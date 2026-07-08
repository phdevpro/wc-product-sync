<?php
class WCPS_Receiver {
    public static function register_routes() {
        register_rest_route('product-sync/v1', '/receive', array(
            'methods' => 'POST',
            'callback' => array('WCPS_Receiver', 'handle_receive'),
            'permission_callback' => array('WCPS_Receiver', 'permission_check')
        ));
        register_rest_route('product-sync/v1', '/config', array(
            'methods' => 'GET',
            'callback' => array('WCPS_Receiver', 'handle_config'),
            'permission_callback' => array('WCPS_Receiver', 'permission_check')
        ));
        register_rest_route('product-sync/v1', '/check-modified', array(
            'methods' => 'POST',
            'callback' => array('WCPS_Receiver', 'handle_check_modified'),
            'permission_callback' => array('WCPS_Receiver', 'permission_check')
        ));
    }

    public static function permission_check($request) {
        $options = get_option('wc_product_sync_sender_settings');
        $expected = isset($options['shop_b_receiver_api_key']) ? $options['shop_b_receiver_api_key'] : '';
        $provided = is_object($request) && method_exists($request, 'get_header') ? $request->get_header('X-Product-Sync-Key') : '';
        if (!empty($expected) && !empty($provided) && hash_equals($expected, $provided)) {
            return true;
        }
        return new WP_Error('forbidden', 'Invalid key', array('status' => 401));
    }

    public static function handle_receive($request) {
        @set_time_limit(120);
        $test_header = is_object($request) && method_exists($request, 'get_header') ? $request->get_header('X-Product-Sync-Test') : '';
        if ($test_header === '1') {
            return rest_ensure_response(array('success' => true));
        }
        if (!class_exists('WooCommerce')) {
            return new WP_Error('woocommerce_required', 'WooCommerce not active', array('status' => 500));
        }
        $data = is_object($request) && method_exists($request, 'get_json_params') ? $request->get_json_params() : null;
        if (!is_array($data)) {
            return new WP_Error('invalid_payload', 'Invalid payload', array('status' => 400));
        }
        if (isset($data['test']) && $data['test']) {
            return rest_ensure_response(array('success' => true));
        }
        $name = isset($data['name']) ? $data['name'] : '';
        $sku = isset($data['sku']) ? $data['sku'] : '';
        $regular = isset($data['regular_price']) ? $data['regular_price'] : '';
        $sale = isset($data['sale_price']) ? $data['sale_price'] : '';
        $desc = isset($data['description']) ? $data['description'] : '';
        $short = isset($data['short_description']) ? $data['short_description'] : '';
        if ($name === '' && $sku === '') {
            return new WP_Error('missing_identity', 'Missing product name and sku', array('status' => 400));
        }
        $product_id = 0;
        if (!empty($sku) && function_exists('wc_get_product_id_by_sku')) {
            $product_id = wc_get_product_id_by_sku($sku);
        }

        // Fallback: se lo SKU è vuoto o il prodotto non è stato trovato tramite SKU,
        // cerchiamo per nome/titolo in modo da evitare la duplicazione.
        if (!$product_id && !empty($name)) {
            global $wpdb;
            $found_id = $wpdb->get_var($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_title = %s AND post_type = 'product' AND post_status != 'trash' LIMIT 1",
                $name
            ));
            if ($found_id) {
                $product_id = $found_id;
            }
        }

        $modified_a = isset($data['modified']) ? intval($data['modified']) : 0;
        $product = $product_id ? wc_get_product($product_id) : null;
        $debug_dates = '';

        // Aggiornamento di soli prezzi: A ha già verificato che il resto è allineato,
        // quindi forziamo la scrittura dei prezzi senza toccare contenuti e immagini.
        // Il payload è privo di contenuti e immagini, quindi non possiamo creare
        // un prodotto nuovo: se non esiste su B, va sincronizzato per intero.
        if (!empty($data['price_only'])) {
            if (!$product) {
                return rest_ensure_response(array(
                    'success' => true,
                    'product_id' => 0,
                    'skipped' => true,
                    'reason' => 'not_found',
                    'debug_dates' => '[price-only: product not on B]'
                ));
            }
            $reg_b_before = (string)$product->get_regular_price();
            if ($regular !== '') { $product->set_regular_price($regular); }
            $product->set_sale_price($sale);
            $product->save();
            return rest_ensure_response(array(
                'success' => true,
                'product_id' => $product->get_id(),
                'price_only' => true,
                'debug_dates' => "[Reg A: {$regular}, Reg B was: {$reg_b_before}]"
            ));
        }

        if ($product && $modified_a > 0) {
            $mod_date_b = method_exists($product, 'get_date_modified') ? $product->get_date_modified() : null;
            $modified_b = $mod_date_b ? $mod_date_b->getTimestamp() : 0;

            // I prezzi possono cambiare su A senza toccare la data di modifica (es. markup),
            // quindi vanno confrontati prima di decidere che il prodotto è aggiornato.
            $regular_b = (string)$product->get_regular_price();
            $sale_b = (string)$product->get_sale_price();
            $price_matches = self::price_equals($regular, $regular_b) && self::price_equals($sale, $sale_b);

            $debug_dates = "[Mod A: {$modified_a}, Mod B: {$modified_b}] [Reg A: {$regular}, Reg B: {$regular_b}]";

            // Se la data di modifica su B è più recente o uguale a quella di A, saltiamo l'aggiornamento
            if ($modified_b >= $modified_a && $price_matches) {
                return rest_ensure_response(array(
                    'success' => true, 
                    'product_id' => $product->get_id(), 
                    'skipped' => true, 
                    'reason' => 'up_to_date',
                    'debug_dates' => $debug_dates
                ));
            }
        }
        if (!$product) {
            $product = new WC_Product_Simple();
        }

        // Assicuriamoci di aggiornare sempre lo SKU, anche sui prodotti pre-esistenti
        if (isset($data['sku'])) {
            $product->set_sku($sku);
        }

        if ($name !== '') {
            $product->set_name($name);
        }
        if ($regular !== '') {
            $product->set_regular_price($regular);
        }
        if ($sale !== '') {
            $product->set_sale_price($sale);
        }
        if ($desc !== '') {
            $product->set_description($desc);
        }
        if ($short !== '') {
            $product->set_short_description($short);
        }
        $options = get_option('wc_product_sync_sender_settings');
        $sync_status = isset($options['receiver_sync_status']) && $options['receiver_sync_status'];
        if ($sync_status && isset($data['status']) && is_string($data['status'])) {
            $status = sanitize_key($data['status']);
            $allowed = array('publish','draft','pending','private');
            if (in_array($status, $allowed, true)) {
                $product->set_status($status);
            } else {
                $product->set_status('publish');
            }
        } else {
            $product->set_status('publish');
        }
        $product->set_catalog_visibility('visible');
        
        $old_image_ids = array_filter(array_merge(
            array($product->get_image_id()),
            (array)$product->get_gallery_image_ids()
        ));

        $ids = array();
        if (isset($data['images']) && is_array($data['images'])) {
            $ids = self::import_images($data['images']);
            if (!empty($ids)) {
                $product->set_image_id($ids[0]);
                if (count($ids) > 1) {
                    $product->set_gallery_image_ids(array_slice($ids, 1));
                } else {
                    $product->set_gallery_image_ids(array());
                }
            } else {
                $product->set_image_id('');
                $product->set_gallery_image_ids(array());
            }

            // Delete old images replaced by the sync
            $to_delete = array_diff($old_image_ids, $ids);
            foreach ($to_delete as $del_id) {
                wp_delete_attachment($del_id, true);
            }
        }
        $product->save();
        return rest_ensure_response(array('success' => true, 'product_id' => $product->get_id(), 'debug_dates' => isset($debug_dates) ? $debug_dates : ''));
    }

    public static function handle_config($request) {
        $options = get_option('wc_product_sync_sender_settings');
        $sync_status = isset($options['receiver_sync_status']) && $options['receiver_sync_status'];
        return rest_ensure_response(array('sync_status' => (bool)$sync_status));
    }

    public static function handle_check_modified($request) {
        $data = is_object($request) && method_exists($request, 'get_json_params') ? $request->get_json_params() : null;
        if (!is_array($data)) {
            return new WP_Error('invalid_payload', 'Invalid payload', array('status' => 400));
        }

        $sku = isset($data['sku']) ? $data['sku'] : '';
        $name = isset($data['name']) ? $data['name'] : '';
        $modified_a = isset($data['modified']) ? intval($data['modified']) : 0;
        $regular_a = isset($data['regular_price']) ? (string)$data['regular_price'] : null;
        $sale_a = isset($data['sale_price']) ? (string)$data['sale_price'] : null;

        if (empty($sku) && empty($name)) {
            return rest_ensure_response(array('needs_update' => true));
        }

        $product_id = 0;
        if (!empty($sku) && function_exists('wc_get_product_id_by_sku')) {
            $product_id = wc_get_product_id_by_sku($sku);
        }

        if (!$product_id && !empty($name)) {
            global $wpdb;
            $found_id = $wpdb->get_var($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_title = %s AND post_type = 'product' AND post_status != 'trash' LIMIT 1",
                $name
            ));
            if ($found_id) {
                $product_id = $found_id;
            }
        }

        if (!$product_id) {
            return rest_ensure_response(array('needs_update' => true));
        }

        $product = wc_get_product($product_id);
        if ($product && $modified_a > 0) {
            $mod_date_b = method_exists($product, 'get_date_modified') ? $product->get_date_modified() : null;
            $modified_b = $mod_date_b ? $mod_date_b->getTimestamp() : 0;
            
            $reg_b_dbg = (string)$product->get_regular_price();
            $price_matches = self::price_equals($regular_a, $reg_b_dbg)
                && self::price_equals($sale_a, $product->get_sale_price());

            $reg_a_dbg = $regular_a === null ? 'null' : $regular_a;
            $debug_dates = "[Mod A: {$modified_a}, Mod B: {$modified_b}] [Reg A: {$reg_a_dbg}, Reg B: {$reg_b_dbg}]";

            if ($modified_b >= $modified_a && $price_matches) {
                return rest_ensure_response(array(
                    'needs_update' => false,
                    'debug_dates' => $debug_dates
                ));
            }

            // Il contenuto su B è già aggiornato: solo i prezzi sono cambiati (es. markup).
            // Segnaliamo ad A che può inviare un payload leggero, senza immagini.
            if ($modified_b >= $modified_a) {
                return rest_ensure_response(array(
                    'needs_update' => true,
                    'price_only' => true,
                    'debug_dates' => $debug_dates
                ));
            }
        }

        return rest_ensure_response(array('needs_update' => true));
    }

    /**
     * Confronta un prezzo inviato da A con quello presente su B.
     * Un prezzo vuoto o assente su A significa "non toccare", quindi conta come uguale.
     */
    private static function price_equals($price_a, $price_b) {
        if ($price_a === null || $price_a === '') {
            return true;
        }
        $price_b = (string)$price_b;
        if (is_numeric($price_a) && is_numeric($price_b)) {
            return abs(floatval($price_a) - floatval($price_b)) <= 0.001;
        }
        return (string)$price_a === $price_b;
    }

    private static function import_images($images) {
        $sorted = $images;
        usort($sorted, function($a, $b) {
            $pa = isset($a['position']) ? intval($a['position']) : 0;
            $pb = isset($b['position']) ? intval($b['position']) : 0;
            return $pa <=> $pb;
        });
        $ids = array();
        foreach ($sorted as $img) {
            if (!isset($img['base64']) || !isset($img['filename'])) {
                continue;
            }
            $decoded = base64_decode($img['base64'], true);
            if ($decoded === false) {
                continue;
            }
            
            global $wpdb;
            $md5 = md5($decoded);
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
                '_wcps_image_md5',
                $md5
            ));
            
            if ($existing) {
                // Verify the attachment actually still exists (in case postmeta is orphaned)
                $is_valid = $wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE ID = %d AND post_type = 'attachment'", $existing));
                if ($is_valid) {
                    $ids[] = $existing;
                    continue;
                } else {
                    $wpdb->delete($wpdb->postmeta, array('post_id' => $existing, 'meta_key' => '_wcps_image_md5'));
                }
            }

            $filename = sanitize_file_name($img['filename']);
            $upload = wp_upload_bits($filename, null, $decoded);
            if (!empty($upload['error'])) {
                continue;
            }
            $filetype = wp_check_filetype($upload['file'], null);
            $attachment = array(
                'post_mime_type' => $filetype['type'],
                'post_title' => preg_replace('/\.[^.]+$/', '', $filename),
                'post_content' => '',
                'post_status' => 'inherit'
            );
            $attach_id = wp_insert_attachment($attachment, $upload['file']);
            if (is_wp_error($attach_id) || !$attach_id) {
                continue;
            }
            
            require_once ABSPATH . 'wp-admin/includes/image.php';
            $attach_data = wp_generate_attachment_metadata($attach_id, $upload['file']);
            if (!empty($attach_data)) {
                wp_update_attachment_metadata($attach_id, $attach_data);
            }
            
            update_post_meta($attach_id, '_wcps_image_md5', $md5);
            $ids[] = $attach_id;
        }
        return $ids;
    }
}

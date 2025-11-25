<?php
class WCPS_Receiver {
    public static function register_routes() {
        register_rest_route('product-sync/v1', '/receive', array(
            'methods' => 'POST',
            'callback' => array('WCPS_Receiver', 'handle_receive'),
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
        $product = $product_id ? wc_get_product($product_id) : null;
        if (!$product) {
            $product = new WC_Product_Simple();
            if (!empty($sku)) {
                $product->set_sku($sku);
            }
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
        $product->set_status('publish');
        $product->set_catalog_visibility('visible');
        $ids = array();
        if (isset($data['images']) && is_array($data['images'])) {
            $ids = self::import_images($data['images']);
            if (!empty($ids)) {
                $product->set_image_id($ids[0]);
                if (count($ids) > 1) {
                    $product->set_gallery_image_ids(array_slice($ids, 1));
                }
            }
        }
        $product->save();
        return rest_ensure_response(array('success' => true, 'product_id' => $product->get_id()));
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
            require_once(ABSPATH . 'wp-admin/includes/image.php');
            $attach_data = wp_generate_attachment_metadata($attach_id, $upload['file']);
            if ($attach_data) {
                wp_update_attachment_metadata($attach_id, $attach_data);
            }
            $ids[] = $attach_id;
        }
        return $ids;
    }
}
<?php
/*
Plugin Name: WooCommerce Product Sync (Sender with Receiver)
Plugin URI: https://example.com
Description: Syncs products from Site A to Shop B by sending product data—including base64 encoded images—to a custom receiver end
point on Shop B.
Version: 2.0
Author: Your Name
Author URI: https://example.com
License: GPL2
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WC_Product_Sync_Send_Receive {

    private $option_name = 'wc_product_sync_sender_settings';

    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_post_wc_product_sync_run', array($this, 'handle_sync'));
        add_action('rest_api_init', array($this, 'register_receiver_route'));
    }

    public function register_settings() {
        register_setting($this->option_name, 'wc_product_sync_sender_settings', array('sanitize_callback' => array($this, 'sanitize_options')));

        add_settings_section(
            'wc_product_sync_sender_section',
            'Shop B Receiver Settings',
            null,
            $this->option_name
        );

        add_settings_field(
            'shop_b_url',
            'Shop B URL',
            array($this, 'shop_b_url_callback'),
            $this->option_name,
            'wc_product_sync_sender_section'
        );

        add_settings_field(
            'shop_b_receiver_api_key',
            'Receiver API Key',
            array($this, 'shop_b_receiver_api_key_callback'),
            $this->option_name,
            'wc_product_sync_sender_section'
        );
    }

    public function shop_b_url_callback() {
        $options = get_option('wc_product_sync_sender_settings');
        ?>
        <input type="text" name="wc_product_sync_sender_settings[shop_b_url]" value="<?php echo esc_attr(isset($options['shop_b_url']) ? $options['shop_b_url'] : ''); ?>" size="50" />
        <?php
    }

    public function shop_b_receiver_api_key_callback() {
        $options = get_option('wc_product_sync_sender_settings');
        ?>
        <input type="text" name="wc_product_sync_sender_settings[shop_b_receiver_api_key]" value="<?php echo esc_attr(isset($options['shop_b_receiver_api_key']) ? $options['shop_b_receiver_api_key'] : ''); ?>" size="50" />
        <?php
    }

    public function add_admin_menu() {
        add_menu_page(
            'WC Product Sync Send/Receive',
            'Product Sync Send/Receive',
            'manage_options',
            'wc-product-sync-send-receive',
            array($this, 'settings_page'),
            'dashicons-update'
        );
    }

    public function settings_page() {
        ?>
        <div class="wrap">
            <h1>WooCommerce Product Sync Send/Receive Settings</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields($this->option_name);
                do_settings_sections($this->option_name);
                submit_button();
                ?>
            </form>
            <hr>
            <h2>Sync Products from Site A to Shop B Receiver</h2>
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <input type="hidden" name="action" value="wc_product_sync_run">
                <?php wp_nonce_field('wc_product_sync_run'); ?>
                <p>
                    <label>
                        <input type="checkbox" name="dry_run" value="1" />
                        Dry Run (No data will be sent)
                    </label>
                </p>
                <p>
                    <label>
                        Number of Products to Sync (0 for all):
                        <input type="number" name="product_limit" value="0" min="0" />
                    </label>
                </p>
                <p>
                    <label>
                        <input type="checkbox" name="skip_image_sync" value="1" />
                        Skip Image Sync (only sync textual data)
                    </label>
                </p>
                <?php submit_button('Run Product Sync'); ?>
            </form>
            <hr>
            <h2>Sync Log</h2>
            <textarea readonly style="width:100%;height:300px;"><?php echo esc_textarea(get_option('wc_product_sync_sender_log', 'No logs available.')); ?></textarea>
        </div>
        <?php
    }

    public function handle_sync() {
        if (!current_user_can('manage_options')) {
            wp_die('Not allowed');
        }
        check_admin_referer('wc_product_sync_run');

        $log = array();
        $log[] = "=== WooCommerce Product Sync Sender Started at " . current_time('mysql') . " ===";

        $dry_run = isset($_POST['dry_run']) && $_POST['dry_run'] == '1';
        if ($dry_run) {
            $log[] = "Dry Run Enabled: No data will be sent to Shop B.";
        }

        $skip_image_sync = isset($_POST['skip_image_sync']) && $_POST['skip_image_sync'] == '1';
        if ($skip_image_sync) {
            $log[] = "Skip Image Sync Enabled: Images will not be included in the payload.";
        }

        $options = get_option('wc_product_sync_sender_settings');
        $shop_b_url = isset($options['shop_b_url']) ? trailingslashit($options['shop_b_url']) : '';
        $receiver_api_key = isset($options['shop_b_receiver_api_key']) ? $options['shop_b_receiver_api_key'] : '';

        if (empty($shop_b_url) || empty($receiver_api_key)) {
            wp_die('Please set Shop B URL and Receiver API Key in the plugin settings.');
        }

        // Receiver endpoint on Shop B
        $receiver_endpoint = $shop_b_url . 'wp-json/product-sync/v1/receive';

        $product_limit = isset($_POST['product_limit']) ? absint($_POST['product_limit']) : 0;
        $args = array(
            'post_type'   => 'product',
            'post_status' => 'publish',
        );
        if ($product_limit > 0) {
            $args['posts_per_page'] = $product_limit;
            $log[] = "Limiting sync to " . $product_limit . " products.";
        } else {
            $args['posts_per_page'] = -1;
            $log[] = "Syncing all available products.";
        }

        $posts = get_posts($args);
        $log[] = "Found " . count($posts) . " products to process.";

        foreach ($posts as $post) {
            $product = wc_get_product($post->ID);
            if (!$product) {
                $log[] = "Skipping product ID " . $post->ID . " - unable to retrieve product data.";
                continue;
            }
            $log[] = "Processing product ID " . $post->ID . " - " . $product->get_name();

            // Build product payload
            $payload = array(
                'name'              => $product->get_name(),
                'sku'               => $product->get_sku(),
                'regular_price'     => $product->get_regular_price(),
                'sale_price'        => $product->get_sale_price(),
                'description'       => $product->get_description(),
                'short_description' => $product->get_short_description(),
            );

            // Process images if not skipped
            if (!$skip_image_sync) {
                $images = array();

                // Main image
                $image_id = $product->get_image_id();
                if ($image_id) {
                    $file_path = get_attached_file($image_id);
                    if ($file_path && file_exists($file_path)) {
                        $image_data = file_get_contents($file_path);
                        if ($image_data !== false) {
                            $base64 = base64_encode($image_data);
                            $filename = basename($file_path);
                            $images[] = array(
                                'filename' => $filename,
                                'base64'   => $base64,
                                'position' => 0,
                            );
                            $log[] = "Processed main image: " . $filename;
                        } else {
                            $log[] = "Failed to read main image file for product ID " . $post->ID;
                        }
                    } else {
                        $log[] = "Main image file not found for product ID " . $post->ID;
                    }
                }

                // Gallery images
                $gallery_ids = $product->get_gallery_image_ids();
                if (!empty($gallery_ids)) {
                    $position = 1;
                    foreach ($gallery_ids as $gid) {
                        $file_path = get_attached_file($gid);
                        if ($file_path && file_exists($file_path)) {
                            $image_data = file_get_contents($file_path);
                            if ($image_data !== false) {
                                $base64 = base64_encode($image_data);
                                $filename = basename($file_path);
                                $images[] = array(
                                    'filename' => $filename,
                                    'base64'   => $base64,
                                    'position' => $position,
                                );
                                $log[] = "Processed gallery image: " . $filename;
                                $position++;
                            } else {
                                $log[] = "Failed to read gallery image file for product ID " . $post->ID;
                            }
                        } else {
                            $log[] = "Gallery image file not found for product ID " . $post->ID;
                        }
                    }
                }
                if (!empty($images)) {
                    $payload['images'] = $images;
                }
            } else {
                $log[] = "Skipping image processing for product ID " . $post->ID;
            }

            // Prepare a copy of the payload for logging
            $log_payload = $payload;
            if ( isset($log_payload['images']) && is_array($log_payload['images']) ) {
                foreach ( $log_payload['images'] as &$img ) {
                    if ( isset($img['base64']) ) {
                        $img['base64'] = "BASE64-Payload";
                    }
                }
                unset($img);
            }
            $log[] = "Payload for product ID " . $post->ID . ": " . print_r($log_payload, true);

            if ($dry_run) {
                $log[] = "Dry Run: Would send payload to receiver endpoint: " . $receiver_endpoint;
            } else {
                $args = array(
                    'headers' => array(
                        'Content-Type' => 'application/json',
                        'X-Product-Sync-Key' => $receiver_api_key,
                    ),
                    'body' => json_encode($payload),
                    'timeout' => 60,
                );
                $response = wp_remote_post($receiver_endpoint, $args);
                if (is_wp_error($response)) {
                    $log[] = "Error sending product ID " . $post->ID . ": " . $response->get_error_message();
                } else {
                    $code = wp_remote_retrieve_response_code($response);
                    $body = wp_remote_retrieve_body($response);
                    $log[] = "Response for product ID " . $post->ID . ": Code " . $code . " Body: " . $body;
                }
            }
        }

        $log[] = "=== WooCommerce Product Sync Sender Completed at " . current_time('mysql') . " ===";
        update_option('wc_product_sync_sender_log', implode("\n", $log));
        wp_redirect(admin_url('admin.php?page=wc-product-sync-send-receive'));
        exit;
    }

    public function register_receiver_route() {
        register_rest_route('product-sync/v1', '/receive', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_receive'),
            'permission_callback' => array($this, 'permission_check')
        ));
    }

    public function permission_check($request) {
        $options = get_option('wc_product_sync_sender_settings');
        $expected = isset($options['shop_b_receiver_api_key']) ? $options['shop_b_receiver_api_key'] : '';
        $provided = is_object($request) && method_exists($request, 'get_header') ? $request->get_header('X-Product-Sync-Key') : '';
        if (!empty($expected) && !empty($provided) && hash_equals($expected, $provided)) {
            return true;
        }
        return new WP_Error('forbidden', 'Invalid key', array('status' => 401));
    }

    public function handle_receive($request) {
        if (!class_exists('WooCommerce')) {
            return new WP_Error('woocommerce_required', 'WooCommerce not active', array('status' => 500));
        }
        $data = is_object($request) && method_exists($request, 'get_json_params') ? $request->get_json_params() : null;
        if (!is_array($data)) {
            return new WP_Error('invalid_payload', 'Invalid payload', array('status' => 400));
        }
        $name = isset($data['name']) ? $data['name'] : '';
        $sku = isset($data['sku']) ? $data['sku'] : '';
        $regular = isset($data['regular_price']) ? $data['regular_price'] : '';
        $sale = isset($data['sale_price']) ? $data['sale_price'] : '';
        $desc = isset($data['description']) ? $data['description'] : '';
        $short = isset($data['short_description']) ? $data['short_description'] : '';
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
        $ids = array();
        if (isset($data['images']) && is_array($data['images'])) {
            $ids = $this->import_images($data['images']);
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

    public function sanitize_options($input) {
        $output = array();
        if (isset($input['shop_b_url'])) {
            $output['shop_b_url'] = esc_url_raw($input['shop_b_url']);
        }
        if (isset($input['shop_b_receiver_api_key'])) {
            $output['shop_b_receiver_api_key'] = sanitize_text_field($input['shop_b_receiver_api_key']);
        }
        return $output;
    }

    private function import_images($images) {
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

new WC_Product_Sync_Send_Receive();
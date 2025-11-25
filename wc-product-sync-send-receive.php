<?php
/*
Plugin Name: WooCommerce Product Sync (Sender with Receiver)
Plugin URI: https://phdevpro.com
Description: Syncs products from Site A to Shop B by sending product data—including base64 encoded images—to a custom receiver end
point on Shop B.
Version: 2.2
Author: Your Simone Palazzin - PHDEVPRO
Author URI: https://phdevpro.com
License: GPL2
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
require_once plugin_dir_path(__FILE__) . 'includes/class-wcps-receiver.php';

class WC_Product_Sync_Send_Receive {

    private $option_name = 'wc_product_sync_sender_settings';

    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        
        add_action('rest_api_init', array('WCPS_Receiver', 'register_routes'));
        add_action('wp_ajax_wc_product_sync_start', array($this, 'ajax_start_sync'));
        add_action('wp_ajax_wc_product_sync_progress', array($this, 'ajax_progress'));
        add_action('wp_ajax_wc_product_sync_cancel', array($this, 'ajax_cancel'));
        add_action('wp_ajax_wc_product_sync_test_receiver', array($this, 'ajax_test_receiver'));
        add_action('wp_ajax_wc_product_sync_resume', array($this, 'ajax_resume'));
        add_action('wc_product_sync_run_event', array($this, 'run_sync_event'), 10, 4);
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
            $this->menu_title(),
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
            <form id="wcps-progress-options" method="post">
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
                <button type="button" id="wcps-start" class="button button-secondary">Start Sync with Progress</button>
                <button type="button" id="wcps-cancel" class="button">Cancel Sync</button>
                <button type="button" id="wcps-test" class="button">Test Receiver</button>
            </form>
            <div id="wcps-progress-wrap" style="margin-top:12px;">
                <div id="wcps-progress-status"></div>
                <div style="width:100%;background:#eee;height:10px;margin:6px 0;">
                    <div id="wcps-progress-bar" style="height:10px;background:#4caf50;width:0%"></div>
                </div>
                <div id="wcps-progress-count"></div>
                <div id="wcps-test-result" style="margin-top:6px;"></div>
            </div>
            <hr>
            <h2>Sync Log</h2>
            <textarea id="wcps-log" readonly style="width:100%;height:300px;"><?php echo esc_textarea(get_option('wc_product_sync_sender_log', 'No logs available.')); ?></textarea>
        </div>
        <script>
        (function(){
            var job=null;var timer=null;var wpAjax=(typeof ajaxurl!=='undefined'?ajaxurl:'<?php echo admin_url('admin-ajax.php'); ?>');
            function setStartEnabled(enabled){
                var b=document.getElementById('wcps-start'); if(b){b.disabled=!enabled}
            }
            function startSync(){
                var f=document.getElementById('wcps-progress-options');
                var dry=f.querySelector('[name="dry_run"]');
                var lim=f.querySelector('[name="product_limit"]');
                var skip=f.querySelector('[name="skip_image_sync"]');
                var data=new FormData();
                data.append('action','wc_product_sync_start');
                data.append('security','<?php echo wp_create_nonce('wc_product_sync'); ?>');
                data.append('dry_run',dry && dry.checked ? '1':'0');
                data.append('product_limit',lim && lim.value ? lim.value : '0');
                data.append('skip_image_sync',skip && skip.checked ? '1':'0');
                fetch(wpAjax,{method:'POST',credentials:'same-origin',body:data}).then(function(r){return r.json()}).then(function(res){
                    if(res && res.success){job=res.data.job_id;document.getElementById('wcps-progress-status').textContent='Running';setStartEnabled(false);poll()} else {alert(res && res.data && res.data.message ? res.data.message : 'Error starting sync')}
                });
            }
            function cancelSync(){
                if(!job){return}
                var data=new FormData();
                data.append('action','wc_product_sync_cancel');
                data.append('security','<?php echo wp_create_nonce('wc_product_sync'); ?>');
                data.append('job_id',job);
                fetch(wpAjax,{method:'POST',credentials:'same-origin',body:data}).then(function(r){return r.json()}).then(function(res){
                    if(res && res.success){document.getElementById('wcps-progress-status').textContent='Cancelling'}
                });
            }
            function testReceiver(){
                var data=new FormData();
                data.append('action','wc_product_sync_test_receiver');
                data.append('security','<?php echo wp_create_nonce('wc_product_sync'); ?>');
                fetch(wpAjax,{method:'POST',credentials:'same-origin',body:data}).then(function(r){return r.json()}).then(function(res){
                    var el=document.getElementById('wcps-test-result');
                    if(res && res.success){el.textContent='Receiver OK'} else {el.textContent=(res && res.data && res.data.message)?res.data.message:'Receiver test failed'}
                }).catch(function(){document.getElementById('wcps-test-result').textContent='Receiver test failed'});
            }
            function poll(){
                if(!job){return}
                var data=new FormData();
                data.append('action','wc_product_sync_progress');
                data.append('security','<?php echo wp_create_nonce('wc_product_sync'); ?>');
                data.append('job_id',job);
                fetch(wpAjax,{method:'POST',credentials:'same-origin',body:data}).then(function(r){return r.json()}).then(function(res){
                    if(res && res.success){
                        var d=res.data;var pct=d.total?Math.round((d.processed/d.total)*100):0;
                        document.getElementById('wcps-progress-bar').style.width=pct+'%';
                        document.getElementById('wcps-progress-count').textContent=d.processed+' / '+d.total;
                        if(d.log){document.getElementById('wcps-log').value=d.log}
                        if(d.status==='done'||d.status==='error'||d.status==='cancelled'){document.getElementById('wcps-progress-status').textContent=d.status;setStartEnabled(true);job=null;return}
                    }
                    timer=setTimeout(poll,2000);
                });
            }
            function resume(){
                var data=new FormData();
                data.append('action','wc_product_sync_resume');
                data.append('security','<?php echo wp_create_nonce('wc_product_sync'); ?>');
                fetch(wpAjax,{method:'POST',credentials:'same-origin',body:data}).then(function(r){return r.json()}).then(function(res){
                    if(res && res.success && res.data && res.data.job_id){
                        job=res.data.job_id;
                        var d=res.data;var pct=d.total?Math.round((d.processed/d.total)*100):0;
                        document.getElementById('wcps-progress-bar').style.width=pct+'%';
                        document.getElementById('wcps-progress-count').textContent=d.processed+' / '+d.total;
                        if(d.log){document.getElementById('wcps-log').value=d.log}
                        document.getElementById('wcps-progress-status').textContent=d.status;
                        if(d.status==='running'||d.status==='scheduled'){setStartEnabled(false);poll()} else {setStartEnabled(true)}
                    }
                });
            }
            document.addEventListener('DOMContentLoaded',function(){var btn=document.getElementById('wcps-start');if(btn){btn.addEventListener('click',function(e){e.preventDefault();startSync()})}var c=document.getElementById('wcps-cancel');if(c){c.addEventListener('click',function(e){e.preventDefault();cancelSync()})}var t=document.getElementById('wcps-test');if(t){t.addEventListener('click',function(e){e.preventDefault();testReceiver()})}resume()})
        })();
        </script>
        <?php
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

    public function ajax_start_sync() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'not_allowed'));
        }
        check_ajax_referer('wc_product_sync', 'security');
        $existing = get_user_meta(get_current_user_id(), 'wc_product_sync_current_job', true);
        if (!empty($existing)) {
            $st = get_transient('wc_product_sync_progress_' . $existing);
            if ($st && isset($st['status']) && ($st['status'] === 'running' || $st['status'] === 'scheduled')) {
                wp_send_json_error(array('message' => 'Another sync is already running', 'job_id' => $existing));
            }
        }
        $options = get_option('wc_product_sync_sender_settings');
        $shop_b_url = isset($options['shop_b_url']) ? trailingslashit($options['shop_b_url']) : '';
        $receiver_api_key = isset($options['shop_b_receiver_api_key']) ? $options['shop_b_receiver_api_key'] : '';
        if (empty($shop_b_url) || empty($receiver_api_key)) {
            wp_send_json_error(array('message' => 'Please set Shop B URL and Receiver API Key'));
        }
        $dry = isset($_POST['dry_run']) && $_POST['dry_run'] == '1';
        $limit = isset($_POST['product_limit']) ? absint($_POST['product_limit']) : 0;
        $skip = isset($_POST['skip_image_sync']) && $_POST['skip_image_sync'] == '1';
        $job = uniqid('sync_', true);
        $total = $this->count_products($limit);
        set_transient('wc_product_sync_progress_' . $job, array('status' => 'scheduled', 'total' => $total, 'processed' => 0, 'log' => '', 'user_id' => get_current_user_id()), 12 * HOUR_IN_SECONDS);
        update_user_meta(get_current_user_id(), 'wc_product_sync_current_job', $job);
        wp_schedule_single_event(time() + 1, 'wc_product_sync_run_event', array($job, $dry, $limit, $skip));
        wp_remote_post(site_url('wp-cron.php'), array('timeout' => 0.01, 'blocking' => false));
        wp_send_json_success(array('job_id' => $job));
    }

    public function ajax_progress() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'not_allowed'));
        }
        check_ajax_referer('wc_product_sync', 'security');
        $job = isset($_POST['job_id']) ? sanitize_text_field($_POST['job_id']) : '';
        if (!$job) {
            wp_send_json_error(array('message' => 'missing_job'));
        }
        $st = get_transient('wc_product_sync_progress_' . $job);
        if (!$st) {
            wp_send_json_success(array('status' => 'unknown', 'total' => 0, 'processed' => 0, 'log' => ''));
        }
        wp_send_json_success($st);
    }

    public function ajax_cancel() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'not_allowed'));
        }
        check_ajax_referer('wc_product_sync', 'security');
        $job = isset($_POST['job_id']) ? sanitize_text_field($_POST['job_id']) : '';
        if (!$job) {
            wp_send_json_error(array('message' => 'missing_job'));
        }
        $st = get_transient('wc_product_sync_progress_' . $job);
        if (!$st) {
            $st = array('status' => 'running', 'total' => 0, 'processed' => 0, 'log' => '');
        }
        $st['status'] = 'cancelled';
        set_transient('wc_product_sync_progress_' . $job, $st, 12 * HOUR_IN_SECONDS);
        $uid = isset($st['user_id']) ? intval($st['user_id']) : get_current_user_id();
        delete_user_meta($uid, 'wc_product_sync_current_job');
        wp_send_json_success(array('status' => 'cancelled'));
    }

    public function ajax_test_receiver() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'not_allowed'));
        }
        check_ajax_referer('wc_product_sync', 'security');
        $options = get_option('wc_product_sync_sender_settings');
        $shop_b_url = isset($options['shop_b_url']) ? trailingslashit($options['shop_b_url']) : '';
        $receiver_api_key = isset($options['shop_b_receiver_api_key']) ? $options['shop_b_receiver_api_key'] : '';
        if (empty($shop_b_url) || empty($receiver_api_key)) {
            wp_send_json_error(array('message' => 'Please set Shop B URL and Receiver API Key'));
        }
        $endpoint = $shop_b_url . 'wp-json/product-sync/v1/receive';
        $resp = wp_remote_post($endpoint, array('headers' => array('Content-Type' => 'application/json', 'X-Product-Sync-Key' => $receiver_api_key, 'X-Product-Sync-Test' => '1'), 'body' => json_encode(array('test' => true)), 'timeout' => 20));
        if (is_wp_error($resp)) {
            wp_send_json_error(array('message' => $resp->get_error_message()));
        }
        $code = wp_remote_retrieve_response_code($resp);
        $body = wp_remote_retrieve_body($resp);
        if ($code >= 200 && $code < 300) {
            $decoded = json_decode($body, true);
            if (is_array($decoded) && isset($decoded['success']) && $decoded['success']) {
                wp_send_json_success();
            }
            wp_send_json_error(array('message' => $body));
        }
        wp_send_json_error(array('message' => 'HTTP ' . $code . ': ' . $body));
    }

    public function run_sync_event($job, $dry, $limit, $skip) {
        $options = get_option('wc_product_sync_sender_settings');
        $shop_b_url = isset($options['shop_b_url']) ? trailingslashit($options['shop_b_url']) : '';
        $receiver_api_key = isset($options['shop_b_receiver_api_key']) ? $options['shop_b_receiver_api_key'] : '';
        $log = array();
        $total = $this->count_products($limit);
        set_transient('wc_product_sync_progress_' . $job, array('status' => 'running', 'total' => $total, 'processed' => 0, 'log' => '', 'user_id' => isset(get_transient('wc_product_sync_progress_' . $job)['user_id']) ? get_transient('wc_product_sync_progress_' . $job)['user_id'] : 0), 12 * HOUR_IN_SECONDS);
        $args = array('post_type' => 'product', 'post_status' => 'publish');
        if ($limit > 0) { $args['posts_per_page'] = $limit; } else { $args['posts_per_page'] = -1; }
        $posts = get_posts($args);
        $processed = 0;
        foreach ($posts as $post) {
            $st = get_transient('wc_product_sync_progress_' . $job);
            if ($st && isset($st['status']) && $st['status'] === 'cancelled') {
                set_transient('wc_product_sync_progress_' . $job, array('status' => 'cancelled', 'total' => $total, 'processed' => $processed, 'log' => implode("\n", $this->trim_log($log)), 'user_id' => isset($st['user_id']) ? $st['user_id'] : 0), 12 * HOUR_IN_SECONDS);
                $uid = isset($st['user_id']) ? intval($st['user_id']) : 0;
                if ($uid) { delete_user_meta($uid, 'wc_product_sync_current_job'); }
                return;
            }
            $product = wc_get_product($post->ID);
            if (!$product) { $log[] = 'Skipping product ID ' . $post->ID; $processed++; $this->update_progress($job, $total, $processed, $log); continue; }
            $payload = array(
                'name' => $product->get_name(),
                'sku' => $product->get_sku(),
                'regular_price' => $product->get_regular_price(),
                'sale_price' => $product->get_sale_price(),
                'description' => $product->get_description(),
                'short_description' => $product->get_short_description(),
            );
            if (!$skip) {
                $images = array();
                $image_id = $product->get_image_id();
                if ($image_id) {
                    $file_path = get_attached_file($image_id);
                    if ($file_path && file_exists($file_path)) {
                        $image_data = file_get_contents($file_path);
                        if ($image_data !== false) {
                            $images[] = array('filename' => basename($file_path), 'base64' => base64_encode($image_data), 'position' => 0);
                        }
                    }
                }
                $gallery_ids = $product->get_gallery_image_ids();
                if (!empty($gallery_ids)) {
                    $position = 1;
                    foreach ($gallery_ids as $gid) {
                        $file_path = get_attached_file($gid);
                        if ($file_path && file_exists($file_path)) {
                            $image_data = file_get_contents($file_path);
                            if ($image_data !== false) {
                                $images[] = array('filename' => basename($file_path), 'base64' => base64_encode($image_data), 'position' => $position);
                                $position++;
                            }
                        }
                    }
                }
                if (!empty($images)) { $payload['images'] = $images; }
            }
            if ($dry) {
                $processed++;
                $log[] = 'Dry Run: ' . $product->get_name();
                $this->update_progress($job, $total, $processed, $log);
                continue;
            }
            $receiver_endpoint = $shop_b_url . 'wp-json/product-sync/v1/receive';
            $resp = wp_remote_post($receiver_endpoint, array('headers' => array('Content-Type' => 'application/json', 'X-Product-Sync-Key' => $receiver_api_key), 'body' => json_encode($payload), 'timeout' => 60));
            if (is_wp_error($resp)) {
                $log[] = 'Error sending ' . $product->get_name() . ': ' . $resp->get_error_message();
            } else {
                $code = wp_remote_retrieve_response_code($resp);
                $body = wp_remote_retrieve_body($resp);
                if ($code >= 200 && $code < 300) {
                    $decoded = json_decode($body, true);
                    if (is_array($decoded) && isset($decoded['success']) && $decoded['success']) {
                        $pid = isset($decoded['product_id']) ? $decoded['product_id'] : '?';
                        $log[] = 'Upserted ' . $product->get_name() . ' (ID ' . $pid . ')';
                    } else {
                        $log[] = 'Receiver error: ' . $body;
                    }
                } else {
                    $log[] = 'Receiver HTTP ' . $code . ': ' . $body;
                }
            }
            $processed++;
            $this->update_progress($job, $total, $processed, $log);
        }
        $st = get_transient('wc_product_sync_progress_' . $job);
        set_transient('wc_product_sync_progress_' . $job, array('status' => 'done', 'total' => $total, 'processed' => $processed, 'log' => implode("\n", $this->trim_log($log)), 'user_id' => isset($st['user_id']) ? $st['user_id'] : 0), 12 * HOUR_IN_SECONDS);
        $uid = isset($st['user_id']) ? intval($st['user_id']) : 0;
        if ($uid) { delete_user_meta($uid, 'wc_product_sync_current_job'); }
    }

    private function update_progress($job, $total, $processed, $log) {
        $st = get_transient('wc_product_sync_progress_' . $job);
        $uid = isset($st['user_id']) ? $st['user_id'] : 0;
        set_transient('wc_product_sync_progress_' . $job, array('status' => 'running', 'total' => $total, 'processed' => $processed, 'log' => implode("\n", $this->trim_log($log)), 'user_id' => $uid), 12 * HOUR_IN_SECONDS);
    }

    private function trim_log($log) {
        $max = 200;
        $count = count($log);
        if ($count > $max) { return array_slice($log, $count - $max); }
        return $log;
    }

    private function count_products($limit) {
        $args = array('post_type' => 'product', 'post_status' => 'publish');
        if ($limit > 0) { $args['posts_per_page'] = $limit; } else { $args['posts_per_page'] = -1; }
        $posts = get_posts($args);
        return count($posts);
    }

    private function menu_title() {
        $label = 'Product Sync Send/Receive';
        $job = get_user_meta(get_current_user_id(), 'wc_product_sync_current_job', true);
        if (!empty($job)) {
            $st = get_transient('wc_product_sync_progress_' . $job);
            if ($st && isset($st['status']) && ($st['status'] === 'running' || $st['status'] === 'scheduled')) {
                return $label . ' <span class="update-plugins count-1"><span class="plugin-count">1</span></span>';
            }
        }
        return $label;
    }

    
    public function ajax_resume() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'not_allowed'));
        }
        check_ajax_referer('wc_product_sync', 'security');
        $job = get_user_meta(get_current_user_id(), 'wc_product_sync_current_job', true);
        if (!$job) {
            wp_send_json_success(array('status' => 'idle'));
        }
        $st = get_transient('wc_product_sync_progress_' . $job);
        if (!$st) {
            delete_user_meta(get_current_user_id(), 'wc_product_sync_current_job');
            wp_send_json_success(array('status' => 'idle'));
        }
        wp_send_json_success(array('job_id' => $job, 'status' => isset($st['status']) ? $st['status'] : 'unknown', 'total' => isset($st['total']) ? $st['total'] : 0, 'processed' => isset($st['processed']) ? $st['processed'] : 0, 'log' => isset($st['log']) ? $st['log'] : ''));
    }
}

new WC_Product_Sync_Send_Receive();
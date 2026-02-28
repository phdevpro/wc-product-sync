<?php
/*
Plugin Name: WooCommerce Product Sync
Plugin URI: https://phdevpro.com
Description: Syncs products from Site A to Shop B by sending product data—including base64 encoded images—to a custom receiver end
point on Shop B.
Version: 2.2.5
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
        add_action('wc_product_sync_run_event', array($this, 'run_sync_event'), 10, 6);
        add_filter('cron_schedules', array($this, 'add_cron_schedules'));
        add_action('init', array($this, 'ensure_scheduler'));
        add_action('wc_product_sync_scheduler_tick', array($this, 'scheduler_tick'));
    }

    public function register_settings() {
        register_setting($this->option_name, 'wc_product_sync_sender_settings', array('sanitize_callback' => array($this, 'sanitize_options')));

        add_settings_section(
            'wc_product_sync_sender_section',
            'Configuration',
            null,
            $this->option_name
        );

        $options = get_option('wc_product_sync_sender_settings');
        $role = isset($options['site_role']) ? $options['site_role'] : 'sender';
        if ($role === 'sender') {
            add_settings_field(
                'shop_b_url',
                'Shop B URL',
                array($this, 'shop_b_url_callback'),
                $this->option_name,
                'wc_product_sync_sender_section'
            );
            add_settings_field(
                'price_markup_percent',
                'Price Markup %',
                array($this, 'price_markup_percent_callback'),
                $this->option_name,
                'wc_product_sync_sender_section'
            );
            add_settings_field(
                'auto_sync_enabled',
                'Enable Auto Sync (Cron)',
                array($this, 'auto_sync_enabled_callback'),
                $this->option_name,
                'wc_product_sync_sender_section'
            );
            add_settings_field(
                'auto_sync_cron',
                'Cron Expression (m h dom mon dow)',
                array($this, 'auto_sync_cron_callback'),
                $this->option_name,
                'wc_product_sync_sender_section'
            );
            add_settings_field(
                'auto_product_limit',
                'Auto Product Limit',
                array($this, 'auto_product_limit_callback'),
                $this->option_name,
                'wc_product_sync_sender_section'
            );
            add_settings_field(
                'auto_skip_image_sync',
                'Auto Skip Image Sync',
                array($this, 'auto_skip_image_sync_callback'),
                $this->option_name,
                'wc_product_sync_sender_section'
            );
            add_settings_field(
                'auto_gallery_limit',
                'Auto Gallery Limit',
                array($this, 'auto_gallery_limit_callback'),
                $this->option_name,
                'wc_product_sync_sender_section'
            );
            add_settings_field(
                'auto_dry_run',
                'Auto Dry Run',
                array($this, 'auto_dry_run_callback'),
                $this->option_name,
                'wc_product_sync_sender_section'
            );
            add_settings_field(
                'auto_compress_images',
                'Compress Images (Send Large)',
                array($this, 'auto_compress_images_callback'),
                $this->option_name,
                'wc_product_sync_sender_section'
            );
        }
        if ($role === 'receiver') {
            add_settings_field(
                'receiver_sync_status',
                'Sync status',
                array($this, 'receiver_sync_status_callback'),
                $this->option_name,
                'wc_product_sync_sender_section'
            );
        }

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

    public function price_markup_percent_callback() {
        $options = get_option('wc_product_sync_sender_settings');
        $val = isset($options['price_markup_percent']) ? floatval($options['price_markup_percent']) : 0;
        ?>
        <input type="number" name="wc_product_sync_sender_settings[price_markup_percent]" value="<?php echo esc_attr($val); ?>" min="0" step="0.01" />
        <?php
    }

    public function auto_sync_enabled_callback() {
        $options = get_option('wc_product_sync_sender_settings');
        $val = isset($options['auto_sync_enabled']) ? (bool)$options['auto_sync_enabled'] : false;
        ?>
        <label><input type="checkbox" name="wc_product_sync_sender_settings[auto_sync_enabled]" value="1" <?php echo $val?'checked':''; ?> /> Enable background automatic sync</label>
        <?php
    }

    public function auto_sync_cron_callback() {
        $options = get_option('wc_product_sync_sender_settings');
        $val = isset($options['auto_sync_cron']) ? $options['auto_sync_cron'] : '';
        ?>
        <input type="text" name="wc_product_sync_sender_settings[auto_sync_cron]" value="<?php echo esc_attr($val); ?>" placeholder="*/30 * * * *" size="30" />
        <?php
    }

    public function auto_product_limit_callback() {
        $options = get_option('wc_product_sync_sender_settings');
        $val = isset($options['auto_product_limit']) ? intval($options['auto_product_limit']) : 0;
        ?>
        <input type="number" name="wc_product_sync_sender_settings[auto_product_limit]" value="<?php echo esc_attr($val); ?>" min="0" />
        <?php
    }

    public function auto_skip_image_sync_callback() {
        $options = get_option('wc_product_sync_sender_settings');
        $val = isset($options['auto_skip_image_sync']) ? (bool)$options['auto_skip_image_sync'] : false;
        ?>
        <label><input type="checkbox" name="wc_product_sync_sender_settings[auto_skip_image_sync]" value="1" <?php echo $val?'checked':''; ?> /> Skip image sync in auto runs</label>
        <?php
    }

    public function auto_gallery_limit_callback() {
        $options = get_option('wc_product_sync_sender_settings');
        $val = isset($options['auto_gallery_limit']) ? intval($options['auto_gallery_limit']) : 0;
        ?>
        <input type="number" name="wc_product_sync_sender_settings[auto_gallery_limit]" value="<?php echo esc_attr($val); ?>" min="0" />
        <?php
    }

    public function auto_dry_run_callback() {
        $options = get_option('wc_product_sync_sender_settings');
        $val = isset($options['auto_dry_run']) ? (bool)$options['auto_dry_run'] : false;
        ?>
        <label><input type="checkbox" name="wc_product_sync_sender_settings[auto_dry_run]" value="1" <?php echo $val?'checked':''; ?> /> Dry run for auto sync</label>
        <?php
    }

    public function auto_compress_images_callback() {
        $options = get_option('wc_product_sync_sender_settings');
        // Default to true
        $val = isset($options['auto_compress_images']) ? (bool)$options['auto_compress_images'] : true;
        ?>
        <label><input type="checkbox" name="wc_product_sync_sender_settings[auto_compress_images]" value="1" <?php echo $val?'checked':''; ?> /> Send compressed 'large' image sizes instead of originals</label>
        <?php
    }

    public function receiver_sync_status_callback() {
        $options = get_option('wc_product_sync_sender_settings');
        $val = isset($options['receiver_sync_status']) ? (bool)$options['receiver_sync_status'] : false;
        ?>
        <label><input type="checkbox" name="wc_product_sync_sender_settings[receiver_sync_status]" value="1" <?php echo $val?'checked':''; ?> /> Update product status from Site A</label>
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
            <?php $options_banner = get_option('wc_product_sync_sender_settings'); $role_banner = isset($options_banner['site_role']) ? $options_banner['site_role'] : 'sender'; ?>
            <div class="notice notice-info"><p><?php echo $role_banner==='receiver' ? 'This site is configured as Receiver (Site B)' : 'This site is configured as Sender (Site A)'; ?></p></div>
            <form method="post" action="options.php">
                <?php
                settings_fields($this->option_name);
                do_settings_sections($this->option_name);
                $options = get_option('wc_product_sync_sender_settings');
                $role = isset($options['site_role']) ? $options['site_role'] : 'sender';
                ?>
                <h2>Site Role</h2>
                <p>
                    <label style="margin-right:12px;">
                        <input type="radio" name="wc_product_sync_sender_settings[site_role]" value="sender" <?php echo $role==='sender'?'checked':''; ?> /> Sender (Site A)
                    </label>
                    <label>
                        <input type="radio" name="wc_product_sync_sender_settings[site_role]" value="receiver" <?php echo $role==='receiver'?'checked':''; ?> /> Receiver (Site B)
                    </label>
                </p>
                <?php submit_button(); ?>
            </form>
            <hr>
            <?php $options = isset($options) ? $options : get_option('wc_product_sync_sender_settings'); $role = isset($options['site_role']) ? $options['site_role'] : 'sender'; if ($role === 'sender') { ?>
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
                <p>
                    <label>
                        <?php $comp = isset($options['auto_compress_images']) ? (bool)$options['auto_compress_images'] : true; ?>
                        <input type="checkbox" name="compress_images_sync" value="1" <?php echo $comp?'checked':''; ?> />
                        Compress Images (Send Large)
                    </label>
                </p>
                <p>
                    <label>
                        Limit gallery images per product (0 = all):
                        <input type="number" name="gallery_limit" value="0" min="0" />
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
            <?php } ?>
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
                var comp=f.querySelector('[name="compress_images_sync"]');
                var gl=f.querySelector('[name="gallery_limit"]');
                var data=new FormData();
                data.append('action','wc_product_sync_start');
                data.append('security','<?php echo wp_create_nonce('wc_product_sync'); ?>');
                data.append('dry_run',dry && dry.checked ? '1':'0');
                data.append('product_limit',lim && lim.value ? lim.value : '0');
                data.append('skip_image_sync',skip && skip.checked ? '1':'0');
                data.append('compress_images_sync',comp && comp.checked ? '1':'0');
                data.append('gallery_limit',gl && gl.value ? gl.value : '0');
                var logEl=document.getElementById('wcps-log'); if(logEl){logEl.value=''}
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
                        var etaMsg=(d.eta_seconds && d.eta_seconds>0) ? ('ETA '+formatEta(d.eta_seconds)) : (d.processed>0?'Estimating...':'');
                        document.getElementById('wcps-progress-count').textContent=d.processed+' / '+d.total + (etaMsg?(' — '+etaMsg):'');
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
                        var etaMsg=(d.eta_seconds && d.eta_seconds>0) ? ('ETA '+formatEta(d.eta_seconds)) : (d.processed>0?'Estimating...':'');
                        document.getElementById('wcps-progress-count').textContent=d.processed+' / '+d.total + (etaMsg?(' — '+etaMsg):'');
                        if(d.log){document.getElementById('wcps-log').value=d.log}
                        document.getElementById('wcps-progress-status').textContent=d.status;
                        if(d.status==='running'||d.status==='scheduled'){setStartEnabled(false);poll()} else {setStartEnabled(true)}
                    }
                });
            }
            function formatEta(s){s=Math.floor(s);var h=Math.floor(s/3600);var m=Math.floor((s%3600)/60);var sec=s%60;var out='';if(h>0){out+=h+'h '}out+=m+'m '+sec+'s';return out.trim()}
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
        if (isset($input['site_role'])) {
            $role = sanitize_text_field($input['site_role']);
            $output['site_role'] = ($role === 'receiver') ? 'receiver' : 'sender';
        }
        if (isset($input['auto_sync_enabled'])) { $output['auto_sync_enabled'] = $input['auto_sync_enabled'] ? 1 : 0; }
        if (isset($input['auto_sync_cron'])) { $output['auto_sync_cron'] = trim(sanitize_text_field($input['auto_sync_cron'])); }
        if (isset($input['auto_product_limit'])) { $output['auto_product_limit'] = absint($input['auto_product_limit']); }
        if (isset($input['auto_skip_image_sync'])) { $output['auto_skip_image_sync'] = $input['auto_skip_image_sync'] ? 1 : 0; }
        if (isset($input['auto_gallery_limit'])) { $output['auto_gallery_limit'] = absint($input['auto_gallery_limit']); }
        if (isset($input['auto_dry_run'])) { $output['auto_dry_run'] = $input['auto_dry_run'] ? 1 : 0; }
        $output['auto_compress_images'] = isset($input['auto_compress_images']) ? ($input['auto_compress_images'] ? 1 : 0) : 0;
        if (isset($input['receiver_sync_status'])) { $output['receiver_sync_status'] = $input['receiver_sync_status'] ? 1 : 0; }
        if (isset($input['price_markup_percent'])) { $p = floatval($input['price_markup_percent']); if ($p < 0) { $p = 0; } $output['price_markup_percent'] = $p; }
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
        update_option('wc_product_sync_sender_log', '');
        $dry = isset($_POST['dry_run']) && $_POST['dry_run'] == '1';
        $limit = isset($_POST['product_limit']) ? absint($_POST['product_limit']) : 0;
        $skip = isset($_POST['skip_image_sync']) && $_POST['skip_image_sync'] == '1';
        $compress = isset($_POST['compress_images_sync']) && $_POST['compress_images_sync'] == '1';
        $job = uniqid('sync_', true);
        $total = $this->count_products($limit);
        set_transient('wc_product_sync_progress_' . $job, array('status' => 'scheduled', 'total' => $total, 'processed' => 0, 'log' => '', 'user_id' => get_current_user_id()), 12 * HOUR_IN_SECONDS);
        update_user_meta(get_current_user_id(), 'wc_product_sync_current_job', $job);
        $gallery_limit = isset($_POST['gallery_limit']) ? absint($_POST['gallery_limit']) : 0;
        $batch_size = 20;
        wp_schedule_single_event(time() + 1, 'wc_product_sync_run_event', array($job, $dry, $limit, $skip, $gallery_limit, $batch_size, $compress));
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
        wp_remote_post(site_url('wp-cron.php'), array('timeout' => 0.01, 'blocking' => false));
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

    public function run_sync_event($job, $dry, $limit, $skip, $gallery_limit = 0, $batch_size = 20, $compress_manual = null) {
        $options = get_option('wc_product_sync_sender_settings');
        $compress = $compress_manual !== null ? $compress_manual : (isset($options['auto_compress_images']) ? (bool)$options['auto_compress_images'] : true);
        $shop_b_url = isset($options['shop_b_url']) ? trailingslashit($options['shop_b_url']) : '';
        $receiver_api_key = isset($options['shop_b_receiver_api_key']) ? $options['shop_b_receiver_api_key'] : '';
        $log = array();
        $allow_all = $this->receiver_supports_status($shop_b_url, $receiver_api_key);
        $total = $this->count_products($limit, $allow_all);
        $st0 = get_transient('wc_product_sync_progress_' . $job);
        $processed = ($st0 && isset($st0['processed'])) ? intval($st0['processed']) : 0;
        $started_at = ($st0 && isset($st0['started_at'])) ? intval($st0['started_at']) : time();
        $user_id = ($st0 && isset($st0['user_id'])) ? $st0['user_id'] : 0;
        set_transient('wc_product_sync_progress_' . $job, array('status' => 'running', 'total' => $total, 'processed' => $processed, 'log' => isset($st0['log']) ? $st0['log'] : '', 'user_id' => $user_id, 'started_at' => $started_at, 'eta_seconds' => isset($st0['eta_seconds']) ? $st0['eta_seconds'] : 0), 12 * HOUR_IN_SECONDS);
        $remaining = max(0, $total - $processed);
        if ($remaining === 0) {
            $st = get_transient('wc_product_sync_progress_' . $job);
            set_transient('wc_product_sync_progress_' . $job, array('status' => 'done', 'total' => $total, 'processed' => $processed, 'log' => isset($st['log']) ? $st['log'] : '', 'user_id' => isset($st['user_id']) ? $st['user_id'] : 0, 'started_at' => isset($st['started_at']) ? $st['started_at'] : time(), 'eta_seconds' => 0), 12 * HOUR_IN_SECONDS);
            $uid = isset($st['user_id']) ? intval($st['user_id']) : 0;
            if ($uid) { delete_user_meta($uid, 'wc_product_sync_current_job'); }
            return;
        }
        $page = min($batch_size, $remaining);
        $args = array('post_type' => 'product', 'orderby' => 'ID', 'order' => 'ASC');
        if ($allow_all) { $args['post_status'] = array('publish','draft','pending','private'); } else { $args['post_status'] = 'publish'; }
        $args['posts_per_page'] = $page;
        $args['offset'] = $processed;
        $posts = get_posts($args);
        foreach ($posts as $post) {
            $st = get_transient('wc_product_sync_progress_' . $job);
            if ($st && isset($st['status']) && $st['status'] === 'cancelled') {
                $uid = isset($st['user_id']) ? intval($st['user_id']) : 0;
                if ($uid) { delete_user_meta($uid, 'wc_product_sync_current_job'); }
                return;
            }
            $product = wc_get_product($post->ID);
            if (!$product) { $log[] = 'Skipping product ID ' . $post->ID; $processed++; $this->update_progress($job, $total, $processed, $log); continue; }
            $markup = isset($options['price_markup_percent']) ? floatval($options['price_markup_percent']) : 0;
            $rp = $product->get_regular_price();
            $sp = $product->get_sale_price();
            $rp_out = $rp;
            $sp_out = $sp;
            if ($markup > 0) {
                if (is_numeric($rp)) { $rp_out = number_format(floatval($rp) * (1 + ($markup/100)), 2, '.', ''); }
                if ($sp !== '' && is_numeric($sp)) { $sp_out = number_format(floatval($sp) * (1 + ($markup/100)), 2, '.', ''); }
            }
            $payload = array(
                'name' => $product->get_name(),
                'sku' => $product->get_sku(),
                'regular_price' => $rp_out,
                'sale_price' => $sp_out,
                'description' => $product->get_description(),
                'short_description' => $product->get_short_description(),
                'status' => method_exists($product, 'get_status') ? $product->get_status() : 'publish',
            );
            if (!$skip) {
                $images = array();
                $image_ids_to_process = array();
                
                $main_image_id = $product->get_image_id();
                if ($main_image_id) {
                    $image_ids_to_process[] = array('id' => $main_image_id, 'position' => 0);
                }
                
                $gallery_ids = $product->get_gallery_image_ids();
                if (!empty($gallery_ids)) {
                    if ($gallery_limit > 0) { $gallery_ids = array_slice($gallery_ids, 0, $gallery_limit); }
                    $position = 1;
                    foreach ($gallery_ids as $gid) {
                        $image_ids_to_process[] = array('id' => $gid, 'position' => $position);
                        $position++;
                    }
                }
                
                foreach ($image_ids_to_process as $img_task) {
                    $aid = $img_task['id'];
                    $pos = $img_task['position'];
                    $file_path = false;
                    $orig_path = get_attached_file($aid);
                    
                    if ($compress && $orig_path && file_exists($orig_path)) {
                        $meta = wp_get_attachment_metadata($aid);
                        $has_large = false;
                        
                        if (is_array($meta) && isset($meta['sizes']['large']['file'])) {
                            $large_path = dirname($orig_path) . '/' . $meta['sizes']['large']['file'];
                            if (file_exists($large_path)) {
                                $file_path = $large_path;
                                $has_large = true;
                            }
                        }
                        
                        // Dynamically generate 'large' thumbnail if missing and Image is big enough
                        if (!$has_large) {
                            $editor = wp_get_image_editor($orig_path);
                            if (!is_wp_error($editor)) {
                                $size = $editor->get_size();
                                $max_w = (int) get_option('large_size_w', 1024);
                                $max_h = (int) get_option('large_size_h', 1024);
                                
                                if ($max_w > 0 && $max_h > 0 && ((isset($size['width']) && $size['width'] > $max_w) || (isset($size['height']) && $size['height'] > $max_h))) {
                                    $editor->resize($max_w, $max_h, false);
                                    $resized = $editor->save();
                                    
                                    if (!is_wp_error($resized) && isset($resized['path'])) {
                                        $file_path = $resized['path'];
                                        if (!is_array($meta)) { $meta = array(); }
                                        if (!isset($meta['sizes'])) { $meta['sizes'] = array(); }
                                        $meta['sizes']['large'] = array(
                                            'file' => $resized['file'],
                                            'width' => $resized['width'],
                                            'height' => $resized['height'],
                                            'mime-type' => $resized['mime-type']
                                        );
                                        wp_update_attachment_metadata($aid, $meta);
                                        $log[] = 'Generated missing "large" size for attachment ' . $aid;
                                        $has_large = true;
                                    }
                                }
                            }
                        }
                        
                        if (!$has_large) {
                            $log[] = 'Note: Could not compress attachment ' . $aid . ' (maybe image is already small or format unsupported), using original.';
                        }
                    }
                    
                    if (!$file_path) {
                        $file_path = $orig_path;
                    }
                    
                    if ($file_path && file_exists($file_path)) {
                        $image_data = file_get_contents($file_path);
                        if ($image_data !== false) {
                            $images[] = array('filename' => basename($file_path), 'base64' => base64_encode($image_data), 'position' => $pos);
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
        if ($processed < $total) {
            $st = get_transient('wc_product_sync_progress_' . $job);
            if ($st && isset($st['status']) && $st['status'] === 'cancelled') {
                $uid = isset($st['user_id']) ? intval($st['user_id']) : 0;
                if ($uid) { delete_user_meta($uid, 'wc_product_sync_current_job'); }
                return;
            }
            wp_schedule_single_event(time() + 1, 'wc_product_sync_run_event', array($job, $dry, $limit, $skip, $gallery_limit, $batch_size, $compress_manual));
            wp_remote_post(site_url('wp-cron.php'), array('timeout' => 0.01, 'blocking' => false));
        } else {
            $st = get_transient('wc_product_sync_progress_' . $job);
            set_transient('wc_product_sync_progress_' . $job, array('status' => 'done', 'total' => $total, 'processed' => $processed, 'log' => implode("\n", $this->trim_log($log)), 'user_id' => isset($st['user_id']) ? $st['user_id'] : 0, 'started_at' => isset($st['started_at']) ? $st['started_at'] : time(), 'eta_seconds' => 0), 12 * HOUR_IN_SECONDS);
            $uid = isset($st['user_id']) ? intval($st['user_id']) : 0;
            if ($uid) { delete_user_meta($uid, 'wc_product_sync_current_job'); }
        }
    }

    private function update_progress($job, $total, $processed, $log) {
        $st = get_transient('wc_product_sync_progress_' . $job);
        
        // If the job has already been cancelled by the user during this processing step, do not overwrite it back to running.
        if ($st && isset($st['status']) && $st['status'] === 'cancelled') {
            return;
        }

        $uid = isset($st['user_id']) ? $st['user_id'] : 0;
        $started = isset($st['started_at']) ? intval($st['started_at']) : time();
        $eta = 0;
        if ($processed >= 5 && $total > 0) {
            $elapsed = time() - $started;
            if ($elapsed > 0) {
                $avg = $elapsed / max(1, $processed);
                $remaining = max(0, $total - $processed);
                $eta = (int) round($avg * $remaining);
            }
        }
        set_transient('wc_product_sync_progress_' . $job, array('status' => 'running', 'total' => $total, 'processed' => $processed, 'log' => implode("\n", $this->trim_log($log)), 'user_id' => $uid, 'started_at' => $started, 'eta_seconds' => $eta), 12 * HOUR_IN_SECONDS);
    }

    private function trim_log($log) {
        $max = 200;
        $count = count($log);
        if ($count > $max) { return array_slice($log, $count - $max); }
        return $log;
    }

    private function count_products($limit, $allow_all = false) {
        $args = array('post_type' => 'product');
        $args['post_status'] = $allow_all ? array('publish','draft','pending','private') : 'publish';
        if ($limit > 0) { $args['posts_per_page'] = $limit; } else { $args['posts_per_page'] = -1; }
        $posts = get_posts($args);
        return count($posts);
    }

    private function menu_title() {
        $options = get_option('wc_product_sync_sender_settings');
        $role = isset($options['site_role']) ? $options['site_role'] : 'sender';
        $label = $role === 'receiver' ? 'Product Sync (Receiver B)' : 'Product Sync (Sender A)';
        $job = get_user_meta(get_current_user_id(), 'wc_product_sync_current_job', true);
        if (!empty($job)) {
            $st = get_transient('wc_product_sync_progress_' . $job);
            if ($st && isset($st['status']) && ($st['status'] === 'running' || $st['status'] === 'scheduled')) {
                return $label . ' <span class="update-plugins count-1"><span class="plugin-count">1</span></span>';
            }
        }
        return $label;
    }

    public function add_cron_schedules($schedules) {
        $schedules['every_minute'] = array('interval' => 60, 'display' => 'Every Minute');
        return $schedules;
    }

    public function ensure_scheduler() {
        if (!wp_next_scheduled('wc_product_sync_scheduler_tick')) {
            wp_schedule_event(time(), 'every_minute', 'wc_product_sync_scheduler_tick');
        }
    }

    public function scheduler_tick() {
        $options = get_option('wc_product_sync_sender_settings');
        $role = isset($options['site_role']) ? $options['site_role'] : 'sender';
        if ($role !== 'sender') { return; }
        $enabled = isset($options['auto_sync_enabled']) && $options['auto_sync_enabled'];
        if (!$enabled) { return; }
        $expr = isset($options['auto_sync_cron']) ? trim($options['auto_sync_cron']) : '';
        if ($expr === '') { return; }
        $now = current_time('timestamp');
        if (!$this->cron_matches($now, $expr)) { return; }
        $minute_key = gmdate('YmdHi', $now);
        $last_key = get_option('wc_product_sync_last_run_min', '');
        if ($last_key === $minute_key) { return; }
        $auto_job = get_option('wc_product_sync_auto_job', '');
        if (!empty($auto_job)) {
            $st = get_transient('wc_product_sync_progress_' . $auto_job);
            if ($st && isset($st['status']) && ($st['status'] === 'running' || $st['status'] === 'scheduled')) { return; }
        }
        $dry = isset($options['auto_dry_run']) && $options['auto_dry_run'];
        $limit = isset($options['auto_product_limit']) ? absint($options['auto_product_limit']) : 0;
        $skip = isset($options['auto_skip_image_sync']) && $options['auto_skip_image_sync'];
        $gallery_limit = isset($options['auto_gallery_limit']) ? absint($options['auto_gallery_limit']) : 0;
        $job = uniqid('auto_', true);
        $allow_all = $this->receiver_supports_status(isset($options['shop_b_url']) ? trailingslashit($options['shop_b_url']) : '', isset($options['shop_b_receiver_api_key']) ? $options['shop_b_receiver_api_key'] : '');
        $total = $this->count_products($limit, $allow_all);
        set_transient('wc_product_sync_progress_' . $job, array('status' => 'scheduled', 'total' => $total, 'processed' => 0, 'log' => '', 'user_id' => 0), 12 * HOUR_IN_SECONDS);
        update_option('wc_product_sync_auto_job', $job);
        update_option('wc_product_sync_last_run_min', $minute_key);
        $batch_size = 20;
        wp_schedule_single_event(time() + 1, 'wc_product_sync_run_event', array($job, $dry, $limit, $skip, $gallery_limit, $batch_size, null));
    }

    private function cron_matches($ts, $expr) {
        $parts = preg_split('/\s+/', trim($expr));
        if (count($parts) !== 5) { return false; }
        $m_ok = $this->cron_field_match(intval(gmdate('i', $ts)), $parts[0], 0, 59);
        $h_ok = $this->cron_field_match(intval(gmdate('G', $ts)), $parts[1], 0, 23);
        $dom_ok = $this->cron_field_match(intval(gmdate('j', $ts)), $parts[2], 1, 31);
        $mon_ok = $this->cron_field_match(intval(gmdate('n', $ts)), $parts[3], 1, 12);
        $dow_ok = $this->cron_field_match(intval(gmdate('w', $ts)), $parts[4], 0, 6);
        $dom_star = trim($parts[2]) === '*';
        $dow_star = trim($parts[4]) === '*';
        if (!$m_ok || !$h_ok || !$mon_ok) { return false; }
        if (!$dom_star && !$dow_star) { return ($dom_ok || $dow_ok); }
        return ($dom_ok && $dow_ok) || ($dom_star && $dow_star);
    }

    private function cron_field_match($val, $expr, $min, $max) {
        $expr = trim($expr);
        if ($expr === '*') { return true; }
        $list = explode(',', $expr);
        foreach ($list as $item) {
            $item = trim($item);
            if ($item === '') { continue; }
            if (strpos($item, '/') !== false) {
                $parts = explode('/', $item, 2);
                $base = $parts[0];
                $step = max(1, intval($parts[1]));
                $range = ($base === '*') ? array($min, $max) : $this->parse_range($base, $min, $max);
                if (!$range) { continue; }
                for ($i = $range[0]; $i <= $range[1]; $i += $step) { if ($val === $i) { return true; } }
            } elseif (strpos($item, '-') !== false) {
                $range = $this->parse_range($item, $min, $max);
                if ($range && $val >= $range[0] && $val <= $range[1]) { return true; }
            } else {
                if ($val === intval($item)) { return true; }
            }
        }
        return false;
    }

    private function parse_range($s, $min, $max) {
        $parts = explode('-', $s, 2);
        if (count($parts) !== 2) { return null; }
        $a = intval($parts[0]);
        $b = intval($parts[1]);
        if ($a < $min) { $a = $min; }
        if ($b > $max) { $b = $max; }
        if ($a > $b) { return null; }
        return array($a, $b);
    }

    private function receiver_supports_status($shop_b_url, $receiver_api_key) {
        if (empty($shop_b_url) || empty($receiver_api_key)) { return false; }
        $endpoint = trailingslashit($shop_b_url) . 'wp-json/product-sync/v1/config';
        $resp = wp_remote_get($endpoint, array('headers' => array('X-Product-Sync-Key' => $receiver_api_key), 'timeout' => 10));
        if (is_wp_error($resp)) { return false; }
        $code = wp_remote_retrieve_response_code($resp);
        if ($code < 200 || $code >= 300) { return false; }
        $body = wp_remote_retrieve_body($resp);
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) { return false; }
        return !empty($decoded['sync_status']);
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
        wp_remote_post(site_url('wp-cron.php'), array('timeout' => 0.01, 'blocking' => false));
        if (!$st) {
            delete_user_meta(get_current_user_id(), 'wc_product_sync_current_job');
            wp_send_json_success(array('status' => 'idle'));
        }
        wp_send_json_success(array('job_id' => $job, 'status' => isset($st['status']) ? $st['status'] : 'unknown', 'total' => isset($st['total']) ? $st['total'] : 0, 'processed' => isset($st['processed']) ? $st['processed'] : 0, 'log' => isset($st['log']) ? $st['log'] : '', 'eta_seconds' => isset($st['eta_seconds']) ? $st['eta_seconds'] : 0, 'started_at' => isset($st['started_at']) ? $st['started_at'] : 0));
    }
}

new WC_Product_Sync_Send_Receive();

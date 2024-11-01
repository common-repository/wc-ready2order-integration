<?php
/**
 * The main plugin file for Integration of ready2order with WooCommerce.
 *
 * This file is included during the WordPress bootstrap process if the plugin is active.
 *
 * @package   WC_Ready2order_Integration
 * @author    BjornTech
 * @license   GPL-3.0
 * @link      https://bjorntech.com
 * @copyright 2021 BjornTech AB
 *
 * @wordpress-plugin
 * Plugin Name:       Integration of ready2order with WooCommerce
 * Plugin URI:        https://www.bjorntech.com/woocommerce-ready-to-order/?utm_source=wp-ready2order&utm_medium=plugin&utm_campaign=product
 * Description:       Synchronizes products, purchases and stock-levels.
 * Version:           2.1.6
 * Author:            BjornTech
 * Author URI:        https://www.bjorntech.com/?utm_source=wp-ready-to-order&utm_medium=plugin&utm_campaign=product
 * Text Domain:       wc-ready2order-integration
 *
 * WC requires at least: 4.0
 * WC tested up to: 9.3
 *
 * Copyright:         2021 BjornTech AB
 * License:           GPLv2
 * License URI:       http://www.gnu.org/licenses/gpl-3.0.html
 */

defined('ABSPATH') || exit;

define('WC_READY_TO_ORDER_MIN_WC_VER', '4.0');

/**
 * WooCommerce fallback notice.
 *
 * @since 1.0.0
 * @return string
 */

if (!function_exists('woocommerce_ready2order_integration_missing_wc_notice')) {
    function woocommerce_ready2order_integration_missing_wc_notice()
    {
        /* translators: 1. URL link. */
        echo '<div class="error"><p><strong>' . sprintf(esc_html__('ready2order integration requires WooCommerce to be installed and active. You can download %s here.', 'wc-ready2order-integration'), '<a href="https://woocommerce.com/" target="_blank">WooCommerce</a>') . '</strong></p></div>';
    }
}

if (
    !class_exists(PluginModule::class)
    && file_exists(__DIR__ . '/vendor/autoload.php')
) {
    include_once __DIR__ . '/vendor/autoload.php';
}

class WC_Ready2order_Integration
{

    /**
     * Plugin data
     */
    const NAME = 'WooCommerce ready2order Integration';
    const VERSION = '2.1.6';
    const SCRIPT_HANDLE = 'wc-ready-to-order';
    const PLUGIN_FILE = __FILE__;

    public $plugin_basename;
    public $includes_dir;
    public $external_dir;
    public $assets_url;
    public $vendor_dir;
    public $version;

    /**
     *    $instance
     *
     * @var    mixed
     * @access public
     * @static
     */
    public static $instance = null;

    private static $shutDownCalled = false;

    public function __construct()
    {
        $this->plugin_basename = plugin_basename(self::PLUGIN_FILE);
        $this->includes_dir = plugin_dir_path(self::PLUGIN_FILE) . 'includes/';
        $this->external_dir = plugin_dir_path(self::PLUGIN_FILE) . 'external/';
        $this->assets_url = trailingslashit(plugins_url('assets', self::PLUGIN_FILE));
        $this->vendor_dir = plugin_dir_path(self::PLUGIN_FILE) . 'vendor/';
        $this->version = self::VERSION;

        $this->includes();

        add_action('plugins_loaded', array($this, 'maybe_load_plugin'));
        add_action('before_woocommerce_init', array($this, 'declare_hpos_compatible'));
    }

    public function includes()
    {
        include_once 'includes/class-wr2o-logger.php';
        include_once 'includes/class-wr2o-stocklevel-handler.php';
        include_once 'includes/class-wr2o-service-object.php';
    }

    public function include_settings($settings)
    {
        $settings[] = include $this->includes_dir . 'class-wr2o-settings.php';
        return $settings;
    }

    public function declare_hpos_compatible()
    {
        if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
        }
    }

    public function maybe_load_plugin()
    {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', 'woocommerce_ready2order_integration_missing_wc_notice');
            return;
        }

        include_once 'includes/class-wr2o-exception.php';

        include_once 'includes/class-wr2o-integration-authorization.php';

        include_once 'includes/class-wr2o-api.php';
        include_once 'includes/class-wr2o-notice.php';
        include_once 'includes/class-wr2o-helper.php';
        include_once 'includes/class-wr2o-woocommerce-product-handler.php';
        include_once 'includes/class-w2ro-ready2order-productgroup-handler.php';
        include_once 'includes/class-wr2o-woocommerce-product-admin.php';
        include_once 'includes/class-wr2o-ready2order-product-handler.php';

        include_once 'includes/class-wr2o-ready2order-invoice-document-handler.php';
        include_once 'includes/class-wr2o-ready2order-invoice-document.php';
        include_once 'includes/class-wr2o-ready2order-invoice-handler.php';
        include_once 'includes/class-wr2o-woocommerce-document-admin.php';

        add_filter('woocommerce_get_settings_pages', array($this, 'include_settings'));
        add_action('wp_ajax_wr2o_connect', array($this, 'ajax_connect'));
        add_action('admin_enqueue_scripts', array($this, 'admin_add_styles_and_scripts'));
        add_action('woocommerce_api_ready2order', array($this, 'ready2order_api_callback'));
        add_action('wp_ajax_ready2order_clear_notice', array($this, 'ajax_clear_notice'));
        add_action('wp_ajax_wr2o_processing_button', array($this, 'ajax_wr2o_processing_button'));

        if (is_admin()) {
            add_action('admin_notices', array($this, 'generate_messages'), 50);
            add_action('wp_ajax_wr2o_refresh', array($this, 'ajax_refresh'));
        }

        add_action('shutdown', array($this, 'shutdown'));

    }

    public function admin_add_styles_and_scripts($pagehook)
    {
        wp_register_style('wr2o-admin-style', plugin_dir_url(__FILE__) . 'resources/css/wr2o-stylesheet.css', array(), $this->version);
        wp_enqueue_style('wr2o-admin-style');

        wp_enqueue_script('wr2o-admin-script', plugin_dir_url(__FILE__) . 'resources/js/wr2o-admin-script.js', array('jquery'), $this->version, true);

        wp_localize_script('wr2o-admin-script', 'wr2o_admin_data', array(
            'nonce' => wp_create_nonce('ajax-wr2o-admin'),
        ));
    }

    /**
     * Returns a new instance of self, if it does not already exist.
     *
     * @access public
     * @static
     * @return WC_Ready2order_Integration
     */
    public static function instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function alternate_url($url, $path, $orig_scheme, $blog_id)
    {
        if ($alternate_url = get_option('bjorntech_alternate_webhook_url')) {
            $url = $alternate_url . $path;
        }
        return $url;
    }

    /**
     * Check if the e-mail has been filled in and saved before requesting authorization (call from js)
     */
    public function ajax_connect()
    {
        if (!wp_verify_nonce($_POST['nonce'], 'ajax-wr2o-admin')) {
            wp_die();
        }

        if (($user_email = get_option('wr2o_user_email')) == sanitize_email($_POST['user_email'])) {
            set_site_transient('ready2order_connect_nonce', $nonce = wp_create_nonce('ready2order_connect_nonce'), DAY_IN_SECONDS);

            add_filter('home_url', array($this, 'alternate_url'), 10, 4);
            add_filter('site_url', array($this, 'alternate_url'), 10, 4);

            $response = array(
                'state' => add_query_arg(
                    array(
                        'plugin_version' => $this->version,
                        'website' => get_site_url(),
                        'user_email' => $user_email,
                        'api_url' => WC()->api_request_url('ready2order', true),
                        'nonce' => $nonce,
                    ),
                    Wr2o_API::get_api_endpoint() . 'authorize'
                ),
                'message' => __('I agree to the BjornTech Privacy Policy', 'wc-ready2order-integration'),
            );

            remove_filter('home_url', array($this, 'alternate_url'), 10);
            remove_filter('site_url', array($this, 'alternate_url'), 10);
        } else {
            $response = array(
                'state' => 'error',
                'message' => __('Enter user-email and save before connecting to the service', 'wc-ready2order-integration'),
            );
        }

        wp_send_json($response);
    }

    public static function add_action_links($links)
    {
        $links = array_merge(array(
            '<a href="' . admin_url('admin.php?page=wc-settings&tab=ready2order') . '">' . __('Settings', 'wc-ready2order-integration') . '</a>',
        ), $links);

        return $links;
    }

    public function registerShutdownHook($request): void
    {
        add_action(
            'shutdown',
            function () use ($request): void {
                if (self::$shutDownCalled) {
                    return;
                }
                $this->handle_webhook_call_rest($request);
                self::$shutDownCalled = true;
            }
        );
    }

    public function handle_webhook_call_rest($request) {
        $resource = Wr2o_Helper::clean_array($request['resource']);

        if (isset($resource['product_id'])) {
            
            Wr2o_Logger::add(sprintf('ready2order_api_callback: ready2order product %s update received via webhook', $resource['product_id']));
            Wr2o_Logger::add(sprintf('ready2order_api_callback: - %s', json_encode($resource)));
            if (get_site_transient('wr2o_product_import_' . $resource['product_id']) === $resource['product_updated_at']){
                Wr2o_Logger::add(sprintf('ready2order_api_callback: ready2order product %s update for already received - %s',$resource['product_id'],$resource['product_updated_at']));
                return;
            }

            set_site_transient('wr2o_product_import_' . $resource['product_id'] , $resource['product_updated_at'], MINUTE_IN_SECONDS);

            if(!empty($fresh_resource = Wr2o_API::get("products/" . $resource['product_id']))) {
                Wr2o_Logger::add('ready2order_api_callback: Fetching fresh resource');
                $resource = $fresh_resource;
            }

            $product_id = Wr2o_Helper::get_wc_product_id_from_resource($resource);

            if (!$product_id || (($product = wc_get_product($product_id)) && $resource['product_updated_at'] != ($updated_at = $product->get_meta('_r2o_product_updated_at', true, 'edit')))) {
                $import_actions = get_option('wr2o_products_import_actions', 'update');

                if ('yes' == get_option('wr2o_products_import_automatically') && !empty($import_actions)) {
                    Wr2o_Logger::add('ready2order_api_callback: product from webhook sent to "wr2o_process_ready2order_products_import_add"');
                    do_action('wr2o_process_ready2order_products_import_add', $resource['product_id'], $import_actions);
                } elseif ('yes' == get_option('wr2o_products_overwrite_stocklevel_in_woocommerce')) {
                    Wr2o_Logger::add('ready2order_api_callback: stocklevel from webhook sent to "wr2o_overwrite_stocklevel_in_woocommerce_add"');
                    do_action('wr2o_overwrite_stocklevel_in_woocommerce_add', $resource);
                } else {
                    Wr2o_Logger::add('ready2order_api_callback: product from webhook ignored');
                    do_action('wr2o_process_ready2order_product_ignore', $resource, $import_actions);
                }

            } elseif ($product) {
                Wr2o_Logger::add(sprintf('ready2order_api_callback (%s): product already updated at %s from ready2order', $product_id, $updated_at));
            }
        } elseif (isset($resource['productgroup_id'])) {
            $import_actions = get_option('wr2o_productgroup_import_actions', 'update');

            if ('yes' == get_option('wr2o_productgroup_import_automatically') && !empty($import_actions)) {
                Wr2o_Logger::add('ready2order_api_callback: received productgroup from webhook');
                do_action('wr2o_process_ready2order_productgroup_import_add', $resource, $import_actions);
            } else {
                Wr2o_Logger::add('ready2order_api_callback: productgroup from webhook ignored');
                do_action('wr2o_process_ready2order_productgroup_ignore', $resource, $import_actions);
            }
        }
    }

    public function ready2order_api_callback()
    {

        if (isset($_REQUEST['resource'])) {

            $request = $_REQUEST;
            Wr2o_Logger::add('ready2order_api_callback: Initiating new webhook handler');
            $this->registerShutdownHook($request);

            return;

        } elseif (isset($_REQUEST['nonce'])) {
            $nonce = sanitize_text_field($_REQUEST['nonce']);
            $uuid = sanitize_text_field($_REQUEST['uuid']);
            if ($nonce == get_site_transient('ready2order_connect_nonce')) {
                Wr2o_Logger::add(sprintf('ready2order_api_callback: Nonce %s for UUID %s verified', $nonce, $uuid));
                update_option('ready2order_uuid', $uuid);
            } else {
                status_header(400);
                Wr2o_Logger::add(sprintf('ready2order_api_callback: Nonce %s NOT verified', $nonce));
            }
        } elseif(isset($_REQUEST['bjorntech_test_message'])){
            Wr2o_Logger::add('ready2order_api_callback: received test message from webhook');
            Wr2o_API::force_connection();
        } else {
            Wr2o_Logger::add('ready2order_api_callback: Nonce is missing');
        }

    }

    public function ajax_clear_notice()
    {
        if (!wp_verify_nonce($_POST['nonce'], 'ajax-wr2o-admin')) {
            wp_die();
        }

        if (isset($_POST['parents'])) {
            $id = sanitize_key(substr($_POST['parents'], strpos($_POST['parents'], 'id-')));
            Wr2o_Notice::clear($id);
        }
        $response = array(
            'status' => 'success',
        );

        wp_send_json($response);
    }

    public function ajax_wr2o_processing_button()
    {
        if (!wp_verify_nonce($_POST['nonce'], 'ajax-wr2o-admin')) {
            wp_die();
        }

        $id = sanitize_key($_POST['id']);

        //$processing_queue = Wr2o_Helper::get_processing_queue($id);
        $processing_queue = Wr2o_Helper::get_processing_queue('wr2o_process_' . $id);

        $queue_lenght = count($processing_queue);

        $display_name = Wr2o_Helper::display_name($id);

        $filter = 'wr2o_process_' . $id . '_all';

        if ('start' == $_POST['task'] && 0 == $queue_lenght) {
            Wr2o_Logger::add(sprintf('ajax_wr2o_processing_button: Applying "%s"', $filter));

            if (0 == ($number_synced = apply_filters($filter, 0, true))) {
                $response = array(
                    'status' => 'success',
                    'ready' => true,
                    'message' => sprintf(__('No %s found.', 'wc-ready2order-integration'), $display_name),
                );
            } else {
                $response = array(
                    'status' => 'success',
                    'button_text' => __('Cancel', 'wc-ready2order-integration'),
                    'message' => sprintf(__('Added %s %s to the queue.', 'wc-ready2order-integration'), $number_synced, $display_name),
                );
            }
        } elseif ('start' == $_POST['task']) {
            as_unschedule_all_actions($filter);
            as_unschedule_all_actions('wr2o_process_' . $id);
            $response = array(
                'status' => 'success',
                'button_text' => __('Start', 'wc-ready2order-integration'),
                'ready' => true,
                'message' => sprintf(__('Successfully removed %s %s from the queue.', 'wc-ready2order-integration'), $queue_lenght, $display_name),
            );
        } elseif (0 != $queue_lenght) {
            $response = array(
                'status' => 'success',
                'button_text' => __('Cancel', 'wc-ready2order-integration'),
                'status_message' => sprintf(__('%s %s in queue - click "Cancel" to clear queue.', 'wc-ready2order-integration'), $queue_lenght, $display_name),
                'message' => sprintf(__('%ss have been added to the queue.', 'wc-ready2order-integration'), $display_name),
            );
        } else {
            $response = array(
                'status' => 'success',
                'ready' => true,
                'button_text' => __('Start', 'wc-ready2order-integration'),
                'message' => sprintf(__('%s finished', 'wc-ready2order-integration'), $display_name),
            );
        }

        wp_send_json($response);
    }

    /**
     * Generate messages to admin users
     *
     * @since 1.5.0
     *
     */
    public function generate_messages()
    {
        $is_valid_to = Wr2o_Service_Handler::get_valid_to();
        $is_trial = Wr2o_Service_Handler::get_is_trial();
        $webhook_status = get_option('ready2order_webhook_status');
        $uuid = get_option('ready2order_uuid');

        if ($is_valid_to && intval($is_valid_to) < intval(time())) {
            $message = sprintf(__('<strong>Your ready2order subscription expired %s</strong>. You need to purchase a subscription in our <a href="%s">webshop.</a> in order to get the sync working again.', 'wc-ready2order-integration'), date("Y-m-d h:i", $is_valid_to), 'https://www.bjorntech.com/product/integration-of-ready2order-with-woocommerce/?token=' . $uuid . '&utm_source=wp-ready2order&utm_medium=plugin&utm_campaign=product');
            Wr2o_Notice::add($message, 'warning', 'expire_warning', false);
        } else {
            Wr2o_Notice::clear('expire_warning');
        }

        if ($is_trial && wc_string_to_bool($is_trial) === 'YES' && !get_site_transient('wr2o_did_show_trial_info')) {
            $message = sprintf(__('To start using the WooCommerce ready2order Integration, <a href="%s">go to the plugin settings page.</a>', 'wc-ready2order-integration'), get_admin_url(null, 'admin.php?page=wc-settings&tab=ready2order'));
            Wr2o_Notice::add($message, 'info','trial');
            set_site_transient('wr2o_did_show_trial_info', 'trial');

        }else{
            Wr2o_Notice::clear('trial');
        }

        if ('ERROR' === $webhook_status) {
            $message = sprintf(__('The ready2order service was not able to reach your site (needed for updates from ready2order). Remove any "coming soon" plugins, enable https and try to <a href="%s">refresh the connection.</a> If you still experience problems contact us at hello@bjorntech.com', 'wc-ready2order-integration'), get_admin_url(null, 'admin.php?page=wc-settings&tab=ready2order&section=advanced'));
            Wr2o_Notice::add($message, 'error', 'webhook_error', false);
        } else {
            Wr2o_Notice::clear('webhook_error');
        }

        Wr2o_Notice::clear('beta_info');

    }

    public function ajax_refresh()
    {
        if (!wp_verify_nonce($_POST['nonce'], 'ajax-wr2o-admin')) {
            wp_die();
        }

        Wr2o_Logger::add('Refresh requested');
        Wr2o_Notice::clear();

        Wr2o_Helper::clear_cache();

        try {
            Wr2o_API::force_connection();
            Wr2o_Notice::add('ready2order: New token requested from service', 'success');
        } catch (Wr2o_API_Exception $e) {
            $e->write_to_logs();
            Wr2o_Notice::add('ready2order: Error when connecting to service, check logs', 'error');
        }

        wp_send_json_success();
    }

    public function shutdown()
    {
        if ('yes' == get_option('wr2o_manual_cron')) {
            do_action('action_scheduler_run_queue');
        }
    }
}

/**
 * Add link to settings in the plugin page
 */
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'WC_Ready2order_Integration::add_action_links');

/**
 * Instantiate
 */
$ready_to_order = WC_Ready2order_Integration::instance();

<?php

/**
 * This class contains common functions for handling WooCommerce products.
 *
 * @author    BjornTech <hello@bjorntech.com>
 * @license   GPL-3.0
 *
 * @see      http://bjorntech.com
 *
 * @copyright 2021 BjornTech
 */
defined('ABSPATH') || exit;

if (!class_exists('Wr2o_WooCommerce_Product_Admin', false)) {
    class Wr2o_WooCommerce_Product_Admin
    {
        public function __construct()
        {
            /*
             * Adding columns inclusive Sync button product list page
             */
            add_filter('manage_edit-product_columns', [$this, 'product_header'], 20);
            add_action('manage_product_posts_custom_column', [$this, 'product_content']);
            add_action('wp_ajax_wr2o_update_product', [$this, 'update_product']);

            add_filter('manage_edit-product_variation_columns', [$this, 'product_variation_header'], 20);
            add_action('manage_product_variation_posts_custom_column', [$this, 'product_variation_content']);

            add_action('woocommerce_product_data_panels', array($this, 'show_ready2order_fields'), 10);
            add_action('woocommerce_product_after_variable_attributes', array($this, 'show_ready2order_fields_variable'), 30, 3);
            add_action('woocommerce_admin_process_product_object', array($this, 'save_product'));
            add_action('woocommerce_admin_process_variation_object', array($this, 'save_product_variation'), 10, 2);
            add_filter('woocommerce_product_data_tabs', array($this, 'product_data_tab'), 50, 1);
        }

        public function product_variation_header($columns)
        {
            $columns = array_merge($columns, ['wr2o_update_product_variation' => __('ready2order', 'wc-ready2order-integration')]);

            return $columns;
        }

        public function product_variation_content($column)
        {
            global $post;

            if ('wr2o_update_product_variation' === $column) {
                echo '<a class="button wc-action-button wr2o_update_product" name="wr2o_update_product" data-product-id="'.esc_html($post->ID).'">'.__('Update', 'wc-ready2order-integration').'</a>';
            }
        }

        public function product_header($columns)
        {
            $columns = array_merge($columns, ['wr2o_update_product' => __('ready2order', 'wc-ready2order-integration')]);

            return $columns;
        }

        public function product_content($column)
        {
            global $post;

            if ('wr2o_update_product' === $column) {
                echo '<a>'.get_post_meta($post->ID, '_r2o_product_id', true).'</a>';
                //   echo '<a class="button wc-action-button wr2o_update_product" name="wr2o_update_product" data-product-id="' . esc_html($post->ID) . '">' . __('Update', 'wc-ready2order-integration') . '</a>';
            }
        }

        public function update_product()
        {
            if (!wp_verify_nonce($_POST['nonce'], 'ajax-wr2o-admin')) {
                wp_die();
            }

            $product_id = sanitize_key($_POST['product_id']);

            $product = wc_get_product($product_id);

            try {
                do_action('wr2o_sync_wc_products_process', $product_id);
            } catch (Wr2o_API_Exception $e) {
                $e->write_to_logs();
                Wr2o_Notice::add(sprintf(__('ready2order: %s when manually syncing product %s', 'wc-ready2order-integration'), $e->getMessage(), $product_id));
            }

            wp_send_json(true);
        }

        public function save_product($product)
        {

            $product->update_meta_data('_ready2order_nosync', isset($_POST['_ready2order_nosync']) ? wc_clean(wp_unslash($_POST['_ready2order_nosync'])) : '');

        }

        public function save_product_variation($variation, $i)
        {
            $variation->update_meta_data('_ready2order_nosync', isset($_POST["_ready2order_nosync_{$i}"]) ? $_POST["_ready2order_nosync_{$i}"] : '');
        }

        public function show_ready2order_fields()
        {

            global $post, $thepostid, $product_object;
            include 'views/html-product-data-inventory.php';

        }

        public function show_ready2order_fields_variable($loop, $variation_data, $variation)
        {
            global $thepostid;
            if ($this->is_default_language($thepostid)) {
                include 'views/html-product-data-inventory-variable.php';
            }
        }


        public function product_data_tab($tabs)
        {

            global $thepostid;

            if ($this->is_default_language($thepostid)) {
                $tabs['ready2order'] = array(
                    'label' => __('ready2order', 'wc-ready2order-integration'),
                    'target' => 'ready2order_product_data',
                    'class' => array('show_if_simple', 'show_if_variable'),
                );
            }

            return $tabs;
        }

        private function is_default_language($product_id)
        {

            $language = Wr2o_Helper::product_language($product_id);
            $wpml_default_language = get_option('wr2o_wpml_default_language', apply_filters('wpml_default_language', null));
            return $language == $wpml_default_language;

        }
    }

    new Wr2o_WooCommerce_Product_Admin();
}

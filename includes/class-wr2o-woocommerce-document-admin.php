<?php

/**
 * This class contains common functions for creating invoices and orders
 *
 * @package   WooCommerce_Ready_To_Order_Integration
 * @author    BjornTech <hello@bjorntech.com>
 * @license   GPL-3.0
 * @link      http://bjorntech.com
 * @copyright 2017-2020 BjornTech
 */

defined('ABSPATH') || exit;

if (!class_exists('Wr2o_WooCommerce_Document_Admin', false)) {

    class Wr2o_WooCommerce_Document_Admin
    {

        public function __construct()
        {

            if (class_exists('Automattic\WooCommerce\Utilities\OrderUtil')) {
                $hpos_enabled = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
            } else {
                $hpos_enabled = false;
            }

            if ($hpos_enabled) {

                $screen_id = 'woocommerce_page_wc-orders';

                add_filter('manage_' . $screen_id . '_columns', array($this, 'document_number_header'), 20);
                add_action('manage_' . $screen_id . '_custom_column', array($this, 'invoice_number_content'), 10, 2);
                add_filter('bulk_actions-' . $screen_id, array($this, 'define_bulk_actions'));
                add_filter('handle_bulk_actions-' . $screen_id, array($this, 'handle_bulk_actions'), 10, 3);

            } else {
                add_filter('manage_edit-shop_order_columns', array($this, 'document_number_header'), 20);
                add_action('manage_shop_order_posts_custom_column', array($this, 'invoice_number_content'), 10, 2);
                add_filter('handle_bulk_actions-edit-shop_order', array($this, 'handle_bulk_actions'), 10, 3);
                add_filter('bulk_actions-edit-shop_order', array($this, 'define_bulk_actions'));
            }

        }

        public function order_meta_general($order)
        {

            $ready2order_id = $order->get_id() ? Wr2o_Helper::get_ready2order_invoice_number($order->get_id()) : '' ;
            echo '<br class="clear" />';
            echo '<h4>ready2order invoice</h4>';
            echo '<div class="address">';
            echo '<p>' . $ready2order_id . '</p>';
            echo '</div>';

        }

        public function document_number_header($columns)
        {

            $creates = get_option('wr2o_enable_invoice_export') == 'yes';

            $new_columns = array();

            foreach ($columns as $column_name => $column_info) {

                $new_columns[$column_name] = $column_info;

                if ('order_number' === $column_name) {

                    if($creates){
                        $new_columns['ready2order_invoice_number'] = __('ready2order invoice', 'woo-fortnox-hub');
                    }
                }
            }

            return $new_columns;

        }

        public function invoice_number_content($column, $order_id)
        {
            $order = wc_get_order($order_id);

            if(!$order){
                return;
            }

            if ('ready2order_invoice_number' == $column) {
                $wr2o_invoice = Wr2o_Helper::get_ready2order_full_invoice_number($order_id);

                echo sprintf('%s', $wr2o_invoice ? $wr2o_invoice : '-');
            }

        }

        public function handle_bulk_actions($redirect_to, $action, $ids)
        {
            if ('ready2order_sync_invoice' == $action) {
                foreach (array_reverse($ids) as $order_id) {
                    if (is_admin() && ('yes' !== get_option('wr2o_queue_admin_requests'))) { 
                        do_action('w2ro_processing_invoice',$order_id);
                    } else {
                        Wr2o_Helper::add_to_queue('w2ro_processing_invoice', array($order_id), 'handle_bulk_actions', 'ready2order invoice', $order_id);
                    }

                }
            }

            if ('ready2order_clean_invoice' == $action) {
                foreach (array_reverse($ids) as $order_id) {
                    if (is_admin() && ('yes' !== get_option('wr2o_queue_admin_requests'))) { 
                        do_action('w2ro_clean_invoice',$order_id);
                    } else {
                        Wr2o_Helper::add_to_queue('w2ro_clean_invoice', array($order_id), 'handle_bulk_actions', 'ready2order invoice', $order_id);
                    }
                }
            }

            return $redirect_to;
        }

        public function define_bulk_actions($actions)
        {
            if (get_option('wr2o_enable_invoice_export') == 'yes') {
                $actions['ready2order_sync_invoice'] = __('Create ready2order invoice from WooCommerce order', 'woo-fortnox-hub');
                $actions['ready2order_clean_invoice'] = __('Clean ready2order invoice from WooCommerce order', 'woo-fortnox-hub');
            }
            return $actions;
        }

    }

    new Wr2o_WooCommerce_Document_Admin();
}

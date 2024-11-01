<?php

/**
 * This class contains common functions for creating ready2order invoices
 *
 * @package   WooCommerce_Ready_To_Order_Integration
 * @author    BjornTech <hello@bjorntech.com>
 * @license   GPL-3.0
 * @link      http://bjorntech.com
 * @copyright 2017-2020 BjornTech
 */

defined('ABSPATH') || exit;

if (!class_exists('Wr2o_Ready2order_Invoice_Document_Handler', false)) {

    class Wr2o_Ready2order_Invoice_Document_Handler
    {

        public function __construct()
        {

            if (get_option('wr2o_enable_invoice_export') == 'yes') {

                if (get_option('wr2o_products_overwrite_stocklevel_in_ready2order') == 'yes') {
                    add_filter('woocommerce_payment_complete_reduce_order_stock', array($this, 'block_order_stock_reduction'),20,2);
                    add_filter('woocommerce_prevent_adjust_line_item_product_stock', array($this, 'adjust_line_item_product_stock'), 10, 3);
                }

                if ($order_status = get_option('wr2o_woo_order_create_automatic_from')) {
                    add_action('woocommerce_order_status_' . $order_status, array($this, 'processing_document'), 30);
                }
            }
        }

        public function processing_document($order_id)
        {

            if (is_admin() && ('yes' !== get_option('wr2o_queue_admin_requests'))) {
                Wr2o_Logger::add(sprintf('processing_document (%s): Creating ready2order invoice', $order_id));

                do_action('w2ro_processing_invoice', $order_id);
            } else {
                Wr2o_Helper::add_to_queue('w2ro_processing_invoice', array($order_id), 'processing_document', 'ready2order invoice', $order_id);
            }
 
        }

        public function block_order_stock_reduction($stock_reduced, $order_id) {

            $stock_reduced = false;

            Wr2o_Logger::add(sprintf('block_order_stock_reduction (%s): Blocking reduction of stock', $order_id));

            return $stock_reduced;
        }

        public function adjust_line_item_product_stock ($prevent_item_stock_adjustment, $item, $item_qty){

            if(($order = $item->get_order())){
                Wr2o_Logger::add(sprintf('adjust_line_item_product_stock (%s): Preventing line item stock to be adjusted', $order->get_id()));
                $prevent_item_stock_adjustment = true;
            }
            
            return $prevent_item_stock_adjustment;
        }

    }

    new Wr2o_Ready2order_Invoice_Document_Handler();
}

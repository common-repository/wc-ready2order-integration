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

if (!class_exists('Wr2o_Ready2order_Invoice_Handler', false)) {

    class Wr2o_Ready2order_Invoice_Handler
    {

        public function __construct()
        {
            add_filter('wr2o_get_all_paymentmethods', array($this, 'get_all_paymentmethods'));
            add_filter('wr2o_get_all_users', array($this, 'get_all_users'));
            add_filter('wr2o_get_all_billtypes', array($this, 'get_all_billtypes'));
            add_filter('wr2o_get_all_products', array($this, 'get_all_products'));

            add_action('w2ro_processing_invoice', array($this, 'processing_invoice'));
            add_action('w2ro_clean_invoice', array($this, 'clean_invoice'));

        }

        public function processing_invoice($order_id)
        {
            $order = wc_get_order($order_id);

            $invoice_number = Wr2o_Helper::get_ready2order_invoice_number($order_id);

            if ($invoice_number){
                return;
            }

            $invoice_obj = new Wr2o_Ready2order_Invoice_Document($order);

            $invoice = $invoice_obj->get_invoice_details();

            if (is_null($invoice)){
                Wr2o_Logger::add(sprintf('processing_invoice (%s): Skipping creating invoice that is not paid', $order_id));
                return;
            }

            if (get_option('wr2o_invoice_open_day') == 'yes') {
                $this->open_day_if_closed($order_id);
            }

            Wr2o_Logger::add(sprintf('processing_invoice (%s): Creating invoice in ready2order - %s', $order_id, json_encode($invoice)));
            $invoice = $this->create_invoice_in_ready2order($invoice,$order_id );

            if ($invoice) {
                Wr2o_Logger::add(sprintf('processing_invoice (%s): Invoice %s created in ready2order', $order_id, $invoice['invoice_id']));
                Wr2o_Helper::set_ready2order_invoice_number($order, $invoice['invoice_id']);
                Wr2o_Helper::set_ready2order_full_invoice_number($order, $invoice['invoice_numberFull']);
                $this->sync_invoice_products($order);

                $order->save();
            } else {
                Wr2o_Logger::add(sprintf('processing_invoice (%s): Failed to create invoice in ready2order', $order_id));
            }

        }

        public function clean_invoice($order_id) {
            $order = wc_get_order($order_id);

            if (!$order) {
                Wr2o_Logger::add(sprintf('clean_invoice (%s): Order not found', $order_id));
                return;
            }

            $invoice_number = Wr2o_Helper::get_ready2order_invoice_number($order_id);

            if ($invoice_number){
                Wr2o_Helper::set_ready2order_invoice_number($order,'');
                Wr2o_Helper::set_ready2order_full_invoice_number($order,'');
                Wr2o_Logger::add(sprintf('clean_invoice (%s): Removed invoice id and full id from order', $order_id));
            }

            $order->save();
        }

        public function sync_invoice_products($order){

            $import_actions = get_option('wr2o_products_import_actions', 'update');

            if (!wc_string_to_bool(get_option('wr2o_products_import_automatically')) || empty($import_actions)) {
                Wr2o_Logger::add(sprintf('sync_invoice_products (%s): Product import not enabled - skipping', $order->get_id()));
                return;
            }

            $items = $order->get_items();

            foreach ($items as $item) {
                if (apply_filters('wr2o_include_product_item', true, $item, $order)){
                    if (($product = $item->get_product()) && ($product_r2o_id = Wr2o_Helper::get_r2o_product_id_from_product($product))) {
                        Wr2o_Logger::add(sprintf('sync_invoice_products (%s): Triggering product import for product %s from invoice update', $order->get_id(), $product->get_id()));
                        do_action('wr2o_process_ready2order_products_import_add', $product_r2o_id, $import_actions, false);
                    }
                }
            }
            
        }

        public function get_all_products($products = array())
        {

            if ($saved_products = get_site_transient('wr2o_all_products')) {
                return $saved_products;
            } 

            try {

                $page = 1;
                $batch_size = get_option('wr2o_import_batch_size', 50);

                do {

                    $product_response = Wr2o_API::get("products?page=$page&limit=$batch_size");
                    $products = array_merge($products, $product_response);
                    $page++;

                } while (!empty($product_response));

            } catch (Wr2o_API_Exception $e) {

                $e->write_to_logs();

            }

            set_site_transient('wr2o_all_products',$products,DAY_IN_SECONDS);

            return $products;

        }

        public function get_all_users($users = array())
        {
            if ($saved_users = get_site_transient('wr2o_all_users')) {
                return $saved_users;
            } 

            try {

                $page = 1;
                $batch_size = get_option('wr2o_import_batch_size', 50);

                do {

                    $user_response = Wr2o_API::get("users?page=$page&limit=$batch_size");
                    $users = array_merge($users, $user_response);
                    $page++;

                } while (!empty($user_response));

            } catch (Wr2o_API_Exception $e) {

                $e->write_to_logs();

            }

            set_site_transient('wr2o_all_users',$users,DAY_IN_SECONDS);

            return $users;

        }

        public function get_all_billtypes($billtypes = array())
        {

            if ($saved_billtypes = get_site_transient('wr2o_all_billtypes')) {
                return $saved_billtypes;
            } 

            try {
                Wr2o_Logger::add(sprintf('get_all_billtypes: Getting billtypes'));


                $billtype_response = Wr2o_API::get("billTypes");
                $billtypes = array_merge($billtypes, $billtype_response);

                Wr2o_Logger::add(sprintf('get_all_billtypes: Response - %s', json_encode($billtypes)));


            } catch (Wr2o_API_Exception $e) {

                $e->write_to_logs();

            }

            set_site_transient('wr2o_all_billtypes',$billtypes,DAY_IN_SECONDS);

            return $billtypes;

        }

        public function get_all_paymentmethods($paymentmethods = array())
        {

            if ($saved_paymentmethods = get_site_transient('wr2o_all_paymentmethods')) {
                return $saved_paymentmethods;
            } 

            try {

                $paymentmethod_response = Wr2o_API::get("paymentMethods");
                $paymentmethods = array_merge($paymentmethods, $paymentmethod_response);

            } catch (Wr2o_API_Exception $e) {

                $e->write_to_logs();

            }

            set_site_transient('wr2o_all_paymentmethods',$paymentmethods,DAY_IN_SECONDS);

            return $paymentmethods;

        }

        public function open_day_if_closed($order_id){
            try {

                if (!($status_response = get_site_transient('ready2order_daily_status'))) {
                    $status_response = Wr2o_API::get('dailyReport/status');
                }

                if (($status = $status_response['status']) && $status != 'open'){
                    Wr2o_Logger::add(sprintf('open_day_if_closed (%s): Triggering day to open', $order_id));
                    $status_open_response = Wr2o_API::put('dailyReport/open');
                    Wr2o_Logger::add(sprintf('open_day_if_closed (%s): %s', $order_id,json_encode($status_open_response)));
                    $status_response = Wr2o_API::get('dailyReport/status');
                    set_site_transient('ready2order_daily_status', $status_response, 600);
                }else{
                    Wr2o_Logger::add(sprintf('open_day_if_closed (%s): Day is already open', $order_id));
                }

            } catch (Wr2o_API_Exception $e) {

                $e->write_to_logs();
                Wr2o_Notice::add(sprintf(__('ready2order: %s when triggering day to open when creating ready2order for order %s', 'wc-ready2order-integration'), $e->getMessage(), $order_id));
                $invoice = null;

            }
        }

        public function create_invoice_in_ready2order($invoice = null, $order_id = 0) {

            try {

                $invoice = Wr2o_API::put('document/invoice', $invoice);


            } catch (Wr2o_API_Exception $e) {

                $e->write_to_logs();
                Wr2o_Notice::add(sprintf(__('ready2order: %s when creating ready2order invoice for order %s', 'wc-ready2order-integration'), $e->getMessage(), $order_id));
                $invoice = null;

            }

            return $invoice;

        }

    }

    new Wr2o_Ready2order_Invoice_Handler();
}

<?php

/**
 * This class handles the creation of a ready2order invoice array array.
 *
 * @package   WooCommerce_Ready_To_Order_Integration
 * @author    BjornTech <hello@bjorntech.com>
 * @license   GPL-3.0
 * @link      http://bjorntech.com
 * @copyright 2017-2020 BjornTech
 */

defined('ABSPATH') || exit;

if (!class_exists('Wr2o_Ready2order_Invoice_Document', false)) {

    class Wr2o_Ready2order_Invoice_Document
    {

        private $include_tax;
        private $order;

        public function __construct($order)
        {
            $this->include_tax = !wc_string_to_bool(get_option('wr2o_amounts_excl_tax','yes'));
            $this->order = $order;
        }

        public function get_invoice_details()
        {
            $order_id = $this->order->get_id();

            $date_paid = $this->order->get_date_paid();

            if (is_null($date_paid)){
                if (get_option('wr2o_invoice_include_unpaid_order') == 'yes'){
                    $date_paid = $this->order->get_date_created();
                } else {
                    return null;
                }
            }

            $invoice_object = array(
                "paymentMethod_id" => apply_filters('wr2o_maybe_change_invoice_paymentmethod_id', (get_option('wr2o_invoice_paymentmethod_id') ? get_option('wr2o_invoice_paymentmethod_id') : 0),$order),
                "user_id" => apply_filters('wr2o_maybe_change_invoice_user_id', (get_option('wr2o_invoice_user_id') ? get_option('wr2o_invoice_user_id') : 0), $order),
                "billType_id" => apply_filters('wr2o_maybe_change_invoice_billType_id', (get_option('wr2o_invoice_billType_id') ? get_option('wr2o_invoice_billType_id') : 0), $order),
                "invoice_externalReferenceNumber" => apply_filters('wr2o_maybe_change_invoice_billType_id', $order_id , $this->order),
                "invoice_roundToSmallestCurrencyUnit" => "0.05",
                "invoice_dueDate" => (string) $this->order->get_date_created()->date('Y-m-d'),
                "invoice_paidDate" => (string) $date_paid->date('Y-m-d')
            );

            $invoice_object['invoice_priceBase'] = $this->include_tax ? "brutto" : "netto";

            $invoice_items = array();

            $items = $this->order->get_items();

            foreach ($items as $item) {
                if (apply_filters('wr2o_include_product_item',true, $item, $this->order)) {

                    if(($product = $item->get_product()) && ($product_r2o_id = Wr2o_Helper::get_r2o_product_id_from_product($product))){
                        $item_object = array();
    
                        $ordered_qty = ($qty = $item->get_quantity()) ? $qty : 1;
    
                        $price = ($price = $this->order->get_item_total($item, $this->include_tax)) ? $price : $this->order->get_line_total($item, $this->include_tax);

                        $item_object = $this->create_invoice_object($ordered_qty,$item->get_name('edit'),$price,self::get_order_tax_rate($this->order, $item),$product_r2o_id);
                        
                        array_push($invoice_items,$item_object);

                    }
                }
            }

            if (get_option('wr2o_enable_invoice_export_shipping') == 'yes') {
                foreach ($this->order->get_shipping_methods() as $item) {

                    $tax = $item->get_total_tax('edit');
                    $total = $item->get_total('edit');
    
                    $method_id = $item->get_method_id();
                    $instance_id = $item->get_instance_id();
    
                    $ready2order_shipping_product = get_option('wr2o_invoice_export_shipping_product_' . $method_id . '_' . $instance_id);
    
                    $shipping_method_name = $item->get_method_title();
    
                    if(!$ready2order_shipping_product){
                        Wr2o_Logger::add(sprintf('get_invoice_details (%s): No shipping product for %s', $order_id, $method_id . '_' . $instance_id));
                        break;
                    }
    
                    try{
                        if (!empty($ready2order_product = Wr2o_API::get("products/$ready2order_shipping_product"))) {
                            Wr2o_Logger::add(sprintf('get_invoice_details (%s): Existing ready2order product "%s" found', $order_id, $ready2order_product['product_id']));
                            $shipping_method_name = $ready2order_product['product_name'];
                        }
                    }catch(Wr2o_API_Exception $e){
                        $e->write_to_logs();
                        Wr2o_Notice::add(sprintf(__('ready2order: %s when getting shipping product %s for creating invoice from order %s', 'wc-ready2order-integration'), $e->getMessage(), $ready2order_shipping_product, $order_id));
                        break;
                    }
    
                    if ($tax) {
    
                        $tax_amounts = $item->get_taxes('edit');
    
                        if (array_key_exists('total', $tax_amounts)) {
    
                            $tax_rate = array_key_first($tax_amounts['total']);
                            $tax_percent = WC_Tax::get_rate_percent_value($tax_rate);
    
                            $amount = $this->order->get_line_total($item, $this->include_tax);
    
                            $item_object = $this->create_invoice_object('1',$shipping_method_name,$amount,$tax_percent,$ready2order_shipping_product);
    
                            Wr2o_Logger::add(sprintf('get_invoice_details (%s): Shipping amount is %s and has tax %s', $order_id, $amount, (string) $tax_percent ));
    
                            array_push($invoice_items,$item_object);
    
                        }
                    } else {
    
                        $item_object = $this->create_invoice_object('1',$shipping_method_name,$total,'0',$ready2order_shipping_product);
                        Wr2o_Logger::add(sprintf('get_invoice_details (%s): Shipping amount is %s and has no tax', $order_id, $total));
    
                        array_push($invoice_items,$item_object);
                    }
                    
    
                }
            }

            $invoice_object['items'] = $invoice_items;

            $invoice_object['address'] = $this->get_customer_address_details($this->order);

            Wr2o_Logger::add(sprintf('get_invoice_details (%s): got resource %s', $this->order_id, json_encode($invoice_object)));

            return apply_filters('wr2o_invoice_after_get_details', $invoice_object, $this->order);

        }

        public function create_invoice_object($quantity,$name,$price,$vat_rate,$product_id){
            $item_object = array(
                "item_quantity" => (string) $quantity,
                "item_name" => (string) $name,
                "item_price" => (string) $price,
                "item_vatRate" => (string) $vat_rate,
                "item_priceBase" => $this->include_tax ? "brutto" : "netto",
                "product_id" => (int) $product_id,
            );

            return $item_object;
        }


        public function get_customer_address_details($order){
            $customer_data = array(
                "firstName" => $order->get_billing_first_name('edit'),
                "lastName" => $order->get_billing_last_name('edit'),
                "street" => $order->get_billing_address_1('edit'),
                "city" => $order->get_billing_city('edit'),
                "zip" => $order->get_billing_postcode('edit'),
                "country" => $order->get_billing_country('edit'),
                "email" => $order->get_billing_email()
            );

            if ($company = $order->get_billing_company()) {
                $customer_data["company"] = $company;
            }

            return $customer_data;
        }

        public function get_vat_number($order_id) {
            
            $order = wc_get_order($order_id);  // Get the order object
        
            if (!$order) { // If the order can't be loaded, return an empty string
                return '';
            }
        
            if ($vat_number = $order->get_meta('_billing_vat_number')) {
                return $vat_number;
            }
        
            if ($vat_number = $order->get_meta('_vat_number')) {
                return $vat_number;
            }
        
            if ($vat_number = $order->get_meta('vat_number')) {
                return $vat_number;
            }
        
            return '';
        }

        public static function get_order_tax_rate ($order, $item) {
            $tax_class = $item->get_tax_class('edit');

            if ('shop_order_refund' == $order->get_type()) {
                $order = wc_get_order($order->get_parent_id());
            }

            $args = array(
                'country' => $order->get_billing_country() ? $order->get_billing_country() : WC()->countries->get_base_country(),
                'state' => $order->get_billing_state() ? $order->get_billing_state() : WC()->countries->get_base_state(),
                'city' => $order->get_billing_city() ? $order->get_billing_city() : WC()->countries->get_base_city(),
                'postcode' => $order->get_billing_postcode() ? $order->get_billing_postcode() : WC()->countries->get_base_postcode(),
                'tax_class' => $tax_class,
            );

            $tax_rates = WC_Tax::find_rates($args);

            if (wc_string_to_bool(get_option('wr2o_always_set_invoice_tax_rate_to_zero'))) {
                return '0';
            }

            if (count($tax_rates)) {
                $tax_rate = reset($tax_rates);
                return $tax_rate['rate'];
            } else {
                return '0';
            }
        } 

        
    }
}

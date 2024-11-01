<?php

/**
 * This class handles stocklevels.
 *
 * @author    BjornTech <info@bjorntech.com>
 * @license   GPL-3.0
 *
 * @see      http://bjorntech.com
 *
 * @copyright 2017-2020 BjornTech - BjornTech AB
 */
defined('ABSPATH') || exit;

if (!class_exists('Wr2o_Stocklevel_To_Ready2order', false)) {
    class Wr2o_Stocklevel_To_Ready2order
    {
        public function __construct()
        {
            /*
             * Internal actions
             */
            add_action('wr2o_received_inventory_balance_changed', [$this, 'received_inventory_balance_changed']);
            add_action('wr2o_overwrite_stocklevel_in_woocommerce_add', [$this, 'overwrite_stocklevel_in_woocommerce_add'], 10, 3);
            add_action('wr2o_overwrite_stocklevel_in_woocommerce', [$this, 'overwrite_stocklevel_in_woocommerce'], 10, 2);
            add_action('wr2o_update_stocklevel_in_ready2order', [$this, 'update_stocklevel_in_ready2order'], 10, 3);
            add_action('wr2o_change_stocklevel_by_order', [$this, 'wr2o_change_stocklevel_by_order'], 10, 2);
            add_filter('wr2o_maybe_get_stocklevel_to_update_ready2order', [$this, 'maybe_get_stocklevel_to_update_ready2order'], 10, 3);


            /**
             * Handles stocklevelchange from orders
             */

            if (('yes' == get_option('wr2o_products_export_adjust_stocklevel_from_order')) && ('yes' != get_option('wr2o_products_overwrite_stocklevel_in_ready2order'))) {
                if ($invoice_statuses = get_option('wr2o_products_export_adjust_stocklevel_at_status', ['completed','processing'])) {
                    foreach ($invoice_statuses as $invoice_status) {
                        add_action('woocommerce_order_status_'.$invoice_status, [$this, 'process_stocklevel_change_from_order']);
                    }
                }

                add_action('woocommerce_order_status_cancelled', [$this, 'cancelled_stocklevel_change_from_order'], 20);
                add_action('woocommerce_order_fully_refunded', [$this, 'fully_refunded_stocklevel_change_from_order'], 20, 2);
                add_action('woocommerce_order_partially_refunded', [$this, 'partially_refunded_stocklevel_change_from_order'], 20, 2);

                add_filter('woocommerce_order_item_display_meta_key', [$this, 'display_meta_key'], 10, 3);
            }
        }

        private function maybe_queue_stocklevel_change_from_order($order_id, $id, $cancel = false)
        {
            if (is_admin() && ('yes' !== get_option('wr2o_queue_admin_requests'))) {
                Wr2o_Logger::add(sprintf('maybe_queue_stocklevel_change_from_order (%s): Processing %s adjustments', $order_id, $id));
                do_action('wr2o_change_stocklevel_by_order', $order_id, $cancel);
            } else {
                Wr2o_Logger::add(sprintf('maybe_queue_stocklevel_change_from_order (%s): Queuing %s adjustments', $order_id, $id));

                Wr2o_Helper::add_to_queue('wr2o_change_stocklevel_by_order', [$order_id, $cancel], 'maybe_queue_stocklevel_change_from_order', 'Adjustment', $order_id);
            }
        }

        public function process_stocklevel_change_from_order($order_id)
        {
            $this->maybe_queue_stocklevel_change_from_order($order_id, 'stocklevel');
        }

        public function cancelled_stocklevel_change_from_order($order_id)
        {
            $this->maybe_queue_stocklevel_change_from_order($order_id, 'cancel stocklevel', true);
        }

        public function fully_refunded_stocklevel_change_from_order($order_id, $refund_id)
        {
            $this->maybe_queue_stocklevel_change_from_order($order_id, 'fully refund');
        }

        public function partially_refunded_stocklevel_change_from_order($order_id, $refund_id)
        {
            $this->maybe_queue_stocklevel_change_from_order($order_id, 'partially refund');
        }

        public function display_meta_key($display_key, $meta, $item)
        {
            if ($meta->key === '_wr2o_reduced_stock') {
                $display_key = __('ready2order reduction', 'wc-ready2order-integration');
            }

            return $display_key;
        }

        /**
         * Update stocklevel in ready2order.
         *
         * Call this by using the action 'wr2o_update_stocklevel_in_ready2order'.
         *
         * @since 1.0
         */
        public function update_stocklevel_in_ready2order($product_id, $from_webhook = true, $resource = false)
        {
            if (!wc_string_to_bool(get_option('wr2o_products_overwrite_stocklevel_in_ready2order'))) {
                return;
            }

            $product = wc_get_product($product_id);

            if (!$product->get_manage_stock('edit')) {
                Wr2o_Logger::add(sprintf('update_stocklevel_in_ready2order (%s): WooCommerce product is not set to handle stock.', $product_id));

                return;
            }

            if (!Wr2o_Helper::is_syncable($product)) {
                return;
            }

            try {
                $r2o_product_id = Wr2o_Helper::get_r2o_product_id_from_product($product);

                if (!$r2o_product_id) {
                    Wr2o_Logger::add(sprintf('update_stocklevel_in_ready2order (%s): The WooCommerce product is not mapped to any ready2order id.', $product_id));

                    return;
                }

                $response = Wr2o_API::get("products/$r2o_product_id/stock");

                if (empty($response)) {
                    Wr2o_Logger::add(sprintf('update_stocklevel_in_ready2order (%s): ready2order product id %s was not set to manage stock', $product_id, $r2o_product_id));

                    return;
                }

                $resource = reset($response);
                $r2o_level = wc_stock_amount($resource['product_stock']);

                $wc_level = wc_stock_amount($product->get_stock_quantity());

                if ($r2o_level != $wc_level) {
                    $resource = Wr2o_API::post("products/$r2o_product_id/stock?product_stock=$wc_level");

                    Wr2o_Logger::add(sprintf('update_stocklevel_in_ready2order (%s): Stocklevel changed from %s to %s on ready2order product item number %s', $product_id, $r2o_level, $wc_level, $r2o_product_id));
                } else {
                    Wr2o_Logger::add(sprintf('update_stocklevel_in_ready2order (%s): No need to update stocklevel on ready2order product item number %s', $product_id, $r2o_product_id));
                }
            } catch (Wr2o_API_Exception $e) {
                if (422 == $e->getCode()) {
                    Wr2o_Logger::add(sprintf('update_stocklevel_in_ready2order (%s): ready2order product id %s was not valid', $product_id, $r2o_product_id));
                } else {
                    $e->write_to_logs();
                }
            }
        }

        public function overwrite_stocklevel_in_woocommerce_add($resource, $webhook = true, $triggered_via_product_update = false)
        {
            
            if ($triggered_via_product_update) {
                do_action('wr2o_overwrite_stocklevel_in_woocommerce', $resource, $webhook);
                return;
            }

            if (true == $webhook) {
                if ('yes' == get_option('wr2o_queue_webhook_calls')) {
                    Wr2o_Helper::add_to_queue('wr2o_overwrite_stocklevel_in_woocommerce',[$resource, $webhook],'overwrite_stocklevel_in_woocommerce_add','Resource',$resource['product_id']);
                } else {
                    do_action('wr2o_overwrite_stocklevel_in_woocommerce', $resource, $webhook);
                }
            } else {
                Wr2o_Helper::add_to_queue('wr2o_overwrite_stocklevel_in_woocommerce',[$resource, $webhook],'overwrite_stocklevel_in_woocommerce_add','Resource',$resource['product_id']);
            }
        }

        public function overwrite_stocklevel_in_woocommerce($resource, $webhook = true)
        {
            try {
                Wr2o_Logger::add(sprintf('overwrite_stocklevel_in_woocommerce: got resource %s', json_encode($resource)));

                if ($product_id = Wr2o_helper::get_wc_product_id_from_resource($resource)) {
                    $product = wc_get_product($product_id);

                    $manage_stock = $product->get_manage_stock();
                    $stock_enabled = isset($resource['product_stock_enabled']) ? rest_sanitize_boolean($resource['product_stock_enabled']) : false;

                    if (($manage_stock != $stock_enabled)) {
                        $product->set_manage_stock($stock_enabled);
                        Wr2o_Helper::maybe_update_r2o_timestamp($product, $resource, true);
                        Wr2o_Logger::add(sprintf('overwrite_stocklevel_in_woocommerce (%s): Set product to manage stock from ready2order product item number "%s" (%s)', $product->get_id(), $resource['product_itemnumber'], $resource['product_id']));
                    }

                    if ($stock_enabled && isset($resource['product_stock_value']) && (($current_stock = $product->get_stock_quantity()) != $resource['product_stock_value'])) {
                        $new_stocklevel = wc_update_product_stock($product, $resource['product_stock_value'], 'set', true);
                        Wr2o_Helper::maybe_update_r2o_timestamp($product, $resource, true);
                        Wr2o_Logger::add(sprintf('overwrite_stocklevel_in_woocommerce (%s): Changed product stock from %s to %s from ready2order product item number "%s" (%s)', $product->get_id(), $current_stock, $resource['product_stock_value'], $resource['product_itemnumber'], $resource['product_id']));
                    } else {
                        Wr2o_Logger::add(sprintf('overwrite_stocklevel_in_woocommerce (%s): No need to change stocklevel from ready2order product item number "%s" (%s)', $product->get_id(), $resource['product_itemnumber'], $resource['product_id']));
                    }
                } else {
                    Wr2o_Logger::add(sprintf('overwrite_stocklevel_in_woocommerce: ready2order product id "%s" not found in WooCommerce', $resource['product_id']));
                }
            } catch (Wr2o_Exception $e) {
                $message = $e->getMessage();
                Wr2o_Logger::add(sprintf('overwrite_stocklevel_in_woocommerce: %s when setting updating stocklevel in WooCommerce', $message));
                Wr2o_Notice::add(sprintf('ready2order: %s when setting updating stocklevel in WooCommerce', $message), 'error');
            }
        }

        public function wr2o_change_stocklevel_by_order($order_id, $cancel = false)
        {
            $order = wc_get_order($order_id);

            $stockchange_timestamp = $order->get_meta('_wr2o_stockchange_timestamp');

            $order_modified = $order->get_date_modified();

            if ($order_modified === $stockchange_timestamp) {
                Wr2o_Logger::add(sprintf('wr2o_change_stocklevel_by_order (%s): ready2order stocklevel already done @%s', $order_id, $stockchange_timestamp));

                return;
            }

            if ($cancel && !$stockchange_timestamp) {
                Wr2o_Logger::add(sprintf('wr2o_change_stocklevel_by_order (%s): ready2order cancel will not be done on non synced order', $order_id));

                return;
            }

            $order_notes = [];

            foreach ($order->get_items() as $item) {
                $note = $this->maybe_update_ready2order_stocklevel($item, $cancel);
                if ($note) {
                    $order_notes[] = $note;
                }
            }

            if (!empty($order_notes)) {
                $order->add_order_note(__('ready2order stock levels changed:', 'wc-ready2order-integration').' '.implode(', ', $order_notes));
            }

            if ($order->meta_exists('_wr2o_stockchange_timestamp')) {
                $order->update_meta_data('_wr2o_stockchange_timestamp', $order_modified);
            } else {
                $order->add_meta_data('_wr2o_stockchange_timestamp', $order_modified, true);
            }

            $order->save();

            Wr2o_Logger::add(sprintf('wr2o_change_stocklevel_by_order (%s): ready2order stocklevel adjustments done @%s', $order_id, $order_modified));
        }

        /**
         * Sees if line item stock has already reduced stock, and whether those values need adjusting e.g. after changing item qty.
         *
         * @since 3.6.0
         *
         * @param WC_Order_Item $item          item object
         * @param int           $item_quantity Optional quantity to check against. Read from object if not passed.
         *
         * @return bool|array|WP_Error Array of changes or error object when stock is updated (@see wc_update_product_stock). False if nothing changes.
         */
        public function maybe_adjust_line_item_product_stock($return, $item, $item_quantity = -1)
        {
            $note = $this->maybe_update_ready2order_stocklevel($item);

            if (!empty($note)) {
                $order = $item->get_order();
                $order->add_order_note(__('ready2order stock level changed:', 'wc-ready2order-integration').' '.$note);
            }

            return $return;
        }

        public function maybe_update_ready2order_stocklevel($item, $cancel = false)
        {
            if (!$item->is_type('line_item')) {
                return false;
            }

            $product = $item->get_product();
            $product_id = $product->get_id();
            $sku = $product->get_sku();
            $order = $item->get_order();
            $order_id = $order->get_id();

            if (!$product) {
                Wr2o_Logger::add(sprintf('wr2o_change_stocklevel_by_order (%s): Product not found for item', $order_id));
                return false;
            }

            if (!$product->managing_stock()) {
                Wr2o_Logger::add(sprintf('wr2o_change_stocklevel_by_order (%s): Product %s not set to manage stock', $order_id, $product->get_id()));
                return false;
            }

            if (empty($r2o_product_id = Wr2o_Helper::get_r2o_product_id_from_product($product))) {
                Wr2o_Logger::add(sprintf('wr2o_change_stocklevel_by_order (%s): r2o product id does not exist for product %s', $order_id, $product->get_id()));
                return false;
            }

            try {
                $item_quantity = wc_stock_amount($item->get_quantity());
                $already_reduced_stock = (int) $item->get_meta('_wr2o_reduced_stock', true);
                $refunded_item_quantity = wc_stock_amount($order->get_qty_refunded_for_item($item->get_id()));
                $delta_qty = $cancel ? $already_reduced_stock : (int) ($already_reduced_stock - $item_quantity - $refunded_item_quantity);

                if (!$cancel && !$delta_qty) {
                    Wr2o_Logger::add(sprintf('wr2o_change_stocklevel_by_order (%s): Stocklevel already changed by %s for r2o product id %s', $order_id, $item_quantity + $refunded_item_quantity, $r2o_product_id));
                    return false;
                }

                $resource = Wr2o_API::post("products/$r2o_product_id/stock?product_stockDelta=$delta_qty");
                $product_after_change = reset($resource);
                $new_stock = (int) $product_after_change['product_stock'];
                $old_stock = $new_stock - $delta_qty;

                if ($cancel) {
                    Wr2o_Logger::add(sprintf('wr2o_change_stocklevel_by_order (%s): Stocklevel reversed by %s to %s for r2o product id %s due to cancel', $order_id, $delta_qty, $new_stock, $r2o_product_id));
                    $item->delete_meta_data('_wr2o_reduced_stock');
                } else {
                    Wr2o_Logger::add(sprintf('wr2o_change_stocklevel_by_order (%s): Stocklevel changed by %s from %s to %s for r2o product id %s', $order_id, $delta_qty, $old_stock, $new_stock, $r2o_product_id));
                    $item->add_meta_data('_wr2o_reduced_stock', $item_quantity + $refunded_item_quantity, true);
                }

                $item->save();

                return $product->get_formatted_name().' '.$old_stock.'&rarr;'.$new_stock;
            } catch (Wr2o_API_Exception $e) {
                if (422 == $e->getCode()) {
                    Wr2o_Logger::add(sprintf('wc_maybe_adjust_line_item_product_stock(%s): SKU "%s" is not a valid ready2order product id', $product_id, $sku));
                } else {
                    $e->write_to_logs();
                }
            }
        }

        public function maybe_get_stocklevel_to_update_ready2order($extracted_stocklevel, $product, $resource) {

            $product_id = $product->get_id();

            if ('yes' != get_option('wr2o_products_overwrite_stocklevel_in_ready2order')) {
                Wr2o_Logger::add(sprintf('maybe_get_stocklevel_to_update_ready2order (%s): Stocklevel not set to update - skipping retrieving WooCommerce stock level', $product_id));
                return $extracted_stocklevel;
            }

            if (!$product->get_manage_stock('edit')) {
                Wr2o_Logger::add(sprintf('maybe_get_stocklevel_to_update_ready2order (%s): Manage stock not set on product - skipping retrieving WooCommerce stock level', $product_id));
                return $extracted_stocklevel;
            }

            $wc_stock = wc_stock_amount($product->get_stock_quantity());

            if ($resource && !empty($resource)) {

                $r2o_level = wc_stock_amount($resource['product_stock_value']);

                if ($r2o_level != $wc_stock) {
                    Wr2o_Logger::add(sprintf('maybe_get_stocklevel_to_update_ready2order (%s): WooCommerce stocklevel (%s) differs from ready2order stocklevel (%s) - returning stocklevel %s', $product_id, $wc_stock, $r2o_level, $wc_stock));

                    $extracted_stocklevel = $wc_stock;
                } else {
                    Wr2o_Logger::add(sprintf('maybe_get_stocklevel_to_update_ready2order (%s): WooCommerce stocklevel (%s) do not differ from ready2order stocklevel (%s) - skipping retrieving WooCommerce stock level', $product_id, $wc_stock, $r2o_level, $wc_stock));
                }

            } else {

                Wr2o_Logger::add(sprintf('maybe_get_stocklevel_to_update_ready2order (%s): New product in ready2order - returning stocklevel %s', $product_id, $wc_stock));

                $extracted_stocklevel = $wc_stock;

            }

            return $extracted_stocklevel;
        }
    }

    new Wr2o_Stocklevel_To_Ready2order();
}

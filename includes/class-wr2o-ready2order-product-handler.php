<?php

/**
 * This class handles syncing products with ready2order
 *
 * @package   WooCommerce_Ready_To_Order_Integration
 * @author    BjornTech <info@bjorntech.com>
 * @license   GPL-3.0
 * @link      http://bjorntech.com
 * @copyright 2017-2020 BjornTech - BjornTech A
 */

defined('ABSPATH') || exit;

if (!class_exists('Wr2o_Ready2order_Product_Handler', false)) {

    class Wr2o_Ready2order_Product_Handler
    {

        private $changed;

        public function __construct()
        {

            add_action('wr2o_process_ready2order_products_import_add', array($this, 'process_ready2order_products_import_add'), 10, 3);
            add_action('wr2o_process_ready2order_products_import', array($this, 'process_ready2order_products_import'), 10, 3);
            add_action('wr2o_process_ready2order_products_import_queue', array($this, 'process_ready2order_products_import_queue'), 10, 3);
            add_action('wr2o_process_ready2order_products_delete', array($this, 'process_ready2order_products_delete'), 10, 3);

            add_filter('wr2o_process_ready2order_products_import_all', array($this, 'process_ready2order_products_import_all'), 10, 2);
            add_action('wr2o_process_ready2order_products_import_all', array($this, 'process_ready2order_products_import_all'), 10, 2);

            add_filter('wr2o_process_ready2order_products_batch', array($this, 'process_ready2order_products_batch'), 10, 3);
            add_action('wr2o_process_ready2order_products_batch', array($this, 'process_ready2order_products_batch'), 10, 3);
            add_filter('wr2o_maybe_process_ready2order_product', array($this, 'filter_ready2order_product'), 10, 2);

        }

        /**
         * Processing an ready2order product object or place in queue
         *
         * @since 1.0.0
         *
         * @param object $resource a ready2order product object or product update object
         * @param bool $webhook Set to true if called from a webhook, Default false
         */

        public function process_ready2order_products_import_add($ready2order_id, $actions, $webhook = true)
        {
            if (true === $webhook) {

                if ('yes' == get_option('wr2o_queue_webhook_calls')) {

                    Wr2o_Helper::add_to_queue('wr2o_process_ready2order_products_import', array($ready2order_id, $actions, $webhook), 'process_ready2order_products_import_add', 'Resource', $ready2order_id);

                } else {

                    do_action('wr2o_process_ready2order_products_import', $ready2order_id, $actions, $webhook);

                }

            } else {

                Wr2o_Helper::add_to_queue('wr2o_process_ready2order_products_import', array($ready2order_id, $actions, $webhook), 'process_ready2order_products_import_add', 'Resource', $ready2order_id);

            }

        }

        private function set_sku_common(&$product, $product_id, $resource)
        {

            $product_id = $product->get_id();
            $sku = $product->get_sku('edit');
            $new_sku = (isset($resource['product_itemnumber']) && $resource['product_itemnumber'] != 'null') ? $resource['product_itemnumber'] : '';

            if(get_option('wr2o_products_import_sku') != 'yes'){
                Wr2o_Logger::add(sprintf('set_sku_common (%s): Skipping SKU', $product_id));
                return;
            }

            if ($new_sku != $sku) {

                if (!empty($new_sku)) {

                    $unique_sku = wc_clean(wp_unslash(wc_product_generate_unique_sku($product_id, $new_sku)));

                    $product->set_sku($unique_sku);

                    if ($unique_sku != $new_sku) {
                        Wr2o_Logger::add(sprintf('set_sku_common (%s): SKU changed from "%s" to "%s" by creating unique SKU from "%s"', $product_id, $sku, $unique_sku, $new_sku));
                    } else {
                        Wr2o_Logger::add(sprintf('set_sku_common (%s): SKU changed from "%s" to "%s"', $product_id, $sku, $new_sku));
                    }
                    $this->changed = true;

                } else {

                    $product->set_sku('');
                    Wr2o_Logger::add(sprintf('set_sku_common (%s): Clearing SKU', $product_id));
                    $this->changed = true;

                }

            }

        }

        public function process_product_data(&$product, $product_id, $resource)
        {

            if (isset($resource['product_id']) && (($existing_product_id = Wr2o_Helper::get_r2o_product_id_from_product($product)) != $resource['product_id'])) {
                Wr2o_Helper::set_r2o_product_id($product, $resource['product_id'], false);
                if (!$existing_product_id) {
                    Wr2o_Logger::add(sprintf('create_common_data (%s): Setting ready2order product id metadata to %s', $product_id, $resource['product_id']));
                } else {
                    Wr2o_Logger::add(sprintf('create_common_data (%s): Changing ready2order product id from %s to %s', $product_id, $existing_product_id, $resource['product_id']));
                }
                $this->changed = true;
            }

            if (Wr2o_Helper::maybe_update_r2o_timestamp($product, $resource)) {
                $this->changed = true;
            }

            do_action('wr2o_remove_product_update_actions');

            if (!$product->get_status() || $product->get_status() == 'importing') {
                $product->set_status(get_option('ready2order_set_products_to_status', 'publish'));
                $this->changed = true;
            }

            if ($this->changed) {
                $product->save();
            }

            //do_action('wr2o_add_product_update_actions');

            Wr2o_Logger::add(sprintf('create_common_data (%s): Processing of product finished in %s status.', $product_id, $product->get_status()));

        }

        public function process_ready2order_products_delete($resource, $actions, $webhook = true)
        {

            $id = $resource['product_id'];
            $products = Wr2o_API::delete("products/$id");

        }

        public function process_ready2order_products_import($ready2order_id, $actions, $webhook = true)
        {

            try {

                if (($ready2order_id)){
                    Wr2o_Logger::add(sprintf('process_ready2order_product: got ready2order product id %s', $ready2order_id));
                } else {
                    Wr2o_Logger::add(sprintf('process_ready2order_product: ready2order product id not found'));
                    return;
                }

                if ($ready2order_id && !empty($resource = Wr2o_API::get("products/$ready2order_id"))) {
                    Wr2o_Logger::add(sprintf('process_ready2order_product: ready2order product %s fetched', $ready2order_id));
                } else {
                    Wr2o_Logger::add(sprintf('process_ready2order_product: Could not fetch ready2order product %s', $ready2order_id));
                }

                Wr2o_Logger::add(sprintf('process_ready2order_product: got resource %s', json_encode($resource)));

                if (apply_filters('wr2o_maybe_process_ready2order_product', true, $resource)) {

                    if (count($resource) == 1) {

                        if (false === strpos($actions, 'delete')) {
                            return;
                        }

                        if ($product_id = Wr2o_helper::get_wc_product_id_from_resource($resource)) {

                            $product = wc_get_product($product_id);
                            $product->delete();
                            Wr2o_Logger::add(sprintf('process_ready2order_product (%s): Product found by ready2order product item number "%s" (%s), deleted with status %s', $product->get_id(), $resource['product_itemnumber'], $resource['product_id'], $product->get_status()));

                        } else {

                            Wr2o_Logger::add(sprintf('process_ready2order_product: WooCommerce product not found by ready2order product id "%s", no deletion possible', $resource['product_id']));

                        }

                    } else {

                        $product_id = Wr2o_helper::get_wc_product_id_from_resource($resource);

                        if (!$product_id && false === strpos($actions, 'create')) {
                            Wr2o_Logger::add(sprintf('process_ready2order_product (%s): No existing product found from item number "%s" (%s)', $product_id, $resource['product_itemnumber'], $resource['product_id']));
                            return;
                        }

                        $this->changed = false;

                        if ($product_id && ($product = wc_get_product($product_id))) {

                            Wr2o_Logger::add(sprintf('process_ready2order_product (%s): Processing of %s product from ready2order product item number "%s" (%s) started', $product_id, $product->get_type(), $resource['product_itemnumber'], $resource['product_id']));

                        } else {

                            $product = new WC_Product_Simple();
                            $product->set_name(sanitize_text_field($resource['product_name']));
                            $product->set_slug(sanitize_title(($resource['product_name'])));
                            $product->set_status('importing');
                            $product_id = $product->get_id();
                            Wr2o_Logger::add(sprintf('process_ready2order_product (%s): Processing by creating simple product from ready2order product item number "%s" (%s) started', $product_id, $resource['product_itemnumber'], $resource['product_id']));
                            $this->changed = true;

                        }

                        $this->create_product_data($product, $product_id, $resource);

                        $this->set_sku_common($product, $product_id, $resource);

                        $this->process_product_data($product, $product_id, $resource);

                        do_action('wr2o_overwrite_stocklevel_in_woocommerce_add', $resource, $webhook, true);

                    }

                } else {

                    if (!wc_string_to_bool(get_option('wr2o_delete_products_with_filters'))){
                        Wr2o_Logger::add(sprintf('process_ready2order_product: Product %s is not syncable, not deleting', $resource['product_id']));
                        return;
                    }

                    Wr2o_Logger::add(sprintf('process_ready2order_product: Product %s is not syncable - potentially deleting', $resource['product_id']));

                    if (false === strpos($actions, 'delete')) {
                        return;
                    }

                    if ($product_id = Wr2o_helper::get_wc_product_id_from_resource($resource)) {

                        $product = wc_get_product($product_id);
                        $product->delete();
                        Wr2o_Logger::add(sprintf('process_ready2order_product (%s): Product found by ready2order product item number "%s" (%s), deleted with status %s', $product->get_id(), $resource['product_itemnumber'], $resource['product_id'], $product->get_status()));

                    } else {

                        Wr2o_Logger::add(sprintf('process_ready2order_product: WooCommerce product not found by ready2order product id "%s", no deletion possible', $resource['product_id']));

                    }
                }

            } catch (Wr2o_API_Exception $e) {

                $e->write_to_logs();

            } catch (Wr2o_Exception $e) {

                $message = $e->getMessage();
                Wr2o_Logger::add(sprintf('process_ready2order_product: %s when importing ready2order product %s', $message, $resource['product_id']));
                Wr2o_Notice::add(sprintf('ready2order: %s when importing ready2order product %s', $message, $resource['product_name']), 'error');

            }

        }

        public function process_ready2order_products_import_queue($offset, $batch_id)
        {

            $r2o_products = get_site_transient('ready2order_all_products_' . $batch_id);

            if (!empty($r2o_products)) {

                $import_actions = get_option('wr2o_products_import_actions', 'update');
                $added = 0;

                foreach ($r2o_products as $r2o_product) {

                    try {
                        do_action('wr2o_process_ready2order_products_import_add', $r2o_product['product_id'], $import_actions, false);
                        $added++;
                    } catch (Wr2o_API_Exception $e) {
                        $e->write_to_logs();
                    }

                }

                delete_site_transient('ready2order_all_products_' . $batch_id);

                Wr2o_Logger::add(sprintf('process_ready2order_products_import_queue: Queuing %d products with offset %d from ready2order to processing queue in WooCommerce', $added, $offset));
            }

        }

        public function process_ready2order_products_batch($number, $page, $batch_size)
        {

            try {

                $batch_id = uniqid() . '_' . $page;

                $products = Wr2o_API::get("products?page=$page&limit=$batch_size");

                if (empty($products)) {
                    Wr2o_Logger::add('process_ready2order_products_batch: No more products found in ready2order.');
                    return 0;
                }

                $number = count($products);

                set_site_transient('ready2order_all_products_' . $batch_id, $products, DAY_IN_SECONDS);

                if ($page === 1) {
                    do_action('wr2o_process_ready2order_products_import_queue', $page, $batch_id);
                } else {

                    Wr2o_Helper::add_to_queue('wr2o_process_ready2order_products_import_queue', array($page, $batch_id), 'process_ready2order_products_batch', 'Batch', $batch_id);

                    Wr2o_Logger::add(sprintf('process_ready2order_products_batch: Created batch %s with id %s including %s products.', $page, $batch_id, $number));
                }

                $page++;

                Wr2o_Helper::add_to_queue('wr2o_process_ready2order_products_batch', array($number, $page, $batch_size), 'process_ready2order_products_batch', 'Page', $page);

            } catch (Wr2o_API_Exception $e) {
                $e->write_to_logs();
            }

            return $number;

        }

        public function process_ready2order_products_import_all($number = 0, $sync_all = false)
        {

            if ($sync_all) {

                $number = apply_filters('wr2o_process_ready2order_products_batch', 0, 1, get_option('wr2o_import_batch_size', 250));

            }

            return $number;

        }

        public function filter_ready2order_product($include_product, $resource){

            $include_product = Wr2o_Helper::is_syncable_r2o_product($resource);
            
            return $include_product;
        }

        private function create_product_data(&$product, $product_id, $resource)
        {

            /**
             * Set description on WooCommerce product
             */

            if ('yes' == get_option('wr2o_products_import_product_description')) {

                $description_type = get_option('wr2o_products_mapping_product_description', 'description');

                if (isset($resource['product_description'])) {

                    $current_description = 'product_description' == $description_type ? $product->get_description('edit') : $product->get_short_description('edit');

                    if ($resource['product_description'] != $current_description) {

                        if ('product_description' == $description_type) {
                            $product->set_description($resource['product_description']);
                        } else {
                            $product->set_short_description($resource['product_description']);
                        }

                        Wr2o_Logger::add(sprintf('create_product_data (%s): Changing %s from %s to %s', $product_id, $description_type, $current_description, $resource['product_description']));
                        $this->changed = true;

                    }
                }
            }

            if ('yes' == get_option('wr2o_products_import_product_vat')) {

                if (wc_tax_enabled() && isset($resource['product_vat']) && is_numeric($resource['product_vat'])) {

                    $tax_class = Wr2o_Helper::get_tax_class($resource['product_vat']);

                    Wr2o_Logger::add(sprintf('create_product_data (%s): Setting tax class "%s" on product', $product_id, $tax_class));

                    $product->set_tax_class($tax_class);
                    $this->changed = true;

                }

            }

            /**
             * Set Price on WooCommerce product
             */

            if ('yes' == get_option('wr2o_products_import_product_price')) {

                $price = $product->get_regular_price('edit');

                if (wc_tax_enabled()) {

                    if ($resource['product_priceIncludesVat']) {

                        if ('yes' == get_option('woocommerce_prices_include_tax')) {

                            $new_price = $resource['product_price'];

                        } else {

                            $new_price = $resource['product_price'] / (1 + ((int) $resource['product_vat'] / 100));

                        }

                    } else {

                        if ('yes' != get_option('woocommerce_prices_include_tax')) {

                            $new_price = $resource['product_price'];

                        } else {

                            $new_price = $resource['product_price'] * (1 + ((int) $resource['product_vat'] / 100));

                        }

                    }

                } elseif (!$resource['product_priceIncludesVat']) {

                    $new_price = $resource['product_price'] * (1 + ((int) $resource['product_vat'] / 100));

                } else {

                    $new_price = $resource['product_price'];

                }

                if ($price != $new_price) {

                    $product->set_regular_price($new_price);

                    Wr2o_Logger::add(sprintf('create_product_data (%s): Changing product price from %s to %s', $product_id, $price, $new_price));
                    $this->changed = true;

                }

            }

            /**
             * Set name on WooCommerce product
             */

            if ('yes' == get_option('wr2o_products_import_product_name')) {

                $current_name = $product->get_name('edit');

                if ($current_name != $resource['product_name']) {

                    $product->set_name($resource['product_name']);

                    Wr2o_Logger::add(sprintf('create_product_data (%s): Product name changed from "%s" to "%s"', $product_id, $current_name, $resource['product_name']));
                    $this->changed = true;

                }

            }

            /**
             * Import category from ready2order
             */

            if ('yes' == get_option('wr2o_products_import_productgroup')) {

                try{
                    $r2o_product = BT_R2O_PH()->get_current_product($product,$resource['product_id']);

                    if(isset($r2o_product['productgroup_id'])){
                        $product_group_resource = Wr2o_API::get("productgroups/" . $r2o_product['productgroup_id']);
                        if (Wr2o_Helper::is_syncable_r2o_group($product_group_resource)) {
                            $current_category = apply_filters('wr2o_maybe_add_product_category', $r2o_product['productgroup_id']);
    
                            $category_ids = $product->get_category_ids('edit');
                            if (!in_array($current_category, $category_ids)) {
                                $product->set_category_ids(array_merge($category_ids, array($current_category)));
            
                                Wr2o_Logger::add(sprintf('create_product_data (%s): Product category changed from %s to %s', $product_id, implode(',', $category_ids), $current_category));
                                $this->changed = true;
                            }
                        }
                    }
                }catch(Wr2o_API_Exception $e){
                    $e->write_to_logs();
                    
                }

            }

        }

    }

    new Wr2o_Ready2order_Product_Handler();
}

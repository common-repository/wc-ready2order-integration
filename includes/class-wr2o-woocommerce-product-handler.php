<?php

/**
 * This class handles syncing products with ready2order
 *
 * @package   WooCommerce_Ready_To_Order_Integration
 * @author    BjornTech <info@bjorntech.com>
 * @license   GPL-3.0
 * @link      http://bjorntech.com
 * @copyright 2021 BjornTech - BjornTech AB
 */

defined('ABSPATH') || exit;

if (!class_exists('Wr2o_WooCommerce_Product_Handler', false)) {
    class Wr2o_WooCommerce_Product_Handler
    {
        static $instance;

        public function __construct()
        {
            add_action('wr2o_process_woocommerce_products_export_add', array($this, 'process_woocommerce_products_export_add'), 10, 3);
            add_action('wr2o_process_woocommerce_products_export_all', array($this, 'process_woocommerce_products_export_all'), 50, 1);
            add_filter('wr2o_process_woocommerce_products_export_all', array($this, 'process_woocommerce_products_export_all'), 50, 2);
            add_action('wr2o_process_woocommerce_products_export', array($this, 'process_woocommerce_products_export'), 10, 3);
            add_action('wr2o_process_woocommerce_products_export_delete', array($this, 'process_woocommerce_products_export_delete'));

            /**
             * Handle sales on products
             */

            add_action('wc_after_products_starting_sales', array($this, 'update_products_when_sales_price_is_changed'));
            add_action('wc_after_products_ending_sales', array($this, 'update_products_when_sales_price_is_changed'));

            /**
             * WooCommerce filters
             */
            add_filter('woocommerce_duplicate_product_exclude_meta', array($this, 'duplicate_product_exclude_meta'), 10, 2);

            /**
             * Wordpress actions
             */
            add_action('wp_trash_post', array($this, 'wc_product_was_trashed'));
            add_action('untrashed_post', array($this, 'wc_product_was_untrashed'));
            add_action('delete_post', array($this, 'wc_product_was_deleted'));

            /**
             * Actions to control product updates
             */
            add_action('wr2o_remove_product_update_actions', array($this, 'remove_product_update_actions'));
            add_action('wr2o_add_product_update_actions', array($this, 'add_product_update_actions'));

            /**
             * Use hooks if realtime updates
             */
            do_action('wr2o_add_product_update_actions');
        }

        public function add_product_update_actions()
        {
            if ('yes' == get_option('wr2o_products_export_automatic_update')) {
                add_action('woocommerce_update_product', array($this, 'wc_product_was_updated'), 20, 2);
                add_action('woocommerce_update_product_variation', array($this, 'wc_product_was_updated'), 20, 2);
                add_action('woocommerce_new_product', array($this, 'wc_product_was_created'), 20, 2);

                if ('yes' == get_option('wr2o_products_export_use_aggresive_hooks')) {
                    add_action('woocommerce_updated_product_stock', [$this, 'wc_product_was_updated'], 20, 3);
                    add_action('woocommerce_process_product_meta', [$this, 'wc_product_was_updated'], 20, 3);
                    add_action('woocommerce_save_product_variation', [$this, 'wc_product_was_updated'], 20, 3);
                }
            }
        }

        public function remove_product_update_actions()
        {
            remove_action('woocommerce_update_product', array($this, 'wc_product_was_updated'), 20);
            remove_action('woocommerce_update_product_variation', array($this, 'wc_product_was_updated'), 20);
            remove_action('woocommerce_new_product', array($this, 'wc_product_was_created'), 20);

            if ('yes' == get_option('wr2o_products_export_use_aggresive_hooks')) {
                remove_action('woocommerce_updated_product_stock', [$this, 'wc_product_was_updated'], 20);
                remove_action('woocommerce_process_product_meta', [$this, 'wc_product_was_updated'], 20);
                remove_action('woocommerce_save_product_variation', [$this, 'wc_product_was_updated'], 20);
            }
        }

        public function handle_unit_name($product_id)
        {
            if (class_exists('Woo_Advanced_Qty')) {
                if (!empty($post_setting = get_post_meta($product_id, '_advanced-qty-quantity-suffix', true))) {
                    Wr2o_Logger::add(sprintf('handle_unit_name (s%): Product has %s set as advanced quantity', $product_id, $post_setting));
                    return $post_setting;
                }

                $terms = get_the_terms($product_id, 'product_cat');

                $term_setting = '';

                if (!empty($terms)) {
                    foreach ($terms as $term) {
                        $term_option = get_option('product-category-advanced-qty-quantity-suffix-' . $term->term_id);
                        Wr2o_Logger::add(print_r($term_option, true));
                        if (!empty($term_option) && $term_option != 'global-input') {
                            $term_setting = $term_option;
                        }
                    }
                    Wr2o_Logger::add(print_r($term_setting, true));
                    if (!empty($term_setting)) {
                        Wr2o_Logger::add(sprintf('handle_unit_name (%s): Product category %s has %s set as advanced quantity', $product_id, $term->term_id, $term_setting));
                        return $term_setting;
                    }
                }

                if (!empty($shop_setting = get_option('woo-advanced-qty-quantity-suffix'))) {
                    Wr2o_Logger::add(sprintf('Global settings has %s set as advanced quantity', $shop_setting));
                    return $shop_setting;
                }

                Wr2o_Logger::add(sprintf('handle_unit_name (%s): No settings found for advanced quantity', $product_id));
            }

            return false;
        }

        private function price_includes_vat($product)
        {
            if (!wc_tax_enabled() || (wc_tax_enabled() && 'yes' == get_option('woocommerce_prices_include_tax'))) {
                return true;
            }

            return false;
        }
        //Loggning
        private function get_price($product, $include_vat_when_sending, $tax_rate, $wc_price_inc_vat)
        {
            $new_price = $product->get_regular_price('edit');

            Wr2o_Logger::add(sprintf('get_price (%s): Regular price %s found', $product->get_id(), $new_price));

            Wr2o_Logger::add(sprintf('get_price (%s): Include VAT when sending set to %s', $product->get_id(), $include_vat_when_sending));

            $preferred_pricelist = get_option('wr2o_product_pricelist');

            if ('_sale' == $preferred_pricelist) {

                if ($product->is_on_sale('edit')) {
                    $sale_price = $product->get_sale_price('edit');
                    Wr2o_Logger::add(sprintf('get_price (%s): Using sale price %s instead of price %s', $product->get_id(), $sale_price, $new_price));
                    $new_price = $sale_price;
                }
            }

            if (($product->get_type() == 'wgm_gift_card') && ($mwb_wgm_meta = $product->get_meta('mwb_wgm_pricing', true, 'edit'))){
                if (($mwb_wgm_regular_price = $mwb_wgm_meta["default_price"])){
                    $new_price = $mwb_wgm_regular_price;
                    Wr2o_Logger::add(sprintf('get_price (%s): WooCommerce Ultimate Gift Card price %s found', $product->get_id(), $new_price));
                }
            }

            if (wc_tax_enabled()) {
                if (!$include_vat_when_sending) {
                    if ('yes' == get_option('woocommerce_prices_include_tax')) {
                        $new_price = $new_price / (1 + ((int) $tax_rate / 100));
                    }
                } else {
                    if ('yes' != get_option('woocommerce_prices_include_tax')) {
                        $new_price = $new_price * (1 + ((int) $tax_rate / 100));
                    }
                }
            } elseif (!$include_vat_when_sending) {
                $new_price = $new_price / (1 + ((int) $tax_rate / 100));
            }

            Wr2o_Logger::add(sprintf('get_price (%s): Using price %s for product', $product->get_id(), $new_price));

            return $new_price;
        }

        public function product_array($product, $resource)
        {
            $product_id = $product->get_id();

            $price = ($price = $product->get_regular_price('edit')) ? $price : 0;
            $tax_rate = Wr2o_Helper::get_tax_rate($product, $resource);
            $wc_price_inc_vat = $this->price_includes_vat($product);
            $include_vat_when_sending = ($resource && isset($resource['product_priceIncludesVat'])) ? $resource['product_priceIncludesVat'] : $wc_price_inc_vat;
            $is_variant = $product->get_parent_id();

            $product_name = $product->get_name('edit');

            if ($is_variant) {
                $product_name = Wr2o_Helper::generate_variant_identifier($product_name,$product);
            }

            $product_name = apply_filters('wr2o_product_name', $product_name, $product);

            $product_price = (string) $this->get_price($product, $include_vat_when_sending, $tax_rate, $wc_price_inc_vat);

            $new_resource = array(
                "product_name" => $product_name,
                "product_externalReference" => (string) $product->get_id('edit'),
                "product_priceIncludesVat" => $include_vat_when_sending,
                "product_price" => $product_price ? $product_price : "0",
                "productgroup_id" => (int) apply_filters('wr2o_maybe_add_productgroup', get_option('wr2o_products_export_default_product_group'), $product),
            );

            if (get_option('wr2o_products_export_sku') == 'yes') {
                $new_resource['product_itemnumber'] = $product->get_sku() ? $product->get_sku() : '';
            }

            if (get_option('wr2o_products_force_export_productgroup') == 'yes') {
                $new_resource["productgroup_id"] = get_option('wr2o_products_export_default_product_group');
            }

            if (get_option('wr2o_products_export_productgroup') != 'yes' && isset($resource) && isset($resource['productgroup_id'])) {
                unset($new_resource["productgroup_id"]);
            }

            if ('yes' == get_option('wr2o_products_overwrite_stocklevel_in_ready2order')) {
                if (!($product->is_type('variable') && Wr2o_Helper::use_ready2order_variable_products())){
                    $new_resource['product_stock_enabled'] = $product->get_manage_stock('edit');

                    if (false !== ($wc_stocklevel = apply_filters('wr2o_maybe_get_stocklevel_to_update_ready2order', false, $product, $resource))) {
                        $new_resource["product_stock_value"] = $wc_stocklevel;
                    }
                } 
            }

            if (false !== $tax_rate) {
                $new_resource["product_vat"] = $tax_rate;
            }

            if (($parent_id = $product->get_parent_id()) && Wr2o_Helper::use_ready2order_variable_products()){
                if($product_base_id = Wr2o_Helper::get_r2o_product_id_from_product($parent_id)){
                    if (!$resource) {
                        $new_resource["product_base"]["product_id"] = $product_base_id;
                        Wr2o_Logger::add(sprintf('product_array (%s): Adding product_base_id %s to variant product', $product_id, $product_base_id));
                    } else {
                        $new_resource["product_base_id"] = $product_base_id;
                        Wr2o_Logger::add(sprintf('product_array (%s): Adding product_base_id %s to existing variant product', $product_id, $product_base_id));
                    }
                }   
            }  

            Wr2o_Logger::add(sprintf('product_array: new resource %s', json_encode($new_resource)));

            return $new_resource;
        }

        public function get_currency()
        {
            $currency = get_woocommerce_currency();
            $preferred_pricelist = trim(get_option('ready2order_product_pricelist'));
            if (strstr($preferred_pricelist, 'wcpbc_')) {
                $preferred_pricelist = trim($preferred_pricelist, 'wcpbc_');
                if ($pricing_zone = WCPBC_Pricing_Zones::get_zone_by_id($preferred_pricelist)) {
                    $currency = $pricing_zone->get_currency();
                    Wr2o_Logger::add(sprintf('Using %s as currency from wcpbc pricing zone %s', $currency, $pricing_zone->get_name()));
                }
            }

            return apply_filters('ready2order_wc_product_currency', $currency);
        }

        public function is_product_changed($new_product, $old_product)
        {
            return Wr2o_Helper::array_diff($new_product, $old_product);
        }

        public function maybe_clean_orphan($product,$ready2order_product){
            $product_id = $product->get_id();
        
            if (($parent_id = $product->get_parent_id()) && ($parent_r2o_id = Wr2o_Helper::get_r2o_product_id_from_product($parent_id))){
                Wr2o_Logger::add(sprintf('maybe_clean_orphan (%s): Found parent ready2order product id %s', $product_id, $parent_r2o_id));

                if (($product_base_id = $ready2order_product["product_base_id"]) && $parent_r2o_id != $product_base_id){
                    Wr2o_Logger::add(sprintf('maybe_clean_orphan (%s): Orphaned ready2order product %s found', $product_id, $product_base_id));

                    if ('yes' == get_option('wr2o_delete_ready2order_products')) {
                        $this->maybe_delete_ready2order_product($product_id); 
                        return null;
                    } 
                }
                
                
            }

            return $ready2order_product;
        }

        public function get_current_product($product,$r2o_product_id = false)
        {

            if($r2o_product_id){
                Wr2o_Logger::add(sprintf('get_current_product (%s): Existing ready2order product id "%s" supplied', $product->get_id(), $r2o_product_id));
            }

            $r2o_product_id = $r2o_product_id ? $r2o_product_id : Wr2o_Helper::get_r2o_product_id_from_product($product);

            if ($r2o_product_id) {
                Wr2o_Logger::add(sprintf('get_current_product (%s): ready2order uuid %s found on product', $product->get_id(), $r2o_product_id));
                if (!empty($ready2order_product = Wr2o_API::get("products/$r2o_product_id"))) {
                    Wr2o_Logger::add(sprintf('get_current_product (%s): Existing product found by ready2order product id "%s"', $product->get_id(), $ready2order_product['product_id']));

                    $ready2order_product = $this->maybe_clean_orphan($product,$ready2order_product);

                    return $ready2order_product;
                }

                Wr2o_Logger::add(sprintf('get_current_product (%s): Product found in WooCommerce but missing in ready2order', $product->get_id()));
            } else {
                Wr2o_Logger::add(sprintf('get_current_product (%s): No matching ready2order product found', $product->get_id()));
            }

            return null;
        }

        public function process_woocommerce_product_export($product, $sync_all, $new_product, $rerun = false){

            $product_id = $product->get_id();
            $r2o_product_id = "[N/A]";

            try {
                $resource = $new_product ? null : $this->get_current_product($product);

                $updating_resource = $this->product_array(
                    $product,
                    $resource
                );

                if (null === $resource) {
                    Wr2o_Logger::add(sprintf('process_woocommerce_product_export (%s): Creating new ready2order %s product from WooCommerce product', $product_id, $product->get_type()));

                    $resource = Wr2o_API::put("products", $updating_resource);
                } else {
                    if ($this->is_product_changed($updating_resource, $resource)) {
                        $r2o_product_id = $resource['product_id'];
                        $resource = Wr2o_API::post("products/$r2o_product_id", $updating_resource);
                        Wr2o_Logger::add(sprintf('process_woocommerce_product_export (%s): Updating %s product', $product_id, $product->get_type()));
                    } else {
                        Wr2o_Logger::add(sprintf('process_woocommerce_product_export (%s): No data changed compared to the existing ready2order product', $product_id));
                    }
                }

                Wr2o_Helper::maybe_update_r2o_timestamp($product, $resource);

                do_action('wr2o_remove_product_update_actions');

                if (($old_product_id = $product->get_meta('_r2o_product_id', true, 'edit')) != $resource['product_id']) {
                    $product->update_meta_data('_r2o_product_id', $resource['product_id']);
                    Wr2o_Logger::add(sprintf('process_woocommerce_product_export (%s): Changing ready2order product id from %s to %s', $product_id, $old_product_id, $resource['product_id']));
                }

                if (Wr2o_Helper::get_r2o_product_id_from_product($product) != $resource['product_id']) {
                    Wr2o_Logger::add(sprintf('process_woocommerce_product_export (%s): Changing ready2order product id again from %s to %s', $product_id, Wr2o_Helper::get_r2o_product_id_from_product($product), $resource['product_id']));
                    Wr2o_Helper::set_r2o_product_id($product, $resource['product_id'], false);
                }

                $product->save();

                Wr2o_Logger::add(sprintf('process_woocommerce_product_export (%s): Done updating %s product', $product_id, $product->get_type()));

                //do_action('wr2o_add_product_update_actions');

            } catch (Wr2o_API_Exception $e) {
                if(!$new_product && $e->getCode() == 404 && !$rerun){
                    Wr2o_Logger::add(sprintf(__("process_woocommerce_product_export (%s): Product not found in ready2order", 'wc-ready2order-integration'), $product_id));
                    $this->process_woocommerce_product_export($product, $sync_all, true, true);
                }else{
                    $e->write_to_logs();
                    Wr2o_Notice::add(sprintf(__("ready2order: %s when syncing product %s", 'wc-ready2order-integration'), $e->getMessage(), $product_id));
                }
            }
        }

        //Bulk method to catch both 
        public function process_woocommerce_products_export($product_id, $sync_all, $new_product)
        {
            Wr2o_Logger::add(sprintf('process_woocommerce_products_export (%s): Starting to process WooCommerce %s', $product_id, $new_product ? 'new product' : 'product'));

            $product = wc_get_product($product_id);

            if ($product->is_type('variable')) {

                if (!(wc_string_to_bool(get_option('wr2o_product_export_enable_variable_products_v2')) || wc_string_to_bool(get_option('wr2o_product_export_enable_variable_products')))) {
                    Wr2o_Logger::add(sprintf('process_woocommerce_products_export (%s): Variable products not set to be created', $product_id));
                    return;
                }

                if (wc_string_to_bool(get_option('wr2o_product_export_enable_variable_products_v2')) && !wc_string_to_bool(get_option('wr2o_product_export_enable_variable_products'))) {
                    Wr2o_Logger::add(sprintf('process_woocommerce_products_export (%s): Creating ready2order parent product', $product_id));
                    $this->process_woocommerce_product_export($product, $sync_all, $new_product);
                } elseif (wc_string_to_bool(get_option('wr2o_product_export_enable_variable_products'))) {
                    Wr2o_Logger::add(sprintf('process_woocommerce_products_export (%s): Only variants will be created in ready2order - skipping parent', $product_id));
                }

                $variations = Wr2o_Helper::get_all_variations($product);

                foreach ($variations as $variation) {

                    if (!is_object($variation)) {
                        $variation = wc_get_product($variation['variation_id']);
                    }

                    do_action('wr2o_process_woocommerce_products_export_add', $variation->get_id(), $sync_all, $new_product);

                }

            } elseif ($product->is_type('variation')) {
                if (wc_string_to_bool(get_option('wr2o_product_export_enable_variable_products_v2')) && !wc_string_to_bool(get_option('wr2o_product_export_enable_variable_products'))) {
                    $parent_id = $product->get_parent_id();

                    if ($parent_id && Wr2o_Helper::get_r2o_product_id_from_product($parent_id)) {
                        Wr2o_Logger::add(sprintf('process_woocommerce_products_export (%s): Syncing variant', $product_id));
                        $this->process_woocommerce_product_export($product, $sync_all, $new_product);
                    } else {
                        Wr2o_Logger::add(sprintf('process_woocommerce_products_export (%s): Parent not yet created in ready2order - skipping', $product_id));
                    }
                } elseif (wc_string_to_bool(get_option('wr2o_product_export_enable_variable_products'))) {
                    Wr2o_Logger::add(sprintf('process_woocommerce_products_export (%s): Syncing variant', $product_id));
                    $this->process_woocommerce_product_export($product, $sync_all, $new_product);
                } else {
                    Wr2o_Logger::add(sprintf('process_woocommerce_products_export (%s): Variants not set to be synced - skipping', $product_id));
                }
            } else {
                Wr2o_Logger::add(sprintf('process_woocommerce_products_export (%s): Syncing product', $product_id));
                $this->process_woocommerce_product_export($product, $sync_all, $new_product);
            }
        }

        public function process_product_wpml($original_product_id){

            if ($wpml_default_language = get_option('wr2o_wpml_default_language', apply_filters('wpml_default_language', null))) {

                $product_id = apply_filters('wpml_object_id', $original_product_id, 'product', false, $wpml_default_language);

                if (!$product_id) {
                    Wr2o_Logger::add(sprintf('process_product_wpml (%s): No product found for language "%s"', $original_product_id, $wpml_default_language));
                    return null;
                }

                if ($original_product_id != $product_id) {

                    $language = Wr2o_Helper::product_language($original_product_id, $wpml_default_language);

                    if ($product_id) {
                        Wr2o_Logger::add(sprintf('process_product_wpml (%s): Product has language "%s", the default language "%s" copy is %s', $original_product_id, $language, $wpml_default_language, $product_id));
                    } else {
                        Wr2o_Logger::add(sprintf('process_product_wpml (%s): Product has language "%s", no default language "%s" "copy exists', $original_product_id, $language, $wpml_default_language));
                    }

                    return $product_id;
                }

            }

            return $original_product_id;
        }

        public function wc_product_was_created($product_id, $product = null)
        {
            $wpml_product_id = $this->process_product_wpml($product_id);

            if ($wpml_product_id && $wpml_product_id == $product_id) {
                Wr2o_Logger::add(sprintf('wc_product_was_created (%s): Product was created', $product_id));

                if (Wr2o_Helper::is_syncable(wc_get_product($product_id))){
                    do_action('wr2o_process_woocommerce_products_export_add', $product_id, false, true);
                }
                
            } else {
                Wr2o_Logger::add(sprintf('wc_product_was_created (%s): Product update received from WooCommerce but product is a language copy to %s', $product_id, $wpml_product_id));
            }
        }

        public function wc_product_was_updated($product_id, $product = null)
        {

            $wpml_product_id = $this->process_product_wpml($product_id);

            if ($wpml_product_id && $wpml_product_id == $product_id) {
                Wr2o_Logger::add(sprintf('wc_product_was_updated (%s): Product was updated', $product_id));

                if (Wr2o_Helper::is_syncable(wc_get_product($product_id))){
                    do_action('wr2o_process_woocommerce_products_export_add', $product_id, false);
                }


            } else {
                Wr2o_Logger::add(sprintf('wc_product_was_updated (%s): Product update received from WooCommerce but product is a language copy to %s', $product_id, $wpml_product_id));
            }
        }

        public function clean_ready2order_meta($product_id)
        {
            delete_post_meta($product_id, '_r2o_product_updated_at');
            delete_post_meta($product_id, '_r2o_product_id');
        }

        public function duplicate_product_exclude_meta($meta_to_exclude, $existing_meta = array())
        {
            array_push($meta_to_exclude, '_r2o_product_updated_at');
            array_push($meta_to_exclude, '_r2o_product_id');

            return $meta_to_exclude;
        }

        public function maybe_delete_ready2order_product($product_id)
        {
            if (($product_uuid = Wr2o_Helper::get_r2o_product_id_from_product($product_id))) {
                if ('yes' == get_option('wr2o_delete_ready2order_products')){
                    do_action('wr2o_process_woocommerce_products_export_delete', $product_uuid);
                }

                $product = wc_get_product($product_id);

                if ($product->is_type('variable')) {
                    $variations = Wr2o_Helper::get_all_variations($product);
                    foreach ($variations as $variation) {
                        if (!is_object($variation)) {
                            $variation = wc_get_product($variation['variation_id']);
                        }

                        $this->clean_ready2order_meta($variation->get_id());
                    }
                } else {
                    $this->clean_ready2order_meta($product_id);
                }
            }
        }

        public function wc_product_was_trashed($id)
        {
            if (!$id) {
                return;
            }

            $post_type = get_post_type($id);

            if ('product' === $post_type) {
                Wr2o_Logger::add(sprintf('wc_product_was_trashed (%s): Product was trashed in WooCommerce', $id));
                $this->maybe_delete_ready2order_product($id);
            }
        }

        public function wc_product_was_deleted($id)
        {
            if (!$id) {
                return;
            }

            $post_type = get_post_type($id);

            if ('product' === $post_type) {
                Wr2o_Logger::add(sprintf('wc_product_was_deleted (%s): Product was deleted in WooCommerce', $id));
                $this->maybe_delete_ready2order_product($id);
            }
        }

        public function wc_product_was_untrashed($id)
        {
            if (!$id) {
                return;
            }

            $post_type = get_post_type($id);

            if ('product' === $post_type) {
                Wr2o_Logger::add(sprintf('wc_product_was_untrashed (%s): Product was untrashed in WooCommerce', $id));
                $this->wc_product_was_created($id);
            }
        }

        public function process_woocommerce_products_export_delete($product_id)
        {
            try {
                $resource = Wr2o_API::delete("products/{$product_id}");
                Wr2o_Logger::add(sprintf('process_woocommerce_products_export_delete: ready2order product id "%s" %s', $product_id, $resource['msg']));
            } catch (Wr2o_API_Exception $e) {
                if (404 != $e->getCode()) {
                    throw new $e($e->getMessage(), $e->getCode(), $e);
                }
                Wr2o_Logger::add(sprintf('process_woocommerce_products_export_delete: ready2order product id "%s" was not found when trying to delete.', $product_id));
            }
        }

        public function process_woocommerce_products_export_add($product_id, $sync_all, $new_product = false)
        {
            if (true === $sync_all || !is_admin() || 'yes' == get_option('wr2o_queue_admin_requests')) {

                Wr2o_Helper::add_to_queue('wr2o_process_woocommerce_products_export', array($product_id, $sync_all, $new_product), 'process_woocommerce_products_export_add', 'Product', $product_id);

            } else {
                do_action('wr2o_process_woocommerce_products_export', $product_id, $sync_all, $new_product);
            }
        }

        public function process_woocommerce_products_export_all($number_synced = 0, $sync_all = false)
        {
            $args = array(
                'limit' => -1,
                'return' => 'ids',
                'type' => get_option('ready2order_products_include', array('simple', 'variable', 'wgm_gift_card')),
                'status' => ($product_status = get_option('ready2order_product_status', array('draft', 'pending', 'private', 'publish'))) ? $product_status : array('draft', 'pending', 'private', 'publish'),
                'category' => empty($categories = get_option('ready2order_product_categories', array())) ? array() : $categories,
                'stock_status' => 'yes' == get_option('ready2order_sync_in_stock_only') ? 'instock' : '',
            );

            $this_sync_time = gmdate('U');

            if (!$sync_all) {
                if (($last_sync_done = get_site_transient('ready2order_upgraded_sync_from'))) {
                    delete_site_transient('ready2order_upgraded_sync_from');
                } else {
                    $last_sync_done = get_option('ready2order_last_product_sync_done', $this_sync_time);
                }
                if ($last_sync_done) {
                    $args['date_modified'] = $last_sync_done . '...' . $this_sync_time;
                }
                update_option('ready2order_last_product_sync_done', $this_sync_time);
            }

            $wpml_default_language = get_option('wr2o_wpml_default_language', apply_filters('wpml_default_language', null));

            if ($wpml_default_language) {
                $args['suppress_filters'] = true;
                Wr2o_Logger::add(sprintf('process_woocommerce_products_export_all: WMPL or Polylang detected, using products with language code %s when syncing products', $wpml_default_language));
            }

            $products_ids = wc_get_products($args);

            $total_to_sync = count($products_ids);

            if ($total_to_sync > 0) {
                Wr2o_Logger::add(sprintf('process_woocommerce_products_export_all: Got %d products from WooCommerce', $total_to_sync));
                $products_added = array();

                foreach ($products_ids as $original_product_id) {
                    if (Wr2o_Helper::is_syncable(wc_get_product($original_product_id))) {
                        if ($wpml_default_language) {
                            $product_id = apply_filters('wpml_object_id', $original_product_id, 'product', false, $wpml_default_language);
                            if ($product_id && !in_array($product_id, $products_added)) {
                                do_action('wr2o_process_woocommerce_products_export_add', $product_id, $sync_all);
                                $products_added[] = $product_id;
                                if ($product_id != $original_product_id) {
                                    Wr2o_Logger::add(sprintf('process_woocommerce_products_export_all: Added product id %s to the sync queue instead of product id %s as the default language is %s', $product_id, $original_product_id, $wpml_default_language));
                                }
                            } else {
                                Wr2o_Logger::add(sprintf('process_woocommerce_products_export_all: Skipping product id %s as it was a language duplicate for product id %s', $original_product_id, $product_id ? $product_id : 'missing'));
                            }
                        } else {
                            do_action('wr2o_process_woocommerce_products_export_add', $original_product_id, $sync_all);
                            $products_added[] = $original_product_id;
                        }
                    }
                }

                Wr2o_Logger::add(sprintf('process_woocommerce_products_export_all: Added %d products to queue for updating ready2order', count($products_added)));
            }

            if (!empty($products_added)) {
                return count($products_added);
            } else {
                return 0;
            }
        }

        /**
         * Trigger product updates when sales price has changed on a product
         *
         * @param array $product_ids product ids that was changed due to sale
         *
         * @since 6.5.0
         */
        public function update_products_when_sales_price_is_changed($product_ids)
        {
            $parents = array();

            foreach ($product_ids as $product_id) {

                $product = wc_get_product($product_id);

                if ($product && ($parent_id = $product->get_parent_id())) {
                    if (!in_array($parent_id,$parents)){
                        array_push($parents,$parent_id);
                        $product_id = $parent_id;
                    }else{
                        continue;
                    }
                }

                do_action('wr2o_process_woocommerce_products_export_add', $product_id, false);

            }
        }

        public static function instance(){
            if (null === self::$instance) {
                self::$instance = new self();
            }
            return self::$instance;
        }
    }

    function BT_R2O_PH()
    {
        return Wr2o_WooCommerce_Product_Handler::instance();
    }

    $bt_r2o_product_handler = BT_R2O_PH();
}

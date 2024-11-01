<?php

/**
 * Wr2o_Helper.
 *
 * @class           Wr2o_Helper
 *
 * @since           2.12.0
 *
 * @category        Class
 *
 * @author          bjorntech
 */
defined('ABSPATH') || exit;

if (!class_exists('Wr2o_Helper', false)) {
    class Wr2o_Helper
    {
        public static $all_products;

        public static function is_syncable_r2o_group($resource){
            $product_group_id = $resource['productgroup_id'];
            $name = $resource['productgroup_name'];

            Wr2o_Logger::add(sprintf('is_syncable_r2o_group (%s): Checking if product group can be synced', $product_group_id,));

            if (($included_product_groups = get_option('wr2o_ready2order_included_product_groups',[]))){
                if(!in_array($product_group_id,$included_product_groups)){
                    Wr2o_Logger::add(sprintf('is_syncable_r2o_group (%s): Product group "%s" is not included in list "%s" - skipping', $product_group_id, $name, implode(',',$included_product_groups)));
                    return false;
                }
            }

            if (($excluded_product_groups = get_option('wr2o_ready2order_excluded_product_groups',[]))){
                if(in_array($product_group_id,$excluded_product_groups)){
                    Wr2o_Logger::add(sprintf('is_syncable_r2o_group (%s): Product group "%s" is part of exclusion list "%s" - skipping', $product_group_id, $name, implode(',',$excluded_product_groups)));
                    return false;
                }
            }

            return true;
        }

        public static function is_syncable_r2o_product($resource){

            $id = $resource['product_id'];
            $name = $resource['product_name'];
            $product_group_id = $resource['productgroup_id'];

            if (($included_product_groups = get_option('wr2o_ready2order_included_product_groups',[]))){

                Wr2o_Logger::add(sprintf('is_syncable_r2o_product (%s): Checking if product can be synced', $id,));


                $included_product_groups = self::maybe_get_children_product_groups($included_product_groups,'wr2o_ready2order_included_product_groups_full');

                if(!in_array($product_group_id,$included_product_groups)){
                    Wr2o_Logger::add(sprintf('is_syncable_r2o_product (%s): Product "%s" has product group "%s" which is not included in list "%s" - skipping', $id, $name, $product_group_id,implode(',',$included_product_groups)));
                    return false;
                }
            }

            if (($excluded_product_groups = get_option('wr2o_ready2order_excluded_product_groups',[]))){

                $excluded_product_groups = self::maybe_get_children_product_groups($excluded_product_groups,'wr2o_ready2order_excluded_product_groups_full');

                if(in_array($product_group_id,$excluded_product_groups)){
                    Wr2o_Logger::add(sprintf('is_syncable_r2o_product (%s): Product "%s" has product group "%s" which is part of the exclusion list "%s" - skipping', $id, $name, $product_group_id, implode(',',$excluded_product_groups)));
                    return false;
                }
            }

            if (($excluded_strings = get_option('wr2o_ready2order_excluded_product_strings',''))){
                $excluded_strings_arr = explode(',',$excluded_strings);
                foreach($excluded_strings_arr as $excluded_string){
                    if (strpos($name, $excluded_string) !== false){
                        Wr2o_Logger::add(sprintf('is_syncable_r2o_product (%s): Product name "%s" contains excluded string "%s" - skipping', $id, $name, $excluded_string));
                        return false;
                    }
                }
            }

            return true;
        }

        public static function is_syncable($product)
        {
            $product_id = $product->get_id();

            if ('yes' == $product->get_meta('_ready2order_nosync', true, 'edit')) {
                Wr2o_Logger::add(sprintf('is_syncable (%s): Product is set not to sync by user', $product_id));

                return false;
            }

            if ($product->is_type('variation')) {
                $parent_id = $product->get_parent_id();
                $parent = wc_get_product($parent_id);
                Wr2o_Logger::add(sprintf('is_syncable (%s): Changed check to product parent %s', $product_id, $parent_id));

                if ('yes' == $parent->get_meta('_ready2order_nosync', true, 'edit')) {
                    Wr2o_Logger::add(sprintf('is_syncable (%s): Parent is set not to sync by user', $parent_id));

                    return false;
                }
            } else {
                $parent_id = $product_id;
                $parent = $product;
            }

            $product_type = $parent->get_type();
            $products_include = get_option('wr2o_woocommerce_included_products_types', ['simple', 'variable', 'wgm_gift_card']);
            if (!in_array($product_type, $products_include)) {
                Wr2o_Logger::add(sprintf('is_syncable (%s): Product type "%s" is not within "%s"', $product_id, $product_type, implode(',', $products_include)));

                return false;
            }

            $product_statuses = ($product_status = get_option('wr2o_woocommerce_included_products_statuses', ['draft', 'pending', 'private', 'publish'])) ? $product_status : ['draft', 'pending', 'private', 'publish'];
            $status = $parent->get_status('edit');
            if (!in_array($status, $product_statuses)) {
                Wr2o_Logger::add(sprintf('is_syncable (%s): Product status "%s" is not within "%s"', $product_id, $status, implode(',', $product_statuses)));

                return false;
            }

            $category_ids = $parent->get_category_ids('edit');

            $product_categories = !($product_categories_raw = get_option('wr2o_woocommerce_included_products_categories')) ? [] : array_map('self::get_term_by_slug', $product_categories_raw);
            if (!empty($product_categories) && empty(array_intersect($category_ids, $product_categories))) {
                Wr2o_Logger::add(sprintf('is_syncable (%s): Product categories "%s" is not within "%s"', $product_id, implode(',', $category_ids), implode(',', $product_categories)));

                return false;
            }

            $in_stock_only = 'yes' == get_option('wr2o_woocommerce_included_products_stock_only');
            if ($in_stock_only && !$product->is_in_stock('edit')) {
                Wr2o_Logger::add(sprintf('is_syncable (%s): Product has no stock and sync_stock_only is "%s"', $product_id, self::true_or_false($in_stock_only)));

                return false;
            }

            return true;
        }


        public static function maybe_get_children_product_groups ($product_group_ids, $transient_name){


            $product_groups = apply_filters('wr2o_get_all_productgroups', array());

            $child_groups = array();

            foreach ($product_group_ids as $product_group_id) {
                $child_groups = array_merge($child_groups,self::find_productgroup_children($product_group_id,$product_groups)); 
            }

            $product_group_ids = array_unique(array_merge($product_group_ids,$child_groups));

            return $product_group_ids;
        }

        public static function find_productgroup_children ($product_group_id, $product_groups, $product_group_child_ids = array()) {
            

            foreach ($product_groups as $product_group) {
                if (is_null($product_group_parent_id = $product_group['productgroup_parent'])) {
                    continue;
                }

                if ($product_group_id != $product_group_parent_id) {
                    continue;
                }

                $child_product_group_id = $product_group["productgroup_id"];

                if (!in_array($child_product_group_id,$product_group_child_ids)){
                    array_push($product_group_child_ids, $child_product_group_id);
                    $product_group_child_ids = self::find_productgroup_children($child_product_group_id,$product_groups,$product_group_child_ids);
                }
            }

            return $product_group_child_ids;
        }

        public static function get_variations($product)
        {
            $available_variations = [];

            foreach ($product->get_visible_children() as $child_id) {
                $variation = wc_get_product($child_id);

                if (!$variation || !$variation->exists()) {
                    continue;
                }

                $avaliable_variation = $product->get_available_variation($variation);

                if ($avaliable_variation) {
                    $available_variations[] = $avaliable_variation;
                }
            }

            return $available_variations;
        }

        public static function compare_id($product_id, $sku)
        {
            if ((strlen($sku) > 3) && ('ID:' == substr($sku, 0, 3))) {
                if ($product_id == trim(substr($sku, 3))) {
                    return true;
                }
            }

            return false;
        }

        public static function compare_legacy_sku($product_id, $sku)
        {
            if (strlen($sku) > 4 && 'SKU:' == substr($sku, 0, 4)) {
                $wc_sku = trim(substr($sku, 4));
                if ($product_id == wc_get_product_id_by_sku($wc_sku)) {
                    return true;
                }
            }

            return false;
        }

        public static function get_shipping_zones(){
            $zones = WC_Shipping_Zones::get_zones();

            if (($default_zone = new WC_Shipping_Zone(0))){
                array_push($zones,$default_zone);
            }

            return $zones;
        }

        public static function compare_sku($product_id, $sku)
        {
            if ($sku && ($product_id == wc_get_product_id_by_sku($sku))) {
                return true;
            }

            return false;
        }

        public static function clean_sku($sku)
        {
            if (false !== ($pos = strpos($sku, ':'))) {
                $sku = substr($sku, $pos + 1);
            }

            return $sku;
        }

        public static function get_id($id)
        {
            if (false !== ($pos = strpos($id, 'ID:'))) {
                $product_id = substr($id, $pos + 3);
            } else {
                $product_id = wc_get_product_id_by_sku($id);
            }

            return trim($product_id);
        }

        public static function get_post_id_by_metadata($meta_value, $post_type = [], $meta_key = '', $max_posts = -1, $post_status = false, $taxonomy = false)
        {
            if ($meta_value) {
                $all_statuses = get_post_stati();
                if (isset($all_statuses['trash'])) {
                    unset($all_statuses['trash']);
                }

                $args = [
                    'posts_per_page' => -1,
                    'post_type' => $post_type,
                    'meta_key' => $meta_key,
                    'meta_value' => $meta_value,
                    'fields' => 'ids',
                    'post_status' => ((false === $post_status) ? $all_statuses : $post_status),
                ];

                if (false !== $taxonomy) {
                    $args['tax_query'] = $taxonomy;
                }

                $query = new WP_Query($args);

                $have_posts = $query->have_posts();

                if ($have_posts) {
                    $posts = $query->get_posts();
                    $post_count = count($posts);

                    if (1 == $max_posts && 1 == $post_count) {
                        return reset($posts);
                    } elseif (-1 == $max_posts) {
                        return $posts;
                    } elseif ($max_posts >= $post_count) {
                        return $posts;
                    } else {
                        throw new Wr2o_Exception(sprintf('get_post_id_by_metadata - %s %s found on more posts than expected (%s).', $meta_key, $meta_value, implode(',', $posts)), $have_posts ? $post_count : -1);
                    }
                }
            }

            return null;
        }

        public static function get_product_categories()
        {
            $cat_args = [
                'orderby' => 'name',
                'order' => 'asc',
                'hide_empty' => false,
            ];

            return get_terms('product_cat', $cat_args);
        }

        public static function get_category_names($ids)
        {
            $product_categories = self::get_product_categories();
            $categories = [];
            foreach ($product_categories as $product_category) {
                foreach ($ids as $id) {
                    if (($product_category->term_id == $id) && ($product_category->name != _x('Uncategorized', 'Default category slug', 'woocommerce'))) {
                        $categories[] = $product_category->name;
                    }
                }
            }

            return $categories;
        }

        public static function get_tax_rate($product, $resource)
        {
            if (isset($resource['product_vat'])) {
                $current_tax_rate = intval($resource['product_vat']);
            } else {
                $current_tax_rate = 0;
            }

            $product_id = $product->get_id();

            if (wc_tax_enabled()) {
                $tax_class = $product->get_tax_class();

                if ($tax_rate = get_option('wr2o_tax_mapping_'.$tax_class)) {
                    if ($tax_rate != $current_tax_rate) {
                        Wr2o_Logger::add(sprintf('get_tax_rate (%s): Tax rate changed from %s to %s by settings', $product_id, $current_tax_rate, $tax_rate));
                    }

                    return (string) $tax_rate;
                }

                $calculate_tax_for = [
                    'country' => WC()->countries->get_base_country(),
                    'state' => WC()->countries->get_base_state(),
                    'city' => WC()->countries->get_base_city(),
                    'postcode' => WC()->countries->get_base_postcode(),
                    'tax_class' => $tax_class,
                ];

                $tax_rates = WC_Tax::find_rates($calculate_tax_for);
                $taxes = WC_Tax::calc_tax(100, $tax_rates, false);

                if ($taxes) {
                    $tax_rate = reset($taxes);

                    if ($tax_rate != $current_tax_rate) {
                        Wr2o_Logger::add(sprintf('get_tax_rate (%s): Tax rate changed from %s to %s by search', $product_id, $current_tax_rate, $tax_rate));
                    }

                    return (string) $tax_rate;
                } else {
                    Wr2o_Logger::add(sprintf('get_tax_rate (%s): Product has no tax rate set. Setting tax rate to 0.', $product_id));
                }
            }

            Wr2o_Logger::add(sprintf('get_tax_rate (%s): Tax not enabled. Setting tax rate to 0', $product_id));

            return '0';
        }

        public static function wc_version_check($version = '4.0')
        {
            if (class_exists('WooCommerce')) {
                global $woocommerce;
                if (version_compare(self::wc_version(), $version, '>=')) {
                    return true;
                }
            }

            return false;
        }

        public static function wc_version()
        {
            global $woocommerce;

            return $woocommerce->version;
        }

        public static function object_diff(stdClass $obj1, stdClass $obj2): bool
        {
            $array1 = json_decode(json_encode($obj1), true);
            $array2 = json_decode(json_encode($obj2), true);

            return self::array_diff($array1, $array2);
        }

        public static function array_diff(array $array1, array $array2): bool
        {
            foreach ($array1 as $key => $value) {
                if (array_key_exists($key, $array2)) {
                    if ($value instanceof stdClass) {
                        $r = self::object_diff((object) $value, (object) $array2[$key]);
                        if ($r === true) {
                            return true;
                        }
                    } elseif (is_array($value)) {
                        $r = self::array_diff((array) $value, (array) $array2[$key]);
                        if ($r === true) {
                            return true;
                        }
                    } elseif (is_double($value)) {
                        // required to avoid rounding errors due to the
                        // conversion from string representation to double
                        if (0 !== bccomp($value, $array2[$key], 12)) {
                            Wr2o_Logger::add(sprintf('array_diff: Key {%s} was changed from "%s" to "%s"', $key, $array2[$key], $value));

                            return true;
                        }
                    } else {
                        if ($value != $array2[$key]) {
                            Wr2o_Logger::add(sprintf('array_diff: Key {%s} was changed from "%s" to "%s"', $key, $array2[$key], $value));

                            return true;
                        }
                    }
                } else {
                    Wr2o_Logger::add(sprintf('array_diff: Key {%s} does not exist in old data', $array1[$key]));

                    return true;
                }
            }

            return false;
        }

        /**
         * Returns the product id or the string 'n/a' if the parameter is empty or not a number.
         *
         * @since 6.0.0
         *
         * @param string $product_id
         *
         * @return string
         */
        public static function id_or_not($product_id)
        {
            if (empty($product_id) || !is_numeric($product_id)) {
                return 'n/a';
            }

            return $product_id;
        }

        /**
         * Returns the product id or the string 'n/a' if the parameter is empty or not a number.
         *
         * @since 6.0.0
         *
         * @return string
         */
        public static function true_or_false($boolean)
        {
            if (!is_bool($boolean)) {
                return 'not bool';
            }

            return $boolean ? 'true' : 'false';
        }

        /**
         * Sorts an array based on keys.
         *
         * @since 6.0.0
         *
         * @param array $array
         *
         * @return bool
         */
        public static function ksort_recursive(&$array)
        {
            if (is_array($array)) {
                ksort($array);
                array_walk($array, 'self::ksort_recursive');
            }
        }

        public static function get_term_by_slug($slug)
        {
            $term = get_term_by('slug', $slug, 'product_cat');

            return $term->term_id ? $term->term_id : '';
        }

        public static function get_processing_queue($id)
        {

            $hook_actions = as_get_scheduled_actions(
                [
                    'hook' => $id,
                    //'group' => $id,
                    'status' => ActionScheduler_Store::STATUS_PENDING,
                    'claimed' => false,
                    'per_page' => -1,
                ],
                'ids'
            );

            $group_actions = as_get_scheduled_actions(
                [
                    'group' => $id,
                    'status' => ActionScheduler_Store::STATUS_PENDING,
                    'claimed' => false,
                    'per_page' => -1,
                ],
                'ids'
            );
            
            return array_unique(array_merge($hook_actions,$group_actions));
        }

        public static function display_name($id)
        {
            switch ($id) {
                case 'woocommerce_products_export':
                    return __('WooCommerce products to export', 'wc-ready2order-integration');
                    break;
                case 'ready2order_products_import':
                    return __('ready2order products to import', 'wc-ready2order-integration');
                    break;
            }

            return '';
        }

        public static function display_sync_button($id, $button_label, $class = '')
        {
            if (!empty($processing_queue = self::get_processing_queue($id))) {
                echo '<div id='.$id.'_status name="'.$id.'" class="wr2o_processing_status" ></div>';
                $button_text = __('Cancel', 'wc-ready2order-integration');
            } else {
                $button_text = __('Start', 'wc-ready2order-integration');
            }

            echo '<div id='.$id.'_titledesc>';
            echo '<tr valign="top">';
            echo '<th scope="row" class="titledesc '.$class.'">';
            echo '<label for="'.$id.'">'.$button_label.'</label>';
            echo '</th>';
            echo '<td class="forminp forminp-button">';
            echo '<button id="'.$id.'" class="button wr2o_processing_button">'.$button_text.'</button>';
            echo '</td>';
            echo '</tr>';
            echo '</div>';
        }

        /**
         * Find a tax class based on a tax rate.
         *
         * @param string $rate
         *
         * @return string
         */
        public static function get_tax_class($rate)
        {
            $tax_classes = WC_Tax::get_tax_class_slugs();

            $tax_classes_incl_standard = array_merge($tax_classes, ['']);

            foreach ($tax_classes_incl_standard as $tax_class) {
                $class_rate = get_option('wr2o_tax_mapping_'.$tax_class);
                if ($class_rate !== '' && $class_rate == $rate) {
                    Wr2o_Logger::add(sprintf('get_tax_class: Found tax class "%s" with rate %s in settings', $tax_class, $rate));

                    return $tax_class;
                }
            }

            foreach ($tax_classes as $tax_class) {
                $base_tax_rates = WC_Tax::get_base_tax_rates($tax_class);
                if ($base_tax_rates && is_array($base_tax_rates) && reset($base_tax_rates)['rate'] == $rate) {
                    Wr2o_Logger::add(sprintf('get_tax_class: Found tax class "%s" with rate %s by rate-search', $tax_class, $rate));

                    return $tax_class;
                }
            }

            Wr2o_Logger::add('get_tax_class: Using tax class "" as default');

            return ''; // Default to standard rate
        }

        /**
         * Get an array of available variations for the current product.
         * Use our own to get all variations regardless of filtering.
         *
         * @return array
         */
        public static function get_all_variations($product)
        {
            $available_variations = [];

            foreach ($product->get_children() as $child_id) {
                $variation = wc_get_product($child_id);

                $available_variations[] = $product->get_available_variation($variation);
            }
            $available_variations = array_values(array_filter($available_variations));

            return $available_variations;
        }

        public static function product_language($product_id, $wpml_default_language = false)
        {

            $wpml_default_language = $wpml_default_language ? $wpml_default_language : get_option('wr2o_wpml_default_language', apply_filters('wpml_default_language', null));
            $lang = apply_filters('wpml_post_language_details', $wpml_default_language, $product_id);
            if ($lang && isset($lang['language_code'])) {
                return $lang['language_code'];
            }

        }

        public static function weight_from_grams($weight)
        {
            $unit = get_option('woocommerce_weight_unit', 'kg');

            $response = $weight;

            if (is_numeric($weight)) {
                switch ($unit) {
                    case 'kg':
                        $response = $weight / 1000;
                        break;
                    case 'lbs':
                        $response = $weight / 453.59237;
                        break;
                    case 'oz':
                        $response = $weight / 28.3495231;
                        break;
                    case 'g':
                    default:
                        $response = $weight;
                }
            }

            return $response;
        }

        public static function debug_string_backtrace()
        {
            ob_start();
            debug_print_backtrace();
            $trace = ob_get_contents();
            ob_end_clean();

            // Remove first item from backtrace as it's this function which
            // is redundant.
            $trace = preg_replace('/^#0\s+'.__FUNCTION__."[^\n]*\n/", '', $trace, 1);

            // Renumber backtrace items.
            $trace = preg_replace('/^#(\d+)/me', '\'#\' . ($1 - 1)', $trace);

            return $trace;
        }

        public static function get_wc_product_id_from_resource($resource)
        {
            if (!(empty($product_id = self::get_post_id_by_metadata($resource['product_id'], ['product','product_variant','product_variation'], '_r2o_product_id', 1)))) {
                return (int) $product_id;
            }

            if (isset($resource['product_itemnumber']) && !empty($resource['product_itemnumber']) && ($product_id = wc_get_product_id_by_sku(trim($resource['product_itemnumber'])))) {
                return $product_id;
            }

            return 0;
        }

        public static function get_r2o_product_id_from_product($product)
        {
            $product = is_object($product) ? $product : wc_get_product($product);

            if (($r2o_product_id = $product->get_meta('_r2o_product_id', true, 'edit'))) {
                return (int) $r2o_product_id;
            }

            return 0;
        }

        public static function set_r2o_product_id(&$product, $r2o_product_id, $save = true)
        {
            do_action('wr2o_remove_product_update_actions');

            $r2o_product_id = (string) $r2o_product_id;
            if (!empty($r2o_product_id) && empty(self::get_post_id_by_metadata($r2o_product_id, ['product','product_variant','product_variation'], '_r2o_product_id', 0))) {
                $product->update_meta_data('_r2o_product_id', $r2o_product_id);
                Wr2o_Logger::add(sprintf('set_r2o_product_id (%s): Updating ready2order metadata %s', $product->get_id(), $r2o_product_id));
            } else {
                $product->delete_meta_data('_r2o_product_id');
                Wr2o_Logger::add(sprintf('set_r2o_product_id (%s): Deleting ready2order metadata %s', $product->get_id(), $r2o_product_id));
            }

            if ($save === true) {
                $product->save();
            }

            //do_action('wr2o_add_product_update_actions');

        }

        public static function clear_cache(){

            $cache_keys = [
                'wr2o_all_products',
                'wr2o_all_users',
                'wr2o_all_billtypes',
                'wr2o_all_paymentmethods',
                'wr2o_productgroup_import'
            ];

            foreach ($cache_keys as $cache_key) {
                delete_site_transient($cache_key);
            }
        }

        public static function use_ready2order_variable_products(){
            return (('yes' == get_option('wr2o_product_export_enable_variable_products_v2')) && ('yes' != get_option('wr2o_product_export_enable_variable_products')));
        }

        public static function get_alternate_url()
        {
            return trailingslashit(($alternate_url = get_option('bjorntech_alternate_webhook_url')) ? $alternate_url : get_site_url());
        }

        public static function add_to_queue ($hook, $args, $calling_function, $entity_type, $entity_identifier) {
            if (as_has_scheduled_action($hook, $args)) {
                Wr2o_Logger::add(sprintf('%s (%s): %s already in queue', $calling_function, $entity_identifier, $entity_type));
            } else {
                Wr2o_Logger::add(sprintf('%s (%s): Queuing creation of %s', $calling_function, $entity_identifier, $entity_type));
                as_schedule_single_action(as_get_datetime_object()->getTimestamp(), $hook, $args);
            }
        }

        public static function generate_variant_identifier($product_name, $product){

            if (get_option('wr2o_products_additional_variant_identifier') == 'yes') {

                $product_name .= ' - ';

                foreach ($product->get_variation_attributes() as $attribute_name => $attribute) {
                    $product_name .= str_replace('attribute_','',$attribute_name) . ': ' . $attribute . ', ';
                }

                $product_name = substr($product_name, 0, -2);
                
            }

            return $product_name;
        }

        public static function clean_array(array $array): array
        {
            $return_array = [];

            foreach ($array as $key => $value) {
                if (is_array($value)) {
                    $return_array[$key] = self::clean_array((array) $value);
                } elseif (is_int($value)) {
                    $return_array[$key] = (int) $value;
                } elseif (is_float($value)) {
                    $return_array[$key] = (float) $value;
                } elseif ($value === 'true') {
                    $return_array[$key] = true;
                } elseif ($value === 'false') {
                    $return_array[$key] = false;
                } elseif ($value === 'null') {
                    $return_array[$key] = null;
                } else {
                    $return_array[$key] = sanitize_textarea_field($value);
                }
            }

            return $return_array;
        }

        /**
         * set_ready2order_invoice_number
         *
         * Set the ready2order invoice number on an order
         *
         * @access public
         * @return void
         */
        public static function set_ready2order_invoice_number(&$order, $ready2order_order_documentnumber)
        {
            if (!$order) {
                return;
            }
            
            if ($ready2order_order_documentnumber != "") {
                if ($order->meta_exists('_r2o_invoice_id')) {
                    $order->update_meta_data('_r2o_invoice_id', $ready2order_order_documentnumber);
                } else {
                    $order->add_meta_data('_r2o_invoice_id', $ready2order_order_documentnumber, true);
                }
            } else {
                $order->delete_meta_data('_r2o_invoice_id');
            }

            $order->save_meta_data();
        }

        /**
         * get_ready2order_invoice_number
         *
         * If the order has a ready2order invoice number, we will return it. If not present we return FALSE.
         *
         * @access public
         * @return string
         * @return bool
         */
        public static function get_ready2order_invoice_number($order_id)
        {
            $order = wc_get_order($order_id);

            if (!$order) {
                return false;
            }

            $result = $order->get_meta('_r2o_invoice_id');

            return $result;
        }

        public static function set_ready2order_full_invoice_number(&$order, $ready2order_order_documentnumber)
        {
            if ($ready2order_order_documentnumber != "") {
                if ($order->meta_exists('_r2o_full_invoice_id')) {
                    $order->update_meta_data('_r2o_full_invoice_id', $ready2order_order_documentnumber);
                } else {
                    $order->add_meta_data('_r2o_full_invoice_id', $ready2order_order_documentnumber, true);
                }
            } else {
                $order->delete_meta_data('_r2o_full_invoice_id');
            }

            $order->save_meta_data();
        }

        /**
         * get_ready2order_invoice_number
         *
         * If the order has a ready2order invoice number, we will return it. If not present we return FALSE.
         *
         * @access public
         * @return string
         * @return bool
         */
        public static function get_ready2order_full_invoice_number($order_id)
        {
            $order = wc_get_order($order_id);

            if (!$order) {
                return false;
            }

            $result = $order->get_meta('_r2o_full_invoice_id', true);

            return $result;
        }

        public static function maybe_update_r2o_timestamp(&$product, $resource, $save = false) {
            $changed = false;
            
            do_action('wr2o_remove_product_update_actions');


            if (isset($resource['product_updated_at']) && (($existing_timestamp = $product->get_meta('_r2o_product_updated_at', true, 'edit')) != $resource['product_updated_at'])) {
                $product->update_meta_data('_r2o_product_updated_at', $resource['product_updated_at']);
                $changed = true;
                Wr2o_Logger::add(sprintf('maybe_update_r2o_timestamp (%s): Changing ready2order product updated at from %s to %s', $product->get_id(), $existing_timestamp, $resource['product_updated_at']));
            }

            if ($save) {
                $product->save();
            }

            //do_action('wr2o_add_product_update_actions');

            return $changed;
        }
    }
}

<?php

/**
 * This class handles syncing productgroup with ready2order
 *
 * @package   WooCommerce_Ready_To_Order_Integration
 * @author    BjornTech <info@bjorntech.com>
 * @license   GPL-3.0
 * @link      http://bjorntech.com
 * @copyright 2017-2020 BjornTech - BjornTech AB
 */

defined('ABSPATH') || exit;

if (!class_exists('Wr2o_Ready2order_ProductGroup_Handler', false)) {

    class Wr2o_Ready2order_ProductGroup_Handler
    {

        private $changed;

        public function __construct()
        {

            add_action('wr2o_process_ready2order_productgroup_import_add', array($this, 'process_ready2order_productgroup_import_add'), 10, 3);
            add_action('wr2o_process_ready2order_productgroup_import', array($this, 'process_ready2order_productgroup_import'), 10, 3);

            add_filter('wr2o_process_ready2order_productgroup_import_all', array($this, 'process_ready2order_productgroup_import_all'), 10, 2);
            add_action('wr2o_process_ready2order_productgroup_import_all', array($this, 'process_ready2order_productgroup_import_all'), 10, 2);

            add_filter('wr2o_maybe_add_product_category', array($this, 'maybe_add_product_category'), 10, 4);
            add_filter('wr2o_get_all_productgroups', array($this, 'get_all_productgroups'));
            add_filter('wr2o_maybe_add_productgroup', array($this, 'maybe_add_productgroup'), 10, 2);

            add_filter('wr2o_maybe_process_ready2order_productgroup',array($this, 'filter_product_group'), 10, 2);

            $this->register_meta_key();

        }

        private function register_meta_key()
        {

            register_term_meta(
                'product_cat',
                'wr2o_productgroup_id',
                array(
                    'type' => 'string',
                    'description' => 'ready2order integration productgroup id',
                    'single' => true,
                    'default' => '',
                )
            );
        }

        /**
         * Processing an ready2order productgroup object or place in queue
         *
         * @since 1.0.0
         *
         * @param object $resource a ready2order product object or product update object
         * @param bool $webhook Set to true if called from a webhook, Default false
         */

         public function process_ready2order_productgroup_import_add($resource, $actions, $webhook = true)
         {
             if (true === $webhook) {
 
                 if ('yes' == get_option('wr2o_queue_webhook_calls')) {

                    Wr2o_Helper::add_to_queue('wr2o_process_ready2order_productgroup_import', array($resource, $actions, $webhook), 'process_ready2order_productgroup_import_add', 'Resource', $resource['productgroup_id']);
 
                 } else {
 
                     do_action('wr2o_process_ready2order_productgroup_import', $resource, $actions, $webhook);
 
                 }
 
             } else {
 
                 Wr2o_Helper::add_to_queue('wr2o_process_ready2order_productgroup_import', array($resource, $actions, $webhook), 'process_ready2order_productgroup_import_add', 'Resource', $resource['productgroup_id']); 
             }
 
        }

        public function maybe_add_productgroup($productgroup_id, $product)
        {
            $parent_id = $product->get_parent_id();
            $parent = false;

            if ($parent_id){
                $parent = wc_get_product($parent_id);
            }

            $category_ids = $parent ? $parent->get_category_ids('edit') : $product->get_category_ids('edit');

            if (!empty($category_ids)) {

                $first_id = reset($category_ids);

                $productgroup_id = get_term_meta($first_id, 'wr2o_productgroup_id', true);

                try{
                    $product_group_resource = Wr2o_API::get("productgroups/$productgroup_id");
                }catch (Wr2o_API_Exception $e){
                    if($e->getCode() == 404){
                        Wr2o_Logger::add(sprintf(__("maybe_add_productgroup (%s): Product group %s not found in ready2order", 'wc-ready2order-integration'), $product->get_id(),$productgroup_id));

                        if (!delete_term_meta($first_id,'wr2o_productgroup_id',$productgroup_id)) {
                            Wr2o_Logger::add(sprintf(__("maybe_add_productgroup (%s): Failed to remove non existing product group %s", 'wc-ready2order-integration'), $product->get_id(),$productgroup_id));
                        }else{
                            Wr2o_Logger::add(sprintf(__("maybe_add_productgroup (%s): Removed non existing product group %s", 'wc-ready2order-integration'), $product->get_id(),$productgroup_id));
                        }

                        $productgroup_id = false;
                    }else{
                        $e->write_to_logs();
                        Wr2o_Notice::add(sprintf(__("ready2order: %s when syncing product %s", 'wc-ready2order-integration'), $e->getMessage(), $productgroup_id));
                    }
                }

                if (!$productgroup_id) {

                    $existing_term = get_term_by('id', $first_id, 'product_cat', ARRAY_A);

                    $pg_description = $existing_term['description'];

                    if(strlen($existing_term['description']) > 254){
                        $pg_description = '';
                        Wr2o_Logger::add(sprintf(__("maybe_add_productgroup (%s): Description for category %s longer than 255 characters - skipping it", 'wc-ready2order-integration'), $product->get_id(),$existing_term['name']));
                    }

                    $resource = Wr2o_API::put("productgroups", array(
                        'productgroup_name' => $existing_term['name'],
                        'productgroup_description' => $pg_description,
                    ));

                    $productgroup_id = $resource['productgroup_id'];

                    add_term_meta($first_id, 'wr2o_productgroup_id', $productgroup_id, true);

                }

            }

            return $productgroup_id;

        }

        private function update_product_category($term_id, $name, $parent_id)
        {
            wp_update_term(
                $term_id,
                'product_cat',
                [
                    'name' => $name,
                    'slug' => sanitize_title($name),
                    'description' => $name,
                    'parent' => $parent_id ? $this->maybe_add_product_category($parent_id) : 0,
                ]
            );

        }

        private function get_category_by_productgroup_id($id)
        {

            global $wpdb;

            $result = $wpdb->get_results($wpdb->prepare("SELECT meta_key, meta_value, meta_id, term_id FROM $wpdb->termmeta WHERE meta_value = %d ORDER BY meta_key,meta_id", $id), ARRAY_A);

            if(!empty($result)){
                foreach($result as $key => $item){
                    if($item['term_id'] == $id){
                        unset($result[$key]);
                        delete_term_meta($item['term_id'],'wr2o_productgroup_id');
                    }
                }
            }

            return !empty($result) ? reset($result) : 0;

        }

        public function maybe_delete_product_category($id)
        {

            if ($term = $this->get_category_by_productgroup_id($id)) {

                wp_delete_term($term, 'product_cat');
                Wr2o_Logger::add(sprintf('maybe_add_product_category (%d): Deleting ready2order productgroup', $id));

            }

        }

        public function filter_product_group($include_product_group, $resource){

            $include_product_group = Wr2o_Helper::is_syncable_r2o_group($resource);
            
            return $include_product_group;
        }

        public function maybe_add_product_category($id, $name = null, $parent_id = 0, $description = '')
        {

            Wr2o_Logger::add(sprintf('maybe_add_product_category (%d): Processing ready2order productgroup "%s" with parent %s', $id, $name, $parent_id));

            if ($name === null && $id) {
                $resource = Wr2o_API::get("productgroups/$id");
                Wr2o_Logger::add(sprintf('maybe_add_product_category (%d): got resource %s', $id, json_encode($resource)));
                $name = $resource['productgroup_name'];
                $parent_id = $resource['productgroup_parent'];
                $description = $resource['productgroup_description'];

                if (wc_string_to_bool(get_option('wr2o_productgroup_no_parents'))) {
                    $parent_id = 0;
                }
            }

            if ($term = $this->get_category_by_productgroup_id($id)) {

                Wr2o_Logger::add(sprintf('maybe_add_product_category (%d): Found ready2order productgroup "%s" as product category %d', $id, $name, $term['term_id']));

                $this->update_product_category($term['term_id'], $name, $parent_id);

                return $term['term_id'];

            } else {

                Wr2o_Logger::add(sprintf('maybe_add_product_category (%d): ready2order productgroup "%s" by productgroup not found', $id, $name));

            }

            $term = wp_insert_term(
                $name,
                'product_cat',
                [
                    'slug' => sanitize_title($name),
                    'description' => $description,
                    'parent' => ($parent_id && !wc_string_to_bool(get_option('wr2o_productgroup_no_parents'))) ? $this->maybe_add_product_category($parent_id) : 0,
                ]
            );

            if (is_wp_error($term)) {

                if (array_key_exists('term_exists', $term->errors)) {

                    Wr2o_Logger::add(sprintf('maybe_add_product_category (%d): ready2order productgroup "%s" already exists as product category %d', $id, $name, $term->error_data['term_exists']));

                    $this->update_product_category($term->error_data['term_exists'], $name, $parent_id);

                    add_term_meta($term->error_data['term_exists'], 'wr2o_productgroup_id', $id, true);

                    return $term->error_data['term_exists'];

                } else {

                    foreach ($term->errors as $err_code => $message) {
                        $error_message = implode(',', $message);
                        Wr2o_Logger::add(sprintf('maybe_add_product_category (%d): %s (%s - %s) when trying to add ready2order productgroup %s', $id, $error_message, $err_code, $term->error_data[$err_code], sanitize_title($name)));
                    }

                    return false;
                }

            } else {

                add_term_meta($term['term_id'], 'wr2o_productgroup_id', $id, true);

                Wr2o_Logger::add(sprintf('maybe_add_product_category (%d): ready2order productgroup "%s" successfully created as category id %d', $id, $name, $term['term_id']));

                return $term['term_id'];

            }

        }

        /*      if ($term = get_term_by('slug', $slug, 'product_cat', ARRAY_A)) {

        return $term['term_id'];

        } else {*/
        public function process_ready2order_productgroup_import($resource, $actions, $webhook = true)
        {

            Wr2o_Logger::add(sprintf('process_ready2order_productgroup_import: got resource %s', json_encode($resource)));

            if (apply_filters('wr2o_maybe_process_ready2order_productgroup', true, $resource)) {

                $this->changed = false;

                if (count($resource) == 1 && false !== strpos($actions, 'delete')) {

                    $this->maybe_delete_product_category($resource['productgroup_id']);

                } else {

                    $product_cat = $this->maybe_add_product_category($resource['productgroup_id'], $resource['productgroup_name'], $resource['productgroup_parent'], $resource['productgroup_description']);

                }

            }

        }

        public function process_ready2order_productgroup_import_all($number_synced = 0, $sync_all = false)
        {

            $r2o_productgroups = $this->get_all_productgroups();

            if (!empty($r2o_productgroups)) {

                $import_actions = get_option('wr2o_productgroup_import_actions', 'update');

                foreach ($r2o_productgroups as $r2o_productgroup) {

                    Wr2o_Helper::add_to_queue('wr2o_process_ready2order_productgroup_import_add', array($r2o_productgroup, $import_actions, false), 'process_ready2order_productgroup_import_all', 'Product group', '');

                }

                Wr2o_Logger::add(sprintf('process_ready2order_products_import_queue: Queuing %d products groups from ready2order to processing queue in WooCommerce', count($r2o_productgroups)));

            }

            return count($r2o_productgroups);

        }

        public function get_all_productgroups($productsgroups = array())
        {

            try {

                if (get_site_transient('wr2o_productgroup_import')){
                    $productsgroups = get_site_transient('wr2o_productgroup_import');

                    return $productsgroups;
                }

                $page = 1;
                $batch_size = get_option('wr2o_productgroup_import_batch_size', 50);

                do {

                    $productsgroup_response = Wr2o_API::get("productgroups?page=$page&limit=$batch_size");
                    $productsgroups = array_merge($productsgroups, $productsgroup_response);
                    $page++;

                } while (!empty($productsgroup_response));

            } catch (Wr2o_API_Exception $e) {

                $e->write_to_logs();

            }

            set_site_transient('wr2o_productgroup_import',$productsgroups,300);

            return $productsgroups;

        }

    }

    new Wr2o_Ready2order_ProductGroup_Handler();

}

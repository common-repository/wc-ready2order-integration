<?php

/**
 * WC_Settings_Page_WRTO
 *
 * @class           WC_Settings_Page_WRTO
 * @since           1.0.0
 * @package         WooCommerce_Ready_To_Order_Integration
 * @category        Class
 * @author          BjornTech
 */

defined('ABSPATH') || exit;

if (!class_exists('WC_Settings_Page_WRTO', false)) {
    class WC_Settings_Page_WRTO extends WC_Settings_Page
    {
        public function __construct()
        {
            $this->id = 'ready2order';
            $this->label = __('ready2order', 'wc-ready2order-integration');

            add_filter('woocommerce_get_sections_' . $this->id, array($this, 'get_rto_sections'));
            add_filter('woocommerce_get_settings_' . $this->id, array($this, 'get_rto_settings'));
            add_action('woocommerce_settings_' . $this->id, array($this, 'authorize_processing'), 5);
            add_action('woocommerce_settings_wr2o_woocommerce_products_export', array($this, 'show_products_export_button'), 20);
            add_action('woocommerce_settings_wr2o_products_import', array($this, 'show_products_import_button'));
            add_action('woocommerce_settings_wr2o_general_settings', array($this, 'show_connection_button'));
            add_action('woocommerce_settings_wr2o_advanced_settings', array($this, 'show_refresh_button'), 20);
            add_action('woocommerce_settings_wr2o_general_settings', array($this, 'ready2order_connection_options_description'));

            add_action('woocommerce_settings_wr2o_woocommerce_products_export', array($this, 'ready2order_misc_options_description'));
            add_action('woocommerce_settings_wr2o_advanced_settings', array($this, 'ready2order_misc_options_description'));
            add_action('woocommerce_settings_wr2o_products_import', array($this, 'ready2order_misc_options_description'));
            add_action('woocommerce_settings_wr2o_stocklevel', array($this, 'ready2order_misc_options_description'));
            add_action('woocommerce_settings_wr2o_invoice_export', array($this, 'ready2order_misc_options_description'));

            add_action('ready2order_show_connection_status', array($this, 'show_connection_status'));


            parent::__construct();
        }

        public function show_connection_status()
        {

            $offset = get_option('gmt_offset') * HOUR_IN_SECONDS;
            $connection_status = apply_filters('ready2order_connection_status', '');
            $valid_to = Wr2o_Service_Handler::get_valid_to() + $offset;

            echo '<div>';

            if ($connection_status == 'unauthorized') {
                echo '<p>' . __('Enter an address to where the confirmation e-mail should be sent and give the plugin access to your ready2order account by pressing <b>Authorize</b>', 'wc-ready2order-integration') . '</p>';
                echo '<p>' . sprintf(__('When authorizing you agree to the BjornTech %s', 'wc-ready2order-integration'), sprintf('<a href="https://www.bjorntech.com/privacy-policy/?utm_source=wp-ready2order&utm_medium=plugin&utm_campaign=product" target="_blank" rel="noopener">%s</a>', __('privacy policy', 'wc-ready2order-integration'))) . '</p>';
            } elseif ($connection_status == 'expired') {
                echo '<p>' . sprintf(__('This plugin is authorized with ready2order but your subscription expired %s.', 'wc-ready2order-integration'), date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $valid_to)) . '</p>';
            } elseif ($connection_status == 'trial') {
                echo '<p>' . sprintf(__('<strong>Congratulations!</strong> This plugin is authorized with ready2order and your trial is valid until %s', 'wc-ready2order-integration'), date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $valid_to)) . '</p>';
            } else {
                echo '<p>' . sprintf(__('This plugin has been authorized with ready2order. Your BjornTech sync account is valid until %s', 'wc-ready2order-integration'), date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $valid_to)) . '</p>';
            }

            if ($connection_status == 'trial') {
                echo '<p>' . sprintf(__('To continue using the automatic sync when the trial has ended go to our <a href="%s">webshop</a> to purchase a subscription', 'wc-ready2order-integration'), 'https://bjorntech.com/product/integration-of-ready2order-with-woocommerce/?token=' . Wr2o_Service_Handler::get_organization_uuid() . '&utm_source=wp-ready2order&utm_medium=plugin&utm_campaign=product') . '</p>';
            }

            if ($connection_status != 'unauthorized') {
                $token = Wr2o_Service_Handler::get_organization_uuid();
                echo '<p>' . sprintf(__('When communicating with BjornTech always refer to this installation id: %s', 'wc-ready2order-integration'), $token) . '</p>';
            }

            echo '</div>';

        }


        /**
         * Handles the callback from the cloudservice when the customer did authorize the client
         *
         * @since 1.0.0
         */
        public function authorize_processing()
        {
            $uuid = get_option('ready2order_uuid');
            $nonce = wp_create_nonce('ready2order_connect_nonce');

            if (!get_option('bjorntech_ready2order_oauth_ready')) {
                update_option('wr2o_queue_admin_requests','yes');
                //update_option('wr2o_delete_products_with_filters','yes');
            }

            if(isset($_REQUEST['oauth'])){
                update_option('bjorntech_ready2order_oauth_ready',true);
            }

            if (isset($_REQUEST['nonce']) && $_REQUEST['nonce'] == $nonce && isset($_REQUEST['uuid']) && $_REQUEST['uuid'] == $uuid) {
                $refresh_token = sanitize_text_field($_REQUEST['refresh_token']);
                update_option('ready2order_refresh_token', $refresh_token);
                delete_site_transient('ready2order_valid_to');
                delete_site_transient('ready2order_access_token');

                try {
                    Wr2o_API::force_connection();
                    Wr2o_Notice::display(sprintf(__('<strong>Congratulations!</strong> Your plugin was successfully connected to ready2order.', 'wc-ready2order-integration')),'info',true);
                    Wr2o_Logger::add(sprintf('Succcessfully authorized, organization request token is %s', $refresh_token));
                } catch (Wr2o_API_Exception $e) {
                    $e->write_to_logs();
                }
            }
        }

        public function show_refresh_button()
        {
            echo '<tr valign="top">';
            echo '<th scope="row" class="titledesc">';
            echo '<label for="wr2o_refresh">' . __('Refresh connection', 'wc-ready2order-integration') . '<span class="woocommerce-help-tip" data-tip="' . __('Request new token from service', 'wc-ready2order-integration') . '"></span></label>';
            echo '</th>';
            echo '<td class="forminp forminp-button">';
            echo '<button name="wr2o_refresh" id="wr2o_refresh" class="button">' . __('Refresh', 'wc-ready2order-integration') . '</button>';
            echo '</td>';
            echo '</tr>';
        }

        public function show_products_export_button()
        {
            Wr2o_Helper::display_sync_button('woocommerce_products_export', __('Export products', 'wc-ready2order-integration'));
        }

        public function show_products_import_button()
        {
            Wr2o_Helper::display_sync_button('ready2order_products_import', __('Import products', 'wc-ready2order-integration'));
        }

        public function ready2order_connection_options_description()
        {
            require_once 'views/html-admin-settings-connection-options-desc.php';
        }

        public function ready2order_misc_options_description()
        {
            require_once 'views/html-admin-settings-all.php';
        }

        /**
         * Get sections.
         *
         * @since 1.0.0
         *
         * @param array $sections
         *
         * @return array
         */
        public function get_rto_sections($sections)
        {
            $sections[''] = __('General', 'wc-ready2order-integration');
            $sections['wr2o_products_import'] = __('Import products', 'wc-ready2order-integration');
            $sections['woocommerce_products_export'] = __('Export products', 'wc-ready2order-integration');
            $sections['woocommerce_to_wr2o_invoice'] = __('Order to Invoice', 'wc-ready2order-integration');
            $sections['wr2o_stocklevel'] = __('Stocklevel from order', 'wc-ready2order-integration');
            $sections['wr2o_field_mapping'] = __('Field mapping', 'wc-ready2order-integration');
            if (wc_tax_enabled()) {
                $sections['wr2o_tax_mapping'] = __('VAT mapping', 'wc-ready2order-integration');
            }
            $sections['advanced'] = __('Advanced', 'wc-ready2order-integration');

            return $sections;
        }

        private function get_valid_order_statuses()
        {
            $valid_order_statuses = array();
            foreach (wc_get_order_statuses() as $slug => $name) {
                $valid_order_statuses[str_replace('wc-', '', $slug)] = $name;
            }

            unset($valid_order_statuses['cancelled']);
            unset($valid_order_statuses['refunded']);
            unset($valid_order_statuses['failed']);

            return $valid_order_statuses;
        }

        /**
         * Builds the settings page. Settings pages are also present in the different handlers
         *
         * @since 1.0.0
         */
        public function get_rto_settings()
        {
            global $current_section;

            $settings = array();

            if ('woocommerce_products_export' == $current_section) {

                $pricelists = array(
                    '' => __('Regular price', 'woocommerce'),
                    '_sale' => __('Sale price if available - Regular if not', 'wc-ready2order-integration'),
                );

                $settings[] = array(
                    'title' => __('Export products to ready2order', 'wc-ready2order-integration'),
                    'type' => 'title',
                    'id' => 'wr2o_woocommerce_products_export',
                );

                $settings[] = array(
                    'title' => __('Automatic update', 'wc-ready2order-integration'),
                    'type' => 'checkbox',
                    'desc' => __('Create/Update products when changed in WooCommerce', 'wc-ready2order-integration'),
                    'default' => '',
                    'id' => 'wr2o_products_export_automatic_update',
                );
                
                $settings[] = array(
                    'title' => __('Export variable products', 'wc-ready2order-integration'),
                    'type' => 'checkbox',
                    'desc' => __('Export variant products to ready2order as simple products', 'wc-ready2order-integration'),
                    'default' => '',
                    'id' => 'wr2o_product_export_enable_variable_products',
                );

                $productgroups = array(
                    'not_found' => __('No product groups found', 'wc-ready2order-integration'),
                );

                foreach (apply_filters('wr2o_get_all_productgroups', array()) as $productgroup) {
                    $productgroups[$productgroup['productgroup_id']] = $productgroup['productgroup_name'];
                }

                $settings[] = array(
                    'title' => __('Default product group', 'wc-ready2order-integration'),
                    'type' => 'select',
                    'desc' => __('A default product group must be selected for products to sync ', 'wc-ready2order-integration'),
                    'default' => '',
                    'options' => $productgroups,
                    'id' => 'wr2o_products_export_default_product_group',
                );

                $settings[] = array(
                    'title' => __('Always use the default product group', 'wc-ready2order-integration'),
                    'type' => 'checkbox',
                    'desc' => __('Force the plugin to always use the default export group when exporting products to ready2order', 'wc-ready2order-integration'),
                    'default' => '',
                    'id' => 'wr2o_products_force_export_productgroup',
                );

                $settings[] = array(
                    'title' => __('Delete products', 'wc-ready2order-integration'),
                    'type' => 'checkbox',
                    'desc' => __('Delete products when deleted in WooCommerce', 'wc-ready2order-integration'),
                    'default' => '',
                    'id' => 'wr2o_delete_ready2order_products',
                );

                $settings[] = array(
                    'type' => 'sectionend',
                    'id' => 'wr2o_woocommerce_products_export',
                );

                $settings[] = array(
                    'title' => __('Data to export', 'wc-ready2order-integration'),
                    'type' => 'title',
                    'id' => 'wr2o_products_export_data',
                );

                $settings[] = [
                    'title' => __('Price', 'wc-ready2order-integration'),
                    'type' => 'select',
                    'desc' => __('Select the pricelist to be used when exporting price to ready2order', 'wc-ready2order-integration'),
                    'id' => 'wr2o_product_pricelist',
                    'default' => '',
                    'options' => $pricelists,
                ];

                $settings[] = array(
                    'title' => __('Category', 'wc-ready2order-integration'),
                    'type' => 'checkbox',
                    'desc' => __('Set the first category of a WooCommerce product as productgroup on the ready2order product. Please note that categories will always be set on the ready2order product when it is created by the plugin.', 'wc-ready2order-integration'),
                    'default' => '',
                    'id' => 'wr2o_products_export_productgroup',
                );

                $settings[] = array(
                    'title' => __('Update SKU', 'wc-ready2order-integration'),
                    'type' => 'checkbox',
                    'desc' => __('Update the item number/artikelnummer in ready2order with the SKU from WooCommerce', 'wc-ready2order-integration'),
                    'default' => '',
                    'id' => 'wr2o_products_export_sku',
                );

                $settings[] = array(
                    'title' => __('Stock', 'wc-ready2order-integration'),
                    'type' => 'checkbox',
                    'desc' => __('Overwrite the ready2order stockevel from the WooCommerce product stock value.', 'wc-ready2order-integration'),
                    'default' => '',
                    'id' => 'wr2o_products_overwrite_stocklevel_in_ready2order',
                );

                $settings[] = array(
                    'type' => 'sectionend',
                    'id' => 'wr2o_products_export_data',
                );

                $settings[] = [
                    'title' => __('Filter products to export', 'wc-ready2order-integration'),
                    'type' => 'title',
                    'desc' => __('Select the products to be exported by selecting in the filters below.', 'wc-ready2order-integration'),
                    'id' => 'wr2o_products_filter',
                ];

                $category_options = array();

                $product_categories = Wr2o_Helper::get_product_categories();

                if (!empty($product_categories)) {
                    foreach ($product_categories as $category) {
                        $category_options[$category->slug] = $category->name;
                    }
                }

                $settings[] = [
                    'title' => __('Product categories', 'wc-ready2order-integration'),
                    'type' => 'multiselect',
                    'class' => 'wc-enhanced-select',
                    'css' => 'width: 400px;',
                    'id' => 'wr2o_woocommerce_included_products_categories',
                    'default' => '',
                    'description' => __('If you only want to export products included in certain product categories, select them here. Leave blank to enable for all categories.', 'wc-ready2order-integration'),
                    'options' => $category_options,
                    'custom_attributes' => array(
                        'data-placeholder' => __('Select product categories or leave empty for all', 'wc-ready2order-integration'),
                    ),
                ];

                $settings[] = [
                    'title' => __('Product status', 'wc-ready2order-integration'),
                    'type' => 'multiselect',
                    'class' => 'wc-enhanced-select',
                    'css' => 'width: 400px;',
                    'id' => 'wr2o_woocommerce_included_products_statuses',
                    'default' => '',
                    'description' => __('If you only want to sync products with a certain product status, select them here. Leave blank to sync all regardless of status.', 'wc-ready2order-integration'),
                    'options' => get_post_statuses(),
                    'custom_attributes' => array(
                        'data-placeholder' => __('Select product statuses to include when exporting products. Leave blank for all', 'wc-ready2order-integration'),
                    ),
                ];

                $settings[] = [
                    'type' => 'sectionend',
                    'id' => 'wr2o_products_filter',
                ];

            } elseif ('wr2o_products_import' == $current_section) {

                $productgroups = array(
                    'not_found' => __('No product groups found', 'wc-ready2order-integration'),
                );

                foreach (apply_filters('wr2o_get_all_productgroups', array()) as $productgroup) {
                    $productgroups[$productgroup['productgroup_id']] = $productgroup['productgroup_name'];
                }

                $settings[] = array(
                    'title' => __('Import products from ready2order', 'wc-ready2order-integration'),
                    'type' => 'title',
                    'id' => 'wr2o_products_import',
                );

                $settings[] = array(
                    'title' => __('Automatic product import', 'wc-ready2order-integration'),
                    'type' => 'checkbox',
                    'desc' => __('Subscribe on product changes in ready2order and process automatically when received.', 'wc-ready2order-integration'),
                    'default' => '',
                    'id' => 'wr2o_products_import_automatically',
                );

                $settings[] = array(
                    'title' => __('Automatic product group import', 'wc-ready2order-integration'),
                    'type' => 'checkbox',
                    'desc' => __('Subscribe on product group changes in ready2order and process automatically when received.', 'wc-ready2order-integration'),
                    'default' => '',
                    'id' => 'wr2o_productgroup_import_automatically',
                );

                $settings[] = array(
                    'title' => __('Product actions', 'wc-ready2order-integration'),
                    'type' => 'select',
                    'desc' => __('Select what action should be performed on creation, update or deletetion of a ready2order product', 'wc-ready2order-integration'),
                    'default' => 'update',
                    'options' => array(
                        'update' => __('Update products in WooCommerce', 'wc-ready2order-integration'),
                        'create' => __('Create products in WooCommerce', 'wc-ready2order-integration'),
                        'create_update' => __('Create and update products in WooCommerce', 'wc-ready2order-integration'),
                        'create_update_delete' => __('Create, update and delete products in WooCommerce', 'wc-ready2order-integration'),
                    ),
                    'id' => 'wr2o_products_import_actions',
                );

                $settings[] = array(
                    'type' => 'sectionend',
                    'id' => 'wr2o_products_import',
                );

                $settings[] = array(
                    'title' => __('Data to import', 'wc-ready2order-integration'),
                    'type' => 'title',
                    'id' => 'wr2o__products_import_data',
                );

                $settings[] = array(
                    'title' => __('Product name', 'wc-ready2order-integration'),
                    'type' => 'checkbox',
                    'desc' => __('Import the ready2order product name to the WooCommerce product name.', 'wc-ready2order-integration'),
                    'default' => '',
                    'id' => 'wr2o_products_import_product_name',
                );

                $settings[] = array(
                    'title' => __('Stock', 'wc-ready2order-integration'),
                    'type' => 'checkbox',
                    'desc' => __('Overwrite the WooCommerce stockevel from the ready2order product stock value.', 'wc-ready2order-integration'),
                    'default' => '',
                    'id' => 'wr2o_products_overwrite_stocklevel_in_woocommerce',
                );

                $settings[] = array(
                    'title' => __('Product description', 'wc-ready2order-integration'),
                    'type' => 'checkbox',
                    'desc' => __('Import the ready2order product description to the description or short description field.', 'wc-ready2order-integration'),
                    'default' => '',
                    'id' => 'wr2o_products_import_product_description',
                );

                $settings[] = array(
                    'title' => __('Product price', 'wc-ready2order-integration'),
                    'type' => 'checkbox',
                    'desc' => __('Import the ready2order product price.', 'wc-ready2order-integration'),
                    'default' => '',
                    'id' => 'wr2o_products_import_product_price',
                );

                $settings[] = array(
                    'title' => __('SKU', 'wc-ready2order-integration'),
                    'type' => 'checkbox',
                    'desc' => __('Update the SKU in WooCommerce with the item number/artikelnummer from ready2order.', 'wc-ready2order-integration'),
                    'default' => '',
                    'id' => 'wr2o_products_import_sku',
                );

                $settings[] = array(
                    'title' => __('Product VAT', 'wc-ready2order-integration'),
                    'type' => 'checkbox',
                    'desc' => __('Import the ready2order product VAT and set tax-class based on settings.', 'wc-ready2order-integration'),
                    'default' => '',
                    'id' => 'wr2o_products_import_product_vat',
                );

                $settings[] = array(
                    'title' => __('Product group', 'wc-ready2order-integration'),
                    'type' => 'checkbox',
                    'desc' => __('Import the ready2order product group and set as category.', 'wc-ready2order-integration'),
                    'default' => '',
                    'id' => 'wr2o_products_import_productgroup',
                );

                $settings[] = array(
                    'type' => 'sectionend',
                    'id' => 'wr2o__products_import_data',
                );

                $settings[] = array(
                    'title' => __('Select the products to be imported in the filters below.', 'wc-ready2order-integration'),
                    'type' => 'title',
                    'id' => 'wr2o__products_import_filters',
                );

                $settings[] = [
                    'title' => __('Include product groups', 'wc-ready2order-integration'),
                    'type' => 'multiselect',
                    'class' => 'wc-enhanced-select',
                    'css' => 'width: 400px;',
                    'id' => 'wr2o_ready2order_included_product_groups',
                    'default' => '',
                    'description' => __('If you only want to import products included in certain product groups, select them here. Leave blank to enable for all product groups.', 'wc-ready2order-integration'),
                    'options' => $productgroups,
                    'custom_attributes' => array(
                        'data-placeholder' => __('Select product groups to import or leave empty for all', 'wc-ready2order-integration'),
                    ),
                ];

                $settings[] = [
                    'title' => __('Exclude product groups', 'wc-ready2order-integration'),
                    'type' => 'multiselect',
                    'class' => 'wc-enhanced-select',
                    'css' => 'width: 400px;',
                    'id' => 'wr2o_ready2order_excluded_product_groups',
                    'default' => '',
                    'description' => __('If you want to exclude importing products from certain product groups, select them here. Leave blank to not exclude any product groups.', 'wc-ready2order-integration'),
                    'options' => $productgroups,
                    'custom_attributes' => array(
                        'data-placeholder' => __('Select product groups to exclude or leave empty for all', 'wc-ready2order-integration'),
                    ),
                ];

                $settings[] = array(
                    'title' => __('Exclude products based on name', 'wc-ready2order-integration'),
                    'type' => 'text',
                    'desc' => __('Skip importing products that contain a certain string. Leave empty to not exclude any products. To use multiple strings - separate them using commas.', 'wc-ready2order-integration'),
                    'default' => '',
                    'id' => 'wr2o_ready2order_excluded_product_strings',
                );

                $settings[] = array(
                    'type' => 'sectionend',
                    'id' => 'wr2o__products_import_filters',
                );

            } elseif ('wr2o_stocklevel' == $current_section) {
                $settings[] = array(
                    'title' => __('Stocklevel handling', 'wc-ready2order-integration'),
                    'type' => 'title',
                    'id' => 'wr2o_stocklevel',
                );

                $settings[] = array(
                    'title' => __('Adjust stocklevel from orders', 'wc-ready2order-integration'),
                    'type' => 'checkbox',
                    'desc' => __('Adjust ready2order stocklevel when a WooCommerce order is changing stocklevel on a product', 'wc-ready2order-integration'),
                    'default' => '',
                    'id' => 'wr2o_products_export_adjust_stocklevel_from_order',
                );

                if ('yes' === get_option('wr2o_products_export_adjust_stocklevel_from_order')) {
                    $settings[] = array(
                    'title' => __('Change at statuses', 'wc-ready2order-integration'),
                    'type' => 'multiselect',
                    'class' => 'wc-enhanced-select',
                    'css' => 'width: 400px;',
                    'desc' => __('Select in what order statuses the order should update stocklevel in ready2order', 'wc-ready2order-integration'),
                    'default' => ['completed','processing'],
                    'options' => $this->get_valid_order_statuses(),
                    'custom_attributes' => array(
                        'data-placeholder' => __('Select order statuses.', 'wc-ready2order-integration'),
                    ),
                    'id' => 'wr2o_products_export_adjust_stocklevel_at_status',
                );
                }

                $settings[] = array(
                    'type' => 'sectionend',
                    'id' => 'wr2o_stocklevel',
                );
            } elseif ('wr2o_field_mapping' == $current_section) {
                $settings[] = array(
                    'title' => __('Field mapping between ready2order and WooCommerce', 'wc-ready2order-integration'),
                    'type' => 'title',
                    'id' => 'wr2o_field_mapping',
                );

                $settings[] = array(
                    'title' => __('Product description', 'wc-ready2order-integration'),
                    'type' => 'select',
                    'desc' => __('Map the ready2order product description to the description or short description field.', 'wc-ready2order-integration'),
                    'id' => 'wr2o_products_mapping_product_description',
                    'default' => 'description',
                    'options' => array(
                        'description' => __('WooCommerce product description', 'wc-ready2order-integration'),
                        'short_description' => __('WooCommerce product short description', 'wc-ready2order-integration'),
                    ),
                );

                $settings[] = array(
                    'type' => 'sectionend',
                    'id' => 'wr2o_field_mapping',
                );
            } elseif ('wr2o_tax_mapping' == $current_section) {
                $settings[] = array(
                    'title' => __('VAT mapping ready2order and WooCommerce', 'wc-ready2order-integration'),
                    'type' => 'title',
                    'id' => 'wr2o_tax_mapping',
                );

                $options = array(
                    '' => __('Standard', 'woocommerce'),
                );

                if (wc_tax_enabled()) {
                    $tax_classes = WC_Tax::get_tax_classes();

                    if (!empty($tax_classes)) {
                        foreach ($tax_classes as $class) {
                            $options[sanitize_title($class)] = esc_html($class);
                        }
                    }

                    if (count($options) > 1) {
                        foreach ($options as $key => $option) {
                            $settings[] = [
                                'title' => $option,
                                'type' => 'number',
                                'default' => '',
                                'desc' => sprintf(__('Enter the tax rate in ready2order that corresponds to %s in WooCommerce', 'wc-ready2order-integration'), $option),
                                'id' => 'wr2o_tax_mapping_' . $key,
                            ];
                        };
                    }
                }

                $settings[] = array(
                    'type' => 'sectionend',
                    'id' => 'wr2o_tax_mapping',
                );
            } elseif ($current_section == 'woocommerce_to_wr2o_invoice') {
                $settings[] = array(
                    'title' => __('Order to ready2order invoice (BETA)', 'wc-ready2order-integration'),
                    'type' => 'title',
                    'id' => 'wr2o_invoice_export',
                );

                $settings[] = array(
                    'title' => __('Enable WooCommerce to ready2order invoice processing', 'wc-ready2order-integration'),
                    'type' => 'checkbox',
                    'desc' => __('Enable the possibility to generate invoices in ready2order based on WooCommerce orders', 'wc-ready2order-integration'),
                    'default' => '',
                    'id' => 'wr2o_enable_invoice_export',
                );

                if (get_option('wr2o_enable_invoice_export') == 'yes'){

                    $users = array(
                        'not_found' => __('No users found', 'wc-ready2order-integration'),
                    );
    
                    foreach (apply_filters('wr2o_get_all_users', array()) as $user) {
                        $users[$user['user_id']] = $user['user_username'];
                    }

                    $billtypes = array(
                        'not_found' => __('No bill types found', 'wc-ready2order-integration'),
                    );
    
                    foreach (apply_filters('wr2o_get_all_billtypes', array()) as $billtype) {
                        $billtypes[$billtype['billType_id']] = $billtype['billType_name'];
                    }

                    $paymentmethods = array(
                        'not_found' => __('No payment methods found', 'wc-ready2order-integration'),
                    );
    
                    foreach (apply_filters('wr2o_get_all_paymentmethods', array()) as $paymentmethod) {
                        $paymentmethods[$paymentmethod['payment_id']] = $paymentmethod['payment_name'];
                    }

                    $settings[] = [
                        'title' => __('Create invoice on order status', 'wc-ready2order-integration'),
                        'type' => 'select',
                        'default' => reset($this->get_valid_order_statuses()),
                        'desc' => __('Select on what order status the ready2order invoice will be created.', 'wc-ready2order-integration'),
                        'options' => $this->get_valid_order_statuses(),
                        'id' => 'wr2o_woo_order_create_automatic_from',
                    ];

                    $settings[] = array(
                        'title' => __('Bill Type', 'wc-ready2order-integration'),
                        'type' => 'select',
                        'desc' => __('The bill type to use when creating a ready2order invoice from a WooCommerce order', 'wc-ready2order-integration'),
                        'default' => '',
                        'options' => $billtypes,
                        'id' => 'wr2o_invoice_billType_id',
                    );

                    $settings[] = array(
                        'title' => __('Default user', 'wc-ready2order-integration'),
                        'type' => 'select',
                        'desc' => __('The ready2order user that will associated with the ready2order invoice created by the integration', 'wc-ready2order-integration'),
                        'default' => '',
                        'options' => $users,
                        'id' => 'wr2o_invoice_user_id',
                    );

                    $settings[] = array(
                        'title' => __('Payment method', 'wc-ready2order-integration'),
                        'type' => 'select',
                        'desc' => __('The ready2order payment method that will be used on the invoices in ready2order', 'wc-ready2order-integration'),
                        'default' => '',
                        'options' => $paymentmethods,
                        'id' => 'wr2o_invoice_paymentmethod_id',
                    );

                    $settings[] = array(
                        'title' => __('Include unpaid orders', 'wc-ready2order-integration'),
                        'type' => 'checkbox',
                        'desc' => __('Create ready2orders from unpaid WooCommerce orders.', 'wc-ready2order-integration'),
                        'default' => '',
                        'id' => 'wr2o_invoice_include_unpaid_order',
                    );

                    $settings[] = array(
                        'title' => __('Automatically trigger day to open if closed', 'wc-ready2order-integration'),
                        'type' => 'checkbox',
                        'desc' => __('Will automatically open the daily report if closed when creating invoices', 'wc-ready2order-integration'),
                        'default' => '',
                        'id' => 'wr2o_invoice_open_day',
                    );
    
                    $settings[] = array(
                        'title' => __('Handle shipping items in invoice', 'wc-ready2order-integration'),
                        'type' => 'checkbox',
                        'desc' => __('Adds the possibility of including shipping in ready2order invoices that are exported', 'wc-ready2order-integration'),
                        'default' => '',
                        'id' => 'wr2o_enable_invoice_export_shipping',
                    );
    
                    if (get_option('wr2o_enable_invoice_export_shipping') == 'yes'){
    
                        $zones = Wr2o_Helper::get_shipping_zones();
    
                        $products = array(
                            'not_found' => __('No products found', 'wc-ready2order-integration'),
                        );
        
                        foreach (apply_filters('wr2o_get_all_products', array()) as $product) {
                            $products[$product['product_id']] = $product['product_name'];
                        }
    
                        foreach ($zones as $zone) {
                            if(is_object($zone)){
                                $shipping_methods = $zone->get_shipping_methods();
                                $zone_formatted_name = $zone->get_formatted_location();
                             } else {
                                $shipping_methods = $zone['shipping_methods'];
                                $zone_formatted_name = $zone['formatted_zone_location'];
                             }
    
                             foreach ($shipping_methods as $shipping_method) {
                                $description = $shipping_method->get_title();
                                $method_id = $shipping_method->id;
                                $instance_id = $shipping_method->get_instance_id();
        
                                $settings[] = [
                                    'title' => sprintf(__('Ready2order shipping product for %s in Shipping Zone %s', 'wc-ready2order-integration'),$description, $zone_formatted_name),
                                    'type' => 'select',
                                    'default' => '',
                                    'options' => $products,
                                    'id' => 'wr2o_invoice_export_shipping_product_' . $method_id . '_' . $instance_id,
                                ];
        
                            }
                        }
    
                    }
                }

                $settings[] = array(
                    'type' => 'sectionend',
                    'id' => 'wr2o_invoice_export',
                );      

            } elseif ('advanced' == $current_section) {
                $settings[] = array(
                    'title' => __('Advanced', 'wc-ready2order-integration'),
                    'type' => 'title',
                    'id' => 'wr2o_advanced_settings',
                );

                $settings[] = array(
                    'title' => __('Queue webhook calls', 'wc-ready2order-integration'),
                    'type' => 'checkbox',
                    'desc' => __('Queue webhook calls from ready2order, useful for performance reasons.', 'wc-ready2order-integration'),
                    'default' => '',
                    'id' => 'wr2o_queue_webhook_calls',
                );

                $settings[] = array(
                    'title' => __('Queue admin requests', 'wc-ready2order-integration'),
                    'type' => 'checkbox',
                    'desc' => __('Queue calls to ready2order also from admin users, useful for performance reasons.', 'wc-ready2order-integration'),
                    'default' => '',
                    'id' =>  'wr2o_queue_admin_requests',
                );

                $settings[] = [
                    'title' => __('CRON disabled on server', 'wc-ready2order-integration'),
                    'type' => 'checkbox',
                    'desc' => __('If your server has CRON-jobs disabled you must check this box in order for the plugin to work', 'wc-ready2order-integration'),
                    'default' => '',
                    'id' => 'wr2o_manual_cron',
                ];


                $settings[] = [
                    'title' => __('Use advanced WooCommerce hooks for update', 'wc-ready2order-integration'),
                    'type' => 'checkbox',
                    'desc' => __('Listen to more advanced WooCommerce hooks when listening for product updates in WooCommerce - will lead to longer update times', 'wc-ready2order-integration'),
                    'default' => '',
                    'id' => 'wr2o_products_export_use_aggresive_hooks',
                ];
                

                $wpml_active_languages = apply_filters('wpml_active_languages', false);

                if ($wpml_active_languages) {

                    $language_selection = array(
                        '' => __('Sync all products in all languages', 'wc-ready2order-integration'),
                    );

                    foreach ($wpml_active_languages as $wpml_active_language) {
                        $language_selection[$wpml_active_language['language_code']] = $wpml_active_language['native_name'];
                    }

                    $settings[] = [
                        'title' => __('Sync WPML/Polylang language', 'wc-ready2order-integration'),
                        'type' => 'select',
                        'desc' => __('Select the language to use for ready2order synchronization. If all products are synced duplicates will be created in ready2order.', 'wc-ready2order-integration'),
                        'id' => 'wr2o_wpml_default_language',
                        'default' => apply_filters('wpml_default_language', ''),
                        'options' => $language_selection,
                    ];
                }

                $settings[] = [
                    'title' => __('Additional variant identifier', 'wc-ready2order-integration'),
                    'type' => 'checkbox',
                    'desc' => __('Use additional identifiers for variants when exporting to ready2order', 'wc-ready2order-integration'),
                    'id' => 'wr2o_products_additional_variant_identifier',
                    'default' => '',
                ];

                $settings[] = array(
                    'title' => __('(BETA) Export variable products - ready2order variants', 'wc-ready2order-integration'),
                    'type' => 'checkbox',
                    'desc' => __('Export variable products to ready2order variable products - remember to enable product variants in ready2order for this to work.', 'wc-ready2order-integration'),
                    'default' => '',
                    'id' => 'wr2o_product_export_enable_variable_products_v2',
                );

                $settings[] = array(
                    'title' => __('Ignore product group hierarchy during import', 'wc-ready2order-integration'),
                    'type' => 'checkbox',
                    'desc' => __('All imported product groups will be created as root categories in WooCommerce.', 'wc-ready2order-integration'),
                    'default' => '',
                    'id' => 'wr2o_productgroup_no_parents',
                );

                //wr2o_always_set_invoice_tax_rate_to_zero
                $settings[] = array(
                    'title' => __('Always set invoice tax rate to zero', 'wc-ready2order-integration'),
                    'type' => 'checkbox',
                    'desc' => __('Set the tax rate to zero on all ready2order invoices created by the plugin.', 'wc-ready2order-integration'),
                    'default' => '',
                    'id' => 'wr2o_always_set_invoice_tax_rate_to_zero',
                );

                //wr2o_delete_products_with_filters
                $settings[] = array(
                    'title' => __('Delete products with filters', 'wc-ready2order-integration'),
                    'type' => 'checkbox',
                    'desc' => __('Delete products in WooCommerce that do not pass through the input filters.', 'wc-ready2order-integration'),
                    'default' => '',
                    'id' => 'wr2o_delete_products_with_filters',
                );

                $settings[] = array(
                    'title' => __('Import batch size', 'wc-ready2order-integration'),
                    'type' => 'number',
                    'default' => 250,
                    'desc' => __('Batch size for manual imports of ready2order items.', 'wc-ready2order-integration'),
                    'id' => 'wr2o_import_batch_size',
                );

                $settings[] = array(
                    'title' => __('Alternate webhook url', 'wc-ready2order-integration'),
                    'type' => 'text',
                    'desc' => __('An alternate url to where the callbacks from the ready to order service are to be sent. Useful if testing locally and using ngrok or similar service.', 'wc-ready2order-integration'),
                    'default' => '',
                    'id' => 'bjorntech_alternate_webhook_url',
                );

                $settings[] = array(
                    'title' => __('Alternate service url', 'wc-ready2order-integration'),
                    'type' => 'text',
                    'desc' => __('An alternate url to the backend service. Do NOT change without instruction from BjornTech', 'wc-ready2order-integration'),
                    'default' => '',
                    'id' => 'wr2o_alternate_service_url',
                );

                $settings[] = array(
                    'title' => __('Refresh token', 'wc-ready2order-integration'),
                    'type' => 'text',
                    'desc' => __('Refresh token for the service. Do NOT change without instruction from BjornTech', 'wc-ready2order-integration'),
                    'default' => '',
                    'id' => 'ready2order_refresh_token',
                );

                $settings[] = array(
                    'type' => 'sectionend',
                    'id' => 'wr2o_advanced_settings',
                );
            } else {
                $settings[] = array(
                    'title' => __('General settings', 'wc-ready2order-integration'),
                    'type' => 'title',
                    'id' => 'wr2o_general_settings',
                );

                $settings[] = array(
                    'title' => __('User e-mail', 'wc-ready2order-integration'),
                    'type' => 'email',
                    'desc' => __('The user mail to where we send the confirmation e-mail and other information.', 'wc-ready2order-integration'),
                    'default' => '',
                    'id' => 'wr2o_user_email',
                );

                $settings[] = array(
                    'title' => __('Enable logging', 'wc-ready2order-integration'),
                    'type' => 'checkbox',
                    'desc' => sprintf(__('Logging is useful when troubleshooting. You can find the logs <a href="%s">here</a>', 'wc-ready2order-integration'), Wr2o_Logger::get_admin_link()),
                    'default' => '',
                    'id' => 'wr2o_logging_enabled',
                );

                $settings[] = array(
                    'type' => 'sectionend',
                    'id' => 'wr2o_general_settings',
                );
            }

            return $settings;
        }

        public function show_connection_button()
        {
            echo '<tr valign="top">';
            echo '<th scope="row" class="titledesc">';
            echo '<label for="wr2o_authorize">' . __('Authorize with ready2order', 'wc-ready2order-integration') . '<span class="woocommerce-help-tip" data-tip="Authorize the plugin to get and write products/stock and to read purchases"></span></label>';
            echo '</th>';
            echo '<td class="forminp forminp-button">';
            echo '<button name="wr2o_authorize" id="wr2o_authorize" class="button">' . __('Authorize', 'wc-ready2order-integration') . '</button>';
            echo '</td>';
            echo '</tr>';
        }
    }

    return new WC_Settings_Page_WRTO();
}

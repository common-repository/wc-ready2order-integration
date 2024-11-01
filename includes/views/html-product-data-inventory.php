<?php
/**
 * Adds ready2order specific fields.
 *
 * @package WooCommerce\Admin
 */

if (!defined('ABSPATH')) {
    exit;
}
echo '<div id="ready2order_product_data" class="panel woocommerce_options_panel">';

woocommerce_wp_checkbox(
    array(
        'id' => "_ready2order_nosync",
        'value' => $product_object->get_meta('_ready2order_nosync', true),
        'label' => '<abbr title="' . esc_attr__('Exclude from ready2order', 'wc-ready2order-integration') . '">' . esc_html__('Exclude from ready2order', 'wc-ready2order-integration') . '</abbr>',
        'desc_tip' => true,
        'description' => __('Check if you do not want this product to be synced to ready2order', 'wc-ready2order-integration'),
    )
);

echo '</div>';
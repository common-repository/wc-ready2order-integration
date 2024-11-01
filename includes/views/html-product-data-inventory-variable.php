<?php
/**
 * Adds a barcode-field.
 *
 * @package WooCommerce\Admin
 */

if (!defined('ABSPATH')) {
    exit;
}

echo '<div>';
echo '<p>';
woocommerce_wp_checkbox(
    array(
        'id' => "_ready2order_nosync_{$loop}",
        'value' => get_post_meta($variation->ID, '_ready2order_nosync', true),
        'label' => '<abbr title="' . esc_attr__('Exclude from ready2order', 'wc-ready2order-integration') . '">' . esc_html__('Exclude from ready2order', 'wc-ready2order-integration') . '</abbr>',
        'desc_tip' => true,
        'description' => __('Check if you do not want this product to be synced to ready2order', 'wc-ready2order-integration'),
    )
);

echo '</p>';
echo '</div>';
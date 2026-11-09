<?php
/**
 * Plugin Name:       Mautic Ecommerce for WooCommerce
 * Plugin URI:        https://github.com/jos0405/mautic-ecommerce-woocommerce
 * Description:       Sends orders, refunds and the product catalog to Mautic's Ecommerce Connector.
 * Version:           0.5.1
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * WC requires at least: 8.0
 * Author:            Friendly
 * License:           GPL-3.0-or-later
 * Text Domain:       mautic-ecommerce-woocommerce
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

const MAUTIC_ECOMMERCE_WC_VERSION = '0.5.1';
const MAUTIC_ECOMMERCE_WC_FILE    = __FILE__;

spl_autoload_register(static function (string $class): void {
    $prefix = 'MauticEcommerceWooCommerce\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__.'/src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

// Compatible with WooCommerce's order tables (HPOS).
add_action('before_woocommerce_init', static function (): void {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

// The weekly sync is scheduled again from the admin when the plugin is active.
register_deactivation_hook(__FILE__, [MauticEcommerceWooCommerce\WeeklySync::class, 'unschedule']);

add_action('plugins_loaded', static function (): void {
    if (class_exists('WooCommerce')) {
        (new MauticEcommerceWooCommerce\Plugin())->register();
    }
});

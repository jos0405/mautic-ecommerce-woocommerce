<?php

declare(strict_types=1);

namespace MauticEcommerceWooCommerce;

/**
 * Wires the plugin into WordPress and WooCommerce.
 */
final class Plugin
{
    public function register(): void
    {
        $settings = new Settings();
        $client   = new Client($settings);
        $queue    = new Queue($client);
        $products = new ProductSync($settings, $queue);
        $orders   = new OrderSync($settings, $queue);

        $settings->register();
        $queue->register();
        $products->register();
        $orders->register();

        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('mautic-ecommerce', new Cli($settings, $client, $products, $orders));
        }
    }
}

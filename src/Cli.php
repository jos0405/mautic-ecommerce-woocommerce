<?php

declare(strict_types=1);

namespace MauticEcommerceWooCommerce;

/**
 * Sync the catalog and past orders to Mautic.
 */
final class Cli
{
    public function __construct(
        private Settings $settings,
        private Client $client,
        private ProductSync $products,
        private OrderSync $orders,
    ) {
    }

    /**
     * Shows the connection settings.
     *
     * @subcommand status
     */
    public function status(): void
    {
        \WP_CLI::line('Mautic URL:     '.($this->settings->getMauticUrl() ?: '(not set)'));
        \WP_CLI::line('Webhook secret: '.('' !== $this->settings->getSecret() ? 'set' : '(not set)'));
        \WP_CLI::line('Store name:     '.$this->settings->getStore());
        \WP_CLI::line('Orders:         '.($this->settings->isEnabled('orders') ? 'on' : 'off'));
        \WP_CLI::line('Products:       '.($this->settings->isEnabled('products') ? 'on' : 'off'));
    }

    /**
     * Sends all products, or all paid, refunded and cancelled orders, to Mautic now.
     *
     * ## OPTIONS
     *
     * <what>
     * : products or orders
     *
     * [--since=<date>]
     * : For orders: only orders created on or after this date (YYYY-MM-DD).
     *
     * ## EXAMPLES
     *
     *     wp mautic-ecommerce sync products
     *     wp mautic-ecommerce sync orders --since=2025-01-01
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function sync(array $args, array $assoc): void
    {
        if (!$this->settings->isConfigured()) {
            \WP_CLI::error('Set the Mautic URL and webhook secret first (WooCommerce > Settings > Mautic).');
        }

        $progress = static function (int $count): void {
            \WP_CLI::log(sprintf('  %d sent', $count));
        };

        try {
            $count = match ($args[0] ?? '') {
                'products' => $this->products->syncAll($this->client, $progress),
                'orders'   => $this->orders->syncAll($this->client, $assoc['since'] ?? null, $progress),
                default    => \WP_CLI::error('Use "products" or "orders".'),
            };
        } catch (\RuntimeException $exception) {
            \WP_CLI::error($exception->getMessage());
        }

        \WP_CLI::success(sprintf('%d %s sent. Mautic logs any rejected ones.', $count, $args[0]));
    }
}

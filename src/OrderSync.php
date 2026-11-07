<?php

declare(strict_types=1);

namespace MauticEcommerceWooCommerce;

/**
 * Sends orders when they are paid, and again when they are refunded (partly or fully) or
 * cancelled. Mautic records the first and updates status and refunds from the later ones.
 */
final class OrderSync
{
    /**
     * WooCommerce statuses Mautic hears about. Unpaid orders (pending, on-hold, failed) are not
     * sent until they are paid.
     */
    public const STATUSES = [
        'processing' => 'paid',
        'completed'  => 'paid',
        'refunded'   => 'refunded',
        'cancelled'  => 'cancelled',
    ];

    public function __construct(
        private Settings $settings,
        private Queue $queue,
    ) {
    }

    public function register(): void
    {
        add_filter('mautic_ecommerce_build_order', [$this, 'buildJob'], 10, 2);

        if (!$this->settings->isEnabled('orders')) {
            return;
        }

        add_action('woocommerce_order_status_changed', [$this, 'onStatusChanged'], 10, 3);
        // Partial refunds do not change the status.
        add_action('woocommerce_order_refunded', [$this, 'queueOrder']);
    }

    public function onStatusChanged(int $orderId, string $from, string $to): void
    {
        if (isset(self::STATUSES[$to])) {
            $this->queueOrder($orderId);
        }
    }

    public function queueOrder(int $orderId): void
    {
        $this->queue->push('order', ['id' => $orderId]);
    }

    /**
     * @param array<string, scalar> $args
     *
     * @return array<string, mixed>|null
     */
    public function buildJob(mixed $payload, array $args): ?array
    {
        $order = wc_get_order((int) ($args['id'] ?? 0));

        return $order instanceof \WC_Order && !$order instanceof \WC_Order_Refund
            ? $this->build($order)
            : null;
    }

    /**
     * @return array<string, mixed>|null the order in the Ecommerce Connector's standard format
     */
    public function build(\WC_Order $order): ?array
    {
        $status = self::STATUSES[$order->get_status()] ?? null;
        if (null === $status) {
            return null;
        }

        $placed = $order->get_date_paid() ?? $order->get_date_created();
        $items  = [];

        foreach ($order->get_items() as $item) {
            if (!$item instanceof \WC_Order_Item_Product) {
                continue;
            }

            $product     = $item->get_product();
            $sku         = $product instanceof \WC_Product ? $product->get_sku() : '';
            $productId   = $item->get_product_id();
            $variationId = $item->get_variation_id();

            $items[] = [
                // SKU first, matching the catalog and native webhooks; else variation or product ID.
                'item_id'      => '' !== $sku ? $sku : (string) ($variationId ?: $productId),
                'product_id'   => $productId > 0 ? (string) $productId : null,
                'item_name'    => html_entity_decode(wp_strip_all_tags($item->get_name()), ENT_QUOTES),
                'qty'          => (int) $item->get_quantity(),
                // Per unit before discounts, including tax.
                'unit_price'   => (string) $order->get_item_subtotal($item, true, false),
                'category'     => $productId > 0 ? ProductSync::mainCategory($productId) : null,
                'refunded_qty' => abs((int) $order->get_qty_refunded_for_item($item->get_id())),
            ];
        }

        $refunded = (float) $order->get_total_refunded();

        return [
            '_endpoint'      => 'order',
            'order_id'       => (string) $order->get_order_number(),
            'order_total'    => (string) $order->get_total(),
            'order_currency' => $order->get_currency(),
            'order_source'   => $this->settings->getStore(),
            'order_date'     => $placed ? $placed->format(DATE_ATOM) : null,
            'status'         => $status,
            'refunded_total' => $refunded > 0 ? (string) $refunded : null,
            'email'          => $order->get_billing_email(),
            'location'       => $order->get_shipping_city() ?: null,
            'items'          => $items,
        ];
    }

    /**
     * Sends paid, refunded and cancelled orders, oldest first. Mautic never triggers campaigns
     * for orders placed more than a day before they arrive, so this sends no emails.
     *
     * @param callable(int): void $progress
     */
    public function syncAll(Client $client, ?string $since, callable $progress): int
    {
        $sent = 0;
        $page = 1;

        do {
            $query = [
                'status'  => array_map(static fn (string $status): string => 'wc-'.$status, array_keys(self::STATUSES)),
                'type'    => 'shop_order',
                'limit'   => 100,
                'page'    => $page++,
                'orderby' => 'date',
                'order'   => 'ASC',
            ];
            if (null !== $since) {
                $query['date_created'] = '>='.$since;
            }

            $orders = wc_get_orders($query);
            foreach ($orders as $order) {
                $payload = $order instanceof \WC_Order ? $this->build($order) : null;
                if (null !== $payload) {
                    $client->send('order', array_diff_key($payload, ['_endpoint' => true]));
                    $progress(++$sent);
                }
            }
        } while (100 === count($orders));

        return $sent;
    }
}

<?php

declare(strict_types=1);

namespace MauticEcommerceWooCommerce\Tests\Integration;

use MauticEcommerceWooCommerce\Client;
use MauticEcommerceWooCommerce\OrderSync;
use MauticEcommerceWooCommerce\Queue;
use MauticEcommerceWooCommerce\Settings;

final class OrderSyncTest extends TestCase
{
    private OrderSync $orders;

    private Client $client;

    public function set_up(): void
    {
        parent::set_up();

        $settings     = new Settings();
        $this->client = new Client($settings);
        $this->orders = new OrderSync($settings, new Queue($this->client));
    }

    public function test_paid_order_in_the_standard_format(): void
    {
        $candle = $this->createProduct('Lavender Candle', '12.50', 'CND-1', 'Candles');
        $soap   = $this->createProduct('Soap', '4.00', '', 'Bath');
        $order  = $this->createOrder([[$candle, 2], [$soap, 1]]);

        $payload = $this->orders->build($order);

        $this->assertNotNull($payload);
        $this->assertSame('order', $payload['_endpoint']);
        $this->assertSame((string) $order->get_order_number(), $payload['order_id']);
        $this->assertSame('29.00', $payload['order_total']);
        $this->assertSame('CHF', $payload['order_currency']);
        $this->assertSame('teststore', $payload['order_source']);
        $this->assertSame('paid', $payload['status']);
        $this->assertNull($payload['refunded_total']);
        $this->assertSame('jane@example.com', $payload['email']);
        $this->assertSame('Basel', $payload['location']);

        $this->assertSame(
            [
                ['item_id' => 'CND-1', 'product_id' => (string) $candle->get_id(), 'item_name' => 'Lavender Candle', 'qty' => 2, 'unit_price' => '12.5', 'category' => 'Candles', 'refunded_qty' => 0],
                // No SKU: the product ID.
                ['item_id' => (string) $soap->get_id(), 'product_id' => (string) $soap->get_id(), 'item_name' => 'Soap', 'qty' => 1, 'unit_price' => '4', 'category' => 'Bath', 'refunded_qty' => 0],
            ],
            $payload['items']
        );
    }

    public function test_unpaid_orders_are_not_sent(): void
    {
        $order = $this->createOrder([[$this->createProduct('Candle', '10'), 1]], 'pending');

        $this->assertNull($this->orders->build($order));
    }

    public function test_partial_refund_sends_refunded_total_and_quantity(): void
    {
        $candle = $this->createProduct('Candle', '10', 'CND-1');
        $order  = $this->createOrder([[$candle, 3]], 'completed');
        $itemId = array_key_first($order->get_items());

        wc_create_refund([
            'order_id'   => $order->get_id(),
            'amount'     => 10,
            'line_items' => [$itemId => ['qty' => 1, 'refund_total' => 10]],
        ]);

        $payload = $this->orders->build(wc_get_order($order->get_id()));

        $this->assertSame('paid', $payload['status']);
        $this->assertSame('10', $payload['refunded_total']);
        $this->assertSame(1, $payload['items'][0]['refunded_qty']);
    }

    public function test_cancelled_order_is_sent_as_cancelled(): void
    {
        $order = $this->createOrder([[$this->createProduct('Candle', '10'), 1]]);
        $order->update_status('cancelled');

        $this->assertSame('cancelled', $this->orders->build(wc_get_order($order->get_id()))['status']);
    }

    public function test_sync_page_sends_paid_orders_from_the_given_day(): void
    {
        $product = $this->createProduct('Candle', '10');
        $old     = $this->createOrder([[$product, 1]]);
        $old->set_date_created('2024-01-15 10:00:00');
        $old->save();
        $this->createOrder([[$product, 1]]);
        $this->createOrder([[$product, 1]], 'pending');

        $batch = $this->orders->syncPage($this->client, gmdate('Y-m-d', strtotime('-1 day')), 1);

        $this->assertSame(['fetched' => 1, 'sent' => 1], $batch);
        $this->assertCount(1, $this->requestsTo('/ecommerce/order'));
        $this->assertArrayNotHasKey('_endpoint', $this->requestsTo('/ecommerce/order')[0]['json']);
    }
}

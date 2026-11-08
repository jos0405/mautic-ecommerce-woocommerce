<?php

declare(strict_types=1);

namespace MauticEcommerceWooCommerce\Tests\Integration;

use MauticEcommerceWooCommerce\BulkSync;
use MauticEcommerceWooCommerce\Client;
use MauticEcommerceWooCommerce\OrderSync;
use MauticEcommerceWooCommerce\ProductSync;
use MauticEcommerceWooCommerce\Queue;
use MauticEcommerceWooCommerce\Settings;

final class BulkSyncTest extends TestCase
{
    private BulkSync $bulkSync;

    public function set_up(): void
    {
        parent::set_up();

        $settings       = new Settings();
        $client         = new Client($settings);
        $queue          = new Queue($client);
        $this->bulkSync = new BulkSync($client, new ProductSync($settings, $queue), new OrderSync($settings, $queue));
        delete_option(BulkSync::OPTION);
    }

    public function test_products_are_sent_page_by_page_in_the_background(): void
    {
        for ($i = 1; $i <= ProductSync::BATCH_SIZE + 1; ++$i) {
            $this->createProduct('Product '.$i, '10', 'SKU-'.$i);
        }
        $this->requests = [];

        $this->assertTrue($this->bulkSync->start('products'));
        $this->assertTrue(as_has_scheduled_action(BulkSync::HOOK, ['products'], Queue::GROUP));

        $this->runBatches('products');

        $requests = $this->requestsTo('/ecommerce/product');
        $this->assertCount(2, $requests);
        $this->assertCount(ProductSync::BATCH_SIZE, $requests[0]['json']['products']);
        $this->assertSame('teststore', $requests[0]['json']['store']);
        $this->assertCount(1, $requests[1]['json']['products']);

        $state = $this->bulkSync->state('products');
        $this->assertSame(BulkSync::DONE, $state['status']);
        $this->assertSame(ProductSync::BATCH_SIZE + 1, $state['sent']);
    }

    public function test_a_failed_sync_keeps_its_place_and_resumes(): void
    {
        $product = $this->createProduct('Candle', '10');
        $this->createOrder([[$product, 1]]);
        $this->createOrder([[$product, 1]]);
        $this->responses['/ecommerce/order'] = ['code' => 503];

        $this->bulkSync->start('orders', '2020-01-01');
        $this->runBatches('orders');

        $state = $this->bulkSync->state('orders');
        $this->assertSame(BulkSync::FAILED, $state['status']);
        $this->assertStringContainsString('HTTP 503', $state['error']);

        unset($this->responses['/ecommerce/order']);
        $this->requests = [];

        $this->assertTrue($this->bulkSync->start('orders', '2020-01-01'));
        $this->runBatches('orders');

        $this->assertSame(BulkSync::DONE, $this->bulkSync->state('orders')['status']);
        $this->assertSame(2, $this->bulkSync->state('orders')['sent']);
        $this->assertCount(2, $this->requestsTo('/ecommerce/order'));
    }

    public function test_a_running_sync_is_not_started_twice_unless_stalled(): void
    {
        $this->assertTrue($this->bulkSync->start('products'));
        $this->assertFalse($this->bulkSync->start('products'));

        $state = get_option(BulkSync::OPTION);
        $state['products']['updated_at'] = time() - BulkSync::STALE_AFTER - 1;
        update_option(BulkSync::OPTION, $state);

        $this->assertTrue($this->bulkSync->start('products'));
    }

    public function test_unknown_type_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->bulkSync->start('customers');
    }

    /**
     * Stands in for Action Scheduler's runner: runs batches until the sync stops.
     */
    private function runBatches(string $type): void
    {
        for ($i = 0; $i < 10 && BulkSync::RUNNING === ($this->bulkSync->state($type)['status'] ?? null); ++$i) {
            $this->bulkSync->runBatch($type);
        }
    }
}

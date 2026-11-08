<?php

declare(strict_types=1);

namespace MauticEcommerceWooCommerce\Tests\Integration;

use MauticEcommerceWooCommerce\BulkSync;
use MauticEcommerceWooCommerce\Client;
use MauticEcommerceWooCommerce\OrderSync;
use MauticEcommerceWooCommerce\ProductSync;
use MauticEcommerceWooCommerce\Queue;
use MauticEcommerceWooCommerce\Settings;
use MauticEcommerceWooCommerce\WeeklySync;

final class WeeklySyncTest extends TestCase
{
    private BulkSync $bulkSync;

    private WeeklySync $weekly;

    public function set_up(): void
    {
        parent::set_up();

        $settings       = new Settings();
        $client         = new Client($settings);
        $queue          = new Queue($client);
        $this->bulkSync = new BulkSync($client, new ProductSync($settings, $queue), new OrderSync($settings, $queue));
        $this->weekly   = new WeeklySync($settings, $this->bulkSync);

        delete_option(BulkSync::OPTION);
        delete_option(WeeklySync::OPTION);
        WeeklySync::unschedule();
    }

    public function tear_down(): void
    {
        WeeklySync::unschedule();

        parent::tear_down();
    }

    public function test_scheduled_weekly_at_night_and_removed_when_switched_off(): void
    {
        $this->weekly->ensureSchedule();

        $next = WeeklySync::nextRun();
        $this->assertNotNull($next);
        $this->assertGreaterThan(time() + HOUR_IN_SECONDS, $next);
        $this->assertLessThanOrEqual(time() + 8 * DAY_IN_SECONDS, $next);
        $hour = (int) wp_date('G', $next);
        $this->assertGreaterThanOrEqual(1, $hour);
        $this->assertLessThan(5, $hour);

        // Scheduling again keeps the one action.
        $this->weekly->ensureSchedule();
        $this->assertSame($next, WeeklySync::nextRun());
        $this->assertCount(1, as_get_scheduled_actions(['hook' => WeeklySync::HOOK, 'status' => \ActionScheduler_Store::STATUS_PENDING, 'group' => Queue::GROUP], 'ids'));

        update_option(WeeklySync::OPTION, 'no');
        $this->weekly->ensureSchedule();
        $this->assertNull(WeeklySync::nextRun());

        update_option(WeeklySync::OPTION, 'yes');
        update_option('mautic_ecommerce_send_products', 'no');
        $this->weekly->ensureSchedule();
        $this->assertNull(WeeklySync::nextRun(), 'Not when products are not sent.');
    }

    public function test_run_sends_all_products_in_the_background(): void
    {
        $this->createProduct('Candle', '10', 'CND-1');
        $this->createProduct('Diffuser', '30', 'DIF-1');
        $this->requests = [];

        $this->weekly->run();
        $this->assertSame(BulkSync::RUNNING, $this->bulkSync->state('products')['status']);
        $this->bulkSync->runBatch('products');

        $requests = $this->requestsTo('/ecommerce/product');
        $this->assertCount(1, $requests);
        $this->assertCount(2, $requests[0]['json']['products']);
        $this->assertSame(BulkSync::DONE, $this->bulkSync->state('products')['status']);
    }

    public function test_run_does_nothing_when_switched_off_since_scheduling(): void
    {
        $this->createProduct('Candle', '10');
        $this->requests = [];
        update_option(WeeklySync::OPTION, 'no');

        $this->weekly->run();

        $this->assertSame([], $this->bulkSync->state('products'));
        $this->assertSame([], $this->requestsTo('/ecommerce/product'));
    }

    public function test_first_run_is_on_one_weekday_within_a_week(): void
    {
        $now = strtotime('2026-11-08 12:00:00 UTC');
        for ($i = 0; $i < 20; ++$i) {
            $first = WeeklySync::firstRun($now);
            $this->assertGreaterThan($now + HOUR_IN_SECONDS, $first);
            $this->assertLessThanOrEqual($now + 8 * DAY_IN_SECONDS, $first);
        }
    }
}

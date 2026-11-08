<?php

declare(strict_types=1);

namespace MauticEcommerceWooCommerce\Tests\Integration;

use MauticEcommerceWooCommerce\CartRestore;
use MauticEcommerceWooCommerce\Client;
use MauticEcommerceWooCommerce\Queue;
use MauticEcommerceWooCommerce\Settings;
use MauticEcommerceWooCommerce\StorefrontEvents;

final class StorefrontEventsTest extends TestCase
{
    /**
     * A "ct" as Mautic puts it on email links (base64 of a serialized array).
     */
    private const CT = 'YTozOntzOjY6InNvdXJjZSI7YToyOntpOjA7czo1OiJlbWFpbCI7aToxO2k6MTt9czo1OiJlbWFpbCI7aToxO3M6NDoic3RhdCI7czoyMjoiNmFlZjg5YmU1OWY3MDc4MjA0NzIzNiI7fQ==';

    private StorefrontEvents $events;

    public function set_up(): void
    {
        parent::set_up();

        update_option('mautic_ecommerce_send_events', 'yes');
        update_option('mautic_ecommerce_events_consent', StorefrontEvents::CONSENT_ALWAYS);

        $this->events = new StorefrontEvents(new Settings(), new Queue(new Client(new Settings())), new CartRestore());

        wp_set_current_user(0);
        // A fresh guest session per test; WooCommerce keeps the session object otherwise.
        WC()->session = null;
        WC()->initialize_session();
        WC()->customer = new \WC_Customer(0, true);
        unset($_GET['ct'], $_SERVER['HTTP_DNT'], $_SERVER['HTTP_SEC_GPC']);
    }

    public function tear_down(): void
    {
        unset($_GET['ct'], $_SERVER['HTTP_DNT'], $_SERVER['HTTP_SEC_GPC']);

        parent::tear_down();
    }

    public function test_guest_from_a_mautic_email_is_identified_by_the_link(): void
    {
        $_GET['ct'] = self::CT;
        $this->events->captureClickthrough();
        unset($_GET['ct']);

        // Later pages carry no "ct"; the session remembers it.
        $this->assertSame(['ct' => self::CT], $this->events->shopperIdentity());
    }

    public function test_a_known_email_wins_over_the_link(): void
    {
        $_GET['ct'] = self::CT;
        $this->events->captureClickthrough();
        WC()->customer->set_billing_email('Jane@Example.com');

        $this->assertSame(['email' => 'jane@example.com'], $this->events->shopperIdentity());
    }

    public function test_nothing_is_kept_without_consent(): void
    {
        update_option('mautic_ecommerce_events_consent', StorefrontEvents::CONSENT_API);
        $_GET['ct'] = self::CT;
        $this->events->captureClickthrough();

        update_option('mautic_ecommerce_events_consent', StorefrontEvents::CONSENT_ALWAYS);
        $this->assertNull($this->events->shopperIdentity());
    }

    public function test_do_not_track_stops_identification(): void
    {
        $_GET['ct'] = self::CT;
        $this->events->captureClickthrough();
        $_SERVER['HTTP_SEC_GPC'] = '1';

        $this->assertNull($this->events->shopperIdentity());
    }

    /**
     * @dataProvider invalidClickthroughs
     */
    public function test_malformed_links_are_ignored(string $ct): void
    {
        $_GET['ct'] = $ct;
        $this->events->captureClickthrough();

        $this->assertNull($this->events->shopperIdentity());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidClickthroughs(): iterable
    {
        yield 'empty' => [''];
        yield 'markup' => ['<script>alert(1)</script>'];
        yield 'too long' => [str_repeat('a', 2049)];
    }

    public function test_cart_event_of_a_guest_from_an_email_carries_the_link(): void
    {
        $_GET['ct'] = self::CT;
        $this->events->captureClickthrough();
        $this->events->register();

        WC()->cart = new \WC_Cart();
        WC()->cart->add_to_cart($this->createProduct('Candle', '12.50', 'CND-1', 'Candles')->get_id(), 2);

        $payload = $this->events->buildJob(null, ['key' => md5(self::CT.'|added_to_cart|')]);

        $this->assertNotNull($payload);
        $this->assertSame('added_to_cart', $payload['event']);
        $this->assertSame(self::CT, $payload['ct']);
        $this->assertArrayNotHasKey('email', $payload);
        $this->assertSame('CND-1', $payload['cart']['items'][0]['item_id']);
        $this->assertSame(2, $payload['cart']['items'][0]['qty']);
    }
}

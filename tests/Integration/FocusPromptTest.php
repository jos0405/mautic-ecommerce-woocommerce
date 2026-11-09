<?php

declare(strict_types=1);

namespace MauticEcommerceWooCommerce\Tests\Integration;

use MauticEcommerceWooCommerce\CartRestore;
use MauticEcommerceWooCommerce\Client;
use MauticEcommerceWooCommerce\FocusPrompt;
use MauticEcommerceWooCommerce\Queue;
use MauticEcommerceWooCommerce\Settings;
use MauticEcommerceWooCommerce\StorefrontEvents;

final class FocusPromptTest extends TestCase
{
    private const CT = 'YTozOntzOjY6InNvdXJjZSI7YToyOntpOjA7czo1OiJlbWFpbCI7aToxO2k6MTt9czo1OiJlbWFpbCI7aToxO3M6NDoic3RhdCI7czoyMjoiNmFlZjg5YmU1OWY3MDc4MjA0NzIzNiI7fQ==';

    private const HANDLE = 'mautic-ecommerce-focus';

    private FocusPrompt $focus;

    public function set_up(): void
    {
        parent::set_up();

        update_option(FocusPrompt::OPTION_FOCUS, '7');
        update_option('mautic_ecommerce_events_consent', StorefrontEvents::CONSENT_API);

        $settings    = new Settings();
        $events      = new StorefrontEvents($settings, new Queue(new Client($settings)), new CartRestore());
        $this->focus = new FocusPrompt($settings, $events);

        wp_set_current_user(0);
        WC()->session = null;
        WC()->initialize_session();
        WC()->customer = new \WC_Customer(0, true);
        unset($_GET['ct'], $_COOKIE[FocusPrompt::MARKER]);
        wp_dequeue_script(self::HANDLE);
        wp_deregister_script(self::HANDLE);
    }

    public function tear_down(): void
    {
        unset($_GET['ct'], $_COOKIE[FocusPrompt::MARKER]);
        wp_dequeue_script(self::HANDLE);
        wp_deregister_script(self::HANDLE);

        parent::tear_down();
    }

    public function test_anonymous_visitors_get_the_script_with_its_settings(): void
    {
        $this->focus->markIfKnown();
        $this->focus->enqueue();

        $this->assertFalse(FocusPrompt::isMarked());
        $this->assertTrue(wp_script_is(self::HANDLE, 'enqueued'));

        $inline = implode('', (array) wp_scripts()->get_data(self::HANDLE, 'before'));
        $this->assertStringContainsString('"src":"https:\/\/mautic.test\/focus\/7.js"', $inline);
        $this->assertStringContainsString('"views":3', $inline);
        $this->assertStringContainsString('"consent":"consent_api"', $inline);
    }

    public function test_logged_in_customers_are_marked_and_get_no_script(): void
    {
        wp_set_current_user(self::factory()->user->create(['user_email' => 'jane@example.com']));

        $this->focus->markIfKnown();
        $this->focus->enqueue();

        $this->assertTrue(FocusPrompt::isMarked());
        $this->assertFalse(wp_script_is(self::HANDLE, 'enqueued'));
    }

    public function test_guests_with_an_email_at_checkout_are_marked(): void
    {
        WC()->customer->set_billing_email('jane@example.com');

        $this->focus->markIfKnown();

        $this->assertTrue(FocusPrompt::isMarked());
    }

    public function test_visitors_from_a_mautic_email_are_marked_even_without_consent(): void
    {
        // Consent mode is the WP Consent API, and no consent plugin answers here.
        $_GET['ct'] = self::CT;

        $this->focus->markIfKnown();

        $this->assertTrue(FocusPrompt::isMarked());
    }

    public function test_a_malformed_link_does_not_mark(): void
    {
        $_GET['ct'] = 'not a ct!';

        $this->focus->markIfKnown();

        $this->assertFalse(FocusPrompt::isMarked());
    }

    public function test_marked_visitors_get_no_script_once_the_session_is_gone(): void
    {
        $_COOKIE[FocusPrompt::MARKER] = '1';

        $this->focus->enqueue();

        $this->assertFalse(wp_script_is(self::HANDLE, 'enqueued'));
    }

    public function test_placing_an_order_marks_the_visitor(): void
    {
        $this->focus->register();
        do_action('woocommerce_checkout_order_processed', 1, [], null);

        $this->assertTrue(FocusPrompt::isMarked());
    }

    public function test_off_without_a_focus_item(): void
    {
        update_option(FocusPrompt::OPTION_FOCUS, '');

        $this->assertFalse($this->focus->isActive());
    }

    public function test_off_without_a_connection(): void
    {
        update_option('mautic_ecommerce_url', '');

        $this->assertFalse($this->focus->isActive());
    }

    /**
     * @dataProvider viewSettings
     */
    public function test_views_needed_stays_in_range(string $stored, int $expected): void
    {
        update_option(FocusPrompt::OPTION_VIEWS, $stored);

        $this->assertSame($expected, $this->focus->viewsNeeded());
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function viewSettings(): array
    {
        return [
            'set'      => ['5', 5],
            'zero'     => ['0', FocusPrompt::DEFAULT_VIEWS],
            'negative' => ['-2', FocusPrompt::DEFAULT_VIEWS],
            'huge'     => ['500', 50],
        ];
    }
}

<?php

declare(strict_types=1);

namespace MauticEcommerceWooCommerce;

/**
 * Shows a Mautic focus item (popup, bar) to anonymous visitors once they viewed a number of
 * different products, for example to ask for their email.
 *
 * Counting happens in the browser (assets/focus-prompt.js), as guests' product pages are often
 * served from a page cache that never runs PHP. The browser script loads the focus item only
 * without the "known shopper" marker cookie, with consent, and without Do Not Track or Global
 * Privacy Control. The server sets the marker whenever it knows the visitor: logged in, an email
 * entered at checkout, an order placed, or a visit from a Mautic email link. The marker holds
 * no personal data ("1"); a visitor who fakes it only stops seeing the popup.
 */
final class FocusPrompt
{
    public const OPTION_FOCUS = 'mautic_ecommerce_focus_id';
    public const OPTION_VIEWS = 'mautic_ecommerce_focus_views';

    public const DEFAULT_VIEWS = 3;

    public const MARKER = 'mautic_ecommerce_known';

    private const HANDLE = 'mautic-ecommerce-focus';

    public function __construct(
        private Settings $settings,
        private StorefrontEvents $events,
    ) {
    }

    public function register(): void
    {
        if (!$this->isActive()) {
            return;
        }

        // After StorefrontEvents::captureClickthrough (5), which keeps a Mautic email link.
        add_action('wp_loaded', [$this, 'markIfKnown'], 6);
        add_action('set_logged_in_cookie', [$this, 'mark']);
        add_action('woocommerce_checkout_order_processed', [$this, 'mark']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue']);
    }

    public function isActive(): bool
    {
        return $this->settings->isConfigured() && $this->focusId() > 0;
    }

    public function focusId(): int
    {
        return max(0, (int) get_option(self::OPTION_FOCUS, 0));
    }

    public function viewsNeeded(): int
    {
        $views = (int) get_option(self::OPTION_VIEWS, self::DEFAULT_VIEWS);

        return $views > 0 ? min($views, 50) : self::DEFAULT_VIEWS;
    }

    public function markIfKnown(): void
    {
        if (!is_admin() && $this->events->isKnownShopper()) {
            $this->mark();
        }
    }

    /**
     * Sets the marker for a year, unless the browser already has it.
     */
    public function mark(): void
    {
        if (self::isMarked()) {
            return;
        }

        $_COOKIE[self::MARKER] = '1';
        if (!headers_sent()) {
            // Not HTTP-only: the browser script reads it.
            wc_setcookie(self::MARKER, '1', time() + YEAR_IN_SECONDS, is_ssl(), false);
        }
    }

    public static function isMarked(): bool
    {
        return '1' === ($_COOKIE[self::MARKER] ?? null);
    }

    public function enqueue(): void
    {
        // Pages rendered for a known visitor (logged in, checkout) never carry the script.
        if (self::isMarked() || $this->events->isKnownShopper()) {
            return;
        }

        wp_enqueue_script(
            self::HANDLE,
            plugins_url('assets/focus-prompt.js', MAUTIC_ECOMMERCE_WC_FILE),
            [],
            MAUTIC_ECOMMERCE_WC_VERSION,
            ['in_footer' => true, 'strategy' => 'defer']
        );
        wp_add_inline_script(self::HANDLE, 'window.mauticEcommerceFocus = '.wp_json_encode($this->config()).';', 'before');
    }

    /**
     * The browser script's settings. The same for every visitor, so cached pages can carry it.
     *
     * @return array<string, mixed>
     */
    public function config(): array
    {
        return [
            'src'     => $this->settings->getMauticUrl().'/focus/'.$this->focusId().'.js',
            'focusId' => $this->focusId(),
            'views'   => $this->viewsNeeded(),
            'consent' => $this->settings->getConsentMode(),
            'marker'  => self::MARKER,
            'path'    => COOKIEPATH ?: '/',
            'domain'  => COOKIE_DOMAIN ?: '',
        ];
    }
}

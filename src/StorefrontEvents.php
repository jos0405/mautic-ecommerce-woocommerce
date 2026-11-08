<?php

declare(strict_types=1);

namespace MauticEcommerceWooCommerce;

/**
 * Product views, cart changes and checkout starts of known shoppers, with consent: logged-in
 * customers, guests once they entered their email at checkout, and guests who arrived from a
 * Mautic email link. Sent in the background; cart changes wait CART_DELAY seconds so a burst of
 * changes sends one snapshot.
 *
 * Anonymous visitors are not linked through Mautic's tracking cookie: the cookie is editable by
 * the visitor and would let anyone put carts on someone else's contact. A Mautic email link's
 * "ct" parameter is kept as received and sent along; Mautic identifies the recipient from the
 * send's random tracking hash in it, never from the (editable) contact ID.
 */
final class StorefrontEvents
{
    public const CART_DELAY = 60;

    public const CONSENT_API    = 'consent_api';
    public const CONSENT_ALWAYS = 'always';

    private const TRANSIENT_PREFIX = 'mautic_ecom_evt_';

    /**
     * WooCommerce session key for the "ct" parameter of the Mautic email link the guest arrived from.
     */
    public const SESSION_CLICKTHROUGH = 'mautic_ecommerce_ct';

    private const CLICKTHROUGH_MAX = 2048;

    public function __construct(
        private Settings $settings,
        private Queue $queue,
        private CartRestore $cartRestore,
    ) {
    }

    public function register(): void
    {
        add_filter('mautic_ecommerce_build_event', [$this, 'buildJob'], 10, 2);

        // The email link identifies guests for events and attributes orders to the email.
        if ($this->settings->isEnabled('events', 'no') || $this->settings->isEnabled('orders')) {
            // Before CartRestore (20), which redirects: restore-cart links in Mautic emails carry "ct" too.
            add_action('wp_loaded', [$this, 'captureClickthrough'], 5);
        }

        if (!$this->settings->isEnabled('events', 'no')) {
            return;
        }

        add_action('template_redirect', [$this, 'onPage']);
        add_action('woocommerce_add_to_cart', [$this, 'onCartChanged'], 20);
        add_action('woocommerce_cart_item_removed', [$this, 'onCartChanged'], 20);
        add_action('woocommerce_after_cart_item_quantity_update', [$this, 'onCartChanged'], 20);
    }

    public function onPage(): void
    {
        if (function_exists('is_product') && is_product()) {
            $product = wc_get_product((int) get_queried_object_id());
            if ($product instanceof \WC_Product) {
                $this->record('viewed_product', ['product' => $this->productData($product)], 0);
            }

            return;
        }

        // get_cart() loads the cart from the session; is_empty() alone may not have yet.
        if (function_exists('is_checkout') && is_checkout() && !is_order_received_page() && null !== WC()->cart && [] !== WC()->cart->get_cart()) {
            $this->record('started_checkout', ['cart' => $this->cartData()], 0);
        }
    }

    public function onCartChanged(): void
    {
        if (null === WC()->cart || [] === WC()->cart->get_cart()) {
            return;
        }

        $this->record('added_to_cart', ['cart' => $this->cartData()], self::CART_DELAY);
    }

    /**
     * @param array<string, scalar> $args
     *
     * @return array<string, mixed>|null
     */
    public function buildJob(mixed $payload, array $args): ?array
    {
        $key   = self::TRANSIENT_PREFIX.sanitize_key((string) ($args['key'] ?? ''));
        $event = get_transient($key);
        delete_transient($key);

        return is_array($event) ? ['_endpoint' => 'event'] + $event : null;
    }

    /**
     * Keeps the "ct" parameter of a Mautic email link in the guest's WooCommerce session, as
     * received (it is not decoded here). Only with consent, as it identifies the visitor.
     */
    public function captureClickthrough(): void
    {
        $ct = isset($_GET['ct']) && is_string($_GET['ct']) ? wp_unslash($_GET['ct']) : '';
        if (!self::isClickthrough($ct) || !$this->hasConsent() || !function_exists('WC') || null === WC()->session) {
            return;
        }

        WC()->session->set(self::SESSION_CLICKTHROUGH, $ct);
        if (!WC()->session->has_session()) {
            // WooCommerce only starts a guest session with a cart; this visitor may not have one yet.
            WC()->session->set_customer_session_cookie(true);
        }
    }

    /**
     * Mautic's "ct" is URL-safe base64.
     */
    public static function isClickthrough(string $ct): bool
    {
        return '' !== $ct && strlen($ct) <= self::CLICKTHROUGH_MAX && 1 === preg_match('#^[A-Za-z0-9+/=_-]+$#', $ct);
    }

    /**
     * Who the shopper is, or null when unknown or without consent: ['email' => ...], or for a guest
     * who arrived from a Mautic email, ['ct' => ...]. An email always wins.
     *
     * @return array{email: string}|array{ct: string}|null
     */
    public function shopperIdentity(): ?array
    {
        if (!$this->hasConsent()) {
            return null;
        }

        $email = $this->shopperEmail();
        if (null !== $email) {
            return ['email' => $email];
        }

        $ct = self::sessionClickthrough();

        return null !== $ct ? ['ct' => $ct] : null;
    }

    /**
     * The "ct" of the Mautic email link this visitor arrived from, if one was kept (with consent).
     */
    public static function sessionClickthrough(): ?string
    {
        $ct = function_exists('WC') && null !== WC()->session ? WC()->session->get(self::SESSION_CLICKTHROUGH) : null;

        return is_string($ct) && self::isClickthrough($ct) ? $ct : null;
    }

    private function shopperEmail(): ?string
    {
        $user  = wp_get_current_user();
        $email = $user->exists() ? $user->user_email : '';

        if ('' === $email && null !== WC()->customer) {
            $email = (string) WC()->customer->get_billing_email();
        }

        return is_email($email) ? strtolower($email) : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function record(string $type, array $data, int $delay): void
    {
        $identity = $this->shopperIdentity();
        if (null === $identity) {
            return;
        }

        $event = ['event' => $type] + $identity + [
            'occurred_at' => gmdate(DATE_ATOM),
            'source'      => $this->settings->getStore(),
            'currency'    => get_woocommerce_currency(),
        ] + $data;

        // One pending job per shopper and event (and product, for views); the latest state wins.
        $key = md5(implode('|', $identity).'|'.$type.'|'.($data['product']['id'] ?? ''));
        set_transient(self::TRANSIENT_PREFIX.$key, $event, HOUR_IN_SECONDS);

        $delay > 0 ? $this->queue->pushLater('event', ['key' => $key], $delay) : $this->queue->push('event', ['key' => $key]);
    }

    private function hasConsent(): bool
    {
        // Browser opt-outs win over everything.
        if ('1' === ($_SERVER['HTTP_DNT'] ?? '') || '1' === ($_SERVER['HTTP_SEC_GPC'] ?? '')) {
            return false;
        }

        if (self::CONSENT_ALWAYS === $this->settings->getConsentMode()) {
            return true;
        }

        return function_exists('wp_has_consent') && wp_has_consent('marketing');
    }

    /**
     * @return array<string, mixed>
     */
    private function productData(\WC_Product $product): array
    {
        $imageId = (int) $product->get_image_id();

        return [
            'id'        => (string) $product->get_id(),
            'name'      => html_entity_decode(wp_strip_all_tags($product->get_name()), ENT_QUOTES),
            'category'  => ProductSync::mainCategory($product->get_id()),
            'price'     => '' !== $product->get_price() ? (string) wc_get_price_to_display($product) : null,
            'url'       => get_permalink($product->get_id()),
            'image_url' => $imageId > 0 ? (wp_get_attachment_image_url($imageId, 'woocommerce_thumbnail') ?: null) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function cartData(): array
    {
        $cart    = WC()->cart;
        $items   = [];
        $restore = [];

        foreach ($cart->get_cart() as $line) {
            $product = $line['data'] ?? null;
            if (!$product instanceof \WC_Product) {
                continue;
            }

            $productId = (int) $line['product_id'];
            $imageId   = (int) $product->get_image_id();
            $items[]   = [
                'item_id'   => '' !== $product->get_sku() ? $product->get_sku() : (string) $product->get_id(),
                'name'      => html_entity_decode(wp_strip_all_tags($product->get_name()), ENT_QUOTES),
                'qty'       => (int) $line['quantity'],
                'price'     => (string) wc_get_price_to_display($product),
                'category'  => ProductSync::mainCategory($productId),
                'url'       => get_permalink($productId),
                'image_url' => $imageId > 0 ? (wp_get_attachment_image_url($imageId, 'woocommerce_thumbnail') ?: null) : null,
            ];
            $restore[] = ['product_id' => $productId, 'variation_id' => (int) $line['variation_id'], 'qty' => (int) $line['quantity']];
        }

        return [
            'id'    => null !== WC()->session ? substr(md5((string) WC()->session->get_customer_id()), 0, 16) : null,
            'total' => (string) ((float) $cart->get_cart_contents_total() + (float) $cart->get_cart_contents_tax()),
            'url'   => $this->cartRestore->createUrl($restore),
            'items' => $items,
        ];
    }
}

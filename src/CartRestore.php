<?php

declare(strict_types=1);

namespace MauticEcommerceWooCommerce;

/**
 * Links that rebuild a cart, for abandoned cart emails: /?mautic_restore_cart=<token>.
 * The token only stores product IDs and quantities, never personal data.
 */
final class CartRestore
{
    public const QUERY_VAR = 'mautic_restore_cart';
    public const TTL       = 14 * DAY_IN_SECONDS;

    public function register(): void
    {
        add_action('wp_loaded', [$this, 'maybeRestore'], 20);
    }

    /**
     * @param list<array{product_id: int, variation_id: int, qty: int}> $lines
     */
    public function createUrl(array $lines): ?string
    {
        if ([] === $lines) {
            return null;
        }

        $token = wp_generate_password(32, false, false);
        set_transient(self::QUERY_VAR.'_'.$token, $lines, self::TTL);

        return add_query_arg(self::QUERY_VAR, $token, wc_get_cart_url());
    }

    public function maybeRestore(): void
    {
        $token = isset($_GET[self::QUERY_VAR]) ? sanitize_key(wp_unslash((string) $_GET[self::QUERY_VAR])) : '';
        if ('' === $token || !function_exists('WC') || null === WC()->cart) {
            return;
        }

        $lines = get_transient(self::QUERY_VAR.'_'.$token);
        if (is_array($lines)) {
            WC()->cart->empty_cart();
            foreach ($lines as $line) {
                WC()->cart->add_to_cart((int) $line['product_id'], max(1, (int) $line['qty']), (int) $line['variation_id']);
            }
        }

        wp_safe_redirect(remove_query_arg(self::QUERY_VAR));
        exit;
    }
}

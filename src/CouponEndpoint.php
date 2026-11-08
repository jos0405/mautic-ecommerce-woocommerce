<?php

declare(strict_types=1);

namespace MauticEcommerceWooCommerce;

/**
 * POST /wp-json/mautic-ecommerce/v1/coupon: Mautic's "Issue coupon" campaign action creates a
 * single-use coupon here, limited to the contact's email.
 *
 * Off until "Allow Mautic to create coupons" is ticked. Requests are signed like the plugin's
 * own requests to Mautic (X-Mautic-Ecommerce-Signature, HMAC-SHA256 of the body with the webhook
 * secret) and carry a timestamp: older than 5 minutes is refused, so a captured request cannot
 * be replayed later. The same code sent again for the same email answers as created (Mautic
 * retries after a timeout); for another email it is refused.
 */
final class CouponEndpoint
{
    public const NAMESPACE = 'mautic-ecommerce/v1';
    public const ROUTE     = '/coupon';
    public const OPTION    = 'mautic_ecommerce_allow_coupons';

    public const SIGNATURE_HEADER = 'X-Mautic-Ecommerce-Signature';
    public const MAX_AGE          = 5 * MINUTE_IN_SECONDS;

    /** Marks coupons this plugin created, with the email they are for. */
    public const META_EMAIL = '_mautic_ecommerce_coupon_email';

    private const TYPES = ['percent', 'fixed_cart'];

    public function __construct(
        private Settings $settings,
    ) {
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoute']);
    }

    public function registerRoute(): void
    {
        register_rest_route(self::NAMESPACE, self::ROUTE, [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'create'],
            'permission_callback' => [$this, 'authorize'],
        ]);
    }

    public static function isAllowed(): bool
    {
        return 'yes' === get_option(self::OPTION, 'no');
    }

    public function authorize(\WP_REST_Request $request): bool|\WP_Error
    {
        if (!self::isAllowed() || !$this->settings->isConfigured()) {
            return new \WP_Error('mautic_ecommerce_coupons_off', 'Coupons from Mautic are not allowed in this shop.', ['status' => 403]);
        }

        $signature = (string) $request->get_header(self::SIGNATURE_HEADER);
        $expected  = hash_hmac('sha256', $request->get_body(), $this->settings->getSecret());
        if ('' === $signature || !hash_equals($expected, $signature)) {
            return new \WP_Error('mautic_ecommerce_bad_signature', 'Invalid signature.', ['status' => 401]);
        }

        $timestamp = $request->get_json_params()['timestamp'] ?? null;
        if (!is_int($timestamp) || abs(time() - $timestamp) > self::MAX_AGE) {
            return new \WP_Error('mautic_ecommerce_stale', 'Request too old or clock out of sync.', ['status' => 401]);
        }

        return true;
    }

    public function create(\WP_REST_Request $request): \WP_REST_Response
    {
        $data  = (array) $request->get_json_params();
        $error = self::validate($data);
        if (null !== $error) {
            return self::answer(400, ['ok' => false, 'message' => $error]);
        }

        $code  = wc_format_coupon_code((string) $data['code']);
        $email = strtolower(sanitize_email((string) $data['email']));

        // A retry of a request that did create the coupon.
        $existingId = wc_get_coupon_id_by_code($code);
        if ($existingId > 0) {
            return $email === get_post_meta($existingId, self::META_EMAIL, true)
                ? self::answer(200, ['ok' => true, 'code' => $code, 'store' => $this->settings->getStore()])
                : self::answer(409, ['ok' => false, 'message' => 'A coupon with this code already exists.']);
        }

        /**
         * Lets the shop veto coupons, e.g. above a maximum value.
         *
         * @param bool                 $allowed
         * @param array<string, mixed> $data the validated request
         */
        if (!apply_filters('mautic_ecommerce_coupon_allowed', true, $data)) {
            return self::answer(403, ['ok' => false, 'message' => 'The shop does not allow this coupon.']);
        }

        try {
            $coupon = new \WC_Coupon();
            $coupon->set_code($code);
            $coupon->set_discount_type((string) $data['discount_type']);
            $coupon->set_amount((string) $data['amount']);
            // Valid through the last day, shop time.
            $coupon->set_date_expires((new \DateTimeImmutable((string) $data['expires_at'].' 23:59:59', wp_timezone()))->getTimestamp());
            $coupon->set_usage_limit(1);
            $coupon->set_usage_limit_per_user(1);
            $coupon->set_email_restrictions([$email]);
            $coupon->set_individual_use(true);
            $coupon->set_exclude_sale_items(!empty($data['exclude_sale_items']));
            $coupon->set_free_shipping(!empty($data['free_shipping']));
            if (isset($data['minimum_amount']) && is_numeric($data['minimum_amount']) && (float) $data['minimum_amount'] > 0) {
                $coupon->set_minimum_amount((string) $data['minimum_amount']);
            }
            $coupon->set_description(sanitize_text_field((string) ($data['description'] ?? 'Mautic')));
            $coupon->update_meta_data(self::META_EMAIL, $email);
            $coupon->save();
        } catch (\Throwable $exception) {
            return self::answer(500, ['ok' => false, 'message' => 'The coupon could not be saved: '.$exception->getMessage()]);
        }

        return self::answer(201, ['ok' => true, 'code' => $code, 'store' => $this->settings->getStore()]);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return string|null what is wrong, or null
     */
    public static function validate(array $data): ?string
    {
        $code = $data['code'] ?? null;
        if (!is_string($code) || 1 !== preg_match('/^[A-Za-z0-9]{1,12}-[A-Za-z0-9]{6,16}$|^[A-Za-z0-9]{6,16}$/', $code)) {
            return 'code must be letters and digits, optionally PREFIX-CODE.';
        }

        if (!is_string($data['email'] ?? null) || !is_email($data['email'])) {
            return 'email is not valid.';
        }

        $type = $data['discount_type'] ?? null;
        if (!in_array($type, self::TYPES, true)) {
            return 'discount_type must be percent or fixed_cart.';
        }

        $amount = $data['amount'] ?? null;
        if (!is_numeric($amount) || (float) $amount <= 0 || ('percent' === $type && (float) $amount > 100)) {
            return 'amount must be above 0, and at most 100 for percent.';
        }

        if (isset($data['minimum_amount']) && (!is_numeric($data['minimum_amount']) || (float) $data['minimum_amount'] < 0)) {
            return 'minimum_amount must be zero or more.';
        }

        $expires = is_string($data['expires_at'] ?? null) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $data['expires_at'], wp_timezone()) : false;
        $today   = new \DateTimeImmutable('today', wp_timezone());
        if (false === $expires || $expires < $today || $expires > $today->modify('+366 days')) {
            return 'expires_at must be a date (YYYY-MM-DD) from today to a year ahead.';
        }

        return null;
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function answer(int $status, array $body): \WP_REST_Response
    {
        return new \WP_REST_Response($body, $status);
    }
}

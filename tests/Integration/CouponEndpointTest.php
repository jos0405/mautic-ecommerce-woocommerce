<?php

declare(strict_types=1);

namespace MauticEcommerceWooCommerce\Tests\Integration;

use MauticEcommerceWooCommerce\CouponEndpoint;
use MauticEcommerceWooCommerce\Settings;

final class CouponEndpointTest extends TestCase
{
    public function set_up(): void
    {
        parent::set_up();

        update_option(CouponEndpoint::OPTION, 'yes');
        (new CouponEndpoint(new Settings()))->register();
        do_action('rest_api_init');
    }

    public function test_creates_a_single_use_coupon_for_one_email(): void
    {
        $response = $this->post($this->coupon(['minimum_amount' => '50.00', 'exclude_sale_items' => true]));

        $this->assertSame(201, $response->get_status());
        $this->assertSame(['ok' => true, 'code' => 'back-abcd2345', 'store' => 'teststore'], $response->get_data());

        $coupon = new \WC_Coupon('BACK-ABCD2345');
        $this->assertGreaterThan(0, $coupon->get_id());
        $this->assertSame('percent', $coupon->get_discount_type());
        $this->assertSame(15.0, (float) $coupon->get_amount());
        $this->assertSame(1, $coupon->get_usage_limit());
        $this->assertSame(1, $coupon->get_usage_limit_per_user());
        $this->assertSame(['jane@example.com'], $coupon->get_email_restrictions());
        $this->assertTrue($coupon->get_individual_use());
        $this->assertTrue($coupon->get_exclude_sale_items());
        $this->assertSame(50.0, (float) $coupon->get_minimum_amount());
        $this->assertSame(gmdate('Y-m-d', strtotime('+10 days')).' 23:59', $coupon->get_date_expires()->date_i18n('Y-m-d H:i'));
    }

    public function test_a_retry_answers_created_and_another_email_is_refused(): void
    {
        $this->assertSame(201, $this->post($this->coupon())->get_status());
        $this->assertSame(200, $this->post($this->coupon(['email' => 'Jane@Example.com']))->get_status());

        $other = $this->post($this->coupon(['email' => 'eve@example.com']));
        $this->assertSame(409, $other->get_status());
        $this->assertSame(['jane@example.com'], (new \WC_Coupon('back-abcd2345'))->get_email_restrictions());
    }

    public function test_refuses_unsigned_stale_switched_off_and_invalid_requests(): void
    {
        $this->assertSame(401, $this->post($this->coupon(), 'wrong-secret')->get_status());
        $this->assertSame(401, $this->post($this->coupon(['timestamp' => time() - 600]))->get_status());
        $this->assertSame(400, $this->post($this->coupon(['amount' => '150']))->get_status(), 'Over 100 %.');
        $this->assertSame(400, $this->post($this->coupon(['code' => '<b>x</b>']))->get_status());
        $this->assertSame(400, $this->post($this->coupon(['expires_at' => '2020-01-01']))->get_status());

        add_filter('mautic_ecommerce_coupon_allowed', '__return_false');
        $this->assertSame(403, $this->post($this->coupon())->get_status());
        remove_filter('mautic_ecommerce_coupon_allowed', '__return_false');

        update_option(CouponEndpoint::OPTION, 'no');
        $this->assertSame(403, $this->post($this->coupon())->get_status());

        $this->assertSame(0, wc_get_coupon_id_by_code('back-abcd2345'));
    }

    public function test_orders_send_their_coupon_codes(): void
    {
        $this->post($this->coupon());
        $product = $this->createProduct('Candle', '100');

        $order = wc_create_order();
        $order->add_product($product, 1);
        $order->set_billing_email('jane@example.com');
        $order->apply_coupon('back-abcd2345');
        $order->calculate_totals();
        $order->set_status('processing');
        $order->save();

        $payload = (new \MauticEcommerceWooCommerce\OrderSync(new Settings(), new \MauticEcommerceWooCommerce\Queue(new \MauticEcommerceWooCommerce\Client(new Settings()))))->build($order);
        $this->assertSame(['back-abcd2345'], $payload['coupons']);
    }

    /**
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    private function coupon(array $override = []): array
    {
        return $override + [
            'code'          => 'BACK-ABCD2345',
            'email'         => 'jane@example.com',
            'discount_type' => 'percent',
            'amount'        => '15.00',
            'expires_at'    => gmdate('Y-m-d', strtotime('+10 days')),
            'description'   => 'Mautic campaign 3',
            'timestamp'     => time(),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function post(array $data, string $secret = self::SECRET): \WP_REST_Response
    {
        $body    = (string) wp_json_encode($data);
        $request = new \WP_REST_Request('POST', '/'.CouponEndpoint::NAMESPACE.CouponEndpoint::ROUTE);
        $request->set_header('Content-Type', 'application/json');
        $request->set_header(CouponEndpoint::SIGNATURE_HEADER, hash_hmac('sha256', $body, $secret));
        $request->set_body($body);

        return rest_get_server()->dispatch($request);
    }
}

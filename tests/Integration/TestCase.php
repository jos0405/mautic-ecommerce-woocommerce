<?php

declare(strict_types=1);

namespace MauticEcommerceWooCommerce\Tests\Integration;

/**
 * WordPress + WooCommerce test case with a fake Mautic: every outgoing HTTP request is captured
 * and answered from $responses (by path), 204 by default. Nothing leaves the machine.
 */
abstract class TestCase extends \WP_UnitTestCase
{
    protected const MAUTIC_URL = 'https://mautic.test';
    protected const SECRET     = 'test-secret';

    /**
     * @var list<array{url: string, path: string, headers: array<string, string>, body: string, json: mixed}>
     */
    protected array $requests = [];

    /**
     * Path => fake answer: ['code' => int, 'body' => string], or a \WP_Error.
     *
     * @var array<string, array{code: int, body?: string}|\WP_Error>
     */
    protected array $responses = [];

    public function set_up(): void
    {
        parent::set_up();

        $this->requests  = [];
        $this->responses = [];

        update_option('mautic_ecommerce_url', self::MAUTIC_URL);
        update_option('mautic_ecommerce_secret', self::SECRET);
        update_option('mautic_ecommerce_store', 'teststore');

        add_filter('pre_http_request', [$this, 'captureRequest'], 10, 3);
        // Headers are long sent under PHPUnit; WooCommerce would warn on every cookie.
        add_filter('woocommerce_set_cookie_enabled', '__return_false');
    }

    public function tear_down(): void
    {
        remove_filter('pre_http_request', [$this, 'captureRequest'], 10);

        parent::tear_down();
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>|\WP_Error
     */
    public function captureRequest(mixed $preempt, array $args, string $url): array|\WP_Error
    {
        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        $body = (string) ($args['body'] ?? '');

        $this->requests[] = [
            'url'     => $url,
            'path'    => $path,
            'headers' => (array) ($args['headers'] ?? []),
            'body'    => $body,
            'json'    => json_decode($body, true),
        ];

        $answer = $this->responses[$path] ?? ['code' => 204];
        if ($answer instanceof \WP_Error) {
            return $answer;
        }

        return [
            'headers'  => [],
            'body'     => $answer['body'] ?? '',
            'response' => ['code' => $answer['code'], 'message' => ''],
            'cookies'  => [],
            'filename' => null,
        ];
    }

    /**
     * @return list<array{url: string, path: string, headers: array<string, string>, body: string, json: mixed}>
     */
    protected function requestsTo(string $path): array
    {
        return array_values(array_filter($this->requests, static fn (array $request): bool => $request['path'] === $path));
    }

    protected function createProduct(string $name, string $price, string $sku = '', ?string $category = null): \WC_Product_Simple
    {
        $product = new \WC_Product_Simple();
        $product->set_name($name);
        $product->set_regular_price($price);
        $product->set_sku($sku);

        if (null !== $category) {
            $term = term_exists($category, 'product_cat') ?: wp_insert_term($category, 'product_cat');
            $product->set_category_ids([(int) $term['term_id']]);
        }

        $product->save();

        return $product;
    }

    /**
     * @param list<array{0: \WC_Product, 1: int}> $lines product and quantity
     */
    protected function createOrder(array $lines, string $status = 'processing', string $email = 'jane@example.com'): \WC_Order
    {
        $order = wc_create_order();
        foreach ($lines as [$product, $qty]) {
            $order->add_product($product, $qty);
        }
        $order->set_billing_email($email);
        $order->set_shipping_city('Basel');
        $order->set_currency('CHF');
        $order->calculate_totals();
        $order->set_status($status);
        $order->save();

        return $order;
    }
}

<?php

declare(strict_types=1);

namespace MauticEcommerceWooCommerce\Tests\Integration;

use MauticEcommerceWooCommerce\Client;
use MauticEcommerceWooCommerce\Settings;

final class ClientTest extends TestCase
{
    private Client $client;

    public function set_up(): void
    {
        parent::set_up();

        $this->client = new Client(new Settings());
    }

    public function test_send_signs_the_exact_body(): void
    {
        $this->client->send('order', ['order_id' => '1001', 'name' => 'Crème brûlée']);

        $request = $this->requestsTo('/ecommerce/order')[0];
        $this->assertSame(self::MAUTIC_URL.'/ecommerce/order', $request['url']);
        $this->assertSame(hash_hmac('sha256', $request['body'], self::SECRET), $request['headers']['X-Mautic-Ecommerce-Signature']);
        $this->assertStringContainsString('Crème brûlée', $request['body']);
    }

    public function test_send_throws_on_server_errors_so_the_queue_retries(): void
    {
        $this->responses['/ecommerce/product'] = ['code' => 500];

        $this->expectException(\RuntimeException::class);
        $this->client->send('product', ['id' => '1']);
    }

    public function test_send_does_nothing_until_configured(): void
    {
        delete_option('mautic_ecommerce_secret');

        $this->client->send('order', ['order_id' => '1']);

        $this->assertSame([], $this->requests);
    }

    public function test_ping_reports_a_working_connection(): void
    {
        $this->responses['/ecommerce/ping'] = ['code' => 200, 'body' => '{"ok":true,"version":"1.7.0","storefront_events":true}'];

        $result = $this->client->ping();

        $this->assertTrue($result['ok']);
        $this->assertSame('1.7.0', $result['version']);
        $this->assertTrue($result['storefront_events']);
        $request = $this->requestsTo('/ecommerce/ping')[0];
        $this->assertSame(hash_hmac('sha256', $request['body'], self::SECRET), $request['headers']['X-Mautic-Ecommerce-Signature']);
    }

    /**
     * @dataProvider failures
     *
     * @param array{code: int, body?: string}|\WP_Error $answer
     */
    public function test_ping_explains_failures(array|\WP_Error $answer, string $expected): void
    {
        $this->responses['/ecommerce/ping'] = $answer;

        $result = $this->client->ping();

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString($expected, $result['message']);
    }

    /**
     * @return iterable<string, array{0: array{code: int, body?: string}|\WP_Error, 1: string}>
     */
    public static function failures(): iterable
    {
        yield 'rejected' => [['code' => 204], 'webhook secret differs'];
        yield 'old connector' => [['code' => 404], 'update the Ecommerce Connector'];
        yield 'other status' => [['code' => 502], 'HTTP 502'];
        yield 'unreachable' => [new \WP_Error('http_request_failed', 'Could not resolve host'), 'Could not resolve host'];
        yield '200 without ok' => [['code' => 200, 'body' => '<html>login</html>'], 'HTTP 200'];
    }

    public function test_ping_needs_settings(): void
    {
        delete_option('mautic_ecommerce_url');

        $this->assertFalse($this->client->ping()['ok']);
        $this->assertSame([], $this->requests);
    }
}

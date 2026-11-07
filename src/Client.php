<?php

declare(strict_types=1);

namespace MauticEcommerceWooCommerce;

/**
 * Posts signed JSON to the Ecommerce Connector: HMAC-SHA256 of the exact body, hex, in
 * X-Mautic-Ecommerce-Signature. Mautic answers 204 whether it accepts or rejects a request;
 * rejections are explained in Mautic's log.
 */
final class Client
{
    public const ENDPOINTS = [
        'order'   => '/ecommerce/order',
        'product' => '/ecommerce/product',
        'event'   => '/ecommerce/event',
    ];

    public function __construct(
        private Settings $settings,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @throws \RuntimeException when the request fails, so the queue retries it
     */
    public function send(string $endpoint, array $payload): void
    {
        if (!$this->settings->isConfigured() || !isset(self::ENDPOINTS[$endpoint])) {
            return;
        }

        $body     = (string) wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $response = wp_remote_post($this->settings->getMauticUrl().self::ENDPOINTS[$endpoint], [
            'timeout' => 15,
            'headers' => [
                'Content-Type'                 => 'application/json',
                'X-Mautic-Ecommerce-Signature' => hash_hmac('sha256', $body, $this->settings->getSecret()),
                'User-Agent'                   => 'MauticEcommerceWooCommerce/'.MAUTIC_ECOMMERCE_WC_VERSION,
            ],
            'body'    => $body,
        ]);

        if (is_wp_error($response)) {
            throw new \RuntimeException('Mautic could not be reached: '.$response->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code >= 300) {
            throw new \RuntimeException(sprintf('Mautic answered HTTP %d for %s.', $code, self::ENDPOINTS[$endpoint]));
        }
    }
}

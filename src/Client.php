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

        $response = $this->post(self::ENDPOINTS[$endpoint], $payload);

        if (is_wp_error($response)) {
            throw new \RuntimeException('Mautic could not be reached: '.$response->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code >= 300) {
            throw new \RuntimeException(sprintf('Mautic answered HTTP %d for %s.', $code, self::ENDPOINTS[$endpoint]));
        }
    }

    /**
     * Checks URL and secret with the connector's signed /ecommerce/ping. Only a correct signature
     * gets an answer with content; Mautic answers 204 to a wrong secret, an unset secret or an
     * unpublished plugin alike, and logs which.
     *
     * @return array{ok: bool, message: string, version?: string, storefront_events?: bool}
     */
    public function ping(): array
    {
        if (!$this->settings->isConfigured()) {
            return ['ok' => false, 'message' => __('Enter the Mautic URL and webhook secret and save first.', 'mautic-ecommerce-woocommerce')];
        }

        $response = $this->post('/ecommerce/ping', ['ping' => true, 'store' => $this->settings->getStore()]);

        if (is_wp_error($response)) {
            /* translators: %s: error message */
            return ['ok' => false, 'message' => sprintf(__('Mautic could not be reached: %s', 'mautic-ecommerce-woocommerce'), $response->get_error_message())];
        }

        $code   = (int) wp_remote_retrieve_response_code($response);
        $answer = json_decode((string) wp_remote_retrieve_body($response), true);

        if (200 === $code && is_array($answer) && true === ($answer['ok'] ?? null)) {
            return [
                'ok'                => true,
                /* translators: %s: Ecommerce Connector version */
                'message'           => sprintf(__('Connected to the Ecommerce Connector %s in Mautic.', 'mautic-ecommerce-woocommerce'), (string) ($answer['version'] ?? '')),
                'version'           => (string) ($answer['version'] ?? ''),
                'storefront_events' => (bool) ($answer['storefront_events'] ?? false),
            ];
        }

        return ['ok' => false, 'message' => match (true) {
            204 === $code => __('Mautic answered but did not accept the request: the webhook secret differs, or the Ecommerce Connector is not published or has no secret in Mautic. Mautic\'s log says which.', 'mautic-ecommerce-woocommerce'),
            404 === $code => __('Mautic does not know /ecommerce/ping: update the Ecommerce Connector in Mautic, or check the Mautic URL.', 'mautic-ecommerce-woocommerce'),
            /* translators: %d: HTTP status code */
            default => sprintf(__('Mautic answered HTTP %d. Check the Mautic URL.', 'mautic-ecommerce-woocommerce'), $code),
        }];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>|\WP_Error
     */
    private function post(string $path, array $payload): array|\WP_Error
    {
        $body = (string) wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return wp_remote_post($this->settings->getMauticUrl().$path, [
            'timeout' => 15,
            'headers' => [
                'Content-Type'                 => 'application/json',
                'X-Mautic-Ecommerce-Signature' => hash_hmac('sha256', $body, $this->settings->getSecret()),
                'User-Agent'                   => 'MauticEcommerceWooCommerce/'.MAUTIC_ECOMMERCE_WC_VERSION,
            ],
            'body'    => $body,
        ]);
    }
}

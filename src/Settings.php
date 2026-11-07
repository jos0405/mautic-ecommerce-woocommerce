<?php

declare(strict_types=1);

namespace MauticEcommerceWooCommerce;

/**
 * WooCommerce > Settings > Mautic.
 */
final class Settings
{
    public const TAB = 'mautic_ecommerce';

    public function register(): void
    {
        add_filter('woocommerce_settings_tabs_array', [$this, 'addTab'], 50);
        add_action('woocommerce_settings_tabs_'.self::TAB, [$this, 'render']);
        add_action('woocommerce_update_options_'.self::TAB, [$this, 'save']);
    }

    /**
     * @param array<string, string> $tabs
     *
     * @return array<string, string>
     */
    public function addTab(array $tabs): array
    {
        $tabs[self::TAB] = __('Mautic', 'mautic-ecommerce-woocommerce');

        return $tabs;
    }

    public function render(): void
    {
        woocommerce_admin_fields($this->fields());
    }

    public function save(): void
    {
        // An empty secret field keeps the stored secret, like a password field.
        $secret = isset($_POST['mautic_ecommerce_secret']) ? trim((string) wp_unslash($_POST['mautic_ecommerce_secret'])) : '';
        if ('' === $secret) {
            unset($_POST['mautic_ecommerce_secret']);
        }

        woocommerce_update_options($this->fields());
    }

    public function isConfigured(): bool
    {
        return '' !== $this->getMauticUrl() && '' !== $this->getSecret();
    }

    public function getMauticUrl(): string
    {
        return untrailingslashit(trim((string) get_option('mautic_ecommerce_url', '')));
    }

    public function getSecret(): string
    {
        return (string) get_option('mautic_ecommerce_secret', '');
    }

    /**
     * The order source and catalog store name in Mautic.
     */
    public function getStore(): string
    {
        $store = sanitize_key((string) get_option('mautic_ecommerce_store', 'woocommerce'));

        return '' !== $store ? $store : 'woocommerce';
    }

    public function isEnabled(string $what): bool
    {
        return 'yes' === get_option('mautic_ecommerce_send_'.$what, 'yes');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fields(): array
    {
        return [
            [
                'title' => __('Mautic', 'mautic-ecommerce-woocommerce'),
                'type'  => 'title',
                'desc'  => __('Connects this shop to the Ecommerce Connector plugin in Mautic. Use the webhook secret from the plugin settings in Mautic.', 'mautic-ecommerce-woocommerce'),
                'id'    => 'mautic_ecommerce_section',
            ],
            [
                'title'       => __('Mautic URL', 'mautic-ecommerce-woocommerce'),
                'id'          => 'mautic_ecommerce_url',
                'type'        => 'url',
                'placeholder' => 'https://mautic.example.com',
            ],
            [
                'title'       => __('Webhook secret', 'mautic-ecommerce-woocommerce'),
                'id'          => 'mautic_ecommerce_secret',
                'type'        => 'password',
                'value'       => '',
                'placeholder' => '' !== $this->getSecret() ? __('(saved; leave empty to keep)', 'mautic-ecommerce-woocommerce') : '',
            ],
            [
                'title'   => __('Store name', 'mautic-ecommerce-woocommerce'),
                'id'      => 'mautic_ecommerce_store',
                'type'    => 'text',
                'default' => 'woocommerce',
                'desc'    => __('Order source and catalog name in Mautic. Use a different name per shop if several shops send to one Mautic.', 'mautic-ecommerce-woocommerce'),
            ],
            [
                'title'   => __('Send orders and refunds', 'mautic-ecommerce-woocommerce'),
                'id'      => 'mautic_ecommerce_send_orders',
                'type'    => 'checkbox',
                'default' => 'yes',
            ],
            [
                'title'   => __('Send products', 'mautic-ecommerce-woocommerce'),
                'id'      => 'mautic_ecommerce_send_products',
                'type'    => 'checkbox',
                'default' => 'yes',
            ],
            [
                'type' => 'sectionend',
                'id'   => 'mautic_ecommerce_section',
            ],
        ];
    }
}

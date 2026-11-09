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

    public function isEnabled(string $what, string $default = 'yes'): bool
    {
        return 'yes' === get_option('mautic_ecommerce_send_'.$what, $default);
    }

    public function getConsentMode(): string
    {
        return StorefrontEvents::CONSENT_ALWAYS === get_option('mautic_ecommerce_events_consent', StorefrontEvents::CONSENT_API)
            ? StorefrontEvents::CONSENT_ALWAYS
            : StorefrontEvents::CONSENT_API;
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
                'title'   => __('Weekly full product sync', 'mautic-ecommerce-woocommerce'),
                'desc'    => __('Send all products once a week, at night, in case Mautic missed a change.', 'mautic-ecommerce-woocommerce'),
                'id'      => WeeklySync::OPTION,
                'type'    => 'checkbox',
                'default' => 'yes',
            ],
            [
                'title'   => __('Allow Mautic to create coupons', 'mautic-ecommerce-woocommerce'),
                'desc'    => sprintf(
                    /* translators: %s: URL */
                    __('For Mautic\'s "Issue coupon" campaign action: single-use coupons for one email address. In Mautic, set the shop coupon endpoint to %s', 'mautic-ecommerce-woocommerce'),
                    '<code>'.esc_html(rest_url(CouponEndpoint::NAMESPACE.CouponEndpoint::ROUTE)).'</code>'
                ),
                'id'      => CouponEndpoint::OPTION,
                'type'    => 'checkbox',
                'default' => 'no',
            ],
            [
                'title'   => __('Send storefront events', 'mautic-ecommerce-woocommerce'),
                'id'      => 'mautic_ecommerce_send_events',
                'type'    => 'checkbox',
                'default' => 'no',
                'desc'    => __('Product views, cart changes and checkout starts of shoppers whose email is known, for abandoned cart and browse campaigns. Also switch on "Record storefront events" in Mautic.', 'mautic-ecommerce-woocommerce'),
            ],
            [
                'title'   => __('Consent for tracking', 'mautic-ecommerce-woocommerce'),
                'id'      => 'mautic_ecommerce_events_consent',
                'type'    => 'select',
                'default' => StorefrontEvents::CONSENT_API,
                'options' => [
                    StorefrontEvents::CONSENT_API    => __('Only with marketing consent from the WP Consent API (Complianz, CookieYes, …)', 'mautic-ecommerce-woocommerce'),
                    StorefrontEvents::CONSENT_ALWAYS => __('Always: this shop handles consent another way', 'mautic-ecommerce-woocommerce'),
                ],
                'desc'    => __('Applies to storefront events, the popup for anonymous visitors, and remembering which Mautic email a shopper came from (to credit the email with the order). Do Not Track and Global Privacy Control always stop all three.', 'mautic-ecommerce-woocommerce'),
            ],
            [
                'type' => 'sectionend',
                'id'   => 'mautic_ecommerce_section',
            ],
            [
                'title' => __('Popup for anonymous visitors', 'mautic-ecommerce-woocommerce'),
                'type'  => 'title',
                'desc'  => __('Shows a Mautic focus item, for example a sign-up form, to visitors the shop does not know once they viewed several different products. Never shown to logged-in customers, guests who entered their email at checkout or placed an order, or visitors from a Mautic email link; the shop remembers them with a cookie that holds no personal data. Follows the consent setting above. In Mautic, set when and how often it appears, and tick "Stop after conversion" so a sign-up ends it.', 'mautic-ecommerce-woocommerce'),
                'id'    => 'mautic_ecommerce_focus_section',
            ],
            [
                'title'             => __('Mautic focus item ID', 'mautic-ecommerce-woocommerce'),
                'id'                => FocusPrompt::OPTION_FOCUS,
                'type'              => 'number',
                'default'           => '',
                'placeholder'       => __('Off', 'mautic-ecommerce-woocommerce'),
                'custom_attributes' => ['min' => '0', 'step' => '1'],
                'desc'              => __('The number in the focus item\'s address in Mautic (Channels → Focus Items). Empty: off.', 'mautic-ecommerce-woocommerce'),
            ],
            [
                'title'             => __('Show after viewing', 'mautic-ecommerce-woocommerce'),
                'id'                => FocusPrompt::OPTION_VIEWS,
                'type'              => 'number',
                'default'           => (string) FocusPrompt::DEFAULT_VIEWS,
                'custom_attributes' => ['min' => '1', 'max' => '50', 'step' => '1'],
                'desc'              => __('different products within 24 hours', 'mautic-ecommerce-woocommerce'),
            ],
            [
                'type' => 'sectionend',
                'id'   => 'mautic_ecommerce_focus_section',
            ],
            [
                'title' => __('Connection and sync', 'mautic-ecommerce-woocommerce'),
                'type'  => 'title',
                'id'    => 'mautic_ecommerce_tools_section',
            ],
            // No "id": WooCommerce saves nothing for it; AdminTools renders the buttons.
            ['type' => AdminTools::FIELD_TYPE],
            [
                'type' => 'sectionend',
                'id'   => 'mautic_ecommerce_tools_section',
            ],
        ];
    }
}

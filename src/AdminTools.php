<?php

declare(strict_types=1);

namespace MauticEcommerceWooCommerce;

/**
 * "Test connection" and "Sync now" on WooCommerce > Settings > Mautic.
 *
 * The buttons sit inside WooCommerce's settings form and post to admin-post.php (formaction),
 * so they work without saving the form; each action checks a nonce and manage_woocommerce.
 */
final class AdminTools
{
    public const FIELD_TYPE = 'mautic_ecommerce_tools';

    private const NONCE       = 'mautic_ecommerce_tools';
    private const NONCE_FIELD = 'mautic_ecommerce_tools_nonce';
    private const NOTICE      = 'mautic_ecommerce_notice_';

    public function __construct(
        private Settings $settings,
        private Client $client,
        private BulkSync $bulkSync,
    ) {
    }

    public function register(): void
    {
        add_action('woocommerce_admin_field_'.self::FIELD_TYPE, [$this, 'render']);
        add_action('admin_post_mautic_ecommerce_test', [$this, 'handleTest']);
        add_action('admin_post_mautic_ecommerce_sync', [$this, 'handleSync']);
        add_action('admin_notices', [$this, 'showNotice']);
    }

    public function render(): void
    {
        $action = admin_url('admin-post.php');
        ?>
        <tr>
            <th scope="row"><?php esc_html_e('Connection', 'mautic-ecommerce-woocommerce'); ?></th>
            <td>
                <?php wp_nonce_field(self::NONCE, self::NONCE_FIELD); ?>
                <button type="submit" class="button" formmethod="post" formnovalidate
                        formaction="<?php echo esc_url(add_query_arg('action', 'mautic_ecommerce_test', $action)); ?>">
                    <?php esc_html_e('Test connection', 'mautic-ecommerce-woocommerce'); ?>
                </button>
                <p class="description"><?php esc_html_e('Checks the saved Mautic URL and webhook secret. Save changes first.', 'mautic-ecommerce-woocommerce'); ?></p>
            </td>
        </tr>
        <tr>
            <th scope="row"><?php esc_html_e('Sync now', 'mautic-ecommerce-woocommerce'); ?></th>
            <td>
                <p>
                    <button type="submit" class="button" name="mautic_ecommerce_sync_type" value="products" formmethod="post" formnovalidate
                            formaction="<?php echo esc_url(add_query_arg('action', 'mautic_ecommerce_sync', $action)); ?>">
                        <?php esc_html_e('Send all products', 'mautic-ecommerce-woocommerce'); ?>
                    </button>
                    <?php echo wp_kses_post($this->describe('products')); ?>
                </p>
                <p>
                    <label>
                        <?php esc_html_e('Orders since', 'mautic-ecommerce-woocommerce'); ?>
                        <input type="date" name="mautic_ecommerce_sync_since" value="">
                    </label>
                    <button type="submit" class="button" name="mautic_ecommerce_sync_type" value="orders" formmethod="post" formnovalidate
                            formaction="<?php echo esc_url(add_query_arg('action', 'mautic_ecommerce_sync', $action)); ?>">
                        <?php esc_html_e('Send past orders', 'mautic-ecommerce-woocommerce'); ?>
                    </button>
                    <?php echo wp_kses_post($this->describe('orders')); ?>
                </p>
                <p class="description"><?php esc_html_e('Runs in the background (Tools > Scheduled Actions, group mautic-ecommerce); reload this page to see the progress. Leave the date empty for all orders. Mautic triggers no campaigns for orders older than a day, so this sends no emails.', 'mautic-ecommerce-woocommerce'); ?></p>
            </td>
        </tr>
        <?php
    }

    public function handleTest(): void
    {
        $this->authorize();

        $result  = $this->client->ping();
        $message = $result['message'];

        if ($result['ok'] && $this->settings->isEnabled('events', 'no') && false === ($result['storefront_events'] ?? null)) {
            $message .= ' '.__('Storefront events are on here but "Record storefront events" is off in Mautic, so Mautic ignores them.', 'mautic-ecommerce-woocommerce');
        }

        $this->redirectWithNotice($result['ok'] ? 'success' : 'error', $message);
    }

    public function handleSync(): void
    {
        $this->authorize();

        $type  = sanitize_key(wp_unslash((string) ($_POST['mautic_ecommerce_sync_type'] ?? '')));
        $since = sanitize_text_field(wp_unslash((string) ($_POST['mautic_ecommerce_sync_since'] ?? '')));

        if (!in_array($type, BulkSync::TYPES, true)) {
            $this->redirectWithNotice('error', __('Unknown sync.', 'mautic-ecommerce-woocommerce'));
        }

        if (!$this->settings->isConfigured()) {
            $this->redirectWithNotice('error', __('Enter the Mautic URL and webhook secret and save first.', 'mautic-ecommerce-woocommerce'));
        }

        if ('' !== $since && 1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', $since)) {
            $this->redirectWithNotice('error', __('Use a date like 2025-01-31.', 'mautic-ecommerce-woocommerce'));
        }

        $started = $this->bulkSync->start($type, '' !== $since ? $since : null);

        $this->redirectWithNotice(
            $started ? 'success' : 'warning',
            $started
                ? __('Sync started in the background.', 'mautic-ecommerce-woocommerce')
                : __('This sync is already running.', 'mautic-ecommerce-woocommerce')
        );
    }

    public function showNotice(): void
    {
        $key    = self::NOTICE.get_current_user_id();
        $notice = get_transient($key);
        if (!is_array($notice)) {
            return;
        }

        delete_transient($key);
        printf(
            '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
            esc_attr((string) $notice['type']),
            esc_html((string) $notice['message'])
        );
    }

    /**
     * One line about the last or current sync of this type, as HTML.
     */
    public function describe(string $type): string
    {
        $state = $this->bulkSync->state($type);
        $sent  = (int) ($state['sent'] ?? 0);

        return match ($state['status'] ?? null) {
            BulkSync::RUNNING => $this->bulkSync->isStale($state)
                /* translators: %d: number sent */
                ? sprintf(__('Stalled after %d sent; check that WP-Cron runs, or start again.', 'mautic-ecommerce-woocommerce'), $sent)
                /* translators: %d: number sent */
                : sprintf(__('Running: %d sent so far.', 'mautic-ecommerce-woocommerce'), $sent),
            BulkSync::DONE => sprintf(
                /* translators: 1: number sent, 2: date and time */
                __('Last sync: %1$d sent, finished %2$s.', 'mautic-ecommerce-woocommerce'),
                $sent,
                esc_html(wp_date(get_option('date_format').' '.get_option('time_format'), (int) ($state['updated_at'] ?? 0)))
            ),
            BulkSync::FAILED => sprintf(
                /* translators: 1: number sent, 2: error message */
                __('Stopped after %1$d sent: %2$s Start again to resume.', 'mautic-ecommerce-woocommerce'),
                $sent,
                esc_html((string) ($state['error'] ?? ''))
            ),
            default => '',
        };
    }

    private function authorize(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You are not allowed to do this.', 'mautic-ecommerce-woocommerce'), 403);
        }

        check_admin_referer(self::NONCE, self::NONCE_FIELD);
    }

    /**
     * @return never
     */
    private function redirectWithNotice(string $type, string $message): void
    {
        set_transient(self::NOTICE.get_current_user_id(), ['type' => $type, 'message' => $message], MINUTE_IN_SECONDS);
        wp_safe_redirect(admin_url('admin.php?page=wc-settings&tab='.Settings::TAB));
        exit;
    }
}

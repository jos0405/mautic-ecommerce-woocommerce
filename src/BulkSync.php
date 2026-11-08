<?php

declare(strict_types=1);

namespace MauticEcommerceWooCommerce;

/**
 * "Sync now" from the settings page: sends all products or past orders in the background, one
 * page per Action Scheduler job, so a large shop never hits a request timeout. Progress is kept
 * in an option and shown on the settings page. A sync that failed resumes from the failed page.
 */
final class BulkSync
{
    public const HOOK   = 'mautic_ecommerce_sync_batch';
    public const OPTION = 'mautic_ecommerce_sync_state';
    public const TYPES  = ['products', 'orders'];

    public const RUNNING = 'running';
    public const DONE    = 'done';
    public const FAILED  = 'failed';

    /**
     * A running sync whose last batch is older than this is treated as stuck and may be restarted.
     */
    public const STALE_AFTER = 15 * MINUTE_IN_SECONDS;

    public function __construct(
        private Client $client,
        private ProductSync $products,
        private OrderSync $orders,
    ) {
    }

    public function register(): void
    {
        add_action(self::HOOK, [$this, 'runBatch']);
    }

    /**
     * @param string|null $since for orders: YYYY-MM-DD, or null for all
     *
     * @return bool false when a sync of this type is already running
     */
    public function start(string $type, ?string $since = null): bool
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException('Unknown sync type '.$type);
        }

        $since    = 'orders' === $type ? $since : null;
        $previous = $this->state($type);

        if (self::RUNNING === ($previous['status'] ?? null) && !$this->isStale($previous)) {
            return false;
        }

        // Resume a failed sync of the same scope where it stopped; otherwise start over.
        $resume = self::FAILED === ($previous['status'] ?? null) && ($previous['since'] ?? null) === $since;

        $this->saveState($type, [
            'status'     => self::RUNNING,
            'since'      => $since,
            'page'       => $resume ? (int) $previous['page'] : 1,
            'sent'       => $resume ? (int) $previous['sent'] : 0,
            'started_at' => $resume ? (int) $previous['started_at'] : time(),
            'updated_at' => time(),
            'error'      => null,
        ]);

        $this->schedule($type);

        return true;
    }

    /**
     * One page; schedules the next one until a short page shows the end.
     */
    public function runBatch(string $type): void
    {
        $state = $this->state($type);
        if (self::RUNNING !== ($state['status'] ?? null)) {
            return;
        }

        $page = (int) $state['page'];

        try {
            $batch = 'products' === $type
                ? $this->products->syncPage($this->client, $page)
                : $this->orders->syncPage($this->client, $state['since'] ?? null, $page);
        } catch (\RuntimeException $exception) {
            $this->saveState($type, ['status' => self::FAILED, 'error' => $exception->getMessage(), 'updated_at' => time()] + $state);

            return;
        }

        $state['sent'] = (int) $state['sent'] + $batch['sent'];
        $state['updated_at'] = time();

        $pageSize = 'products' === $type ? ProductSync::BATCH_SIZE : OrderSync::BATCH_SIZE;
        if ($batch['fetched'] < $pageSize) {
            $state['status'] = self::DONE;
            $this->saveState($type, $state);

            return;
        }

        $state['page'] = $page + 1;
        $this->saveState($type, $state);
        $this->schedule($type);
    }

    /**
     * @return array{status?: string, since?: string|null, page?: int, sent?: int, started_at?: int, updated_at?: int, error?: string|null}
     */
    public function state(string $type): array
    {
        $all = get_option(self::OPTION, []);

        return is_array($all) && is_array($all[$type] ?? null) ? $all[$type] : [];
    }

    /**
     * @param array<string, mixed> $state
     */
    public function isStale(array $state): bool
    {
        return (int) ($state['updated_at'] ?? 0) < time() - self::STALE_AFTER;
    }

    private function schedule(string $type): void
    {
        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action(self::HOOK, [$type], Queue::GROUP);

            return;
        }

        // Without Action Scheduler (not expected with WooCommerce 8+), run inline.
        $this->runBatch($type);
    }

    /**
     * @param array<string, mixed> $state
     */
    private function saveState(string $type, array $state): void
    {
        $all = get_option(self::OPTION, []);
        $all = is_array($all) ? $all : [];
        $all[$type] = $state;

        update_option(self::OPTION, $all, false);
    }
}

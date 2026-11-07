<?php

declare(strict_types=1);

namespace MauticEcommerceWooCommerce;

/**
 * Sends in the background with WooCommerce's Action Scheduler, so checkout and product saves
 * never wait for Mautic. A failed send throws; Action Scheduler logs it and the next change
 * sends the current state again.
 */
final class Queue
{
    public const HOOK  = 'mautic_ecommerce_send';
    public const GROUP = 'mautic-ecommerce';

    public function __construct(
        private Client $client,
    ) {
    }

    public function register(): void
    {
        add_action(self::HOOK, [$this, 'process'], 10, 2);
    }

    /**
     * Payloads are built when the job runs (see OrderSync, ProductSync), so the latest state
     * is sent and duplicate jobs for the same object collapse.
     *
     * @param array<string, scalar> $args
     */
    public function push(string $type, array $args): void
    {
        $job = [$type, $args];

        if (function_exists('as_has_scheduled_action') && as_has_scheduled_action(self::HOOK, $job, self::GROUP)) {
            return;
        }

        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action(self::HOOK, $job, self::GROUP);

            return;
        }

        $this->process($type, $args);
    }

    /**
     * Runs once, $delay seconds from now; a job already waiting for the same arguments is kept.
     *
     * @param array<string, scalar> $args
     */
    public function pushLater(string $type, array $args, int $delay): void
    {
        $job = [$type, $args];

        if (!function_exists('as_schedule_single_action')) {
            $this->process($type, $args);

            return;
        }

        if (!as_has_scheduled_action(self::HOOK, $job, self::GROUP)) {
            as_schedule_single_action(time() + $delay, self::HOOK, $job, self::GROUP);
        }
    }

    /**
     * @param array<string, scalar> $args
     */
    public function process(string $type, array $args): void
    {
        $payload = apply_filters('mautic_ecommerce_build_'.$type, null, $args);
        if (!is_array($payload)) {
            return;
        }

        $this->client->send($payload['_endpoint'], array_diff_key($payload, ['_endpoint' => true]));
    }
}

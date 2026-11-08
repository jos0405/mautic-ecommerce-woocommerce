<?php

declare(strict_types=1);

namespace MauticEcommerceWooCommerce;

/**
 * A full product sync once a week, as a safety net for product changes Mautic missed (Mautic
 * down, wrong secret, a change that bypassed WooCommerce's hooks). It runs the same background
 * sync as "Send all products".
 *
 * The time is picked once per site, at night (01:00 to 05:00 site time) on a random weekday,
 * so shops sharing one Mautic do not all sync at once.
 */
final class WeeklySync
{
    public const HOOK   = 'mautic_ecommerce_weekly_sync';
    public const OPTION = 'mautic_ecommerce_weekly_sync';

    public function __construct(
        private Settings $settings,
        private BulkSync $bulkSync,
    ) {
    }

    public function register(): void
    {
        add_action(self::HOOK, [$this, 'run']);
        // Checked in the admin only: no extra query on shop pages.
        add_action('admin_init', [$this, 'ensureSchedule']);
    }

    public static function isWanted(Settings $settings): bool
    {
        return $settings->isConfigured()
            && $settings->isEnabled('products')
            && 'yes' === get_option(self::OPTION, 'yes');
    }

    /**
     * Schedules the weekly action when wanted, removes it when not.
     */
    public function ensureSchedule(): void
    {
        if (!function_exists('as_has_scheduled_action')) {
            return;
        }

        $scheduled = as_has_scheduled_action(self::HOOK, [], Queue::GROUP);

        if (!self::isWanted($this->settings)) {
            if ($scheduled) {
                self::unschedule();
            }

            return;
        }

        if (!$scheduled) {
            as_schedule_recurring_action(self::firstRun(), WEEK_IN_SECONDS, self::HOOK, [], Queue::GROUP, true);
        }
    }

    public function run(): void
    {
        // Settings may have changed since the action was scheduled.
        if (!self::isWanted($this->settings)) {
            return;
        }

        // Does nothing while a sync is running; resumes a failed one.
        $this->bulkSync->start('products');
    }

    /**
     * @return int|null Unix time of the next weekly sync, or null when none is scheduled
     */
    public static function nextRun(): ?int
    {
        if (!function_exists('as_next_scheduled_action')) {
            return null;
        }

        $next = as_next_scheduled_action(self::HOOK, [], Queue::GROUP);

        return is_int($next) ? $next : null;
    }

    public static function unschedule(): void
    {
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions(self::HOOK, [], Queue::GROUP);
        }
    }

    /**
     * The next occurrence of this site's weekday and night hour, at least an hour from now.
     */
    public static function firstRun(?int $now = null): int
    {
        $now  = $now ?? time();
        $zone = wp_timezone();

        $day    = wp_rand(0, 6);
        $minute = wp_rand(60, 299); // 01:00 to 04:59

        $start = (new \DateTimeImmutable('@'.$now))->setTimezone($zone)->setTime(0, 0);
        for ($offset = 0; $offset <= 7; ++$offset) {
            $candidate = $start->modify(sprintf('+%d days', $offset))->modify(sprintf('+%d minutes', $minute));
            if ((int) $candidate->format('w') === $day && $candidate->getTimestamp() > $now + HOUR_IN_SECONDS) {
                return $candidate->getTimestamp();
            }
        }

        return $start->modify('+7 days')->modify(sprintf('+%d minutes', $minute))->getTimestamp();
    }
}

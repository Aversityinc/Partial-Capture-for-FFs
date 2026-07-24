<?php

namespace BetterFCF\Partials;

use BetterFCF\Settings;
use BetterFCF\Webhooks\Dispatcher;

if (! defined('ABSPATH')) {
    exit;
}

class Abandonment
{
    const HOOK = 'bfcf_sweep';

    const SCHEDULE = 'bfcf_five_minutes';

    public function register()
    {
        add_filter('cron_schedules', [$this, 'addSchedule']);
        add_action('init', [$this, 'schedule']);
        add_action(self::HOOK, [$this, 'sweep']);
    }

    public function addSchedule($schedules)
    {
        $schedules[self::SCHEDULE] = [
            'interval' => 5 * MINUTE_IN_SECONDS,
            'display'  => __('Every five minutes (Better FCFs)', 'better-fcfs'),
        ];

        return $schedules;
    }

    public function schedule()
    {
        if (! wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + MINUTE_IN_SECONDS, self::SCHEDULE, self::HOOK);
        }
    }

    public static function unschedule()
    {
        $next = wp_next_scheduled(self::HOOK);

        if ($next) {
            wp_unschedule_event($next, self::HOOK);
        }
    }

    /**
     * Two queues, one clock.
     *
     * Step sends wait out the grace window here rather than firing from the
     * browser: if the visitor converted or answered again in the meantime, there
     * is nothing left to send. Abandonment stays the long-inactivity fallback.
     *
     * Both passes re-check the conversion flag before queueing anything — a row
     * can slip past link()'s deletes when its save was in flight during the
     * submission, and this is where such a straggler finally dies.
     */
    public function sweep()
    {
        foreach (Repository::due() as $row) {
            if (Conversion::hasConverted($row->form_id, $row->session_hash)) {
                Repository::delete($row->id, $row->form_id);
                continue;
            }

            // Clear before queueing: a failed send is visible in the logs and can
            // be resent by hand, but it must not retry on every sweep forever.
            Repository::clearDispatch($row->id);
            Dispatcher::queue(Dispatcher::TRIGGER_STEP, $row->id);
        }

        $minutes = (int) Settings::get('abandon_after_minutes');

        foreach (Repository::stale($minutes) as $row) {
            if (Conversion::hasConverted($row->form_id, $row->session_hash)) {
                Repository::delete($row->id, $row->form_id);
                continue;
            }

            Repository::markAbandoned($row->id);
            Dispatcher::queue(Dispatcher::TRIGGER_ABANDONED, $row->id);
        }

        Repository::purge((int) Settings::get('retention_days'));
    }
}

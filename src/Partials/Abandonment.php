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
     * A partial goes quiet and never converts => it's an abandoned lead.
     *
     * The grace period matters: fire too eagerly and you spam the CRM about
     * someone who is simply reading the next question.
     */
    public function sweep()
    {
        $minutes = (int) Settings::get('abandon_after_minutes');

        foreach (Repository::stale($minutes) as $row) {
            Repository::markAbandoned($row->id);
            Dispatcher::queue(Dispatcher::TRIGGER_ABANDONED, $row->id);
        }

        Repository::purge((int) Settings::get('retention_days'));
    }
}

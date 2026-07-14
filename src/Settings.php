<?php

namespace BetterFCF;

if (! defined('ABSPATH')) {
    exit;
}

class Settings
{
    const OPTION = 'bfcf_settings';

    /** The Vue app fires one save per question advance. Faster than this is not a person. */
    const RATE_LIMIT_PER_MINUTE = 60;

    public static function defaults()
    {
        return [
            'abandon_after_minutes' => 15,
            'retention_days'        => 90,
        ];
    }

    public static function all()
    {
        return wp_parse_args((array) get_option(self::OPTION, []), self::defaults());
    }

    public static function get($key)
    {
        $all = self::all();

        return $all[$key] ?? null;
    }

    public static function save(array $values)
    {
        update_option(self::OPTION, [
            'abandon_after_minutes' => max(1, (int) ($values['abandon_after_minutes'] ?? 15)),
            'retention_days'        => max(1, (int) ($values['retention_days'] ?? 90)),
        ], false);
    }
}

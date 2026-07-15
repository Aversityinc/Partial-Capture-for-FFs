<?php

namespace BetterFCF;

if (! defined('ABSPATH')) {
    exit;
}

class Settings
{
    const OPTION = 'bfcf_settings';

    /**
     * We store on every question advance past a checkpoint, so the ceiling has to
     * clear a fast typist on a long form. Two per second is still well beyond a human.
     */
    const RATE_LIMIT_PER_MINUTE = 120;

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

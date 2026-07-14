<?php

namespace BetterFCF\Database;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Partial rows live in our own tables, never in fluentform_submissions:
 * that table computes serial_number non-atomically from the previous max, and
 * Submission::countByStatuses() subtracts only 'trashed' from 'all', so a custom
 * status would both perturb real serials and inflate the admin Entries badge.
 */
class Migrator
{
    const DB_VERSION = 1;

    const OPTION_KEY = 'bfcf_db_version';

    public static function partialsTable()
    {
        global $wpdb;
        return $wpdb->prefix . 'bfcf_partials';
    }

    public static function logsTable()
    {
        global $wpdb;
        return $wpdb->prefix . 'bfcf_webhook_logs';
    }

    /**
     * Safe to call on every request; it no-ops once the version matches. Fluent
     * Forms itself does this (DraftSubmissionsManager::migrate) because an addon
     * can be updated without ever hitting register_activation_hook again.
     */
    public static function maybeMigrate()
    {
        if ((int) get_option(self::OPTION_KEY) === self::DB_VERSION) {
            return;
        }
        self::migrate();
    }

    public static function migrate()
    {
        global $wpdb;

        $charset = $wpdb->get_charset_collate();
        $partials = self::partialsTable();
        $logs = self::logsTable();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta("CREATE TABLE {$partials} (
            `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            `form_id` INT UNSIGNED NOT NULL,
            `session_hash` VARCHAR(64) NOT NULL,
            `response` LONGTEXT NULL,
            `checkpoint` VARCHAR(191) NULL,
            `max_step` INT UNSIGNED NOT NULL DEFAULT 0,
            `total_steps` INT UNSIGNED NOT NULL DEFAULT 0,
            `last_field` VARCHAR(191) NULL,
            `active_seconds` INT UNSIGNED NOT NULL DEFAULT 0,
            `status` VARCHAR(20) NOT NULL DEFAULT 'active',
            `submission_id` BIGINT(20) UNSIGNED NULL,
            `converted_at` TIMESTAMP NULL,
            `source_url` TEXT NULL,
            `referrer` TEXT NULL,
            `utm` TEXT NULL,
            `ip` VARCHAR(45) NULL,
            `browser` VARCHAR(45) NULL,
            `device` VARCHAR(45) NULL,
            `user_id` BIGINT(20) UNSIGNED NULL,
            `started_at` TIMESTAMP NULL,
            `last_activity_at` TIMESTAMP NULL,
            `created_at` TIMESTAMP NULL,
            `updated_at` TIMESTAMP NULL,
            PRIMARY KEY  (`id`),
            UNIQUE KEY `bfcf_session` (`form_id`, `session_hash`),
            KEY `bfcf_sweep` (`status`, `last_activity_at`)
        ) {$charset};");

        dbDelta("CREATE TABLE {$logs} (
            `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            `partial_id` BIGINT(20) UNSIGNED NULL,
            `feed_id` BIGINT(20) UNSIGNED NULL,
            `form_id` INT UNSIGNED NULL,
            `trigger_type` VARCHAR(30) NULL,
            `url` TEXT NULL,
            `request` LONGTEXT NULL,
            `response_code` INT NULL,
            `response_body` LONGTEXT NULL,
            `status` VARCHAR(20) NULL,
            `created_at` TIMESTAMP NULL,
            PRIMARY KEY  (`id`),
            KEY `bfcf_partial` (`partial_id`),
            KEY `bfcf_form` (`form_id`)
        ) {$charset};");

        update_option(self::OPTION_KEY, self::DB_VERSION, false);
    }
}

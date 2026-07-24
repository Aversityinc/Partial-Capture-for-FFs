<?php

namespace BetterFCF\Webhooks;

use BetterFCF\Conditions;
use BetterFCF\Database\Migrator;
use BetterFCF\Partials\Repository;
use BetterFCF\Support\Form;
use FluentForm\App\Services\FormBuilder\ShortCodeParser;
use FluentForm\Framework\Helpers\ArrayHelper;

if (! defined('ABSPATH')) {
    exit;
}

class Dispatcher
{
    const TRIGGER_STEP = 'step';

    const TRIGGER_ABANDONED = 'abandoned';

    /** @var array<int,array{0:string,1:int}> */
    private static $queue = [];

    /**
     * Real-time means "the CRM sees the phone number seconds after they type it",
     * not "the visitor waits for the CRM before seeing the next question". Deferring
     * to shutdown gets us the first without paying for the second.
     */
    public static function queue($trigger, $partialId)
    {
        if (! self::$queue) {
            add_action('shutdown', [self::class, 'flush'], 99);
        }

        self::$queue[] = [$trigger, (int) $partialId];
    }

    public static function flush()
    {
        $queue = self::$queue;
        self::$queue = [];

        foreach ($queue as $item) {
            try {
                self::dispatch($item[0], $item[1]);
            } catch (\Throwable $e) {
                // A broken endpoint must never take the page down with it.
                error_log('[Better FCFs] dispatch failed: ' . $e->getMessage());
            }
        }
    }

    public static function dispatch($trigger, $partialId)
    {
        // The queue is flushed on shutdown / from cron, so re-read fresh: a row
        // deleted by a conversion in the meantime bails right here, and the
        // status check below catches pre-v2 leftovers that were flagged
        // 'converted' instead of deleted.
        $partial = Repository::find($partialId);
        if (! $partial) {
            return;
        }

        if ($partial->status === Repository::CONVERTED) {
            return;
        }

        $form = Form::load($partial->form_id);
        if (! $form) {
            return;
        }

        $data = Repository::response($partial);

        foreach (self::feeds($partial->form_id) as $feed) {
            $settings = $feed['settings'];

            if (empty($settings['enabled'])) {
                continue;
            }
            if (ArrayHelper::get($settings, 'bfcf_trigger') !== $trigger) {
                continue;
            }
            if (! self::passesFieldGates($settings, $data)) {
                continue;
            }
            if (! Conditions::evaluate(ArrayHelper::get($settings, 'conditionals'), $data)) {
                continue;
            }
            if (! empty($settings['bfcf_once']) && self::alreadySent($partial->id, $feed['id'])) {
                continue;
            }

            self::send($feed, $partial, $form, $data, $trigger);
        }
    }

    /**
     * The explicit answer to "fire only if these fields exist / don't exist". Fluent
     * Forms' conditional_block has no empty/exists operator to express this, so it
     * gets its own pair of field pickers on the feed.
     */
    private static function passesFieldGates(array $settings, array $data)
    {
        foreach ((array) ArrayHelper::get($settings, 'bfcf_require_filled', []) as $name) {
            if (Conditions::isEmpty(ArrayHelper::get($data, $name))) {
                return false;
            }
        }

        foreach ((array) ArrayHelper::get($settings, 'bfcf_require_empty', []) as $name) {
            if (! Conditions::isEmpty(ArrayHelper::get($data, $name))) {
                return false;
            }
        }

        return true;
    }

    public static function feeds($formId)
    {
        $rows = wpFluent()->table('fluentform_form_meta')
            ->where('form_id', $formId)
            ->where('meta_key', WebhookIntegration::SETTINGS_KEY)
            ->get();

        $feeds = [];

        foreach ($rows as $row) {
            $settings = json_decode($row->value, true);

            if (is_array($settings)) {
                $feeds[] = ['id' => (int) $row->id, 'settings' => $settings];
            }
        }

        return $feeds;
    }

    private static function send(array $feed, $partial, $form, array $data, $trigger)
    {
        $settings = $feed['settings'];

        // Rules are matched against raw answers; they must never be smart-code parsed.
        unset($settings['conditionals']);

        // Ours first: ShortCodeParser doesn't know {bfcf.*} and would blank them out.
        $settings = self::applyPartialCodes($settings, $partial, $trigger);

        try {
            ShortCodeParser::resetData();
            $processed = ShortCodeParser::parse($settings, null, $data, $form);
        } catch (\Throwable $e) {
            $processed = $settings;
        }

        // Static singleton with a cache it never invalidates between forms.
        ShortCodeParser::resetData();

        $url = trim((string) ArrayHelper::get($processed, 'request_url'));
        if (! $url) {
            return;
        }

        $method = strtoupper((string) ArrayHelper::get($processed, 'request_method', 'POST')) ?: 'POST';
        $format = strtoupper((string) ArrayHelper::get($processed, 'request_format', 'JSON')) ?: 'JSON';
        $body = self::payload($processed);
        $headers = self::headers($processed);

        $args = [
            'method'  => $method,
            'timeout' => 10,
            'headers' => $headers,
        ];

        if (in_array($method, ['GET', 'DELETE'], true)) {
            $url = add_query_arg(array_map('strval', array_filter($body, 'is_scalar')), $url);
        } elseif ($format === 'JSON') {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = wp_json_encode($body);
        } else {
            $args['body'] = $body;
        }

        $args = apply_filters('better_fcfs/webhook_request_args', $args, $feed, $partial, $form);

        $response = wp_remote_request($url, $args);

        self::log($partial, $feed, $trigger, $url, $args, $response);
    }

    private static function payload(array $processed)
    {
        $body = [];

        foreach ((array) ArrayHelper::get($processed, 'fields', []) as $row) {
            $key = trim((string) ArrayHelper::get($row, 'label'));
            if ($key === '') {
                continue;
            }

            $value = ArrayHelper::get($row, 'item_value');

            // Same convention as Fluent Forms' own webhook: a trailing [] collects.
            if (substr($key, -2) === '[]') {
                $body[substr($key, 0, -2)][] = $value;
                continue;
            }

            $body[$key] = $value;
        }

        return $body;
    }

    private static function headers(array $processed)
    {
        $headers = [];
        $raw = (string) ArrayHelper::get($processed, 'request_headers');

        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            if (strpos($line, ':') === false) {
                continue;
            }

            list($name, $value) = explode(':', $line, 2);
            $name = trim($name);

            if ($name !== '') {
                $headers[$name] = trim($value);
            }
        }

        return $headers;
    }

    /**
     * Partial-specific smart codes, resolved by direct substitution rather than
     * through ShortCodeParser's group filter — that keeps them working identically
     * across Fluent Forms versions.
     */
    private static function applyPartialCodes($value, $partial, $trigger)
    {
        $map = [
            '{bfcf.checkpoint}'  => (string) $partial->checkpoint,
            '{bfcf.status}'      => $trigger === self::TRIGGER_ABANDONED ? 'abandoned' : 'active',
            '{bfcf.step}'        => (string) $partial->max_step,
            '{bfcf.total_steps}' => (string) $partial->total_steps,
            '{bfcf.seconds}'     => (string) $partial->active_seconds,
            '{bfcf.partial_id}'  => (string) $partial->id,
            '{bfcf.form_id}'     => (string) $partial->form_id,
            '{bfcf.source_url}'  => (string) $partial->source_url,
            '{bfcf.referrer}'    => (string) $partial->referrer,
        ];

        foreach ((array) json_decode($partial->utm ?? '', true) as $key => $utmValue) {
            $map['{bfcf.' . $key . '}'] = (string) $utmValue;
        }

        return self::replaceDeep($value, $map);
    }

    private static function replaceDeep($value, array $map)
    {
        if (is_array($value)) {
            return array_map(function ($item) use ($map) {
                return self::replaceDeep($item, $map);
            }, $value);
        }

        if (is_string($value)) {
            return strtr($value, $map);
        }

        return $value;
    }

    private static function alreadySent($partialId, $feedId)
    {
        global $wpdb;
        $table = Migrator::logsTable();

        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE partial_id = %d AND feed_id = %d AND status = 'success'",
            $partialId,
            $feedId
        ));
    }

    private static function log($partial, array $feed, $trigger, $url, array $args, $response)
    {
        global $wpdb;

        $failed = is_wp_error($response);
        $code = $failed ? 0 : (int) wp_remote_retrieve_response_code($response);

        $wpdb->insert(Migrator::logsTable(), [
            'partial_id'    => $partial->id,
            'feed_id'       => $feed['id'],
            'form_id'       => $partial->form_id,
            'trigger_type'  => $trigger,
            'url'           => $url,
            'request'       => wp_json_encode($args),
            'response_code' => $code,
            'response_body' => $failed
                ? $response->get_error_message()
                : substr((string) wp_remote_retrieve_body($response), 0, 5000),
            'status'        => (! $failed && $code >= 200 && $code < 300) ? 'success' : 'failed',
            'created_at'    => current_time('mysql'),
        ]);
    }

    /**
     * Replays a logged request exactly as it went out the first time, rather than
     * rebuilding it — the answers may have moved on since, and a resend should send
     * what you're looking at.
     */
    public static function resend($logId)
    {
        global $wpdb;
        $table = Migrator::logsTable();

        $log = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $logId));
        if (! $log) {
            return false;
        }

        $args = json_decode($log->request, true);
        if (! is_array($args)) {
            return false;
        }

        $partial = Repository::find($log->partial_id);
        if (! $partial) {
            return false;
        }

        $response = wp_remote_request($log->url, $args);

        self::log(
            $partial,
            ['id' => (int) $log->feed_id],
            $log->trigger_type,
            $log->url,
            $args,
            $response
        );

        return ! is_wp_error($response);
    }

    public static function logsFor($partialId)
    {
        global $wpdb;
        $table = Migrator::logsTable();

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE partial_id = %d ORDER BY id DESC",
            $partialId
        ));
    }
}

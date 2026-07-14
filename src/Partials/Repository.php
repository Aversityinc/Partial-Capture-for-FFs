<?php

namespace BetterFCF\Partials;

use BetterFCF\Database\Migrator;

if (! defined('ABSPATH')) {
    exit;
}

class Repository
{
    const ACTIVE = 'active';
    const ABANDONED = 'abandoned';
    const CONVERTED = 'converted';

    public static function find($id)
    {
        global $wpdb;
        $table = Migrator::partialsTable();

        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id));
    }

    public static function findBySession($formId, $session)
    {
        global $wpdb;
        $table = Migrator::partialsTable();

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE form_id = %d AND session_hash = %s",
            $formId,
            $session
        ));
    }

    /**
     * @return array{id:int,created:bool}
     */
    public static function upsert($formId, $session, array $data)
    {
        global $wpdb;
        $table = Migrator::partialsTable();
        $now = current_time('mysql');

        $existing = self::findBySession($formId, $session);

        if ($existing) {
            // A converted row is terminal. A late beacon must not resurrect it
            // and hand it back to the abandonment sweep.
            if ($existing->status === self::CONVERTED) {
                return ['id' => (int) $existing->id, 'created' => false];
            }

            // Going back a question must not walk the recorded stage backwards —
            // how far they got is the thing you care about, not where they are now.
            $data['max_step'] = max((int) $existing->max_step, (int) ($data['max_step'] ?? 0));

            $wpdb->update(
                $table,
                array_merge($data, [
                    'status'           => self::ACTIVE,
                    'last_activity_at' => $now,
                    'updated_at'       => $now,
                ]),
                ['id' => $existing->id]
            );

            return ['id' => (int) $existing->id, 'created' => false];
        }

        $wpdb->insert($table, array_merge($data, [
            'form_id'          => $formId,
            'session_hash'     => $session,
            'status'           => self::ACTIVE,
            'started_at'       => $now,
            'last_activity_at' => $now,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]));

        return ['id' => (int) $wpdb->insert_id, 'created' => true];
    }

    public static function markConverted($id, $submissionId)
    {
        global $wpdb;
        $now = current_time('mysql');

        $wpdb->update(
            Migrator::partialsTable(),
            [
                'status'        => self::CONVERTED,
                'submission_id' => $submissionId,
                'converted_at'  => $now,
                'updated_at'    => $now,
            ],
            ['id' => $id]
        );
    }

    /**
     * Rows that have gone quiet and never converted. The cutoff is computed in
     * SQL against the same current_time('mysql') clock we write with, so there is
     * no timezone round-trip to get wrong.
     */
    public static function stale($minutes, $limit = 50)
    {
        global $wpdb;
        $table = Migrator::partialsTable();

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE status = %s AND last_activity_at < DATE_SUB(%s, INTERVAL %d MINUTE)
             ORDER BY last_activity_at ASC LIMIT %d",
            self::ACTIVE,
            current_time('mysql'),
            $minutes,
            $limit
        ));
    }

    public static function markAbandoned($id)
    {
        global $wpdb;

        $wpdb->update(
            Migrator::partialsTable(),
            ['status' => self::ABANDONED, 'updated_at' => current_time('mysql')],
            ['id' => $id, 'status' => self::ACTIVE]
        );
    }

    public static function purge($days)
    {
        global $wpdb;
        $partials = Migrator::partialsTable();
        $logs = Migrator::logsTable();

        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$partials} WHERE created_at < DATE_SUB(%s, INTERVAL %d DAY) LIMIT 500",
            current_time('mysql'),
            $days
        ));

        if (! $ids) {
            return 0;
        }

        $in = implode(',', array_map('intval', $ids));
        $wpdb->query("DELETE FROM {$logs} WHERE partial_id IN ({$in})");
        $wpdb->query("DELETE FROM {$partials} WHERE id IN ({$in})");

        return count($ids);
    }

    public static function response($row)
    {
        $decoded = json_decode($row->response ?? '', true);

        return is_array($decoded) ? $decoded : [];
    }

    public static function paginate($formId, $args = [])
    {
        global $wpdb;
        $table = Migrator::partialsTable();

        $perPage = max(1, min(100, (int) ($args['per_page'] ?? 20)));
        $offset = max(0, (int) ($args['offset'] ?? 0));
        $status = $args['status'] ?? '';

        if ($status) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table} WHERE form_id = %d AND status = %s ORDER BY id DESC LIMIT %d OFFSET %d",
                $formId,
                $status,
                $perPage,
                $offset
            ));
            $total = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE form_id = %d AND status = %s",
                $formId,
                $status
            ));
        } else {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table} WHERE form_id = %d ORDER BY id DESC LIMIT %d OFFSET %d",
                $formId,
                $perPage,
                $offset
            ));
            $total = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE form_id = %d",
                $formId
            ));
        }

        return ['rows' => $rows, 'total' => $total];
    }

    public static function delete($id, $formId)
    {
        global $wpdb;

        $wpdb->delete(Migrator::logsTable(), ['partial_id' => $id]);
        $wpdb->delete(Migrator::partialsTable(), ['id' => $id, 'form_id' => $formId]);
    }
}

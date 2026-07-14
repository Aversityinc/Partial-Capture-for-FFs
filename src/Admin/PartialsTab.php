<?php

namespace BetterFCF\Admin;

use BetterFCF\Partials\Repository;
use BetterFCF\Settings;
use BetterFCF\Webhooks\Dispatcher;
use FluentForm\App\Helpers\Helper;
use FluentForm\App\Modules\Acl\Acl;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * A tab alongside Editor / Settings / Entries, using the same pair of hooks Fluent
 * Forms' own conversational Design tab uses. Plain PHP — the form-editor SPA's routes
 * are compiled in and closed to third parties, but this outer menu is not.
 */
class PartialsTab
{
    const ROUTE = 'bfcf_partials';

    const CAP = 'fluentform_forms_manager';

    const PER_PAGE = 25;

    public function register()
    {
        add_filter('fluentform/form_admin_menu', [$this, 'addTab'], 10, 2);
        add_filter('fluentform/form_inner_route_permission_set', [$this, 'permission']);
        add_action('fluentform/form_application_view_' . self::ROUTE, [$this, 'render']);

        add_action('admin_post_bfcf_settings', [$this, 'saveSettings']);
        add_action('admin_post_bfcf_delete', [$this, 'deletePartial']);
        add_action('admin_post_bfcf_resend', [$this, 'resend']);
        add_action('admin_post_bfcf_export', [$this, 'export']);
    }

    public function addTab($menuItems, $formId)
    {
        if (! Helper::isConversionForm($formId) || ! Acl::hasPermission(self::CAP)) {
            return $menuItems;
        }

        $menuItems[self::ROUTE] = [
            'slug'  => self::ROUTE,
            'title' => __('Partial Leads', 'better-fcfs'),
            'url'   => $this->tabUrl($formId),
        ];

        return $menuItems;
    }

    public function permission($permissions)
    {
        $permissions[self::ROUTE] = self::CAP;

        return $permissions;
    }

    private function tabUrl($formId, $args = [])
    {
        return add_query_arg(array_merge([
            'page'    => 'fluent_forms',
            'form_id' => $formId,
            'route'   => self::ROUTE,
        ], $args), admin_url('admin.php'));
    }

    /**
     * human_time_diff() rounds anything under a minute up to "1 min", which is exactly
     * the range that matters when you're deciding whether a visitor was engaged.
     */
    public static function duration($seconds)
    {
        $seconds = max(0, (int) $seconds);

        if ($seconds < 60) {
            return sprintf(_n('%d sec', '%d secs', $seconds, 'better-fcfs'), $seconds);
        }

        return sprintf('%dm %ds', intdiv($seconds, 60), $seconds % 60);
    }

    public function render($formId)
    {
        Acl::verify(self::CAP);

        $status = isset($_GET['status']) ? sanitize_text_field(wp_unslash($_GET['status'])) : '';
        $paged = max(1, (int) ($_GET['paged'] ?? 1));

        $result = Repository::paginate($formId, [
            'per_page' => self::PER_PAGE,
            'offset'   => ($paged - 1) * self::PER_PAGE,
            'status'   => $status,
        ]);

        $settings = Settings::all();

        include BFCF_DIR . 'views/partials-tab.php';
    }

    public function saveSettings()
    {
        Acl::verify(self::CAP);
        check_admin_referer('bfcf_settings');

        Settings::save([
            'abandon_after_minutes' => $_POST['abandon_after_minutes'] ?? 15,
            'retention_days'        => $_POST['retention_days'] ?? 90,
        ]);

        wp_safe_redirect($this->tabUrl((int) $_POST['form_id'], ['saved' => 1]));
        exit;
    }

    public function deletePartial()
    {
        Acl::verify(self::CAP);
        check_admin_referer('bfcf_delete');

        $formId = (int) ($_POST['form_id'] ?? 0);
        Repository::delete((int) ($_POST['partial_id'] ?? 0), $formId);

        wp_safe_redirect($this->tabUrl($formId, ['deleted' => 1]));
        exit;
    }

    public function resend()
    {
        Acl::verify(self::CAP);
        check_admin_referer('bfcf_resend');

        $ok = Dispatcher::resend((int) ($_POST['log_id'] ?? 0));

        wp_safe_redirect($this->tabUrl((int) ($_POST['form_id'] ?? 0), [
            'resent' => $ok ? 1 : 0,
        ]));
        exit;
    }

    public function export()
    {
        Acl::verify(self::CAP);
        check_admin_referer('bfcf_export');

        $formId = (int) ($_GET['form_id'] ?? 0);
        $result = Repository::paginate($formId, ['per_page' => 100, 'offset' => 0]);

        // Collect every answer key across the page so the columns line up even
        // though each partial stopped at a different question.
        $keys = [];
        foreach ($result['rows'] as $row) {
            $keys = array_merge($keys, array_keys(Repository::response($row)));
        }
        $keys = array_values(array_unique($keys));

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=partial-leads-' . $formId . '-' . gmdate('Y-m-d') . '.csv');

        $out = fopen('php://output', 'w');

        fputcsv($out, array_merge(
            ['id', 'status', 'checkpoint', 'step', 'total_steps', 'seconds', 'submission_id', 'source_url', 'referrer', 'created_at'],
            $keys
        ));

        foreach ($result['rows'] as $row) {
            $response = Repository::response($row);
            $answers = [];

            foreach ($keys as $key) {
                $value = $response[$key] ?? '';
                $answers[] = is_array($value) ? implode(' | ', $value) : $value;
            }

            fputcsv($out, array_merge([
                $row->id,
                $row->status,
                $row->checkpoint,
                $row->max_step,
                $row->total_steps,
                $row->active_seconds,
                $row->submission_id,
                $row->source_url,
                $row->referrer,
                $row->created_at,
            ], $answers));
        }

        fclose($out);
        exit;
    }
}

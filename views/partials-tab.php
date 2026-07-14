<?php

/**
 * @var int    $formId
 * @var array  $result   ['rows' => object[], 'total' => int]
 * @var array  $settings
 * @var string $status
 * @var int    $paged
 * @var \BetterFCF\Admin\PartialsTab $this
 */

use BetterFCF\Partials\Repository;
use BetterFCF\Webhooks\Dispatcher;

if (! defined('ABSPATH')) {
    exit;
}

$pages = (int) ceil($result['total'] / \BetterFCF\Admin\PartialsTab::PER_PAGE);

$statuses = [
    ''                        => __('All', 'better-fcfs'),
    Repository::ACTIVE        => __('In progress', 'better-fcfs'),
    Repository::ABANDONED     => __('Abandoned', 'better-fcfs'),
    Repository::CONVERTED     => __('Converted', 'better-fcfs'),
];
?>

<style>
    /* Scoped to this tab. Fluent Forms drops the route view straight under the tab bar
       with no breathing room, so give it its own padding and section rhythm. */
    .bfcf-wrap { padding: 24px 24px 48px; max-width: 1180px; box-sizing: border-box; }
    .bfcf-wrap .bfcf-header { margin: 0 0 24px; }
    .bfcf-wrap .bfcf-title { margin: 0 0 6px; padding: 0; font-size: 23px; font-weight: 600; line-height: 1.3; }
    .bfcf-wrap .bfcf-sub { margin: 0; max-width: 780px; color: #646970; font-size: 13px; line-height: 1.6; }
    .bfcf-wrap .bfcf-bar { display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; margin: 0 0 14px; }
    .bfcf-wrap .bfcf-bar .subsubsub { margin: 0; float: none; }
    .bfcf-wrap table.wp-list-table { margin-top: 2px; }
    .bfcf-wrap table.wp-list-table td { vertical-align: top; padding-top: 10px; padding-bottom: 10px; }
    .bfcf-wrap .bfcf-answers > div { margin: 1px 0; }
    .bfcf-wrap .bfcf-settings { margin-top: 44px; padding: 4px 24px 20px; background: #fff; border: 1px solid #dcdcde; border-radius: 8px; max-width: 680px; }
    .bfcf-wrap .bfcf-settings .bfcf-settings-title { margin: 16px 0 4px; font-size: 15px; font-weight: 600; }
    .bfcf-wrap details summary { cursor: pointer; }
</style>

<div class="bfcf-wrap">

    <?php if (isset($_GET['saved'])) : ?>
        <div class="notice notice-success"><p><?php esc_html_e('Settings saved.', 'better-fcfs'); ?></p></div>
    <?php endif; ?>
    <?php if (isset($_GET['resent'])) : ?>
        <div class="notice notice-<?php echo $_GET['resent'] ? 'success' : 'error'; ?>">
            <p><?php echo $_GET['resent'] ? esc_html__('Webhook resent.', 'better-fcfs') : esc_html__('Resend failed — see the log.', 'better-fcfs'); ?></p>
        </div>
    <?php endif; ?>

    <div class="bfcf-header">
        <h1 class="bfcf-title"><?php esc_html_e('Partial Leads', 'better-fcfs'); ?></h1>
        <p class="bfcf-sub">
            <?php esc_html_e('Visitors who got past a Partial Store element and stopped. Drop that element into the form editor to choose where a session starts counting as a lead — nothing before it is stored.', 'better-fcfs'); ?>
        </p>
    </div>

    <div class="bfcf-bar">
        <ul class="subsubsub">
            <?php foreach ($statuses as $key => $label) : ?>
                <li>
                    <a href="<?php echo esc_url($this->tabUrl($formId, $key ? ['status' => $key] : [])); ?>"
                       class="<?php echo $status === $key ? 'current' : ''; ?>">
                        <?php echo esc_html($label); ?>
                    </a><?php echo $key === array_key_last($statuses) ? '' : ' |'; ?>
                </li>
            <?php endforeach; ?>
        </ul>

        <a class="button"
           href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=bfcf_export&form_id=' . $formId), 'bfcf_export')); ?>">
            <?php esc_html_e('Export CSV', 'better-fcfs'); ?>
        </a>
    </div>

    <table class="wp-list-table widefat striped">
        <thead>
        <tr>
            <th style="width:50px"><?php esc_html_e('ID', 'better-fcfs'); ?></th>
            <th style="width:100px"><?php esc_html_e('Status', 'better-fcfs'); ?></th>
            <th style="width:110px"><?php esc_html_e('Checkpoint', 'better-fcfs'); ?></th>
            <th style="width:70px"><?php esc_html_e('Stage', 'better-fcfs'); ?></th>
            <th style="width:80px"><?php esc_html_e('On page', 'better-fcfs'); ?></th>
            <th style="width:30%"><?php esc_html_e('Answers', 'better-fcfs'); ?></th>
            <th style="width:130px"><?php esc_html_e('Webhooks', 'better-fcfs'); ?></th>
            <th style="width:150px"><?php esc_html_e('Started', 'better-fcfs'); ?></th>
            <th style="width:60px"></th>
        </tr>
        </thead>
        <tbody>
        <?php if (! $result['rows']) : ?>
            <tr>
                <td colspan="9"><?php esc_html_e('No partial leads yet.', 'better-fcfs'); ?></td>
            </tr>
        <?php endif; ?>

        <?php foreach ($result['rows'] as $row) :
            $response = Repository::response($row);
            $logs = Dispatcher::logsFor($row->id);
            $ok = count(array_filter($logs, function ($log) {
                return $log->status === 'success';
            }));
            ?>
            <tr>
                <td><?php echo (int) $row->id; ?></td>
                <td>
                    <?php if ($row->status === Repository::CONVERTED) : ?>
                        <span style="color:#1a7efb;font-weight:600"><?php esc_html_e('Converted', 'better-fcfs'); ?></span><br>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=fluent_forms&route=entries&form_id=' . $formId . '#/entries/' . $row->submission_id)); ?>">
                            <?php printf(esc_html__('Entry #%d', 'better-fcfs'), (int) $row->submission_id); ?>
                        </a>
                    <?php elseif ($row->status === Repository::ABANDONED) : ?>
                        <span style="color:#d63638;font-weight:600"><?php esc_html_e('Abandoned', 'better-fcfs'); ?></span>
                    <?php else : ?>
                        <span style="color:#996800"><?php esc_html_e('In progress', 'better-fcfs'); ?></span>
                    <?php endif; ?>
                </td>
                <td><?php echo esc_html($row->checkpoint); ?></td>
                <td>
                    <?php echo (int) $row->max_step; ?><?php echo $row->total_steps ? ' / ' . (int) $row->total_steps : ''; ?>
                </td>
                <td><?php echo esc_html(\BetterFCF\Admin\PartialsTab::duration($row->active_seconds)); ?></td>
                <td class="bfcf-answers">
                    <?php foreach ($response as $key => $value) : ?>
                        <?php
                        $display = is_array($value) ? implode(', ', $value) : (string) $value;
                        if (trim($display) === '') {
                            continue; // don't show fields that were reached but left blank
                        }
                        ?>
                        <div>
                            <strong><?php echo esc_html($key); ?>:</strong>
                            <?php echo esc_html($display); ?>
                        </div>
                    <?php endforeach; ?>
                </td>
                <td>
                    <?php if (! $logs) : ?>
                        <span style="color:#787c82">&mdash;</span>
                    <?php else : ?>
                        <details>
                            <summary><?php printf(esc_html__('%1$d sent, %2$d failed', 'better-fcfs'), $ok, count($logs) - $ok); ?></summary>
                            <?php foreach ($logs as $log) : ?>
                                <div style="margin:6px 0;padding:6px;background:#f6f7f7">
                                    <code><?php echo esc_html($log->trigger_type); ?></code>
                                    <?php echo esc_html($log->response_code ?: '—'); ?>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
                                        <?php wp_nonce_field('bfcf_resend'); ?>
                                        <input type="hidden" name="action" value="bfcf_resend">
                                        <input type="hidden" name="form_id" value="<?php echo (int) $formId; ?>">
                                        <input type="hidden" name="log_id" value="<?php echo (int) $log->id; ?>">
                                        <button class="button-link"><?php esc_html_e('Resend', 'better-fcfs'); ?></button>
                                    </form>
                                    <div style="color:#787c82;word-break:break-all">
                                        <?php echo esc_html(mb_substr((string) $log->response_body, 0, 160)); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </details>
                    <?php endif; ?>
                </td>
                <td><?php echo esc_html($row->created_at); ?></td>
                <td>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                          onsubmit="return confirm('<?php esc_attr_e('Delete this partial lead?', 'better-fcfs'); ?>')">
                        <?php wp_nonce_field('bfcf_delete'); ?>
                        <input type="hidden" name="action" value="bfcf_delete">
                        <input type="hidden" name="form_id" value="<?php echo (int) $formId; ?>">
                        <input type="hidden" name="partial_id" value="<?php echo (int) $row->id; ?>">
                        <button class="button-link" style="color:#d63638"><?php esc_html_e('Delete', 'better-fcfs'); ?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($pages > 1) : ?>
        <div class="tablenav"><div class="tablenav-pages">
            <?php
            // add_query_arg() would url-encode paginate_links' %#% placeholder, so the
            // page number has to be appended via `format` instead.
            echo paginate_links([
                'base'    => $this->tabUrl($formId, $status ? ['status' => $status] : []) . '%_%',
                'format'  => '&paged=%#%',
                'current' => $paged,
                'total'   => $pages,
            ]);
            ?>
        </div></div>
    <?php endif; ?>

    <div class="bfcf-settings">
    <h2 class="bfcf-settings-title"><?php esc_html_e('Capture Settings', 'better-fcfs'); ?></h2>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('bfcf_settings'); ?>
        <input type="hidden" name="action" value="bfcf_settings">
        <input type="hidden" name="form_id" value="<?php echo (int) $formId; ?>">

        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="bfcf_abandon"><?php esc_html_e('Mark as abandoned after', 'better-fcfs'); ?></label>
                </th>
                <td>
                    <input id="bfcf_abandon" name="abandon_after_minutes" type="number" min="1" class="small-text"
                           value="<?php echo esc_attr($settings['abandon_after_minutes']); ?>"> <?php esc_html_e('minutes of no activity', 'better-fcfs'); ?>
                    <p class="description">
                        <?php esc_html_e('Abandonment webhooks fire when this elapses. Too short and you notify the CRM about someone who is simply reading the next question.', 'better-fcfs'); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="bfcf_retention"><?php esc_html_e('Delete partials after', 'better-fcfs'); ?></label>
                </th>
                <td>
                    <input id="bfcf_retention" name="retention_days" type="number" min="1" class="small-text"
                           value="<?php echo esc_attr($settings['retention_days']); ?>"> <?php esc_html_e('days', 'better-fcfs'); ?>
                    <p class="description">
                        <?php esc_html_e('These rows hold personal data that was never actually submitted to you. Keep the window tight.', 'better-fcfs'); ?>
                    </p>
                </td>
            </tr>
        </table>

        <?php submit_button(__('Save Settings', 'better-fcfs')); ?>
    </form>
    </div>
</div>

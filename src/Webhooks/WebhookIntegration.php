<?php

namespace BetterFCF\Webhooks;

use FluentForm\App\Http\Controllers\IntegrationManagerController;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Feeds live in Fluent Forms' own Settings -> Integrations screen, rendered from
 * this PHP schema alone — the feed list, the field mapper and the conditional-logic
 * builder are all Fluent Forms' native components. That is what makes configuring a
 * partial webhook feel identical to configuring one for a full submission.
 *
 * IntegrationManagerController ships in the FREE plugin, so none of this needs Pro.
 */
class WebhookIntegration extends IntegrationManagerController
{
    const KEY = 'bfcf_webhook';

    const SETTINGS_KEY = 'bfcf_webhook_feeds';

    public $disableGlobalSettings = 'yes';

    public function __construct($app)
    {
        parent::__construct(
            $app,
            __('Partial Lead Webhooks', 'better-fcfs'),
            self::KEY,
            '_bfcf_webhook_settings',
            self::SETTINGS_KEY,
            30
        );

        $this->description = __('Send conversational-form partials to any endpoint the moment they qualify, or when they go cold.', 'better-fcfs');
        $this->logo = BFCF_URL . 'assets/img/logo.svg';
        $this->category = 'crm';

        // registerAdminHooks() bails immediately unless the module is switched on in
        // Fluent Forms -> Integration Modules. For us, the plugin being active IS the
        // enablement, so short-circuit that check before it runs.
        add_filter('fluentform/is_integration_enabled_' . self::KEY, '__return_true');
        add_filter('fluentform/save_integration_value_' . self::KEY, [$this, 'validate'], 10, 3);

        $this->registerAdminHooks();
    }

    /** No API key to store, so no global settings tab. */
    public function addGlobalMenu($setting)
    {
        return $setting;
    }

    /**
     * The base class only registers the notify hooks when this returns true — it
     * normally means "the user has saved valid API credentials", which we don't have.
     */
    public function isConfigured()
    {
        return true;
    }

    public function getMergeFields($list = false, $listId = false, $formId = false)
    {
        return [];
    }

    /**
     * Our feeds are NOT submission notifications, so keep them out of the list
     * GlobalNotificationHandler walks on fluentform/submission_inserted. Otherwise
     * every completed entry would queue a scheduled action and parse every smart code
     * in every feed, just to reach a notify() that does nothing.
     *
     * Dispatcher drives these feeds from the partial pipeline instead.
     */
    public function addActiveNotificationType($types)
    {
        return $types;
    }

    /** @see addActiveNotificationType() — never called. */
    public function notify($feed, $formData, $entry, $form)
    {
        return;
    }

    public function pushIntegration($integrations, $formId)
    {
        $integrations[self::KEY] = [
            'category'                => 'crm',
            'disable_global_settings' => 'yes',
            'logo'                    => $this->logo,
            'title'                   => $this->title,
            'is_active'               => true,
        ];

        return $integrations;
    }

    public function getIntegrationDefaults($settings, $formId)
    {
        return [
            'name'                => '',
            'bfcf_trigger'        => Dispatcher::TRIGGER_STEP,
            'request_url'         => '',
            'request_method'      => 'POST',
            'request_format'      => 'JSON',
            'request_headers'     => '',
            // Seed one blank row so the Payload map is visible and obviously
            // fillable. An empty array renders nothing, which reads as "there is
            // no payload section" and produces an empty {} body. Blank-key rows
            // are skipped when the body is built, so this adds no junk.
            'fields'              => [
                ['label' => '', 'item_value' => ''],
            ],
            'bfcf_require_filled' => [],
            'bfcf_require_empty'  => [],
            'bfcf_once'           => false,
            'conditionals'        => [
                'conditions' => [],
                'status'     => false,
                'type'       => 'all',
            ],
            'enabled' => true,
        ];
    }

    public function getSettingsFields($settings, $formId)
    {
        try {
            $labels = fluentFormApi()->form($formId)->labels();
        } catch (\Throwable $e) {
            $labels = [];
        }

        return [
            'fields' => [
                [
                    'key'         => 'name',
                    'label'       => __('Feed Name', 'better-fcfs'),
                    'required'    => true,
                    'placeholder' => __('Send lead to CRM', 'better-fcfs'),
                    'component'   => 'text',
                ],
                [
                    'key'       => 'bfcf_trigger',
                    'label'     => __('Trigger', 'better-fcfs'),
                    'required'  => true,
                    'component' => 'select',
                    'options'   => [
                        Dispatcher::TRIGGER_STEP      => __('Partial — after they pause or leave, once the grace window passes without a submission', 'better-fcfs'),
                        Dispatcher::TRIGGER_ABANDONED => __('Abandoned — long-inactivity fallback (never submitted)', 'better-fcfs'),
                    ],
                ],
                [
                    'key'         => 'request_url',
                    'label'       => __('Request URL', 'better-fcfs'),
                    'required'    => true,
                    'placeholder' => 'https://',
                    'component'   => 'value_text',
                ],
                [
                    'key'       => 'request_method',
                    'label'     => __('Request Method', 'better-fcfs'),
                    'component' => 'select',
                    'options'   => [
                        'POST'  => 'POST',
                        'PUT'   => 'PUT',
                        'PATCH' => 'PATCH',
                        'GET'   => 'GET',
                    ],
                ],
                [
                    'key'       => 'request_format',
                    'label'     => __('Request Format', 'better-fcfs'),
                    'component' => 'select',
                    'options'   => [
                        'JSON' => __('JSON', 'better-fcfs'),
                        'FORM' => __('Form-encoded', 'better-fcfs'),
                    ],
                ],
                [
                    'key'         => 'request_headers',
                    'label'       => __('Request Headers', 'better-fcfs'),
                    'placeholder' => "Authorization: Bearer xxxxx",
                    'component'   => 'value_textarea',
                    'inline_tip'  => __('One per line, as Header-Name: value. Leave empty for none.', 'better-fcfs'),
                ],
                [
                    'key'          => 'fields',
                    'require_list' => false,
                    'label'        => __('Payload', 'better-fcfs'),
                    'tips'         => __('The key is what your endpoint expects; the value is the form field it maps to. Partial-specific smart codes are also available: {bfcf.checkpoint}, {bfcf.status}, {bfcf.step}, {bfcf.seconds}, {bfcf.partial_id}.', 'better-fcfs'),
                    'component'    => 'dropdown_label_repeater',
                    'field_label'  => __('Payload Key', 'better-fcfs'),
                    'value_label'  => __('Form Field', 'better-fcfs'),
                ],
                [
                    'key'        => 'bfcf_require_filled',
                    'label'      => __('Only send if these fields are FILLED', 'better-fcfs'),
                    'component'  => 'checkbox-multiple-text',
                    'options'    => $labels,
                    'inline_tip' => __('The point of a partial is that most answers are still missing. Use this to hold the webhook until the ones you actually need exist — e.g. do not call the CRM until there is a phone number.', 'better-fcfs'),
                ],
                [
                    'key'       => 'bfcf_require_empty',
                    'label'     => __('Only send if these fields are EMPTY', 'better-fcfs'),
                    'component' => 'checkbox-multiple-text',
                    'options'   => $labels,
                ],
                [
                    'key'       => 'conditionals',
                    'label'     => __('Conditional Logic', 'better-fcfs'),
                    'component' => 'conditional_block',
                ],
                [
                    'key'            => 'bfcf_once',
                    'label'          => __('Frequency', 'better-fcfs'),
                    'component'      => 'checkbox-single',
                    'checkbox_label' => __('Send only once per visitor session', 'better-fcfs'),
                    'inline_tip'     => __('A visitor who pauses, comes back, and pauses again can send this feed more than once (with their answers as of each pause). Tick this to send a single time instead.', 'better-fcfs'),
                ],
                [
                    'key'            => 'enabled',
                    'label'          => __('Status', 'better-fcfs'),
                    'component'      => 'checkbox-single',
                    'checkbox_label' => __('Enable this feed', 'better-fcfs'),
                ],
            ],
            'integration_title' => $this->title,
        ];
    }

    public function validate($settings, $integrationId, $formId)
    {
        $url = trim((string) ($settings['request_url'] ?? ''));

        // Smart codes are resolved at send time, so the saved value is not a URL yet
        // and filter_var() would reject perfectly good feeds.
        if (! $url || stripos($url, 'http') !== 0) {
            wp_send_json_error([
                'message' => __('Validation Failed', 'better-fcfs'),
                'errors'  => [
                    'request_url' => [__('A request URL starting with http is required.', 'better-fcfs')],
                ],
            ], 423);
        }

        return $settings;
    }
}

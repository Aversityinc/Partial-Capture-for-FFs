<?php

namespace BetterFCF\Partials;

use BetterFCF\Settings;
use BetterFCF\Support\Form;
use BetterFCF\Webhooks\Dispatcher;
use FluentForm\App\Helpers\Helper;
use FluentForm\App\Services\Browser\Browser;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Receives the payload the conversational Vue app already builds for Fluent Forms
 * Pro's per-step save. Our front end rewrites the action to point here, so Pro's
 * DraftSubmissionsManager never sees it: no draft rows, no resume/prefill side
 * effects, and the whole thing works on free Fluent Forms.
 */
class CaptureController
{
    const ACTION = 'bfcf_partial_save';

    public function register()
    {
        add_action('wp_ajax_' . self::ACTION, [$this, 'save']);
        add_action('wp_ajax_nopriv_' . self::ACTION, [$this, 'save']);
    }

    public function save()
    {
        if (! wp_verify_nonce($this->post('nonce'), 'bfcf_partial')) {
            wp_send_json_error(['message' => 'bad nonce'], 403);
        }

        $formId = (int) $this->post('form_id');
        if (! $formId || ! Helper::isConversionForm($formId)) {
            wp_send_json_error(['message' => 'not a conversational form'], 400);
        }

        $session = $this->post('session');
        if (! preg_match('/^[A-Za-z0-9-]{16,64}$/', $session)) {
            wp_send_json_error(['message' => 'bad session'], 400);
        }

        if (! $this->withinRateLimit($session)) {
            wp_send_json_error(['message' => 'slow down'], 429);
        }

        $form = Form::load($formId);
        $checkpoint = Form::checkpoint($form, $this->post('checkpoint'));

        if (! $checkpoint) {
            wp_send_json_error(['message' => 'unknown checkpoint'], 400);
        }

        parse_str(wp_unslash($_POST['data'] ?? ''), $raw);
        $response = Form::cleanResponse((array) $raw, $formId);

        if (! $this->qualifies($checkpoint, $response)) {
            // Not an error — the visitor simply hasn't earned a row yet.
            wp_send_json_success(['stored' => false]);
        }

        $result = Repository::upsert($formId, $session, $this->row($checkpoint, $response));

        /**
         * Two kinds of call hit this endpoint:
         *  - progress saves (dispatch=0), fired on every answer past a checkpoint,
         *    which capture/update the row so it grows as they move through the form;
         *  - the webhook fire (dispatch=1), on the settle timer or on page exit.
         * Only the latter queues the feeds. Dispatch runs on `shutdown` so the
         * visitor's next question never waits on a third-party endpoint.
         */
        if ($this->post('dispatch') === '1') {
            Dispatcher::queue(Dispatcher::TRIGGER_STEP, $result['id']);
        }

        wp_send_json_success([
            'stored'     => true,
            'created'    => $result['created'],
            'dispatched' => $this->post('dispatch') === '1',
        ]);
    }

    /**
     * The client is not trusted about which checkpoint it passed. You cannot get
     * past a required question without answering it, so every required field
     * ahead of the marker must be present in the payload.
     *
     * The dwell timer (min_seconds) is NOT enforced here: it is a client-side idle
     * countdown, and the server has no way to know when the checkpoint was passed.
     * By the time this request arrives, the countdown has already elapsed (or the
     * visitor left the page) — the send itself is the proof of qualification.
     */
    private function qualifies(array $checkpoint, array $response)
    {
        foreach ($checkpoint['required_before'] as $name) {
            if (Form::isEmpty($response[$name] ?? null)) {
                return false;
            }
        }

        return true;
    }

    private function row(array $checkpoint, array $response)
    {
        $browser = new Browser();

        return [
            'response'       => wp_json_encode($response, JSON_UNESCAPED_UNICODE),
            'checkpoint'     => $checkpoint['label'],
            'max_step'       => (int) $this->post('active_step'),
            'total_steps'    => (int) $this->post('total_steps'),
            'last_field'     => $this->lastField($response),
            'active_seconds' => $this->activeSeconds(),
            'source_url'     => esc_url_raw($this->post('source_url')),
            'referrer'       => esc_url_raw($this->post('referrer')),
            'utm'            => wp_json_encode($this->utm()),
            'ip'             => $this->ip(),
            'browser'        => $browser->getBrowser(),
            'device'         => $browser->getPlatform(),
            'user_id'        => get_current_user_id() ?: null,
        ];
    }

    /**
     * Time on page is a junk filter, not a security boundary — the browser reports
     * it, so we only sanity-clamp rather than pretend to verify it.
     */
    private function activeSeconds()
    {
        return max(0, min((int) $this->post('active_seconds'), DAY_IN_SECONDS));
    }

    private function lastField(array $response)
    {
        $filled = array_filter($response, function ($value) {
            return ! Form::isEmpty($value);
        });

        if (! $filled) {
            return null;
        }

        return (string) array_key_last($filled);
    }

    private function utm()
    {
        $decoded = json_decode(wp_unslash($this->post('utm')), true);

        if (! is_array($decoded)) {
            return [];
        }

        return array_map('sanitize_text_field', array_slice($decoded, 0, 10));
    }

    private function ip()
    {
        $request = wpFluentForm('request');
        $ip = $request->getIp();

        if (
            (defined('FLUENTFROM_DISABLE_IP_LOGGING') && FLUENTFROM_DISABLE_IP_LOGGING) ||
            apply_filters('fluentform/disable_ip_logging', false, (int) $this->post('form_id'))
        ) {
            return null;
        }

        return $ip;
    }

    /**
     * The app fires one save per question advance, so anything faster than this is
     * not a person filling in a form.
     */
    private function withinRateLimit($session)
    {
        $key = 'bfcf_rl_' . md5($session);
        $hits = (int) get_transient($key);

        if ($hits > Settings::RATE_LIMIT_PER_MINUTE) {
            return false;
        }

        set_transient($key, $hits + 1, MINUTE_IN_SECONDS);

        return true;
    }

    private function post($key)
    {
        return isset($_POST[$key]) ? sanitize_text_field(wp_unslash($_POST[$key])) : '';
    }
}

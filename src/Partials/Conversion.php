<?php

namespace BetterFCF\Partials;

use FluentForm\App\Helpers\Helper;
use FluentForm\Framework\Helpers\ArrayHelper;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Closes the loop: a partial whose visitor went on to actually submit is not a
 * lead to chase any more. The row (and its logs) are deleted the moment the real
 * entry is inserted, and the session is flagged in a transient so that stale
 * timers, exit beacons or a raced progress save can neither resurrect the row
 * nor push its data to a webhook. Without this the sweep would fire webhooks at
 * people who completed the form — the "partial + full" double submission.
 */
class Conversion
{
    /**
     * How long a converted session stays refused. A healthy client rotates its
     * session id right after the submit response, so this only has to outlive
     * in-flight requests and the odd tab that never saw the success.
     */
    const FLAG_TTL = HOUR_IN_SECONDS;

    /** @var array<int,string> form id => session, captured from the raw request */
    private static $sessions = [];

    public function register()
    {
        // arg 2 here is $formDataRaw — the unfiltered parse_str output, before
        // array_intersect_key strips everything that isn't a real form field. It is
        // the only place our session id survives in the payload.
        add_action('fluentform/before_insert_submission', [$this, 'remember'], 5, 3);

        // Priority 5: ahead of FF Pro's DraftSubmissionsManager::delete() at 10.
        add_action('fluentform/submission_inserted', [$this, 'link'], 5, 3);
    }

    public function remember($insertData, $formDataRaw, $form)
    {
        $session = ArrayHelper::get((array) $formDataRaw, 'bfcf_session');

        if ($session) {
            self::$sessions[(int) $form->id] = sanitize_text_field($session);
        }
    }

    public function link($insertId, $formData, $form)
    {
        $formId = (int) $form->id;

        if (! Helper::isConversionForm($formId)) {
            return;
        }

        $session = $this->session($formId);
        if (! $session) {
            return;
        }

        // Flag BEFORE deleting: a capture request checks the flag once, early, so
        // it has to be up before the row disappears or that request would simply
        // recreate it.
        self::flagConverted($formId, $session);

        $row = Repository::findBySession($formId, $session);
        if ($row) {
            Repository::delete($row->id, $formId);
        }

        // A save that passed its flag check just before ours went up may have
        // landed since the delete — sweep once more. One can in principle still
        // be in flight after this too; the cron sweep re-checks the flag before
        // it dispatches or abandons anything, which is the durable guard.
        $straggler = Repository::findBySession($formId, $session);
        if ($straggler) {
            Repository::delete($straggler->id, $formId);
        }
    }

    /**
     * A converted session is refused by the capture endpoint and reaped by the
     * sweep for as long as the flag lives. Session ids are validated as
     * [A-Za-z0-9-]{16,64}, so they are safe raw inside an option name and fit
     * its 191-char budget.
     */
    public static function flagConverted($formId, $session)
    {
        set_transient('bfcf_conv_' . (int) $formId . '_' . $session, 1, self::FLAG_TTL);
    }

    public static function hasConverted($formId, $session)
    {
        return (bool) get_transient('bfcf_conv_' . (int) $formId . '_' . $session);
    }

    /**
     * The payload copy is authoritative: it is written by the same tab that owns
     * the answers, while the cookie is shared across tabs and holds whichever
     * form instance wrote it last. The cookie is the fallback for the payment
     * submit path (which rebuilds its FormData without our extra_inputs) and it
     * is why a conversion still links when the payload copy went missing.
     */
    private function session($formId)
    {
        if (! empty(self::$sessions[$formId])) {
            return self::$sessions[$formId];
        }

        $cookie = ArrayHelper::get($_COOKIE, 'bfcf_sid_' . $formId);

        if ($cookie) {
            return sanitize_text_field(wp_unslash($cookie));
        }

        return null;
    }
}

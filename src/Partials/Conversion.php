<?php

namespace BetterFCF\Partials;

use FluentForm\App\Helpers\Helper;
use FluentForm\Framework\Helpers\ArrayHelper;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Closes the loop: a partial whose visitor went on to actually submit is not an
 * abandoned lead. Without this the sweep would fire abandonment webhooks at people
 * who completed the form.
 */
class Conversion
{
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

        $row = Repository::findBySession($formId, $session);
        if ($row && $row->status !== Repository::CONVERTED) {
            Repository::markConverted($row->id, $insertId);
        }
    }

    /**
     * The cookie is the primary link and survives even if the payload is rebuilt
     * (the payment submit path constructs its own FormData). The payload copy is
     * the fallback for visitors who block cookies.
     */
    private function session($formId)
    {
        $cookie = ArrayHelper::get($_COOKIE, 'bfcf_sid_' . $formId);

        if ($cookie) {
            return sanitize_text_field(wp_unslash($cookie));
        }

        return self::$sessions[$formId] ?? null;
    }
}

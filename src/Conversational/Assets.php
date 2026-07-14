<?php

namespace BetterFCF\Conversational;

use FluentForm\App\Helpers\Helper;

if (! defined('ABSPATH')) {
    exit;
}

class Assets
{
    /** @var array<int,array> keyed by form id — a page can host more than one form */
    private static $configs = [];

    public function register()
    {
        add_filter('fluentform/rendering_form', [$this, 'maybeEnqueue'], 10, 1);
    }

    /**
     * Our script has to execute BEFORE conversationalForm.js: the Vue app mounts
     * the moment its bundle is parsed, so by then every FlowFormCustomType has
     * already fired ffc_init_custom_field, and the global-var trap has to be armed
     * before wp_localize_script assigns it.
     *
     * fluentform/rendering_form fires immediately before the conversational Form
     * class enqueues its own bundle, so enqueueing here puts us ahead of it in
     * $wp_scripts->queue. Both render paths honour that order:
     *
     *   - standalone (?fluent-form=N) never calls wp_head()/wp_footer(), so
     *     Form::printLoadedScripts() hand-walks the queue IN ORDER, printing each
     *     handle's localized data and then its <script src>.
     *   - the inline shortcode is a normal page, printed by wp_print_footer_scripts,
     *     also in queue order.
     *
     * Either way: BFCF_CONFIG, then our JS, then Fluent Forms'.
     */
    public function maybeEnqueue($form)
    {
        if (empty($form->id) || ! Helper::isConversionForm($form->id)) {
            return $form;
        }

        wp_enqueue_style(
            'bfcf-conv',
            BFCF_URL . 'assets/css/bfcf-conv.css',
            [],
            BFCF_VERSION
        );

        wp_enqueue_script(
            'bfcf-conv',
            BFCF_URL . 'assets/js/bfcf-conv.js',
            [],
            BFCF_VERSION,
            true
        );

        self::$configs[(int) $form->id] = Config::forForm($form);

        // wp_localize_script concatenates on repeated calls, so with two forms on
        // one page both `var BFCF_CONFIG = ...` statements print and the last one
        // wins. Re-sending the whole accumulated map keeps that last one complete.
        wp_localize_script('bfcf-conv', 'BFCF_CONFIG', ['forms' => self::$configs]);

        return $form;
    }
}

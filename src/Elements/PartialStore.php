<?php

namespace BetterFCF\Elements;

use FluentForm\App\Services\FormBuilder\BaseFieldManager;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * A checkpoint you drag into the middle of a conversational form.
 *
 * It maps to FlowFormHiddenType, and QuestionModel::showQuestion() returns false
 * for Hidden — so it holds a real position in the field order that the user never
 * sees. Advance past it and the session becomes a lead; everything before it is
 * throwaway and never leaves the browser.
 *
 * Modelled on Fluent Forms' own WelcomeScreen, the one conversational-only element
 * it ships.
 */
class PartialStore extends BaseFieldManager
{
    const KEY = 'bfcf_partial_store';

    public function __construct()
    {
        parent::__construct(
            self::KEY,
            __('Partial Store', 'better-fcfs'),
            ['partial', 'lead', 'abandon', 'capture', 'checkpoint'],
            'advanced'
        );

        add_filter('fluentform/conversational_editor_elements', [$this, 'pushConversationalComponent'], 10, 1);
        add_filter('fluentform/conversational_accepted_field_elements', [$this, 'accept'], 10, 1);
        add_filter('fluentform/conversational_field_types', [$this, 'mapType'], 10, 1);
        add_filter('fluentform/editor_element_customization_settings', [$this, 'defineSettings']);
    }

    /** Conversational forms only — a classic form has no steps to sit between. */
    public function getComponent()
    {
        return [];
    }

    /** Never an input: it must not reach validation, entries, or the response JSON. */
    public function pushFormInputType($types)
    {
        return $types;
    }

    public function accept($elements)
    {
        $elements[] = self::KEY;

        return $elements;
    }

    public function mapType($types)
    {
        $types[self::KEY] = 'FlowFormHiddenType';

        return $types;
    }

    public function pushConversationalComponent($components)
    {
        $components['advanced'][] = [
            'index'      => 55,
            'element'    => self::KEY,
            'attributes' => [
                'type'  => 'hidden',
                'name'  => self::KEY,
                'value' => '',
            ],
            'settings' => [
                'label'              => __('Partial Store', 'better-fcfs'),
                'admin_field_label'  => __('Qualified', 'better-fcfs'),
                'bfcf_min_seconds'   => '',
                'container_class'    => '',
                'conditional_logics' => [],
            ],
            'editor_options' => [
                'title'      => __('Partial Store', 'better-fcfs'),
                'icon_class' => 'ff-edit-hidden-field',
                'template'   => 'inputHidden',
            ],
            'style_pref' => [
                'layout'           => 'default',
                'media'            => '',
                'brightness'       => 0,
                'alt_text'         => '',
                'media_x_position' => 50,
                'media_y_position' => 50,
            ],
        ];

        return $components;
    }

    public function defineSettings($settings)
    {
        $settings['bfcf_min_seconds'] = [
            'template'  => 'inputText',
            'label'     => __('Settle timer (seconds)', 'better-fcfs'),
            'help_text' => __('After the visitor passes this point, wait this many seconds of no further answers before sending the partial. Each new answer restarts the countdown. Reaching a later Partial Store, or fully submitting, cancels it; leaving the page sends it immediately. 0 = send as soon as they pass this point.', 'better-fcfs'),
        ];

        return $settings;
    }

    public function getGeneralEditorElements()
    {
        return [
            'admin_field_label',
            'bfcf_min_seconds',
        ];
    }

    public function getAdvancedEditorElements()
    {
        return [
            'name',
            'conditional_logics',
        ];
    }

    /** Classic-form rendering. There is none — the element is conversational-only. */
    public function render($data, $form)
    {
        return '';
    }
}

<?php

namespace BetterFCF\Support;

use BetterFCF\Elements\PartialStore;
use FluentForm\Framework\Helpers\ArrayHelper;

if (! defined('ABSPATH')) {
    exit;
}

class Form
{
    /** Keys Fluent Forms (or we) put in the payload that are not real answers. */
    const INTERNAL = [
        '_wp_http_referer',
        '__fluent_form_embded_post_id',
        'isFFConversational',
        '__ff_all_applied_coupons',
        '__fluent_state_hash',
        'bfcf_session',        // our own session id, injected via extra_inputs
        'bfcf_partial_store',  // the hidden checkpoint marker serializes as an empty value
    ];

    public static function load($formId)
    {
        return wpFluent()->table('fluentform_forms')->find($formId);
    }

    public static function fields($form)
    {
        if (! $form) {
            return [];
        }

        $decoded = json_decode($form->form_fields ?? '', true);

        return ArrayHelper::get($decoded, 'fields', []);
    }

    /**
     * Resolve a Partial Store element by name, together with the names of every
     * REQUIRED question that precedes it.
     *
     * That list is the server-side gate. The browser tells us which checkpoint it
     * passed, but we don't take its word for it: you cannot advance past a
     * required question without answering it, so if any required field before the
     * marker is missing from the payload, the claim is false.
     *
     * @return array{field:array,required_before:string[],label:string,min_seconds:int}|null
     */
    public static function checkpoint($form, $name)
    {
        $required = [];

        foreach (self::fields($form) as $field) {
            if (ArrayHelper::get($field, 'element') === PartialStore::KEY) {
                if (ArrayHelper::get($field, 'attributes.name') !== $name) {
                    // An earlier marker. Keep accumulating: getting past THIS one means
                    // they got past that one too, so its required questions still count.
                    continue;
                }

                $settings = ArrayHelper::get($field, 'settings', []);

                return [
                    'field'           => $field,
                    'required_before' => $required,
                    'label'           => ArrayHelper::get($settings, 'admin_field_label') ?: __('Partial Store', 'better-fcfs'),
                    'min_seconds'     => (int) ArrayHelper::get($settings, 'bfcf_min_seconds', 0),
                ];
            }

            if (! ArrayHelper::isTrue($field, 'settings.validation_rules.required.value')) {
                continue;
            }

            // A required question hidden behind conditional logic may legitimately
            // never be shown, so it cannot be part of the gate.
            if (ArrayHelper::isTrue($field, 'settings.conditional_logics.status')) {
                continue;
            }

            $fieldName = ArrayHelper::get($field, 'attributes.name');
            if ($fieldName) {
                $required[] = $fieldName;
            }
        }

        return null;
    }

    /**
     * Drop Fluent Forms' plumbing keys so what we store, show and send onward is
     * only what the visitor actually answered.
     */
    public static function cleanResponse(array $raw, $formId)
    {
        unset($raw['_fluentform_' . $formId . '_fluentformnonce']);

        foreach (self::INTERNAL as $key) {
            unset($raw[$key]);
        }

        return map_deep($raw, 'sanitize_textarea_field');
    }

    public static function isEmpty($value)
    {
        if (is_array($value)) {
            return ! array_filter($value, function ($item) {
                return ! self::isEmpty($item);
            });
        }

        return $value === null || trim((string) $value) === '';
    }
}

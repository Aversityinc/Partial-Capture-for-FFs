<?php

namespace BetterFCF\Conversational;

use BetterFCF\Elements\PartialStore;
use FluentForm\Framework\Helpers\ArrayHelper;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Builds the per-form payload our front-end script needs, straight off the form's
 * own field definitions. Nothing here is stored separately — number formatting
 * lives in each Number field's `settings`, and the capture threshold is a
 * Partial Store element's position in the field order.
 */
class Config
{
    public static function forForm($form)
    {
        $fields = self::fields($form);

        return [
            'ajaxurl'     => admin_url('admin-ajax.php'),
            'nonce'       => wp_create_nonce('bfcf_partial'),
            'form_id'     => (int) $form->id,
            'numbers'     => self::numberFields($fields),
            'checkpoints' => self::checkpoints($fields),
        ];
    }

    /**
     * `renderFormHtml()` decodes form_fields onto ->fields before it fires
     * fluentform/rendering_form, but the shortcode path does not always, so
     * fall back to the raw column.
     */
    private static function fields($form)
    {
        if (! empty($form->fields['fields'])) {
            return $form->fields['fields'];
        }
        $decoded = json_decode($form->form_fields ?? '', true);
        return ArrayHelper::get($decoded, 'fields', []);
    }

    /**
     * Only Number fields that actually opted in. A field with none of the three
     * checkboxes ticked is left entirely alone, so an unconfigured form renders
     * exactly as Fluent Forms would render it.
     */
    private static function numberFields(array $fields)
    {
        $out = [];

        foreach ($fields as $field) {
            if (ArrayHelper::get($field, 'element') !== 'input_number') {
                continue;
            }

            $settings = ArrayHelper::get($field, 'settings', []);

            $dollar = self::isOn($settings, 'bfcf_prefix_dollar');
            $percent = self::isOn($settings, 'bfcf_suffix_percent');
            $commas = self::isOn($settings, 'bfcf_thousand_commas');

            // Fluent Forms already ships these two on the Number field and the
            // conversational renderer throws them away. Honour them for free.
            $prefix = $dollar ? '$' : (string) ArrayHelper::get($settings, 'prefix_label', '');
            $suffix = $percent ? '%' : (string) ArrayHelper::get($settings, 'suffix_label', '');

            if (! $prefix && ! $suffix && ! $commas) {
                continue;
            }

            $name = ArrayHelper::get($field, 'attributes.name');
            if (! $name) {
                continue;
            }

            $out[$name] = [
                'prefix'   => $prefix,
                'suffix'   => $suffix,
                'commas'   => $commas,
                'decimals' => self::decimals($settings),
                'min'      => self::numeric(ArrayHelper::get($settings, 'validation_rules.min.value')),
                'max'      => self::numeric(ArrayHelper::get($settings, 'validation_rules.max.value')),
            ];
        }

        return $out;
    }

    /**
     * Derived from Fluent Forms' own number_step so we don't invent a second
     * setting for the same thing: a step of 0.01 means two decimal places.
     */
    private static function decimals(array $settings)
    {
        $step = ArrayHelper::get($settings, 'number_step');

        if (! is_numeric($step) || (float) $step <= 0) {
            return 0;
        }

        $parts = explode('.', rtrim(rtrim(sprintf('%.10F', (float) $step), '0'), '.'));

        return isset($parts[1]) ? strlen($parts[1]) : 0;
    }

    /**
     * Each Partial Store element, with the number of *rendered* questions that
     * precede it. Conditional logic can hide questions at runtime, so the front
     * end resolves the real index by field name against the live question list —
     * this ordinal is only a fallback.
     */
    private static function checkpoints(array $fields)
    {
        $out = [];
        $before = [];

        foreach ($fields as $field) {
            $element = ArrayHelper::get($field, 'element');

            if ($element !== PartialStore::KEY) {
                $name = ArrayHelper::get($field, 'attributes.name');
                if ($name) {
                    $before[] = $name;
                }
                continue;
            }

            $settings = ArrayHelper::get($field, 'settings', []);

            $out[] = [
                'name'         => ArrayHelper::get($field, 'attributes.name'),
                'label'        => ArrayHelper::get($settings, 'admin_field_label') ?: __('Partial Store', 'better-fcfs'),
                'min_seconds'  => (int) ArrayHelper::get($settings, 'bfcf_min_seconds', 0),
                'after_fields' => $before,
            ];

            $before = [];
        }

        return $out;
    }

    private static function isOn(array $settings, $key)
    {
        $value = ArrayHelper::get($settings, $key);

        return $value === true || $value === 'yes' || $value === '1' || $value === 1;
    }

    private static function numeric($value)
    {
        return is_numeric($value) ? (float) $value : null;
    }
}

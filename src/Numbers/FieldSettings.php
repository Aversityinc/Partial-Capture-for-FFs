<?php

namespace BetterFCF\Numbers;

use FluentForm\App\Helpers\Helper;
use FluentForm\Framework\Helpers\ArrayHelper;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Adds three checkboxes to the Number field's own settings panel, rather than
 * putting them on a separate screen. They ride along in the field's `settings`
 * inside form_fields, exactly like any native Fluent Forms option.
 */
class FieldSettings
{
    /** Booleans, because that's what the inputYesNoCheckBox template binds. */
    public static function defaults()
    {
        return [
            'bfcf_prefix_dollar'  => false,
            'bfcf_suffix_percent' => false,
            'bfcf_thousand_commas' => false,
        ];
    }

    public function register()
    {
        add_filter('fluentform/editor_element_customization_settings', [$this, 'defineSettings']);
        add_filter('fluentform/editor_element_settings_placement', [$this, 'placeSettings'], 10, 2);
        add_filter('fluentform/editor_components', [$this, 'seedNewFields'], 20);
        add_filter('fluentform/editor_vars', [$this, 'seedExistingFields']);
    }

    public function defineSettings($settings)
    {
        $settings['bfcf_prefix_dollar'] = [
            'template'  => 'inputYesNoCheckBox',
            'label'     => __('Show $ before the number', 'better-fcfs'),
            'help_text' => __('Displays as $450,000 while the user types. The stored value stays a plain number.', 'better-fcfs'),
        ];

        $settings['bfcf_suffix_percent'] = [
            'template'  => 'inputYesNoCheckBox',
            'label'     => __('Show % after the number', 'better-fcfs'),
            'help_text' => __('Displays as 7.5% while the user types. The stored value stays a plain number.', 'better-fcfs'),
        ];

        $settings['bfcf_thousand_commas'] = [
            'template'  => 'inputYesNoCheckBox',
            'label'     => __('Auto-format with commas', 'better-fcfs'),
            'help_text' => __('Groups thousands as the user types: 1,234,567.', 'better-fcfs'),
        ];

        return $settings;
    }

    /**
     * Conversational forms only — these settings do nothing on a classic form,
     * where Fluent Forms already honours prefix_label / suffix_label itself.
     */
    public function placeSettings($placements, $form)
    {
        $formId = is_object($form) ? ($form->id ?? null) : null;

        if (! $formId || ! Helper::isConversionForm($formId) || empty($placements['input_number']['general'])) {
            return $placements;
        }

        $placements['input_number']['general'] = array_merge(
            $placements['input_number']['general'],
            array_keys(self::defaults())
        );

        return $placements;
    }

    /**
     * New Number fields dragged onto the canvas.
     */
    public function seedNewFields($components)
    {
        foreach ($components as $group => $items) {
            foreach ($items as $index => $item) {
                if (ArrayHelper::get($item, 'element') !== 'input_number') {
                    continue;
                }
                $components[$group][$index]['settings'] = array_merge(
                    self::defaults(),
                    ArrayHelper::get($item, 'settings', [])
                );
            }
        }

        return $components;
    }

    /**
     * Number fields that were saved before this plugin existed have none of our
     * keys. The form editor is Vue 2, which cannot make a property reactive after
     * the fact — a checkbox bound to a missing key just wouldn't respond. So the
     * keys have to be present on the object the editor boots with.
     */
    public function seedExistingFields($data)
    {
        $form = ArrayHelper::get($data, 'form');

        if (! is_object($form) || empty($form->form_fields)) {
            return $data;
        }

        $decoded = json_decode($form->form_fields, true);
        if (empty($decoded['fields'])) {
            return $data;
        }

        $touched = false;

        foreach ($decoded['fields'] as $index => $field) {
            if (ArrayHelper::get($field, 'element') !== 'input_number') {
                continue;
            }
            foreach (self::defaults() as $key => $value) {
                if (! array_key_exists($key, $field['settings'] ?? [])) {
                    $decoded['fields'][$index]['settings'][$key] = $value;
                    $touched = true;
                }
            }
        }

        if ($touched) {
            $data['form']->form_fields = json_encode($decoded);
        }

        return $data;
    }
}

<?php

namespace BetterFCF;

use FluentForm\App\Helpers\Str;
use FluentForm\Framework\Helpers\ArrayHelper;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Fluent Forms' own ConditionAssesor cannot express "this field was never answered",
 * which is the single most useful thing to ask about a partial submission.
 *
 * It has no is-empty / is-not-empty / exists operator, and it bails before the
 * operator ever runs (ConditionAssesor::assess, v6.2.6 line 75):
 *
 *     if (!Arr::has($inputs, $accessor)) { return false; }
 *
 * So an absent key returns FALSE for every operator. For `field != ""` that lands on
 * the right answer by accident — absent really isn't filled. But for `field = ""`,
 * meaning "is empty", an unanswered field returns false when it should return true.
 * On a partial, where most keys are absent by definition, that is exactly backwards:
 * you cannot write "fire this webhook only if they never gave us a phone number".
 *
 * We keep Fluent Forms' operator vocabulary verbatim, so its native conditional_block
 * UI drives us unchanged, and treat an absent key as an empty value instead of an
 * automatic false. `field = ""` then means "empty or never answered" and `field != ""`
 * means "is filled" — and both agree with what FF does when the key IS present, so
 * nothing regresses.
 */
class Conditions
{
    public static function evaluate($conditionals, array $data)
    {
        if (! is_array($conditionals) || empty($conditionals['status'])) {
            return true;
        }

        $conditions = array_filter((array) ArrayHelper::get($conditionals, 'conditions', []), function ($condition) {
            return ! empty($condition['field']) && ! empty($condition['operator']);
        });

        if (! $conditions) {
            return true;
        }

        $matched = array_map(function ($condition) use ($data) {
            return self::assess($condition, $data);
        }, $conditions);

        if (ArrayHelper::get($conditionals, 'type') === 'any') {
            return in_array(true, $matched, true);
        }

        return ! in_array(false, $matched, true);
    }

    private static function assess(array $condition, array $data)
    {
        // Same accessor normalisation Fluent Forms uses: items[0][name] -> items.0.name
        $accessor = rtrim(str_replace(['[', ']', '*'], ['.'], $condition['field']), '.');

        $input = ArrayHelper::get($data, $accessor, '');
        $value = $condition['value'] ?? '';

        switch ($condition['operator']) {
            case '=':
                return is_array($input) ? in_array($value, $input) : $input == $value;
            case '!=':
                return is_array($input) ? ! in_array($value, $input) : $input != $value;
            case '>':
                return $input > $value;
            case '<':
                return $input < $value;
            case '>=':
                return $input >= $value;
            case '<=':
                return $input <= $value;
            case 'startsWith':
                return Str::startsWith(self::flatten($input), $value);
            case 'endsWith':
                return Str::endsWith(self::flatten($input), $value);
            case 'contains':
                return Str::contains(self::flatten($input), $value);
            case 'doNotContains':
                return ! Str::contains(self::flatten($input), $value);
            case 'length_equal':
                return self::length($input) == $value;
            case 'length_less_than':
                return self::length($input) < $value;
            case 'length_greater_than':
                return self::length($input) > $value;
            case 'test_regex':
                return (bool) preg_match('/' . $value . '/', self::flatten($input));

            // Not reachable from Fluent Forms' conditional_block dropdown, but a feed
            // can carry them and the webhook feed's own "must be filled/empty" pickers
            // compile down to these.
            case 'is_empty':
                return self::isEmpty($input);
            case 'is_not_empty':
                return ! self::isEmpty($input);
        }

        return false;
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

    private static function flatten($value)
    {
        return is_array($value) ? implode(' ', $value) : (string) $value;
    }

    private static function length($value)
    {
        return is_array($value) ? count($value) : strlen((string) $value);
    }
}

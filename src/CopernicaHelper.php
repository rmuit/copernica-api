<?php

namespace CopernicaApi;

use DateTime;
use DateTimeZone;
use Exception;
use UnexpectedValueException;

/**
 * Static utility method(s) for code that deals with Copernica
 */
class CopernicaHelper
{
    /**
     * The timezone which the Copernica API backend operates in.
     *
     * The fact that this is a constant is representative of the fact that I
     * have no clue whether this is configurable per Copernica environment.
     * Once we know, we can decide whether this should be an argument to below
     * method or e.g. a public static variable with a setter
     */
    const TIMEZONE_DEFAULT = 'Europe/Amsterdam';

    /**
     * Adjusts an input value as Copernica (apparently) does before using it.
     *
     * This can be use for e.g. checking whether sending an update/PUT request
     * will actually change anything.
     *
     * The Copernica API basically never returns an error for unknown field
     * input for e.g. profiles; it just converts unknown types/values. This
     * code grew out of testing those conversions against the live API and
     * recreating the apparent specs. (Though in recreating it, it turns out
     * maybe the live API code wasn't fully designed around specs, but was just
     * made to do e.g. "whatever code like PHP strtotime() does" including all
     * the strangeness. See the unit test serving as a reverse engineered spec.)
     *
     * Of course we don't know for sure whether the live API stores the values
     * literally as converted here (because we only know the values it outputs)
     * but it's likely, given some other behavior re. defaults/required fields.
     *
     * The best specification we have available of how Copernica behaves w.r.t.
     * accepting / changing field input is the combination of comments in this
     * method and the data provider for the unit test.
     *
     * @param mixed $value
     *   An input value.
     * @param array $field_struct
     *   A field structure which at a minimum needs a valid 'type' property.
     *   For 'select' types, it also needs a 'value' property.
     *
     * @return mixed
     *   The normalized value.
     *
     * @see \CopernicaApi\Tests\CopernicaHelperTest::provideDataForNormalizeInputValue()
     */
    public static function normalizeInputValue($value, array $field_struct)
    {
        // We'll accept values as-is if the field type is unknown, so the code
        // is also useful for code that does not define _all_ its field types
        // explicitly. We'll throw exceptions for unknown types; this library's
        // usual policy is "don't let any strange value through unnoticed /
        // taking away an exception for a previously not accepted value is
        // easier than erroring out on a previously accepted value".
        if (!isset($field_struct['type'])) {
            return $value;
        }
        switch ((string)$field_struct['type']) {
            // Email field is not checked for valid e-mail. (The UI does that,
            // the REST API doesn't.)
            case 'email':
            case 'text':
                if (is_scalar($value)) {
                    // Convert to string. Boolean becomes "1" / "".
                    $value = (string) $value;
                } else {
                    // Other values are not ignored (because they don't
                    // become the default value for the field on inserting);
                    // they're explicitly "".
                    $value = '';
                }
                break;

            case 'select':
                // Only let the value pass if the string equivalent (e.g. "1"
                // for true) is contained in the allowed values, matching case
                // sensitively (unlike other fields). If not, the value becomes
                // empty string. (It makes no difference if the empty string is
                // among the explicitly configured values.) Right trim choices,
                // not the value itself (which leads to values with trailing
                // spaces always being discarded).
                $value_property = isset($field_struct['value']) && is_scalar($field_struct['value']) ? $field_struct['value'] : '';
                $choices = array_map('rtrim', explode("\r\n", $value_property));
                if (is_scalar($value) && in_array((string)$value, $choices, true)) {
                    $value = (string)$value;
                } else {
                    // Other values are not ignored (because they don't
                    // become the default value for the field on inserting);
                    // they're explicitly "".
                    $value = '';
                }
                break;

            case 'integer':
                // All non-empty arrays become 1, strings (including "true")
                // become 0.
                $value = (int) $value;
                break;

            case 'float':
                // Same as integer.
                $value = (float) $value;
                break;

            case 'date':
            case 'datetime':
            case 'empty_date':
            case 'empty_datetime':
                // It seems that Copernica is using strtotime() internally. We
                // use DateTime objects so we can also work well if our PHP is
                // not configured for the same timezone as Copernica is. Points:
                // - new DateTime(''/false) is a valid expression (evaluates to
                //   <now>) but strtotime('') is not (evaluates to False).
                //   Copernica evaluates to <empty>.
                // - strtotime(' ') on the other hand evaluates to <now>. But
                //   Copernica does not; it makes this empty too. This is the
                //   only reason we're trim()ing strings - trim()ing is not
                //   known to make a difference for any other value.
                // - Expressions like "0000-00-01" can result in a negative
                //   year number. Apparently the 'negative year' is where
                //   Copernica draws a line and gives it "0000-00-00" instead
                //   (or +00:00:00 for datetime fields), also for 'empty_' type
                //   dates.
                // - Exception to the previous are dates starting with
                //   "0000-00-00", regardless of the time specification. Those
                //   become empty for 'empty_' type fields /
                //   "0000-00-00 00:00:00" for datetime. (This means
                //   "0000-00-00 23:59:59" and "0000-00-01 00:00:00" are
                //   treated differently; the first is 'empty'.)
                if (is_string($value)) {
                    $value = trim($value);
                }
                $negative_date = false;
                // $empty_input is not all cases of 'empty' input; it's all
                // cases that would not come out as empty if we put them
                // through date wrangling.
                $empty_input = $value === '' || $value === false || $value === null || !is_scalar($value)
                    || is_string($value) && strpos($value, '0000-00-00') === 0;
                if (!$empty_input) {
                    // DateTime is finicky when working in the non-default
                    // timezone: if the date/time expression does not contain a
                    // timezone component, we must pass a timezone object into
                    // the constructor to make it unambiguous (because we don't
                    // want it to be interpreted in the context of PHP's
                    // default timezone) - and this timezone also gets used for
                    // output. But if the expression does contain a timezone
                    // component / is a timestamp, the timezone argument gets
                    // completely ignored so we have to explicitly set the
                    // timezone afterwards in order to get the right output.
                    $tz_obj = new DateTimeZone(static::TIMEZONE_DEFAULT);
                    try {
                        $date = new DateTime($value, $tz_obj);
                        $date->setTimezone($tz_obj);
                        if (substr($field_struct['type'], -4) === 'time') {
                            $value = $date->format('Y-m-d H:i:s');
                        } else {
                            $value = $date->format('Y-m-d');
                        }
                    } catch (Exception $e) {
                        $value = '';
                    }
                    $negative_date = strpos($value, '-') === 0;
                }
                if ($value === '' || $empty_input || $negative_date) {
                    if ($field_struct['type'] === 'date' || $negative_date && $field_struct['type'] === 'empty_date') {
                        $value = '0000-00-00';
                    } elseif ($field_struct['type'] === 'datetime' || $negative_date && $field_struct['type'] === 'empty_datetime') {
                        $value = '0000-00-00 00:00:00';
                    } else {
                        $value = '';
                    }
                }
                break;

            default:
                throw new UnexpectedValueException("Unknown field type '{$field_struct['type']}'.");
        }

        return $value;
    }

    /**
     * Normalizes an input value to be able to be used as 'secret'.
     *
     * Not sure if this has any use outside of TestApi, but it fits with
     * normalizeInputValue(). If anything, it serves as a 'specification'
     * of what the REST API does with the value.
     *
     * @param mixed $value
     *   An input value.
     *
     * @return string
     *   A string, as it would be converted by the live API.
     */
    public static function normalizeSecretInput($value)
    {
        if (isset($value) && is_scalar($value)) {
            // Convert non-ASCII to question marks. I think this approximates
            // well enough (if not equals) what the live API is doing.
            $value = mb_convert_encoding($value, "ASCII");
        } else {
            $value = '1';
        }

        return $value;
    }
}

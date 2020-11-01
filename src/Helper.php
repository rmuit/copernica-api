<?php

namespace CopernicaApi;

use DateTime;
use DateTimeZone;
use Exception;
use RuntimeException;
use UnexpectedValueException;

/**
 * Static utility method(s) for code that deals with Copernica
 */
class Helper
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
     * @see \CopernicaApi\Tests\HelperTest::provideDataForNormalizeInputValue()
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
     * Indicates whether a value converts to True by Copernica.
     *
     * This logic would likely be part of normalizeInputValue() if there was a
     * boolean field. It's used for 'boolean' query parameters and has only
     * been tested for values of the 'total' parameter so far, so it stays
     * private until it needs to be used more widely.
     */
    private static function isBooleanTrue($value)
    {
        if (!is_bool($value)) {
            if (is_string($value) && !is_numeric($value)) {
                // Leading/trailing spaces 'falsify' the outcome.
                $value = in_array(strtolower($value), ['yes', 'true'], true);
            } elseif (is_scalar($value)) {
                $value = abs($value) >= 1;
            } else {
                // All arrays, no others (because rawurlencode() encodes
                // objects to empty string).
                $value = is_array($value);
            }
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
            // well enough (if not equals) what the live API is doing. It's
            // quite unlikely that the mbstring extension is not installed.
            if (extension_loaded('mbstring')) {
                $value = mb_convert_encoding($value, "ASCII");
            } else {
                // ...it's so unlikely that I don't care this doesn't really
                // work as intended. It sanitizes things, but replaces multi=
                // byte characters by multiple question marks
                $value = preg_replace('/[\x00-\x1F\x80-\xFF]/', '?', $value);
            }
        } else {
            $value = '1';
        }

        return $value;
    }

    /**
     * Extracts entities embedded within an entity.
     *
     * A set of embedded entities is wrapped inside its own structure of
     * start/limit/count/total properties, and this method unwraps that
     * structure so callers don't need to deal with it. (Just like every set
     * of entities in an API response contains of such a structure but
     * getEntities() transparently unwraps it.)
     *
     * Two types of entity are known to contain sets of embedded entities:
     * - databases, which have 'fields', 'interests' and 'collections';
     * - collections, which again have 'fields'.
     * Each of these embedded properties also have their own API calls defined,
     * e.g. databases/<ID>/fields, which is recommended to call instead if we
     * are just looking for one set of embedded entities. But for code that
     * wants to use data from several sets of embedded entities and/or the main
     * entity at the same time, and does not want to perform repeated API
     * calls, this helper method may come in handy.
     *
     * This method isn't very generic, in the sense that it requires the caller
     * to know about which properties contain 'wrapped' entities and to
     * estimate that the set of entities is complete. Then again, the whole
     * concept of returning embedded entities inside an API result feels not
     * very generic to begin with. (It wastes time on the API server side if we
     * don't need those data.) It's quite possible that this only applies to
     * databases and collections; if Copernica was doing this more often, it
     * would make more sense to create a separate class to represent entity
     * data with getters for the embedded entities. But at the moment, that
     * seems unnecessary.
     *
     * @param array $entity
     *   The entity containing embedded data.
     * @param string $property_name
     *   The property containing a set of entities wrapped in
     *   start/limit/count/total properties.
     * @param bool $throw_if_incomplete
     *   (Optional) if False is passed, this method will not throw an exception
     *   if the set of embedded entities is incomplete; it will just return
     *   incomplete data.
     *
     * @return array
     *   The embedded entities.
     *
     * @throws \RuntimeException
     *   If the property does not have the expected structure.
     *
     */
    public static function getEmbeddedEntities(array $entity, $property_name, $throw_if_incomplete = true)
    {
        // We'd return an empty array for a not-set property name, IF we had an
        // example of a certain expected property not being set at all if the
        // number of embedded entities is 0.
        if (!isset($entity[$property_name])) {
            throw new RuntimeException("'$property_name' property is not set; cannot extract embedded entities.");
        }
        $wrapper = $entity[$property_name];
        if (!is_array($wrapper)) {
            throw new RuntimeException("'$property_name' property is not an array; cannot extract embedded entities.");
        }
        static::checkEntitiesMetadata($wrapper, [], "'$property_name' property");

        if ($wrapper['start'] !== 0) {
            // This is unexpected; we don't know how embedded entities
            // implement paging and until we do, we disallow this. Supposedly
            // the only way to get a complete list is to perform the separate
            // API call for the specific entities and then call
            // getEntitiesNextBatch() until the set is complete.
            throw new RuntimeException("Set of entities inside '$property_name' property starts at {$wrapper['start']}; we cannot handle anything not starting at 0.", 804);
        }
        if ($throw_if_incomplete) {
            if (isset($wrapper['total']) && $wrapper['count'] !== $wrapper['total']) {
                throw new RuntimeException("Cannot return the total set of {$wrapper['totel']} entities inside '$property_name' property; only {$wrapper['count']} found.", 804);
            }
            if (!isset($wrapper['total']) && $wrapper['count'] === $wrapper['limit']) {
                // We're taking the risk of throwing an unnecessary
                // exception if limit == total; we can't check that. It's a bit
                // unfortunate, but by the time the caller hits 'limit' they
                // are likely to be in trouble anyway. (Making sure that there
                // is a 'total' value would have saved them only until just one
                // more embedded entity was added.)
                throw new RuntimeException("(Likely) cannot return the total set of entities inside '$property_name' property; only {$wrapper['count']} found but the total set is likely larger.", 804);
            }
        }

        return $wrapper['data'];
    }

    /**
     * Re-keys a list of entities.
     *
     * Entities returned by get(Embedded)Entities() are numerically keyed. Call
     * this to enable e.g. easier access by the 'ID' or 'name' value.
     *
     * @param array $entities
     *   A list of entities.
     * @param string $new_key_property
     *   The property which should be ued for the key.
     * @param bool $throw_if_incomplete
     *   (Optional) if False is passed, this method will not throw an exception
     *   but return an incomplete list instead, if the 'key' property is
     *   duplicate across entities or cannot be found/used.
     *
     * @return array
     */
    public static function rekeyEntities(array $entities, $new_key_property, $throw_if_incomplete = true)
    {
        $keyed = [];
        foreach ($entities as $orig_key => $entity) {
            if (!isset($entity[$new_key_property])) {
                if ($throw_if_incomplete) {
                    throw new RuntimeException("Embedded entity has no '$new_key_property' property.", 803);
                }
                continue;
            }
            if (!is_string($entity[$new_key_property]) && !is_int($entity[$new_key_property])) {
                if ($throw_if_incomplete) {
                    throw new RuntimeException("Embedded entity's' '$new_key_property' property has an invalid type.", 803);
                }
                continue;
            }
            if (isset($keyed[$entity[$new_key_property]]) && $throw_if_incomplete) {
                throw new RuntimeException("Multiple embedded entities contain the same '$new_key_property' value ({$entity[$new_key_property]}) multiple times.", 803);
            }
            $keyed[$entity[$new_key_property]] = $entity;
            // If the array is quite large, PHP might want to garbage collect
            // now-unnecessary memory at its convenience.
            unset($entities[$orig_key]);
        }
        return $keyed;
    }

    /**
     * Checks a data structure containing Copernica's 'list metadata'.
     *
     * This is a static copy of a RestClient method. It's slightly unfortunate
     * that we need to duplicate this helper code but that's not a reason to
     * make the classes interdependent yet.
     *
     * @param array $struct
     *   The structure, which is usually either the JSON-decoded response body
     *   from a GET query, or a property inside an entity which contains
     *   embedded entities.
     * @param array $parameters
     *   The parameters for the GET query returning this result. These are used
     *   to doublecheck some result properties.
     * @param string $struct_descn
     *   Description of the structure, for exception messages.
     *
     * @throws \RuntimeException
     *   If the result metadata are not successfully verified.
     *
     */
    protected static function checkEntitiesMetadata(array $struct, array $parameters, $struct_descn)
    {
        // We will throw an exception for any unexpected value. That may seem
        // way too strict but at least we'll know when something changes.
        foreach (['start', 'limit', 'count', 'total', 'data'] as $key) {
            if ($key !== 'total' || !isset($parameters['total']) || self::isBooleanTrue($parameters['total'])) {
                if (!isset($struct[$key])) {
                    throw new RuntimeException("Unexpected structure in $struct_descn: no '$key' value found.'", 804);
                }
                if ($key !== 'data' && !is_numeric($struct[$key])) {
                    throw new RuntimeException("Unexpected structure in $struct_descn: '$key' value (" . json_encode($struct[$key]) . ') is non-numeric.', 804);
                }
            }
        }
        if (!is_array($struct['data'])) {
            throw new RuntimeException("Unexpected structure in $struct_descn: 'data' value is not an array(" . json_encode($struct['count']) . ').', 804);
        }
        // Regardless of the paging stuff, 'count' should always be equal to
        // count of data.
        if ($struct['count'] !== count($struct['data'])) {
            throw new RuntimeException("Unexpected structure in $struct_descn: 'count' value (" . json_encode($struct['count']) . ") is not equal to number of array values in 'data' (" . count($struct['data']) . ').', 804);
        }

        $expected_start = isset($parameters['start']) ? $parameters['start'] : 0;
        if ($struct['start'] !== $expected_start) {
            throw new RuntimeException("Unexpected structure in $struct_descn: 'start' value is " . json_encode($struct['start']) . ' but is expected to be 0.', 804);
        }
        if ($struct['count'] > $struct['limit']) {
            throw new RuntimeException("Unexpected structure in response from Copernica API: 'count' value (" . json_encode($struct['count']) . ") is larger than 'limit' (" . json_encode($struct['limit']) . ').', 804);
        }
        if (isset($struct['total']) && $struct['start'] + $struct['count'] > $struct['total']) {
            throw new RuntimeException("Unexpected structure in $struct_descn: 'total' property (" . json_encode($struct['total']) . ") is smaller than start (" . json_encode($struct['start']) . ") + count (" . json_encode($struct['count']) . ").", 804);
        }
    }
}

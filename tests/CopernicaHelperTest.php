<?php

namespace CopernicaApi\Tests;

use CopernicaApi\CopernicaHelper;
use DateTime;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * Tests for CopernicaHelper.
 */
class CopernicaHelperTest extends TestCase
{
    /**
     * Tests 'normalization' of input values to values we want to store.
     *
     * Copernica never refuses field values or decides to discard them and take
     * the field default value; it always converts them to something.
     *
     * @dataProvider provideDataForNormalizeInputValue
     *
     * @param $destination_field_type
     *   Type of field which the value should be written into.
     * @param $input_value
     *   The value.
     * @param $expected_value
     *   Expected value - except if the field type is 'datetime' and this is
     *   an integer; then this is a timestamp and it's on us to transform it
     *   into (an) expected date representation(s), using PHP's default
     *   timezone. (Not whatever the API timezone is set to. Reason: the
     *   timestamp is also the result of converting whatever date string, using
     *   PHP's default timezone.)
     */
    public function testNormalizeInputValue($destination_field_type, $input_value, $expected_value, $struct_value = '')
    {
        // 'value' is mandatory for integers/floats. We're not using it.
        if ($struct_value === '' && in_array($destination_field_type, ['integer', 'float'], true)) {
            $struct_value = '-4';
        }
        $field_struct = ['type' => $destination_field_type, 'value' => $struct_value];
        if (is_array($expected_value)) {
            // This is expected to be a date expression + format. We need to
            // convert it at the last minute because
            // - The expression may contain dynamic components that convert to
            //   the current second; (see data provider;)
            // - The data providers are apparently executed before all tests,
            //   and our tests take several seconds to execute.
            // The below is basically a copy of normalizeInputValue() :-/
            $tz_obj = new DateTimeZone(CopernicaHelper::TIMEZONE_DEFAULT);
            $date = new DateTime($expected_value[0], $tz_obj);
            $date->setTimezone($tz_obj);

            $date2 = new DateTime();
            $date2->setTimezone($tz_obj);
            $date2->setTimestamp($date->getTimestamp() + 1);
            $expected_value = [$date->format($expected_value[1]), $date2->format($expected_value[1])];

            $value = CopernicaHelper::normalizeInputValue($input_value, $field_struct);
            $this->assertTrue(in_array($value, $expected_value, true), "$value is not among expected values " . implode(', ', $expected_value));
        } else {
            $value = CopernicaHelper::normalizeInputValue($input_value, $field_struct);
            $this->assertSame($expected_value, $value);
        }
    }

    /**
     * Provides data for testNormalizeInputValue() and ApiBehaviorTest code.
     *
     * At the same time (like many tests, but more prominently) this serves as
     * a 'specification' of how fields work on the live API.
     *
     * @return array[]
     */
    public function provideDataForNormalizeInputValue()
    {
        // 'Coincidentally' (probably not), the outcome exactly matches that of
        // PHP's type conversion to int/string/float, as I found out after
        // having tested all these cases and trying to recreate them in code...
        // But hey. Now that we have these 'specs', let's just implement them
        // as a test, for some overview.
        $data = [
            ['text', 'value', 'value'],
            ['text', '  value ', '  value '],
            ['text', true, '1'],
            ['text', false, ''],
            ['text', 0, '0'],
            ['text', -2, '-2'],
            ['text', 2.99, '2.99'],
            ['text', -2.99, '-2.99'],
            // This one isn't literally PHP's string conversion, though...
            ['text', ['any-kind-of-array'], ''],
            // Email field is not checked for valid e-mail. (The UI does that,
            // the REST API doesn't.) It's treated the same as string.
            ['email', 'value', 'value'],
            ['email', '  value ', '  value '],
            ['email', true, '1'],
            ['email', false, ''],
            ['email', 0, '0'],
            ['email', -2, '-2'],
            ['email', 2.99, '2.99'],
            ['email', -2.99, '-2.99'],
            ['email', ['any-kind-of-array'], ''],
            // Select fields only accept specific strings and convert all else
            // to "".
            // Values with leading/trailing spaces can be added through the UI,
            // and will then show up as-is in the field's 'value' property.
            // @todo: test that the API calls for adding/changing fields work
            //   the same, once we add them. We assume they do.
            // Apparently these values (the choides) are stripped of trailing
            // spaces, but not leading spaces, before being used. Input for
            // (sub)profile field values is not trimmed before being compared.
            // This follows from:
            // - Duplicate values with trailing spaces (e.g. "x" and "x ") do
            //   not show up as separate choices in the Copernica UI.
            // - Duplicate values with leading spaces do; "x" and " x" leads to
            //   a select element showing (visibly) two choices of "x".
            // - A select field whose 'value' is "x\r\n x\r\n`x " will accept
            //   "x" and " x" as input, but "x " will be converted to "". In
            //   other words, values with trailing spaces are never accepted.
            ['select', 'value1', 'value1', "value1\r\nvalue2"],
            ['select', 'value1', '', "VALUE1\r\nvalue2"],
            ['select', 'value', '', "value1\r\nvalue2"],
            ['select', 'value ', '', "value \r\n value\r\n`value"],
            ['select', ' value', ' value', "value \r\n value\r\n`value"],
            ['select', true, '1', "value\r\n1"],
            ['select', true, '', "value\r\n"],
            ['integer', 'value', 0],
            ['integer', '   3 ', 3],
            ['integer', 'true', 0],
            ['integer', true, 1],
            ['integer', false, 0],
            ['integer', -2, -2],
            ['integer', 2.99, 2],
            ['integer', -2.99, -2],
            ['integer', ['any-kind-of-array'], 1],
            ['float', 'value', 0.0],
            ['float', ' 2.99  ', 2.99],
            ['float', 'true', 0.0],
            ['float', true, 1.0],
            ['float', false, 0.0],
            ['float', -2, -2.0],
            ['float', 2.99, 2.99],
            ['float', -2.99, -2.99],
            ['float', ['any-kind-of-array'], 1.0],
        ];
        // Date specifications are a separate issue. First, we have inputs
        // whose matching output we know exactly:
        // - those that contain no dynamic parts AND whose input values don't
        //   specify a timezone.
        // - those that don't properly convert;
        // We can add these to the above array as-is; we just want to do it 4
        // times where, the empty output is "0000-00-00 00:00:00" for
        // 'datetime' fields and "0000-00-00" for 'date' fields.
        $dates1 = [
            ['2020-01-02 03:04:05', '2020-01-02 03:04:05'],
            ['  2020-01-02t03:04:05  ', '2020-01-02 03:04:05'],
            ['2020-01-02t03:04:05.987654', '2020-01-02 03:04:05'],
            ['2020-01-02 03:4', '2020-01-02 03:04:00'],
            ['2020-01-02 03:', ''],
            ['2020-01-02 03:04 02:00', ''],
            ['2020-01-02tz03:04', ''],
            ['2020-2', '2020-02-01 00:00:00'],
            ['2020 2', ''],
            ['value', ''],
            ['true', ''],
            ['', ''],
            [true, ''],
            [false, ''],
            // Timestamps don't work. (But they do when suffixed with '@'.)
            [1596979546, ''],
            [999, ''],
            [10000, ''],
        ];
        // Then we have input where the output value is dynamic:
        // - those whose input value specifies an explicit timezone. The
        //   output is dynamic because it changes with the API timezone.
        // - those where components of the input value are either dynamic (e.g.
        //   "now", "yesterday"), or they are unspecified and substituted by
        //   parts of "now".
        // This means:
        // - we need to calculate the expected output values, and the most
        //   obvious way is to use input values for strtotime() for that. Given
        //   TestApi also uses strtotime() to interpret dates, that means
        //   - the expected output value spec is the same as the input values
        //   - the code in the test is basically a copy of the tested code
        //   - so it's hard to see that we really can trust this test
        //   ...but I don't think there's a better way. And at least the below
        //   serves as some kind of specification.
        // - given that the conversion in TestApi happens slightly later, we'll
        //   need to specify two values in order to never have the test fail;
        //   the second one being 1 second later. Compare against both.
        // Incidentally: while constructing the input/output spec it became
        // obvious that the live API must be using strtotime() internally.
        // Which makes the added usefulnes of these tests even more doubtful.
        $dates2 = [
            ['2020-01-02 03:04:05z'],
            ['2020-01-02 03:04:05utc'],
            ['2020-01-02 03:04:05+03:00'],
            // In a few cases we're providing an expected output spec, but
            // that's just for some clarification. They are completely
            // equivalent to the input value.
            ['@1577934245', '2020-01-02 03:04:05z'],
            // Note the following also converts the same for 'date' types,
            // meaning that 1959 converts to today and 1960 converts to a
            // date in 1960!
            [1959, '19:59'], // YYYY-MM-DD 19:59:00 (in API tiemzone)
            [1960], // 1960-MM-DD HH:mm:ss
            ['now'],
            ['yesterday'],
        ];

        // Add the date conversions to the data array.
        foreach (['date', 'datetime', 'empty_date', 'empty_datetime'] as $type) {
            foreach ($dates1 as $input_output) {
                if ($input_output[1] === '') {
                    if ($type === 'date') {
                        $input_output[1] = '0000-00-00';
                    } elseif ($type === 'datetime') {
                        $input_output[1] = '0000-00-00 00:00:00';
                    }
                } elseif (substr($type, -4) === 'date') {
                    // Non-empty values are alway 19 chars long for $dates1.
                    // Strip time.
                    $input_output[1] = substr($input_output[1], 0, 10);
                }
                $data[] = [$type, $input_output[0], $input_output[1]];
            }

            $format = substr($type, -4) === 'date' ? 'Y-m-d' : 'Y-m-d H:i:s';
            foreach ($dates2 as $input_output) {
                $expected_output = isset($input_output[1]) ? $input_output[1] : $input_output[0];
                // Do last-minute conversion of date in the test itself.
                $data[] = [$type, $input_output[0], [$expected_output, $format]];
            }
        }

        return $data;
    }
}

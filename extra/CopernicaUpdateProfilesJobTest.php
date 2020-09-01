<?php

/**
 * @file
 * PHPUnit test class to test a synchronization job class / processes.
 *
 * This is provided as an example, for the moment. I'm running this
 * occasionally on an application's test environment, manually, which is why
 * I left all the ugly initialization code in.
 *
 * This tests parts of a class (CopernicaUpdateProfilesJob) and at least one
 * synchronization process implemented using this class.
 * CopernicaUpdateProfilesJob is not in this Github repository yet. I'm on the
 * fence about it because:
 * - While very useful for implementing a synchronization process of a
 *   remote system's data into Copernica profiles/subprofiles, it is not
 *   immediately usable by many people. The biggest part (the updating logic)
 *   is usable and works well, but the class is built upon a "process runner"
 *   (that takes care of scheduling/running the whole process) that is tightly
 *   integrated with a Drupal 7 site: https://www.drupal.org/project/drunkins.
 *   You'll likely have to pry it loose from Drupal in order to use it. (The
 *   fact that this test class is runnable by PHPUnit after require'ing a
 *   parent class + interface file from the Drunkins module, proves that this
 *   is very much possible; it's just quite ugly at the moment.)
 * - CopernicaUpdateProfilesJob represents 100+ hours of work with the vast
 *   majority paid by one customer. I might want to check with them before
 *   making 'their' synchronization available for free.
 */

use CopernicaApi\CopernicaRestClient;
use CopernicaApi\Tests\TestApi;
use CopernicaApi\Tests\TestApiFactory;
use PHPUnit\Framework\TestCase;

// phpcs:disable

// Decoupling Drunkins Jobs from Drupal 7 is still a work in progress. Until
// that's finished, emulate some functions/constants.
if (!defined('WATCHDOG_ERROR')) {
    define('WATCHDOG_EMERGENCY', 0);
    define('WATCHDOG_ALERT', 1);
    define('WATCHDOG_CRITICAL', 2);
    define('WATCHDOG_ERROR', 3);
    define('WATCHDOG_WARNING', 4);
    define('WATCHDOG_NOTICE', 5);
    define('WATCHDOG_INFO', 6);
    define('WATCHDOG_DEBUG', 7);
}

if (!function_exists('t')) {
    // Replace $args into $string. (Original function is for translation.) We
    // likely don't need this function because we've only used it in the job's
    // finish() method which we don't have to call... but... just to be sure.
    // (And now that we define it, we'll use it in __drunkins_log() too.)
    function t($string, array $args = array(), array $options = array())
    {
        foreach ($args as $token => $text) {
            $string = str_replace($token, $text, $string);
        }
        return $string;
    }
}

if (!function_exists('__drunkins_log')) {
    // Replace $args into $string. (Original function is for translation.)
    function __drunkins_log($job_id, $message, array $variables, $severity, array $settings, $repeat = null)
    {
        if (isset($GLOBALS['drunkins_log_test_threshold'])
            && is_numeric($GLOBALS['drunkins_log_test_threshold'])
            && $severity <= $GLOBALS['drunkins_log_test_threshold']
        ) {
            if (!isset($GLOBALS['drunkins_log_test_stack'])
                || !is_array($GLOBALS['drunkins_log_test_stack'])) {
                $GLOBALS['drunkins_log_test_stack'] = [];
            }
            $GLOBALS['drunkins_log_test_stack'][] = [$message, $variables];
            // Not exactly sure yet what is the best way of making logs stand
            // out in between PHPUnit output but not mess up other things.
            if (!empty($GLOBALS['drunkins_log_test_screen'])) {
              print '[LOG] >> ' . t($message, $variables) . "\n";
              // fwrite(STDERR, t($message, $variables) . "\n");
            }
        }
    }
}

// This is the structure of my Drupal 7 site (because noone else will probably
// run these tests anyway). Include all dependencies of the Drunkins job.
require_once(__DIR__ . '/../../../../modules/contrib/drunkins/classes/fetcher.inc');
require_once(__DIR__ . '/../../../../modules/contrib/drunkins/classes/job.inc');
require_once(__DIR__ . '/../../../../modules/custom/ygsync/CopernicaUpdateProfilesJob.php');
require_once(__DIR__ . '/../../../../modules/custom/ygsync/CopernicaSubprofileContainer.php');
// I don't want to think about why this is not autoloaded:
require_once(__DIR__ . '/../tests/TestApiFactory.php');
require_once(__DIR__ . '/../tests/TestApi.php');

// phpcs:enable

/**
 * Test case for testing CopernicaUpdateProfilesJob.
 *
 * This implicitly depends on the profile functionality from Copernica being
 * fully known / specified / tested in copernica-api's ApiBehavorTest so we
 * have a stable base to build on.
 *
 * Most tests have to be exercised against an API 'backend', for which we've
 * hardcoded the TestApi class.
 *
 * Contains:
 * - a unit test for a method (testGetItemKeyFieldValue());
 * - a test of a method that cannot be unit tested, only(?) be exercised by
 *   passing items through a job, i.e. calling processItem() repeatedly
 *   (testGetMainProfilesByKey());
 * - a test for a type of job (i.e. a specific configuration) that tests all
 *   behavior we can think of by passing sets of items through it.
 * There are a ton of scenarios/job types that haven't been implemented yet,
 * for lack of budget. There are probably a few more things that can be tested
 * in a 'method centric' way (bullet 1/2) which would be nice, but we'll likely
 * end up mostly implementing 'scenario/job configuration centric' tests.
 */
// Above should be in global namespace. Using two namespaces in the same file
// has its own issues, so we put this test class in global and mute PHPCS.
// phpcs:ignore PSR1.Classes.ClassDeclaration
class CopernicaUpdateProfilesJobTest extends TestCase
{

    /**
     * ID for our copernica database. The value doesn't matter.
     *
     * @todo maybe make this (and below) a random value because that's
     *   theoretically better for writing good tests.
     *
     * @var int
     */
    const DATABASE_ID = 3;

    /**
     * ID for the collection in our copernica database. Value doesn't matter.
     *
     * @var int
     */
    const COLLECTION_ID = 45;

    /**
     * Fake Copernica token used with TestApi. Value doesn't matter.
     *
     * @var string
     */
    const TOKEN = 'MyTestToken';

    /**
     * Returns some reusable field settings.
     */
    private function getFieldSettings($get_field_names_to_test = false)
    {
        $settings = [
            'myName' => 'text',
            'myNameIns' => ['type' => 'text', 'compare_case_insensitive' => true],
            'myEmail' => 'email',
            'myEmailIns' => ['type' => 'email', 'compare_case_insensitive' => true],
            // We'll assume that 'zero_can_overwrite' only applies to integer /
            // float. (We don't know a practical application of resetting it to
            // False for float, but hwy. It's possible.)
            'myInt' => 'integer',
            'myId' => ['type' => 'integer', 'zero_can_overwrite' => false],
            'myFloat' => 'float',
            'myNonZeroFloat' => ['type' => 'float', 'zero_can_overwrite' => false],
            'myDate' => 'date',
            'myDateEmpty' => 'empty_date',
            'myDateTime' => 'datetime',
            'myDateTimeEmpty' => 'empty_datetime',
        ];
        if ($get_field_names_to_test) {
            // We also need to return an undefined field name among all the
            // fields to test - because defining fields is opotional.
            $settings = array_merge(['myUnknownTypeField'], array_keys($settings));
        }

        return $settings;
    }

    /**
     * Tests a series of fields for emptiness of a value.
     *
     * Contains a hack so that we can reuse this for 'equality' testing too.
     */
    private function doTestEmpty($test_value, array $supposed_empty_fields, $value_is_new_value = null)
    {
        if (empty($GLOBALS['test_equality_instead_of_emptiness'])) {
            $job = $this->getJobWithAccessibleMethods(['copernica_field_settings' => $this->getFieldSettings()]);
            // For completeness, test both true and false for the third
            //  argument, unless it was explicitly specified.
            $loggable = var_export($test_value, true);
            if ($value_is_new_value === null || empty($value_is_new_value)) {
                foreach ($supposed_empty_fields as $field_name) {
                    $this->assertTrue($job->isFieldValueEmptyTest($test_value, $field_name, false), "isFieldValueEmpty($loggable, '$field_name', false) should be TRUE.");
                }
                // All fields except the ones specified should be non-empty.
                foreach (array_diff($this->getFieldSettings(true), $supposed_empty_fields) as $field_name) {
                    $this->assertFalse($job->isFieldValueEmptyTest($test_value, $field_name, false), "isFieldValueEmpty($loggable, '$field_name', false) should be FALSE.");
                }
            }
            if ($value_is_new_value === null || !empty($value_is_new_value)) {
                foreach ($supposed_empty_fields as $field_name) {
                    $this->assertTrue($job->isFieldValueEmptyTest($test_value, $field_name, true), "isFieldValueEmpty($loggable, '$field_name', true) should be TRUE.");
                }
                foreach (array_diff($this->getFieldSettings(true), $supposed_empty_fields) as $field_name) {
                    $this->assertFalse($job->isFieldValueEmptyTest($test_value, $field_name, true), "isFieldValueEmpty($loggable, '$field_name', true) should be FALSE.");
                }
            }
        } elseif ($value_is_new_value !== true) {
            // Dirty hack in action: do essentially the same tests (specified
            // by testIsFieldValueEmpty()), but test isFieldValueEqual. The
            // special isFieldValueEmpty() $value_is_new_value=true 'hack' does
            // not apply here, so we're skipping that.
            if ($GLOBALS['test_equality_instead_of_emptiness'] === 'updatability') {
                // Uhm actually, test updatability instead of equality.
                $this->doTestUpdatable($test_value, '', array_diff($this->getFieldSettings(true), $supposed_empty_fields));
            } else {
                $this->doTestEqual($test_value, '', $supposed_empty_fields);
                $this->doTestEqual($test_value, null, $supposed_empty_fields);
            }
        }
    }

    /**
     * Tests a series of fields for equality of values.
     */
    private function doTestEqual($test_value1, $test_value2, array $supposed_equal_fields)
    {
        $job = $this->getJobWithAccessibleMethods(['copernica_field_settings' => $this->getFieldSettings()]);

        $loggable1 = var_export($test_value1, true);
        $loggable2 = var_export($test_value2, true);
        foreach ($supposed_equal_fields as $field_name) {
            // Test both ways, which should make no difference, because we can.
            $this->assertTrue($job->isFieldValueEqualTest($test_value1, $test_value2, $field_name), "isFieldValueEqual($loggable1, $loggable2, '$field_name') should be TRUE.");
            $this->assertTrue($job->isFieldValueEqualTest($test_value2, $test_value1, $field_name), "isFieldValueEqual($loggable2, $loggable1, '$field_name') should be TRUE.");
        }
        foreach (array_diff($this->getFieldSettings(true), $supposed_equal_fields) as $field_name) {
            $this->assertFalse($job->isFieldValueEqualTest($test_value1, $test_value2, $field_name), "isFieldValueEqual($loggable1, $loggable2, '$field_name') should be FALSE.");
            $this->assertFalse($job->isFieldValueEqualTest($test_value2, $test_value1, $field_name), "isFieldValueEqual($loggable2, $loggable1, '$field_name') should be FALSE.");
        }
    }

    /**
     * Tests a series of fields for 'updatability' of a value.
     *
     * @param mixed $new_value
     *   The value that possibly should be updated in Copernica
     * @param mixed $existing_value
     *   The value inside the fields. This is always a string in a Copernica
     *   object but might be something else if the object was cached.
     * @param array $supposed_updatable_fields
     *   The subset of fields which should be updatable to $new_value if their
     *   current value is $existing_value.
     * @param array $extra_job_settings
     *   Extra settings to initialize the job class with.
     */
    private function doTestUpdatable($new_value, $existing_value, array $supposed_updatable_fields, array $extra_job_settings = [])
    {
        $job = $this->getJobWithAccessibleMethods($extra_job_settings + ['copernica_field_settings' => $this->getFieldSettings()]);

        $loggable1 = var_export($existing_value, true);
        $loggable2 = var_export($new_value, true);
        foreach ($supposed_updatable_fields as $field_name) {
            // Prepopulate fake item with existing value. We'll treat NULL as
            // "field does not exist" and assume "field exists with value NULL"
            // does not need to be tested explicitly.
            $fake_profile = ['fields' => isset($existing_value) ? [$field_name => $existing_value] : []];
            $this->assertTrue($job->shouldUpdateTest($new_value, $fake_profile, $field_name), "Field $field_name should be updatable from $loggable1 to $loggable2.");
        }
        foreach (array_diff($this->getFieldSettings(true), $supposed_updatable_fields) as $field_name) {
            $fake_profile = ['fields' => isset($existing_value) ? [$field_name => $existing_value] : []];
            $this->assertFalse($job->shouldUpdateTest($new_value, $fake_profile, $field_name), "Field $field_name should not be updatable from $loggable1 to $loggable2.");
        }
    }

    /**
     * Tests isFieldValueEmpty().
     *
     */
    public function testIsFieldValueEmpty()
    {
        // Explicitly testing all unimportant details could serve as a good
        // specification / reference, so let's do it.
        $all_testable_fields = $this->getFieldSettings(true);
        // All non-number/date fields are 'treated as strings'. (And all values
        // except ''/null are nonempty for those fields.)
        $number_fields = ['myInt', 'myId', 'myFloat', 'myNonZeroFloat'];
        $date_fields = ['myDate', 'myDateEmpty', 'myDateTime', 'myDateTimeEmpty'];

        // Empty string / null / false are always considered empty; ''
        // figures because Copernica always returns empty string (not null)
        // in REST API responses, for empty fields, where needed.
        $this->doTestEmpty('', $all_testable_fields);
        $this->doTestEmpty(null, $all_testable_fields);
        $this->doTestEmpty(false, $all_testable_fields);
        // A single space: nonempty for 'string' fields / all others are empty.
        $this->doTestEmpty(' ', array_merge($number_fields, $date_fields));

        // Zero: ...here is where some weirdness comes in: emptiness of item
        // fields is not always the same as in Copernica - not if
        // 'zero_can_overwrite' is true (which is set in the constructor
        // for integer/float):
        $this->doTestEmpty(0, array_merge($number_fields, $date_fields), false);
        $this->doTestEmpty('0', array_merge($number_fields, $date_fields), false);
        // So 0 is nonempty when being inserted into 'myInt/myFloat' but empty
        // when being inserted to 'myId' (or the equivalent float).
        $this->doTestEmpty(0, array_merge(['myId', 'myNonZeroFloat'], $date_fields), true);
        $this->doTestEmpty('0', array_merge(['myId', 'myNonZeroFloat'], $date_fields), true);

        // 1: can't be converted to date, so empty for dates.
        $this->doTestEmpty(1, $date_fields);

        // Random strings: always empty for 'number' fields; whether they
        // are empty for date fields, depends on... strtotime() logic
        // which we don't really know about. Dates are nonempty for 'a' (which
        // is 'now' in military timezone Alpha), but empty (invalid) for 'aa':
        $this->doTestEmpty('a', $number_fields);
        $this->doTestEmpty('aa', array_merge($number_fields, $date_fields));

        // Legal dates are non-empty for all fields.
        $this->doTestEmpty('1900-01-01 00:00', []);
        // Most illegal date expressions convert to numbers (1900), except the
        // rest of the edge cases that start with 0000.
        $this->doTestEmpty('1900-01-001 00:00', $date_fields);
        // Some edge cases of dates may be unexpected:
        // 0000-00-00 is empty because it converts to the 'empty' value for all
        // dates. regardless of the time specification.
        $this->doTestEmpty('0000-00-00', array_merge($number_fields, $date_fields));
        $this->doTestEmpty('0000-00-00 23:59:59', array_merge($number_fields, $date_fields));
        // 0000-00-01 00:00:00, on the other hand, is not empty - it, and all
        // date specifications that are 'legal' for strtotime() but would
        // result in a negative year number, convert to "0000-00-00"... which
        // is _not the empty value_ for empty_date(time) fields.
        $this->doTestEmpty('0000-00-01', array_merge($number_fields, ['myDate', 'myDateTime']));
        $this->doTestEmpty('0000-00-01 00:00', array_merge($number_fields, ['myDate', 'myDateTime']));
        // Illegal dates (which strtotime() does not recognize) are empty.
        $this->doTestEmpty('0000-00-01 00:00:', array_merge($number_fields, $date_fields));

        // True: is empty for dates. Arrays: empty for dates and strings -
        // because that's how Copernica treats them - but: not empty for
        // unknown field types.
        $this->doTestEmpty(['any-nonempty-array'], array_diff($all_testable_fields, $number_fields, ['myUnknownTypeField']));
        $this->doTestEmpty([], array_diff($all_testable_fields, ['myUnknownTypeField']));
        $this->doTestEmpty(true, $date_fields);
    }

    /**
     * Tests getCopernicaFieldSettings().
     *
     * Implicit dependencies are messed up to keep the tests small:
     * isFieldValueEmpty() calls getCopernicaFieldSettings() - but this test
     * implicitly depends on testIsFieldValueEmpty() / assumes it succeeds, so
     * that we only have to test one small aspect of getCopernicaFieldSettings()
     * which isn't in testIsFieldValueEmpty() because it doesn't fit with the
     * array diffing/merging: case sensitivity of the field names.
     */
    public function testGetCopernicaFieldSettings()
    {
        $job = $this->getJobWithAccessibleMethods(['copernica_field_settings' => $this->getFieldSettings()]);
        // See testIsFieldValueEmpty(): 0000-00-01 is nonempty for almost all
        // fields (including e.g. myDateEmpty) but empty for myDate. If this
        // correctly asserts True, that means it matches case as needed.
        $this->assertTrue($job->isFieldValueEmptyTest('0000-00-01', 'MyDATE'));
    }

    /**
     * Tests isFieldValueEqual().
     *
     * We test all combinations of values + field types against each other,
     * even for values + field types that don't really match, because:
     * - it makes tests more readable (by calling one helper function)
     * - the value may be stored in a cached item that doesn't come literally
     *   from Copernica, in which case it could be anything.
     */
    public function testIsFieldValueEqual()
    {
        // First of all, "equality with ''" should be the same as "emptiness".
        // This is clear from the code, but the code might change and we should
        // ideally test for that... and the best way to do this is to replicate
        // the exact tests which we're doing in testIsFieldValueEmpty(). We
        // introduce a dirty hack in doTestEmpty() to make this possible, which
        // is arguably better than duplicating code.
        $GLOBALS['test_equality_instead_of_emptiness'] = true;
        $this->testIsFieldValueEmpty();
        unset($GLOBALS['test_equality_instead_of_emptiness']);

        $all_testable_fields = $this->getFieldSettings(true);
        $number_fields = ['myInt', 'myId', 'myFloat', 'myNonZeroFloat'];
        $date_fields = ['myDate', 'myDateEmpty', 'myDateTime', 'myDateTimeEmpty'];
        $string_fields = ['myUnknownTypeField', 'myName', 'myNameIns', 'myEmail', 'myEmailIns'];

        // String against string: differing case is unequal for 'normal'
        // string fields. Equal for 'case insensitive' string fields & others.
        $this->doTestEqual('a', 'a', $all_testable_fields);
        $this->doTestEqual('a', 'A', array_diff($all_testable_fields, ['myName', 'myEmail', 'myUnknownTypeField']));

        // Zeroes: unequal to '' for string fields. Equal for others. (We do
        // this because it's significant. But actually it's the same as testing
        // doTestEmpty('0'). We won't test everything against '' again below.)
        $this->doTestEqual('0', '', array_merge($number_fields, $date_fields));
        $this->doTestEqual(0, '', array_merge($number_fields, $date_fields));

        // Test some strange values against each other. Admittedly this largely
        // duplicates normalizeInputValue(), but it's a more concise 'spec'. We
        // won't do an exhaustive comparison of weird date formats though; no
        // need to totally duplicate normalizeInputValue() /
        // testIsFieldValueEmpty().
        //
        // False always == '' (because it's empty). So 0 != false for strings;
        // basically the same as 0 != '', just above.
        $this->doTestEqual(0, false, array_merge($number_fields, $date_fields));
        // Zeroes are equal to strings for number fields. For date fields...
        // that depends on the string. (See testIsFieldValueEmpty().)
        $this->doTestEqual(0, 'a', $number_fields);
        $this->doTestEqual(0, 'aa', array_merge($number_fields, $date_fields));
        $this->doTestEqual([], 0, array_merge($number_fields, $date_fields));
        // 1 == true for... everything. (Because true is empty for dates, '1'
        // for strings.)
        $this->doTestEqual(true, 1, $all_testable_fields);
        // Nonempty array converts to 1 for numbers, '' for strings; All arrays
        // (and all booleans, and 1) are empty for dates. So:
        $this->doTestEqual(['any-nonempty-array'], 1, array_merge($number_fields, $date_fields));
        $this->doTestEqual(['any-nonempty-array'], false, array_merge(array_diff($string_fields, ['myUnknownTypeField']), $date_fields));
        $this->doTestEqual([], true, $date_fields);
        $this->doTestEqual([], false, array_diff($all_testable_fields, ['myUnknownTypeField']));
    }

    /**
     * Tests shouldCopernicaFieldBeUpdated().
     *
     * We test all combinations of values + field types against each other,
     * even for values + field types that don't really match, because:
     * - it makes tests more readable (by calling one helper function)
     * - the value may be stored in a cached item that doesn't come literally
     *   from Copernica, in which case it could be anything.
     * This is all quite similar to testIsFieldValueEqual(), but hey, we should
     * test what little code is in shouldCopernicaFieldBeUpdated() separately.
     */
    public function testShouldCopernicaFieldBeUpdated()
    {
        $all_testable_fields = $this->getFieldSettings(true);
        $number_fields = ['myInt', 'myId', 'myFloat', 'myNonZeroFloat'];
        $string_fields = ['myUnknownTypeField', 'myName', 'myNameIns', 'myEmail', 'myEmailIns'];

        // First of all, "updatability from ''" should equal "emptiness".
        // This is clear from the code, but the code might change and we should
        // ideally test for that... and the best way to do this is to replicate
        // the exact tests which we're doing in testIsFieldValueEmpty(). We
        // introduce a dirty hack in doTestEmpty() to make this possible, which
        // is arguably better than duplicating code.
        $GLOBALS['test_equality_instead_of_emptiness'] = 'updatability';
        $this->testIsFieldValueEmpty();
        unset($GLOBALS['test_equality_instead_of_emptiness']);

        // Zeroes: unequal to '' for string fields. Equal for others. We won't
        // cycle through all 'strange' values because that will just end up
        // being a modified testIsFieldValueEqual(), with inverted copies of
        // the doTestEqual() calls. This means we're largely ignoring "when do
        // date fields update", which is fine; we can derive that they're OK
        // from the combination of testIsFieldValueEqual() and the fact that
        // non-date fields are tested well here.
        $this->doTestUpdatable('0', '', $string_fields);
        $this->doTestUpdatable(0, '', $string_fields);

        // String against string: differing case is unequal for 'normal'
        // string fields. Equal for 'case insensitive' string fields & others.
        $this->doTestUpdatable('a', 'a', []);
        $this->doTestUpdatable('a', 'A', ['myName', 'myEmail', 'myUnknownTypeField']);
        // (Let's not test differing one-character strings here, because
        // they represent timezones which means it's hard to work out when a
        // date-without-time field is updatable.)
        $this->doTestUpdatable('aa', 'bb', $string_fields);
        // We can't update numeric fields from 2 to 'aa' == 0, because that's
        // the empty value. We can update them from 'aa' (0) to 2.
        $this->doTestUpdatable('aa', 2, $string_fields);
        $this->doTestUpdatable(2, 'aa', array_merge($string_fields, $number_fields));
        $this->doTestUpdatable(2, 0, array_merge($string_fields, $number_fields));
        // In contrast, updating to 0 specifically is also possible for
        // 'default' numeric fields, which don't treat 0 as empty _for an item
        // value that is being imported_. (Indeed, to repeat: this means that
        // emptiness of 0 values is treated 'asymetrically' for those fields.)
        $this->doTestUpdatable(0, 2, array_merge($string_fields, ['myInt', 'myFloat']));

        // If 'prevent_overwrite_profile_fields' is true, no fields are
        // updatable.
        $this->doTestUpdatable(3, 2, [], ['prevent_overwrite_profile_fields' => true]);
        // All fields are updatable from '' (except invalid date expressions
        // are empty -> not updatable for date fields).
        $this->doTestUpdatable(3, '', array_merge($string_fields, $number_fields), ['prevent_overwrite_profile_fields' => true]);
        $this->doTestUpdatable('1900-01-01', '', $all_testable_fields, ['prevent_overwrite_profile_fields' => true]);
        // And only numbers are updatable from zero. Yes, all numbers, because
        // by default 0 is not considered empty when being updated from an
        // item, but it is always empty when in Copernica.
        $this->doTestUpdatable(3, 0, $number_fields, ['prevent_overwrite_profile_fields' => true]);
        // Obviously nothing is updtable to 0, just as it wasn't from 2 to 3.
        $this->doTestUpdatable(0, 3, [], ['prevent_overwrite_profile_fields' => true]);
    }

    /**
     * Tests getItemKeyFieldValue().
     */
    public function testGetItemKeyFieldValue()
    {
        // Job with single key_field setting.
        $job = $this->getJobWithAccessibleMethods(['copernica_profile_key_field' => 'email', 'key_field_validate_email' => true]);
        $item = ['email' => 'rm@wyz.biz'];
        $value = $job->getItemKeyFieldValueTest($item);
        $this->assertSame(['email' => 'rm@wyz.biz'], $value);
        $value = $job->getItemKeyFieldValueTest($item, true, 'until-nonempty');
        $this->assertSame(['email' => 'rm@wyz.biz'], $value);
        $value = $job->getItemKeyFieldValueTest($item, true, 'all');
        $this->assertSame(['email' => 'rm@wyz.biz'], $value);
        $value = $job->getItemKeyFieldValueTest($item, true, 'loggable');
        $this->assertSame('"rm@wyz.biz"', $value);
        $value = $job->getItemKeyFieldValueTest($item, true, 0);
        $this->assertSame('rm@wyz.biz', $value);
        $this->assertExceptionForGetItemKeyFieldValue($job, $item, false, 1, LogicException::class, "Key 1 not found in 'copernica_profile_key_field' setting.");
        // Test invalid e-mail, with $invalid arg being true/false:
        $item = ['email' => 'invalid@'];
        $this->assertExceptionForGetItemKeyFieldValue($job, $item, true, 0, UnexpectedValueException::class, "'email' field contains an invalid e-mail address: \"invalid@\".");
        $value = $job->getItemKeyFieldValueTest($item, false, 0);
        $this->assertSame('invalid@', $value);
        // ...also without validation setting:
        $job = $this->getJobWithAccessibleMethods(['copernica_profile_key_field' => 'email']);
        $value = $job->getItemKeyFieldValueTest($item, true, 0);
        $this->assertSame('invalid@', $value);
        // Test empty e-mail
        $item = ['email' => ''];
        $this->assertExceptionForGetItemKeyFieldValue($job, $item, true, 'key', UnexpectedValueException::class, "Item does not contain a value for 'email' field(s).");
        $this->assertExceptionForGetItemKeyFieldValue($job, $item, true, 'until-nonempty', UnexpectedValueException::class, "Item does not contain a value for 'email' field(s).");
        $value = $job->getItemKeyFieldValueTest($item, false);
        $this->assertSame([], $value);
        $value = $job->getItemKeyFieldValueTest($item, false, 'until-nonempty');
        $this->assertSame(['email' => null], $value);
        $this->assertExceptionForGetItemKeyFieldValue($job, $item, true, 'all', UnexpectedValueException::class, "Item does not contain a value for 'email' field(s).");
        $value = $job->getItemKeyFieldValueTest($item, false, 'all');
        $this->assertSame(['email' => null], $value);
        $value = $job->getItemKeyFieldValueTest($item, true, 'loggable');
        $this->assertSame('<empty>', $value);
        $value = $job->getItemKeyFieldValueTest($item, true, 0);
        $this->assertSame(null, $value);

        // Job with key_field being a single-element array: makes no difference.
        $job = $this->getJobWithAccessibleMethods(['copernica_profile_key_field' => ['email'], 'key_field_validate_email' => true]);
        $item = ['email' => 'rm@wyz.biz'];
        $value = $job->getItemKeyFieldValueTest($item);
        $this->assertSame(['email' => 'rm@wyz.biz'], $value);
        $value = $job->getItemKeyFieldValueTest($item, true, 'until-nonempty');
        $this->assertSame(['email' => 'rm@wyz.biz'], $value);
        $value = $job->getItemKeyFieldValueTest($item, true, 'all');
        $this->assertSame(['email' => 'rm@wyz.biz'], $value);
        $value = $job->getItemKeyFieldValueTest($item, true, 'loggable');
        $this->assertSame('"rm@wyz.biz"', $value);
        $value = $job->getItemKeyFieldValueTest($item, true, 0);
        $this->assertSame('rm@wyz.biz', $value);
        $this->assertExceptionForGetItemKeyFieldValue($job, $item, false, 1, LogicException::class, "Key 1 not found in 'copernica_profile_key_field' setting.");
        $item = ['email' => 'invalid@'];
        $this->assertExceptionForGetItemKeyFieldValue($job, $item, true, 0, UnexpectedValueException::class, "'email' field contains an invalid e-mail address: \"invalid@\".");
        $value = $job->getItemKeyFieldValueTest($item, false, 0);
        $this->assertSame('invalid@', $value);
        $job = $this->getJobWithAccessibleMethods(['copernica_profile_key_field' => ['email']]);
        $value = $job->getItemKeyFieldValueTest($item, true, 0);
        $this->assertSame('invalid@', $value);
        $item = ['email' => ''];
        $this->assertExceptionForGetItemKeyFieldValue($job, $item, true, 'key', UnexpectedValueException::class, "Item does not contain a value for 'email' field(s).");
        $this->assertExceptionForGetItemKeyFieldValue($job, $item, true, 'until-nonempty', UnexpectedValueException::class, "Item does not contain a value for 'email' field(s).");
        $value = $job->getItemKeyFieldValueTest($item, false);
        $this->assertSame([], $value);
        $value = $job->getItemKeyFieldValueTest($item, false, 'until-nonempty');
        $this->assertSame(['email' => null], $value);
        $this->assertExceptionForGetItemKeyFieldValue($job, $item, true, 'all', UnexpectedValueException::class, "Item does not contain a value for 'email' field(s).");
        $value = $job->getItemKeyFieldValueTest($item, false, 'all');
        $this->assertSame(['email' => null], $value);
        $value = $job->getItemKeyFieldValueTest($item, true, 'loggable');
        $this->assertSame('<empty>', $value);
        $value = $job->getItemKeyFieldValueTest($item, true, 0);
        $this->assertSame(null, $value);

        // Job with key_field being two fields.
        $job = $this->getJobWithAccessibleMethods([
          'copernica_profile_key_field' => ['OptionalId', 'email'],
          'copernica_profile_fields' => ['OptionalId' => 'OptionalIdInCopernica'],
          'copernica_field_settings' => ['OptionalIdInCopernica' => ['type' => 'integer', 'zero_can_overwrite' => false]]
        ]);
        // If only the first key is present:
        $item = ['OptionalId' => 5];
        $value = $job->getItemKeyFieldValueTest($item);
        $this->assertSame(['OptionalId' => 5], $value);
        $value = $job->getItemKeyFieldValueTest($item, true, 'until-nonempty');
        $this->assertSame(['OptionalId' => 5], $value);
        $value = $job->getItemKeyFieldValueTest($item, true, 'all');
        $this->assertSame(['OptionalId' => 5, 'email' => null], $value);
        $value = $job->getItemKeyFieldValueTest($item, true, 'loggable');
        $this->assertSame('5', $value);
        $value = $job->getItemKeyFieldValueTest($item, true, 0);
        $this->assertSame(5, $value);
        $value = $job->getItemKeyFieldValueTest($item, true, 1);
        $this->assertSame(null, $value);
        $this->assertExceptionForGetItemKeyFieldValue($job, $item, false, 2, LogicException::class, "Key 2 not found in 'copernica_profile_key_field' setting.");

        // Both keys are present:
        $item = ['OptionalId' => 5, 'email' => 'invalid@'];
        $value = $job->getItemKeyFieldValueTest($item);
        $this->assertSame(['OptionalId' => 5], $value);
        $value = $job->getItemKeyFieldValueTest($item, true, 'until-nonempty');
        $this->assertSame(['OptionalId' => 5], $value);
        $value = $job->getItemKeyFieldValueTest($item, true, 'all');
        // Invalid e-mail does not get checked if e-mail is not the first field.
        // (Whether or not that makes sense... is a question for later.)
        $this->assertSame(['OptionalId' => 5, 'email' => 'invalid@'], $value);
        $value = $job->getItemKeyFieldValueTest($item, true, 'loggable');
        $this->assertSame('5', $value);
        $value = $job->getItemKeyFieldValueTest($item, true, 0);
        $this->assertSame(5, $value);
        $value = $job->getItemKeyFieldValueTest($item, true, 1);
        $this->assertSame('invalid@', $value);
        $this->assertExceptionForGetItemKeyFieldValue($job, $item, false, 2, LogicException::class, "Key 2 not found in 'copernica_profile_key_field' setting.");

        // Only non-first key is present (0 does not count for zero_is_empty);
        // this also does some implicit non-exhaustive testing for
        // isFieldValueEmpty() / getCopernicaProfileFieldName() /
        // getCopernicaFieldType(), which have no own unit tests):
        $item = ['OptionalId' => 0, 'email' => 'rm@wyz.biz'];
        $value = $job->getItemKeyFieldValueTest($item);
        $this->assertSame(['email' => 'rm@wyz.biz'], $value);
        $value = $job->getItemKeyFieldValueTest($item, true, 'until-nonempty');
        $this->assertSame(['OptionalId' => null, 'email' => 'rm@wyz.biz'], $value);
        $value = $job->getItemKeyFieldValueTest($item, true, 'all');
        $this->assertSame(['OptionalId' => null, 'email' => 'rm@wyz.biz'], $value);
        $value = $job->getItemKeyFieldValueTest($item, true, 'loggable');
        $this->assertSame('email="rm@wyz.biz"', $value);
        $value = $job->getItemKeyFieldValueTest($item, true, 0);
        $this->assertSame(null, $value);
        $value = $job->getItemKeyFieldValueTest($item, true, 1);
        $this->assertSame('rm@wyz.biz', $value);
        $item = ['email' => 'invalid@'];
        $value = $job->getItemKeyFieldValueTest($item, true, 1);
        $this->assertSame('invalid@', $value);

        // 0 is a key for the 'email' field because 0 is not an empty value
        // for string-like fields. (We treat 0 like any other string; this code
        // block is only here, copied from above, to shake out any code that
        // would treat empty-but-not-really-empty values in the wrong way.)
        $item = ['OptionalId' => 0, 'email' => 0];
        $value = $job->getItemKeyFieldValueTest($item);
        $this->assertSame(['email' => 0], $value);
        $value = $job->getItemKeyFieldValueTest($item, true, 'until-nonempty');
        $this->assertSame(['OptionalId' => null, 'email' => 0], $value);
        $value = $job->getItemKeyFieldValueTest($item, true, 'all');
        $this->assertSame(['OptionalId' => null, 'email' => 0], $value);
        $value = $job->getItemKeyFieldValueTest($item, true, 'loggable');
        $this->assertSame('email=0', $value);
        $value = $job->getItemKeyFieldValueTest($item, true, 0);
        $this->assertSame(null, $value);
        $value = $job->getItemKeyFieldValueTest($item, true, 1);
        $this->assertSame(0, $value);
        $item = ['email' => 'invalid@'];
        $value = $job->getItemKeyFieldValueTest($item, true, 1);
        $this->assertSame('invalid@', $value);

        // No keys present at all.
        $item = ['OptionalId' => 0, 'email' => ''];
        $this->assertExceptionForGetItemKeyFieldValue($job, $item, true, 'key', UnexpectedValueException::class, "Item does not contain a value for 'OptionalId/email' field(s).");
        $value = $job->getItemKeyFieldValueTest($item, false);
        $this->assertSame([], $value);
        $this->assertExceptionForGetItemKeyFieldValue($job, $item, true, 'until-nonempty', UnexpectedValueException::class, "Item does not contain a value for 'OptionalId/email' field(s).");
        $value = $job->getItemKeyFieldValueTest($item, false, 'until-nonempty');
        $this->assertSame(['OptionalId' => null, 'email' => null], $value);
        $this->assertExceptionForGetItemKeyFieldValue($job, $item, true, 'all', UnexpectedValueException::class, "Item does not contain a value for 'OptionalId/email' field(s).");
        $value = $job->getItemKeyFieldValueTest($item, false, 'all');
        $this->assertSame(['OptionalId' => null, 'email' => null], $value);
        $value = $job->getItemKeyFieldValueTest($item, true, 'loggable');
        $this->assertSame('<empty>', $value);
        $value = $job->getItemKeyFieldValueTest($item, true, 0);
        $this->assertSame(null, $value);
        $value = $job->getItemKeyFieldValueTest($item, true, 1);
        $this->assertSame(null, $value);

        // If OptionalId is a 'regular number' field, 0 is treated as nonempty
        // when in the item, but empty when it hits Copernica - which is bad
        // for getItemKeyFieldValue() logic. We should prevent this.
        $job = $this->getJobWithAccessibleMethods([
          'copernica_profile_key_field' => ['OptionalId', 'email'],
          'copernica_profile_fields' => ['OptionalId' => 'OptionalIdInCopernica'],
          'copernica_field_settings' => ['OptionalIdInCopernica' => ['type' => 'integer']]
        ]);
        $item = ['OptionalId' => 0, 'email' => 'rm@wyz.biz'];
        $this->assertExceptionForGetItemKeyFieldValue($job, $item, true, 'all', UnexpectedValueException::class, "'OptionalId' value 0 is not considered empty, but will be considered empty when updated in Copernica. We cannot use this as (part of) a key field value.");
    }

    /**
     * Asserts an exception is thrown. Used for shortening test method.
     */
    private function assertExceptionForGetItemKeyFieldValue($job, $item, $validate, $sub, $exception_class, $message)
    {
        try {
            $job->getItemKeyFieldValueTest($item, $validate, $sub);
            throw new LogicException('expectExceptionForGetItemKeyFieldValue() did not throw an exception.');
        } catch (Exception $exception) {
            self::assertSame($exception_class, get_class($exception));
            self::assertSame($message, $exception->getMessage());
        }
    }

    /**
     * Returns job that makes some protected methods callable (in a way).
     *
     * @return \CopernicaUpdateProfilesJob
     *   Class with public getItemKeyFieldValueTest() method.
     */
    protected function getJobWithAccessibleMethods($settings)
    {
        return new class ($settings) extends CopernicaUpdateProfilesJob {
            public function getItemKeyFieldValueTest(array $item, $validate = true, $sub = 'key')
            {
                return $this->getItemKeyFieldValue($item, $validate, $sub);
            }

            public function isFieldValueEqualTest($item_value, $other_value, $settings_field_name = '')
            {
                return $this->isFieldValueEqual($item_value, $other_value, $settings_field_name);
            }

            public function isFieldValueEmptyTest($item_value, $settings_field_name = '', $value_is_new_value = false)
            {
                return $this->isFieldValueEmpty($item_value, $settings_field_name, $value_is_new_value);
            }

            public function shouldUpdateTest($new_value, $copernica_profile_or_sub, $copernica_field_name)
            {
                return $this->shouldCopernicaFieldBeUpdated($new_value, $copernica_profile_or_sub, $copernica_field_name);
            }
        };
    }

    /**
     * Tests getMainProfilesByKey().
     *
     * Not a unit test; exercises code by processing items. If we wanted to
     * test this method 'as standalone as possible', we'd need to do a boatload
     * of setup of settings / API backend contents, which is all way easier
     * (and more logical to read) by just stuffing items through processItem().
     * We'll live with the consequence of this not being a unit test; it
     * depends on processItem() / updateMainProfiles() / createMainProfiles()
     * not being broken.
     *
     * @todo maybe: after we implement cache backend, also test whether profiles
     *   are cached?
     */
    public function testGetMainProfilesByKey()
    {
        $database_id = self::DATABASE_ID;
        $structure = [
            $database_id => [
                'fields' => [
                    'MainId' => ['type' => 'integer', 'value' => 0],
                    'Email' => ['type' => 'email'],
                    'Firstname' => ['type' => 'text'],
                ],
            ]
        ];
        $api = new TestApi($structure);
        // This is how we keep this API 'environment' for CopernicaRestApi:
        TestApiFactory::$testApis[self::TOKEN] = $api;
        // Setup (which, ironically, already exercises getMainProfilesByKey()):
        // Call start() to initialize context, but don't fetch items.
        $job__mail = $this->getJob(['copernica_profile_key_field' => 'email', 'duplicate_updates' => true]);
        $job_context = ['drunkins_override_fetch' => ['anything']];
        $items_not_fetched = $job__mail->start($job_context);
        // Standard job configuration to insert email without main_id.
        $job__mail->processItem(['email' => '1@example.com'], $job_context);
        // Then get a job with 'main_id' as key field, to insert duplicate
        // e-mail addresses.
        $job__id = $this->getJob(['copernica_profile_key_field' => 'main_id']);
        $job__id->processItem(['main_id' => 3, 'email' => '1@example.com'], $job_context);
        $job__id->processItem(['main_id' => 1, 'email' => '1@example.com'], $job_context);
        $this->assertSame(
            ["POST database/$database_id/profiles", "POST database/$database_id/profiles", "POST database/$database_id/profiles"],
            $api->getApiUpdateLog()
        );
        $api->resetApiUpdateLog();
        $GLOBALS['drunkins_log_test_threshold'] = WATCHDOG_NOTICE;
        // For humans editing code:
        //$GLOBALS['drunkins_log_test_screen'] = true;

        // Now get a job with two key fields.
        $job__2 = $this->getJob(['copernica_profile_key_field' => ['main_id', 'email'], 'duplicate_updates' => true]);
        // This should not update anything (same values):
        $job__2->processItem(['main_id' => 3, 'email' => '1@example.com'], $job_context);
        $this->assertSame([], $api->getApiUpdateLog());
        // This should update 1, leaving 2 others with the same e-mail:
        $job__2->processItem(['main_id' => 3, 'email' => '2@example.com'], $job_context);
        // We don't know the Copernica ID, so don't compare full log strings.
        $log = $api->getApiUpdateLog();
        $this->assertSame(1, count($log));
        $api->resetApiUpdateLog();

        // Updating purely on the basis of the e-mail address should select
        // two records, and update both if 'duplicate_updates' is set...
        $job__mail->processItem(['first_name' => 'Me', 'email' => '1@example.com'], $job_context);
        $log = $api->getApiUpdateLog();
        $this->assertSame(2, count($log));
        $this->assertSame(1, count($GLOBALS['drunkins_log_test_stack']));
        $this->assertStringStartsWith(
            'Copernica returned 2 profiles for "1@example.com"; taking all of them ',
            t($GLOBALS['drunkins_log_test_stack'][0][0], $GLOBALS['drunkins_log_test_stack'][0][1])
        );
        $api->resetApiUpdateLog();
        $GLOBALS['drunkins_log_test_stack'] = [];
        // ...and just 1 if 'duplicate_updates' is not set.
        $job__mail1 = $this->getJob();
        $job__mail1->processItem(['first_name' => 'You', 'email' => '1@example.com'], $job_context);
        $log = $api->getApiUpdateLog();
        $this->assertSame(1, count($log));
        $this->assertSame(1, count($GLOBALS['drunkins_log_test_stack']));
        $this->assertStringStartsWith(
            'Copernica returned 2 profiles for "1@example.com"; taking the first one ',
            t($GLOBALS['drunkins_log_test_stack'][0][0], $GLOBALS['drunkins_log_test_stack'][0][1])
        );
        $api->resetApiUpdateLog();
        $GLOBALS['drunkins_log_test_stack'] = [];

        // When updating based on two key fields id+email, updating an item
        // without ID should select both the record with and without ID (if
        // 'duplicate_updates' was set).
        $job__2->processItem(['first_name' => 'Test', 'email' => '1@example.com'], $job_context);
        $log = $api->getApiUpdateLog();
        $this->assertSame(2, count($log));
        $this->assertSame(1, count($GLOBALS['drunkins_log_test_stack']));
        $this->assertStringStartsWith(
            'Copernica returned 2 profiles for email="1@example.com"; taking all of them ',
            t($GLOBALS['drunkins_log_test_stack'][0][0], $GLOBALS['drunkins_log_test_stack'][0][1])
        );
        $api->resetApiUpdateLog();
        $GLOBALS['drunkins_log_test_stack'] = [];
        // Updating an item with unknown ID should only take the one without ID
        // (and 'take it over' by inserting its ID into that record).
        $job__2->processItem(['main_id' => 25, 'first_name' => 'Test2', 'email' => '1@example.com'], $job_context);
        $log = $api->getApiUpdateLog();
        $this->assertSame(1, count($log));
        $this->assertSame(0, count($GLOBALS['drunkins_log_test_stack']));
        // main_id was updated.
        $check_result = $api->get("database/$database_id/profiles", ['fields' => ['MainId==25']]);
        $this->assertSame(1, count($check_result['data']));
        $api->resetApiUpdateLog();
        // Next time we have an unknown ID, there are no records with the same
        // e-mail to take over, so we create a new one.
        $job__2->processItem(['main_id' => 26, 'first_name' => 'Test3', 'email' => '1@example.com'], $job_context);
        $this->assertSame(["POST database/$database_id/profiles"], $api->getApiUpdateLog());
        $this->assertSame(0, count($GLOBALS['drunkins_log_test_stack']));
        $api->resetApiUpdateLog();
        // To repeat: if we have a record without ID, we update all records
        // with that e-mail address, which in this case all have an ID already.
        // The message mentions 3 records; we update 2 because 1 is already ok.
        $job__2->processItem(['first_name' => 'Test3', 'email' => '1@example.com'], $job_context);
        $log = $api->getApiUpdateLog();
        $this->assertSame(2, count($log));
        $this->assertSame(1, count($GLOBALS['drunkins_log_test_stack']));
        $this->assertStringStartsWith(
            'Copernica returned 3 profiles for email="1@example.com"; taking all of them ',
            t($GLOBALS['drunkins_log_test_stack'][0][0], $GLOBALS['drunkins_log_test_stack'][0][1])
        );
        // Empty out profiles for next test.
        $this->deleteAllProfiles($api, $database_id);
    }

    /**
     * Tests a synchronization where a collection is a sort of 'action log'.
     *
     * This means that a sub-item processed by the synchronization will lead to
     * a new subprofile being created.
     */
    public function testActionLogSync()
    {
        $structure = [
            self::DATABASE_ID => [
                'fields' => [
                    'Email' => ['type' => 'email'],
                    'Firstname' => ['type' => 'text'],
                    'Lastname' => ['type' => 'text'],
                    // Official definition in our live db is "select"; we skip
                    // that for now, because it doesn't influence our tests. The
                    // data passed into processItem() is always "Male"/"Female".
                    //'Gender' => ['type' => 'select', 'value' => "\nMale\nFemale"],
                    'Gender' => ['type' => 'text'],
                    'Birthdate' => ['type' => 'empty_date'],
                ],
                'collections' => [
                    self::COLLECTION_ID => [
                        'fields' => [
                            'Date' => ['type' => 'empty_date'],
                            'Type' => ['type' => 'text'],
                            'LocationDescription' => ['type' => 'text'],
                            'City' => ['type' => 'text'],
                            'Locale' => ['type' => 'text'],
                            // Unused fields:
                            //'Country' => ['type' => 'text'],
                            //'LocationID' => ['type' => 'text'],
                        ]
                    ]
                ]
            ]
        ];
        $api = new TestApi($structure);
        // This is how we keep this API 'environment' for CopernicaRestApi:
        TestApiFactory::$testApis[self::TOKEN] = $api;

        // Call start() to initialize context, but don't fetch items.
        $job_context = ['drunkins_override_fetch' => ['anything']];
        $items_not_fetched = $this->getJob()->start($job_context);
        // For this job configuration, an item's profile fields do not have
        // the literal Copernica field names; they are mapped inside the job.
        // The subprofile data do have the Copernica field names.
        $item = [
            'email' => 'rm@wyz.biz',
            'first_name' => 'Roderik',
            'last_name' => 'Muit',
            'gender' => 'Male',
            'date_of_birth' => '1974-04-27',
            self::COLLECTION_ID => [
                'Date' => '2020-07-20',
                'Type' => 'email',
                'LocationDescription' => 'Hotel Bla',
                'City' => 'Amsterdam',
                'Locale' => 'NL_nl',
            ]
        ];
        // For humans editing code:
        //$GLOBALS['drunkins_log_test_threshold'] = WATCHDOG_DEBUG;
        //$GLOBALS['drunkins_log_test_screen'] = true;

        $this->getJob()->processItem($item, $job_context);
        // Check if two POST requests were done. (We don't necessarily have to
        // check the exact URLS but hey, as long as we can... We also don't
        // have to verify contents in the database backend because
        // TestApiBaseTest is doing that sufficiently.)
        $database_id = self::DATABASE_ID;
        $collection_id = self::COLLECTION_ID;
        // As long as we use SQLite we can assume this. Otherwise we may need
        // to derive it from a get(), or just from getApiUpdateLog().
        $profile_id = 1;
        $this->assertSame(
            ["POST database/$database_id/profiles", "POST profile/$profile_id/subprofiles/$collection_id"],
            $api->getApiUpdateLog()
        );

        // (With the job settings as they are,) if we insert data with the same
        // email, a new subprofile gets created, but not a new profile. The
        // profile PUT (update) should not be called at all if no fields are
        // changing. Empty fields do not cause changes.
        $api->resetApiUpdateLog();
        $item['last_name'] = '';
        $this->getJob()->processItem($item, $job_context);
        $this->assertSame(
            ["POST profile/$profile_id/subprofiles/$collection_id"],
            $api->getApiUpdateLog()
        );

        // Profile field overwriting (except with empty value as above) is not
        // prohibited. Not-set fields are not emptied out either.
        $api->resetApiUpdateLog();
        $item['first_name'] = 'Piet';
        unset($item['last_name']);
        $this->getJob()->processItem($item, $job_context);
        $this->assertSame(
            ["PUT profile/$profile_id/fields", "POST profile/$profile_id/subprofiles/$collection_id"],
            $api->getApiUpdateLog()
        );
        $api_client = new CopernicaRestClient(self::TOKEN, '\CopernicaApi\Tests\TestApiFactory');
        $check_profiles = $api_client->getEntities("database/$database_id/profiles", ['fields' => ['Firstname==Piet', 'Lastname==Muit']]);
        $this->assertSame(1, count($check_profiles));

        // Only case insensitivity changes in the main profile are not updated.
        $api->resetApiUpdateLog();
        $item['first_name'] = 'PIET';
        unset($item['last_name']);
        $this->getJob([
            'copernica_field_settings' => [
              'Email' => ['compare_case_insensitive' => true],
              'Firstname' => ['compare_case_insensitive' => true],
              'Lastname' => ['compare_case_insensitive' => true],
            ],
        ])->processItem($item, $job_context);
        $this->assertSame(
            ["POST profile/$profile_id/subprofiles/$collection_id"],
            $api->getApiUpdateLog()
        );

        // @todo test inserting subprofile for deleted profile. Is only
        //   interesting if we test with cache on.
    }

    protected function deleteAllProfiles($api, $database_id)
    {
        $response = $api->get("database/$database_id/profiles");
        foreach ($response['data'] as $profile) {
            $api->delete("profile/{$profile['ID']}");
        }
        // Also, reset logs.
        $api->resetApiUpdateLog();
        // We need to reset the job because it's the only way to reset the
        // CoprenicaRestClient client class for the next job- which retains a
        // reference to an old TestApi class with an old database schema.
        $this->getJob(false);
    }

    /**
     * Returns an instance of the job class.
     *
     * @param array|bool $more_settings
     *   Extra settings to pass to the job. If an empty array, the job instance
     *   gets cached. If TRUE, that cache first gets reset. If FALSE, the
     *   cache gets reset and nothing gets returned.
     *
     * @return \CopernicaUpdateProfilesJob|null
     */
    protected function getJob($more_settings = [])
    {
        static $instance;
        if (is_bool($more_settings)) {
            $instance = null;
            if (!$more_settings) {
                return;
            }
            $more_settings = [];
        }

        // Once every ~6 times randomly, reinstantiate the job, to prove that
        // there is no difference in reusing vs. using new job classes. This is
        // only implemented for the default settings.
        if ($more_settings || !isset($instance) || rand(0, 6) == 0) {
            // Copied real job settings from my code.
            $settings = $more_settings + [
                'copernica_database_id' => self::DATABASE_ID,
                // This logs more:
                'log_individual_updates' => true,
                // Prevent re-querying recently seen people before inserting subprofile.
                // @todo turn on.
                //'cache_profiles_by_key' => true,
                'copernica_profile_key_field' => 'email',
                // Field mapping for the main profile - i.e. the items have the
                // non-mapped keys (not the Copernica fieldnames) for the main
                // profile, but not for the subprofile.
                'copernica_profile_fields' => [
                    'main_id' => 'MainId',
                    'email' => 'Email',
                    'first_name' => 'Firstname',
                    'last_name' => 'Lastname',
                    'gender' => 'Gender',
                    'date_of_birth' => 'Birthdate',
                ],
                'copernica_collections' => [
                    self::COLLECTION_ID => ['name' => 'TestCollection'],
                ],
                // I think we want to deprecate this but it may still be
                // necessary. (drunkins_get_job() always adds it.)
                'job_id' => 'copernica_update_test',
                'dependencies' => [
                    'copernica_client' => new CopernicaRestClient(self::TOKEN, '\CopernicaApi\Tests\TestApiFactory')
                    //'profile_cache' => ...
                ],
                // The class doesn't use this (because CopernicaRestClient is
                // already instantiated) but still checks its presence.
                'copernica_token' => self::TOKEN,
            ];

            $return = new CopernicaUpdateProfilesJob($settings);
            if (!$more_settings) {
                $instance = $return;
            }
        } else {
            $return = $instance;
        }

        return $return;
    }
}

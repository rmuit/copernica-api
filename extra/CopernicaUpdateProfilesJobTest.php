<?php

/**
 * @file
 * PHPUnit test class to test a synchronization job class / processes.
 *
 * I'm using this in an application's test environment, but it's included here
 * as an example of functional tests that utilize TestApi. The real unit tests
 * (which don't use TestApi) of methods in the class under test are obviously
 * uninteresting if you don't have that class.
 *
 * The class under test (CopernicaUpdateProfilesJob) is not in this Github
 * repository yet. I'm on the fence about it because:
 * - While very useful for implementing a synchronization process of a
 *   remote system's data into Copernica profiles/subprofiles, it is not
 *   immediately usable by many people. The biggest part (the updating logic)
 *   is usable and works well, but the class is built upon a "process runner"
 *   (that takes care of scheduling/running the whole process) that is tightly
 *   integrated with a Drupal 7 site: https://www.drupal.org/project/drunkins.
 *   You'll likely have to pry it loose from Drupal in order to use it. (The
 *   fact that this test class is runnable by phpunit after require'ing a
 *   parent class + interface file from the Drunkins module, proves that this
 *   is very much possible; it's just quite ugly at the moment.)
 * - CopernicaUpdateProfilesJob represents 100+ hours of work with the vast
 *   majority paid by one customer. I might want to check with them before
 *   making 'their' synchronization available for free.
 */

use CopernicaApi\CopernicaRestAPI;
use CopernicaApi\Helper;
use CopernicaApi\Tests\TestApi;
use CopernicaApi\Tests\TestRestClient;
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
        // This is a bit wonky, I'm still figuring out what the best way is to
        // use this:
        // - drunkins_log_test_threshold: put stuff onto stack. Needs to be
        //   enabled.
        // - drunkins_log_test_screen: put stuff onto stack. Needs to be
        //   disabled if not wanted, because that's how we'll see messages
        //   while writing tests.
        if (isset($GLOBALS['drunkins_log_test_threshold'])
            && $severity <= (is_numeric($GLOBALS['drunkins_log_test_threshold']) ? $GLOBALS['drunkins_log_test_threshold'] : WATCHDOG_DEBUG)
        ) {
            if (!isset($GLOBALS['drunkins_log_test_stack'])
                || !is_array($GLOBALS['drunkins_log_test_stack'])) {
                $GLOBALS['drunkins_log_test_stack'] = [];
            }
            $GLOBALS['drunkins_log_test_stack'][] = [$message, $variables];
        }
        if (!isset($GLOBALS['drunkins_log_test_screen'])
            || $severity <= (is_numeric($GLOBALS['drunkins_log_test_screen']) ? $GLOBALS['drunkins_log_test_screen'] : WATCHDOG_DEBUG)
        ) {
            // Not exactly sure yet what is the best way of making logs
            // stand out in between PHPUnit output but not mess things up.
            print '[LOG] >> ' . t($message, $variables) . "\n";
            // fwrite(STDERR, t($message, $variables) . "\n");
        }
    }
}

// This is the structure of my Drupal 7 site (because noone else will probably
// run these tests anyway). Include all dependencies of the Drunkins job.
require_once(__DIR__ . '/../../../../modules/contrib/drunkins/classes/DrunkinsProcessSummaryTrait.inc');
require_once(__DIR__ . '/../../../../modules/contrib/drunkins/classes/fetcher.inc');
require_once(__DIR__ . '/../../../../modules/contrib/drunkins/classes/job.inc');
require_once(__DIR__ . '/../../../../modules/custom/ygsync/CopernicaUpdateProfilesJob.php');
require_once(__DIR__ . '/../../../../modules/custom/ygsync/CopernicaSubprofileContainer.php');
// And the test infrastructure providing a (test replacement for) a local cache
// plus the Copernica API.
// A NOTE: if tests complain about YGSyncKeyValueStoreManager not being found,
// we should not require() it here - rather we should adjust the tests' code
// (getJob()?) to inject SqliteKeyValueStoreManager.
require_once(__DIR__ . '/SqliteKeyValueStoreManager.php');
require_once(__DIR__ . '/PdoKeyValueStore.php');
// Not always autoloaded on my test system:
require_once(__DIR__ . '/../tests/TestApi.php');
require_once(__DIR__ . '/../tests/TestRestClient.php');

// phpcs:enable

/**
 * Test case for testing CopernicaUpdateProfilesJob.
 *
 * This implicitly depends on the profile functionality from Copernica being
 * fully known / specified / tested in copernica-api's ApiBehavorTest so we
 * have a stable base to build on.
 *
 * Contains:
 * - unit tests for several methods;
 * - a test of a method that is better off not being unit tested but being
 *   exercised by passing items through a job, i.e. calling processItem()
 *   repeatedly; this utilizes an API 'backend', for which we've hardcoded the
 *   TestApi class.
 * - a test for a type of job (i.e. a specific configuration) that tests all
 *   behavior we can think of by passing sets of items through it.
 * Multiple scenarios/job types have not been implemented yet. There are
 * probably a few more things that can be tested in a 'method centric' way
 * (bullet 1/2) which would be nice, but we'll likely end up mostly
 * implementing 'scenario/job configuration centric' tests from now on.
 */
// Above should be in global namespace. Using two namespaces in the same file
// has its own issues, so we put this test class in global too, and mute PHPCS.
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
    const COLLECTION2_ID = 58;

    /**
     * Determines whether a job returned by getJob() uses a local profile cache.
     *
     * This must be set using setJobUsesProfileCache\() to be bug free.
     */
    protected $jobUsesProfileCache = [];

    /**
     * Returns some reusable field settings.
     *
     * @param bool|int $get_field_names_to_test
     *   True: get field names only (without prefixes). Other value besides
     *   0/FALSE: return settings where the field names are supposedly part of
     *   a collection.
     *
     * @return array
     */
    private function getFieldSettings($get_field_names_to_test = false)
    {
        $coll_prefix = ($get_field_names_to_test && $get_field_names_to_test !== true)
          ? "$get_field_names_to_test:" : '';
        $settings = [
            "{$coll_prefix}myName" => 'text',
            "{$coll_prefix}myNameIns" => ['type' => 'text', 'compare_case_insensitive' => true],
            "{$coll_prefix}myEmail" => 'email',
            "{$coll_prefix}myEmailIns" => ['type' => 'email', 'compare_case_insensitive' => true],
            // We'll assume that 'zero_can_overwrite' only applies to integer /
            // float. (We don't know a practical application of resetting it to
            // False for float, but hwy. It's possible.)
            "{$coll_prefix}myInt" => 'integer',
            "{$coll_prefix}myId" => ['type' => 'integer', 'zero_can_overwrite' => false],
            "{$coll_prefix}myFloat" => 'float',
            "{$coll_prefix}myNonZeroFloat" => ['type' => 'float', 'zero_can_overwrite' => false],
            "{$coll_prefix}myDate" => 'date',
            "{$coll_prefix}myDateEmpty" => 'empty_date',
            "{$coll_prefix}myDateTime" => 'datetime',
            "{$coll_prefix}myDateTimeEmpty" => 'empty_datetime',
        ];
        if ($get_field_names_to_test === true) {
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
                    $this->assertTrue($job->isFieldValueEmpty($test_value, $field_name, false), "isFieldValueEmpty($loggable, '$field_name', false) should be TRUE.");
                }
                // All fields except the ones specified should be non-empty.
                foreach (array_diff($this->getFieldSettings(true), $supposed_empty_fields) as $field_name) {
                    $this->assertFalse($job->isFieldValueEmpty($test_value, $field_name, false), "isFieldValueEmpty($loggable, '$field_name', false) should be FALSE.");
                }
            }
            if ($value_is_new_value === null || !empty($value_is_new_value)) {
                foreach ($supposed_empty_fields as $field_name) {
                    $this->assertTrue($job->isFieldValueEmpty($test_value, $field_name, true), "isFieldValueEmpty($loggable, '$field_name', true) should be TRUE.");
                }
                foreach (array_diff($this->getFieldSettings(true), $supposed_empty_fields) as $field_name) {
                    $this->assertFalse($job->isFieldValueEmpty($test_value, $field_name, true), "isFieldValueEmpty($loggable, '$field_name', true) should be FALSE.");
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
            $this->assertTrue($job->isFieldValueEqual($test_value1, $test_value2, $field_name), "isFieldValueEqual($loggable1, $loggable2, '$field_name') should be TRUE.");
            $this->assertTrue($job->isFieldValueEqual($test_value2, $test_value1, $field_name), "isFieldValueEqual($loggable2, $loggable1, '$field_name') should be TRUE.");
        }
        foreach (array_diff($this->getFieldSettings(true), $supposed_equal_fields) as $field_name) {
            $this->assertFalse($job->isFieldValueEqual($test_value1, $test_value2, $field_name), "isFieldValueEqual($loggable1, $loggable2, '$field_name') should be FALSE.");
            $this->assertFalse($job->isFieldValueEqual($test_value2, $test_value1, $field_name), "isFieldValueEqual($loggable2, $loggable1, '$field_name') should be FALSE.");
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
        // Collection ID 0 means 'no collection' in getFieldSettings().
        $collection_id = rand(0, 2);
        $job = $this->getJobWithAccessibleMethods($extra_job_settings + [
            'copernica_field_settings' => $this->getFieldSettings($collection_id)
        ]);

        $loggable1 = var_export($existing_value, true);
        $loggable2 = var_export($new_value, true);
        foreach ($supposed_updatable_fields as $field_name) {
            // Prepopulate fake item with existing value. We'll treat NULL as
            // "field does not exist" and assume "field exists with value NULL"
            // does not need to be tested explicitly.
            $fake_profile = ['fields' => isset($existing_value) ? [$field_name => $existing_value] : []];
            $this->assertTrue($job->shouldCopernicaFieldBeUpdated($new_value, $fake_profile, $field_name, $collection_id), "Field $field_name should be updatable from $loggable1 to $loggable2.");
        }
        foreach (array_diff($this->getFieldSettings(true), $supposed_updatable_fields) as $field_name) {
            $fake_profile = ['fields' => isset($existing_value) ? [$field_name => $existing_value] : []];
            $this->assertFalse($job->shouldCopernicaFieldBeUpdated($new_value, $fake_profile, $field_name, $collection_id), "Field $field_name should not be updatable from $loggable1 to $loggable2.");
        }
    }

    /**
     * Tests isFieldValueEmpty().
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
        $this->assertTrue($job->isFieldValueEmpty('0000-00-01', 'MyDATE'));
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
    public function testArrayMergeKeyCaseInsensitive()
    {
        $base = [
            'dupe' => 1,
            'key' => 'ha',
            'DUPE' => 2,
            'anotherkey' => 'ho',
        ];
        $merge = [
            'newkey' => 'new',
            'DUPE' => 'new',
            'Dupe' => 3,
            'key' => 'new',
            'KEY' => 'newtoo',
        ];
        $expected = [
            'dupe' => 3,
            'key' => 'newtoo',
            'DUPE' => 3,
            'anotherkey' => 'ho',
            'newkey' => 'new',
        ];
        $job = $this->getJobWithAccessibleMethods([]);
        $this->assertSame($expected, $job->arrayMergeKeyCaseInsensitive($base, $merge));
    }

    /**
     * Tests getItemKeyFieldValue().
     */
    public function testGetItemKeyFieldValue()
    {
        // Job with single key_field setting.
        $job = $this->getJobWithAccessibleMethods(['copernica_profile_key_field' => 'email', 'key_field_validate_email' => true]);
        $item = ['email' => 'rm@wyz.biz'];
        $value = $job->getItemKeyFieldValue($item);
        $this->assertSame(['email' => 'rm@wyz.biz'], $value);
        $value = $job->getItemKeyFieldValue($item, true, 'until-nonempty');
        $this->assertSame(['email' => 'rm@wyz.biz'], $value);
        $value = $job->getItemKeyFieldValue($item, true, 'all');
        $this->assertSame(['email' => 'rm@wyz.biz'], $value);
        $value = $job->getItemKeyFieldValue($item, true, 'loggable');
        $this->assertSame('"rm@wyz.biz"', $value);
        $value = $job->getItemKeyFieldValue($item, true, 0);
        $this->assertSame('rm@wyz.biz', $value);
        $this->assertExceptionForGetItemKeyFieldValue($job, $item, false, 1, LogicException::class, "Key 1 not found in 'copernica_profile_key_field' setting.");
        // Test invalid e-mail, with $invalid arg being true/false:
        $item = ['email' => 'invalid@'];
        $this->assertExceptionForGetItemKeyFieldValue($job, $item, true, 0, UnexpectedValueException::class, "'email' field contains an invalid e-mail address: \"invalid@\".");
        $value = $job->getItemKeyFieldValue($item, false, 0);
        $this->assertSame('invalid@', $value);
        // ...also without validation setting:
        $job = $this->getJobWithAccessibleMethods(['copernica_profile_key_field' => 'email']);
        $value = $job->getItemKeyFieldValue($item, true, 0);
        $this->assertSame('invalid@', $value);
        // Test empty e-mail
        $item = ['email' => ''];
        $this->assertExceptionForGetItemKeyFieldValue($job, $item, true, 'key', UnexpectedValueException::class, "Item does not contain a value for 'email' field(s).");
        $this->assertExceptionForGetItemKeyFieldValue($job, $item, true, 'until-nonempty', UnexpectedValueException::class, "Item does not contain a value for 'email' field(s).");
        $value = $job->getItemKeyFieldValue($item, false);
        $this->assertSame([], $value);
        $value = $job->getItemKeyFieldValue($item, false, 'until-nonempty');
        $this->assertSame(['email' => null], $value);
        $this->assertExceptionForGetItemKeyFieldValue($job, $item, true, 'all', UnexpectedValueException::class, "Item does not contain a value for 'email' field(s).");
        $value = $job->getItemKeyFieldValue($item, false, 'all');
        $this->assertSame(['email' => null], $value);
        $value = $job->getItemKeyFieldValue($item, true, 'loggable');
        $this->assertSame('<empty>', $value);
        $value = $job->getItemKeyFieldValue($item, true, 0);
        $this->assertSame(null, $value);

        // Job with key_field being a single-element array: makes no difference.
        // Test that casing of field names in the setting doesn't matter;
        // things come out as in the item.
        $job = $this->getJobWithAccessibleMethods(['copernica_profile_key_field' => ['Email'], 'key_field_validate_email' => true]);
        $item = ['email' => 'rm@wyz.biz'];
        $value = $job->getItemKeyFieldValue($item);
        $this->assertSame(['email' => 'rm@wyz.biz'], $value);
        $value = $job->getItemKeyFieldValue($item, true, 'until-nonempty');
        $this->assertSame(['email' => 'rm@wyz.biz'], $value);
        $value = $job->getItemKeyFieldValue($item, true, 'all');
        $this->assertSame(['email' => 'rm@wyz.biz'], $value);
        $value = $job->getItemKeyFieldValue($item, true, 'loggable');
        $this->assertSame('"rm@wyz.biz"', $value);
        $value = $job->getItemKeyFieldValue($item, true, 0);
        $this->assertSame('rm@wyz.biz', $value);
        $this->assertExceptionForGetItemKeyFieldValue($job, $item, false, 1, LogicException::class, "Key 1 not found in 'copernica_profile_key_field' setting.");
        $item = ['email' => 'invalid@'];
        $this->assertExceptionForGetItemKeyFieldValue($job, $item, true, 0, UnexpectedValueException::class, "'email' field contains an invalid e-mail address: \"invalid@\".");
        $value = $job->getItemKeyFieldValue($item, false, 0);
        $this->assertSame('invalid@', $value);
        $job = $this->getJobWithAccessibleMethods(['copernica_profile_key_field' => ['Email']]);
        $value = $job->getItemKeyFieldValue($item, true, 0);
        $this->assertSame('invalid@', $value);
        $item = ['email' => ''];
        $this->assertExceptionForGetItemKeyFieldValue($job, $item, true, 'key', UnexpectedValueException::class, "Item does not contain a value for 'Email' field(s).");
        $this->assertExceptionForGetItemKeyFieldValue($job, $item, true, 'until-nonempty', UnexpectedValueException::class, "Item does not contain a value for 'Email' field(s).");
        $value = $job->getItemKeyFieldValue($item, false);
        $this->assertSame([], $value);
        $value = $job->getItemKeyFieldValue($item, false, 'until-nonempty');
        $this->assertSame(['email' => null], $value);
        $this->assertExceptionForGetItemKeyFieldValue($job, $item, true, 'all', UnexpectedValueException::class, "Item does not contain a value for 'Email' field(s).");
        $value = $job->getItemKeyFieldValue($item, false, 'all');
        $this->assertSame(['email' => null], $value);
        $value = $job->getItemKeyFieldValue($item, true, 'loggable');
        $this->assertSame('<empty>', $value);
        $value = $job->getItemKeyFieldValue($item, true, 0);
        $this->assertSame(null, $value);

        // Job with key_field being two fields.
        $job = $this->getJobWithAccessibleMethods([
          'copernica_profile_key_field' => ['OptionalId', 'email'],
          'copernica_profile_fields' => ['OptionalId' => 'OptionalIdInCopernica'],
          'copernica_field_settings' => ['OptionalIdInCopernica' => ['type' => 'integer', 'zero_can_overwrite' => false]]
        ]);
        // If only the first key is present:
        $item = ['optionalid' => 5];
        $value = $job->getItemKeyFieldValue($item);
        $this->assertSame(['optionalid' => 5], $value);
        $value = $job->getItemKeyFieldValue($item, true, 'until-nonempty');
        $this->assertSame(['optionalid' => 5], $value);
        $value = $job->getItemKeyFieldValue($item, true, 'all');
        $this->assertSame(['optionalid' => 5, 'email' => null], $value);
        $value = $job->getItemKeyFieldValue($item, true, 'loggable');
        $this->assertSame('5', $value);
        $value = $job->getItemKeyFieldValue($item, true, 0);
        $this->assertSame(5, $value);
        $value = $job->getItemKeyFieldValue($item, true, 1);
        $this->assertSame(null, $value);
        $this->assertExceptionForGetItemKeyFieldValue($job, $item, false, 2, LogicException::class, "Key 2 not found in 'copernica_profile_key_field' setting.");

        // Both keys are present:
        $item = ['OptionalId' => 5, 'email' => 'invalid@'];
        $value = $job->getItemKeyFieldValue($item);
        $this->assertSame(['OptionalId' => 5], $value);
        $value = $job->getItemKeyFieldValue($item, true, 'until-nonempty');
        $this->assertSame(['OptionalId' => 5], $value);
        $value = $job->getItemKeyFieldValue($item, true, 'all');
        // Invalid e-mail does not get checked if e-mail is not the first field.
        // (Whether or not that makes sense... is a question for later.)
        $this->assertSame(['OptionalId' => 5, 'email' => 'invalid@'], $value);
        $value = $job->getItemKeyFieldValue($item, true, 'loggable');
        $this->assertSame('5', $value);
        $value = $job->getItemKeyFieldValue($item, true, 0);
        $this->assertSame(5, $value);
        $value = $job->getItemKeyFieldValue($item, true, 1);
        $this->assertSame('invalid@', $value);
        $this->assertExceptionForGetItemKeyFieldValue($job, $item, false, 2, LogicException::class, "Key 2 not found in 'copernica_profile_key_field' setting.");

        // Only non-first key is present (0 does not count for zero_is_empty);
        // this also does some implicit non-exhaustive testing for
        // getCopernicaProfileFieldName() / getCopernicaFieldType(), which have
        // no own unit tests):
        $item = ['OptionalId' => 0, 'email' => 'rm@wyz.biz'];
        $value = $job->getItemKeyFieldValue($item);
        $this->assertSame(['email' => 'rm@wyz.biz'], $value);
        $value = $job->getItemKeyFieldValue($item, true, 'until-nonempty');
        $this->assertSame(['OptionalId' => null, 'email' => 'rm@wyz.biz'], $value);
        $value = $job->getItemKeyFieldValue($item, true, 'all');
        $this->assertSame(['OptionalId' => null, 'email' => 'rm@wyz.biz'], $value);
        $value = $job->getItemKeyFieldValue($item, true, 'loggable');
        $this->assertSame('email="rm@wyz.biz"', $value);
        $value = $job->getItemKeyFieldValue($item, true, 0);
        $this->assertSame(null, $value);
        $value = $job->getItemKeyFieldValue($item, true, 1);
        $this->assertSame('rm@wyz.biz', $value);
        $item = ['email' => 'invalid@'];
        $value = $job->getItemKeyFieldValue($item, true, 1);
        $this->assertSame('invalid@', $value);

        // 0 is a key for the 'email' field because 0 is not an empty value
        // for string-like fields. (We treat 0 like any other string; this code
        // block is only here, copied from above, to shake out any code that
        // would treat empty-but-not-really-empty values in the wrong way.)
        $item = ['OptionalId' => 0, 'email' => 0];
        $value = $job->getItemKeyFieldValue($item);
        $this->assertSame(['email' => 0], $value);
        $value = $job->getItemKeyFieldValue($item, true, 'until-nonempty');
        $this->assertSame(['OptionalId' => null, 'email' => 0], $value);
        $value = $job->getItemKeyFieldValue($item, true, 'all');
        $this->assertSame(['OptionalId' => null, 'email' => 0], $value);
        $value = $job->getItemKeyFieldValue($item, true, 'loggable');
        $this->assertSame('email=0', $value);
        $value = $job->getItemKeyFieldValue($item, true, 0);
        $this->assertSame(null, $value);
        $value = $job->getItemKeyFieldValue($item, true, 1);
        $this->assertSame(0, $value);
        $item = ['email' => 'invalid@'];
        $value = $job->getItemKeyFieldValue($item, true, 1);
        $this->assertSame('invalid@', $value);

        // No keys present at all.
        $item = ['OptionalId' => 0, 'email' => ''];
        $this->assertExceptionForGetItemKeyFieldValue($job, $item, true, 'key', UnexpectedValueException::class, "Item does not contain a value for 'OptionalId/email' field(s).");
        $value = $job->getItemKeyFieldValue($item, false);
        $this->assertSame([], $value);
        $this->assertExceptionForGetItemKeyFieldValue($job, $item, true, 'until-nonempty', UnexpectedValueException::class, "Item does not contain a value for 'OptionalId/email' field(s).");
        $value = $job->getItemKeyFieldValue($item, false, 'until-nonempty');
        $this->assertSame(['OptionalId' => null, 'email' => null], $value);
        $this->assertExceptionForGetItemKeyFieldValue($job, $item, true, 'all', UnexpectedValueException::class, "Item does not contain a value for 'OptionalId/email' field(s).");
        $value = $job->getItemKeyFieldValue($item, false, 'all');
        $this->assertSame(['OptionalId' => null, 'email' => null], $value);
        $value = $job->getItemKeyFieldValue($item, true, 'loggable');
        $this->assertSame('<empty>', $value);
        $value = $job->getItemKeyFieldValue($item, true, 0);
        $this->assertSame(null, $value);
        $value = $job->getItemKeyFieldValue($item, true, 1);
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
     * Asserts that an exception is thrown. Used for shortening test method.
     */
    private function assertExceptionForGetItemKeyFieldValue($job, $item, $validate, $sub, $exception_class, $message)
    {
        try {
            $job->getItemKeyFieldValue($item, $validate, $sub);
            throw new LogicException('assertExceptionForGetItemKeyFieldValue() did not throw an exception.');
        } catch (Exception $exception) {
            self::assertSame($exception_class, get_class($exception));
            self::assertSame($message, $exception->getMessage());
        }
    }

    /**
     * Returns job that makes some protected methods callable.
     *
     * @return CopernicaUpdateProfilesJob
     *   Class with some protected methods made public.
     */
    protected function getJobWithAccessibleMethods($settings)
    {
        // Override protected methods under test to be public.
        return new class ($settings) extends CopernicaUpdateProfilesJob {
            public function getItemKeyFieldValue(array $item, $validate = true, $sub = 'key')
            {
                return parent::getItemKeyFieldValue($item, $validate, $sub);
            }

            public function isFieldValueEqual($item_value, $other_value, $settings_field_name = '')
            {
                return parent::isFieldValueEqual($item_value, $other_value, $settings_field_name);
            }

            public function isFieldValueEmpty($item_value, $settings_field_name = '', $value_is_new_value = false)
            {
                return parent::isFieldValueEmpty($item_value, $settings_field_name, $value_is_new_value);
            }

            public function shouldCopernicaFieldBeUpdated($new_value, $copernica_profile_or_sub, $copernica_field_name, $collection_id = 0)
            {
                return parent::shouldCopernicaFieldBeUpdated($new_value, $copernica_profile_or_sub, $copernica_field_name, $collection_id);
            }

            public function arrayMergeKeyCaseInsensitive($base, $new_value)
            {
                return parent::arrayMergeKeyCaseInsensitive($base, $new_value);
            }

            public function keyFieldValueWasNeverProcessedBefore($key_field_value, $collection_id = 0)
            {
                return parent::keyFieldValueWasNeverProcessedBefore($key_field_value, $collection_id);
            }

            public function getHighestCopernicaValue($field_name, $collection_id = 0)
            {
                return parent::getHighestCopernicaValue($field_name, $collection_id);
            }
        };
    }

    /**
     * Tests keyFieldValueWasNeverProcessedBefore() & getHighestCopernicaValue()
     *
     * getHighestCopernicaValue() is a dependency. We're testing both in one
     * test method because we don't want to do separate setup.
     *
     * Not a unit test; exercises code by processing items. If we wanted to
     * test this method 'as standalone as possible', we'd need to do more setup
     * of settings / API backend contents, which is all way easier (and more
     * logical to read) by just stuffing items through processItem(). We'll
     * live with the consequence of this not being a unit test, which is: we
     * depend on processItem() / update*) / create*() / etc. working well.
     */
    public function testKeyFieldValueWasNeverProcessedBefore()
    {
        // Copypasted from another test so we can use the same job settings.
        $structure = [
            self::DATABASE_ID => [
                'fields' => [
                    'MagentoCustomerId' => ['type' => 'integer', 'value' => 0],
                    'Email' => ['type' => 'email'],
                    'Name' => ['type' => 'text'],
                ],
                'collections' => [
                    self::COLLECTION_ID => [
                        'fields' => [
                            'OrderId' => ['type' => 'integer', 'value' => 0],
                            'Total' => ['type' => 'float'],
                            'Status' => ['type' => 'text'],
                        ]
                    ],
                ]
            ]
        ];
        $api = new TestApi($structure, null, true);
        // getHighestCopernicaValue() does not depend on any settings; it just
        // fetches entities from Copernica.
        // testKeyFieldValueWasNeverProcessedBefore() depends on 'serial'.
        // (A confession: there's ugliness in getJob() which makes it not do
        // a good job of creating a new table for the cache/state backend
        // unless the _first_ call to getJob() actually uses a cache/state
        // backend. And I'm lazy. So the order of the following two lines is
        // important.)
        $this->setJobUsesProfileCache(['serial' => true]);
        $job = $this->getJob('magento_import', [], $api);
        $this->setJobUsesProfileCache([]);
        $job_noserial = $this->getJob('magento_import', [], $api);
        // Call start() to initialize context, but don't fetch items. We likely
        // don't even need an initialized context here, but don't want to have
        // to think about it.
        $job_context = ['drunkins_override_fetch' => ['anything']];
        $items_not_fetched = $job->start($job_context);

        // If the database is empty, everything just returns null.
        $this->assertSame(null, $job->getHighestCopernicaValue('magentocustomerid'));
        $this->assertSame(null, $job->getHighestCopernicaValue('orderid', self::COLLECTION_ID));
        $this->assertSame(null, $job->getHighestCopernicaValue('Status', self::COLLECTION_ID));
        // Inconsistency in misconfigured edge cases which we won't fix: a
        // nonexistent field will also return null until at least one entity
        // is in the database.
        $this->assertSame(null, $job->getHighestCopernicaValue('Status'));

        $item = [
            'email' => 'rm@wyz.biz', 'name' => 'me',
            self::COLLECTION_ID => ['OrderId' => 4, 'Total' => 9.99, 'Status' => 'pending'],
        ];
        $this->getJob('', [], $api)->processItem($item, $job_context);

        // Existing but not-filled values return null, not e.g. 0.
        $this->assertSame(null, $job->getHighestCopernicaValue('magentocustomerid'));
        $this->assertEquals(4, $job->getHighestCopernicaValue('orderid', self::COLLECTION_ID));
        // A nonexistent field usually throws this error:
        $this->assertExceptionForGetHighestCopernicaValue('orderid', 0, $job, LogicException::class, "'orderid' field does not exist in profiles; it cannot be used for autoincrement / highest-ID checks.");
        $this->assertExceptionForGetHighestCopernicaValue('Status', self::COLLECTION_ID, $job, LogicException::class, "'Status' contains a non-numeric 'highest' value in subprofile 1; it cannot be used for autoincrement / highest-ID checks in collection " . self::COLLECTION_ID . '.');

        // We can't know anything for the 'noserial' job => it returns FALSE.
        $this->assertSame(false, $job_noserial->keyFieldValueWasNeverProcessedBefore(1));
        // With this field not having a value in Copernica, we are sure value
        // 1 was not processed before. A repeated call will always return
        // false (because the method maintains its own "this value might be
        // processed now" state).
        $this->assertSame(true, $job->keyFieldValueWasNeverProcessedBefore(1));
        $this->assertSame(false, $job->keyFieldValueWasNeverProcessedBefore(1));
        $this->assertSame(false, $job->keyFieldValueWasNeverProcessedBefore(4, self::COLLECTION_ID));
        $this->assertSame(true, $job->keyFieldValueWasNeverProcessedBefore(5, self::COLLECTION_ID));
        $this->assertSame(false, $job->keyFieldValueWasNeverProcessedBefore(5, self::COLLECTION_ID));
        $this->getJob('', [], $api)->processItem(['magentocustomerid' => 7], $job_context);
        $this->assertSame(true, $job->keyFieldValueWasNeverProcessedBefore(9));
        $this->assertSame(false, $job->keyFieldValueWasNeverProcessedBefore(9));
        // Implications on API calls are tested in testMagentoImport().
    }

    /**
     * Asserts that an exception is thrown. Used for shortening test method.
     */
    private function assertExceptionForGetHighestCopernicaValue($field_name, $collection_id, $job, $exception_class, $message)
    {
        try {
            $job->getHighestCopernicaValue($field_name, $collection_id);
            throw new LogicException('assertExceptionForGetHighestCopernicaValue() did not throw an exception.');
        } catch (Exception $exception) {
            self::assertSame($exception_class, get_class($exception));
            self::assertSame($message, $exception->getMessage());
        }
    }

    /**
     * Tests getMainProfilesByKey().
     *
     * Mixed in with this, tests various insert/update actions, o.a.
     * - the fact that we can change the 'secondary' key field in a two-field
     *   key without issue (and without needing to turn on extra settings), in
     *   a profile/with an item where the 'primary' key field is populated.
     * - working of the by-key profile cache.
     *
     * Not a unit test; exercises code by processing items.
     */
    public function testGetMainProfilesByKey()
    {
        // Test things without and with local profile cache.
        $this->setJobUsesProfileCache([]);
        $this->doTestGetMainProfilesByKey();
        $this->setJobUsesProfileCache(['key' => true]);
        $this->doTestGetMainProfilesByKey();
    }

    /**
     * Does the actual testGetMainProfilesByKey() work. (Twice, with/out cache.)
     */
    public function doTestGetMainProfilesByKey()
    {
        // Comment if you want logs on screen.
        $GLOBALS['drunkins_log_test_screen'] = WATCHDOG_WARNING;

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
        $api = new TestApi($structure, null, true);
        // Setup (which, ironically, already exercises getMainProfilesByKey()):
        // Call start() to initialize context, but don't fetch items.
        $job__mail = $this->getJob('action_log', ['copernica_profile_key_field' => 'email', 'duplicate_updates' => true], $api);
        $job_context = ['drunkins_override_fetch' => ['anything']];
        $items_not_fetched = $job__mail->start($job_context);

        // Standard job configuration to insert email without main_id.
        $job__mail->processItem(['email' => '1@example.com'], $job_context);
        if ($this->jobUsesProfileCache('key')) {
            // For the API backend, the number of logs is enough to test,
            // because we have comprehensive more basic tests for the backend
            // contents. For the cache backend contents we don't - so check.
            $kv_store = $this->getProfileCache($api);
            $cached_profiles = $kv_store->getAllBatched();
            $this->assertSame(1, count($cached_profiles));
            // Single basic check on validity of structure of cached items.
            // It's 'coincidence' that the case for the field name is correct;
            // it would be "email" if the job didn't define a
            // 'copernica_profile_fields' mapping.
            $this->assertTrue(isset($cached_profiles['1@example.com'][0]['fields']['Email']));
            // The cache will get invalid when we add more items for the same
            // e-mail while the key field is set to main_id. Specifically, this
            // would make the cache item for 1@example.com go stale and make
            // $job__mail fail later on.
            $kv_store->deleteAll();
        }

        // Then get a job with 'main_id' as key field, to insert duplicate
        // e-mail addresses.
        $job__id = $this->getJob('action_log', ['copernica_profile_key_field' => 'main_id'], $api);
        $job__id->processItem(['main_id' => 3, 'email' => '1@example.com'], $job_context);
        $job__id->processItem(['main_id' => 1, 'email' => '1@example.com'], $job_context);
        $this->assertSame(
            ["POST database/$database_id/profiles", "POST database/$database_id/profiles", "POST database/$database_id/profiles"],
            $api->getApiUpdateLog(['POST', 'PUT', 'DELETE'])
        );
        if ($this->jobUsesProfileCache('key')) {
            $cached_profiles = $kv_store->getAllBatched();
            $this->assertSame(2, count($cached_profiles));
            // Set up for next cache test.
            $cached_profiles = $kv_store->delete(3);
        }
        $api->resetApiUpdateLog();
        $GLOBALS['drunkins_log_test_threshold'] = WATCHDOG_NOTICE;

        // Now get a job with two key fields.
        $job__2 = $this->getJob('action_log', ['copernica_profile_key_field' => ['main_id', 'email'], 'duplicate_updates' => true], $api);
        // This should not update anything (same values):
        $job__2->processItem(['main_id' => 3, 'email' => '1@example.com'], $job_context);
        $this->assertSame([], $api->getApiUpdateLog(['POST', 'PUT', 'DELETE']));
        // This should update 1, leaving 2 others with the same e-mail:
        $job__2->processItem(['main_id' => 3, 'email' => '2@example.com'], $job_context);
        // We don't know the Copernica ID, so don't compare full log strings.
        $log = $api->getApiUpdateLog(['POST', 'PUT', 'DELETE']);
        $this->assertSame(1, count($log));
        if ($this->jobUsesProfileCache('key')) {
            // Check if items are also re-cached if not changed.
            $cached_profiles = $kv_store->get(3);
            $this->assertSame(1, count($cached_profiles));
            $this->assertSame('2@example.com', $cached_profiles[0]['fields']['Email']);
        }
        $api->resetApiUpdateLog();

        // Updating purely on the basis of the e-mail address should select
        // two records, and update both if 'duplicate_updates' is set...
        $job__mail->processItem(['first_name' => 'Me', 'email' => '1@example.com'], $job_context);
        $log = $api->getApiUpdateLog(['POST', 'PUT', 'DELETE']);
        $this->assertSame(2, count($log));
        $this->assertSame(1, count($GLOBALS['drunkins_log_test_stack']));
        $this->assertStringStartsWith(
            'Copernica returned 2 profiles for "1@example.com"; taking all of them ',
            t($GLOBALS['drunkins_log_test_stack'][0][0], $GLOBALS['drunkins_log_test_stack'][0][1])
        );
        if ($this->jobUsesProfileCache('key')) {
            $cached_profiles = $kv_store->get('1@example.com');
            $this->assertSame(2, count($cached_profiles));
        }
        $api->resetApiUpdateLog();
        $GLOBALS['drunkins_log_test_stack'] = [];
        // ...and just 1 if 'duplicate_updates' is not set.
        $job__mail1 = $this->getJob('action_log', [], $api);
        $job__mail1->processItem(['first_name' => 'You', 'email' => '1@example.com'], $job_context);
        $log = $api->getApiUpdateLog(['POST', 'PUT', 'DELETE']);
        $this->assertSame(1, count($log));
        $this->assertSame(1, count($GLOBALS['drunkins_log_test_stack']));
        $this->assertStringStartsWith(
            'Copernica returned 2 profiles for "1@example.com"; taking the first one ',
            t($GLOBALS['drunkins_log_test_stack'][0][0], $GLOBALS['drunkins_log_test_stack'][0][1])
        );
        if ($this->jobUsesProfileCache('key')) {
            $cached_profiles = $kv_store->get('1@example.com');
            $this->assertSame(2, count($cached_profiles));
            $this->assertSame('You', $cached_profiles[0]['fields']['Firstname']);
            $this->assertSame('Me', $cached_profiles[1]['fields']['Firstname']);
            // Again delete the record keyed by e-mail; we're only testing ID
            // stuff from now on.
            $kv_store->delete('1@example.com');
            $cached_profiles_snapshot = $kv_store->getAllBatched();
        }
        $api->resetApiUpdateLog();
        $GLOBALS['drunkins_log_test_stack'] = [];

        // When updating based on two key fields id+email, updating an item
        // without ID should select both the record with and without ID (if
        // 'duplicate_updates' is set).
        $job__2->processItem(['first_name' => 'Test', 'email' => '1@example.com'], $job_context);
        $log = $api->getApiUpdateLog(['POST', 'PUT', 'DELETE']);
        $this->assertSame(2, count($log));
        $this->assertSame(1, count($GLOBALS['drunkins_log_test_stack']));
        $this->assertStringStartsWith(
            'Copernica returned 2 profiles for email="1@example.com"; taking all of them ',
            t($GLOBALS['drunkins_log_test_stack'][0][0], $GLOBALS['drunkins_log_test_stack'][0][1])
        );
        if ($this->jobUsesProfileCache('key')) {
            // And nothing should be cached for the non-first key field.
            $cached_profiles = $kv_store->getAllBatched();
            $this->assertSame($cached_profiles_snapshot, $cached_profiles);
        }
        $api->resetApiUpdateLog();
        $GLOBALS['drunkins_log_test_stack'] = [];
        // Updating an item with unknown ID should only take the one without ID
        // (and 'take it over' by inserting its ID into that record).
        $job__2->processItem(['main_id' => 25, 'first_name' => 'Test2', 'email' => '1@example.com'], $job_context);
        $log = $api->getApiUpdateLog(['POST', 'PUT', 'DELETE']);
        $this->assertSame(1, count($log));
        $this->assertSame(0, count($GLOBALS['drunkins_log_test_stack']));
        // main_id was updated.
        $check_result = $api->get("database/$database_id/profiles", ['fields' => ['MainId==25']]);
        $this->assertSame(1, count($check_result['data']));
        if ($this->jobUsesProfileCache('key')) {
            // And this new record should be added (under that ID)
            $cached_profiles = $kv_store->getAllBatched();
            $this->assertSame(count($cached_profiles_snapshot) + 1, count($cached_profiles));
            $cached_profiles = $kv_store->get(25);
            $this->assertSame(1, count($cached_profiles));
        }
        $api->resetApiUpdateLog();
        // Next time we have an unknown ID, there are no records with the same
        // e-mail to take over, so we create a new one.
        $job__2->processItem(['main_id' => 26, 'first_name' => 'Test3', 'email' => '1@example.com'], $job_context);
        $this->assertSame(["POST database/$database_id/profiles"], $api->getApiUpdateLog(['POST', 'PUT', 'DELETE']));
        $this->assertSame(0, count($GLOBALS['drunkins_log_test_stack']));
        if ($this->jobUsesProfileCache('key')) {
            $cached_profiles = $kv_store->getAllBatched();
            $this->assertSame(count($cached_profiles_snapshot) + 2, count($cached_profiles));
        }
        $api->resetApiUpdateLog();
        // To repeat: if we have a record without ID, we update all records
        // with that e-mail address, which in this case all have an ID already.
        // The message mentions 3 records; we update 2 because 1 is already ok.
        $job__2->processItem(['first_name' => 'Test3', 'email' => '1@example.com'], $job_context);
        $log = $api->getApiUpdateLog(['POST', 'PUT', 'DELETE']);
        $this->assertSame(2, count($log));
        $this->assertSame(1, count($GLOBALS['drunkins_log_test_stack']));
        $this->assertStringStartsWith(
            'Copernica returned 3 profiles for email="1@example.com"; taking all of them ',
            t($GLOBALS['drunkins_log_test_stack'][0][0], $GLOBALS['drunkins_log_test_stack'][0][1])
        );
        $this->resetCaches($api, $database_id);
    }

    /**
     * Tests the 'prevent_overwrite_profile_fields' setting and more.
     *
     * This is a specification / quick overview of how updates are influenced
     * by
     * - the 'prevent_overwrite_profile_fields' setting
     * - the 'type' and 'zero_can_overwrite' field settings.
     *
     * Conclusions from the second block:
     * - It's safe to use text fields for fields which our jobs define as
     *   integer. (So we have a choice of getting rid of zero defaults).
     * - Prevent_overwrite_profile_fields can cause unwanted behavior for
     *   integer fields that we forget to define as integer (because then the
     *   default empty value of 0 is regarded non-empty and cannot be changed).
     * - Another unsafe situation would be connected to the contents of the
     *   data source (items). If an item's field is supposed to e.g. contain
     *   only positive integers and 'empty' values, but thase 'empty' values
     *   are represented as zero rather than null/empty string... then that's
     *   regarded as an issue with the incoming data. (The reason we comment on
     *   this here is, that issue could be present if the data source is
     *   another Copernica database containing integer fields - because those
     *   have zeroes for empty values.) There is a way to ignore those zeroes:
     *   define the field as an integer and set 'zero_can_overwrite' = False -
     *   regardless of the actual Copernica type. (If that's "string", the
     *   field will ignore the zero and would stay empty for a new profile.
     *   A Copernica integer field will ignore the zero and would be... zero
     *   for a new profile, regardless of its default... because that's the
     *   standard 'empty' value.)
     *
     * @todo explicitly test non-empty defaults in the field structure? Those
     *   should not make a difference. However, we can only believably do that
     *   after we implement handling of default values in TestApi and encode
     *   it in ApiBehaviorTest. Only then do we have something to build on / do
     *   we really know if we even need to test that explicitly here.)
     */
    public function testPreventOverwriteProfileFields()
    {
        $structure = [
            self::DATABASE_ID => [
                'fields' => [
                    'ID' => ['type' => 'integer', 'value' => 0],
                    'myInt' => ['type' => 'integer', 'value' => 0],
                    'myString' => ['type' => 'text'],
                ],
            ]
        ];
        $api = new TestApi($structure, null, true);
        // Call start() to initialize context, but don't fetch items.
        $job_context = ['drunkins_override_fetch' => ['anything']];
        $items_not_fetched = $this->getJob('id', [], $api)->start($job_context);

        // We won't test a separate job without field settings, as 'text' is
        // effectively the same as 'no settings'.
        $regular_field_settings = [
            'copernica_field_settings' => [
                'myInt' => ['type' => 'integer'],
                'myString' => ['type' => 'text'],
            ],
        ];
        $itemzeroempty_field_settings = [
            'copernica_field_settings' => [
                'myInt' => ['type' => 'integer', 'zero_can_overwrite' => false],
                // This will prove that zero_can_overwrite does not have effect
                // for strings. It's an integer/float only setting.
                'myString' => ['type' => 'text', 'zero_can_overwrite' => false],
            ],
        ];
        $switched_field_settings = [
            'copernica_field_settings' => [
                'myInt' => ['type' => 'text'],
                'myString' => ['type' => 'integer'],
            ],
        ];
        $switched_itemzeroempty_field_settings = [
            'copernica_field_settings' => [
                'myInt' => ['type' => 'text', 'zero_can_overwrite' => false],
                'myString' => ['type' => 'integer', 'zero_can_overwrite' => false],
            ],
        ];
        $job_regular = $this->getJob('id', $regular_field_settings, $api);
        $job_prevent = $this->getJob('id', ['prevent_overwrite_profile_fields' => true] + $regular_field_settings, $api);
        $job_itemzeroempty = $this->getJob('id', $itemzeroempty_field_settings, $api);
        $job_switched_regular = $this->getJob('id', $switched_field_settings, $api);
        $job_switched_prevent = $this->getJob('id', ['prevent_overwrite_profile_fields' => true] + $switched_field_settings, $api);
        $job_switched_itemzeroempty = $this->getJob('id', $switched_itemzeroempty_field_settings, $api);

        $this->assertProfileUpdate(['', ''], ['0', ''], $job_regular, $job_context, $api);
        // prevent_overwrite_fields does not prevent overwriting empty values.
        $this->assertProfileUpdate([2, 2], ['2', '2'], $job_prevent, $job_context, $api);
        // ...but does prevent overwriting non-empty values.
        $this->assertProfileUpdate([3, 3], ['2', '2'], $job_prevent, $job_context, $api);
        $this->assertProfileUpdate([3, 3], ['3', '3'], $job_regular, $job_context, $api);
        // Normally, 0 does overwrite other values; also integers. (That is, a
        // zero _item value_ is not considered empty by default. See last call.)
        $this->assertProfileUpdate([0, 0], ['0', '0'], $job_regular, $job_context, $api);
        // prevent_overwrite_fields does prevent overwriting string '0' which
        // is not 'empty'.
        $this->assertProfileUpdate([2, 2], ['2', '0'], $job_prevent, $job_context, $api);
        // 0 won't overwrite another integer value if we added field setting
        // 'zero_can_overwrite' = False. This has nothing to do with
        // prevent_overwrite_fields; it doesn't consider the current profile
        // value, only the item value (and it considers 0 'empty' for integers).
        $this->assertProfileUpdate([2, 2], ['2', '2'], $job_regular, $job_context, $api);
        $this->assertProfileUpdate([0, 0], ['2', '0'], $job_itemzeroempty, $job_context, $api);

        // Attach wrong definitions to the fields:
        // - first is Copernica integer, now defined as string (which shows us
        //   behavior if we forget to define an integer as integer);
        // - second is Copernica text, now defined as integer (which shows us
        //   behavior if e.g. some process defines an integer field, but we
        //   make it a string because we can't stand all those 0 defaults).
        // We can't empty out values yet(!), that is, make the string field an
        // empty string - so do a second profile. The empty values are as
        // determined by the real fields, of course.
        $this->assertProfileUpdate(['', ''], ['0', ''], $job_switched_regular, $job_context, $api, 2);
        // prevent_overwrite_fields does prevent overwriting a zero if we
        // accidentally classify an integer field as a string (because that
        // sees 0 as non-empty)
        $this->assertProfileUpdate([2, 2], ['0', '2'], $job_switched_prevent, $job_context, $api, 2);
        $this->assertProfileUpdate([3, 3], ['0', '2'], $job_switched_prevent, $job_context, $api, 2);
        $this->assertProfileUpdate([3, 3], ['3', '3'], $job_switched_regular, $job_context, $api, 2);
        // 0 does overwrite - as above.
        $this->assertProfileUpdate([0, 0], ['0', '0'], $job_switched_regular, $job_context, $api, 2);
        // prevent_overwrite_fields does prevent overwriting a *field defined
        // as* text/nothing, which contains '0'.
        $this->assertProfileUpdate([2, 2], ['0', '2'], $job_switched_prevent, $job_context, $api, 2);
        // 0 won't overwrite another integer value if we added field setting
        // 'zero_can_overwrite' = False to a *field defined as* integer, see
        // first block.
        $this->assertProfileUpdate([2, 2], ['2', '2'], $job_switched_regular, $job_context, $api, 2);
        $this->assertProfileUpdate([0, 0], ['0', '2'], $job_switched_itemzeroempty, $job_context, $api, 2);

        // Re. the last part of the method comment: if a zero comes in for a
        // string field defined as an integer + 'zero_can_overwrite' = False,
        // the field stays empty.
        $this->assertProfileUpdate([0, 0], ['0', ''], $job_switched_itemzeroempty, $job_context, $api, 3);
    }

    /**
     * Helper function for testPrevent\OverwriteProfileFields().
     */
    protected function assertProfileUpdate(array $send, array $expect, $job, array $job_context, $api, $id = 1)
    {
        $job->processItem(['ID' => $id, 'myint' => $send[0], 'mystring' => $send[1]], $job_context);
        $profile = $api->get("profile/$id");
        $this->assertSame(['ID' => "$id", 'myInt' => $expect[0], 'myString' => $expect[1]], $profile['fields']);
    }

    /**
     * Tests a synchronization where a collection is a sort of 'action log'.
     *
     * This means that a sub-item processed by the synchronization will lead to
     * a new subprofile being created.
     *
     * Also tests
     * - fields with 'colon' notation being properly recognized/converted
     * - a few scenarios where field names in a (sub)item are not equally cased;
     * - 'copernica_profile_fields' setting (i.e. item field names differing
     *   from Copernica field names), also combined with the previous point;
     * - a few lower-level aspects of the cache,
     * because we have no dedicated lower-level tests for that.
     */
    public function testActionLogSync()
    {
        // Test things without and with local profile cache.
        $this->setJobUsesProfileCache([]);
        $this->doTestActionLogSync();
        $this->setJobUsesProfileCache(['key' => true]);
        $this->doTestActionLogSync();
    }

    /**
     * Does the actual testActionLogSync() work. (Twice, with/without cache.)
     */
    public function doTestActionLogSync()
    {
        // Comment if you want logs on screen. (This test issues warnings and
        // tests for them.)
        $GLOBALS['drunkins_log_test_screen'] = WATCHDOG_ERROR;

        $structure = [
            self::DATABASE_ID => [
                'fields' => [
                    'Email' => ['type' => 'email'],
                    'Firstname' => ['type' => 'text'],
                    'Lastname' => ['type' => 'text'],
                ],
                'collections' => [
                    self::COLLECTION_ID => [
                        'fields' => [
                            'Date' => ['type' => 'empty_date'],
                            'Type' => ['type' => 'text'],
                        ]
                    ]
                ]
            ]
        ];
        $api = new TestApi($structure, null, true);
        $database_id = self::DATABASE_ID;
        $collection_id = self::COLLECTION_ID;

        // Call start() to initialize context, but don't fetch items.
        $job_context = ['drunkins_override_fetch' => ['anything']];
        $items_not_fetched = $this->getJob('action_log', [], $api)->start($job_context);
        // For this job configuration, an item's profile fields do not have
        // the literal Copernica field names; they are mapped inside the job.
        // The subprofile data do have the Copernica field names.
        $item = [
            // Value 99 is explicitly ignored / warned about, because the
            // fact that it comes before a colon makes explicit that it's
            // supposed to be a collection ID... even though a standalone "99"
            // key would not cause a warning.
            '99:type' => '?',
            'email' => 'rm@wyz.biz',
            'first_name' => 'Roderik',
            // Should cause warning for being ignored:
            'last_name' => '?',
            'last_Name' => 'Muit',
            // The 0,date value causes warning for being ignored; the other
            // one causes warning for being the one to be taken.
            "$collection_id:date" => '',
            "$collection_id,0:daTe" => '2020-07-23',
            $collection_id => [
                'Date' => '2020-07-20',
                // Should cause warning for being ignored:
                'TYpe' => '?',
                'Type' => 'email',
            ],
            // Same: 2 warnings; 'facebook' gets taken.
            "$collection_id,0:TYPE" => '!',
            "$collection_id:TYpe" => 'facebook',
        ];
        $GLOBALS['drunkins_log_test_threshold'] = WATCHDOG_WARNING;
        $this->getJob('', [], $api)->processItem($item, $job_context);
        // As long as we use SQLite we can assume this. Otherwise we may need
        // to derive it from a get(), or just from getApiUpdateLog().
        $profile_id = 1;
        // Check if two POST requests were done. (We don't necessarily have to
        // check the exact URLS but hey, as long as we can... We also don't
        // have to verify contents in the database backend because
        // TestApiBaseTest is doing that sufficiently.)
        $this->assertSame(
            ["GET database/$database_id/profiles", "POST database/$database_id/profiles", "POST profile/$profile_id/subprofiles/$collection_id"],
            $api->getApiUpdateLog()
        );
        $this->assertSame(
            [
                "Ignoring '99:type' field in item, as 99:type_root is not a collection ID.",
                "Item's 'last_name' field value is ignored because it also contains a 'last_Name' field.",
                "(Sub)item's '45:date' field value is ignored because it also contains a '45,0:daTe' field.",
                "Subitem's 'TYpe' field value is ignored because it also contains a 'Type' field.",
                "(Sub)item's '45,0:TYPE' field value is ignored because it also contains a '45:TYpe' field.",
                "Subitem's 'Date' field value is ignored because the item also contains a '45,0:daTe' field.",
                "Subitem's 'Type' field value is ignored because the item also contains a '45:TYpe' field."
            ],
            $this->getDrunkinsLogs()
        );
        if ($this->jobUsesProfileCache('key')) {
            $kv_store = $this->getProfileCache($api);
            $cached_profiles = $kv_store->getAllBatched();
            $this->assertSame(1, count($cached_profiles));
            // Caching is off for subprofiles.
            $kv_store_sub = $this->getProfileCache($api, self::COLLECTION_ID);
            $cached_profiles = $kv_store_sub->getAllBatched();
            $this->assertSame(0, count($cached_profiles));
        }
        // Check if the latest subprofile has the expected values.
        $response = $api->get("collection/$collection_id/subprofiles");
        $last_sub = end($response['data']);
        $this->assertSame(['Date' => '2020-07-23', 'Type' => 'facebook'], $last_sub['fields']);

        // (With the job settings as they are,) if we insert data with the same
        // email, a new subprofile gets created, but not a new profile. The
        // profile PUT (update) should not be called at all if no fields are
        // changing. GET should not be called either if caching is on. Empty
        // fields do not cause changes. Differently cased fields are updated.
        $api->resetApiUpdateLog();
        $item = [
            'eMAIL' => 'rm@wyz.biz',
            'first_NAme' => 'Roderik',
            'last_name' => '',
            self::COLLECTION_ID => [
                'date' => '2020-07-29',
                'TYPE' => 'email',
            ]
        ];
        $this->getJob('', [], $api)->processItem($item, $job_context);
        if ($this->jobUsesProfileCache('key')) {
            $this->assertSame(
                ["POST profile/$profile_id/subprofiles/$collection_id"],
                $api->getApiUpdateLog()
            );
            $cached_profiles = $kv_store->getAllBatched();
            $this->assertSame(1, count($cached_profiles));
            // No duplication in cache either.
            $this->assertSame(1, count($cached_profiles['rm@wyz.biz']));
            // These are the only low-level cache assertions done; more details
            // are already cached in e.g. doTestGetMainProfilesByKey().
        } else {
            $this->assertSame(
                ["GET database/$database_id/profiles", "POST profile/$profile_id/subprofiles/$collection_id"],
                $api->getApiUpdateLog()
            );
        }
        // Check if the latest subprofile actually has its fields populated.
        $response = $api->get("collection/$collection_id/subprofiles");
        $last_sub = end($response['data']);
        $this->assertSame(['Date' => '2020-07-29', 'Type' => 'email'], $last_sub['fields']);

        // Profile field overwriting (except with empty value as above) is not
        // prohibited. Not-set fields are not emptied out either.
        $api->resetApiUpdateLog();
        $item = [
            'email' => 'rm@wyz.biz',
            'first_name' => 'Piet',
            self::COLLECTION_ID => [
                'Date' => '2020-07-20',
                'Type' => 'email',
            ]
        ];
        $this->getJob('', [], $api)->processItem($item, $job_context);
        $this->assertSame(
            ["PUT profile/$profile_id/fields", "POST profile/$profile_id/subprofiles/$collection_id"],
            $api->getApiUpdateLog(['POST', 'PUT', 'DELETE'])
        );
        $api_client = new TestRestClient($api);
        $check_profiles = $api_client->getEntities("database/$database_id/profiles", ['fields' => ['Firstname==Piet', 'Lastname==Muit']]);
        $this->assertSame(1, count($check_profiles));

        // Only case insensitivity changes in the main profile are not updated.
        $api->resetApiUpdateLog();
        $item['first_name'] = 'PIET';
        unset($item['last_name']);
        $this->getJob('action_log', [
            'copernica_field_settings' => [
              'Email' => ['compare_case_insensitive' => true],
              'Firstname' => ['compare_case_insensitive' => true],
              'Lastname' => ['compare_case_insensitive' => true],
            ],
        ], $api)->processItem($item, $job_context);
        $this->assertSame(
            ["POST profile/$profile_id/subprofiles/$collection_id"],
            $api->getApiUpdateLog(['POST', 'PUT', 'DELETE'])
        );

        // More a spec/documentation of behavior than a test (because we don't
        // need it and would even prefer it was different): what happens when
        // we process a profile that was deleted? Initialization:
        $response = $api->get("database/$database_id/profiles");
        $this->assertSame(1, count($response['data']));
        // After something (not the job, so caches are left alone) deletes a
        // profile, its subprofiles are gone too:
        $api->delete("profile/$profile_id");
        $response = $api->get("database/$database_id/profiles");
        $this->assertSame(0, count($response['data']));
        $response = $api->get("collection/$collection_id/subprofiles");
        $this->assertSame(0, count($response['data']));
        // Now, the real test:
        $this->getJob('', [], $api)->processItem($item, $job_context);
        $profiles_response = $api->get("database/$database_id/profiles");
        $profiles_count = count($response['data']);
        $response = $api->get("collection/$collection_id/subprofiles");
        if ($this->jobUsesProfileCache('key')) {
            // A new subprofile was created for the old removed main profile,
            // because Copernica just allows that - and because the job assumes
            // it still exists (because it was cached locally).
            $this->assertSame(0, count($profiles_response['data']));
            $this->assertSame(1, count($response['data']));
            $this->assertEquals($profile_id, $response['data'][0]['profile']);
        } else {
            // A new profile + subprofile were created. (Note - this is not the
            // live configuration for our job.)
            $this->assertSame(1, count($profiles_response['data']));
            $this->assertSame(1, count($response['data']));
            $this->assertEquals($profiles_response['data'][0]['ID'], $response['data'][0]['profile']);
        }

        $this->resetCaches($api, $database_id);
    }

    /**
     * Tests a synchronization where sub-item data goes into two collections.
     *
     * Also tests
     * - getHighestCopernicaValue() (because I'm lazy and don't want to do
     *   separate setup)
     * - scenarios where field names in a (sub)item are not equally cased;
     * - basic tests around caching (e.g. modified/created date, does cache
     *   not get confused by unequal casing). Some by-key cache stuff is
     *   already tested elsewhere, though.
     * - and some other edge cases at the end of this method, which may or may
     *   not have to do with cache behavior only, not with the Magento sync.
     * It almost feels like we should untangle some things (e.g cache validity
     * tests from the subprofile-update testing with 'key_field' settings?) and
     * separate them into another method. But I'll leave that for the future to
     * really judge.
     */
    public function testMagentoImport()
    {
        // Comment if you want logs on screen. (The job itself tests error
        // messages, so we have 0 instead of WATCHDOG_ERROR.)
        $GLOBALS['drunkins_log_test_screen'] = 0;
        // Test things without and with 'serial key' & local profile cache.
        $this->setJobUsesProfileCache([]);
        $this->doTestMagentoImport();

        $GLOBALS['drunkins_log_test_screen'] = 0;
        $this->setJobUsesProfileCache(['serial' => true]);
        $this->doTestMagentoImport();

        // The most logical values for caching are subprofile (by-key) caching
        // plus by-id caching. They really should not influence each other so
        // we don't need to test them independently. (They could be tested
        // independently in two runs, if the tests start failing / need to be
        // debugged...)
        $GLOBALS['drunkins_log_test_screen'] = 0;
        $this->setJobUsesProfileCache(['sub' => true, 'id' => true, 'serial' => true]);
        $this->doTestMagentoImport();
        $GLOBALS['drunkins_log_test_screen'] = 0;
        $this->setJobUsesProfileCache(['sub' => true, 'id' => true]);
        $this->doTestMagentoImport();
        //$GLOBALS['drunkins_log_test_screen'] = 0;
        //$this->setJobUsesProfileCache([]);
        //$this->doTestMagentoImport();
        //$GLOBALS['drunkins_log_test_screen'] = 0;
        //$this->setJobUsesProfileCache(['sub' => true]);
        //$this->doTestMagentoImport();

        $GLOBALS['drunkins_log_test_screen'] = 0;
        $this->setJobUsesProfileCache(['key' => true]);
        $this->doTestMagentoImport();
    }

    /**
     * Does the actual testActionLogSync() work. (Twice, with/without cache.)
     */
    public function doTestMagentoImport()
    {
        // It doesn't matter if our various 'ID' fields are defined as text or
        // integer. The difference on the Copernica side is that optional
        // fields (like MagentoCustomerId) will be 0 if empty, but that should
        // not matter for our synchronization. (It would matter if our input
        // had 0 for empty fields, or if 'prevent_overwrite_profile_fields' was
        // on, but that's not the case.) To test this, we'll randomly make this
        // integers vs strings, and we'll randomly leave the 'integer'
        // definition away in getJob().
        $id_type = rand(0, 1) ? ['type' => 'text'] : ['type' => 'integer', 'value' => 0];
        __drunkins_log('copernica_update_test', "TestApi uses '@type' ID fields.", ['@type' => $id_type['type']], WATCHDOG_DEBUG, []);

        $structure = [
            self::DATABASE_ID => [
                'fields' => [
                    'MagentoCustomerId' => $id_type,
                    'Email' => ['type' => 'email'],
                    'Name' => ['type' => 'text'],
                ],
                'collections' => [
                    self::COLLECTION_ID => [
                        'fields' => [
                            'OrderId' => $id_type,
                            'Total' => ['type' => 'float'],
                            'Status' => ['type' => 'text'],
                        ]
                    ],
                    self::COLLECTION2_ID => [
                        'fields' => [
                            'ItemId' => $id_type,
                            // As far as we're concerned, OrderId is just a
                            // different field picked up from another source
                            // field in the Magento import. No special testing,
                            // so leaving out for brevity of items.
                            // 'OrderId' => $id_type,
                            'SKU' => ['type' => 'text'],
                        ]
                    ]
                ]
            ]
        ];
        $api = new TestApi($structure, null, true);

        // Call start() to initialize context, but don't fetch items.
        $job_context = ['drunkins_override_fetch' => ['anything']];
        $items_not_fetched = $this->getJob('magento_import', [], $api)->start($job_context);

        // Basic error check: If not all sub-items have key field values, the
        // valid sub-items still get inserted as subprofiles (and the main
        // profile too obviously, except depending on settings if there are no
        // subprofiles).
        $item = [
            'email' => 'rm@wyz.biz', 'name' => 'me',
            // I don't know or care if 'status' is correct...
            self::COLLECTION_ID => ['OrderId' => 1, 'Total' => 9.99, 'Status' => 'pending'],
            self::COLLECTION2_ID => [['SKU' => 'prod-345', 'ItemId' => 3], ['SKU' => 'prod-346']]
        ];
        $GLOBALS['drunkins_log_test_threshold'] = WATCHDOG_WARNING;
        $this->getJob('', [], $api)->processItem($item, $job_context);
        $database_id = self::DATABASE_ID;
        $collection_id = self::COLLECTION_ID;
        $collection2_id = self::COLLECTION2_ID;
        // As long as we use SQLite we can assume this. Otherwise we may need
        // to derive it from a get(), or just from getApiUpdateLog(). Being
        // lazy for now.
        $profile_id = 1;
        $timestamp = time();

        // First, subprofiles are queried and not found. Then profile. Then
        // profile + subprofiles inserted - except the third/incomplete one.
        // GETs are always done because profiles are not cached yet.
        $this->assertUpdateLog(1, 1, true, [
            "POST database/$database_id/profiles",
            "POST profile/$profile_id/subprofiles/$collection_id",
            "POST profile/$profile_id/subprofiles/$collection2_id",
        ], $api);
        $this->assertSame(1, count($GLOBALS['drunkins_log_test_stack']));
        $this->assertStringStartsWith(
            "'ItemId' key not present in item's subprofile data for collection 58; it cannot be processed:",
            t($GLOBALS['drunkins_log_test_stack'][0][0], $GLOBALS['drunkins_log_test_stack'][0][1])
        );
        // Unlike the API backend, we lack comprehensive more basic tests for
        // the cache backend contents. Roll them into this test for 'sub'/'id'.
        // ('key' is done in testGetMainProfilesByKey().)
        if ($this->jobUsesProfileCache('id')) {
            $kv_store_id = $this->getProfileCache($api, '-');
            $cached_profile = $kv_store_id->get($profile_id);
            // Basic check on validity of structure of cached items. Field name
            // casing is as provided in the item - not normalized to the actual
            // Copernica field name. (Not that it has to be - but we'll test
            // for it, for the moment.)
            $this->assertSame('rm@wyz.biz', $cached_profile['fields']['email']);
            // Do the same check for created/modified date on the cached entry
            // as we do in ApiBehaviorTest, because the cached value is likely
            // generated by us, not Copernica.
            $this->assertInDateRange($api, $cached_profile, 'created', $timestamp, 1);
            $this->assertInDateRange($api, $cached_profile, 'modified', $timestamp, 1);
        }
        if ($this->jobUsesProfileCache('key')) {
            // E-mail addresses are not cached; only the 'first key field' is.
            // (Which was empty here). We won't do other deta8l checks for the
            // "key" cache because those are spread over earlier tests.
            $kv_store_key = $this->getProfileCache($api);
            $this->assertEmpty($kv_store_key->getAllBatched());
        }
        if ($this->jobUsesProfileCache('sub')) {
            $kv_store_c1 = $this->getProfileCache($api, self::COLLECTION_ID);
            $cached_profiles = $kv_store_c1->get(1);
            $this->assertSame(1, count($cached_profiles));
            $this->assertInDateRange($api, $cached_profiles[0], 'created', $timestamp, 1);
            $this->assertInDateRange($api, $cached_profiles[0], 'modified', $timestamp, 1);
            $kv_store_c2 = $this->getProfileCache($api, self::COLLECTION2_ID);
            $cached_profiles = $kv_store_c2->get(3);
            $this->assertSame(1, count($cached_profiles));
            $this->assertSame('prod-345', $cached_profiles[0]['fields']['SKU']);
        }

        // For all future assertions where we only query the cache for existing
        // subprofiles, not Copernica:
        $q1_ifnocache = $this->jobUsesProfileCache('sub') ? 0 : 1;

        // Test/use cases for next test update:
        // - existing user gets customer ID. (Is already tested elsewhere but
        //   let's test it in the full sync with caching and subprofiles.)
        // - one subprofile gets updated (status), other one does not. So
        //   the mis-cased fields are recognized as being "equal".
        // We've set NOTICE for key-change log level in our job settings.
        $api->resetApiUpdateLog();
        if ($this->jobUsesProfileCache('id') || $this->jobUsesProfileCache('sub')) {
            // To test modified times in cached entries:
            sleep(1);
        }
        $GLOBALS['drunkins_log_test_stack'] = [];
        $GLOBALS['drunkins_log_test_threshold'] = WATCHDOG_NOTICE;
        $item = [
            'MagentoCustomerId' => 4, 'eMAil' => 'rm@wyz.biz', 'Name' => 'ME',
            // I don't know or care if the 'status' is correct for Magento...
            self::COLLECTION_ID => ['ORDERId' => 1, 'Total' => 9.99, 'STAtus' => 'shipping'],
            self::COLLECTION2_ID => [['Sku' => 'prod-345', 'itemId' => 3]]
        ];
        $this->getJob('', [], $api)->processItem($item, $job_context);
        $profile_queries = $this->jobUsesProfileCache('id') ? [] : [$profile_id];
        if ($this->jobUsesProfileCache('serial')) {
            // database/N/profiles is queried this one first time, to populate
            // the state value.
            $profile_queries[] = 0;
        }
        $this->assertUpdateLog($q1_ifnocache, $q1_ifnocache, $profile_queries, [
            "PUT profile/$profile_id/fields",
            // Subprofile in collection 1 == 1
            "PUT subprofile/1/fields",
        ], $api);
        $this->assertSame(1, count($GLOBALS['drunkins_log_test_stack']));
        $message = t($GLOBALS['drunkins_log_test_stack'][0][0], $GLOBALS['drunkins_log_test_stack'][0][1]);
        // The actual value alternates betwee '0', '' and <empty> - we won't
        // test for it.
        $this->assertStringStartsWith("Unexpected value encountered for the 'MagentoCustomerId' value: the item to update contains 4 but the Copernica profile linked to the existing subprofiles we're updating, is ", $message);
        $this->assertStringContainsString("The value will be updated in Copernica. Full item for reference:", $message);
        // Check if all fields were updated, including the mis-cased ones.
        $response = $api->get("collection/$collection_id/subprofiles");
        $this->assertSame(1, count($response['data']));
        $this->assertSame(['OrderId' => '1', 'Total' => '9.99', 'Status' => 'shipping'], $response['data'][0]['fields']);
        if ($this->jobUsesProfileCache('id')) {
            $cached_profile = $kv_store_id->get($profile_id);
            // Basic check on validity of structure of cached upaated items -
            // with mis-cased key field. Fieldname casing is as provided in the
            // initial item that was cached; it's not absolutely guaranteed to
            // stay that way but we'll do a strict test for the moment. We'll
            // see about what to test exactly, whenever this starts failing.
            $this->assertSame(['email' => 'rm@wyz.biz', 'name' => 'ME', 'MagentoCustomerId' => 4], $cached_profile['fields']);
            // We'll just test that created/modified now differ. (Officially
            // should also test that created is still the same as previously.)
            $this->assertNotEquals($cached_profile['created'], $cached_profile['modified']);
        }
        if ($this->jobUsesProfileCache('sub')) {
            $cached_profiles = $kv_store_c1->get(1);
            $this->assertSame(1, count($cached_profiles));
            $this->assertSame('shipping', $cached_profiles[0]['fields']['Status']);
            $this->assertNotEquals($cached_profiles[0]['created'], $cached_profiles[0]['modified']);
            // Ideally we'd have a $cached_profiles[1] and check that its
            // 'modified' did not change. We'll test it on the not-updated
            // profile in collection 2.
            $cached_profiles = $kv_store_c2->get(3);
            $this->assertSame($cached_profiles[0]['created'], $cached_profiles[0]['modified']);
        }

        // Maybe this is duplicate/superfluous:
        // If we have an existing subprofile in one collection and a
        // nonexistent one in another, all subprofiles get inserted-or-updated
        // for that particular profile. There is no check whether other
        // subprofiles already exist, no crosscheck between earlier inserted
        // orders + order items. (The latter should be done by the item
        // fetcher/queuer if needed, for Magento.)
        $customer_id_testlater = 5;
        $item_id_testlater = 6;
        $api->resetApiUpdateLog();
        $item = [
            'MagentoCustomerId' => $customer_id_testlater, 'eMAil' => 'rm@wyz.biz', 'Name' => 'me',
            self::COLLECTION_ID => ['OrderId' => 3, 'Total' => 9.99, 'STAtus' => 'shipping'],
            self::COLLECTION2_ID => [['Sku' => 'prod-346', 'itemId' => 3], ['Sku' => 'prod-345', 'itemId' => $item_id_testlater]]
        ];
        $this->getJob('', [], $api)->processItem($item, $job_context);
        // From only checking the API log, we can derive that all subprofiles
        // were inserted to the existing profile. GET queries for subprofiles
        // - are done for new subprofiles if no 'key_field_serial' (because
        //   both OrderId and ItemId are higher than any previous ones);
        // - + 1 optionally for an existing subprofile.
        $q1_ifnoserial = $this->jobUsesProfileCache('serial') ? 0 : 1;
        $this->assertUpdateLog($q1_ifnoserial, $q1_ifnoserial + $q1_ifnocache, $this->jobUsesProfileCache('id') ? false : [$profile_id], [
            // Update profile. Insert new subprofile in collection 1,
            // update existing subprofile + insert new in collection 2.
            "PUT profile/$profile_id/fields",
            "POST profile/$profile_id/subprofiles/$collection_id",
            // Subprofile in collection 2 == 2, by coincidence.
            "PUT subprofile/2/fields",
            "POST profile/$profile_id/subprofiles/$collection2_id",
        ], $api);
        if ($this->jobUsesProfileCache('sub')) {
            // Check _subprofile creation_ with mis-cased key field. With that,
            // we're hopefully complete on the basic checks. (We may not have
            // explicitly checked main profile creation with mis-cased key.)
            $this->assertNotEmpty($kv_store_c2->get($item_id_testlater));
        }

        // Insert a second profile with the same e-mail.
        $api->resetApiUpdateLog();
        $item = [
            'MagentoCustomerId' => 1, 'eMAil' => 'rm@wyz.biz', 'Name' => 'not-me',
        ];
        $this->getJob('', [], $api)->processItem($item, $job_context);
        $this->assertSame([
            // The job queries for the profile twice: by ID and email.
            "GET database/$database_id/profiles",
            "GET database/$database_id/profiles",
            "POST database/$database_id/profiles",
        ], $api->getApiUpdateLog());

        // Test/use case:
        // - duplicate_updates. (When not adding a customer ID, both existing
        //   profiles will be selected and upaated and have a subprofile
        //   updated/inserted.) In order to select / update both existing main
        //   profiles, we'll need to change the key field because otherwise
        //   we'll only try to select records with the below email + an empty
        //   customer ID. We also cannot have any subprofiles with existing
        //   key field values, because that will not select the profile based
        //   on the e-mail address.
        $api->resetApiUpdateLog();
        $GLOBALS['drunkins_log_test_stack'] = [];
        $more_settings = [
            'duplicate_updates' => true,
            'copernica_profile_key_field' => 'email',
        ];
        $item = [
            'eMAil' => 'rm@wyz.biz', 'name' => 'me',
            self::COLLECTION_ID => ['OrderId' => 4, 'Status' => 'shipping'],
            self::COLLECTION2_ID => ['Sku' => 'prod-347', 'itemId' => 7]
        ];
        $this->getJob('magento_import', $more_settings, $api)->processItem($item, $job_context);
        $this->assertStringStartsWith(
            'Copernica returned 2 profiles for "rm@wyz.biz"; taking all of them (IDs 1, 2).',
            t($GLOBALS['drunkins_log_test_stack'][0][0], $GLOBALS['drunkins_log_test_stack'][0][1])
        );
        $this->assertUpdateLog($q1_ifnoserial, $q1_ifnoserial, true, [
            // First profile is already equal.
            "PUT profile/2/fields",
            "POST profile/$profile_id/subprofiles/$collection_id",
            "POST profile/2/subprofiles/$collection_id",
            "POST profile/$profile_id/subprofiles/$collection2_id",
            "POST profile/2/subprofiles/$collection2_id",
        ], $api);
        // General spec: 'duplicate_updates' does not have effect on
        // existing subprofiles. If multiple are found, all are always updated.
        $item = [
            self::COLLECTION_ID => ['OrderId' => 4, 'STAtus' => 'SHIPPING'],
        ];
        $api->resetApiUpdateLog();
        $this->getJob('', [], $api)->processItem($item, $job_context);
        $this->assertUpdateLog($q1_ifnocache, 0, $this->jobUsesProfileCache('id') ? false : [$profile_id, 2], [
            "PUT subprofile/5/fields",
            "PUT subprofile/6/fields",
        ], $api);

        // Another general spec: 'compare_case_insensitive' prevents updates.
        $more_settings = [
            'copernica_field_settings' => [
                "$collection_id:status" => ['compare_case_insensitive' => true]
            ]
        ];
        $item = [
            self::COLLECTION_ID => ['OrderId' => 4, 'STAtus' => 'Shipping'],
        ];
        $api->resetApiUpdateLog();
        $this->getJob('magento_import', $more_settings, $api)->processItem($item, $job_context);
        // Only GETs of the subprofile + profiles.
        $this->assertUpdateLog($q1_ifnocache, 0, $this->jobUsesProfileCache('id') ? false : [$profile_id, 2], [], $api);

        // Import will completely break if we have an item that leads back to
        // different profiles through their collections' keys.
        $api->resetApiUpdateLog();
        $GLOBALS['drunkins_log_test_stack'] = [];
        $item = [
            'eMAil' => 'rm@wyz.biz',
            self::COLLECTION_ID => ['OrderId' => 4, 'Status' => 'shipping'],
            self::COLLECTION2_ID => ['Sku' => 'prod-347', 'itemId' => 3]
        ];
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('differ from main profiles');
        $this->getJob('', [], $api)->processItem($item, $job_context);
        $this->assertSame([], $api->getApiUpdateLog());

        // More general tests for the job, not connected to the Magento import:

        // Do some edge case checks to see if updateMainProfiles() handles the
        // by-key cache well (but we'll run the code also when caching is off).
        // By-key caching isn't very logical for the Magento import as long as
        // it precludes by-id caching. We're just running this at the end of
        // the Magento import because we already have the necessary setup done.
        if ($this->jobUsesProfileCache('sub')) {
            // As a pre-check: 1 item is cached: (We literally tested this
            // above, but to get some separation, do it again.)
            $result = $kv_store_c2->get($item_id_testlater);
            $this->assertSame(1, count($result));
        }
        $api->resetApiUpdateLog();
        // Setup: needs a subprofile inserted with the same key field as one
        // that already exists, attached to a main profile containing values
        // that differ from the existing main profile. This is impossible to do
        // with a job that has a 'key_field' defined for the collection, so
        // This turns off the key field setting, and probably also caching if
        // it was turned on.
        $more_settings = [
            'copernica_collections' => [
                    self::COLLECTION2_ID => ['name' => 'OrderItems'],
            ],
        ];
        $item = [
            'MagentoCustomerId' => 23, 'email' => 'eh@example.com', 'name' => 'eh',
            self::COLLECTION2_ID => ['Sku' => 'prod-345', 'itemId' => $item_id_testlater]
        ];
        $this->getJob('magento_import', $more_settings, $api)->processItem($item, $job_context);
        // The job queries for the profile twice: by ID and email.
        $profile_queries = $this->jobUsesProfileCache('serial') ? [] : [0, 0];
        $this->assertUpdateLog(0, 0, $profile_queries, [
            "POST database/3/profiles",
            "POST profile/3/subprofiles/$collection2_id",
        ], $api);
        if ($this->jobUsesProfileCache('key')) {
            // A last pre-check: now 1`customer present with ID 23.
            $result = $this->getProfileCache($api, 0)->get(23);
            $this->assertSame(1, count($result));
            // ...and 1 customer with this old ID.
            $result = $this->getProfileCache($api, 0)->get($customer_id_testlater);
            $this->assertSame(1, count($result));
        }
        // The subprofile cache entry is now outdated because it only contains
        // the subprofile that was already in there, not this inserted entry;
        // remove it.
        if ($this->jobUsesProfileCache('sub')) {
            $this->getProfileCache($api, self::COLLECTION2_ID)->delete($item_id_testlater);
        }

        // Now send in an update which should hit those two profiles, and
        // update one. (This is mainly about checking whether the by-key cache
        // entry for the main profile is as expected.)
        $api->resetApiUpdateLog();
        $item = [
            'MagentoCustomerId' => 23,
            self::COLLECTION2_ID => ['Sku' => 'prod-345', 'itemId' => $item_id_testlater]
        ];
        $this->getJob('', [], $api)->processItem($item, $job_context);
        $this->assertUpdateLog(0, 1, $this->jobUsesProfileCache('id') ? false : [$profile_id, 3], [
            "PUT profile/1/fields",
        ], $api);
        if ($this->jobUsesProfileCache('key')) {
            // 1 customer was updated to 23; another one (the most recently
            // inserted) was not updated. Both should be in the cache.
            $result = $this->getProfileCache($api, 0)->get(23);
            $this->assertSame(2, count($result));
            // Both have a different e-mail address. (Actually the order of
            // the cached items, the case of the field names and the type of
            // the number field shouldn't be taken to be guaranteed; I'm just
            // trying to finish quickly.)
            $this->assertSame(['MagentoCustomerId' => 23, 'Email' => 'rm@wyz.biz', 'Name' => 'me'], $result[0]['fields']);
            $this->assertSame(['MagentoCustomerId' => 23, 'email' => 'eh@example.com', 'name' => 'eh'], $result[1]['fields']);

            // ...and the entry with the old ID shold be gone.
            $result = $this->getProfileCache($api, 0)->get($customer_id_testlater);
            $this->assertSame([], $result);
        }
        if ($this->jobUsesProfileCache('sub')) {
            // No updates were done, but the cache should be repopulated with
            // the two existing profiles.
            $result = $kv_store_c2->get($item_id_testlater);
            $this->assertSame(2, count($result));
        }

        $this->resetCaches($api, self::DATABASE_ID);
    }

    /**
     * Compares the update log against a variable amount of GET queries.
     */
    protected function assertUpdateLog($get_for_sub_c1, $get_for_sub_c2, $get_for_individual_profiles, $other_exptected_logs, $api)
    {
        // Not abstracted yet; don't want to think about this right now.
        $database_id = self::DATABASE_ID;
        $collection_id = self::COLLECTION_ID;
        $collection2_id = self::COLLECTION2_ID;

        $expected = [];
        if ($get_for_sub_c1) {
            for ($i = 1; $i <= $get_for_sub_c1; $i++) {
                $expected[] = "GET collection/$collection_id/subprofiles";
            }
        }
        if ($get_for_sub_c2) {
            for ($i = 1; $i <= $get_for_sub_c2; $i++) {
                $expected[] = "GET collection/$collection2_id/subprofiles";
            }
        }
        if ($get_for_individual_profiles) {
            if (is_array($get_for_individual_profiles)) {
                // We queried for profile(s) by ID.
                foreach ($get_for_individual_profiles as $id) {
                    $expected[] = $id ? "GET profile/$id" : "GET database/$database_id/profiles";
                }
            } else {
                // We queried by fieldname/value (i.e. not found in by-key
                // cache).
                $expected[] = "GET database/$database_id/profiles";
            }
        }
        $expected = array_merge($expected, $other_exptected_logs);
        $this->assertSame($expected, $api->getApiUpdateLog());
    }

    /**
     * Asserts that a string date value is in a certain format / date range.
     *
     * @param CopernicaRestAPI|TestApi $api
     *   An API instance, for getting the date format.
     * @param array $entity
     *   The entity (profile, subprofile, or likely other)
     * @param string $property
     *   The name of the property whose value to compare.
     * @param int $compare_timestamp
     *   Timestamp to compare the date value to.
     * @param int $deviation_seconds
     *   (Optional) allowed number of seconds (0 or larger) which the compared
     *   date value is allowed to be larger than the timestamp.
     */
    private function assertInDateRange($api, array $entity, $property, $compare_timestamp, $deviation_seconds = 0)
    {
        $this->assertTrue(isset($entity[$property]));

        $compare_datetimes = [];
        $date = $this->getApiDateTimeObject($api);
        do {
            $date->setTimestamp($compare_timestamp);
            $compare_datetimes[] = $date->format('Y-m-d H:i:s');
            $compare_timestamp++;
        } while ($deviation_seconds-- > 0);

        $this->assertTrue(in_array($entity[$property], $compare_datetimes, true));
    }

    /**
     * Gets DateTime object with correct timezone for the API.
     *
     * This may be overkill because we're not sure anymore about the sense of
     * having a configurable timezone for TestApi. Whatever.
     *
     * @param CopernicaRestAPI|TestApi $api
     *   An API instance.
     *
     * @return DateTime
     *   Datetime object; time set to 'now'.
     */
    private function getApiDateTimeObject($api)
    {
        $date = new DateTime();
        if ($api instanceof TestApi) {
            $timezone = $api->getTimezone();
        } else {
            // We don't know the timezone used by the real API and we don't
            // know if there's even a setting. Take our default.
            $timezone = Helper::TIMEZONE_DEFAULT;
        }
        $date->setTimezone(new DateTimeZone($timezone));
        return $date;
    }

    /**
     * Returns contents of Drunkins test log stack, with placeholders replaced.
     *
     * @return string[]
     *   The log messages.
     */
    protected function getDrunkinsLogs()
    {
        $logs = [];
        foreach ($GLOBALS['drunkins_log_test_stack'] as $log) {
            $logs[] = t($log[0], $log[1]);
        }
        return $logs;
    }

    /**
     * Reset caches so the next test won't be influenced by them.
     */
    protected function resetCaches($api, $database_id, $reset_caches = true)
    {
        /** @var TestApi $api */
        $response = $api->get("database/$database_id/profiles");
        foreach ($response['data'] as $profile) {
            $api->delete("profile/{$profile['ID']}");
        }
        // And the cache.
        if ($reset_caches) {
            //$this->getProfileCache($api)->deleteAll();
            // Too bad we can't access the KeyValueStore object
            // easily, because we should really be calling deleteAll() on that.
            $api->getPdoConnection()->exec("TRUNCATE key_value");
        }
        // Also, reset logs.
        $api->resetApiUpdateLog();
        unset($GLOBALS['drunkins_log_test_threshold']);
        unset($GLOBALS['drunkins_log_test_stack']);
        unset($GLOBALS['drunkins_log_test_screen']);
        // We need to reset the job because it's the only way to reset the
        // RestClient client class for the next job- which retains a reference
        // to an old TestApi class with an old database schema.
        $this->getJob(false);
    }

    /**
     * Returns an instance of the job class.
     *
     * This helper method is getting to be a bit overloaded; it nowadays
     * - can keep cached jobs
     * - can get a job with specified (extra) settings, which are not cached
     * - uses jobUsesProfileCache() to determine whether jobs use profile
     *   caches. This means that cache settings must not be set in the standard
     *   configurations.
     *
     * @param string|false $type
     *   (Optional) The 'job type', which is used as an ID for selecting a standard
     *   settings configuration. If '', gets the same type as previously. If
     *   FALSE, the cache gets reset and nothing gets returned.
     * @param array $more_settings
     *   Extra settings to pass to the job. If an empty array, the job instance
     *   gets cached.
     * @param TestApi $api
     *   Api class. Sometimes used.
     *
     * @return CopernicaUpdateProfilesJob|null
     */
    protected function getJob($type = '', $more_settings = [], $api = null)
    {
        static $instance;
        static $current_job_type;

        $types_settings = [
            'id' => [
                // The only predefined setting is a key field.
                'copernica_profile_key_field' => 'ID',
            ],
            'action_log' => [
                // This logs more:
                'log_individual_updates' => true,
                'copernica_profile_key_field' => 'email',
                // Field mapping for the main profile - i.e. the items have the
                // non-mapped keys (not the Copernica fieldnames) for the main
                // profile, but not for the subprofile.
                'copernica_profile_fields' => [
                    'main_id' => 'MainId',
                    'email' => 'Email',
                    'first_name' => 'Firstname',
                    'last_name' => 'Lastname',
                ],
                'copernica_collections' => [
                    self::COLLECTION_ID => ['name' => 'TestCollection'],
                ],
            ],
            'magento_import' => [
              'copernica_profile_key_field' => ['MagentoCustomerId', 'Email'],
              'copernica_profile_key_field_serial' => $this->jobUsesProfileCache('serial'),
              'copernica_collections' => [
                    self::COLLECTION_ID => ['name' => 'Orders', 'key_field' => 'OrderId', 'key_field_serial' => $this->jobUsesProfileCache('serial'), 'cache' => $this->jobUsesProfileCache('sub')],
                    self::COLLECTION2_ID => ['name' => 'OrderItems', 'key_field' => 'ItemId', 'key_field_serial' => $this->jobUsesProfileCache('serial'), 'cache' => $this->jobUsesProfileCache('sub')],
                ],
                // Key field change is allowed because
                // - We allow a profile's Magento Customer ID to change from 'empty' to
                //   'a value', thereby taking over existing customer data sets without
                //   Magento ID. (Which is regarded to be a change just like any other;
                //   I'm not seeing a reason to change that yet.)
                // - This also implies that a change of Magento Customer ID is not seen
                //   as an error/warning (if it changes in Magento for a specific order
                //   that we're updating). That's next to impossible, so I don't care.
                // - Also, Magento customers are allowed to change e-mail address. I
                //   don't know if that's possible in practice for customers without a
                //   Magento Customer ID... for customers with an ID, a change of
                //   e-mail address is always allowed / does not cause a log.
                'copernica_profile_key_field_change_allowed' => WATCHDOG_NOTICE,
                // Caching by ID is good for updating existing orders (which we do because
                // we're following the status changes). Default is TRUE.
                // Caching by key is good for inserting new orders for an existing
                // customer. We don't know how often that happens; probably not significant.
                'cache_profiles_by_id' => $this->jobUsesProfileCache('id'),
            ],
        ];
        // See comments at top of doTestMagentoImport(): random variation. Do
        // only if we'll actually use $types_settings for initializations (not
        // every time we get $instance), because we'll log.
        if (
            ($type === 'magento_import' && ($current_job_type !== 'magento_import' || $more_settings)
             || $type === '' && $current_job_type === 'magento_import' && $more_settings)
            && rand(0, 1)
        ) {
            $types_settings['magento_import']['copernica_field_settings'] = [
                'MagentoCustomerId' => ['type' => 'integer'],
                self::COLLECTION_ID . ':OrderId' => ['type' => 'integer'],
                self::COLLECTION2_ID . ':OrderId' => ['type' => 'integer'],
                self::COLLECTION2_ID . ':ItemId' => ['type' => 'integer'],
            ];
            __drunkins_log('copernica_update_test', "Job settings have 'integer' ID fields.", [], WATCHDOG_DEBUG, []);
        }

        // Are we creating a job class for a new process or recreating one for
        // a running process? Guess.
        $new_process = false;
        if ($type !== '') {
            if (!isset($types_settings) && $type !== false) {
                throw new InvalidArgumentException('Unknown job settings type.');
            }
            if ($type !== $current_job_type) {
                $instance = null;
                if ($type === false) {
                    // Resetting curent type is necessary so we'll get to
                    // setting $new_process = true next time.
                    $current_job_type = '';
                    return null;
                }
                $current_job_type = $type;
                $new_process = true;
            }
        }

        // Once every ~6 times randomly, reinstantiate the job, to prove that
        // there is no difference in reusing vs. using new job classes. Only
        // some tests' repeated getJob() calls invoke this, but that's enough.
        if ($more_settings || !isset($instance) || rand(0, 6) == 0) {
            if ($type === '') {
                $type = $current_job_type;
            }
            // Copied real job settings from my code.
            $more = $more_settings;
            unset($more['copernica_field_settings']);
            $settings = $more + $types_settings[$type] + [
                'copernica_database_id' => self::DATABASE_ID,
                // I think we want to deprecate this but it may still be
                // necessary. (drunkins_get_job() always adds it.)
                'job_id' => 'copernica_update_test',
                'dependencies' => [
                    'copernica_client' => new TestRestClient($api)
                ],
                // The class doesn't use this (because RestClient is already
                // instantiated) but still checks its presence.
                'copernica_token' => 'testtoken',
                'cache_profiles_by_key' => $this->jobUsesProfileCache('key'),
            ];
            if (isset($more_settings['copernica_field_settings'])) {
                // Array_merge the field settings one by one.
                foreach ($more_settings['copernica_field_settings'] as $field => $s) {
                    $settings['copernica_field_settings'][$field] = $s + ($settings['copernica_field_settings'][$field] ?? []);
                }
            }

            if (
                !empty($settings['cache_profiles_by_id']) || !empty($settings['cache_profiles_by_key'])
                || !empty($settings['copernica_profile_key_field_serial'])
                || array_filter($settings, function ($v) {
                    return is_array($v) && array_filter($v, function ($x) {
                        return is_array($x) && !empty($x['cache']);
                    });
                })
                || array_filter($settings, function ($v) {
                    return is_array($v) && array_filter($v, function ($x) {
                        return is_array($x) && !empty($x['key_field_serial']);
                    });
                })
            ) {
                // It's not super easy to see when this is the first time a job
                // is initialized (i.e. when we should create the table). By
                // now we're likely just depending on the constructor ignoring
                // any errors caused by recreating the table.
                $settings['dependencies']['profile_cache_manager'] = new SqliteKeyValueStoreManager(
                    $api->getPdoConnection(),
                    $new_process
                );
            }

            $return = $this->getJobWithAccessibleMethods($settings);
            if (!$more_settings) {
                $instance = $return;
            }
        } else {
            $return = $instance;
        }

        return $return;
    }

    /**
     * Indicates whether the job uses a certain local (sub)profile cache
     *
     * @param string $key
     *   'key', 'id' or 'sub'.
     *
     * @return array|bool
     *   Array with all keys if $key is null, otherwise boolean.
     */
    protected function jobUsesProfileCache($key = null)
    {
        if (isset($key)) {
            return $this->jobUsesProfileCache[$key] ?? false;
        }
        return $this->jobUsesProfileCache;
    }

    /**
     * Changes whether the return value of getJob([]) uses a profile cache.
     */
    protected function setJobUsesProfileCache(array $use_cache)
    {
        $this->jobUsesProfileCache = $use_cache;
        // Reset job, to prevent bugs.
        $this->getJob(false);
    }

    /**
     * Returns a key-value store that would have been used by a Test API.
     *
     * This assumes that whatever key-value store manager was used for creating
     * the key-value store, has done this inside the API's PDO connection.
     *
     * @param TestApi $api
     *   The API containing the PDO connection.
     * @param string|int $collection_id
     *   The ID of the collection containing the subprofiles; used as a
     *   substring for the 'collection' field in the key-value store. We use 0
     *   to query/store main profiles by their 'key field' value and '-' to
     *   query/store main profiles by their ID.
     *
     * @return PdoKeyValueStore
     */
    protected function getProfileCache($api, $collection_id = 0)
    {
        // We create a new manager to create a new store. No problem, as long
        // as we have the back end (the PDO connection) to use.
        return (new SqliteKeyValueStoreManager($api->getPdoConnection(), false))
            ->get(self::DATABASE_ID . ".$collection_id");
    }
}

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
    function __drunkins_log($job_id, $message, array $variables, $severity, array $settings, $repeat = NULL)
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
     * Tests getItemKeyFieldValue(). (One of few real unit testable methods.)
     */
    public function testGetItemKeyFieldValue()
    {
        // Job with single key_field setting.
        $job = $this->getJobWithGetItemKeyFieldValueTest(['copernica_profile_key_field' => 'email', 'key_field_validate_email' => true]);
        $item = ['email' => 'rm@wyz.biz'];
        $value = $job->getItemKeyFieldValueTest($item);
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
        $job = $this->getJobWithGetItemKeyFieldValueTest(['copernica_profile_key_field' => 'email']);
        $value = $job->getItemKeyFieldValueTest($item, true, 0);
        $this->assertSame('invalid@', $value);
        // Test empty e-mail
        $item = ['email' => ''];
        $this->assertExceptionForGetItemKeyFieldValue($job, $item, true, 'until-nonempty', UnexpectedValueException::class, "Item does not contain a value for 'email' field(s).");
        $value = $job->getItemKeyFieldValueTest($item, false);
        $this->assertSame(['email' => null], $value);
        $this->assertExceptionForGetItemKeyFieldValue($job, $item, true, 'all', UnexpectedValueException::class, "Item does not contain a value for 'email' field(s).");
        $value = $job->getItemKeyFieldValueTest($item, false, 'all');
        $this->assertSame(['email' => null], $value);
        $value = $job->getItemKeyFieldValueTest($item, true, 'loggable');
        $this->assertSame('<empty>', $value);
        $value = $job->getItemKeyFieldValueTest($item, true, 0);
        $this->assertSame(null, $value);

        // Job with key_field being a single-element array: makes no difference.
        $job = $this->getJobWithGetItemKeyFieldValueTest(['copernica_profile_key_field' => ['email'], 'key_field_validate_email' => true]);
        $item = ['email' => 'rm@wyz.biz'];
        $value = $job->getItemKeyFieldValueTest($item);
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
        $job = $this->getJobWithGetItemKeyFieldValueTest(['copernica_profile_key_field' => ['email']]);
        $value = $job->getItemKeyFieldValueTest($item, true, 0);
        $this->assertSame('invalid@', $value);
        $item = ['email' => ''];
        $this->assertExceptionForGetItemKeyFieldValue($job, $item, true, 'until-nonempty', UnexpectedValueException::class, "Item does not contain a value for 'email' field(s).");
        $value = $job->getItemKeyFieldValueTest($item, false);
        $this->assertSame(['email' => null], $value);
        $this->assertExceptionForGetItemKeyFieldValue($job, $item, true, 'all', UnexpectedValueException::class, "Item does not contain a value for 'email' field(s).");
        $value = $job->getItemKeyFieldValueTest($item, false, 'all');
        $this->assertSame(['email' => null], $value);
        $value = $job->getItemKeyFieldValueTest($item, true, 'loggable');
        $this->assertSame('<empty>', $value);
        $value = $job->getItemKeyFieldValueTest($item, true, 0);
        $this->assertSame(null, $value);

        // Job with key_field being two fields.
        $job = $this->getJobWithGetItemKeyFieldValueTest([
          'copernica_profile_key_field' => ['OptionalId', 'email'],
          'copernica_profile_fields' => ['OptionalId' => 'OptionalIdInCopernica'],
          'copernica_field_types' => ['OptionalIdInCopernica' => 'positive_int']
        ]);
        // If only the first key is present:
        $item = ['OptionalId' => 5];
        $value = $job->getItemKeyFieldValueTest($item);
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
        $value = $job->getItemKeyFieldValueTest($item, true, 'all');
        // Invalid e-mail does not get checked if e-mail is not the first field.
        // (Whether or not that makes sense... is for later.)
        $this->assertSame(['OptionalId' => 5, 'email' => 'invalid@'], $value);
        $value = $job->getItemKeyFieldValueTest($item, true, 'loggable');
        $this->assertSame('5', $value);
        $value = $job->getItemKeyFieldValueTest($item, true, 0);
        $this->assertSame(5, $value);
        $value = $job->getItemKeyFieldValueTest($item, true, 1);
        $this->assertSame('invalid@', $value);
        $this->assertExceptionForGetItemKeyFieldValue($job, $item, false, 2, LogicException::class, "Key 2 not found in 'copernica_profile_key_field' setting.");

        // Only non-first key is present (0 does not count for positive_int;
        // this also does some implicit non-exhaustive testing for
        // isFieldValueEmpty() / getCopernicaProfileFieldName() /
        // getCopernicaFieldType(), which have no own unit tests):
        $item = ['OptionalId' => 0, 'email' => 'rm@wyz.biz'];
        $value = $job->getItemKeyFieldValueTest($item);
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

        // No keys present at all.
        $item = ['OptionalId' => 0, 'email' => ''];
        $this->assertExceptionForGetItemKeyFieldValue($job, $item, true, 'until-nonempty', UnexpectedValueException::class, "Item does not contain a value for 'OptionalId/email' field(s).");
        $value = $job->getItemKeyFieldValueTest($item, false);
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
     * Returns job that makes getItemKeyFieldValue() callable (in a way).
     *
     * @return \CopernicaUpdateProfilesJob
     *   Class with public getItemKeyFieldValueTest() method.
     */
    protected function getJobWithGetItemKeyFieldValueTest($settings)
    {
        return new class ($settings) extends CopernicaUpdateProfilesJob {
            public function getItemKeyFieldValueTest(array $item, $validate = true, $sub = 'until-nonempty')
            {
                return $this->getItemKeyFieldValue($item, $validate, $sub);
            }
        };
    }

    /**
     * Tests getMainProfilesByKey().
     *
     * Not a unit test; exercises code by processing items. If we wanted to
     * test this method 'as standalone as possible', we'd need to do a boatload
     * of setup of settings / API backend contents, which is all way easier
     * (and more logical to read) by just stuffing items into processItem().
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

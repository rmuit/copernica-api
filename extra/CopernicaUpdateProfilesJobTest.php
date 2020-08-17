<?php

/**
 * @file
 * PHPUnit test class to test a synchronization process.
 *
 * This is provided as an example, for the moment. I'm running this
 * occasionally on an application's test environment, manually, which is why
 * I left all the ugly initialization code in. The synchronization process is
 * tightly coupled to a Drupal 7 site, which explains all that code.
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
        // Not exactly sure yet what is the best way of making logs stand out
        // in between PHPUnit output but not mess up other things.
        print '[LOG] >> ' . t($message, $variables) . "\n";
        // fwrite(STDERR, t($message, $variables) . "\n");
    }
}

// This is the structure of my Drupal 7 site (because noone else will probably
// run these tests anyway). Include all dependencies of the Drunkins job.
require_once(__DIR__ . '/../../../../modules/contrib/drunkins/classes/fetcher.inc');
require_once(__DIR__ . '/../../../../modules/contrib/drunkins/classes/job.inc');
require_once(__DIR__ . '/../../../../modules/custom/ygsync/CopernicaUpdateProfilesJob.php');
require_once(__DIR__ . '/../../../../modules/custom/ygsync/CopernicaSubprofileContainer.php');

// phpcs:enable

/**
 * Test case for testing CopernicaUpdateProfilesJob.
 *
 * This is supposed to test the logic encoded in the processItem() part because
 * start() and finish() don't really contain testable code. Tests have to be
 * exercised against an API 'backend', for which we've hardcoded the TestApi
 * class.
 *
 * This test implicitly depends on the profile functionality from Copernica
 * being fully known / specified / tested in copernica-api's ApiBehavorTest so
 * we have a stable base to build on.
 *
 * There are a ton of tests that haven't been implemented yet, for lack of
 * budget. Just one simple synchronization that inserts items containing of
 * one profile with one subprofile (and never updates subprofiles) are tested
 * so far.
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
        $this->assertEquals(
            ["POST database/$database_id/profiles", "POST profile/$profile_id/subprofiles/$collection_id"],
            $api->getApiUpdateLog()
        );

        // (With the job settings as they are,) if we insert data with the same
        // email, a new subprofile gets created, but not a new profile. The
        // profile PUT (update) should not be called at all if no fields are
        // changing. Empty fields do not cause changes.
        // @TODO test "Empty fields do not cause changes.".
        $api->resetApiUpdateLog();
        $item['last_name'] = '';
        $this->getJob()->processItem($item, $job_context);
        $this->assertEquals(
            ["POST profile/$profile_id/subprofiles/$collection_id"],
            $api->getApiUpdateLog()
        );

        // Profile field overwriting (except with empty value as above) is not
        // prohibited. Not-set fields are not emptied out either. Sleep 1 to be
        // able to check modified time (though that's a check on TestApi, not
        // on the update job).
        // @TODO check from "not-set fields" down. (I shouldn't have gotten rid of the db queries?)
        $api->resetApiUpdateLog();
        $item['first_name'] = 'Piet';
        unset($item['last_name']);
        $this->getJob()->processItem($item, $job_context);
        $this->assertEquals(
            ["PUT profile/$profile_id/fields", "POST profile/$profile_id/subprofiles/$collection_id"],
            $api->getApiUpdateLog()
        );


        //@todo with cache?
        // @todo test inserting subprofile for deleted profile. Before that, we need to
        //  implement deleting profile in TestApi.
    }

    /**
     * Returns an instance of the job class.
     *
     * @return \CopernicaUpdateProfilesJob
     */
    protected function getJob()
    {
        static $instance;

        // Once every ~6 times randomly, reinstantiate the job, to prove that
        // there is no difference in reusing vs. using new job classes.
        if (!isset($instance) || rand(0, 6) == 0) {
            // Copied real job settings from my code.
            $settings = [
                'copernica_database_id' => self::DATABASE_ID,
                'deduplicate' => WATCHDOG_ERROR,
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

            $instance = new CopernicaUpdateProfilesJob($settings);
        }

        return $instance;
    }
}

<?php

namespace CopernicaApi\Tests;

use DateTime;
use DateTimeZone;
use LogicException;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for API behavior.
 *
 * The intended purpose(s) of this test class is:
 * - to form a baseline for functional tests / tests of code which uses this
 *   library; that code can use this test as a 'specification' of how the API
 *   works and not worry about reimplementing tests for these details.
 * - as a result: to be a somewhat readable specification of fussy details, if
 *   we forget how they work.
 * - to become and stay runnable against both TestApi and CopernicaRestAPI + a
 *   'live' API backend; this can be a test that live API behavior does not
 *   change over time (thereby supporting the previous point). It's not
 *   intended to run against a live database often, but it is intended to be
 *   possible.
 *
 * Conceptually, it would make sense to have a set specification of detailed
 * API behavior which was the canonical source of truth - which this test would
 * implement to check if TestApi is behaving correctly. However... we don't
 * control the real API and don't know its exact specs, and in principle cannot
 * trust it to never change behavior. In practice, we can just hope / assume
 * that we implemented these tests correctly at some point in time, so TestApi
 * behaved correctly - and we can use them as a basis to incidentally check
 * whether the API is still behaving the same as it used to. So essentially,
 * this test class is the 'most canonical' source of truth re. API behavior
 * now, which we use as a specification. (But which might change over time.)
 *
 * Code wise, it might be cleanest if this didn't use CopernicaRestClient
 * but just ran directly against CopernicaRestAPI / TestApi. However there is
 * no problem with using CopernicaRestClient in places it is more convenient
 * because:
 * - This class conceptually 'depends on' TestApiTest & CopernicaRestClientTest
 *   and we should be able to treat CopernicaRestClient as working flawlessly.
 *   (Nothing officially encodes this dependency yet...)
 * - The likely future of the code is for logic to move from CopernicaRestAPI
 *   into CopernicaRestClient anyway (thereby simplifying e.g. all that error
 *   handling which is semi-arbitrarily separated into two classes now).
 *
 * We can mostly test with the API class' $throwOnError == True because
 * it yields more precise results than False. (We have one base test with False
 * which should be enough because we never use that for serious code.) In cases
 * where handling exceptions is more tedious than we like, we could either set
 * throwOnError=false or use CopernicaRestClient to handle the exceptions. The
 * latter is supposedly more future proof.
 *
 * @todo
 *   - The "intended purpose" means we should not initialize a database
 *     structure through the TestAPI constructor but need to create a new
 *     database and collections and fields using API calls. (Which means
 *     TestApi first needs to support all those, before this test class can
 *     fulfill its full purpose.)
 *   - This should exercise as much logic as possible and serve as some form of
 *     documentation on the exact behavior of Copernica, like
 *     - how exactly is the '/databases' response/output influenced by setting
 *       field defaults and other properties?
 *     - which inputs exactly are illegal for various types of calls and
 *       database fields?
 *     - what is the exact behavior re. default values for e.g. profile fields?
 *
 * Scratch space for tests to implement if we want to be complete:
 * - test autoincrement: delete highest numbered (sub)profile and then
 *   reinsert; check if the ID is not reused (if it isn't in Copernica). This
 *   baseline test should help ensure that other tests behave as expected.
 */
class ApiBehaviorTest extends TestCase
{
    /**
     * We'll need to make an assumption about Copernica's timezone.
     *
     * We have a getter/setter for TestApi but can't do that for the live API.
     */
    const LIVE_API_TIMEZONE = 'Europe/Amsterdam';

    /**
     * Tests error handling when the API class does not have throwOnError set.
     *
     * At the same time, this tests the behavior consistency for "invalid
     * token" errors. This is special in the sense that our API classes need to
     * be set up differently in order to have this particular error returned.
     * But as far as the live API is concerned, this is an error like any
     * other; it's returned with a HTTP 400 in the standard error JSON body.
     */
    public function testErrorsWithoutThrow()
    {
        // Don't waste time doing SQL commands for table creations etc; that's
        // not what this test is for. So pass a bogus PDO connection.
        $pdo = new PDO('sqlite::memory:');
        $api = new TestApi([], $pdo);
        // Special initialization:
        $api->invalidToken = true;

        $this->assertFalse($api->post('databases'));
        $this->assertFalse($api->post('interest'));
        $this->assertFalse($api->post('interest'));
        $this->assertEquals(['error' => ['message' => 'Invalid access token']], $api->get('databases'));
    }

    /**
     * Tests that API throws exceptions in specified cases.
     *
     * @dataProvider provideBasicExceptionsData
     */
    public function testApiExceptions($class_method, $url, $error_code, $expected_message, $api, $send_data = [])
    {
        $this->expectExceptionMessage($expected_message);
        $this->expectExceptionCode($error_code);
        if (in_array($class_method, ['post', 'put'], true)) {
            $api->$class_method($url, $send_data);
        } else {
            if ($send_data) {
                throw new LogicException("\$send_data cannot be nonemppty for $class_method().");
            }
            $api->$class_method($url);
        }
    }

    /**
     * Provides / documents the cases in which calls result in errors.
     *
     * @return array[]
     */
    public function provideBasicExceptionsData()
    {
        // Instantiate API class once; use for all tests. It's potentially
        // tricky but a lot less expensive.
        $api = $this->getTestApiWithProfileStructure();
        $structure = $api->getDatabasesStructure();
        $database_id = $api->getMemberId('Test');
        $collection_id = $api->getMemberId('Test', $structure[$database_id]['collections']);
        $profile_id = $api->post("database/$database_id/profiles", ['fields' => ['LastName' => 'Muit']]);
        $subprofile_id = $api->post("profile/$profile_id/subprofiles/$collection_id", ['Score' => 6]);
        $nonexistent_id = $this->nonexistentId();

        // Exception messages have a wrapper 'METHOD RESOURCE returned HTTP
        // code 400. Response contents: "HEADERS JSON-in-body"', because that's
        // what CopernicaRestAPI throws for the benefit of CopernicaRestClient.
        // We're not really interested in testing the whole structure; just
        // test for the error message including quotes (which is an indication
        // it's JSON encoded).

        // Basic general errors 0: paths are case sensitive. We only test one.
        $data[] = ['get', "PROFILE/$profile_id", 400, '"Invalid method"', $api];

        // Basic general errors 1: Known paths with too few components return
        // "missing element" error. And adding extra slashes changes nothing.
        foreach (
            [
                ['get', 'database'], ['post', 'database'], ['put', 'database'], ['delete', 'database'],
                ['get', 'collection'], ['post', 'collection'], ['put', 'collection'], ['delete', 'collection'],
                // minicondition is "invalid method" (does not exist) for GET.
                ['post', 'minicondition'], ['put', 'minicondition'], ['delete', 'minicondition'],
                ['get', 'profile'], ['post', 'profile'], ['put', 'profile'], ['delete', 'profile'],
                // Note this is not documented in an ideal way in Copernica's
                // list of calls: you can't get all subprofiles for a profile.
                // Only for a profile+collection (and for a collection).
                // profile/ID/subprofiles/ID is "invalid method" for DELETE.
                ['get', "profile/$profile_id/subprofiles"], ['post', "profile/$profile_id/subprofiles"], ['put', "profile/$profile_id/subprofiles"],
                ['get', 'subprofile'], ['post', 'subprofile'], ['put', 'subprofile'], ['delete', 'subprofile'],
                // database/ID/field/ID (unlike profile/ID/subprofiles/ID) has
                // its dedicated error message.
                // @todo implement... here or elsewhere.
            ] as $method_base
        ) {
            // Usually, element 1 is missing.
            $missing_element = substr_count($method_base[1], '/') + 1;
            foreach ([$method_base[1], "$method_base[1]/", "$method_base[1]//"] as $path) {
                $data[] = [$method_base[0], $path, 400, "\"Invalid request, element $missing_element is missing in 'directory' path\"", $api];
            }
        }

        // Basic general errors 2: Known paths whose second component is an
        // entity ID always check its validity before any other error, and give
        // the same message for any kind of validity (emptiness, non-numeric,
        // etc). We're being obsessive, so we'll want to check specific
        // per-request valid sub-paths to see if that's the case - as well as
        // try a valid ID with a double slash.
        $error_messages = [
            'database' => 'No database with supplied ID',
            'collection' => 'No collection with given ID',
            'profile' => 'No entity with supplied ID',
            'subprofile' => 'No subprofile with supplied ID',
        ];
        foreach (
            [
                ['get', 'database', "/$database_id", 'collections', 'fields', 'interests', 'profileids', 'profiles', 'views', 'unsubcribe'],
                ['post', 'database', "/$database_id", 'collections', 'copy', 'field', 'fields', 'intentions', 'interests', 'profiles', 'views', 'unsubcribe'],
                ['put', 'database', "/$database_id", 'collections', 'field', 'fields', 'intentions', 'interests', 'profiles', 'views', 'unsubcribe'],
//                ['delete', 'database', "/$database_id", 'collections', 'field', 'fields', 'intentions', 'interests', 'profiles', 'views', 'unsubcribe'],
                ['get', 'collection', "/$collection_id", 'fields', 'miniviews', 'subprofileids', 'subprofiles', 'unsubcribe'],
                ['post', 'collection', "/$collection_id", 'field', 'fields', 'intentions', 'miniviews', 'unsubcribe'],
                ['put', 'collection', "/$collection_id", 'field', 'fields', 'intentions', 'miniviews', 'unsubcribe'],
//                ['delete', 'collection', "/$collection_id", 'field', 'fields', 'intentions', 'miniviews', 'unsubcribe'],
                ['get', 'profile', "/$profile_id", 'datarequest', 'fields', 'files', 'interests', 'ms/destinations', 'ms/emailings', 'publisher/destinations', 'publisher/emailings', 'subprofiles'],
                ['post', 'profile', "/$profile_id", 'datarequest', 'fields', 'interests', 'subprofiles'],
                ['put', 'profile', "/$profile_id", 'datarequest', 'fields', 'interests', 'subprofiles'],
//                ['delete', 'profile', "/$profile_id", 'datarequest', 'fields', 'interests', 'subprofiles'],
                ['get', 'subprofile', "/$subprofile_id", 'datarequest', 'fields', 'ms/destinations', 'ms/emailings', 'publisher/destinations', 'publisher/emailings'],
                ['post', 'subprofile', "/$subprofile_id", 'datarequest', 'fields'],
                ['put', 'subprofile', "/$subprofile_id", 'datarequest', 'fields'],
//                ['delete', 'subprofile', "/$subprofile_id", 'datarequest', 'fields'],
                // database/ID/field/INVALID & profile/ID/subprofiles/INVALID
                // have differing messages/behavior; we check them elsewhere.
                // @todo implement database/ID/field/INVALID elsewhere.
            ] as $arg
        ) {
            $method = array_shift($arg);
            $uri_base = array_shift($arg);
            // Just an extra (few) slash(es) gives no error; only if something
            // comes after.
            $path_suffixes = [$nonexistent_id, -1, 'bogus/path', '/bogus'];
            foreach ($arg as $extra_suffix) {
                // Try with invalid ID + known-suffix, and //known-suffix. And,
                // heck, let's try the known suffix in the wrong place too.
                $path_suffixes[] = "0/$extra_suffix";
                $path_suffixes[] = "/$extra_suffix";
                $path_suffixes[] = $extra_suffix;
            }
            foreach ($path_suffixes as $path_suffix) {
                $data[] = [$method, "$uri_base/$path_suffix", 400, '"' . $error_messages[$uri_base] . '"', $api];
            }
        }

        // Basic general errors 3: if the entity ID is known and the path
        // contains extra components after the ID which do not lead to a known
        // separate endpoint, the API usually returns "Invalid method". We
        // want to check all suffixes that look like they might be valid
        // (because e.g. other methods support them), to specify/document that
        // they are not.
        foreach (
            [
                // field/X = PUT/DELETE, intentions = PUT. Note parts of a
                // database (like field/X and intentions) don't have their own
                // GET; data apparently only gets returned with /database/X.
                ['get', "database/$database_id", $database_id, 'copy', 'field/1', 'intentions'],
                // 'profileids' = GET. The others are bogus.
                ['post', "database/$database_id", $database_id, 'profileids', 'profile', 'subprofiles'],
                ['put', "database/$database_id", $database_id, 'profileids', 'profile', 'subprofiles'],
                // fields/views = GET/POST, interests = GET/POST,
                // profiles = GET/POST/PUT, unsubscribe = GET/PUT
//                ['delete', "database/$database_id", $database_id, 'copy', 'fields', 'interests', 'profileids', 'profiles', 'unsubscribe', 'views'],

                // field/X = PUT/DELETE, intentions = PUT
                ['get', "collection/$collection_id", $collection_id, 'field', 'intentions'],
                // 'subprofile(id)s' = GET.
                ['post', "collection/$collection_id", $collection_id, 'subprofileids', 'subprofiles'],
                ['put', "collection/$collection_id", $collection_id, 'subprofileids', 'subprofiles'],
                // fields/miniviews = GET/POST, unsubscribe = GET/PUT
//                ['delete', "collection/$collection_id", $collection_id, 'fields', 'intentions', 'miniviews', 'subprofileids', 'subprofiles', 'unsubscribe'],

                // datarequest = POST. subprofile = nothing.
                ['get', "profile/$profile_id", $profile_id, 'datarequest', 'subprofile'],
                // files/ms/publisher = GET
                ['post', "profile/$profile_id", $profile_id, 'files', 'ms', 'publisher'],
                ['put', "profile/$profile_id", $profile_id, 'files', 'ms', 'publisher'],
                // interests/subprofiles = GET/POST/PUT, fields = GET/PUT
//                ['delete', "profile/$profile_id", $profile_id, 'datarequest', 'fields', 'files', 'interests', 'ms', 'publisher', 'subprofiles'],

                // datarequest = POST
                ['get', "subprofile/$subprofile_id", $subprofile_id, 'datarequest'],
                // ms/publisher = GET
                ['post', "subprofile/$subprofile_id", $subprofile_id, 'ms', 'publisher'],
                ['put', "subprofile/$subprofile_id", $subprofile_id, 'ms', 'publisher'],
                // fields = GET/PUT
//                ['delete', "subprofile/$subprofile_id", $subprofile_id, 'datarequest', 'fields', 'ms', 'publisher'],
            ] as $arg
        ) {
            $method = array_shift($arg);
            $uri_base = array_shift($arg);
            // Add basic suffixes to try everywhere, to the suffixes from above.
            $path_suffixes = array_merge([0, '/0'], $arg);
            foreach ($path_suffixes as $path_suffix) {
                $data[] = [$method, "$uri_base/$path_suffix", 400, '"Invalid method"', $api];
            }
        }

        // GET database/X/profiles has no error paths to check. (Superfluous
        // path components and query parameters are ignored -> checked
        // elsewhere. Superfluous path components for POST too.)
        // POST and PUT to correct /database/X/profiles path with incorrect
        // data fails. PUT has a different application than POST (i.e. updating
        // multiple profiles at the same time) but its handling of data is the
        // same. Superfluous path components and query parameters are ignored
        // just like GET.
        $data[] = ['post', "database/$database_id/profiles", 400, '"Invalid data provided"', $api, ['fields' => true]];
        $data[] = ['post', "database/$database_id/profiles/bogus", 400, '"Invalid data provided"', $api, ['fields' => 0]];
        $data[] = ['post', "database/$database_id/profiles//$profile_id", 400, '"Invalid data provided"', $api, ['fields' => 'blah']];
        $data[] = ['put', "database/$database_id/profiles", 400, '"Invalid data provided"', $api, ['fields' => true]];
        $data[] = ['put', "database/$database_id/profiles/bogus", 400, '"Invalid data provided"', $api, ['fields' => 0]];
        $data[] = ['put', "database/$database_id/profiles//$profile_id", 400, '"Invalid data provided"', $api, ['fields' => 'blah']];
        // (DELETE /database/X/profiles is an invalid path, tested above.)

        // A note: PUT profile/PID does not have the same error as above; a
        // non-array 'fields' section is simply ignored. (And superfluous
        // path components have been tested above -> no errors to check here.)

        // GET profile/PID/subprofiles/CID: not implemented yet. <- @todo. (also all *profileids GET calls then?)
        // POST:
        // - Note it's not totally analogous to profile because the path is a
        //   subpath of /profile/PID, not of /collection/CID.)
        // - Superfluous path components are ignored -> checked elsewhere. <- @todo don't forget to check for GET/PUT.
        // - Strange collection ID tests are implemented here (not in "basic
        //   general errors 2") because the error message is different:
        // - Passing 'Incorrect data' is not possible like it is for creating
        //   profiles, because the passed data array only consists of fields.
        //   (There is no 'fields' sub-array).
        foreach ([$nonexistent_id, -1, 'bogus/path', '/bogus'] as $path_suffix) {
            $data[] = ['POST', "profile/$profile_id/subprofiles/$path_suffix", 400, '"Subprofile could not be created"', $api];
        }
        // PUT profile/PID/subprofiles/CID: not implemented yet.
        //   <- NOTE ITS DIFFERENCE. It may or may not need CID, it's not documented correctly at https://www.copernica.com/en/documentation/restv2/rest-put-profile-subprofiles
        // @todo ^
        //   - Don't forget testing parameters (which POST does not have)
        //   - Note that PUT with strange/missing IDs is not implemented yet
        // (DELETE profile/PID/subprofiles is an invalid path, tested above.)

        return $data;
    }

    /**
     * Tests/specifies POST paths that don't actually do anything.
     *
     * It kind-of feels like all POST paths which aren't really implemented but
     * have a PUT equivalent, return true instead of an "invalid method" error.
     * (Not just a GET equivalent like database/X/profileids. Those return
     * "invalid method".)
     *
     * We haven't implemented the test for all of them yet, because... we'd
     * first need to support them in TestApi, which is just grunt work. The
     * commented-out versions still serve as some kind of spec...
     *
     * @todo currently only POST requests with empty-array data work in TestApi.
     *   It may be that all these requests actually do stuff if we pass data -
     *   we haven't tested it all yet... but we tested profile/ID and it does!
     *   So, we should test all the other ones - and write real tests for them
     *   (likely in another test next to all the PUT requests?) if they do.
     * @todo so this means that PUT request with empty bodies are equivalent
     *   to POST with empty bodies, right? Do we even need this test, then?
     *   Do we not just test empty PUT+POST in other tests, as some form of spec?
     */
    public function testInconsequentialPost()
    {
        $api = $this->getTestApiWithProfileStructure();

        $structure = $api->getDatabasesStructure();
        $database_id = $api->getMemberId('Test');
        $collection_id = $api->getMemberId('Test', $structure[$database_id]['collections']);

        // When we post to an unknown database ID it says "unknown"; see
        // testApiExceptions(). When we post anything to a known database ID
        // without extra path... it returns true from the API class' post()
        // i.e. the API returns a 2xx response without a 'X-Created' header.
        $result = $api->post("database/$database_id");
        $this->assertSame(true, $result);
        // Same for a collection.
        $result = $api->post("collection/$collection_id");
        $this->assertSame(true, $result);

        // Not implemented in TestApi yet so we can't enable these until then:
        // (If we do, we likely need to also test with superfluous path parts,
        // because those also seem to work in most cases - if they don't have
        // any subpaths pointing to other actual functionality.)
        // "collection/$collection_id/field/$field_id" // known fields only
        // "collection/$collection_id/intentions"
        // "collection/$collection_id/unsubscribe"
        // "database/$database_id/field/$field_id" // known fields only
        // "database/$database_id/intentions"
        // "database/$database_id/unsubscribe"
        // "profile/$profile_id/fields"
        // "subprofile/$subprofile_id/fields"
    }

    /**
     * Tests/specifies all kinds of (sub)profile CRUD behavior.
     */
    public function testProfileCrud()
    {
        $api = $this->getTestApiWithProfileStructure();

        $structure = $api->getDatabasesStructure();
        $database_id = $api->getMemberId('Test');
        $collection_id = $api->getMemberId('Test', $structure[$database_id]['collections']);
        $now = time();

        // POST profile:
        // - Path components passed after 'profiles' are ignored.
        // - Profile can be inserted with nonexistent fields, nonexistent
        //   properties; there's no error; they're just ignored.
        // - 'secret' is also ignored. (It can only be updated with PUT.)
        // - Field names are treated case insensitively. The 'fields' key
        //   containing the fields is not. A non-array 'fields' value causes an
        //   error (which is tested elsewhere).
        // - Field values for differently typed fields are converted as
        //   specified in TestApiTest::provideDataForNormalizeInputValue().
        //   @todo we should write post/get tests for that, and maybe put/get;
        //     their use will be clearer if/when we can also run this test
        //     against a live API. For now, the use of the little extra TestApi
        //     code coverage it would provide is unclear, and we didn't.
        // - Adding duplicate fields (differently cased) will have the later
        //   field being inserted. Also if the latter value is empty. <- TODO test this on PUT as well.
        // - Inserting the same data will result in a second profile.
        // - 'Empty' profile can be created.
        // @todo also still test defaults for all types.
        $profile = [
            'wonky' => 3,
            'Email' => 'rm@wyz.biz',
            'email' => '',
            'laSTNAme' => 'Muiii',
            'LaSTNAme' => 'Muit',
            'Birthdate' => '1974-04-27'
        ];
        $data = ['tonky' => ['nothing' => 'not'], 'secret' => 'secret!', 'fields' => $profile];
        $profile_id = $api->post("database/$database_id/profiles", $data);
        $profile2_id = $api->post("database/$database_id/profiles/$profile_id", $data);
        $profile3_id = $api->post("database/$database_id/profiles//bogus/");
        $profile4_id = $api->post("database/$database_id/profiles", ['FIELDS' => $profile]);
        // Get data, see if it gets returned in the expected format.
        $result_backup = $result = $api->get("database/$database_id/profiles");
        // Test that all secrets are different and adhere to a certain format.
        // Then unset because we can't compare them below.
        $previous_value = '';
        $allowed_deviation = 1;
        foreach ($result['data'] as $key => $value) {
            $this->assertInitialSecretFormat($value);
            // We don't crosscheck all 3, but this should be enough.
            if ($previous_value) {
                $this->assertNotEquals($previous_value, $value['secret']);
            }
            $previous_value = $value['secret'];

            $this->assertInDateRange($api, $value, 'created', $now, $allowed_deviation);
            $this->assertInDateRange($api, $value, 'modified', $now, $allowed_deviation);

            unset($result['data'][$key]['secret'], $result['data'][$key]['created'], $result['data'][$key]['modified']);
            $allowed_deviation++;
        }
        $expected = ['start' => 0, 'limit' => 100, 'count' => 4, 'data' => [
            [
                'ID' => (string)$profile_id,
                'fields' => [
                    'Email' => '',
                    'LastName' => 'Muit',
                    'Birthdate' => '1974-04-27',
                    'ANumber' => '-1',
                ],
                'interests' => [],
                'database' => (string)$database_id,
                'removed' => false,
            ],
            [
                'ID' => (string)$profile2_id,
                'fields' => [
                    'Email' => '',
                    'LastName' => 'Muit',
                    'Birthdate' => '1974-04-27',
                    'ANumber' => '-1',
                ],
                'interests' => [],
                'database' => (string)$database_id,
                'removed' => false,
            ],
            [
                'ID' => (string)$profile3_id,
                'fields' => [
                    'Email' => '',
                    'LastName' => '',
                    'Birthdate' => '',
                    'ANumber' => '-1',
                ],
                'interests' => [],
                'database' => (string)$database_id,
                'removed' => false,
            ],
            [
                'ID' => (string)$profile4_id,
                'fields' => [
                    'Email' => '',
                    'LastName' => '',
                    'Birthdate' => '',
                    'ANumber' => '-1',
                ],
                'interests' => [],
                'database' => (string)$database_id,
                'removed' => false,
            ],
        ], 'total' => 4];
        // assertSame() also reports false if the array items are in different
        // order. assertEquals() does not check type of values, which we want.
        // (Commented for now, because not yet necessary.)
        //array_multisort($result);
        //// I resisted alphabetizing above array...
        //array_multisort($expected);
        $this->assertSame($expected, $result);
        // More tests for get-multiple:
        // - The start/limit/fields parameter names must be lower cased
        // - Superfluous path components, unknown parameters and unknown
        //   fields in the 'fields' parameter are ignored.
        // - A non-array 'fields' parameter is ignored.
        // - Field names are matched case insensitively.
        // - Spaces are stripped from a ' field==value ' condition as a whole;
        //   spaces around the operator are not allowed (are seen as part of
        //   the field / value, and therefore ignored / incorrectly filtered).
        // - Multiple conditions for the same field are ANDed.
        // - 'total' vaiue is constrained by filter ('fields').
        // Testing everything combined - trying to test some odd 'total' values.
        // @todo still make test for limit <= 0, start.
        //   Maybe we should just make loops to go through some of these, so we
        //   still see what we're doing.
        // @todo still test orderby and more conditions, including multiple
        //   conditions forthe same field. We'll need more data for that.
        //   Likely first test defaults for all types, per above.
        //   (Or maybe after some PUTting including batch PUTting)
        $result = $api->get("database/$database_id/profiles/$profile_id", ['fields' => false, 2 => 4, 'bogus' => 345, 'total' => 'TRue']);
        $this->assertSame($result_backup, $result);
        $result = $api->get("database/$database_id/profiles/bogus/path", ['fields' => 'nothing', 'total' => []]);
        $this->assertSame($result_backup, $result);
        $result = $api->get("database/$database_id/profiles/$profile_id", ['FIELds' => ['LastName==Muit'], 'LIMIT' => 1, 'starT' => 2, 'TOTAL' => false, 'order' => 'descendin']);
        $this->assertSame($result_backup, $result);
        $result = $api->get("database/$database_id/profiles/$profile_id", ['fields' => ['LastName ==Muit'], 'order' => ['desc']]);
        $this->assertSame($result_backup, $result);
        $result = $api->get("database/$database_id/profiles/$profile_id", ['fields' => ['LastName== Muit'], 'limit' => true, 'total' => 'thisisfalse']);
        $this->assertSame(['start' => 0, 'limit' => 1, 'count' => 0, 'data' => []], $result);
        $result = $api->get("database/$database_id/profiles/$profile_id", ['fields' => [' LASTNAME==mUIT ', 'ANumber>=-1'], 'limit' => ['evaluate-to-one'], 'total' => 'trUE', 'order' => 'descendING']);
        // Filtering selects [0, 1]; order descending, limit 1 -> selects [1]
        $this->assertSame(
            ['start' => 0, 'limit' => 1, 'count' => 1, 'data' => [$result_backup['data'][1]], 'total' => 2],
            $result
        );

        // @TODO duplicate the above to subprofiles. (Also changes we've made to POST testing?)

        // Test the output of getting a single profile, see that it's exactly
        // equal. Compare to $profile_backup, not the 'compare' array above, so
        // we don't have to strip out the fields.
        $this->assertSame($result_backup['data'][0], $api->get("profile/$profile_id"));
        $this->assertSame($result_backup['data'][0]['fields'], $api->get("profile/$profile_id/fields"));
        $this->assertSame($result_backup['data'][1], $api->get("profile/$profile2_id"));
        $this->assertSame($result_backup['data'][1]['fields'], $api->get("profile/$profile2_id/fields"));
        $this->assertSame($result_backup['data'][2], $api->get("profile/$profile3_id"));
        $this->assertSame($result_backup['data'][2]['fields'], $api->get("profile/$profile3_id/fields"));
        // Requests for a single profile containing too many URL components
        // return errors (so are exercised elsewhere). 'fields' works fine.
        $this->assertSame($result_backup['data'][0]['fields'], $api->get("profile/$profile_id/fields/1"));
        $this->assertSame($result_backup['data'][0]['fields'], $api->get("profile/$profile_id/fields/bogus/path"));

        // PUT single profile:
        // - illegal (non-array) 'field' value is ignored (unlike POST/PUT
        //   database/ID/profiles, where it causes an error).
        // - 'modified' only gets increased if a field value actually changes.
        // - 'secret' can be updated - and won't update 'modified' date.
        // - Path components passed after 'profile/ID' cause error.
        // The rest is analogous to POST database/ID/profiles (above):
        // - Nonexistent fields, nonexistent properties cause no error.
        // - Fields with differently cased / duplicate names work like POST
        // (The concept of 'emptying out a field' doesn't really exist so we're
        // not testing that separately. Any value, including e.g. null for a
        // required field, gets converted to a specific value and that value
        // gets written.)
        // - (Test conversion of types... later, after we do that for POST.)
        $expected_profiles = $result_backup;
        unset($result_backup);
        $this->executePut($api, "profile/$profile3_id/", ['fields' => 'bogus', 'secret' => "s`~\"{}'üCE"]);
        $expected_profiles['data'][2]['secret'] = "s`~\"{}'?CE";
        // 'modified' did not change:
        $this->assertSame($expected_profiles['data'][2], $api->get("profile/$profile3_id"));
        //@TODO just discovered that 'modified' only gets updated with actual value
        //   updates; this is not the case yet with TestAPI.
        //   First going to test bulk-PUT before fixing that, because that can influence test DB structure

        // @TODO further test putProfile, putProfilefields,
        //   then test POST equivalents.
        // - secret: check that it's still the same after a (sub)profile update
        //   that does not update the secret


        // @TODO write a test to get back a single 'removed' profile. Test in
        //   the live API whether the 'secret' is emptied <- it is not
        // @TODO implement a test for: updating secret for deleted record also works.
        // @todo test: deleting (sub)profile does not change 'modified' date.



        // @TODO test putProfiles after implementing in TestApi. Tested on live:
        // - if 'fields' param is empty array or contains only unknown fields:
        //   update everyhing.
        // - if 'fields' contains a known field and a record matches: updates
        //   that (regarcless of 'create' praram), returns true.
        // - if 'fields' contains a known field and no record matches:
        //   - if 'create' param: creates new entity, returns location
        //   - if not: updates nothing, returns true.

        // @todo test when putProfiles updates the _modified date
        //   ^ <- also if there are no matching/real fields / if 'fields' is empty. <- I thought it did and I documented that, but I may be wrong. putProfile(fields)() does not.
        //   ^ <- for removed records too. <- tested to be true on LIVE; call returns true.
        // @todo test if putProfiles updates the _modified date <- for removed records too?
        // @TODO support for putProfiles create=1


        // Same insert/get tests for subprofile - except there's no empty
        // 'properties', only fields.
        $subprofile = [
            'Score' => 3,
            'score' => 6,
            'actIONTime' => '2020-04-27 14:15:34',
        ];
        $subprofile_id = $api->post("profile/$profile_id/subprofiles/$collection_id", ['wonky' => 'yes'] + $subprofile);
        $subprofile2_id = $api->post("profile/$profile_id/subprofiles/$collection_id", $subprofile);
        $subprofile3_id = $api->post("profile/$profile2_id/subprofiles/$collection_id/bogus/path");

        $result_backup = $result = $api->get("collection/$collection_id/subprofiles");
        foreach ($result['data'] as $key => $value) {
            $this->assertInitialSecretFormat($value);
            $this->assertNotEquals($previous_value, $value['secret']);
            $previous_value = $value['secret'];

            $this->assertInDateRange($api, $value, 'created', $now, $allowed_deviation);
            $this->assertInDateRange($api, $value, 'modified', $now, $allowed_deviation);

            unset($result['data'][$key]['secret'], $result['data'][$key]['created'], $result['data'][$key]['modified']);
            $allowed_deviation++;
        }

        $expected = ['start' => 0, 'count' => 3, 'total' => 3, 'limit' => 100, 'data' => [
            [
                'ID' => (string)$subprofile_id,
                'fields' => ['Score' => '6', 'ActionTime' => '2020-04-27 14:15:34'],
                'profile' => (string)$profile_id,
                'collection' => (string)$collection_id,
                'removed' => false,
            ],
            [
                'ID' => (string)$subprofile2_id,
                'fields' => ['Score' => '6', 'ActionTime' => '2020-04-27 14:15:34'],
                'profile' => (string)$profile_id,
                'collection' => (string)$collection_id,
                'removed' => false,
            ],
            [
                'ID' => (string)$subprofile3_id,
                'fields' => ['Score' => '-1', 'ActionTime' => ''],
                'profile' => (string)$profile2_id,
                'collection' => (string)$collection_id,
                'removed' => false,
            ],
        ]];
        array_multisort($result);
        array_multisort($expected);
        $this->assertSame($expected, $result);
        $this->assertSame($result_backup, $api->get("collection/$collection_id/subprofiles/$subprofile_id"));
        $this->assertSame($result_backup, $api->get("collection/$collection_id/subprofiles/bogus/path"));

        $this->assertSame($result_backup['data'][0], $api->get("subprofile/$subprofile_id"));
        $this->assertSame($result_backup['data'][0]['fields'], $api->get("subprofile/$subprofile_id/fields"));
        $this->assertSame($result_backup['data'][1], $api->get("subprofile/$subprofile2_id"));
        $this->assertSame($result_backup['data'][1]['fields'], $api->get("subprofile/$subprofile2_id/fields"));
        $this->assertSame($result_backup['data'][2], $api->get("subprofile/$subprofile3_id"));
        $this->assertSame($result_backup['data'][2]['fields'], $api->get("subprofile/$subprofile3_id/fields"));
        $this->assertSame($result_backup['data'][0]['fields'], $api->get("subprofile/$subprofile_id/fields/1"));
        $this->assertSame($result_backup['data'][0]['fields'], $api->get("subprofile/$subprofile_id/fields//bogus/path"));

        // @TODO test GET profile/PID/subprofiles/CID. Check if it has any
        //   parameters (unlike collection/subprofiles) because the docs suggest
        //   it does not.
    }

    /**
     * Returns a clean TestApi which we can use for many of our tests.
     */
    private function getTestApiWithProfileStructure()
    {
        $api = new TestApi([
            'Test' => [
                'fields' => [
                    'Email' => ['type' => 'email'],
                    'LastName' => ['type' => 'text'],
                    'Birthdate' => ['type' => 'empty_date'],
                    'ANumber' => ['type' => 'integer', 'value' => '-1'],
                ],
                'collections' => [
                    'Test' => [
                        'fields' => [
                            'Score' => ['type' => 'integer', 'value' => '-1'],
                            'ActionTime' => ['type' => 'empty_datetime'],
                        ],
                    ]
                ],
            ]
        ]);
        // throwOnError doesn't make a lot of difference for get() / post()
        // because we're testing success paths in this method, and we'll always
        // want to do assertions on the returned values - but True may make for
        // more specific errors if tests fail. put() is different; we use
        // executePut() for PUT calls, which ignores the value we set here.
        $api->throwOnError = true;

        return $api;
    }

    /**
     * Asserts that a (sub)profile's 'secret' value has correct initial format.
     *
     * The 'secret' can be updated to an arbitrary string; in that case this
     * method must not be used anymore.
     *
     * @param array $entity
     *   Profile or subprofile.
     */
    private function assertInitialSecretFormat(array $entity)
    {
        $this->assertTrue(isset($entity['secret']));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{28}$/', $entity['secret']);
    }

    /**
     * Asserts that a string date value is in a certain format / date range.
     *
     * @param \CopernicaApi\CopernicaRestAPI|\CopernicaApi\Tests\TestApi $api
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
     * Executes put(), catches 303.
     *
     * This is a standalone method because most/all code behind TestApi::put()
     * is supposed to behave differently from get()/post(): it is supposed to
     * throw a RuntimeException with code 303 on success if throwOnError==true.
     *
     * We can get away with executing most (all?) put() calls twice, so we'll
     * do this to make sure they behave well for both throwOnError states.
     *
     * @param \CopernicaApi\CopernicaRestAPI|\CopernicaApi\Tests\TestApi $api
     *   An API instance.
     * @param string $resource
     *   Resource (URI relative to the versioned API) to send data to.
     * @param array $data
     *   Data to send.
     * @param array $parameters
     *   (Optional) parameters for the API query. (This parameter is taken over
     *   from CopernicaRestAPI but it is unclear which PUT requests need
     *   parameters, at the time of writing this method.)
     * @param bool $add_random_params
     *  (Optional) False to suppress adding random parameters to the request.
     * @param bool $execute_twice
     *  (Optional) False to execute the call only once, and not execute it with
     *  throwError=False.
     */
    private function executePut($api, $resource, $data, array $parameters = array(), $add_random_params = true, $execute_twice = true)
    {
        // While we're here anyway: just randomly add parameters to test that
        // they're all ignored.
        // @todo this would be a good addition once we get to running this test
        //   against a live API. For now, we haven't bothered to test all
        //   requests with this manually, and we know TestApi doesn't handle
        //   them (and even doesn't allow them at the moment) so it doesn't add
        //   anything. If we get to doing this, maybe we should do it for get()
        //   too.
        if (false && rand(0, 1)) {
            $params_copy = array_change_key_case($parameters);
            if (!isset($params_copy['fields'])) {
                $parameters['fields'] = ['LastName== mismatch'];
            }
            $parameters['randomstring'] = 'randomstring';
            $parameters['randomarray'] = [55, 'randommember'];
        }

        $old_throw_state = $api->throwOnError;

        $api->throwOnError = true;
        try {
            $api->put($resource, $data, $parameters);
            throw new LogicException("put($resource) should have thrown an exception and didn't.");
        } catch (RuntimeException $exception) {
            $code = $exception->getCode();
            if ($code !== 303) {
                throw $exception;
            }
        }

        if ($execute_twice) {
            $api->throwOnError = false;
            $this->assertSame(true, $api->put($resource, $data, $parameters));
        }

        $api->throwOnError = $old_throw_state;
    }

    /**
     * Gets DateTime object with correct timezone for the API.
     *
     * @param \CopernicaApi\CopernicaRestAPI|\CopernicaApi\Tests\TestApi $api
     *   An API instance.
     *
     * @return \DateTime
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
            $timezone = TestApi::TIMEZONE_DEFAULT;
        }
        $date->setTimezone(new DateTimeZone($timezone));
        return $date;
    }

    /**
     * Gets an ID that definitely does not exist in our database.
     *
     * This can be used for testing that things 'error out correctly'.
     *
     * We have no good way of abstracting this so just return a constant value.
     * If we want to 'be better', we'll need to check the callers and change
     * the method signature to be dependent on entity type... probably. But
     * it's not the most important thing in the world as long as we don't run
     * too many tests yet.
     *
     * @return int
     *   ID.
     */
    private function nonexistentId()
    {
        return 99984;
    }
}
/*
 * @TODO tell Copernica about docs:
 * - https://www.copernica.com/en/documentation/restv2/rest-get-database-profiles and
 *   https://www.copernica.com/en/documentation/restv2/rest-get-collection-subprofiles
 *   are a bit inconsistent; the latter has more info about parameters on the
 *   page itself. This is a good idea for the 'total' parameter because it can
 *   speed up calls and lighten server load, so IMHO this should be copied to
 *   the profiles page and be more explicit about speedup.
 *   - I am not sure what 'dataonly' does. Also on the subprofiles page it
 *     mentions "profiles", Does this parameter even do anything or has it been
 *     superseded by total=false?
 * - https://www.copernica.com/en/documentation/restv2/rest-put-profile-subprofiles
 *   documents things wrongly; its info is from PUT profile/fields. It should
 *   take https://www.copernica.com/en/documentation/restv2/rest-put-database-profiles
 *   as an example. (Copypaste and change for "subprofiles".)
 * - @TODO figure out whether https://www.copernica.com/en/documentation/restv2/rest-put-profile-subprofiles
 *   has a mandatory collection ID. Work that into the previous point.
 * - If GET and POST (and maybe PUT) profile suprofiles get an extra $id on
 *   the overview page (profile/$id/subprofiles/$id), this would clarify the
 *   fact that this second ID is also mandatory, and make its exact use (and
 *   the exact distinction with collection/$id/subprofiles) easier to grasp, on
 *   the overview page itself.
 * - A suggestion: maybe in the "Available parameters" section of
 *   https://www.copernica.com/en/documentation/restv2/rest-get-collection-subprofiles
 *   it is a good idea to explicitly say that we can't filter on a specific
 *   profile but we have a specific call for this:
 *   https://www.copernica.com/en/documentation/restv2/rest-get-profile-subprofiles
 *   (If it's easy enough to link to the latter from the former page.)
 */

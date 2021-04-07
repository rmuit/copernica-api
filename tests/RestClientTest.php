<?php

namespace CopernicaApi\Tests;

use CopernicaApi\RestClient;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the RestClient class.
 *
 * This should still:
 * - test getEntity() for removed entity (throwing exception & suppressing)
 * - Simulate errors in getEntities() to check checkEntitiesMetadata logic.
 *   (By implementing _simulate_strange_response for incomplete entities data)
 * (I already know the above works, because of running most of it in
 * production and having most of it covered by higher-level automated tests.
 * So I'm not in an extreme hurry for full test coverage. But I should, just to
 * make it official.)
 * @todo all this ^
 *
 * These tests are coded to use specific functionality in TestApi.
 *
 * This class implicitly depends on TestApiBaseTest + ApiBehaviorTest, in the
 * sense that we can only trust its results fully if we know that TestApi
 * behaves flawlessly. (We have not created a phpunit.xml specifically to
 * encode this dependency/order because it's unlikely this will cause issues.)
 *
 * Anything that smells like it's testing 'logic / behavior of the API' should
 * likely be in ApiBehaviorTest.
 */
class RestClientTest extends TestCase
{
    /**
     * Tests that RestClient throws the expected exceptions.
     *
     * Or rather: that it passes through the exceptions thrown in TestApi
     * without modification, by default. So we can test non-defaults after.
     *
     * We also run through some non-default 'suppress' situations, so we can
     * see that certain exceptions are still thrown in those situations.
     *
     * @dataProvider provideBasicExceptionsData
     */
    public function testApiExceptionsDefault($class_method, $url, $error_code, $expected_message, $suppress = null)
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($expected_message);
        $this->expectExceptionCode($error_code);
        // For put(), the second argument is somehow mandatory (because it is
        // for CopernicaRestAPI too), unlike others / for delete() it's not an
        // array. So if/then it, in whatever way.
        if ($class_method === 'put') {
            $this->getClient($suppress, $url === 'INVALID-TOKEN')->$class_method($url, []);
        } else {
            // url "INVALID-TOKEN" doesn't exist - which is no problem if we
            // turn on the 'invalid token' hack anyway.
            $this->getClient($suppress, $url === 'INVALID-TOKEN')->$class_method($url);
        }
    }

    /**
     * Provides data for testApiExceptionsDefault().
     *
     * @return array[]
     */
    public function provideBasicExceptionsData()
    {
        // Expected values are those defined in TestApi::simulateException().
        // ($method_for_original_exception isn't necessary anymore; we
        // changed our mind about exception messages for POST/PUT/DELETE. We're
        // just keeping the code around in case we re-change our mind.)
        $create_message = function ($code, $method_for_original_exception = false) {
            // If only a JSON body with an error is returned as the response
            // contents, then RestClient re-throws the exception
            // with the message becoming just the message inside that body,
            // prepended with "Copernica API request failed: ".
            $original_error_message = "Simulated message returned along with HTTP code $code.";
            return $method_for_original_exception
                ? "$method_for_original_exception _simulate_exception/http/$code returned HTTP code $code. Response contents: \""
                . "HTTP/1.1 $code No descriptive string\r\nX-Fake-Header: fakevalue\r\nX-Fake-Header2: fakevalue2\r\n\r\n{\"error\":\r\n{\"message\":\"" . json_encode($original_error_message) . '"}}'
                : "Copernica API request failed: $original_error_message";
        };

        // All methods in CopernicaRestApi behave the same way, regarding the
        // basic exception handling: if a Curl error or a non-2XX HTTP response
        // is returned, it throws an exception. All those HTTP codes are
        // treated equally by CopernicaRestApi (and it could be up to
        // RestClient to handle things differently).
        $data = [];
        foreach (['get', 'post', 'put', 'delete'] as $class_method) {
            // We have not paid any attention to different Curl errors. An
            // error is an error. If RestClient starts interpreting
            // them, this test can be amended. (7 = Could not connect)
            $http_method = strtoupper($class_method);
            $data[] = [$class_method, '_simulate_exception/curl/7', 7, "CURL returned code 7 (\"Simulated error, description N/A\") for $http_method _simulate_exception/curl/7. Response contents: \""];

            // RestClient also doesn't differentiate between types of
            // HTTP return code yet, but let's add a few error and non-error
            // types to preempt future changes.
            // https://www.copernica.com/en/documentation/restv2/rest-requests
            // mentions 301 being possible for GET, 303 for PUT.
            foreach ([100, 301, 303, 400, 403, 404, 500, 503] as $code) {
                if ($class_method === 'put' && $code === 303) {
                    // This has special handling; exception, will always be
                    // intercepted by RestClient.
                    continue;
                } elseif ($class_method === 'delete' && $code === 400) {
                    // 400 for DELETE is handled by 2 different constants.
                    // Check that suppressing one value does not prevent
                    // throwing the exceptino for the other situation.
                    $data[] = [$class_method, "_simulate_exception/http/$code", $code, $create_message($code), RestClient::DELETE_RETURNS_ALREADY_REMOVED];
                    $data[] = [$class_method, "_simulate_exception/http/$code-alreadyremoved", $code, 'Copernica API request failed: FAKE-ENTITY has already been removed', RestClient::DELETE_RETURNS_BAD_REQUEST];
                } else {
                    $data[] = [$class_method, "_simulate_exception/http/$code", $code, $create_message($code)];
                }
            }
            // "Invalid access token" is just another 400 error for the live
            // API, but our checkResultForError() implemented a special code;
            // wee if that works. Also, check that this (HTTP 400) is not
            // covered by the general "strange HTTP code" suppression.
            // INVALID-TOKEN is not a url; it's recognized by the test method.
            $data[] = [$class_method, 'INVALID-TOKEN', 800, 'Copernica API request failed: Invalid access token', RestClient::POST_RETURNS_STRANGE_HTTP_CODE];
        }
        // 303 for PUT usually does not throw an exception, but we emulate a
        // subset of the strange circumstances that make
        // checkResponseSeeOther() give up. Also, test that the exception is
        // not suppressed by the regular constants. (It has its own.) The
        // difference in exception message comes from the first having a
        // standard "error" structure in the body, which RestClient
        // extracts and uses for a message of a re-thrown exception. The other
        // one with the headers + empty body is the original exception as it
        // would be thrown from CopernicaRestAPI.
        $data[] = ['put', '_simulate_exception/http/303-withbody', 303, 'Copernica API request failed: Simulated message returned along with HTTP code 303.', RestClient::PUT_RETURNS_STRANGE_HTTP_CODE];
        $data[] = ['put', '_simulate_exception/http/303-nolocation', 303, "PUT _simulate_exception/http/303-nolocation returned HTTP code 303. Response contents: \"", RestClient::PUT_RETURNS_BAD_REQUEST];

        // There's one other exception in CopernicaRestApi: for invalid JSON.
        $data[] = ['get', '_simulate_exception/invalid_json', 0, 'Unexpected input: '];

        // Cases where CopernicaRestApi doesn't throw an exception, but returns
        // data that should make RestClient throw one:

        // post() returning both false and true. Note that
        // - post() getting true is something we need to test, because it's
        //   supposed to only return an ID by default. put() can return true as
        //   well as an ID, we don't care.
        $data[] = ['post', '_simulate_strange_response/true', 803, "Response to POST _simulate_strange_response/true request returned no 'X-Created: ID' header. Details are unavailable."];

        $data[] = ['get', '_simulate_strange_response/non-array', 0, 'Response body is not a JSON encoded array: "'];
        // This one has no 'suppress' constant, so is only tested here, not in
        // the 'NoException' test.
        $data[] = ['getEntity', '_simulate_strange_response/invalid-entity', 803, "Entity returned from _simulate_strange_response/invalid-entity resource does not contain 'id'."];

        return $data;
    }

    /**
     * Tests that RestClient handles some calls/exceptions as expected.
     *
     * These are three kinds of cases:
     * 1. API calls that make TestApi (CopernicaRestAPI) throw an exception,
     *    which RestClient should catch and handle differently because
     *    of the specified 'suppress exceptions' state.
     * 2. API calls whose response by default makes RestClient throw
     *    an exception but not with the specified 'suppress exceptions' state.
     * 3. API calls whose response by default throws no exception - where we've
     *    created a fake TestApi endpoint to prove that.
     */
    public function testApiCallsThrowNoException()
    {
        $run_tests = function ($class_method, $url, $suppress, $expected_value) {
            $client = $this->getClient($suppress);
            // See testApiExceptionsDefault():
            if ($class_method === 'put') {
                $result = $client->$class_method($url, []);
            } else {
                $result = $client->$class_method($url);
            }
            $this->assertEquals($expected_value, $result);

            // Same thing with argument passed to individual call.
            $client = $this->getClient();
            switch ($class_method) {
                case 'get':
                case 'post':
                    $result = $client->$class_method($url, [], $suppress);
                    break;

                case 'put':
                    $result = $client->$class_method($url, [], [], $suppress);
                    break;

                case 'delete':
                    $result = $client->$class_method($url, $suppress);
                    break;

                default:
                    throw new LogicException("Test logic is wrong, no test available for $class_method method.");
            }
            $this->assertEquals($expected_value, $result);
        };

        // Expected values are those defined in TestApi::simulateException().
        // If we suppress the exception, RestClient does not process
        // the returned result (by e.g. decoding JSON / looking for error msg).
        $create_return_value = function ($code, $with_headers) {
            $payload = "{\"error\":\r\n{\"message\":\"Simulated message returned along with HTTP code $code.\"}}";
            if ($with_headers) {
                $payload = "HTTP/1.1 $code No descriptive string\r\nX-Fake-Header: fakevalue\r\nX-Fake-Header2: fakevalue2\r\n\r\n$payload";
            }
            return $payload;
        };

        $run_tests('post', '_simulate_exception/curl/7', RestClient::POST_RETURNS_CURL_ERROR, '');
        $run_tests('put', '_simulate_exception/curl/7', RestClient::PUT_RETURNS_CURL_ERROR, '');
        $run_tests('delete', '_simulate_exception/curl/7', RestClient::DELETE_RETURNS_CURL_ERROR, '');
        $run_tests('get', '_simulate_exception/curl/7', RestClient::GET_RETURNS_CURL_ERROR, '');
        foreach (
            [
                ['post', 100, RestClient::POST_RETURNS_STRANGE_HTTP_CODE, $create_return_value(100, true)],
                ['post', 301, RestClient::POST_RETURNS_STRANGE_HTTP_CODE, $create_return_value(301, true)],
                ['post', 303, RestClient::POST_RETURNS_STRANGE_HTTP_CODE, $create_return_value(303, true)],
                ['post', 304, RestClient::POST_RETURNS_STRANGE_HTTP_CODE, $create_return_value(304, true)],
                ['post', 400, RestClient::POST_RETURNS_BAD_REQUEST, $create_return_value(400, true)],
                ['post', 403, RestClient::POST_RETURNS_STRANGE_HTTP_CODE, $create_return_value(403, true)],
                ['post', 404, RestClient::POST_RETURNS_STRANGE_HTTP_CODE, $create_return_value(404, true)],
                ['post', 500, RestClient::POST_RETURNS_STRANGE_HTTP_CODE, $create_return_value(500, true)],
                ['post', 503, RestClient::POST_RETURNS_STRANGE_HTTP_CODE, $create_return_value(503, true)],

                ['put', 100, RestClient::PUT_RETURNS_STRANGE_HTTP_CODE, $create_return_value(100, true)],
                ['put', 301, RestClient::PUT_RETURNS_STRANGE_HTTP_CODE, $create_return_value(301, true)],
                // Regular 303 never throws exception for PUT, because it's
                // standard to return a 303. (It's regular return value is an
                // entity's relative URL.) Non-regular 303s need suppressing.
                ['put', 303, RestClient::NONE, 'someentity/1'],
                ['put', '303-withbody', RestClient::PUT_RETURNS_STRANGE_SEE_OTHER, "HTTP/1.1 303 See Other (Not Really)\r\nLocation: https://test.test/someentity/1\r\n\r\n{\"error\":\r\n{\"message\":\"Simulated message returned along with HTTP code 303.\"}}"],
                ['put', '303-nolocation', RestClient::PUT_RETURNS_STRANGE_SEE_OTHER, "HTTP/1.1 303 See Other (Not Really)\r\nX-Fake-Header: fakevalue\r\n\r\n"],
                ['put', 304, RestClient::PUT_RETURNS_STRANGE_HTTP_CODE, $create_return_value(304, true)],
                ['put', 400, RestClient::PUT_RETURNS_BAD_REQUEST, $create_return_value(400, true)],
                ['put', 403, RestClient::PUT_RETURNS_STRANGE_HTTP_CODE, $create_return_value(403, true)],
                ['put', 404, RestClient::PUT_RETURNS_STRANGE_HTTP_CODE, $create_return_value(404, true)],
                ['put', 500, RestClient::PUT_RETURNS_STRANGE_HTTP_CODE, $create_return_value(500, true)],
                ['put', 503, RestClient::PUT_RETURNS_STRANGE_HTTP_CODE, $create_return_value(503, true)],

                ['delete', 100, RestClient::DELETE_RETURNS_STRANGE_HTTP_CODE, $create_return_value(100, true)],
                ['delete', 301, RestClient::DELETE_RETURNS_STRANGE_HTTP_CODE, $create_return_value(301, true)],
                ['delete', 303, RestClient::DELETE_RETURNS_STRANGE_HTTP_CODE, $create_return_value(303, true)],
                ['delete', 304, RestClient::DELETE_RETURNS_STRANGE_HTTP_CODE, $create_return_value(304, true)],
                // DELETE HTTP 400 is handled by either of 2 constants,
                // depending on the exception message.
                ['delete', 400, RestClient::DELETE_RETURNS_BAD_REQUEST, $create_return_value(400, true)],
                ['delete', '400-alreadyremoved', RestClient::DELETE_RETURNS_ALREADY_REMOVED, "HTTP/1.1 400 No descriptive string\r\nX-Fake-Header: fakevalue\r\n\r\n{\"error\":\r\n{\"message\":\"FAKE-ENTITY has already been removed\"}}"],
                ['delete', 403, RestClient::DELETE_RETURNS_STRANGE_HTTP_CODE, $create_return_value(403, true)],
                ['delete', 404, RestClient::DELETE_RETURNS_STRANGE_HTTP_CODE, $create_return_value(404, true)],
                ['delete', 500, RestClient::DELETE_RETURNS_STRANGE_HTTP_CODE, $create_return_value(500, true)],
                ['delete', 503, RestClient::DELETE_RETURNS_STRANGE_HTTP_CODE, $create_return_value(503, true)],

                ['get', 100, RestClient::GET_RETURNS_STRANGE_HTTP_CODE, $create_return_value(100, false)],
                ['get', 301, RestClient::GET_RETURNS_STRANGE_HTTP_CODE, $create_return_value(301, false)],
                ['get', 303, RestClient::GET_RETURNS_STRANGE_HTTP_CODE, $create_return_value(303, false)],
                ['get', 304, RestClient::GET_RETURNS_STRANGE_HTTP_CODE, $create_return_value(304, false)],
                ['get', 400, RestClient::GET_RETURNS_BAD_REQUEST, $create_return_value(400, false)],
                ['get', 403, RestClient::GET_RETURNS_STRANGE_HTTP_CODE, $create_return_value(403, false)],
                ['get', 404, RestClient::GET_RETURNS_STRANGE_HTTP_CODE, $create_return_value(404, false)],
                ['get', 500, RestClient::GET_RETURNS_STRANGE_HTTP_CODE, $create_return_value(500, false)],
                ['get', 503, RestClient::GET_RETURNS_STRANGE_HTTP_CODE, $create_return_value(503, false)],
            ] as $value
        ) {
            list($class_method, $http_code, $constant, $expected_value) = $value;
            $run_tests($class_method, "_simulate_exception/http/$http_code", $constant, $expected_value);
        }

        $run_tests('get', '_simulate_exception/invalid_json', RestClient::GET_RETURNS_INVALID_JSON, '["invalid_json}');

        // Cases 2 and 3 as per the phpdoc:
        $run_tests('post', '_simulate_strange_response/true', RestClient::POST_RETURNS_NO_ID, true);

        $run_tests('get', '_simulate_strange_response/non-array', RestClient::GET_RETURNS_NON_ARRAY, 'This is not a json decoded body.');
    }

    /**
     * Test the checks that getEntities() does.
     *
     * getEntities() itself doesn't have any real testable code.
     *
     * Strictly speaking, this isn't complete yet; it verifies that some things
     * don't cause errors, but doesn't verify that some things do cause errors.
     */
    public function testCheckEntitiesMetadata()
    {
        // Create some entities.
        $api = new TestApi([
            'Test' => [
                'fields' => [
                    'Email' => ['type' => 'email'],
                ],
            ]
        ]);
        $database_id = $api->getMemberId('Test');
        $api->post("database/$database_id/profiles", ['Email' => 'me@example.com']);
        $api->post("database/$database_id/profiles", ['Email' => 'me@example.com']);
        $api->post("database/$database_id/profiles", ['Email' => 'me@example.com']);

        $client = $this->getClientForInitializedApi($api);
        // We've tested the returned metadata for the following queries in
        // ApiBehaviorTest; here, we only care that the checks don't cause
        // errors.
        $entities = $client->getEntities("database/$database_id/profiles", ['start' => -2]);
        $this->assertSame(0, count($entities));
        $entities = $client->getEntities("database/$database_id/profiles", ['limit' => -2]);
        $this->assertSame(0, count($entities));
    }


    /**
     * Constructs client class.
     *
     * @param int|null $suppress_exceptions_default
     *   Which exceptions to suppress by default for this client.
     * @param bool $hack_api_for_invalid_token
     *   (Optional) hack TestApi class to return "invalid token" errors.
     *
     * @return RestClient
     */
    protected function getClient($suppress_exceptions_default = null, $hack_api_for_invalid_token = false)
    {
        $api = new TestApi();
        $client = $this->getClientForInitializedApi($api);

        if (isset($suppress_exceptions_default)) {
            $client->suppressApiCallErrors($suppress_exceptions_default);
        }
        $api->invalidToken = $hack_api_for_invalid_token;

        return $client;
    }

    /**
     * Constructs test REST client.
     *
     * @param \CopernicaApi\Tests\TestApi $api
     *   Test API instance.
     *
     * @return \CopernicaApi\RestClient
     *   REST client, with
     */
    protected function getClientForInitializedApi(TestApi $api)
    {
        return new class ($api) extends RestClient {
            public function __construct(TestApi $api)
            {
                parent::__construct('testtoken');
                parent::setApi($api);
            }
        };
    }
}

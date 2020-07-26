<?php

namespace CopernicaApi\Tests;

use CopernicaApi\CopernicaRestClient;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the CopernicaRestClient class.
 *
 * This should still:
 * - test getEntity() for removed entity (throwing exceptino & suppressing)
 * - Simulate errors in getEntities() to check checkEntitiesMetadata logic.
 *   (By implementing _simulate_strange_response for incomplete entities data)
 * - test 'paging' in getEntities/nextBatch/lastDatasetIsComplete().
 * - test backupState() and restoreState() to a new CopernicaRestClient,
 *   probably right before calling getEntitiesnextBatch() on the new instance.
 *   (We're already testing that they work for a few other properties.)
 * - test getEmbeddedEntities() on the result of a '/databases' call, after we
 *   implement a response for that in TestApi.
 * (I already know the above works, because of running most of it in
 * production and having most of it covered by higher-level automated tests.
 * So I'm not in an extreme hurry for full test coverage. But I should, just to
 * make it official.)
 * @todo all this ^
 *
 * These tests are coded to use specific functionality in TestApi.
 *
 * This class implicitly depends on TestApiTest, in the sense that we can only
 * trust its results fully if we know that TestApi behaves flawlessly. (We have
 * not created a phpunit.xml specifically to encode this dependency/order
 * because it's unlikely this will cause issues.)
 *
 * Anything that smells like it's testing 'logic / behavior of the API' should
 * likely be in the class we describe in the TODO in TestApiTest: a new test
 * class that we could run against TestApi as well as a live API/database.
 */
class CopernicaRestClientTest extends TestCase
{
    /**
     * Tests that CopernicaRestClient throws the expected exceptions.
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
        $this->expectExceptionMessage($expected_message);
        $this->expectExceptionCode($error_code);
        // For put(), the second argument is somehow mandatory (because it is
        // for CopernicaRestAPI too), unlike others / for delete() it's not an
        // array. So if/then it, in whatever way.
        if ($class_method === 'put') {
            $this->getClient($suppress)->$class_method($url, []);
        } else {
            $this->getClient($suppress)->$class_method($url);
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
        $create_message = function ($code, $full_message_including_method) {
            // If only a JSON body with an error is returned as the response
            // contents, then the exception message actually becomes just the
            // message inside that body. So... IF the headers are included in
            // the response contents, that does not happen. This is enough of a
            // distinction for us to be able to test both cases; 'returning
            // headers' == 'not returning pure json' == 'full message'.
            $original_error_message = "Simulated message returned along with HTTP code $code.";
            return $full_message_including_method
                ? "$full_message_including_method _simulate_exception/http/$code returned HTTP code $code. Response contents: \""
                . "X-Fake-Header: fakevalue\r\nX-Fake-Header2: fakevalue2\r\n\r\n{\"error\":\r\n{\"message\":\"$original_error_message\"}}\"."
                : $original_error_message;
        };

        // All methods in CopernicaRestApi behave the same way, regarding the
        // basic exception handling: if a Curl error or a non-2XX HTTP response
        // is returned, it throws an exception. All those HTTP codes are
        // treated equally by CopernicaRestApi (and it could be up to
        // CopernicaRestClient to handle things differently).
        $data = [];
        foreach (['get', 'post', 'put', 'delete'] as $class_method) {
            // We have not paid any attention to different Curl errors. An
            // error is an error. If CopernicaRestClient starts interpreting
            // them, this test can be amended. (7 = Could not connect)
            $http_method = strtoupper($class_method);
            $data[] = [$class_method, '_simulate_exception/curl/7', 7, "CURL returned code 7 (\"Simulated error, description N/A\") for $http_method _simulate_exception/curl/7. Response contents: \""];
            // CopernicaRestClient also doesn't differentiate between types of
            // HTTP return code yet, but let's add a few error and non-error
            // types to preempt future changes.
            // https://www.copernica.com/en/documentation/restv2/rest-requests
            // mentions 301 being possible for GET, 303 for PUT.
            foreach ([100, 301, 303, 400, 403, 404, 500, 503] as $code) {
                // POST/PUT include headers and therefore throw exception with
                // full message. Others get the message from the body.
                $data[] = [$class_method, "_simulate_exception/http/$code", $code, $create_message(
                    $code,
                    in_array($http_method, ['POST', 'PUT']) ? $http_method : false
                )];
            }
        }
        // HTTP 400 is not covered by the general "strange HTTP code"
        // suppression. (See above for difference in messages between post/put
        // and delete/get. We won't construct full message for post/put here.)
        $data[] = ['post', '_simulate_exception/http/400', 400, "POST _simulate_exception/http/400 returned HTTP code 400. Response contents: \"", CopernicaRestClient::POST_RETURNS_STRANGE_HTTP_CODE];
        $data[] = ['put', '_simulate_exception/http/400', 400, "PUT _simulate_exception/http/400 returned HTTP code 400. Response contents: \"", CopernicaRestClient::PUT_RETURNS_STRANGE_HTTP_CODE];
        $data[] = ['delete', '_simulate_exception/http/400', 400, 'Simulated message returned along with HTTP code 400.', CopernicaRestClient::DELETE_RETURNS_STRANGE_HTTP_CODE];
        $data[] = ['get', '_simulate_exception/http/400', 400, 'Simulated message returned along with HTTP code 400.', CopernicaRestClient::GET_RETURNS_STRANGE_HTTP_CODE];
        // Neither is 303 for PUT; that also has its own specific suppression
        // constant. (But it is for all other methods).
        $data[] = ['put', '_simulate_exception/http/303', 303, "PUT _simulate_exception/http/303 returned HTTP code 303. Response contents: \"", CopernicaRestClient::PUT_RETURNS_STRANGE_HTTP_CODE];
        $data[] = ['put', '_simulate_exception/http/303', 303, "PUT _simulate_exception/http/303 returned HTTP code 303. Response contents: \"", CopernicaRestClient::PUT_RETURNS_BAD_REQUEST];

        // There's one other exception in CopernicaRestApi: for invalid JSON.
        $data[] = ['get', '_simulate_exception/invalid_json', 0, 'Unexpected input: '];

        // Cases where CopernicaRestApi doesn't throw an exception, but returns
        // data that should make CopernicaRestClient throw one:

        // post() returning both false and true. Note that
        // - post() getting false from CopernicaRestApi::post() is impossible
        //   when $throwOnError is true, so we shouldn't even need to test
        //   _simulate_strange_response/false (because CopernicaRestClient
        //   always sets $throwOnError=true).
        // - post() getting true is something we need to test, because it's
        //   supposed to only return an ID by default. put() can return true as
        //   well as an ID (as well as false), we don't care.
        $data[] = ['post', '_simulate_strange_response/false', 803, "Response to POST _simulate_strange_response/false request returned no 'X-Created: ID' header. Details are unavailable."];
        $data[] = ['post', '_simulate_strange_response/true', 803, "Response to POST _simulate_strange_response/true request returned no 'X-Created: ID' header. Details are unavailable."];

        $data[] = ['get', '_simulate_strange_response/non-array', 0, 'Response body is not a JSON encoded array: "'];
        $data[] = ['get', '_simulate_strange_response/invalid-token', 800, 'Copernica API request failed: Invalid access token'];
        // This one has no 'suppress' constant, so is only tested here.
        $data[] = ['getEntity', '_simulate_strange_response/invalid-entity', 803, "Entity returned from _simulate_strange_response/invalid-entity resource does not contain 'id'."];

        return $data;
    }

    /**
     * Tests that CopernicaRestClient handles some calls/exceptions as expected.
     *
     * These are three kinds of cases:
     * 1. API calls that make TestApi (CopernicaRestAPI) throw an exception,
     *    which CopernicaRestClient should catch and handle differently because
     *    of the specified 'suppress exceptions' state.
     * 2. API calls whose response by default makes CopernicaRestClient throw
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
                    throw new \LogicException("Test logic is wrong, no test available for $class_method method.");
            }
            $this->assertEquals($expected_value, $result);
        };

        // Expected values are those defined in TestApi::simulateException().
        // If we suppress the exception, CopernicaRestClient does not process
        // the returned result (by e.g. decoding JSON / looking for error msg).
        $create_return_value = function ($code, $with_headers) {
            $payload = "{\"error\":\r\n{\"message\":\"Simulated message returned along with HTTP code $code.\"}}";
            if ($with_headers) {
                $payload = "X-Fake-Header: fakevalue\r\nX-Fake-Header2: fakevalue2\r\n\r\n$payload";
            }
            return $payload;
        };

        $run_tests('post', '_simulate_exception/curl/7', CopernicaRestClient::POST_RETURNS_CURL_ERROR, '');
        $run_tests('put', '_simulate_exception/curl/7', CopernicaRestClient::PUT_RETURNS_CURL_ERROR, '');
        $run_tests('delete', '_simulate_exception/curl/7', CopernicaRestClient::DELETE_RETURNS_CURL_ERROR, '');
        $run_tests('get', '_simulate_exception/curl/7', CopernicaRestClient::GET_RETURNS_CURL_ERROR, '');
        foreach (
            [
                ['post', 100, CopernicaRestClient::POST_RETURNS_STRANGE_HTTP_CODE, $create_return_value(100, true)],
                ['post', 301, CopernicaRestClient::POST_RETURNS_STRANGE_HTTP_CODE, $create_return_value(301, true)],
                ['post', 303, CopernicaRestClient::POST_RETURNS_STRANGE_HTTP_CODE, $create_return_value(303, true)],
                ['post', 304, CopernicaRestClient::POST_RETURNS_STRANGE_HTTP_CODE, $create_return_value(304, true)],
                ['post', 400, CopernicaRestClient::POST_RETURNS_BAD_REQUEST, $create_return_value(400, true)],
                ['post', 403, CopernicaRestClient::POST_RETURNS_STRANGE_HTTP_CODE, $create_return_value(403, true)],
                ['post', 404, CopernicaRestClient::POST_RETURNS_STRANGE_HTTP_CODE, $create_return_value(404, true)],
                ['post', 500, CopernicaRestClient::POST_RETURNS_STRANGE_HTTP_CODE, $create_return_value(500, true)],
                ['post', 503, CopernicaRestClient::POST_RETURNS_STRANGE_HTTP_CODE, $create_return_value(503, true)],

                ['put', 100, CopernicaRestClient::PUT_RETURNS_STRANGE_HTTP_CODE, $create_return_value(100, true)],
                ['put', 301, CopernicaRestClient::PUT_RETURNS_STRANGE_HTTP_CODE, $create_return_value(301, true)],
                ['put', 303, CopernicaRestClient::PUT_RETURNS_SEE_OTHER, $create_return_value(303, true)],
                ['put', 304, CopernicaRestClient::PUT_RETURNS_STRANGE_HTTP_CODE, $create_return_value(304, true)],
                ['put', 400, CopernicaRestClient::PUT_RETURNS_BAD_REQUEST, $create_return_value(400, true)],
                ['put', 403, CopernicaRestClient::PUT_RETURNS_STRANGE_HTTP_CODE, $create_return_value(403, true)],
                ['put', 404, CopernicaRestClient::PUT_RETURNS_STRANGE_HTTP_CODE, $create_return_value(404, true)],
                ['put', 500, CopernicaRestClient::PUT_RETURNS_STRANGE_HTTP_CODE, $create_return_value(500, true)],
                ['put', 503, CopernicaRestClient::PUT_RETURNS_STRANGE_HTTP_CODE, $create_return_value(503, true)],

                ['delete', 100, CopernicaRestClient::DELETE_RETURNS_STRANGE_HTTP_CODE, $create_return_value(100, false)],
                ['delete', 301, CopernicaRestClient::DELETE_RETURNS_STRANGE_HTTP_CODE, $create_return_value(301, false)],
                ['delete', 303, CopernicaRestClient::DELETE_RETURNS_STRANGE_HTTP_CODE, $create_return_value(303, false)],
                ['delete', 304, CopernicaRestClient::DELETE_RETURNS_STRANGE_HTTP_CODE, $create_return_value(304, false)],
                ['delete', 400, CopernicaRestClient::DELETE_RETURNS_BAD_REQUEST, $create_return_value(400, false)],
                ['delete', 403, CopernicaRestClient::DELETE_RETURNS_STRANGE_HTTP_CODE, $create_return_value(403, false)],
                ['delete', 404, CopernicaRestClient::DELETE_RETURNS_STRANGE_HTTP_CODE, $create_return_value(404, false)],
                ['delete', 500, CopernicaRestClient::DELETE_RETURNS_STRANGE_HTTP_CODE, $create_return_value(500, false)],
                ['delete', 503, CopernicaRestClient::DELETE_RETURNS_STRANGE_HTTP_CODE, $create_return_value(503, false)],

                ['get', 100, CopernicaRestClient::GET_RETURNS_STRANGE_HTTP_CODE, $create_return_value(100, false)],
                ['get', 301, CopernicaRestClient::GET_RETURNS_STRANGE_HTTP_CODE, $create_return_value(301, false)],
                ['get', 303, CopernicaRestClient::GET_RETURNS_STRANGE_HTTP_CODE, $create_return_value(303, false)],
                ['get', 304, CopernicaRestClient::GET_RETURNS_STRANGE_HTTP_CODE, $create_return_value(304, false)],
                ['get', 400, CopernicaRestClient::GET_RETURNS_BAD_REQUEST, $create_return_value(400, false)],
                ['get', 403, CopernicaRestClient::GET_RETURNS_STRANGE_HTTP_CODE, $create_return_value(403, false)],
                ['get', 404, CopernicaRestClient::GET_RETURNS_STRANGE_HTTP_CODE, $create_return_value(404, false)],
                ['get', 500, CopernicaRestClient::GET_RETURNS_STRANGE_HTTP_CODE, $create_return_value(500, false)],
                ['get', 503, CopernicaRestClient::GET_RETURNS_STRANGE_HTTP_CODE, $create_return_value(503, false)],
            ] as $value
        ) {
            list($class_method, $http_code, $constant, $expected_value) = $value;
            $run_tests($class_method, "_simulate_exception/http/$http_code", $constant, $expected_value);
        }

        $run_tests('get', '_simulate_exception/invalid_json', CopernicaRestClient::GET_RETURNS_INVALID_JSON, '["invalid_json}');

        // Cases 2 and 3 as per the phpdoc:
        $run_tests('post', '_simulate_strange_response/false', CopernicaRestClient::POST_RETURNS_NO_ID, false);
        $run_tests('post', '_simulate_strange_response/true', CopernicaRestClient::POST_RETURNS_NO_ID, true);
        // Return value of false throws exception for post() because "No ID",
        // but put() has no such check. See also: the above 'exceptions' tests.
        // Fot delete(), we don't even have a fake endpoint to test because we
        // just assume it will never throw an exception.
        $run_tests('put', '_simulate_strange_response/false', CopernicaRestClient::NONE, false);

        $run_tests('get', '_simulate_strange_response/non-array', CopernicaRestClient::GET_RETURNS_NON_ARRAY, 'This is not a json decoded body.');
        $run_tests('get', '_simulate_strange_response/invalid-token', CopernicaRestClient::GET_RETURNS_ERROR_MESSAGE, ['error' => ['message' => 'Invalid access token']]);
    }

    /**
     * Gets client class.
     *
     * @param int|null $suppress_exceptions_default
     *   Which exceptions to suppress by default for this client.
     *
     * @return \CopernicaApi\CopernicaRestClient
     */
    protected function getClient($suppress_exceptions_default = null)
    {
        $token = 'testtoken';
        $api = new TestApi();
        TestApiFactory::$testApis[$token] = $api;
        $client = new CopernicaRestClient($token, '\CopernicaApi\Tests\TestApiFactory');
        if (isset($suppress_exceptions_default)) {
            $client->suppressApiCallErrors($suppress_exceptions_default);
        }

        // Randomly instantiate a new client and try to restore state
        // (factory class and suppressions) into it, to prove that works.
        if (rand(0, 1)) {
            $client2 = new CopernicaRestClient('');
            $client2->restoreState($client->backupState());
            $client = $client2;
        }

        return $client;
    }
}

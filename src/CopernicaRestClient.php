<?php

namespace CopernicaApi;

use LogicException;
use RuntimeException;

/**
 * REST API Client for Copernica.
 */
class CopernicaRestClient
{
    /**
     * Equivalent to "none", if one likes using constants instead of integers.
     *
     * Usable with suppressApiCallErrors() / a relevant argument to method
     * calls; use this value to always throw exceptions (i.e. suppress none) in
     * real or perceived error situations - thereby ensuring that values
     * returned from calls are always valid and usable.
     */
    const NONE = 0;

    /**
     * Equivalent to "all", if one likes using constants instead of integers.
     *
     * Usable for suppressApiCallErrors() / a relevant argument to method
     * calls; use this value to suppress all exceptions being thrown - but
     * beware this implies remaining unsure that returned values truly indicate
     * success.
     */
    const ALL = -1;

    /**
     * Value to use for method argument / suppressApiCallErrors() as a bitmask:
     *
     * Represents a HTTP GET request resulting in a Curl error.
     */
    const GET_RETURNS_CURL_ERROR = 1;

    /**
     * Value to use for method argument / suppressApiCallErrors() as a bitmask:
     *
     * Represents a HTTP GET request resulting in a HTTP 400 "Bad request"
     * response. (This gets a separate constant to be able to suppress it,
     * because https://www.copernica.com/en/documentation/restv2/rest-requests
     * mentions it as one of very few HTTP response codes that could occur. The
     * application is unknown so far, though.)
     */
    const GET_RETURNS_BAD_REQUEST = 2;

    /**
     * Value to use for method argument / suppressApiCallErrors() as a bitmask:
     *
     * Represents a HTTP GET request resulting in any code lower than 200 or
     * higher than 299, except 400. The Copernica docs mention "301 Moved
     * Permanently" as a possible return code, and we treat that as an error
     * rather than just returning an empty value. Practical examples of other
     * codes are unknown; Copernica may or may not always return an empty
     * response rather than a 5xx error code, in case of internal errors.
     */
    const GET_RETURNS_STRANGE_HTTP_CODE = 4;

    /**
     * Value to use for method argument / suppressApiCallErrors() as a bitmask:
     *
     * Represents a REST API GET endpoint returning a response of type
     * "application/json" whose body cannot be decoded as JSON. (This is
     * the only case where the standard CopernicaRestAPI class threw an
     * exception.) If suppressed, this will return the non-decoded contents.
     */
    const GET_RETURNS_INVALID_JSON = 8;

    /**
     * Value to use for method argument / suppressApiCallErrors() as a bitmask:
     *
     * Represents a REST API GET endpoint returning either a valid response of
     * type other than "application/json", or a response of of type
     * "application/json" whose body contains a (JSON encoded) value that is
     * not an array. (The default CopernicaRestAPI class does not let us
     * distinguish between the two cases of string values.)
     *
     * get() hardcodes a small amount of API calls which are allowed to return
     * string values by default, and makes sure to only return arrays in all
     * other cases, for consistency / convenience to the caller. That is: if
     * non-arrays are returned, an exception is thrown. If other API calls
     * unexpectedly also return non-arrays as valid values, callers may need to
     * pass this value as the third argument to get() - or to
     * suppressApiCallErrors().
     *
     * Note a get() call may still return a non-array value if other values are
     * set (i.e. exceptions are not thrown in other cases).
     */
    const GET_RETURNS_NON_ARRAY = 16;

    /**
     * Value to use for method argument / suppressApiCallErrors() as a bitmask:
     *
     * Represents a REST API GET endpoint returning an 'expected' HTTP response
     * code and a body containing valid JSON containing an "error" entry. By
     * default, an exception will be thrown, specifying the accompanying error
     * message. (This value does not have any effect on cases governed by
     * GET_RETURNS_BAD_REQUEST / GET_RETURNS_STRANGE_HTTP_CODE. Ff those are
     * suppressed, the response contents are returned; if that's JSON with an
     * "error" entry, we never decide to throw an exception anyway.)
     */
    const GET_RETURNS_ERROR_MESSAGE = 32;

    /**
     * Value to use for method argument / suppressApiCallErrors() as a bitmask:
     *
     * Represents a REST API POST endpoint returning a response body containing
     * valid JSON containing an 'entity' whose "removed" property is not false.
     */
    const GET_ENTITY_IS_REMOVED = 64;

    /**
     * Value to use for method argument / suppressApiCallErrors() as a bitmask:
     *
     * Represents a HTTP POST request resulting in a Curl error.
     */
    const POST_RETURNS_CURL_ERROR = 128;

    /**
     * Value to use for method argument / suppressApiCallErrors() as a bitmask:
     *
     * Represents a HTTP POST request resulting in a HTTP 400 "Bad request"
     * response. (This gets a separate constant to be able to suppress it,
     * because https://www.copernica.com/en/documentation/restv2/rest-requests
     * mentions it as one of very few HTTP response codes that could occur. The
     * application is unknown so far, though.)
     */
    const POST_RETURNS_BAD_REQUEST = 256;

    /**
     * Value to use for method argument / suppressApiCallErrors() as a bitmask:
     *
     * Represents a HTTP POST request resulting in any code lower than 200 or
     * higher than 299, except 400. Practical examples are unknown.
     */
    const POST_RETURNS_STRANGE_HTTP_CODE = 512;

    /**
     * Value to use for method argument / suppressApiCallErrors() as a bitmask:
     *
     * Represents a call to CopernicaRestAPI::post() returning true rather than
     * an ID. (It seems likely that very many HTTP POST calls are always
     * supposed to return an "X-Created:" header with an ID value, and absence
     * of this indicates some kind of error.)
     *
     * This class does not (yet?) know the particulars of every single request,
     * so throwing exceptions is suppressed by default for this case, for fear
     * of causing unwanted exceptions. Code which knows that a HTTP POST
     * request must return an ID, is encouraged to negate this behavior by
     * unsetting this bit or passing the appropriate value (e.g. NONE) to the
     * third argument of post() calls.
     */
    const POST_RETURNS_NO_ID = 1024;

    /**
     * Value to use for method argument / suppressApiCallErrors() as a bitmask:
     *
     * Represents a HTTP GET request resulting in a Curl error.
     */
    const PUT_RETURNS_CURL_ERROR = 2048;

    /**
     * Value to use for method argument / suppressApiCallErrors() as a bitmask:
     *
     * Represents a HTTP PUT request resulting in a HTTP 303 "See other"
     * response. https://www.copernica.com/en/documentation/restv2/rest-requests
     * mentions it as one of very few HTTP response codes that could occur. So
     * far we do not know when it happens or how it should best be handled.
     * That likely depends on whether the caller needs the value of the
     * 'Location:' header; currently, the only way for the caller to access it
     * is likely to have this class throw an exception and then grab it from
     * the exception message, which should contain the full headers and body.
     * That's fairly ugly, so if the caller indeed needs the value, we should
     * likely release a new version of the library with better support.
     * Currently we're not sure if the caller even needs it, though. If not, it
     * could use this constant to suppress the exception.
     */
    const PUT_RETURNS_SEE_OTHER = 4096;

    /**
     * Value to use for method argument / suppressApiCallErrors() as a bitmask:
     *
     * Represents a HTTP PUT request resulting in a HTTP 400 "Bad request"
     * response. (This gets a separate constant to be able to suppress it,
     * because https://www.copernica.com/en/documentation/restv2/rest-requests
     * mentions it as one of very few HTTP response codes that could occur. The
     * application is unknown so far, though.)
     */
    const PUT_RETURNS_BAD_REQUEST = 8192;

    /**
     * Value to use for method argument / suppressApiCallErrors() as a bitmask:
     *
     * Represents a HTTP PUT request resulting in any code lower than 200 or
     * higher than 299, except 303 and 400. Practical examples are unknown.
     */
    const PUT_RETURNS_STRANGE_HTTP_CODE = 16384;

    /**
     * Value to use for method argument / suppressApiCallErrors() as a bitmask:
     *
     * Represents a HTTP DELETE request resulting in a Curl error.
     */
    const DELETE_RETURNS_CURL_ERROR = 32768;

    /**
     * Value to use for method argument / suppressApiCallErrors() as a bitmask:
     *
     * Represents a HTTP DELETE request resulting in a HTTP 400 "Bad request"
     * response. (This gets a separate constant to be able to suppress it,
     * because https://www.copernica.com/en/documentation/restv2/rest-requests
     * mentions it as one of very few HTTP response codes that could occur. The
     * application is unknown so far, though.)
     */
    const DELETE_RETURNS_BAD_REQUEST = 65536;

    /**
     * Value to use for method argument / suppressApiCallErrors() as a bitmask:
     *
     * Represents a HTTP DELETE request resulting in any code lower than 200 or
     * higher than 299, except 400. Practical examples are unknown.
     */
    const DELETE_RETURNS_STRANGE_HTTP_CODE = 131072;

    /**
     * Indicates whether API calls may throw exceptions in error situations.
     *
     * See suppressApiCallErrors().
     *
     * @var int
     */
    protected $suppressApiCallErrors = self::NONE;

    /**
     * Name of a factory class that instantiates the actual connection class.
     *
     * The class must have a static create() method as implicitly documented in
     * getApiConnection().
     *
     * @var string
     */
    private $apiFactoryClassName;

    /**
     * An instantiated API connection class.
     *
     * @var object
     */
    private $api;

    /**
     * The access token.
     *
     * @var string
     */
    private $token;

    /**
     * The version of the REST API that should be accessed.
     *
     * @var int
     */
    private $version;

    /**
     * The resource (relative URI) accessed by the last getEntities() call.
     *
     * @var string
     */
    private $lastEntitiesResource = '';

    /**
     * The parameters used for the last getEntities() call.
     *
     * @var array
     */
    private $lastEntitiesParameters = [];

    /**
     * The 'total' property in the result from the last getEntities*() call.
     *
     * @var int
     */
    private $lastEntitiesDatasetTotal = 0;

    /**
     * The start position to be used by the next getEntitiesNextBatch() call.
     *
     * @var int
     */
    private $nextEntitiesDatasetStart = 0;

    /**
     * Constructor.
     *
     * @param string $token
     *   The access token used by the wrapped class.
     * @param string $api_factory
     *   The full class name responsible for instantiating classes that handle
     *   the actual REST API connection. When in doubt, do not pass / pass "".
     * @param int $version
     *   The API version to call.
     */
    public function __construct($token, $api_factory = '', $version = 2)
    {
        $this->token = $token;
        $this->version = $version;
        $this->apiFactoryClassName = $api_factory;
    }

    /**
     * Sets certain types of error to not throw an exception.
     *
     * Set -1/ALL to mean "never", 0/false/NONE (recommended) to mean "in all
     * real or perceived error situations", or use defined constants as bitmask
     * values.
     *
     * Error handling in this class is imperfect because we don't know all
     * possible ways to handle certain responses - and by default, we throw
     * exceptions if we don't know. The intention is to never return a value to
     * the caller if it can't be sure how to treat that value - but this means
     * we could be throwing exceptions in too many cases. In order to suppress
     * throwing certain types of exceptions, suppressApiCallErrors() can be
     * called with the appropriate constants.
     *
     * If exceptions for Curl errors or HTTP response codes are suppressed, the
     * method will return the value returned by curl_exec(). For Curl errors,
     * In the case of HTTP response codes, for get() and delete() this is the
     * response body, which happens to be the same as the return value from
     * get() / delete() - except for get() this means that if the body contains
     * JSON, it is not decoded. For post() and put(), this is concatenated
     * headers and body, separated by "\r\n\r\n". Future version of this
     * library might have a different implementation; whenever these constants
     * need to be used, a report is welcome because they are here "just to be
     * sure".
     *
     * In addition, sometimes the question of what constitutes a real error is
     * dependent on application logic. (Example: a deleted entity gets
     * re-deleted with a consecutive API call and the API returns HTTP 400.) In
     * these cases, while we could always throw an exception, it makes sense
     * for the caller to not always have to deal with catching those.
     *
     * The bitmask values passed to this function are a combination of these
     * two types: 'internal-only logic which might change as we learn more' and
     * 'application logic that makes sense to be tweakable'. Bitmask values in
     * the second category are documented with the method documentation; others
     * only in code comments.
     *
     * This method sets default behavior; methods also have corresponding
     * arguments to influence this behavior for single API calls. Those
     * arguments overwrite the default, so in order to add to it instead, pass
     * $this->getSuppressedApiCallErrors() | EXTRA_CONSTANT.
     *
     * @param int $types
     *   The types of errors to suppress exceptions for. 0/false means the same
     *   as the NONE constant.
     */
    public function suppressApiCallErrors($types)
    {
        $this->suppressApiCallErrors = $types;
    }

    /**
     * Gets the indicator of which types or error do not throw an exception.
     */
    public function getSuppressedApiCallErrors()
    {
        return $this->suppressApiCallErrors;
    }

    /**
     * Executes a POST request.
     *
     * Please note an imperfection in error handling: (See
     * suppressApiCallErrors() for general remarks on why this method may start
     * throwing more exceptions over time.)
     *
     * Our current default behavior is to throw a RuntimeException when the
     * return value from CopernicaRestAPI::post() seems to indicate error (by
     * being false), but not to do this when the return value is true. However,
     * it is not at all unlikely that the CopernicaRestAPI class returns true
     * instead, for some strange errors. (Because any HTTP codes higher than
     * 400 likely return true; see the code: a HTTP response code other than
     * 400 returns either an ID found in a header or true.)
     *
     * We do not (yet?) know the particulars of HTTP responses and how they
     * vary over API calls, so this method cannot make assumptions (yet?) about
     * what a return value of true means. But callers which are sure they need
     * to have an ID returned instead, are encouraged to pass 0/NONE as the
     * third argument, or to set this (unset POST_RETURNS_NO_ID) globally
     * through suppressApiCallErrors(), so that calls are guaranteed to either
     * return an ID of throw an exception.
     *
     * @param string $resource
     *   Resource (URI relative to the versioned API) to send data to.
     * @param array $data
     *   (Optional) data to send.
     * @param int|null $suppress_errors
     *   (Optional) suppress throwing exceptions for certain cases; see
     *   suppressApiCallErrors(). It is recommended to pass 0, for any caller
     *   which knows that's safe; see method comments.
     *
     * @return mixed
     *   ID of created entity, or simply true/false to indicate success/failure.
     *   (This description comes from CopernicaRestAPI but it seems doubtful
     *   that true actually indicates success for most cases though. False is
     *   only returned for nonstandard $suppress_errors values.)
     *
     * @throws \RuntimeException
     *   If the wrapped class returns False.
     *
     * @see CopernicaRestClient::suppressApiCallErrors()
     */
    public function post($resource, array $data = array(), $suppress_errors = null)
    {
        // API NOTE: One error we've seen when POSTing data to one Copernica
        // db (but not another one) was that addresses@126.com were disallowed.
        // If you try to enter them in the UI, you get a message "spambots".
        // This REST request will return no indicative message headers and a
        // body {"error":{"message":"Failed to create profile"}}.
        if (!isset($suppress_errors)) {
            $suppress_errors = $this->suppressApiCallErrors;
        }

        $api = $this->getApiConnection();
        // Make the API class throw exceptions. We can extract the headers/body
        // from the exception message if needed.
        $api->throwOnError = true;
        try {
            $result = $api->post($resource, $data);
        } catch (RuntimeException $e) {
            $code = $e->getCode();
            $suppress = ($code > 0 && $code < 100 && $suppress_errors & self::POST_RETURNS_CURL_ERROR)
                || ($code == 400 && $suppress_errors & self::POST_RETURNS_BAD_REQUEST)
                || ((($code >= 100 && $code < 200) || ($code > 299 && $code != 400))
                    && $suppress_errors & self::POST_RETURNS_STRANGE_HTTP_CODE);
            return $this->throwOrReturn($e, !$suppress);
        }

        // post() returns true or an ID. We think it should always be an ID...
        if (!is_numeric($result) && !($suppress_errors && self::POST_RETURNS_NO_ID)) {
            throw new RuntimeException("Response to POST $resource request returned no 'X-Created: ID' header. Details are unavailable.", 803);
        }

        return $result;
    }

    /**
     * Executes a PUT request.
     *
     * This code may start throwing more exceptions over time (unless a
     * relevant value is passed in the $suppress_errors argument / set globally
     * through suppressApiCallErrors(). See comments at suppressApiCallErrors()
     * for more information.)
     *
     * @param string $resource
     *   Resource (URI relative to the versioned API) to send data to.
     * @param array $data
     *   Data to send.
     * @param array $parameters
     *   (Optional) parameters for the API query. (This parameter is taken over
     *   from CopernicaRestAPI but it is unclear which PUT requests need
     *   parameters, at the time of writing this method.)
     * @param int|null $suppress_errors
     *   (Optional) suppress throwing exceptions for certain cases; see
     *   suppressApiCallErrors().
     *
     * @return mixed
     *   ID of created entity, or simply true/false to indicate success/failure.
     *   (This description comes from CopernicaRestAPI but we're not sure of
     *   any PUT request returning an ID yet. False is only returned for
     *   nonstandard $suppress_errors values.)
     *
     * @throws \RuntimeException
     *   If the wrapped class returns False.
     *
     * @see CopernicaRestClient::suppressApiCallErrors()
     */
    public function put($resource, array $data, array $parameters = array(), $suppress_errors = null)
    {
        if (!isset($suppress_errors)) {
            $suppress_errors = $this->suppressApiCallErrors;
        }

        $api = $this->getApiConnection();
        // Make the API class throw exceptions. We can extract the headers/body
        // from the exception message if needed.
        $api->throwOnError = true;
        try {
            $result = $api->put($resource, $data, $parameters);
        } catch (RuntimeException $e) {
            $code = $e->getCode();
            $suppress = ($code > 0 && $code < 100 && $suppress_errors & self::PUT_RETURNS_CURL_ERROR)
                || ($code == 303 && $suppress_errors & self::PUT_RETURNS_SEE_OTHER)
                || ($code == 400 && $suppress_errors & self::PUT_RETURNS_BAD_REQUEST)
                || ((($code >= 100 && $code < 200) || ($code > 299 && !in_array($code, [303, 400])))
                    && $suppress_errors & self::PUT_RETURNS_STRANGE_HTTP_CODE);
            return $this->throwOrReturn($e, !$suppress);
        }

        // Unlike post(), we don't make assumptions about what kind of value
        // this returns. It's likely always true. (Because we assume no PUT
        // request responds with an "X-Created" header... on the other hand,
        // why would that "X-Created" code be inside sendData() rather than
        // post()?) But even if it's not - we're not bothered.
        return $result;
    }

    /**
     * Executes a DELETE request.
     *
     * This code may start throwing more exceptions over time (unless a
     * relevant value is passed in the $suppress_errors argument / set globally
     * through suppressApiCallErrors(). See comments at suppressApiCallErrors()
     * for more information.)
     *
     * @param string $resource
     *   Resource (URI relative to the versioned API).
     * @param int|null $suppress_errors
     *   (Optional) suppress throwing exceptions for certain cases; see
     *   suppressApiCallErrors(). This parameter has no effect but may have in
     *   the future (in the sense that setting it to a relevant value will
     *   prevent exceptions from starting to be thrown in the future).
     *
     * @return bool
     *   ? @todo check
     *
     * @see CopernicaRestClient::suppressApiCallErrors()
     *
     * @todo Implement handling of HTTP 204, check "X-deleted" header. See
     *   https://www.copernica.com/en/documentation/restv2/rest-requests.
     */
    public function delete($resource, $suppress_errors = null)
    {
        if (!isset($suppress_errors)) {
            $suppress_errors = $this->suppressApiCallErrors;
        }

        $api = $this->getApiConnection();
        // Make the API class throw exceptions. We can extract the body from
        // the exception message if needed; the headers are not included but
        // we don't know of an application that needs it. (Successful DELETE
        // requests apparently have a "X-deleted" header, but we can live
        // without doublechecking that.)
        $api->throwOnError = true;
        try {
            $result = $api->delete($resource);
        } catch (RuntimeException $e) {
            $code = $e->getCode();
            $suppress = ($code > 0 && $code < 100 && $suppress_errors & self::DELETE_RETURNS_CURL_ERROR)
                || ($code == 400 && $suppress_errors & self::DELETE_RETURNS_BAD_REQUEST)
                || ((($code >= 100 && $code < 200) || ($code > 299 && $code != 400))
                    && $suppress_errors & self::DELETE_RETURNS_STRANGE_HTTP_CODE);
            return $this->throwOrReturn($e, !$suppress);
        }

        return $result;
    }

    /**
     * Executes a GET request.
     *
     * @param string $resource
     *   Resource (URI relative to the versioned API) to fetch data from.
     * @param array $parameters
     *   (Optional) parameters for the API query.
     * @param int|null $suppress_errors
     *   (Optional) suppress throwing exceptions for certain cases; see
     *   suppressApiCallErrors().
     *
     * @return array|mixed
     *   The JSON-decoded response body; can be other things and/or a non-array
     *   value if throwing exceptions is suppressed.
     *
     * @see CopernicaRestClient::suppressApiCallErrors()
     * @see CopernicaRestClient::getEntity()
     * @see CopernicaRestClient::getEntities()
     */
    public function get($resource, array $parameters = array(), $suppress_errors = null)
    {
        // API NOTES:
        // - Curl occasionally returns error 7 "Failed to connect" (and get()
        //   returns a string value, I assume empty string).
        // - We've also seen error 52 "Empty reply from server" - for a
        //   'subprofiles' query for a 'large' collection. This suggests
        //   timeout-like conditions are possible on the server end, which
        //   ideally should return a 4xx/5xx HTTP code instead of 'empty'.
        if (!isset($suppress_errors)) {
            $suppress_errors = $this->suppressApiCallErrors;
        }

        $api = $this->getApiConnection();
        // Make the API class throw exceptions. We can extract the body from
        // the exception message if needed; the headers are not included but
        // we don't know of an application that needs it.
        $api->throwOnError = true;
        try {
            $result = $api->get($resource, $parameters);
        } catch (RuntimeException $e) {
            // Code 0 is normally one specific error, indicating invalid JSON -
            // but we match the specific message because we don't want other
            // exceptions to be handled by this suppression by accident.
            $code = $e->getCode();
            if (
                $code == 0 && $suppress_errors && self::GET_RETURNS_INVALID_JSON
                && preg_match('/^Unexpected input: (.*)$/', $e->getMessage(), $matches)
            ) {
                return $matches[1];
            }
            $suppress = ($code > 0 && $code < 100 && $suppress_errors & self::GET_RETURNS_CURL_ERROR)
                || ($code == 400 && $suppress_errors & self::GET_RETURNS_BAD_REQUEST)
                || ((($code >= 100 && $code < 200) || ($code > 299 && $code != 400))
                    && $suppress_errors & self::GET_RETURNS_STRANGE_HTTP_CODE);
            return $this->throwOrReturn($e, !$suppress);
        }

        // Either the response had content type "application/json" and is valid
        // JSON; we got the decoded JSON. Or the response did not have content
        // type "application/json"; we got the literal string. This means it's
        // impossible to distinguish JSON encoded strings from other strings.
        // In the default situation this doesn't matter to us because we only
        // accept array values.
        if (!is_array($result)) {
            if (
                // Some (hardcoded) paths are always allowed to return strings.
                !(is_string($result) && in_array(substr($result, -4), ['/xml', '/csv'], true))
                && !($suppress_errors && self::GET_RETURNS_NON_ARRAY)
            ) {
                // We throw with code 0, just like invalid JSON.
                throw new RuntimeException("Response body is not a JSON encoded array: \"$result\".");
            }
        } elseif (!($suppress_errors && self::GET_RETURNS_ERROR_MESSAGE)) {
            $this->checkResultForError($result);
        }

        return $result;
    }

    /**
     * Executes a GET request that returns a single entity.
     *
     * This method always throws an exception if an ID value is not found in
     * the response body; code will likely want to always call this method
     * instead of get() for a specific set of paths, so they don't need to
     * check this by themselves.
     *
     * By default, an exception is also thrown if the entity is removed (i.e.
     * the structure of an entity is valid but the 'removed' property has a
     * nonempty value); this can be suppressed by passing the
     * GET_ENTITY_IS_REMOVED bit to the $suppress_errors argument or setting it
     * through suppressApiCallErrors().
     *
     * No other bit values for the 'suppress' method/argument have any effect;
     * other exception types are thrown regardless of this value, because this
     * method is explicitly meant to return a valid entity.
     *
     * @param string $resource
     *   Resource (URI relative to the versioned API) to fetch data from.
     * @param array $parameters
     *   (Optional) parameters for the API query.
     * @param int|null $suppress_errors
     *   (Optional) suppress throwing exceptions for certain cases. See above:
     *   only GET_ENTITY_IS_REMOVED currently has any effect.
     *
     * @return array
     *   The JSON-decoded response body containing an entity.
     *
     * @throws \RuntimeException
     *   If the get() call threw a RuntimeException, if the entity was removed
     *   (code 112), if the entity structure is invalid or if the returned
     *   response includes an 'error' value. See checkResultForError() for
     *   error codes.
     */
    public function getEntity($resource, array $parameters = array(), $suppress_errors = null)
    {
        $result = $this->get($resource, $parameters, self::NONE);
        // Some of the calls (like publisher/documents) have 'id' property;
        // some (like profiles, collections) have 'ID' property.
        if (empty($result['id']) && empty($result['ID'])) {
            throw new RuntimeException("Entity returned from $resource resource does not contain 'id'.", 803);
        }

        // We do not know for sure but have seen this with profiles, so for now
        // will assume it's with every entity: 'removed' indicates the date
        // when the item was removed. the 'fields' array still contains all
        // fields, but with empty string as values.
        if (!isset($suppress_errors)) {
            $suppress_errors = $this->suppressApiCallErrors;
        }
        if (!empty($result['removed']) && !($suppress_errors && self::GET_ENTITY_IS_REMOVED)) {
            throw new RuntimeException("Entity returned from $resource resource was removed {$result['removed']}.", 802);
        }
        return $result;
    }

    /**
     * Executes a GET request that returns a batch of items.
     *
     * This method checks if the returned response contains a valid structure
     * for a list of entities, and if each individual entity is also valid (has
     * an ID value).
     *
     * The bitmask set through suppressApiCallErrors() has no effect on this
     * method because it is explicitly meant to return a list of valid entities.
     *
     * @param string $resource
     *   Resource (URI relative to the versioned API) to fetch data from.
     * @param array $parameters
     *   (Optional) parameters for the API query.
     *
     * @return array[]
     *   The 'data' part of the JSON-decoded response body, i.e. an array of
     *   entities.
     *
     * @throws \RuntimeException
     *   If the result metadata are not successfully verified.
     */
    public function getEntities($resource, array $parameters = array())
    {
        $result = $this->get($resource, $parameters, self::NONE);
        $this->lastEntitiesResource = $resource;
        $this->lastEntitiesParameters = $parameters;

        $this->checkEntitiesMetadata($result, $parameters, 'response from Copernica API');

        $this->lastEntitiesDatasetTotal = $result['total'];
        // Remember 'assumed total fetched, according to the start parameter'.
        $this->nextEntitiesDatasetStart = $result['start'] + $result['count'];
        if ($this->nextEntitiesDatasetStart > $this->lastEntitiesDatasetTotal) {
            throw new RuntimeException("Unexpected structure in response from Copernica API: 'total' property (" . json_encode($result['total']) . ") is smaller than next start pointer (" . json_encode($this->nextEntitiesDatasetStart) . ").", 804);
        }

        foreach ($result['data'] as $entity) {
            if (empty($entity['id']) && empty($entity['ID'])) {
                throw new RuntimeException("One of the entities returned from $resource resource does not contain 'id'.", 803);
            }
        }

        return $result['data'];
    }

    /**
     * Returns a batch of entities 'following' the last getEntities*() result.
     *
     * A set of data that contains more entities than the REST API limit can
     * handle in one response, can be retrieved by one call to getEntities()
     * and consecutive calls to getEntitiesNextBatch(), until this returns an
     * empty array.
     *
     * @return array[]
     *   The 'data' part of the JSON-decoded response body, i.e. an array of
     *   entities - or empty array if no more items are left in the batch.
     */
    public function getEntitiesNextBatch()
    {
        if ($this->lastEntitiesDatasetIsComplete()) {
            return [];
        }

        // The below should never return an empty array. (It probably would if
        // $this->nextEntitiesDatasetStart == $this->lastEntitiesDatasetTotal,
        // but we just returned above without fetching data, in that case.)
        return $this->getEntities(
            $this->lastEntitiesResource,
            ['start' => $this->nextEntitiesDatasetStart] + $this->lastEntitiesParameters
        );
    }

    /**
     * Checks if the last dataset was fetched completely.
     *
     * Code can call this by itself to see whether anything should still be
     * fetched, but just calling getEntitiesNextBatch() immediately is also
     * fine; that will return an empty array without actually fetching
     * anything, if the dataset is already complete.
     *
     * @return bool
     *   Indicator of the dataset represented by the previous getEntities() /
     *   getEntitiesNextBatch() being fully fetched already.
     */
    public function lastEntitiesDatasetIsComplete()
    {
        // The '>' situation would have errored out in unwrapEntitiesResult().
        return $this->nextEntitiesDatasetStart >= $this->lastEntitiesDatasetTotal;
    }

    /**
     * Extracts embedded entities from an entity's data.
     *
     * A list of embedded entities is wrapped inside its own structure of
     * start/limit/count/total properties, and this function extract them from
     * that structure so calling code does not need to deal with it. (Just like
     * every API response containing entities exists of such a structure but
     * getEntities() extracts transparently them for us.)
     *
     * Two types of entity that are known to have lists of embedded entities,
     * are:
     * - databases, which have 'fields', 'interests' and 'collections'
     * - collections, which again have 'fields'.
     * Each of these embedded properties also have their own API calls
     * defined, e.g. databases/<ID>/fields, which is recommended to call if we
     * are just looking for the embedded entities. But for code that wants to
     * use data from lists of embedded entities and/or the main entity at the
     * same time, and does not want to perform repeated API calls, this helper
     * method can come in handy.
     *
     * This method isn't very generic, in the sense that it requires the caller
     * to know about which properties contain 'wrapped' entities and to
     * estimate that the list of entities is complete. Then again, the whole
     * concept of returning  embedded entities inside an API result feels not
     * very generic to begin with. (It wastes time on the API server side if we
     * don't need those data.) It's quite possible that this only  applies to
     * databases and collections; if Copernica was doing this more often, it
     * would make more sense to create a separate class to represent entity
     * data with getters for the embedded entities. But at the moment, that
     * seems unnecessary.
     *
     * @param array $entity
     *   The entity containing embedded data.
     * @param string $property_name
     *   The property containing a list of entities wrapped in
     *   start/limit/count/total properties.
     * @param bool $throw_if_incomplete
     *   (Optional) if set to FALSE, this method will not throw an exception if
     *   the list of embedded entities is incomplete but will just return the
     *   incomplete list. By default it throws an exception with code 113, and
     *   supposedly the only way to get a complete list is to perform the
     *   separate API call for the entities, and then supposedly call
     *   getEntitiesNextBatch() until you have
     *
     * @return array
     *   The embedded entities.
     *
     * @throws \RuntimeException
     *   If the property does not have the expected structure.
     */
    public function getEmbeddedEntities(array $entity, $property_name, $throw_if_incomplete = true)
    {
        // We'd return an empty array for a not-set property name, IF we had an
        // example of a certain expected property not being set at all if the
        // number of embedded entities is 0.
        if (!isset($entity[$property_name])) {
            throw new RuntimeException("'$property_name' property is not set; cannot extract embedded entities.");
        }
        if (!is_array($entity[$property_name])) {
            throw new RuntimeException("'$property_name' property is not an array; cannot extract embedded entities.");
        }
        $this->checkEntitiesMetadata($entity[$property_name], [], "'$property_name' property");

        if ($entity[$property_name]['start'] !== 0) {
            // This is unexpected; we don't know how embedded entities
            // implement paging and until we do, we disallow this.
            throw new RuntimeException("List of entities inside '$property_name' property starts at {}; we cannot handle anything not starting at 0.", 804);
        }
        if ($throw_if_incomplete && $entity[$property_name]['count'] !== $entity[$property_name]['total']) {
            throw new RuntimeException("Cannot return the total list of {$entity[$property_name]['totel']} entities inside '$property_name' property; only {$entity[$property_name]['count']} found.", 804);
        }

        return $entity[$property_name]['data'];
    }

    /**
     * Returns state that must be kept for the next getEntitiesNextBatch() call.
     *
     * If for whatever reason getEntitiesNextBatch() needs to work on a newly
     * constructed class, feed the return value from this method into that new
     * class through restoreState().
     *
     * @return array
     *   The state array.
     */
    public function backupState()
    {
        return [
            'last_resource' => $this->lastEntitiesResource,
            'last_parameters' => $this->lastEntitiesParameters,
            'last_total' => $this->lastEntitiesDatasetTotal,
            'next_start' => $this->nextEntitiesDatasetStart,
            'token' => $this->token,
            'version' => $this->version,
            'suppress_errors' => $this->getSuppressedApiCallErrors(),
        ];
    }

    /**
     * Restores old state.
     *
     * @param array $state
     *   State, probably previously returned by backupState(). Token/version
     *   values are optional; if set, they overwrite the arguments passed into
     *   the constructor.
     *
     * @throws \LogicException
     *   If state has invalid values..
     */
    public function restoreState(array $state)
    {
        if (
            isset($state['token']) && !is_string($state['token'])
            || isset($state['version']) && !is_string($state['version'])
            || !isset($state['suppress_errors'])
            || !is_int($state['suppress_errors'])
            || !isset($state['last_resource'])
            || !is_string($state['last_resource'])
            || !isset($state['last_parameters'])
            || !is_array($state['last_parameters'])
            || !isset($state['last_total'])
            || !isset($state['next_start'])
            // If this ever fails, we need to check unwrapEntitiesResult().
            || !is_int($state['last_total'])
            || !is_int($state['next_start'])
        ) {
            // Not spending time on detailed errors. (Yet?)
            throw new LogicException('Invalid structure for state.');
        }
        $this->suppressApiCallErrors($state['suppress_errors']);
        $this->lastEntitiesResource = $state['last_resource'];
        $this->lastEntitiesParameters = $state['last_parameters'];
        $this->lastEntitiesDatasetTotal = $state['last_total'];
        $this->nextEntitiesDatasetStart = $state['next_start'];
        // We don't know if the current class has the same token/version.
        unset($this->api);
        if (isset($state['token'])) {
            $this->token = $state['token'];
        }
        if (isset($state['version'])) {
            $this->version = $state['version'];
        }
    }

    /**
     * Checks a data structure containing Copernica's 'list metadata'.
     *
     * @param array $struct
     *   The structure, which is usually either the JSON-decoded response body
     *   from a GET query, or a property inside an entity which contains
     *   embedded entities.
     * @param array $parameters
     *   The parameters for the GET query returning this result. These are used
     *   to doublecheck some result properties.
     * @param string $struct_descn
     *   Description of the structure, for log files.
     *
     * @throws \RuntimeException
     *   If the result metadata are not successfully verified.
     */
    private function checkEntitiesMetadata(array $struct, array $parameters, $struct_descn)
    {
        // We will throw an exception for any unexpected value. That may seem
        // way too strict but at least we'll know when something changes.
        foreach (['start', 'limit', 'count', 'total', 'data'] as $key) {
            if (!isset($struct[$key])) {
                throw new RuntimeException("Unexpected structure in $struct_descn: no '$key' value found.'", 804);
            }
            if ($key !== 'data' && !is_numeric($struct[$key])) {
                throw new RuntimeException("Unexpected structure in $struct_descn: '$key' value (" . json_encode($struct[$key]) . ') is non-numeric.', 804);
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
        // We expect the count of data fetched to be exactly equal to the
        // limit, unless we've fetched all data - then the count can be less.
        if ($struct['start'] + $struct['count'] == $struct['total']) {
            if ($struct['count'] > $struct['limit']) {
                throw new RuntimeException("Unexpected structure in response from Copernica API: 'count' value (" . json_encode($struct['count']) . ") is not equal to 'limit' (" . json_encode($struct['limit']) . ').', 804);
            }
        } elseif ($struct['count'] !== $struct['limit']) {
            throw new RuntimeException("Unexpected structure in response from Copernica API: 'count' value (" . json_encode($struct['count']) . ") is not equal to 'limit' (" . json_encode($struct['limit']) . '), which should be the case when we only fetched part of the result.', 804);
        }
    }

    /**
     * Checks a result array from a GET request for an 'error' value.
     *
     * @param array $result
     *   The JSON-decoded response body from a GET query.
     *
     * @throws \RuntimeException
     *   If an 'error' value is found.
     */
    private function checkResultForError(array $result)
    {
        if (isset($result['error'])) {
            // Copernica seems to have a neat structure with a single-value
            // array with key 'error' containing a single-value array with key
            //  'message'. We'll assume that is the case.
            if (
                isset($result['error']['message']) && is_string($result['error']['message'])
                && count($result['error']) == 1 && count($result) == 1
            ) {
                // We have no idea whether defining our own codes will be an
                // advantage to callers - but we've made some incomplete
                // attempt. (< 800 can be used by Curl or HTTP responses. 802
                // is used elsewhere for "entity removed", 803 for "ID property
                // not found" (rather than "no entity with ID"), 804 for
                // various errors in a 'metadata wrapper' structure.)
                switch ($result['error']['message']) {
                    case 'Invalid access token':
                        $code = 800;
                        break;

                    case 'No entity with supplied ID':
                        // We have various 'missing entity' / 'missing ID'
                        // errors with the same code, all for distinct calls.
                        $code = 801;
                        break;

                    default:
                        $code = 0;
                }
                throw new RuntimeException('Copernica API request failed: ' . $result['error']['message'], $code);
            }
            // If reality differs from the above, we'll output the full array
            // as the (json encoded) message. In that case, hopefully someone
            // will find their way back here and refine this part of the code.
            throw new RuntimeException('Copernica API request got unexpected response: ' . json_encode($result));
        }
    }

    /**
     * Throw exception or return value contained in exception message.
     *
     * Just some code abstracted so we don't need to duplicate it. What we
     * exactly return or throw still needs to be determined. This is explicitly
     * meant to only handle specific circumstances; see the code comments.
     *
     * @param \RuntimeException $exception
     *   An exception that was thrown.
     * @param bool $throw
     *   If False, return something from this method (and throw away the
     *   exception code / original message; we'll likely return response
     *   contents extracted from the exception message). If True, throw an
     *   exception; either the same that was passed in, or one with a simpler
     *   message IF the response contents in the exception message were ONLY a
     *   JSON encoded error-message structure. (If the response contents
     *   include headers, that means the error-message structure will not bve
     *   JSON decoded / simplified, because we assume the caller might want to
     *   inspect the headers too. This is currently the case for all POST / PUT
     *   requests made through CopernicaRestAPI.)
     */
    private function throwOrReturn(RuntimeException $exception, $throw)
    {
        // This should always match. Assume first-to-last double quote matches
        // correctly.
        $return = preg_match('/Response contents: \"(.*)\"\./s', $exception->getMessage(), $matches);
        if ($return) {
            // Suppress exception; return original response contents.
            $return = $matches[1];
        }
        if (!$throw) {
            // We'll want to either return the response contents that was
            // extracted from the message, or false (because in the latter case
            // we want to distinguish the return value from values returned by
            // a non-error path), which means the exact exception is obscured.
            // (In other words, we can only use this from places that never
            // throw miscellaneous exceptions.)
            return $return;
        }

        // The message is fairly generic. If the body itself contains of a
        // Copernica-standard JSON 'error structure', return just the
        // message contained in it.
        $return = json_decode($return, true);
        if (
            $return && isset($return['error']['message'])
            && is_string($return['error']['message'])
            && count($return['error']) == 1 && count($return) == 1
        ) {
            $exception_type = get_class($exception);
            throw new $exception_type($return['error']['message'], $exception->getCode());
        }

        // Ignore $return; throw the original message (including the response).
        throw $exception;
    }

    /**
     * Returns an 'api connection' instance.
     *
     * @return \CopernicaApi\CopernicaRestAPI|object
     */
    private function getApiConnection()
    {
        if (!isset($this->api)) {
            if ($this->apiFactoryClassName) {
                // We're using the factory pattern because we need to be able
                // to instantiate the actual 'api connection' class outside of
                // the constructor, to e.g. make restoreState() work.
                $this->api = call_user_func([$this->apiFactoryClassName, 'create'], $this->token, $this->version);
            } else {
                $this->api = new CopernicaRestAPI($this->token, $this->version);
            }
        }
        return $this->api;
    }
}

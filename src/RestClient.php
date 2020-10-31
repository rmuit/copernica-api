<?php

namespace CopernicaApi;

use LogicException;
use RuntimeException;

/**
 * REST API Client for Copernica.
 */
class RestClient
{
    /**
     * Equivalent to "none", if one likes using constants instead of integers.
     *
     * Usable with suppressApiCallErrors() / a relevant argument to method
     * calls; use this value to always throw exceptions (i.e. suppress none) in
     * real or perceived error situations - thereby ensuring that values
     * returned from calls are always valid and usable.
     *
     * This is the class default.
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
     * response, which happens with (almost?) all errors returned from the API.
     * If these errors are suppressed, get() returns the full response body
     * (which is likely a JSON endoded error structure).
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
     * message. While we're not sure (for lack of specs), this is expected to
     * never happen because we expect error messages to only be returned
     * along with a response code of 400; these are covered by
     * GET_RETURNS_BAD_REQUEST.
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
     * Represents a HTTP GET request resulting in a HTTP 400 "Bad request"
     * response, which happens with (almost?) all errors returned from the API.
     * If these errors are suppressed, post() returns the full response headers
     * plus body, separated by double CR/LF.
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
     * response with unexpected contents. This would be quite strange; please
     * inspect the exception message vs. the code to see why this gets thrown
     * (and decide whether you want to set this constant to get the full
     * headers+body returned, instead of an exception thrown).
     */
    const PUT_RETURNS_STRANGE_SEE_OTHER = 4096;

    /**
     * Value to use for method argument / suppressApiCallErrors() as a bitmask:
     *
     * Represents a HTTP GET request resulting in a HTTP 400 "Bad request"
     * response, which happens with (almost?) all errors returned from the API.
     * If these errors are suppressed, put() returns the full response headers
     * plus body, separated by double CR/LF.
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
     * Represents a HTTP GET request resulting in a HTTP 400 "Bad request"
     * response containing a "this <entity> has already been removed" message.
     * (We are left to interpret the error message because this does not have
     * its own error message - so we just hope we're covering all cases.) If
     * these errors are suppressed, delete() returns the full response headers
     * plus body, separated by double CR/LF.
     */
    const DELETE_RETURNS_ALREADY_REMOVED = 65536;

    /**
     * Value to use for method argument / suppressApiCallErrors() as a bitmask:
     *
     * Represents a HTTP DELETE request resulting in a HTTP 400 "Bad request"
     * response which is not covered by DELETE_RETURNS_ALREADY_REMOVED.
     */
    const DELETE_RETURNS_BAD_REQUEST = 131072;

    /**
     * Value to use for method argument / suppressApiCallErrors() as a bitmask:
     *
     * Represents a HTTP DELETE request resulting in any code lower than 200 or
     * higher than 299, except 400. Practical examples are unknown.
     */
    const DELETE_RETURNS_STRANGE_HTTP_CODE = 262144;

    /**
     * Indicates whether API calls may throw exceptions in error situations.
     *
     * See suppressApiCallErrors().
     *
     * @var int
     */
    protected $suppressApiCallErrors = self::NONE;

    /**
     * An instantiated CopernicaRestApi class.
     *
     * @var object
     */
    private $api;

    /**
     * The access token.
     *
     * @var string
     */
    protected $token;

    /**
     * The version of the REST API that should be accessed.
     *
     * @var int
     */
    private $version;

    /**
     * RestClient constructor.
     *
     * @param string $token
     *   The access token used by the wrapped class.
     * @param int $version
     *   The API version to call.
     */
    public function __construct($token, $version = 2)
    {
        $this->token = $token;
        $this->version = $version;
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
     * headers and body, separated by "\r\n\r\n". Future versions of this
     * library might have a different implementation; whenever these constants
     * need to be used, a report is welcome (to possibly create a more stable
     * solution), because they are here "just to be sure".
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
     * @param string $resource
     *   Resource (URI relative to the versioned API) to send data to.
     * @param array $data
     *   (Optional) data to send.
     * @param int|null $suppress_errors
     *   (Optional) suppress throwing exceptions for certain cases; see
     *   suppressApiCallErrors().
     *
     * @return mixed
     *   ID of created entity, or simply true to indicate success (if no
     *   "X-Created" header was returned with the response). If errors were
     *   encountered which are suppressed: either a string containing the full
     *   response headers and body (so it should be distinguishable from a
     *   'normal' return value), or false if an suppressed exception has an
     *   unrecognized message (which we hope is impossible).
     *
     * @throws \RuntimeException
     *   If any non-supressed Curl error, nonstandard HTTP response code or
     *   unexpected response headers are encountered.
     *
     * @see RestClient::suppressApiCallErrors()
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

        $api = $this->getApi();
        // Make the API class throw exceptions. We can extract the headers/body
        // from the exception message if needed.
        $api->throwOnError = true;
        try {
            // This never returns false (because of throwOnError).
            $result = $api->post($resource, $data);
        } catch (RuntimeException $e) {
            $code = $e->getCode();
            $suppress = ($code > 0 && $code < 100 && $suppress_errors & self::POST_RETURNS_CURL_ERROR)
                || ($code == 400 && $suppress_errors & self::POST_RETURNS_BAD_REQUEST)
                || ((($code >= 100 && $code < 200) || ($code > 299 && $code != 400))
                    && $suppress_errors & self::POST_RETURNS_STRANGE_HTTP_CODE);
            return $this->throwOrSuppress($e, $suppress);
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
     *   True, or the location (URI relative to the versioned API) of the
     *   entity that was just updated/created. (As far as known,) all
     *   resources that are meant to update a single entity return the location
     *   of that entity, i.e. a value equivalent to the resource URL. (As far
     *   as known,) all resources that are meant to update multiple entities
     *   and possibly create a new entity, return true if zero or more entities
     *   were updated, or the location of the new entity if one was created
     *   instead. If errors were encountered which are are suppressed, the
     *   method can returns either a string containing the full response
     *   headers and body (so it should be distinguishable from a 'normal'
     *   return value)... or false if an suppressed exception has an
     *   unrecognized message (which we hope is impossible).
     *
     * @throws \RuntimeException
     *   If any non-suppressed Curl error, nonstandard HTTP response code or
     *   unexpected response headers are encountered.
     *
     * @see RestClient::suppressApiCallErrors()
     */
    public function put($resource, array $data, array $parameters = array(), $suppress_errors = null)
    {
        if (!isset($suppress_errors)) {
            $suppress_errors = $this->suppressApiCallErrors;
        }

        $api = $this->getApi();
        // Make the API class throw exceptions. We can extract the headers/body
        // from the exception message if needed.
        $api->throwOnError = true;
        try {
            // This never returns false (because of throwOnError). As far as we
            // could tell, it always throws an exception with code 303 (because
            // the HTTP response is a 303 "See other") and the "Location"
            // header contains the entity we've just updated. So
            // https://www.copernica.com/en/documentation/restv2/rest-requests
            // is incomplete in mentioning "will create a new entity, in which
            // case a "303 See Other" code will refer you to the new entity" -
            // because a 303 is returned for non-new entities too.
            $result = $api->put($resource, $data, $parameters);
        } catch (RuntimeException $e) {
            $code = $e->getCode();
            if ($code == 303) {
                // CopernicaRestAPI::put() would return true. Let's instead
                // return the contents of the Location header - the caller can
                // treat it as a 'boolean' measure of success, but could also
                // inspect the value to see if it references a new entity.
                // (There is no sense / advantage in throwing an exception
                // instead, if the URI of the entity is not the same as the one
                // we just updated. Which we very much don't expect, but which
                // theoretically could be true if we look at the code of
                // CopernicaRestAPI::sendDate() and/or the URL mentioned above.)
                $return = $this->checkResponseSeeOther($e);
                // $return is either the location header or false if we really
                // don't recognize the contents of the 303.
                if ($return !== false) {
                    return $return;
                }
            }
            $suppress = ($code > 0 && $code < 100 && $suppress_errors & self::PUT_RETURNS_CURL_ERROR)
                || ($code == 303 && $suppress_errors & self::PUT_RETURNS_STRANGE_SEE_OTHER)
                || ($code == 400 && $suppress_errors & self::PUT_RETURNS_BAD_REQUEST)
                || ((($code >= 100 && $code < 200) || ($code > 299 && !in_array($code, [303, 400])))
                    && $suppress_errors & self::PUT_RETURNS_STRANGE_HTTP_CODE);
            return $this->throwOrSuppress($e, $suppress);
        }

        // Unlike post(), we don't make assumptions about what kind of value
        // this returns. (Likely we never get here because an exception is
        // always thrown with code 303, and a value is returned from inside the
        // 'catch' block. Incidentally, that mechanism is a sign that we should
        // really pull code from CopernicaRestAPI into here to make the flow
        // more logical. Which we'll do... sometime later. For now, it works.)
        return $result;
    }

    /**
     * Executes a DELETE request.
     *
     * @param string $resource
     *   Resource (URI relative to the versioned API).
     * @param int|null $suppress_errors
     *   (Optional) suppress throwing exceptions for certain cases; see
     *   suppressApiCallErrors(). Pass self::DELETE_RETURNS_ALREADY_REMOVED
     *   if re-deleting an already deleted entity should not throw an exception.
     *
     * @return mixed
     *   True to indicate success. If errors were encountered which are
     *   suppressed: either a string containing the full response headers and
     *   body, or false if an suppressed exception has an unrecognized message
     *   (which we hope is impossible).
     *
     * @throws \RuntimeException
     *   If any non-supressed Curl error, nonstandard HTTP response code or
     *   unexpected response headers are encountered.
     *
     * @see RestClient::suppressApiCallErrors()
     */
    public function delete($resource, $suppress_errors = null)
    {
        if (!isset($suppress_errors)) {
            $suppress_errors = $this->suppressApiCallErrors;
        }

        $api = $this->getApi();
        // Make the API class throw exceptions. We can extract the headers/body
        // from the exception message if needed.
        $api->throwOnError = true;
        try {
            $result = $api->delete($resource);
        } catch (RuntimeException $e) {
            $code = $e->getCode();
            $already_removed = $code == 400 && $this->errorIsAlreadyRemoved($e);
            $suppress = ($code > 0 && $code < 100 && $suppress_errors & self::DELETE_RETURNS_CURL_ERROR)
                || ($code == 400 && $already_removed && $suppress_errors & self::DELETE_RETURNS_ALREADY_REMOVED)
                || ($code == 400 && !$already_removed && $suppress_errors & self::DELETE_RETURNS_BAD_REQUEST)
                || ((($code >= 100 && $code < 200) || ($code > 299 && $code != 400))
                    && $suppress_errors & self::DELETE_RETURNS_STRANGE_HTTP_CODE);
            return $this->throwOrSuppress($e, $suppress);
        }

        // https://www.copernica.com/en/documentation/restv2/rest-requests
        // mentions:
        // - A "X-deleted" header. This is indeed present on responses (which
        //   return HTTP code 200) for just-deleted items, and has content
        //   "ENTITY-TYPE ID" in the case we've checked. I'm not sure this adds
        //   any information (i.e. would ever not be equivalent to $resource).
        // - A "204 No Content" response in case the data that was meant to be
        //   deleted could not be located. I'm not aware of when this would
        //   apply: trying to delete an already-deleted (or nonexistent)
        //   profile results in a HTTP 400 response. So either this happens
        //   only for specific entities, or it's a remnant of v1 documentation.
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
     * @see RestClient::suppressApiCallErrors()
     * @see RestClient::getEntity()
     * @see RestClient::getEntities()
     */
    public function get($resource, array $parameters = array(), $suppress_errors = null)
    {
        // API NOTES: (strange location, may need to move to README)
        // - Curl occasionally returns error 7 "Failed to connect" (and
        //   getApi()->get() returns a string value, I assume empty string).
        // - ~Jun 2020: We've seen error 52 "Empty reply from server" - for a
        //   'subprofiles' query for a 'large' collection. This suggests
        //   timeout-like conditions are possible on the server end, which
        //   ideally should return a 4xx/5xx HTTP code instead of 'empty'.
        // - ~Oct 2020: We've seen HTTP 503 (Service Unavailable) returning a
        //   HTML body with title "Loadbalancer Error" and a header mentioning
        //   "too many requests to handle".
        // - ~Oct 2020: We've also seen HTTP 504 (gateway timeout) returning
        //   the same HTML body. This may or may not be replacing the Curl 52?
        if (!isset($suppress_errors)) {
            $suppress_errors = $this->suppressApiCallErrors;
        }

        $api = $this->getApi();
        // Make the API class throw exceptions. We can extract the body from
        // the exception message if needed; the headers are not included but
        // we don't know of an application that needs it.
        $api->throwOnError = true;
        try {
            // If the caller left $resource empty, let's preempt that; throw a
            // 400 as the API would, or return FALSE if suppressed. Do the
            // same for non-strings. Don't specify resource because the API
            // wouldn't.
            if (empty($resource) || !is_string($resource)) {
                throw new RuntimeException('Copernica API request failed: Invalid method.', 400);
            }
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
            return $this->throwOrSuppress($e, $suppress);
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
            // It would be strange if the result contained an error structure;
            // errors are usually sent in a HTTP 400 response, which is handled
            // above. But we can't be sure, from the CopericaRestAPI code which
            // does not check for HTTP 400. If this happens, an exception is
            // thrown with (very likely) code 200.
            $this->checkResultForError($result, 200);
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
     * for a set of entities, and if each individual entity is also valid (has
     * an ID value).
     *
     * The bitmask set through suppressApiCallErrors() has no effect on this
     * method because it is explicitly meant to return a set of valid entities.
     *
     * @param string $resource
     *   Resource (URI relative to the versioned API) to fetch data from.
     * @param array $parameters
     *   (Optional) parameters for the API query.
     *
     * @return array[]
     *   The 'data' part of the JSON-decoded response body, i.e. an array of
     *   (zero or more) entities.
     *
     * @throws \RuntimeException
     *   If the result metadata are not successfully verified.
     */
    public function getEntities($resource, array $parameters = array())
    {
        // We can expect that setting 'total' = false is easier on the API
        // back end, though we're not sure how much. (From some casual
        // experimenting, it seems that a decrease in API call completion time
        // is only noticeable by humans if the dataset size is multiple
        // hundreds of thousands.) The only tiny advantage is: if the returned
        // batch contains a number of items equal to the limit AND the last
        // item in the returned dataset is the last one in the full dataset
        // (i.e. if the full dataset has a number of items that is exactly a
        // multiple of the 'limit', provided we always keep 'limit' equal),
        // then we'll be forced to make an extra call which returns 0 items,
        // before we discover the dataset was already fully fetched. If
        // it's significant to prevent that for some reason, then pass 'total'
        // = true explicitly.
        $parameters += ['total' => false];
        $result = $this->get($resource, $parameters, self::NONE);

        $this->checkEntitiesMetadata($result, $parameters, 'response from Copernica API');
        foreach ($result['data'] as $entity) {
            if (empty($entity['id']) && empty($entity['ID'])) {
                throw new RuntimeException("One of the entities returned from $resource resource does not contain 'id'.", 803);
            }
        }

        return $result['data'];
    }

    /**
     * Returns the 'api connection' instance.
     *
     * @return \CopernicaApi\CopernicaRestAPI|object
     */
    private function getApi()
    {
        if (!isset($this->api)) {
            $this->setApi();
        }
        return $this->api;
    }

    /**
     * Sets an 'api connection' instance.
     *
     * This is for test classes to override - but we're making it a bit
     * tedious because we're keeping this a protected method.
     *
     * @param \CopernicaApi\CopernicaRestAPI|object $api
     */
    protected function setApi($api = null)
    {
        $this->api = isset($api) ? $api : new CopernicaRestAPI($this->token, $this->version);
    }

    /**
     * Throw exception or return value contained in exception message.
     *
     * Just some code abstracted so we don't need to duplicate it. This is only
     * meant to handle specific circumstances; see the code comments.
     *
     * @param \RuntimeException $exception
     *   An exception that was thrown.
     * @param bool $suppress
     *   If True, return something from this method (and throw away the
     *   exception code / original message). If False, throw an exception;
     *   either the same that was passed in, or one with a simpler message IF
     *   the response body in the exception message were ONLY a
     *   JSON encoded error-message structure. (If the response contents
     *   include headers, that means the error-message structure will not bve
     *   JSON decoded / simplified, because we assume the caller might want to
     *   inspect the headers too. This is currently the case for all POST / PUT
     *   requests made through CopernicaRestAPI.)
     *
     * @return string|false
     *   if $suppress = True: either the response contents (the body, with
     *   in the case of POST/PUT/DELETE, headers prepended) - or False if we
     *   cannot find the response contents.
     */
    private function throwOrSuppress(RuntimeException $exception, $suppress)
    {
        $return = $this->extractResponse($exception, true);
        if ($suppress) {
            // We'll want to either return the response contents that were
            // extracted from the message, or false (because in the latter case
            // the caller will want to distinguish the return value from values
            // returned by a non-error path), which means the exact exception
            // is obscured. (In other words, we can only use this from places
            // that never throw miscellaneous exceptions.)
            return isset($return['full']) ? $return['full'] : false;
        }
        if (isset($return['body']) && is_array($return['body'])) {
            // Supposedly this return value would have an "error" component. If
            // so, re-throw the exception with, possibly, a designated code.
            $this->checkResultForError($return['body'], $exception->getCode());
        }

        // Ignore $return; throw the original message (including the response).
        throw $exception;
    }

    /**
     * Interprets an exception with code 303, thrown in put().
     *
     * Separated out only to keep put() code a bit small. This logic is
     * specific to one situation.
     *
     * @param \RuntimeException $exception
     *   The exception, which is assumed to be of code 303 and contain full
     *   headers + body whose contents can/should be checked strictly.
     *
     * @return string
     *   The 'path' part of the "Location" header without trailing slash (i.e.
     *   no hostname or query).
     */
    private function checkResponseSeeOther(RuntimeException $exception)
    {
        // Any reason to return false is not going to be specified; we assume
        // it will result in the exception being thrown as-is, so the caller
        // can/will need to figure things out.
        $response = $this->extractResponse($exception, false, true);
        if (!$response) {
            return false;
        }
        // The body should be empty, otherwise we want to pass it (throw it) to
        // the caller for info. We're not absolutely sure if a stray newline
        // could be present - haven't tested / read the precise HTTP specs.
        if (trim($response['body'])) {
            return false;
        }
        $headers = $response['headers'];
        // We want to only have this called for 303.
        if (!preg_match('/^HTTP\/[\d\.]+ 303/', $headers[0])) {
            return false;
        }
        if (!isset($headers['location']) || is_array($headers['location'])) {
            return false;
        }
        // The 'token' argument is in the query and we don't need it. If
        // there's another argument, that means 1) somehow a 'canonical entity
        // URI' needs an argument, which would be quite strange - or 2) an API
        // change introduced another 'unneeded' argument just like token. In
        // this instance, we won't be strict - and we'll just ignore the query.
        // The path is including slash but excluding the API version. (Which is
        // apparently also a working URL - just one that is never used by
        // the API client class because it always inserts a version in paths.)
        // As per the comment in the caller, let's not check if the location
        // matches the original resource URL.
        $path = ltrim(parse_url($headers['location'], PHP_URL_PATH), '/');
        // We'd be surprised if a new entity was created as the result of a PUT
        // request, but both the remarks about creating new entities in
        // https://www.copernica.com/en/documentation/restv2/rest-requests and
        // the code of CopernicaRestAPI::sendData() suggest it may be possible.
        // So let's doublecheck it. According to sendData() it only consists of
        // digits. If it's equal to the last part of the location, we consider
        // it duplicate info we can discard. If it's different... that's so
        // strange we'll let the caller figure things out.
        if (
            isset($headers['X-Created'])
            && substr($path, -strlen($headers['X-Created'])) != $headers['X-Created']
        ) {
            return false;
        }
        return $path;
    }

    /**
     * Extracts API response / headers and body from an exception message.
     *
     * This helper function is only needed because we don't have access to the
     * Curl handle anymore.
     *
     * @param \RuntimeException $exception
     *   The response with headers and body concatenated, which we get from
     *   some Curl calls.*
     * @param bool $json_decode_body
     *   (Optional) JSON-decode the body.
     * @param bool $return_headers
     *   (Optional) return headers.
     *
     * @return array|false
     *   False if the exception message is not as we expected. Otherwise an
     *   array with 'full' (string), 'body' (optionally JSON-decoded), and
     *   optionally 'headers' (array with lowercase header names as keys;
     *   header contents as values).
     */
    private function extractResponse(RuntimeException $exception, $json_decode_body = false, $return_headers = false)
    {
        // This should always match. Assume first-to-last double quote matches
        // correctly.
        $return = preg_match('/Response contents: \"(.*)\"\./s', $exception->getMessage(), $matches);
        if ($return) {
            $return = ['full' => $matches[1]];
            if ($return['full']) {
                $parts = explode("\r\n\r\n", $return['full'], 2);
                // We assume headers may have been omitted.
                $body = isset($parts[1]) ? $parts[1] : $parts[0];
                $return['body'] = $json_decode_body ? json_decode($body, true) : $body;
                if ($return_headers && isset($parts[1])) {
                    $return['headers'] = $this->parseHeaders($parts[0]);
                }
            }
        }

        return $return;
    }

    /**
     * Parses string with headers. Stackoverflow copy-paste-modify.
     *
     * @param string $raw_headers
     *    Headers.
     *
     * @return array
     *    Headers as key-value pairs, with headers lowercased.
     */
    private function parseHeaders($raw_headers)
    {
        $headers = array();
        $previous_header = '';

        foreach (explode("\n", $raw_headers) as $header) {
            $h = explode(':', $header, 2);

            if (isset($h[1])) {
                $name = strtolower($h[0]);
                $value = trim($h[1]);
                if (!isset($headers[$name])) {
                    $headers[$name] = $value;
                } elseif (is_array($headers[$name])) {
                    $headers[$name] = array_merge($headers[$name], array($value));
                } else {
                    $headers[$name] = array_merge(array($headers[$name]), array($value));
                }

                $previous_header = $name;
            } elseif (substr($header, 0, 1) === "\t") {
                // Continue previous header.
                $headers[$previous_header] .= "\r\n\t" . trim($header);
            } else {
                // This _should_ only occur for the first line (HTTP) which
                // will be stored as key 0.
                $headers[] = trim($header);
            }
        }

        return $headers;
    }

    /**
     * Checks a data structure containing Copernica's 'list metadata'.
     *
     * This will likely keep having only one caller, but it's good to have it
     * in a separate method - not only because it makes getEntities a bit more
     * readable but also because this method has an almost verbatim copy in
     * Helper.
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
     * @todo there's one thing we're not checking: whether the top level
     *   has only 5 keys, i.e. whether there are no unknown properties, which
     *   the caller will likely throw away unseen. I'd actually like to know if
     *   Copernica introduces new properties - but I'm too scared to break
     *   existing code by throwing an unnecessary exception. Maybe... make that
     *   one optional somehow?
     * @todo negative 'start' parameter returns the negative integer in 'start'
     *   and zero count/items. Check what we want to do with that: likely not
     *   throw an exception because we want to emulate Copernica?
     */
    protected function checkEntitiesMetadata(array $struct, array $parameters, $struct_descn)
    {
        // We will throw an exception for any unexpected value. That may seem
        // way too strict but at least we'll know when something changes.
        foreach (['start', 'limit', 'count', 'total', 'data'] as $key) {
            if ($key !== 'total' || !isset($parameters['total']) || $this->isBooleanTrue($parameters['total'])) {
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
            throw new RuntimeException("Unexpected structure in $struct_descn: 'count' value (" . json_encode($struct['count']) . ") is larger than 'limit' (" . json_encode($struct['limit']) . ').', 804);
        }
        if (isset($struct['total']) && $struct['start'] + $struct['count'] > $struct['total']) {
            throw new RuntimeException("Unexpected structure in $struct_descn: 'total' property (" . json_encode($struct['total']) . ") is smaller than start (" . json_encode($struct['start']) . ") + count (" . json_encode($struct['count']) . ").", 804);
        }
    }

    /**
     * Indicates whether a value converts to True by Copernica.
     *
     * This has only been tested for values of the 'total' parameter so far, so
     * it stays private until we have more use / more evidence of this
     * conversion being uniformly applicable.
     */
    private function isBooleanTrue($value)
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
     * Checks if an exception contains an "already removed" message.
     *
     * @param \RuntimeException $exception
     *   The exception.
     *
     * @return bool
     *   True if the exception contains an "already removed" message.
     */
    protected function errorIsAlreadyRemoved(RuntimeException $exception)
    {
        $return = $this->extractResponse($exception, true);
        if ($return) {
            $return = isset($return['body']['error']['message'])
                && is_string($return['body']['error']['message'])
                // Here's where we just need to hope there's no other exotic
                // error message we'd want to match. The only ones we've seen
                // so far are ("This <entity> has already been removed" for
                // profile & subprofile. The rest are 'just to be sure'.
                && (
                    strpos($return['body']['error']['message'], ' has already been removed')
                    || strpos($return['body']['error']['message'], ' has already been deleted')
                    || strpos($return['body']['error']['message'], 's already removed')
                    || strpos($return['body']['error']['message'], 's already deleted')
                );
        }

        return $return;
    }

    /**
     * Checks a result array from a GET request for an 'error' value.
     *
     * @param array $result
     *   The JSON-decoded response body from a GET query.
     * @param int $code
     *   The error code that should be used for the exception if this method
     *   does not decide it has a dedicated code for this error.
     *
     * @throws \RuntimeException
     *   If an 'error' value is found.
     */
    private function checkResultForError(array $result, $code)
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
                        $code = 801;
                        break;
                }
                throw new RuntimeException('Copernica API request failed: ' . $result['error']['message'], $code);
            }
            // If reality differs from the above, we'll output the full array
            // as the (json encoded) message. In that case, hopefully someone
            // will find their way back here and refine this part of the code.
            throw new RuntimeException('Copernica API request got unexpected response: ' . json_encode($result), $code);
        }
    }
}

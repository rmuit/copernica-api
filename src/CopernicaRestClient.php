<?php

namespace CopernicaApi;

use LogicException;
use RuntimeException;

/**
 * REST API Client for Copernica.
 */
class CopernicaRestClient
{
    // @TODO define constant which means 'defaults for v2.0". Set as default.
    /**
     * Equivalent to "none", if one likes using constants instead of integers.
     *
     * Usable with suppressApiCallErrors() / a relevant argument to method
     * calls; use this value to always throw exceptions (i.e. suppress none) in
     * real or perceived error situations - thereby ensuring that values
     * returned from calls are always valid and usable.
     *
     * For non-GET calls, this not ensured yet; future versions of this class
     * may throw exceptions in more cases, to make it so. See
     * suppressApiCallErrors() for details.
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
     * Represents a HTTP GET call resulting in a Curl error. (No similar value
     * exists yet for non-GET calls; it will likely be introduced as errors are
     * observed in practice.)
     */
    const CURL_ERROR_GET = 1;

    /**
     * Value to use for method argument / suppressApiCallErrors() as a bitmask:
     *
     * Represents a REST API GET endpoint returning a response of type
     * "application/json" whose body cannot be decoded as JSON. (This is
     * the only case where the standard CopernicaRestAPI class threw an
     * exception.) If suppressed, this will return the non-decoded contents.
     */
    const GET_RETURNS_INVALID_JSON = 2;

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
    const GET_RETURNS_NON_ARRAY = 4;

    /**
     * Value to use for method argument / suppressApiCallErrors() as a bitmask:
     *
     * Represents a REST API GET endpoint returning a response body containing
     * valid JSON containing an "error" entry. By default, an exception will
     * be thrown, specifying the accompanying error message.
     */
    const GET_RETURNS_ERROR_MESSAGE = 8;

    /**
     * Value to use for method argument / suppressApiCallErrors() as a bitmask:
     *
     * Represents a REST API GET endpoint returning a response body containing
     * valid JSON containing an 'entity' whose "removed" property is not false.
     */
    const GET_ENTITY_IS_REMOVED = 16;

    /**
     * Value to use for method argument / suppressApiCallErrors() as a bitmask:
     *
     * Represents a call to CopernicaRestAPI::post() returning false, which
     * points to a HTTP 400 "bad request" being returned - or no HTTP response
     * code being available.
     */
    const POST_RETURNS_FALSE = 32;

    /**
     * Value to use for method argument / suppressApiCallErrors() as a bitmask:
     *
     * Represents a call to CopernicaRestAPI::post() returning true rather than
     * an ID. (It seems likely that very many HTTP POST calls are always
     * supposed to return an "X-Created:" header with an ID value, and absence
     * of this indicates some kind of error.)
     *
     * This class does not (yet?) know the particulars of every single call, so
     * throwing exceptions is suppressed by default for these cases, for fear
     * of causing unwanted exceptions. Code which knows that a HTTP POST call
     * must return an ID, is encouraged to negate this behavior by unsetting
     * this bit or passing the appropriate value (e.g. NONE) to the third
     * argument of post() calls.
     *
     * Note the naming suggests that this might also cover post() returning
     * false, but that isn't the case.
     */
    const POST_RETURNS_NO_ID = 64;

    /**
     * Value to use for method argument / suppressApiCallErrors() as a bitmask:
     *
     * Represents a call to CopernicaRestAPI::put() returning false, which
     * points to a HTTP 400 "bad request" being returned - or no HTTP response
     * code being available.
     */
    const PUT_RETURNS_FALSE = 128;

    /**
     * Indicates whether API calls may throw exceptions in error situations.
     */
    protected $suppressApiCallErrors = self::POST_RETURNS_NO_ID;

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
     * Error handling in this class is imperfect. Our ideal is to have the code
     * throw exceptions for any 'error' returned from the server / encountered
     * during API communication, so calling code can assume everything is OK on
     * its regular execution path. That is currently not possible because
     * - We don't have full detailed info/documentation about error conditions;
     *   https://www.copernica.com/en/documentation/restv2/rest-requests
     *   documents some/most behavior but the standard CopernicaRestAPI class'
     *   code suggests some extra behavior which we don't have full insight in.
     * - The standard class doesn't inspire the utmost confidence that it's
     *   taking care of errors.
     * We so far have observed a few errors in the wild, which are noted in
     * code comments of the relevant functions (marked "API NOTE");
     *
     * So... in order to achieve our ideal / ensure errors don't go unnoticed
     * and code doesn't have to perform its own unspecified checks, we may
     * gradually start throwing more exceptions for fringe cases as we
     * encounter them. Those exceptions however will introduce behavior that
     * is incompatible with the current situation. Code that prefers having a
     * very consistent situation in throwing errors -at the cost of being less
     * sure that returned values truly indicate success- may set this property.
     *
     * In addition, sometimes the question of what constitutes a real error is
     * dependent on application logic. (Example: a deleted entity gets
     * re-deleted with a consecutive API call and the API returns HTTP 400 the
     * second time.) In these cases, while we could always throw an exception,
     * it makes sense for the caller to not always have to deal with catching
     * those.
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
        if (!isset($suppress_errors)) {
            $suppress_errors = $this->suppressApiCallErrors;
        }

        // This code makes assumptions about detailed behavior of the below
        // call to post() because that's the only way to work with the default
        // CopernicaRestAPI class effectively. The exception messages show the
        // need for better error handling, probably by patching
        // CopernicaRestAPI to recognize HTTP response codes and Curl errors.
        // The reason we haven't done this yet is... we haven't observed many
        // of these situations yet. Which is a sign of the stability of
        // Coprenica's API servers.
        $result = $this->getApiConnection()->post($resource, $data);
        if ($result === false && !($suppress_errors && self::POST_RETURNS_FALSE)) {
            // API NOTE:  One error we've seen when POSTing data to one
            // Copernica db (but not another one) was that addresses@126.com
            // were disallowed. If you try to enter them in the UI, you get a
            // message "spambots". This REST call will return no indicative
            // message headers and a body {"error":{"message":"Failed to create
            // profile"}} - which implies we should get the message body in
            // CopernicaRestAPI::post() rather than returning false. That's a
            // TODO; I'm not doing it yet because above message isn't
            // descriptive anyway. So I'd want to see more practical situations
            //  before changing CopernicaRestAPI code.
            throw new RuntimeException("put() returned false (likely POST $resource returned HTTP 400); reason unknown.", 1);
        }
        if ($result === true && !($suppress_errors && self::POST_RETURNS_NO_ID)) {
            throw new RuntimeException("POST $resource returned no 'X-Created: ID' header in the call, which likely points to a HTTP failure response being returned, or the connection being unsuccessful. Details are unavailable.", 2);
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
     *   any PUT call returning an ID yet. False is only returned for
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

        // This code makes assumptions about detailed behavior of the below
        // call to put() because that's the only way to work with the default
        // CopernicaRestAPI class effectively.
        $result = $this->getApiConnection()->put($resource, $data, $parameters);
        if ($result === false && !($suppress_errors && self::PUT_RETURNS_FALSE)) {
            throw new RuntimeException("put() returned false (likely PUT $resource returned HTTP 400); reason unknown.", 1);
        }
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
     *   ?
     *
     * @see CopernicaRestClient::suppressApiCallErrors()
     *
     * @todo Implement handling of HTTP 204, check "X-deleted" header. See
     *   https://www.copernica.com/en/documentation/restv2/rest-requests.
     */
    public function delete($resource, $suppress_errors = null)
    {
        return $this->getApiConnection()->delete($resource);
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
        if (!isset($suppress_errors)) {
            $suppress_errors = $this->suppressApiCallErrors;
        }

        // This code makes assumptions about detailed behavior of the below
        // call to get() because that's the only way to work with the default
        // CopernicaRestAPI class effectively.
        try {
            $result = $this->getApiConnection()->get($resource, $parameters);
        } catch (RuntimeException $e) {
            // Code 0 is one specific error, indicating invalid JSON. (This is
            // the only situation in the original CopernicaRestAPI class that
            // threw an exception.) Others are Curl errors.
            if ($e->getCode()) {
                // API NOTES:
                // - Curl occasionally returns error 7 "Failed to connect" (and
                //   get() returns a string value, likely empty string, though
                //   we're not sure yet)
                // - We've also seen error 52 "Empty reply from server" - for
                //   a 'subprofiles' query for a 'large' collection. This
                //   suggests timeout-like conditions are possible on the
                //   server end, which ideally should return a 4xx/5xx HTTP
                //   code instead of nothing at all.
                // We've patched the CopernicaRestAPI class to throw an
                // exception for Curl errors. The following suppression would
                // fall back to the situation before the patch: return the
                // original response contents. (Which is probably nothing,
                // since we got a Curl error, but we're not absolutely sure of
                // that.)
                if (!($suppress_errors && self::CURL_ERROR_GET)) {
                    throw $e;
                }
                if (preg_match('/Response contents: \"(.*?)\"/', $e->getMessage(), $matches)) {
                    return $matches[1];
                }
                // This situation likely cannot happen.
                return '';
            } elseif (!($suppress_errors && self::GET_RETURNS_INVALID_JSON)) {
                throw $e;
            }
            // The caller doesn't want invalid JSON to throw an exception, so
            // we try to return the original input unchecked.
            if (preg_match('/^Unexpected input: (.*)$/', $e->getMessage(), $matches)) {
                return $matches[1];
            }
            // This situation likely cannot happen.
            return '';
        }

        // Either the response had content type "application/json" and is valid
        // JSON; we got the decoded JSON. Or the response did not have content
        // type "application/json"; we got the literal string. This means it's
        // impossible to distinguish JSON encoded strings from other strings.
        // In the default situation this doesn't matter to us because we only
        // accept array values.
        if (!is_array($result)) {
            if (
                // Some paths, hardcoded here, are always allowed to return strings.
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
            throw new RuntimeException("Entity returned from $resource call does not contain 'id'.");
        }

        // We do not know for sure but have seen this with profiles, so for now
        // will assume it's with every entity: 'removed' indicates the date
        // when the item was removed. the 'fields' array still contains all
        // fields, but with empty string as values.
        if (!isset($suppress_errors)) {
            $suppress_errors = $this->suppressApiCallErrors;
        }
        if (!empty($result['removed']) && !($suppress_errors && self::GET_ENTITY_IS_REMOVED)) {
            // We don't have a 'code space' yet and will just add our own
            // codes. If Copernica ever gets error codes, we should just create
            // a new class that responds with their codes.
            throw new RuntimeException("Entity returned from $resource call was removed {$result['removed']}.", 112);
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
            throw new RuntimeException("Unexpected structure in response from Copernica API: 'total' property (" . json_encode($result['total']) . ") is smaller than next start pointer (" . json_encode($this->nextEntitiesDatasetStart) . ").");
        }

        foreach ($result['data'] as $entity) {
            if (empty($entity['id']) && empty($entity['ID'])) {
                throw new RuntimeException("One of the entities returned from $resource call does not contain 'id'.");
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
            // This is unexpected; we don't know how embedded entities implement
            // paging and until we do, we disallow this.
            throw new RuntimeException("List of entities inside '$property_name' property starts at {}; we cannot handle anything not starting at 0.");
        }
        if ($throw_if_incomplete && $entity[$property_name]['count'] !== $entity[$property_name]['total']) {
            throw new RuntimeException("Cannot return the total list of {$entity[$property_name]['totel']} entities inside '$property_name' property; only {$entity[$property_name]['count']} found.");
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
                throw new RuntimeException("Unexpected structure in $struct_descn: no '$key' value found.'");
            }
            if ($key !== 'data' && !is_numeric($struct[$key])) {
                throw new RuntimeException("Unexpected structure in $struct_descn: '$key' value (" . json_encode($struct[$key]) . ') is non-numeric.');
            }
        }
        if (!is_array($struct['data'])) {
            throw new RuntimeException("Unexpected structure in $struct_descn: 'data' value is not an array(" . json_encode($struct['count']) . ').');
        }
        // Regardless of the paging stuff, 'count' should always be equal to
        // count of data.
        if ($struct['count'] !== count($struct['data'])) {
            throw new RuntimeException("Unexpected structure in $struct_descn: 'count' value (" . json_encode($struct['count']) . ") is not equal to number of array values in 'data' (" . count($struct['data']) . ').');
        }

        $expected_start = isset($parameters['start']) ? $parameters['start'] : 0;
        if ($struct['start'] !== $expected_start) {
            throw new RuntimeException("Unexpected structure in $struct_descn: 'start' value is " . json_encode($struct['start']) . ' but is expected to be 0.');
        }
        // We expect the count of data fetched to be exactly equal to the
        // limit, unless we've fetched all data - then the count can be less.
        if ($struct['start'] + $struct['count'] == $struct['total']) {
            if ($struct['count'] > $struct['limit']) {
                throw new RuntimeException("Unexpected structure in response from Copernica API: 'count' value (" . json_encode($struct['count']) . ") is not equal to 'limit' (" . json_encode($struct['limit']) . ').');
            }
        } elseif ($struct['count'] !== $struct['limit']) {
            throw new RuntimeException("Unexpected structure in response from Copernica API: 'count' value (" . json_encode($struct['count']) . ") is not equal to 'limit' (" . json_encode($struct['limit']) . '), which should be the case when we only fetched part of the result.');
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
                // We don't have a 'code space' yet and will just add our own
                // codes. If Copernica ever gets error codes, we should create
                // a new class that responds with their codes. Note we use 112
                // for "removed" in getEntity(), which is not a Copernica error.
                switch ($result['error']['message']) {
                    case 'No entity with supplied ID':
                        $code = 110;
                        break;

                    case 'Invalid access token':
                        $code = 120;
                        break;

                    default:
                        $code = 0;
                }
                throw new RuntimeException('Copernica API call failed: ' . $result['error']['message'], $code);
            }
            // If reality differs from the above, we'll output the full array
            // as the (json encoded) message. In that case, hopefully someone
            // will find their way back here and refine this part of the code.
            throw new RuntimeException('Copernica API call returned unexpected result: ' . json_encode($result));
        }
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

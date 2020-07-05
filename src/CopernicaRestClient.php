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
     * Executes a GET request.
     *
     * Preferably call one of the wrapper functions instead.
     *
     * @param string $resource
     *   Resource (URI relative to the versioned API) to fetch data from.
     * @param array $parameters
     *   (Optional) parameters for the API query.
     *
     * @return array
     *   The JSON-decoded response body.
     *
     * @see getData()
     * @see getEntity()
     * @see getEntities()
     */
    public function get($resource, array $parameters = array())
    {
        // Below get() call throws a RuntimeException if a Curl error was
        // encountered or if the response indicated application/json but the
        // body did not contain JSON. A response is returned if:
        // - Either the response was marked as content type application/json
        //   and is valid JSON; in this case we get the decoded JSON returned.
        // - Or the response was not marked as content type application/json;
        //   in this case we get the literal string returned.
        // This means it's impossible to distinguish JSON encoded strings from
        // strings. Since we at this moment do not know any API endpoint that
        // is meant to return either, we throw an exception in this case. If
        // a plain string is ever seen to be an acceptable return value, that
        // will be cause for changing this code - and in the meantime, callers
        // can call CopernicaRestAPI::get() directly instead, to circumvent the
        // RuntimeException.
        $response = $this->getApiConnection()->get($resource, $parameters);
        if (is_string($response)) {
            throw new RuntimeException("Response is a string (or was a JSON encoded string): \"$response\".");
        }

        return $response;
    }

    /**
     * Executes a POST request.
     *
     * PLEASE NOTE: we throw a RuntimeException when the return value seems to
     * indicate error, mainly for forward compatibility reasons (so we can
     * throw varying RuntimeExceptions after we figure out how to differentiate
     * various errors). For now, there is only one type of error "reason
     * unknown" with code 1. Callers that want False returned instead, like the
     * original CopernicaRestAPI class does, should call sendData().
     *
     * @param string $resource
     *   Resource (URI relative to the versioned API) to send data to.
     * @param array $data
     *   (Optional) data to send.
     *
     * @return mixed
     *   ID of created entity, or simply true to indicate success.
     *
     * @throws \RuntimeException
     *   If the wrapped class returns False.
     */
    public function post($resource, array $data = array())
    {
        $result = $this->getApiConnection()->post($resource, $data);
        if ($result === false) {
            throw new RuntimeException("POST request returned False; reason unknown.", 1);
        }
        return $result;
    }

    /**
     * Executes a PUT request.
     *
     * PLEASE NOTE: we throw a RuntimeException when the return value seems to
     * indicate error, mainly for forward compatibility reasons (so we can
     * throw varying RuntimeExceptions after we figure out how to differentiate
     * various errors). For now, there is only one type of error "reason
     * unknown" with code 1. Callers that want False returned instead, like the
     * original CopernicaRestAPI class does, should call sendData().
     *
     * @param string $resource
     *   Resource (URI relative to the versioned API) to send data to.
     * @param array $data
     *   Data to send.
     * @param array $parameters
     *   (Optional) parameters for the API query.
     *
     * @return mixed
     *   ID of created entity, or simply true to indicate success.
     *
     * @throws \RuntimeException
     *   If the wrapped class returns False.
     */
    public function put($resource, array $data, array $parameters = array())
    {
        $result = $this->getApiConnection()->put($resource, $data, $parameters);
        if ($result === false) {
            throw new RuntimeException("PUT request returned False; reason unknown.", 1);
        }
        return $result;
    }

    /**
     * Executes a (PUT/POST) request to create/edit data.
     *
     * @param string $resource
     *   Resource (URI relative to the versioned API) to send data to.
     * @param array $data
     *   (Optional) data to send.
     * @param array $parameters
     *   (Optional) parameters for the API query.
     * @param bool $put
     *   (Optional) if True, use PUT method (which is typically used for
     *   updating data). By default/if False, POST (create) is used.
     *
     * @return mixed
     *   ID of created entity, or simply true/false to indicate success/failure.
     */
    public function sendData($resource, array $data = array(), array $parameters = array(), $put = false)
    {
        // Force some compatibility in case someone is porting code from the
        // CopernicaRestAPI class and still sending a string.
        $method = is_string($put) ? $put : ($put ? 'PUT' : 'POST');
        // API NOTE: Returning False for errors of course isn't very verbose.
        // One error we've seen when POSTing data to Copernica's db (only their
        // live db, not test) was that addresses@126.com were disallowed. If
        // you try to enter them in the UI, you get a message "spambots". This
        // REST call will return no indicative message headers and a body
        // {"error":{"message":"Failed to create profile"}} - so this means we
        // can throw an exception with this message. That's a TODO; I'm not
        // doing it yet because
        // - The decision to start throwing exceptions, while probably right
        //   for POST/PUT calls, has implications for code, so I'd ideally only
        //   change things once, to something that is exactly right;
        // - Above message isn't descriptive anyway. So I'd want to see more
        //   practical situations before changing code.
        // - There could be an API endpoint that just returns the value False.
        //   (Which currently would make CopernicaRestAPI::sendData() return
        //   True, which would be strange, but... hey, it's not impossible.)
        return $this->getApiConnection()->sendData($resource, $data, $parameters, $method);
    }

    /**
     * Executes a DELETE request.
     *
     * PLEASE NOTE: We may at some point decide to break compatibility re.
     * error handling, and start throwing RuntimeExceptions (once we know more
     * about error codes). Handling (Runtime)Exceptions might aid compatibility
     * with future versions of this library.
     *
     * @param string $resource
     *   Resource (URI relative to the versioned API).
     *
     * @return bool
     *   ?
     */
    public function delete($resource)
    {
        return $this->getApiConnection()->delete($resource);
    }

    /**
     * Executes a GET request; returns a response that does not include 'error'.
     *
     * This method checks if the returned response contains an 'error' value,
     * which any code that calls get() would likely need to do by itself.
     *
     * @param string $resource
     *   Resource (URI relative to the versioned API) to fetch data from.
     * @param array $parameters
     *   (Optional) parameters for the API query. (It isn't clear that there's
     *   any call that actually needs this.)
     *
     * @return array
     *   The JSON-decoded response body (that does not have an 'error' value).
     *
     * @throws \RuntimeException
     *   If the get() call threw a RuntimeException, or if the returned
     *   response includes an 'error' value. See checkResultForError() for
     *   error codes.
     */
    public function getData($resource, array $parameters = array())
    {
        $result = $this->get($resource, $parameters);
        $this->checkResultForError($result);
        return $result;
    }

    /**
     * Executes a GET request that returns a single entity.
     *
     * This method checks if the returned response contains an ID value, or if
     * the entity has been removed (because the REST API still returns a
     * response for removed entities), which any code that calls get() /
     * getData() would likely need to do by itself.
     *
     * @param string $resource
     *   Resource (URI relative to the versioned API) to fetch data from.
     * @param array $parameters
     *   (Optional) parameters for the API query. (It isn't clear that there's
     *   any call that actually needs this.)
     * @param bool $throw_if_removed
     *   (Optional) if false, do not throw an exception if the entity was
     *   removed, but return the API response.
     *
     * @return array
     *   The JSON-decoded response body.
     *
     * @throws \RuntimeException
     *   If the get() call threw a RuntimeException, if the entity was removed
     *   (code 112), if the entity structure is invalid or if the returned
     *   response includes an 'error' value. See checkResultForError() for
     *   error codes.
     */
    public function getEntity($resource, array $parameters = array(), $throw_if_removed = true)
    {
        $result = $this->get($resource, $parameters);
        $this->checkResultForError($result);
        // Some of the calls (like publisher/documents) have 'id' property;
        // some (like profiles, collections) have 'ID' property.
        if (empty($result['id']) && empty($result['ID'])) {
            throw new RuntimeException("Entity returned from $resource call does not contain 'id'.");
        }
        // We do not know for sure but have seen this with profiles, so for now
        // will assume it's with every entity: 'removed' indicates the date
        // when the item was removed. the 'fields' array is empty.
        if ($throw_if_removed && !empty($result['removed'])) {
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
     * @param string $resource
     *   Resource (URI relative to the versioned API) to fetch data from.
     * @param array $parameters
     *   (Optional) parameters for the API query.
     *
     * @return array[]
     *   The 'data' part of the JSON-decoded response body, i.e. an array of
     *   entities.
     */
    public function getEntities($resource, array $parameters = array())
    {
        $result = $this->get($resource, $parameters);
        $this->lastEntitiesResource = $resource;
        $this->lastEntitiesParameters = $parameters;

        $data = $this->unwrapEntitiesResult($result, $parameters);
        foreach ($data as $result) {
            if (empty($result['id']) && empty($result['ID'])) {
                throw new RuntimeException("One of the entities returned from $resource call does not contain 'id'.");
            }
        }

        return $data;
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
        $this->lastEntitiesResource = $state['last_resource'];
        $this->lastEntitiesParameters = $state['last_parameters'];
        $this->lastEntitiesDatasetTotal = $state['last_total'];
        $this->nextEntitiesDatasetStart = $state['next_start'];
        // We don't know if the currenet class has the same token/version.
        unset($this->api);
        if (isset($state['token'])) {
            $this->token = $state['token'];
        }
        if (isset($state['version'])) {
            $this->version = $state['version'];
        }
    }

    /**
     * Unwraps a result array from a GET request that contains 'entities'.
     *
     * Calling this method influences the next call to getEntitiesNextBatch(),
     * so it's doubtful that this is safe to call from anywhere except
     * getEntities().
     *
     * @param array $result
     *   The JSON-decoded response body from a GET query.
     * @param array $parameters
     *   The parameters for the query returning this result. These are used to
     *   doublecheck some result properties.
     *
     * @return array
     *   The 'data' part of the JSON-decoded response body, if the metadata
     *   are successfully verified.
     *
     * @throws \RuntimeException
     *   If the result metadata are not successfully verified.
     */
    private function unwrapEntitiesResult(array $result, array $parameters)
    {
        $this->checkResultForError($result);
        // We will throw an exception for any unexpected value. That may seem
        // way too strict but at least we'll know when something changes.
        foreach (['start', 'limit', 'count', 'total', 'data'] as $key) {
            if (!isset($result[$key])) {
                throw new RuntimeException("Unexpected structure in response from Copernica API: no '$key' value found.'");
            }
            if ($key !== 'data' && !is_numeric($result[$key])) {
                throw new RuntimeException("Unexpected structure in response from Copernica API: '$key' value (" . json_encode($result[$key]) . ') is non-numeric.');
            }
        }
        if (!is_array($result['data'])) {
            throw new RuntimeException("Unexpected structure in response from Copernica API: 'data' value is not an array(" . json_encode($result['count']) . ').');
        }

        // IF there is no paging going on for this response, then start == 0...
        if (!isset($parameters['start']) && $result['start'] !== 0) {
            throw new RuntimeException("Unexpected structure in response from Copernica API: 'start' value is " . json_encode($result['start']) . ' but is expected to be 0.');
        }
        if (isset($parameters['start']) && $result['start'] !== $parameters['start']) {
            throw new RuntimeException("Unexpected structure in response from Copernica API: 'start' value is " . json_encode($result['start']) . ' but is expected to be ' . $parameters['start'] . '.');
        }
        $this->lastEntitiesDatasetTotal = $result['total'];
        // Remember 'assumed total fetched, according to the start parameter'.
        $this->nextEntitiesDatasetStart = $result['start'] + $result['count'];
        if ($this->nextEntitiesDatasetStart > $this->lastEntitiesDatasetTotal) {
            throw new RuntimeException("Unexpected structure in response from Copernica API: 'total' property (" . json_encode($result['total']) . ") is smaller than next start pointer (" . json_encode($this->nextEntitiesDatasetStart) . ").");
        }
        // We expect the count of data fetched to be exactly equal to the
        // limit, unless we've fetched all data - then the count can be less.
        if ($this->nextEntitiesDatasetStart == $this->lastEntitiesDatasetTotal) {
            if ($result['count'] > $result['limit']) {
                throw new RuntimeException("Unexpected structure in response from Copernica API: 'count' value (" . json_encode($result['count']) . ") is not equal to 'limit' (" . json_encode($result['limit']) . ').');
            }
        } elseif ($result['count'] !== $result['limit']) {
            throw new RuntimeException("Unexpected structure in response from Copernica API: 'count' value (" . json_encode($result['count']) . ") is not equal to 'limit' (" . json_encode($result['limit']) . '), which should be the case when we only fetched part of the result.');
        }
        // Regardless of the paging stuff, 'count' should always be equal to
        // count of data.
        if ($result['count'] !== count($result['data'])) {
            throw new RuntimeException("Unexpected structure in response from Copernica API: 'count' value (" . json_encode($result['count']) . ") is not equal to number of array values in 'data' (" . count($result['data']) . ').');
        }

        return $result['data'];
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

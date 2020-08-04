<?php

namespace CopernicaApi;

/**
 *  This helper class was made for the Copernica REST API and will 
 *  handle GET, POST, PUT and DELETE calls. It can be used for both 
 *  version 1 and the newer, more consistent version 2.
 */
class CopernicaRestAPI
{
    /**
     *  Set to True to throw exceptions on Curl errors / strange HTTP codes.
     *
     *  Ideally we'd always do this in the future once we establish this has
     *  no downsides.
     *
     *  @var bool
     */
    public $throwOnError;

    /**
     *  The access token
     *  @var string
     */
    private $token;

    /**
     *  The version of the REST API
     */
    private $version;

    /**
     *  The API host
     */
    private $host = 'https://api.copernica.com';

    /**
     *  Constructor
     *  @param  string      Access token
     *  @param  int         Version parameter (optional)
     */
    public function __construct($token, $version = 2)
    {
        // copy the token
        $this->token = $token;

        // set the version
        $this->version = $version;
    }

    /**
     * Converts parameters into an encoded string.
     *
     * An example of passing the 'fields' parameter into this function:
     * ['fields' => ['land==netherlands', 'age>16']]
     *
     * @param array $parameters
     *   An array with key-value pairs, where the value can also be an array. In
     *   the latter case, the key will be present multiple time in the resulting
     *   query string, as 'key[]'.
     *
     * @return string
     *   The encoded URL parameters, including a leading '?'.
     */
    private function encodeParams(array $parameters)
    {
        $encoded_parts = [];

        foreach ($parameters as $key => $value) {
            if (is_array($value)) {
                // This will come out as key[]=subvalue1&key[]=subvalue2; the
                // keys inside $value are ignored. The important thing here is
                // that the [] are NOT URL-encoded.
                foreach ($value as $subvalue) {
                    // Testing with a value containing spaces suggests that both
                    // urlencode() and rawurlencode() are good for Copernica.
                    $encoded_parts[] = rawurlencode($key) . '[]=' . rawurlencode($subvalue);
                }
            } else {
                $encoded_parts[] = rawurlencode($key) . '=' . rawurlencode($value);
            }
        }

        return $encoded_parts ? '?' . implode('&', $encoded_parts) : '';
    }

    /**
     *  Execute Curl handle, check for errors, throw exception if applicable.
     *
     *  @param  object    Curl resource object
     *  @param  string    HTTP method, just for logging.
     *  @param  string    Resource path, just for logging.
     *  @param  array     HTTP response codes that must not cause an exception.
     *  @return string    The HTTP response body, possibly with headers
     *                    prepended, depending on CURLOPT_HEADER being set.
     */
    private function curlExec($curl, $method, $resource, $allowed_http_codes = array())
    {
        $answer = curl_exec($curl);

        // Check for errors. We assume any non-2xx HTTP code indicates some
        // situation that we don't just want to let pass silently. This is a
        // departure from the original code and will require a caller to
        // perform extra checks in certain cases, e.g. HTTP 400 being returned
        // by a DELETE call that re-deletes an already deleted resource.
        if ($this->throwOnError) {
            $curl_errno = curl_errno($curl);
            $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            if ($curl_errno) {
                $curl_error = curl_error($curl);
                // We say "contents" not "body", because it may include headers.
                throw new \RuntimeException("CURL returned code $curl_errno (\"$curl_error\") for $method $resource. Response contents: \"$answer\".", $curl_errno);
            } elseif (($httpCode < 200 || $httpCode >= 300) && !in_array($httpCode, $allowed_http_codes)) {
                // It's possible that we're throwing exceptions when we should
                // not be, for e.g. some 3xx codes. But if we don't know what
                // specific action to take in those cases, we should not just
                // let those responses pass through (given that we don't
                // return the HTTP code or headers to the caller).
                throw new \RuntimeException("$method $resource returned HTTP code $httpCode. Response contents: \"$answer\".", $httpCode);
            }
        }

        return $answer;
    }

    /**
     *  Do a GET request
     * 
     *  @param  string      Resource to fetch
     *  @param  array       Associative array with additional parameters
     *  @return array       Associative array with the result
     */
    public function get($resource, array $parameters = array())
    {
        // construct curl resource
        $parameters['access_token'] = $this->token;
        $curl = curl_init("{$this->host}/v{$this->version}/$resource" . $this->encodeParams($parameters));

        // additional options
        curl_setopt_array($curl, array(CURLOPT_RETURNTRANSFER => true));

        // do the call
        $answer = $this->curlExec($curl, 'GET', $resource);

        // do we have a JSON output? we can be nice and parse it for the user
        if (curl_getinfo($curl, CURLINFO_CONTENT_TYPE) == 'application/json') {

            // the JSON parsed output
            $jsonOut = json_decode($answer, true);

            // if we have a json error then we have some garbage in the out
            if (json_last_error() != JSON_ERROR_NONE) throw new \RuntimeException('Unexpected input: '.$answer);

            // return the json
            return $jsonOut;
        }

        // clean up curl resource
        curl_close($curl);

        // it's not JSON so we out it just like that
        return $answer;
    }

    /**
     *  Execute a POST request.
     *
     *  @param  string          Resource name
     *  @param  array           Associative array with data to post
     *
     *  @return mixed           ID of created entity, or simply true/false
     *                          to indicate success or failure
     */
    public function post($resource, array $data = array())
    {
        // Pass the request on
        return $this->sendData($resource, $data, array(), "POST");
    }

    /**
     *  Execute a PUT request.
     *
     *  @param  string          Resource name
     *  @param  array           Associative array with data to post
     *  @param  array           Associative array with additional parameters
     *
     *  @return mixed           ID of created entity, or simply true/false
     *                          to indicate success or failure
     */
    public function put($resource, $data, array $parameters = array())
    {
        // Pass the request on
        return $this->sendData($resource, $data, $parameters, "PUT");
    }

    /**
     *  Execute a request to create/edit data. (PUT + POST)
     *
     *  @param  string          Resource name
     *  @param  array           Associative array with data to post
     *  @param  array           Associative array with additional parameters
     *  @param  string          Method to use (POST or PUT)
     *
     *  @return mixed           ID of created entity, or simply true/false
     *                          to indicate success or failure
     */
    public function sendData($resource, array $data = array(), array $parameters = array(), $method = "POST")
    {
        // construct curl resource
        $parameters['access_token'] = $this->token;
        $curl = curl_init("{$this->host}/v{$this->version}/$resource" . $this->encodeParams($parameters));

        // data will be json encoded
        $data = json_encode($data);

        // set the options for a POST method
        if ($method == "POST") $options = array(
            CURLOPT_POST            =>  true,
            CURLOPT_HEADER          =>  true,
            CURLOPT_RETURNTRANSFER  =>  true,
            CURLOPT_HTTPHEADER      =>  array('content-type: application/json'),
            CURLOPT_POSTFIELDS      =>  $data
        );
        // set the options for a PUT method
        else $options = array(
            CURLOPT_CUSTOMREQUEST   =>  'PUT',
            CURLOPT_HEADER          =>  true,
            CURLOPT_RETURNTRANSFER  =>  true,
            CURLOPT_HTTPHEADER      =>  array('content-type: application/json', 'content-length: '.strlen($data)),
            CURLOPT_POSTFIELDS      =>  $data
        );

        // additional options
        curl_setopt_array($curl, $options);

        // execute the call
        $answer = $this->curlExec($curl, $method, $resource);
        
        // retrieve the HTTP status code
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

        // clean up curl resource
        curl_close($curl);

        // Bad request or (likely) Curl error. Note this does not apply if
        // $this->throwOnError is true.
        if (!$httpCode || $httpCode == 400) return false;

        // try and get the X-Created id from the header
        // if we have none we just return true for a succesful request
        if (!preg_match('/X-Created:\s?(\d+)/i', $answer, $matches)) return true;

        // return the id of the created item
        return $matches[1];
    }

    /**
     *  Execute a DELETE request
     * 
     *  @param  string      Resource name
     *  @return bool        Success?
     */
    public function delete($resource)
    {
        // the query string
        $query = http_build_query(array('access_token' => $this->token));

        // construct curl resource
        $curl = curl_init("{$this->host}/v{$this->version}/$resource?$query");

        // additional options
        curl_setopt_array($curl, array(
            CURLOPT_CUSTOMREQUEST   =>  'DELETE'
        ));

        // do the call
        $answer = $this->curlExec($curl, 'DELETE', $resource);

        // clean up curl resource
        curl_close($curl);

        // done
        return $answer;
    }
}

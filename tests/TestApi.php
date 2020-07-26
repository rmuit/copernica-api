<?php

namespace CopernicaApi\Tests;

use DateTime;
use DateTimeZone;
use Exception;
use InvalidArgumentException;
use PDO;
use LogicException;
use RuntimeException;
use UnexpectedValueException;

/**
 * Fake CopernicaRestAPI class, usable by tests.
 *
 * This class follows the (unofficial, undefined) 'interface' of
 * CopernicaRestAPI so it can be substituted in code that normally uses
 * CopernicaRestAPI, to test said code. It stores/updates/retrieves data in its
 * own storage, rather than doing API calls.
 *
 * There's a caveat: the constructor is different and the token/version
 * arguments are not used. This is solved by using a factory class
 * (TestApiFactory) which is usable by at least CopernicaRestClient. (Which is
 * the basic building block that's is using CopernicaRestAPI / can be tested
 * through this TestApi class. It is expected that most other code that uses
 * the Copernica REST API and needs tests, uses CopernicaRestClient and
 * TestApiFactory rather than using CopernicaRestAPI directly.)
 *
 * Besides 'being a CopernicaRestAPI', this class may contain some extra public
 * methods usable by tests.
 *
 * It contains two overlapping pieces of logic:
 * - remembering things in temporary storage (represented by a PDO database
 *   connection, currently SQLite only) to be able to emulate the REST API;
 * - knowing about, and being able to create, the structure of that temporary
 *   storage (database tables).
 * The second part of the functionality is also in this class because the API
 * also has calls for retrieving and manipulating the structure of Copernica's
 * databases, and we may also want to emulate responses to those calls. At the
 * same time it's quite possible that tests will want to setup a table
 * structure by themselves, before running tests - and this class contains the
 * helper code to do that. Many tests should be able to pre-create any needed
 * structure by just instantiating this class, though.
 *
 * This class will be permanently incomplete, only emulating parts of the API
 * which were needed to test certain code. Feel free to extend and contribute
 * additions. Please check the TODOs in initDatabase() to see if the database
 * currently contains the field types and constraints you need, before writing
 * tests.
 */
class TestApi
{
    /**
     * Set to True to throw exceptions on Curl errors / strange HTTP codes.
     *
     * This variable isn't expected to make any difference in behavior because
     * most methods don't explicitly cause errors. It's here mostly to be
     * 'compatible' with CopernicaRestAPI; callers can set it so let's make it
     * official.
     *
     * @var bool
     */
    public $throwOnError;

    /**
     * Field types that are allowed to occur in the databases structure.
     *
     * This is not the single authoritative value; it's a quick reference for
     * normalizeDatabasesStructure() to check, but other code uses database
     * specific mappings defined elsewhere in this class.
     *
     * @var string[]
     */
    protected $allowedFieldTypes = ['text' => 1, 'email' => 1, 'integer' => 1, 'float' => 1, 'empty_date' => 1, 'empty_datetime' => 1];

    /**
     * Timezone which the fake API backend seems to operate in.
     *
     * This influences e.g. "created" times, which are strings without timezone
     * expression. Empty means, take PHP's default timezone setting.
     *
     * @var string
     */
    protected $timezone = 'Europe/Amsterdam';

    /**
     * Test database connection.
     *
     * @var \PDO
     */
    protected $pdoConnection;

    /**
     * Structure of the databases/fields/collections.
     *
     * See normalizeDatabasesStructure().
     *
     * @var array
     */
    protected $databasesStructure;

    /**
     * Known values per type, in the database structure. (Not content related.)
     *
     * Outer key: type ('database', 'database_name', 'field', 'collection').
     * (As an informal standard, we use a singular noun for the keys that
     * represent IDs, because those are used to fill missing/default names in
     * the database structure.) Value: an array with keys being the known
     * values, values being... something we might redefine later; don't assume
     * anything. (Right now, 'collection' values are database IDs and the rest
     * is true. Looking up database IDs is abstracted into a helper method so
     * we don't need to assume this.)
     *
     * @var array[]
     */
    protected $knownValues;

    /**
     * A log of the API calls made. For tests. Structure might change still.
     *
     * This is all POST/PUT/DELETE calls, not GET.
     *
     * @var string[]
     */
    protected $apiUpdateLog = [];

    /**
     * TestApi constructor.
     *
     * @param array $databases_structure
     *   (Optional) structure of databases that needs to exist (be created).
     *   See normalizeDatabasesStructure() for format. Note "databases" refers
     *   to the copernica entities that hold profiles etc, not to our SQL
     *   database backend.
     * @param \PDO $pdo_connection
     *   (Optional) PDO connection; if passed, the contents of the database
     *   are assumed to match the structure already (to a point that tests do
     *   not fail inexplicably), so no tables are created at construction time.
     *
     * @see TestApi::normalizeDatabasesStructure()
     */
    public function __construct(array $databases_structure = [], PDO $pdo_connection = null)
    {
        $this->databasesStructure = $this->normalizeDatabasesStructure($databases_structure);
        if ($this->pdoConnection) {
            // We're not making any assumptions about a provided connection. If
            // needed, call initDatabase() by yourself after construction.
            $this->pdoConnection = $pdo_connection;
        } else {
            // We can define an environment variable before running tests, if
            // we want to use another database to inspect contents later. (At
            // the moment, only SQLite because that's the only db that we've
            // defined the CREATE TABLE commands for.)
            if (!empty($_ENV['COPERNICA_TEST_PDO_DSN'])) {
                $this->pdoConnection = new PDO(
                    $_ENV['COPERNICA_TEST_PDO_DSN'],
                    !empty($_ENV['COPERNICA_TEST_PDO_USER']) ? $_ENV['COPERNICA_TEST_PDO_USER'] : null,
                    !empty($_ENV['COPERNICA_TEST_PDO_PASS']) ? $_ENV['COPERNICA_TEST_PDO_PASS'] : null
                );
            } else {
                $this->pdoConnection = new PDO('sqlite::memory:');
            }
            $this->initDatabase();
        }
    }

    /**
     * Returns a result for a GET request.
     *
     * @param string $resource
     *   Resource to fetch.
     * @param array $parameters
     *   Additional parameters.
     *
     * @return mixed
     *   Usually: associative array with the result (which is the equivalent of
     *   CopernicaRestAPI JSON-decoding a response body). Other return value
     *   could represent a non-decoded response, or just false in extreme
     *   cases that should never happen.
     */
    public function get($resource, array $parameters = array())
    {
        // No update log; we don't care that much (yet) how often we call GET.
        $parts = explode('/', $resource);

        // _simulate_exception has variable 2nd/3rd component.
        if ($parts[0] === '_simulate_exception') {
            $this->checkUrlPartCount($resource, 2, 3);
            $this->simulateException($parts[1], isset($parts[2]) ?$parts[2] : '', 'GET');
        }
        // The majority of paths have a variable second component. So select
        // the first+third (until we need anything else).
        switch ($parts[0] . (isset($parts[2]) ? '/' . $parts[2] : '')) {
            case '_simulate_strange_response':
                $this->checkUrlPartCount($resource, 2, 2);
                switch ($parts[1]) {
                    case 'non-array':
                        return 'This is not a json decoded body.';

                    case 'invalid-token':
                        return ['error' => ['message' => 'Invalid access token']];

                    case 'invalid-entity':
                        return ['name' => 'incomplete-entity'];
                }
                // Fall through to "not implemented".
                break;

            case 'database/profiles':
                return $this->getProfiles($parts[1], $parameters);

            case 'collection/subprofiles':
                return $this->getSubprofiles($parts[1], $parameters);

            case 'profile':
                $this->throwIfNotEmpty($parameters);
                return $this->getProfile($parts[1]);
        }

        throw new RuntimeException("Resource $resource (GET) not implemented.");
    }

    /**
     * Fake-executes a POST request; stores results in test backend.
     *
     * @param string $resource
     *   Resource (URI relative to the versioned API) to send data to.
     * @param array $data
     *   (Optional) data to send.
     *
     * @return mixed
     *   ID of created entity, or simply true/false to indicate success/failure.
     *   (This description comes from CopernicaRestAPI but it seems doubtful
     *   that true actually indicates success for most cases though. False is
     *   only returned for nonstandard $suppress_errors values.)
     */
    public function post($resource, array $data = array())
    {
        $this->apiUpdateLog [] = "POST $resource";
        $parts = explode('/', $resource);

        // _simulate_exception has variable 2nd/3rd component.
        if ($parts[0] === '_simulate_exception') {
            $this->checkUrlPartCount($resource, 3, 3);
            $this->simulateException($parts[1], $parts[2], 'POST');
        }
        // The majority of paths have a variable second component. So select
        // the first+third (until we need anything else).
        switch ($parts[0] . (isset($parts[2]) ? '/' . $parts[2] : '')) {
            case '_simulate_strange_response':
                $this->checkUrlPartCount($resource, 2, 2);
                // Both false and true are considered 'strange responses'. If
                // we discover a POST request that actually returns no ID, then
                // we can likely take out the 'true' part here.
                if (in_array($parts[1], ['false', 'true'], true)) {
                    return $parts[1] === 'true';
                }
                // Fall through to "not implemented".
                break;

            case 'database/profiles':
                $this->checkUrlPartCount($resource, 3, 3);
                $id = $this->postProfile($parts[1], $data);
                // This can only return the inserted ID.
                if (is_numeric($id)) {
                    // CopernicaRestAPI picks it out from the header and
                    // returns a string.
                    return (string)$id;
                }
                // Figure out later whether we just want to have the test
                // throw an exception, or return true for CopernicaRestAPI
                // compatibility.
                // @todo check current state
                // @todo does this tie in with the new throwOnError property?
                throw new RuntimeException('postProfile() returned non-numeric value ' . var_export($id, true) . '.');

            case 'profile/subprofiles':
                $this->checkUrlPartCount($resource, 4, 4);
                $id = $this->postSubprofile($parts[1], $parts[3], $data);
                // This can only return the inserted ID.
                if (is_numeric($id)) {
                    // CopernicaRestAPI picks it out from the header and
                    // returns a string.
                    return (string)$id;
                }
                // Figure out later whether we just want to have the test
                // throw an exception, or return true for CopernicaRestAPI
                // compatibility.
                // @todo check current state
                throw new RuntimeException('postSubprofile() returned non-numeric value ' . var_export($id, true) . '.');
        }

        throw new RuntimeException("Resource $resource (POST) not implemented.");
    }

    /**
     * Fake-executes a PUT request; stores results in test backend.
     *
     * @param string $resource
     *   Resource (URI relative to the versioned API) to send data to.
     * @param array $data
     *   Data to send.
     * @param array $parameters
     *   (Optional) parameters for the API query. (This parameter is taken over
     *   from CopernicaRestAPI but it is unclear which PUT requests need
     *   parameters, at the time of writing this method.)
     *
     * @return mixed
     *   ID of created entity, or simply true/false to indicate success/failure.
     *   (This description comes from CopernicaRestAPI but we're not sure of
     *   any PUT call returning an ID yet. False is only returned for
     *   nonstandard $suppress_errors values.)
     */
    public function put($resource, $data, array $parameters = array())
    {
        $this->apiUpdateLog [] = "PUT $resource";
        $parts = explode('/', $resource);
        $this->throwIfNotEmpty($parameters);

        // _simulate_exception has variable 2nd/3rd component.
        if ($parts[0] === '_simulate_exception') {
            $this->checkUrlPartCount($resource, 3, 3);
            $this->simulateException($parts[1], $parts[2], 'PUT');
        }
        // The majority of paths have a variable second component. So select
        // the first+third (until we need anything else).
        switch ($parts[0] . (isset($parts[2]) ? '/' . $parts[2] : '')) {
            case '_simulate_strange_response':
                $this->checkUrlPartCount($resource, 2, 2);
                // false is a 'strange response'; neither true or another value
                // (ID value only, but we don't check that) are 'strange'.
                if ($parts[1] === 'false') {
                    return false;
                }
                // Fall through to "not implemented".
                break;

            case 'profile/fields':
                $this->checkUrlPartCount($resource, 3, 3);
                $this->putProfileFields($parts[1], $data);
                return true;

            case 'subprofile/fields':
                $this->checkUrlPartCount($resource, 3, 3);
                $this->putSubprofileFields($parts[1], $data);
                return true;
        }
        throw new RuntimeException("Resource $resource (PUT) not implemented.");
    }

    /**
     * Fake-executes a POST/PUT request; stores results in test backend.
     *
     * This is here for compatibility with CopernicaRestAPI but not expected to
     * be used. post() / put() is more consistent with other code.
     *
     * @param string $resource
     *   Resource (URI relative to the versioned API) to send data to.
     * @param array $data
     *   Data to send.
     * @param array $parameters
     *   (Optional) parameters for the API query.
     * @param string $method
     *   (Optional) method.
     *
     * @return mixed
     *   ID of created entity, or simply true/false to indicate success/failure.
     */
    public function sendData($resource, array $data = array(), array $parameters = array(), $method = "POST")
    {
        // This is literally the condition used by CopernicaRestAPI.
        if ($method == "POST") {
            // $parameters is not a parameter to the post() method; it's
            // unlikely this was meant to be supported.
            $this->throwIfNotEmpty($parameters);
            return $this->post($resource, $data);
        }
        return $this->put($resource, $data, $parameters);
    }

    /**
     * Fake-executes a DELETE request; stores results in test backend.
     *
     * @param string $resource
     *   Resource (URI relative to the versioned API) to send data to.
     *
     * @return bool
     *   Success?
     */
    public function delete($resource)
    {
        $this->apiUpdateLog [] = "DELETE $resource";
        $parts = explode('/', $resource);

        // _simulate_exception has variable 2nd/3rd component.
        if ($parts[0] === '_simulate_exception') {
            $this->checkUrlPartCount($resource, 3, 3);
            $this->simulateException($parts[1], $parts[2], 'DELETE');
        }

        throw new RuntimeException("Resource $resource (DELETE) not implemented.");
    }

    /**
     * Gets the current normalized database structure.
     *
     * This is equivalent to what the 'databases' API call would return but
     * not equal:
     * - There are no metadata wrappers.
     * - There are no 'ID' properties; the IDs are keys.
     * - A lot of properties are not included. (Or normalized. If we gpt
     *   properties in the constructor that we don't use, we just keep them.)
     *
     * @return array[]
     */
    public function getDatabasesStructure()
    {
        return $this->databasesStructure;
    }

    /**
     * Gets the database connection.
     *
     * @return \PDO
     */
    public function getPdoConnection()
    {
        return $this->pdoConnection;
    }

    /**
     * Gets arrays of known values.
     *
     * One needs to know the keys used internally for this to be useful - it's
     * useful for tests.
     *
     * @return array[]
     */
    public function getKnownValues()
    {
        return $this->knownValues;
    }

    /**
     * Gets the timezone used for converting datetime expressions.
     *
     * @return string
     *   A valid timezone name, or '' meaning PHP's default timezone setting.
     */
    public function getTimezone()
    {
        return $this->timezone;
    }

    /**
     * Sets the timezone used for converting datetime expressions.
     *
     * @param string
     *   A valid timezone name, or '' to use PHP's default timezone setting.
     */
    public function setTimezone($timezone)
    {
        $this->timezone = $timezone;
    }

    /**
     * Gets the 'call log' for API calls made.
     *
     * @return string[]
     *   The URLs of the API calls made, in order.
     */
    public function getApiUpdateLog()
    {
        return $this->apiUpdateLog;
    }

    /**
     * Resets the 'call log' for API calls made.
     */
    public function resetApiUpdateLog()
    {
        $this->apiUpdateLog = [];
    }

//    /**
//     * Gets a part of the database structure, by name.
//     *
//     * The structure (array of arrays) itself is indexed by ID, and each array
//     * member has a 'name' property.
//     *
//     * @param string $name
//     *   The name (the value of the 'name' property) of the member.
//     * @param array $structure
//     *   (Optional) structure to look into. By default, our databases structure
//     *   is taken, so $name would be the name of an existing database.
//     *
//     * @return array
//     *   The named member. Note this has no ID value set.
//     */
//    public function getMemberByName($name, $structure = [])
//    {
//
//    }

    /**
     * Gets an ID of a (database structure's or other list's) member, by name.
     *
     * The structure (array of arrays) itself is indexed by ID, and each array
     * member has a 'name' property.
     *
     * @param string $name
     *   The name (the value of the 'name' property) of the member.
     * @param array $structure
     *   (Optional) structure to look into. By default, our databases structure
     *   is taken, so $name would be the name of an existing database.
     *
     * @return int
     *   The ID; zero if not found.
     */
    public function getMemberId($name, $structure = null)
    {
        if ($structure === null) {
            $structure = $this->databasesStructure;
        }

        foreach ($structure as $id => $member) {
            // We should be able to blindly assume that 'name' exists.
            if ($member['name'] === $name) {
                return $id;
            }
        }

        return 0;
    }

    protected function throwIfNotEmpty($parameters)
    {
        if ($parameters) {
            throw new RuntimeException('TestAPI does not support parameters sent with this request (until there is clarity on how to support them).');
        }
    }

    /**
     * Checks number of parts in a URL.
     *
     * @param string $resource
     *   URL.
     * @param int $min_parts
     *   The minimum number of parts that must be present, in other words, the
     *   minimum number of slashes that a URL must have plus 1.
     * @param int $max_parts
     *   The maximum number of parts that can be present.
     */
    protected function checkUrlPartCount($resource, $min_parts, $max_parts)
    {
        $parts = explode('/', $resource);
        if ($min_parts && !isset($parts[$min_parts - 1])) {
            throw new InvalidArgumentException("Resource URL must have at least $min_parts parts.");
        }
        if ($max_parts && isset($parts[$max_parts])) {
            throw new InvalidArgumentException("Resource URL must have maximum $max_parts parts.");
        }
    }

    /**
     * Throw some exception.
     *
     * This is a utility method which throws exeptions similar to
     * CopernicaRestAPI - to be able to test error handling of client classes
     * which usually use CopernicaRestAPI. Tests exercising such a client class
     * often need to call nonexistent endpoints that only this TestApi
     * implements, in order to simulate this behavior.
     *
     * @param string $type
     *   The type of exception.
     * @param int $code
     *   The code for the exception.
     * @param string $method
     *   The HTTP method (verb) that was supposedly used to make the API call.
     */
    protected function simulateException($type, $code, $method)
    {
        if (!$code && ($type !== 'invalid_json' || $method !== 'GET')) {
            throw new InvalidArgumentException('$code argument is empty.');
        }

        // The distinguishing property of many CopernicaRestAPI exceptions is
        // that they include "Response contents: <body and maybe headers>".
        // CopernicaRestClient may get those from the exception message and
        // just return the body. (And we know this shady practice but it is the
        // way for CopernicaRestClient to _optionally_ throw an exception.) So
        // it's important to add some response contents with newlines.
        switch ($type) {
            case 'curl':
                // Emulate CopernicaRestAPI message. Assume the response
                // contents will always be "" for Curl errors.
                throw new RuntimeException("CURL returned code $code (\"Simulated error, description N/A\") for $method _simulate_exception/$type/$code. Response contents: \"\".", $code);

            case 'http':
                // Let's make sure to add a newline and a double quote inside
                // the payload. We don't know much about payloads in errors
                // though we've seen one response to a POST request contain a
                // JSON body. It may not be desirable to send an "error" along
                // with every HTTP code, but it'll do for now.
                $payload = "{\"error\":\r\n{\"message\":\"Simulated message returned along with HTTP code $code.\"}}";
                // post()/put() methods in CopernicaRestAPI add the headers to
                // the value returned from Curl - that's just a hardcoded
                // inconsistency (if we can call it that).
                if (in_array($method, ['POST', 'PUT'], true)) {
                    // Just fYI: HTTP 1.1 mandates \r\n between headers and
                    // before the body. The body itself (above) might contain
                    // anything; we just inserted the \r because we can.
                    $payload = "X-Fake-Header: fakevalue\r\nX-Fake-Header2: fakevalue2\r\n\r\n$payload";
                }
                throw new RuntimeException("$method _simulate_exception/$type/$code returned HTTP code $code. Response contents: \"$payload\".", $code);

            case 'invalid_json':
                if ($code || $method !== 'GET') {
                    throw new InvalidArgumentException('Unknown $code / $method.');
                }
                // Simulate CopernicaRestAPI getting invalid JSON returned.
                throw new RuntimeException('Unexpected input: ["invalid_json}');
        }

        throw new RuntimeException("Unrecognized exception type $type, code $code.", $code);
    }

    /**
     * API endpoint emulation: retrieves a single profile.
     *
     * @param int $profile_id
     *   A profile ID.
     *
     * @return array
     *   A structure as returned in the API response.
     */
    protected function getProfile($profile_id)
    {
        // @todo check what messages live API has for invalid ID. Encode in
        //   test.
        if (filter_var($profile_id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            // Copernica returns the same message for any non-numeric argument.
            return ['error' => ['message' => 'No database with supplied ID']];
        }

        // @todo check for deleted profiles. Should likely return 'removed'.
        //   Check for unknown profiles. Likely different message.
        $database_id = $this->dbFetchField('SELECT database_id FROM profile_db where profile_id = :id', [':id' => $profile_id]);
        if ($database_id === false) {
            throw new LogicException("TestApi: need to know what the error message is for getting nonexistent profile.");
        }
        if (empty($database_id)) {
            throw new LogicException("TestApi: need to know what the error message is for getting nonexistent profile.");
        }

        $result = $this->dbFetchAll("SELECT * FROM profile_$database_id WHERE _pid = :id", [':id' => $profile_id]);
        $row = current($result);
        if (empty($row)) {
            throw new LogicException("TestApi: internal inconsistency; profile data doesn't exist in the table which the 'database pointer' references.");
        }
        $id = $row['_pid'];
        // We could convert created/updated to a date/int and then back to
        // a string for compatibility, but as long as we're only using
        // SQLite that's unnecessary.
        $created = $row['_created'];
        $modified = $row['_modified'];
        unset($row['_pid'], $row['_created'], $row['_modified']);
        // For some reason, everything is returned as a string. Except removed.
        return [
            'ID' => (string)$id,
            'fields' => array_map('strval', $row),
            // We don't support interests yet. @todo
            'interests' => [],
            'database' => (string)$database_id,
            // I have no clue what 'secret' is but it's apparently
            // - 112 bits hex (28 chars)
            // - "stored with the profile", so unchanging over updates. (I
            //   didn't check.) @todo check?
            // It seems OK to provide something random-ish rather than a
            // hardcoded value.
            'secret' => substr(hash("sha1", $id), 0, 28),
            'created' => $created,
            'modified' => $modified,
            'removed' => false,
        ];
    }

    /**
     * API endpoint emulation: retrieves profiles.
     *
     * @param int $database_id
     *   A database ID.
     * @param array $parameters
     *   Request parameters.
     *
     * @return array
     *   A structure as returned in the API response.
     */
    protected function getProfiles($database_id, array $parameters)
    {
        if (
            filter_var($database_id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false
            || !isset($this->knownValues['database'])
        ) {
            // Copernica returns the same message for any non-numeric argument.
            return ['error' => ['message' => 'No database with supplied ID']];
        }

        // @todo test if the live API just ignores unknown parameters.
        //   (Guessing and hoping it does, so we don't need to check them.
        //   Explicitly say this when removing the TODO. Encode this in a test.)
        if (isset($parameters['orderby']) || isset($parameters['order'])) {
            // @todo
            throw new RuntimeException('TestApi does not support order/orderby yet.');
        }
        if (!empty($parameters['dataonly'])) {
            // @todo
            throw new RuntimeException("TestApi does not support 'dataonly' parameter yet.");
        }

        $this->normalizeEntitiesParameters($parameters);
        $data = [];
        if ($parameters['start'] >= 0 && $parameters['limit'] > 0) {
            $sql_expr = $this->getSqlConditionsFromParameters($parameters, $database_id);
            if ($sql_expr) {
                $sql_expr = " WHERE $sql_expr";
            }
            // Let's just always limit.
            $result = $this->dbFetchAll("SELECT * FROM profile_$database_id$sql_expr ORDER BY _pid LIMIT :limit OFFSET :offset", [
                ':limit' => $parameters['limit'],
                ':offset' => $parameters['start']
            ]);
            foreach ($result as $row) {
                $id = $row['_pid'];
                $created = $row['_created'];
                $modified = $row['_modified'];
                unset($row['_pid'], $row['_created'], $row['_modified']);
                $data[] = [
                    'ID' => (string)$id,
                    'fields' => array_map('strval', $row),
                    // We don't support interests yet. @todo
                    'interests' => [],
                    'database' => (string)$database_id,
                    // See getProfile().
                    'secret' => substr(hash("sha1", $id), 0, 28),
                    'created' => $created,
                    'modified' => $modified,
                    // 'removed' is always false in entities that are part of lists.
                    'removed' => false,
                ];
            }
        }
        $response = [
            'start' => $parameters['start'],
            'limit' => $parameters['limit'],
            'count' => count($data),
            'data' => $data
        ];
        if ($parameters['total']) {
            $response['total'] = (int)$this->dbFetchField("SELECT COUNT(*) FROM profile_$database_id");
        }

        return $response;
    }

    /**
     * API endpoint emulation: retrieves profiles.
     *
     * @param int $collection_id
     *   A collection ID.
     * @param array $parameters
     *   Request parameters.
     *
     * @return array
     *   A structure as returned in the API response.
     */
    protected function getSubprofiles($collection_id, array $parameters)
    {
        // @todo test if this is also the case for the live API on POST (and
        //   create test to encode this). Only tried it on getProfiles so far.
        if (
            filter_var($collection_id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false
            || !isset($this->knownValues['collection'][$collection_id])
        ) {
            throw new LogicException("TestApi: need to know what the error message is for retrieving subprofiles from nonexistent collection.");
            // Copernica returns the same message for any non-numeric argument.
            return ['error' => ['message' => 'No collection with supplied ID']];
        }

        // @todo test if the live API just ignores unknown parameters.
        //   (Guessing and hoping it does, so we don't need to check them.
        //   Explicitly say this when removing the TODO. Encode this in a test.)
        if (isset($parameters['orderby']) || isset($parameters['order'])) {
            // @todo
            throw new RuntimeException('TestApi does not support order/orderby yet.');
        }
        if (!empty($parameters['dataonly'])) {
            // @todo
            throw new RuntimeException("TestApi does not support 'dataonly' parameter yet.");
        }

        $this->normalizeEntitiesParameters($parameters);
        $data = [];
        if ($parameters['start'] >= 0 && $parameters['limit'] > 0) {
            $sql_expr = $this->getSqlConditionsFromParameters($parameters, $collection_id, true);
            if ($sql_expr) {
                $sql_expr = " WHERE $sql_expr";
            }
            // Let's just always limit.
            $result = $this->dbFetchAll("SELECT * FROM subprofile_$collection_id$sql_expr ORDER BY _spid LIMIT :limit OFFSET :offset", [
                ':limit' => $parameters['limit'],
                ':offset' => $parameters['start']
            ]);
            foreach ($result as $row) {
                $id = $row['_spid'];
                $profile_id = $row['_pid'];
                $created = $row['_created'];
                $modified = $row['_modified'];
                unset($row['_spid'], $row['_pid'], $row['_created'], $row['_modified']);
                $data[] = [
                    'ID' => (string)$id,
                    // See getProfile().
                    'secret' => substr(hash("sha1", $id), 0, 28),
                    'fields' => array_map('strval', $row),
                    'profile' => (string)$profile_id,
                    'collection' => (string)$collection_id,
                    'created' => $created,
                    'modified' => $modified,
                    'removed' => false,
                ];
            }
        }
        $response = [
            'start' => $parameters['start'],
            'limit' => $parameters['limit'],
            'count' => count($data),
            'data' => $data
        ];
        if ($parameters['total']) {
            $response['total'] = (int)$this->dbFetchField("SELECT COUNT(*) FROM subprofile_$collection_id");
        }

        return $response;
    }

    /**
     * API endpoint emulation: creates a new profile.
     *
     * @param int $database_id
     *   A database ID.
     * @param array $data
     *   Data as provided to the post() method.
     *
     * @return int|array
     *   The ID of the created profile, or standardized 'error array'.
     */
    protected function postProfile($database_id, array $data)
    {
        // @todo test if this is also the case for the live API on POST (and
        //   create test to encode this). Only tried it on getProfiles so far.
        if (
            filter_var($database_id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false
            || !isset($this->knownValues['database'])
        ) {
            // Copernica returns the same message for any non-numeric argument.
            return ['error' => ['message' => 'No database with supplied ID']];
        }
        // @todo test what happens for the live API when we pass any properties
        //   besides 'fields'. Also encode this in tests. At any rate we should
        //   keep throwing an exception if 'interests' is passed, as long as
        //   we don't store those.
        if (count($data) != 1 || !isset($data['fields'])) {
            throw new LogicException("TestApi so far requires a data payload with a 'fields' key, and nothing else.");
        }

        // @todo test what happens for the live API when we pass unknown fields.
        //   Are they just ignored?
        // @todo test if the live API is OK with misspelling case of fields.
        //   for both of these points, encode this in tests. (We may need to
        //   make an effort here to emulate mis-cased fields because of
        //   filterFields().)
        $this->filterFields($data['fields'], $database_id);
        $id = $this->insertEntityRecord("profile_$database_id", $data['fields']);
        $this->dbInsertRecord('profile_db', ['profile_id' => $id, 'database_id' => $database_id]);
        return $id;
    }

    /**
     * API endpoint emulation: creates a new subprofile.
     *
     * @param int $profile_id
     *   A profile ID.
     * @param int $collection_id
     *   A collection ID.
     * @param array $data
     *   Data as provided to the post() method.
     *
     * @return int|array
     *   The ID of the created subprofile, or standardized 'error array'.
     */
    protected function postSubprofile($profile_id, $collection_id, array $data)
    {
        // @todo test if this is also the case for the live API on POST (and
        //   create test to encode this). Only tried it on getProfiles so far.
        if (
            filter_var($collection_id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false
            || !isset($this->knownValues['collection'][$collection_id])
        ) {
            throw new LogicException("TestApi: need to know what the error message is for inserting subprofile in nonexistent collection.");
            // Copernica returns the same message for any non-numeric argument.
            return ['error' => ['message' => 'No collection with supplied ID']];
        }
        // @todo test if below is also the case for the live API on POST (and
        //   create test to encode this). Only tried it on getProfiles so far.
        if (
            filter_var($profile_id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false
            // @todo test both unknown profile and deleted profile Supposedly
            //   the latter would return a zero here, after we implement it.
            //   Maybe there are differing messages.
            || empty($this->dbFetchField('SELECT database_id FROM profile_db where profile_id = :id', [':id' => $profile_id]))
        ) {
            throw new LogicException("TestApi: need to know what the error message is for inserting subprofile for nonexistent profile.");
            return ['error' => ['message' => 'No database with supplied ID']];
        }

        // @todo test what happens for the live API when we pass unknown fields.
        //   Are they just ignored?
        // @todo test if the live API is OK with misspelling case of fields.
        //   for both of these points, encode this in tests. (We may need to
        //   make an effort here to emulate mis-cased fields because of
        //   filterFields().)
        $this->filterFields($data, $collection_id, true);
        $data['_pid'] = $profile_id;
        $id = $this->insertEntityRecord("subprofile_$collection_id", $data);
        $this->dbInsertRecord('subprofile_coll', ['subprofile_id' => $id, 'collection_id' => $collection_id]);
        return $id;
    }

    /**
     * API endpoint emulation: updates profile fields.
     *
     * @param int $profile_id
     *   A profile ID.
     * @param array $data
     *   Data as provided to the put() method.
     *
     * @return true
    */
    protected function putProfileFields($profile_id, array $data)
    {
        // @todo test both unknown profile and deleted profile. Supposedly
        //   the latter would return a zero here, after we implement it.
        //   Maybe there are differing messages.
        $database_id = $this->dbFetchField('SELECT database_id FROM profile_db where profile_id = :id', [':id' => $profile_id]);
        if (!$database_id) {
            throw new LogicException("TestApi so far requires a known un-deleted profile id.");
        }

        // @todo test what happens for the live API when we pass unknown fields.
        //   Are they just ignored?
        // @todo test if the live API is OK with misspelling case of fields.
        //   for both of these points, encode this in tests. (We may need to
        //   make an effort here to emulate mis-cased fields because of
        //   filterFields().)
        $this->filterFields($data, (int)$database_id);
        // @todo check in the live API what happens when the profile does not
        //   exist anymore. Encode it in a test. Implement here.
        $this->updateEntityRecord("profile_$database_id", $data, '_pid', $profile_id);
        return true;
    }

    /**
     * API endpoint emulation: updates subprofile fields.
     *
     * @param int $subprofile_id
     *   A subprofile ID.
     * @param array $data
     *   Data as provided to the put() method.
     *
     * @return true
     */
    protected function putSubprofileFields($subprofile_id, array $data)
    {
        // @todo test both unknown profile and deleted profile. Supposedly
        //   the latter would return a zero here, after we implement it.
        //   Maybe there are differing messages.
        $collection_id = $this->dbFetchField('SELECT collection_id FROM subprofile_coll where subprofile_id = :id', [':id' => $subprofile_id]);
        if (!$collection_id) {
            throw new LogicException("TestApi so far requires a known un-deleted profile id.");
        }

        // @todo test what happens for the live API when we pass unknown fields.
        //   Are they just ignored?
        // @todo test if the live API is OK with misspelling case of fields.
        //   for both of these points, encode this in tests. (We may need to
        //   make an effort here to emulate mis-cased fields because of
        //   filterFields().)
        $this->filterFields($data, (int)$collection_id, true);
        // @todo check in the live API what happens when the subprofile does
        //   not exist anymore. Encode it in a test. Implement here.
        $this->updateEntityRecord("subprofile_$collection_id", $data, '_spid', $subprofile_id);
        return true;
    }

    /**
     * Generates a normalized set of parameters for 'entities' requests.
     *
     * start / limit / total are always guaranteed to be set afterwards; start
     * / limit are integers and total boolean. Note start / limit  can be
     * passed back to the caller as-is but we may not be able to just pass it
     * into the SQL query as-is. We likely are better off not querying anything
     * if start < 0 or limit <= 0.
     *
     * Note our checks are likely a bit different from the real API, in order
     * to have the exact same effect - because the real API works through URL
     * encoded parameters and therefore isn't likely to even be able to get
     * numeric/boolean types as input. We are.
     *
     * @param array $parameters
     *   A set of paramaters passed to an 'entities' request. This is modified
     *   by reference.
     * @param int $default_limit
     *   (Optional) default limit, in cases where it's different than 100.
     *
     * @todo check if there is a maximum limit that can be set for queries.
     * @todo encode all below assumptions about parameters in tests (that can
     *   be run against live Copernica, to see if our assumptions still hold).
     */
    public function normalizeEntitiesParameters(array &$parameters, $default_limit = 100)
    {
        if (!isset($parameters['limit'])) {
            // Limit is 100 for profiles. I think it's 1000 for mailing
            // statistics though not 100% sure. Not tested other queries yet.
            $parameters['limit'] = $default_limit;
        } elseif (!is_numeric($parameters['limit'])) {
            // Strange parameters lead to an answer with start/limit/count=0
            throw new RuntimeException('todo check if LIMIT 0 returns a valid set of 0 rows in SQLite.');
            $parameters['limit'] = 0;
        } else {
            // Round all numeric values to int: if the result is negative or 0,
            // then the 'limit' value returned to the caller is this value and
            // the 'count' is always 0.
            $parameters['limit'] = (int) $parameters['limit'];
        }

        // Basically same for start - except default is 0.
        if (!isset($parameters['start']) || !is_numeric($parameters['start'])) {
            $parameters['start'] = 0;
        } else {
            // This will work for negative numbers as well: if the result is
            // negative, then the 'start' value returned to the caller is this
            // negative integer and the 'count' is always 0.
            $parameters['start'] = (int) $parameters['start'];
        }

        // 'total' seems to have the same logic in that any string except "true"
        // and any number between (not including) 1 and -1 evaluate to false;
        // all others to default/true. Always convert it to boolean here.
        if (!isset($parameters['total'])) {
            $parameters['total'] = true;
        } elseif (is_numeric($parameters['total'])) {
            $parameters['total'] = abs($parameters['total']) >= 1;
        } elseif (!is_bool($parameters['total'])) {
            $parameters['total'] = $parameters['total'] !== 'true';
        }
    }

    /**
     * Constructs SQL 'WHERE' conditions from API call parameters.
     *
     * @param array $parameters
     *   API call parameters. Only the 'fields' parameter is looked at.
     * @param int $id
     *   Database ID or collection ID
     * @param bool $for_collection
     *   (Optional) if true, the ID is a database ID; collection ID otherwise.
     *
     * @return string
     *   If (valid) fields are found: SQL conditions usable inside a WHERE
     *   statement; possibly multiple separated by 'AND'. Otherwise, empty
     *   string.
     */
    protected function getSqlConditionsFromParameters(array $parameters, $id, $for_collection = false)
    {
        $clauses = [];
        if (isset($parameters['fields'])) {
            if (!is_array($parameters['fields'])) {
                // @todo check how the real API behaves, encode it in a test,
                //   possibly change the below throw.
                throw new RuntimeException("'fields' parameter is not an array.");
            }
            $available_fields = $this->getFields($id, $for_collection);

            foreach ($parameters['fields'] as $key => $field_statement) {
                if (!is_int($key)) {
                    // @todo Maybe this is unnecessary. This depends on whether
                    //   it is possible to pass non-numeric keys to the real
                    //   API through URL parameters, I guess. Haven't checked.
                    throw new RuntimeException("'fields' parameter has non-numeric key. (Are you sure it was specified correctly?)");
                }
                if (!preg_match('/^\s*(\w+)\s*([\=\<\>\!\~]{1,2})\s*(.*?)\s*$/', $field_statement, $matches)) {
                    throw new RuntimeException("'fields' parameter $key has unrecognized syntax ($field_statement). (This may or may not point to a bug in TestApi code.)");
                }
                if ($matches[2] !== '==') {
                    // @todo support more.
                    throw new RuntimeException("Comparison operator $matches[2] in 'fields' parameter is not supported by TestApi (yet).");
                }
                if (!isset($available_fields[$matches[1]])) {
                    // @todo check how the real API behaves, encode it in a
                    //   test, possibly change the below throw.
                    // @todo support id, modified and code.
                    throw new RuntimeException("Field $matches[2] in 'fields' parameter is apparently not present in the entity we're filtering. (Should this be supported?)");
                }
                $field_struct = $available_fields[$matches[1]];
                if (!in_array($field_struct['type'], ['integer', 'float'], true)) {
                    $matches[3] = "'$matches[3]'";
                }
                $clauses[] = "$matches[1] = $matches[3]";
            }
        }

        return implode(' AND ', $clauses);
    }

    /**
     * Executes a PDO query/statement.
     *
     * @param string $query
     *   Un-prepared query, with placeholders as can also be used for calling
     *   PDO::prepare(), i.e. starting with a colon.
     * @param array $parameters
     *   Query parameters as could be used in PDOStatement::execute.
     *
     * @return \PDOStatement
     *   Executed PDO statement.
     */
    protected function dbExecutePdoStatement($query, $parameters)
    {
        $statement = $this->pdoConnection->prepare($query);
        if (!$statement) {
            // This is likely an error in some internal SQL query so we likely
            // want to know the arguments too.
            throw new LogicException("PDO statement could not be prepared: $query " . json_encode($parameters));
        }
        $ret = $statement->execute($parameters);
        if (!$ret) {
            $info = $statement->errorInfo();
            throw new RuntimeException("Database statement execution failed: Driver code $info[1], SQL code $info[0]: $info[2]");
        }

        return $statement;
    }

    /**
     * Executes a non-select query.
     *
     * @param string $query
     *   Un-prepared query, with placeholders as can also be used for calling
     *   PDO::prepare(), i.e. starting with a colon.
     * @param array $parameters
     *   Query parameters as could be used in PDOStatement::execute.
     * @param int $special_handling
     *   Affects the behavior and/or type of value returned from this function.
     *   (Admittedly this is a strange way to do things; quick and dirty and
     *   does the job.)
     *   0 = return the number of affected rows
     *   1 = return the last inserted ID; assumes insert statement which
     *       inserts exactly one row; otherwise logs an error.
     *   other values: undefined.
     *
     * @return int
     *   The number of rows affected by the executed SQL statement, or (if
     *   $special_handling == 1) the last inserted ID.
     */
    protected function dbExecuteQuery($query, $parameters = [], $special_handling = 0)
    {
        $statement = $this->dbExecutePdoStatement($query, $parameters);
        $affected_rows = $statement->rowCount();
        if ($special_handling === 1) {
            if ($affected_rows !== 1) {
                throw new RuntimeException('Unexpected affected-rows count in insert statement: {affected_rows}.', ['affected_rows' => $affected_rows]);
            }
            return $this->pdoConnection->lastInsertId();
        }
        return $affected_rows;
    }

    /**
     * Fetches single field value from database.
     *
     * @param string $query
     *   Un-prepared query, with placeholders as can also be used for calling
     *   PDO::prepare(), i.e. starting with a colon.
     * @param array $parameters
     *   (Optional) query parameters as could be used in PDOStatement::execute.
     *
     * @return mixed
     *   The value, or false for not found. (This implies that 'not found'
     *   only works for fields that we know cannot contain return boolean
     *   false, but that's most of them.) Note integer field values are likely
     *   returned as numeric strings (by SQLite); don't trust the type.
     */
    public function dbFetchField($query, $parameters = [])
    {
        $statement = $this->dbExecutePdoStatement($query, $parameters);
        $ret = $statement->fetchAll(PDO::FETCH_ASSOC);
        $record = current($ret);
        if ($record) {
            // Misuse the record/row as the value. Get the first field of what
            // we assume to be a record with a single field.
            $record = reset($record);
        }

        return $record;
    }

    /**
     * Fetches database rows for query.
     *
     * @param string $query
     *   Un-prepared query, with placeholders as can also be used for calling
     *   PDO::prepare(), i.e. starting with a colon.
     * @param array $parameters
     *   (Optional) query parameters as could be used in PDOStatement::execute.
     * @param string $key
     *   (Optional) Name of the field on which to index the array. It's the
     *   caller's responsibility to be sure the field values are unique and
     *   always populated; if not, there is no guarantee on the returned
     *   result. If and empty string is passed, then the array is numerically
     *   indexed; the difference with not passing the argument is that the
     *   return value is guaranteed to be an array (so it's countable, etc).
     *
     * @return array|\Traversable
     *   An array of database rows (as objects), or an equivalent traversable.
     */
    public function dbFetchAll($query, $parameters = [], $key = null)
    {
        $statement = $this->dbExecutePdoStatement($query, $parameters);
        $ret = $statement->fetchAll(PDO::FETCH_ASSOC);
        if (isset($key)) {
            $result = [];
            $i = 0;
            foreach ($ret as $record) {
                $result[$key ? $record->$key : $i++] = $record;
            }
            $ret = $result;
        }

        return $ret;
    }

    /**
     * Filters array of field-value pairs to only contain existing fields.
     *
     * @param array $record
     *   Possible database record / field-value pairs; modified by reference.
     * @param int $id
     *   Database ID or collection ID
     * @param bool $for_collection
     *   (Optional) if true, the ID is a database ID; collection ID otherwise.
     */
    protected function filterFields(array &$record, $id, $for_collection = false)
    {
        $field_names = array_flip($this->getFields($id, $for_collection, true));
        foreach (array_keys($record) as $field_name) {
            if (!isset($field_names[$field_name])) {
                unset($record[$field_name]);
            }
        }
    }

    /**
     * Inserts record in a table for a new entity.
     *
     * @param string $table_name
     *   The table name. Expected to be SQL safe.
     * @param array $record
     *   The record (field-value pairs), except _created and _modified fields
     *   which are added by this method. Fields are expected to be SQL safe.
     *
     * @return int
     *   The inserted ID.
     */
    protected function insertEntityRecord($table_name, array $record)
    {
        $date = new DateTime();
        if ($this->getTimezone()) {
            $date->setTimezone(new DateTimeZone($this->getTimezone()));
        }
        $record['_created'] = $record['_modified'] = $date->format('Y-m-d H:i:s');
        return $this->dbInsertRecord($table_name, $record);
    }

    /**
     * Inserts a record into the database.
     *
     * @param $table
     *   Table name. Expected to be SQL safe.
     * @param array $record
     *   The record (field-value pairs), except _created and _modified fields
     *   which are added by this method. Fields are expected to be SQL safe.
     *
     * @return int
     *   The last inserted ID.
     */
    protected function dbInsertRecord($table, array $record)
    {
        $fields = [];
        $values = [];
        $args = [];
        foreach ($record as $key => $value) {
            // Input parameters MAYBE should not be a substring of any other
            // parameter. I'm not even sure of that because I'm not sure how
            // the PDO prepare()/bindValue() stuff exactly works. I guess
            // prepending 'xx' makes it safe enough.
//            $param = 'xx' . str_replace('_', '', strtolower($key));
            $param = "xx$key";
            // Add quotes. (Apparently double quotes are universally recognized
            // in SQL to be quotes for identifiers, not literal values.) SQLite
            // needs it for fields starting with an underscore.
//            $fields[] = '"' . $key . '"';
            $fields[] = $key;
            $values[] = ":$param";
            $args[$param] = $value;
        }
        $result = $this->dbExecuteQuery("INSERT INTO $table (" . implode(', ', $fields)
            . ') VALUES (' . implode(', ', $values) . ');', $args, 1);
        if (!$result) {
            throw new LogicException('Unexpected return value from insert query: ' . var_export($result, true));
        }
        return $result;
    }

    /**
     * Updates a database record for an entity.
     *
     * @param string $table_name
     *   The table name. Expected to be SQL safe.
     * @param array $record
     *   The record (field-value pairs), except _created and _modified fields
     *   which are added by this method. Fields are expected to be SQL safe.
     * @param string $key_field
     *   They primary key field in the table.
     * @param string $key_field_value
     *   They primary key ID value to update.
     *
     * @return int
     *   The number of affected rows.
     */
    protected function updateEntityRecord($table_name, array $record, $key_field, $key_field_value)
    {
        $date = new DateTime();
        if ($this->getTimezone()) {
            $date->setTimezone(new DateTimeZone($this->getTimezone()));
        }
        $record['_modified'] = $date->format('Y-m-d H:i:s');
        return $this->dbUpdateRecord($table_name, $record, $key_field, $key_field_value);
    }

    /**
     * Updates a database record.
     *
     * @param $table
     *   Table name. Expected to be SQL safe.
     * @param array $record
     *   The record (field-value pairs), except _modified field which is added
     *   by this method. Fields are expected to be SQL safe.
     * @param string $key_field
     *   They primary key field in the table.
     * @param string $key_field_value
     *   They primary key ID value to update.
     *
     * @return int
     *   The number of affected rows.
     */
    protected function dbUpdateRecord($table, array $record, $key_field, $key_field_value)
    {
        $sets = [];
        $args = [];
        foreach ($record as $key => $value) {
            // Input parameters MAYBE should not be a substring of any other
            // parameter. I'm not even sure of that because I'm not sure how
            // the PDO prepare()/bindValue() stuff exactly works. I guess
            // prepending 'xx' makes it safe enough.
            $param = "xx$key";
            $sets[] = "$key = :$param";
            $args[$param] = $value;
        }
        $args["xx$key_field"] = $key_field_value;
        $result = $this->dbExecuteQuery(
            "UPDATE $table SET " . implode(', ', $sets) . " WHERE $key_field = :xx$key_field",
            $args
        );
        // Return value can be 1 or 0 for number of updated rows. This
        // check/log is arguably unneeded; we shouldn't need to second guess
        // the number of affected rows.
        $ret = ($result === 0 || $result === 1);
        if (!$ret) {
            throw new RuntimeException("Unexpected return value from update query: $result.");
        }

        return $result;
    }

    /**
     * Retrieves field names from the database structure.
     *
     * @param int $id
     *   Database ID or collectino ID
     * @param bool $for_collection
     *   (Optional) if true, the ID is a database ID; collection ID otherwise.
     * @param bool $names
     *   (Optional) if true, return array of names. By default, return array
     *   of field structures keyed by name.
     *
     * @return array
     *   Field names (zero or more, numerically keyed).
     */
    protected function getFields($id, $for_collection = false, $names = false)
    {
        if ($for_collection) {
            $database_id = $this->getDatabaseIdForCollection($id);
            $fields = isset($this->databasesStructure[$database_id]['collections'][$id]['fields']) ? $this->databasesStructure[$database_id]['collections'][$id]['fields'] : [];
        } else {
            $fields = isset($this->databasesStructure[$id]['fields']) ? $this->databasesStructure[$id]['fields'] : [];
        }

        // We know all field definitions have 'name'.
        if ($names) {
            return array_map(
                function ($struct) {
                    return $struct['name'];
                },
                $fields
            );
        }

        $ret = [];
        foreach ($fields as $id => $struct) {
            $struct['ID'] = $id;
            $ret[$struct['name']] = $struct;
        }
        return $ret;
    }

    /**
     * Retrieves the database ID corresponding to a collection.
     *
     * @param int $collection_id
     *   The collection ID.
     *
     * @return int
     *   The ddtabase ID.
     *
     * @throws \InvalidArgumentException
     *   If the collection ID is not recognized.
     */
    protected function getDatabaseIdForCollection($collection_id)
    {
        if (!isset($this->knownValues['collection'][$collection_id])) {
            throw new InvalidArgumentException("Unknown collection ID in database map.");
        }
        return $this->knownValues['collection'][$collection_id];
    }

    /**
     * Checks and reformats the structure for databases.
     *
     * The structure can be the same as is returned from a 'databases' API call
     * (and only a few of the properties are checked/used), but we also accept
     * 'shortened' versions that are easier to construct for the caller:
     * - without any 'start', 'count' (etc.) metadata in the 'fields' and
     *   'collections' properties
     * - without 'ID' and 'name' fields for individual databases / collections
     *   / fields; the ID or name will be derived from the array key and the
     *   other property will be assigned a semi-random unique value. If the
     *   keys are all auto-numbered, both ID and name will be random.
     *
     * For reference, something like this (where all array members are
     * optional except for field names and the 'type' property in fields):
     *   <db-names-or-ids> => [
     *     'ID' => ID,
     *     'name' => DATABASE NAME,
     *     'fields' => [
     *       <field-names-or-ids> => [
     *         'ID' => ID,
     *         'name' => FIELD NAME,
     *         'type' => FIELD TYPE,
     *         'value' => x, // This is mandatory for integer and float because
     *                       // that's what Copernica enforces.
     *       ], ...
     *     ],
     *     'collections' => [
     *       <coll-names-or-ids> =>  [
     *         'ID' => ID,
     *         'name' => COLLECTION NAME,
     *         'fields' => [ SEE DATABASE FIELDS ]
     *       ], ...
     *     ],
     *   ], ...
     * ]
     * The 'normalized' structure will have all array keys be the IDs and no
     * 'ID' property will be set anywhere. (We may decide later to e.g. have
     * the keys for 'fields' be names, if that makes things easier for other
     * code - but that doesn't seem very likely.)
     *
     * @param array[] $databases
     *   A data structure representing zero or more databases, as per above.
     *
     * @return array[]
     *   The normalized version of the structure.
     */
    protected function normalizeDatabasesStructure(array $databases)
    {
        $this->checkKeysIdsNames($databases, 'database', 'database_name');

        // Check collections before changing any database IDs, because database
        // IDs can also be mentioned inside collections.
        $autonum_index = 0;
        $original_database_keys = [];
        foreach ($databases as $db_key => &$database) {
            // See checkKeysIdsNames() for the original logic.
            $key_is_autonum = $db_key === $autonum_index;
            if ($key_is_autonum) {
                $autonum_index++;
            } elseif ($db_key === 0) {
                $key_is_autonum = true;
                $autonum_index = 1;
            } else {
                $autonum_index = -1;
            }
            // Remember $db_key because we need it later if it's not the
            // actual database ID / it's going to be renumbered.
            $original_database_keys[] = $db_key;

            if (isset($database['collections'])) {
                // Issues:
                // - Collections have a single ID-space over all databases
                //   (which is why we need to check all collections in a loop
                //   before assigning values for unknown collection IDs.)
                // - Their names only need to be unique within a given database.
                // - They can also have a 'database' property, which we extract
                //   if it's set (and consistent), and then check against the
                //   database ID. (It may be crazy that we even support this
                //   because it makes the above/below code so much harder...
                //   but it's part of the '/databases' API return value and we
                //   kinda want to support any valid return value from there,
                //   just because.) This means we can only start assigning
                //   values for unknown database IDs after we check all
                //   collections.
                // The combination of these points means our 'known_names_key'
                // is not guaranteed to contain the database ID... yet.
                $coll_db_id = $this->checkKeysIdsNames($database['collections'], 'collection', "collection_name_$db_key", false, 'database');
                if (isset($coll_db_id)) {
                    if ($key_is_autonum && !isset($database['ID'])) {
                        // The database ID was only set in the 'database'
                        // property in (a) collection(s), which is weird but
                        // legal. We can set it in 'ID' for now.
                        if (isset($this->knownValues['database'][$coll_db_id])) {
                            throw new RuntimeException("Database ID $coll_db_id was already seen before.");
                        }
                        $this->knownValues['database'][$coll_db_id] = true;
                        $database['ID'] = $coll_db_id;
                    } elseif (isset($database['ID']) || !is_string($db_key)) {
                        // Compare with the ID we already have. (To repeat:
                        // is_string() works to see if $db_key is non-numeric
                        // because it's an array key.)
                        $db_id = isset($database['ID']) ? $database['ID'] : $db_key;
                        if ($coll_db_id != $db_id) {
                            throw new RuntimeException("Database ID $coll_db_id set inside 'database' property of collection differs from set ID $db_id.");
                        }
                    }
                }
            }
        }
        // Unset $database so we don't mess up the array by assigning to it.
        unset($database);

        // Assign unknown IDs.
        $databases = $this->transformKeys($databases, 'database', 'database_name');
        // Fix collection namespace keys if necessary. $database_keys has the
        // same number of elements as $databases, and their ordering
        // corresponds, but the keys in $databases may be different now. It is
        // theoretically possible that one of the higher original keys was
        // reused as one of the lower assigned IDs, so we need to reassign from
        // highest to lowest.
        $database = end($databases);
        $original_key = end($original_database_keys);
        while ($database !== false) {
            $db_id = key($databases);
            if ($db_id != $original_key) {
                // Throw for some situations that can never happen.
                if (isset($database['collections'])) {
                    if (isset($this->knownValues["collection_name_$original_key"])) {
                        if (isset($this->knownValues["collection_name_$db_id"])) {
                            throw new RuntimeException("Internal code error: collection-namespace 'collection_name_$db_id' already in use.");
                        }
                        $this->knownValues["collection_name_$db_id"] = $this->knownValues["collection_name_$original_key"];
                        unset($this->knownValues["collection_name_$original_key"]);
                    }
                } elseif (isset($this->knownValues["collection_name_$original_key"])) {
                    throw new RuntimeException("Internal code error: collection-namespace 'collection_name_$original_key' exists, but should not.");
                }
            }
            $database = prev($databases);
            $original_key = prev($original_database_keys);
        }

        // Assign unknown IDs to collections.
        foreach ($databases as $db_id => &$database) {
            if (isset($database['collections'])) {
                $database['collections'] = $this->transformKeys($database['collections'], 'collection', "collection_name_$db_id");
                // Make knownValues into a collection-db lookup.
                foreach ($database['collections'] as $id => $value) {
                    $this->knownValues['collection'][$id] = $db_id;
                }
            }
        }
        unset($database);

        // Fields do not have one namespace; every collection or main database
        // can have a field with the same name. They also do not have one
        // ID-space; I don't know how it works and I doubt anyone cares (we
        // only need to care not to throw exceptions for legal structures);
        // I've seen fields in separate databases with the same ID. For now,
        // let's give each database their own ID-space for fields.
        foreach ($databases as $db_id => &$database) {
            if (isset($database['fields'])) {
                $this->checkKeysIdsNames($database['fields'], "field_$db_id", "field_name_$db_id", true);
            }

            if (isset($database['collections'])) {
                foreach ($database['collections'] as $coll_id => &$collection) {
                    if (isset($collection['fields'])) {
                        $this->checkKeysIdsNames($collection['fields'], "field_$db_id", "field_name_$db_id.$coll_id", true);
                    }
                }
                // I believe even going through another loop is an assignment
                // that can mess up the last element of the array.
                unset($collection);
            }

            if (isset($database['fields'])) {
                $database['fields'] = $this->transformKeys($database['fields'], "field_$db_id", "field_name_$db_id");
                foreach ($database['fields'] as &$field) {
                    $this->normalizeFieldStructure($field);
                }
                unset($field);
            }

            if (isset($database['collections'])) {
                foreach ($database['collections'] as $coll_id => &$collection) {
                    if (isset($collection['fields'])) {
                        $collection['fields'] = $this->transformKeys($collection['fields'], "field_$db_id", "field_name_$db_id.$coll_id");
                        foreach ($database['fields'] as &$field) {
                            $this->normalizeFieldStructure($field);
                        }
                        unset($field);
                    }
                }
                unset($collection);
            }
        }

        return $databases;
    }
    // @todo in the return values from the /databases (and other) API calls,
    //   stringify all the ID & database fields, just to be as equal as possible
    //   with the standard class. (Done for profile, profiles, subprofiles.)

    /**
     * Checks and reformats the structure for a database/collection field.
     *
     * @param $field
     *   The field structure. Passed by reference to be easier on the caller.
     *   Checks for array-ness and ID/name are done elsewhere and are assumed
     *   to be done already.
     */
    protected function normalizeFieldStructure(array &$field)
    {
        if (empty($field['type'])) {
            throw new RuntimeException("Field has no 'type' set.");
        }
        if (!is_string($field['type']) || !isset($this->allowedFieldTypes[$field['type']])) {
            throw new RuntimeException('Unknown field type ' . json_encode($field['type']) . '.');
        }

        if (in_array($field['type'], ['integer', 'float'], true)) {
            if (!isset($field['value'])) {
                throw new RuntimeException("Integer/float field has no 'value' set. Copernica requires this.");
            }
            $is_num = $field['type'] === 'float' ? is_numeric($field['value'])
                : filter_var($field['value'], FILTER_VALIDATE_INT);
            if ($is_num === false) {
                throw new RuntimeException("Integer/float field has an illegal 'value' property.");
            }
        } elseif (isset($field['value']) && !is_string($field['value'])) {
            throw new RuntimeException("{$field['type']} field has a non-string 'value' property.");
        }
        if (isset($field['value']) && is_int($field['value'])) {
            // We don't know why, but Copernica always returns string values
            // also for the default values for integer/float fields. If this
            // becomes an issue internally (e.g. with populating field values)
            // then we should amend the output for the 'databases' API call
            // instead.
            $field['value'] = (string)$field['value'];
        }
    }

    /**
     * Checks whether data has/is a 'metadata wrapper', and removes it if so.
     *
     * @param array $structure
     *   The structure that may be a metadata wrapper or a list of entities.
     *   Passed by reference because that makes for the least invasive way of
     *   (very likely not) unwrapping the data. We're not doing strict typing
     *   to be easier on the caller.
     */
    protected function checkUnwrapEntities(array &$structure)
    {
        // Kind-of arbitrary: as per normalizeDatabasesStructure(), we assume
        // the entities can have alphanumeric keys in our case. So we'll only
        // unwrap if we find exactly the 5 expected properties in metadata.
        if (isset($structure['start']) && isset($structure['limit']) && isset($structure['count']) && isset($structure['total']) && isset($structure['data'])) {
            $structure = $structure['data'];
        }
    }

    /**
     * Checks 'ID' and 'name' properties for each element.
     *
     * This checks whether any of those properties is duplicate or illegal, and
     * checks whether the key of the element is a name, an ID, or just am
     * auto-numbered key.
     *
     * Auto-numbered here means that the key of the Nth element is N-1, AND no
     * other auto-numbered keys are preceding it in the array. (This is because
     * we can't know for sure whether numeric keys are explicitly assigned or
     * auto-numbered, so we have to make assumptions.) If the keys in an array
     * array are 0, 272, 1, 2 then 0 is assumed to be auto-numbered but all the
     * following are taken as the intended value for the ID. Same for
     * 0, "name", 1, 2. The only exception is "name", 0, 1 - then 0 and 1 are
     * assumed to be auto-numbered... because 0 is not a valid ID.
     *
     * This also fills $this->knownValues with any known IDs/names and clears
     * the optional metadata wrapper / $single_value_id_property property from
     * the structure.
     *
     * @param array[] $structure
     *   The structure of arrays whose keys and 'ID'/'name' values we'll check.
     *   Passed by reference because it can changes the structure even if it
     *   doesn't change the keys/IDs/names at the moment. We're not doing
     *   strict typing to be easier on the caller / to do more checks here.
     * @param string $known_ids_key
     *   Key by which our array of known unique IDs is stored.
     * @param string $known_names_key
     *   Key by which our array of known unique names is stored.
     * @param bool $name_required
     *   (Optional) indicator for the 'name' property being required.
     * @param string $single_value_id_property
     *   (Optional) property name to check; IF it is set, it must be the same
     *   value everywhere. Then, remove the property from all elements.
     *
     * @return mixed
     *   If $single_value_id_property was passed and at least one value for
     *   the property was found: that value. Otherwise null.
     */
    protected function checkKeysIdsNames(&$structure, $known_ids_key, $known_names_key, $name_required = false, $single_value_id_property = '')
    {
        if (!is_array($structure)) {
            // We're passing $known_ids_key to have at least some kind of
            // specification, and we have a vaguely appropriate naming
            // convention. We won't be specific in all below messages, though.
            throw new RuntimeException("'$known_ids_key' structure is not an array.");
        }
        $this->checkUnwrapEntities($structure);

        // We don't have to define $previous_key because it will never be used
        // in the first iteration of the loop. Except, IDE complains.
        $previous_key = '';
        $single_value_id = null;
        $autonum_index = 0;
        foreach ($structure as $key => &$element) {
            if (!is_array($element)) {
                // We're passing $known_ids_key to have at least some kind of
                // specification, and we have a vaguely appropriate naming
                // convention. We won't be specific in all below messages, though.
                throw new RuntimeException("Non-array value '$element' found inside what is supposed to be a list of elements.");
            }

            // Keys are never numeric strings. Use === to distinguish 0 vs ''.
            $key_is_autonum = $key === $autonum_index;
            if ($key_is_autonum) {
                // Already increase; we don't need it in the rest of the loop.
                $autonum_index++;
            } elseif ($key === 0) {
                // This element comes after a specifically assigned key. If the
                // previous key was numeric, that means 0 is specifically
                // assigned here.
                if (is_numeric($previous_key)) {
                    throw new RuntimeException("Explicitly assigned key 0 is not a legal ID.");
                }
                // We can't see if 0 was explicitly assigned by the caller but
                // we'll assume it wasn't. So... we're turning auto-numbering
                // back on.
                $key_is_autonum = true;
                $autonum_index = 1;
            } else {
                // Once a key is not autonumbered, any following keys are also
                // assumed not to be. (See method doc.) Ensure this through
                // $autonum_index and the above ===.
                $autonum_index = -1;
            }

            if (isset($element['ID'])) {
                // We're OK with 'ID' being an 'integer' numeric string.
                if (filter_var($element['ID'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                    // We don't have the perfect identifier, but (unlike most other
                    // exceptions) we're taking a stab.
                    throw new RuntimeException("'ID' property for $known_ids_key $key is not a positive integer.");
                }

                if (isset($this->knownValues[$known_ids_key][$element['ID']])) {
                    throw new RuntimeException("ID {$element['ID']} was already seen before.");
                }
                $this->knownValues[$known_ids_key][$element['ID']] = true;
            }
            if (isset($element['name'])) {
                // The UI does not allow fields starting with an underscore or
                // a number, which is great because then we can use those for
                // 'reserved' fields internally.
                // @todo try creating one through the API and see if that fails too.
                //   Then document that. (Probably write a test for it, as a form of documentation.)
                if (!is_string($element['name']) || !preg_match('/^[a-zA-z][a-zA-z0-9_]*$/', $element['name'])) {
                    throw new RuntimeException("Name '{$element['name']}' is not a legal name.");
                }
                if (!is_string($element['name']) || $element['name'] === '') {
                    throw new RuntimeException("Name '{$element['name']}' is not a nonempty string.");
                }

                if (isset($this->knownValues[$known_names_key][$element['name']])) {
                    throw new RuntimeException("Name '{$element['name']}' was already seen before.");
                }
                $this->knownValues[$known_names_key][$element['name']] = true;
            }

            if (is_string($key)) {
                // The key is a name.
                if (isset($element['name'])) {
                    if ($key !== $element['name']) {
                        throw new RuntimeException("Key '$key' and name '{$element['name']}' differ.");
                    }
                } else {
                    if (isset($this->knownValues[$known_names_key][$key])) {
                        throw new RuntimeException("Name '$key' (in array key) was already seen before.");
                    }
                    $this->knownValues[$known_names_key][$key] = true;
                }
            } elseif ($key >= 0) {
                // The key is either an ID or an auto-numbered index that can be
                // ignored in this method.
                if ($name_required && !isset($element['name'])) {
                    // We don't have the perfect identifier, but (unlike most
                    // other exceptions) we're taking a stab.
                    throw new RuntimeException("'name' property is required for $known_ids_key $key.");
                }

                if (!$key_is_autonum) {
                    if (isset($element['ID'])) {
                        if ($key != $element['ID']) {
                            throw new RuntimeException("Key $key and ID {$element['ID']} differ.");
                        }
                    } else {
                        if (isset($this->knownValues[$known_ids_key][$key])) {
                            throw new RuntimeException("ID $key (in array key) was already seen before.");
                        }
                        $this->knownValues[$known_ids_key][$key] = true;
                    }
                }
            } else {
                // Negative integers are not valid 'ID' values (and also are
                // not auto-numbered).
                throw new RuntimeException("Key $key is a negative integer.");
            }

            // Optionally check/take/remove a property name.
            if ($single_value_id_property && isset($element[$single_value_id_property])) {
                // Must be equal to any other value found earlier
                if (isset($single_value_id) && $single_value_id != $element[$single_value_id_property]) {
                    throw new RuntimeException("'$single_value_id_property property ({$element[$single_value_id_property]}) was already seen before, with a different value ($single_value_id).");
                }
                $single_value_id = $element[$single_value_id_property];
                unset($element[$single_value_id_property]);
            }

            // Also check if all the properties inside a single element are
            // non-numeric; otherwise we assume that some array has too many /
            // few dimensions.
            foreach ($element as $property => $value) {
                if (is_int($property)) {
                    throw new RuntimeException("Numeric property $property found inside '$known_ids_key' element; the structure is likely malformed.");
                }
            }


            $previous_key = $key;
        }

        return $single_value_id;
    }

    /**
     * Moves 'ID' values into array keys.
     *
     * This should be done only on an array structure that contains no
     * invalid keys / 'ID' values as per checkKeysIdsNames(). After this,
     * all array keys are 'known not-auto-numbered' values and all 'ID' values
     * are removed / transposed to keys. Unknown keys are assigned semi random
     * numbers higher than the highest known key/ID.
     *
     * @param array[] $structure
     *   The structure that was passed into checkKeysIdsNames() before.
     * @param string $known_ids_key
     *   Key by which our array of known unique IDs is stored; doubles as
     *   a prefix for random names to assign to all elements that don't have
     *   one.
     * @param string $known_names_key
     *   Key by which our array of known unique names is stored.
     *
     * @return array[]
     *   The modified structure.
     */
    protected function transformKeys(array $structure, $known_ids_key, $known_names_key)
    {
        // We rebuild the full structure rather than replacing inside the
        // original structure, so we don't have any clashes between 'known' IDs
        // and auto-numbered keys which are still inside the structure (and
        // will be replaced later).
        $new_structure = [];
        $autonum_index = 0;
        if (empty($this->knownValues[$known_ids_key])) {
            $highest_id = 0;
        } else {
            ksort($this->knownValues[$known_ids_key]);
            end($this->knownValues[$known_ids_key]);
            $highest_id = key($this->knownValues[$known_ids_key]);
        }
        foreach ($structure as $key => $element) {
            // See checkKeysIdsNames() for the original logic.
            $key_is_autonum = $key === $autonum_index;
            if ($key_is_autonum) {
                $autonum_index++;
            } elseif ($key === 0) {
                $key_is_autonum = true;
                $autonum_index = 1;
            } else {
                $autonum_index = -1;
            }

            // Get and/or calculate the (new) ID, and unset 'ID'.
            if (isset($element['ID'])) {
                // We know the ID is valid and not a duplicate; the key is
                // either unimportant or equal to this ID value.
                $new_id = $element['ID'];
                unset($element['ID']);
            } elseif (!$key_is_autonum && !is_string($key)) {
                $new_id = $key;
            } else {
                // Assign random ID.
                $highest_id += rand(1, 3);
                $new_id = $highest_id;
                $this->knownValues[$known_ids_key][$new_id] = true;
            }

            // We know if the key is a string, the 'name' either doesn't exist
            // or is equal to the key.
            if (!isset($element['name'])) {
                if (is_string($key)) {
                    $element['name'] = $key;
                } else {
                    // Assign random name named after the ID. We need to add
                    // an underscore because $known_ids_key may end with a
                    // digit, which could cause duplicates otherwise.
                    $prefix = ucfirst($known_ids_key);
                    if (is_numeric(substr($prefix, -1))) {
                        // Prevent possible duplicates
                        $prefix .= '_';
                    }
                    while (isset($this->knownValues[$known_names_key][$prefix . $new_id])) {
                        // That's odd, this name is taken up by another element
                        // already. Keep adding underscores, then. (A field
                        // name cannot contain hyphens or spaces.)
                        $prefix .= '_';
                    }
                    $element['name'] = $prefix . $new_id;
                    $this->knownValues[$known_names_key][$prefix . $new_id] = true;
                }
            }

            $new_structure[$new_id] = $element;
        }

        return $new_structure;
    }

    /**
     * Initializes a database / table(s) for tests.
     *
     * This is done by the constructor, but can also be called by tests
     * themselves to clean out the database.
     */
    public function initDatabase()
    {
        // Drop tables first - in reverse order of dependency. (It doesn't
        // matter, but feels better.)
        foreach ($this->databasesStructure as $db_id => $database) {
            if (!empty($database['collections'])) {
                foreach ($database['collections'] as $coll_id => $collection) {
                    if (!empty($collection['fields'])) {
                        $this->pdoConnection->exec("DROP TABLE subprofile_$coll_id");
                    }
                }
            }
            if (!empty($database['fields'])) {
                $this->pdoConnection->exec("DROP TABLE IF EXISTS profile_$db_id");
            }
        }
        $this->pdoConnection->exec('DROP TABLE IF EXISTS subprofile_coll');
        $this->pdoConnection->exec('DROP TABLE IF EXISTS profile_db');

        $driver = $this->pdoConnection->getAttribute(PDO::ATTR_DRIVER_NAME);
        switch ($driver) {
            case 'sqlite':
                // We'll need a table for profile id -> database mapping, if we
                // don't want to look into each individual database's profile
                // table to resolve calls like profile/$id/subprofiles.
                // Same for subprofile -> collection mapping. We'll likely also
                // use this for marking 'removed' (sub)profiles with DB 0.
                // @todo before implementing that, re-test a DELETE statement for a profile and see if it
                //   really does return 'removed' when we try to GET it afterwards.
                $this->pdoConnection->exec("CREATE TABLE profile_db (
                    profile_id  INTEGER PRIMARY KEY,
                    database_id INTEGER NOT NULL)");
                $this->pdoConnection->exec("CREATE TABLE subprofile_coll (
                    subprofile_id INTEGER PRIMARY KEY,
                    collection_id INTEGER NOT NULL)");
                // We don't need an index on db id; we have the database
                // specific profile table for that.

                foreach ($this->databasesStructure as $db_id => $database) {
                    if (!empty($database['fields'])) {
                        // Create table for profiles.
                        $fields = [];
                        foreach ($database['fields'] as $field) {
                            $fields[] = $this->getSqlFieldSpec($field);
                        }
                        // "_pid" is for the profile id. AUTOINCREMENT is
                        // likely necessary because of Copernica's 'removed'
                        // functionality.
                        $this->pdoConnection->exec("CREATE TABLE profile_$db_id (_pid INTEGER PRIMARY KEY AUTOINCREMENT, _created TEXT, _modified TEXT, " . implode(', ', $fields) . ')');
                    }
                    if (!empty($database['collections'])) {
                        foreach ($database['collections'] as $coll_id => $collection) {
                            if (!empty($collection['fields'])) {
                                // Create table for subprofiles.
                                $fields = [];
                                foreach ($collection['fields'] as $field) {
                                    $fields[] = $this->getSqlFieldSpec($field);
                                }
                                // "_(s)pid" are for the (sub)profile id.
                                $this->pdoConnection->exec("CREATE TABLE subprofile_$coll_id (_spid INTEGER PRIMARY KEY AUTOINCREMENT, _pid INTEGER NOT NULL, _created TEXT, _modified TEXT, " . implode(', ', $fields) . ')');
                            }
                        }
                    }
                }
                // TODO 'CREATE INDEX iii ON tablename (fieldname)' for all indexed fields.

                // In SQLite we need to set case sensitive behavior of LIKE
                // globally(which is off by default apparently). We haven't
                // tested it yet, but it feels like it's necessary for "like"
                // operators in the 'fields' parameter, and will likely never
                // need to be OFF.
                $this->pdoConnection->exec('PRAGMA case_sensitive_like=ON');
                break;

            default:
                throw new RuntimeException("No table creation methods known for driver $driver.");
        }
    }

    /**
     * Gets a field specification to use within a CREATE TABLE statement.
     *
     * @param array $field
     *   The field structure which is part of $this->databasesStructure.
     *
     * @return string
     *   The field specification.
     */
    protected function getSqlFieldSpec(array $field)
    {
        // WARNING: if you change this, change $this->allowedFieldTypes.
        $field_type_map = [
            // Text fields are case insensitive by default; 'fix' that.
            // @todo check what we should do with default vs. not null. This depends on what we get back
            //   (from the real API) after we insert a profile _without_ these fields set. Test this - and all other
            //   cases for default values - in a documented repeatable-on-live test.
            // @todo test how the real API behaves with updating fields to '', updating fields to null.
            //   Then implement that as tests and mark them as "documentation".
            // TODO test what the real API does with text length restrictions, and emulate that
            //   (implement that as tests). Note SQLite does not have length restrictions by default.
            //   See CHECK CONSTRAINTS.
            'text' => 'TEXT COLLATE NOCASE',
            'email' => 'TEXT COLLATE NOCASE',
            // We'll only start supporting phone once we need it,
            // because our TestApi will likely need to do formatting in
            // order to be truly compatible. Haven't checked yet.
            //'phone' => 'TEXT COLLATE NOCASE',
            // Same for select, maybe, not sure yet.
            //'select' => 'TEXT COLLATE NOCASE',
            // TODO also implement check constraints for integer (after testing real API behavior). And float.
            'integer' => 'INTEGER NOT NULL',
            'float' => 'REAL NOT NULL',
            // For dates it seems best to use TEXT - we'd use INTEGER
            // and convert to timestamps internally, if Copernica had
            // any concept of timezones. But since it doesn't / as long
            // as we don't see that it does, storing the date as text
            // seems better. After all, that's how we get it in POST /
            // PUT requests.
            // @TODO how to do date constraints for input (POST/PUT)? Make tests with failure cases.
            'empty_date' => 'TEXT',
            'empty_datetime' => 'TEXT',
        ];

        if (!isset($field_type_map[$field['type']])) {
            // We already checked this during construction so this should never
            // happen.
            throw new UnexpectedValueException('Unknown field type ' . json_encode($field['type']) . '.');
        }
        $spec = "{$field['name']} {$field_type_map[$field['type']]}";
        if (in_array($field['type'], ['integer', 'float'], true)) {
            // In Copernica, the default for integer/float is mandatory. We've
            // checked at construction.
            $spec .= ' DEFAULT ' . (empty($field['value']) ? 0 : $field['value']);
        } elseif (isset($field['value'])) {
            // We've checked at construction these are all strings. For some
            // mad reason, the '/databases' call returns '0' as value for all
            // @TODO check this statement. It is apparently true for YG TEST db
            //   but not for a C. live database I checked. If inconsistent, and
            //   if I think it has effects, contect Copernica support to ask
            //   what this is about. Encode this in tests that can be run on a
            //   live db.
            $spec .= " DEFAULT '{$field['value']}'";
        }

        return $spec;
    }
}

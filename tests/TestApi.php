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
     * See $timezone.
     */
    const TIMEZONE_DEFAULT = 'Europe/Amsterdam';

    /**
     * Field types that are allowed to occur in the databases structure.
     *
     * This is not the single authoritative value; it's for
     * normalizeFieldStructure() to check, but there is also getSqlFieldSpec()
     * which can't use this mapping because it needs database specific mappings
     * (and we didn't want to define those for just SQLite).
     *
     * @var string[]
     */
    protected static $allowedFieldTypes = ['text' => 1, 'email' => 1, 'select' => 1, 'integer' => 1, 'float' => 1, 'date' => 1, 'datetime' => 1, 'empty_date' => 1, 'empty_datetime' => 1];

    /**
     * Set to True to throw exceptions on Curl errors / strange HTTP codes.
     *
     * This wants to emulate the behavior of CopernicaRestAPI, to facilitiate
     * writing the 'behavior tests' which we want to be able run against both
     * this class and the live API, to verify both against each other. In
     * order to do that, those tests should set this property to True (just
     * like CopernicaRestClient always does), because keeping this False on
     * CopernicaRestAPI just isn't informative enough to guarantee behavior.
     *
     * This class may opt to throw LogicExceptions in certain circumstances
     * (like needing to report an error back to the caller) if  the value is
     * still false.
     *
     * @var bool
     */
    public $throwOnError;

    /**
     * Set to True to cause "Invalid access token" errors.
     *
     * This differs from CopernicaRestAPIbecause passing it into the
     * constructor wouldn't be compatible either. Tests which want to emulate
     * the error can just set this property to True (there's no setter method).
     *
     * @var bool
     */
    public $invalidToken;

    /**
     * Timezone which the fake API backend seems to operate in.
     *
     * This influences e.g. "created" times, which are strings without timezone
     * expression. Empty means "take PHP's default timezone setting". Some code
     * uses a constant value instead because... 1) it turns out we can't make
     * it dynamic in all cases; 2) we don't even know if it makes sense to make
     * it dynamic because we don't know if the live API has a configurable
     * timezone. If it turns out that it does, we'll have some rewriting to do
     * of the code that uses the constant.
     *
     * @var string
     */
    protected $timezone = self::TIMEZONE_DEFAULT;

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
     * Current (or last registered) REST method (verb) we're processing.
     *
     * This can be useful when logging / generating errors.
     *
     * @var string
     */
    protected $currentMethod;

    /**
     * Current (or last registered) resource name we're processing.
     *
     * This can be useful when logging / generating errors.
     *
     * @var string
     */
    protected $currentResource;

    /**
     * A log of the API calls made. For tests. Structure might change still.
     *
     * This is all POST/PUT/DELETE calls, not GET.
     *
     * @var string[]
     */
    protected $apiUpdateLog = [];

    /**
     * Normalizes an input value.
     *
     * The Copernica API basically never returns an error for unknown field
     * input for e.g. profiles; it just converts unknown types/values. This
     * code grew out of testing those conversions against the live API and
     * recreating the apparent specs. (Though in recreating it, it turns out
     * maybe the live API code wasn't designed around specs, but was just made
     * to do e.g. "whatever code like PHP strtotime() does" including all the
     * strangeness. See the unit test serving as a reverse engineered spec.)
     *
     * Of course we don't know for sure whether the live API stores the values
     * literally as converted here (because we only know the values it outputs)
     * but it's likely, given some other behavior re. defaults/required fields.
     * The only difference between how we store things (and how we are guessing
     * the live API stores things) and the output is, we store numbers as
     * numeric values (while they're always output as strings).
     *
     * This is a static method in order to be better testable (by a unit test)
     * and also to be usable by other (functional / higher level) tests.
     *
     * The best specification of how Copernica behaves w.r.t. accepting /
     * changing field input is the combination of comments in this method and
     * the data provider for the unit test.
     *
     * @param mixed $value
     *   An input value.
     * @param array $field_struct
     *   A field structure which at a minimum needs a valid 'type' property.
     * @param string $timezone
     *   (Optional) timezone in case we're formatting dates and somehow need a
     *   nonstandard timezone. (Applicability unknown.)
     *
     * @return mixed
     *   The normalized value.
     *
     * @see \CopernicaApi\Tests\TestApiBaseTest::provideDataForNormalizeInputValue()
     */
    public static function normalizeInputValue($value, array $field_struct, $timezone = self::TIMEZONE_DEFAULT)
    {
        static::normalizeFieldStructure($field_struct);

        switch ($field_struct['type']) {
            // Email field is not checked for valid e-mail. (The UI does that,
            // the REST API doesn't.) It's treated the same as string re. above
            // conversion.
            case 'email':
            case 'text':
                if (is_scalar($value)) {
                    // Convert to string. Boolean becomes "1" / "".
                    $value = (string) $value;
                } else {
                    // Other values are not ignored (because they don't
                    // become the default value for the field on inserting);
                    // they're explicitly "".
                    $value = '';
                }
                break;

            case 'select':
                // Only let the value pass if the string equivalent (e.g. "1"
                // for true) is contained in the allowed values, matching case
                // sensitively (unlike other fields). If not, the value becomes
                // empty string. (It makes no difference if the empty string is
                // among the explicitly configured values.) Right trim choices,
                // not the value itself (which leads to values with leading
                // spaces always being discarded).
                $choices = array_map('rtrim', explode("\r\n", $field_struct['value']));
                if (is_scalar($value) && in_array((string)$value, $choices, true)) {
                    $value = (string)$value;
                } else {
                    // Other values are not ignored (because they don't
                    // become the default value for the field on inserting);
                    // they're explicitly "".
                    $value = '';
                }
                break;

            case 'integer':
                // All non-empty arrays become 1, strings (including "true")
                // become 0.
                $value = (int) $value;
                break;

            case 'float':
                // Same as integer.
                $value = (float) $value;
                break;

            case 'date':
            case 'datetime':
            case 'empty_date':
            case 'empty_datetime':
                // It seems that Copernica is using strtotime() internally. We
                // use DateTime objects so we can also work well if our PHP is
                // not configured for the same timezone as Copernica is. A
                // difference is: new DateTime(''/false) is a valid expression
                // but strtotime('') is not.
                if ($value !== '' && $value !== false) {
                    // DateTime is finicky when working in the non-default
                    // timezone: if the date/time expression does not contain a
                    // timezone component, we must pass a timezone object into
                    // the constructor to make it unambiguous (because we don't
                    // want it to be interpreted in the context of PHP's
                    // default timezone) - and this timezone also gets used for
                    // output. But if the expression does contain a timezone
                    // component, the timezone argument gets completely ignored
                    // so we have to explicitly set the timezone afterwards in
                    // order to get the right output.
                    $tz_obj = new DateTimeZone($timezone ?: date_default_timezone_get());
                    try {
                        $date = new DateTime($value, $tz_obj);
                        $date->setTimezone($tz_obj);
                        if (substr($field_struct['type'], -4) === 'date') {
                            $value = $date->format('Y-m-d');
                        } else {
                            $value = $date->format('Y-m-d H:i:s');
                        }
                    } catch (\Exception $e) {
                        $value = '';
                    }
                }
                if ($value === '' || $value === false) {
                    if ($field_struct['type'] === 'date') {
                        $value = '0000-00-00';
                    } elseif ($field_struct['type'] === 'datetime') {
                        $value = '0000-00-00 00:00:00';
                    } else {
                        $value = '';
                    }
                }
        }

        return $value;
    }

    /**
     * Normalizes an input value to be able to be used as 'secret'.
     *
     * This is made static primarily because it fits with normalizeInputValue().
     *
     * @param mixed $value
     *   An input value.
     *
     * @return string
     *   A string, as it would be converted by the live API.
     */
    public static function normalizeSecret($value)
    {
        if (isset($value) && is_scalar($value)) {
            // Convert non-ASCII to question marks. I think this approximates
            // well enough (if not equals) what the live API is doing.
            $value = mb_convert_encoding($value, "ASCII");
        } else {
            $value = '1';
        }

        return $value;
    }

    /**
     * Checks and reformats the structure for a database/collection field.
     *
     * @param array $field
     *   The field structure. Passed by reference to be easier on the caller.
     *   Checks for array-ness and ID/name are done elsewhere and are assumed
     *   to be done already.
     */
    protected static function normalizeFieldStructure(array &$field)
    {
        if (empty($field['type'])) {
            throw new RuntimeException("Field has no 'type' set.");
        }
        if (!is_string($field['type']) || !isset(static::$allowedFieldTypes[$field['type']])) {
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
     *   should only happen if $this->throwOnError is false; those could
     *   represent a non-decoded response, or just false in extreme cases.
     *
     * @throws \LogicException
     *   If this class does not know how to handle input.
     * @throws \RuntimeException
     *   If 'API failure' was encounterd and $this->throwOnError is true.
     */
    public function get($resource, array $parameters = array())
    {
        // No update log; we don't care that much (yet) how often we call GET.
        $parts = $this->initRestCall('GET', $resource, false);
        if (isset($parts['error'])) {
            return $parts;
        }
        // Fairly horrible way of passing extra info back depending on 'base':
        if (in_array($parts[0], ['profile', 'subprofile'], true)) {
            // This is the database or collection ID, to save a db query later.
            $context_id = array_pop($parts);
        }

        switch ($parts[0]) {
            case '_simulate_exception':
                $return = $this->checkUrlPartCount($parts, 2, 3);
                if ($return !== true) {
                    return $return;
                }
                $this->simulateException($parts[1], isset($parts[2]) ? $parts[2] : '', 'GET');
                break;

            case '_simulate_strange_response':
                $return = $this->checkUrlPartCount($parts, 2, 2);
                if ($return !== true) {
                    return $return;
                }
                switch ($parts[1]) {
                    case 'non-array':
                        return 'This is not a json decoded body.';

                    case 'invalid-entity':
                        return ['name' => 'incomplete-entity'];
                }
                break;

            case 'collection':
                // This can be trimmed as these subpaths are implemented above
                // (or as colection/ID is implemented).
                if (!isset($parts[2]) || in_array($parts[2], ['fields', 'miniviews', 'subprofileids', 'unsubscribe'], true)) {
                    throw new LogicException("TestApi does not implement GET $resource yet.");
                }
                if ($parts[2] === 'subprofiles') {
                    // Superfluous path components are ignored.
                    return $this->getSubprofiles($parts[1], $parameters);
                }
                break;

            case 'database':
                // This can be trimmed as these subpaths are implemented above
                // (or as database/ID is implemented).
                if (!isset($parts[2]) || in_array($parts[2], ['collections', 'fields', 'interests', 'profileids', 'unsubscribe', 'views'], true)) {
                    throw new LogicException("TestApi does not implement GET $resource yet.");
                }
                if ($parts[2] === 'profiles') {
                    // Superfluous path components are ignored.
                    return $this->getProfiles($parts[1], $parameters);
                    // @todo implement profileids?
                }
                break;

            case 'profile':
                if (!isset($parts[2]) || $parts[2] === 'fields') {
                    return $this->getProfile($parts[1], $context_id, isset($parts[2]));
                } elseif ($parts[2] === 'subprofiles') {
                    // @todo check if we should return error for too many parts. Probably not -> 4, 0
                    $return = $this->checkUrlPartCount($parts, 4, 4);
                    if ($return !== true) {
                        return $return;
                    }
                    throw new LogicException("TestApi does not implement GET $resource yet.");
                } elseif (in_array($parts[2], ['interests', 'ms', 'publisher', 'files'], true)) {
                    throw new LogicException("TestApi does not implement GET $resource yet.");
                }
                break;

            case 'subprofile':
                if (!isset($parts[2]) || $parts[2] === 'fields') {
                    return $this->getSubprofile($parts[1], $context_id, isset($parts[2]));
                } elseif (in_array($parts[2], ['ms', 'publisher'], true)) {
                    throw new LogicException("TestApi does not implement GET $resource yet.");
                }
                break;

            // Throw "not implemented" for known parts which were not rejected
            // by initRestCall() yet, because that's preferred to our default
            // of "invalid method" (which is used as a fall-through for some
            // more-specific versions of paths processed above, and tested by
            // ApiBehaviorTest).
            case 'databases':
            case 'email':
            case 'ms':
            case 'publisher':
                throw new LogicException("TestApi does not implement GET $resource yet.");
        }

        return $this->returnError('Invalid method');
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
     *   (The only cases we know of true being returned so far, are calls for a
     *   'no-op' path which might also have returned an error.) False is only
     *   returned if $this->throwOnError is false.
     *
     * @throws \LogicException
     *   If this class does not know how to handle input.
     * @throws \RuntimeException
     *   If 'API failure' was encountered and $this->throwOnError is true.
     */
    public function post($resource, array $data = array())
    {
        $parts = $this->initRestCall('POST', $resource);
        if ($parts === false) {
            return $parts;
        }
        // Fairly horrible way of passing extra info back depending on 'base':
        if (in_array($parts[0], ['profile', 'subprofile'], true)) {
            // This is the database or collection ID, to save a db query later.
            $context_id = array_pop($parts);
        }

        switch ($parts[0]) {
            case '_simulate_exception':
                $return = $this->checkUrlPartCount($parts, 3, 3);
                if ($return !== true) {
                    return $return;
                }
                $this->simulateException($parts[1], $parts[2], 'POST');
                break;

            case '_simulate_strange_response':
                $return = $this->checkUrlPartCount($parts, 2, 2);
                if ($return !== true) {
                    return $return;
                }
                // We have the ability to return true as a 'strange response'
                // though we know some calls that do it. (Basically the 'PUT
                // emulating' calls we've tried so far. But those require an
                // entity to exist already, so we'll keep this endpoint too.)
                if ($parts[1] === 'true') {
                    return true;
                }
                break;

            case 'collection':
                // This can be trimmed as these subpaths are implemented above.
                if (isset($parts[2]) && in_array($parts[2], ['fields', 'miniviews'], true)) {
                    throw new LogicException("TestApi does not implement POST $resource yet.");
                }
                // The below are all valid for PUT. Supposedly they don't do
                // anything for POST but they don't return the standard
                // "invalid path" error. If we want to be super compatible / be
                // able to test/spec this in ApiBehaviorTest, we'll need to
                // implement these - but we'll need to check for each, if they
                // really don't have any effects.
                // - field: returns "No field id supplied for the field"
                // - field/X: "Failed to get the field", or returns true.
                // - intentions & unsubscribe: returns true <-we don't trust it.
                if (isset($parts[2]) && in_array($parts[2], ['field', 'intentions', 'unsubscribe'], true)) {
                    throw new LogicException("TestApi does not implement POST $resource with non-empty data yet. (Supposedly this path isn't supported, but other supposedly-unsupported POST resources exist, which do have effect. Maybe this does the same as PUT?)");
                }
                if (!isset($parts[2])) {
                    // collection/VALID-ID returns true from CopernicaRestAPI
                    // (so it must return a 2xx without X-Created header),
                    // despite it not being officially supported/documented.
                    if ($data) {
                        // @todo test if non-empty data does anything; some
                        // undocumented POST endpoints that return true, do.
                        throw new LogicException("TestApi does not implement POST $resource with non-empty data yet. (Supposedly this path isn't supported, but other supposedly-unsupported POST resources exist, which do have effect. Maybe this does the same as PUT?)");
                    }
                    return true;
                }
                // Unknown paths for valid collection -> "Invalid method"
                break;

            case 'database':
                // See 'collection' for comments.
                if (isset($parts[2]) && in_array($parts[2], ['copy', 'fields', 'views'], true)) {
                    throw new LogicException("TestApi does not implement POST $resource yet.");
                }
                // These happen to be the same paths as 'collection'.
                if (isset($parts[2]) && in_array($parts[2], ['field', 'intentions', 'unsubscribe'], true)) {
                    throw new LogicException("TestApi does not implement POST $resource with non-empty data yet. (Supposedly this path isn't supported, but other supposedly-unsupported POST resources exist, which do have effect. Maybe this does the same as PUT?)");
                }
                if (!isset($parts[2])) {
                    if ($data) {
                        // @todo test if non-empty data does anything; some
                        // undocumented POST endpoints that return true, do.
                        throw new LogicException("TestApi does not implement POST $resource with non-empty data yet. (Supposedly this path isn't supported, but other supposedly-unsupported POST resources exist, which do have effect. Maybe this does the same as PUT?)");
                    }
                    return true;
                } elseif ($parts[2] === 'profiles') {
                    $return = $this->checkUrlPartCount($parts, 3);
                    if ($return !== true) {
                        return $return;
                    }
                    $id = $this->postProfile($parts[1], $data);
                    if (is_numeric($id) || $id === false) {
                        // CopernicaRestAPI picks the ID out from the header and
                        // returns a string.
                        return $id === false ? $id : (string)$id;
                    }
                    throw new LogicException('postProfile() returned non-numeric value ' . var_export($id, true) . '.');
                }

                break;

            case 'profile':
                // See 'collection' for comments.
                if (isset($parts[2]) && in_array($parts[2], ['datarequest', 'interests'], true)) {
                    throw new LogicException("TestApi does not implement POST $resource yet.");
                }
                if (isset($parts[2]) && $parts[2] === 'fields') {
                    throw new LogicException("TestApi does not implement POST $resource yet. (Supposedly this path isn't supported, but other supposedly-unsupported POST resources exist, which do have effect. Maybe this does the same as PUT?)");
                }
                // @TODO also test 'fields' (on live API first, then change the
                //   above/below lines.)
                if (!isset($parts[2])) {
                    // POST profile/VALID-ID does the same as PUT, returns true.
                    // @TODO make explicit test for this path
                    return $this->put($resource, $data);
                } elseif ($parts[2] === 'subprofiles') {
                    // 4, not 3, because collection ID is required.
                    $return = $this->checkUrlPartCount($parts, 4);
                    if ($return !== true) {
                        return $return;
                    }
                    $id = $this->postSubprofile($parts[1], $parts[3], $data);
                    if (is_numeric($id) || $id === false) {
                        // CopernicaRestAPI picks the ID out from the header and
                        // returns a string.
                        return $id === false ? $id : (string)$id;
                    }
                    throw new LogicException('postSubprofile() returned non-numeric value ' . var_export($id, true) . '.');
                }
                break;

            case 'subprofile':
                // See 'collection' for comments.
                if (isset($parts[2]) && $parts[2] === 'datarequest') {
                    throw new LogicException("TestApi does not implement POST $resource yet.");
                }
                if (isset($parts[2]) && $parts[2] === 'fields') {
                    throw new LogicException("TestApi does not implement POST $resource with non-empty data yet. (Supposedly this path isn't supported, but other supposedly-unsupported POST resources exist, which do have effect. Maybe this does the same as PUT?)");
                }
                if (!isset($parts[2])) {
                    // POST profile/VALID-ID does the same as PUT, returns true.
                    // @TODO make explicit test for this path
                    return $this->put($resource, $data);
                }
                break;

            // Throw "not implemented" for known parts which were not rejected
            // by initRestCall() yet, because that's preferred to our default
            // of "invalid method" (which is used as a fall-through for some
            // more-specific versions of paths processed above, and tested by
            // ApiBehaviorTest).
            case 'databases':
            case 'email':
            case 'ms':
            case 'publisher':
                throw new LogicException("TestApi does not implement POST $resource yet.");
        }

        return $this->returnError('Invalid method');
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
     *   any PUT call returning an ID yet. False is only returned if
     *   $this->throwOnError is false.)
     *
     * @throws \LogicException
     *   If this class does not know how to handle input.
     * @throws \RuntimeException
     *   If 'API failure' was encounterd and $this->throwOnError is true.
     */
    public function put($resource, $data, array $parameters = array())
    {
        $parts = $this->initRestCall('PUT', $resource);
        if ($parts === false) {
            return $parts;
        }
        // Fairly horrible way of passing extra info back depending on 'base':
        if (in_array($parts[0], ['profile', 'subprofile'], true)) {
            // This is the database or collection ID, to save a db query later.
            $context_id = array_pop($parts);
        }

        // Select routes based on only the first part, regardless of the third
        // part's value, except if that was matched above & didn't fall through.
        switch ($parts[0]) {
            case '_simulate_exception':
                $return = $this->checkUrlPartCount($parts, 3, 3);
                if ($return !== true) {
                    return $return;
                }
                $this->simulateException($parts[1], $parts[2], 'PUT');
                break;

            case '_simulate_strange_response':
                $return = $this->checkUrlPartCount($parts, 2, 2);
                if ($return !== true) {
                    return $return;
                }
                // false is a 'strange response'; neither true or another value
                // (ID value only, but we don't check that) are 'strange'.
                if ($parts[1] === 'false') {
                    return false;
                }
                break;

            case 'collection':
                // This can be trimmed as these subpaths are implemented above.
                if (isset($parts[2]) && in_array($parts[2], ['field', 'intentions', 'unsubscribe'], true)) {
                    throw new LogicException("TestApi does not implement PUT $resource yet.");
                }
                if (isset($parts[2]) && in_array($parts[2], ['fields', 'miniviews'], true)) {
                    throw new LogicException("TestApi does not implement PUT $resource yet / this is officially only a POST resource but we're not sure yet if it isn't supported by PUT anyway..");
                }
                if (!isset($parts[2])) {
                    // We can likely say PUTting an empty array is supported
                    // (returns true) like we did at post(). Not tested yet.
                    throw new LogicException("TestApi does not implement PUT $resource yet.");
                }
                break;

            case 'database':
                if (isset($parts[2]) && in_array($parts[2], ['field', 'intentions', 'unsubscribe'], true)) {
                    throw new LogicException("TestApi does not implement PUT $resource yet.");
                }
                if (isset($parts[2]) && in_array($parts[2], ['copy', 'fields', 'views'], true)) {
                    throw new LogicException("TestApi does not implement PUT $resource yet / this is officially only a POST resource but we're not sure yet if it isn't supported by PUT anyway..");
                }
                if (!isset($parts[2])) {
                    // We can likely say PUTting an empty array is supported
                    // (returns true) like we did at post(). Not tested yet.
                    throw new LogicException("TestApi does not implement PUT $resource yet.");
                } elseif ($parts[2] === 'profiles') {
                    // The use of this call is explicitly different from the
                    // POST call, unlike many other cases. (Updating multiple
                    // profiles.) 'fields' parameter (for selection) behaves
                    // same as GET; 'fields' part of data (for updating) behaves
                    // same as POST. Superfluous path components are ignored.
                    return $this->putProfiles($parts[1], $data, $parameters);
                }
                break;

            case 'profile':
                if (!isset($parts[2]) || $parts[2] === 'fields') {
                    // Superfluous path components are ignored.
                    return $this->putProfile($parts[1], $context_id, $data, isset($parts[2]));
                    // @todo implement/test POST equivalent too.
                } elseif ($parts[2] === 'subprofiles') {
                    // @todo say
                    //  - explicit different application from POST, unlike many other resources
                    //  - this is mis-documented at https://www.copernica.com/en/documentation/restv2/rest-put-profile-subprofiles
                    //  - whatever else we say with database/profiles
                    //  - difference from profiles is the body data because that has no 'fields' subsection?
                    //    ^ Not tried. @todo Test.
                    //  - 'fields' query param (not body arg) behaves DIFFERENT from database//profiles:
                    //     illegal param leads to error here.
                    //     (This means we should test more, and explicitly test other tings as well I think)
                    // @todo check if we should return error for too many parts. Probably not -> 4, 0
                    //  ^ actually, probably remove because 4th parameter not required? See database/profiles
                    $return = $this->checkUrlPartCount($parts, 4, 4);
                    if ($return !== true) {
                        return $return;
                    }
                    throw new LogicException("TestApi does not implement PUT $resource yet.");
                } elseif (isset($parts[2]) && $parts[2] === 'interests') {
                    throw new LogicException("TestApi does not implement PUT $resource yet.");
                } elseif (isset($parts[2]) && $parts[2] === 'datarequest') {
                    throw new LogicException("TestApi does not implement PUT $resource yet / this is officially only a POST resource but we're not sure yet if it isn't supported by PUT anyway..");
                }

                break;

            case 'subprofile':
                if (!isset($parts[2]) || $parts[2] === 'fields') {
                    // Superfluous path components are ignored.
                    return $this->putSubprofile($parts[1], $context_id, $data, isset($parts[2]));
                    // @todo implement/test POST equivalent too.
                } elseif ($parts[2] === 'datarequest') {
                    throw new LogicException("TestApi does not implement PUT $resource yet / this is officially only a POST resource but we're not sure yet if it isn't supported by PUT anyway..");
                }
                break;

            // Throw "not implemented" for known parts which were not rejected
            // by initRestCall() yet, because that's preferred to our default
            // of "invalid method" (which is used as a fall-through for some
            // more-specific versions of paths processed above, and tested by
            // ApiBehaviorTest).
            case 'databases':
            case 'email':
            case 'ms':
            case 'publisher':
                throw new LogicException("TestApi does not implement PUT $resource yet.");
        }

        return $this->returnError('Invalid method');
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
     *   Failure only if $this->throwOnError is false.
     *
     * @throws \LogicException
     *   If this class does not know how to handle input.
     * @throws \RuntimeException
     *   If 'API failure' was encounterd and $this->throwOnError is true.
     */
    public function sendData($resource, array $data = array(), array $parameters = array(), $method = "POST")
    {
        // This is literally the condition used by CopernicaRestAPI.
        if ($method == "POST") {
            // $parameters is not a parameter to the post() method; it's
            // unlikely this was meant to be supported.
            if ($parameters) {
                throw new LogicException('TestAPI does not support parameters sent with a POST request (until there is clarity on how to support them).');
            }
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
     * @return true
     *   Both on success and on failure if $this->throwOnError is false (for
     *   compatibility with CopernicaRestAPI).
     *
     * @throws \LogicException
     *   If this class does not know how to handle input.
     * @throws \RuntimeException
     *   If 'API failure' was encounterd and $this->throwOnError is true.
     */
    public function delete($resource)
    {
        $parts = $this->initRestCall('DELETE', $resource);
        if ($parts === true) {
            return $parts;
        }
        // Fairly horrible way of passing extra info back depending on 'base':
        if (in_array($parts[0], ['profile', 'subprofile'], true)) {
            // This is the database or collection ID, to save a db query later.
            $context_id = array_pop($parts);
        }

        // Select routes based on only the first part, regardless of the third
        // part's value, except if that was matched above & didn't fall through.
        switch ($parts[0]) {
            case '_simulate_exception':
                $return = $this->checkUrlPartCount($parts, 3, 3);
                if ($return !== true) {
                    return $return;
                }
                $this->simulateException($parts[1], $parts[2], 'DELETE');
                break;

            case 'collection':
            case 'database':
                if (!isset($parts[2]) || $parts[2] === 'field') {
                    throw new LogicException("TestApi does not implement DELETE $resource yet.");
                }
                break;

            case 'profile':
                // Like the check whether the ID is valid (in initRestCall()),
                // the check whether the profile is already deleted is done
                // before the check on superfluous path parts. That's why we'll
                // copy that "Invalid method" check into deleteProfile().
                return $this->deleteProfile($parts[1], $context_id, isset($parts[2]));

            case 'subprofile':
                // Same as profile.
                return $this->deleteSubprofile($parts[1], $context_id, isset($parts[2]));
        }

        return $this->returnError('Invalid method');
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

    /**
     * Does some initialization shared between GET/POST/PUT/DELETE calls.
     *
     * @param string $method
     * @param string $resource
     * @param bool $addLog
     *
     * @return bool|array
     *   Array with 'error' key for errors on GET; true for errors on DELETE;
     *   false for errors on other methods. If no error: numerically keyed
     *   array with URL parts. If the path is / starts with an 'entity/ID'
     *   format, the ID was validated... and depending on the 'entity type', an
     *   extra part may have been pushed onto the array. (This is a fairly
     *   horrible way of communicating extra info back to the caller, which now
     *   every caller must be aware of.)
     */
    protected function initRestCall($method, $resource, $addLog = true)
    {
        $this->currentResource = trim($resource, '/');
        $this->currentMethod = $method;
        if ($addLog) {
            $this->apiUpdateLog [] = "$method $resource";
        }
        if ($this->invalidToken) {
            return $this->returnError('Invalid access token');
        }

        // Take any empty parts off end because (as far as we've seen) the API
        // always ignores them.
        $parts = explode('/', $resource);
        while ($parts && end($parts) === '') {
            array_pop($parts);
        }
        if (!$parts) {
            return $this->returnError('Invalid method');
        }

        // Validate "entity/id" paths. (And (mini)condition/type/id.) This
        // validation is the same for all methods (unless a method totally
        // does not implement the base path, in which case we're issuing
        // "invalid method"):
        // - if the second part (id) is missing, we issue a generic path error;
        // - if the id is either invalid or not found, we issue a per-entity
        //   error. (If a sub-path in the 3rd-xth part is invalid/unknow, that
        //   doesn't matter; the API still issues the 'invalid ID' error.)
        if (
            in_array($parts[0], [
                'collection',
                'condition',
                'database',
                'minicondition',
                'miniview',
                'minirule',
                'profile',
                'rule',
                'subprofile',
                'view',
            ])
        ) {
            $return = $this->checkUrlPartCount($parts, 2);
            if ($return !== true) {
                return $return;
            }
            switch ($parts[0]) {
                case 'collection':
                    if (
                        filter_var($parts[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false
                        || !isset($this->knownValues['collection'][$parts[1]])
                    ) {
                        return $this->returnError('No collection with given ID');
                    }
                    break;

                case 'database':
                    if (
                        filter_var($parts[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false
                        || !isset($this->knownValues['database'][$parts[1]])
                    ) {
                        return $this->returnError('No database with supplied ID');
                    }
                    break;

                case 'minicondition':
                    // We have not looked at this beyond seeing that GET is not
                    // implemented, and POST seems to be. So let's tweak the
                    // errors to that.
                    if ($method !== 'GET') {
                        throw new LogicException("TestApi does not implement $method $resource yet.");
                    }
                    return $this->returnError('Invalid method');

                case 'profile':
                    if (filter_var($parts[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                        // Note "entity", not "profile". (For all calls.)
                        return $this->returnError('No entity with supplied ID');
                    }
                    $id = $this->dbFetchField('SELECT database_id FROM profile_db where profile_id = :id', [':id' => $parts[1]]);
                    if ($id) {
                        array_push($parts, $id);
                    } else {
                        // Note "entity", not "profile". (For all calls.)
                        return $this->returnError('No entity with supplied ID');
                    }
                    break;

                case 'subprofile':
                    if (filter_var($parts[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                        return $this->returnError('No subprofile with supplied ID');
                    }
                    $id = $this->dbFetchField('SELECT collection_id FROM subprofile_coll where subprofile_id = :id', [':id' => $parts[1]]);
                    if ($id) {
                        array_push($parts, $id);
                    } else {
                        return $this->returnError('No subprofile with supplied ID');
                    }
                    break;

                default:
                    throw new LogicException("TestApi does not implement $method $resource yet.");
            }
        }

        return $parts;
    }

    /**
     * Checks number of parts in a URL.
     *
     * @param array $parts
     *   Parts of the resource/URL, already stripped from 'trailing' empty ones.
     * @param int $min_parts
     *   The minimum number of parts that must be present, in other words, the
     *   minimum number of slashes that a URL must have plus 1.
     * @param int $max_parts
     *   (Optional) maximum number of parts that can be present.
     *
     * @return mixed
     *   True if OK (which can also mean too many path components if
     *   $return_if_too_many is true); false or array should be returned to
     *   caller of original 'REST' method (if $this->throwOnError is false).
     */
    protected function checkUrlPartCount($parts, $min_parts, $max_parts = 0)
    {
        if ($min_parts && !isset($parts[$min_parts - 1])) {
            return $this->returnError('Invalid request, element ' . ($min_parts - 1) . " is missing in 'directory' path");
        }
        if ($max_parts && isset($parts[$max_parts])) {
            return $this->returnError('Invalid method');
        }

        return true;
    }

    /**
     * Throws an exception, mentioning "simulated error".
     *
     * This is used to explicitly throw exeptions similar to CopernicaRestAPI,
     * to be able to test error handling of a caller (in cases where there's no
     * easy other way to guarantee exceptions being thrown); this class has
     * implemented some extra 'fake endpoints' to explicitly throw these
     * exceptions, which call this method.
     *
     * @param string $type
     *   The type of exception.
     * @param int $code
     *   The code for the exception.
     * @param string $method
     *   The HTTP method (verb) that was supposedly used to make the API call.
     *
     * @see TestApi::returnError()
     */
    protected function simulateException($type, $code, $method)
    {
        if (!in_array($method, ['GET', 'POST', 'PUT', 'DELETE'], true)) {
            throw new InvalidArgumentException("Unknown HTTP method $method.");
        }
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
                // Partly duplicated in returnError():
                $real_code = intval($code);
                if ($real_code < 100 || ($real_code >= 200 && $real_code < 300) || $real_code >= 600) {
                    throw new LogicException("simulateException() has no support for HTTP code $real_code");
                }
                // Let's make sure to add a newline and a double quote inside
                // the payload. We don't know much about payloads in errors
                // though we've seen one response to a POST request contain a
                // JSON body. It may not be desirable to send an "error" along
                // with every HTTP code, but it'll do for now.
                $payload = "{\"error\":\r\n{\"message\":\"Simulated message returned along with HTTP code $real_code.\"}}";
                // Non-GET methods in CopernicaRestAPI add the headers to the
                // value returned from Curl - that's just a hardcoded
                // inconsistency (if we can call it that).
                if ($method !== 'GET') {
                    // Just fYI: HTTP 1.1 mandates \r\n between headers and
                    // before the body. The body itself (above) might contain
                    // anything; we just inserted the \r because we can.
                    switch ("$method/$code") {
                        case 'DELETE/400-alreadyremoved':
                            $payload = "HTTP/1.1 $real_code No descriptive string\r\nX-Fake-Header: fakevalue\r\n\r\n{\"error\":\r\n{\"message\":\"FAKE-ENTITY has already been removed\"}}";
                            break;

                        case 'PUT/303':
                            // Regular 303 (where CopernicaRestClient throws no
                            // exception) has no body.
                            $payload = "HTTP/1.1 $real_code See Other (Not Really)\r\nLocation: https://test.test/someentity/1\r\n\r\n";
                            break;

                        case 'PUT/303-nolocation':
                            // No location, also no body.
                            $payload = "HTTP/1.1 $real_code See Other (Not Really)\r\nX-Fake-Header: fakevalue\r\n\r\n";
                            break;

                        case 'PUT/303-withbody':
                            $payload = "HTTP/1.1 $real_code See Other (Not Really)\r\nLocation: https://test.test/someentity/1\r\n\r\n$payload";
                            break;

                        default:
                            $payload = "HTTP/1.1 $real_code No descriptive string\r\nX-Fake-Header: fakevalue\r\nX-Fake-Header2: fakevalue2\r\n\r\n$payload";
                    }
                }
                throw new RuntimeException("$method _simulate_exception/$type/$code returned HTTP code $real_code. Response contents: \"$payload\".", $real_code);

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
     * Throws exception with code 303 and 'current resource', or returns true.
     *
     * When we rewrite CopernicaRestApi logic re. throwing exceptions and move
     * it into CopernicaRestClient for simplification, and remove the
     * throwOnError property as a result, this method goes away.
     *
     * @return true
     *   Only if $this->throwOnError is false.
     */
    protected function return303()
    {
        // The live API puts no version identifiers into the location.
        // Deriving the entity path from the resource path is a bit of
        // guesswork; until now, we know an (sub)profile/X/fields needs to be
        // shortened to (sub)profile/X.
        $entity_path = substr($this->currentResource, -7) === '/fields'
            ? substr($this->currentResource, 0, strlen($this->currentResource) - 7) : $this->currentResource;
        $payload = "HTTP/1.1 303 See Other (Not Really)\r\nLocation: https://test.test/$entity_path\r\n\r\n";
        if ($this->throwOnError) {
            throw new RuntimeException("{$this->currentMethod} {$this->currentResource} returned HTTP code 303. Response contents: \"$payload\".", 303);
        }
        return true;
    }

    /**
     * Returns an error body or throws an exception.
     *
     * @param string $error_message
     *   The error message.
     * @param int $http_code
     *   (Optional) HTTP response code that was supposedly returned from the
     *   API, which causes this error to be returned.
     * @param string $method
     *   (Optional) HTTP method (verb) that was supposedly used for API call.
     * @param string $resource
     *   (Optional) API resource that was supposedly called.
     *
     * @return mixed
     *   If $this->throwOnError is false: error values as they would be
     *   returned by the CopernicaRestAPI method:
     *   - GET: an array with an error value.
     *   - POST/PUT: false for HTTP 400 (which is basically every error), true
     *     otherwise.
     *   - DELETE: true.
     */
    protected function returnError($error_message, $http_code = 400, $method = '', $resource = '')
    {
        if (!is_string($error_message)) {
            // We'd JSON-encode it incorrectly.
            throw new InvalidArgumentException('Error message is not a string.');
        }
        $method = $method ?: $this->currentMethod;
        $resource = $resource ?: $this->currentResource;
        if (!in_array($method, ['GET', 'POST', 'PUT', 'DELETE'], true)) {
            throw new InvalidArgumentException("Unknown HTTP method $method.");
        }
        if ($http_code < 100 || ($http_code >= 200 && $http_code < 300) || $http_code >= 600) {
            throw new InvalidArgumentException("returnError() has no support for HTTP code $http_code");
        }

        if ($this->throwOnError) {
            // Put the error message in the body, and wrap it in an exception
            // with a non descriptive message - to emulate
            // CopernicaRestAPI::curlExec(). Code partly duplicated
            // from simulateException(); inserting \r\n in a differrent place
            // just for the heck of it.
            $payload = "{\"error\":{\"message\":\r\n" . json_encode($error_message) . '}}';
            if ($method !== 'GET') {
                if ($method === 'PUT' && $http_code == 303) {
                    throw new InvalidArgumentException("Not sure how to throw 303.. (Do we include error message in body? Location header? A combination of both supposedly never happens and will break CopernicaRestClient.)");
                }
                $payload = "HTTP/1.1 $http_code No descriptive string\r\nX-Fake-Header: fakevalue\r\nX-Fake-Header2: fakevalue2\r\n\r\n$payload";
            }
            throw new RuntimeException("$method $resource returned HTTP code $http_code. Response contents: \"$payload\".", $http_code);
        }

        if ($method === 'GET') {
            return ['error' => ['message' => $error_message]];
        }
        return $method === 'DELETE' || $http_code != 400;
    }

    /**
     * API endpoint emulation: retrieves a single profile or its fields.
     *
     * @param int $profile_id
     *   A profile ID, which must be validated already as existing.
     * @param int $database_id
     *   The related database ID.
     * @param bool $fields_only
     *   If true, get only the fields. (A separate resource exists for this).
     *
     * @return array
     *   A structure as returned in the API response. (This could be an error,
     *   if $this->throwOnError is false, which is also an array provided we're
     *   called from a GET method.)
     */
    protected function getProfile($profile_id, $database_id, $fields_only)
    {
        $result = $this->dbFetchAll("SELECT * FROM profile_$database_id WHERE _pid = :id", [':id' => $profile_id]);
        $row = current($result);
        if (empty($row)) {
            throw new LogicException("TestApi: internal inconsistency; profile data doesn't exist in the table which the 'database pointer' references.");
        }
        $id = $row['_pid'];
        $secret = $row['_secret'];
        // We could convert removed/created/updated to a date/int and then back
        // to a string for compatibility, but as long as we're only using
        // SQLite that's unnecessary.
        $created = $row['_created'];
        $modified = $row['_modified'];
        $removed = $row['_removed'];
        unset($row['_pid'], $row['_secret'], $row['_created'], $row['_modified'], $row['_removed']);
        $fields = array_map(function ($value) use ($removed) {
            return $removed ? '' : (string) $value;
        }, $row);
        if ($fields_only) {
            return $fields;
        }
        // For some reason, everything is returned as a string. If a profile is
        // 'removed' all fields are an empty string.
        return [
            'ID' => (string)$id,
            'fields' => $fields,
            // We don't support interests yet. @todo
            'interests' => [],
            'database' => (string)$database_id,
            'secret' => $secret,
            'created' => $created,
            'modified' => $modified,
            'removed' => isset($removed) ? $removed : false,
        ];
    }

    /**
     * API endpoint emulation: retrieves a single subprofile.
     *
     * @param int $subprofile_id
     *   A subprofile ID, which must be validated already as existing.
     * @param int $collection_id
     *   The related collection ID.
     * @param bool $fields_only
     *   If true, get only the fields. (A separate resource exists for this).
     *
     * @return array
     *   A structure as returned in the API response. (This could be an error,
     *   if $this->throwOnError is false, which is also an array provided we're
     *   called from a GET method.)
     */
    protected function getSubprofile($subprofile_id, $collection_id, $fields_only)
    {
        $result = $this->dbFetchAll("SELECT * FROM subprofile_$collection_id WHERE _spid = :id", [':id' => $subprofile_id]);
        $row = current($result);
        if (empty($row)) {
            throw new LogicException("TestApi: internal inconsistency; subprofile data doesn't exist in the table which the 'database pointer' references.");
        }
        $id = $row['_spid'];
        $profile_id =  $row['_pid'];
        $secret = $row['_secret'];
        // We could convert removed/created/updated to a date/int and then back
        // to a string for compatibility, but as long as we're only using
        // SQLite that's unnecessary.
        $created = $row['_created'];
        $modified = $row['_modified'];
        $removed = $row['_removed'];
        unset($row['_spid'], $row['_pid'], $row['_secret'], $row['_created'], $row['_modified'], $row['_removed']);
        $fields = array_map(function ($value) use ($removed) {
            return $removed ? '' : (string) $value;
        }, $row);
        if ($fields_only) {
            return $fields;
        }
        // For some reason, everything is returned as a string. If a
        // subprofile is 'removed' all fields are an empty string.
        return [
            'ID' => (string)$id,
            'secret' => $secret,
            'fields' => $fields,
            'profile' => (string)$profile_id,
            'collection' => (string)$collection_id,
            'created' => $created,
            'modified' => $modified,
            'removed' => isset($removed) ? $removed : false,
        ];
    }

    /**
     * API endpoint emulation: retrieves profiles.
     *
     * @param int $database_id
     *   A database ID, which must be validated already as existing.
     * @param array $parameters
     *   Request parameters, whose names must be lower case matched but field
     *   names in 'fields' are matched case insensitively.
     *
     * @return array
     *   A structure as returned in the API response. (This could be an error,
     *   if $this->throwOnError is false, which is also an array provided we're
     *   called from a GET method.)
     */
    protected function getProfiles($database_id, array $parameters)
    {
        // The official docs for database/X/profiles and
        // collection|profile/X/subprofiles about a 'dataonly' parameter. This
        // doesn't unwrap the 'data', and still returns 'total', so if it does
        // anything, I don't know what.
        // @TODO test it. If it doesn't do anything for profile and sub, tell
        //   Copernica. See bottom of ApiBehaviorTest.
        if (!empty($parameters['dataonly'])) {
            throw new LogicException("TestApi does not implement 'dataonly' parameter yet.");
        }

        $this->normalizeEntitiesParameters($parameters);
        $data = [];
        $where = '';
        if ($parameters['total'] || ($parameters['start'] >= 0 && $parameters['limit'] > 0)) {
            $pdo_parameters = $this->getSqlConditionsFromParameters($parameters, $database_id);
            if ($pdo_parameters) {
                $where = ' WHERE ' . array_pop($pdo_parameters);
            }
            if ($parameters['start'] >= 0 && $parameters['limit'] > 0) {
                $orderby = ' ORDER BY ' . $this->getSqlOrderByFromParameters($parameters, '_pid', $database_id);
                // Let's just always limit.
                $pdo_parameters_copy = $pdo_parameters;
                $pdo_parameters_copy['limit'] = $parameters['limit'];
                $pdo_parameters_copy['offset'] = $parameters['start'];
                $result = $this->dbFetchAll("SELECT * FROM profile_$database_id$where$orderby LIMIT :limit OFFSET :offset", $pdo_parameters_copy);
                foreach ($result as $row) {
                    $id = $row['_pid'];
                    $created = $row['_created'];
                    $modified = $row['_modified'];
                    $secret = $row['_secret'];
                    unset($row['_pid'], $row['_secret'], $row['_created'], $row['_modified'], $row['_removed']);
                    $data[] = [
                        'ID' => (string)$id,
                        'fields' => array_map('strval', $row),
                        // We don't support interests yet. @todo
                        'interests' => [],
                        'database' => (string)$database_id,
                        'secret' => $secret,
                        'created' => $created,
                        'modified' => $modified,
                        // 'removed' is always false in entities that are in lists.
                        'removed' => false,
                    ];
                }
            }
        }
        $response = [
            'start' => $parameters['start'],
            'limit' => $parameters['limit'],
            'count' => count($data),
            'data' => $data
        ];
        if ($parameters['total']) {
            $response['total'] = (int)$this->dbFetchField("SELECT COUNT(*) FROM profile_$database_id$where", $pdo_parameters);
        }

        return $response;
    }

    /**
     * API endpoint emulation: retrieves profiles.
     *
     * @param int $collection_id
     *   A collection ID, which must be validated already as existing.
     * @param array $parameters
     *   Request parameters, whose names must be lower case matched but field
     *   names in 'fields' are matched case insensitively.
     *
     * @return array
     *   A structure as returned in the API response. (This could be an error,
     *   if $this->throwOnError is false, which is also an array provided we're
     *   called from a GET method.)
     */
    protected function getSubprofiles($collection_id, array $parameters)
    {
        // @todo see getProfiles()
        if (!empty($parameters['dataonly'])) {
            throw new LogicException("TestApi does not implement 'dataonly' parameter yet.");
        }

        $this->normalizeEntitiesParameters($parameters);
        $data = [];
        $where = '';
        if ($parameters['total'] || $parameters['start'] >= 0 && $parameters['limit'] > 0) {
            $pdo_parameters = $this->getSqlConditionsFromParameters($parameters, $collection_id, true);
            if ($pdo_parameters) {
                $where = ' WHERE ' . array_pop($pdo_parameters);
            }
            $orderby = ' ORDER BY ' . $this->getSqlOrderByFromParameters($parameters, '_spid', $collection_id, true);
            // Let's just always limit.
            $pdo_parameters_copy = $pdo_parameters;
            $pdo_parameters_copy['limit'] = $parameters['limit'];
            $pdo_parameters_copy['offset'] = $parameters['start'];
            $result = $this->dbFetchAll("SELECT * FROM subprofile_$collection_id$where$orderby LIMIT :limit OFFSET :offset", $pdo_parameters_copy);
            foreach ($result as $row) {
                $id = $row['_spid'];
                $profile_id = $row['_pid'];
                $created = $row['_created'];
                $modified = $row['_modified'];
                $secret = $row['_secret'];
                unset($row['_spid'], $row['_pid'], $row['_secret'], $row['_created'], $row['_modified'], $row['_removed']);
                $data[] = [
                    'ID' => (string)$id,
                    'secret' => $secret,
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
            $response['total'] = (int)$this->dbFetchField("SELECT COUNT(*) FROM subprofile_$collection_id$where", $pdo_parameters);
        }

        return $response;
    }

    /**
     * API endpoint emulation: creates a new profile.
     *
     * @param int $database_id
     *   A database ID, which must be validated already as existing.
     * @param array $data
     *   Data as provided to the request. The first-level keys must be
     *   lower case but field names in 'fields' are matched case insensitively.
     *
     * @return int|false
     *   The ID of the created profile, or false if an error is encountered and
     *   $this->throwOnError is false / we're called from a non-GET method.
     */
    protected function postProfile($database_id, array $data)
    {
        if (isset($data['interests'])) {
            throw new LogicException("TestApi does not implement 'interests' for {$this->currentMethod} {$this->currentResource} yet.");
        }
        // Unknown properties / fields do not cause errors; they're ignored.
        // Only a non-array 'fields' property is not parseable.
        if (isset($data['fields']) && !is_array($data['fields'])) {
            // This only happens on POST because others can't pass non-arrays.
            return $this->returnError('Invalid data provided');
        }
        $insert_data = ['_secret' => $this->getRandomSecret()];
        if (!empty($data['fields'])) {
            $insert_data += $this->normalizeFieldsInput($data['fields'], $database_id);
        }
        $id = $this->insertEntityRecord("profile_$database_id", $insert_data);
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
     *   Data as provided to the request. The first-level keys must be
     *   lower case but field names in 'fields' are matched case insensitively.
     *
     * @return int|false
     *   The ID of the created subprofile, or false if an error is encountered
     *   and $this->throwOnError is false / we're called from a non-GET method.
     */
    protected function postSubprofile($profile_id, $collection_id, array $data)
    {
        // Profile ID was validated in initRestCall(). Collection ID was not.
        if (
            filter_var($collection_id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false
            || !isset($this->knownValues['collection'][$collection_id])
        ) {
            // Copernica returns the same message for any non-numeric argument
            // (though its concept is not consistent with the "invalid ID"
            // messages in other places.)
            return $this->returnError('Subprofile could not be created');
        }
        $data = ['_pid' => $profile_id, '_secret' => $this->getRandomSecret()]
            + $this->normalizeFieldsInput($data, $collection_id, true);
        $id = $this->insertEntityRecord("subprofile_$collection_id", $data);
        $this->dbInsertRecord('subprofile_coll', ['subprofile_id' => $id, 'collection_id' => $collection_id]);
        return $id;
    }

    /**
     * API endpoint emulation: updates a single profile or its fields.
     *
     * @param int $profile_id
     *   A profile ID, which must be validated already as existing.
     * @param int $database_id
     *   The related database ID.
     * @param array $data
     *   Data as provided to the request. The first-level keys must be
     *   lower case but field names in 'fields' are matched case insensitively.
     * @param bool $fields_only
     *   If true, the data represents only the fields. (A separate resource
     *   exists for this).
     *
     * @return true
     *   Only if $this->throwOnError is false.
     *
     * @throws \RuntimeException
     *   Code 303 on success if $this->throwOnError is true.
    */
    protected function putProfile($profile_id, $database_id, array $data, $fields_only)
    {
        $secret = null;
        if (!$fields_only) {
            // Possible data: 'fields', 'interests', 'secret'. Others are
            // ignored; a 303 response is returned.
            if (isset($data['interests'])) {
                throw new LogicException("TestApi does not implement 'interests' for {$this->currentMethod} {$this->currentResource} yet.");
            }
            if (isset($data['secret'])) {
                $secret = static::normalizeSecret($data['secret']);
            }
            // Unlike POST and PUT /database/ID/profiles, an illegal 'fields'
            // array does not generate an error.
            $data = isset($data['fields']) && is_array($data['fields']) ? $data['fields'] : [];
        }
        $data = $this->normalizeFieldsInput($data, $database_id);
        // 'modified' only gets updated if a field actually changes, so we beed
        // to read/compare the current field values.
        if ($data) {
            $result = $this->dbFetchAll("SELECT * FROM profile_$database_id WHERE _pid = :id", [':id' => $profile_id]);
            $row = current($result);
            if (empty($row)) {
                throw new LogicException("TestApi: internal inconsistency; profile data doesn't exist in the table which the 'database pointer' references.");
            }
            // 'modified' is always increased when we try to update any
            // valid fields. (Odd but true. We don't have an idea of whether
            // the values actually get updated in the database, but we'll do
            // it.)
            if (!empty($row['_removed']) || array_diff_assoc($data, $row)) {
                $data['_modified'] = $this->getDateTimeApiExpr();
            } else {
                // All fields are equal; no need to update anything.
                $data = [];
            }
        }
        if ($data || isset($secret)) {
            if (isset($secret)) {
                // Changing 'secret' alone does not update modified (so we also
                // don't mind doing an UPDATE query if the value stays equal).
                $data['_secret'] = $secret;
            }
            $this->dbUpdateRecord("profile_$database_id", $data, '_pid', $profile_id);
        }

        return $this->return303();
    }


    /**
     * API endpoint emulation: updates several profiles.
     *
     * Can also create a new profile if the 'create' parameter is nonempty and
     * no existing profiles are matched through 'fields' parameters.
     *
     * @param int $database_id
     *   A database ID, which must be validated already as existing.
     * @param array $data
     *   Data as provided to the request. The first-level keys must be
     *   lower case but field names in 'fields' are matched case insensitively.
     * @param array $parameters
     *   Request parameters, whose names must be lower case matched but field
     *   names in 'fields' are matched case insensitively.
     *
     * @return true
     *   Tf no new profile was created or if $this->throwOnError is false.
     *
     * @throws \RuntimeException
     *   Code 303 if a new profile was created and $this->throwOnError is true.
     */
    protected function putProfiles($database_id, array $data, array $parameters)
    {
        if (isset($data['interests'])) {
            throw new LogicException("TestApi does not implement 'interests' for {$this->currentMethod} {$this->currentResource} yet.");
        }
        if (isset($parameters['create'])) {
            // @TODO support for create=true (and check which values convert to
            //   true; guessing: same way as 'total' for GET profiles.)
            throw new LogicException("TestApi does not implement create=true for {$this->currentMethod} {$this->currentResource} yet.");
        }
        // Unknown properties / fields do not cause errors; they're ignored.
        // Only a non-array 'fields' property is not parseable.
        if (isset($data['fields']) && !is_array($data['fields'])) {
            // This only happens on POST because others can't pass non-arrays.
            return $this->returnError('Invalid data provided');
        }

        throw new LogicException("TestApi {$this->currentMethod} {$this->currentResource} has not been tested yet.");

        if (empty($data['fields'])) {
            $updates = $this->normalizeFieldsInput($data['fields'], $database_id);
            if ($updates) {
                $updates['_modified'] = $this->getDateTimeApiExpr();
                $pdo_parameters = $this->getSqlConditionsFromParameters($parameters, $database_id);
                $where_expression = '';
                if ($pdo_parameters) {
                    $where_expression = array_pop($pdo_parameters);
                }
                // @TODO this needs to first check if any fields actually
                //   change, and only update 'modified' in that case.
                $this->dbUpdateRecord("profile_$database_id", $updates, $where_expression, $pdo_parameters);
            }
        }
        return true;
        // @TODO if profile was created:
        return $this->return303();
    }

    /**
     * API endpoint emulation: updates subprofile.
     *
     * @param int $subprofile_id
     *   A subprofile ID, which must be validated already as existing.
     * @param int $collection_id
     *   The related collection ID.
     * @param array $data
     *   Data as provided to the request. The first-level keys must be
     *   lower case but field names in 'fields' are matched case insensitively.
     * @param bool $fields_only
     *   If true, the data represents only the fields. (A separate resource
     *   exists for this).
     *
     * @return true
     *   Only if $this->throwOnError is false.
     *
     * @throws \RuntimeException
     *   Code 303 on success if $this->throwOnError is true.
     */
    protected function putSubprofile($subprofile_id, $collection_id, array $data, $fields_only)
    {
        $secret = null;
        if (!$fields_only) {
            // Possible data: 'fields' and 'secret'. Others are ignored; a 303
            // response is returned.
            if (isset($data['secret'])) {
                $secret = static::normalizeSecret($data['secret']);
            }
            // Unlike POST and PUT /database/ID/profiles, an illegal 'fields'
            // array does not generate an error.
            $data = isset($data['fields']) && is_array($data['fields']) ? $data['fields'] : [];
        }
        $data = $this->normalizeFieldsInput($data, $collection_id, true);
        // 'modified' only gets updated if a field actually changes, so we beed
        // to read/compare the current field values.
        if ($data) {
            // 'modified' only gets updated if a field actually changes, so we
            // need to read/compare the current field values.
            $result = $this->dbFetchAll("SELECT * FROM subprofile_$collection_id WHERE _spid = :id", [':id' => $subprofile_id]);
            $row = current($result);
            if (empty($row)) {
                throw new LogicException("TestApi: internal inconsistency; subprofile data doesn't exist in the table which the 'collection pointer' references.");
            }
            // 'modified' is always increased when we try to update any
            // valid fields. (Odd but true. We don't have an idea of whether
            // the values actually get updated in the database, but we'll do
            // it.)
            if (!empty($row['_removed']) || array_diff_assoc($data, $row)) {
                $data['_modified'] = $this->getDateTimeApiExpr();
            } else {
                // All fields are equal; no need to update anything.
                $data = [];
            }
        }
        if ($data || isset($secret)) {
            if (isset($secret)) {
                // Changing 'secret' alone does not update modified (so we also
                // don't mind doing an UPDATE query if the value stays equal).
                $data['_secret'] = $secret;
            }
            $this->dbUpdateRecord("subprofile_$collection_id", $data, '_spid', $subprofile_id);
        }
        return $this->return303();
    }

    /**
     * API endpoint emulation: deletes a profile.
     *
     * This automatically deletes existing subprofiles. (But it doesn't prevent
     * new subprofiles from being created - which feels like an oversight.)
     *
     * @param int $profile_id
     *   A profile ID, which must be validated already as existing.
     * @param int $database_id
     *   The related database ID.
     * @param bool $extra_components
     *   (Optional) true if the queried resource had more than 2 components.
     *
     * @return true
     *   Both for success and for failure if $this->throwOnError is false, for
     *   convenience to delete(). (If another caller ever needs to call this
     *   method, we'll probably need to do some rewriting.)
     */
    protected function deleteProfile($profile_id, $database_id, $extra_components = false)
    {
        $removed = $this->dbFetchField("SELECT _removed FROM profile_$database_id WHERE _pid = :id", [':id' => $profile_id]);
        if ($removed) {
            $this->returnError('This profile has already been removed');
        }
        // Only after checking the ID, do we check whether the requested path
        // contained too many components.
        if ($extra_components) {
            $this->returnError('Invalid method');
        }
        // Deleting a profile empties out the fields; it doesn't remove them
        // (and still has the created/updated fields), it just adds a 'removed'
        // time. We won't empty out the fields in the database for a 'removed'
        // entity, but will take care of that on output.
        $now = $this->getDateTimeApiExpr();
        if (isset($this->databasesStructure[$database_id]['collections'])) {
            // First delete subprofiles for this profile in every collection.
            // dbUpdateRecord() also works for this (updating multiple records).
            // A NOTE: this is one example of it actually being easier if
            // _removed was in the 'global subprofile table'.
            foreach ($this->databasesStructure[$database_id]['collections'] as $collection_id => $data) {
                $this->dbUpdateRecord("subprofile_$collection_id", ['_removed' => $now], '_pid', $profile_id);
            }
        }
        $this->dbUpdateRecord("profile_$database_id", ['_removed' => $now], '_pid', $profile_id);

        return true;
    }

    /**
     * API endpoint emulation: deletes a subprofile.
     *
     * @param int $subprofile_id
     *   A subprofile ID, which must be validated already as existing.
     * @param int $collection_id
     *   The related collection ID.
     * @param bool $extra_components
     *   (Optional) true if the queried resource had more than 2 components.
     *
     * @return true
     *   Both for success and for failure if $this->throwOnError is false, for
     *   convenience to delete(). (If another caller ever needs to call this
     *   method, we'll probably need to do some rewriting.)
     */
    protected function deleteSubprofile($subprofile_id, $collection_id, $extra_components = false)
    {
        $removed = $this->dbFetchField("SELECT _removed FROM subprofile_$collection_id WHERE _spid = :id", [':id' => $subprofile_id]);
        if ($removed) {
            $this->returnError('This subprofile has already been removed');
        }
        // Only after checking the ID, do we check whether the requested path
        // contained too many components.
        if ($extra_components) {
            $this->returnError('Invalid method');
        }
        // Deleting a subprofile empties out the fields; it doesn't remove them
        // (and still has the created/updated fields), it just adds a 'removed'
        // time. We won't empty out the fields in the database for a 'removed'
        // entity, but will take care of that on output.
        $this->dbUpdateRecord("subprofile_$collection_id", ['_removed' => $this->getDateTimeApiExpr()], '_spid', $subprofile_id);

        return true;
    }

    /**
     * Generates a normalized set of parameters for 'entities' requests.
     *
     * start / limit / total are always guaranteed to be set afterwards; start
     * / limit are integers and total boolean. Note start / limit can be
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
     */
    protected function normalizeEntitiesParameters(array &$parameters, $default_limit = 100)
    {
        // Limit parameter: all nonempty arrays and true become 1,
        // false/null/strings become 0. Empty array is ignored.
        // If numeric: convert to int. The resulting number of
        // elements (and 'count' value) returned will be 0 if
        // 'limit' < 1.
        if (!isset($parameters['limit']) || $parameters['limit'] === []) {
            $parameters['limit'] = $default_limit;
        } else {
            // This works for strings/null/bool/nonempty array.
            $parameters['limit'] = (int) $parameters['limit'];
        }

        // Basically same for start - except default is 0. The returned
        // number of elements/count will be 0 if 'start' < 0.
        if (!isset($parameters['start'])) {
            $parameters['start'] = 0;
        } else {
            $parameters['start'] = (int) $parameters['start'];
        }

        // Type coercion for the 'total' argument: all arrays (including empty)
        // evaluate to true; all abs(number) < 1 evaluate to false; all other
        // strings except "true" (case insensitive) evaluate to false;. (This
        // is not equivalent to any type coercions in normalizeInputValue().)
        if (!isset($parameters['total'])) {
            $parameters['total'] = true;
        } elseif (!is_bool($parameters['total'])) {
            if (!is_scalar($parameters['total'])) {
                $parameters['total'] = true;
            } elseif (is_string($parameters['total']) && !is_numeric($parameters['total'])) {
                $parameters['total'] = strtolower($parameters['total']) === 'true';
            } else {
                $parameters['total'] = abs($parameters['total']) >= 1;
            }
        }
    }

    /**
     * Constructs SQL 'WHERE' conditions from API call parameters.
     *
     * Some rules:
     * - the 'fields' parameter name itself is matched case sensitively.
     *   (Tested on GET & PUT database/X/profiles).
     * - A non-array 'fields' parameter may or may not lead to an error.
     * - Field names are matched case insensitively.
     * - Multiple conditions for the same field are ANDed.
     * - Unknown fields are ignored.
     *
     * @param array $parameters
     *   API call parameters. Only the 'fields' parameter is looked at. The
     *   'fields' key must have the correct case; the field names themselves
     *   are fine in every case.
     * @param int $id
     *   Database ID or collection ID
     * @param bool $for_collection
     *   (Optional) if true, the ID is a database ID; collection ID otherwise.
     *
     * @return array
     *   Empty array if no SQL WHERE conditions should be applied, otherwise,
     *   array with
     *   - zero or more array keys with the names/values for the PDO
     *     parameters (all starting with 'cond');
     *   - the very last array element being the SQL fragment usable inside a
     *     WHERE statement, which uses these parameters.
     */
    protected function getSqlConditionsFromParameters(array $parameters, $id, $for_collection = false)
    {
        $pdo_parameters = [];
        // For now (profiles/subprofiles) we assume we always need to add a
        // '_removed' condition. This means the return value is never empty.
        $clauses = ['_removed IS NULL'];
        // Non-array 'fields' is just ignored.
        if (isset($parameters['fields']) && is_array($parameters['fields'])) {
            $available_fields = $this->getFields($id, $for_collection);

            // CopernicaRestApi ignores array keys; we don't check them.
            foreach ($parameters['fields'] as $key => $field_statement) {
                // - The live API ignores expressions we can't match.
                // - Spaces are just part of the expression:
                //   - 'field== name' matches values equal to " name" if the
                //     field is a string type. Non-strings match correctly.
                //   - 'field ==name' is ignored (because it matches "field ").
                // - The expression as a whole is still trimmed so
                //   ' field==name ' is not ignored / does filter on "name".
                if (!preg_match('/^(.+?)([\=\<\>\!\~]{1,2})(.*)$/', trim($field_statement), $matches)) {
                    continue;
                }
                // The live API ignores unknown fields.
                if (!isset($available_fields[strtolower($matches[1])])) {
                    continue;
                }
                $field_struct = $available_fields[strtolower($matches[1])];
                // Non-strings need to be 'normalized', which means converting
                // empty strings to a 'zero' value, and trimming them. Strings
                // can be skipped because they're already strings (and are not
                // supposed to be trimmed).
                $string_type = !in_array($field_struct['type'], ['integer', 'float', 'date', 'datetime', 'empty_date', 'empty_datetime'], true);
                $value = $string_type ? $matches[3] : static::normalizeInputValue($matches[3], $field_struct, $this->getTimezone());
                switch ($matches[2]) {
                    case '==':
                        $operator = '=';
                        break;

                    case '!=':
                        // != would actually also be good... for SQLite.
                        $operator = '<>';
                        break;

                    case '>':
                    case '<':
                    case '>=':
                    case '<=':
                        if ($string_type) {
                            $value = 0;
                        }
                        $operator = $matches[2];
                        break;

                    case '=~':
                        // The '%' should already be part of the expression
                        // passed to us so this should be OK... Haven't done a
                        // large amount of testing.
                        // @todo test case sensitivity / collation vs SQLite?
                        if (!$string_type) {
                            throw new LogicException("Comparison operator $matches[2] in 'fields' parameter has not yet been tested for use with this field typeyet.");
                        }
                        $operator = 'LIKE';
                        break;

                    case '!~':
                        // @todo test case sensitivity / collation vs SQLite?
                        if (!$string_type) {
                            throw new LogicException("Comparison operator $matches[2] in 'fields' parameter has not yet been tested for use with this field typeyet.");
                        }
                        $operator = 'NOT LIKE';
                        break;

                    default:
                        throw new LogicException("Comparison operator $matches[2] in 'fields' parameter is not supported by TestApi (yet).");
                }
                $pdo_param = 'cond' . count($clauses) . 'x';
                $pdo_parameters[$pdo_param] = $value;
                $clauses[] = "$matches[1] $operator :$pdo_param";
            }
        }

        if ($clauses) {
            array_push($pdo_parameters, implode(' AND ', $clauses));
        }
        return $pdo_parameters;
    }

    /**
     * Constructs SQL 'ORDER BY' condition from API call parameters.
     *
     * Rules:
     * - 'orderby'/'order' parameter names themselves are matched case
     *    sensitively. (Tested on GET database/X/profiles).
     * - The values are treated case insensitively.
     * - Unknown 'orderby' fields are ignored.
     * = Only one field in 'orderby'. No leading/trailing spaces.
     * - 'desc' or 'descending' leads to sorting descending. All else ascending.
     *
     * @param array $parameters
     *   API call parameters.
     * @param string $default_field
     *   The default field to order by if an 'orderby' parameter is not found.
     * @param int $id
     *   Database ID or collection ID
     * @param bool $for_collection
     *   (Optional) if true, the ID is a database ID; collection ID otherwise.
     *
     * @return string
     *   A valid "ORDER BY" clause, not including the keywords "ORDER BY".
     */
    protected function getSqlOrderByFromParameters(array $parameters, $default_field, $id, $for_collection = false)
    {
        // We only match known field names so the SQL is safe.
        if (isset($parameters['orderby']) && is_string($parameters['orderby'])) {
            $available_fields = $this->getFields($id, $for_collection);
            if (isset($available_fields[strtolower($parameters['orderby'])])) {
                $default_field = $parameters['orderby'];
            }
        }

        return $default_field . ' '
            . ((isset($parameters['order']) && is_string($parameters['order'])
                && in_array(strtolower($parameters['order']), ['desc', 'descending'], true))
                ? 'DESC' : 'ASC');
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
     * Returns a random secret string.
     */
    protected function getRandomSecret()
    {
        // Apparently 28 hex chars -> 14 bytes. Stored with (sub)profile, so
        // this is needed with every insert.]
        return bin2hex(openssl_random_pseudo_bytes(14));
    }

    /**
     * Gets date/time expression, usable as storable database value.
     *
     * @param string $time_expr
     *   (Optional) time expression.
     *
     * @return string
     *   The date expression, formatted as the API would.
     */
    private function getDateTimeApiExpr($time_expr = 'now')
    {
        // Remember if we set date in constructor that MIGHT NOT have timezone,
        //  we need to pass timezone. And if it MIGHT have a timezone by itself,
        // we must still set timezone again afterwards.
        $date = new DateTime($time_expr);
        if ($this->getTimezone()) {
            $date->setTimezone(new DateTimeZone($this->getTimezone()));
        }
        return $date->format('Y-m-d H:i:s');
    }
    
    /**
     * Strips nonexistent fields and normalizes the values as the API does.
     *
    * @param array $input
     *   Input values for field-value pairs.
     * @param int $id
     *   Database ID or collection ID
     * @param bool $is_subprofile
     *   (Optional) if true, the data is for a subprofile and $id must be a
     *   collection ID; otherwise it's for a profile and $id is a database ID.
     *
     * @return array
     *   Normalized field values (as they would apparently be saved by the live
     *   API, because that's how we get them returned from the live API, keyed
     *   by the field names with casing as used in our structure; in case of
     *   'duplicate' (case insensitive) field names, only the last one remains.
     */
    protected function normalizeFieldsInput(array $input, $id, $is_subprofile = false)
    {
        $fields = $this->getFields($id, $is_subprofile);
        // We rebuild the array rather than unsetting unknown values in $input,
        // so that we deduplicate any duplicate (differently cased) field
        // names, keeping only the last encountered one.
        $normalized = [];
        foreach ($input as $field_name => $value) {
            $field_name = strtolower($field_name);
            if (isset($fields[$field_name])) {
                $field_struct = $fields[$field_name];
                // Use the non-lowercased real fieldname as key.
                $normalized[$field_struct['name']] = static::normalizeInputValue($value, $field_struct, $this->getTimezone());
            }
        }

        return $normalized;
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
        // Modified is changed at the same time as created. (As opposed to when
        // removing a profile.)
        $record['_created'] = $record['_modified'] = $this->getDateTimeApiExpr();
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
            $param = "xx$key";
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
     * Updates a database record.
     *
     * This can also update multiple records if you 'cheat' and pass a
     * non-'primary key' field / value pair.
     *
     * @param $table
     *   Table name. Expected to be SQL safe.
     * @param array $record
     *   The record (field-value pairs), except _modified field which is added
     *   by this method. Fields are expected to be SQL safe.
     * @param string $select_expression
     *   A field in the table or an SQL clause containing PDO replacement
     *   parameters (which could also be an empty string). Which one depends on
     *   the type of the next method parameter. Expected to be SQL safe.
     * @param string|array $pdo_parameters
     *   Either the value used with the 'select field' to select record(s),
     *   or an array of PDO replacement parameters.
     *
     * @return int
     *   The number of affected rows.
     */
    protected function dbUpdateRecord($table, array $record, $select_expression, $pdo_parameters)
    {
        if (!is_array($pdo_parameters)) {
            $pdo_parameters = ["xx$select_expression" => $pdo_parameters];
            $select_expression = "$select_expression = :xx$select_expression";
        }
        if ($select_expression) {
            $select_expression = " WHERE $select_expression";
        }
        $set_clauses = [];
        foreach ($record as $key => $value) {
            // Input parameters MAYBE should not be a substring of any other
            // parameter. I'm not even sure of that because I'm not sure how
            // the PDO prepare()/bindValue() stuff exactly works. I guess
            // prepending 'xx' makes it safe enough.
            $param = "xx$key";
            $set_clauses[] = "$key = :$param";
            $pdo_parameters[$param] = $value;
        }
        return $this->dbExecuteQuery(
            "UPDATE $table SET " . implode(', ', $set_clauses) . $select_expression,
            $pdo_parameters
        );
    }

    /**
     * Retrieves (lowercased) field names from the database structure.
     *
     * @param int $id
     *   Database ID or collectino ID
     * @param bool $for_collection
     *   (Optional) if true, the ID is a collection ID; database ID otherwise.
     * @param bool $names
     *   (Optional) if true, return array of lowercased names (numerically
     *   keyed). By default, return array of field structures keyed by
     *   lowercased name (and the structure still has the un-lowercased 'name').
     *
     * @return array
     *   (Zero or more) fields/names, where the field names (either in key or
     *   value) are lowercased. (In the former case, the 'name' property has
     *   the same case as the actual field name.)
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
                    return strtolower($struct['name']);
                },
                $fields
            );
        }

        $ret = [];
        foreach ($fields as $id => $struct) {
            $struct['ID'] = $id;
            $ret[strtolower($struct['name'])] = $struct;
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
                    static::normalizeFieldStructure($field);
                }
                unset($field);
            }

            if (isset($database['collections'])) {
                foreach ($database['collections'] as $coll_id => &$collection) {
                    if (isset($collection['fields'])) {
                        $collection['fields'] = $this->transformKeys($collection['fields'], "field_$db_id", "field_name_$db_id.$coll_id");
                        foreach ($database['fields'] as &$field) {
                            static::normalizeFieldStructure($field);
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
        // unwrap if we find exactly the expected properties in metadata.
        // (We're not checking 'count' as that is not always there and we don't
        // want to do more detailed logic.)
        if (isset($structure['start']) && isset($structure['limit']) && isset($structure['total']) && isset($structure['data'])) {
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
                // Same for subprofile -> collection mapping.
                // @TODO? instead create tables named (sub)profile_meta and move
                //   _created _modified _removed _pid (for subprofile) _secret
                //   into there, without underscores. If I remember correctly,
                //   the only reason for having those in individual tables is
                //   that _modified would be easy to update - but that doesn't
                //   fly now that we need to pre-check whether we actually
                //   update anything, anyway. <- Well actually it does save 1
                //   SQL write still; because we'd need to write to 2 tables
                //   for updating each record, after having read first. Also,
                //   it would make most GET requests need a table join to get
                //   the metadata. Even though it'd be much easier to select
                //   ALL subprofiles for a certain profiles, ALL removed
                //   subprofiles, etc. But I don't know if that's ever
                //   necessary. Maybe wait until there's a real need.
                $this->pdoConnection->exec("CREATE TABLE profile_db (
                    profile_id  INTEGER PRIMARY KEY,
                    database_id INTEGER NOT NULL)");
                $this->pdoConnection->exec("CREATE TABLE subprofile_coll (
                    subprofile_id INTEGER PRIMARY KEY,
                    collection_id INTEGER NOT NULL)");
                // We don't need an index on db id; we have the database
                // specific profile table for that.

                $common_columns = '_secret TEXT NOT NULL, _created TEXT NOT NULL, _modified TEXT NOT NULL, _removed TEXT';
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
                        $this->pdoConnection->exec("CREATE TABLE profile_$db_id (_pid INTEGER PRIMARY KEY AUTOINCREMENT, " . implode(', ', $fields) . ", $common_columns)");
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
                                $this->pdoConnection->exec("CREATE TABLE subprofile_$coll_id (_spid INTEGER PRIMARY KEY AUTOINCREMENT, _pid INTEGER NOT NULL, " . implode(', ', $fields) . ", $common_columns)");
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
        // WARNING: if you change this, change static::$allowedFieldTypes.
        $field_type_map = [
            // Text fields are case insensitive by default; 'fix' that.
            // @todo check what we should do with default vs. not null. This depends on what we get back
            //   (from the real API) after we insert a profile _without_ these fields set. Test this - and all other
            //   cases for default values - in ApiBehaviorTest.
            // TODO test what the real API does with text length restrictions, and emulate that
            //   (implement that as tests). Note SQLite does not have length restrictions by default.
            //   See CHECK CONSTRAINTS.
            'text' => 'TEXT COLLATE NOCASE',
            'email' => 'TEXT COLLATE NOCASE',
            // We'll only start supporting phone once we need it,
            // because our TestApi will likely need to do formatting in
            // order to be truly compatible. Haven't checked yet.
            //'phone' => 'TEXT COLLATE NOCASE',
            // 'select' compares case sensitively on both input and output,
            // unlike other fields / query parameters.
            'select' => 'TEXT COLLATE BINARY',
            'integer' => 'INTEGER NOT NULL',
            'float' => 'REAL NOT NULL',
            // For dates it seems best to use TEXT - we'd use INTEGER and
            // convert to timestamps internally, if Copernica had any concept
            // of timezones. But since it doesn't / as long as we don't see
            // that it does, storing the date as text seems better. After all,
            // that's how we get it in POST / PUT requests. (Note TestApi has a
            // concept of timezones, but that's 'local' to this class, only so
            // we don't bake in some dependency by accident.)
            'date' => 'TEXT NOT NULL',
            'datetime' => 'TEXT NOT NULL',
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
            // @todo is this actually true? Maybe it's only a UI thing that
            //   seems to mandate this? I've seen an older live database where
            //   the 'value' property for integer fields returned from a
            //   database/ID call is "", not "0". I guess we'll only have the
            //   need and the freedom to doublecheck this once we start messing
            //   with database/ID/fields API calls, which is not a priority.
            //   (An empty default seems to clash with below TODO though.)
            $spec .= ' DEFAULT ' . (empty($field['value']) ? 0 : $field['value']);
        } elseif (isset($field['value'])) {
            // We've checked at construction these are all strings.
            $spec .= " DEFAULT '{$field['value']}'";
        }
        // @TODO if we add a numeric or date field (even a date field without
        //   default value!) to a database with existing profiles: existing
        //   records are updated to 0 or 0000-00-00 00:00:00. Notes:
        //   - not to the new default; above happens also if the new field has
        //     a non-0 default.
        //   - It's not that the date fields stay empty in the database and are
        //     converted to 0000-00-00 00:00:00 on output. (We can see that by
        //     the fact that it happens for non-required fields, and that
        //     nothing changes to existing dates if we remove 'requiredness'.)
        //   If we implement REST calls for changing defaults, we need tests
        //   to codify the above behavior. Though we should re-test before this
        //   because it seems possible that this behavior (bug?) could be
        //   changed by Copernica.

        return $spec;
    }
}

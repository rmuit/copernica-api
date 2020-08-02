<?php

namespace CopernicaApi\Tests;

use DateTime;
use DateTimeZone;
use LogicException;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use UnexpectedValueException;

/**
 * Tests for the TestApi class.
 *
 * What has test coverage at the moment:
 * - Logic in TestApi::normalizeDatabasesStructure() and its called methods.
 * - The database backend re. profiles and subprofiles (data is written
 *   correctly by POST/PUT/DELETE, and returned correctly by GET).
 *
 * Of all the test classes in this directory, this is the 'base' test. It does
 * not use other classes (like CopernicaRestClient); it tests whether TestApi
 * is OK so that other test classes can assume it is, and use it.
 *
 * Rule of thumb: tests that compare the outcome of API calls vs. contents in
 * the back end (either database or structure stored in class variables) should
 * be in this class. Tests that test the results of PUT/POST/DELETE calls vs.
 * GET calls often belong in ApiBehaviorTest instead. (There could be some
 * overlap in tests though they are implemented for differing reasons: tests
 * in here make sure TestApi itself doesn't do anything strange in the code
 * that stores/returns content, while tests in ApiBehaviorTest are written to
 * cover every kind of reproducible behavior between TestApi vs. a live API so
 * we want them to be as complete as possible. We may decide to only implement
 * certain things in ApiBehaviorTest so we're not duplicating code, in which
 * we should be clear about that in comments.)
 */
class TestApiTest extends TestCase
{
    /**
     * Tests 'normalization' of input values to values we want to store.
     *
     * @dataProvider provideDataForNormalizeInputValue
     *
     * @param $destination_field_type
     *   Type of field which the value should be written into.
     * @param $input_value
     *   The value.
     * @param $expected_value
     *   Expected value - except if the field type is 'datetime' and this is
     *   an integer; then this is a timestamp and it's on us to transform it
     *   into (an) expected date representation(s), using PHP's default
     *   timezone. (Not whatever the API timezone is set to. Reason: the
     *   timestamp is also the result of converting whatever date string, using
     *   PHP's default timezone.)
     */
    public function testNormalizeInputValue($destination_field_type, $input_value, $expected_value)
    {
        // We don't use 'value' but it's mandatory for integers/floats.
        $field_struct = ['type' => $destination_field_type, 'value' => '-3'];
        $value = TestApi::normalizeInputValue($input_value, $field_struct);
        if (is_array($expected_value)) {
            $this->assertTrue(in_array($value, $expected_value, true));
        } else {
            $this->assertSame($expected_value, $value);
        }
    }

    /**
     * Provides data for testNormalizeInputValue() and ApiBehaviorTest code.
     *
     * At the same time (like many tests, but more prominently) serves as a
     * 'specification' of how fields work on the live API. It's an incomplete
     * specification for dates, though, we depend on outside logic to encode
     *
     *
     * @return array[]
     */
    public function provideDataForNormalizeInputValue()
    {
        // 'Coincidentally' (probably not), the outcome exactly matches that of
        // PHP's type conversion to int/string/float, as I found out after
        // having tested all these cases and trying to recreate them in code...
        // But hey. Now that we have these 'specs', let's just implement them
        // as a test, for some overview.
        $data = [
            ['text', 'value', 'value'],
            ['text', true, '1'],
            ['text', false, ''],
            ['text', 0, '0'],
            ['text', -2, '-2'],
            ['text', 2.99, '2.99'],
            ['text', -2.99, '-2.99'],
            // This one isn't literally PHP's string conversion, though...
            ['text', ['any-kind-of-array'], ''],
            // Email field is not checked for valid e-mail. (The UI does that,
            // the REST API doesn't.) It's treated the same as string.
            ['email', 'value', 'value'],
            ['email', true, '1'],
            ['email', false, ''],
            ['email', 0, '0'],
            ['email', -2, '-2'],
            ['email', 2.99, '2.99'],
            ['email', -2.99, '-2.99'],
            ['email', ['any-kind-of-array'], ''],
            ['integer', 'value', 0],
            ['integer', 'true', 0],
            ['integer', true, 1],
            ['integer', false, 0],
            ['integer', -2, -2],
            ['integer', 2.99, 2],
            ['integer', -2.99, -2],
            ['integer', ['any-kind-of-array'], 1],
            ['float', 'value', 0.0],
            ['float', 'true', 0.0],
            ['float', true, 1.0],
            ['float', false, 0.0],
            ['float', -2, -2.0],
            ['float', 2.99, 2.99],
            ['float', -2.99, -2.99],
            ['float', ['any-kind-of-array'], 1.0],
        ];
        // Date specifications are a separate issue. First, we have inputs
        // whose matching output we know exactly:
        // - those that contain no dynamic parts AND whose input values don't
        //   specify a timezone.
        // - those that don't properly convert;
        // We can add these to the above array as-is; we just want to do it 4
        // times where, the empty output is "0000-00-00 00:00:00" for
        // 'datetime' fields and "0000-00-00" for 'date' fields.
        $dates1 = [
            ['2020-01-02 03:04:05', '2020-01-02 03:04:05'],
            ['2020-01-02t03:04:05', '2020-01-02 03:04:05'],
            ['2020-01-02t03:04:05.987654', '2020-01-02 03:04:05'],
            ['2020-01-02 03:4', '2020-01-02 03:04:00'],
            ['2020-01-02 03:', ''],
            ['2020-01-02 03:04 02:00', ''],
            ['2020-01-02tz03:04', ''],
            ['2020-2', '2020-02-01 00:00:00'],
            ['2020 2', ''],
            ['value', ''],
            ['true', ''],
            ['', ''],
            [true, ''],
            [false, ''],
            // Timestamps don't work. (But they do when suffixed with '@'.)
            [1596979546, ''],
            [999, ''],
            [10000, ''],
        ];
        // Then we have input where the output value is dynamic:
        // - those whose input value specifies an explicit timezone. The
        //   output is dynamic because it changes with the API timezone.
        // - those where components of the input value are either dynamic (e.g.
        //   "now", "yesterday"), or they are unspecified and substituted by
        //   parts of "now".
        // This means:
        // - we need to calculate the expected output values, and the most
        //   obvious way is to use input values for strtotime() for that. Given
        //   TestApi also uses strtotime() to interpret dates, that means
        //   - the expected output value spec is the same as the input values
        //   - the code in the test is basically a copy of the tested code
        //   - so it's hard to see that we really can trust this test
        //   ...but I don't think there's a better way. And at least the below
        //   serves as some kind of specification.
        // - given that the conversion in TestApi happens slightly later, we'll
        //   need to specify two values in order to never have the test fail;
        //   the second one being 1 second later. Compare against both.
        // Incidentally: while constructing the input/output spec it became
        // obvious that the live API must be using strtotime() internally.
        // Which makes the added usefulnes of these tests even more doubtful.
        $dates2 = [
            ['2020-01-02 03:04:05z'],
            ['2020-01-02 03:04:05utc'],
            ['2020-01-02 03:04:05+03:00'],
            // In a few cases we're providing an expected output spec, but
            // that's just for some clarification. They are completely
            // equivalent to the input value.
            ['@1577934245', '2020-01-02 03:04:05z'],
            // Note the following also converts the same for 'date' types,
            // meaning that 1959 converts to today and 1960 converts to a
            // date in 1960!
            [1959, '19:59'], // YYYY-MM-DD 19:59:00 (in API tiemzone)
            [1960], // 1960-MM-DD HH:mm:ss
            ['now'],
            ['yesterday'],
        ];

        // Add the date conversions to the data array.
        foreach (['date', 'datetime', 'empty_date', 'empty_datetime'] as $type) {
            foreach ($dates1 as $input_output) {
                if ($input_output[1] === '') {
                    if ($type === 'date') {
                        $input_output[1] = '0000-00-00';
                    } elseif ($type === 'datetime') {
                        $input_output[1] = '0000-00-00 00:00:00';
                    }
                } elseif (substr($type, -4) === 'date') {
                    // Non-empty values are alway 19 chars long for $dates1.
                    // Strip time.
                    $input_output[1] = substr($input_output[1], 0, 10);
                }
                $data[] = [$type, $input_output[0], $input_output[1]];
            }
        }
        foreach ($dates2 as $input_output) {
            $expected_output = isset($input_output[1]) ? $input_output[1] : $input_output[0];
            // Convert the output in the context of the API timezone. So far,
            // this data provider assumes TestApi::TIMEZONE_DEFAULT because
            // that's what the test does. The below is basically a copy of
            // normalizeInputValue() :-/
            $tz_obj = new DateTimeZone(TestApi::TIMEZONE_DEFAULT);
            $date = new DateTime($expected_output, $tz_obj);
            $date->setTimezone($tz_obj);

            $date2 = new DateTime();
            $date2->setTimezone($tz_obj);
            $date2->setTimestamp($date->getTimestamp() + 1);



            $data[] = ['date', $input_output[0], [$date->format('Y-m-d'), $date2->format('Y-m-d')]];
            $data[] = ['empty_date', $input_output[0], [$date->format('Y-m-d'), $date2->format('Y-m-d')]];
            $data[] = ['datetime', $input_output[0], [$date->format('Y-m-d H:i:s'), $date2->format('Y-m-d H:i:s')]];
            $data[] = ['empty_datetime', $input_output[0], [$date->format('Y-m-d H:i:s'), $date2->format('Y-m-d H:i:s')]];
        }

        return $data;
    }

    /**
     * Tests that normalization of the database structure will fail if needed.
     *
     * @dataProvider provideDataForNormalizationFailure
     */
    public function testApiDatabaseStructuresNormalizationFailure($exception_message, $structure)
    {
        $this->expectException('\RuntimeException');
        $this->expectExceptionMessage($exception_message);
        new TestApi($structure);
    }

    /**
     * Provides data for testApiDatabaseStructuresNormalizationFailure().
     *
     * @return array[]
     */
    public function provideDataForNormalizationFailure()
    {
        return [
            // The parameter is an array of database arrays. Instead, we have
            // only a string for the first database.
            ["Non-array value 'MyDatabase' found inside what is supposed to be a list of elements.", [
                'MyDatabase'
            ]],
            ["Non-array value 'x' found inside what is supposed to be a list of elements.", [
                'MyDatabase' => ['fields' => ['x']]
            ]],
            // 'fields' is one string.
            ["' structure is not an array.", [
                'MyDatabase' => ['fields' => 'x']
            ]],
            ["Numeric property 0 found inside 'database' element; the structure is likely malformed.", [
                'MyDatabase' => [
                    'fields' => [],
                    [],
                ]
            ]],
            ["Name '' is not a legal name.", [
                ['name' => '']
            ]],
            ["Name 'Database-name' is not a legal name.", [
                ['name' => 'Database-name']
            ]],
            // "Explicitly assigned key 0" can only occur after another numeric
            // key. (If after an alphanumeric key, it's assumed to be auto-
            // assigned.)
            ["Explicitly assigned key 0 is not a legal ID.", [
                25 => ['name' => 'db'],
                0 => ['name' => 'db2'],
            ]],
            ["Key -25 is a negative integer.", [
                -25 => ['name' => 'db'],
            ]],

            ["Key 'DatabaseX' and name 'DatabaseY' differ.", [
                'DatabaseX' => ['name' => 'DatabaseY']]
            ],
            ['Key 2 and ID 3 differ.', [
                2 => ['ID' => 3]]
            ],
            ['ID 5 was already seen before.', [
                ['ID' => 5],
                ['ID' => 5],
            ]],
            ['ID 5 was already seen before.', [
                5 => [],
                ['ID' => 5],
            ]],
            ['ID 5 (in array key) was already seen before.', [
                ['ID' => 5],
                5 => [],
            ]],
            ["Name 'x' was already seen before.", [
                5 => ['name' => 'x'],
                ['name' => 'x'],
            ]],
            ["Name 'y' (in array key) was already seen before.", [
                ['name' => 'y'],
                'y' => [],
            ]],

            // Collection IDs should be unique across databases.
            ["ID 1 was already seen before.", [
                ['collections' => [1 => ['Name' => 'colX']]],
                ['collections' => ['colX' => ['ID' => 1]]],
            ]],
            // Collection names should be unique inside a database. Not across
            // databases, as the next test will show (and the above, kinda.)
            ["Name 'colX' was already seen before.", [[
                'collections' => [
                    'colX' => [],
                    ['name' => 'colX'],
                ],
            ]]],
            // Collection IDs should be unique across databases.
            ["Database ID 17 set inside 'database' property of collection differs from set ID 1.", [
                1 => ['collections' => [0 => ['database' => '17']]],
            ]],

            // Field names should be unique inside a database/collection. Not
            // across collections; the next test will establish that.
            ["Name 'Myfield' was already seen before.", [[
                'collections' => [[
                    'fields' => [
                        'Myfield' => ['type' => 'text'],
                        ['name' => 'Myfield', 'type' => 'text']
                    ],
                ]],
            ]]],
            // Field IDs should be unique inside a database. Not across
            // databases; the next test will establish that.
            ["ID 1 (in array key) was already seen before.", [[
                'fields' => [1 => ['name' => 'Myfield', 'type' => 'text']],
                'collections' => [[
                    'fields' => [1 => ['name' => 'Myfield', 'type' => 'text']],
                ]],
            ]]],
            ["ID 1 was already seen before.", [[
                'fields' => [1 => ['name' => 'Myfield', 'type' => 'text']],
                'collections' => [[
                    'fields' => ['Myfield' => ['ID' => 1, 'type' => 'text']],
                ]],
            ]]],
            ["'name' property is required for field_", [[
                'fields' => [['type' => 'text']],
            ]]],
            ["Field has no 'type' set.", [[
                'fields' => [['name' => 'Myfield']],
            ]]],
            ['Unknown field type "textt".', [[
                'fields' => [['name' => 'Myfield', 'type' => 'textt']],
            ]]],
            ["Integer/float field has no 'value' set. Copernica requires this.", [[
                'fields' => [['name' => 'Myfield', 'type' => 'integer']],
            ]]],
            ["Integer/float field has an illegal 'value' property.", [[
                'fields' => [['name' => 'Myfield', 'type' => 'integer', 'value' => '0.5']],
            ]]],
            ["Integer/float field has an illegal 'value' property.", [[
                'fields' => [['name' => 'Myfield', 'type' => 'float', 'value' => '']],
            ]]],
            ["text field has a non-string 'value' property.", [[
                'fields' => [['name' => 'Myfield', 'type' => 'text', 'value' => 2]],
            ]]],
        ];
    }

    /**
     * Tests normalization of a specially crafted structure containing gotchas.
     */
    public function testApiDatabaseStructuresNormalization()
    {
        // Databases: one new assigned IDs, one 17 (through a weird mechanism
        // because we supposedly forgot to remove the 'database' properties)
        // which is named Test. Collection: IDs 34, 3 and 2, the rest newly
        // assigned. Two of them are named 'Test'. And various stuff with
        // fields. This should give a good enough idea about the code working.
        $input_structure = ['start' => 0, 'count' => 2, 'total' => 2, 'limit' => 1000, 'data' => [
            [
                'fields' => [
                    2 => ['name' => 'MyField', 'type' => 'text'],
                ],
                'collections' => [
                    '0' => [],
                    1 => ['ID' => 34, 'name' => 'Collection35'],
                    3 => [],
                    'Test' => [],
                ]
            ],
            'Test' => [
                'fields' => [
                    // We can reuse field names here. Also field IDs from the
                    // other database.
                    'MyField' => ['type' => 'text'],
                ],
                // One collection has a metadata wrapper, one does not. Very
                // strange, but OK for the test.
                'collections' => ['start' => 0, 'count' => 3, 'total' => 3, 'limit' => 1000, 'data' => [
                    ['database' => 17],
                    'Test' => ['fields' => ['MyField' => ['type' => 'text']]],
                    2 => ['name' => 'MyColl', 'database' => 17, 'fields' => ['start' => 0, 'count' => 3, 'total' => 3, 'limit' => 1000, 'data' => [
                        // We can reuse field names from the main db and/or;
                        // other collections; we cannot reuse IDs.
                        'MyField' => ['type' => 'integer', 'value' => 0],
                        // This is kinda sketchy: field names are optional.
                        // These will get an auto-assigned ID (because the
                        // first array key is 0 which is not a legal ID)...
                        ['name' => 'MyFieldA', 'type' => 'text'],
                        ['name' => 'MyFieldB', 'type' => 'text', 'ID' => 56],
                        'MyField2' => ['type' => 'text', 'ID' => 55],
                        // ...but this one (whose key is 2) will not (because
                        // it isn't 0 so we err on the side of "the user
                        // probably assigned 2). Therefore 2 (or any lower
                        // ID) should not be used as a field ID anywhere else
                        // in this particular database.
                        ['name' => 'MyFieldC', 'type' => 'text'],
                    ]]],
                ]],
            ]
        ]];
        // Don't waste time doing SQL commands for table creations etc; that's
        // not what this test is for. So pass a bogus PDO connection.
        $pdo = new PDO('sqlite::memory:');
        $api = new TestApi($input_structure, $pdo);

        // Test if the structure is normalized as expected. The challenge is
        // the randomly assigned IDs, so we make a separate method for
        // massaging/comparing the output.
        $expected = [
            '>= 1' => [
                'name' => 'DatabaseX',
                'fields' => [
                    2 => ['name' => 'MyField', 'type' => 'text'],
                ],
                'collections' => [
                    '>= 35' => ['name' => 'CollectionX'],
                    // This should make the above collection never have name
                    // "Collection35" though the ID can be 35.
                    34 => ['name' => 'Collection35'],
                    3 => ['name' => 'Collection3'],
                    '>= 36' => ['name' => 'Test'],
                ]
            ],
            '>= 2' => [
                'name' => 'Test',
                'fields' => [
                    '>= 57' => ['name' => 'MyField', 'type' => 'text'],
                ],
                'collections' => [
                    '>= 37' => ['name' => 'CollectionX'],
                    '>= 38' => ['name' => 'Test', 'fields' => [
                        '>= 58' => ['name' => 'MyField', 'type' => 'text'],
                    ]],
                    2 => ['name' => 'MyColl', 'fields' => [
                        '>= 59' => ['name' => 'MyField', 'type' => 'integer', 'value' => 0],
                        '>= 60' => ['name' => 'MyFieldA', 'type' => 'text'],
                        56 => ['name' => 'MyFieldB', 'type' => 'text'],
                        55 => ['name' => 'MyField2', 'type' => 'text'],
                        2 => ['name' => 'MyFieldC', 'type' => 'text'],
                    ]],
                ],
            ]
        ];
        $this->compareStruct($expected, $api->getDatabasesStructure());

        // Do a few tests on $api->knownValues. Don't go through it all but see
        // if some of the values whose keys we know, are as expected.
        $known_values = $api->getKnownValues();
        $this->assertEquals(2, count($known_values['database']));
        $first_db_id = key($known_values['database']);
        next($known_values['database']);
        $second_db_id = key($known_values['database']);

        $this->assertEquals(2, count($known_values['database_name']));
        $this->assertTrue(isset($known_values['database_name']['Test']));
        $this->assertTrue(isset($known_values['database_name']["Database$first_db_id"]));

        $this->assertEquals(7, count($known_values['collection']));
        $this->assertEquals(4, count($known_values["collection_name_$first_db_id"]));
        $this->assertEquals(3, count($known_values["collection_name_$second_db_id"]));
        $this->assertTrue(isset($known_values["collection_name_$first_db_id"]['Test']));
        $this->assertTrue(isset($known_values["collection_name_$second_db_id"]['Test']));
        $this->assertTrue(isset($known_values["collection_name_$second_db_id"]['MyColl']));

        $this->assertEquals(1, count($known_values["field_$first_db_id"]));
        $this->assertEquals(1, count($known_values["field_name_$first_db_id"]));
        // 7 in all of the second DB: 1 in the main db, 5 in the collection
        // whose ID we know to be 4, and 1 in another whose collection ID we
        // won't bother deducing.
        $this->assertEquals(7, count($known_values["field_$second_db_id"]));
        $this->assertEquals(1, count($known_values["field_name_$second_db_id"]));
        $this->assertEquals(5, count($known_values["field_name_$second_db_id.2"]));
    }

    /**
     * Performs some CRUD tests on (sub)profiles.
     *
     * As a side effect, this also tests that the table creation commands in
     * testApi::initDatabase() at least don't cause errors.
     *
     * To repeat the class comments: this test's purpose is to guarantee
     * TestApi specific behavior, i.e. its backend treats/stores data correctly.
     *
     * Most read/get() functionality and some post()/put()/delete() details are
     * not tested in this class; as long as we establish the backend contents
     * are OK, we can test more of their all details of GET return values in
     * ApiBehaviorTest, which enables us to also check that logic against the
     * live API. (Doing get() here wouldn't add that much because the test code
     * is just the reverse of the array construction code in e.g.
     * TestApi::getProfiles().)
     *
     * Similar tests might be implemented in CopernicaRestClientTest but their
     * purpose would only be to exercise CopernicaRestClient logic - likely to
     * test specific error situations... so they have an implicit dependency on
     * this test / on TestApi working flawlessly.
     */
    public function testProfileCrud()
    {
        $api = new TestApi([
            'Test' => [
                'fields' => [
                    'Email' => ['type' => 'email'],
                    'LastName' => ['type' => 'text'],
                    'Birthdate' => ['type' => 'empty_date'],
                ],
                'collections' => [
                    'Test' => [
                        'fields' => [
                            'Score' => ['type' => 'integer', 'value' => -1],
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

        $structure = $api->getDatabasesStructure();
        $database_id = $api->getMemberId('Test');
        $collection_id = $api->getMemberId('Test', $structure[$database_id]['collections']);
        $now = time();
        $profile = ['Email' => 'rm@wyz.biz', 'LastName' => 'Muit', 'Birthdate' => '1974-04-27'];
        $profile_id = $api->post("database/$database_id/profiles", ['fields' => $profile]);

        // Assert that a single row is stored in the backend, containing the
        // correct(ly formatted) data, with legal/expected profile ID and
        // created/modified dates.
        $this->assertNotEmpty($profile_id);
        $result = $api->dbFetchAll("SELECT * FROM profile_$database_id WHERE _pid = :id", [':id' => $profile_id], '');
        $this->assertEquals(1, count($result));
        $result = reset($result);
        // Account for possible race condition of the profile data being inserted
        // in the 'next' second.
        $this->assertTrue($result['_created'] === $this->getApiDate($api, $now)
            || $result['_created'] === $this->getApiDate($api, $now + 1));
        $this->assertEquals($result['_created'], $result['_modified']);
        $this->assertNull($result['_removed']);
        $profile_created = $result['_created'];
        unset($result['_secret'], $result['_created'], $result['_modified'], $result['_removed']);
        $this->assertEquals($profile + ['_pid' => $profile_id], $result);

        // Inserting a second profile will result in a second row, also if the
        // profile is the same.
        $profile2_id = $api->post("database/$database_id/profiles", ['fields' => $profile]);
        $this->assertNotEmpty($profile2_id);
        $this->assertGreaterThan($profile_id, $profile2_id);
        // We assume that our database was empty at the start of this test.
        $result = $api->dbFetchAll("SELECT * FROM profile_$database_id ORDER BY _pid", [], '');
        $this->assertEquals(2, count($result));
        $result = end($result);
        $this->assertTrue($result['_created'] === $this->getApiDate($api, $now)
            || $result['_created'] === $this->getApiDate($api, $now + 1));
        $this->assertEquals($result['_created'], $result['_modified']);
        $this->assertNull($result['_removed']);
        $profile2_created = $result['_created'];
        unset($result['_secret'], $result['_created'], $result['_modified'], $result['_removed']);
        $this->assertEquals($profile + ['_pid' => $profile2_id], $result);

        // Insert subprofile.
        $subprofile = ['Score' => 6, 'ActionTime' => '2020-04-27 14:15:34'];
        $subprofile_id = $api->post("profile/$profile_id/subprofiles/$collection_id", $subprofile);
        $this->assertNotEmpty($subprofile_id);
        $result = $api->dbFetchAll("SELECT * FROM subprofile_$collection_id WHERE _spid = :id", [':id' => $subprofile_id], '');
        $this->assertEquals(1, count($result));
        $result = reset($result);
        $this->assertTrue($result['_created'] === $this->getApiDate($api, $now)
            || $result['_created'] === $this->getApiDate($api, $now + 1));
        $this->assertEquals($result['_created'], $result['_modified']);
        $this->assertNull($result['_removed']);
        $subprofile_created = $result['_created'];
        unset($result['_secret'], $result['_created'], $result['_modified'], $result['_removed']);
        $this->assertEquals($subprofile + ['_spid' => $subprofile_id, '_pid' => $profile_id], $result);

        // Update a field to empty. Sleep 1 to be able to check modified time.
        sleep(1);
        $this->executePut($api, "profile/$profile_id/fields", ['LastName' => '']);
        // Still two profiles, i.e. nothing was magically duplicated.
        $result = $api->dbFetchAll("SELECT * FROM profile_$database_id ORDER BY _pid", [], '');
        $this->assertEquals(2, count($result));
        $result = reset($result);
        $this->assertNotEquals($profile_created, $result['_modified'], 'Profile modified time did not change.');
        $this->assertNull($result['_removed']);
        $profile_modified = $result['_modified'];
        unset($result['_secret'], $result['_created'], $result['_modified'], $result['_removed']);
        $this->assertEquals(['_pid' => $profile_id, 'LastName' => ''] + $profile, $result);

        // We should test the exact format of accepted fields in
        // ApiBehaviorTest / we know SQLite does a TEXT field for dates, so
        // there's nothing special about that here.
        $this->executePut($api, "subprofile/$subprofile_id/fields", ['Score' => 0]);
        // See there's still only one subprofile.
        $result = $api->dbFetchAll("SELECT * FROM subprofile_$collection_id", [], '');
        $result = reset($result);
        $this->assertNotEquals($profile_created, $result['_modified'], 'Profile modified time did not change.');
        $this->assertNull($result['_removed']);
        $subprofile_modified = $result['_modified'];
        unset($result['_secret'], $result['_created'], $result['_modified'], $result['_removed']);
        $this->assertEquals(['Score' => 0, '_spid' => $subprofile_id, '_pid' => $profile_id] + $subprofile, $result);


        // @TODO return value for deleted (s)p
        // @todo check what happens when you delete nonexistent (s)p

        // @TODO check what happens when you re-delete a (s)p <- according to below "is fine/returns true". What answer does it give?
        // -> The second deletion of a profile returns a 400 - but the Client doesn't see that because Api just returns true.

        // @TODO IMPLEMENT: PUT of subprofile/XX works fine. Updates _modified. Still does not return anything on GET.
        // -> also try/implement tests for subprofile/XX/fields and for profile.)


        // @TODO test how deletion of a profile with subprofiles works <- note that this smells like a logic test though, which should be moved into the other class later.
        // -> The second deletion of a profile returns a 400 - but the Client doesn't see that because Api just returns true.
        // -> first try "first deletion" on live. Then fix this.

        //  -> subprofiles are auto deleted. Repeated deletion of a profile is fine / returns true.
        //  -> it is possible in the UI to create new subprofiles! (If you haven't refreshed yet.)
        //     They will show up in the subprofile list for a collection. Haven't tried per-profile yet.
        //     -> yes that works too. They show up in subprofile list for the collection.
        // listing for the (deleted)profile gives "error" body with message: "Invalid request, element 3 is missing in 'directory' path"


        // @todo note that this is a behavior test that should really be moved into the other test class.
    }
    // @todo more tests for profile behavior that should end up in the new test:
    //   - deleting (sub)profile does not change 'modified' date.

    /**
     * Executes put(), catches 303.
     *
     * This is a standalone method because most/all code behind TestApi::put()
     * is supposed to behave differently from get()/post(): it is supposed to
     * throw a RuntimeException with code 303 on success if throwOnError==true.
     *
     * We can get away with executing most (all?) put() calls twice, so we'll
     * do this to make sure they behave well for both throwOnError states.
     */
    private function executePut(TestApi $api, $resource, $data, array $parameters = array(), $execute_twice = true)
    {
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
     * Compares array structures against each other except for some keys.
     *
     * @param array $expected_struct
     *   The structure we provide to compare $actual_struct against - except
     *   we don't know the exact values for all the keys in $actual_struct.
     * @param array $actual_struct
     *   The structure we want to compare against $expected_struct.
     */
    private function compareStruct(array $expected_struct, array $actual_struct)
    {
        $expected_element = reset($expected_struct);
        foreach ($actual_struct as $actual_key => $actual_element) {
            $expected_key = key($expected_struct);

            // Compare key, either literally or '<'
            if (is_numeric($expected_key) || strpos($expected_key, '>= ') !== 0) {
                if ($actual_key !== $expected_key) {
                    throw new UnexpectedValueException("Key '$actual_key' is expected to be '$expected_key'.");
                }
            } else {
                // Compare key as '<' because we don't know the exact value.
                if ($actual_key < (int)substr($expected_key, 3)) {
                    throw new UnexpectedValueException("Key in normalized structure ($actual_key) is not expected to be smaller than " . substr($expected_key, 3) . '.');
                }

                if (!isset($actual_element['name'])) {
                    throw new UnexpectedValueException("Element with key $actual_key is not an array or has no 'name' value.");
                }
                if (!isset($expected_element['name'])) {
                    // This is a definition error (expected key vs element).
                    throw new UnexpectedValueException("Element with key '$expected_key' must be defined with a 'name' sub-value.");
                }
                // Replace ID in 'name' element
                if (substr($expected_element['name'], -1) === 'X') {
                    $expected_name = substr($expected_element['name'], 0, strlen($expected_element['name']) - 1)
                        . $actual_key;
                    // Actually, this is not always true! There could have been
                    // a name clash which caused an underscore to be inserted.
                    // HARDCODED: we know the one instance where that happens
                    // (because we're using "Collection35" elsewhere, to
                    // trigger this situation).
                    $expected_element['name'] = $expected_name === 'Collection35' ? 'Collection_35' : $expected_name;
                }
            }

            // Hardcoded: if 'collections' or 'fields' then compare all
            // array members individually (because they can have variable keys).
            if ($expected_key === 'fields' || $expected_key === 'collections') {
                $this->compareStruct($expected_element, $actual_element);
            } elseif (isset($actual_element['fields']) || isset($actual_element['collections'])) {
                // Hardcoded: if the value _contains_ 'collections' or 'fields'
                // then compare all array members individually, (because their
                // sub-elements can have variable keys). We are on the level of
                // a single database/collection, which only has named
                // properties / is not a list of numerically keyed entities. So
                // we can/should key-sort those before individual comparison of
                // the properties.
                ksort($expected_element);
                ksort($actual_element);
                $this->compareStruct($expected_element, $actual_element);
            } elseif ($actual_element != $expected_element) {
                // The name is actually
                throw new UnexpectedValueException('Element ' . json_encode($actual_element) . ' is expected to be ' . json_encode($expected_element) . '.');
            }

            $expected_element = next($expected_struct);
        }
        if ($expected_element !== false) {
            // $actual_struct is out of elements; $expected_struct is not.
            throw new UnexpectedValueException('Array structure has too few elements.');
        }
    }

    /**
     * Gets date formatted as the API would.
     *
     * @param \CopernicaApi\Tests\TestApi $api
     *   An API instance
     * @param int $timestamp
     *   Timestamp.
     *
     * @return string
     *   Formatted date.
     */
    private function getApiDate(TestApi $api, $timestamp)
    {
        $date = new DateTime();
        if ($api->getTimezone()) {
            $date->setTimezone(new DateTimeZone($api->getTimezone()));
        }
        $date->setTimestamp($timestamp);
        return $date->format('Y-m-d H:i:s');
    }
}

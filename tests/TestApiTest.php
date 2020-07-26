<?php

namespace CopernicaApi\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

/**
 * Tests for the TestApi class.
 *
 * What has test coverage at the moment:
 * - Logic in TestApi::normalizeDatabasesStructure() and its called methods.
 * - The database backend re. profiles and subprofiles (data is written
 *   correctly by POST/PUT/DELETE, and returned correctly by GET).
 * @todo every time we add some database structure, we should add test coverage.
 *   Rule of thumb: tests that compare the outcome of API calls vs. contents in
 *   the back end (either database or structure stored in class variables)
 *   should be in this class. Tests that test the results of PUT/POST/DELETE
 *   calls vs. GET calls, often test 'API logic' rather than TestApi
 *   consistency, and should likely eventually end up in the separate class
 *   described below - not in this one (though we can implement them here as
 *   long as we don't have that test class yet).
 *
 * Of all the test classes in this directory, this is the 'base' test. It does
 * not use other classes (like CopernicaRestClient); it tests whether TestApi
 * is OK so that other test classes can assume it is, and use it.
 *
 * @todo
 *   Create a standalone test class which exercises as much logic as possible
 *   in a way can be run against both TestApi and CopernicaRestApi + real
 *   Copernica database.
 *   - This means they can't initialize a database structure through the
 *     TestAPI constructor but need to create a new database and collections
 *     and fields using API calls. (Which means TestApi first needs to support
 *     all those.)
 *   - This should exercise as much logic as possible and serve as some form of
 *     documentation on the exact behavior of Copernica, like
 *     - how exactly is the '/databases' response/output influenced by setting
 *       field defaults and other properties?
 *     - which inputs exactly are illegal for various types of calls and
 *       database fields?
 *     - what is the exact behavior re. default values for e.g. profile fields?
 *   - While it isn't meant to run often against live Copernica, we can do it
 *     once in a while and thereby prove that TestApi still behaves the same as
 *     the live API and therefore we can trust other tests written against it.
 *   - Conceptually it would be best if this didn't test CopernicaRestClient at
 *     all, just ran directly against CopernicaRestAPI / TestApi. However the
 *     code added to CopernicaRestClient is so thin and convenient, if we don't
 *     use it we might end up just reimplementing it. (Not sure yet.)
 *
 * Scratch space for tests to implement if we want to be complete:
 * - test autoincrement: delete highest numbered (sub)profile and then
 *   reinsert; check if the ID is not reused (if it isn't in Copernica). This
 *   baseline test should help ensure that other tests behave as expected.
 * - check returned message when we insert subprofiles for an unkown collection.
 */
class TestApiTest extends TestCase
{
    /**
     * Tests that normalization will fail if we need it to.
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
     * Similar tests might be implemented in the standalone test described in
     * this class' large TODOs; their purpose would be spec/test API behavior,
     * and we would be able to run them against the live API too. This means it
     * doesn't test anything against the TestApi specific database backend.
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
                            'Score' => ['type' => 'integer'],
                            'ActionTime' => ['type' => 'empty_datetime'],
                        ],
                    ]
                ],
            ]
        ]);
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
        $this->assertTrue($result['_created'] === date('Y-m-d H:i:s', $now)
                          || $result['_created'] === date('Y-m-d H:i:s', $now + 1));
        $this->assertEquals($result['_created'], $result['_modified']);
        $profile_created = $result['_created'];
        unset($result['_created'], $result['_modified']);
        $this->assertEquals($profile + ['_pid' => $profile_id], $result);

        // Inserting a second profile will result in a second row, also if the
        // profile is the same.
        $profile2_id = $api->post("database/$database_id/profiles", ['fields' => $profile]);
        $this->assertNotEmpty($profile2_id);
        $this->assertGreaterThan($profile_id, $profile2_id);
        // We assume that our database was emptied out.
        $result = $api->dbFetchAll("SELECT * FROM profile_$database_id ORDER BY _pid", [], '');
        $this->assertEquals(2, count($result));
        $result = end($result);
        $this->assertTrue($result['_created'] === date('Y-m-d H:i:s', $now)
                          || $result['_created'] === date('Y-m-d H:i:s', $now + 1));
        $this->assertEquals($result['_created'], $result['_modified']);
        $profile2_created = $result['_created'];
        unset($result['_created'], $result['_modified']);
        $this->assertEquals($profile + ['_pid' => $profile2_id], $result);

        // Insert subprofile.
        $subprofile = ['Score' => 6, 'ActionTime' => '2020-04-27 14:15:34'];
        $subprofile_id = $api->post("profile/$profile_id/subprofiles/$collection_id", $subprofile);
        $this->assertNotEmpty($subprofile_id);
        $result = $api->dbFetchAll("SELECT * FROM subprofile_$collection_id WHERE _spid = :id", [':id' => $subprofile_id], '');
        $this->assertEquals(1, count($result));
        $result = reset($result);
        $this->assertTrue($result['_created'] === date('Y-m-d H:i:s', $now)
                          || $result['_created'] === date('Y-m-d H:i:s', $now + 1));
        $this->assertEquals($result['_created'], $result['_modified']);
        $subprofile_created = $result['_created'];
        unset($result['_created'], $result['_modified']);
        $this->assertEquals($subprofile + ['_spid' => $subprofile_id, '_pid' => $profile_id], $result);

        // Update a field to empty. Sleep 1 to be able to check modified time.
        sleep(1);
        $result = $api->put("profile/$profile_id/fields", ['LastName' => '']);
        $this->assertEquals(true, $result);
        // Still two profiles, i.e. nothing was magically duplicated.
        $result = $api->dbFetchAll("SELECT * FROM profile_$database_id ORDER BY _pid", [], '');
        $this->assertEquals(2, count($result));
        $result = reset($result);
        $this->assertNotEquals($profile_created, $result['_modified'], 'Profile modified time did not change.');
        $profile_modified = $result['_modified'];
        unset($result['_created'], $result['_modified']);
        $this->assertEquals(['_pid' => $profile_id, 'LastName' => ''] + $profile, $result);

        // We should test the exact format of accepted fields in the other
        // (nonexistent as yet?) test class / we know SQLite does a TEXT field
        // for dates, so there's nothing special about that here.
        $result = $api->put("subprofile/$subprofile_id/fields", ['Score' => 0]);
        $this->assertEquals(true, $result);
        // See there's still only one subprofile.
        $result = $api->dbFetchAll("SELECT * FROM subprofile_$collection_id", [], '');
        $result = reset($result);
        $this->assertNotEquals($profile_created, $result['_modified'], 'Profile modified time did not change.');
        $subprofile_modified = $result['_modified'];
        unset($result['_created'], $result['_modified']);
        $this->assertEquals(['Score' => 0, '_spid' => $subprofile_id, '_pid' => $profile_id] + $subprofile, $result);

        // Get data, see if it gets returned in the right format. (This feels a
        // bit superfluous because it's just the reverse of the construction
        // code in TestApi::getProfiles(). Maybe if/when the API behavior test
        // gets made, this can be moved there, instead of copied there. On the
        // other hand... that test might choose to use CopernicaRestClient for
        // convenience, and then we wouldn't be testing the below 'standalone'
        // in a/the base test class?)
        $result = $api->get("database/$database_id/profiles");
        // We can't compare the secret.
        foreach ($result['data'] as $key => $value) {
            unset($result['data'][$key]['secret']);
        }
        $this->assertEquals(['start' => 0, 'count' => 2, 'total' => 2, 'limit' => 100, 'data' => [
            [
                'ID' => (string)$profile_id,
                'fields' => [
                    'Email' => 'rm@wyz.biz',
                    'LastName' => '',
                    'Birthdate' => '1974-04-27',
                ],
                'interests' => [],
                'database' => (string)$database_id,
                'created' => $profile_created,
                'modified' => $profile_modified,
                'removed' => false,
            ],
            [
                'ID' => (string)$profile2_id,
                'fields' => [
                    'Email' => 'rm@wyz.biz',
                    'LastName' => 'Muit',
                    'Birthdate' => '1974-04-27',
                ],
                'interests' => [],
                'database' => (string)$database_id,
                'created' => $profile2_created,
                'modified' => $profile2_created,
                'removed' => false,
            ],
        ]], $result);

        $result = $api->get("collection/$collection_id/subprofiles");
        foreach ($result['data'] as $key => $value) {
            unset($result['data'][$key]['secret']);
        }
        $this->assertEquals(['start' => 0, 'count' => 1, 'total' => 1, 'limit' => 100, 'data' => [
            [
                'ID' => (string)$subprofile_id,
                'fields' => ['Score' => '0', 'ActionTime' => '2020-04-27 14:15:34'],
                'profile' => (string)$profile_id,
                'collection' => (string)$collection_id,
                'created' => $subprofile_created,
                'modified' => $subprofile_modified,
                'removed' => false,
            ],
        ]], $result);

        // @todo test some get() API calls to see if TestApi formats return
        //   values in the way we expect it. (And also after DELETE. Check it does not contain 'removed')


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
}

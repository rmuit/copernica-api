<?php

namespace CopernicaApi\Tests;

use CopernicaApi\BatchableRestClient;
use InvalidArgumentException;
use LogicException;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for the RestClient class.
 *
 * This should still:
 * - test 'paging' in getEntities/nextBatch/lastDatasetIsComplete().
 * - test backupState() and restoreState() to a new RestClient, @TODO
 *   probably right before calling getEntitiesnextBatch() on the new instance.
 *   (We're already testing that they work for a few other properties.)
 *
 * This class implicitly depends on RestClientTest and ApiBehaviorTest; we have
 * to trust that both the client class and the API behave as expected and are
 * fully tested, so we can concentrate on just BatchableRestClient logic.
 * (We have not created a phpunit.xml specifically to encode this dependency /
 * order because it's unlikely this will cause issues.)
 *
 * @todo the above isn't strictly true yet; it assumes that all intricacies of
 *   e.g. 'orderby' behavior and filtering are explicitly tested in
 *   ApiBehaviorTest, including for emailing queries. We haven't done that yet
 *   and just trust the API to behave as expected (i.e. this class is actually
 *   implicitly testing part of those intricacies), for now.
 */
class BatchableRestClientTest extends TestCase
{
    /**
     * ID for our copernica database. The value doesn't matter.
     *
     * @todo maybe make this (and below) a random value because that's
     *   theoretically better for writing good tests.
     *
     * @var int
     */
    const DATABASE_ID = 3;

    /**
     * ID for the collection in our copernica database. Value doesn't matter.
     *
     * @todo is it worth testing any collection specific code? So far we
     *   haven't, because there's just too little of it.
     *
     * @var int
     */
    const COLLECTION_ID = 45;

    /**
     * Tests (or rather: specifies) default behavior of allEntitiesFetched()
     *
     * That is: what happens if it's called before getEntities() is ever called.
     */
    public function testAllEntitiesFetchedDefault()
    {
        $client = $this->getClient();
        $this->assertSame(false, $client->allEntitiesFetched());
    }

    /**
     * Tests (or rather: specifies) default behavior of getMoreEntities().
     *
     * That is: what happens if it's called before getEntities() is ever called.
     */
    public function testGetMoreDefault()
    {
        // This calls the client's get() despite having an empty string as the
        // endpoint.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Copernica API request failed: Invalid method.');
        $this->getClient()->getMoreEntities();
    }

    /**
     * Tests (or rather: specifies) default behavior of getMoreEntitiesOrdered()
     *
     * That is: what happens if it's called before getEntities() is ever called.
     */
    public function testGetMoreOrderedDefault()
    {
        // This calls its internal getContext() which throws an exception.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("The current API resource/query/configuration is not suitable for querying entities in an 'ordered' way.");
        $this->getClient()->getMoreEntitiesOrdered();
    }

    /**
     * Tests behavior of getMoreEntities().
     */
    public function testGetMoreEntities()
    {
        $database_id = self::DATABASE_ID;
        $client = $this->getClient(true);
        $client->post("database/$database_id/profiles", ['fields' => ['Email' => '1@c.cc', 'Birthdate' => '2000-01-07']]);
        $client->post("database/$database_id/profiles", ['fields' => ['Email' => '2@c.cc', 'Birthdate' => '2000-01-06']]);
        $client->post("database/$database_id/profiles", ['fields' => ['Email' => '3@c.cc', 'Birthdate' => '2000-01-05']]);
        $client->post("database/$database_id/profiles", ['fields' => ['Email' => '4@c.cc', 'Birthdate' => '2000-01-04']]);
        $client->post("database/$database_id/profiles", ['fields' => ['Email' => '5@c.cc', 'Birthdate' => '2000-01-03']]);
        $client->post("database/$database_id/profiles", ['fields' => ['Email' => '6@c.cc', 'Birthdate' => '2000-01-02']]);
        $client->post("database/$database_id/profiles", ['fields' => ['Email' => '7@c.cc', 'Birthdate' => '2000-01-01']]);

        $entities = $client->getEntities("database/$database_id/profiles", ['limit' => 3]);
        $this->assertSame(3, count($entities));
        $entities = $client->getMoreEntities();
        $this->assertSame(3, count($entities));
        $this->assertSame(false, $client->allEntitiesFetched());

        $entities = $client->getMoreEntities();
        $this->assertSame(1, count($entities));
        $this->assertSame(true, $client->allEntitiesFetched());
        // After the end, we can keep on calling getMoreEntities without error.
        $entities = $client->getMoreEntities();
        $this->assertSame(0, count($entities));
        $this->assertSame(true, $client->allEntitiesFetched());
        $entities = $client->getMoreEntities();
        $this->assertSame(0, count($entities));
        $this->assertSame(true, $client->allEntitiesFetched());
        $this->assertSame(7, $client->getFetchedCount());

        // Changing 'limit' parameter in between also works, and sticks.
        $entities = $client->getEntities("database/$database_id/profiles", ['limit' => 3]);
        $this->assertSame(3, count($entities));
        $entities = $client->getMoreEntities(['limit' => 2, 'dataonly' => true]);
        $this->assertSame(2, count($entities));
        // Quick check to see that 'dataonly' has effect. There's no special
        // logic in BatchableRestClient special to 'dataonly', so further
        // tests should be in the base ApiBehaviorTest.
        $this->assertSame(false, $client->allEntitiesFetched());
        $entities = $client->getMoreEntities();
        $this->assertSame(2, count($entities));
        // Test that the extra parameters stuck in the next call..
        $this->assertFalse(isset($entities[0]['created']));
        // This still (falsely) returns false because we don't know yet that we
        // got exactly the last 2 items in the set. (Because we've passed
        // total==false to the API calls to offload the API.)
        $this->assertSame(false, $client->allEntitiesFetched());
        $this->assertSame(7, $client->getFetchedCount());

        $entities = $client->getMoreEntities();
        $this->assertSame(0, count($entities));
        $this->assertSame(true, $client->allEntitiesFetched());
        $entities = $client->getMoreEntities();
        $this->assertSame(0, count($entities));
        $this->assertSame(true, $client->allEntitiesFetched());
        $this->assertSame(7, $client->getFetchedCount());

        // Test limit=1 just to be sure the code is not doing anything wonky.
        // getMoreEntitiesOrdered() isn't able to support limit=1 because it
        // will throw an "All entities in the previous batch had the same value"
        // exception.
        $entities = $client->getEntities("database/$database_id/profiles", ['limit' => 1]);
        // New query resets the $extra_parameters from previous query.
        $this->assertTrue(isset($entities[0]['created']));
        $client->getMoreEntities();
        $client->getMoreEntities();
        $client->getMoreEntities();
        $client->getMoreEntities();
        $client->getMoreEntities();
        $entities = $client->getMoreEntities();
        $this->assertSame(1, count($entities));
        $entities = $client->getMoreEntities();
        $this->assertSame(0, count($entities));
        $this->assertSame(7, $client->getFetchedCount());

        // One quasi random test: another parameter than 'limit' throws
        // exception (if there are still items to be fetched).
        $entities = $client->getEntities("database/$database_id/profiles", ['limit' => 3]);
        $this->assertSame(3, count($entities));
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid parameters passed.');
        $client->getMoreEntities(['start' => '2']);
    }

    /**
     * Tests basic behavior of getMoreEntitiesOrdered().
     *
     * Also tests that getFetchedCount() counts double-fetched entities only
     * once.
     */
    public function testGetMoreEntitiesOrdered()
    {
        $database_id = self::DATABASE_ID;
        $client = $this->getClient(true);
        $client->post("database/$database_id/profiles", ['fields' => ['Email' => '1@c.cc', 'Birthdate' => '2000-01-07']]);
        $client->post("database/$database_id/profiles", ['fields' => ['Email' => '2@c.cc', 'Birthdate' => '2000-01-06']]);
        $client->post("database/$database_id/profiles", ['fields' => ['Email' => '3@c.cc', 'Birthdate' => '2000-01-05']]);
        $client->post("database/$database_id/profiles", ['fields' => ['Email' => '4@c.cc', 'Birthdate' => '2000-01-04']]);
        $client->post("database/$database_id/profiles", ['fields' => ['Email' => '5@c.cc', 'Birthdate' => '2000-01-03']]);
        $client->post("database/$database_id/profiles", ['fields' => ['Email' => '6@c.cc', 'Birthdate' => '2000-01-02']]);
        $client->post("database/$database_id/profiles", ['fields' => ['Email' => '7@c.cc', 'Birthdate' => '2000-01-01']]);

        // Same as testGetMoreEntities(), #1:
        $entities = $client->getEntities("database/$database_id/profiles", ['limit' => 3]);
        $this->assertSame(3, count($entities));
        $first_id = $entities[0]['ID'];
        $entities = $client->getMoreEntitiesOrdered();
        $this->assertSame(3, count($entities));
        $this->assertSame(false, $client->allEntitiesFetched());

        $entities = $client->getMoreEntitiesOrdered();
        $this->assertSame(1, count($entities));
        $this->assertSame(true, $client->allEntitiesFetched());
        // After the end, we can keep on calling getMoreEntities without error.
        $entities = $client->getMoreEntitiesOrdered();
        $this->assertSame(0, count($entities));
        $this->assertSame(true, $client->allEntitiesFetched());
        $entities = $client->getMoreEntitiesOrdered();
        $this->assertSame(0, count($entities));
        $this->assertSame(true, $client->allEntitiesFetched());
        $this->assertSame(7, $client->getFetchedCount());

        // Same as testGetMoreEntities(), #2:
        // Changing 'limit' parameter in between also works, and sticks.
        $entities = $client->getEntities("database/$database_id/profiles", ['limit' => 3]);
        $this->assertSame(3, count($entities));
        $entities = $client->getMoreEntitiesOrdered(['limit' => 2]);
        $this->assertSame(2, count($entities));
        $this->assertSame(false, $client->allEntitiesFetched());
        $this->assertSame(5, $client->getFetchedCount());
        $entities = $client->getMoreEntitiesOrdered();
        $this->assertSame(2, count($entities));
        // This still (falsely) returns false because we don't know yet that we
        // got exactly the last 2 items in the set. (Because we've passed
        // total==false to the API calls to offload the API.)
        $this->assertSame(false, $client->allEntitiesFetched());

        $entities = $client->getMoreEntitiesOrdered();
        $this->assertSame(0, count($entities));
        $this->assertSame(true, $client->allEntitiesFetched());
        $entities = $client->getMoreEntitiesOrdered();
        $this->assertSame(0, count($entities));
        $this->assertSame(true, $client->allEntitiesFetched());

        // Explicitly test a query with 'fields' parameter (because
        // getMoreEntitiesOrdered() messes with it). This should return 6.
        $client->getEntities("database/$database_id/profiles", ['fields' => ["ID>$first_id"], 'limit' => 3]);
        $entities = $client->getMoreEntitiesOrdered();
        $this->assertSame(3, count($entities));
        $entities = $client->getMoreEntitiesOrdered();
        $this->assertSame(0, count($entities));
        $this->assertSame(6, $client->getFetchedCount());
        // And this 5.
        $client->getEntities("database/$database_id/profiles", ['fields' => ["ID>$first_id", 'Birthdate>=2000-01-02'], 'limit' => 3]);
        $entities = $client->getMoreEntitiesOrdered();
        $this->assertSame(2, count($entities));
        $this->assertSame(5, $client->getFetchedCount());

        // Different ordering: this will assume the ordered field is not
        // unique, which makes it return 1 less than the limit.
        $entities = $client->getEntities("database/$database_id/profiles", ['orderby' => 'birthdate', 'limit' => 3]);
        $this->assertSame(3, count($entities));
        $entities = $client->getMoreEntitiesOrdered();
        $this->assertSame(2, count($entities));
        $entities = $client->getMoreEntitiesOrdered();
        $this->assertSame(2, count($entities));
        // Superfluous assertion that allEntitiesFetched works the same here.
        $this->assertSame(false, $client->allEntitiesFetched());
        $entities = $client->getMoreEntitiesOrdered();
        $this->assertSame(0, count($entities));
        $this->assertSame(true, $client->allEntitiesFetched());
        $this->assertSame(7, $client->getFetchedCount());

        // ...except if we explicitly specify its uniqueness  - which needs to
        // be repeated for every call.
        $entities = $client->getEntities("database/$database_id/profiles", ['limit' => 2, 'orderby' => 'Email', 'order' => 'desc']);
        $this->assertSame(2, count($entities));
        $entities = $client->getMoreEntitiesOrdered();
        $this->assertSame(1, count($entities));
        $entities = $client->getMoreEntitiesOrdered([], ['ordered_field_has_unique_values' => true]);
        $this->assertSame(2, count($entities));
        $entities = $client->getMoreEntitiesOrdered([], ['ordered_field_has_unique_values' => false]);
        $this->assertSame(1, count($entities));
        $this->assertSame('2@c.cc', $entities[0]['fields']['Email']);

        // getMoreEntitiesOrdered() works after getMoreEntities(): the calls
        // are interchangeable - as long as getMoreEntitiesOrdered() has a good
        // parameter structure to work with. (I'm not sure what would be a "bad"
        // parameter structure because the default orderby is always known.)
        // The other way around, getMoreEntities() after
        // getMoreEntitiesOrdered(), gets tested elsewhere with the
        // 'fall_back_to_unordered' option.
        // Setup:
        $entities = $client->getEntities("database/$database_id/profiles", ['limit' => 3]);
        $this->assertSame(3, count($entities));
        $entities = $client->getMoreEntities();
        $this->assertSame(3, count($entities));
        // Test:
        $entities = $client->getMoreEntitiesOrdered();
        $this->assertSame(1, count($entities));
        $this->assertSame('7@c.cc', $entities[0]['fields']['Email']);
        $this->assertSame(true, $client->allEntitiesFetched());

        // One quasi random test: another parameter than 'limit' throws
        // exception (if there are still items to be fetched).
        $client->getEntities("database/$database_id/profiles", ['limit' => 3]);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid parameters passed.');
        $client->getMoreEntitiesOrdered(['start' => '5']);
    }

    /**
     * Tests getMoreEntitiesOrdered() with many equal values.
     *
     * If there are more than <limit> entities having the same value for the
     * 'ordered' field, we cannot fetch all entities - and if there are exactly
     * <limit> entities having the same value, we cannot determine if we can
     * fetch all entities.
     */
    public function testGetMoreEntitiesOrderedEqualValues()
    {
        $database_id = self::DATABASE_ID;
        $client = $this->getClient(true);
        $client->post("database/$database_id/profiles", ['fields' => ['Email' => '1@c.cc', 'Birthdate' => '2000-01-01']]);
        $client->post("database/$database_id/profiles", ['fields' => ['Email' => '2@c.cc', 'Birthdate' => '2000-01-01']]);
        $client->post("database/$database_id/profiles", ['fields' => ['Email' => '2@c.cc', 'Birthdate' => '2000-01-05']]);
        $client->post("database/$database_id/profiles", ['fields' => ['Email' => '2@c.cc', 'Birthdate' => '2000-01-05']]);
        $client->post("database/$database_id/profiles", ['fields' => ['Email' => '2@c.cc', 'Birthdate' => '2000-01-05']]);
        $client->post("database/$database_id/profiles", ['fields' => ['Email' => '3@c.cc', 'Birthdate' => '2000-01-05']]);

        $entities = $client->getEntities("database/$database_id/profiles", ['orderby' => 'Email', 'limit' => 3]);
        $this->assertSame(3, count($entities));
        // This fetches 3 but removes 2 entities that were fetched previously.
        // It also records an error message about having 3 items with the same
        // value, but does not throw an exception yet (because who knows the
        // caller still wants to use the data already, despite not having
        // fetched a full set).
        $entities = $client->getMoreEntitiesOrdered();
        $this->assertSame(1, count($entities));

        // If we pass the fallback option, we can actually continue and should
        // now get just the last 2 items. (See below for non-fallback.)
        $entities = $client->getMoreEntitiesOrdered(['limit' => 10], ['fall_back_to_unordered' => true]);
        $this->assertSame(2, count($entities));
        // Changes the below a little, even though it isn't necessary.
        $client->delete("profile/{$entities[0]['ID']}");
        $client->delete("profile/{$entities[1]['ID']}");

        // Now test the non-fallback option. Start the same.
        $entities = $client->getEntities("database/$database_id/profiles", ['orderby' => 'Email', 'limit' => 3]);
        $this->assertSame(3, count($entities));
        $entities = $client->getMoreEntitiesOrdered();
        $this->assertSame(1, count($entities));
        // Even though we've fetched all entities, we're not sure of that.
        // (Which is independent of the error; just noting it.)
        $this->assertSame(false, $client->allEntitiesFetched());
        // The recorded error is now thrown.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("The current dataset cannot be retrieved in an 'ordered' way: All entities in the previous batch had the same value for 'Email'; further 'ordered' fetching cannot deal with this.");
        $client->getMoreEntitiesOrdered();
    }

    /**
     * Tests getState() / setState().
     */
    public function testGetSetState()
    {
        // The intention is to use separate client classes for fetching
        // separate batches, to prove that e.g. we could do that over
        // separate HTTP requests. However the API backend must stay the same;
        // that is not our issue.
        $api = $this->getApiWithInitializedProfile();

        $client = $this->getClient($api);
        $database_id = self::DATABASE_ID;
        $client->post("database/$database_id/profiles", ['fields' => ['Email' => '1@c.cc', 'Birthdate' => '2000-01-07']]);
        $client->post("database/$database_id/profiles", ['fields' => ['Email' => '2@c.cc', 'Birthdate' => '2000-01-06']]);
        $client->post("database/$database_id/profiles", ['fields' => ['Email' => '3@c.cc', 'Birthdate' => '2000-01-05']]);
        $client->post("database/$database_id/profiles", ['fields' => ['Email' => '4@c.cc', 'Birthdate' => '2000-01-04']]);
        $client->post("database/$database_id/profiles", ['fields' => ['Email' => '5@c.cc', 'Birthdate' => '2000-01-03']]);
        $client->post("database/$database_id/profiles", ['fields' => ['Email' => '6@c.cc', 'Birthdate' => '2000-01-02']]);
        $client->post("database/$database_id/profiles", ['fields' => ['Email' => '7@c.cc', 'Birthdate' => '2000-01-01']]);

        // Same as testGetMoreEntitiesOrdered() "Different ordering":
        $client->getEntities("database/$database_id/profiles", ['orderby' => 'birthdate', 'limit' => 3]);
        $state = $client->getState();
        // We could be doing this much later, with a previously saved state;
        // getMoreEntitiesOrdered() still works:
        $client = $this->getClient($api);
        $client->setState($state);
        $entities = $client->getMoreEntitiesOrdered();
        $this->assertSame(2, count($entities));
        $entities = $client->getMoreEntitiesOrdered();
        $this->assertSame(2, count($entities));
        $entities = $client->getMoreEntitiesOrdered();
        $this->assertSame(0, count($entities));
        $this->assertSame(true, $client->allEntitiesFetched());
        $this->assertSame(7, $client->getFetchedCount());

        // If we don't save personal info, we can still do getMoreEntities():
        // We're not testing the effect of $include_secrets = false because we
        // are not using tokens in test classes.)
        $client->getEntities("database/$database_id/profiles", ['limit' => 3]);
        $state = $client->getState(false, false);
        $client = $this->getClient($api);
        $client->setState($state);
        $entities = $client->getMoreEntities();
        $this->assertSame(3, count($entities));
        $this->assertSame(false, $client->allEntitiesFetched());
        $entities = $client->getMoreEntities();
        $this->assertSame(1, count($entities));
        $this->assertSame(true, $client->allEntitiesFetched());
        $this->assertSame(7, $client->getFetchedCount());

        // But we can't use getMoreEntitiesOrdered() if we don't save personal
        // info:
        $client->getEntities("database/$database_id/profiles", ['limit' => 3]);
        $state = $client->getState(false, false);
        $client = $this->getClient($api);
        $client->setState($state);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('datasetLastFetchedEntities class property does not have the expected structure');
        $client->getMoreEntitiesOrdered();
    }

    /**
     * Gets test API with initialized profile tables.
     *
     * @return TestApi
     */
    protected function getApiWithInitializedProfile()
    {
        return new TestApi([
            self::DATABASE_ID => [
                'name' => 'Test',
                'fields' => [
                    'Email' => ['type' => 'email'],
                    'Birthdate' => ['type' => 'empty_date'],
                ],
            ]
        ]);
    }

    /**
     * Gets BatchableRestClient (by default) having access to profile tables.
     *
     * @param \CopernicaApi\Tests\TestApi|bool $api
     *   If True, get new API with initialized profile tables. If False, get
     *   'empty' new API.
     *
     * @return BatchableRestClient
     */
    protected function getClient($api = false)
    {
        if (!is_object($api)) {
            if ($api) {
                $api = $this->getApiWithInitializedProfile();
            } else {
                // Don't waste time doing SQL commands for table creations etc;
                // that's not what this test is for. So pass a bogus PDO
                // connection.
                $pdo = new PDO('sqlite::memory:');
                $api = new TestApi([], $pdo);
            }
        }

        return new class ($api) extends BatchableRestClient {
            public function __construct(TestApi $api)
            {
                parent::__construct('testtoken');
                parent::setApi($api);
            }
        };
    }
}

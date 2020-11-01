<?php

namespace CopernicaApi;

use InvalidArgumentException;
use LogicException;
use RuntimeException;

/**
 * A REST API Client with functionality for batched fetching of entities.
 *
 * This can be used in place of RestClient for all operations; the
 * 'getEntities' part has some added logic / methods (and keeps extra state
 * internally).
 */
class BatchableRestClient extends RestClient
{
    /**
     * This is supposedly the maximum 'limit' parameter that any call will use.
     *
     * If some calls cap it at even less if we pass 1000, that's fine. So in
     * theory we don't need this constant and Copernica is fine with clients
     * passing really high 'limit' numbers and will just cap them. But it felt
     * somehow safer to cap high numbers ourselves too, just in case some
     * API resource doesn't implement this.
     */
    const MAX_BATCH_LIMIT = 1000;

    /**
     * Properties which Copernica orders query results by, indexed by resource.
     *
     * We need this in order to support fetching data set batches that
     * guarantee no data is lost, i.e. getMoreEntitiesOrdered(). Properties
     * must only be included here if we can apply a 'larger/smaller than'
     * filter to them, or they are ordered like this by default.
     *
     * In order to be able to implement some 'batched data set' functionality
     * we have to assume a fixed ordering for our query results - and it seems
     * safe to do so even in some places where Copernica may not officially
     * document this.
     *
     * Below arrays have three or four sub values:
     * - The name of the property which results are ordered by, by default
     *   (or "always", if the specific API query doesn't support 'orderby').
     * - True if the property value is always unique per entity.
     * - Optional: 'asc' or 'desc' to signify how the results are ordered by
     *   default. Default is 'asc'.
     * - Optional: the query parameter that needs to be given a value, in order
     *   to do filtering, if it's not the general "fields" parameter.
     *
     * The keys are substrings of a resource. We'd be in trouble if multiple
     * resources matching the same string have differing properties but we'll
     * cross that bridge whene we get there. (Some resource matching should stay
     * 'open ended' though, because Copernica allows adding 'useless' parts at
     * the end of some resources.)
     */
    const ORDER_PROPERTIES = [
        // All profile / subprofile queries order by ID by default (as
        // specified in Copernica docs). It seems unlikely that a resource will
        // be added which contains the substring "profiles" but behaves
        // differently, so we'll live dangerously and perform a single
        // substring match for the below subpaths. (We also support mis-cased
        // ID fields, but the below documents the actual values which also is
        // slightly faster in our code.)
        'profiles' => ['ID', true],
        // emailings apparently cannot be filtered by ID; there are dedicated
        // parameters for 'from/to timestamp' filtering and there is apparently
        // no way to influence ordering - we assume they're ordered by both
        // ID and timestamp (because they're always inserted with 'now' as
        // timestampp). All this implies we need to act as if we're filtering
        // on timestamp. For 'scheduledemailings' this is NOT necessarily the
        // case; if they are always ordered by ID and that isn't the same as
        // being ordered by timestamp... that means we cannot support them with
        // getMoreEntitiesOrdered(). (@todo verify this.)
        // profile/$id/ms/emailings is NOT documented as having a "fromdate"
        // parameter, so we won't support it - only
        // profile/$id/publisher/emailings. (main "ms/emailings" does have a
        // "fromdate" parameter, so we could support it, but we'd need to
        // re-code something to only support the 'main' one and not the
        // ms/emailings per (sub) profile. @todo test this, sometime.)
        //'publisher/emailings' => ['timestamp', false, 'asc', 'fromdate'],
        // @TODO for starters, uncomment the above line to make publisher
        //   emailings work. Seems like it would, there is very little
        //   untested code in this class which is specific to this
        //   functionality, but I'm not taking chances until it's confirmed.
    ];

    /**
     * Resource (relative URI) accessed by the last getEntities() call.
     *
     * @var string
     */
    protected $lastCallResource = '';

    /**
     * Parameters used for the last getEntities() call.
     *
     * @var array
     */
    protected $lastCallParameters = [];

    /**
     * The subtotal of entities fetched by the getEntities() + fetch() calls.
     *
     * It's reset by every getEntities() call. It's usually the same as
     * $datasetNextStart, but that can be influenced by overriding the 'start'
     * parameter (in the first getEntities() call). This subtotal is just an
     * increasing number with a getter and is not used otherwise.
     *
     * @var int
     */
    protected $fetchedCount = 0;

    /**
     * The start position for the next getMoreEntities() call.
     *
     * @var int
     */
    protected $datasetNextStart = 0;

    /**
     * The first entity in the previously fetched batch of entities.
     *
     * @var array
     */
    protected $datasetLastFetchedFirstEntity;

    /**
     * Entities containing start value for next getMoreEntitiesOrdered() call.
     *
     * All entities have the same value in the 'order' property.
     *
     * @var array[]
     */
    protected $datasetLastFetchedEntities;

    /**
     * A reason why getMoreEntitiesOrdered() cannot work, set by getEntities().
     *
     * @var string
     */
    protected $orderedFetchImpossibleReason;

    /**
     * Exception code to accompany the reason.
     *
     * @var string
     */
    protected $orderedFetchImpossibleCode;

    /**
     * BatchableRestClient constructor.
     *
     * @param string $token
     *   The access token used by the wrapped class.
     */
    public function __construct($token)
    {
        parent::__construct($token, 2);
    }

    /**
     * Executes a GET request that returns a batch of entities.
     *
     * This does the same as the parent method but also keeps some internal
     * counters, needed by getMoreEntities() / getMoreEntitiesOrdered(). The
     * other methods will throw an exception if this method isn't called first.
     *
     * @param string $resource
     *   Resource (URI relative to the versioned API) to fetch data from.
     * @param array $parameters
     *   (Optional) parameters for the API query.
     * @param bool $reset_fetched_count
     *   (Optional) pass False to not reset the counter for entities fetched.
     *   This is (likely) for internal use only.
     *
     * @return array[]
     *   A batch of (zero or more) entities
     *
     * @throws \RuntimeException
     *   If the result metadata are not successfully verified.
     */
    public function getEntities($resource, array $parameters = [], $reset_fetched_count = true)
    {
        $this->lastCallResource = $resource;
        $this->lastCallParameters = $parameters;
        // Cap limit (which is likely unneeded because we could leave that to
        // the remote API).
        if (isset($parameters['limit']) && $parameters['limit'] > self::MAX_BATCH_LIMIT) {
            $parameters['limit'] = self::MAX_BATCH_LIMIT;
        }
        if ($reset_fetched_count) {
            $this->fetchedCount = 0;
        }

        // Straight copy of parent code rather than calling it - because we
        // need the full $result.
        $parameters += ['total' => false];
        $result = $this->get($resource, $parameters, self::NONE);
        $this->checkEntitiesMetadata($result, $parameters, 'response from Copernica API');
        foreach ($result['data'] as $entity) {
            if (empty($entity['id']) && empty($entity['ID'])) {
                throw new RuntimeException("One of the entities returned from $resource resource does not contain 'id'.", 803);
            }
        }

        $this->fetchedCount += $result['count'];
        if ($result['count'] != $result['limit']) {
            // count < limit; otherwise we'd have thrown an exception. This
            // set of entities has no further results.
            $this->datasetNextStart = -1;
        } else {
            // Remember where getMoreEntities() should start from.
            $this->datasetNextStart = $result['start'] + $result['count'];
        }
        // Remember where getMoreEntitiesOrdered() should start from.
        $this->orderedFetchImpossibleReason = '';
        $this->orderedFetchImpossibleCode = 0;
        $this->datasetLastFetchedEntities = $this->datasetLastFetchedFirstEntity = [];
        if ($result['data']) {
            try {
                $context = $this->getQueryContext();
                $order_field = $context['order_field'];
                $keep_entities = [];
                $this->datasetLastFetchedFirstEntity = reset($result['data']);
                // Collect IDs for all the entities whose 'order' property has
                // the same value. If an entity has no value for the id/order
                // property, a RuntimeException gets thrown.
                $entity = end($result['data']);
                $last_ordered_value = $this->getEntityValue($entity, $order_field);
                do {
                    $keep_entities[] = $entity;
                    $entity = prev($result['data']);
                    if ($entity) {
                        $previous_value = $this->getEntityValue($entity, $order_field);
                    }
                } while ($entity && $previous_value === $last_ordered_value);
                if ($entity) {
                    // Doublecheck: the current entity's property value must be
                    // smaller - or larger if we're sorting descending. (We can
                    // supposedly compare all values using larger/smaller,
                    // including dates, because of their string format.) If
                    // not, something is wrong with the 'context definitions'
                    // and we'll prevent getMoreEntitiesOrdered() from being
                    // able to run. (This isn't water tight but it's better to
                    // do this half of the time then never.)
                    if ($context['order'] === 'desc') {
                        if ($previous_value > $last_ordered_value) {
                            $this->datasetLastFetchedEntities = $keep_entities;
                        } else {
                            $this->orderedFetchImpossibleReason = "The dataset was supposedly ordered descending by '$order_field' but the last few entities in the previous batch showed increasing values for '$order_field.";
                            // We might use 802 for various things that should
                            // be impossible. (It's reusing a code not used by
                            // RestClient::getEntities().)
                            $this->orderedFetchImpossibleCode = 802;
                        }
                    } elseif ($previous_value < $last_ordered_value) {
                        $this->datasetLastFetchedEntities = $keep_entities;
                    } else {
                        $this->orderedFetchImpossibleReason = "The dataset was supposedly ordered ascending by '$order_field' but the last few entities in the previous batch showed decreasing values for '$order_field.";
                        $this->orderedFetchImpossibleCode = 802;
                    }
                } else {
                    $this->orderedFetchImpossibleReason = "All entities in the previous batch had the same value for '$order_field'; further 'ordered' fetching cannot deal with this.";
                    $this->orderedFetchImpossibleCode = 801;
                }
            } catch (RuntimeException $e) {
                // getMoreEntitiesOrdered() will throw this same exception if
                // it's called later; we swallow it now because we don't even
                // know if the caller wants to fetch in an 'ordered' way.
                $this->orderedFetchImpossibleReason = $e->getMessage();
                $this->orderedFetchImpossibleCode = $e->getCode();
            }
        }

        return $result['data'];
    }

    /**
     * Returns a batch of entities 'following' the last getEntities*() result.
     *
     * A set of data that contains more entities than the REST API limit can
     * handle in one response, can be retrieved by one call to getEntities()
     * and consecutive calls to getMoreEntities(); when a call returns an empty
     * array, this indicates that the full data set was fetched.
     *
     * The previous query is modified by increasing the 'start' query parameter
     * to the number of entities already returned by previous calls. This is
     * the simplest way to do things and is guaranteed to work, but there is a
     * chance that some entities will be missing from the full returned data
     * set in the end. It is also possible for the same entity to be present
     * several times (in different batches) in the full returned data set.
     * (Consider, for instance, what happens when
     * - an entity that was already returned in a previous batch gets deleted;
     * - an entity that was already returned in a previous batch gets updated,
     *   while we are ordering ascending by 'modified' time.)
     * Missing items can be prevented by calling getMoreEntitiesOrdered()
     * instead, though that call can also fail in edge cases. Duplicates (of
     * the type just described) can still occur also with
     * getMoreEntitiesOrdered().
     *
     * @param array $extra_parameters
     *   (Optional) parameters to override those passed to the original
     *   getEntities() call. At this moment only 'limit' is supported; others
     *   will cause an exception.
     * @return array[]|false
     *   The 'data' part of the JSON-decoded response body, i.e. an array of
     *   entities - or empty array if no more entities are left to return as
     *   part of the full data set. False if exceptions were suppressed for
     *   '400' errors and no getEntities() call was done earlier.
     *
     * @see BatchableRestClient::getMoreEntitiesOrdered()
     */
    public function getMoreEntities(array $extra_parameters = [])
    {
        if ($this->allEntitiesFetched()) {
            return [];
        }

        $extra_parameters = array_change_key_case($extra_parameters);
        if ($extra_parameters && (!isset($extra_parameters['limit']) || count($extra_parameters) > 1)) {
            throw new InvalidArgumentException('Invalid parameters passed.');
        }

        // If this returns an empty array, that's because we could not see
        // last time that the data set was fully fetched already (because the
        // number of entities in the last batch was exactly equal to the limit).
        return $this->getEntities(
            $this->lastCallResource,
            ['start' => $this->datasetNextStart] + $extra_parameters + $this->lastCallParameters,
            false
        );
    }

    /**
     * Returns a batch of entities 'following' the last getEntities*() result.
     *
     * A set of data that contains more entities than the REST API limit can
     * handle in one response, can be retrieved by one call to getEntities()
     * and consecutive calls to getMoreEntitiesOrdered(); when a call returns
     * an empty array, this indicates that the full data set was fetched.
     *
     * The previous query is modified by adding/modifying a filter on the
     * property/field which the results are ordered by. This circumvents the
     * flaw in getMoreEntities() that can cause entities to not be present in
     * the full returned data set, but:
     * - It does not work for all queries and heavily depends on the field
     *   which the query is ordered by. (For instance, if the query is ordered
     *   descending by 'modified' time, and an entity gets updated while we
     *   are fetching data, it will be missed. The ordering must be ascending.)
     * - It is recommended for the 'ordered' field to be unique if at all
     *   possible. If it isn't, and all entities returned in one batch (by one
     *   call to getMoreEntitiesOrdered()) have the same value, this method
     *   will throw an exception (unless the 'fall_back_to_unordered' is
     *   passed).
     * - If the 'ordered' field is not immutable, it is possible for the same
     *   entity to be present several times (in different batches) in the full
     *   returned data set - for instance if the query is ordered ascending by
     *   'modified' time, and an entity gets updated while we are fetching
     *   data. (This is also the case for getMoreEntities().)
     * - If the query is ordered by 'modified' time, and we're querying
     *   (sub)profiles that contain an actual field named "modified", this will
     *   likely fail. (It will either throw an exception or, likely, just
     *   return faulty data.) The reason is that the Copernica API applies the
     *   "fields" parameter (the filter on "modified") to the actual field,
     *   which returns unexpected data, while ordering by the 'modified'
     *   property. (We won't pre-check this by checking for the existence of
     *   the field; that's way too expensive for a situation that almost never
     *   happens. Instead, we throw an exception if the supposedly-filtered
     *   value is out of the expected range.)
     *
     * @param array $extra_parameters
     *   (Optional) parameters to override those passed to the original
     *   getEntities() call. At this moment only 'limit' is supported; others
     *   will cause an exception.
     * @param array $options
     *   (Optional) options that change the behavior of this method, for
     *   advanced usage:
     *   - ordered_field_has_unique_values (boolean):
     *     If this method cannot determine whether the values of the 'ordered'
     *     field are unique in the data set (e.g. serial ID values), then it
     *     will assume non-uniqueness. This does no harm, but changes one
     *     slight detail: the returned set of entities will be 1 less than the
     *     query limit (because the API query will return a batch of entities
     *     including the last already-fetched entity, which will be removed
     *     from the return value). If the caller uses this property to assert
     *     that the field is unique, the call will actually return <limit>
     *     entities (at the danger of missing entities if this assertion is
     *     wrong).
     *   - fall_back_to_unordered (boolean):
     *     If true and if the previous fetch returned entities which all had
     *     the same 'ordered' value, this will fall back to getMoreEntities(),
     *     i.e. increasing the 'start' parameter, at the risk of missing items
     *     like outlined in
     *
     * @return array[]|false
     *   The 'data' part of the JSON-decoded response body, i.e. an array of
     *   entities - or empty array if no more entities are left to return as
     *   part of the full data set. False if exceptions were suppressed for
     *   '400' errors and no getEntities() call was done earlier.
     *
     * @see BatchableRestClient::getMoreEntities()
     */
    public function getMoreEntitiesOrdered(array $extra_parameters = [], array $options = [])
    {
        if ($this->allEntitiesFetched()) {
            return [];
        }
        if ($this->orderedFetchImpossibleReason) {
            if ($this->orderedFetchImpossibleCode == 801 && !empty($options['fall_back_to_unordered'])) {
                return $this->getMoreEntities($extra_parameters);
            }
            throw new RuntimeException("The current dataset cannot be retrieved in an 'ordered' way: " . $this->orderedFetchImpossibleReason, $this->orderedFetchImpossibleCode);
        }
        if (!is_array($this->lastCallParameters)) {
            throw new LogicException('lastCallParameters class property is not an array; this is indicative of a bug.');
        }

        $extra_parameters = array_change_key_case($extra_parameters);
        if ($extra_parameters && (!isset($extra_parameters['limit']) || count($extra_parameters) > 1)) {
            throw new InvalidArgumentException('Invalid parameters passed.');
        }

        // This supposedly doesn't throw a RuntimeException because it already
        // would have done that in the previous getEntities() call which would
        // have set $this->orderedFetchImpossibleReason. If it did throw one,
        // that would be fine however.
        $context = $this->getQueryContext();
        if (isset($context['query_param'])) {
            // The query parameter to set is not "fields". Hardcoded logic: we
            // are assuming the query has no 'orderby' or 'order' parameter,
            // i.e. we cannot influence how the query results are ordered. The
            // 'order_field' value is only meant for deriving the 'last value
            // from the previous batch'. Right now, the 'order' and
            // 'order_field_unique' values are unused for constructing query
            // parameters (only by checks afterwards), and we assume we should
            // just always stick this 'last value' into the 'query_param'
            // verbatim which will do the right thing. Example:
            // - for e-mailings, 'query_param' value is "fromdate" and
            //   'orderby' is "timestamp".
            // - we should just stick the last 'timestamp' field value into the
            //   'fromdate' parameter, because the hardcoded behavior is 1)
            //   'timestamp' is not unique, 2) the results are always sorted
            //   ascending, 3) 'fromdate' works like '>= value' (not '> value'),
            //   which fits.
            // It's theoretically possible that things won't always fit
            // automatically; we might encounter a situation where e.g. we need
            // to subtract 1 from the last value, and we may need the 'order'
            // and 'order_field_unique' to derive this. But we'll cross
            // that bridge when we get to it.
            if (empty($this->datasetLastFetchedEntities) || !isset($this->datasetLastFetchedEntities[0][$context['order_field']])) {
                throw new LogicException('datasetLastFetchedEntities class property does not have the expected structure; this is indicative of a bug.');
            }
            $parameters[$context['query_param']] = $filter_value = $this->datasetLastFetchedEntities[0][$context['order_field']];
            $ordered_field_unique = $context['order_field_unique'];
        } else {
            if (empty($this->datasetLastFetchedEntities)) {
                throw new LogicException('datasetLastFetchedEntities class property does not have the expected structure; this is indicative of a bug.');
            }
            $filter_value = $this->getEntityValue($this->datasetLastFetchedEntities[0], $context['order_field']);
            // Derive 'orderby' and 'order' from either default parameters or
            // from the already existing ones (i.e. override the values with
            // themselves). Unset 'start' and instead add/change a filter on
            // the 'order' field.
            $parameters = array_change_key_case($this->lastCallParameters);
            unset($parameters['start']);
            $parameters['orderby'] = $context['order_field'];
            $parameters['order'] = $context['order'];
            $filter_without_value = $parameters['orderby'] . ($parameters['order'] === 'desc' ? '<' : '>');
            $ordered_field_unique = !empty($options['ordered_field_has_unique_values']) || $context['order_field_unique'];
            if (!$ordered_field_unique) {
                $filter_without_value .= '=';
            }
            // If we find the same filter at the end of the 'fields' parameter,
            // we can remove it - regardless whether we set it a previous time
            // or it was passed into the original getEntities(). (If a filter
            // on the same field was passed into the original getEntities() but
            // we don't remove it here, then that shouldn't matter; it should
            // not be having any effect that our extra added filter doesn't.)
            $parameters['fields'] = isset($this->lastCallParameters['fields']) ? $this->lastCallParameters['fields'] : [];
            if ($parameters['fields'] && strpos(end($parameters['fields']), $filter_without_value) === 0) {
                array_pop($parameters['fields']);
            }
            $parameters['fields'][] = $filter_without_value . $filter_value;
        }

        $last_fetched_entities = $this->datasetLastFetchedEntities;

        // If this returns an empty array, that's because we could not see
        // last time that the data set was fully fetched already (because the
        // number of entities in the last batch was exactly equal to the limit).
        $entities = $this->getEntities($this->lastCallResource, $extra_parameters + $parameters, false);
        if ($this->datasetLastFetchedFirstEntity) {
            // Check whether the value of the first entity actually adheres to
            // the filter we applied. (This may not be the case if we have a
            // (sub)profile field called "modified", which makes that field be
            // filtered rather than the entity's 'modified' property - even
            // though the API orders by the latter, and so we get the latter.)
            $first_value = $this->getEntityValue($this->datasetLastFetchedFirstEntity, $parameters['orderby']);
            if ($parameters['order'] === 'desc' ? $first_value > $filter_value : $first_value < $filter_value) {
                $order = $parameters['order'] === 'desc' ? 'descending' : 'ascending';
                throw new RuntimeException("The dataset was supposedly ordered $order by '{$parameters['orderby']}', starting at \"$filter_value\", but the first returned '{$parameters['orderby']}' value is \"$first_value\"", 803);
            }
        }

        if (!$ordered_field_unique) {
            // Remove the entities that were already in the previous batch from
            // this one. We'll likely have at least one overlapping entity but
            // it's possible that entities are missing from this batch, if they
            // were updated in the meantime.
            foreach ($entities as $index => $entity) {
                if (in_array($entity, $last_fetched_entities, true)) {
                    unset($entities[$index]);
                    // Count duplicate-fetched items only once.
                    $this->fetchedCount--;
                } else {
                    // This can only throw an exception if the query
                    // parameters do not work as expected.
                    $compare_value = $this->getEntityValue($entity, $context['order_field']);
                    // Stop comparing entities - not on the first one that
                    // can't be found in $last_fetched_entities but on the
                    // first one that has a different $filter_value too,
                    // because the entities with this $filter_value may be a
                    // superset of <last_fetched_entities> and (in theory)
                    // ordered differently.
                    if ($compare_value !== $filter_value) {
                        break;
                    }
                }
            }
        }

        // Renumber keys in return value.
        return array_values($entities);
    }

    /**
     * Checks if the current data set is fetched fully already.
     *
     * Code can call this by itself to see whether anything should still be
     * fetched, but just calling getMoreEntities() / getMoreEntitiesOrdered()
     * immediately is also fine; that will return an empty array (hopefully
     * without actually fetching anything) if the data set is already complete.
     *
     * This can give false negatives, if the previous getEntities() call got
     * exactly the last <limit> entities in the data set. In that case it will
     * return true after the next call to getMoreEntities() which will return
     * zero entities.
     *
     * @return bool
     *   Indicator that the data set represented by previous getEntities() /
     *   getMoreEntities() / getMoreEntitiesOrdered() calls is fully fetched.
     */
    public function allEntitiesFetched()
    {
        return $this->datasetNextStart == -1;
    }

    /**
     * Returns number of entities fetched since (including) last getEntities().
     *
     * @return int
     *   The total entities fetched during the last getEntities() call which did
     *   not have False passed for $reset_fetched_count, plus any subsequent
     *   getMoreEntities*() (or getEntities(,,false)) calls. Entities which
     *   were fetched twice (once at the end of a batch and once at the start
     *   of the next batch, according to getMoreEntitiesOrdered() logic) are
     *   counted only once.
     */
    public function getFetchedCount()
    {
        return $this->fetchedCount;
    }

    /**
     * Returns state that must be kept for the next getMoreEntities*() call.
     *
     * If for whatever reason getMoreEntities*() needs to work on a newly
     * constructed class, feed the return value from this method into that new
     * class through setState().
     *
     * @param bool $include_secrets
     *   (Optional) False to omit security sensitive info, i.e. the token. This
     *   means the code which instantiates this class before calling setState()
     *   must know the token - and not setting it / setting a wrong token value
     *   will have unspecified consequences.
     * @param bool $include_personal_info
     *   (Optional) False to omit potentially personally identifying info, i.e.
     *   entities fetched from the database. This will make a
     *   getMoreEntitiesOrdered() call fail (at least before any other
     *   getEntities call is done); getMoreEntities() still works fine.
     *
     * @return array
     *   The state array.
     */
    public function getState($include_secrets = true, $include_personal_info = true)
    {
        $state =  [
            'last_resource' => $this->lastCallResource,
            'last_parameters' => $this->lastCallParameters,
            'count' => $this->fetchedCount,
            'next_start' => $this->datasetNextStart,
            'error_message' => $this->orderedFetchImpossibleReason,
            'error_code' => $this->orderedFetchImpossibleCode,
            'suppress_errors' => $this->getSuppressedApiCallErrors(),
        ];
        if ($include_secrets) {
            $state += [
                'token' => $this->token,
            ];
        }
        if ($include_personal_info) {
            $state += [
                'last_first_entity' => $this->datasetLastFetchedFirstEntity,
                'last_entities' => $this->datasetLastFetchedEntities,
            ];
        }

        return $state;
    }

    /**
     * Restores old state.
     *
     * @param array $state
     *   State, probably previously returned by getState(). Token is optional;
     *   if set, it overwrites the token that was passed into the constructor.
     *
     * @throws \LogicException
     *   If state has invalid values.
     */
    public function setState(array $state)
    {
        if (
            isset($state['token']) && !is_string($state['token'])
            || isset($state['last_first_entity']) && !is_array($state['last_first_entity'])
            || isset($state['last_entities']) && !is_array($state['last_entities'])
            || !isset($state['last_resource'])
            || !is_string($state['last_resource'])
            || !isset($state['last_parameters'])
            || !is_array($state['last_parameters'])
            || !isset($state['error_message'])
            || !is_string($state['error_message'])
            || !isset($state['error_code'])
            || filter_var($state['error_code'], FILTER_VALIDATE_INT) === false
            || !isset($state['suppress_errors'])
            || filter_var($state['suppress_errors'], FILTER_VALIDATE_INT) === false
            || !isset($state['count'])
            || filter_var($state['count'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === false
            || !isset($state['next_start'])
            || filter_var($state['next_start'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false
        ) {
            // Not spending time on detailed errors. (Yet?)
            throw new LogicException('Invalid structure for state.');
        }
        $this->lastCallResource = $state['last_resource'];
        $this->lastCallParameters = $state['last_parameters'];
        $this->fetchedCount = $state['count'];
        $this->datasetNextStart = $state['next_start'];
        $this->orderedFetchImpossibleReason = $state['error_message'];
        $this->orderedFetchImpossibleCode = $state['error_code'];
        $this->suppressApiCallErrors($state['suppress_errors']);
        if (isset($state['token'])) {
            $this->token = $state['token'];
        }
        if (isset($state['last_first_entity'])) {
            $this->datasetLastFetchedFirstEntity = $state['last_first_entity'];
        }
        if (isset($state['last_entities'])) {
            $this->datasetLastFetchedEntities = $state['last_entities'];
        }
    }

    /**
     * Returns several values related to the current query's results.
     *
     * ('Context' is a strange naming but 'properties' is basically already
     * taken and would be confusing.)
     *
     * If 'query_param' is present, this alters the meaning of the
     * 'order_peroperty' & 'order' properties. For now, see the code in
     * getMoreEntities() for details.
     *
     * @return array
     *   Array with four elements:
     *   - order_field: property or field of the entities which the current
     *     query is ordered by, and on which we are able to apply a filter.
     *     (There is no distinction between 'property' and 'field' because the
     *     API has only one argument for ordering / filtering either, and has
     *     hardcoded logic to determine when something is a property.)
     *   - order_field_unique:
     *   - order: asc/desc.
     *   - query_param: query parameter that needs to be populated with the
     *     last value from the previous batch, if it's not the general "fields"
     *     parameter.
     *
     * @throws \RuntimeException
     *   No default 'orderby' and related properties are defined for this API
     *   endpoint and none are specified in the last call parameters.
     */
    protected function getQueryContext()
    {
        // First get defaults, if we have them.
        $context = [];
        $resource = strtolower($this->lastCallResource);
        foreach (static::ORDER_PROPERTIES as $substring => $properties) {
            // As per the comment with the constant: just match substring.
            if (strpos($resource, $substring) !== false) {
                $context = [
                    'order_field_unique' => !empty($properties[1]),
                    'order_field' => $properties[0],
                    // Not yet validated; that's done below.
                    'order' => isset($properties[2]) ? $properties[2] : 'asc',
                ];
                if (isset($properties[3])) {
                    $context['query_param'] = $properties[3];
                }
                break;
            }
        }

        // We'll accept anything in the order(by) property and assume it
        // actually works as advertised. If the current resource has no
        // defaults, we'll assume the ID property is 'id' (because we're
        // already assuming things like a working orderby anyway).
        $parameters = array_change_key_case($this->lastCallParameters);
        if (!empty($parameters['orderby'])) {
            $order_field_is_default = $parameters['orderby'] == $context['order_field'];
            if (!$order_field_is_default) {
                $context['order_field'] = $parameters['orderby'];
                // Defaults do not apply.
                unset($context['order_field_unique']);
                unset($context['order']);
            }
            if (!isset($context['order_field_unique'])) {
                // Assuming false is somewhat less efficient but safe; see
                // elsewhere.
                $context['order_field_unique'] = false;
            }
        } elseif (!$context) {
            throw new RuntimeException("The current API resource/query/configuration is not suitable for querying entities in an 'ordered' way.", 800);
        }

        if (!empty($parameters['order'])) {
            $context['order'] = $parameters['order'];
        }
        $context['order'] =
            (isset($context['order']) && is_string($context['order'])
                && in_array(strtolower($context['order']), ['desc', 'descending'], true))
                ? 'desc' : 'asc';

        return $context;
    }

    /**
     * Gets a property value from an entity, allowing mis-cased field/property.
     *
     * @param array $entity
     *   The entity.
     * @param $field_name
     *   The field/property name.
     *
     * @return mixed
     *   The property value.
     *
     * @throws \RuntimeException
     *   The property is not found.
     */
    protected function getEntityValue(array $entity, $field_name)
    {
        // The specific use case for this function is: our field/property name
        // is sortable, by the REST API. The API jumbles both fields and
        // properties into one parameter - and hardcodes which things are a
        // property (see API docs on the web / TestApi class). Actually
        // "modified" will not work because of inconsistent handling of
        // order/filter parameters - and "code" will also not work because
        // that's a special case for the "fields" filter parameter and "random"
        // will also not work because that's a special case for the orderby
        // parameter. But that's not our problem. All other values not given
        // here should be fields, not properties.
        $field_name = strtolower($field_name);
        if (in_array($field_name, ['id', 'modified'], true)) {
            // Some entities have 'ID' property, some 'id'.
            if (!isset($entity[$field_name])) {
                $entity = array_change_key_case($entity);
                if (!isset($entity[$field_name])) {
                    throw new RuntimeException("Entity contains no '$field_name' property.", 803);
                }
            }
        } else {
            $entity = $entity['fields'];
            if (!isset($entity[$field_name])) {
                $entity = array_change_key_case($entity);
                if (!isset($entity[$field_name])) {
                    throw new RuntimeException("Entity contains no '$field_name' field.", 803);
                }
            }
        }

        return $entity[$field_name];
    }
}

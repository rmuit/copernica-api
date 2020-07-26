<?php

namespace CopernicaApi\Tests;

use RuntimeException;

/**
 * Fake factory class for fake CopernicaRestAPI classes, usable by tests.
 *
 * This class name must be passed into the CopernicaRestClient constructor to
 * work with TestApi backend instead of the standard 'live' API.
 */
class TestApiFactory
{
    /**
     * TestApi instances indexed by token. Must be set before calling create().
     *
     * Tests are responsible for instantiating TestAPI classes with the
     * expected (initial) database structure and setting them into this static
     * variable. After test code is exercised, their structure / database
     * contents can be inspected.
     *
     * @var \CopernicaApi\Tests\TestApi[]
     */
    public static $testApis;

    /**
     * Fake factory method. Only returns already existing TestApi instances.
     *
     * @param $token
     * @param $version
     *
     * @return \CopernicaApi\Tests\TestApi
     */
    public static function create($token, $version = 2)
    {
        if ($version != 2) {
            throw new RuntimeException("API version $version is not supported by this test.");
        }
        if (!isset(self::$testApis[$token])) {
            throw new RuntimeException("No known REST API connection for token '$token'.");
        }

        return self::$testApis[$token];
    }
}

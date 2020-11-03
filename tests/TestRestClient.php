<?php

namespace CopernicaApi\Tests;

use CopernicaApi\RestClient;

/**
 * RestClient which connects to TestApi.
 *
 * This just overrides the constructor to set the $api variable. We should just
 * remove this file and instantiate an anonymous class instead, for tests.
 * Example is in the tests in extra/.
 */
class TestRestClient extends RestClient
{
    public function __construct(TestApi $api)
    {
        parent::__construct('testtoken');
        parent::setApi($api);
    }
}

<?php

namespace CopernicaApi\Tests;

use CopernicaApi\CopernicaRestClient;

/**
 * RestClient which connects to TestApi.
 *
 * This just overrides the constructor to set the $api variable. It's
 * unfortunate that we have a separate test class to override each 'client'
 * class, but so far it's still simpler than having a factory class (we've
 * tried) and it's not worth making setApi a public method... yet?
 */
class TestRestClient extends CopernicaRestClient
{
    public function __construct(TestApi $api)
    {
        parent::__construct('testtoken');
        parent::setApi($api);
    }
}

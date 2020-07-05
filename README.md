Copernica REST API PHP tools [![Build Status](https://travis-ci.com/rmuit/sharpspring-restapi.svg?branch=master)](https://travis-ci.com/rmuit/copernica-api)
============================

This builds on the CopernicaRestAPI class which Copernica offer for download,
and adds an API Client class containing some useful helper methods to work
more smoothly with returned data.

(The code is the result of several months of work with various endpoints, to
the level that I'm reasonably confident the methods are generally applicable
and represent a decent way forward - so it's time to publish.)

## Usage

Only the REST API client is detailed here; the rest is left for the user to
discover.

The get() call is usable, but it is recommended to call one of the 'wrapper'
methods, instead; one of the three below hopefully fits any specific API
endpoint:
```php
$client = new \CopernicaApi\CopernicaRestClient(TOKEN);

// Get a single entity, making sure that it still exists:
try {
    $profile = $client->getEntity("profile/$id");
}
catch (\RuntimeException $e) {
    // Either something went wrong here, or the profile was deleted (which the
    // API endpoint does not treat as an error, but you probably want to. If
    // you want to have the response indicating "removed" returned instead,
    // then pass TRUE as the third argument to the getEntity() call.
}
// You likely want to wrap all get*() calls in try/catch blocks; we only
// provide the example for getEntity() because it's the only call that can
// throw exceptions for cases that are not technically errors.


// Get a list of entities; this will check the structure with its
// start/total/count/etc properties, and return only the relevant 'data' part:
$mailings = $client->getEntities('publisher/emailings');
// If the list of mailings is longer than the limit returned by the API, get
// the next batches like this: (example code; you may not actually want to
// fetch them all before processing one giant array...)
while ($next_batch = $client->getEntitiesNextBatch()) {
    $mailings = array_merge($mailings, $next_batch);
    // It is possible to pause execution and fetch the next batch in a separate
    // PHP thread, with some extra work; check saveState() for this.
}

// Get non-entity data (i.e. data that has no 'id' property). This is preferred
// over get() in cases where you don't want to have to check if the response
// contains an 'error' value rather than the requested data. The only
// difference between getData() and getEntity() is the extra checks on presence
// of an id property, and 'removed-ness'.
$stats = $client->getData("publisher/emailing/$id/statistics");
```

The post() / put() calls behave a bit differently from those in Copernica's
example class, in that they throw an exception where the example class would
return False. (I estimate that is easier for most code that wants to just
concentrate on non-failure paths and move any error handling outside of the
regular code flow.) However, there are no distinctive errors yet; until we get
examples, every instance of returning False throws a RuntimeException with
code 1. This will hopefully change as more knowledge is gained about exact
error conditions / messages.
```php
$new_id = $client->post("database/$db_id/profiles", ['email' => 'rm@wyz.biz']);
$success = $client->put("profile/$new_id", ['email' => 'info@wyz.biz']);
// If you don't want the exception behavior, you can use sendData() at least
// for now (maybe until a next major version of this library). It's the same
// as the example class' sendData() except the last argument is a boolean.
$success_or_failure = $client->sendData("profile/$new_id", ['email' => 'info@wyz.biz'], [], true);
```

## Some more details

Below text is likely unimportant to most people (who just want to use a REST
API client class).

### Contents of this library

- copernica_rest_api.php (the CopernicaRestAPI class) as downloaded from the
  Copernica website (REST API v2 documentation - REST API example), with only a
  few changes:
  - Proper handling of array parameters like 'fields'.
  - A little extra error handling in the get() call, as far as it's necessary
    to be kept close to the Curl call. (Additional error handling is in the
    extra wrapper class.)
  - Addition of the required namespace.

- A CopernicaRestClient class which wraps around CopernicaRestAPI. Some
  comments reflect gaps (around detailed error reporting) which cannot be
  improved yet without further detailed knowledge about API responses. See
  examples above.

I hope to soon add some utility code to aid in writing automated tests for
processes using this class, and a component I'm using in a synchronization
process that can insert/update profiles and related subprofiles.

It is recommended to not use CopernicaRestAPI directly. I'm using it but
keeping it in a separate file, for a combination of overlapping reasons:
- I don't have a reason yet to change the code around doing HTTP requests too
  much because it works well enough (despite it feeling somewhat clunky and
  needing a few fixes).
- The API responses may have undocumented features (e.g. specific error codes /
  messages / non-JSON being returned in certain circumstances) that I don't
  know about yet, and I don't want to make assumptions about this until there
  is a real need to.
- This way, we can more easily track if Copernica makes changes to their
  reference class, and we can merge them in here fairly easily.
- Besides the fixes, I needed to extend the class with extra functionality
  interpreting the responses, which was not tied to the HTTP requests
  themselves. Separating that extra functionality out seemed to make sense.
- Because of this separation, CopernicaRestClient could wrap another class
  which executes the HTTP requests in a different way (e.g. using Guzzle),
  without needing to touch (most of) the code which interprets the responses.

### Branches

- 'copernica' holds the unmodified downloaded copernica_rest_api.php.
- 'copernica-changed' holds the patches to it (except for the namespace, which
  is added in 'master') which might be good to fix upstream by Copernica.

### Compatibility

The library (very likely) works with PHP5 and PHP7. While PHP5 is way beyond
end-of-life, this class will keep from adding PHP7-only language constructs
until I see a real benefit on code quality (which is not the case yet), or
until other contributors indicate a need for it.

The 'build process' (see icon at the top; a similar passed/failed message will
appear on PRs) is only checking coding standards against PHP5.6+ / PSR12.
CopernicaRestClient is such a thin layer that I don't consider it in need of
unit tests.

### Project name

The package is called "copernica-api" rather than e.g. "copernica-rest-api", to
leave open the possibility of adding code to e.g. work with the older SOAP API.
(I have some code for processes using SOAP, but more recent work on the v2
REST API indicates this may not be needed anymore - so I haven't polished it
up.)

### Similar projects

In principle I'm not a fan of creating new projects (like this PHP library)
rather than cooperating with existing projects. I have looked around and found
several - so at the risk of being pedantic, I feel the need to justify this
choice. Maybe this will be a guideline for other people comparing the projects.

If the situation changes and there is value in merging this project into
another one, I'm open to it.

Projects found (excluding the ones that just take Copernica's SOAP class and
provide a composer.json for it):
* https://github.com/Relinks/copernica-rest

Last commit Oct 2018. Uses the Curl code from Copernica's example class with
few changes (and does not yet fix the 'fields parameter' bug that has been
fixed in this project). The class more or less explicitly states that new
methods must be created for every single API call - and only three of them are
created so far. This cannot be considered "in active development".

* https://github.com/TomKriek/copernica-api-php (and forks)

Last updated September 2019, and seemingly nearly complete as far as methods
go. (Not complete; it's missing EmailingStatistics and EmailingTemplates which
I am using.)

This has replaced the original code with Guzzle, which I appreciate. Judging by
the code and the README, this has been written by someone who
* loves chained method calls;
* does not like the fact that he needs to check the contents of a returned
  array (for e.g. database fields); he wants dedicated methods and classes for
  each entity and its properties.

While I am certainly [not](https://github.com/rmuit/sharpspring-restapi)
[against](https://github.com/rmuit/PracticalAfas/blob/master/README-update.md)
using value objects where it has a practical purpose... I tend to favor working
with the returned array data if the reasons to do otherwise aren't clear.
Wrapping returned data often just obfuscates them. That may be a personal
preference.

And I unfortunately cannot derive the practical purpose from the code or the
example in the README.

What I think has much more practical value than wrapping array data into a
bunch of separate PHP classes, is encapsulating the necessary checks that a
programmer needs to do repeatedly on data / call results which an API endpoint
returns. I favor being really strict and throwing exceptions for any unexpected
data, so that my 'regular' code doesn't have to do them / can be sure that the
data is as expected. The checks in various get*() commands would need to be
ported into individual classes in this project. (Likely one of the get*() calls
maps to each of the classes, which in itself is good.) And I'm not sure how
difficult it would be to port over the getEntitiesNextBatch() functionality.

For now, I'd rather spend some time writing this README and polishing my
existing code to publish it as a separate project, rather than retrofit the
checks I need into the copernica-api-php project and add it to / re-test my own
live projects after adding EmailingTemplates/EmailingStatistics.

Again, once I see a use for wrapping all data into loads of classes instead of
working with simple array data... I'll gladly reconsider adding my code into
the copernica-api-php project and retiring this one.

## Authors

* The Copernica team
* Roderik Muit - [Wyz](https://wyz.biz/)

## License

This library is licensed under the GPL, version 2 or any higher version -
except the CopernicaRestAPI class (which is not illegal for me to republish but
which is also downloadable from the Copernica website).

(The license may seem slightly odd but is chosen to keep the possibility of
distributing the code together with Drupal, which is a potential issue for the
profile import component. If that outlook changes, I might relicense a newer
version under either GPLv3 or MIT license.)

## Acknowledgments

* Partly sponsored by [Yellowgrape](http://www.yellowgrape.nl/), E-commerce
  specialists. (Original code was commissioned by them as a closed project;
  polishing/documenting/republishing the code was done in my own unpaid time.)

Copernica REST API PHP tools [![Build Status](https://travis-ci.com/rmuit/sharpspring-restapi.svg?branch=master)](https://travis-ci.com/rmuit/copernica-api)
============================

**Please note:** The CopernicaRestClient has already changed its interface since
the 1.0 release. While it is expected to be stable, we'll wait a little while
before tagging a 2.0 version. (There are TODOs in the code for some likely
speed improvements, and we want to be sure we don't need to break compatibility
in order to support them.) In the meantime, using master is recommended over
v1.0.

--

This builds on the CopernicaRestAPI class which Copernica offer for download,
and adds an API Client class containing some useful helper methods to work
more smoothly with returned data.

The code is the result of several months of work with various endpoints, to
the level that I'm reasonably confident the code is generally applicable and
future proof - so it's time to publish. Ideally it still needs work on
handling/documentation of errors.

## Usage

Only the basic API methods in CopernicaRestAPI are documented; the rest is left
for developers to discover if they feel so inclined.

CopernicaRestAPI contains get() / post() / put() and delete() calls (just like
the standard CopernicaRestAPI); it also contains two extra calls getEntity()
and getEntities() which do some extra checks and are guaranteed to return an
'entity' or 'list of entities' instead. (An 'entity' is something like a
profile / subprofile / emailing / database; likely anything that has an ID
value.) It is hopefully self evident which of the three 'get' methods
can best be used, based on the API endpoint.
```php
use CopernicaApi\CopernicaRestClient;

$client = new CopernicaRestClient(TOKEN);
// When using this library to code new processes, it is recommended to throw
// exceptions for any error encountered. (Using false or 0 instead of
// CopernicaRestClient::NONE is fine.)
$client->suppressApiCallErrors(CopernicaRestClient::NONE);

$new_id = $client->post("database/$db_id/profiles", ['fields' => ['email' => 'rm@wyz.biz']]);
$success = $client->put("profile/$new_id", ['email' => 'info@wyz.biz']);

// Get non-entity data (i.e. data that has no 'id' property).
$stats = $client->get("publisher/emailing/$id/statistics");

// Get a single entity, making sure that it still exists:
$profile = $client->getEntity("profile/$id");
// If for some reason you want to also have entity data returned if the entity
// was 'removed' from Copernica: (An exception gets thrown by default.)
$profile = $client->getEntity("profile/$id", [], CopernicaRestClient::GET_ENTITY_IS_REMOVED);

// Get a list of entities; this will check the structure with its
// start/total/count/etc properties, and return only the relevant 'data' part:
$mailings = $client->getEntities('publisher/emailings');
// If the list of mailings is longer than the limit returned by the API, get
// the next batches like this: (example code; you may not actually want to
// fetch them all before processing one giant array...)
while ($next_batch = $client->getEntitiesNextBatch()) {
    $mailings = array_merge($mailings, $next_batch);
    // It is possible to pause execution and fetch the next batch in a separate
    // PHP thread, with some extra work; check backupState() for this.
}
```

The response of some API calls contain lists of other entities inside an entity.
This is not very common. (It's likely only the case for 'structure' entities
like databases and collections, as opposed to 'user data' entities.) These
embedded entities are wrapped inside a similar set of metadata as an API result
for a list of entities. While this library does not implement perfect
structures to work with embedded entities, it does provide one method to
validate and 'unwrap' these metadata so the caller doesn't need to worry about
it. An example:
```php
$database = $client->getEntity("database/$db_id");
$collections = $client->getEmbeddedEntities($database, 'collections');
$first_collection = reset($collections);
$first_collection_fields = $client->getEmbeddedEntities($first_collection, 'fields');
// Note if you only need the collections of one database, or the fields of one
// collection, it is recommended to call the dedicated API endpoint instead.
```

### Error handling

If CopernicaRestClient methods receive an invalid response / fail to get any
response from the API, they usually throw an exception. However, callers can
influence this behavior and make the class method just return the invalid
response instead. The basic things to know are:

- The behavior can be influenced globally by setting certain types of errors
  to 'suppress' (i.e. not throw exceptions for), using
  `suppressApiCallErrors()`. The same values can also be passed as an argument
  to individual calls.

- Whether some responses are 'invalid', may depend on the specific application,
  like:
  - By default an exception is thrown if a single entity is queried (e.g.
    `getEntity("profile/$id")` which has been removed in the meantime. However,
    the API actually returns an entity with the correct ID, all fields empty,
    and a 'removed' property with a date value. If this empty entity should be
    returned without throwing an exception: see above example code.

- There is currently one case where the class is not strict, but application
  code can benefit by setting stricter rules:
  - calls to post() can return True (because the standard CopernicaRestApi
    class' code seems to suggest that it is possible for the API to return
    a 'success' response but not specify an ID). However, in many cases
    application code probably wants to be sure that post() returns an actual
    ID value. This can be done by
    `suppressApiCallErrors(CopernicaRestClient::NONE)`, which will make a
    True return value throw an exception instead. Individual calls can still
    be made to return True with
    `post(<url>, <data>, CopernicaRestClient::POST_RETURNS_NO_ID)`.

- There are loads of other constants which are only there 'just to be sure',
  influenced by the specific structure of the standard CopernicaRestAPI code.
  Go ahead and read the code if you want to, but it's probably best to ignore
  them unless you get unexpected exceptions. (In that case, a report is
  appreciated, because this code or this README might need changes.)


## Some more details

Below text is likely unimportant to most people (who just want to use a REST
API client class).

### Contents of this library

- copernica_rest_api.php (the CopernicaRestAPI class) as downloaded from the
  Copernica website (REST API v2 documentation - REST API example), with only a
  few changes.

- A CopernicaRestClient class which wraps around CopernicaRestAPI. Some
  comments reflect gaps (around detailed error reporting) which cannot be
  improved yet without further detailed knowledge about API responses. See
  examples above.

I hope to soon add some utility code to aid in writing automated tests for
processes using this class, and a component I'm using in a synchronization
process that can insert/update profiles and related subprofiles.

It is recommended to not use CopernicaRestAPI directly. I'm using it but
keeping it in a separate file, for a combination of overlapping reasons:
- Even though Copernica's API servers are quite stable and the API behavior is
  documented in https://www.copernica.com/en/documentation/restv2/rest-requests,
  there are still unknowns. (Mostly in the body/headers returned by the
  response, e.g whether POST requests could sometimes not return an ID; see
  POST_RETURNS_NO_ID above.) So, I was afraid of upsetting my live processes by
  moving completely away from the standard code (and writing code that was in
  itself more consistent by throwing exceptions for every Curl error or HTTP
  4xx/5xx response) until I observed more behavior in practice. I'd rather move
  slowly and carefully than have the chance of a bunch of errors in my
  processes because of making some wrong assumptions.
- This way, we can more easily track if Copernica makes changes to their
  reference class, and we can merge them in here fairly easily.
- Separating extra functionality interpreting the responses (e.g. getEntity() /
  getEntities()) from the code doing HTTP requests, seemed to make sense.

The approach does have disadvantages, though:

- Introducing a bunch of 'just to be sure' constants to suppress exceptions in
  possible future unknown cases, which are strictly tied to the
  CopernicaRestAPI structure... is awkward.
- Some error circumstances (that probably happen very infrequently), like
  Curl errors or HTTP 4xx/5xx responses during PUT requests, are still obscured.

We'll see how this evolves in the future.

### Extra branches

- 'copernica' holds the unmodified downloaded copernica_rest_api.php.
- 'copernica-changed' holds the patches to it (except for the addition of the
  namespace, which is done in 'master'):
  - Proper handling of array parameters like 'fields'.*
  - A little extra error handling in the get() call, as far as it's necessary
    to be kept close to the Curl call. (Additional error handling is in the
    extra wrapper class.)

\* Actually... The first patch doesn't seem to have any additional value
anymore. In case you want to know:
* https://www.copernica.com/en/documentation/restv2/rest-fields-parameter
  documents an example for the fields parameter of
  `https://api.copernica.com/v2/database/$id/profiles?fields[]=land%3D%3Dnetherlands&fields[]=age%3E16`
* This is impossible to do with Copernica's own class: passing an array with
  parameters results in
  `https://api.copernica.com/v2/database/$id/profiles?fields%5B0%5D=land%3D%3Dnetherlands&fields%5B1%5D=age%3E16`
* I could have sworn that around September 2019, querying the latter URL did
  not result in correct data being returned, which is why I patched the code
  to generate the former.
* As of the time of publishing this code, the latter URL works fine. So either
  I was doing something dumb, or Copernica has patched the API endpoint after
  September 2019 to be able to handle encoded [] characters as if they were not
  encoded. At any rate... The patch doesn't seem to have real value anymore,
  but I'll keep it.

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

I can certainly appreciate that last sentiment for 'embedded entities' (like
fields inside collections inside the response for a database), which contain
layers of metadata that calling code shouldn't need to deal with. However,
embedded entities are an outlier, not the norm.

While I am certainly [not](https://github.com/rmuit/sharpspring-restapi)
[against](https://github.com/rmuit/PracticalAfas/blob/master/README-update.md)
using value objects where it has a practical purpose... I tend to favor working
with the returned array data if the reasons to do otherwise aren't clear.
Wrapping returned data often just obfuscates it. That may be a personal
preference.

And I unfortunately cannot derive the practical purpose from the code or the
example in the README. The README only documents one example of those embedded
entities which IMHO isn't very representative of every day API use.

What I think has much more practical value (generally when dealing with remote
APIs) than wrapping array data into separate PHP classes, is doing all
necessary checks which code needs to do repeatedly on returned results, so that
a caller 1) doesn't need to deal with those; 2) can be absolutely sure that the
data returend by a client class is valid. (I favor being really strict and
throwing exceptions for any unexpected data, for reason 2.)

So: it would be important to me to integrate the strict checks I have made in
the various get*() commands, into individual classes in this project. The
advantage would likely be that the difference between my various get*() calls
would disappear, because only one of each maps to each of the classes. However,
* I cannot tell from reading the code, how successful that integration would be.
  (The code setup is unfortunately too opaque for me to immediately grasp this.)
* I'm also not sure how difficult it would be to port over the
  getEntitiesNextBatch() functionality.

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

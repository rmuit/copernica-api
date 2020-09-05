Copernica REST API PHP tools [![Build Status](https://travis-ci.com/rmuit/sharpspring-restapi.svg?branch=master)](https://travis-ci.com/rmuit/copernica-api)
============================

This project contains
* a ciient for the REST API (which adds some functionality and better error
  handling around the CopernicaRestAPI class that Copernica offer for download);
* a framework to enable writing automated tests for your PHP code that uses
  this library.

## Usage

Use the CopernicaRestClient class; act as if CopernicaRestAPI does not exist.

Only the basic API methods in CopernicaRestClient are documented; the rest is
left for developers to discover if they feel so inclined.

CopernicaRestClient contains get() / post() / put() and delete() calls (just like
the standard CopernicaRestAPI); it also contains two extra calls getEntity()
and getEntities() which do some extra checks and are guaranteed to return an
'entity' or 'list of entities' instead. (An 'entity' is something like a
profile / subprofile / emailing / database; likely anything that has an ID
value.) It is hopefully self evident which of the three 'get' methods
can best be used, based on the API endpoint.
```php
use CopernicaApi\CopernicaRestClient;

$client = new CopernicaRestClient(TOKEN);

$new_id = $client->post("database/$db_id/profiles", ['fields' => ['email' => 'rm@wyz.biz']]);

// put() often returns a location string for the updated entity, which often
// isn't very useful because it's the same as the first argument - e.g. in this
// case it always returns "profile/$new_id":
$client->put("profile/$new_id", ['fields' => ['email' => 'info@wyz.biz']]);
// ...but there are resources which can create new entities, e.g. the following
// call will update all profiles matching the company name and return true, but
// if zero profiles match then it will create a new profile and return its
// location:
$return = $client->put(
  "profile/$new_id",
  ['fields' => ['email' => 'info@wyz.biz', 'company' => 'Wyz']],
  ['fields' => ['company==Wyz'], 'create' => true]);
if ($return !== true) {
    list($unimportant__always_profile, $created_id) = explode('/', $return);
}

// Get non-entity data (i.e. data that has no 'id' property).
$stats = $client->get("publisher/emailing/$id/statistics");

// Get a single entity; throw an exception if it was removed earlier:
$profile = $client->getEntity("profile/$id");
// If we want to also have entity data returned (with all fields being empty
// strings) if the entity was 'removed' from Copernica:
$profile = $client->getEntity("profile/$id", [], CopernicaRestClient::GET_ENTITY_IS_REMOVED);

// Get a list of entities; this will return only the relevant 'data' part from
// the response: (If we want to have the full structure including start / count
// / etc, we can use get()):
$mailings = $client->getEntities('publisher/emailings');
// If the list of mailings is longer than the limit returned by the API, we can
// get the next batches like this: (example code; we may not actually want to
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
// Note if we only need the collections of one database, or the fields of one
// collection, it is recommended to call the dedicated API endpoint instead.
```

### Error handling (is imperfect)

If CopernicaRestClient methods receive an invalid or unrecognized response /
fail to get any response from the API, they throw an exception. Callers can
influence this behavior and make the class method just return the response
instead. Things to know:

- The behavior can be influenced globally by setting certain types of errors
  to 'suppress' (i.e. not throw exceptions for), using
  `suppressApiCallErrors()`. The same values can also be passed as an argument
  to individual calls.

- If an error is suppressed that was caused by a non-2XX HTTP code being
  returned, the post() / put() / delete() calls return the full headers and
  body returned in the HTTP response, so the caller can figure out what to do
  with it. get() calls return only the body.

- Whether some responses are 'invalid', may depend on the specific application,
  like:
  - By default an exception is thrown if a single entity is queried (e.g.
    `getEntity("profile/$id")`) which was deleted earlier. However, the API
    actually returns an entity with the correct ID, all fields empty, and a
    'removed' property with a date value. If this empty entity should be
    returned without throwing an exception: see above example code.
  - By default an exception is thrown if we try to re-delete an entity which
    was deleted earlier. This exception can be suppressed by passing
    CopernicaRestClient::DELETE_RETURNS_ALREADY_REMOVED to the second argument
    of `delete()` or setting it using `suppressApiCallErrors()`. In this case,
    the `delete()` call will return the full headers and body (including the
    JSON encoded error message). This return value can be ignored and the fact
    that the call returns normally can be treated as 'success'.

- Some API endpoints behave in unknown ways. CopernicaRestClient throws
  exceptions by default for unknown behavior; the intention is to never return
  a value to the caller if it can't be sure how to treat that value. This might
  however cause exceptions for 'valid' responses. Possible examples:
  - The standard CopernicaRestApi code seems to suggest that not all responses
    to POST requests contain an "X-Created" header containing the ID of a newly
    created entity. At the moment, the absence of this header will cause an
    exception (so the caller can be sure it gets an actual ID returned by
    default). If you run into cases where this is not OK: pass
    CopernicaRestClient::POST_RETURNS_NO_ID to the third argument of `post()`
    or set it using `suppressApiCallErrors()`. (We know of some calls which do
    this, but they are undocumented POST equivalents of PUT calls. We should
    really be using the documented PUT calls - so we don't mind that these
    throw exceptions.)

Any time you hit an exception that you need to work around (by e.g. fiddling
with these constants) but you think actually the class should handle this
better: feel free to file a bug report.

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

- A 'test implementation' of the Copernica API, i.e. a class that can be used
  instead of CopernicaRestAPI and that stores data internally. And PHPUnit
  tests which use this test API.

The 'test API' should enable writing tests for your own processes which use
CopernicaRestClient. See CopernicaRestClient::getClient() for an example on
how to instantiate a CopernicaRestClient which uses the test API. (Simplify at
will; the relevant code is about how to use TestApiFactory.)

The extra/ directory may contain example tests/other code I wrote for my own
processes.

#### Don't use CopernicaRestAPI

It is recommended to not use CopernicaRestAPI directly. I'm using it but
keeping it in a separate file, for a combination of overlapping reasons:
- Even though Copernica's API servers are quite stable and the API behavior is
  documented in https://www.copernica.com/en/documentation/restv2/rest-requests,
  there are still unknowns. (See "error handling" above.) So, I was afraid of
  upsetting my live processes by moving completely away from the standard code
  until I observed more behavior in practice.
- This way, we can more easily track if Copernica makes changes to their
  reference class, and we can merge them in here fairly easily.
- Separating extra functionality interpreting the responses (e.g. getEntity() /
  getEntities()) from the code doing HTTP requests, seemed to make sense.

The approach does have disadvantages, though:

- Introducing a bunch of 'just to be sure' constants to suppress exceptions in
  possible future unknown cases, which are strictly tied to the
  CopernicaRestAPI structure... is awkward.
- Our desire to not let any strange circumstances go unnoticed (which means we
  throw an exception for anything possibly-strange and have those constants to
  suppress them) has created a tight coupling between both classes.
- The division of responsibilities between CopernicaRestAPI and
  CopernicaRestClient is suboptimal / should ideally be rewritten. Indications
  of this:
  - Some 'communication from the API to the client class' is done through
    exceptions. This results in the many constants to suppress exceptions
    (which are much more in number / more illogical than needed if the code
    were set up differently), and the fact that the exception message must now
    contain the full response body in order for CopernicaRestClient to access
    it.
  - At the moment it is impossible to for CopernicaRestClient to get to the
    headers of responses to GET requests, because they are not inside exception
    messages.

It's likely that the next step in the evolution of this code will be to bite
the bullet and get rid of CopernicaRestAPI / make it only a very thin layer
(only to enable emulating API calls by tests). All this is unimportant for 'the
99.99% case' though, which should only use CopernicaRestClient directly and
doesn't need to use the illogical constants. It could take years until the next
rewrite as long as the current code just works, for all practical applications
we encounter.

### Extra branches

- 'copernica' holds the unmodified downloaded copernica_rest_api.php.
- 'copernica-changed' holds the patches to it (except for the addition of the
  namespace, which is done in 'master'):
  - An extra public property which enables throwing an exception when any
    non-2xx HTTP response is returned. (This enables CopernicaRestClient to do
    stricter checks... even when that means we need to do extra work to catch
    the exception for HTTP 303s which are always returned for PUT requests. It
    also enables us to extract and return the location header for PUT requests,
    which is significant if they create a new entity.)
  - Proper handling of array parameters like 'fields'.*

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

The library works with PHP5 and PHP7.

While PHP5 is way beyond end-of-life, I'm trying to keep it compatible as long
as I don't see a real benefit / because  I'm still used to it / because who
knows what old code companies are still running internally. I won't reject
PHP7-only additions though. (Admittedly not using the ?? operator is starting
to feel masochistic.)

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
few changes. The class more or less explicitly states that new methods must be
created for every single API call - and only three of them are created so far.
This cannot be considered "in active development".

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

I can appreciate that last sentiment for 'embedded entities' (like fields
inside collections inside the response for a database), which contain layers of
metadata that calling code shouldn't need to deal with. However, embedded
entities are an outlier, not the norm.

While I am [not](https://github.com/rmuit/sharpspring-restapi)
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
would disappear, because only one of each maps to each of the API endpoints.
However,
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

### Contributing

If you submit a PR and it's small: tell me explicitly if you don't want me to
rebase it / want to keep using your own repository as-is. (If a project is slow
moving and the PR changes are not too big, I tend to want to 'merge --rebase'
PR's if I can't fast-forward them, to keep the git history uncomplicated for
both me and yourself. But that does mean you're forced to change the commit
history on your side while you upgrade to my 'merged' upstream version.)

Adding tests to your changes is encouraged, but possibly not required; we'll
see about that on a case by case basis. (Unit test coverage isn't complete
anyway.)

## Authors

* The Copernica team
* Roderik Muit - [Wyz](https://wyz.biz/)

## Acknowledgments

* Partly sponsored by [Yellowgrape](http://www.yellowgrape.nl/), E-commerce
  specialists. (Original code was commissioned by them as a closed project;
  polishing/documenting/republishing the code and implementing most of the test
  code was done in my own unpaid time.)

The result of the partial sponsorship is a component for use in synchronization
processes which can update profiles plus attached subprofiles on the basis of
imported 'items' - in a trustworthy, efficient and configurable way. If you're
in need of such a component, feel free to contact me and state your case for
needing it. (It's not yet open sourced as it has cost a _lot_ of paid and
unpaid developer hours. The tests and helper code in extra/ give some insight
into the robustness of its code.)

## License

This library is licensed under the GPL, version 2 or any higher version -
except the CopernicaRestAPI class (which is not illegal for me to republish but
which is also downloadable from the Copernica website).

(The license may seem slightly odd but is chosen to keep the possibility of
distributing the code together with Drupal, which would be a potential issue
for the profile import component. If that outlook changes, I might relicense a
newer version under either GPLv3 or MIT license.)

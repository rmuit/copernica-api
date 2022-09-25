Copernica REST API PHP tools [![Build Status](https://travis-ci.com/rmuit/sharpspring-restapi.svg?branch=master)](https://travis-ci.com/rmuit/copernica-api)
============================

This project contains
* a client for the REST API (which adds some functionality and better error
  handling around the CopernicaRestAPI class that Copernica offer for download);
* helper classes for handling (larger/embedded) sets of entities;
* a framework to enable writing automated tests for your PHP code that uses
  this library.

## Usage

Use the RestClient class and the two other classes mentioned below; act as if
CopernicaRestAPI does not exist.

RestClient contains get() / post() / put() and delete() calls (just like the
standard CopernicaRestAPI); it also contains two extra calls getEntity() and
getEntities() which do some extra checks and are guaranteed to return a valid
'entity' or 'list of entities'. (An 'entity' is something like a profile /
subprofile / emailing / database; likely anything that has an ID value.) It is
hopefully self-evident which of the three 'get' methods can best be used, based
on the API endpoint.
```php
use CopernicaApi\Helper;
use CopernicaApi\RestClient;

$client = new RestClient(TOKEN);

// Create a new profile.
$id = $client->post("database/$db_id/profiles", ['fields' => ['email' => 'rm@wyz.biz']]);

// Update a single existing profile (or multiple, in the second example).
// put() often returns a location string for the updated entity, which often
// isn't very useful because it's the same as the first argument - e.g. in this
// case it always returns "profile/$id":
$client->put("profile/$id", ['fields' => ['email' => 'info@wyz.biz']]);
// ...but there are resources which can create new entities, e.g. the following
// call will update all profiles matching the company name and return true, but
// if zero profiles match then it will create a new profile and return its
// location:
$return = $client->put(
  "database/$db_id/profiles",
  // Profile data to update:
  ['fields' => ['email' => 'info@wyz.biz', 'company' => 'Wyz']],
  // Selection criteria for existing profiles:
  ['fields' => ['company==Wyz'], 'create' => true]);
if ($return !== true) {
    // (This is an odd way of having to extract the new ID, and requires you to
    // know how many parts the URL has - but it's compatible with the other
    // 'put' calls and allows the library to better handle any future behavior
    // changes.)
    list($unimportant__always_profile, $created_id) = explode('/', $return);
}

// Get non-entity data (i.e. data that has no 'id' property).
$stats = $client->get("publisher/emailing/$mailing_id/statistics");

// Get a single entity; throw an exception if it was removed earlier:
$profile = $client->getEntity("profile/$id");
// If we want to also have entity data returned (with all fields being empty
// strings) if the entity was 'removed' from Copernica, we can pass an argument
// to suppress that specific error. (There's practically no difference between
// the below and just calling get() instead.)
$profile = $client->getEntity("profile/$id", [], RestClient::GET_ENTITY_IS_REMOVED);

// Get a list of entities; this will return only the relevant 'data' part from
// the response. (If we want to have the full structure including start / count
// / etc, we can use get().) There's a limit to the returned number of entities.
$profiles = $client->getEntities("database/$db_id/profiles");
// Setting 'dataonly = true' on getEntities() calls for profiles or subprofiles
// can make the calls faster. It omits some property values from the individual
// (sub)profiles that are likely not needed anyway; see the method comments.
$profiles = $client->getEntities("database/$db_id/profiles", ['dataonly' => true]);
// The returned list has a zero-based index. If we want to access the profiles
// by ID, here's a quick helper method.
$profiles = Helper::rekeyEntities($profiles, 'ID');

// Delete a single entity; throw an exception if it was already removed/deleted
// earlier:
$profile = $client->delete("profile/$id");
// If we want to suppress the exception and just return true after re-deleting
// an entity:
$profile = $client->delete("profile/$id", RestClient::DELETE_RETURNS_ALREADY_REMOVED);
// To always suppress particular exceptions without having to pass it to
// every get() / delete() call, call e.g.:
$client->suppressApiCallErrors(RestClient::DELETE_RETURNS_ALREADY_REMOVED);
// There's a bunch of constants for suppressing other exceptions, but only the
// two mentioned here are likely to ever be needed.
```

Large sets of entities (larger than the limit which the API allows in one
response) need to be fetched in batches. BatchableRestClient is a Client
containing some helpful methods: getMoreEntities() and getMoreEntitiesOrdered().
Either of these two methods should be used depending on what data set is being
fetched; getMoreEntities() works for all types of entities but has a slightly
higher risk of skipping entities in some cases. See the method comments for
more detailed info. (Or, for starters, just copy the below example and replace
by getMoreEntities() if things don't work.)
```php
use CopernicaApi\BatchableRestClient;

$client = new BatchableRestClient(TOKEN);
$profiles = $client->getEntities("database/$db_id/profiles", ['orderby' => 'modified', 'fields' => ['modified>=2020-01-01'], 'dataonly' => true]);

// It is possible to pause execution and fetch the next batch in a separate
// PHP thread, with some extra work; check getState() for this.
while (!$client->allEntitiesFetched()) {
    $next_batch = $client->getMoreEntitiesOrdered([], ['fall_back_to_unordered' => true]);
    $profiles = array_merge($profiles, $next_batch);
}
```

The response of some API calls contain lists of other entities inside an entity.
This is not very common. (It's likely only the case for 'structure' entities
like databases and collections, as opposed to 'user data' entities.) These
embedded entities are wrapped inside a similar set of metadata as an API result
for a list of entities. While this library does not implement perfect
structures to work with embedded entities, it does provide a method to
validate and 'unwrap' these metadata so the caller doesn't need to worry about
it. An example:
```php
$database = $client->getEntity("database/$db_id");
$collections = Helper::getEmbeddedEntities($database, 'collections');
$collections = Helper::rekeyEntities($collections, 'ID');
$collection_fields = Helper::getEmbeddedEntities($collections[$a_collection_id], 'fields');
// Note if we only need the collections of one database, or the fields of one
// collection, it is recommended to call the dedicated API endpoint instead.
```

### Error handling

If RestClient methods receive an invalid or unrecognized response / fail to
get any response from the API, they throw an exception. Callers can influence
this behavior and make the class method just return the response instead.
Things to know:

- The behavior can be influenced globally by setting certain types of errors
  to 'suppress' (i.e. not throw exceptions for), using
  `suppressApiCallErrors()`. The same values can also be passed as an argument
  to individual calls.

- If an error is suppressed that was caused by a non-2XX HTTP code being
  returned, the post() / put() / delete() calls return the full headers and
  body returned in the HTTP response, so the caller can figure out what to do
  with it. get() calls return only the body.

- Whether some responses are 'invalid', may depend on the specific application.
  E.g. an exception is thrown by default
  - if a single entity is queried (e.g. `getEntity("profile/$id")`) which was
    deleted earlier. However, the API actually returns an entity with the
    correct ID, all fields empty, and a 'removed' property with a date value.
    If this empty entity should be returned without throwing an exception:
    see above example code.
  - if we try to re-delete an entity which was deleted earlier. Also see above
    example code. In that case the return value from delete() can be ignored,
    and the fact that the call returns normally can be treated as 'success'.

- Some API endpoints behave in unknown ways. RestClient throws exceptions by
  default for unknown behavior; the intention is to never return a value to the
  caller if it can't be sure how to treat that value. This might however cause
  exceptions for 'valid' responses. Possible examples:
  - The standard CopernicaRestApi code seems to suggest that not all responses
    to POST requests contain an "X-Created" header containing the ID of a newly
    created entity. At the moment, the absence of this header will cause an
    exception (so the caller can be sure it gets an actual ID returned by
    default). If you run into cases where this is not OK: pass
    RestClient::POST_RETURNS_NO_ID to the third argument of `post()` or set it
    using `suppressApiCallErrors()`. (We know of some calls which do this, but
    they are undocumented POST equivalents of PUT calls. We should really be
    using the documented PUT calls - so we don't mind that these throw
    exceptions.)

Any time you hit an exception that you need to work around (by e.g. fiddling
with these constants), but you think actually the class should handle this
better: feel free to file a bug report.

### Temporary network errors

For some software projects, it is relevant to know which errors can be
classified as temporary. (E.g. those that terminate a process on unexpected
errors and only want to continue / repeat their actions when specific
'known to be temporary' errors are encountered. Another strategy that could
work, but might be dangerous, is to only regard HTTP 400 response codes as
permanent, and regard everything else a temporary error - at the risk of a
process getting stuck retrying.)

Copernica's service infrastructure is quite stable but hiccups and temporary
outages can occur everywhere. This is a semi live document of errors observed:

Temporary:
- Curl occasionally returns error 7 "Failed to connect". The nature and
  frequency (relatively high compared to the rest) may make it necessary to
  treat this as a temporary error. This is typically not a hiccup on a single
  connection, but a service issue that lasts for a few minutes.
- Copernica occasionally returns HTTP response codes 503 (Service Unavailable)
  and 504 (gateway timeout), along with a HTML body with title "Loadbalancer
  Error" and a header mentioning "too many requests to handle". These typically
  last a few minutes maximum.

Not sure:
- Once, we've seen error 35
  "OpenSSL SSL_connect: SSL_ERROR_SYSCALL in connection to api.copernica.com:443".
  While this was a temporary error, this is not the only kind of SSL error that
  could return Curl error 35 - so I'm personally still hesitant thinking of
  this as "always temporary" until there's more of a need to.
- ~Jun 2020 we've observed Curl error 52 "Empty reply from server" for a GET
  query that would return a large result set. We don't have enough information
  to know if such an error would always be temporary. (It's also possible that
  this specific circumstance has in the meantime been replaced by returning
  a HTTP 504 response.)
- Once, we've seen a HTTP response code 502, along with a HTML body with title
  "502 Server Error". It was apparently a very temporary internal hiccup, but
  a 502 should probably not be treated as something that lasts only a short
  time (unless this starts happening more often).

## Some more details

Below text is likely unimportant to most people (who just want to use a REST
API client class).

### Other contents of this repository

The tests/ directory contains TestApi, a 'test implementation' of the Copernica
API, i.e. a class that can be used instead of CopernicaRestAPI and that stores
ata internally. Other code in tests/ are PHPUnit tests, and some small test
helper classes; a few tests are real unit tests, but most need an API to work
against, so they use TestApi.

TestApi should enable writing tests for your own processes which use the REST
API. The extra/ directory contains example tests/other code I wrote for my own
processes.

#### Don't use CopernicaRestAPI

It is recommended to not use CopernicaRestAPI directly. I'm using it but
keeping it in a separate file, for a combination of overlapping reasons:
- Even though Copernica's API servers are quite stable, and the API behavior is
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
  RestClient is suboptimal / should ideally be rewritten. Indications of this:
  - Some 'communication from the API to the client class' is done through
    exceptions. This results in the many constants to suppress exceptions
    (which are much more in number / more illogical than needed if the code
    were set up differently), and the fact that the exception message must now
    contain the full response body in order for RestClient to access it.
  - At the moment it is impossible to for RestClient to get to the headers of
    responses to GET requests, because they are not inside exception messages.

It's likely that the next step in the evolution of this code will be to bite
the bullet and get rid of CopernicaRestAPI / make it only a very thin layer
(only to enable emulating API calls by tests). All this is unimportant for 'the
99.99% case' though, which should only use RestClient directly and doesn't need
to use the illogical constants. It could take years until the next rewrite, as
long as the current code just works for all practical applications we encounter.

### Extra branches

- 'copernica' holds the unmodified downloaded copernica_rest_api.php.
- 'copernica-changed' holds the patches to it (except for the addition of the
  namespace, which is done in 'master'):
  - An extra public property which enables throwing an exception when any
    non-2xx HTTP response is returned. (This enables RestClient to do stricter
    checks... even when that means we need to do extra work to catch the
    exception for HTTP 303s which are always returned for PUT requests. It also
    enables us to extract and return the location header for PUT requests,
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

The library works with PHP7/8 and _probably_ with PHP5.

While PHP5 is way beyond end-of-life, I'm trying to keep it compatible as long
as I don't see a real benefit to using PHP7-only constructs, because I'm still
used to it / because who knows what old code companies are still running
internally. I won't reject PHP7-only additions though. And test coverage for
PHP5 was already dropped because the tests themselves contain PHP7-only
constructs, so I won't notice if I accidentally break PHP5 compatibility.

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
data returned by a client class is valid. (I favor being really strict and
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

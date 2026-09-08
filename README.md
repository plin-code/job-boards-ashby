# Job Boards Ashby

Ashby connector for the [plin-code](https://github.com/plin-code) job boards family. It reads the public Ashby posting API, which needs no credentials and returns a whole board in one request:

```
GET https://api.ashbyhq.com/posting-api/job-board/{slug}
{ "apiVersion": "2",
  "jobBoard": { "title": null, "description": "..." },
  "jobs": [ { "id": "abc-123", "title": "Backend Engineer", "location": "Paris", "isRemote": false, ... } ] }
```

It implements `PlinCode\JobBoards\Contracts\JobBoardClient` from [`plin-code/job-boards-core`](https://github.com/plin-code/job-boards-core), so it is interchangeable with every other connector in the family. Generated from [`plin-code/job-boards-skeleton`](https://github.com/plin-code/job-boards-skeleton).

## Installation

```bash
composer require plin-code/job-boards-ashby
```

## Framework agnostic on purpose

`AshbyClient` takes core's `HttpClient` and an optional PSR-3 logger. It imports nothing from Laravel, so a Symfony or plain PHP consumer builds it directly:

```php
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use PlinCode\JobBoards\Ashby\AshbyClient;
use PlinCode\JobBoards\Http\HttpClient;

$http = new HttpClient(new Client, new HttpFactory);

$client = new AshbyClient($http);

$jobs = $client->fetchJobsForCompany('acme');       // list<JobPostingDTO>
$name = $client->validateSlug('acme');              // ?string, see below
$about = $client->fetchCompanyDescription('acme');  // ?string
```

`AshbyServiceProvider` is the only Laravel aware file in the package, and all it does is that same wiring out of the container.

## Laravel usage

The provider is auto discovered.

```php
use PlinCode\JobBoards\Ashby\AshbyClient;

$client = app(AshbyClient::class);

foreach ($client->fetchJobsForCompany('acme') as $job) {
    JobPosting::updateOrCreate(
        ['external_id' => $job->externalId],
        $job->toArray(),
    );
}
```

Publish the config to change the base URL, the timeouts or the request headers:

```bash
php artisan vendor:publish --tag=job-boards-ashby-config
```

```php
'base_url'       => env('JOB_BOARDS_ASHBY_BASE_URL', AshbyClient::API_BASE_URL),
'timeout'        => env('JOB_BOARDS_ASHBY_TIMEOUT', 30),
'lookup_timeout' => env('JOB_BOARDS_ASHBY_LOOKUP_TIMEOUT', 15),
'headers'        => ['Accept' => 'application/json'],
```

The provider binds a PSR-18 client and a PSR-17 factory with `bindIf`, so an application that already binds its own keeps it. It deliberately does **not** bind `JobBoardClient` itself: several connectors implement that interface and would fight over the binding. Bind the one you want in your own application service provider.

## Mapping

| `JobPostingDTO` | Ashby field |
| --- | --- |
| `externalId` | `id`, falling back to `''` |
| `title` | `title`, falling back to `'Untitled Position'` |
| `location` | `location`, with `' (Remote)'` appended when `isRemote` is true; just `'Remote'` when there is no location; `null` when neither is present |
| `url` | `jobUrl`, falling back to `''` |
| `department` | `department`, or `null` |
| `rawPayload` | the untouched job object |

## The company name Ashby does not give you

`validateSlug()` is documented to return the company name. Ashby answers with `jobBoard.title` set to `null` on every board we have seen, so in practice the name is derived from the slug instead:

1. `jobBoard.title` when it is a non empty string. Kept because the field is documented, never observed populated.
2. Otherwise, if the payload carries a `jobs` array the board exists (Ashby 404s on an unknown identifier), so the slug is humanised: a trailing `.com`, `.io`, `.co`, `.org` or `.net` is stripped, then `-` and `_` become spaces and each word is capitalised. `kraken.com` becomes `Kraken`, `propel-auth` becomes `Propel Auth`.
3. Otherwise `null`.

## Slugs are not always plain

Ashby board identifiers are used verbatim as a URL path segment, and real ones include `jimdo.com` (a dot) and `Scale%20Army%20Careers` (already percent encoded). The connector normalises with a decode followed by an encode, which is idempotent: an already encoded slug is passed through untouched, a raw slug carrying a space or a slash is escaped instead of rewriting the path.

One consequence worth knowing: an already encoded slug humanises to itself, so `Scale%20Army%20Careers` comes back from `validateSlug()` unchanged rather than as `Scale Army Careers`. That is the behaviour of the application this connector was extracted from, and it is pinned by a test.

## Error handling

`fetchJobsForCompany()` never throws at the caller. Everything is logged through the injected PSR-3 logger and an empty list comes back, so one broken company cannot abort a sync over hundreds of them:

| Situation | Level | Message |
| --- | --- | --- |
| non 2xx status | `warning` | `Ashby API request failed` |
| payload has no `jobs` array | `warning` | `Ashby API response missing jobs array` |
| DNS failure, refused connection, timeout | `error` | `Ashby API connection error` |
| unreadable body, unexpected shape | `error` | `Unexpected error fetching Ashby jobs` |

Every record carries `company_slug`. With no logger passed, a `NullLogger` is used and everything is silent.

`validateSlug()` and `fetchCompanyDescription()` return `null` for every failure and log nothing. That is intentional: neither a 404 nor a dropped connection proves a slug is good, and callers use these to validate user input.

## Timeouts

PSR-18 has no notion of a timeout, so core's `HttpClient::withTimeout()` is only honoured by clients implementing `PlinCode\JobBoards\Http\SupportsTimeout`. Guzzle's PSR-18 client does not, so the configured 30 and 15 seconds are a request the transport may ignore. If timeouts matter to you, build the Guzzle client with `['timeout' => 30]` and bind it yourself, or wrap it in a small `SupportsTimeout` adapter.

## Depending on core

During local development this package resolves core through a path repository:

```json
"repositories": [
    { "type": "path", "url": "../job-boards-core", "options": { "symlink": true, "versions": { "plin-code/job-boards-core": "0.2.0" } } }
]
```

The `versions` option is what lets the published constraint `"plin-code/job-boards-core": "^0.2"` resolve against a local checkout sitting on `main`, which composer would otherwise see only as `dev-main`. Once core is on Packagist, drop the whole `repositories` block; the constraint already says the right thing.

## Development

```bash
composer install
composer lint          # pint, writes
composer lint:check    # pint, read only
composer analyse       # phpstan level 10, matching core
composer test:unit     # pest
composer test          # analyse + lint:check + test:unit
```

`tests/Unit` builds the client against a faked PSR-18 client and boots no framework. `tests/Feature` boots Testbench and covers the service provider only. The PSR-18 test doubles come from core, under `PlinCode\JobBoards\Testing`.

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

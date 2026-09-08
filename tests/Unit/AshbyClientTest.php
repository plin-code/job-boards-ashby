<?php

declare(strict_types=1);

use PlinCode\JobBoards\Ashby\AshbyClient;
use PlinCode\JobBoards\Data\JobPostingDTO;
use PlinCode\JobBoards\Testing\FakePsrClient;
use PlinCode\JobBoards\Testing\RecordingLogger;

function ashbyClient(FakePsrClient $fake, ?RecordingLogger $logger = null): AshbyClient
{
    return new AshbyClient($fake->asHttpClient(), logger: $logger);
}

/**
 * The real Ashby board payload: an apiVersion, a jobBoard object whose title is
 * null on every board we have seen, and the jobs array.
 *
 * @param  list<array<string, mixed>>  $jobs
 * @param  array<string, mixed>  $jobBoard
 */
function ashbyBoard(array $jobs, array $jobBoard = ['title' => null]): FakePsrClient
{
    return (new FakePsrClient)->respondWithJson([
        'apiVersion' => '2',
        'jobBoard' => $jobBoard,
        'jobs' => $jobs,
    ]);
}

it('fetches jobs for a valid company slug', function (): void {
    $fake = ashbyBoard([
        [
            'id' => 'abc-123',
            'title' => 'Backend Engineer',
            'location' => 'Paris',
            'department' => 'Engineering',
            'jobUrl' => 'https://jobs.ashbyhq.com/testco/abc-123',
            'isRemote' => false,
        ],
        [
            'id' => 'def-456',
            'title' => 'Frontend Engineer',
            'location' => 'Amsterdam',
            'department' => 'Design',
            'jobUrl' => 'https://jobs.ashbyhq.com/testco/def-456',
            'isRemote' => false,
        ],
    ]);

    $jobs = ashbyClient($fake)->fetchJobsForCompany('testco');

    expect($jobs)->toHaveCount(2)
        ->and($jobs[0])->toBeInstanceOf(JobPostingDTO::class)
        ->and($jobs[0]->externalId)->toBe('abc-123')
        ->and($jobs[0]->title)->toBe('Backend Engineer')
        ->and($jobs[0]->location)->toBe('Paris')
        ->and($jobs[0]->url)->toBe('https://jobs.ashbyhq.com/testco/abc-123')
        ->and($jobs[0]->department)->toBe('Engineering')
        ->and($jobs[1]->externalId)->toBe('def-456')
        ->and($fake->lastUri())->toBe('https://api.ashbyhq.com/posting-api/job-board/testco');
});

it('returns an empty list for an empty jobs array', function (): void {
    expect(ashbyClient(ashbyBoard([]))->fetchJobsForCompany('testco'))->toBe([]);
});

it('returns an empty list on a failed http response', function (): void {
    $fake = (new FakePsrClient)->respondWith(500, 'Server Error');
    $logger = new RecordingLogger;

    expect(ashbyClient($fake, $logger)->fetchJobsForCompany('broken'))->toBe([])
        ->and($logger->messages())->toBe(['Ashby API request failed'])
        ->and($logger->levels())->toBe(['warning'])
        ->and($logger->records[0]['context'])->toBe([
            'company_slug' => 'broken',
            'status' => 500,
            'body' => 'Server Error',
        ]);
});

it('returns an empty list on a connection error', function (): void {
    $fake = (new FakePsrClient)->throwNetworkError();
    $logger = new RecordingLogger;

    expect(ashbyClient($fake, $logger)->fetchJobsForCompany('timeout'))->toBe([])
        ->and($logger->messages())->toBe(['Ashby API connection error'])
        ->and($logger->levels())->toBe(['error'])
        ->and($logger->records[0]['context']['company_slug'])->toBe('timeout')
        ->and($logger->records[0]['context']['error'])->toContain('connection refused');
});

it('returns an empty list when the jobs key is missing', function (): void {
    $fake = (new FakePsrClient)->respondWithJson(['error' => 'not found']);
    $logger = new RecordingLogger;

    expect(ashbyClient($fake, $logger)->fetchJobsForCompany('invalid'))->toBe([])
        ->and($logger->messages())->toBe(['Ashby API response missing jobs array'])
        ->and($logger->levels())->toBe(['warning'])
        ->and($logger->records[0]['context']['response'])->toBe(['error' => 'not found']);
});

it('appends remote to the location when isRemote is true and a location exists', function (): void {
    $jobs = ashbyClient(ashbyBoard([
        ['id' => 'abc-123', 'title' => 'Engineer', 'location' => 'Berlin', 'jobUrl' => 'https://example.com', 'isRemote' => true],
    ]))->fetchJobsForCompany('testco');

    expect($jobs[0]->location)->toBe('Berlin (Remote)');
});

it('sets the location to remote when isRemote is true and there is no location', function (): void {
    $jobs = ashbyClient(ashbyBoard([
        ['id' => 'abc-123', 'title' => 'Engineer', 'jobUrl' => 'https://example.com', 'isRemote' => true],
    ]))->fetchJobsForCompany('testco');

    expect($jobs[0]->location)->toBe('Remote');
});

it('leaves the location null when there is neither a location nor a remote flag', function (): void {
    $jobs = ashbyClient(ashbyBoard([
        ['id' => 'a', 'title' => 'Engineer', 'jobUrl' => 'https://example.com', 'isRemote' => false],
        ['id' => 'b', 'title' => 'Engineer', 'location' => '', 'isRemote' => false],
    ]))->fetchJobsForCompany('testco');

    expect($jobs[0]->location)->toBeNull()
        ->and($jobs[1]->location)->toBeNull();
});

it('falls back to a placeholder title, an empty id and an empty url', function (): void {
    $jobs = ashbyClient(ashbyBoard([
        [],
        ['id' => 42],
    ]))->fetchJobsForCompany('testco');

    expect($jobs[0]->externalId)->toBe('')
        ->and($jobs[0]->title)->toBe('Untitled Position')
        ->and($jobs[0]->url)->toBe('')
        ->and($jobs[0]->department)->toBeNull()
        ->and($jobs[1]->externalId)->toBe('42');
});

it('stores the raw payload in the dto', function (): void {
    $jobs = ashbyClient(ashbyBoard([
        ['id' => 'abc-123', 'title' => 'Engineer', 'jobUrl' => 'https://example.com', 'customField' => 'value'],
    ]))->fetchJobsForCompany('testco');

    expect($jobs[0]->rawPayload)->toHaveKey('customField', 'value');
});

it('returns an empty list when the body is not json', function (): void {
    $fake = (new FakePsrClient)->respondWith(200, '<html>maintenance</html>');
    $logger = new RecordingLogger;

    expect(ashbyClient($fake, $logger)->fetchJobsForCompany('testco'))->toBe([])
        ->and($logger->messages())->toBe(['Unexpected error fetching Ashby jobs'])
        ->and($logger->levels())->toBe(['error']);
});

it('returns an empty list when a jobs entry is not an object', function (): void {
    $fake = (new FakePsrClient)->respondWithJson(['jobs' => ['not-an-object']]);
    $logger = new RecordingLogger;

    expect(ashbyClient($fake, $logger)->fetchJobsForCompany('testco'))->toBe([])
        ->and($logger->messages())->toBe(['Unexpected error fetching Ashby jobs']);
});

it('validates a valid slug and returns the job board title when Ashby sends one', function (): void {
    $fake = (new FakePsrClient)->respondWithJson([
        'jobBoard' => ['title' => 'TestCo Inc'],
        'jobs' => [],
    ]);

    expect(ashbyClient($fake)->validateSlug('testco'))->toBe('TestCo Inc');
});

it('returns null for an invalid slug', function (): void {
    $fake = (new FakePsrClient)->respondWith(404, 'Not Found');

    expect(ashbyClient($fake)->validateSlug('nonexistent'))->toBeNull();
});

it('returns null for slug validation on a connection error, and logs nothing', function (): void {
    $fake = (new FakePsrClient)->throwNetworkError();
    $logger = new RecordingLogger;

    expect(ashbyClient($fake, $logger)->validateSlug('timeout'))->toBeNull()
        ->and($logger->records)->toBe([]);
});

it('derives the name from the slug when the job board title is missing, null or empty', function (): void {
    // Ashby returns jobBoard.title as null on every board we have seen, so this
    // is the branch that runs in production.
    expect(ashbyClient(ashbyBoard([['id' => '1', 'title' => 'Engineer']], ['title' => null]))->validateSlug('propel'))->toBe('Propel')
        ->and(ashbyClient(ashbyBoard([['id' => '1']], ['title' => '']))->validateSlug('propel-auth'))->toBe('Propel Auth')
        ->and(ashbyClient((new FakePsrClient)->respondWithJson(['jobs' => [], 'apiVersion' => '2']))->validateSlug('under_score'))->toBe('Under Score');
});

it('strips a domain suffix when deriving the name from the slug', function (): void {
    expect(ashbyClient(ashbyBoard([['id' => '1']]))->validateSlug('kraken.com'))->toBe('Kraken')
        ->and(ashbyClient(ashbyBoard([['id' => '1']]))->validateSlug('jimdo.com'))->toBe('Jimdo')
        ->and(ashbyClient(ashbyBoard([['id' => '1']]))->validateSlug('acme.IO'))->toBe('Acme');
});

it('humanises an already encoded slug to itself, which is a known wart', function (): void {
    // Pinned deliberately. "Scale%20Army%20Careers" is a real board identifier
    // and it is stored encoded, so the derived name comes back encoded too.
    // Decoding here would be an improvement, and a behaviour change.
    expect(ashbyClient(ashbyBoard([['id' => '1']]))->validateSlug('Scale%20Army%20Careers'))
        ->toBe('Scale%20Army%20Careers');
});

it('returns null when the response has neither a job board title nor a jobs array', function (): void {
    $fake = (new FakePsrClient)->respondWithJson(['error' => 'unknown']);

    expect(ashbyClient($fake)->validateSlug('testco'))->toBeNull();
});

it('fetches the company description', function (): void {
    $fake = (new FakePsrClient)->respondWithJson([
        'jobBoard' => ['title' => 'Test Co', 'description' => 'Ashby company description.'],
        'jobs' => [],
    ]);

    expect(ashbyClient($fake)->fetchCompanyDescription('test-co'))->toBe('Ashby company description.');
});

it('returns null from fetchCompanyDescription when it is absent, empty or unreachable', function (): void {
    expect(ashbyClient((new FakePsrClient)->respondWithJson(['jobBoard' => ['title' => 'Test Co']]))->fetchCompanyDescription('x'))->toBeNull()
        ->and(ashbyClient((new FakePsrClient)->respondWithJson(['jobBoard' => ['description' => '']]))->fetchCompanyDescription('x'))->toBeNull()
        ->and(ashbyClient((new FakePsrClient)->respondWithJson(['jobBoard' => ['description' => 42]]))->fetchCompanyDescription('x'))->toBeNull()
        ->and(ashbyClient((new FakePsrClient)->respondWith(500, ''))->fetchCompanyDescription('fail'))->toBeNull()
        ->and(ashbyClient((new FakePsrClient)->respondWith(404, 'Not Found'))->fetchCompanyDescription('nope'))->toBeNull()
        ->and(ashbyClient((new FakePsrClient)->throwNetworkError())->fetchCompanyDescription('timeout'))->toBeNull()
        ->and(ashbyClient((new FakePsrClient)->respondWith(200, 'not json'))->fetchCompanyDescription('x'))->toBeNull();
});

it('keeps a dot in the slug and does not double encode an already encoded one', function (): void {
    // Both of these are real production board identifiers.
    $dotted = ashbyBoard([]);
    ashbyClient($dotted)->fetchJobsForCompany('jimdo.com');

    $encoded = ashbyBoard([]);
    ashbyClient($encoded)->fetchJobsForCompany('Scale%20Army%20Careers');

    expect($dotted->lastUri())->toBe('https://api.ashbyhq.com/posting-api/job-board/jimdo.com')
        ->and($encoded->lastUri())->toBe('https://api.ashbyhq.com/posting-api/job-board/Scale%20Army%20Careers');
});

it('percent encodes a raw slug that would otherwise rewrite the path', function (): void {
    $fake = ashbyBoard([]);

    ashbyClient($fake)->fetchJobsForCompany('a b/../c');

    expect($fake->lastUri())->toBe('https://api.ashbyhq.com/posting-api/job-board/a%20b%2F..%2Fc');
});

it('asks for 30 seconds when listing and 15 when looking up', function (): void {
    $fake = (new FakePsrClient)
        ->respondWithJson(['jobs' => []])
        ->respondWithJson(['jobBoard' => ['title' => 'TestCo'], 'jobs' => []])
        ->respondWithJson(['jobBoard' => ['description' => 'Hello']]);

    $client = ashbyClient($fake);
    $client->fetchJobsForCompany('testco');
    $client->validateSlug('testco');
    $client->fetchCompanyDescription('testco');

    expect($fake->appliedTimeouts)->toBe([30.0, 15.0, 15.0]);
});

it('is safe with no logger at all', function (): void {
    $fake = (new FakePsrClient)->throwNetworkError();

    expect((new AshbyClient($fake->asHttpClient()))->fetchJobsForCompany('testco'))->toBe([]);
});

it('accepts a custom base url', function (): void {
    $fake = ashbyBoard([]);

    (new AshbyClient($fake->asHttpClient(), 'https://fixtures.test/job-board/'))->fetchJobsForCompany('testco');

    expect($fake->lastUri())->toBe('https://fixtures.test/job-board/testco');
});

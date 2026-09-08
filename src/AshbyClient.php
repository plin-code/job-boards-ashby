<?php

declare(strict_types=1);

namespace PlinCode\JobBoards\Ashby;

use PlinCode\JobBoards\Contracts\JobBoardClient;
use PlinCode\JobBoards\Data\JobPostingDTO;
use PlinCode\JobBoards\Exceptions\InvalidResponseException;
use PlinCode\JobBoards\Exceptions\TransportException;
use PlinCode\JobBoards\Http\HttpClient;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Reads the public Ashby posting API:
 *
 *   GET https://api.ashbyhq.com/posting-api/job-board/{slug}
 *   { "apiVersion": "2", "jobBoard": { "title": null, "description": ... },
 *     "jobs": [ { "id": "abc-123", "title": "Backend Engineer", ... } ] }
 *
 * No credentials, no pagination: one request returns the whole board.
 *
 * Nothing here knows about Laravel. It is handed core's HttpClient and an
 * optional PSR-3 logger, both of which a Symfony or plain PHP consumer can
 * build by hand. {@see AshbyServiceProvider} is the only Laravel aware file.
 */
final class AshbyClient implements JobBoardClient
{
    public const string API_BASE_URL = 'https://api.ashbyhq.com/posting-api/job-board';

    /**
     * Listing a whole board can be slow, so it gets a longer budget than the
     * cheap name and description lookups below.
     */
    public const float TIMEOUT_SECONDS = 30.0;

    public const float LOOKUP_TIMEOUT_SECONDS = 15.0;

    /**
     * Trailing domain suffixes stripped when humanising a slug: "kraken.com"
     * is a real board identifier, "Kraken" is the company.
     */
    private const string DOMAIN_SUFFIX_PATTERN = '/\.(com|io|co|org|net)$/i';

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly HttpClient $http,
        private readonly string $baseUrl = self::API_BASE_URL,
        private readonly float $timeout = self::TIMEOUT_SECONDS,
        private readonly float $lookupTimeout = self::LOOKUP_TIMEOUT_SECONDS,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger;
    }

    /**
     * Never throws at the caller: whatever goes wrong is logged and an empty
     * list comes back, so one broken company cannot abort a sync over hundreds
     * of them.
     *
     * @return list<JobPostingDTO>
     */
    public function fetchJobsForCompany(string $slug): array
    {
        try {
            // get() rather than tryGet() so the transport error message survives
            // into the log. tryGet() would flatten it to a null.
            $response = $this->http->withTimeout($this->timeout)->get($this->endpoint($slug));

            if ($response->failed()) {
                $this->logger->warning('Ashby API request failed', [
                    'company_slug' => $slug,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return [];
            }

            $jobs = $response->json('jobs');

            if (! is_array($jobs)) {
                $this->logger->warning('Ashby API response missing jobs array', [
                    'company_slug' => $slug,
                    'response' => $response->json(),
                ]);

                return [];
            }

            $postings = [];

            foreach ($jobs as $job) {
                if (! is_array($job)) {
                    throw InvalidResponseException::unexpectedShape(
                        $response->url(),
                        'jobs.*',
                        get_debug_type($job),
                    );
                }

                /** @var array<string, mixed> $job */
                $postings[] = $this->mapToDTO($job);
            }

            return $postings;
        } catch (TransportException $e) {
            $this->logger->error('Ashby API connection error', [
                'company_slug' => $slug,
                'error' => $e->getMessage(),
            ]);

            return [];
        } catch (Throwable $e) {
            $this->logger->error('Unexpected error fetching Ashby jobs', [
                'company_slug' => $slug,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [];
        }
    }

    /**
     * Ashby answers with `jobBoard.title` set to null on every board we have
     * seen, so in practice this always falls through to humanising the slug.
     * The title branch is kept because the field is documented and may start
     * being populated.
     *
     * The presence of a `jobs` array is what proves the board exists: Ashby
     * returns 404 for an unknown identifier, so a 200 with jobs is enough.
     * Anything else, including a dead connection, is null: neither a 404 nor a
     * dropped connection proves the slug is good.
     */
    public function validateSlug(string $slug): ?string
    {
        try {
            // tryGet() here: this is silent by contract, so there is no message
            // to keep.
            $response = $this->http->withTimeout($this->lookupTimeout)->tryGet($this->endpoint($slug));

            if ($response === null || $response->failed()) {
                return null;
            }

            $title = $response->json('jobBoard.title');

            if (is_string($title) && $title !== '') {
                return $title;
            }

            return is_array($response->json('jobs')) ? $this->humanizeSlug($slug) : null;
        } catch (Throwable) {
            return null;
        }
    }

    public function fetchCompanyDescription(string $slug): ?string
    {
        try {
            $response = $this->http->withTimeout($this->lookupTimeout)->tryGet($this->endpoint($slug));

            if ($response === null || $response->failed()) {
                return null;
            }

            $description = $response->json('jobBoard.description');

            return is_string($description) && $description !== '' ? $description : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * "kraken.com" becomes "Kraken", "propel-auth" becomes "Propel Auth".
     *
     * Percent encoded slugs are left as they arrive, which is what the source
     * application does today: "Scale%20Army%20Careers" humanises to itself.
     */
    private function humanizeSlug(string $slug): string
    {
        $slug = preg_replace(self::DOMAIN_SUFFIX_PATTERN, '', $slug) ?? $slug;

        return ucwords(str_replace(['-', '_'], ' ', $slug));
    }

    /**
     * Ashby board identifiers are used verbatim as a path segment, and real
     * ones are not always plain: "jimdo.com" carries a dot and
     * "Scale%20Army%20Careers" arrives already percent encoded.
     *
     * Decoding before encoding makes this idempotent, so an already encoded
     * slug is not encoded a second time, while a raw slug carrying a slash or a
     * space still gets escaped instead of rewriting the path.
     */
    private function endpoint(string $slug): string
    {
        return sprintf('%s/%s', rtrim($this->baseUrl, '/'), rawurlencode(rawurldecode($slug)));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function mapToDTO(array $data): JobPostingDTO
    {
        $id = $data['id'] ?? null;
        $title = $data['title'] ?? null;
        $url = $data['jobUrl'] ?? null;
        $department = $data['department'] ?? null;

        return new JobPostingDTO(
            externalId: is_scalar($id) ? (string) $id : '',
            title: is_string($title) ? $title : 'Untitled Position',
            location: $this->location($data),
            url: is_string($url) ? $url : '',
            department: is_string($department) ? $department : null,
            rawPayload: $data,
        );
    }

    /**
     * Ashby sends one location string plus an isRemote flag, and either may be
     * absent: "Berlin", "Berlin (Remote)", "Remote" or null.
     *
     * @param  array<string, mixed>  $data
     */
    private function location(array $data): ?string
    {
        $location = $data['location'] ?? null;
        $location = is_string($location) && $location !== '' ? $location : null;
        $isRemote = (bool) ($data['isRemote'] ?? false);

        return match (true) {
            $location !== null && $isRemote => $location.' (Remote)',
            $location !== null => $location,
            $isRemote => 'Remote',
            default => null,
        };
    }
}

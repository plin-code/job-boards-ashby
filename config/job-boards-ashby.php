<?php

declare(strict_types=1);

use PlinCode\JobBoards\Ashby\AshbyClient;

return [

    /*
    |--------------------------------------------------------------------------
    | API Base URL
    |--------------------------------------------------------------------------
    |
    | The Ashby posting API root. The board slug is appended to it. Override it
    | to point the connector at a recorded fixture server.
    |
    */

    'base_url' => env('JOB_BOARDS_ASHBY_BASE_URL', AshbyClient::API_BASE_URL),

    /*
    |--------------------------------------------------------------------------
    | Timeouts
    |--------------------------------------------------------------------------
    |
    | Seconds. "timeout" covers listing a whole board, "lookup_timeout" the
    | cheaper calls behind validateSlug() and fetchCompanyDescription().
    | Honoured only by PSR-18 clients that implement
    | PlinCode\JobBoards\Http\SupportsTimeout; other clients keep the timeout
    | they were built with.
    |
    */

    'timeout' => env('JOB_BOARDS_ASHBY_TIMEOUT', 30),

    'lookup_timeout' => env('JOB_BOARDS_ASHBY_LOOKUP_TIMEOUT', 15),

    /*
    |--------------------------------------------------------------------------
    | Request Headers
    |--------------------------------------------------------------------------
    |
    | Sent with every request. The public posting API needs no authentication,
    | so Accept is all Ashby asks for.
    |
    */

    'headers' => [
        'Accept' => 'application/json',
    ],

];

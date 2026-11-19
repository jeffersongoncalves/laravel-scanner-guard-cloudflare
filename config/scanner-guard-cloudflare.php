<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | API Credentials
    |--------------------------------------------------------------------------
    |
    | An API token with the "Account Filter Lists: Edit" permission, the
    | account id, and the id of the Custom IP List the sink owns. The sink
    | REPLACES the whole list on every change: give it a list of its own,
    | never one you also edit by hand.
    |
    */
    'api_token' => env('SCANNER_GUARD_CLOUDFLARE_API_TOKEN'),

    'account_id' => env('SCANNER_GUARD_CLOUDFLARE_ACCOUNT_ID'),

    'list_id' => env('SCANNER_GUARD_CLOUDFLARE_LIST_ID'),

    /*
    |--------------------------------------------------------------------------
    | HTTP
    |--------------------------------------------------------------------------
    |
    | `timeout` per API call, in seconds. A list replace is an asynchronous
    | bulk operation: the sink polls its status up to `poll_attempts` times,
    | `poll_interval` seconds apart. One still pending after that is retried
    | on the next sync (Cloudflare allows one pending operation per account,
    | so a retry while it runs fails and is logged until it finishes).
    |
    */
    'timeout' => env('SCANNER_GUARD_CLOUDFLARE_TIMEOUT', 10),

    'poll_attempts' => env('SCANNER_GUARD_CLOUDFLARE_POLL_ATTEMPTS', 10),

    'poll_interval' => env('SCANNER_GUARD_CLOUDFLARE_POLL_INTERVAL', 1),

];

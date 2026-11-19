<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use JeffersonGoncalves\ScannerGuardCloudflare\CloudflareSink;

const ITEMS_URL = 'https://api.cloudflare.com/client/v4/accounts/acc123/rules/lists/list456/items';
const OPERATION_URL = 'https://api.cloudflare.com/client/v4/accounts/acc123/rules/lists/bulk_operations/op-1';

beforeEach(function () {
    Http::preventStrayRequests();
    Sleep::fake();
});

function fakeCloudflare(string ...$statuses): void
{
    $sequence = Http::sequence();

    foreach ($statuses as $status) {
        $sequence->push(['success' => true, 'result' => ['id' => 'op-1', 'status' => $status, 'error' => $status === 'failed' ? 'invalid ip' : null]]);
    }

    Http::fake([
        ITEMS_URL => Http::response(['success' => true, 'result' => ['operation_id' => 'op-1']]),
        OPERATION_URL => $sequence,
    ]);
}

function sink(): CloudflareSink
{
    return app(CloudflareSink::class);
}

it('replaces the list with every entry, commented with its reason and expiry', function () {
    fakeCloudflare('completed');

    sink()->sync([
        ['ip' => '185.177.72.0/24', 'reason' => 'static', 'expires_at' => null],
        ['ip' => '2001:db8::1', 'reason' => 'scanner_path', 'expires_at' => '2026-11-20T10:00:00+00:00'],
    ]);

    Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
        && $request->url() === ITEMS_URL
        && $request->hasHeader('Authorization', 'Bearer test-token')
        && $request->data() === [
            ['ip' => '185.177.72.0/24', 'comment' => 'static'],
            ['ip' => '2001:db8::1', 'comment' => 'scanner_path, expires 2026-11-20T10:00:00+00:00'],
        ]);
});

it('skips the api call when the list did not change since the last completed replace', function () {
    fakeCloudflare('completed', 'completed');
    $entries = [['ip' => '10.0.0.1', 'reason' => 'static', 'expires_at' => null]];

    sink()->sync($entries);
    sink()->sync($entries);

    Http::assertSentCount(2); // one PUT + one status poll
});

it('polls a pending operation until it completes', function () {
    fakeCloudflare('pending', 'running', 'completed');

    sink()->sync([]);

    Http::assertSentCount(4);
    Sleep::assertSleptTimes(2);
});

it('retries on the next sync when the operation is still pending after the last poll', function () {
    config(['scanner-guard-cloudflare.poll_attempts' => 2]);
    fakeCloudflare('pending', 'pending', 'completed');
    Log::spy();

    sink()->sync([]);
    sink()->sync([]);

    Http::assertSentCount(5); // PUT, 2 polls, then PUT + poll again
    Log::shouldHaveReceived('warning')->with('scanner-guard-cloudflare: list replace still pending, retrying on the next sync', Mockery::any())->once();
});

it('throws when the operation fails, so the next sync retries', function () {
    fakeCloudflare('failed');

    expect(fn () => sink()->sync([]))->toThrow(RuntimeException::class, 'invalid ip');
});

it('throws when the api rejects the replace', function () {
    Http::fake([ITEMS_URL => Http::response(['success' => false, 'errors' => [['message' => 'pending operation']]], 409)]);

    expect(fn () => sink()->sync([]))->toThrow(RequestException::class);
});

it('skips prefixes Cloudflare lists reject', function () {
    fakeCloudflare('completed');
    Log::spy();

    sink()->sync([
        ['ip' => '10.0.0.0/7', 'reason' => 'static', 'expires_at' => null],
        ['ip' => '10.0.0.0/8', 'reason' => 'static', 'expires_at' => null],
        ['ip' => '2001:db8::/11', 'reason' => 'static', 'expires_at' => null],
        ['ip' => '2001:db8::/12', 'reason' => 'static', 'expires_at' => null],
    ]);

    Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
        && array_column($request->data(), 'ip') === ['10.0.0.0/8', '2001:db8::/12']);
    Log::shouldHaveReceived('warning')->with('scanner-guard-cloudflare: skipped an entry Cloudflare lists reject', Mockery::any())->twice();
});

it('refuses to run without credentials', function () {
    config(['scanner-guard-cloudflare.list_id' => null]);

    expect(fn () => sink()->sync([]))->toThrow(RuntimeException::class, 'list_id');
    Http::assertNothingSent();
});

it('is driven by scanner-guard:sync-denylist', function () {
    fakeCloudflare('completed');
    config([
        'scanner-guard.driver' => 'http', // no database needed: bans come from the (empty) last pull
        'scanner-guard.sinks' => [CloudflareSink::class],
        'scanner-guard.ip_blocklist' => ['185.177.72.69'],
    ]);

    $this->artisan('scanner-guard:sync-denylist')->assertSuccessful();

    Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
        && $request->data() === [['ip' => '185.177.72.69', 'comment' => 'static']]);
});

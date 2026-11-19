<?php

declare(strict_types=1);

namespace JeffersonGoncalves\ScannerGuardCloudflare;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use JeffersonGoncalves\ScannerGuard\Contracts\DenylistSink;
use JeffersonGoncalves\ScannerGuard\ScannerGuard;
use RuntimeException;

/**
 * Replaces a Cloudflare Custom IP List with the denylist; a WAF custom rule
 * (`ip.src in $list`) then blocks those IPs at the edge. The list is only
 * replaced when its content changed since the last completed replace.
 */
class CloudflareSink implements DenylistSink
{
    public const HASH_KEY = 'scanner-guard-cloudflare:synced-hash';

    public function __construct(protected ScannerGuard $scannerGuard) {}

    public function sync(array $entries): void
    {
        $items = [];

        foreach ($entries as $entry) {
            if (! $this->accepts($entry['ip'])) {
                Log::warning('scanner-guard-cloudflare: skipped an entry Cloudflare lists reject', ['ip' => $entry['ip']]);

                continue;
            }

            $items[] = [
                'ip' => $entry['ip'],
                'comment' => $entry['expires_at'] === null ? $entry['reason'] : "{$entry['reason']}, expires {$entry['expires_at']}",
            ];
        }

        $hash = hash('sha256', (string) json_encode($items));
        $cache = $this->scannerGuard->cache();

        if ($cache->get(self::HASH_KEY) === $hash) {
            return;
        }

        [$account, $list] = $this->target();

        // ponytail: one PUT replaces the whole list; an empty list is sent as `[]` too.
        $operation = (string) $this->client()
            ->put("accounts/{$account}/rules/lists/{$list}/items", $items)
            ->throw()
            ->json('result.operation_id');

        if ($this->completed($account, $operation)) {
            $cache->forever(self::HASH_KEY, $hash);
        }
    }

    /**
     * Cloudflare lists take IPv4 /8–/32 and IPv6 /12–/128 (a bare address is
     * the longest prefix). Entries are already valid IPs/CIDRs.
     */
    protected function accepts(string $ip): bool
    {
        [$address, $prefix] = array_pad(explode('/', $ip, 2), 2, null);

        if ($prefix === null) {
            return true;
        }

        return (int) $prefix >= (str_contains($address, ':') ? 12 : 8);
    }

    /**
     * true once the bulk operation completed, false while it is still
     * pending after the last poll (retried on the next sync).
     */
    protected function completed(string $account, string $operation): bool
    {
        $attempts = max((int) config('scanner-guard-cloudflare.poll_attempts', 10), 1);

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $result = (array) $this->client()
                ->get("accounts/{$account}/rules/lists/bulk_operations/{$operation}")
                ->throw()
                ->json('result');

            $status = $result['status'] ?? null;

            if ($status === 'completed') {
                return true;
            }

            if ($status === 'failed') {
                throw new RuntimeException('Cloudflare list replace failed: '.($result['error'] ?? 'unknown error'));
            }

            if ($attempt < $attempts) {
                Sleep::for((int) config('scanner-guard-cloudflare.poll_interval', 1))->seconds();
            }
        }

        Log::warning('scanner-guard-cloudflare: list replace still pending, retrying on the next sync', ['operation_id' => $operation]);

        return false;
    }

    /**
     * @return array{string, string}
     */
    protected function target(): array
    {
        $account = (string) config('scanner-guard-cloudflare.account_id');
        $list = (string) config('scanner-guard-cloudflare.list_id');

        if ($account === '' || $list === '' || (string) config('scanner-guard-cloudflare.api_token') === '') {
            throw new RuntimeException('Set scanner-guard-cloudflare.api_token, account_id and list_id.');
        }

        return [$account, $list];
    }

    protected function client(): PendingRequest
    {
        return Http::baseUrl('https://api.cloudflare.com/client/v4/')
            ->withToken((string) config('scanner-guard-cloudflare.api_token'))
            ->acceptJson()
            ->timeout((int) config('scanner-guard-cloudflare.timeout', 10));
    }
}

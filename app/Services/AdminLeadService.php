<?php

namespace App\Services;

use App\Enums\CrawlStatus;
use App\Models\Website;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class AdminLeadService
{
    /**
     * Queue domains for the Python crawler to pick up.
     *
     * Coordination is through the database, matching how deep scans work: this
     * only inserts rows with `crawl_status = 'pending'` and a due
     * `next_crawl_at`. The Celery beat tick claims them within a minute. Nothing
     * here performs any HTTP work.
     *
     * Bulk Common Crawl discovery is NOT this endpoint. That runs in the worker
     * (`discover-common-crawl`), because it streams a multi-megabyte index and
     * cannot run inside a web request.
     *
     * @param  array<int, string>  $domains
     * @return array{queued:int, skipped:int, invalid:array<int, string>}
     */
    public function enqueueDomains(array $domains, string $source = 'admin'): array
    {
        $now = Carbon::now();
        $rows = [];
        $invalid = [];

        foreach ($domains as $domain) {
            try {
                $normalized = Website::normalizeDomain($domain);
            } catch (InvalidArgumentException) {
                $invalid[] = $domain;

                continue;
            }

            // Keyed by domain so a payload repeating the same host collapses to
            // one row rather than tripping the unique index mid-insert.
            $rows[$normalized] = [
                'domain' => $normalized,
                'canonical_url' => 'https://'.$normalized,
                'source' => $source,
                'crawl_status' => CrawlStatus::PENDING->value,
                'discovered_at' => $now,
                'last_seen_at' => $now,
                // Due immediately, so the next beat tick picks it up.
                'next_crawl_at' => $now->copy()->subSecond(),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows === []) {
            return ['queued' => 0, 'skipped' => 0, 'invalid' => $invalid];
        }

        $existing = Website::query()
            ->whereIn('domain', array_keys($rows))
            ->pluck('domain')
            ->all();

        $new = array_values(array_diff_key($rows, array_flip($existing)));

        if ($new !== []) {
            // insertOrIgnore rather than insert: a concurrent crawl could have
            // discovered the same domain between the select and this write.
            Website::query()->insertOrIgnore($new);
        }

        return [
            'queued' => count($new),
            'skipped' => count($rows) - count($new),
            'invalid' => $invalid,
        ];
    }

    /**
     * Make one domain due for an immediate re-crawl.
     *
     * Resets `crawl_attempts` because the ceiling counts consecutive failures —
     * without this, a domain already parked as `blocked` would be set due and
     * then immediately skipped by the claim predicate, which filters on the
     * ceiling. Returns null when the domain is unknown.
     *
     * A row currently being crawled is left alone: overwriting its status would
     * strand the worker's claim token and cause the completion write to be
     * silently discarded.
     */
    public function requestRecrawl(string $domain): ?array
    {
        try {
            $normalized = Website::normalizeDomain($domain);
        } catch (InvalidArgumentException) {
            return null;
        }

        $website = Website::query()->where('domain', $normalized)->first();

        if (! $website) {
            return null;
        }

        // crawl_status is cast to the CrawlStatus enum, so comparing against a
        // raw string here would never match and the in-flight guard below would
        // silently never fire.
        if ($website->crawl_status === CrawlStatus::CRAWLING) {
            return [
                'domain' => $normalized,
                'queued' => false,
                'reason' => 'A crawl is already in progress for this domain.',
            ];
        }

        DB::table('websites')
            ->where('id', $website->id)
            ->update([
                'crawl_status' => CrawlStatus::PENDING->value,
                'crawl_attempts' => 0,
                'next_crawl_at' => Carbon::now()->subSecond(),
                'last_crawl_error' => null,
                'updated_at' => Carbon::now(),
            ]);

        return [
            'domain' => $normalized,
            'queued' => true,
            'reason' => null,
        ];
    }
}

<?php

namespace App\Ingest;

use Illuminate\Support\Facades\DB;

/**
 * Fills events.host for the events stored before the column existed (migration 2026_10_19_100001), in chunks.
 */
final class HostBackfill
{
    /**
     * @return int Events that got a host.
     */
    public static function run(int $chunk = 500): int
    {
        $filled = 0;

        DB::table('events')->whereNull('host')->select(['id', 'payload', 'request_url'])->chunkById($chunk, function ($events) use (&$filled) {
            foreach ($events as $event) {
                $payload = json_decode((string) $event->payload, true);
                $payload = is_array($payload) ? $payload : [];
                $request = is_array($payload['request'] ?? null) ? $payload['request'] : [];
                $payload['request'] = $request + ['url' => $event->request_url];

                $host = HostResolver::resolve($payload);

                if ($host !== null) {
                    DB::table('events')->where('id', $event->id)->update(['host' => $host]);
                    $filled++;
                }
            }
        }, 'id');

        return $filled;
    }
}

<?php

namespace App\Ingest;

use App\Demo\DemoMode;
use App\Models\Project;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Two limits: events per minute per project, and failed authentications per minute per IP.
 */
class IngestRateLimiter
{
    /**
     * Reserve capacity for $events in the current minute. Returns the seconds to wait when over the limit.
     */
    public function consume(Project $project, int $events): ?int
    {
        $window = intdiv(time(), 60);
        $key = "ingest:rate:{$project->getKey()}:{$window}";

        Cache::add($key, 0, 120);
        $count = (int) Cache::increment($key, $events);

        if ($count > $this->limitFor($project)) {
            Cache::decrement($key, $events);

            return (($window + 1) * 60) - time();
        }

        return null;
    }

    /**
     * Events per minute allowed for the project. In public demo mode nobody gets more than the (lower) demo limit.
     */
    private function limitFor(Project $project): int
    {
        $limit = (int) $project->rate_limit_per_minute;

        if (DemoMode::enabled()) {
            $limit = min($limit, max(1, (int) config('nightguardian.demo.ingest_rate_limit_per_minute', 120)));
        }

        return $limit;
    }

    public function tooManyBadTokens(string $ip): ?int
    {
        $key = $this->badTokenKey($ip);
        $max = (int) config('nightguardian.ingest.invalid_token_rate_limit_per_minute');

        return RateLimiter::tooManyAttempts($key, $max) ? RateLimiter::availableIn($key) : null;
    }

    public function hitBadToken(string $ip): void
    {
        RateLimiter::hit($this->badTokenKey($ip), 60);
    }

    private function badTokenKey(string $ip): string
    {
        return 'ingest:bad-token:'.$ip;
    }
}

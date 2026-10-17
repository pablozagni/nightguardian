<?php

namespace App\Ingest;

use App\Models\ProjectToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Resolves the project from the "Authorization: Bearer ng_..." header.
 *
 * Tokens are looked up by sha256 hash. The hash -> token id mapping is cached for 60 seconds, so a revoked
 * token may keep working for up to a minute unless forget() is called when it is revoked.
 */
class TokenAuthenticator
{
    private const CACHE_SECONDS = 60;

    private const TOUCH_INTERVAL_SECONDS = 60;

    public function authenticate(Request $request): ?ProjectToken
    {
        $plain = $request->bearerToken();

        if ($plain === null || $plain === '') {
            return null;
        }

        $hash = ProjectToken::hash($plain);

        $id = Cache::remember(
            $this->cacheKey($hash),
            self::CACHE_SECONDS,
            fn () => ProjectToken::query()->where('token_hash', $hash)->whereNull('revoked_at')->value('id'),
        );

        if (! is_int($id)) {
            return null;
        }

        $token = ProjectToken::with('project')->find($id);

        if ($token === null || $token->isRevoked()) {
            return null;
        }

        $this->touch($token);

        return $token;
    }

    public function forget(string $plainToken): void
    {
        Cache::forget($this->cacheKey(ProjectToken::hash($plainToken)));
    }

    /**
     * Update last_used_at at most once per minute.
     */
    private function touch(ProjectToken $token): void
    {
        ProjectToken::query()
            ->whereKey($token->getKey())
            ->where(fn ($query) => $query
                ->whereNull('last_used_at')
                ->orWhere('last_used_at', '<', now()->subSeconds(self::TOUCH_INTERVAL_SECONDS)))
            ->update(['last_used_at' => now()]);
    }

    private function cacheKey(string $hash): string
    {
        return 'ingest:token:'.$hash;
    }
}

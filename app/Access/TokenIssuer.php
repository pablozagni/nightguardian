<?php

namespace App\Access;

use App\Models\Project;
use App\Models\ProjectToken;

/**
 * Creates and revokes ingest tokens. Only the sha256 of a token is stored: the plain value exists
 * in the return value of issue() and nowhere else, so callers must show it right away.
 */
class TokenIssuer
{
    /**
     * @return array{token: ProjectToken, plain: string}
     */
    public function issue(Project $project, string $name): array
    {
        $plain = ProjectToken::generatePlainToken();

        $token = $project->tokens()->create([
            'name' => $name,
            'token_hash' => ProjectToken::hash($plain),
        ]);

        return ['token' => $token, 'plain' => $plain];
    }

    public function revoke(ProjectToken $token): void
    {
        if (! $token->isRevoked()) {
            $token->forceFill(['revoked_at' => now()])->save();
        }
    }

    /**
     * The block to paste in the monitored app's .env, plus the install command.
     */
    public function installSnippet(string $plainToken, ?string $url = null): string
    {
        $url ??= rtrim((string) config('app.url'), '/');

        return "NIGHTGUARDIAN_URL={$url}\nNIGHTGUARDIAN_TOKEN={$plainToken}";
    }

    public function composerCommand(): string
    {
        return 'composer require nightguardian/laravel';
    }
}

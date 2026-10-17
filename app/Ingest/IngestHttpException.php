<?php

namespace App\Ingest;

use RuntimeException;

/**
 * A batch-level failure that maps directly to an HTTP status of protocol v1 (section 5).
 */
class IngestHttpException extends RuntimeException
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $headers = [],
    ) {
        parent::__construct($message);
    }

    public static function malformed(string $message): self
    {
        return new self(400, 'malformed', $message);
    }

    public static function tooLarge(string $message): self
    {
        return new self(413, 'too_large', $message);
    }

    public static function unauthorized(): self
    {
        return new self(401, 'unauthorized', 'Invalid or revoked token.');
    }

    public static function archived(): self
    {
        return new self(403, 'project_archived', 'This project is archived and does not accept events.');
    }

    public static function rateLimited(int $retryAfter): self
    {
        return new self(429, 'rate_limited', 'Rate limit exceeded.', ['Retry-After' => (string) max(1, $retryAfter)]);
    }
}

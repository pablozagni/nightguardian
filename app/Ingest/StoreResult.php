<?php

namespace App\Ingest;

final class StoreResult
{
    private function __construct(
        public readonly bool $duplicate,
        public readonly ?IssueUpdate $update,
    ) {}

    public static function duplicate(): self
    {
        return new self(true, null);
    }

    public static function accepted(?IssueUpdate $update): self
    {
        return new self(false, $update);
    }
}

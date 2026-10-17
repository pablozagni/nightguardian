<?php

namespace App\Ingest\Types;

use App\Enums\EventType;
use App\Grouping\Normalizer;

class SlowQueryType extends AbstractEventType
{
    public function type(): EventType
    {
        return EventType::SlowQuery;
    }

    public function validate(array $payload): array
    {
        $errors = [];

        if (! is_string($payload['sql'] ?? null) || $payload['sql'] === '') {
            $errors[] = 'payload.sql: required';
        }

        if (! is_numeric($payload['duration_ms'] ?? null)) {
            $errors[] = 'payload.duration_ms: required number';
        }

        return $errors;
    }

    public function title(array $event): string
    {
        $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];

        return $this->limit('Slow query: '.Normalizer::sql($this->string($payload['sql'] ?? ''))) ?? '';
    }

    public function culprit(array $event): ?string
    {
        $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
        $location = is_array($payload['location'] ?? null) ? $payload['location'] : [];
        $file = Normalizer::file($this->string($location['file'] ?? ''));

        if ($file === '') {
            return null;
        }

        return $this->limit(isset($location['line']) && is_int($location['line']) ? "{$file}:{$location['line']}" : $file);
    }

    public function exceptionClass(array $event): ?string
    {
        return null;
    }
}

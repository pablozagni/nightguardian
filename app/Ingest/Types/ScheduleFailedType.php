<?php

namespace App\Ingest\Types;

use App\Enums\EventType;

class ScheduleFailedType extends AbstractEventType
{
    public function type(): EventType
    {
        return EventType::ScheduleFailed;
    }

    public function validate(array $payload): array
    {
        $errors = [];

        if (! is_string($payload['command'] ?? null) || $payload['command'] === '') {
            $errors[] = 'payload.command: required';
        }

        if (isset($payload['exceptions'])) {
            $errors = [...$errors, ...$this->validateExceptions($payload['exceptions'])];
        }

        return $errors;
    }

    public function title(array $event): string
    {
        $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];

        return $this->limit('Scheduled command failed: '.$this->string($payload['command'] ?? '')) ?? '';
    }

    public function culprit(array $event): ?string
    {
        $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];

        return $this->limit($this->string($payload['command'] ?? '')) ?: null;
    }
}

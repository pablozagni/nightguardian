<?php

namespace App\Ingest\Types;

use App\Enums\EventType;

class JobFailedType extends AbstractEventType
{
    public function type(): EventType
    {
        return EventType::JobFailed;
    }

    public function validate(array $payload): array
    {
        $errors = [];

        if (! is_string($payload['job'] ?? null) || $payload['job'] === '') {
            $errors[] = 'payload.job: required';
        }

        return [...$errors, ...$this->validateExceptions($payload['exceptions'] ?? null)];
    }

    public function title(array $event): string
    {
        $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];

        return $this->limit($this->string($payload['job'] ?? 'Job').' failed: '.$this->exceptionTitle($event)) ?? '';
    }

    public function culprit(array $event): ?string
    {
        $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];

        return $this->limit($this->string($payload['job'] ?? '')) ?: parent::culprit($event);
    }
}

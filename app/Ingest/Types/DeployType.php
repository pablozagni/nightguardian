<?php

namespace App\Ingest\Types;

use App\Enums\EventType;

class DeployType extends AbstractEventType
{
    public function type(): EventType
    {
        return EventType::Deploy;
    }

    public function groups(): bool
    {
        return false;
    }

    public function validate(array $payload): array
    {
        $release = $payload['release'] ?? null;

        return is_string($release) && $release !== '' && mb_strlen($release) <= 128
            ? []
            : ['payload.release: required string (max 128)'];
    }

    public function title(array $event): string
    {
        $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];

        return 'Deploy '.$this->string($payload['release'] ?? '');
    }

    public function culprit(array $event): ?string
    {
        return null;
    }

    public function exceptionClass(array $event): ?string
    {
        return null;
    }
}

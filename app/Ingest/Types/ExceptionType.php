<?php

namespace App\Ingest\Types;

use App\Enums\EventType;

class ExceptionType extends AbstractEventType
{
    public function type(): EventType
    {
        return EventType::Exception;
    }

    public function validate(array $payload): array
    {
        if (! isset($payload['exceptions']) || $payload['exceptions'] === []) {
            return ['payload.exceptions: required'];
        }

        return $this->validateExceptions($payload['exceptions']);
    }

    public function title(array $event): string
    {
        return $this->exceptionTitle($event);
    }
}

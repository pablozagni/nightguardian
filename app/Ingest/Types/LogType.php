<?php

namespace App\Ingest\Types;

use App\Enums\EventType;
use App\Grouping\ExceptionChain;

class LogType extends AbstractEventType
{
    public function type(): EventType
    {
        return EventType::Log;
    }

    public function validate(array $payload): array
    {
        $errors = [];

        if (! is_string($payload['message'] ?? null) || $payload['message'] === '') {
            $errors[] = 'payload.message: required';
        }

        if (isset($payload['exception'])) {
            $errors = [...$errors, ...$this->validateException($payload['exception'], 'payload.exception')];
        }

        return $errors;
    }

    public function title(array $event): string
    {
        if (ExceptionChain::innermost($event) !== null) {
            return $this->exceptionTitle($event);
        }

        $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];

        return $this->limit($this->string($payload['message'] ?? '')) ?? '';
    }
}

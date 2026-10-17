<?php

namespace App\Ingest\Types;

use App\Enums\EventType;

class FeedbackType extends AbstractEventType
{
    public function type(): EventType
    {
        return EventType::Feedback;
    }

    public function groups(): bool
    {
        return false;
    }

    public function validate(array $payload): array
    {
        $errors = [];
        $comment = $payload['comment'] ?? null;

        if (! is_string($comment) || trim($comment) === '') {
            $errors[] = 'payload.comment: required';
        } elseif (mb_strlen($comment) > 2000) {
            $errors[] = 'payload.comment: at most 2000 characters';
        }

        if (isset($payload['email']) && ! is_string($payload['email'])) {
            $errors[] = 'payload.email: must be a string or null';
        }

        return $errors;
    }

    public function title(array $event): string
    {
        $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];

        return $this->limit($this->string($payload['comment'] ?? '')) ?? '';
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

<?php

namespace App\Ingest\Types;

use App\Enums\EventType;

class SlowRequestType extends AbstractEventType
{
    public function type(): EventType
    {
        return EventType::SlowRequest;
    }

    public function validate(array $payload): array
    {
        return is_numeric($payload['duration_ms'] ?? null) ? [] : ['payload.duration_ms: required number'];
    }

    public function title(array $event): string
    {
        return $this->limit('Slow request: '.$this->culprit($event)) ?? '';
    }

    public function culprit(array $event): ?string
    {
        $request = is_array($event['request'] ?? null) ? $event['request'] : [];
        $target = $this->string($request['route'] ?? '') ?: $this->string($request['url'] ?? '');

        return $this->limit(trim(strtoupper($this->string($request['method'] ?? '')).' '.$target)) ?: null;
    }

    public function exceptionClass(array $event): ?string
    {
        return null;
    }
}

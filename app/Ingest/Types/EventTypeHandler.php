<?php

namespace App\Ingest\Types;

use App\Enums\EventType;

/**
 * Knows how to validate and describe one event type of protocol v1.
 */
interface EventTypeHandler
{
    public function type(): EventType;

    /**
     * Whether events of this type are grouped into issues.
     */
    public function groups(): bool;

    /**
     * Validate the type-specific payload.
     *
     * @param  array<string, mixed>  $payload
     * @return list<string> error messages, empty when valid
     */
    public function validate(array $payload): array;

    /**
     * @param  array<string, mixed>  $event
     */
    public function title(array $event): string;

    /**
     * @param  array<string, mixed>  $event
     */
    public function culprit(array $event): ?string;

    /**
     * @param  array<string, mixed>  $event
     */
    public function exceptionClass(array $event): ?string;

    /**
     * Short text shown in event lists.
     *
     * @param  array<string, mixed>  $event
     */
    public function message(array $event): ?string;
}

<?php

namespace App\Ingest;

use App\Enums\Level;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Validates a decoded batch against protocol v1 (docs/protocol/v1.md, event.v1.schema.json).
 */
class BatchValidator
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    private const RFC3339 = '/^\d{4}-\d{2}-\d{2}[Tt]\d{2}:\d{2}:\d{2}(\.\d+)?([Zz]|[+-]\d{2}:\d{2})$/';

    private const REFERENCE = '/^NG-[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}$/';

    public function __construct(private readonly EventTypeRegistry $types) {}

    /**
     * Batch-level checks. Returns the list of raw events.
     *
     * @param  array<string, mixed>  $body
     * @return list<mixed>
     */
    public function events(array $body): array
    {
        $events = $body['events'] ?? null;

        if (! is_array($events) || $events === [] || ! array_is_list($events)) {
            throw IngestHttpException::malformed('"events" must be a non-empty array.');
        }

        if (count($events) > (int) config('nightguardian.ingest.max_events_per_request')) {
            throw IngestHttpException::tooLarge('Too many events in one request.');
        }

        return $events;
    }

    /**
     * Validate one event. Returns error messages; an empty list means the event is valid.
     *
     * @return list<string>
     */
    public function validate(mixed $event): array
    {
        if (! is_array($event) || array_is_list($event) && $event !== []) {
            return ['event: must be an object'];
        }

        $encoded = json_encode($event);

        if ($encoded !== false && strlen($encoded) > (int) config('nightguardian.ingest.max_event_bytes')) {
            return ['event: larger than the maximum event size'];
        }

        $errors = [];

        $id = $event['id'] ?? null;
        if (! is_string($id) || preg_match(self::UUID, $id) !== 1) {
            $errors[] = 'id: required UUID';
        }

        $type = $event['type'] ?? null;
        $handler = is_string($type) ? $this->types->find($type) : null;
        if (! is_string($type)) {
            $errors[] = 'type: required';
        } elseif ($handler === null) {
            $errors[] = 'type: unknown_type';
        }

        if (! $this->isTimestamp($event['timestamp'] ?? null)) {
            $errors[] = 'timestamp: required RFC 3339 date-time';
        }

        $levelRequired = ! in_array($type, ['deploy', 'feedback'], true);
        if (array_key_exists('level', $event) && $event['level'] !== null) {
            if (! is_string($event['level']) || Level::tryFrom($event['level']) === null) {
                $errors[] = 'level: must be a PSR-3 level';
            }
        } elseif ($levelRequired && $handler !== null) {
            $errors[] = 'level: required';
        }

        if (isset($event['reference']) && (! is_string($event['reference']) || preg_match(self::REFERENCE, $event['reference']) !== 1)) {
            $errors[] = 'reference: must match NG-XXXX-XXXX';
        }

        $errors = [
            ...$errors,
            ...$this->requiredString($event, 'environment', 64),
            ...$this->requiredString($event, 'platform', 32),
            ...$this->optionalString($event, 'release', 128),
            ...$this->optionalString($event, 'server', 255),
            ...$this->validateTags($event['tags'] ?? null),
            ...$this->validateFingerprint($event['fingerprint'] ?? null),
        ];

        foreach (['context', 'user', 'request', 'runtime', 'sdk'] as $key) {
            if (isset($event[$key]) && ! $this->isObject($event[$key])) {
                $errors[] = "{$key}: must be an object";
            }
        }

        if (isset($event['context']) && is_array($event['context'])) {
            $context = json_encode($event['context']);
            if ($context !== false && strlen($context) > 32 * 1024) {
                $errors[] = 'context: larger than 32 KB';
            }
        }

        $payload = $event['payload'] ?? null;
        if (! $this->isObject($payload)) {
            $errors[] = 'payload: required object';
        } elseif ($handler !== null) {
            $errors = [...$errors, ...$handler->validate($payload)];
        }

        return $errors;
    }

    private function isObject(mixed $value): bool
    {
        return is_array($value) && ($value === [] || ! array_is_list($value));
    }

    private function isTimestamp(mixed $value): bool
    {
        if (! is_string($value) || preg_match(self::RFC3339, $value) !== 1) {
            return false;
        }

        try {
            Carbon::parse($value);
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $event
     * @return list<string>
     */
    private function requiredString(array $event, string $key, int $max): array
    {
        $value = $event[$key] ?? null;

        return is_string($value) && $value !== '' && mb_strlen($value) <= $max
            ? []
            : ["{$key}: required string (max {$max})"];
    }

    /**
     * @param  array<string, mixed>  $event
     * @return list<string>
     */
    private function optionalString(array $event, string $key, int $max): array
    {
        if (! isset($event[$key])) {
            return [];
        }

        return is_string($event[$key]) && mb_strlen($event[$key]) <= $max ? [] : ["{$key}: must be a string (max {$max})"];
    }

    /**
     * @return list<string>
     */
    private function validateTags(mixed $tags): array
    {
        if ($tags === null) {
            return [];
        }

        if (! $this->isObject($tags)) {
            return ['tags: must be an object'];
        }

        /** @var array<array-key, mixed> $tags */
        if (count($tags) > 20) {
            return ['tags: at most 20 keys'];
        }

        foreach ($tags as $key => $value) {
            if (mb_strlen((string) $key) > 64 || ! is_string($value) || mb_strlen($value) > 200) {
                return ['tags: keys max 64 characters, values must be strings of max 200 characters'];
            }
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function validateFingerprint(mixed $fingerprint): array
    {
        if ($fingerprint === null) {
            return [];
        }

        if (! is_array($fingerprint) || ! array_is_list($fingerprint) || count($fingerprint) > 10) {
            return ['fingerprint: must be an array of at most 10 strings'];
        }

        foreach ($fingerprint as $part) {
            if (! is_string($part)) {
                return ['fingerprint: must be an array of at most 10 strings'];
            }
        }

        return [];
    }
}

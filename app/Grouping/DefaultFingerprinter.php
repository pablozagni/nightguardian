<?php

namespace App\Grouping;

/**
 * Default grouping strategy (docs/grouping.md). Parts are joined with "\n" and hashed with SHA-1.
 */
class DefaultFingerprinter
{
    public const DEFAULT_MARKER = '{{ default }}';

    /**
     * @param  array<string, mixed>  $event
     */
    public function fingerprint(array $event): string
    {
        $custom = $event['fingerprint'] ?? null;

        if (is_array($custom) && $custom !== []) {
            $parts = [];

            foreach ($custom as $part) {
                if ($part === self::DEFAULT_MARKER) {
                    array_push($parts, ...$this->defaultParts($event));
                } else {
                    $parts[] = (string) $part;
                }
            }

            return sha1(implode("\n", $parts));
        }

        return sha1(implode("\n", $this->defaultParts($event)));
    }

    /**
     * @param  array<string, mixed>  $event
     * @return list<string>
     */
    public function defaultParts(array $event): array
    {
        $type = is_string($event['type'] ?? null) ? $event['type'] : '';
        $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];

        return match ($type) {
            'exception', 'test' => $this->exceptionParts($type, $event),
            'log' => ExceptionChain::innermost($event) !== null
                ? $this->exceptionParts($type, $event)
                : [
                    $type,
                    $this->str($event['level'] ?? ''),
                    $this->str($payload['channel'] ?? ''),
                    Normalizer::message($this->str($payload['message'] ?? '')),
                ],
            'job_failed' => [
                $type,
                Normalizer::className($this->str($payload['job'] ?? '')),
                ...$this->innermostParts($event),
            ],
            'schedule_failed' => [$type, Normalizer::message($this->str($payload['command'] ?? ''))],
            'slow_request' => [$type, ...$this->requestParts($event)],
            'slow_query' => [
                $type,
                $this->str($payload['connection'] ?? ''),
                Normalizer::sql($this->str($payload['sql'] ?? '')),
            ],
            default => [$type, $this->str($event['id'] ?? '')],
        };
    }

    /**
     * @param  array<string, mixed>  $event
     * @return list<string>
     */
    private function exceptionParts(string $type, array $event): array
    {
        return [$type, ...$this->innermostParts($event)];
    }

    /**
     * Innermost exception class + culprit frame (or normalized message when there are no frames).
     *
     * @param  array<string, mixed>  $event
     * @return list<string>
     */
    private function innermostParts(array $event): array
    {
        $exception = ExceptionChain::innermost($event);

        if ($exception === null) {
            return ['', ''];
        }

        $class = Normalizer::className($this->str($exception['class'] ?? ''));
        $frame = ExceptionChain::culpritFrame($exception);

        return [
            $class,
            $frame !== null
                ? ExceptionChain::frameIdentity($frame)
                : Normalizer::message($this->str($exception['message'] ?? '')),
        ];
    }

    /**
     * @param  array<string, mixed>  $event
     * @return list<string>
     */
    private function requestParts(array $event): array
    {
        $request = is_array($event['request'] ?? null) ? $event['request'] : [];
        $route = $this->str($request['route'] ?? '');

        return [
            strtoupper($this->str($request['method'] ?? '')),
            $route !== '' ? $route : Normalizer::path($this->str($request['url'] ?? '')),
        ];
    }

    private function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}

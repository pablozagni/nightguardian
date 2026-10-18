<?php

namespace App\Support;

use App\Models\Event;

/**
 * Turns a stored protocol event into plain arrays for the panel views. Everything returned is untrusted
 * event data: views must print it with {{ }} only.
 */
class EventPresenter
{
    /**
     * Exceptions of the chain with their frames grouped in runs of in-app and vendor frames.
     * Frames keep the protocol order: most recent first (docs/adr/0005-frame-order.md).
     *
     * @return list<array{class: string, message: string, code: string|null, runs: list<array{in_app: bool, frames: list<array<string, mixed>>}>}>
     */
    public function exceptions(Event $event): array
    {
        $payload = $this->payload($event);
        $exceptions = $payload['exceptions'] ?? null;

        if (! is_array($exceptions)) {
            return [];
        }

        $result = [];

        foreach ($exceptions as $exception) {
            if (! is_array($exception)) {
                continue;
            }

            $result[] = [
                'class' => $this->string($exception['class'] ?? null),
                'message' => $this->string($exception['message'] ?? null),
                'code' => isset($exception['code']) ? $this->string($exception['code']) : null,
                'runs' => $this->runs(is_array($exception['frames'] ?? null) ? $exception['frames'] : []),
            ];
        }

        return $result;
    }

    /**
     * @param  array<int, mixed>  $frames
     * @return list<array{in_app: bool, frames: list<array<string, mixed>>}>
     */
    private function runs(array $frames): array
    {
        $runs = [];

        foreach ($frames as $frame) {
            if (! is_array($frame)) {
                continue;
            }

            $inApp = (bool) ($frame['in_app'] ?? false);
            $view = $this->frame($frame, $inApp);

            $last = array_key_last($runs);

            if ($last !== null && $runs[$last]['in_app'] === $inApp) {
                $runs[$last]['frames'][] = $view;
            } else {
                $runs[] = ['in_app' => $inApp, 'frames' => [$view]];
            }
        }

        return $runs;
    }

    /**
     * @param  array<mixed>  $frame
     * @return array<string, mixed>
     */
    private function frame(array $frame, bool $inApp): array
    {
        $line = is_int($frame['line'] ?? null) ? $frame['line'] : null;
        $context = is_array($frame['context'] ?? null) ? $frame['context'] : null;

        return [
            'file' => $this->string($frame['file'] ?? null),
            'line' => $line,
            'call' => $this->call($frame),
            'in_app' => $inApp,
            'code' => $inApp && $context !== null ? $this->code($context, $line) : [],
        ];
    }

    /**
     * @param  array<mixed>  $frame
     */
    private function call(array $frame): string
    {
        $function = $this->string($frame['function'] ?? null);
        $class = $this->string($frame['class'] ?? null);

        if ($function === '') {
            return '';
        }

        return $class === '' ? $function.'()' : $class.'::'.$function.'()';
    }

    /**
     * Source lines around the failing one: [{number, text, current}].
     *
     * @param  array<mixed>  $context
     * @return list<array{number: int|null, text: string, current: bool}>
     */
    private function code(array $context, ?int $line): array
    {
        $pre = array_values(array_filter(is_array($context['pre'] ?? null) ? $context['pre'] : [], 'is_string'));
        $post = array_values(array_filter(is_array($context['post'] ?? null) ? $context['post'] : [], 'is_string'));
        $current = is_string($context['line'] ?? null) ? $context['line'] : '';
        $first = $line === null ? null : max(1, $line - count($pre));

        $lines = [];
        $number = $first;

        foreach ($pre as $text) {
            $lines[] = ['number' => $number, 'text' => $text, 'current' => false];
            $number = $number === null ? null : $number + 1;
        }

        $lines[] = ['number' => $line, 'text' => $current, 'current' => true];
        $number = $line === null ? null : $line + 1;

        foreach ($post as $text) {
            $lines[] = ['number' => $number, 'text' => $text, 'current' => false];
            $number = $number === null ? null : $number + 1;
        }

        return $lines;
    }

    /**
     * @return array<string, mixed>
     */
    public function request(Event $event): array
    {
        $request = $this->raw($event)['request'] ?? null;

        return is_array($request) ? $request : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function user(Event $event): array
    {
        $user = $this->raw($event)['user'] ?? null;

        return is_array($user) ? $user : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function tags(Event $event): array
    {
        $tags = $this->raw($event)['tags'] ?? null;

        return is_array($tags) ? $tags : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function context(Event $event): array
    {
        $context = $this->raw($event)['context'] ?? null;

        return is_array($context) ? $context : [];
    }

    /**
     * Pretty JSON for any value (escaped by the view).
     */
    public function json(mixed $value): string
    {
        return (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    /**
     * The type specific part of the event (`payload` of the protocol event).
     *
     * @return array<string, mixed>
     */
    public function payload(Event $event): array
    {
        $payload = $this->raw($event)['payload'] ?? null;

        return is_array($payload) ? $payload : [];
    }

    /**
     * The stored protocol event.
     *
     * @return array<string, mixed>
     */
    private function raw(Event $event): array
    {
        return $event->payload;
    }

    private function string(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}

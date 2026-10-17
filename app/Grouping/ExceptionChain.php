<?php

namespace App\Grouping;

/**
 * Helpers to read exception data from a protocol v1 event.
 */
class ExceptionChain
{
    /**
     * The innermost exception: last of payload.exceptions (ordered outermost to innermost),
     * or payload.exception for log events.
     *
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>|null
     */
    public static function innermost(array $event): ?array
    {
        $payload = $event['payload'] ?? [];

        if (! is_array($payload)) {
            return null;
        }

        $exceptions = $payload['exceptions'] ?? null;

        if (is_array($exceptions) && $exceptions !== []) {
            $last = end($exceptions);

            return is_array($last) ? $last : null;
        }

        $single = $payload['exception'] ?? null;

        return is_array($single) && $single !== [] ? $single : null;
    }

    /**
     * First in-app frame (top of stack first); if none, the first frame.
     *
     * @param  array<string, mixed>  $exception
     * @return array<string, mixed>|null
     */
    public static function culpritFrame(array $exception): ?array
    {
        $frames = $exception['frames'] ?? [];

        if (! is_array($frames) || $frames === []) {
            return null;
        }

        $frames = array_values(array_filter($frames, 'is_array'));

        foreach ($frames as $frame) {
            if (($frame['in_app'] ?? false) === true) {
                return $frame;
            }
        }

        return $frames[0] ?? null;
    }

    /**
     * Normalized "file class function" identity of a frame (no line number).
     *
     * @param  array<string, mixed>  $frame
     */
    public static function frameIdentity(array $frame): string
    {
        return implode(' ', [
            Normalizer::file(self::string($frame['file'] ?? null)),
            Normalizer::className(self::string($frame['class'] ?? null)),
            self::string($frame['function'] ?? null) ?? '',
        ]);
    }

    /**
     * Human readable culprit, e.g. "App\Http\Controllers\InvoiceController@update".
     *
     * @param  array<string, mixed>  $frame
     */
    public static function frameLabel(array $frame): ?string
    {
        $class = Normalizer::className(self::string($frame['class'] ?? null));
        $function = self::string($frame['function'] ?? null);

        if ($class !== '' && $function !== null && $function !== '') {
            return $class.'@'.$function;
        }

        $file = Normalizer::file(self::string($frame['file'] ?? null));

        if ($file === '') {
            return null;
        }

        $line = $frame['line'] ?? null;

        return $function !== null && $function !== '' ? $file.'@'.$function : $file.(is_int($line) ? ':'.$line : '');
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}

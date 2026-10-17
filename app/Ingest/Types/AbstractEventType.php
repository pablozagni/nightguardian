<?php

namespace App\Ingest\Types;

use App\Grouping\ExceptionChain;
use App\Grouping\Normalizer;
use Illuminate\Support\Str;

abstract class AbstractEventType implements EventTypeHandler
{
    public function groups(): bool
    {
        return true;
    }

    public function culprit(array $event): ?string
    {
        $exception = ExceptionChain::innermost($event);
        $frame = $exception === null ? null : ExceptionChain::culpritFrame($exception);

        return $frame === null ? null : $this->limit(ExceptionChain::frameLabel($frame));
    }

    public function exceptionClass(array $event): ?string
    {
        $class = ExceptionChain::innermost($event)['class'] ?? null;

        return is_string($class) ? $this->limit(Normalizer::className($class)) : null;
    }

    public function message(array $event): ?string
    {
        return $this->limit($this->title($event), 1024);
    }

    /**
     * @return list<string>
     */
    protected function validateExceptions(mixed $exceptions, string $path = 'payload.exceptions'): array
    {
        if (! is_array($exceptions) || ! array_is_list($exceptions)) {
            return ["{$path}: must be an array"];
        }

        $errors = [];

        foreach ($exceptions as $i => $exception) {
            $errors = [...$errors, ...$this->validateException($exception, "{$path}.{$i}")];
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    protected function validateException(mixed $exception, string $path): array
    {
        if (! is_array($exception)) {
            return ["{$path}: must be an object"];
        }

        $errors = [];

        if (! is_string($exception['class'] ?? null) || $exception['class'] === '' || mb_strlen($exception['class']) > 255) {
            $errors[] = "{$path}.class: required string (max 255)";
        }

        if (isset($exception['message']) && (! is_string($exception['message']) || mb_strlen($exception['message']) > 8192)) {
            $errors[] = "{$path}.message: must be a string (max 8192)";
        }

        if (isset($exception['frames'])) {
            if (! is_array($exception['frames']) || ! array_is_list($exception['frames'])) {
                $errors[] = "{$path}.frames: must be an array";
            } elseif (count($exception['frames']) > 100) {
                $errors[] = "{$path}.frames: at most 100 frames";
            }
        }

        return $errors;
    }

    /**
     * "Class: message" for the innermost exception.
     *
     * @param  array<string, mixed>  $event
     */
    protected function exceptionTitle(array $event): string
    {
        $exception = ExceptionChain::innermost($event);

        if ($exception === null) {
            return 'Unknown error';
        }

        $class = is_string($exception['class'] ?? null) ? $exception['class'] : 'Exception';
        $message = is_string($exception['message'] ?? null) ? trim(preg_replace('/\s+/', ' ', $exception['message']) ?? '') : '';

        return $this->limit($message === '' ? class_basename($class) : class_basename($class).': '.$message) ?? $class;
    }

    protected function limit(?string $value, int $max = 255): ?string
    {
        return $value === null ? null : Str::limit($value, $max, '…');
    }

    protected function string(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}

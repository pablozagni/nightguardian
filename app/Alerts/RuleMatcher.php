<?php

namespace App\Alerts;

use App\Models\AlertRule;
use App\Models\Event;
use App\Models\Issue;

/**
 * Evaluates the project's alert rules against an issue and its latest event.
 */
class RuleMatcher
{
    public const FIELDS = ['exception_class', 'message', 'route', 'type', 'level'];

    /**
     * @param  iterable<AlertRule>  $rules
     * @return list<AlertRule> the enabled rules that match, in position order
     */
    public function matching(iterable $rules, Issue $issue, ?Event $event): array
    {
        $matched = [];

        foreach ($rules as $rule) {
            if ($rule->enabled && $this->matches($rule, $issue, $event)) {
                $matched[] = $rule;
            }
        }

        return $matched;
    }

    public function matches(AlertRule $rule, Issue $issue, ?Event $event): bool
    {
        $actual = $this->value($rule->field, $issue, $event);

        if ($actual === null) {
            return false;
        }

        $expected = $rule->value;

        return match ($rule->operator) {
            'equals' => $actual === $expected,
            'contains' => $expected !== '' && str_contains($actual, $expected),
            'starts_with' => $expected !== '' && str_starts_with($actual, $expected),
            'regex' => $this->regex($expected, $actual),
            default => false,
        };
    }

    private function value(string $field, Issue $issue, ?Event $event): ?string
    {
        if (str_starts_with($field, 'tag:')) {
            $tags = $event->payload['tags'] ?? null;
            $value = is_array($tags) ? ($tags[substr($field, 4)] ?? null) : null;

            return is_scalar($value) ? (string) $value : null;
        }

        return match ($field) {
            'exception_class' => $issue->exception_class,
            'message' => $event->message ?? $issue->title,
            'route' => $event?->request_route,
            'type' => $event->type->value ?? $issue->type,
            'level' => ($event->level ?? $issue->level)->value,
            default => null,
        };
    }

    private function regex(string $pattern, string $subject): bool
    {
        // Users type a bare pattern; delimiters are ours. An invalid pattern simply never matches.
        $result = @preg_match('~'.str_replace('~', '\~', $pattern).'~u', $subject);

        return $result === 1;
    }
}

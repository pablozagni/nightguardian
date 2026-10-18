<?php

namespace App\Alerts;

/**
 * A ready-to-send, already translated notification. Channels render it as HTML (Telegram) or plain text (mail).
 * Everything in it can come from event data: renderers must escape.
 */
final class AlertMessage
{
    /**
     * @param  string  $kind  new, regressed, threshold, rule, digest or test
     * @param  list<string>  $lines  Detail lines under the title.
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $icon,
        public readonly string $headline,
        public readonly string $title,
        public readonly array $lines = [],
        public readonly ?string $url = null,
    ) {}

    /**
     * @return array{kind: string, icon: string, headline: string, title: string, lines: list<string>, url: string|null}
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'icon' => $this->icon,
            'headline' => $this->headline,
            'title' => $this->title,
            'lines' => $this->lines,
            'url' => $this->url,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        /** @var list<string> $lines */
        $lines = array_values(array_map('strval', (array) ($data['lines'] ?? [])));

        return new self(
            (string) ($data['kind'] ?? 'new'),
            (string) ($data['icon'] ?? ''),
            (string) ($data['headline'] ?? ''),
            (string) ($data['title'] ?? ''),
            $lines,
            isset($data['url']) ? (string) $data['url'] : null,
        );
    }

    /** Telegram "HTML" parse mode: only <b> is used, everything else is escaped. */
    public function toHtml(): string
    {
        $e = static fn (string $text): string => htmlspecialchars($text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $parts = ['<b>'.$e($this->icon.' '.$this->headline).'</b>'];

        if ($this->title !== '') {
            $parts[] = $e($this->title);
        }

        foreach ($this->lines as $line) {
            $parts[] = $e($line);
        }

        if ($this->url !== null) {
            $parts[] = '→ '.$e($this->url);
        }

        return implode("\n", $parts);
    }

    public function toPlain(): string
    {
        $parts = [trim($this->icon.' '.$this->headline)];

        if ($this->title !== '') {
            $parts[] = $this->title;
        }

        foreach ($this->lines as $line) {
            $parts[] = $line;
        }

        if ($this->url !== null) {
            $parts[] = '→ '.$this->url;
        }

        return implode("\n", $parts);
    }

    public function subject(): string
    {
        return mb_substr(trim($this->icon.' '.$this->headline.($this->title !== '' ? ' — '.$this->title : '')), 0, 200);
    }
}

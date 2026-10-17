<?php

namespace App\Grouping;

/**
 * Normalization rules from docs/grouping.md.
 */
class Normalizer
{
    public const MESSAGE_LIMIT = 255;

    public static function file(?string $file): string
    {
        if ($file === null || $file === '') {
            return '';
        }

        $file = str_replace('\\', '/', $file);
        // Deploy directories such as /var/www/app/releases/20261017120000/app/Foo.php -> app/Foo.php
        $file = preg_replace('#^.*?(?:^|/)releases/[^/]+/#', '', $file) ?? $file;

        while (str_starts_with($file, './')) {
            $file = substr($file, 2);
        }

        return $file;
    }

    public static function message(?string $message): string
    {
        if ($message === null) {
            return '';
        }

        $replacements = [
            '/"(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\'/s' => '<str>',
            '#\b[a-z][a-z0-9+.-]*://[^\s<>"\']+#i' => '<url>',
            '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/' => '<email>',
            '/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i' => '<uuid>',
            '/\b[0-9a-f]{8,}\b/i' => '<hex>',
            '/\d+/' => '<n>',
        ];

        foreach ($replacements as $pattern => $placeholder) {
            $message = preg_replace($pattern, $placeholder, $message) ?? $message;
        }

        $message = trim(preg_replace('/\s+/', ' ', $message) ?? $message);

        return mb_substr($message, 0, self::MESSAGE_LIMIT);
    }

    public static function sql(?string $sql): string
    {
        if ($sql === null) {
            return '';
        }

        $sql = preg_replace('#/\*.*?\*/#s', ' ', $sql) ?? $sql;
        $sql = preg_replace('/(?:--|#)[^\r\n]*/', ' ', $sql) ?? $sql;
        $sql = strtolower($sql);
        $sql = trim(preg_replace('/\s+/', ' ', $sql) ?? $sql);

        return preg_replace('/\bin\s*\(\s*\?(?:\s*,\s*\?)*\s*\)/', 'in (?)', $sql) ?? $sql;
    }

    public static function className(?string $class): string
    {
        if ($class === null) {
            return '';
        }

        // "class@anonymous/path/to/File.php:12$0" -> "class@anonymous"
        return preg_replace('/@anonymous.*$/s', '@anonymous', $class) ?? $class;
    }

    /**
     * Path of a URL without the query string, with numeric/uuid segments replaced.
     */
    public static function path(?string $url): string
    {
        if ($url === null || $url === '') {
            return '';
        }

        $path = parse_url($url, PHP_URL_PATH);
        $path = is_string($path) ? $path : $url;

        return preg_replace(
            ['#/\d+(?=/|$)#', '#/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}(?=/|$)#i'],
            ['/<n>', '/<uuid>'],
            $path,
        ) ?? $path;
    }
}

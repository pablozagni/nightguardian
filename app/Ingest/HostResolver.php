<?php

namespace App\Ingest;

/**
 * The installation an event comes from (events.host): the `host` tag when the SDK sent one (the Laravel SDK tags every
 * event with the host of APP_URL), otherwise the host of the request URL. Null for events with neither.
 */
final class HostResolver
{
    public const MAX_LENGTH = 255;

    /**
     * @param  array<string, mixed>  $event  A protocol event (or the payload stored for it).
     */
    public static function resolve(array $event): ?string
    {
        $tags = is_array($event['tags'] ?? null) ? $event['tags'] : [];
        $tag = $tags['host'] ?? null;

        if (is_string($tag) && trim($tag) !== '') {
            return mb_substr(trim($tag), 0, self::MAX_LENGTH);
        }

        $request = is_array($event['request'] ?? null) ? $event['request'] : [];
        $url = $request['url'] ?? null;
        $host = is_string($url) && $url !== '' ? parse_url($url, PHP_URL_HOST) : null;

        return is_string($host) && $host !== '' ? mb_substr(strtolower($host), 0, self::MAX_LENGTH) : null;
    }
}

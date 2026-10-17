<?php

namespace App\Privacy;

/**
 * Server-side scrubbing (defense in depth, see docs/security.md).
 *
 * Keys matching the denylist (case-insensitive substring) have their value replaced, and sensitive value
 * patterns (card numbers, bearer tokens, JWTs, private key blocks) are replaced inside every string.
 */
class Scrubber
{
    public const FILTERED = '[Filtered]';

    /** @var list<string> */
    public const DEFAULT_KEYS = [
        'password', 'passwd', 'pwd', 'secret', 'token', 'api_key', 'apikey', 'access_key', 'private_key',
        'authorization', 'auth', 'cookie', 'session', 'csrf', 'xsrf', 'credit_card', 'card_number', 'cvv', 'iban',
    ];

    /** @var list<string> */
    private array $keys;

    /**
     * @param  list<string>  $extraKeys  Additional denylist keys (configuration).
     */
    public function __construct(array $extraKeys = [])
    {
        $this->keys = array_values(array_unique(array_map(
            'strtolower',
            [...self::DEFAULT_KEYS, ...$extraKeys],
        )));
    }

    public static function fromConfig(): self
    {
        /** @var list<string> $extra */
        $extra = config('nightguardian.scrub.extra_keys', []);

        return new self($extra);
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public function scrub(array $data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && $this->isSensitiveKey($key)) {
                $result[$key] = self::FILTERED;
            } elseif (is_array($value)) {
                $result[$key] = $this->scrub($value);
            } elseif (is_string($value)) {
                $result[$key] = $this->scrubString($value);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    public function scrubString(string $value): string
    {
        // Private key blocks.
        $value = preg_replace(
            '/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?(?:-----END [A-Z ]*PRIVATE KEY-----|$)/s',
            self::FILTERED,
            $value,
        ) ?? $value;

        // Bearer tokens.
        $value = preg_replace('/\bBearer\s+[A-Za-z0-9._~+\/=-]+/i', 'Bearer '.self::FILTERED, $value) ?? $value;

        // JWTs.
        $value = preg_replace(
            '/\beyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]*/',
            self::FILTERED,
            $value,
        ) ?? $value;

        // Card numbers: 13-19 digits, optionally separated by spaces or dashes, Luhn-valid.
        return preg_replace_callback(
            '/(?<![\d-])\d(?:[ -]?\d){12,18}(?![\d-])/',
            fn (array $m) => $this->passesLuhn(preg_replace('/\D/', '', $m[0]) ?? '') ? self::FILTERED : $m[0],
            $value,
        ) ?? $value;
    }

    private function isSensitiveKey(string $key): bool
    {
        $key = strtolower($key);

        foreach ($this->keys as $needle) {
            if (str_contains($key, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function passesLuhn(string $digits): bool
    {
        $length = strlen($digits);

        if ($length < 13 || $length > 19) {
            return false;
        }

        $sum = 0;

        for ($i = 0; $i < $length; $i++) {
            $digit = (int) $digits[$length - 1 - $i];

            if ($i % 2 === 1) {
                $digit *= 2;

                if ($digit > 9) {
                    $digit -= 9;
                }
            }

            $sum += $digit;
        }

        return $sum % 10 === 0;
    }
}

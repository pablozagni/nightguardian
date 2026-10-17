<?php

namespace App\Ingest;

use Illuminate\Http\Request;

/**
 * Turns the raw request body into the decoded batch array, enforcing size and depth limits.
 */
class PayloadDecoder
{
    private const CHUNK = 1024;

    /**
     * @return array<string, mixed>
     */
    public function decode(Request $request): array
    {
        $maxCompressed = (int) config('nightguardian.ingest.max_compressed_bytes');
        $maxUncompressed = (int) config('nightguardian.ingest.max_uncompressed_bytes');
        $depth = (int) config('nightguardian.ingest.max_json_depth');

        $raw = $request->getContent();
        $encoding = strtolower(trim((string) $request->header('Content-Encoding', 'identity')));

        if ($encoding === 'gzip') {
            if (strlen($raw) > $maxCompressed) {
                throw IngestHttpException::tooLarge('Compressed body exceeds the limit.');
            }

            $raw = $this->gunzip($raw, $maxUncompressed);
        } elseif ($encoding === 'identity' || $encoding === '') {
            if (strlen($raw) > $maxUncompressed) {
                throw IngestHttpException::tooLarge('Body exceeds the limit.');
            }
        } else {
            throw IngestHttpException::malformed("Unsupported Content-Encoding \"{$encoding}\".");
        }

        $data = json_decode($raw, true, $depth);

        if (! is_array($data) || ($data !== [] && array_is_list($data))) {
            throw IngestHttpException::malformed(
                json_last_error() === JSON_ERROR_DEPTH ? 'JSON is nested too deeply.' : 'Body must be a JSON object.',
            );
        }

        return $data;
    }

    /**
     * Inflate in small chunks and stop as soon as the output cap is exceeded (gzip-bomb protection).
     */
    private function gunzip(string $compressed, int $max): string
    {
        $context = inflate_init(ZLIB_ENCODING_GZIP);

        if ($context === false) {
            throw IngestHttpException::malformed('Unable to initialise gzip decoder.');
        }

        $output = '';
        $length = strlen($compressed);

        set_error_handler(static fn () => true);

        try {
            for ($offset = 0; $offset < $length; $offset += self::CHUNK) {
                $piece = inflate_add($context, substr($compressed, $offset, self::CHUNK), ZLIB_SYNC_FLUSH);

                if ($piece === false) {
                    throw IngestHttpException::malformed('Invalid gzip data.');
                }

                $output .= $piece;

                if (strlen($output) > $max) {
                    throw IngestHttpException::tooLarge('Uncompressed body exceeds the limit.');
                }
            }

            if ($length === 0 || inflate_get_status($context) !== ZLIB_STREAM_END) {
                throw IngestHttpException::malformed('Invalid or truncated gzip data.');
            }
        } finally {
            restore_error_handler();
        }

        return $output;
    }
}

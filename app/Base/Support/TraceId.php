<?php

namespace App\Base\Support;

final class TraceId
{
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    private const REQUEST_ATTRIBUTE = 'blb_trace_id';

    public static function current(?string $preferred = null): string
    {
        if (! app()->bound('request')) {
            return $preferred ?? self::generate();
        }

        $request = request();
        $existing = $request->attributes->get(self::REQUEST_ATTRIBUTE);

        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        $traceId = $preferred ?? self::generate();
        $request->attributes->set(self::REQUEST_ATTRIBUTE, $traceId);

        return $traceId;
    }

    /**
     * Generate a compact request/process trace ID.
     *
     * Uses 12 Crockford Base32 characters (60 bits), stored without display
     * separators. UI may render it in 4-4-4 groups for readability.
     */
    public static function generate(): string
    {
        $traceId = '';
        $maxIndex = strlen(self::ALPHABET) - 1;

        for ($i = 0; $i < 12; $i++) {
            $traceId .= self::ALPHABET[random_int(0, $maxIndex)];
        }

        return $traceId;
    }
}

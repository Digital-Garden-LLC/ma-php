<?php

namespace Miniargus\Tracing;

/**
 * Generates random trace/span IDs as lowercase hex -- 16 bytes for a trace
 * ID, 8 for a span ID, per the W3C Trace Context / OTel convention (matches
 * ma-go's tracing/idgen.go). These are correlation identifiers, not
 * secrets, so a non-cryptographic fallback is an acceptable last resort on
 * a PHP 5.6 install without the openssl extension -- it just needs to be
 * unlikely to collide, not unpredictable.
 */
final class IdGenerator
{
    /**
     * @param int $bytes
     * @return string
     */
    public static function generate($bytes)
    {
        // random_bytes() is PHP 7.0+; not assumed on a 5.6 floor.
        if (function_exists('random_bytes')) {
            return bin2hex(random_bytes($bytes));
        }

        if (function_exists('openssl_random_pseudo_bytes')) {
            $strong = false;
            $raw = openssl_random_pseudo_bytes($bytes, $strong);
            if ($raw !== false) {
                return bin2hex($raw);
            }
        }

        $hex = '';
        for ($i = 0; $i < $bytes; $i++) {
            $hex .= str_pad(dechex(mt_rand(0, 255)), 2, '0', STR_PAD_LEFT);
        }
        return $hex;
    }
}

<?php
namespace EzMvc;

/**
 * Cryptographically secure random helpers, built on random_int()/random_bytes()
 * — PHP's CSPRNG, backed by the OS source (getrandom/CryptGenRandom, openssl as
 * one provider) and always available in PHP 7+.
 *
 * They deliberately have no mt_rand() fallback: if the system has no secure
 * source these throw (\Random\RandomException on 8.2+, \Exception/\Error before)
 * and the request 500s — a loud failure is safer than a silently predictable
 * token. Suitable for session ids, CSRF tokens, password-reset links, etc.
 */
class Random {
    const DICTIONARY = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

    /**
     * A random string of $length characters drawn from DICTIONARY, uniformly
     * (random_int() has no modulo bias). No length limit.
     */
    public static function getString(int $length = 16): string {
        $dict = self::DICTIONARY;
        $max  = strlen($dict) - 1;
        $out  = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $dict[random_int(0, $max)];
        }
        return $out;
    }

    /** A random lowercase-hex string of $length hex characters. No length limit. */
    public static function getHex(int $length = 16): string {
        return substr(bin2hex(random_bytes((int) ceil($length / 2))), 0, $length);
    }

    /**
     * A random integer in [$minimum, $maximum]. With $maximum left at 0 it
     * returns a full-range non-negative int, ignoring $minimum.
     */
    public static function getInt(int $minimum = 0, int $maximum = 0): int {
        return $maximum
            ? random_int($minimum, $maximum)
            : random_int(0, PHP_INT_MAX);
    }
}

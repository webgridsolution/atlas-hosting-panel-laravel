<?php

namespace App\Domain\Security;

/**
 * RFC 6238 time-based one-time passwords.
 *
 * Written out rather than pulled in as a package because it is forty lines of
 * well-specified arithmetic and the panel already avoids dependencies it does
 * not need. Every constant here comes from RFC 4226 (HOTP) and RFC 6238 (TOTP);
 * the tests check it against the published test vectors, which is the only way
 * to know an implementation of this is right.
 *
 * Compatible with Google Authenticator, Authy, 1Password, Bitwarden, FreeOTP and
 * anything else that scans an otpauth:// URI.
 */
final class Totp
{
    /** Seconds a code is valid for. */
    public const PERIOD = 30;

    /** Digits in a code. Six is what every authenticator app assumes. */
    public const DIGITS = 6;

    /**
     * How many periods either side to accept.
     *
     * One period of clock drift is normal between a phone and a server. More
     * than one starts to make a stolen code useful for too long.
     */
    public const WINDOW = 1;

    /** A fresh secret: 160 bits, the size RFC 4226 recommends for SHA-1. */
    public static function generateSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    /**
     * The code for a given counter.
     *
     * HOTP is HMAC-SHA1 over the 8-byte big-endian counter, then a dynamic
     * truncation. TOTP is HOTP with the counter set to floor(time / period).
     */
    public static function code(string $secret, ?int $timestamp = null, int $digits = self::DIGITS): string
    {
        $key = self::base32Decode($secret);
        $counter = intdiv($timestamp ?? time(), self::PERIOD);

        $binary = pack('N*', 0).pack('N*', $counter);
        $hash = hash_hmac('sha1', $binary, $key, true);

        $offset = ord($hash[19]) & 0x0F;

        $value = (
            ((ord($hash[$offset]) & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8) |
            (ord($hash[$offset + 3]) & 0xFF)
        ) % (10 ** $digits);

        return str_pad((string) $value, $digits, '0', STR_PAD_LEFT);
    }

    /**
     * Does this code match, allowing for clock drift?
     *
     * Compared with hash_equals so the check cannot be timed, though the window
     * is small enough that timing is not the real risk here — a sloppy compare
     * is just a bad habit to keep out of the codebase.
     */
    public static function verify(string $secret, string $code, ?int $timestamp = null, int $window = self::WINDOW): bool
    {
        $code = preg_replace('/\D/', '', $code) ?? '';

        if (strlen($code) !== self::DIGITS) {
            return false;
        }

        $now = $timestamp ?? time();

        for ($offset = -$window; $offset <= $window; $offset++) {
            if (hash_equals(self::code($secret, $now + ($offset * self::PERIOD)), $code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The otpauth:// URI an authenticator app scans.
     *
     * The issuer appears twice on purpose: once as a label prefix and once as a
     * parameter, because different apps read different ones.
     */
    public static function provisioningUri(string $secret, string $accountLabel, string $issuer = 'Atlas'): string
    {
        $label = rawurlencode($issuer).':'.rawurlencode($accountLabel);

        $query = http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::PERIOD,
        ]);

        return 'otpauth://totp/'.$label.'?'.$query;
    }

    /**
     * A secret shown as five groups of four: XXXX XXXX XXXX XXXX
     *
     * Typed secrets are misread in groups, not as one long string.
     */
    public static function readableSecret(string $secret): string
    {
        return trim(chunk_split($secret, 4, ' '));
    }

    // -----------------------------------------------------------------------
    // base32 (RFC 4648), because every authenticator app speaks it
    // -----------------------------------------------------------------------

    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function base32Encode(string $bytes): string
    {
        $bits = '';

        foreach (str_split($bytes) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $out = '';

        foreach (str_split($bits, 5) as $chunk) {
            // The final chunk carries fewer than five bits; pad it right.
            $chunk = str_pad($chunk, 5, '0');
            $out .= self::ALPHABET[bindec($chunk)];
        }

        return $out;
    }

    public static function base32Decode(string $encoded): string
    {
        $encoded = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $encoded) ?? '');

        if ($encoded === '') {
            return '';
        }

        $bits = '';

        foreach (str_split($encoded) as $char) {
            $bits .= str_pad(decbin((int) strpos(self::ALPHABET, $char)), 5, '0', STR_PAD_LEFT);
        }

        $out = '';

        foreach (str_split($bits, 8) as $chunk) {
            // A trailing partial byte is padding, not data.
            if (strlen($chunk) === 8) {
                $out .= chr(bindec($chunk));
            }
        }

        return $out;
    }
}

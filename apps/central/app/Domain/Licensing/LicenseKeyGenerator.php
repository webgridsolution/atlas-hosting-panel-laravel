<?php

namespace App\Domain\Licensing;

/**
 * Generates human-transcribable licence keys.
 *
 * Format: ATLS-XXXX-XXXX-XXXX-XXXX  (4 groups × 4 chars, Crockford Base32)
 * 16 chars × 5 bits = 80 bits of entropy. Crockford excludes I, L, O, U to
 * remove transcription ambiguity (no 0/O or 1/I/L confusion).
 */
final class LicenseKeyGenerator
{
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    private const PREFIX = 'ATLS';

    public function generate(): string
    {
        $groups = [];

        for ($g = 0; $g < 4; $g++) {
            $group = '';
            for ($i = 0; $i < 4; $i++) {
                $group .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
            $groups[] = $group;
        }

        return self::PREFIX.'-'.implode('-', $groups);
    }

    /** Normalises user input before lookup: uppercase, strip spaces, restore dashes. */
    public function normalise(string $input): string
    {
        $clean = preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($input)));

        if (str_starts_with($clean, self::PREFIX)) {
            $clean = substr($clean, strlen(self::PREFIX));
        }

        $clean = preg_replace('/[IL]/', '1', $clean);
        $clean = preg_replace('/O/', '0', $clean);

        return self::PREFIX.'-'.implode('-', str_split(substr($clean, 0, 16), 4));
    }
}

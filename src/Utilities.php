<?php

declare(strict_types=1);

namespace GhostBot;

final class Utilities
{
    private const IBAN_LENGTHS = [
        'AD' => 24, 'AE' => 23, 'AL' => 28, 'AT' => 20, 'AZ' => 28, 'BA' => 20,
        'BE' => 16, 'BG' => 22, 'BH' => 22, 'BI' => 27, 'BR' => 29, 'CH' => 21, 'CR' => 22,
        'CY' => 28, 'CZ' => 24, 'DE' => 22, 'DK' => 18, 'DO' => 28, 'EE' => 20,
        'EG' => 29, 'ES' => 24, 'FI' => 18, 'FO' => 18, 'FR' => 27, 'GB' => 22,
        'GE' => 22, 'GI' => 23, 'GL' => 18, 'GR' => 27, 'GT' => 28, 'HR' => 21,
        'HN' => 28, 'HU' => 28, 'IE' => 22, 'IL' => 23, 'IQ' => 23, 'IS' => 26, 'IT' => 27,
        'JO' => 30, 'KW' => 30, 'KZ' => 20, 'LB' => 28, 'LC' => 32, 'LI' => 21,
        'LT' => 20, 'LU' => 20, 'LV' => 21, 'MC' => 27, 'MD' => 24, 'ME' => 22,
        'MK' => 19, 'MR' => 27, 'MT' => 31, 'MU' => 30, 'NL' => 18, 'NO' => 15, 'OM' => 23,
        'PK' => 24, 'PL' => 28, 'PS' => 29, 'PT' => 25, 'QA' => 29, 'RO' => 24,
        'RS' => 22, 'SA' => 24, 'SC' => 31, 'SE' => 24, 'SI' => 19, 'SK' => 24,
        'SM' => 27, 'ST' => 25, 'SV' => 28, 'TL' => 23, 'TN' => 24, 'TR' => 26,
        'UA' => 29, 'VA' => 22, 'VG' => 24, 'XK' => 20,
    ];

    public static function validateIban(string $input): array
    {
        $iban = strtoupper((string) preg_replace('/\s+/', '', $input));
        $country = substr($iban, 0, 2);
        if (!preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]+$/', $iban)) {
            return ['valid' => false, 'country' => $country, 'reason' => 'Invalid IBAN format'];
        }
        if (!isset(self::IBAN_LENGTHS[$country]) || strlen($iban) !== self::IBAN_LENGTHS[$country]) {
            return ['valid' => false, 'country' => $country, 'reason' => 'Invalid country or length'];
        }

        $rearranged = substr($iban, 4) . substr($iban, 0, 4);
        $remainder = 0;
        foreach (str_split($rearranged) as $character) {
            $digits = ctype_alpha($character) ? (string) (ord($character) - 55) : $character;
            foreach (str_split($digits) as $digit) {
                $remainder = (($remainder * 10) + (int) $digit) % 97;
            }
        }

        return [
            'valid' => $remainder === 1,
            'country' => $country,
            'reason' => $remainder === 1 ? 'Checksum valid' : 'Checksum invalid',
        ];
    }

    public static function normalizeBin(string $input): string
    {
        $bin = preg_replace('/\D/', '', $input) ?? '';
        if (strlen($bin) < 6 || strlen($bin) > 8) {
            throw new \InvalidArgumentException('BIN must contain 6 to 8 digits.');
        }
        return $bin;
    }
}

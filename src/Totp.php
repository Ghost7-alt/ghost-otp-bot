<?php

declare(strict_types=1);

namespace GhostBot;

final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(int $length = 32): string
    {
        $secret = '';
        for ($index = 0; $index < $length; $index++) {
            $secret .= self::ALPHABET[random_int(0, 31)];
        }
        return $secret;
    }

    public static function current(string $secret, ?int $timestamp = null): string
    {
        $counter = intdiv($timestamp ?? time(), 30);
        $binaryCounter = pack('N2', ($counter >> 32) & 0xffffffff, $counter & 0xffffffff);
        $hash = hash_hmac('sha1', $binaryCounter, self::decodeBase32($secret), true);
        $offset = ord($hash[19]) & 0x0f;
        $number = unpack('N', substr($hash, $offset, 4))[1] & 0x7fffffff;
        return str_pad((string) ($number % 1000000), 6, '0', STR_PAD_LEFT);
    }

    public static function verify(string $secret, string $token, int $window = 1, ?int $timestamp = null): bool
    {
        if (!preg_match('/^[0-9]{6}$/', $token)) {
            return false;
        }
        $timestamp ??= time();
        for ($offset = -$window; $offset <= $window; $offset++) {
            if (hash_equals(self::current($secret, $timestamp + ($offset * 30)), $token)) {
                return true;
            }
        }
        return false;
    }

    private static function decodeBase32(string $value): string
    {
        $value = strtoupper(rtrim($value, '='));
        $buffer = 0;
        $bits = 0;
        $output = '';
        foreach (str_split($value) as $character) {
            $position = strpos(self::ALPHABET, $character);
            if ($position === false) {
                throw new \InvalidArgumentException('Invalid Base32 secret.');
            }
            $buffer = ($buffer << 5) | $position;
            $bits += 5;
            if ($bits >= 8) {
                $bits -= 8;
                $output .= chr(($buffer >> $bits) & 0xff);
                $buffer &= (1 << $bits) - 1;
            }
        }
        return $output;
    }
}

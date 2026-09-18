<?php

declare(strict_types=1);

namespace GhostBot;

final class Crypto
{
    private string $key;

    public function __construct(string $encodedKey)
    {
        $key = base64_decode($encodedKey, true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \InvalidArgumentException('APP_KEY must be a base64-encoded 32-byte value.');
        }
        $this->key = $key;
    }

    public function encrypt(string $plainText): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce . sodium_crypto_secretbox($plainText, $nonce, $this->key));
    }

    public function decrypt(string $payload): string
    {
        $decoded = base64_decode($payload, true);
        if ($decoded === false || strlen($decoded) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('Encrypted value is malformed.');
        }

        $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plainText = sodium_crypto_secretbox_open(
            substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $nonce,
            $this->key
        );
        if ($plainText === false) {
            throw new \RuntimeException('Encrypted value could not be decrypted.');
        }

        return $plainText;
    }

    public static function mask(string $secret): string
    {
        return str_repeat('*', strlen($secret));
    }
}

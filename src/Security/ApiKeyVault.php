<?php

declare(strict_types=1);

namespace App\Security;

use RuntimeException;

final readonly class ApiKeyVault
{
    public function __construct(private string $secret)
    {
    }

    public function generatePlainKey(string $prefix): string
    {
        return $prefix.'.'.bin2hex(random_bytes(24));
    }

    public function hmac(string $plainKey): string
    {
        return hash_hmac('sha256', $plainKey, $this->secret);
    }

    public function encrypt(string $plainKey): string
    {
        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plainKey,
            'aes-256-gcm',
            $this->key(),
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
        );

        if (false === $ciphertext) {
            throw new RuntimeException('Unable to encrypt API key.');
        }

        return 'v1.'.base64_encode($nonce).'.'.base64_encode($tag).'.'.base64_encode($ciphertext);
    }

    public function decrypt(string $payload): ?string
    {
        $parts = explode('.', $payload);

        if (4 !== count($parts) || 'v1' !== $parts[0]) {
            return null;
        }

        $nonce = $this->decode($parts[1]);
        $tag = $this->decode($parts[2]);
        $ciphertext = $this->decode($parts[3]);

        if (null === $nonce || null === $tag || null === $ciphertext) {
            return null;
        }

        $plaintext = openssl_decrypt(
            $ciphertext,
            'aes-256-gcm',
            $this->key(),
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
        );

        return is_string($plaintext) ? $plaintext : null;
    }

    private function key(): string
    {
        return hash('sha256', $this->secret, true);
    }

    private function decode(string $value): ?string
    {
        $decoded = base64_decode($value, true);

        return is_string($decoded) ? $decoded : null;
    }
}

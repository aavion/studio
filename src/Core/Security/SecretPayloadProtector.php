<?php

declare(strict_types=1);

namespace App\Core\Security;

final readonly class SecretPayloadProtector
{
    private const CIPHER = 'aes-256-gcm';
    private const TAG_LENGTH = 16;
    private const VERSION = 'v1';

    public function __construct(private string $secret)
    {
        if ('' === $this->secret) {
            throw new \InvalidArgumentException('Secret payload root secret must not be empty.');
        }
    }

    public function protect(string $plaintext, string $context, string $associatedData = ''): string
    {
        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $this->key($context),
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            $associatedData,
            self::TAG_LENGTH,
        );

        if (false === $ciphertext) {
            throw new \RuntimeException('Secret payload could not be encrypted.');
        }

        return implode('.', [
            self::VERSION,
            base64_encode($nonce),
            base64_encode($tag),
            base64_encode($ciphertext),
        ]);
    }

    public function reveal(string $payload, string $context, string $associatedData = ''): string
    {
        $parts = explode('.', $payload);

        if (4 !== count($parts) || self::VERSION !== $parts[0]) {
            throw new \RuntimeException('Secret payload is invalid.');
        }

        $nonce = $this->decode($parts[1]);
        $tag = $this->decode($parts[2]);
        $ciphertext = $this->decode($parts[3]);

        if (null === $nonce || null === $tag || null === $ciphertext) {
            throw new \RuntimeException('Secret payload is invalid.');
        }

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $this->key($context),
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            $associatedData,
        );

        if (!is_string($plaintext)) {
            throw new \RuntimeException('Secret payload could not be decrypted.');
        }

        return $plaintext;
    }

    private function key(string $context): string
    {
        if ('' === $context) {
            throw new \InvalidArgumentException('Secret payload context must not be empty.');
        }

        $key = hash_hkdf('sha256', $this->secret, 32, 'studio.secret_payload.'.self::VERSION.'.'.$context);

        if (32 !== strlen($key)) {
            throw new \RuntimeException('Secret payload key could not be derived.');
        }

        return $key;
    }

    private function decode(string $value): ?string
    {
        $decoded = base64_decode($value, true);

        return is_string($decoded) ? $decoded : null;
    }
}

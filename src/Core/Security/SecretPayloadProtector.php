<?php

declare(strict_types=1);

namespace App\Core\Security;

use App\Core\Message\MessageCode;
use App\Core\Message\MessageException;
use App\Core\Message\MessageKey;

final readonly class SecretPayloadProtector
{
    private const CIPHER = 'aes-256-gcm';
    private const TAG_LENGTH = 16;
    private const VERSION = 'v1';

    public function __construct(private string $secret)
    {
        if ('' === $this->secret) {
            throw MessageException::forMessage(
                MessageCode::SYSTEM_SECRET_PAYLOAD_ROOT_SECRET_EMPTY,
                MessageKey::SYSTEM_SECRET_PAYLOAD_ROOT_SECRET_EMPTY,
            );
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
            throw MessageException::forMessage(
                MessageCode::SYSTEM_SECRET_PAYLOAD_ENCRYPT_FAILED,
                MessageKey::SYSTEM_SECRET_PAYLOAD_ENCRYPT_FAILED,
            );
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
            throw MessageException::forMessage(
                MessageCode::SYSTEM_SECRET_PAYLOAD_INVALID,
                MessageKey::SYSTEM_SECRET_PAYLOAD_INVALID,
            );
        }

        $nonce = $this->decode($parts[1]);
        $tag = $this->decode($parts[2]);
        $ciphertext = $this->decode($parts[3]);

        if (null === $nonce || null === $tag || null === $ciphertext) {
            throw MessageException::forMessage(
                MessageCode::SYSTEM_SECRET_PAYLOAD_INVALID,
                MessageKey::SYSTEM_SECRET_PAYLOAD_INVALID,
            );
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
            throw MessageException::forMessage(
                MessageCode::SYSTEM_SECRET_PAYLOAD_DECRYPT_FAILED,
                MessageKey::SYSTEM_SECRET_PAYLOAD_DECRYPT_FAILED,
            );
        }

        return $plaintext;
    }

    public function hmac(string $value, string $context): string
    {
        return hash_hmac('sha256', $value, $this->key($context));
    }

    private function key(string $context): string
    {
        if ('' === $context) {
            throw MessageException::forMessage(
                MessageCode::SYSTEM_SECRET_PAYLOAD_CONTEXT_EMPTY,
                MessageKey::SYSTEM_SECRET_PAYLOAD_CONTEXT_EMPTY,
            );
        }

        $key = hash_hkdf('sha256', $this->secret, 32, 'system.secret_payload.'.self::VERSION.'.'.$context);

        if (32 !== strlen($key)) {
            throw MessageException::forMessage(
                MessageCode::SYSTEM_SECRET_PAYLOAD_KEY_DERIVATION_FAILED,
                MessageKey::SYSTEM_SECRET_PAYLOAD_KEY_DERIVATION_FAILED,
            );
        }

        return $key;
    }

    private function decode(string $value): ?string
    {
        $decoded = base64_decode($value, true);

        return is_string($decoded) ? $decoded : null;
    }
}

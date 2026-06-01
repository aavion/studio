<?php

declare(strict_types=1);

namespace App\Setup;

final readonly class SetupLiveOperationPayloadProtector
{
    private const VERSION = 'v1';
    public const MARKER = '_setup_payload_protected';
    public const SECRETS = '_setup_payload_secrets';
    private const SECRET_FIELDS = [
        'admin_password',
        'admin_password_confirm',
        'database_password',
        'database_url',
        'app_secret',
    ];

    public function __construct(private string $secret)
    {
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function protect(array $payload): array
    {
        if (true === ($payload[self::MARKER] ?? false)) {
            return $payload;
        }

        $values = is_array($payload['values'] ?? null) ? $payload['values'] : [];
        $secrets = [];

        foreach (self::SECRET_FIELDS as $field) {
            $value = $values[$field] ?? null;

            if (!is_string($value) || '' === $value) {
                continue;
            }

            $secrets[$field] = $this->encrypt($value);
            $values[$field] = '[protected]';
        }

        if ([] === $secrets) {
            return $payload;
        }

        return [
            ...$payload,
            'values' => $values,
            self::MARKER => true,
            self::SECRETS => $secrets,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function unprotect(array $payload): array
    {
        if (true !== ($payload[self::MARKER] ?? false)) {
            return $payload;
        }

        $values = is_array($payload['values'] ?? null) ? $payload['values'] : [];
        $secrets = is_array($payload[self::SECRETS] ?? null) ? $payload[self::SECRETS] : [];

        foreach ($secrets as $field => $encrypted) {
            if (!is_string($field) || !in_array($field, self::SECRET_FIELDS, true) || !is_string($encrypted)) {
                continue;
            }

            $values[$field] = $this->decrypt($encrypted);
        }

        unset($payload[self::MARKER], $payload[self::SECRETS]);
        $payload['values'] = $values;

        return $payload;
    }

    private function encrypt(string $value): string
    {
        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $value,
            'aes-256-gcm',
            $this->key(),
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
        );

        if (false === $ciphertext) {
            throw new \RuntimeException('Setup live-operation payload could not be encrypted.');
        }

        return implode('.', [
            self::VERSION,
            base64_encode($nonce),
            base64_encode($tag),
            base64_encode($ciphertext),
        ]);
    }

    private function decrypt(string $payload): string
    {
        $parts = explode('.', $payload);

        if (4 !== count($parts) || self::VERSION !== $parts[0]) {
            throw new \RuntimeException('Setup live-operation payload secret is invalid.');
        }

        $nonce = $this->decode($parts[1]);
        $tag = $this->decode($parts[2]);
        $ciphertext = $this->decode($parts[3]);

        if (null === $nonce || null === $tag || null === $ciphertext) {
            throw new \RuntimeException('Setup live-operation payload secret is invalid.');
        }

        $plaintext = openssl_decrypt(
            $ciphertext,
            'aes-256-gcm',
            $this->key(),
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
        );

        if (!is_string($plaintext)) {
            throw new \RuntimeException('Setup live-operation payload could not be decrypted.');
        }

        return $plaintext;
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

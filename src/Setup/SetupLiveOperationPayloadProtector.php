<?php

declare(strict_types=1);

namespace App\Setup;

use App\Core\Security\SecretPayloadProtector;

final readonly class SetupLiveOperationPayloadProtector
{
    private const CONTEXT = 'setup.live_operation_payload';
    public const MARKER = '_setup_payload_protected';
    public const SECRETS = '_setup_payload_secrets';
    private const SECRET_FIELDS = [
        'admin_password',
        'admin_password_confirm',
        'database_password',
        'database_url',
        'app_secret',
    ];

    public function __construct(private SecretPayloadProtector $protector)
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

            $secrets[$field] = $this->protector->protect($value, self::CONTEXT, $field);
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

            $values[$field] = $this->protector->reveal($encrypted, self::CONTEXT, $field);
        }

        unset($payload[self::MARKER], $payload[self::SECRETS]);
        $payload['values'] = $values;

        return $payload;
    }
}

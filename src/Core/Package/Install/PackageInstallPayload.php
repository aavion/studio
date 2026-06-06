<?php

declare(strict_types=1);

namespace App\Core\Package\Install;

use App\Core\Message\Message;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use App\Core\Workflow\WorkflowResult;

final readonly class PackageInstallPayload
{
    /**
     * @param array<string, mixed> $payload
     */
    public function string(array $payload, string $key): ?string
    {
        $value = $payload[$key] ?? null;

        return is_string($value) && '' !== trim($value) ? trim($value) : null;
    }

    /**
     * @param list<string|int> $payloadKeys
     *
     * @return WorkflowResult<array<string, mixed>>
     */
    public function invalid(string $stage, array $payloadKeys): WorkflowResult
    {
        return WorkflowResult::invalid([
            Message::warning(
                MessageCode::E_INVALID_ARGUMENT,
                MessageKey::OPERATION_INVALID_PAYLOAD,
                ['%operation%' => 'package.install.'.$stage],
                ['operation' => 'package.install.'.$stage, 'payload_keys' => array_values($payloadKeys)],
            ),
        ], ['operation' => 'package.install.'.$stage, 'payload_keys' => array_values($payloadKeys)]);
    }
}

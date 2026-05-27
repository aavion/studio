<?php

declare(strict_types=1);

namespace App\Core\Manifest;

use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use App\Core\Message\Message;
use App\Core\Message\MessageLevel;
use App\Core\Workflow\WorkflowResult;

final class ManifestValidator
{
    /**
     * @return WorkflowResult<Manifest>
     */
    public function validate(Manifest $manifest, ManifestSpec $spec): WorkflowResult
    {
        $issues = [];

        foreach ($spec->requiredKeys() as $requiredKey) {
            if (!$manifest->has($requiredKey) || '' === trim((string) $manifest->get($requiredKey))) {
                $issues[] = Message::create(
                    MessageCode::MANIFEST_MISSING_REQUIRED_KEY,
                    MessageKey::MANIFEST_MISSING_REQUIRED_KEY,
                    ['%key%' => $requiredKey],
                    context: ['key' => $requiredKey],
                    level: MessageLevel::Error,
                );
            }
        }

        $allowedKeys = $spec->allowedKeys();
        if (null !== $allowedKeys) {
            foreach ($manifest->keys() as $key) {
                if (!in_array($key, $allowedKeys, true)) {
                    $issues[] = Message::create(
                        MessageCode::MANIFEST_UNKNOWN_KEY,
                        MessageKey::MANIFEST_UNKNOWN_KEY,
                        ['%key%' => $key],
                        context: ['key' => $key],
                        level: MessageLevel::Error,
                    );
                }
            }
        }

        if ([] !== $issues) {
            return WorkflowResult::invalid($issues);
        }

        return WorkflowResult::success($manifest, [
            'required_keys' => $spec->requiredKeys(),
            'allowed_keys' => $spec->allowedKeys(),
        ], [
            Message::debug(MessageCode::MANIFEST_VALIDATED, MessageKey::MANIFEST_VALIDATED, context: [
                'required_keys' => $spec->requiredKeys(),
                'allowed_keys' => $spec->allowedKeys(),
            ]),
        ]);
    }
}

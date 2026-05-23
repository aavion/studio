<?php

declare(strict_types=1);

namespace App\Core\Manifest;

use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use App\Core\Workflow\OperationIssue;
use App\Core\Workflow\OperationResult;

final class ManifestValidator
{
    /**
     * @return OperationResult<Manifest>
     */
    public function validate(Manifest $manifest, ManifestSpec $spec): OperationResult
    {
        $issues = [];

        foreach ($spec->requiredKeys() as $requiredKey) {
            if (!$manifest->has($requiredKey) || '' === trim((string) $manifest->get($requiredKey))) {
                $issues[] = OperationIssue::create(
                    MessageCode::MANIFEST_MISSING_REQUIRED_KEY,
                    MessageKey::MANIFEST_MISSING_REQUIRED_KEY,
                    context: ['key' => $requiredKey],
                );
            }
        }

        $allowedKeys = $spec->allowedKeys();
        if (null !== $allowedKeys) {
            foreach ($manifest->keys() as $key) {
                if (!in_array($key, $allowedKeys, true)) {
                    $issues[] = OperationIssue::create(
                        MessageCode::MANIFEST_UNKNOWN_KEY,
                        MessageKey::MANIFEST_UNKNOWN_KEY,
                        context: ['key' => $key],
                    );
                }
            }
        }

        if ([] !== $issues) {
            return OperationResult::invalid($issues);
        }

        return OperationResult::success($manifest);
    }
}

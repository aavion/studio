<?php

declare(strict_types=1);

namespace App\Core\Manifest;

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
                    'manifest.missing_required_key',
                    'Required manifest key is missing.',
                    ['key' => $requiredKey],
                );
            }
        }

        $allowedKeys = $spec->allowedKeys();
        if (null !== $allowedKeys) {
            foreach ($manifest->keys() as $key) {
                if (!in_array($key, $allowedKeys, true)) {
                    $issues[] = OperationIssue::create(
                        'manifest.unknown_key',
                        'Manifest key is not allowed by this specification.',
                        ['key' => $key],
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

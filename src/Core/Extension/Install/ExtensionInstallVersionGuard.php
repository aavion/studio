<?php

declare(strict_types=1);

namespace App\Core\Extension\Install;

use App\Core\Manifest\Manifest;
use App\Core\Message\Message;
use App\Core\Extension\ExtensionStatus;
use App\Core\Extension\ExtensionMessageCode;
use App\Core\Extension\ExtensionMessageKey;
use App\Core\Workflow\WorkflowResult;
use App\Entity\Extension;

final readonly class ExtensionInstallVersionGuard
{
    /**
     * @return WorkflowResult<array<string, mixed>>|null
     */
    public function ensureAllowed(
        Manifest $manifest,
        ?Extension $existing,
        string $installId,
        string $slug,
    ): ?WorkflowResult {
        if (!$existing instanceof Extension) {
            return null;
        }

        $version = trim((string) $manifest->get('EXTENSION_VERSION', ''));
        $installedVersion = $this->installedVersion($existing);

        if (null === $installedVersion || '' === $version || version_compare($version, $installedVersion, '>')) {
            return null;
        }

        if (
            0 === version_compare($version, $installedVersion)
            && in_array($existing->status(), [ExtensionStatus::Faulty, ExtensionStatus::Removed], true)
        ) {
            return null;
        }

        return WorkflowResult::blocked([
            Message::warning(
                ExtensionMessageCode::EXTENSION_INSTALL_VERSION_BLOCKED,
                ExtensionMessageKey::EXTENSION_INSTALL_VERSION_BLOCKED,
                [
                    '%extension%' => $slug,
                    '%version%' => '' !== $version ? $version : 'unknown',
                    '%installed_version%' => $installedVersion,
                ],
                [
                    'install_id' => $installId,
                    'extension' => $slug,
                    'version' => '' !== $version ? $version : null,
                    'installed_version' => $installedVersion,
                    'status' => $existing->status()->value,
                ],
            ),
        ], [
            'install_id' => $installId,
            'extension' => $slug,
            'version' => '' !== $version ? $version : null,
            'installed_version' => $installedVersion,
            'status' => $existing->status()->value,
        ]);
    }

    private function installedVersion(Extension $extension): ?string
    {
        $version = $extension->installedVersion() ?? $extension->manifestVersion();

        return is_string($version) && '' !== trim($version) ? trim($version) : null;
    }
}

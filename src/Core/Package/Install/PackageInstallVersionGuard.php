<?php

declare(strict_types=1);

namespace App\Core\Package\Install;

use App\Core\Manifest\Manifest;
use App\Core\Message\Message;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use App\Core\Package\ExtensionPackageStatus;
use App\Core\Workflow\WorkflowResult;
use App\Entity\ExtensionPackage;

final readonly class PackageInstallVersionGuard
{
    /**
     * @return WorkflowResult<array<string, mixed>>|null
     */
    public function ensureAllowed(
        Manifest $manifest,
        ?ExtensionPackage $existing,
        string $installId,
        string $slug,
    ): ?WorkflowResult {
        if (!$existing instanceof ExtensionPackage) {
            return null;
        }

        $version = trim((string) $manifest->get('PACKAGE_VERSION', ''));
        $installedVersion = $this->installedVersion($existing);

        if (null === $installedVersion || '' === $version || version_compare($version, $installedVersion, '>')) {
            return null;
        }

        if (
            0 === version_compare($version, $installedVersion)
            && in_array($existing->status(), [ExtensionPackageStatus::Faulty, ExtensionPackageStatus::Removed], true)
        ) {
            return null;
        }

        return WorkflowResult::blocked([
            Message::warning(
                MessageCode::PACKAGE_INSTALL_VERSION_BLOCKED,
                MessageKey::PACKAGE_INSTALL_VERSION_BLOCKED,
                [
                    '%package%' => $slug,
                    '%version%' => '' !== $version ? $version : 'unknown',
                    '%installed_version%' => $installedVersion,
                ],
                [
                    'install_id' => $installId,
                    'package' => $slug,
                    'version' => '' !== $version ? $version : null,
                    'installed_version' => $installedVersion,
                    'status' => $existing->status()->value,
                ],
            ),
        ], [
            'install_id' => $installId,
            'package' => $slug,
            'version' => '' !== $version ? $version : null,
            'installed_version' => $installedVersion,
            'status' => $existing->status()->value,
        ]);
    }

    private function installedVersion(ExtensionPackage $package): ?string
    {
        $version = $package->installedVersion() ?? $package->manifestVersion();

        return is_string($version) && '' !== trim($version) ? trim($version) : null;
    }
}

<?php

declare(strict_types=1);

namespace App\Core\Package\Install;

use App\Core\Manifest\Manifest;
use App\Core\Manifest\ManifestParser;
use App\Core\Manifest\ManifestValidator;
use App\Core\Message\Message;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use App\Core\Message\MessageLevel;
use App\Core\Operation\Live\LiveOperationQueueFactory;
use App\Core\Package\ExtensionPackageStatus;
use App\Core\Package\PackageActivator;
use App\Core\Package\PackageCandidate;
use App\Core\Package\PackageDiscoveryRunner;
use App\Core\Package\PackageManifestSpec;
use App\Core\Package\PackageRemover;
use App\Core\Package\PackageScope;
use App\Core\Package\PackageSource;
use App\Core\Package\PackageSpec;
use App\Core\Package\PackageValidator;
use App\Core\Workflow\WorkflowResult;
use App\Entity\ExtensionPackage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Throwable;
use ZipArchive;

final readonly class PackageZipInstaller
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PackageDiscoveryRunner $discoveryRunner,
        private PackageActivator $activator,
        private PackageRemover $remover,
        private string $projectDir,
        private string $environment,
        private ManifestParser $manifestParser = new ManifestParser(),
        private ManifestValidator $manifestValidator = new ManifestValidator(),
        private PackageValidator $packageValidator = new PackageValidator(),
    ) {
    }

    /**
     * @return WorkflowResult<array{install_id: string, zip_path: string}>
     */
    public function stageUpload(?UploadedFile $file): WorkflowResult
    {
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            return WorkflowResult::invalid([
                Message::warning(
                    MessageCode::PACKAGE_INSTALL_UPLOAD_INVALID,
                    MessageKey::PACKAGE_INSTALL_UPLOAD_INVALID,
                    context: ['reason' => 'missing_or_invalid_upload'],
                ),
            ]);
        }

        $extension = strtolower((string) $file->getClientOriginalExtension());
        if ('zip' !== $extension) {
            return WorkflowResult::invalid([
                Message::warning(
                    MessageCode::PACKAGE_INSTALL_UPLOAD_INVALID,
                    MessageKey::PACKAGE_INSTALL_UPLOAD_INVALID,
                    context: ['reason' => 'unsupported_extension', 'extension' => $extension],
                ),
            ]);
        }

        $installId = bin2hex(random_bytes(12));
        $root = $this->installRoot($installId);
        $zipPath = $root.DIRECTORY_SEPARATOR.'upload.zip';

        try {
            $this->ensureDirectory($root);
            $file->move($root, 'upload.zip');
        } catch (Throwable $error) {
            return WorkflowResult::failed([
                Message::exception(
                    MessageCode::PACKAGE_INSTALL_UPLOAD_INVALID,
                    MessageKey::PACKAGE_INSTALL_UPLOAD_INVALID,
                    context: [
                        'install_id' => $installId,
                        'exception' => $error::class,
                        'message' => $error->getMessage(),
                    ],
                ),
            ]);
        }

        return WorkflowResult::success([
            'install_id' => $installId,
            'zip_path' => $this->relativePath($zipPath),
        ], [
            'install_id' => $installId,
            'zip_path' => $this->relativePath($zipPath),
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return WorkflowResult<array<string, mixed>>
     */
    public function verify(array $payload): WorkflowResult
    {
        $installId = $this->payloadString($payload, 'install_id');
        if (null === $installId || 1 !== preg_match('/^[a-f0-9]{24}$/', $installId)) {
            return $this->invalidPayload('verify', array_keys($payload));
        }

        $root = $this->installRoot($installId);
        $zipPath = $root.DIRECTORY_SEPARATOR.'upload.zip';
        $stagePath = $root.DIRECTORY_SEPARATOR.'staged';

        if (!is_file($zipPath)) {
            return $this->invalidPayload('verify', ['install_id', 'upload_missing']);
        }

        $extract = $this->extractZip($zipPath, $stagePath);
        if (!$extract->isSuccess()) {
            return $extract;
        }

        $packageRoot = $this->packageRoot($stagePath);
        if (null === $packageRoot) {
            return WorkflowResult::invalid([
                Message::error(
                    MessageCode::PACKAGE_INSTALL_ROOT_INVALID,
                    MessageKey::PACKAGE_INSTALL_ROOT_INVALID,
                    context: ['install_id' => $installId, 'stage_path' => $this->relativePath($stagePath)],
                ),
            ]);
        }

        $manifest = $this->readManifest($packageRoot);
        if (!$manifest->isSuccess()) {
            return $manifest;
        }

        $manifestValue = $manifest->value();
        $validation = $this->manifestValidator->validate($manifestValue, PackageManifestSpec::create());
        if (!$validation->isSuccess()) {
            return WorkflowResult::invalid($validation->issues(), ['install_id' => $installId], $validation->messages());
        }

        $slug = trim((string) $manifestValue->get('PACKAGE_SLUG', ''));
        $name = trim((string) $manifestValue->get('PACKAGE_NAME', $slug));
        $version = trim((string) $manifestValue->get('PACKAGE_VERSION', ''));
        $scope = trim((string) $manifestValue->get('PACKAGE_SCOPE', ''));

        if ('system' === $slug || !PackageManifestSpec::isValidSlug($slug)) {
            return WorkflowResult::invalid([
                Message::warning(
                    MessageCode::PACKAGE_IDENTIFIER_INVALID,
                    MessageKey::PACKAGE_IDENTIFIER_INVALID,
                    ['%identifier%' => $slug],
                    ['install_id' => $installId, 'slug' => $slug],
                ),
            ]);
        }

        try {
            PackageScope::fromManifestValue($scope);
        } catch (\InvalidArgumentException) {
            return WorkflowResult::invalid([
                Message::error(
                    MessageCode::PACKAGE_SCOPE_INVALID,
                    MessageKey::PACKAGE_SCOPE_INVALID,
                    ['%scope%' => $scope],
                    ['install_id' => $installId, 'slug' => $slug, 'scope' => $scope],
                ),
            ]);
        }

        $candidate = new PackageCandidate(
            PackageSource::single('package', '.', PackageManifestSpec::create()),
            $packageRoot,
            $packageRoot.DIRECTORY_SEPARATOR.'.manifest',
            $manifestValue,
        );
        $packageValidation = $this->packageValidator->validate($candidate, PackageSpec::create()->withInventoryDepth(4)->withLintingChecks());
        if (!$packageValidation->isSuccess()) {
            return WorkflowResult::invalid($packageValidation->issues(), [
                'install_id' => $installId,
                'slug' => $slug,
            ], $packageValidation->messages());
        }

        $existing = $this->package($slug);
        $wasActive = $existing instanceof ExtensionPackage && ExtensionPackageStatus::Active === $existing->status();
        $issues = [
            Message::info(
                MessageCode::PACKAGE_INSTALL_READY,
                MessageKey::PACKAGE_INSTALL_READY,
                ['%package%' => $name, '%version%' => $version],
                ['install_id' => $installId, 'package' => $slug, 'version' => $version],
            ),
        ];

        if ($existing instanceof ExtensionPackage) {
            $issues[] = Message::warning(
                MessageCode::PACKAGE_INSTALL_OVERWRITE,
                MessageKey::PACKAGE_INSTALL_OVERWRITE,
                ['%package%' => $slug],
                ['install_id' => $installId, 'package' => $slug, 'was_active' => $wasActive],
            );
        }

        return WorkflowResult::requiresReview([
            'install_id' => $installId,
            'package' => $slug,
            'name' => $name,
            'version' => $version,
            'was_active' => $wasActive,
        ], $issues, [
            'install_id' => $installId,
            'package' => $slug,
            'name' => $name,
            'version' => $version,
            'was_active' => $wasActive,
            'live_operation_continuation' => [
                'operation' => LiveOperationQueueFactory::PACKAGE_INSTALL_APPLY,
                'label' => sprintf('Install package %s', $slug),
                'payload' => [
                    'install_id' => $installId,
                    'package' => $slug,
                    'was_active' => $wasActive,
                    'trigger' => 'admin_ui',
                    'environment' => $this->environment,
                ],
            ],
        ], [
            ...$validation->messages(),
            ...$packageValidation->messages(),
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return WorkflowResult<array<string, mixed>>
     */
    public function apply(array $payload): WorkflowResult
    {
        $installId = $this->payloadString($payload, 'install_id');
        $slug = $this->payloadString($payload, 'package');
        $wasActive = true === ($payload['was_active'] ?? false);

        if (null === $installId || null === $slug || !PackageManifestSpec::isValidSlug($slug)) {
            return $this->invalidPayload('apply', array_keys($payload));
        }

        $root = $this->installRoot($installId);
        $stagePath = $root.DIRECTORY_SEPARATOR.'staged';
        $packageRoot = $this->packageRoot($stagePath);

        if (null === $packageRoot) {
            return WorkflowResult::invalid([
                Message::error(
                    MessageCode::PACKAGE_INSTALL_ROOT_INVALID,
                    MessageKey::PACKAGE_INSTALL_ROOT_INVALID,
                    context: ['install_id' => $installId, 'package' => $slug],
                ),
            ]);
        }

        $messages = [];
        $existing = $this->package($slug);

        if ($existing instanceof ExtensionPackage) {
            $remove = $this->remover->remove($slug, $this->environment, rebuildAssets: false);
            $messages = [...$messages, ...$remove->messages()];

            if (!$remove->isSuccess()) {
                return WorkflowResult::failed($remove->issues(), [
                    'install_id' => $installId,
                    'package' => $slug,
                    'remove_context' => $remove->context(),
                ], $messages);
            }
        }

        $target = $this->projectDir.DIRECTORY_SEPARATOR.'packages'.DIRECTORY_SEPARATOR.$slug;

        try {
            $this->removePath($target);
            $this->ensureDirectory(dirname($target));
            $this->copyDirectory($packageRoot, $target);
        } catch (Throwable $error) {
            return WorkflowResult::failed([
                Message::exception(
                    MessageCode::OPERATION_EXCEPTION,
                    MessageKey::OPERATION_EXCEPTION,
                    context: [
                        'install_id' => $installId,
                        'package' => $slug,
                        'exception' => $error::class,
                        'message' => $error->getMessage(),
                    ],
                ),
            ], ['install_id' => $installId, 'package' => $slug], $messages);
        }

        $discovery = ($this->discoveryRunner)('package_install');
        $messages = [...$messages, ...$discovery->messages()];

        if (!$discovery->isSuccess()) {
            return WorkflowResult::failed($discovery->issues(), [
                'install_id' => $installId,
                'package' => $slug,
                'discovery_context' => $discovery->context(),
            ], $messages);
        }

        if ($wasActive) {
            $activation = $this->activator->activate($slug, $this->environment);
            $messages = [...$messages, ...$activation->messages()];

            if (!$activation->isSuccess()) {
                return WorkflowResult::failed($activation->issues(), [
                    'install_id' => $installId,
                    'package' => $slug,
                    'activation_context' => $activation->context(),
                ], $messages);
            }
        }

        $this->removePath($root);

        return WorkflowResult::success([
            'package' => $slug,
            'was_active' => $wasActive,
        ], [
            'package' => $slug,
            'was_active' => $wasActive,
        ], [
            ...$messages,
            Message::success(
                MessageKey::PACKAGE_INSTALL_COMPLETED,
                ['%package%' => $slug],
                ['package' => $slug, 'was_active' => $wasActive],
            ),
        ]);
    }

    private function package(string $packageName): ?ExtensionPackage
    {
        $package = $this->entityManager->getRepository(ExtensionPackage::class)->findOneBy([
            'packageName' => $packageName,
        ]);

        return $package instanceof ExtensionPackage ? $package : null;
    }

    /**
     * @return WorkflowResult<array<string, mixed>>
     */
    private function extractZip(string $zipPath, string $stagePath): WorkflowResult
    {
        if (!class_exists(ZipArchive::class)) {
            return WorkflowResult::failed([
                Message::error(
                    MessageCode::PACKAGE_INSTALL_ZIP_INVALID,
                    MessageKey::PACKAGE_INSTALL_ZIP_INVALID,
                    context: ['reason' => 'zip_extension_missing'],
                ),
            ]);
        }

        $zip = new ZipArchive();
        $opened = $zip->open($zipPath);

        if (true !== $opened) {
            return WorkflowResult::invalid([
                Message::warning(
                    MessageCode::PACKAGE_INSTALL_ZIP_INVALID,
                    MessageKey::PACKAGE_INSTALL_ZIP_INVALID,
                    context: ['reason' => 'open_failed', 'zip_error' => $opened],
                ),
            ]);
        }

        try {
            for ($index = 0; $index < $zip->numFiles; ++$index) {
                $name = $zip->getNameIndex($index);
                if (!is_string($name) || $this->unsafeZipEntry($name)) {
                    return WorkflowResult::invalid([
                        Message::warning(
                            MessageCode::PACKAGE_INSTALL_ZIP_INVALID,
                            MessageKey::PACKAGE_INSTALL_ZIP_INVALID,
                            context: ['reason' => 'unsafe_entry', 'entry' => $name],
                        ),
                    ]);
                }
            }

            $this->removePath($stagePath);
            $this->ensureDirectory($stagePath);

            if (!$zip->extractTo($stagePath)) {
                return WorkflowResult::failed([
                    Message::error(
                        MessageCode::PACKAGE_INSTALL_ZIP_INVALID,
                        MessageKey::PACKAGE_INSTALL_ZIP_INVALID,
                        context: ['reason' => 'extract_failed', 'zip_path' => $this->relativePath($zipPath)],
                    ),
                ]);
            }
        } finally {
            $zip->close();
        }

        return WorkflowResult::success([
            'stage_path' => $this->relativePath($stagePath),
        ]);
    }

    /**
     * @return WorkflowResult<Manifest>
     */
    private function readManifest(string $packageRoot): WorkflowResult
    {
        $path = $packageRoot.DIRECTORY_SEPARATOR.'.manifest';
        $contents = is_file($path) ? file_get_contents($path) : false;

        if (!is_string($contents)) {
            return WorkflowResult::invalid([
                Message::error(
                    MessageCode::PACKAGE_MANIFEST_UNREADABLE,
                    MessageKey::PACKAGE_MANIFEST_UNREADABLE,
                    ['%path%' => $this->relativePath($path)],
                    ['path' => $this->relativePath($path)],
                ),
            ]);
        }

        return $this->manifestParser->parse($contents);
    }

    private function packageRoot(string $stagePath): ?string
    {
        if (is_file($stagePath.DIRECTORY_SEPARATOR.'.manifest')) {
            return $stagePath;
        }

        $children = array_values(array_filter(scandir($stagePath) ?: [], static fn (string $entry): bool => !in_array($entry, ['.', '..', '__MACOSX'], true)));

        if (1 !== count($children)) {
            return null;
        }

        $child = $stagePath.DIRECTORY_SEPARATOR.$children[0];

        return is_dir($child) && is_file($child.DIRECTORY_SEPARATOR.'.manifest') ? $child : null;
    }

    private function installRoot(string $installId): string
    {
        return $this->projectDir.DIRECTORY_SEPARATOR.'var'.DIRECTORY_SEPARATOR.'cache'.DIRECTORY_SEPARATOR.$this->environment.DIRECTORY_SEPARATOR.'package-installs'.DIRECTORY_SEPARATOR.$installId;
    }

    private function ensureDirectory(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
            throw new \RuntimeException(sprintf('Directory "%s" could not be created.', $path));
        }
    }

    private function copyDirectory(string $source, string $target): void
    {
        $this->ensureDirectory($target);
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            if (!$item instanceof \SplFileInfo) {
                continue;
            }

            $relative = substr($item->getPathname(), strlen($source) + 1);
            $destination = $target.DIRECTORY_SEPARATOR.$relative;

            if ($item->isDir()) {
                $this->ensureDirectory($destination);
                continue;
            }

            $this->ensureDirectory(dirname($destination));
            if (!copy($item->getPathname(), $destination)) {
                throw new \RuntimeException(sprintf('File "%s" could not be copied.', $relative));
            }
        }
    }

    private function removePath(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }

        if (is_file($path) || is_link($path)) {
            unlink($path);

            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if (!$item instanceof \SplFileInfo) {
                continue;
            }

            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($path);
    }

    private function unsafeZipEntry(string $entry): bool
    {
        $normalized = str_replace('\\', '/', $entry);

        return str_starts_with($normalized, '/')
            || str_contains($normalized, '../')
            || str_starts_with($normalized, '../')
            || str_contains($normalized, "\0");
    }

    /**
     * @param list<string|int> $payloadKeys
     *
     * @return WorkflowResult<array<string, mixed>>
     */
    private function invalidPayload(string $stage, array $payloadKeys): WorkflowResult
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

    /**
     * @param array<string, mixed> $payload
     */
    private function payloadString(array $payload, string $key): ?string
    {
        $value = $payload[$key] ?? null;

        return is_string($value) && '' !== trim($value) ? trim($value) : null;
    }

    private function relativePath(string $path): string
    {
        $projectDir = rtrim(str_replace('\\', '/', $this->projectDir), '/');
        $path = str_replace('\\', '/', $path);

        return str_starts_with($path, $projectDir.'/') ? substr($path, strlen($projectDir) + 1) : $path;
    }
}

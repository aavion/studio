<?php

declare(strict_types=1);

namespace App\Core\Package\Install;

use App\Core\Message\Message;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use App\Core\Workflow\WorkflowResult;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Throwable;

final readonly class PackageUploadStager
{
    public function __construct(
        private PackageInstallFilesystem $filesystem,
        private string $environment,
    ) {
    }

    /**
     * @return WorkflowResult<array{install_id: string, zip_path: string}>
     */
    public function stage(?UploadedFile $file): WorkflowResult
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
        $root = $this->filesystem->installRoot($this->environment, $installId);
        $zipPath = $root.DIRECTORY_SEPARATOR.'upload.zip';

        try {
            $this->filesystem->ensureDirectory($root);
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
            'zip_path' => $this->filesystem->relativePath($zipPath),
        ], [
            'install_id' => $installId,
            'zip_path' => $this->filesystem->relativePath($zipPath),
        ]);
    }
}

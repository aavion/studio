<?php

declare(strict_types=1);

namespace App\Core\Extension\Install;

use App\Core\Message\Message;
use App\Core\Extension\ExtensionMessageCode;
use App\Core\Extension\ExtensionMessageKey;
use App\Core\Workflow\WorkflowResult;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Throwable;

final readonly class ExtensionUploadStager
{
    public function __construct(
        private ExtensionInstallFilesystem $filesystem,
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
                    ExtensionMessageCode::EXTENSION_INSTALL_UPLOAD_INVALID,
                    ExtensionMessageKey::EXTENSION_INSTALL_UPLOAD_INVALID,
                    context: ['reason' => 'missing_or_invalid_upload'],
                ),
            ]);
        }

        $extension = strtolower((string) $file->getClientOriginalExtension());
        if ('zip' !== $extension) {
            return WorkflowResult::invalid([
                Message::warning(
                    ExtensionMessageCode::EXTENSION_INSTALL_UPLOAD_INVALID,
                    ExtensionMessageKey::EXTENSION_INSTALL_UPLOAD_INVALID,
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
                    ExtensionMessageCode::EXTENSION_INSTALL_UPLOAD_INVALID,
                    ExtensionMessageKey::EXTENSION_INSTALL_UPLOAD_INVALID,
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

<?php

declare(strict_types=1);

namespace App\Core\Package\Install;

use App\Core\Workflow\WorkflowResult;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final readonly class PackageZipInstaller
{
    public function __construct(
        private PackageUploadStager $uploadStager,
        private PackageInstallVerifier $installVerifier,
        private PackageInstallApplier $installApplier,
    ) {
    }

    /**
     * @return WorkflowResult<array{install_id: string, zip_path: string}>
     */
    public function stageUpload(?UploadedFile $file): WorkflowResult
    {
        return $this->uploadStager->stage($file);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return WorkflowResult<array<string, mixed>>
     */
    public function verify(array $payload): WorkflowResult
    {
        return $this->installVerifier->verify($payload);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return WorkflowResult<array<string, mixed>>
     */
    public function apply(array $payload): WorkflowResult
    {
        return $this->installApplier->apply($payload);
    }
}

<?php

declare(strict_types=1);

namespace App\Core\Translation;

use App\Core\Filesystem\PathGuard;
use Symfony\Component\Yaml\Yaml;
use Throwable;

final readonly class TranslationRuntimeWriter
{
    public function __construct(
        private string $projectDir,
        private PathGuard $pathGuard,
        private TranslationRuntimePath $runtimePath,
    ) {
    }

    /**
     * @param array<string, array<string, mixed>> $catalogues
     *
     * @return list<string>
     */
    public function write(array $catalogues): array
    {
        $stagingDirectory = $this->runtimePath->relativeDirectory().'.tmp-'.bin2hex(random_bytes(8));
        $staging = $this->absolutePath($stagingDirectory);
        $targets = [];

        try {
            $this->ensureDirectory($staging);
            $this->preserveRuntimeMetadata($staging);
            ksort($catalogues);

            foreach ($catalogues as $locale => $catalogue) {
                $relativeTarget = $this->runtimePath->relativeCataloguePath($locale);
                $stagedTarget = $stagingDirectory.'/messages.'.$locale.'.yaml';
                $target = $this->absolutePath($stagedTarget);
                $this->ensureDirectory(dirname($target));
                $this->writeFile($target, Yaml::dump($catalogue, 6, 4, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK));
                $targets[] = $relativeTarget;
            }

            $this->replaceRuntimeDirectory($stagingDirectory);
        } catch (Throwable $error) {
            $this->removeDirectory($this->absolutePath($stagingDirectory));

            throw $error;
        }

        return $targets;
    }

    private function replaceRuntimeDirectory(string $stagingDirectory): void
    {
        $runtimeDirectory = $this->absolutePath($this->runtimePath->relativeDirectory());
        $staging = $this->absolutePath($stagingDirectory);
        $backupDirectory = $this->runtimePath->relativeDirectory().'.backup-'.bin2hex(random_bytes(8));
        $backup = $this->absolutePath($backupDirectory);

        if (is_dir($runtimeDirectory) || is_link($runtimeDirectory)) {
            if (!@rename($runtimeDirectory, $backup)) {
                throw new \RuntimeException(sprintf('Runtime translation directory "%s" could not be moved aside.', $this->runtimePath->relativeDirectory()));
            }
        }

        if (!@rename($staging, $runtimeDirectory)) {
            if (is_dir($backup) && !file_exists($runtimeDirectory)) {
                @rename($backup, $runtimeDirectory);
            }

            throw new \RuntimeException(sprintf('Runtime translation directory "%s" could not be replaced.', $this->runtimePath->relativeDirectory()));
        }

        $this->removeDirectory($backup);
    }

    private function preserveRuntimeMetadata(string $staging): void
    {
        $runtimeDirectory = $this->absolutePath($this->runtimePath->relativeDirectory());

        if (!is_dir($runtimeDirectory) || is_link($runtimeDirectory)) {
            return;
        }

        foreach (scandir($runtimeDirectory) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry || 1 === preg_match('/^messages\.[^.]+\.yaml$/', $entry)) {
                continue;
            }

            $source = $runtimeDirectory.DIRECTORY_SEPARATOR.$entry;
            $target = $staging.DIRECTORY_SEPARATOR.$entry;

            if (is_link($source)) {
                continue;
            }

            if (is_dir($source)) {
                $this->copyDirectory($source, $target);
                continue;
            }

            if (is_file($source) && !copy($source, $target)) {
                throw new \RuntimeException(sprintf('Runtime translation metadata "%s" could not be staged.', $entry));
            }
        }
    }

    private function copyDirectory(string $source, string $target): void
    {
        $this->ensureDirectory($target);

        foreach (scandir($source) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            $sourcePath = $source.DIRECTORY_SEPARATOR.$entry;
            $targetPath = $target.DIRECTORY_SEPARATOR.$entry;

            if (is_link($sourcePath)) {
                continue;
            }

            if (is_dir($sourcePath)) {
                $this->copyDirectory($sourcePath, $targetPath);
                continue;
            }

            if (is_file($sourcePath) && !copy($sourcePath, $targetPath)) {
                throw new \RuntimeException(sprintf('Runtime translation metadata "%s" could not be staged.', $sourcePath));
            }
        }
    }

    private function ensureDirectory(string $path): void
    {
        if (is_link($path)) {
            throw new \RuntimeException(sprintf('Runtime translation directory "%s" must not be a symlink.', $path));
        }

        if (file_exists($path) && !is_dir($path)) {
            throw new \RuntimeException(sprintf('Runtime translation directory "%s" exists as a file.', $path));
        }

        if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
            throw new \RuntimeException(sprintf('Runtime translation directory "%s" could not be created.', $path));
        }
    }

    private function writeFile(string $path, string $contents): void
    {
        $temporaryPath = $path.'.tmp-'.bin2hex(random_bytes(8));

        if (false === file_put_contents($temporaryPath, $contents, LOCK_EX)) {
            @unlink($temporaryPath);
            throw new \RuntimeException(sprintf('Runtime translation file "%s" could not be written.', $path));
        }

        if (!@rename($temporaryPath, $path)) {
            @unlink($temporaryPath);
            throw new \RuntimeException(sprintf('Runtime translation file "%s" could not be replaced.', $path));
        }
    }

    private function removeDirectory(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }

        if (is_link($path) || is_file($path)) {
            $this->removeFileOrLink($path);
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            $this->removeDirectory($path.DIRECTORY_SEPARATOR.$entry);
        }

        @rmdir($path);
    }

    private function removeFileOrLink(string $path): void
    {
        if ('\\' === DIRECTORY_SEPARATOR && @rmdir($path)) {
            return;
        }

        @unlink($path);
    }

    private function absolutePath(string $path): string
    {
        return $this->projectDir.'/'.$this->pathGuard->relativePath($path);
    }
}

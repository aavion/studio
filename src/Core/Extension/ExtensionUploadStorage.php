<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Core\Filesystem\PathGuard;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Throwable;

final readonly class ExtensionUploadStorage
{
    public const MAX_BYTES = ExtensionStorage::MAX_BYTES;

    /**
     * @var list<string>
     */
    private const BLOCKED_EXTENSIONS = [
        '7z',
        'asp',
        'aspx',
        'bat',
        'bash',
        'bz2',
        'cgi',
        'cmd',
        'com',
        'dll',
        'dmg',
        'ear',
        'exe',
        'fcgi',
        'fish',
        'gz',
        'html',
        'htm',
        'jar',
        'js',
        'jsp',
        'msi',
        'phar',
        'php',
        'php3',
        'php4',
        'php5',
        'phtml',
        'pl',
        'ps1',
        'py',
        'rar',
        'rb',
        'scr',
        'sh',
        'so',
        'svg',
        'tar',
        'tgz',
        'war',
        'xhtml',
        'xz',
        'zip',
        'zsh',
    ];

    /**
     * @var list<string>
     */
    private const BLOCKED_MIME_TYPES = [
        'application/gzip',
        'application/java-archive',
        'application/javascript',
        'application/vnd.microsoft.portable-executable',
        'application/x-7z-compressed',
        'application/x-bzip2',
        'application/x-cgi',
        'application/x-dosexec',
        'application/x-executable',
        'application/x-httpd-php',
        'application/x-msdownload',
        'application/x-msdos-program',
        'application/x-php',
        'application/x-rar-compressed',
        'application/x-sh',
        'application/x-shellscript',
        'application/x-tar',
        'application/x-xz',
        'application/zip',
        'image/svg+xml',
        'text/html',
        'text/javascript',
        'text/php',
        'text/x-php',
        'text/x-python',
        'text/x-ruby',
        'text/x-script',
        'text/x-shellscript',
    ];

    public function __construct(
        private ExtensionStorage $storage,
        private PathGuard $pathGuard = new PathGuard(),
    ) {
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array{path: string, original_name: string, size: int, mime_type: string|null, extension: string|null}|null
     */
    public function store(string $extensionName, UploadedFile $file, string $targetPath, array $options = []): ?array
    {
        if (!ExtensionManifestSpec::isValidSlug($extensionName) || !$file->isValid()) {
            return null;
        }

        try {
            $targetPath = $this->pathGuard->relativePath($targetPath);
            $size = $file->getSize();
            if (!is_int($size) || $size < 0 || $size > $this->maxBytes($options)) {
                return null;
            }

            $extension = $this->extension($targetPath, $file);
            $mimeType = $file->getMimeType() ?: $file->getClientMimeType();
            if ($this->blockedExtension($extension) || $this->blockedMimeType($mimeType)) {
                return null;
            }

            $contents = file_get_contents((string) $file->getRealPath());
            if (false === $contents || strlen($contents) !== $size) {
                return null;
            }

            if (!$this->storage->put($extensionName, $targetPath, $contents, $options)) {
                return null;
            }

            return [
                'path' => $targetPath,
                'original_name' => $this->originalName($file),
                'size' => $size,
                'mime_type' => is_string($mimeType) && '' !== trim($mimeType) ? strtolower(trim($mimeType)) : null,
                'extension' => $extension,
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    private function maxBytes(array $options): int
    {
        $maxBytes = $options['max_bytes'] ?? self::MAX_BYTES;
        $maxBytes = is_numeric($maxBytes) ? (int) $maxBytes : self::MAX_BYTES;

        return max(1, min(self::MAX_BYTES, $maxBytes));
    }

    private function extension(string $targetPath, UploadedFile $file): ?string
    {
        $extension = strtolower((string) pathinfo($targetPath, PATHINFO_EXTENSION));
        if ('' === $extension) {
            $extension = strtolower($file->getClientOriginalExtension());
        }

        return '' === $extension ? null : $extension;
    }

    private function blockedExtension(?string $extension): bool
    {
        return null !== $extension && in_array(strtolower($extension), self::BLOCKED_EXTENSIONS, true);
    }

    private function blockedMimeType(?string $mimeType): bool
    {
        if (!is_string($mimeType) || '' === trim($mimeType)) {
            return false;
        }

        $mimeType = strtolower(trim(explode(';', $mimeType, 2)[0]));

        return in_array($mimeType, self::BLOCKED_MIME_TYPES, true);
    }

    private function originalName(UploadedFile $file): string
    {
        $name = trim(str_replace(["\0", '/', '\\'], '', $file->getClientOriginalName()));

        return '' === $name ? 'upload' : substr($name, 0, 180);
    }
}

<?php

declare(strict_types=1);

namespace App\Core\Integrity;

use InvalidArgumentException;
use RuntimeException;

final class ChecksumCalculator
{
    /**
     * @param non-empty-string $algorithm
     */
    public function __construct(
        private readonly string $algorithm = 'sha256',
    ) {
        if (!in_array($algorithm, hash_algos(), true)) {
            throw new InvalidArgumentException(sprintf('Checksum algorithm "%s" is not supported.', $algorithm));
        }
    }

    public function forString(string $contents): Checksum
    {
        return new Checksum($this->algorithm, hash($this->algorithm, $contents));
    }

    public function forFile(string $path): Checksum
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException(sprintf('Checksum file "%s" is not readable.', $path));
        }

        $hash = hash_file($this->algorithm, $path);

        if (false === $hash) {
            throw new RuntimeException(sprintf('Checksum file "%s" could not be hashed.', $path));
        }

        return new Checksum($this->algorithm, $hash);
    }

    /**
     * @param list<string> $paths
     */
    public function forFileSet(string $root, array $paths): Checksum
    {
        $hashContext = hash_init($this->algorithm);

        foreach ($paths as $path) {
            if (str_ends_with($path, '/')) {
                continue;
            }

            $absolutePath = rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$path;
            if (!is_file($absolutePath) || !is_readable($absolutePath)) {
                throw new RuntimeException(sprintf('Checksum file "%s" is not readable.', $absolutePath));
            }

            hash_update($hashContext, $path."\n");

            $handle = fopen($absolutePath, 'rb');
            if (false === $handle) {
                throw new RuntimeException(sprintf('Checksum file "%s" could not be opened.', $absolutePath));
            }

            while (!feof($handle)) {
                $chunk = fread($handle, 8192);
                if (false === $chunk) {
                    fclose($handle);
                    throw new RuntimeException(sprintf('Checksum file "%s" could not be read.', $absolutePath));
                }

                hash_update($hashContext, $chunk);
            }

            fclose($handle);
            hash_update($hashContext, "\n");
        }

        return new Checksum($this->algorithm, hash_final($hashContext));
    }
}

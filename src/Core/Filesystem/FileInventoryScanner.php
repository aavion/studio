<?php

declare(strict_types=1);

namespace App\Core\Filesystem;

use InvalidArgumentException;

final class FileInventoryScanner
{
    public function scan(string $root, int $depth = 2): FileInventory
    {
        if ($depth < 0) {
            throw new InvalidArgumentException('File inventory depth must not be negative.');
        }

        if (!is_dir($root)) {
            return new FileInventory([]);
        }

        $entries = [];
        $this->collect(rtrim($root, DIRECTORY_SEPARATOR), rtrim($root, DIRECTORY_SEPARATOR), $depth, $entries);
        sort($entries);

        return new FileInventory($entries);
    }

    /**
     * @param list<string> $entries
     */
    private function collect(string $root, string $directory, int $remainingDepth, array &$entries): void
    {
        if ($remainingDepth < 0) {
            return;
        }

        $items = scandir($directory);
        if (false === $items) {
            return;
        }

        foreach ($items as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }

            $path = $directory.DIRECTORY_SEPARATOR.$item;
            if (is_link($path)) {
                continue;
            }

            $relativePath = ltrim(str_replace('\\', '/', substr($path, strlen($root))), '/');
            $entries[] = is_dir($path) ? $relativePath.'/' : $relativePath;

            if (is_dir($path)) {
                $this->collect($root, $path, $remainingDepth - 1, $entries);
            }
        }
    }
}

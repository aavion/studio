<?php

declare(strict_types=1);

namespace App\Core\Log;

final readonly class LogLineReader
{
    private const MAX_SCAN_LINES = 5000;
    private const READ_CHUNK_BYTES = 65536;

    /**
     * @return list<string>
     */
    public function readLines(string $file): array
    {
        $handle = fopen($file, 'rb');
        if (false === $handle) {
            return [];
        }

        $position = filesize($file);
        if (false === $position || $position <= 0) {
            fclose($handle);

            return [];
        }

        $lines = [];
        $partialLine = '';

        while ($position > 0 && count($lines) < self::MAX_SCAN_LINES) {
            $chunkSize = min(self::READ_CHUNK_BYTES, $position);
            $position -= $chunkSize;

            if (0 !== fseek($handle, $position)) {
                break;
            }

            $chunk = fread($handle, $chunkSize);
            if (false === $chunk) {
                break;
            }

            $parts = preg_split('/\r\n|\n|\r/', $chunk.$partialLine);
            if (!is_array($parts)) {
                break;
            }

            $partialLine = $position > 0 ? (string) array_shift($parts) : '';

            for ($index = count($parts) - 1; $index >= 0 && count($lines) < self::MAX_SCAN_LINES; --$index) {
                $line = trim((string) $parts[$index]);

                if ('' !== $line) {
                    $lines[] = $line;
                }
            }
        }

        fclose($handle);

        return $lines;
    }
}

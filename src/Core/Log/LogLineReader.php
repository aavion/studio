<?php

declare(strict_types=1);

namespace App\Core\Log;

use SplFileObject;

final readonly class LogLineReader
{
    private const MAX_SCAN_LINES = 5000;

    /**
     * @return list<string>
     */
    public function readLines(string $file): array
    {
        $object = new SplFileObject($file, 'r');
        $object->seek(PHP_INT_MAX);
        $lastLine = $object->key();
        $start = max(0, $lastLine - self::MAX_SCAN_LINES);
        $lines = [];

        for ($lineNumber = $lastLine; $lineNumber >= $start; --$lineNumber) {
            $object->seek($lineNumber);
            $line = trim((string) $object->current());

            if ('' !== $line) {
                $lines[] = $line;
            }
        }

        return $lines;
    }
}

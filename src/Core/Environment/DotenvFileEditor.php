<?php

declare(strict_types=1);

namespace App\Core\Environment;

final readonly class DotenvFileEditor
{
    /**
     * @param array<string, string> $values
     */
    public function merge(string $contents, array $values): string
    {
        $lines = '' === $contents ? [] : preg_split('/\R/', rtrim($contents));
        $seen = [];

        foreach ($lines as $index => $line) {
            if (!is_string($line) || 1 !== preg_match('/^([A-Z][A-Z0-9_]*)=/', $line, $matches)) {
                continue;
            }

            $key = $matches[1];

            if (array_key_exists($key, $values)) {
                $lines[$index] = $key.'='.$this->quote($values[$key]);
                $seen[$key] = true;
            }
        }

        foreach ($values as $key => $value) {
            if (!isset($seen[$key])) {
                $lines[] = $key.'='.$this->quote($value);
            }
        }

        return implode(PHP_EOL, $lines).PHP_EOL;
    }

    public function readValue(string $contents, string $key): ?string
    {
        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            if (!is_string($line) || 1 !== preg_match('/^'.preg_quote($key, '/').'=(.*)$/', $line, $matches)) {
                continue;
            }

            return $this->unquote(trim($matches[1]));
        }

        return null;
    }

    private function quote(string $value): string
    {
        return "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $value)."'";
    }

    private function unquote(string $value): string
    {
        if (strlen($value) >= 2 && "'" === $value[0] && "'" === $value[strlen($value) - 1]) {
            return str_replace(["\\'", '\\\\'], ["'", '\\'], substr($value, 1, -1));
        }

        if (strlen($value) >= 2 && '"' === $value[0] && '"' === $value[strlen($value) - 1]) {
            return stripcslashes(substr($value, 1, -1));
        }

        return $value;
    }
}

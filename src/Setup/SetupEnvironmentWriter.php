<?php

declare(strict_types=1);

namespace App\Setup;

use App\Core\Message\Message;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;

final readonly class SetupEnvironmentWriter
{
    /**
     * @return array<string, mixed>
     */
    public function write(string $projectDir, SetupInput $input, string $appSecret, string $databaseUrl): array
    {
        $path = $projectDir.'/.env.'.$input->appEnv().'.local';
        $values = [
            'APP_SECRET' => $appSecret,
            'DEFAULT_URI' => $input->defaultUri(),
            'DATABASE_URL' => $databaseUrl,
        ];
        $contents = '';

        if (is_file($path)) {
            $contents = file_get_contents($path);

            if (false === $contents) {
                throw $this->failure(MessageCode::SETUP_ENVIRONMENT_FILE_UNREADABLE, MessageKey::SETUP_ENVIRONMENT_FILE_UNREADABLE, $path);
            }
        }

        $bytes = @file_put_contents($path, $this->merge($contents, $values), LOCK_EX);

        if (false === $bytes) {
            throw $this->failure(MessageCode::SETUP_ENVIRONMENT_FILE_WRITE_FAILED, MessageKey::SETUP_ENVIRONMENT_FILE_WRITE_FAILED, $path);
        }

        return ['path' => basename($path), 'keys' => array_keys($values)];
    }

    /**
     * @param array<string, string> $values
     */
    private function merge(string $contents, array $values): string
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

    private function quote(string $value): string
    {
        return "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $value)."'";
    }

    private function failure(string $code, string $translationKey, string $path): SetupStepFailedException
    {
        return SetupStepFailedException::fromMessage(Message::error(
            $code,
            $translationKey,
            ['%file%' => basename($path)],
            ['path' => $path],
        ));
    }
}

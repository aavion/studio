<?php

declare(strict_types=1);

namespace App\Setup;

use App\Core\Environment\DotenvFileEditor;
use App\Core\Message\Message;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;

final readonly class SetupEnvironmentWriter
{
    public function __construct(private DotenvFileEditor $editor = new DotenvFileEditor())
    {
    }

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
            'APP_DATABASE_PREFIX' => $input->databasePrefix() ?? '',
        ];
        $contents = '';

        if (is_file($path)) {
            $contents = file_get_contents($path);

            if (false === $contents) {
                throw $this->failure(MessageCode::SETUP_ENVIRONMENT_FILE_UNREADABLE, MessageKey::SETUP_ENVIRONMENT_FILE_UNREADABLE, $path);
            }
        }

        $bytes = @file_put_contents($path, $this->editor->merge($contents, $values), LOCK_EX);

        if (false === $bytes) {
            throw $this->failure(MessageCode::SETUP_ENVIRONMENT_FILE_WRITE_FAILED, MessageKey::SETUP_ENVIRONMENT_FILE_WRITE_FAILED, $path);
        }

        return ['path' => basename($path), 'keys' => array_keys($values)];
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

<?php

declare(strict_types=1);

namespace App\Setup;

final readonly class SetupWizardDatabaseTester
{
    public function __construct(
        private string $projectDir,
        private string $environment,
        private SetupWebInputFactory $inputFactory,
        private DatabaseUrlFactory $databaseUrlFactory,
        private SetupDatabaseConnectionFactory $databaseConnectionFactory,
    ) {
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array{0: array<string, list<string>>, 1: array{success: bool, key: string}|null}
     */
    public function test(array $values): array
    {
        $errors = $this->inputFactory->validateStep('database', $values);

        if ([] !== $errors) {
            return [$errors, ['success' => false, 'key' => 'setup.database.test_invalid']];
        }

        $input = $this->inputFactory->create([
            ...$values,
            'admin_username' => 'admin',
            'admin_password' => 'Valid1!pass',
            'admin_password_confirm' => 'Valid1!pass',
            'admin_email' => 'admin@example.test',
        ]);

        if (!$input->isValid() || null === $input->input()) {
            return [$input->errors(), ['success' => false, 'key' => 'setup.database.test_invalid']];
        }

        try {
            $databaseUrl = $this->databaseUrlFactory->create($input->input(), $this->projectDir);
            $connection = $this->databaseConnectionFactory->create($this->projectDir, $databaseUrl, $this->environment);
            $connection->fetchOne('SELECT 1');

            return [[], ['success' => true, 'key' => 'setup.database.test_success']];
        } catch (\Throwable) {
            return [[], ['success' => false, 'key' => 'setup.database.test_failed']];
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Setup;

final readonly class SetupDatabaseTableSnapshot
{
    /**
     * @param list<string> $tables
     */
    private function __construct(
        private array $tables,
        private ?string $error = null,
    ) {
    }

    public static function capture(
        string $projectDir,
        string $databaseUrl,
        string $environment,
        ?string $databasePrefix,
        SetupDatabaseConnectionFactory $connectionFactory = new SetupDatabaseConnectionFactory(),
    ): self {
        $connection = null;

        try {
            $connection = $connectionFactory->create($projectDir, $databaseUrl, $environment, $databasePrefix);

            return new self(array_values(array_map('strval', $connection->createSchemaManager()->listTableNames())));
        } catch (\Throwable $throwable) {
            return new self([], $throwable->getMessage());
        } finally {
            $connection?->close();
        }
    }

    public function existed(string $table): bool
    {
        return in_array($table, $this->tables, true);
    }

    public function reliable(): bool
    {
        return null === $this->error;
    }

    public function error(): ?string
    {
        return $this->error;
    }

    /**
     * @return list<string>
     */
    public function tables(): array
    {
        return $this->tables;
    }
}

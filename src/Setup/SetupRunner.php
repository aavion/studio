<?php

declare(strict_types=1);

namespace App\Setup;

use App\Core\ActionLog\ActionLog;
use App\Core\ActionLog\ActionLogEntry;
use App\Core\ActionLog\ActionLogStatus;
use App\Core\Message\Message;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageLevel;
use App\Core\Message\MessageKey;
use App\Core\Workflow\OperationIssue;
use App\Core\Workflow\OperationResult;
use Throwable;

final class SetupRunner
{
    public function __construct(
        private readonly string $projectDir,
        private readonly SetupCommandExecutorInterface $commandExecutor = new ProcOpenSetupCommandExecutor(),
        private readonly DatabaseUrlFactory $databaseUrlFactory = new DatabaseUrlFactory(),
        private readonly SetupEnvironmentWriter $environmentWriter = new SetupEnvironmentWriter(),
        private readonly SetupDatabaseSeeder $databaseSeeder = new SetupDatabaseSeeder(),
        private readonly SetupLanguageCatalog $languageCatalog = new SetupLanguageCatalog(),
        private readonly SetupLanguageSelector $languageSelector = new SetupLanguageSelector(),
        private readonly SetupComposerCommandResolver $composerCommandResolver = new SetupComposerCommandResolver(),
        private readonly SetupDryRunPlanner $dryRunPlanner = new SetupDryRunPlanner(),
    ) {
    }

    /**
     * @return OperationResult<ActionLog>
     */
    public function run(SetupInput $input): OperationResult
    {
        $log = ActionLog::create();
        $prepare = $this->prepare($input, $log);

        if ($prepare instanceof OperationResult) {
            return $prepare;
        }

        [$appSecret, $databaseUrl, $environment] = $prepare;

        foreach ($this->steps($input, $appSecret, $databaseUrl, $environment) as $step) {
            [$name, $callback] = $step;
            $status = $step[2] ?? ActionLogStatus::Success;
            $entry = ActionLogEntry::pending($name)->start();

            try {
                $context = $callback();
                $messages = $this->messagesFromContext($context);
                unset($context['_messages']);
                $log = $log->add($entry->finish($status, context: $context, messages: $messages));
            } catch (Throwable $throwable) {
                $issue = OperationIssue::create(
                    MessageCode::SETUP_STEP_FAILED,
                    MessageKey::SETUP_STEP_FAILED,
                    ['%step%' => $name, '%message%' => $throwable->getMessage()],
                    ['step' => $name, 'exception' => $throwable::class],
                    MessageLevel::Error,
                );
                $log = $log->add($entry->finish(ActionLogStatus::Failed, [$issue]));

                return OperationResult::failed([$issue], [
                    'halt_on_error' => true,
                    'failed_step' => $name,
                    'action_log' => $log->toArray(),
                ]);
            }
        }

        return OperationResult::success($log, [
            'halt_on_error' => false,
            'dry_run' => $input->dryRun(),
            'app_env' => $input->appEnv(),
            'language' => $input->language(),
            'available_languages' => $this->languageCatalog->availableLanguages($this->projectDir),
            'default_uri' => $input->defaultUri(),
            'database_driver' => $input->databaseDriver()->value,
        ]);
    }

    /**
     * @return array{0: string, 1: string, 2: array<string, string>}|OperationResult<ActionLog>
     */
    private function prepare(SetupInput $input, ActionLog $log): array|OperationResult
    {
        try {
            $appSecret = $this->appSecret($input);
            $databaseUrl = $this->databaseUrlFactory->create($input, $this->projectDir);

            return [$appSecret, $databaseUrl, $this->environment($input, $appSecret, $databaseUrl)];
        } catch (Throwable $throwable) {
            $issue = OperationIssue::create(
                MessageCode::SETUP_STEP_FAILED,
                MessageKey::SETUP_STEP_FAILED,
                ['%step%' => 'prepare_setup', '%message%' => $throwable->getMessage()],
                ['step' => 'prepare_setup', 'exception' => $throwable::class],
                MessageLevel::Error,
            );

            return OperationResult::failed([$issue], [
                'halt_on_error' => true,
                'failed_step' => 'prepare_setup',
                'action_log' => $log->toArray(),
            ]);
        }
    }

    /**
     * @param array<string, string> $environment
     *
     * @return list<array{0: string, 1: callable(): array<string, mixed>, 2?: ActionLogStatus}>
     */
    private function steps(SetupInput $input, string $appSecret, string $databaseUrl, array $environment): array
    {
        if ($input->dryRun()) {
            return [
                ['select_language', fn (): array => $this->languageSelector->select($this->projectDir, $input), ActionLogStatus::Success],
                ...$this->dryRunPlanner->steps(
                    $this->projectDir,
                    $input,
                    $appSecret,
                    $databaseUrl,
                    $this->migrationCommand($input),
                ),
            ];
        }

        return [
            ['select_language', fn (): array => $this->languageSelector->select($this->projectDir, $input)],
            ['write_environment', fn (): array => $this->environmentWriter->write($this->projectDir, $input, $appSecret, $databaseUrl)],
            ['dump_environment', fn (): array => $this->dumpEnvironment($input, $environment)],
            ['run_migrations', fn (): array => $this->runMigrations($input, $environment)],
            ['seed_default_settings', fn (): array => $this->databaseSeeder->seedDefaultSettings($this->projectDir, $input, $databaseUrl)],
            ['seed_admin_user', fn (): array => $this->databaseSeeder->seedAdminUser($this->projectDir, $input, $databaseUrl)],
        ];
    }

    /**
     * @param array<string, string> $environment
     *
     * @return array<string, mixed>
     */
    private function dumpEnvironment(SetupInput $input, array $environment): array
    {
        $composer = $this->composerCommandResolver->resolve($this->projectDir, $this->commandExecutor, $environment);
        $command = [...$composer, 'dump-env', $input->appEnv()];
        $result = $this->commandExecutor->run($command, $this->projectDir, $environment);

        if (!$result->isSuccessful()) {
            throw new SetupStepFailedException($this->commandError($result));
        }

        return ['command' => $command];
    }

    /**
     * @param array<string, string> $environment
     *
     * @return array<string, mixed>
     */
    private function runMigrations(SetupInput $input, array $environment): array
    {
        $command = $this->migrationCommand($input);
        $result = $this->commandExecutor->run($command, $this->projectDir, $environment);

        if (!$result->isSuccessful()) {
            throw new SetupStepFailedException($this->commandError($result));
        }

        return ['command' => $command];
    }

    /**
     * @return list<string>
     */
    private function migrationCommand(SetupInput $input): array
    {
        return [
            PHP_BINARY,
            $this->projectDir.'/bin/console',
            'doctrine:migrations:migrate',
            '--no-interaction',
            '--env='.$input->appEnv(),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function environment(SetupInput $input, string $appSecret, string $databaseUrl): array
    {
        return [
            'APP_ENV' => $input->appEnv(),
            'APP_SECRET' => $appSecret,
            'DEFAULT_URI' => $input->defaultUri(),
            'DATABASE_URL' => $databaseUrl,
            'APP_DEBUG' => '0',
            'SHELL_VERBOSITY' => '-1',
        ];
    }

    private function commandError(SetupCommandResult $result): string
    {
        return trim($result->output().PHP_EOL.$result->errorOutput()) ?: 'Setup command failed.';
    }

    private function generateSecret(): string
    {
        return bin2hex(random_bytes(32));
    }

    private function appSecret(SetupInput $input): string
    {
        if (null !== $input->appSecret()) {
            return $input->dryRun() ? '[provided]' : $input->appSecret();
        }

        return $input->dryRun() ? '[generated]' : $this->generateSecret();
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return list<Message>
     */
    private function messagesFromContext(array $context): array
    {
        $messages = $context['_messages'] ?? [];

        if (!is_array($messages)) {
            return [];
        }

        return array_values(array_filter($messages, static fn (mixed $message): bool => $message instanceof Message));
    }
}

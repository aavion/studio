<?php

declare(strict_types=1);

namespace App\Setup;

use App\Core\ActionLog\ActionLog;
use App\Core\ActionLog\ActionLogEntry;
use App\Core\ActionLog\ActionLogStatus;
use App\Core\Message\Message;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use App\Core\Operation\ActionQueue;
use App\Core\Message\WorkflowResultMessageReporterInterface;
use App\Core\Workflow\WorkflowResult;
use App\Database\DatabaseReadyState;
use App\Security\PasswordPolicy;
use Throwable;

final class SetupRunner
{
    public function __construct(
        private readonly string $projectDir,
        private readonly WorkflowResultMessageReporterInterface $messageReporter,
        private readonly SetupCommandExecutorInterface $commandExecutor = new ProcOpenSetupCommandExecutor(),
        private readonly DatabaseUrlFactory $databaseUrlFactory = new DatabaseUrlFactory(),
        private readonly SetupEnvironmentWriter $environmentWriter = new SetupEnvironmentWriter(),
        private readonly SetupDatabaseSeeder $databaseSeeder = new SetupDatabaseSeeder(),
        private readonly SetupCompletionMarker $completionMarker = new SetupCompletionMarker(),
        private readonly SetupLanguageCatalog $languageCatalog = new SetupLanguageCatalog(),
        private readonly SetupLanguageSelector $languageSelector = new SetupLanguageSelector(),
        private readonly SetupComposerCommandResolver $composerCommandResolver = new SetupComposerCommandResolver(),
        private readonly SetupDryRunPlanner $dryRunPlanner = new SetupDryRunPlanner(),
        private readonly SetupPasswordPolicy $passwordPolicy = new SetupPasswordPolicy(),
    ) {
    }

    /**
     * @return WorkflowResult<ActionQueue>
     */
    public function queue(SetupInput $input): WorkflowResult
    {
        $prepare = $this->prepare($input, ActionLog::create());

        if ($prepare instanceof WorkflowResult) {
            return $this->report($prepare, $input);
        }

        [$appSecret, $databaseUrl, $environment] = $prepare;
        $actions = [];

        foreach ($this->steps($input, $appSecret, $databaseUrl, $environment) as [$name, $callback]) {
            $actions[] = new SetupStepAction($name, $callback);
        }

        return WorkflowResult::success(ActionQueue::create('setup apply', $actions, context: [
            'dry_run' => $input->dryRun(),
            'app_env' => $input->appEnv(),
            'language' => $input->language(),
            'default_uri' => $input->defaultUri(),
            'database_driver' => $input->databaseDriver()->value,
        ]));
    }

    /**
     * @return WorkflowResult<ActionLog>
     */
    public function run(SetupInput $input): WorkflowResult
    {
        $log = ActionLog::create();
        $prepare = $this->prepare($input, $log);

        if ($prepare instanceof WorkflowResult) {
            return $this->report($prepare, $input);
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
                $issue = $this->failureMessage($name, $throwable);
                $log = $log->add($entry->finish(ActionLogStatus::Failed, [$issue]));

                return $this->report(WorkflowResult::failed([$issue], [
                    'halt_on_error' => true,
                    'failed_step' => $name,
                    'action_log' => $log->toArray(),
                ]), $input);
            }
        }

        return $this->report(WorkflowResult::success($log, [
            'halt_on_error' => false,
            'dry_run' => $input->dryRun(),
            'app_env' => $input->appEnv(),
            'language' => $input->language(),
            'available_languages' => $this->languageCatalog->availableLanguages($this->projectDir),
            'default_uri' => $input->defaultUri(),
            'database_driver' => $input->databaseDriver()->value,
        ]), $input);
    }

    /**
     * @return array{0: string, 1: string, 2: array<string, string>}|WorkflowResult<ActionLog>
     */
    private function prepare(SetupInput $input, ActionLog $log): array|WorkflowResult
    {
        try {
            $validationIssues = $this->validate($input);

            if ([] !== $validationIssues) {
                return WorkflowResult::invalid($validationIssues, [
                    'halt_on_error' => true,
                    'failed_step' => 'validate_setup',
                    'action_log' => $log->toArray(),
                ]);
            }

            $appSecret = $this->appSecret($input);
            $databaseUrl = $this->databaseUrlFactory->create($input, $this->projectDir);

            return [$appSecret, $databaseUrl, $this->environment($input, $appSecret, $databaseUrl)];
        } catch (Throwable $throwable) {
            $issue = $this->failureMessage('prepare_setup', $throwable);

            return WorkflowResult::failed([$issue], [
                'halt_on_error' => true,
                'failed_step' => 'prepare_setup',
                'action_log' => $log->toArray(),
            ]);
        }
    }

    /**
     * @return list<Message>
     */
    private function validate(SetupInput $input): array
    {
        return array_map(
            fn (string $violation): Message => $this->adminPasswordMessage($violation),
            $this->passwordPolicy->violationCodes($input->adminPassword(), $input->adminUsername(), $input->adminEmail()),
        );
    }

    private function adminPasswordMessage(string $violation): Message
    {
        [$code, $key] = match ($violation) {
            PasswordPolicy::VIOLATION_COMPLEXITY => [MessageCode::SETUP_ADMIN_PASSWORD_COMPLEXITY, MessageKey::SETUP_ADMIN_PASSWORD_COMPLEXITY],
            PasswordPolicy::VIOLATION_REPEATED => [MessageCode::SETUP_ADMIN_PASSWORD_REPEATED, MessageKey::SETUP_ADMIN_PASSWORD_REPEATED],
            PasswordPolicy::VIOLATION_PERSONAL => [MessageCode::SETUP_ADMIN_PASSWORD_PERSONAL, MessageKey::SETUP_ADMIN_PASSWORD_PERSONAL],
            default => [MessageCode::SETUP_ADMIN_PASSWORD_TOO_SHORT, MessageKey::SETUP_ADMIN_PASSWORD_TOO_SHORT],
        };

        return Message::error(
            $code,
            $key,
            ['%min_length%' => SetupPasswordPolicy::MIN_ADMIN_PASSWORD_LENGTH],
            ['field' => 'admin_password', 'min_length' => SetupPasswordPolicy::MIN_ADMIN_PASSWORD_LENGTH, 'violation' => $violation],
        );
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
            ['seed_initial_content', fn (): array => $this->databaseSeeder->seedInitialContent($this->projectDir, $input, $databaseUrl)],
            ['clear_cache', fn (): array => $this->clearCache($input, $environment)],
            ['run_package_discovery', fn (): array => $this->runPackageDiscovery($input, $environment)],
            ['run_asset_rebuild', fn (): array => $this->runAssetRebuild($input, $environment)],
            ['mark_setup_completed', fn (): array => $this->completionMarker->markComplete($this->projectDir, $input->appEnv())],
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
        $result = $this->commandExecutor->run($command, $this->projectDir, $this->databaseCommandEnvironment($environment));

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
    private function clearCache(SetupInput $input, array $environment): array
    {
        $command = $this->cacheClearCommand($input);
        $result = $this->commandExecutor->run($command, $this->projectDir, $this->databaseCommandEnvironment($environment));

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
    private function runPackageDiscovery(SetupInput $input, array $environment): array
    {
        $command = [
            PHP_BINARY,
            $this->projectDir.'/bin/console',
            'studio:packages:discover',
            '--run-now',
            '--trigger=setup',
            '--env='.$input->appEnv(),
        ];
        $result = $this->commandExecutor->run($command, $this->projectDir, $this->databaseCommandEnvironment($environment));

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
    private function runAssetRebuild(SetupInput $input, array $environment): array
    {
        $command = [
            PHP_BINARY,
            $this->projectDir.'/bin/console',
            'studio:assets:rebuild',
            '--trigger=setup',
            '--env='.$input->appEnv(),
        ];
        $result = $this->commandExecutor->run($command, $this->projectDir, $this->databaseCommandEnvironment($environment));

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
     * @return list<string>
     */
    private function cacheClearCommand(SetupInput $input): array
    {
        return [
            PHP_BINARY,
            $this->projectDir.'/bin/console',
            'cache:clear',
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
            'APP_DATABASE_PREFIX' => $input->databasePrefix() ?? '',
            'APP_DEBUG' => '0',
            'SHELL_VERBOSITY' => '-1',
        ];
    }

    /**
     * @param array<string, string> $environment
     *
     * @return array<string, string>
     */
    private function databaseCommandEnvironment(array $environment): array
    {
        return [
            ...$environment,
            DatabaseReadyState::ALLOW_UNREADY_KEY => '1',
        ];
    }

    private function commandError(SetupCommandResult $result): string
    {
        return trim($result->output().PHP_EOL.$result->errorOutput()) ?: 'Setup command failed.';
    }

    private function failureMessage(string $step, Throwable $throwable): Message
    {
        if ($throwable instanceof SetupStepFailedException && null !== $throwable->messageObject()) {
            return $throwable->messageObject()->withContext([
                'step' => $step,
            ]);
        }

        $parameters = ['%step%' => $step, '%message%' => $throwable->getMessage()];
        $context = ['step' => $step, 'exception' => $throwable::class];

        if ($throwable instanceof SetupStepFailedException) {
            return Message::error(MessageCode::SETUP_STEP_FAILED, MessageKey::SETUP_STEP_FAILED, $parameters, $context);
        }

        return Message::exception(MessageCode::SETUP_STEP_FAILED, MessageKey::SETUP_STEP_FAILED, $parameters, $context);
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

    private function report(WorkflowResult $result, SetupInput $input): WorkflowResult
    {
        return $this->messageReporter->report($result, [
            'operation' => 'setup.run',
            'app_env' => $input->appEnv(),
            'dry_run' => $input->dryRun(),
            'language' => $input->language(),
        ]);
    }
}

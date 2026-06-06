<?php

declare(strict_types=1);

namespace App\Setup;

use App\Core\ActionLog\ActionLog;
use App\Core\ActionLog\ActionLogEntry;
use App\Core\ActionLog\ActionLogStatus;
use App\Core\Message\Message;
use App\Core\Message\WorkflowResultMessageReporterInterface;
use App\Core\Operation\ActionQueue;
use App\Core\Workflow\WorkflowResult;
use App\Setup\SetupMessageCode;
use App\Setup\SetupMessageKey;
use Throwable;

final class SetupRunner
{
    public function __construct(
        private readonly string $projectDir,
        private readonly WorkflowResultMessageReporterInterface $messageReporter,
        private readonly SetupCommandExecutorInterface $commandExecutor = new ProcessSetupCommandExecutor(),
        private readonly DatabaseUrlFactory $databaseUrlFactory = new DatabaseUrlFactory(),
        private readonly SetupEnvironmentWriter $environmentWriter = new SetupEnvironmentWriter(),
        private readonly SetupDatabaseSeeder $databaseSeeder = new SetupDatabaseSeeder(),
        private readonly SetupCompletionMarker $completionMarker = new SetupCompletionMarker(),
        private readonly SetupLanguageCatalog $languageCatalog = new SetupLanguageCatalog(),
        private readonly SetupLanguageSelector $languageSelector = new SetupLanguageSelector(),
        private readonly SetupDryRunPlanner $dryRunPlanner = new SetupDryRunPlanner(),
        private readonly SetupRollbacker $rollbacker = new SetupRollbacker(),
        private readonly SetupRuntimeCommandRunner $runtimeCommands = new SetupRuntimeCommandRunner(),
        private readonly SetupDatabaseEnvironmentScope $databaseEnvironmentScope = new SetupDatabaseEnvironmentScope(),
        private readonly SetupRunInputValidator $inputValidator = new SetupRunInputValidator(),
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

        [$appSecret, $databaseUrl, $environment, $rollbackSnapshot, $tableSnapshot] = $prepare;
        $actions = [];

        foreach ($this->steps($input, $appSecret, $databaseUrl, $environment) as [$name, $callback]) {
            $actions[] = new SetupStepAction(
                $name,
                $callback,
                fn (Throwable $_): array => $this->rollback($input, $databaseUrl, $rollbackSnapshot, $tableSnapshot),
            );
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

        [$appSecret, $databaseUrl, $environment, $rollbackSnapshot, $tableSnapshot] = $prepare;

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
                $context = $this->rollback($input, $databaseUrl, $rollbackSnapshot, $tableSnapshot);
                $messages = $this->messagesFromContext($context);
                unset($context['_messages']);
                $log = $log->add($entry->finish(ActionLogStatus::Failed, [$issue], $context, messages: $messages));

                return $this->report(WorkflowResult::failed([$issue], [
                    'halt_on_error' => true,
                    'failed_step' => $name,
                    'action_log' => $log->toArray(),
                    ...$context,
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
     * @return array{0: string, 1: string, 2: array<string, string>, 3: SetupEnvironmentSnapshot, 4: SetupDatabaseTableSnapshot|null}|WorkflowResult<ActionLog>
     */
    private function prepare(SetupInput $input, ActionLog $log): array|WorkflowResult
    {
        try {
            $validationIssues = $this->inputValidator->validate($input);

            if ([] !== $validationIssues) {
                return WorkflowResult::invalid($validationIssues, [
                    'halt_on_error' => true,
                    'failed_step' => 'validate_setup',
                    'action_log' => $log->toArray(),
                ]);
            }

            $appSecret = $this->appSecret($input);
            $databaseUrl = $this->databaseUrlFactory->create($input, $this->projectDir);

            return [
                $appSecret,
                $databaseUrl,
                $this->environment($input, $appSecret, $databaseUrl),
                SetupEnvironmentSnapshot::capture($this->projectDir, $input->appEnv()),
                $input->dryRun() ? null : SetupDatabaseTableSnapshot::capture($this->projectDir, $databaseUrl, $input->appEnv(), $input->databasePrefix()),
            ];
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
                    $this->runtimeCommands->dryRunMigrationCommand($this->projectDir, $input),
                ),
            ];
        }

        return [
            ['select_language', fn (): array => $this->languageSelector->select($this->projectDir, $input)],
            ['write_environment', fn (): array => $this->environmentWriter->write($this->projectDir, $input, $appSecret, $databaseUrl)],
            ['resolve_php_cli', fn (): array => $this->runtimeCommands->resolvePhpCli($this->projectDir, $input, $environment)],
            ['dump_environment', fn (): array => $this->runtimeCommands->dumpEnvironment($this->projectDir, $input, $environment, $this->commandExecutor)],
            ['run_migrations', fn (): array => $this->runtimeCommands->runMigrations($this->projectDir, $input, $environment, $this->commandExecutor)],
            ['seed_default_settings', fn (): array => $this->databaseEnvironmentScope->run($environment, fn (): array => $this->databaseSeeder->seedDefaultSettings($this->projectDir, $input, $databaseUrl))],
            ['seed_admin_user', fn (): array => $this->databaseEnvironmentScope->run($environment, fn (): array => $this->databaseSeeder->seedAdminUser($this->projectDir, $input, $databaseUrl))],
            ['seed_initial_content', fn (): array => $this->databaseEnvironmentScope->run($environment, fn (): array => $this->databaseSeeder->seedInitialContent($this->projectDir, $input, $databaseUrl))],
            ['clear_cache', fn (): array => $this->runtimeCommands->clearCache($this->projectDir, $input, $environment, $this->commandExecutor)],
            ['run_package_discovery', fn (): array => $this->runtimeCommands->runPackageDiscovery($this->projectDir, $input, $environment, $this->commandExecutor)],
            ['run_asset_rebuild', fn (): array => $this->runtimeCommands->runAssetRebuild($this->projectDir, $input, $environment, $this->commandExecutor)],
            ['mark_setup_completed', fn (): array => $this->completionMarker->markComplete($this->projectDir, $input->appEnv())],
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
            return Message::error(SetupMessageCode::SETUP_STEP_FAILED, SetupMessageKey::SETUP_STEP_FAILED, $parameters, $context);
        }

        return Message::exception(SetupMessageCode::SETUP_STEP_FAILED, SetupMessageKey::SETUP_STEP_FAILED, $parameters, $context);
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

    /**
     * @return array<string, mixed>
     */
    private function rollback(
        SetupInput $input,
        string $databaseUrl,
        SetupEnvironmentSnapshot $environmentSnapshot,
        ?SetupDatabaseTableSnapshot $tableSnapshot,
    ): array
    {
        return $this->rollbacker->rollback($this->projectDir, $input, $databaseUrl, $environmentSnapshot, $tableSnapshot);
    }
}

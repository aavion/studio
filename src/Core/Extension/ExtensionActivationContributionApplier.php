<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Core\Message\Message;
use App\Core\Message\MessageException;
use App\Core\Message\MessageLevel;
use App\Core\Workflow\WorkflowResult;
use App\Entity\Extension;
use Doctrine\DBAL\Connection;
use Throwable;

final readonly class ExtensionActivationContributionApplier implements ExtensionActivationContributionApplierInterface
{
    public function __construct(
        private ExtensionContributionReader $contributionReader,
        private Database\ExtensionDatabaseSchemaSynchronizer $databaseSynchronizer,
        private Content\ExtensionContentSchemaSynchronizer $contentSchemaSynchronizer,
        private Connection $connection,
    ) {
    }

    public function applyActivatedExtensions(array $extensions): WorkflowResult
    {
        $messages = [];
        $actions = [];
        $createdDatabaseTables = [];
        $this->connection->beginTransaction();

        try {
            foreach ($extensions as $extension) {
                $registry = $this->contributionReader->read($extension);

                $database = $this->databaseSynchronizer->apply($extension, $registry->extensionDatabaseTables());
                if (!$database->isSuccess()) {
                    $this->rollBackIfActive();
                    $cleanup = $this->cleanupCreatedDatabaseTables($createdDatabaseTables);

                    return WorkflowResult::failed([...$database->issues(), ...$cleanup['issues']], [
                        'extension' => $extension->extensionName(),
                        'database_context' => $database->context(),
                        'cleanup_incomplete' => [] !== $cleanup['issues'],
                    ], [...$messages, ...$database->messages(), ...$cleanup['messages']]);
                }

                $createdDatabaseTables[] = [
                    'extension' => $extension,
                    'tables' => $database->value()['created'] ?? [],
                ];

                $schemas = $this->contentSchemaSynchronizer->apply($extension, $registry->extensionContentSchemas());
                if (!$schemas->isSuccess()) {
                    $this->rollBackIfActive();
                    $cleanup = $this->cleanupCreatedDatabaseTables($createdDatabaseTables);

                    return WorkflowResult::failed([...$schemas->issues(), ...$cleanup['issues']], [
                        'extension' => $extension->extensionName(),
                        'content_schema_context' => $schemas->context(),
                        'cleanup_incomplete' => [] !== $cleanup['issues'],
                    ], [...$messages, ...$database->messages(), ...$schemas->messages(), ...$cleanup['messages']]);
                }

                $messages = [...$messages, ...$database->messages(), ...$schemas->messages()];
                $actions[] = [
                    'extension' => $extension->extensionName(),
                    'database' => $database->value(),
                    'content_schema' => $schemas->value(),
                ];
            }

            $this->connection->commit();
        } catch (MessageException $error) {
            $this->rollBackIfActive();
            $cleanup = $this->cleanupCreatedDatabaseTables($createdDatabaseTables);
            $messages = [...$messages, ...$cleanup['messages']];

            return WorkflowResult::failed([$error->message(), ...$cleanup['issues']], ['exception' => $error::class, 'cleanup_incomplete' => [] !== $cleanup['issues'], ...$error->context()], $messages);
        } catch (Throwable $error) {
            $this->rollBackIfActive();
            $cleanup = $this->cleanupCreatedDatabaseTables($createdDatabaseTables);
            $messages = [...$messages, ...$cleanup['messages']];

            return WorkflowResult::failed([
                Message::create(
                    ExtensionMessageCode::EXTENSION_LIFECYCLE_PHP_LOAD_FAILED,
                    ExtensionMessageKey::EXTENSION_LIFECYCLE_PHP_LOAD_FAILED,
                    ['%extension%' => 'activation'],
                    ['exception' => $error::class, 'message' => $error->getMessage()],
                    MessageLevel::Exception,
                ),
                ...$cleanup['issues'],
            ], ['exception' => $error::class, 'message' => $error->getMessage(), 'cleanup_incomplete' => [] !== $cleanup['issues']], $messages);
        }

        return WorkflowResult::success(['actions' => $actions], ['actions' => $actions], $messages);
    }

    private function rollBackIfActive(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
    }

    /**
     * @param list<array{extension: Extension, tables: list<string>}> $createdDatabaseTables
     *
     * @return array{issues: list<Message>, messages: list<Message>}
     */
    private function cleanupCreatedDatabaseTables(array $createdDatabaseTables): array
    {
        $issues = [];
        $messages = [];

        foreach (array_reverse($createdDatabaseTables) as $entry) {
            if (!$entry['extension'] instanceof Extension || [] === $entry['tables']) {
                continue;
            }

            $cleanup = $this->databaseSynchronizer->dropTables($entry['extension'], $entry['tables']);
            $messages = [...$messages, ...$cleanup->messages()];
            $issues = [...$issues, ...$cleanup->issues()];
        }

        return ['issues' => $issues, 'messages' => $messages];
    }
}

<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Core\Message\Message;
use App\Core\Message\MessageException;
use App\Core\Message\MessageLevel;
use App\Core\Workflow\WorkflowResult;
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
        $this->connection->beginTransaction();

        try {
            foreach ($extensions as $extension) {
                $registry = $this->contributionReader->read($extension);

                $database = $this->databaseSynchronizer->apply($extension, $registry->extensionDatabaseTables());
                if (!$database->isSuccess()) {
                    $this->connection->rollBack();

                    return WorkflowResult::failed($database->issues(), [
                        'extension' => $extension->extensionName(),
                        'database_context' => $database->context(),
                    ], [...$messages, ...$database->messages()]);
                }

                $schemas = $this->contentSchemaSynchronizer->apply($extension, $registry->extensionContentSchemas());
                if (!$schemas->isSuccess()) {
                    $this->connection->rollBack();

                    return WorkflowResult::failed($schemas->issues(), [
                        'extension' => $extension->extensionName(),
                        'content_schema_context' => $schemas->context(),
                    ], [...$messages, ...$database->messages(), ...$schemas->messages()]);
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
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }

            return WorkflowResult::failed([$error->message()], ['exception' => $error::class, ...$error->context()], $messages);
        } catch (Throwable $error) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }

            return WorkflowResult::failed([
                Message::create(
                    ExtensionMessageCode::EXTENSION_LIFECYCLE_PHP_LOAD_FAILED,
                    ExtensionMessageKey::EXTENSION_LIFECYCLE_PHP_LOAD_FAILED,
                    ['%extension%' => 'activation'],
                    ['exception' => $error::class, 'message' => $error->getMessage()],
                    MessageLevel::Exception,
                ),
            ], ['exception' => $error::class, 'message' => $error->getMessage()], $messages);
        }

        return WorkflowResult::success(['actions' => $actions], ['actions' => $actions], $messages);
    }
}

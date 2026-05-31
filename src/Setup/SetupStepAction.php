<?php

declare(strict_types=1);

namespace App\Setup;

use App\Core\DryRun\DryRunAction;
use App\Core\DryRun\DryRunRisk;
use App\Core\Message\Message;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use App\Core\Operation\OperationActionInterface;
use App\Core\Workflow\WorkflowResult;
use Closure;
use Throwable;

final readonly class SetupStepAction implements OperationActionInterface
{
    private Closure $callback;

    public function __construct(
        private string $name,
        callable $callback,
    ) {
        $this->callback = Closure::fromCallable($callback);
    }

    public function type(): string
    {
        return 'setup.'.$this->name;
    }

    public function label(): string
    {
        return $this->name;
    }

    public function dryRun(): DryRunAction
    {
        return DryRunAction::create($this->type(), $this->label(), DryRunRisk::High, [
            '.env.{APP_ENV}.local',
            '.env.local.php',
            'var/',
            'public/assets/',
            'translations/runtime/{APP_ENV}/',
        ]);
    }

    public function execute(): WorkflowResult
    {
        try {
            $context = ($this->callback)();
            $messages = $this->messagesFromContext($context);
            unset($context['_messages']);

            return WorkflowResult::success(context: $context, messages: $messages);
        } catch (Throwable $throwable) {
            return WorkflowResult::failed([$this->failureMessage($throwable)], [
                'halt_on_error' => true,
                'failed_step' => $this->name,
            ]);
        }
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

    private function failureMessage(Throwable $throwable): Message
    {
        if ($throwable instanceof SetupStepFailedException && null !== $throwable->messageObject()) {
            return $throwable->messageObject()->withContext([
                'step' => $this->name,
            ]);
        }

        $parameters = ['%step%' => $this->name, '%message%' => $throwable->getMessage()];
        $context = ['step' => $this->name, 'exception' => $throwable::class];

        if ($throwable instanceof SetupStepFailedException) {
            return Message::error(MessageCode::SETUP_STEP_FAILED, MessageKey::SETUP_STEP_FAILED, $parameters, $context);
        }

        return Message::exception(MessageCode::SETUP_STEP_FAILED, MessageKey::SETUP_STEP_FAILED, $parameters, $context);
    }
}

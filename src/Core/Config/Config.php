<?php

declare(strict_types=1);

namespace App\Core\Config;

use App\Core\Message\Message;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageException;
use App\Core\Message\MessageKey;
use App\Core\Message\MessageReporterInterface;
use App\Core\Validation\Identifier;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use JsonException;
use Throwable;

final readonly class Config
{
    public function __construct(
        private Connection $connection,
        private ?MessageReporterInterface $messageReporter = null,
    )
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (!$this->validateKey($key, 'config.get')) {
            return $default;
        }

        try {
            $value = $this->connection->fetchOne('SELECT value FROM config_entry WHERE config_key = ?', [$key]);
        } catch (Throwable $error) {
            $this->report(Message::exception(
                MessageCode::CONFIG_READ_FAILED,
                MessageKey::CONFIG_READ_FAILED,
                ['%key%' => $key],
                $this->errorContext($error, 'config.get', $key),
            ));

            return $default;
        }

        if (!is_string($value)) {
            return $default;
        }

        try {
            return json_decode($value, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            $this->report(Message::warning(
                MessageCode::CONFIG_VALUE_INVALID,
                MessageKey::CONFIG_VALUE_INVALID,
                ['%key%' => $key],
                $this->errorContext($error, 'config.get', $key),
            ));

            return $default;
        }
    }

    public function set(
        string $key,
        mixed $value,
        ?ConfigValueType $type = null,
        bool $sensitive = false,
        ?string $modifiedBy = null,
    ): bool {
        if (!$this->validateKey($key, 'config.set')) {
            return false;
        }

        try {
            $values = [
                'value' => json_encode($value, JSON_THROW_ON_ERROR),
                'value_type' => ($type ?? $this->typeFor($value))->value,
                'sensitive' => $sensitive ? 1 : 0,
                'modified_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
                'modified_by' => $modifiedBy,
            ];

            $this->connection->fetchOne('SELECT config_key FROM config_entry WHERE config_key = ?', [$key])
                ? $this->connection->update('config_entry', $values, ['config_key' => $key])
                : $this->connection->insert('config_entry', ['config_key' => $key, ...$values]);

            return true;
        } catch (Throwable $error) {
            $this->report(Message::exception(
                MessageCode::CONFIG_WRITE_FAILED,
                MessageKey::CONFIG_WRITE_FAILED,
                ['%key%' => $key],
                $this->errorContext($error, 'config.set', $key),
            ));

            return false;
        }
    }

    private function typeFor(mixed $value): ConfigValueType
    {
        return match (true) {
            is_bool($value) => ConfigValueType::Boolean,
            is_int($value) => ConfigValueType::Integer,
            is_float($value) => ConfigValueType::Float,
            is_string($value) => ConfigValueType::String,
            default => ConfigValueType::Json,
        };
    }

    private function validateKey(string $key, string $operation): bool
    {
        try {
            Identifier::assertConfigKey($key, MessageKey::CONFIG_KEY_INVALID);

            return true;
        } catch (MessageException $exception) {
            $this->report($exception->message()->withContext([
                'operation' => $operation,
                'config_key' => $key,
            ]));

            return false;
        }
    }

    private function report(Message $message): Message
    {
        return null === $this->messageReporter
            ? $message
            : $this->messageReporter->report($message, [
                'component' => self::class,
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function errorContext(Throwable $error, string $operation, string $key): array
    {
        return [
            'operation' => $operation,
            'config_key' => $key,
            'exception' => $error::class,
            'message' => $error->getMessage(),
        ];
    }
}

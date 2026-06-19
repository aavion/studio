<?php

declare(strict_types=1);

namespace App\Core\Extension\Settings;

use App\Core\Config\ConfigMessageKey;
use App\Core\Config\ConfigValueType;
use App\Core\Message\CommonMessageCode;
use App\Core\Message\Message;
use App\Core\Message\MessageReporterInterface;
use App\Core\Extension\ExtensionMessageCode;
use App\Core\Extension\ExtensionMessageKey;
use App\Core\Extension\ExtensionIdentity;
use App\Core\Validation\Identifier;
use App\Form\FormFieldDefinition;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use JsonException;
use Throwable;

final readonly class ExtensionSettings
{
    public function __construct(
        private Connection $connection,
        private ?MessageReporterInterface $messageReporter = null,
    ) {
    }

    public function get(string $extensionName, string $key, mixed $default = null): mixed
    {
        if (!$this->validate($extensionName, $key, 'extension_settings.get')) {
            return $default;
        }

        try {
            $value = $this->connection->fetchOne(
                'SELECT value FROM extension_setting_entry WHERE extension_name = ? AND setting_key = ?',
                [$extensionName, $key],
            );
        } catch (Throwable $error) {
            $this->report(Message::exception(
                ExtensionMessageCode::EXTENSION_SETTING_READ_FAILED,
                ExtensionMessageKey::EXTENSION_SETTING_READ_FAILED,
                ['%extension%' => $extensionName, '%key%' => $key],
                $this->errorContext($error, 'extension_settings.get', $extensionName, $key),
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
                ExtensionMessageCode::EXTENSION_SETTING_VALUE_INVALID,
                ExtensionMessageKey::EXTENSION_SETTING_VALUE_INVALID,
                ['%extension%' => $extensionName, '%key%' => $key],
                $this->errorContext($error, 'extension_settings.get', $extensionName, $key),
            ));

            return $default;
        }
    }

    public function getDefinitionValue(ExtensionSettingDefinition $definition): mixed
    {
        return $this->get($definition->extensionName(), $definition->key(), $definition->defaultValue());
    }

    public function set(
        string $extensionName,
        string $key,
        mixed $value,
        ?ConfigValueType $type = null,
        ?string $modifiedBy = null,
    ): bool {
        if (!$this->validate($extensionName, $key, 'extension_settings.set')) {
            return false;
        }

        try {
            $values = [
                'value' => json_encode($value, JSON_THROW_ON_ERROR),
                'value_type' => ($type ?? $this->typeFor($value))->value,
                'metadata' => json_encode([], JSON_THROW_ON_ERROR),
                'modified_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
                'modified_by' => $modifiedBy,
            ];

            $exists = $this->connection->fetchOne(
                'SELECT setting_key FROM extension_setting_entry WHERE extension_name = ? AND setting_key = ?',
                [$extensionName, $key],
            );

            $exists
                ? $this->connection->update('extension_setting_entry', $values, ['extension_name' => $extensionName, 'setting_key' => $key])
                : $this->connection->insert('extension_setting_entry', ['extension_name' => $extensionName, 'setting_key' => $key, ...$values]);

            return true;
        } catch (Throwable $error) {
            $this->report(Message::exception(
                ExtensionMessageCode::EXTENSION_SETTING_WRITE_FAILED,
                ExtensionMessageKey::EXTENSION_SETTING_WRITE_FAILED,
                ['%extension%' => $extensionName, '%key%' => $key],
                $this->errorContext($error, 'extension_settings.set', $extensionName, $key),
            ));

            return false;
        }
    }

    public function removeExtension(string $extensionName): int
    {
        if (!$this->validateExtensionName($extensionName, 'extension_settings.remove_extension')) {
            return 0;
        }

        try {
            return $this->connection->delete('extension_setting_entry', ['extension_name' => $extensionName]);
        } catch (Throwable $error) {
            $this->report(Message::exception(
                ExtensionMessageCode::EXTENSION_SETTING_DELETE_FAILED,
                ExtensionMessageKey::EXTENSION_SETTING_DELETE_FAILED,
                ['%extension%' => $extensionName],
                $this->errorContext($error, 'extension_settings.remove_extension', $extensionName, null),
            ));

            return 0;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function viewRows(string $extensionName, ExtensionSettingRegistry $registry): array
    {
        return array_map(
            fn (ExtensionSettingDefinition $definition): array => $definition->toArray($this->getDefinitionValue($definition)),
            $registry->definitions($extensionName),
        );
    }

    /**
     * @return list<FormFieldDefinition>
     */
    public function formFields(string $extensionName, ExtensionSettingRegistry $registry): array
    {
        return array_map(
            fn (ExtensionSettingDefinition $definition): FormFieldDefinition => $definition->formField($this->getDefinitionValue($definition)),
            $registry->definitions($extensionName),
        );
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

    private function validate(string $extensionName, string $key, string $operation): bool
    {
        return $this->validateExtensionName($extensionName, $operation) && $this->validateKey($key, $operation, $extensionName);
    }

    private function validateExtensionName(string $extensionName, string $operation): bool
    {
        if (ExtensionIdentity::isExtensionName($extensionName)) {
            return true;
        }

        $this->report(Message::warning(
            ExtensionMessageCode::EXTENSION_IDENTIFIER_INVALID,
            ExtensionMessageKey::EXTENSION_IDENTIFIER_INVALID,
            ['%identifier%' => $extensionName],
            ['operation' => $operation, 'extension' => $extensionName],
        ));

        return false;
    }

    private function validateKey(string $key, string $operation, string $extensionName): bool
    {
        try {
            Identifier::assertConfigKey($key, ConfigMessageKey::CONFIG_KEY_INVALID);

            return true;
        } catch (Throwable $error) {
            $this->report(Message::warning(
                CommonMessageCode::E_INVALID_ARGUMENT,
                ConfigMessageKey::CONFIG_KEY_INVALID,
                ['%key%' => $key],
                $this->errorContext($error, $operation, $extensionName, $key),
            ));

            return false;
        }
    }

    private function report(Message $message): Message
    {
        return null === $this->messageReporter
            ? $message
            : $this->messageReporter->report($message, ['component' => self::class]);
    }

    /**
     * @return array<string, mixed>
     */
    private function errorContext(Throwable $error, string $operation, string $extensionName, ?string $key): array
    {
        return [
            'operation' => $operation,
            'extension' => $extensionName,
            'setting_key' => $key,
            'exception' => $error::class,
            'message' => $error->getMessage(),
        ];
    }
}

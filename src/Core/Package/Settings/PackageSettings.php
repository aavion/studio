<?php

declare(strict_types=1);

namespace App\Core\Package\Settings;

use App\Core\Config\ConfigMessageKey;
use App\Core\Config\ConfigValueType;
use App\Core\Message\CommonMessageCode;
use App\Core\Message\Message;
use App\Core\Message\MessageReporterInterface;
use App\Core\Package\PackageMessageCode;
use App\Core\Package\PackageMessageKey;
use App\Core\Package\ExtensionPackageIdentity;
use App\Core\Validation\Identifier;
use App\Form\FormFieldDefinition;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use JsonException;
use Throwable;

final readonly class PackageSettings
{
    public function __construct(
        private Connection $connection,
        private ?MessageReporterInterface $messageReporter = null,
    ) {
    }

    public function get(string $packageName, string $key, mixed $default = null): mixed
    {
        if (!$this->validate($packageName, $key, 'package_settings.get')) {
            return $default;
        }

        try {
            $value = $this->connection->fetchOne(
                'SELECT value FROM package_setting_entry WHERE package_name = ? AND setting_key = ?',
                [$packageName, $key],
            );
        } catch (Throwable $error) {
            $this->report(Message::exception(
                PackageMessageCode::PACKAGE_SETTING_READ_FAILED,
                PackageMessageKey::PACKAGE_SETTING_READ_FAILED,
                ['%package%' => $packageName, '%key%' => $key],
                $this->errorContext($error, 'package_settings.get', $packageName, $key),
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
                PackageMessageCode::PACKAGE_SETTING_VALUE_INVALID,
                PackageMessageKey::PACKAGE_SETTING_VALUE_INVALID,
                ['%package%' => $packageName, '%key%' => $key],
                $this->errorContext($error, 'package_settings.get', $packageName, $key),
            ));

            return $default;
        }
    }

    public function getDefinitionValue(PackageSettingDefinition $definition): mixed
    {
        return $this->get($definition->packageName(), $definition->key(), $definition->defaultValue());
    }

    public function set(
        string $packageName,
        string $key,
        mixed $value,
        ?ConfigValueType $type = null,
        ?string $modifiedBy = null,
    ): bool {
        if (!$this->validate($packageName, $key, 'package_settings.set')) {
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
                'SELECT setting_key FROM package_setting_entry WHERE package_name = ? AND setting_key = ?',
                [$packageName, $key],
            );

            $exists
                ? $this->connection->update('package_setting_entry', $values, ['package_name' => $packageName, 'setting_key' => $key])
                : $this->connection->insert('package_setting_entry', ['package_name' => $packageName, 'setting_key' => $key, ...$values]);

            return true;
        } catch (Throwable $error) {
            $this->report(Message::exception(
                PackageMessageCode::PACKAGE_SETTING_WRITE_FAILED,
                PackageMessageKey::PACKAGE_SETTING_WRITE_FAILED,
                ['%package%' => $packageName, '%key%' => $key],
                $this->errorContext($error, 'package_settings.set', $packageName, $key),
            ));

            return false;
        }
    }

    public function removePackage(string $packageName): int
    {
        if (!$this->validatePackageName($packageName, 'package_settings.remove_package')) {
            return 0;
        }

        try {
            return $this->connection->delete('package_setting_entry', ['package_name' => $packageName]);
        } catch (Throwable $error) {
            $this->report(Message::exception(
                PackageMessageCode::PACKAGE_SETTING_DELETE_FAILED,
                PackageMessageKey::PACKAGE_SETTING_DELETE_FAILED,
                ['%package%' => $packageName],
                $this->errorContext($error, 'package_settings.remove_package', $packageName, null),
            ));

            return 0;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function viewRows(string $packageName, PackageSettingRegistry $registry): array
    {
        return array_map(
            fn (PackageSettingDefinition $definition): array => $definition->toArray($this->getDefinitionValue($definition)),
            $registry->definitions($packageName),
        );
    }

    /**
     * @return list<FormFieldDefinition>
     */
    public function formFields(string $packageName, PackageSettingRegistry $registry): array
    {
        return array_map(
            fn (PackageSettingDefinition $definition): FormFieldDefinition => $definition->formField($this->getDefinitionValue($definition)),
            $registry->definitions($packageName),
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

    private function validate(string $packageName, string $key, string $operation): bool
    {
        return $this->validatePackageName($packageName, $operation) && $this->validateKey($key, $operation, $packageName);
    }

    private function validatePackageName(string $packageName, string $operation): bool
    {
        if (ExtensionPackageIdentity::isPackageName($packageName)) {
            return true;
        }

        $this->report(Message::warning(
            PackageMessageCode::PACKAGE_IDENTIFIER_INVALID,
            PackageMessageKey::PACKAGE_IDENTIFIER_INVALID,
            ['%identifier%' => $packageName],
            ['operation' => $operation, 'package' => $packageName],
        ));

        return false;
    }

    private function validateKey(string $key, string $operation, string $packageName): bool
    {
        try {
            Identifier::assertConfigKey($key, ConfigMessageKey::CONFIG_KEY_INVALID);

            return true;
        } catch (Throwable $error) {
            $this->report(Message::warning(
                CommonMessageCode::E_INVALID_ARGUMENT,
                ConfigMessageKey::CONFIG_KEY_INVALID,
                ['%key%' => $key],
                $this->errorContext($error, $operation, $packageName, $key),
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
    private function errorContext(Throwable $error, string $operation, string $packageName, ?string $key): array
    {
        return [
            'operation' => $operation,
            'package' => $packageName,
            'setting_key' => $key,
            'exception' => $error::class,
            'message' => $error->getMessage(),
        ];
    }
}

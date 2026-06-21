<?php

declare(strict_types=1);

namespace App\Tests\Core\Extension;

use App\Core\Config\ConfigValueType;
use App\Core\Extension\ActiveExtensionProviderInterface;
use App\Core\Extension\ExtensionStatus;
use App\Core\Extension\ExtensionScope;
use App\Core\Extension\Settings\ExtensionSettingDefinition;
use App\Core\Extension\Settings\ExtensionSettingProviderInterface;
use App\Core\Extension\Settings\ExtensionSettingRegistry;
use App\Entity\Extension;
use App\Form\FormInputType;
use PHPUnit\Framework\TestCase;

final class ExtensionSettingRegistryTest extends TestCase
{
    public function testItReturnsSettingsOnlyForActiveExtensions(): void
    {
        $registry = new ExtensionSettingRegistry(
            [new StaticExtensionSettingProvider([
                new ExtensionSettingDefinition(
                    'active-module',
                    'display.mode',
                    'Display mode',
                    'compact',
                    ConfigValueType::String,
                    options: ['compact', 'comfortable'],
                    validation: ['required' => true],
                    sortOrder: 20,
                ),
                new ExtensionSettingDefinition('inactive-module', 'display.mode', 'Display mode', 'compact'),
                new ExtensionSettingDefinition('active-module', 'feature.enabled', 'Feature enabled', true, ConfigValueType::Boolean, sortOrder: 10),
            ])],
            new StaticActiveExtensionProvider([
                new Extension(
                    '10000000-0000-7000-8000-000000000601',
                    [ExtensionScope::Module],
                    'active-module',
                    'extensions/active-module',
                    ExtensionStatus::Active,
                    [
                        'display_name' => 'Active Module',
                        'description' => 'Adds configurable active module behavior.',
                    ],
                ),
            ]),
        );

        $definitions = $registry->definitions();

        self::assertSame(['feature.enabled', 'display.mode'], array_map(
            static fn (ExtensionSettingDefinition $definition): string => $definition->key(),
            $definitions,
        ));
        self::assertSame(FormInputType::Checkbox, $definitions[0]->inputType());
        self::assertSame(FormInputType::Select, $definitions[1]->inputType());
        self::assertSame(['required' => true], $definitions[1]->validation());
        self::assertSame('select', $definitions[1]->toArray()['input_type']);
        self::assertSame(['compact', 'comfortable'], $definitions[1]->toArray()['options']);
        self::assertSame(['compact' => 'compact', 'comfortable' => 'comfortable'], $definitions[1]->formField()->options());
        self::assertSame([
            'active-module' => [
                'label' => 'Active Module',
                'description' => 'Adds configurable active module behavior.',
                'path' => '/admin/settings/extensions/active-module',
            ],
        ], $registry->extensionsWithDefinitions());
    }
}

final readonly class StaticExtensionSettingProvider implements ExtensionSettingProviderInterface
{
    /**
     * @param list<ExtensionSettingDefinition> $definitions
     */
    public function __construct(private array $definitions)
    {
    }

    public function extensionSettings(): array
    {
        return $this->definitions;
    }
}

final readonly class StaticActiveExtensionProvider implements ActiveExtensionProviderInterface
{
    /**
     * @param list<Extension> $extensions
     */
    public function __construct(private array $extensions)
    {
    }

    public function extensions(?ExtensionScope $scope = null): array
    {
        if (null === $scope) {
            return $this->extensions;
        }

        return array_values(array_filter(
            $this->extensions,
            static fn (Extension $extension): bool => $extension->hasScope($scope),
        ));
    }

    public function extension(string $extensionName): ?Extension
    {
        foreach ($this->extensions as $extension) {
            if ($extension->extensionName() === $extensionName) {
                return $extension;
            }
        }

        return null;
    }
}

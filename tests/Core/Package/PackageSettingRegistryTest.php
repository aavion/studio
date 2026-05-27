<?php

declare(strict_types=1);

namespace App\Tests\Core\Package;

use App\Core\Config\ConfigValueType;
use App\Core\Package\ActivePackageProviderInterface;
use App\Core\Package\ExtensionPackageStatus;
use App\Core\Package\PackageScope;
use App\Core\Package\Settings\PackageSettingDefinition;
use App\Core\Package\Settings\PackageSettingProviderInterface;
use App\Core\Package\Settings\PackageSettingRegistry;
use App\Entity\ExtensionPackage;
use App\Form\FormInputType;
use PHPUnit\Framework\TestCase;

final class PackageSettingRegistryTest extends TestCase
{
    public function testItReturnsSettingsOnlyForActivePackages(): void
    {
        $registry = new PackageSettingRegistry(
            [new StaticPackageSettingProvider([
                new PackageSettingDefinition(
                    'active-module',
                    'display.mode',
                    'Display mode',
                    'compact',
                    ConfigValueType::String,
                    options: ['compact', 'comfortable'],
                    validation: ['required' => true],
                    sortOrder: 20,
                ),
                new PackageSettingDefinition('inactive-module', 'display.mode', 'Display mode', 'compact'),
                new PackageSettingDefinition('active-module', 'feature.enabled', 'Feature enabled', true, ConfigValueType::Boolean, sortOrder: 10),
            ])],
            new StaticActivePackageProvider([
                new ExtensionPackage(
                    '10000000-0000-0000-0000-000000000601',
                    [PackageScope::Module],
                    'active-module',
                    'packages/active-module',
                    ExtensionPackageStatus::Active,
                    [
                        'display_name' => 'Active Module',
                        'description' => 'Adds configurable active module behavior.',
                    ],
                ),
            ]),
        );

        $definitions = $registry->definitions();

        self::assertSame(['feature.enabled', 'display.mode'], array_map(
            static fn (PackageSettingDefinition $definition): string => $definition->key(),
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
                'path' => '/admin/settings/packages/active-module',
            ],
        ], $registry->packagesWithDefinitions());
    }
}

final readonly class StaticPackageSettingProvider implements PackageSettingProviderInterface
{
    /**
     * @param list<PackageSettingDefinition> $definitions
     */
    public function __construct(private array $definitions)
    {
    }

    public function packageSettings(): array
    {
        return $this->definitions;
    }
}

final readonly class StaticActivePackageProvider implements ActivePackageProviderInterface
{
    /**
     * @param list<ExtensionPackage> $packages
     */
    public function __construct(private array $packages)
    {
    }

    public function packages(?PackageScope $scope = null): array
    {
        if (null === $scope) {
            return $this->packages;
        }

        return array_values(array_filter(
            $this->packages,
            static fn (ExtensionPackage $package): bool => $package->hasScope($scope),
        ));
    }

    public function package(string $packageName): ?ExtensionPackage
    {
        foreach ($this->packages as $package) {
            if ($package->packageName() === $packageName) {
                return $package;
            }
        }

        return null;
    }
}

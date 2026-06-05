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
use App\Core\Package\Settings\PackageSettings;
use App\Core\Package\Settings\PackageSettingsFormHandler;
use App\Entity\ExtensionPackage;
use App\Form\FormInputType;
use App\Form\FormSubmissionHandler;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PackageSettingsFormHandlerTest extends KernelTestCase
{
    protected function tearDown(): void
    {
        if (self::$booted) {
            self::getContainer()->get(Connection::class)->delete('package_setting_entry', ['package_name' => 'form-module']);
        }

        parent::tearDown();
    }

    public function testItPersistsTypedPackageSettingsFromSubmittedFormValues(): void
    {
        self::bootKernel();
        $settings = self::getContainer()->get(PackageSettings::class);
        $handler = new PackageSettingsFormHandler(
            new PackageSettingRegistry(
                [new FormHandlerPackageSettingProvider([
                    new PackageSettingDefinition('form-module', 'display.mode', 'Display mode', 'compact', ConfigValueType::String, options: ['compact', 'comfortable']),
                    new PackageSettingDefinition('form-module', 'feature.enabled', 'Feature enabled', false, ConfigValueType::Boolean, inputType: FormInputType::Checkbox),
                ])],
                new FormHandlerActivePackageProvider([
                    new ExtensionPackage(
                        '10000000-0000-7000-8000-000000000777',
                        [PackageScope::Module],
                        'form-module',
                        'packages/form-module',
                        ExtensionPackageStatus::Active,
                    ),
                ]),
            ),
            $settings,
            new FormSubmissionHandler(),
        );

        $result = $handler->submit('form-module', [
            'display.mode' => 'comfortable',
            'feature.enabled' => '1',
        ], 'test');

        self::assertTrue($result->isValid());
        self::assertSame('comfortable', $settings->get('form-module', 'display.mode'));
        self::assertTrue($settings->get('form-module', 'feature.enabled'));
    }
}

final readonly class FormHandlerPackageSettingProvider implements PackageSettingProviderInterface
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

final readonly class FormHandlerActivePackageProvider implements ActivePackageProviderInterface
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

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
use App\Core\Extension\Settings\ExtensionSettings;
use App\Core\Extension\Settings\ExtensionSettingsFormHandler;
use App\Entity\Extension;
use App\Form\FormInputType;
use App\Form\FormSubmissionHandler;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ExtensionSettingsFormHandlerTest extends KernelTestCase
{
    protected function tearDown(): void
    {
        if (self::$booted) {
            self::getContainer()->get(Connection::class)->delete('extension_setting_entry', ['extension_name' => 'form-module']);
        }

        parent::tearDown();
    }

    public function testItPersistsTypedExtensionSettingsFromSubmittedFormValues(): void
    {
        self::bootKernel();
        $settings = self::getContainer()->get(ExtensionSettings::class);
        $handler = new ExtensionSettingsFormHandler(
            new ExtensionSettingRegistry(
                [new FormHandlerExtensionSettingProvider([
                    new ExtensionSettingDefinition('form-module', 'display.mode', 'Display mode', 'compact', ConfigValueType::String, options: ['compact', 'comfortable']),
                    new ExtensionSettingDefinition('form-module', 'feature.enabled', 'Feature enabled', false, ConfigValueType::Boolean, inputType: FormInputType::Checkbox),
                ])],
                new FormHandlerActiveExtensionProvider([
                    new Extension(
                        '10000000-0000-7000-8000-000000000777',
                        [ExtensionScope::Module],
                        'form-module',
                        'extensions/form-module',
                        ExtensionStatus::Active,
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

    public function testItPreservesSensitiveExtensionSettingsWhenSubmittedProtectedPlaceholder(): void
    {
        self::bootKernel();
        $settings = self::getContainer()->get(ExtensionSettings::class);
        $settings->set('form-module', 'api.secret', 'stored-secret', ConfigValueType::String, 'test');
        $handler = new ExtensionSettingsFormHandler(
            new ExtensionSettingRegistry(
                [new FormHandlerExtensionSettingProvider([
                    new ExtensionSettingDefinition(
                        'form-module',
                        'api.secret',
                        'API secret',
                        '',
                        ConfigValueType::String,
                        inputType: FormInputType::Password,
                        metadata: ['sensitive' => true],
                    ),
                ])],
                new FormHandlerActiveExtensionProvider([
                    new Extension(
                        '10000000-0000-7000-8000-000000000778',
                        [ExtensionScope::Module],
                        'form-module',
                        'extensions/form-module',
                        ExtensionStatus::Active,
                    ),
                ]),
            ),
            $settings,
            new FormSubmissionHandler(),
        );

        $result = $handler->submit('form-module', [
            'api.secret' => '[protected]',
        ], 'test');

        self::assertTrue($result->isValid());
        self::assertSame('stored-secret', $settings->get('form-module', 'api.secret'));
    }
}

final readonly class FormHandlerExtensionSettingProvider implements ExtensionSettingProviderInterface
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

final readonly class FormHandlerActiveExtensionProvider implements ActiveExtensionProviderInterface
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

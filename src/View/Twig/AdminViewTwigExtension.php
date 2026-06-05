<?php

declare(strict_types=1);

namespace App\View\Twig;

use App\Backend\BackendActions;
use App\Core\Config\Config;
use App\Core\Config\Settings\CoreSettingsRegistry;
use App\Core\Package\PackageAdminOverview;
use App\Core\Package\Settings\PackageSettingRegistry;
use App\Core\Package\Settings\PackageSettings;
use App\Core\Package\ThemeAdminOverview;
use App\Form\FormBuilder;
use App\View\SystemPackageMetadataProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Throwable;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class AdminViewTwigExtension extends AbstractExtension
{
    public function __construct(
        private readonly Config $config,
        private readonly CoreSettingsRegistry $coreSettingsRegistry,
        private readonly FormBuilder $formBuilder,
        private readonly BackendActions $backendActions,
        private readonly PackageAdminOverview $packageAdminOverview,
        private readonly ThemeAdminOverview $themeAdminOverview,
        private readonly PackageSettings $packageSettings,
        private readonly PackageSettingRegistry $packageSettingRegistry,
        private readonly SystemPackageMetadataProvider $systemPackageMetadata,
        private readonly RequestStack $requestStack,
    ) {
    }

    /**
     * @return list<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('studio_core_settings_form', $this->coreSettingsForm(...)),
            new TwigFunction('studio_backend_actions', $this->backendActions(...)),
            new TwigFunction('studio_footer_copyright', $this->footerCopyright(...)),
            new TwigFunction('studio_extension_packages', $this->extensionPackages(...)),
            new TwigFunction('studio_themes', $this->themes(...)),
            new TwigFunction('studio_package_setting', $this->packageSetting(...)),
            new TwigFunction('studio_package_settings', $this->packageSettings(...)),
            new TwigFunction('studio_package_settings_form', $this->packageSettingsForm(...)),
            new TwigFunction('studio_package_setting_packages', $this->packageSettingPackages(...)),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function extensionPackages(): array
    {
        return $this->packageAdminOverview->packages();
    }

    /**
     * @param list<string> $ids
     *
     * @return list<array{id: string, label_key: string, variant: string}>
     */
    public function backendActions(array $ids = []): array
    {
        return $this->backendActions->definitions($ids);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function themes(): array
    {
        return $this->themeAdminOverview->sections();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function packageSettings(string $packageName): array
    {
        return $this->packageSettings->viewRows($packageName, $this->packageSettingRegistry);
    }

    public function packageSetting(string $packageName, string $key, mixed $default = null): mixed
    {
        return $this->packageSettings->get($packageName, $key, $default);
    }

    public function footerCopyright(string $area = 'frontend'): string
    {
        $default = $this->defaultFooterCopyright();

        if ('frontend' !== $area) {
            return $default;
        }

        try {
            $configured = $this->config->get('site.footer_copyright', '');
        } catch (Throwable) {
            return $default;
        }

        return is_string($configured) && '' !== trim($configured) ? trim($configured) : $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function coreSettingsForm(string $section): array
    {
        $definitions = $this->coreSettingsRegistry->definitions($section);
        $values = [];
        $request = $this->requestStack->getCurrentRequest();

        foreach ($definitions as $definition) {
            $values[$definition->key()] = $this->config->get($definition->key(), $definition->defaultValue());
        }

        $values = array_replace($values, $this->requestFormValues($request));
        $errors = $this->requestFormErrors($request);

        return $this->formBuilder->build(
            'admin-settings-'.$section,
            'admin.settings.'.$section.'.title',
            array_map(static fn ($definition) => $definition->formField(), $definitions),
            $values,
            $errors,
            $errors['__form'] ?? [],
        )->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function packageSettingsForm(string $packageName): array
    {
        $request = $this->requestStack->getCurrentRequest();
        $errors = $this->requestFormErrors($request);

        return $this->formBuilder->build(
            'package-settings-'.preg_replace('/[^a-z0-9_]+/', '_', strtolower($packageName)),
            $packageName,
            $this->packageSettings->formFields($packageName, $this->packageSettingRegistry),
            $this->requestFormValues($request),
            $errors,
            $errors['__form'] ?? [],
        )->toArray();
    }

    /**
     * @return list<array{package_name: string, label: string, description: string|null, path: string}>
     */
    public function packageSettingPackages(): array
    {
        $packages = [];

        foreach ($this->packageSettingRegistry->packagesWithDefinitions() as $packageName => $metadata) {
            $packages[] = [
                'package_name' => $packageName,
                'label' => $metadata['label'],
                'description' => $metadata['description'],
                'path' => $metadata['path'],
            ];
        }

        return $packages;
    }

    private function defaultFooterCopyright(): string
    {
        $metadata = $this->systemPackageMetadata->metadata();
        $name = trim((string) ($metadata['name'] ?? 'Studio'));
        $version = trim((string) ($metadata['version'] ?? ''));
        $homepage = trim((string) ($metadata['homepage'] ?? ''));
        $label = '' !== $name ? $name : 'Studio';
        $linkedName = '' !== $homepage ? sprintf('[%s](%s)', $label, $homepage) : $label;

        return trim(sprintf('Powered by %s %s', $linkedName, $version));
    }

    /**
     * @return array<string, mixed>
     */
    private function requestFormValues(?Request $request): array
    {
        $values = $request?->attributes->get('_studio_form_values');

        return is_array($values) ? $values : [];
    }

    /**
     * @return array<string, list<string>>
     */
    private function requestFormErrors(?Request $request): array
    {
        $errors = $request?->attributes->get('_studio_form_errors');

        return is_array($errors) ? $errors : [];
    }
}

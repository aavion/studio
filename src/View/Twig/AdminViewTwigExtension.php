<?php

declare(strict_types=1);

namespace App\View\Twig;

use App\Backend\BackendActions;
use App\Core\Access\AccessActor;
use App\Core\AdminAcl\AdminFeatureAccessPolicy;
use App\Core\AdminAcl\AdminPermissionState;
use App\Core\Config\Config;
use App\Core\Extension\ExtensionAdminOverview;
use App\Core\Extension\ThemeAdminOverview;
use App\Entity\UserAccount;
use App\View\SystemExtensionMetadataProvider;
use Symfony\Bundle\SecurityBundle\Security;
use Throwable;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class AdminViewTwigExtension extends AbstractExtension
{
    public function __construct(
        private readonly Config $config,
        private readonly AdminSettingsFormViewFactory $settingsForms,
        private readonly BackendActions $backendActions,
        private readonly ExtensionAdminOverview $extensionAdminOverview,
        private readonly ThemeAdminOverview $themeAdminOverview,
        private readonly SystemExtensionMetadataProvider $systemExtensionMetadata,
        private readonly Security $security,
        private readonly ?AdminFeatureAccessPolicy $adminAcl = null,
    ) {
    }

    /**
     * @return list<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('core_settings_form', $this->coreSettingsForm(...)),
            new TwigFunction('backend_actions', $this->backendActions(...)),
            new TwigFunction('footer_copyright', $this->footerCopyright(...)),
            new TwigFunction('extensions', $this->extensions(...)),
            new TwigFunction('themes', $this->themes(...)),
            new TwigFunction('extension_setting', $this->extensionSetting(...)),
            new TwigFunction('extension_settings', $this->extensionSettings(...)),
            new TwigFunction('extension_settings_form', $this->extensionSettingsForm(...)),
            new TwigFunction('extension_setting_extensions', $this->extensionSettingExtensions(...)),
            new TwigFunction('admin_feature_state', $this->adminFeatureState(...)),
            new TwigFunction('admin_feature_visible', $this->adminFeatureVisible(...)),
            new TwigFunction('admin_feature_mutable', $this->adminFeatureMutable(...)),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function extensions(): array
    {
        return $this->extensionAdminOverview->extensions();
    }

    /**
     * @param list<string> $ids
     *
     * @return list<array{id: string, label_key: string, variant: string}>
     */
    public function backendActions(array $ids = []): array
    {
        return $this->backendActions->definitions($ids, $this->actor());
    }

    public function adminFeatureState(string $feature): string
    {
        if (null === $this->adminAcl) {
            return AdminPermissionState::Mutable->value;
        }

        return $this->adminAcl->state($feature, $this->actor())->value;
    }

    public function adminFeatureVisible(string $feature): bool
    {
        if (null === $this->adminAcl) {
            return true;
        }

        return $this->adminAcl->isVisible($feature, $this->actor());
    }

    public function adminFeatureMutable(string $feature): bool
    {
        if (null === $this->adminAcl) {
            return true;
        }

        return $this->adminAcl->isMutable($feature, $this->actor());
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
    public function extensionSettings(string $extensionName): array
    {
        return $this->settingsForms->extensionSettings($extensionName);
    }

    public function extensionSetting(string $extensionName, string $key, mixed $default = null): mixed
    {
        return $this->settingsForms->extensionSetting($extensionName, $key, $default);
    }

    public function footerCopyright(string $area = 'frontend'): string
    {
        $default = $this->defaultFooterCopyright();

        if ('frontend' !== $area) {
            return $default;
        }

        try {
            $configured = $this->config->get('site.footer_copyright') ?? '';
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
        return $this->settingsForms->coreSettingsForm($section);
    }

    /**
     * @return array<string, mixed>
     */
    public function extensionSettingsForm(string $extensionName): array
    {
        return $this->settingsForms->extensionSettingsForm($extensionName);
    }

    /**
     * @return list<array{extension_name: string, label: string, description: string|null, path: string}>
     */
    public function extensionSettingExtensions(): array
    {
        return $this->settingsForms->extensionSettingExtensions();
    }

    private function defaultFooterCopyright(): string
    {
        $metadata = $this->systemExtensionMetadata->metadata();
        $name = trim((string) ($metadata['name'] ?? 'Studio'));
        $version = trim((string) ($metadata['version'] ?? ''));
        $homepage = trim((string) ($metadata['homepage'] ?? ''));
        $label = '' !== $name ? $name : 'Studio';
        $linkedName = '' !== $homepage ? sprintf('[%s](%s)', $label, $homepage) : $label;

        return trim(sprintf('Powered by %s %s', $linkedName, $version));
    }

    private function actor(): AccessActor
    {
        $user = $this->security->getUser();

        return $user instanceof UserAccount ? AccessActor::fromUserAccount($user) : AccessActor::anonymous();
    }

}

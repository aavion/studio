<?php

declare(strict_types=1);

namespace App\View\Twig;

use App\Backend\BackendActions;
use App\Core\Access\AccessActor;
use App\Core\Config\Config;
use App\Core\Config\Settings\CoreSettingsRegistry;
use App\Core\Event\EventHookDescriptor;
use App\Core\Event\PublicEventHookRegistry;
use App\Core\Log\AccessRequestMetadata;
use App\Core\Package\PackageAdminOverview;
use App\Core\Package\Settings\PackageSettingRegistry;
use App\Core\Package\Settings\PackageSettings;
use App\Core\Statistics\VisitorIdGenerator;
use App\Core\Package\ThemeAdminOverview;
use App\Debug\StudioDebugCollector;
use App\Entity\UserAccount;
use App\Form\FormBuilder;
use App\Navigation\NavigationBuilder;
use App\View\MarkdownRenderer;
use App\View\PackageMacroRegistry;
use App\View\ViewContextProvider;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\Markup;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class ViewTwigExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(
        private readonly ViewContextProvider $contextProvider,
        private readonly PackageMacroRegistry $macroRegistry,
        private readonly MarkdownRenderer $markdownRenderer,
        private readonly PublicEventHookRegistry $eventHookRegistry,
        private readonly NavigationBuilder $navigationBuilder,
        private readonly Config $config,
        private readonly CoreSettingsRegistry $coreSettingsRegistry,
        private readonly FormBuilder $formBuilder,
        private readonly BackendActions $backendActions,
        private readonly PackageAdminOverview $packageAdminOverview,
        private readonly ThemeAdminOverview $themeAdminOverview,
        private readonly PackageSettings $packageSettings,
        private readonly PackageSettingRegistry $packageSettingRegistry,
        private readonly StudioDebugCollector $debugCollector,
        private readonly Security $security,
        private readonly RequestStack $requestStack,
        private readonly AccessRequestMetadata $accessRequestMetadata,
        private readonly VisitorIdGenerator $visitorIdGenerator,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getGlobals(): array
    {
        return [
            'studio_view' => $this->contextProvider,
        ];
    }

    /**
     * @return list<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('studio_view_context', $this->contextProvider->context(...)),
            new TwigFunction('studio_macro_namespaces', $this->macroRegistry->namespaces(...)),
            new TwigFunction('studio_macro_template', $this->macroRegistry->template(...)),
            new TwigFunction('studio_event_hooks', $this->eventHooks(...)),
            new TwigFunction('studio_html_attributes', $this->htmlAttributes(...), ['is_safe' => ['html']]),
            new TwigFunction('studio_navigation', $this->navigation(...)),
            new TwigFunction('studio_core_settings_form', $this->coreSettingsForm(...)),
            new TwigFunction('studio_backend_actions', $this->backendActions(...)),
            new TwigFunction('studio_extension_packages', $this->extensionPackages(...)),
            new TwigFunction('studio_themes', $this->themes(...)),
            new TwigFunction('studio_package_setting', $this->packageSetting(...)),
            new TwigFunction('studio_package_settings', $this->packageSettings(...)),
            new TwigFunction('studio_package_settings_form', $this->packageSettingsForm(...)),
            new TwigFunction('studio_package_setting_packages', $this->packageSettingPackages(...)),
            new TwigFunction('studio_debug_info', $this->debugInfo(...)),
            new TwigFunction('studio_request_trace', $this->requestTrace(...)),
        ];
    }

    /**
     * @return list<TwigFilter>
     */
    public function getFilters(): array
    {
        return [
            new TwigFilter('studio_markdown', $this->markdownRenderer->render(...), ['is_safe' => ['html']]),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function eventHooks(): array
    {
        return array_map(
            static fn (EventHookDescriptor $hook): array => $hook->toArray(),
            $this->eventHookRegistry->hooks(),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function navigation(
        string $identifier = 'main',
        string $language = 'en',
        int $maxDepth = 3,
        int $startLevel = 1,
        ?string $rootUid = null,
    ): array
    {
        $request = $this->requestStack->getCurrentRequest();
        $activeRoute = $request?->attributes->get('_route');

        return $this->navigationBuilder->build(
            $identifier,
            $language,
            $maxDepth,
            $startLevel,
            $rootUid,
            $this->actor(),
            $request?->getPathInfo(),
            is_string($activeRoute) ? $activeRoute : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function debugInfo(): array
    {
        return $this->debugCollector->summary();
    }

    /**
     * @return array{request_id: string|null, visitor_id: string|null, requested_path: string|null, resolved_route: string|null}
     */
    public function requestTrace(): array
    {
        $request = $this->requestStack->getCurrentRequest();

        if (null === $request) {
            return [
                'request_id' => null,
                'visitor_id' => null,
                'requested_path' => null,
                'resolved_route' => null,
            ];
        }

        return $this->accessRequestMetadata->trace($request, $this->visitorIdGenerator->generate($request));
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

    /**
     * @param array<string, mixed> $attributes
     */
    public function htmlAttributes(array $attributes): Markup
    {
        $rendered = [];

        foreach ($attributes as $name => $value) {
            if (!is_string($name) || !$this->isSafeAttributeName($name) || false === $value || null === $value) {
                continue;
            }

            $escapedName = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

            if (true === $value) {
                $rendered[] = $escapedName;

                continue;
            }

            if (!is_scalar($value)) {
                continue;
            }

            $rendered[] = sprintf(
                '%s="%s"',
                $escapedName,
                htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            );
        }

        return new Markup(implode(' ', $rendered), 'UTF-8');
    }

    private function actor(): AccessActor
    {
        $user = $this->security->getUser();

        return $user instanceof UserAccount ? AccessActor::fromUserAccount($user) : AccessActor::anonymous();
    }

    /**
     * @return array<string, mixed>
     */
    private function requestFormValues(?\Symfony\Component\HttpFoundation\Request $request): array
    {
        $values = $request?->attributes->get('_studio_form_values');

        return is_array($values) ? $values : [];
    }

    /**
     * @return array<string, list<string>>
     */
    private function requestFormErrors(?\Symfony\Component\HttpFoundation\Request $request): array
    {
        $errors = $request?->attributes->get('_studio_form_errors');

        return is_array($errors) ? $errors : [];
    }

    private function isSafeAttributeName(string $name): bool
    {
        if (!preg_match('/^[a-z][a-z0-9:_-]*$/i', $name)) {
            return false;
        }

        if (str_starts_with(strtolower($name), 'on')) {
            return false;
        }

        return str_starts_with($name, 'data-')
            || str_starts_with($name, 'aria-')
            || in_array($name, [
                'autocomplete',
                'class',
                'download',
                'id',
                'max',
                'maxlength',
                'min',
                'minlength',
                'pattern',
                'placeholder',
                'rel',
                'step',
                'target',
                'title',
            ], true);
    }
}

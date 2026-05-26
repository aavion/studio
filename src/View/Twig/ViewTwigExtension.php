<?php

declare(strict_types=1);

namespace App\View\Twig;

use App\Core\Access\AccessActor;
use App\Core\Event\EventHookDescriptor;
use App\Core\Event\PublicEventHookRegistry;
use App\Core\Package\Settings\PackageSettingRegistry;
use App\Core\Package\Settings\PackageSettings;
use App\Debug\StudioDebugCollector;
use App\Entity\UserAccount;
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
        private readonly PackageSettings $packageSettings,
        private readonly PackageSettingRegistry $packageSettingRegistry,
        private readonly StudioDebugCollector $debugCollector,
        private readonly Security $security,
        private readonly RequestStack $requestStack,
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
            new TwigFunction('studio_package_settings', $this->packageSettings(...)),
            new TwigFunction('studio_package_setting_packages', $this->packageSettingPackages(...)),
            new TwigFunction('studio_debug_info', $this->debugInfo(...)),
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
     * @return list<array<string, mixed>>
     */
    public function packageSettings(string $packageName): array
    {
        return $this->packageSettings->viewRows($packageName, $this->packageSettingRegistry);
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
            || in_array($name, ['download', 'id', 'rel', 'target', 'title'], true);
    }
}

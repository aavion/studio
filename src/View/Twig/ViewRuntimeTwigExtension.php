<?php

declare(strict_types=1);

namespace App\View\Twig;

use App\Core\Access\AccessActor;
use App\Core\Log\AccessRequestMetadata;
use App\Core\Statistics\VisitorIdGenerator;
use App\Debug\SystemDebugCollector;
use App\Entity\UserAccount;
use App\Navigation\NavigationBuilder;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\Markup;
use Twig\TwigFunction;

final class ViewRuntimeTwigExtension extends AbstractExtension
{
    public function __construct(
        private readonly NavigationBuilder $navigationBuilder,
        private readonly SystemDebugCollector $debugCollector,
        private readonly Security $security,
        private readonly RequestStack $requestStack,
        private readonly AccessRequestMetadata $accessRequestMetadata,
        private readonly VisitorIdGenerator $visitorIdGenerator,
    ) {
    }

    /**
     * @return list<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('studio_html_attributes', $this->htmlAttributes(...), ['is_safe' => ['html']]),
            new TwigFunction('studio_navigation', $this->navigation(...)),
            new TwigFunction('studio_debug_info', $this->debugInfo(...)),
            new TwigFunction('studio_request_trace', $this->requestTrace(...)),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function navigation(
        string $identifier = 'main',
        string $language = '',
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

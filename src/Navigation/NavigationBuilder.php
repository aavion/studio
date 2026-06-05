<?php

declare(strict_types=1);

namespace App\Navigation;

use App\Core\Access\AccessActor;
use App\Core\Event\PublicEventDispatcher;
use App\Navigation\Event\NavigationBuilderEvent;

final readonly class NavigationBuilder
{
    public function __construct(
        private NavigationItemRepository $repository,
        private PublicEventDispatcher $eventDispatcher,
        private NavigationAccessFilter $accessFilter,
        private NavigationUrlResolver $urlResolver,
        private NavigationTreeBuilder $treeBuilder,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function build(
        string $identifier = 'main',
        string $language = '',
        int $maxDepth = 3,
        int $startLevel = 1,
        ?string $rootUid = null,
        ?AccessActor $actor = null,
        ?string $activeUrl = null,
        ?string $activeRoute = null,
    ): array {
        $maxDepth = max(1, $maxDepth);
        $startLevel = max(1, $startLevel);
        $items = $this->collectItems($identifier, $language, $maxDepth, $startLevel, $rootUid, $actor);
        $tree = $this->treeBuilder->buildTree($items);
        $tree = $this->treeBuilder->markActive($tree, $activeUrl, $activeRoute);
        $slice = $this->treeBuilder->slice($tree, $maxDepth, $startLevel, $rootUid);
        $arrayLevel = null === $rootUid ? $startLevel : 1;

        return array_map(
            static fn (NavigationItem $item): array => $item->toArray($arrayLevel),
            $slice,
        );
    }

    /**
     * @return list<NavigationItem>
     */
    public function collectItems(
        string $identifier = 'main',
        string $language = '',
        int $maxDepth = 3,
        int $startLevel = 1,
        ?string $rootUid = null,
        ?AccessActor $actor = null,
    ): array
    {
        $maxDepth = max(1, $maxDepth);
        $startLevel = max(1, $startLevel);
        $items = $this->repository->items($identifier, $language);
        $event = new NavigationBuilderEvent($identifier, $language, $maxDepth, $startLevel, $rootUid, $items);
        $result = $this->eventDispatcher->dispatch($event, [
            'operation' => 'navigation_builder',
            'identifier' => $identifier,
            'language' => $language,
            'max_depth' => $maxDepth,
            'start_level' => $startLevel,
            'root_uid' => $rootUid,
        ]);

        $collectedItems = $result->isSuccess() ? $event->items() : $items;

        return $this->urlResolver->resolve($this->accessFilter->filter($collectedItems, $actor));
    }
}

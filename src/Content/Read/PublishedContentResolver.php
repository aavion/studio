<?php

declare(strict_types=1);

namespace App\Content\Read;

use App\Content\ContentStatus;
use App\Content\ContentVisibility;
use App\Core\Access\AccessActor;
use App\Core\Access\AccessCapability;
use App\Core\Access\AccessResolver;
use App\Core\Access\AccessRule;
use App\Entity\ContentItem;
use App\Repository\ContentFieldValueRepository;
use App\Repository\ContentItemRepository;

final readonly class PublishedContentResolver
{
    public function __construct(
        private ContentItemRepository $contentItems,
        private ContentFieldValueRepository $fieldValues,
        private ContentReadContextResolver $contextResolver = new ContentReadContextResolver(),
        private AccessResolver $accessResolver = new AccessResolver(),
    ) {
    }

    public function findBySlug(string $slug, AccessActor $actor, string $language = 'en', string $variant = 'default'): ?PublishedContentView
    {
        return $this->resolveBySlug($slug, $actor, $language, $variant)->view();
    }

    public function resolveBySlug(string $slug, AccessActor $actor, string $language = 'en', string $variant = 'default'): PublishedContentResolveResult
    {
        return $this->resolve($this->contentItems->findOneContentBySlug($slug), $actor, $language, $variant);
    }

    public function findByPath(string $path, AccessActor $actor, string $language = 'en', string $variant = 'default'): ?PublishedContentView
    {
        return $this->resolveByPath($path, $actor, $language, $variant)->view();
    }

    public function resolveByPath(string $path, AccessActor $actor, string $language = 'en', string $variant = 'default'): PublishedContentResolveResult
    {
        $path = $this->normalizePath($path);
        $content = $this->contentItems->findOneContentByCustomUrl($path);

        if (null === $content) {
            $content = $this->findByHierarchyPath($path);
        }

        return $this->resolve($content, $actor, $language, $variant);
    }

    private function findByHierarchyPath(string $path): ?ContentItem
    {
        $parentUid = null;
        $content = null;

        foreach ($this->pathSegments($path) as $segment) {
            $content = $this->contentItems->findOneContentBySlugAndParentUid($segment, $parentUid);

            if (null === $content) {
                return null;
            }

            $parentUid = $content->uid();
        }

        return $content;
    }

    private function resolve(?ContentItem $content, AccessActor $actor, string $language, string $variant): PublishedContentResolveResult
    {
        if (null === $content) {
            return PublishedContentResolveResult::notFound();
        }

        if (ContentStatus::Published !== $content->status()) {
            return PublishedContentResolveResult::notPublished();
        }

        if (ContentVisibility::Public !== $content->visibility()) {
            return PublishedContentResolveResult::notPublic();
        }

        $context = $this->contextResolver->resolve($content, $language, $variant);
        $revision = $content->activeRevision();

        if (null === $context || null === $revision) {
            return PublishedContentResolveResult::contextUnavailable();
        }

        if (!$this->aclRestrictionsAllow($content, $actor)) {
            return PublishedContentResolveResult::denied();
        }

        $decision = $this->accessResolver->decide(
            $actor,
            AccessCapability::View,
            AccessRule::from($content->viewMinLevel(), $content->viewGroupIdentifiers()),
        );

        if (!$decision->isGranted()) {
            return PublishedContentResolveResult::denied();
        }

        return PublishedContentResolveResult::resolved(
            new PublishedContentView(
                $content,
                $revision,
                $context,
                $this->fieldsFor($revision->uid(), $context),
                $decision,
            ),
        );
    }

    private function aclRestrictionsAllow(ContentItem $content, AccessActor $actor): bool
    {
        $restrictions = $content->aclRestrictions();

        if ([] === $restrictions) {
            return true;
        }

        foreach ($restrictions as $identifier) {
            if ($actor->hasGroupIdentifier($identifier)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function fieldsFor(string $revisionUid, ContentReadContext $context): array
    {
        $fields = [];

        foreach ($this->fieldValues->findForRevisionContext($revisionUid, $context->language(), $context->variant()) as $fieldValue) {
            $fields[$fieldValue->fieldIdentifier()] = $fieldValue->fieldContent();
        }

        return $fields;
    }

    private function normalizePath(string $path): string
    {
        if ('/' === $path) {
            return '/';
        }

        return '/'.trim($path, '/');
    }

    /**
     * @return list<string>
     */
    private function pathSegments(string $path): array
    {
        return array_values(array_filter(
            explode('/', trim($path, '/')),
            static fn (string $segment): bool => '' !== $segment,
        ));
    }
}

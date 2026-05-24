<?php

declare(strict_types=1);

namespace App\Content\Read;

use App\Content\ContentStatus;
use App\Content\ContentVisibility;
use App\Core\Access\AccessActor;
use App\Core\Access\AccessCapability;
use App\Core\Access\AccessResolver;
use App\Core\Access\AccessRule;
use App\Content\Routing\ContentPathLookup;
use App\Content\Routing\ContentRoutePath;
use App\Core\Message\Message;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use App\Entity\ContentItem;
use App\Repository\ContentFieldValueRepository;
use App\Repository\ContentItemRepository;

final class PublishedContentResolver
{
    private ContentPathLookup $pathLookup;

    public function __construct(
        private ContentItemRepository $contentItems,
        private ContentFieldValueRepository $fieldValues,
        private ContentReadContextResolver $contextResolver = new ContentReadContextResolver(),
        private AccessResolver $accessResolver = new AccessResolver(),
        ?ContentPathLookup $pathLookup = null,
    ) {
        $this->pathLookup = $pathLookup ?? new ContentPathLookup($contentItems);
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
        $routePath = ContentRoutePath::fromPath($path);
        $variant = $routePath->variant() ?? $variant;

        return $this->resolve($this->pathLookup->findByPath($path), $actor, $language, $variant);
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
            $this->messagesForContext($content, $context),
        );
    }

    /**
     * @return list<Message>
     */
    private function messagesForContext(ContentItem $content, ContentReadContext $context): array
    {
        $messages = [];

        if ($context->languageFallbackUsed()) {
            $messages[] = Message::warning(MessageCode::CONTENT_LANGUAGE_FALLBACK, MessageKey::CONTENT_LANGUAGE_FALLBACK, [
                '%requested_language%' => $context->requestedLanguage(),
                '%resolved_language%' => $context->language(),
            ], [
                'content_uid' => $content->uid(),
                'slug' => $content->slug(),
                'requested_language' => $context->requestedLanguage(),
                'resolved_language' => $context->language(),
            ]);
        }

        if ($context->variantFallbackUsed()) {
            $messages[] = Message::warning(MessageCode::CONTENT_VARIANT_FALLBACK, MessageKey::CONTENT_VARIANT_FALLBACK, [
                '%requested_variant%' => $context->requestedVariant(),
                '%resolved_variant%' => $context->variant(),
            ], [
                'content_uid' => $content->uid(),
                'slug' => $content->slug(),
                'requested_variant' => $context->requestedVariant(),
                'resolved_variant' => $context->variant(),
            ]);
        }

        return $messages;
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
}

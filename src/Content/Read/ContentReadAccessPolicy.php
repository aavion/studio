<?php

declare(strict_types=1);

namespace App\Content\Read;

use App\Core\Access\AccessActor;
use App\Core\Access\AccessCapability;
use App\Core\Access\AccessDecision;
use App\Core\Access\AccessResolver;
use App\Core\Access\AccessRule;
use App\Entity\ContentItem;

final readonly class ContentReadAccessPolicy
{
    public function __construct(private AccessResolver $accessResolver)
    {
    }

    public function allowsView(ContentItem $content, AccessActor $actor): bool
    {
        return $this->allowsAclRestrictions($content, $actor)
            && $this->viewDecision($content, $actor)->isGranted();
    }

    public function viewDecision(ContentItem $content, AccessActor $actor): AccessDecision
    {
        return $this->accessResolver->decide(
            $actor,
            AccessCapability::View,
            AccessRule::from($content->viewMinLevel(), $content->viewGroupIdentifiers()),
        );
    }

    public function allowsAclRestrictions(ContentItem $content, AccessActor $actor): bool
    {
        if ([] === $content->aclRestrictions()) {
            return true;
        }

        foreach ($content->aclRestrictions() as $identifier) {
            if ($actor->hasGroupIdentifier($identifier)) {
                return true;
            }
        }

        return false;
    }
}

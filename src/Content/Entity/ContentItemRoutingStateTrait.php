<?php

declare(strict_types=1);

namespace App\Content\Entity;

use App\Content\Routing\ContentSlug;
use App\Content\Routing\ContentSystemRoute;
use Doctrine\ORM\Mapping as ORM;

trait ContentItemRoutingStateTrait
{
    #[ORM\Column(length: 160)]
    private string $slug;

    #[ORM\Column(length: 36, options: ['default' => ContentSystemRoute::ROOT_PARENT_UID])]
    private string $parentUid = ContentSystemRoute::ROOT_PARENT_UID;

    #[ORM\Column]
    private int $sortOrder = 0;

    #[ORM\Column(length: 1024, nullable: true)]
    private ?string $customUrl = null;

    #[ORM\Column(length: 1024, nullable: true)]
    private ?string $redirectTarget = null;

    public function slug(): string
    {
        return $this->slug;
    }

    public function rename(string $slug): void
    {
        $this->slug = ContentSlug::fromString($slug)->value();
    }

    public function parentUid(): string
    {
        return $this->parentUid;
    }

    public function moveTo(?string $parentUid, int $sortOrder = 0): void
    {
        $this->parentUid = ContentItemInput::parentUid($parentUid ?? ContentSystemRoute::ROOT_PARENT_UID);
        $this->sortOrder = $sortOrder;
    }

    public function sortOrder(): int
    {
        return $this->sortOrder;
    }

    public function customUrl(): ?string
    {
        return $this->customUrl;
    }

    public function setCustomUrl(?string $customUrl): void
    {
        $this->customUrl = $customUrl;
    }

    public function redirectTarget(): ?string
    {
        return $this->redirectTarget;
    }

    public function redirectRoute(): ?string
    {
        return $this->redirectTarget;
    }

    public function setRedirectTarget(?string $redirectTarget): void
    {
        $this->redirectTarget = $redirectTarget;
    }

    public function setRedirectRoute(?string $redirectRoute): void
    {
        $this->redirectTarget = $redirectRoute;
    }
}

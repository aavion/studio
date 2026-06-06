<?php

declare(strict_types=1);

namespace App\Content\Entity;

use App\Content\ContentVisibility;
use App\Core\Access\AccessLevel;
use Doctrine\ORM\Mapping as ORM;

trait ContentItemAccessStateTrait
{
    #[ORM\Column(enumType: ContentVisibility::class)]
    private ContentVisibility $visibility = ContentVisibility::Public;

    /**
     * @var list<string>
     */
    #[ORM\Column(type: 'json')]
    private array $aclRestrictions = [];

    #[ORM\Column(nullable: true)]
    private ?int $viewMinLevel = null;

    /**
     * @var list<string>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $viewGroupIdentifiers = null;

    #[ORM\Column(nullable: true)]
    private ?int $editMinLevel = null;

    /**
     * @var list<string>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $editGroupIdentifiers = null;

    #[ORM\Column(nullable: true)]
    private ?int $manageMinLevel = null;

    /**
     * @var list<string>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $manageGroupIdentifiers = null;

    public function visibility(): ContentVisibility
    {
        return $this->visibility;
    }

    public function setVisibility(ContentVisibility $visibility): void
    {
        $this->visibility = $visibility;
    }

    /**
     * @return list<string>
     */
    public function aclRestrictions(): array
    {
        return $this->aclRestrictions;
    }

    /**
     * @param list<string> $aclRestrictions
     */
    public function setAclRestrictions(array $aclRestrictions): void
    {
        $this->aclRestrictions = ContentItemInput::stringList($aclRestrictions, 'ACL restrictions');
    }

    /**
     * @param list<string>|null $groupIdentifiers
     */
    public function setViewRule(?int $minLevel, ?array $groupIdentifiers = null): void
    {
        $this->viewMinLevel = AccessLevel::assert($minLevel);
        $this->viewGroupIdentifiers = ContentItemInput::optionalAclGroupIdentifiers($groupIdentifiers);
    }

    public function viewMinLevel(): ?int
    {
        return $this->viewMinLevel;
    }

    /**
     * @return list<string>|null
     */
    public function viewGroupIdentifiers(): ?array
    {
        return $this->viewGroupIdentifiers;
    }

    /**
     * @param list<string>|null $groupIdentifiers
     */
    public function setEditRule(?int $minLevel, ?array $groupIdentifiers = null): void
    {
        $this->editMinLevel = AccessLevel::assert($minLevel);
        $this->editGroupIdentifiers = ContentItemInput::optionalAclGroupIdentifiers($groupIdentifiers);
    }

    public function editMinLevel(): ?int
    {
        return $this->editMinLevel;
    }

    /**
     * @return list<string>|null
     */
    public function editGroupIdentifiers(): ?array
    {
        return $this->editGroupIdentifiers;
    }

    /**
     * @param list<string>|null $groupIdentifiers
     */
    public function setManageRule(?int $minLevel, ?array $groupIdentifiers = null): void
    {
        $this->manageMinLevel = AccessLevel::assert($minLevel);
        $this->manageGroupIdentifiers = ContentItemInput::optionalAclGroupIdentifiers($groupIdentifiers);
    }

    public function manageMinLevel(): ?int
    {
        return $this->manageMinLevel;
    }

    /**
     * @return list<string>|null
     */
    public function manageGroupIdentifiers(): ?array
    {
        return $this->manageGroupIdentifiers;
    }
}

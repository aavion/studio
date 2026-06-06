<?php

declare(strict_types=1);

namespace App\Entity;

use App\Core\Access\AccessLevel;
use App\Core\Access\AccessRule;
use App\Core\Message\MessageException;
use App\Core\Validation\Uid;
use App\Navigation\NavigationMessageKey;
use App\Navigation\NavigationTargetType;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'site_menu_item')]
#[ORM\Index(name: 'idx_site_menu_item_menu_parent_sort', columns: ['menu_uid', 'parent_uid', 'sort_order'])]
#[ORM\Index(name: 'idx_site_menu_item_target', columns: ['target_type', 'target_value'])]
class SiteMenuItem
{
    private const MAX_TARGET_VALUE_LENGTH = 512;

    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $uid;

    #[ORM\ManyToOne(targetEntity: SiteMenu::class, inversedBy: 'items')]
    #[ORM\JoinColumn(name: 'menu_uid', referencedColumnName: 'uid', nullable: false, onDelete: 'CASCADE')]
    private SiteMenu $menu;

    #[ORM\Column(length: 36, nullable: true)]
    private ?string $parentUid = null;

    #[ORM\Column]
    private int $sortOrder = 0;

    /**
     * @var array<string, string>
     */
    #[ORM\Column(type: 'json')]
    private array $labels;

    #[ORM\Column(length: 40)]
    private string $targetType;

    #[ORM\Column(length: 512)]
    private string $targetValue;

    #[ORM\Column(nullable: true)]
    private ?int $viewMinLevel = null;

    /**
     * @var list<string>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $viewGroupIdentifiers = null;

    /**
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json')]
    private array $metadata = [];

    /**
     * @param array<string, string> $labels
     * @param list<string>|null $viewGroupIdentifiers
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        string $uid,
        SiteMenu $menu,
        array $labels,
        string $targetType,
        string $targetValue,
        ?string $parentUid = null,
        int $sortOrder = 0,
        ?int $viewMinLevel = null,
        ?array $viewGroupIdentifiers = null,
        array $metadata = [],
    ) {
        $this->uid = Uid::assert($uid, 'Site menu item UID');
        $this->menu = $menu;
        $this->labels = $labels;
        $this->targetType = self::assertTargetType($targetType);
        $this->targetValue = self::assertTargetValue($targetValue);
        $this->parentUid = null === $parentUid ? null : Uid::assert($parentUid, 'Parent site menu item UID');
        $this->sortOrder = $sortOrder;
        $this->setViewRule($viewMinLevel, $viewGroupIdentifiers);
        $this->metadata = $metadata;
    }

    public function uid(): string
    {
        return $this->uid;
    }

    public function attachTo(SiteMenu $menu): void
    {
        $this->menu = $menu;
    }

    public function viewMinLevel(): ?int
    {
        return $this->viewMinLevel;
    }

    public function targetType(): string
    {
        return $this->targetType;
    }

    public function targetValue(): string
    {
        return $this->targetValue;
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
    public function setViewRule(?int $minLevel, ?array $groupIdentifiers = null): void
    {
        $this->viewMinLevel = AccessLevel::assert($minLevel);
        $this->viewGroupIdentifiers = AccessRule::normalizeGroupIdentifiersOrNull($groupIdentifiers);
    }

    private static function assertTargetType(string $targetType): string
    {
        if (!NavigationTargetType::isSupported($targetType)) {
            throw MessageException::invalidArgument(NavigationMessageKey::MENU_TARGET_TYPE_INVALID, [
                '%target_type%' => $targetType,
            ]);
        }

        return $targetType;
    }

    private static function assertTargetValue(string $targetValue): string
    {
        $targetValue = trim($targetValue);

        if (
            '' === $targetValue
            || strlen($targetValue) > self::MAX_TARGET_VALUE_LENGTH
            || 1 === preg_match('/[\x00-\x1F\x7F]/', $targetValue)
        ) {
            throw MessageException::invalidArgument(NavigationMessageKey::MENU_TARGET_VALUE_INVALID);
        }

        return $targetValue;
    }
}

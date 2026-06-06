<?php

declare(strict_types=1);

namespace App\Entity;

use App\Core\Validation\Identifier;
use App\Core\Validation\Uid;
use App\Navigation\NavigationMessageKey;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'site_menu')]
#[ORM\UniqueConstraint(name: 'uniq_site_menu_identifier', columns: ['identifier'])]
class SiteMenu
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $uid;

    #[ORM\Column(length: 80)]
    private string $identifier;

    /**
     * @var array<string, string>
     */
    #[ORM\Column(type: 'json')]
    private array $labels;

    #[ORM\Column]
    private bool $active = true;

    /**
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json')]
    private array $metadata = [];

    /**
     * @var Collection<int, SiteMenuItem>
     */
    #[ORM\OneToMany(mappedBy: 'menu', targetEntity: SiteMenuItem::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $items;

    /**
     * @param array<string, string> $labels
     * @param array<string, mixed> $metadata
     */
    public function __construct(string $uid, string $identifier, array $labels, array $metadata = [])
    {
        $this->uid = Uid::assert($uid, 'Site menu UID');
        $this->identifier = Identifier::assertSnakeCase($identifier, NavigationMessageKey::MENU_IDENTIFIER_INVALID, '%identifier%');
        $this->labels = $labels;
        $this->metadata = $metadata;
        $this->items = new ArrayCollection();
    }

    public function uid(): string
    {
        return $this->uid;
    }

    public function identifier(): string
    {
        return $this->identifier;
    }

    public function active(): bool
    {
        return $this->active;
    }

    public function addItem(SiteMenuItem $item): void
    {
        if (!$this->items->contains($item)) {
            $this->items->add($item);
            $item->attachTo($this);
        }
    }
}

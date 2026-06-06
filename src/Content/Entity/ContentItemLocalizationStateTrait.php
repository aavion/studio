<?php

declare(strict_types=1);

namespace App\Content\Entity;

use Doctrine\ORM\Mapping as ORM;

trait ContentItemLocalizationStateTrait
{
    /**
     * @var list<string>
     */
    #[ORM\Column(type: 'json')]
    private array $availableLanguages = [];

    /**
     * @var list<string>
     */
    #[ORM\Column(type: 'json')]
    private array $availableVariants = ['default'];

    /**
     * @return list<string>
     */
    public function availableLanguages(): array
    {
        return $this->availableLanguages;
    }

    /**
     * @param list<string> $languages
     */
    public function setAvailableLanguages(array $languages): void
    {
        $this->availableLanguages = ContentItemInput::nonEmptyStringList($languages, 'Available languages');
    }

    /**
     * @return list<string>
     */
    public function availableVariants(): array
    {
        return $this->availableVariants;
    }

    /**
     * @param list<string> $variants
     */
    public function setAvailableVariants(array $variants): void
    {
        $this->availableVariants = ContentItemInput::nonEmptyStringList($variants, 'Available variants');
    }
}

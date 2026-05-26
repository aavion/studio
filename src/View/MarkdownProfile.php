<?php

declare(strict_types=1);

namespace App\View;

enum MarkdownProfile: string
{
    case Readme = 'readme';
    case Design = 'design';
    case Allrounder = 'allrounder';
    case Basic = 'basic';

    public static function resolve(string|self|null $profile): self
    {
        if ($profile instanceof self) {
            return $profile;
        }

        if (null === $profile || '' === trim($profile)) {
            return self::Allrounder;
        }

        return self::tryFrom(strtolower(trim($profile))) ?? self::Allrounder;
    }
}

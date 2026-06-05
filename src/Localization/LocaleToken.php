<?php

declare(strict_types=1);

namespace App\Localization;

use Locale;

final readonly class LocaleToken
{
    /**
     * BCP 47 language subtag for an undetermined language.
     */
    public const UNDETERMINED = 'und';

    public static function isValid(string $locale): bool
    {
        return 1 === preg_match('/^[a-z][a-z0-9]*(?:[_-][a-zA-Z0-9]+)*$/', $locale);
    }

    public static function systemDefault(): string
    {
        $locale = str_replace('_', '-', trim(Locale::getDefault()));

        return self::isValid($locale) ? $locale : self::UNDETERMINED;
    }
}

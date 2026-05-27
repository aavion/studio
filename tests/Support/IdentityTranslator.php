<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class IdentityTranslator implements TranslatorInterface
{
    public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
    {
        return strtr($id, array_map(static fn (mixed $value): string => (string) $value, $parameters));
    }

    public function getLocale(): string
    {
        return 'en';
    }
}

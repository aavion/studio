<?php

declare(strict_types=1);

namespace App\Theme;

final readonly class ThemeMacroRegistry
{
    /**
     * @return array<string, array<string, string>>
     */
    public function namespaces(): array
    {
        return [
            'core' => [
                'content' => 'macros/core/content.html.twig',
                'form' => 'macros/core/form.html.twig',
                'ui' => 'macros/core/ui.html.twig',
            ],
            'theme' => [],
            'module' => [],
        ];
    }

    public function template(string $provider, string $namespace): ?string
    {
        $namespaces = $this->namespaces();

        return $namespaces[$provider][$namespace] ?? null;
    }
}

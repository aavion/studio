<?php

declare(strict_types=1);

namespace App\View;

final readonly class PackageMacroRegistry
{
    /**
     * @return array<string, array<string, string>>
     */
    public function namespaces(): array
    {
        return [
            'core' => [
                'content' => '@root/macros/core/content.html.twig',
                'form' => '@root/macros/core/form.html.twig',
                'ui' => '@root/macros/core/ui.html.twig',
            ],
            'package' => [],
        ];
    }

    public function template(string $provider, string $namespace): ?string
    {
        $namespaces = $this->namespaces();

        return $namespaces[$provider][$namespace] ?? null;
    }
}

<?php

declare(strict_types=1);

namespace App\Content\Render;

use App\Content\Read\PublishedContentView;
use Twig\Environment;
use Throwable;

final readonly class ContentFieldsetRenderer
{
    public function __construct(private Environment $twig)
    {
    }

    public function render(PublishedContentView $view): string
    {
        $customTwig = $view->revision()->schemaVersion()->customTwig();

        if (is_string($customTwig) && '' !== trim($customTwig)) {
            try {
                return $this->twig->createTemplate($customTwig)->render($this->context($view));
            } catch (Throwable) {
                return $this->fallback($view);
            }
        }

        return $this->fallback($view);
    }

    private function fallback(PublishedContentView $view): string
    {
        return $this->twig->render('@frontend/content/partials/_generic-fields.html.twig', [
            'content_view' => $view,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function context(PublishedContentView $view): array
    {
        return [
            'content_view' => $view,
            'content' => $view->content(),
            'revision' => $view->revision(),
            'schema' => $view->content()->schema(),
            'schema_version' => $view->revision()->schemaVersion(),
            'fields' => $view->fields(),
            'language' => $view->context()->language(),
            'variant' => $view->context()->variant(),
        ];
    }
}

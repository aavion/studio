<?php

declare(strict_types=1);

namespace App\Content\Render;

use App\Content\ContentMessageCode;
use App\Content\ContentMessageKey;
use App\Content\Read\PublishedContentView;
use App\Core\Message\Message;
use App\Core\Message\MessageReporterInterface;
use Twig\Environment;
use Throwable;

final readonly class ContentFieldsetRenderer
{
    public function __construct(
        private Environment $twig,
        private ?MessageReporterInterface $messageReporter = null,
    ) {
    }

    public function render(PublishedContentView $view): string
    {
        $customTwig = $view->revision()->schemaVersion()->customTwig();

        if (is_string($customTwig) && '' !== trim($customTwig)) {
            try {
                return $this->twig->createTemplate($customTwig)->render($this->context($view));
            } catch (Throwable $error) {
                $this->reportCustomTwigFailure($view, $error);

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

    private function reportCustomTwigFailure(PublishedContentView $view, Throwable $error): void
    {
        $this->messageReporter?->report(Message::warning(
            ContentMessageCode::CONTENT_CUSTOM_TWIG_FAILED,
            ContentMessageKey::CONTENT_CUSTOM_TWIG_FAILED,
            [
                '%schema%' => $view->content()->schema()?->identifier() ?? 'unknown',
            ],
            [
                'operation' => 'content.render.custom_twig',
                'content_uid' => $view->content()->uid(),
                'schema_uid' => $view->content()->schemaUid(),
                'schema_version_uid' => $view->revision()->schemaVersion()->uid(),
                'exception' => $error::class,
                'message' => $error->getMessage(),
            ],
        ), [
            'operation' => 'content.render.custom_twig',
        ]);
    }
}

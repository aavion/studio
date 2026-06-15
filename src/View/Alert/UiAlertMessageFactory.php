<?php

declare(strict_types=1);

namespace App\View\Alert;

use App\Core\Message\Message;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class UiAlertMessageFactory
{
    public function __construct(private TranslatorInterface $translator)
    {
    }

    public function create(UiAlert|Message|UiAlertTranslation $alert, ?string $locale = null, ?UiAlertPresentation $presentation = null): UiAlert
    {
        if ($alert instanceof UiAlert) {
            return $alert->withPresentation($presentation);
        }

        if ($alert instanceof UiAlertTranslation) {
            return UiAlert::fromLevel(
                $alert->level(),
                $this->translator->trans($alert->translationKey(), $alert->parameters(), locale: $locale),
            )->withPresentation($presentation);
        }

        return UiAlert::translated(
            $this->translator->trans($alert->translationKey(), $alert->parameters(), locale: $locale),
            $alert->level(),
            $alert->code(),
            $alert->translationKey(),
        )->withPresentation($presentation);
    }

}

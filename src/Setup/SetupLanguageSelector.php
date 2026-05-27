<?php

declare(strict_types=1);

namespace App\Setup;

use App\Core\Message\Message;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;

final readonly class SetupLanguageSelector
{
    public function __construct(
        private SetupLanguageCatalog $languageCatalog = new SetupLanguageCatalog(),
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function select(string $projectDir, SetupInput $input): array
    {
        $availableLanguages = $this->languageCatalog->availableLanguages($projectDir);

        if (!in_array($input->language(), $availableLanguages, true)) {
            throw new SetupStepFailedException(sprintf('Setup language "%s" is not available.', $input->language()));
        }

        return [
            '_messages' => [
                Message::info(
                    MessageCode::SETUP_LANGUAGE_SELECTED,
                    MessageKey::SETUP_LANGUAGE_SELECTED,
                    ['%language%' => $input->language()],
                ),
                Message::debug(
                    MessageCode::SETUP_AVAILABLE_LANGUAGES,
                    MessageKey::SETUP_AVAILABLE_LANGUAGES,
                    ['%languages%' => implode(', ', $availableLanguages)],
                ),
            ],
            'language' => $input->language(),
            'available_languages' => $availableLanguages,
        ];
    }
}

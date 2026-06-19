<?php

declare(strict_types=1);

namespace App\Core\Extension\Settings;

use App\Form\FormErrorKey;
use App\Form\FormSubmissionHandler;
use App\Form\FormSubmissionResult;

final readonly class ExtensionSettingsFormHandler
{
    private const PROTECTED_VALUE = '[protected]';

    public function __construct(
        private ExtensionSettingRegistry $registry,
        private ExtensionSettings $settings,
        private FormSubmissionHandler $submissionHandler,
    ) {
    }

    /**
     * @param array<string, mixed> $submitted
     */
    public function submit(string $extensionName, array $submitted, ?string $modifiedBy = null): FormSubmissionResult
    {
        $definitions = $this->registry->definitions($extensionName);
        $result = $this->submissionHandler->submit(
            array_map(static fn (ExtensionSettingDefinition $definition) => $definition->formField(), $definitions),
            $submitted,
        );

        if (!$result->isValid()) {
            return $result;
        }

        foreach ($definitions as $definition) {
            if (
                true === ($definition->metadata()['sensitive'] ?? false)
                && $this->isUnchangedSensitiveValue($result->value($definition->key()))
            ) {
                continue;
            }

            if (!$this->settings->set($extensionName, $definition->key(), $result->value($definition->key()), $definition->valueType(), $modifiedBy)) {
                return new FormSubmissionResult($result->values(), [
                    '__form' => [FormErrorKey::SAVE_FAILED],
                ]);
            }
        }

        return $result;
    }

    private function isUnchangedSensitiveValue(mixed $value): bool
    {
        return null === $value
            || (is_string($value) && in_array(trim($value), ['', self::PROTECTED_VALUE], true));
    }
}

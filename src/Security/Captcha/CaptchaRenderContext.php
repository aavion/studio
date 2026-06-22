<?php

declare(strict_types=1);

namespace App\Security\Captcha;

final readonly class CaptchaRenderContext
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private string $workflow,
        private string $formId,
        private string $fieldName = 'captcha',
        private array $metadata = [],
    ) {
    }

    public function workflow(): string
    {
        return $this->workflow;
    }

    public function formId(): string
    {
        return $this->formId;
    }

    public function fieldName(): string
    {
        return $this->fieldName;
    }

    /**
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return $this->metadata;
    }
}

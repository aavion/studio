<?php

declare(strict_types=1);

namespace App\Security\Captcha;

final readonly class CaptchaInstanceEntry
{
    public function __construct(
        private string $id,
        private string $visitorId,
        private string $workflow,
        private string $formId,
        private string $fieldName,
        private int $createdAt,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function visitorId(): string
    {
        return $this->visitorId;
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

    public function createdAt(): int
    {
        return $this->createdAt;
    }
}

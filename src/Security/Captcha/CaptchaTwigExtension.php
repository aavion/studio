<?php

declare(strict_types=1);

namespace App\Security\Captcha;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class CaptchaTwigExtension extends AbstractExtension
{
    public function __construct(private readonly CaptchaFieldRenderer $fields)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('captcha_field', $this->fields->render(...)),
        ];
    }
}

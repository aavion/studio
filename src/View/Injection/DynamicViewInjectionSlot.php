<?php

declare(strict_types=1);

namespace App\View\Injection;

enum DynamicViewInjectionSlot: string
{
    case BeforeContent = 'before_content';
    case AfterContent = 'after_content';
    case Route = 'route';
}

<?php

declare(strict_types=1);

namespace App\View\Injection;

interface DynamicViewInjectionProviderInterface
{
    /**
     * @return list<DynamicViewInjection>
     */
    public function dynamicViewInjections(): array;
}

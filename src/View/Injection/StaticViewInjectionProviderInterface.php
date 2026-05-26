<?php

declare(strict_types=1);

namespace App\View\Injection;

interface StaticViewInjectionProviderInterface
{
    /**
     * @return list<StaticViewInjection>
     */
    public function staticViewInjections(): array;
}

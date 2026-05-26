<?php

declare(strict_types=1);

namespace App\Backend;

interface BackendViewProviderInterface
{
    /**
     * @return list<BackendViewDefinition>
     */
    public function backendViews(): array;
}

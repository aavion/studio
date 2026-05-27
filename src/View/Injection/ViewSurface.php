<?php

declare(strict_types=1);

namespace App\View\Injection;

enum ViewSurface: string
{
    case Public = 'public';
    case Admin = 'admin';
    case Editor = 'editor';
}

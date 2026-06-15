<?php

declare(strict_types=1);

namespace App\View\Alert;

enum UiAlertMode: string
{
    case Auto = 'auto';
    case Hidden = 'hidden';
    case Persistent = 'persistent';
}

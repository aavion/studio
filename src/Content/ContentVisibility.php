<?php

declare(strict_types=1);

namespace App\Content;

enum ContentVisibility: string
{
    case Public = 'public';
    case Private = 'private';
}

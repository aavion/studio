<?php

declare(strict_types=1);

namespace App\Content\Schema;

enum ContentSchemaSource: string
{
    case Preset = 'preset';
    case Custom = 'custom';
    case Module = 'module';
}

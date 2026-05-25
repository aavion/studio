<?php

declare(strict_types=1);

namespace App\Core\Message;

enum MessageLevel: string
{
    case Error = 'ERROR';
    case Warning = 'WARN';
    case Info = 'INFO';
    case Debug = 'DEBUG';
}

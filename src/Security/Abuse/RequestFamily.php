<?php

declare(strict_types=1);

namespace App\Security\Abuse;

enum RequestFamily: string
{
    case Browser = 'browser';
    case Admin = 'admin';
    case Editor = 'editor';
    case Api = 'api';
    case LiveApi = 'live_api';
    case Scheduler = 'scheduler';
    case Setup = 'setup';
    case Unknown = 'unknown';
}

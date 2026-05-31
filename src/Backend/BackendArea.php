<?php

declare(strict_types=1);

namespace App\Backend;

use App\Core\Access\AccessLevel;
use App\Core\Access\AccessRule;

enum BackendArea: string
{
    case Setup = 'setup';
    case Admin = 'admin';
    case Editor = 'editor';

    public function routeName(): string
    {
        return 'backend_'.$this->value.'_index';
    }

    public function navigationIdentifier(): string
    {
        return 'backend.'.$this->value;
    }

    public function indexTemplate(): string
    {
        return '@backend/'.$this->value.'/index.html.twig';
    }

    public function messageTemplate(): string
    {
        return '@backend/'.$this->value.'/message.html.twig';
    }

    public function minimumAccessLevel(): int
    {
        return match ($this) {
            self::Setup => AccessLevel::PUBLIC,
            self::Editor => AccessLevel::AUTHOR,
            self::Admin => AccessLevel::ADMIN,
        };
    }

    public function accessRule(): AccessRule
    {
        return AccessRule::from($this->minimumAccessLevel());
    }
}

<?php

declare(strict_types=1);

namespace App\Setup;

enum DatabaseDriver: string
{
    case MySql = 'mysql';
    case SQLite = 'sqlite';
    case PostgreSql = 'postgresql';
}

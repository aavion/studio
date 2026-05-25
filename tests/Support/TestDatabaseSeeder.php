<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Tests\Support\DatabaseSeed\TestDatabaseConfigSeeder;
use App\Tests\Support\DatabaseSeed\TestDatabaseContentSeeder;
use App\Tests\Support\DatabaseSeed\TestDatabaseExtensionSeeder;
use App\Tests\Support\DatabaseSeed\TestDatabaseMenuSeeder;
use App\Tests\Support\DatabaseSeed\TestDatabaseSchemaSeeder;
use App\Tests\Support\DatabaseSeed\TestDatabaseSecuritySeeder;
use App\Tests\Support\DatabaseSeed\TestDatabaseSeedWriter;
use PDO;

final class TestDatabaseSeeder
{
    public static function seed(string $databasePath): void
    {
        $pdo = new PDO('sqlite:'.$databasePath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys = ON');

        $writer = new TestDatabaseSeedWriter($pdo);

        TestDatabaseConfigSeeder::seed($writer);
        TestDatabaseSecuritySeeder::seed($writer);
        TestDatabaseExtensionSeeder::seed($writer);
        TestDatabaseSchemaSeeder::seed($writer);
        TestDatabaseContentSeeder::seed($writer);
        TestDatabaseMenuSeeder::seed($writer);
    }

    private function __construct()
    {
    }
}

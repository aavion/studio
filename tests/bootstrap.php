<?php

use App\Tests\Support\TestSuiteLifecycle;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

TestSuiteLifecycle::initialize();
register_shutdown_function([TestSuiteLifecycle::class, 'cleanup']);

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}

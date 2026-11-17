<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;

// Everything generated at runtime lives in var/: Laravel's storage, its bootstrap cache and the SQLite file.
// The cache paths are read from the environment while the Application is constructed, before .env is loaded.
// var/ is not in git, so the one directory Laravel refuses to create itself is created here.
is_dir(dirname(__DIR__) . '/var/bootstrap-cache') || mkdir(dirname(__DIR__) . '/var/bootstrap-cache', 0o775, true);

foreach (['SERVICES', 'PACKAGES', 'CONFIG', 'ROUTES', 'EVENTS'] as $cache) {
    $_ENV["APP_{$cache}_CACHE"] ??= 'var/bootstrap-cache/' . strtolower($cache) . '.php';
}

$app = Application::configure(basePath: dirname(__DIR__))
    ->withExceptions(static function (Exceptions $exceptions): void {
    })
    ->create();

return $app->useStoragePath($app->basePath('var'));

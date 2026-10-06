<?php
declare(strict_types=1);

use App\Config\Config;
use App\Config\Env;

/**
 * Shared startup for every PHP entry point: the web front controller,
 * CLI tools (database/migrate.php) and tests.
 *
 * Registers the class autoloader, loads .env and builds the configuration.
 * Safe to require more than once.
 */
if (defined('APP_ROOT')) {
    return;
}

define('APP_ROOT', dirname(__DIR__));
date_default_timezone_set('UTC');

// PSR-4 style autoloader: App\Config\Env -> backend/config/Env.php
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $parts = explode('\\', substr($class, 4));
    $file  = array_pop($parts);
    $dir   = strtolower(implode('/', $parts));
    $path  = APP_ROOT . '/backend/' . ($dir !== '' ? "$dir/" : '') . "$file.php";
    if (is_file($path)) {
        require $path;
    }
});

Env::load(APP_ROOT . '/.env');
Config::load(require APP_ROOT . '/backend/config/settings.php');

<?php
declare(strict_types=1);

// Front controller: every /api/* request enters here.

use App\Config\Config;
use App\Config\Env;
use App\Routes\Router;
use App\Utils\ErrorHandler;
use App\Utils\Response;

$root = dirname(__DIR__, 2);

// PSR-4 style autoloader: App\Config\Env -> backend/config/Env.php
spl_autoload_register(static function (string $class) use ($root): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $parts = explode('\\', substr($class, 4));
    $file  = array_pop($parts);
    $dir   = strtolower(implode('/', $parts));
    $path  = "$root/backend/" . ($dir !== '' ? "$dir/" : '') . "$file.php";
    if (is_file($path)) {
        require $path;
    }
});

Env::load($root . '/.env');
Config::load(require $root . '/backend/config/settings.php');
ErrorHandler::register();

// Strip everything up to and including "/api" so routes stay deployment-independent.
$uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$pos     = strpos($uriPath, '/api');
$path    = $pos === false ? '/' : (substr($uriPath, $pos + 4) ?: '/');
if ($path[0] !== '/') {
    Response::error('Endpoint not found.', 404);
}

$router = new Router();
(require $root . '/backend/routes/api.php')($router);
$router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $path);

<?php
declare(strict_types=1);

// Front controller: every /api/* request enters here.

use App\Routes\Router;
use App\Utils\ErrorHandler;
use App\Utils\Request;

require dirname(__DIR__) . '/bootstrap.php';

ErrorHandler::register(); // from here on every error and exception becomes a JSON reply

$router = new Router();
(require APP_ROOT . '/backend/routes/api.php')($router);
$router->dispatch(Request::fromGlobals());

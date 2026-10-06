<?php
declare(strict_types=1);

use App\Controllers\HealthController;
use App\Routes\Router;

/** Health check. Paths are relative to /api. */
return static function (Router $router): void {
    $router->get('/health', [HealthController::class, 'index']);
};

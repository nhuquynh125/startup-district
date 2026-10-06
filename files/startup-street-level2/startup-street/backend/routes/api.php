<?php
declare(strict_types=1);

use App\Routes\Router;

/**
 * Route registry. Each module owns one file in routes/modules/ and groups its
 * paths under its own prefix. Paths are relative to /api.
 *
 * To add a module: create routes/modules/<name>.php (copy catalog.php), add
 * its name to the list below. Planned modules for later levels:
 * auth, players, properties, shops, inventory, employees, upgrades,
 * marketing, events, achievements, notifications, advice.
 */
return static function (Router $router): void {
    $modules = ['health', 'catalog'];

    foreach ($modules as $module) {
        (require __DIR__ . "/modules/$module.php")($router);
    }
};

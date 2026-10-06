<?php
declare(strict_types=1);

use App\Controllers\CatalogController;
use App\Routes\Router;

/** Read-only reference data (business types and their products). */
return static function (Router $router): void {
    $router->group('/catalog', static function (Router $r): void {
        $r->get('/business-types', [CatalogController::class, 'businessTypes']);
        $r->get('/business-types/{slug:slug}', [CatalogController::class, 'businessType']);
    });
};

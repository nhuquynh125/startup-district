<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Utils\Request;

/**
 * Cross-cutting checks that run before a controller (authentication, rate
 * limits, ...). Call $next($request) to continue, or throw an HttpException to stop.
 * Attach to a route or a group:  $router->group('/shops', $define, [AuthMiddleware::class]);
 */
interface MiddlewareInterface
{
    public function handle(Request $request, callable $next): mixed;
}

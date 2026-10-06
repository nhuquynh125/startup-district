<?php
declare(strict_types=1);

namespace App\Routes;

use App\Utils\Response;

/** Minimal method + path router. Handlers are [ControllerClass::class, 'method']. */
final class Router
{
    private array $routes = [];

    public function get(string $path, array $handler): void  { $this->add('GET', $path, $handler); }
    public function post(string $path, array $handler): void { $this->add('POST', $path, $handler); }

    private function add(string $method, string $path, array $handler): void
    {
        $this->routes[$method][rtrim($path, '/') ?: '/'] = $handler;
    }

    public function dispatch(string $method, string $path): void
    {
        $path = rtrim($path, '/') ?: '/';

        if (isset($this->routes[$method][$path])) {
            [$class, $action] = $this->routes[$method][$path];
            (new $class())->$action();
            return;
        }

        $allowed = array_keys(array_filter($this->routes, static fn ($r) => isset($r[$path])));
        if ($allowed) {
            header('Allow: ' . implode(', ', $allowed));
            Response::error('Method not allowed.', 405);
        }
        Response::error('Endpoint not found.', 404);
    }
}

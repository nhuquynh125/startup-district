<?php
declare(strict_types=1);

namespace App\Routes;

use App\Middleware\MiddlewareInterface;
use App\Utils\HttpException;
use App\Utils\Request;
use Closure;
use InvalidArgumentException;

/**
 * Method + path router with path parameters, groups and middleware.
 *
 *   $router->get('/shops/{id:int}', [ShopController::class, 'show']);
 *   $router->group('/shops', function (Router $r) { ... }, [AuthMiddleware::class]);
 *
 * Handlers are [ControllerClass::class, 'method'] or a closure; they receive
 * (Request $request, array $params). Parameter types: {name} (any segment),
 * {name:int} (digits), {name:slug} (lower-case words joined by dashes).
 */
final class Router
{
    private const TYPES = [
        'any'  => '[^/]+',
        'int'  => '[0-9]+',
        'slug' => '[a-z0-9]+(?:-[a-z0-9]+)*',
    ];

    /** @var list<array{method: string, regex: string, handler: array|Closure, middleware: array}> */
    private array $routes = [];
    private string $prefix = '';
    private array $groupMiddleware = [];

    public function get(string $path, array|Closure $handler, array $middleware = []): void    { $this->add('GET', $path, $handler, $middleware); }
    public function post(string $path, array|Closure $handler, array $middleware = []): void   { $this->add('POST', $path, $handler, $middleware); }
    public function put(string $path, array|Closure $handler, array $middleware = []): void    { $this->add('PUT', $path, $handler, $middleware); }
    public function patch(string $path, array|Closure $handler, array $middleware = []): void  { $this->add('PATCH', $path, $handler, $middleware); }
    public function delete(string $path, array|Closure $handler, array $middleware = []): void { $this->add('DELETE', $path, $handler, $middleware); }

    /** Register several routes under a shared path prefix and middleware list. */
    public function group(string $prefix, callable $define, array $middleware = []): void
    {
        $previousPrefix     = $this->prefix;
        $previousMiddleware = $this->groupMiddleware;

        $this->prefix         = $this->join($previousPrefix, $prefix);
        $this->groupMiddleware = [...$previousMiddleware, ...$middleware];
        try {
            $define($this);
        } finally {
            $this->prefix          = $previousPrefix;
            $this->groupMiddleware = $previousMiddleware;
        }
    }

    /**
     * Find the route for a request and run it (middleware first).
     * Throws HttpException 404 / 405 when nothing matches.
     */
    public function dispatch(Request $request): mixed
    {
        [$route, $params] = $this->resolve($request->method(), $request->path());

        $pipeline = fn (): mixed => $this->callHandler($route['handler'], $request, $params);
        foreach (array_reverse($route['middleware']) as $middleware) {
            $next     = $pipeline;
            $pipeline = fn (): mixed => $this->callMiddleware($middleware, $request, $next);
        }
        return $pipeline();
    }

    /** @return array{0: array, 1: array<string, string>} the route and its path parameters */
    public function resolve(string $method, string $path): array
    {
        $path    = '/' . trim($path, '/');
        $allowed = [];

        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $matches)) {
                continue;
            }
            if ($route['method'] === $method) {
                return [$route, array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY)];
            }
            $allowed[$route['method']] = true;
        }

        if ($allowed) {
            $methods = array_keys($allowed);
            sort($methods);
            if (!headers_sent()) {
                header('Allow: ' . implode(', ', $methods));
            }
            throw HttpException::methodNotAllowed();
        }
        throw HttpException::notFound('Endpoint not found.');
    }

    private function add(string $method, string $path, array|Closure $handler, array $middleware): void
    {
        $full = $this->join($this->prefix, $path);
        $this->routes[] = [
            'method'     => $method,
            'regex'      => $this->compile($full),
            'handler'    => $handler,
            'middleware' => [...$this->groupMiddleware, ...$middleware],
        ];
    }

    private function join(string $base, string $path): string
    {
        $joined = '/' . trim(trim($base, '/') . '/' . trim($path, '/'), '/');
        return $joined;
    }

    /** Turn "/shops/{id:int}" into an anchored regex with named groups. */
    private function compile(string $path): string
    {
        $regex = preg_replace_callback('/\{(\w+)(?::(\w+))?\}|[^{]+/', function (array $m): string {
            if (!isset($m[1])) {
                return preg_quote($m[0], '#');
            }
            $type = $m[2] ?? 'any';
            if (!isset(self::TYPES[$type])) {
                throw new InvalidArgumentException("Unknown route parameter type: $type");
            }
            return '(?P<' . $m[1] . '>' . self::TYPES[$type] . ')';
        }, $path);

        return '#^' . $regex . '$#';
    }

    private function callHandler(array|Closure $handler, Request $request, array $params): mixed
    {
        if ($handler instanceof Closure) {
            return $handler($request, $params);
        }
        [$class, $action] = $handler;
        return (new $class())->$action($request, $params);
    }

    private function callMiddleware(string|Closure|MiddlewareInterface $middleware, Request $request, callable $next): mixed
    {
        if (is_string($middleware)) {
            $middleware = new $middleware();
        }
        return $middleware instanceof Closure
            ? $middleware($request, $next)
            : $middleware->handle($request, $next);
    }
}

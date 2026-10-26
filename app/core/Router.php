<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Small path router.
 *
 *   $router->get('/', [HomeController::class, 'index']);
 *   $router->get('/product/{slug}', [ProductController::class, 'show']);
 *
 * {name} segments match anything except "/" and are passed to the
 * handler as arguments, in order.
 */
final class Router
{
    /** @var array<string, array<int, array{regex: string, handler: callable|array}>> */
    private array $routes = [];

    public function get(string $path, callable|array $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    public function post(string $path, callable|array $handler): void
    {
        $this->add('POST', $path, $handler);
    }

    public function add(string $method, string $path, callable|array $handler): void
    {
        $path  = '/' . trim($path, '/');
        $regex = preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', '([^/]+)', $path);

        $this->routes[strtoupper($method)][] = [
            'regex'   => '#^' . $regex . '$#',
            'handler' => $handler,
        ];
    }

    public function dispatch(string $method, string $path): void
    {
        $method = strtoupper($method) === 'HEAD' ? 'GET' : strtoupper($method);
        $path   = '/' . trim($path, '/');

        foreach ($this->routes[$method] ?? [] as $route) {
            if (preg_match($route['regex'], $path, $matches)) {
                array_shift($matches);
                $this->call($route['handler'], array_map('rawurldecode', $matches));
                return;
            }
        }

        // Path exists for another method -> 405, otherwise 404
        $allowed = [];
        foreach ($this->routes as $other => $routes) {
            foreach ($routes as $route) {
                if ($other !== $method && preg_match($route['regex'], $path)) {
                    $allowed[] = $other === 'GET' ? 'GET, HEAD' : $other;
                    break;
                }
            }
        }
        if ($allowed) {
            http_response_code(405);
            header('Allow: ' . implode(', ', $allowed));
            echo view('errors/404', [
                'title' => 'Method not allowed',
                'meta'  => ['title' => 'Method not allowed', 'robots' => 'noindex, follow'],
            ], 'main');
            return;
        }

        abort(404);
    }

    private function call(callable|array $handler, array $params): void
    {
        if (is_array($handler) && is_string($handler[0])) {
            $handler = [new $handler[0](), $handler[1]];
        }

        $output = $handler(...$params);

        if (is_string($output)) {
            echo $output;
        }
    }
}

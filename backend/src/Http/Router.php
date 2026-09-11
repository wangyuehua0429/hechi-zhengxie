<?php

declare(strict_types=1);

namespace HechiZx\Http;

/**
 * 路由表：支持 GET/POST + {参数} 占位。对外只读接口与后台写接口共用一套。
 */
final class Router
{
    /** @var list<array{method:string, regex:string, params:list<string>, handler:callable}> */
    private array $routes = [];

    public function get(string $pattern, callable $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    public function add(string $method, string $pattern, callable $handler): void
    {
        $params = [];
        $regex = preg_replace_callback('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', static function (array $m) use (&$params): string {
            $params[] = $m[1];
            return '([^/]+)';
        }, $pattern);

        $this->routes[] = [
            'method'  => strtoupper($method),
            'regex'   => '#^' . $regex . '$#',
            'params'  => $params,
            'handler' => $handler,
        ];
    }

    /**
     * 处理函数可以返回数组（转 JSON）、HtmlResponse、RedirectResponse；
     * 路径不匹配抛 404，路径匹配但方法不对抛 405。
     */
    public function dispatch(Request $request): mixed
    {
        $pathMatched = false;

        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $request->path(), $matches)) {
                continue;
            }
            $pathMatched = true;
            if ($route['method'] !== $request->method()) {
                continue;
            }

            $args = [];
            foreach ($route['params'] as $index => $name) {
                $args[$name] = $matches[$index + 1];
            }

            $result = ($route['handler'])($request, $args);
            return $result;
        }

        if ($pathMatched) {
            throw new ApiException('method_not_allowed', '该路径不支持 ' . $request->method() . ' 方法', 405);
        }

        throw ApiException::notFound('接口不存在：' . $request->path());
    }
}

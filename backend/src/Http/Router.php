<?php

declare(strict_types=1);

namespace HechiZx\Http;

/**
 * 路由表：只支持 GET + {参数} 占位，够本期只读接口用；写接口按同一套加方法即可。
 */
final class Router
{
    /** @var list<array{method:string, regex:string, params:list<string>, handler:callable}> */
    private array $routes = [];

    public function get(string $pattern, callable $handler): void
    {
        $this->add('GET', $pattern, $handler);
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
     * @return array<string, mixed>|null 命中的处理结果；路径不匹配返回 null
     */
    public function dispatch(Request $request): ?array
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

            /** @var array<string, mixed> $result */
            $result = ($route['handler'])($request, $args);
            return $result;
        }

        if ($pathMatched) {
            throw new ApiException('method_not_allowed', '该路径不支持 ' . $request->method() . ' 方法', 405);
        }

        throw ApiException::notFound('接口不存在：' . $request->path());
    }
}

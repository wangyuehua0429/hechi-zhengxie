<?php

declare(strict_types=1);

namespace HechiZx\Http;

/**
 * 请求对象：只收方法、路径与查询串，够本期只读接口用。
 */
final class Request
{
    /** @param array<string, string> $query */
    public function __construct(
        private string $method,
        private string $path,
        private array $query
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) ? $path : '/';
        $path = '/' . trim($path, '/');

        /** @var array<string, string> $query */
        $query = $_GET;

        return new self($method, $path === '/' ? '/' : rtrim($path, '/'), $query);
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function query(string $key, ?string $default = null): ?string
    {
        $value = $this->query[$key] ?? null;
        if ($value === null || $value === '') {
            return $default;
        }
        return is_string($value) ? $value : $default;
    }

    public function int(string $key, int $default, int $min = PHP_INT_MIN, int $max = PHP_INT_MAX): int
    {
        $raw = $this->query($key);
        if ($raw === null || !preg_match('/^-?\d+$/', $raw)) {
            return $default;
        }
        $value = (int) $raw;
        return max($min, min($max, $value));
    }
}

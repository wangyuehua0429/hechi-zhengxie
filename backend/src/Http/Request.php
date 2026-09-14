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
        private array $query,
        private array $post = []
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
        /** @var array<string, string> $post */
        $post = $_POST;

        return new self($method, $path === '/' ? '/' : rtrim($path, '/'), $query, $post);
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

    /** 表单字段（后台用）：始终返回字符串，缺省为空串 */
    public function post(string $key, string $default = ''): string
    {
        $value = $this->post[$key] ?? null;
        if ($value === null) {
            return $default;
        }
        return is_string($value) ? trim($value) : $default;
    }

    /**
     * 表单里是否真的带了某个字段。
     *
     * 用来区分「没提交这个字段」与「提交了空值」——两者语义不同：
     * 例如原标题三列，没提交时不应改动正文里的题区，提交空值则是有意清空。
     */
    public function hasPost(string $key): bool
    {
        return array_key_exists($key, $this->post);
    }
}

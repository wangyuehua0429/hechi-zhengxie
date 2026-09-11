<?php

declare(strict_types=1);

namespace HechiZx\Support;

/**
 * 配置容器：支持点号取值（db.driver、paths.storage）。
 */
final class Config
{
    /** @param array<string, mixed> $items */
    public function __construct(private array $items)
    {
    }

    public static function load(string $file): self
    {
        if (!is_file($file)) {
            throw new \RuntimeException('配置文件不存在：' . $file);
        }
        /** @var array<string, mixed> $items */
        $items = require $file;
        return new self($items);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $node = $this->items;
        foreach (explode('.', $key) as $part) {
            if (!is_array($node) || !array_key_exists($part, $node)) {
                return $default;
            }
            $node = $node[$part];
        }
        return $node;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->items;
    }
}

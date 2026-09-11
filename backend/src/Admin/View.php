<?php

declare(strict_types=1);

namespace HechiZx\Admin;

use HechiZx\Http\HtmlResponse;

/**
 * 后台视图：把内容渲染进 layout.php 再返回 HTML 响应。
 */
final class View
{
    /** @var array<string, mixed> */
    private array $shared;

    /**
     * @param array<string, mixed> $shared
     */
    public function __construct(private string $templateDir, array $shared)
    {
        $this->shared = $shared;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function page(string $template, array $data, string $title, int $status = 200): HtmlResponse
    {
        $file = rtrim($this->templateDir, '/') . '/' . $template . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException('后台模板不存在：' . $file);
        }

        $vars = array_merge($this->shared, $data, [
            'title'   => $title,
            'flash'   => Flash::take(),
            'csrf'    => Csrf::field(),
            'current' => $data['current'] ?? '',
        ]);

        ob_start();
        (static function (string $__file, array $__vars): void {
            extract($__vars, EXTR_SKIP);
            include $__file;
        })($file, $vars);
        $content = (string) ob_get_clean();

        $layout = rtrim($this->templateDir, '/') . '/admin/layout.php';
        ob_start();
        (static function (string $__file, array $__vars): void {
            extract($__vars, EXTR_SKIP);
            include $__file;
        })($layout, array_merge($vars, ['content' => $content]));

        return new HtmlResponse((string) ob_get_clean(), $status);
    }
}

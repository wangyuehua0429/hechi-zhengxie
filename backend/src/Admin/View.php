<?php

declare(strict_types=1);

namespace HechiZx\Admin;

use HechiZx\Http\HtmlResponse;

/**
 * 后台视图：把内容渲染进 layout.php 再返回 HTML 响应。
 *
 * 登录页这类自己就是完整 HTML 文档的模板走 bare()，不要套外壳——
 * 套上去会形成嵌套 document，顶栏与侧栏会泄到登录页上，浏览器只是把
 * body 属性合并过去才显得「勉强能用」。
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
     * 带后台外壳的页面。
     *
     * @param array<string, mixed> $data
     */
    public function page(string $template, array $data, string $title, int $status = 200): HtmlResponse
    {
        // flash 只能取一次，所以先把变量备好，模板与外壳共用同一份
        $vars = $this->vars($data, $title);
        $content = $this->render($template, $vars);

        return new HtmlResponse($this->render('admin/layout', array_merge($vars, ['content' => $content])), $status);
    }

    /**
     * 不带外壳渲染：用于登录页等自带完整 HTML 文档的模板。
     *
     * @param array<string, mixed> $data
     */
    public function bare(string $template, array $data, string $title, int $status = 200): HtmlResponse
    {
        return new HtmlResponse($this->render($template, $this->vars($data, $title)), $status);
    }

    /**
     * @param array<string, mixed> $vars
     */
    private function render(string $template, array $vars): string
    {
        $file = rtrim($this->templateDir, '/') . '/' . $template . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException('后台模板不存在：' . $file);
        }

        ob_start();
        (static function (string $__file, array $__vars): void {
            extract($__vars, EXTR_SKIP);
            include $__file;
        })($file, $vars);

        return (string) ob_get_clean();
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function vars(array $data, string $title): array
    {
        return array_merge($this->shared, $data, [
            'title'   => $title,
            'flash'   => Flash::take(),
            'csrf'    => Csrf::field(),
            'current' => $data['current'] ?? '',
        ]);
    }
}

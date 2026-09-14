<?php

declare(strict_types=1);

namespace HechiZx\Member;

use HechiZx\Admin\Flash;
use HechiZx\Admin\Csrf;
use HechiZx\Http\HtmlResponse;

/**
 * 委员门户视图：与后台 View 同一套写法，但外壳换成 templates/member/layout.php。
 * 用 bare() 渲染不需要外壳的页面（登录页自带完整文档）。
 */
final class MemberView
{
    /** @param array<string, mixed> $shared */
    public function __construct(private string $templateDir, private array $shared)
    {
    }

    /** @param array<string, mixed> $data */
    public function page(string $template, array $data, string $title, int $status = 200): HtmlResponse
    {
        $vars = $this->vars($data, $title);
        $content = $this->render($template, $vars);

        return new HtmlResponse($this->render('member/layout', array_merge($vars, ['content' => $content])), $status);
    }

    /** @param array<string, mixed> $data */
    public function bare(string $template, array $data, string $title, int $status = 200): HtmlResponse
    {
        return new HtmlResponse($this->render($template, $this->vars($data, $title)), $status);
    }

    /** @param array<string, mixed> $vars */
    private function render(string $template, array $vars): string
    {
        $file = rtrim($this->templateDir, '/') . '/' . $template . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException('门户模板不存在：' . $file);
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
            'title' => $title,
            'flash' => Flash::take(),
            'csrf'  => Csrf::field(),
        ]);
    }
}

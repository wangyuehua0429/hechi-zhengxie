<?php

declare(strict_types=1);

namespace HechiZx\Admin;

use HechiZx\Http\RedirectResponse;
use HechiZx\Http\Request;
use HechiZx\Http\HtmlResponse;
use HechiZx\Content\Permissions;
use HechiZx\Publish\Publisher;
use HechiZx\Support\Db;

/**
 * 一键发布：把库里的内容重新生成静态页与数据快照（后台按钮，等价于
 * php backend/bin/publish.php）。
 */
final class PublishController extends AdminController
{
    public function __construct(
        Auth $auth,
        View $view,
        Db $db,
        int $siteId,
        private string $outDir,
        private string $templateFile,
        private string $siteName,
        private string $siteDomain
    ) {
        parent::__construct($auth, $view, $db, $siteId);
    }

    public function run(Request $request): HtmlResponse|RedirectResponse
    {
        if ($redirect = $this->requireLogin()) {
            return $redirect;
        }
        if ($denied = $this->requirePermission(Permissions::PUBLISH_RUN)) {
            return $denied;
        }
        if ($denied = $this->guard($request)) {
            // guard 命中说明令牌失效；这里退化成跳首页并提示，避免把 HTML 混进 POST 流程
            Flash::set('error', '页面已过期，请重新点击发布。');
            return new RedirectResponse('/admin');
        }

        try {
            $publisher = new Publisher(
                $this->db,
                $this->siteId,
                $this->outDir,
                $this->templateFile,
                $this->siteName,
                $this->siteDomain
            );
            $html = $publisher->publishHtml();
            $this->log('publish.all', 'site', (string) $this->siteId, $html);
            Flash::set('ok', sprintf(
                '发布完成：静态页 %d 个（首页 1 + 栏目 %d + 详情 %d）、清理失效页 %d 个，输出到 %s',
                $html['html_pages'] ?? 0,
                $html['channels'] ?? 0,
                $html['articles'] ?? 0,
                $html['pruned'] ?? 0,
                $this->outDir
            ));
        } catch (\Throwable $e) {
            Flash::set('error', '发布失败：' . $e->getMessage());
        }

        return new RedirectResponse('/admin');
    }
}

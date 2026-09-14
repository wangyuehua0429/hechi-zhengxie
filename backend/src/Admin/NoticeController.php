<?php

declare(strict_types=1);

namespace HechiZx\Admin;

use HechiZx\Content\Permissions;
use HechiZx\Http\HtmlResponse;
use HechiZx\Http\RedirectResponse;
use HechiZx\Http\Request;
use HechiZx\Repository\HomeRepository;
use HechiZx\Support\Db;

/**
 * 滚动公告：首页导航条下方、搜索框左侧那条滚动要闻（前台读 home.meta.marquee）。
 *
 * 公告文字存在 cms_home_block 的 meta 块里，与站点版权、备案号、联系方式同一个块，
 * 所以这里只改 marquee 一个字段、其余字段原样写回——不能整块覆盖。
 */
final class NoticeController extends AdminController
{
    /** 公告是一条横向滚动条，太长会在首屏刷得很快，给编辑一个上限 */
    private const MAX_LENGTH = 500;

    public function __construct(
        Auth $auth,
        View $view,
        Db $db,
        int $siteId,
        private HomeRepository $home
    ) {
        parent::__construct($auth, $view, $db, $siteId);
    }

    public function edit(Request $request): HtmlResponse|RedirectResponse
    {
        if ($redirect = $this->requireLogin()) {
            return $redirect;
        }
        if ($denied = $this->requirePermission(Permissions::HOME_MANAGE)) {
            return $denied;
        }

        $meta = $this->metaBlock();
        return $this->view->page('admin/notice', [
            'current'   => 'notice',
            'marquee'   => (string) ($meta['marquee'] ?? ''),
            'hidden'    => !empty($meta['marqueeHidden']),
            'maxLength' => self::MAX_LENGTH,
        ], '滚动公告');
    }

    public function update(Request $request): HtmlResponse|RedirectResponse
    {
        if ($redirect = $this->requireLogin()) {
            return $redirect;
        }
        if ($denied = $this->guard($request)) {
            return $denied;
        }
        if ($denied = $this->requirePermission(Permissions::HOME_MANAGE)) {
            return $denied;
        }

        // 公告是单行滚动条：换行与连续空白在前台会挤在一起，这里先收成单个空格
        $text = (string) preg_replace('/\s+/u', ' ', trim((string) $request->post('marquee')));
        $length = mb_strlen($text, 'UTF-8');
        if ($length > self::MAX_LENGTH) {
            Flash::set('error', '公告最多 ' . self::MAX_LENGTH . ' 个字，当前 ' . $length . ' 个。');
            return new RedirectResponse('/admin/notice');
        }

        $meta = $this->metaBlock();
        $wasHidden = !empty($meta['marqueeHidden']);
        $meta['marquee'] = $text;
        // 停用＝前台整条滚条（含图标）不显示、搜索框仍贴右并放宽；字段照 nav 的写法，只有隐藏时才写、取消即删
        $hidden = $request->post('hidden') === '1';
        if ($hidden) {
            $meta['marqueeHidden'] = true;
        } else {
            unset($meta['marqueeHidden']);
        }
        $this->home->saveBlock('meta', $meta);
        $this->log('notice.update', 'home', 'meta', [
            'length'  => $length,
            'hidden'  => $hidden,
            'marquee' => $text,
        ]);

        if ($hidden) {
            Flash::set('ok', '已停用：前台首页不再显示这条滚条，搜索框仍贴右、宽度放宽到 560px；文字仍保留，取消停用即可恢复。');
        } elseif ($wasHidden) {
            Flash::set('ok', '已恢复显示：前台首页又是「喇叭图标 + 滚动文字」，搜索框回到原来的宽度。');
        } elseif ($text === '') {
            Flash::set('ok', '已清空滚动公告，前台首页显示「暂无要闻」。');
        } else {
            Flash::set('ok', '已更新滚动公告，前台首页即刻生效。');
        }
        return new RedirectResponse('/admin/notice');
    }

    /** meta 块原样读出（标题、域名、版权、备案号、联系方式与公告都在这一块里） */
    private function metaBlock(): array
    {
        $meta = $this->home->block('meta');
        return is_array($meta) ? $meta : [];
    }

}

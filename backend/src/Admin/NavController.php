<?php

declare(strict_types=1);

namespace HechiZx\Admin;

use HechiZx\Content\Permissions;
use HechiZx\Http\HtmlResponse;
use HechiZx\Http\RedirectResponse;
use HechiZx\Http\Request;
use HechiZx\Repository\ChannelRepository;
use HechiZx\Repository\HomeRepository;
use HechiZx\Support\Db;

/**
 * 导航栏目：维护首页（与内页共用的）顶部导航条。
 *
 * 导航条存 cms_home_block 的 nav 块，是「首页 / 政协概况 / 政协动态 …」这 18 个入口，
 * 里面有栏目入口、聚合页与外部链接，所以不做成「按栏目自动生成」，
 * 而是给编辑一个可以改名、改链接、调顺序、隐藏条目的列表。
 */
final class NavController extends AdminController
{
    public function __construct(
        Auth $auth,
        View $view,
        Db $db,
        int $siteId,
        private HomeRepository $home,
        private ChannelRepository $channels
    ) {
        parent::__construct($auth, $view, $db, $siteId);
    }

    public function index(Request $request): HtmlResponse|RedirectResponse
    {
        if ($redirect = $this->requireLogin()) {
            return $redirect;
        }
        if ($denied = $this->requirePermission(Permissions::HOME_MANAGE)) {
            return $denied;
        }

        return $this->view->page('admin/nav', [
            'current' => 'nav',
            'items'   => $this->navItems(),
            'channelCounts' => $this->channelCounts(),
        ], '导航栏目');
    }

    /**
     * 组内上移／下移：相邻两项交换位置。
     *
     * @param array<string, string> $args
     */
    public function move(Request $request, array $args): HtmlResponse|RedirectResponse
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

        $items = $this->navItems();
        $index = (int) $args['index'];
        $target = $request->post('dir') === 'up' ? $index - 1 : $index + 1;
        if (!isset($items[$index]) || !isset($items[$target])) {
            Flash::set('error', $request->post('dir') === 'up' ? '已经在最前面了。' : '已经在最后面了。');
            return new RedirectResponse('/admin/nav');
        }

        [$items[$index], $items[$target]] = [$items[$target], $items[$index]];
        $this->home->saveBlock('nav', array_values($items));
        $this->log('nav.move', 'home', 'nav', [
            'title'     => (string) ($items[$target]['title'] ?? ''),
            'direction' => $request->post('dir'),
        ]);
        Flash::set('ok', '已调整导航顺序：' . (string) ($items[$target]['title'] ?? ''));
        return new RedirectResponse('/admin/nav');
    }

    /**
     * 保存一条导航项：显示名称、链接、是否隐藏。
     *
     * @param array<string, string> $args
     */
    public function update(Request $request, array $args): HtmlResponse|RedirectResponse
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

        $items = $this->navItems();
        $index = (int) $args['index'];
        if (!isset($items[$index])) {
            Flash::set('error', '没有找到这个导航项。');
            return new RedirectResponse('/admin/nav');
        }

        $title = trim($request->post('title'));
        $url = trim($request->post('url'));
        if ($title === '' || $url === '') {
            Flash::set('error', '导航名称与链接都不能为空。');
            return new RedirectResponse('/admin/nav');
        }
        if (preg_match('#^\s*(javascript|data|vbscript):#i', $url) === 1) {
            Flash::set('error', '链接不支持 javascript: 这类协议。');
            return new RedirectResponse('/admin/nav');
        }

        $items[$index]['title'] = $title;
        $items[$index]['url'] = $url;
        if ($request->post('hidden') === '1') {
            $items[$index]['hidden'] = true;
        } else {
            unset($items[$index]['hidden']);
        }

        $this->home->saveBlock('nav', array_values($items));
        $this->log('nav.update', 'home', 'nav', ['title' => $title, 'url' => $url, 'hidden' => $request->post('hidden') === '1']);
        Flash::set('ok', '已保存导航项：' . $title);
        return new RedirectResponse('/admin/nav');
    }

    /**
     * nav 块条目；每条补上「指向哪个栏目、这个栏目有多少稿件」的提示。
     *
     * @return list<array<string, mixed>>
     */
    private function navItems(): array
    {
        $payload = $this->home->block('nav');
        $items = [];
        foreach (is_array($payload) ? $payload : [] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $url = (string) ($item['url'] ?? '');
            $items[] = [
                'title'  => (string) ($item['title'] ?? ''),
                'url'    => $url,
                'hidden' => !empty($item['hidden']),
                'channel' => $this->channelFromUrl($url),
            ];
        }
        return $items;
    }

    /** 从导航链接里认出栏目号：旧站 news_list.php?id=904 与新版 channel.html?id=904 都认 */
    private function channelFromUrl(string $url): string
    {
        return preg_match('/[?&]id=(\d+)/', $url, $m) === 1 ? $m[1] : '';
    }

    /**
     * 栏目号 => 稿件数，用于列表里给编辑一个规模提示。
     *
     * @return array<string, int>
     */
    private function channelCounts(): array
    {
        $counts = [];
        foreach ($this->channels->adminAll() as $channel) {
            $counts[(string) $channel['type_code']] = (int) $channel['article_count'];
        }
        return $counts;
    }
}

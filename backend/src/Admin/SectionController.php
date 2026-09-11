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
use HechiZx\Support\Json;

/**
 * 其他栏目：首页正文里那些「由某个栏目稿件构成」的模块。
 *
 * 每个模块记三件事：绑哪个（或哪几个）栏目、取几条、上不上线；列表由稿件现算，
 * 所以编辑发稿就上首页，不需要再手工维护一份首页清单。
 * 页面下半部分列出没进首页导航的栏目，方便直接进它们的稿件列表。
 */
final class SectionController extends AdminController
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
        if ($notReady = $this->requireHomeTables($this->home)) {
            return $notReady;
        }
        if ($denied = $this->requirePermission(Permissions::HOME_MANAGE)) {
            return $denied;
        }

        $sections = [];
        foreach ($this->home->sectionRows() as $row) {
            $key = (string) $row['section_key'];
            $blocks = $this->home->blocks([$key]);
            $sections[] = [
                'row'     => $row,
                'kind'    => $this->scopeKind($row),
                'scopeText' => $this->scopeText($row),
                'channels' => $this->scopeChannelNames($row),
                'firstChannel' => $this->firstScopeChannel($row),
                'preview' => $this->previewOf($blocks[$key] ?? null),
            ];
        }

        return $this->view->page('admin/sections', [
            'current'       => 'sections',
            'sections'      => $sections,
            'channelNames'  => $this->channelNames(),
            'otherGroups'   => $this->otherChannelGroups(),
        ], '其他栏目');
    }

    /**
     * @param array<string, string> $args
     */
    public function update(Request $request, array $args): HtmlResponse|RedirectResponse
    {
        if ($redirect = $this->requireLogin()) {
            return $redirect;
        }
        if ($notReady = $this->requireHomeTables($this->home)) {
            return $notReady;
        }
        if ($denied = $this->guard($request)) {
            return $denied;
        }
        if ($denied = $this->requirePermission(Permissions::HOME_MANAGE)) {
            return $denied;
        }

        $key = (string) $args['key'];
        $section = $this->home->sectionFind($key);
        if ($section === null) {
            Flash::set('error', '没有找到这个首页模块。');
            return new RedirectResponse('/admin/sections');
        }

        $kind = $request->post('kind');
        if (!in_array($kind, ['channels', 'parent', 'tabs'], true)) {
            $kind = 'channels';
        }

        $known = $this->channelNames();
        $parse = static function (string $value): array {
            $parts = preg_split('/[\s,，、|]+/u', $value) ?: [];
            return array_values(array_filter(array_map('trim', $parts), static fn (string $v): bool => $v !== ''));
        };

        $scope = [];
        $unknown = [];
        if ($kind === 'parent') {
            $parent = trim($request->post('scope'));
            if ($parent === '' || !isset($known[$parent])) {
                $unknown[] = $parent === '' ? '（空）' : $parent;
            } else {
                $scope = ['parent' => $parent];
            }
        } elseif ($kind === 'tabs') {
            $tabs = [];
            foreach (preg_split('/\r\n|\n|\r/', (string) $request->post('scope_tabs')) ?: [] as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $pieces = array_map('trim', explode('|', $line, 2));
                $channel = $pieces[0] ?? '';
                if ($channel === '' || !isset($known[$channel])) {
                    $unknown[] = $channel === '' ? $line : $channel;
                    continue;
                }
                $tabs[] = ['channel' => $channel, 'label' => $pieces[1] ?? ''];
            }
            if ($tabs === [] && $unknown === []) {
                Flash::set('error', '分标签模式至少要写一行「栏目号|标签名」。');
                return new RedirectResponse('/admin/sections');
            }
            $scope = ['tabs' => $tabs];
        } else {
            $channels = $parse((string) $request->post('scope'));
            foreach ($channels as $channel) {
                if (!isset($known[$channel])) {
                    $unknown[] = $channel;
                }
            }
            $scope = ['channels' => array_values(array_diff($channels, $unknown))];
        }

        if ($unknown !== []) {
            Flash::set('error', '这些栏目号不存在：' . implode('、', array_slice(array_unique($unknown), 0, 5)));
            return new RedirectResponse('/admin/sections');
        }

        $pageSize = (int) $request->post('page_size', '10');
        $pageSize = max(1, min(30, $pageSize));
        $status = $request->post('status') === 'offline' ? 'offline' : 'published';

        $this->db->execute(
            'UPDATE cms_home_section
             SET label = :label, scope_json = :scope, page_size = :size, group_by = :group,
                 more_url = :more, status = :status, updated_at = :t
             WHERE site_id = :site AND section_key = :key',
            [
                'label'  => trim($request->post('label')),
                'scope'  => Json::encode($scope),
                'size'   => $pageSize,
                'group'  => $kind === 'tabs' ? 'child' : '',
                'more'   => trim($request->post('more_url')),
                'status' => $status,
                't'      => $this->db->now(),
                'site'   => $this->siteId,
                'key'    => $key,
            ]
        );
        $this->log('section.update', 'home', $key, ['kind' => $kind, 'scope' => $scope, 'size' => $pageSize, 'status' => $status]);
        Flash::set('ok', '已保存首页模块：' . (string) $section['label']);
        return new RedirectResponse('/admin/sections');
    }

    /** @param array<string, mixed> $row */
    private function scopeKind(array $row): string
    {
        $scope = Json::decode((string) $row['scope_json'], []);
        if (is_array($scope) && isset($scope['tabs'])) {
            return 'tabs';
        }
        if (is_array($scope) && isset($scope['parent'])) {
            return 'parent';
        }
        return 'channels';
    }

    /** 模块绑定的第一个栏目号：列表页「进稿件管理」用 */
    private function firstScopeChannel(array $row): string
    {
        $scope = Json::decode((string) $row['scope_json'], []);
        if (!is_array($scope)) {
            return '';
        }
        if (isset($scope['tabs'][0]['channel'])) {
            return (string) $scope['tabs'][0]['channel'];
        }
        if (isset($scope['parent'])) {
            return (string) $scope['parent'];
        }
        return (string) ($scope['channels'][0] ?? '');
    }

    /** @param array<string, mixed> $row */
    private function scopeText(array $row): string
    {
        $scope = Json::decode((string) $row['scope_json'], []);
        if (!is_array($scope)) {
            return '';
        }
        if (isset($scope['tabs'])) {
            $lines = [];
            foreach ((array) $scope['tabs'] as $tab) {
                $lines[] = (string) ($tab['channel'] ?? '') . '|' . (string) ($tab['label'] ?? '');
            }
            return implode("\n", $lines);
        }
        if (isset($scope['parent'])) {
            return (string) $scope['parent'];
        }
        return implode(',', array_map('strval', (array) ($scope['channels'] ?? [])));
    }

    /**
     * 模块绑定的栏目名，页面直接显示成人能读的写法。
     *
     * @param array<string, mixed> $row
     * @return list<string>
     */
    private function scopeChannelNames(array $row): array
    {
        $names = $this->channelNames();
        $scope = Json::decode((string) $row['scope_json'], []);
        $out = [];
        if (!is_array($scope)) {
            return $out;
        }
        if (isset($scope['tabs'])) {
            foreach ((array) $scope['tabs'] as $tab) {
                $channel = (string) ($tab['channel'] ?? '');
                $out[] = (string) ($tab['label'] ?? '') . '（' . $channel . ' ' . ($names[$channel] ?? '?') . '）';
            }
            return $out;
        }
        if (isset($scope['parent'])) {
            $parent = (string) $scope['parent'];
            return ['一级栏目 ' . $parent . ' ' . ($names[$parent] ?? '?') . ' 及其全部子栏目'];
        }
        foreach ((array) ($scope['channels'] ?? []) as $channel) {
            $type = (string) $channel;
            $out[] = $type . ' ' . ($names[$type] ?? '?');
        }
        return $out;
    }

    /**
     * 模块当前取到的内容摘要：列表给前几条标题，标签页给每个标签的条数。
     *
     * @return array{tabs:list<array{title:string,count:int,titles:list<string>}>, titles:list<string>, count:int}
     */
    private function previewOf(mixed $payload): array
    {
        $out = ['tabs' => [], 'titles' => [], 'count' => 0];
        if (!is_array($payload)) {
            return $out;
        }
        if (isset($payload['tabs']) && is_array($payload['tabs'])) {
            foreach ($payload['tabs'] as $tab) {
                $items = is_array($tab['items'] ?? null) ? $tab['items'] : [];
                $out['tabs'][] = [
                    'title'  => (string) ($tab['title'] ?? ''),
                    'count'  => count($items),
                    'titles' => array_map(static fn (array $i): string => (string) ($i['title'] ?? ''), array_slice($items, 0, 3)),
                ];
                $out['count'] += count($items);
            }
            return $out;
        }
        foreach (['list', 'dynamic'] as $part) {
            if (isset($payload[$part]) && is_array($payload[$part])) {
                $items = $payload[$part];
                $out['titles'] = array_map(static fn (array $i): string => (string) ($i['title'] ?? ''), array_slice($items, 0, 5));
                $out['count'] = count($items);
                return $out;
            }
        }
        if (array_is_list($payload)) {
            $out['titles'] = array_map(static fn (array $i): string => (string) ($i['title'] ?? ''), array_slice($payload, 0, 5));
            $out['count'] = count($payload);
        }
        return $out;
    }

    /** @return array<string, string> 栏目号 => 子栏目名 */
    private function channelNames(): array
    {
        $names = [];
        foreach ($this->channels->adminAll() as $channel) {
            $names[(string) $channel['type_code']] = (string) $channel['inner_name'];
        }
        return $names;
    }

    /**
     * 没进首页导航的栏目，按一级栏目分组——它们也能直接进稿件列表。
     *
     * @return list<array{key:string, title:string, channels:list<array<string,mixed>>}>
     */
    private function otherChannelGroups(): array
    {
        $nav = $this->home->block('nav');
        $inNav = [];
        foreach (is_array($nav) ? $nav : [] as $item) {
            if (is_array($item) && preg_match('/[?&]id=(\d+)/', (string) ($item['url'] ?? ''), $m) === 1) {
                $inNav[] = $m[1];
            }
        }

        $groups = [];
        foreach ($this->channels->navGroups() as $group) {
            $children = array_values(array_filter(
                $group['channels'],
                static fn (array $channel): bool => !in_array((string) $channel['type_code'], $inNav, true)
            ));
            if ($children !== []) {
                $group['channels'] = $children;
                $groups[] = $group;
            }
        }
        return $groups;
    }
}

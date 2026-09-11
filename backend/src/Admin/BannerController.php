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
 * 站内横幅：首页上 7 个固定的图片位。
 *
 * 槽位是前端页面上定死的位置（首屏两张专题条幅、正文里 5 处横幅），
 * 后台只换图、换链接、改 alt、上下线，不增删位置——位置一变前台版式就得跟着改。
 */
final class BannerController extends AdminController
{
    /** 槽位定义：key => [位置说明, 建议尺寸说明] */
    public const SLOTS = [
        'hero-1' => ['首屏专题条幅 · 左', '与右侧等宽，横图'],
        'hero-2' => ['首屏专题条幅 · 右', '与左侧等宽，横图'],
        'body-1' => ['政协动态下方（通栏插画，可不填链接）', '通栏横图'],
        'body-2' => ['时政要闻下方', '通栏横图'],
        'body-3' => ['网上书院下方', '侧栏宽横图'],
        'body-4' => ['政协视频卡内', '侧栏宽横图'],
        'body-5' => ['页面底部通栏', '通栏横图'],
    ];

    public function __construct(
        Auth $auth,
        View $view,
        Db $db,
        int $siteId,
        private HomeRepository $home,
        string $uploadsDir
    ) {
        parent::__construct($auth, $view, $db, $siteId, $uploadsDir);
    }

    public function index(Request $request): HtmlResponse|RedirectResponse
    {
        if ($redirect = $this->requireLogin()) {
            return $redirect;
        }
        if ($denied = $this->requirePermission(Permissions::HOME_MANAGE)) {
            return $denied;
        }

        // 以固定槽位为准展示：某一行被删掉了也照样列出来，保存时会补建
        $rows = [];
        foreach ($this->home->bannerRows() as $row) {
            $rows[(string) $row['slot_key']] = $row;
        }
        $slots = [];
        foreach (self::SLOTS as $key => [$label, $hint]) {
            $slots[] = [
                'slot'  => $key,
                'label' => $label,
                'hint'  => $hint,
                'row'   => $rows[$key] ?? null,
            ];
        }

        return $this->view->page('admin/banners', [
            'current' => 'banners',
            'slots'   => $slots,
        ], '站内横幅');
    }

    /**
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

        $slot = (string) $args['slot'];
        if (!isset(self::SLOTS[$slot])) {
            Flash::set('error', '没有这个横幅位：' . $slot);
            return new RedirectResponse('/admin/banners');
        }

        $image = trim($request->post('image_url'));
        $file = $_FILES['image'] ?? null;
        if (is_array($file) && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            try {
                $image = $this->storeHomeImage($file);
            } catch (\RuntimeException $e) {
                Flash::set('error', '图片上传失败：' . $e->getMessage());
                return new RedirectResponse('/admin/banners');
            }
        }

        $link = trim($request->post('link_url'));
        if ($link !== '' && preg_match('#^\s*(javascript|data|vbscript):#i', $link) === 1) {
            Flash::set('error', '链接不支持 javascript: 这类协议。');
            return new RedirectResponse('/admin/banners');
        }

        $status = $request->post('status') === 'offline' ? 'offline' : 'published';
        $existing = null;
        foreach ($this->home->bannerRows() as $row) {
            if ((string) $row['slot_key'] === $slot) {
                $existing = $row;
                break;
            }
        }

        $title = trim($request->post('title'));
        $now = $this->db->now();
        if ($existing === null) {
            $this->db->execute(
                'INSERT INTO cms_home_banner (site_id, slot_key, title, image_url, link_url, sort_no, status, created_at, updated_at)
                 VALUES (:site, :slot, :title, :img, :link, :sort, :status, :t, :t)',
                [
                    'site'   => $this->siteId,
                    'slot'   => $slot,
                    'title'  => $title,
                    'img'    => $image,
                    'link'   => $link,
                    'sort'   => array_search($slot, array_keys(self::SLOTS), true) + 1,
                    'status' => $status,
                    't'      => $now,
                ]
            );
        } else {
            $this->db->execute(
                'UPDATE cms_home_banner
                 SET title = :title, image_url = :img, link_url = :link, status = :status, updated_at = :t
                 WHERE site_id = :site AND banner_id = :id',
                [
                    'title'  => $title,
                    'img'    => $image,
                    'link'   => $link,
                    'status' => $status,
                    't'      => $now,
                    'site'   => $this->siteId,
                    'id'     => (int) $existing['banner_id'],
                ]
            );
        }

        $this->log('banner.update', 'home', $slot, ['status' => $status, 'image' => $image]);
        Flash::set('ok', '已保存横幅位：' . self::SLOTS[$slot][0]);
        return new RedirectResponse('/admin/banners');
    }
}

<?php

declare(strict_types=1);

namespace HechiZx\Admin;

use HechiZx\Http\HtmlResponse;
use HechiZx\Http\RedirectResponse;
use HechiZx\Http\Request;
use HechiZx\Support\Config;
use HechiZx\Support\Db;
use HechiZx\Support\HealthProbe;
use Throwable;

/**
 * 接口状态自检页。
 *
 * 侧栏原来直接打开 /api/v1/health，浏览器里就是一串 JSON：值班的人得自己认字段，
 * 也看不出「本地/生产」「发布目录不可写」这类真正会出事的状态。这里把探活结果、
 * 运行环境与目录写权限放到一页里，与监控共用同一份探活代码，原始 JSON 仍留链接。
 *
 * 权限：登录即可看。侧栏那个「接口状态」本来就是人人可见的入口，且
 * /api/v1/health 本身是对外公开的，这里只是把同样的信息换成能读的样子。
 */
final class HealthController extends AdminController
{
    public function __construct(Auth $auth, View $view, Db $db, int $siteId, private Config $config)
    {
        parent::__construct($auth, $view, $db, $siteId);
    }

    public function index(Request $request): HtmlResponse|RedirectResponse
    {
        if ($redirect = $this->requireLogin()) {
            return $redirect;
        }

        // 探活走与 /api/v1/health 同一份代码：页面上的结论和监控拿到的必须是同一个
        $probe = [];
        $probeError = '';
        $started = microtime(true);
        try {
            $probe = (new HealthProbe($this->db))->run();
        } catch (Throwable $error) {
            $probeError = $error->getMessage();
        }
        $elapsedMs = (int) round((microtime(true) - $started) * 1000);

        return $this->view->page('admin/health', [
            'current'    => 'health',
            'probe'      => $probe,
            'probeError' => $probeError,
            'elapsedMs'  => $elapsedMs,
            // 本机毫秒级抖动看不出差别，别显示成「0 ms」让人以为是没测
            'elapsedText' => $elapsedMs > 0 ? $elapsedMs . ' ms' : '不到 1 ms',
            'checkedAt'  => date('Y-m-d H:i:s'),
            'environment' => $this->environment(),
            'storage'     => $this->storage(),
        ], '接口状态');
    }

    /**
     * 运行环境：出错时最常要核对的几项。
     *
     * @return list<array{label:string,value:string,tag:string,tagClass:string}>
     */
    private function environment(): array
    {
        $env = (string) $this->config->get('app.env', 'local');
        $debug = (bool) $this->config->get('app.debug', false);

        return [
            [
                'label' => '应用环境',
                'value' => $env,
                'tag'   => $env === 'production' ? '' : '非生产环境',
                'tagClass' => 'tag-draft',
            ],
            [
                'label' => '调试模式',
                'value' => $debug ? '开（报错会显示细节，上线前请关掉）' : '关',
                'tag'   => $debug ? '建议关闭' : '',
                'tagClass' => 'tag-draft',
            ],
            [
                'label' => 'PHP',
                'value' => PHP_VERSION,
                'tag'   => '',
                'tagClass' => '',
            ],
            [
                'label' => '时区',
                'value' => date_default_timezone_get(),
                'tag'   => '',
                'tagClass' => '',
            ],
            [
                'label' => '站点',
                'value' => (string) $this->config->get('site.name', '')
                    . '（' . (string) $this->config->get('site.domain', '') . '，site_id ' . $this->siteId . '）',
                'tag'   => '',
                'tagClass' => '',
            ],
        ];
    }

    /**
     * 数据与目录：真正会「静默失败」的两件事——库读不到、目录写不了。
     *
     * @return list<array{label:string,value:string,tag:string,tagClass:string}>
     */
    private function storage(): array
    {
        $driver = strtolower($this->db->driver());
        $rows = [];

        if ($driver === 'sqlite') {
            $file = (string) $this->config->get('db.database', '');
            $exists = $file !== '' && is_file($file);
            $rows[] = [
                'label' => '数据库文件',
                'value' => $file . ($exists ? '（' . $this->humanSize((int) filesize($file)) . '）' : ''),
                'tag'   => $exists ? '可读' : '找不到文件',
                'tagClass' => $exists ? 'tag-published' : 'tag-rejected',
            ];
        } else {
            $host = (string) $this->config->get('db.host', '');
            $port = (int) $this->config->get('db.port', 3306);
            $name = (string) $this->config->get('db.database', '');
            $rows[] = [
                'label' => '数据库',
                'value' => $driver . ' · ' . $host . ':' . $port . '/' . $name,
                'tag'   => '连接正常',
                'tagClass' => 'tag-published',
            ];
        }

        $rows[] = $this->dirRow('发布目录', (string) $this->config->get('publish.out', ''));
        $rows[] = $this->dirRow('上传目录', (string) $this->config->get('paths.uploads', ''));

        return $rows;
    }

    /** @return array{label:string,value:string,tag:string,tagClass:string} */
    private function dirRow(string $label, string $path): array
    {
        if ($path === '' || !is_dir($path)) {
            return [
                'label' => $label,
                'value' => $path === '' ? '未配置' : $path . '（目录不存在）',
                'tag'   => '不可用',
                'tagClass' => 'tag-rejected',
            ];
        }
        $writable = is_writable($path);

        return [
            'label' => $label,
            'value' => $path,
            'tag'   => $writable ? '可写' : '只读',
            'tagClass' => $writable ? 'tag-published' : 'tag-rejected',
        ];
    }

    private function humanSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1) . ' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 1) . ' KB';
        }
        return $bytes . ' B';
    }
}

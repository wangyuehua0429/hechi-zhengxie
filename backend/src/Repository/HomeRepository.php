<?php

declare(strict_types=1);

namespace HechiZx\Repository;

use HechiZx\Support\Db;
use HechiZx\Support\Json;

/**
 * 首页模块仓储：原型期按模块整块存 JSON（cms_home_block），
 * 与 frontend/home/data/home.json 的 21 个顶层键一一对应；
 * 正式版按模块建模后，接口输出结构不变。
 */
final class HomeRepository
{
    public function __construct(private Db $db, private int $siteId)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function blocks(?array $keys = null): array
    {
        $rows = $this->db->select(
            'SELECT block_key, payload_json FROM cms_home_block WHERE site_id = :site ORDER BY sort_no ASC, block_id ASC',
            ['site' => $this->siteId]
        );

        $blocks = [];
        foreach ($rows as $row) {
            $key = (string) $row['block_key'];
            if ($keys !== null && !in_array($key, $keys, true)) {
                continue;
            }
            $blocks[$key] = Json::decode($row['payload_json'], null);
        }
        return $blocks;
    }
}

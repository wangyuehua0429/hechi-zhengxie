<?php

declare(strict_types=1);

namespace HechiZx\Support;

/**
 * 探活结果：对外接口 /api/v1/health 与后台「接口状态」页共用同一份代码。
 *
 * 抽出来是为了口径一致——两边各写一遍的话，值班看后台页面显示正常、
 * 监控那边却报错，没人说得清该信哪个。返回结构即接口契约（docs/api-contract.md 4.1）。
 */
final class HealthProbe
{
    public function __construct(private Db $db)
    {
    }

    /** @return array<string, mixed> */
    public function run(): array
    {
        return [
            'status' => 'ok',
            'driver' => $this->db->driver(),
            'time'   => $this->db->now(),
        ];
    }
}

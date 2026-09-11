<?php

declare(strict_types=1);

namespace HechiZx\Api;

use HechiZx\Http\Request;
use HechiZx\Support\Db;

/**
 * 探活：部署自检与监控用（docs/api-contract.md 4.1）。
 */
final class HealthController
{
    public function __construct(private Db $db)
    {
    }

    /** @return array<string, mixed> */
    public function show(Request $request): array
    {
        return [
            'status' => 'ok',
            'driver' => $this->db->driver(),
            'time'   => $this->db->now(),
        ];
    }
}

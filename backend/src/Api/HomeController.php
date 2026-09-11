<?php

declare(strict_types=1);

namespace HechiZx\Api;

use HechiZx\Http\Request;
use HechiZx\Repository\HomeRepository;

/**
 * 首页聚合数据（docs/api-contract.md 4.2）。
 */
final class HomeController
{
    public function __construct(private HomeRepository $home)
    {
    }

    /** @return array<string, mixed> */
    public function show(Request $request): array
    {
        return ['home' => $this->home->blocks()];
    }
}

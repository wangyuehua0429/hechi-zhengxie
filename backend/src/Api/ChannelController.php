<?php

declare(strict_types=1);

namespace HechiZx\Api;

use HechiZx\Http\ApiException;
use HechiZx\Http\Request;
use HechiZx\Repository\ChannelRepository;

/**
 * 栏目接口（docs/api-contract.md 4.3、4.4）。
 */
final class ChannelController
{
    public function __construct(private ChannelRepository $channels)
    {
    }

    /** @return array<string, mixed> */
    public function index(Request $request): array
    {
        $withList = $request->query('withList', '1') !== '0';
        $listSize = $request->int('listSize', 50, 0, 200);
        $layout = $request->query('layout');
        $column = $request->query('column');

        return [
            'channels' => $this->channels->all($withList, $listSize, $layout, $column),
        ];
    }

    /**
     * @param array<string, string> $args
     * @return array<string, mixed>
     */
    public function show(Request $request, array $args): array
    {
        $type = $args['type'];
        $withList = $request->query('withList', '1') !== '0';
        $listSize = $request->int('listSize', 50, 0, 200);

        $channel = $this->channels->byType($type, $withList, $listSize);
        if ($channel === null) {
            throw ApiException::notFound('未找到栏目 ' . $type);
        }

        return ['channel' => $channel];
    }

    /**
     * 链接映射用的精简索引（docs/api-contract.md 4.3 说明的 withList=0 场景的等价物）。
     *
     * @return array<string, mixed>
     */
    public function indexMap(Request $request): array
    {
        return [
            'channels' => $this->channels->indexMap($request->int('listSize', 50, 1, 200)),
        ];
    }
}

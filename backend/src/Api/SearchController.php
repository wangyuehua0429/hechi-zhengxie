<?php

declare(strict_types=1);

namespace HechiZx\Api;

use HechiZx\Http\ApiException;
use HechiZx\Http\Request;
use HechiZx\Repository\ArticleRepository;

/**
 * 站内检索（docs/api-contract.md 4.8）：本期走 LIKE，接口结构不变。
 * 默认查标题＋摘要＋正文（契约口径），scope=title 时只查标题（对齐旧站“标题”一档）。
 */
final class SearchController
{
    private const SCOPES = ['all', 'title'];

    public function __construct(
        private ArticleRepository $articles,
        private int $defaultSize = 20,
        private int $maxSize = 100
    ) {
    }

    /** @return array<string, mixed> */
    public function index(Request $request): array
    {
        $keyword = trim((string) $request->query('q', ''));
        if ($keyword === '') {
            throw ApiException::badRequest('缺少检索词 q');
        }

        $scope = strtolower(trim((string) $request->query('scope', 'all')));
        if ($scope === '') {
            $scope = 'all';
        }
        if (!in_array($scope, self::SCOPES, true)) {
            throw ApiException::badRequest('scope 只支持 all（标题＋摘要＋正文）或 title（标题）');
        }

        $page = $request->int('page', 1, 1);
        $size = $request->int('size', $this->defaultSize, 1, $this->maxSize);

        $result = $this->articles->paginate([], $page, $size, $keyword, 'date_desc', $scope);

        return [
            'articles' => $this->articles->attachExcerpts($result['items'], $keyword),
            'page'     => $page,
            'size'     => $size,
            'total'    => $result['total'],
            'pages'    => (int) ceil($result['total'] / $size),
            'q'        => $keyword,
            'scope'    => $scope,
        ];
    }
}

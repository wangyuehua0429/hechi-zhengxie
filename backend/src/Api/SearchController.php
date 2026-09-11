<?php

declare(strict_types=1);

namespace HechiZx\Api;

use HechiZx\Http\ApiException;
use HechiZx\Http\Request;
use HechiZx\Repository\ArticleRepository;

/**
 * 站内检索（docs/api-contract.md 4.8）：本期走 LIKE，接口结构不变。
 */
final class SearchController
{
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

        $page = $request->int('page', 1, 1);
        $size = $request->int('size', $this->defaultSize, 1, $this->maxSize);

        $result = $this->articles->paginate([], $page, $size, $keyword);

        return [
            'articles' => $result['items'],
            'page'     => $page,
            'size'     => $size,
            'total'    => $result['total'],
            'pages'    => (int) ceil($result['total'] / $size),
            'q'        => $keyword,
        ];
    }
}

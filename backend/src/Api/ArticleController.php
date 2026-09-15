<?php

declare(strict_types=1);

namespace HechiZx\Api;

use HechiZx\Http\ApiException;
use HechiZx\Http\Request;
use HechiZx\Content\BodyNormalizer;
use HechiZx\Content\HtmlSanitizer;
use HechiZx\Repository\ArticleRepository;

/**
 * 稿件接口（docs/api-contract.md 4.5、4.6、4.7）。
 */
final class ArticleController
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
        $page = $request->int('page', 1, 1);
        $size = $request->int('size', $this->defaultSize, 1, $this->maxSize);
        $keyword = (string) $request->query('q', '');
        $order = (string) $request->query('order', 'date_desc');

        $types = array_values(array_filter(array_map('trim', explode(',', (string) $request->query('channel', '')))));

        $result = $this->articles->paginate($types, $page, $size, $keyword, $order);

        return [
            'articles' => $result['items'],
            'page'     => $page,
            'size'     => $size,
            'total'    => $result['total'],
            'pages'    => $size > 0 ? (int) ceil($result['total'] / $size) : 0,
        ];
    }

    /**
     * @param array<string, string> $args
     * @return array<string, mixed>
     */
    public function show(Request $request, array $args): array
    {
        $article = $this->articles->byId($args['id']);
        if ($article === null) {
            throw ApiException::notFound('未找到稿件 ' . $args['id']);
        }
        // 出口兜底：库里可能还有编辑器上线之前写入的正文，输出前统一过白名单；
        // 再按展示口径归一化（去空段、裁多余缩进、拍平嵌套块、改写旧站资源地址、去标题重复行）。
        // 归一化只发生在出口，库里的 content_html 保持原样，后台编辑回填不受影响。
        $article['content'] = BodyNormalizer::normalize(
            HtmlSanitizer::clean((string) ($article['content'] ?? '')),
            (string) $article['title'],
            (string) ($article['author'] ?? '')
        );
        // 图集出口只留图片：旧库里有 50 条图集记录是 mp4，前台会渲染成空白格
        $article['images'] = BodyNormalizer::normalizeImages((array) ($article['images'] ?? []));

        return ['article' => $article];
    }

    /**
     * @param array<string, string> $args
     * @return array<string, mixed>
     */
    public function attachments(Request $request, array $args): array
    {
        $article = $this->articles->byId($args['id']);
        if ($article === null) {
            throw ApiException::notFound('未找到稿件 ' . $args['id']);
        }
        return ['attachments' => $article['attachments']];
    }

    /** @return array<string, mixed> */
    public function attachmentsByQuery(Request $request): array
    {
        $id = (string) $request->query('article', '');
        if ($id === '') {
            throw ApiException::badRequest('缺少 article 参数');
        }
        $article = $this->articles->byId($id);
        if ($article === null) {
            throw ApiException::notFound('未找到稿件 ' . $id);
        }
        return ['attachments' => $article['attachments']];
    }
}

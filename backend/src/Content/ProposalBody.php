<?php

declare(strict_types=1);

namespace HechiZx\Content;

/**
 * 提案正文口径：入库前统一清洗（只留分段、加粗、下划线、列表），
 * 字数按「纯文本字符数」计——标点算，空格、换行、制表符不算。
 *
 * 前后端必须同一算法：网页那一侧在 assets/member-editor.js 里按同一规则数，
 * 两端口径不一致时用户会看到「页面说没超、提交却被挡」。
 */
final class ProposalBody
{
    /** 正文上限（字），提案委 2026-09-16 确认按整体 2000 字 */
    public const MAX_CHARS = 2000;

    public static function clean(string $html): string
    {
        return trim(HtmlSanitizer::cleanProposal($html));
    }

    /** HTML 转纯文本：块级边界换成换行，实体还原，便于统计与导出 */
    public static function plainText(string $html): string
    {
        $text = preg_replace('#<\s*(br|/p|/div|/li|/ul|/ol|/h[1-6])\s*/?\s*>#i', "\n", $html) ?? $html;
        $text = str_replace(['&nbsp;', "\xC2\xA0"], ' ', (string) $text);

        return html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** 正文字数：去掉所有空白后的字符数（与前端 Array.from(text).length 一致） */
    public static function charCount(string $html): int
    {
        $stripped = preg_replace('/\s+/u', '', self::plainText($html));

        return mb_strlen((string) $stripped, 'UTF-8');
    }

    public static function isEmpty(string $html): bool
    {
        return self::charCount($html) === 0;
    }

    /**
     * 按段落拆成文本行，供 Word 导出与摘要使用。
     *
     * @return list<string>
     */
    public static function paragraphs(string $html): array
    {
        $lines = [];
        foreach (preg_split('/\n+/', self::plainText($html)) ?: [] as $line) {
            $line = trim((string) $line);
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /** 列表页可用的一行摘要 */
    public static function summary(string $html, int $limit = 60): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', self::plainText($html)) ?? '');
        if ($text === '') {
            return '';
        }

        return mb_strlen($text, 'UTF-8') > $limit ? mb_substr($text, 0, $limit, 'UTF-8') . '…' : $text;
    }
}

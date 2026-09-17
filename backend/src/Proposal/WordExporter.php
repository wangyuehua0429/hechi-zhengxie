<?php

declare(strict_types=1);

namespace HechiZx\Proposal;

use HechiZx\Content\ProposalBody;
use HechiZx\Content\ProposalWorkflow;

/**
 * 提案表 Word 导出（.docx）：A4、案由三号居中、正文小四宋体、行距 28 磅、段落首行缩进 2 字。
 * 委员端「下载本人提案」、提案委「单件导出」与「批量导出」共用这一份生成器，保证三边口径一致。
 *
 * 正文是轻量富文本（p／br／strong／u／ul／ol），这里把它翻译成对应 Word 段落与运行格式：
 * 加粗、下划线、项目符号与编号都保留；字体字号统一由模板定，正文里没有字号颜色，不存在带进来一说。
 */
final class WordExporter
{
    private const BODY_SIZE = 24;     // 小四 = 12pt = 24 半磅
    private const TITLE_SIZE = 32;    // 三号 = 16pt = 32 半磅
    private const LINE_TWIPS = 560;   // 固定行距 28 磅
    private const INDENT_TWIPS = 480; // 首行缩进 2 字（12pt 字号的两个字）
    private const LIST_INDENT = 480;  // 列表缩进

    /**
     * 单件提案表。
     *
     * @param array<string,mixed> $proposal
     * @param list<array<string,mixed>> $attachments
     * @param list<array<string,mixed>> $coMembers
     * @param list<array<string,mixed>> $units
     */
    public static function proposal(array $proposal, array $attachments = [], array $coMembers = [], array $units = []): string
    {
        return self::document([
            self::item($proposal, $attachments, $coMembers, $units),
        ]);
    }

    /**
     * 批量导出：把多份提案装进同一个 Word，每份从新的一页开始。
     *
     * @param list<array{proposal:array<string,mixed>,attachments?:list<array<string,mixed>>,coMembers?:list<array<string,mixed>>,units?:list<array<string,mixed>>}> $items
     */
    public static function batch(array $items): string
    {
        $blocks = [];
        foreach ($items as $item) {
            $blocks[] = self::item(
                (array) ($item['proposal'] ?? []),
                (array) ($item['attachments'] ?? []),
                (array) ($item['coMembers'] ?? []),
                (array) ($item['units'] ?? [])
            );
        }

        return self::document($blocks);
    }

    /**
     * @param list<string> $blocks 每份提案的正文 XML
     */
    private static function document(array $blocks): string
    {
        $body = '';
        foreach ($blocks as $index => $block) {
            if ($index > 0) {
                // 分页符：每份提案独立起页，装订与归档时好翻
                $body .= '<w:p><w:r><w:br w:type="page"/></w:r></w:p>';
            }
            $body .= $block;
        }

        $document = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            . '<w:body>' . $body . self::sectionProperties() . '</w:body></w:document>';

        return OfficePackage::build([
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                . '<Default Extension="xml" ContentType="application/xml"/>'
                . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
                . '</Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
                . '</Relationships>',
            'word/document.xml' => $document,
        ]);
    }

    /**
     * 一份提案的正文 XML。
     *
     * @param array<string,mixed> $proposal
     * @param list<array<string,mixed>> $attachments
     * @param list<array<string,mixed>> $coMembers
     * @param list<array<string,mixed>> $units
     */
    private static function item(array $proposal, array $attachments, array $coMembers, array $units): string
    {
        $body = self::paragraph((string) ($proposal['title'] ?? '案由'), [
            'size' => self::TITLE_SIZE, 'bold' => true, 'align' => 'center', 'line' => 600, 'spaceAfter' => 200,
        ]);

        $status = ProposalWorkflow::normalize((string) ($proposal['status'] ?? ''));
        $unitNames = array_map(static fn (array $row): string => (string) ($row['unit_name'] ?? ''), $units);
        $pairs = [
            '提案人' => (string) ($proposal['proposer_name'] ?? ''),
            '提案人类别' => ProposalWorkflow::proposerTypeLabel((string) ($proposal['proposer_type'] ?? 'personal')),
            '界别' => (string) ($proposal['sector'] ?? ''),
            '专委会' => (string) ($proposal['committee'] ?? ''),
            '提案类别' => (string) ($proposal['category'] ?? ''),
            '建议承办单位' => implode('、', $unitNames),
            '提交时间' => (string) ($proposal['submitted_at'] ?? ''),
            '当前状态' => ProposalWorkflow::label($status),
        ];
        if (trim((string) ($proposal['collective_name'] ?? '')) !== '') {
            $pairs['集体名称'] = (string) $proposal['collective_name'];
        }
        foreach ($pairs as $label => $value) {
            if (trim((string) $value) === '') {
                continue;
            }
            $body .= self::paragraph($label . '：' . $value, [
                'size' => self::BODY_SIZE, 'indent' => false, 'spaceAfter' => 40,
            ]);
        }

        // 联名委员逐个成行：姓名、单位及职务、联系电话齐全才好联系
        if ($coMembers !== []) {
            $body .= self::paragraph('联名委员', ['size' => self::BODY_SIZE, 'bold' => true, 'indent' => false, 'spaceBefore' => 200, 'spaceAfter' => 40]);
            $index = 1;
            foreach ($coMembers as $row) {
                $parts = array_filter([
                    (string) ($row['name'] ?? ''),
                    (string) ($row['org_title'] ?? ''),
                    (string) ($row['mobile'] ?? ''),
                ], static fn (string $value): bool => trim($value) !== '');
                $body .= self::paragraph($index . '. ' . implode('，', $parts), [
                    'size' => self::BODY_SIZE, 'indent' => false, 'spaceAfter' => 40,
                ]);
                $index++;
            }
        }

        // 办理联系人：六项齐全（必填），空着的历史数据自动跳过
        $contact = array_filter([
            '姓名' => (string) ($proposal['contact_name'] ?? ''),
            '单位' => (string) ($proposal['contact_org'] ?? ''),
            '职务' => (string) ($proposal['contact_title'] ?? ''),
            '联系地址' => (string) ($proposal['contact_address'] ?? ''),
            '邮政编码' => (string) ($proposal['contact_postcode'] ?? ''),
            '联系电话' => (string) ($proposal['contact_mobile'] ?? ''),
        ], static fn (string $value): bool => trim($value) !== '');
        if ($contact !== []) {
            $body .= self::paragraph('提案办理联系人', ['size' => self::BODY_SIZE, 'bold' => true, 'indent' => false, 'spaceBefore' => 200, 'spaceAfter' => 40]);
            $pairs = [];
            foreach ($contact as $label => $value) {
                $pairs[] = $label . '：' . $value;
            }
            $body .= self::paragraph(implode('　', $pairs), ['size' => self::BODY_SIZE, 'indent' => false, 'spaceAfter' => 40]);
        }

        $body .= self::paragraph('提案内容', ['size' => self::BODY_SIZE, 'bold' => true, 'spaceBefore' => 200]);
        $body .= self::richText((string) ($proposal['body_html'] ?? ''));

        if ($status === ProposalWorkflow::RETURNED && trim((string) ($proposal['returned_reason'] ?? '')) !== '') {
            $body .= self::paragraph('提案委退回意见', ['size' => self::BODY_SIZE, 'bold' => true, 'spaceBefore' => 200]);
            foreach (self::lines((string) $proposal['returned_reason']) as $line) {
                $body .= self::paragraph($line);
            }
        } elseif (trim((string) ($proposal['review_note'] ?? '')) !== '') {
            $body .= self::paragraph('提案委受理意见', ['size' => self::BODY_SIZE, 'bold' => true, 'spaceBefore' => 200]);
            foreach (self::lines((string) $proposal['review_note']) as $line) {
                $body .= self::paragraph($line);
            }
        }

        if ($attachments !== []) {
            $body .= self::paragraph('附件清单', ['size' => self::BODY_SIZE, 'bold' => true, 'spaceBefore' => 200]);
            $index = 1;
            foreach ($attachments as $attachment) {
                $body .= self::paragraph(
                    $index . '. ' . (string) ($attachment['name'] ?? ''),
                    ['size' => self::BODY_SIZE, 'indent' => false]
                );
                $index++;
            }
        }

        return $body;
    }

    /**
     * 轻量富文本 → Word 段落。
     *
     * 走 DOM 解析而不是正则：正文允许嵌套（列表里加粗），字符串替换很容易把标签配错对。
     */
    private static function richText(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return self::paragraph('');
        }

        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML(
            '<meta http-equiv="Content-Type" content="text/html; charset=utf-8"><body>' . $html . '</body>',
            LIBXML_NOERROR | LIBXML_NOWARNING
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $body = $loaded ? $dom->getElementsByTagName('body')->item(0) : null;
        if ($body === null) {
            // 解析失败时退回纯文本，宁可丢格式也不能丢内容
            $xml = '';
            foreach (ProposalBody::paragraphs($html) as $line) {
                $xml .= self::paragraph($line);
            }
            return $xml === '' ? self::paragraph('') : $xml;
        }

        $blocks = [];
        $current = null;
        self::walk($body, ['bold' => false, 'underline' => false], $blocks, $current);
        self::flush($blocks, $current);

        $xml = '';
        foreach ($blocks as $block) {
            if ($block['runs'] === []) {
                continue;
            }
            $options = ['size' => self::BODY_SIZE];
            if ($block['kind'] === 'li') {
                $options['indent'] = false;
                $options['listIndent'] = true;
            }
            $xml .= self::runsParagraph($block['runs'], $options);
        }

        return $xml === '' ? self::paragraph('') : $xml;
    }

    /**
     * 递归遍历 DOM，把块级元素收成一个个段落，行内元素只改运行格式。
     *
     * @param array{bold:bool,underline:bool} $format
     * @param list<array{kind:string,runs:list<array{text:string,bold:bool,underline:bool}>}> $blocks
     * @param array{kind:string,runs:list<array{text:string,bold:bool,underline:bool}>}|null $current
     */
    private static function walk(\DOMNode $node, array $format, array &$blocks, ?array &$current): void
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMText) {
                $text = $child->nodeValue ?? '';
                if (trim($text) === '') {
                    continue;
                }
                if ($current === null) {
                    $current = ['kind' => 'p', 'runs' => []];
                }
                $current['runs'][] = [
                    'text'      => $text,
                    'bold'      => $format['bold'],
                    'underline' => $format['underline'],
                ];
                continue;
            }
            if (!$child instanceof \DOMElement) {
                continue;
            }

            $tag = strtolower($child->tagName);
            if (in_array($tag, ['strong', 'b'], true)) {
                self::walk($child, ['bold' => true, 'underline' => $format['underline']], $blocks, $current);
                continue;
            }
            if ($tag === 'u') {
                self::walk($child, ['bold' => $format['bold'], 'underline' => true], $blocks, $current);
                continue;
            }
            if ($tag === 'br') {
                // 手工换行：在 Word 里另起一段，避免整段挤成一行
                self::flush($blocks, $current);
                continue;
            }
            if (in_array($tag, ['ul', 'ol'], true)) {
                self::flush($blocks, $current);
                $index = 1;
                foreach ($child->childNodes as $listItem) {
                    if (!$listItem instanceof \DOMElement || strtolower($listItem->tagName) !== 'li') {
                        continue;
                    }
                    $inner = null;
                    self::walk($listItem, ['bold' => false, 'underline' => false], $blocks, $inner);
                    if ($inner !== null && $inner['runs'] !== []) {
                        $marker = $tag === 'ol' ? $index . '. ' : '· ';
                        array_unshift($inner['runs'], ['text' => $marker, 'bold' => false, 'underline' => false]);
                        $blocks[] = ['kind' => 'li', 'runs' => $inner['runs']];
                    }
                    $index++;
                }
                $current = null;
                continue;
            }
            // p／div／li（游离）与其余块级标签：各自成段
            self::flush($blocks, $current);
            if (in_array($tag, ['p', 'div', 'li', 'h1', 'h2', 'h3', 'blockquote'], true)) {
                $inner = null;
                self::walk($child, $format, $blocks, $inner);
                if ($inner !== null && $inner['runs'] !== []) {
                    $inner['kind'] = $tag === 'li' ? 'li' : 'p';
                    $blocks[] = $inner;
                }
                $current = null;
                continue;
            }
            // 其余行内标签（em／i／span／a 等）：沿用当前格式往下走
            self::walk($child, $format, $blocks, $current);
        }
    }

    /**
     * @param list<array{kind:string,runs:list<array{text:string,bold:bool,underline:bool}>}> $blocks
     * @param array{kind:string,runs:list<array{text:string,bold:bool,underline:bool}>}|null $current
     */
    private static function flush(array &$blocks, ?array &$current): void
    {
        if ($current !== null && $current['runs'] !== []) {
            $blocks[] = $current;
        }
        $current = null;
    }

    /**
     * 段落文本按行拆开，每行一个 Word 段落
     *
     * @return list<string>
     */
    private static function lines(string $text): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $lines = [];
        foreach (explode("\n", $text) as $line) {
            $line = trim($line);
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return $lines === [] ? [''] : $lines;
    }

    /**
     * 单运行段落（案由、字段行、意见行都用它）。
     *
     * @param array{size?:int,bold?:bool,align?:string,indent?:bool,line?:int,spaceBefore?:int,spaceAfter?:int} $options
     */
    private static function paragraph(string $text, array $options = []): string
    {
        return self::runsParagraph(
            [['text' => $text, 'bold' => (bool) ($options['bold'] ?? false), 'underline' => false]],
            $options
        );
    }

    /**
     * 多运行段落：粗体、下划线逐个运行带格式。
     *
     * @param list<array{text:string,bold:bool,underline:bool}> $runs
     * @param array{size?:int,bold?:bool,align?:string,indent?:bool,listIndent?:bool,line?:int,spaceBefore?:int,spaceAfter?:int} $options
     */
    private static function runsParagraph(array $runs, array $options = []): string
    {
        $size = (int) ($options['size'] ?? self::BODY_SIZE);
        $align = (string) ($options['align'] ?? 'left');
        $indent = $options['indent'] ?? true;
        $listIndent = (bool) ($options['listIndent'] ?? false);
        $line = (int) ($options['line'] ?? self::LINE_TWIPS);
        $spaceBefore = (int) ($options['spaceBefore'] ?? 0);
        $spaceAfter = (int) ($options['spaceAfter'] ?? 80);

        $indentXml = '';
        if ($listIndent) {
            $indentXml = '<w:ind w:left="' . self::LIST_INDENT . '" w:hanging="' . self::INDENT_TWIPS . '"/>';
        } elseif ($indent) {
            $indentXml = '<w:ind w:firstLine="' . self::INDENT_TWIPS . '"/>';
        }

        $properties = '<w:pPr>'
            . '<w:spacing w:before="' . $spaceBefore . '" w:after="' . $spaceAfter
            . '" w:line="' . $line . '" w:lineRule="exact"/>'
            . $indentXml
            . '<w:jc w:val="' . $align . '"/>'
            . '<w:rPr>' . self::runProperties($size, (bool) ($options['bold'] ?? false), false) . '</w:rPr>'
            . '</w:pPr>';

        $xml = '';
        foreach ($runs as $run) {
            $xml .= '<w:r>' . self::runProperties($size, (bool) $run['bold'], (bool) $run['underline'])
                . '<w:t xml:space="preserve">' . OfficePackage::escape((string) $run['text']) . '</w:t></w:r>';
        }

        return '<w:p>' . $properties . ($xml === '' ? '' : $xml) . '</w:p>';
    }

    private static function runProperties(int $size, bool $bold, bool $underline): string
    {
        return '<w:rFonts w:ascii="Times New Roman" w:hAnsi="Times New Roman" w:eastAsia="宋体"/>'
            . ($bold ? '<w:b/>' : '')
            . ($underline ? '<w:u w:val="single"/>' : '')
            . '<w:sz w:val="' . $size . '"/><w:szCs w:val="' . $size . '"/>';
    }

    private static function sectionProperties(): string
    {
        return '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/>'
            . '<w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440" w:header="851" w:footer="992" w:gutter="0"/>'
            . '</w:sectPr>';
    }
}

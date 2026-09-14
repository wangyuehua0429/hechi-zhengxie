<?php

declare(strict_types=1);

namespace HechiZx\Proposal;

use HechiZx\Content\ProposalWorkflow;

/**
 * 提案表 Word 导出（.docx）：A4、案由三号居中、正文小四宋体、行距 28 磅、段落首行缩进 2 字。
 * 委员端「下载本人提案」与提案委「单件导出」共用这一份生成器，保证两边口径一致。
 */
final class WordExporter
{
    private const BODY_SIZE = 24;     // 小四 = 12pt = 24 半磅
    private const TITLE_SIZE = 32;    // 三号 = 16pt = 32 半磅
    private const LINE_TWIPS = 560;   // 固定行距 28 磅
    private const INDENT_TWIPS = 480; // 首行缩进 2 字（12pt 字号的两个字）

    /**
     * @param array<string,mixed> $proposal
     * @param list<array<string,mixed>> $attachments
     */
    public static function proposal(array $proposal, array $attachments = []): string
    {
        $body = '';
        $body .= self::paragraph((string) ($proposal['title'] ?? '案由'), [
            'size' => self::TITLE_SIZE, 'bold' => true, 'align' => 'center', 'line' => 600, 'spaceAfter' => 200,
        ]);

        $status = ProposalWorkflow::normalize((string) ($proposal['status'] ?? ''));
        $pairs = [
            '提案人' => (string) ($proposal['proposer_name'] ?? ''),
            '提案人类别' => ProposalWorkflow::proposerTypeLabel((string) ($proposal['proposer_type'] ?? 'personal')),
            '界别' => (string) ($proposal['sector'] ?? ''),
            '专委会' => (string) ($proposal['committee'] ?? ''),
            '联系电话' => (string) ($proposal['contact_mobile'] ?? ''),
            '提案类别' => (string) ($proposal['category'] ?? ''),
            '提交时间' => (string) ($proposal['submitted_at'] ?? ''),
            '当前状态' => ProposalWorkflow::label($status),
        ];
        if (trim((string) ($proposal['co_members'] ?? '')) !== '') {
            $pairs['联名委员'] = (string) $proposal['co_members'];
        }
        if (trim((string) ($proposal['collective_name'] ?? '')) !== '') {
            $pairs['集体名称'] = (string) $proposal['collective_name'];
        }
        foreach ($pairs as $label => $value) {
            $body .= self::paragraph($label . '：' . $value, [
                'size' => self::BODY_SIZE, 'indent' => false, 'spaceAfter' => 40,
            ]);
        }

        $sections = [
            '一、情况与问题' => (string) ($proposal['problem_text'] ?? ''),
            '二、分析' => (string) ($proposal['analysis_text'] ?? ''),
            '三、建议' => (string) ($proposal['suggestion_text'] ?? ''),
        ];
        foreach ($sections as $heading => $text) {
            $body .= self::paragraph($heading, ['size' => self::BODY_SIZE, 'bold' => true, 'spaceBefore' => 200]);
            foreach (self::lines($text) as $line) {
                $body .= self::paragraph($line);
            }
        }

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

    /** 段落文本按行拆开，每行一个 Word 段落 */

    /** @return list<string> */
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
     * @param array{size?:int,bold?:bool,align?:string,indent?:bool,line?:int,spaceBefore?:int,spaceAfter?:int} $options
     */
    private static function paragraph(string $text, array $options = []): string
    {
        $size = (int) ($options['size'] ?? self::BODY_SIZE);
        $align = (string) ($options['align'] ?? 'left');
        $indent = $options['indent'] ?? true;
        $line = (int) ($options['line'] ?? self::LINE_TWIPS);
        $spaceBefore = (int) ($options['spaceBefore'] ?? 0);
        $spaceAfter = (int) ($options['spaceAfter'] ?? 80);

        $properties = '<w:pPr>'
            . '<w:spacing w:before="' . $spaceBefore . '" w:after="' . $spaceAfter
            . '" w:line="' . $line . '" w:lineRule="exact"/>'
            . ($indent ? '<w:ind w:firstLine="' . self::INDENT_TWIPS . '"/>' : '')
            . '<w:jc w:val="' . $align . '"/>'
            . '<w:rPr>' . self::runProperties($size, (bool) ($options['bold'] ?? false)) . '</w:rPr>'
            . '</w:pPr>';
        $run = '<w:r>' . self::runProperties($size, (bool) ($options['bold'] ?? false))
            . '<w:t xml:space="preserve">' . OfficePackage::escape($text) . '</w:t></w:r>';

        return '<w:p>' . $properties . $run . '</w:p>';
    }

    private static function runProperties(int $size, bool $bold): string
    {
        return '<w:rFonts w:ascii="Times New Roman" w:hAnsi="Times New Roman" w:eastAsia="宋体"/>'
            . ($bold ? '<w:b/>' : '')
            . '<w:sz w:val="' . $size . '"/><w:szCs w:val="' . $size . '"/>';
    }

    private static function sectionProperties(): string
    {
        return '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/>'
            . '<w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440" w:header="851" w:footer="992" w:gutter="0"/>'
            . '</w:sectPr>';
    }
}

<?php

declare(strict_types=1);

namespace HechiZx\Proposal;

use HechiZx\Content\ProposalWorkflow;

/**
 * 提案收件清单导出（.xlsx）。零依赖手写 OOXML：内容用内联字符串，不引共享字符串表。
 */
final class XlsxExporter
{
    private const COLUMNS = [
        ['提案号', 10],
        ['案由', 42],
        ['提案人类别', 12],
        ['提案人', 12],
        ['界别', 14],
        ['专委会', 16],
        ['类别', 14],
        ['提交时间', 18],
        ['状态', 10],
        ['办理时间', 18],
        ['办理意见', 30],
    ];

    /**
     * @param list<array<string,mixed>> $rows cms_proposal 行（含 member_name 可选）
     */
    public static function proposalList(array $rows): string
    {
        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . self::cols()
            . '<sheetData>'
            . self::row(1, array_map(static fn (array $column): string => $column[0], self::COLUMNS), true)
            . self::body($rows)
            . '</sheetData>'
            . '<autoFilter ref="A1:K' . max(1, count($rows) + 1) . '"/>'
            . '</worksheet>';

        return OfficePackage::build([
            '[Content_Types].xml' => self::contentTypes(),
            '_rels/.rels'         => self::rootRels(),
            'xl/workbook.xml'     => self::workbook(),
            'xl/_rels/workbook.xml.rels' => self::workbookRels(),
            'xl/styles.xml'       => self::styles(),
            'xl/worksheets/sheet1.xml' => $sheet,
        ]);
    }

    /** @param list<array<string,mixed>> $rows */
    private static function body(array $rows): string
    {
        $xml = '';
        $index = 2;
        foreach ($rows as $row) {
            $status = ProposalWorkflow::normalize((string) ($row['status'] ?? ''));
            $note = $status === ProposalWorkflow::RETURNED
                ? (string) ($row['returned_reason'] ?? '')
                : (string) ($row['review_note'] ?? '');
            $xml .= self::row($index, [
                (string) ($row['proposal_id'] ?? ''),
                (string) ($row['title'] ?? ''),
                ProposalWorkflow::proposerTypeLabel((string) ($row['proposer_type'] ?? 'personal')),
                (string) ($row['proposer_name'] ?? ''),
                (string) ($row['sector'] ?? ''),
                (string) ($row['committee'] ?? ''),
                (string) ($row['category'] ?? ''),
                (string) ($row['submitted_at'] ?? ''),
                ProposalWorkflow::label($status),
                (string) ($row['reviewed_at'] ?? ''),
                $note,
            ]);
            $index++;
        }

        return $xml;
    }

    /** @param list<string> $values */
    private static function row(int $rowNumber, array $values, bool $header = false): string
    {
        $xml = '<row r="' . $rowNumber . '">';
        foreach (array_values($values) as $index => $value) {
            $ref = self::columnName($index + 1) . $rowNumber;
            $style = $header ? ' s="1"' : ' s="2"';
            if ($value === '' && !$header) {
                $xml .= '<c r="' . $ref . '"' . $style . '/>';
                continue;
            }
            $xml .= '<c r="' . $ref . '" t="inlineStr"' . $style . '><is><t xml:space="preserve">'
                . OfficePackage::escape($value) . '</t></is></c>';
        }

        return $xml . '</row>';
    }

    private static function columnName(int $index): string
    {
        $name = '';
        while ($index > 0) {
            $index--;
            $name = chr(65 + ($index % 26)) . $name;
            $index = intdiv($index, 26);
        }

        return $name;
    }

    private static function cols(): string
    {
        $xml = '<cols>';
        foreach (self::COLUMNS as $index => $column) {
            $xml .= '<col min="' . ($index + 1) . '" max="' . ($index + 1)
                . '" width="' . $column[1] . '" customWidth="1"/>';
        }

        return $xml . '</cols>';
    }

    private static function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>';
    }

    private static function rootRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private static function workbook(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="提案收件清单" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';
    }

    private static function workbookRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';
    }

    private static function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2">'
            . '<font><sz val="11"/><color rgb="FF000000"/><name val="宋体"/></font>'
            . '<font><b/><sz val="11"/><color rgb="FF000000"/><name val="宋体"/></font>'
            . '</fonts>'
            . '<fills count="3">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFF2F2F2"/><bgColor indexed="64"/></patternFill></fill>'
            . '</fills>'
            . '<borders count="2"><border/>'
            . '<border><left style="thin"><color rgb="FFD9D9D9"/></left>'
            . '<right style="thin"><color rgb="FFD9D9D9"/></right>'
            . '<top style="thin"><color rgb="FFD9D9D9"/></top>'
            . '<bottom style="thin"><color rgb="FFD9D9D9"/></bottom><diagonal/></border>'
            . '</borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="3">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">'
            . '<alignment horizontal="center" vertical="center"/></xf>'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1">'
            . '<alignment vertical="top" wrapText="1"/></xf>'
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="常规" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }
}

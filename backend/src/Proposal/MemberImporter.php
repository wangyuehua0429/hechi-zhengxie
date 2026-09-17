<?php

declare(strict_types=1);

namespace HechiZx\Proposal;

use HechiZx\Repository\MemberRepository;
use RuntimeException;

/**
 * 委员名册导入：CSV → 账号。
 *
 * 表头为「姓名,界别,职务,联系电话」，姓名与职务必填：缺这两列（或它们的别名）不认这份表头，
 * 某一行姓名／职务留空则该行不入库并给出原因。界别与联系电话可以整列留空。
 * 老表头（手机号／专委会／单位及职务／届次／备注）仍然照旧识别，早年导出的名册不用改。
 * 表头与姓名都按「忽略空白」处理，所以「姓 名」「现 任 职 务」这类排版空格，以及姓名里的
 * 对齐空格（韦　　平）都能识别。上传的 CSV 自动识别 UTF-8／GBK 与 BOM；登录名取委员本人
 * 姓名，重名的依次补 2、3…（张三、张三2），联系电话只作联系方式与备用登录标识，不当登录名。
 * 初始密码当场返回给调用方（一次性下载清单），库里只存 password_hash，不落明文。
 */
final class MemberImporter
{
    public const HEADERS = ['姓名', '界别', '职务', '联系电话'];

    /** Excel 导出的名册常带一行合并标题，表头可能不在第 1 行；只在前若干行里找 */
    private const HEADER_SCAN_LIMIT = 10;

    /** 易混淆字符（0O1lI）不参与随机密码 */
    private const PASSWORD_CHARS = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';

    public function __construct(private MemberRepository $members)
    {
    }

    /** 导入模板（UTF-8 带 BOM，Excel 双击直接可编辑） */
    public static function template(): string
    {
        return "\xEF\xBB\xBF" . implode(',', self::HEADERS) . "\n"
            . "张三,中国共产党,河池市某某局副局长,13800000000\n"
            . "李四,经济界,河池市某某公司总经理,\n";
    }

    /** CSV 原文导入（编码自动识别） */
    public function import(string $raw): array
    {
        return $this->importRows(self::parse($raw));
    }

    /**
     * 按「表头 + 数据行」导入。后台手工建号也走这里，保证重名补序号、联系电话判重、
     * 必填校验与初始密码规则和名册导入完全一致。
     *
     * @param list<list<string>> $rows 第 1 行起是表头（允许前面有若干非表头行，如合并标题）
     * @return array{
     *   total:int,
     *   created:list<array{name:string,login_name:string,password:string}>,
     *   failed:list<array{line:int,name:string,reason:string}>
     * }
     */
    public function importRows(array $rows): array
    {
        if ($rows === []) {
            throw new RuntimeException('文件是空的，或不是可识别的 CSV。');
        }

        $headerIndex = null;
        $map = [];
        foreach (array_slice($rows, 0, self::HEADER_SCAN_LIMIT) as $index => $cells) {
            $candidate = $this->mapHeader(array_map(static fn (string $cell): string => trim($cell), $cells));
            if ($candidate !== []) {
                $headerIndex = $index;
                $map = $candidate;
                break;
            }
        }
        if ($headerIndex === null) {
            throw new RuntimeException(
                '表头对不上：姓名与职务两列都要有（模板表头：' . implode('、', self::HEADERS) . '）。'
            );
        }

        $usedLogins = [];
        $usedMobiles = [];
        foreach ($this->members->all() as $row) {
            $usedLogins[(string) $row['login_name']] = true;
            if ((string) $row['mobile'] !== '') {
                $usedMobiles[(string) $row['mobile']] = true;
            }
        }

        $dataRows = array_slice($rows, $headerIndex + 1);
        $created = [];
        $failed = [];
        foreach ($dataRows as $offset => $cells) {
            $line = $headerIndex + $offset + 2; // 表头那一行的下一行是第 1 条数据
            $row = [];
            foreach ($map as $field => $column) {
                $value = (string) ($cells[$column] ?? '');
                // 姓名与手机号去掉全部空白：名册里的「韦　　平」是排版撑出来的，
                // 留着会变成带空格的登录名；单位及职务只去首尾，内部空格是内容
                $row[$field] = in_array($field, ['name', 'mobile'], true)
                    ? self::squeeze($value)
                    : trim($value);
            }
            if (implode('', array_values($row)) === '') {
                continue; // 整行空白直接跳过
            }

            $name = $row['name'] ?? '';
            if ($name === '') {
                $failed[] = ['line' => $line, 'name' => '', 'reason' => '姓名为空'];
                continue;
            }
            $orgTitle = $row['org'] ?? '';
            if ($orgTitle === '') {
                $failed[] = ['line' => $line, 'name' => $name, 'reason' => '职务为空'];
                continue;
            }
            $mobile = $row['mobile'] ?? '';
            if ($mobile !== '' && isset($usedMobiles[$mobile])) {
                $failed[] = ['line' => $line, 'name' => $name, 'reason' => '联系电话重复：' . $mobile];
                continue;
            }

            // 登录名就是本人姓名；重名的补序号，保证 login_name 唯一
            $login = $this->nextLoginName($usedLogins, $name);

            $password = self::randomPassword();
            $this->members->create([
                'name'          => $name,
                'login_name'    => $login,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'mobile'        => $mobile,
                'sector'        => $row['sector'] ?? '',
                'committee'     => $row['committee'] ?? '',
                'org_title'     => $orgTitle,
                'term'          => $row['term'] ?? '',
                'status'        => 'enabled',
                'remark'        => $row['remark'] ?? '',
            ]);

            $usedLogins[$login] = true;
            if ($mobile !== '') {
                $usedMobiles[$mobile] = true;
            }
            $created[] = ['name' => $name, 'login_name' => $login, 'password' => $password];
        }

        return ['total' => count($dataRows), 'created' => $created, 'failed' => $failed];
    }

    /**
     * 解析 CSV：先统一编码，再按标准 CSV 规则（含引号内逗号与换行）切行。
     *
     * @return list<list<string>>
     */
    public static function parse(string $raw): array
    {
        $text = self::toUtf8($raw);
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $handle = fopen('php://memory', 'r+');
        if ($handle === false) {
            throw new RuntimeException('无法建立解析缓冲区。');
        }
        fwrite($handle, $text);
        rewind($handle);

        $rows = [];
        while (($cells = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            if ($cells === [null]) {
                continue;
            }
            $rows[] = array_map(static fn ($cell): string => (string) $cell, $cells);
        }
        fclose($handle);

        return $rows;
    }

    /** BOM 与 GBK／GB18030 一律转成 UTF-8 */
    public static function toUtf8(string $raw): string
    {
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;
        if (mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }
        return mb_convert_encoding($text, 'UTF-8', 'GB18030');
    }

    /**
     * 表头映射：返回「内部字段 => 列号」。
     *
     * @param list<string> $header
     * @return array<string, int>
     */
    private function mapHeader(array $header): array
    {
        $aliases = [
            'name'      => ['姓名', '名字', '委员姓名'],
            'mobile'    => ['联系电话', '手机号', '手机', '电话'],
            'sector'    => ['界别'],
            'committee' => ['专委会', '专门委员会'],
            'org'       => ['职务', '单位及职务', '单位职务', '工作单位及职务', '现任职务'],
            'term'      => ['届次'],
            'remark'    => ['备注'],
        ];

        $map = [];
        foreach ($header as $column => $title) {
            // 表头里的空格是排版（姓 名／现 任 职 务），比对前一律去掉
            $title = self::squeeze($title);
            foreach ($aliases as $field => $names) {
                if (isset($map[$field])) {
                    continue;
                }
                if (in_array($title, $names, true)) {
                    $map[$field] = $column;
                    break;
                }
            }
        }

        // 姓名与职务是必填列；界别与联系电话可以整列不写
        return isset($map['name'], $map['org']) ? $map : [];
    }

    /** 去掉首尾与内部的全部空白（含全角空格与不换行空格） */
    private static function squeeze(string $value): string
    {
        return (string) preg_replace('/[\s\x{00A0}\x{3000}]+/u', '', $value);
    }

    /**
     * 登录名取姓名；重名时依次补 2、3…（张三、张三2、张三3）。
     *
     * @param array<string, bool> $used 已占用的登录名
     */
    private function nextLoginName(array $used, string $name): string
    {
        $candidate = $name;
        $suffix = 1;
        while (isset($used[$candidate])) {
            $suffix++;
            $candidate = $name . $suffix;
        }

        return $candidate;
    }

    public static function randomPassword(int $length = 10): string
    {
        $max = strlen(self::PASSWORD_CHARS) - 1;
        $password = '';
        for ($i = 0; $i < $length; $i++) {
            $password .= self::PASSWORD_CHARS[random_int(0, $max)];
        }
        return $password;
    }
}

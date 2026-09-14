<?php

declare(strict_types=1);

namespace HechiZx\Proposal;

use HechiZx\Repository\MemberRepository;
use RuntimeException;

/**
 * 委员名册导入：CSV → 账号。
 *
 * 模板表头固定为「姓名,手机号,界别,专委会,单位及职务,届次,备注」，上传的 CSV 自动识别
 * UTF-8／GBK 与 BOM；登录名默认取手机号，缺手机号时生成 hczx + 4 位序号。
 * 初始密码当场返回给调用方（一次性下载清单），库里只存 password_hash，不落明文。
 */
final class MemberImporter
{
    public const HEADERS = ['姓名', '手机号', '界别', '专委会', '单位及职务', '届次', '备注'];

    /** 易混淆字符（0O1lI）不参与随机密码 */
    private const PASSWORD_CHARS = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';

    public function __construct(private MemberRepository $members)
    {
    }

    /** 导入模板（UTF-8 带 BOM，Excel 双击直接可编辑） */
    public static function template(): string
    {
        return "\xEF\xBB\xBF" . implode(',', self::HEADERS) . "\n"
            . "张三,13800000000,中国共产党,提案委员会,河池市某某局副局长,五届,\n"
            . "李四,13900000000,经济界,,河池市某某公司总经理,五届,\n";
    }

    /**
     * @return array{
     *   total:int,
     *   created:list<array{name:string,login_name:string,password:string}>,
     *   failed:list<array{line:int,name:string,reason:string}>
     * }
     */
    public function import(string $raw): array
    {
        $rows = self::parse($raw);
        if ($rows === []) {
            throw new RuntimeException('文件是空的，或不是可识别的 CSV。');
        }

        $header = array_map(static fn (string $cell): string => trim($cell), $rows[0]);
        $map = $this->mapHeader($header);
        $expected = implode('、', self::HEADERS);
        if ($map === []) {
            throw new RuntimeException('表头对不上，请用模板里的表头：' . $expected);
        }

        $usedLogins = [];
        $usedMobiles = [];
        foreach ($this->members->all() as $row) {
            $usedLogins[(string) $row['login_name']] = true;
            if ((string) $row['mobile'] !== '') {
                $usedMobiles[(string) $row['mobile']] = true;
            }
        }

        $created = [];
        $failed = [];
        $sequence = count($usedLogins);
        foreach (array_slice($rows, 1) as $index => $cells) {
            $line = $index + 2; // 表头占第 1 行，数据从第 2 行起
            $row = [];
            foreach ($map as $field => $column) {
                $row[$field] = trim((string) ($cells[$column] ?? ''));
            }
            if (implode('', array_values($row)) === '') {
                continue; // 整行空白直接跳过
            }

            $name = $row['name'] ?? '';
            if ($name === '') {
                $failed[] = ['line' => $line, 'name' => '', 'reason' => '姓名为空'];
                continue;
            }
            $mobile = $row['mobile'] ?? '';
            if ($mobile !== '' && isset($usedMobiles[$mobile])) {
                $failed[] = ['line' => $line, 'name' => $name, 'reason' => '手机号重复：' . $mobile];
                continue;
            }

            $login = $mobile !== '' ? $mobile : $this->nextLogin($usedLogins, $sequence);
            if (isset($usedLogins[$login])) {
                $failed[] = ['line' => $line, 'name' => $name, 'reason' => '登录名已被占用：' . $login];
                continue;
            }

            $password = self::randomPassword();
            $this->members->create([
                'name'          => $name,
                'login_name'    => $login,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'mobile'        => $mobile,
                'sector'        => $row['sector'] ?? '',
                'committee'     => $row['committee'] ?? '',
                'org_title'     => $row['org'] ?? '',
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

        return ['total' => max(0, count($rows) - 1), 'created' => $created, 'failed' => $failed];
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
            'mobile'    => ['手机号', '手机', '联系电话', '电话'],
            'sector'    => ['界别'],
            'committee' => ['专委会', '专门委员会'],
            'org'       => ['单位及职务', '单位职务', '工作单位及职务'],
            'term'      => ['届次'],
            'remark'    => ['备注'],
        ];

        $map = [];
        foreach ($header as $column => $title) {
            $title = trim($title);
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

        // 姓名与手机号是必填项，缺任一个都不认这份表头
        return isset($map['name'], $map['mobile']) ? $map : [];
    }

    /** @param array<string, bool> $used */
    private function nextLogin(array $used, int &$sequence): string
    {
        do {
            $sequence++;
            $candidate = 'hczx' . str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
        } while (isset($used[$candidate]));

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

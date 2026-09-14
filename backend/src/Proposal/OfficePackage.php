<?php

declare(strict_types=1);

namespace HechiZx\Proposal;

use RuntimeException;
use ZipArchive;

/**
 * 极简 OOXML 打包器：把若干 XML 部件压成 .docx／.xlsx。
 * 依赖 ext-zip（生产镜像需装 zip 扩展，见 deploy/php/Dockerfile）。
 */
final class OfficePackage
{
    /**
     * @param array<string, string> $parts 部件路径 => XML 内容
     */
    public static function build(array $parts): string
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('当前 PHP 环境缺少 zip 扩展，无法生成 Word／Excel 文件。');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'zxoffice');
        if ($tmp === false) {
            throw new RuntimeException('无法创建临时文件。');
        }
        $path = $tmp . '.bin';
        @unlink($tmp);

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('无法创建导出文件。');
        }
        foreach ($parts as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        $binary = (string) file_get_contents($path);
        @unlink($path);

        return $binary;
    }

    public static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}

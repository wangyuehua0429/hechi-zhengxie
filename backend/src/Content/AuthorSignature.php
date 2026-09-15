<?php

declare(strict_types=1);

namespace HechiZx\Content;

/**
 * 正文末尾的作者署名处理：删掉与作者栏重复的署名，把作者归口到标题下的作者栏。
 *
 * 背景（2026-09-15 开发库 4969 篇实读）：
 * - 旧站正文末尾普遍带一行署名，形态有三种——`（黄荞丹 覃可论）`、`口黄正华`（旧站的方框署名标记
 *   在导出后成了「口」）、`（作者：本报首席记者 罗昌亮）`；
 * - 这些署名与 `cms_article.author` 基本重复（如 64088 正文末尾「(黄荞丹 覃可论)」、作者栏
 *   「黄荞丹 覃可论」），页面上标题下已显示作者，正文里再来一遍属于重复；
 * - 尾随的「（作者系…职务）」「（文章刊登于…）」「（本版图片均由…/摄）」「（发言者为…）」
 *   是职务说明、出处与图片署名，不是作者姓名，本类不动它们。
 *
 * 删除口径（只认「与作者栏对得上」的署名，宁少勿多）：
 * - 括号署名：括号内去掉空白与署名标签（作者、文/图、记者、通讯员、摄影…）后，等于作者栏、
 *   是作者栏的一部分、或把作者栏的姓名全都包含在内（如作者栏「包诗璞 骆保伶等」对
 *   「（包诗璞 骆保伶 韦莲珠 蓝 斌 李万阳）」）→ 删；
 * - 方框署名：`口`／`□`／`◇`／`■`／`◎`／`○` + 作者姓名 → 删标记与姓名；紧跟着的
 *   「图/文」「摄」这类空标签一并删，后面若是图片说明（`图为…。刁海音/摄`）则保留；
 * - 作者栏为空时不猜、不删，只由 {@see self::extractName()} 取明确写了名字的署名
 *   （`【口】姓名`、`（作者：X）`）回填作者栏，回填由调用方决定。
 *
 * 保护口径：含「系／为／单位／来源／原载／刊登」的作者说明行、含「电＋记者」的通讯社电头、
 * 含「版／期」的报刊出处一律保留——删了会丢职务、单位与出处信息。
 */
final class AuthorSignature
{
    /** 只在正文末尾这些可见字符内找署名（约两行），避免误伤正文中间出现的括号 */
    private const TAIL_CHARS = 160;

    /** 旧站署名前的方框标记（导出后多为「口」） */
    private const MARKERS = '口□◇■◎○';

    /** 署名标签：括号里写着「作者：」「文/图」「摄影」这类字样时，比对前先去掉 */
    private const LABELS = [
        '本版图片均由', '本报记者', '首席记者', '见习记者', '摄影报道', '本版图片',
        '作者', '摄影', '报道', '供稿', '记者', '通讯员', '图文', '文/图', '图/文', '文', '图', '摄',
    ];

    /** 出现这些字样说明括号里是职务／出处说明，不是单纯署名，一律保留 */
    private const KEEP_MARKS = ['系', '单位', '来源', '原载', '刊登', '转自'];

    /** 机构名尾巴：括号里以这些字收尾的不当人名（「（河池市政协）」「（新华社）」） */
    private const ORG_TAILS = ['社', '报', '会', '协', '网', '厅', '局', '委', '部', '室', '站', '台', '校', '院',
        '中心', '公司', '集团', '单位', '频道', '协会', '委员会', '办公厅', '研究院', '工作室'];

    /** 常见非人名（民族成分、称谓、通稿署名），出现在括号里时不当作者名 */
    private const NAME_STOP = ['新华社', '中新社', '人民日报', '广西日报', '河池日报', '本报', '综合',
        '转载', '壮族', '汉族', '毛南族', '仫佬族', '苗族', '侗族', '瑶族', '回族', '京族', '水族', '彝族', '女', '男'];

    /** 姓名之间允许的分隔符（「韦剑平  欧浩坚」与一个名字里带空格的「韦 东」都算） */
    private const SEP = '[\s、，,·/／]*';

    /**
     * 删掉正文末尾与 $author 对得上的署名（对不上时原样返回）。
     */
    public static function strip(string $html, string $author): string
    {
        $spans = self::spans($html, $author);
        if ($spans === []) {
            return $html;   // 没命中：一个字节都不动
        }
        $out = $html;
        foreach ($spans as [$from, $to]) {
            $out = substr($out, 0, $from) . substr($out, $to);
        }

        return self::tidyTail($out);
    }

    /**
     * 末尾署名在 HTML 里的字节区间（[起, 止)），按从后往前排序，便于直接回删。
     *
     * 旧库正文里换行、<br>、空段很多，按「可见字符」定位比按字节窗口稳：
     * 先把 HTML 拆成「纯文本 + 每个字符对应的字节区间」，在文本上找署名，再换算回字节区间。
     *
     * @return list<array{0:int, 1:int}>
     */
    public static function spans(string $html, string $author): array
    {
        if (trim($author) === '') {
            return [];
        }
        $map = self::textMap($html);
        $charSpans = self::signatureSpans($map['text'], $author);
        if ($charSpans === []) {
            return [];
        }
        $spans = [];
        foreach ($charSpans as [$from, $to]) {
            $spans[] = [$map['start'][$from], $map['end'][$to - 1]];
        }
        // 从后往前删，避免前面的删除挪动后面的偏移
        usort($spans, static fn (array $a, array $b): int => $b[0] <=> $a[0]);

        return $spans;
    }

    /**
     * 作者栏为空时，从末尾署名里取作者名：「口姓名」或「（作者：X）」。
     *
     * 取不到（或拿到的是「作者系…职务」这种说明行）时返回空串，由调用方决定是否回填。
     */
    public static function extractName(string $html): string
    {
        $map = self::textMap($html);
        $text = $map['text'];
        if ($text === '') {
            return '';
        }
        $tail = mb_substr($text, -self::TAIL_CHARS);

        if (preg_match('~[（(]\s*作者\s*[：:]?\s*([^（()）]{1,20}?)\s*[)）]\s*$~u', $tail, $m, PREG_OFFSET_CAPTURE) === 1) {
            $from = mb_strlen($text) - mb_strlen($tail) + mb_strlen(substr($tail, 0, $m[1][1]));
            // 「作者：本报首席记者 罗昌亮」→「罗昌亮」：署名标签在填入作者栏前先去掉
            $name = self::dropLabels(self::squash(self::rawBetween($html, $map, $from, $from + mb_strlen($m[1][0]))));
            if (!self::looksLikeRoleNote($name) && self::isNameLike($name)) {
                return $name;
            }
        }
        if (preg_match('~[' . self::MARKERS . ']\s*([\x{4e00}-\x{9fa5}·]{2,10})\s*$~u', $tail, $m) === 1) {
            return $m[1];
        }
        // 末尾就是一个光括号姓名（「（韦立辉）」「（韦瑞展 袁文展）」）时也算署名，
        // 但要挡住民族成分、通稿署名与机构名，免得把「（壮族）」「（新华社）」当作者
        // 姓名回原 HTML 里取：文本映射去掉了空白，「韦瑞展 袁文展」直接读文本会粘成一个词
        $tailFrom = max(0, mb_strlen($text) - self::TAIL_CHARS);
        $byteFrom = mb_strlen($text) > $tailFrom ? strlen(mb_substr($text, 0, $tailFrom)) : 0;
        if (preg_match('~[（(]([^（()）]{2,12}?)[)）]\s*$~u', $text, $m, PREG_OFFSET_CAPTURE, $byteFrom) === 1) {
            $from = mb_strlen(substr($text, 0, $m[1][1]));
            $name = self::rawBetween($html, $map, $from, $from + mb_strlen($m[1][0]));
            if (self::looksLikePersonName($name)) {
                return $name;
            }
        }

        return '';
    }

    /**
     * 把「纯文本里的片段」还原成原始 HTML 里的文字（保留词间空格，去标签、压空白）。
     *
     * @param array{text: string, start: list<int>, end: list<int>} $map
     */
    private static function rawBetween(string $html, array $map, int $from, int $to): string
    {
        $raw = substr($html, $map['start'][$from], $map['end'][$to - 1] - $map['start'][$from]);

        return self::collapse(strip_tags($raw));
    }

    /** 括号里的文字是否像一个／组人名（1～3 个 2～4 字的姓名，且不是机构名与常见非人名） */
    private static function looksLikePersonName(string $text): bool
    {
        if ($text === '' || in_array($text, self::NAME_STOP, true)) {
            return false;
        }
        foreach (self::ORG_TAILS as $tail) {
            if (str_ends_with($text, $tail)) {
                return false;
            }
        }
        $tokens = preg_split('/[\s\x{3000}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($tokens === [] || count($tokens) > 3) {
            return false;
        }
        foreach ($tokens as $token) {
            $length = mb_strlen($token);
            // 文本映射已经把空白去掉，「韦瑞展 袁文展」在这里是 6 个字，所以上限放到 8
            if ($length < 2 || $length > 8 || preg_match('~^[\x{4e00}-\x{9fa5}·]+$~u', $token) !== 1) {
                return false;
            }
        }

        return true;
    }

    // ---------------------------------------------------------------- 定位署名

    /**
     * 正文末尾与作者栏对得上的署名区间（字符下标，[起, 止)）。
     *
     * @return list<array{0:int, 1:int}>
     */
    private static function signatureSpans(string $text, string $author): array
    {
        // 偏移是字节数，换算成字符下标后再做窗口判断与删除
        $offset = max(0, mb_strlen($text) - self::TAIL_CHARS);
        $byteFrom = mb_strlen($text) > $offset ? strlen(mb_substr($text, 0, $offset)) : 0;
        $spans = array_merge(
            self::parenSpans($text, $byteFrom, $author),
            self::markerSpans($text, $byteFrom, $author)
        );
        if ($spans === []) {
            return [];
        }
        usort($spans, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        // 方框署名与紧跟其后的括号说明可能各自命中，合并重叠区间
        $merged = [];
        foreach ($spans as $span) {
            $last = count($merged) - 1;
            if ($last >= 0 && $span[0] <= $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $span[1]);
                continue;
            }
            $merged[] = $span;
        }

        return $merged;
    }

    /** 括号署名：`（黄荞丹 覃可论）`、`（作者：本报首席记者 罗昌亮）` */
    private static function parenSpans(string $text, int $byteFrom, string $author): array
    {
        $matches = [];
        // 库里偶有非法 UTF-8 的历史正文，正则可能直接失败（返回 false）：那时按「没命中」处理
        if (preg_match_all('~[（(]([^（()）]{1,60})[)）]~u', $text, $matches, PREG_OFFSET_CAPTURE, $byteFrom) === false) {
            return [];
        }
        if (($matches[0] ?? []) === []) {
            return [];
        }
        $spans = [];
        foreach ($matches[0] as $index => $full) {
            if ($full[1] < $byteFrom || !self::matchesAuthor((string) $matches[1][$index][0], $author)) {
                continue;
            }
            $from = mb_strlen(substr($text, 0, $full[1]));
            $spans[] = [$from, $from + mb_strlen($full[0])];
        }
        // 只取最后命中的一个：正文中间的同名括号不动
        return $spans === [] ? [] : [$spans[count($spans) - 1]];
    }

    /** 方框署名：`口黄正华`（旧站的方框署名标记导出后成了「口」），可带一个「图/文」空标签 */
    private static function markerSpans(string $text, int $byteFrom, string $author): array
    {
        $pattern = self::markerPattern($author);
        if ($pattern === null) {
            return [];
        }
        $matches = [];
        if (preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE, $byteFrom) === false) {
            return [];
        }
        if (($matches[0] ?? []) === []) {
            return [];
        }
        $full = $matches[0][count($matches[0]) - 1];
        if ($full[1] < $byteFrom) {
            return [];
        }
        $from = mb_strlen(substr($text, 0, $full[1]));

        return [[$from, $from + mb_strlen($full[0])]];
    }

    /** 括号内文本与作者栏是否是同一个署名 */
    private static function matchesAuthor(string $inner, string $author): bool
    {
        $raw = self::collapse($inner);
        if (self::looksLikeRoleNote($raw) || self::looksLikeWireCredit($raw)) {
            return false;
        }
        $text = self::dropLabels(self::squash($raw));
        $target = self::squash($author);
        if ($text === '' || $target === '') {
            return false;
        }
        if ($text === $target) {
            return true;
        }
        // 署名是作者栏的一部分：「（黄 炼）」对作者栏「黄炼 韦平 田昌勇」
        if (mb_strlen($text) >= 2 && mb_strpos($target, $text) !== false) {
            return true;
        }
        // 作者栏的姓名全都出现，且多出来的也只是姓名（如作者栏带「等」、正文把全名单写全）
        $tokens = self::tokens($author, 2);
        if ($tokens === []) {
            return false;
        }
        $extra = $text;
        foreach ($tokens as $token) {
            $at = mb_strpos($extra, $token);
            if ($at === false) {
                return false;
            }
            $extra = mb_substr($extra, 0, $at) . mb_substr($extra, $at + mb_strlen($token));
        }

        return $extra === '' || self::isNameLike($extra);
    }

    /**
     * 由作者栏姓名拼出方框署名的正则：`口` + 姓名（姓名内部允许空白，姓名之间允许分隔符）。
     *
     * 作者姓名必须原样出现在正文里才算命中，避免了「口」出现在别的词里被误删。
     */
    private static function markerPattern(string $author): ?string
    {
        $tokens = self::tokens($author);
        if ($tokens === []) {
            return null;
        }
        $parts = [];
        foreach ($tokens as $token) {
            $parts[] = self::charGap($token);
        }
        $run = implode(self::SEP, $parts);

        return '~[' . self::MARKERS . '](' . $run . ')(\s*(?:图\s*/\s*文|文\s*/\s*图|图文|摄影报道|报道|摄))?~u';
    }

    /** 「韦剑平」→ 允许每个字之间有空白（旧库里有「韦 剑 平」的排法） */
    private static function charGap(string $token): string
    {
        $chars = preg_split('//u', $token, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $escaped = array_map(static fn (string $char): string => preg_quote($char, '~'), $chars);

        return implode('\s*', $escaped);
    }

    // ---------------------------------------------------------------- 工具

    /**
     * HTML → 纯文本 + 每个字符对应的字节区间。
     *
     * 旧库正文里换行、<br>、空段很多，按字节估算的位置跟可见文字对不上；这里把每个可见字符
     * 映射回它在 HTML 里的字节起点与终点，定位到署名后就能精确回删。
     *
     * @return array{text: string, start: list<int>, end: list<int>}
     */
    private static function textMap(string $html): array
    {
        $text = '';
        $starts = [];
        $ends = [];
        $length = strlen($html);
        $offset = 0;
        while ($offset < $length) {
            $lt = strpos($html, '<', $offset);
            $chunkEnd = $lt === false ? $length : $lt;
            if ($chunkEnd > $offset) {
                $chunk = substr($html, $offset, $chunkEnd - $offset);
                $tokens = [];
                // 只收可见字符：旧库正文里换行、制表、全角空格极多，把它们算进「末尾 N 字」
                // 的窗口会让真正的署名滑出窗口（39488 这类图片稿一整段都是空白）
                preg_match_all('~&[#a-zA-Z0-9]{2,8};|[^\s\x{00a0}\x{2000}-\x{200b}\x{202f}\x{205f}\x{3000}]~us',
                    $chunk, $tokens, PREG_OFFSET_CAPTURE);
                foreach ($tokens[0] ?? [] as $token) {
                    $raw = $token[0];
                    $at = $offset + $token[1];
                    $decoded = $raw[0] === '&'
                        ? html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8')
                        : $raw;
                    foreach (preg_split('//u', $decoded, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
                        $starts[] = $at;
                        $ends[] = $at + strlen($raw);
                        $text .= $char;
                    }
                }
            }
            if ($lt === false) {
                break;
            }
            $gt = strpos($html, '>', $lt);
            $offset = $gt === false ? $length : $gt + 1;
        }

        return ['text' => $text, 'start' => $starts, 'end' => $ends];
    }

    /** 压缩空白与特殊空格（比对用） */
    private static function collapse(string $text): string
    {
        $text = (string) preg_replace('/[\x{00a0}\x{2002}\x{2003}\x{2009}\x{3000}]+/u', ' ', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /** 删掉署名后留下的空块与多余空白（`<div>（黄炼）</div>` 这种壳子不再占位） */
    private static function tidyTail(string $html): string
    {
        $before = $html;
        do {
            $before = $html;
            $html = (string) preg_replace(
                '~<(div|p|span|strong|b)\b[^>]*>(?:\s|&nbsp;|&#\d+;|<br\s*/?>)*</\1>\s*$~u',
                '',
                $html
            );
            $html = (string) preg_replace('~(?:\s|<br\s*/?>)+$~u', '', $html);
        } while ($html !== $before);

        return $html;
    }

    /** 比对用归一：去掉空白与顿号、逗号、间隔号、斜杠、冒号 */
    private static function squash(string $text): string
    {
        $text = (string) preg_replace('/[\s\x{00a0}\x{2002}\x{2003}\x{2009}\x{3000}]+/u', '', $text);

        return (string) preg_replace('~[、,，·•/／:：]~u', '', $text);
    }

    /** 迭代去掉首尾的署名标签（「作者：本报首席记者 罗昌亮」→「罗昌亮」） */
    private static function dropLabels(string $text): string
    {
        $labels = self::LABELS;
        usort($labels, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
        for ($round = 0; $round < 4; $round++) {
            $before = $text;
            foreach ($labels as $label) {
                if ($text === $label) {
                    break 2;
                }
                if (str_starts_with($text, $label)) {
                    $text = mb_substr($text, mb_strlen($label));
                }
                if (str_ends_with($text, $label)) {
                    $text = mb_substr($text, 0, mb_strlen($text) - mb_strlen($label));
                }
            }
            // 注意：不能用 trim($text, '、，（）…')——trim 的字符表按字节处理，
            // 会把「磊」(E7 A3 8A) 这类字的尾字节当成要裁的字符，直接切坏 UTF-8。
            $text = (string) preg_replace('~^[\s、,，·:：/／（）()《》]+|[\s、,，·:：/／（）()《》]+$~u', '', $text);
            if ($text === $before) {
                break;
            }
        }

        return $text;
    }

    /**
     * 作者栏 → 姓名片段：按空白与顿号、逗号切开，去掉「等」这类尾巴。
     *
     * $minLength 用于两种场合：拼署名正则时要保留单字片段（作者栏写「陈海洋 黄 河」，
     * 正文是「陈海洋 黄 河」；只留 ≥2 字的片段会把它切成「陈海洋」而匹配不全），
     * 判断「作者栏姓名是否都在括号里」时则要求 ≥2 字，免得单字误配。
     *
     * @return list<string>
     */
    private static function tokens(string $author, int $minLength = 1): array
    {
        $raw = preg_split('/[\s\x{00a0}\x{3000}、,，]+/u', trim($author), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tokens = [];
        foreach ($raw as $token) {
            $token = trim((string) preg_replace('/[等]+$/u', '', $token));
            if (mb_strlen($token) >= $minLength) {
                $tokens[] = $token;
            }
        }

        return $tokens;
    }

    /** 职务／单位说明行（「作者系…」「文章刊登于…」），保留不删 */
    private static function looksLikeRoleNote(string $text): bool
    {
        foreach (self::KEEP_MARKS as $mark) {
            if (str_contains($text, $mark)) {
                return true;
            }
        }

        return false;
    }

    /** 通讯社电头（「新华社北京10月15日电 记者赵超…」），保留不删 */
    private static function looksLikeWireCredit(string $text): bool
    {
        return str_contains($text, '电') && (str_contains($text, '记者') || str_contains($text, '新华社'));
    }

    /** 看着像姓名（纯中文或间隔号，2～12 字），用于判断括号里多出来的部分还是人名 */
    private static function isNameLike(string $text): bool
    {
        $length = mb_strlen($text);

        return $length >= 1 && $length <= 12 && preg_match('~^[\x{4e00}-\x{9fa5}·•]+$~u', $text) === 1;
    }
}

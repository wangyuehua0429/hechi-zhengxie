#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""阶段 D 迁移第一段：旧库 SQL → 中间产物（JSONL + 图片清单 + 报表）。

只读旧库导出文件，不连数据库、不改仓库内容；入库是第二段 legacy_import.php 的事。

  parse   旧 SQL → articles.jsonl（待渲染）+ media_manifest.csv + 报表
  render  articles.jsonl + 媒体清单 → articles.final.jsonl（清洗、地址改写后的入库稿）

口径（2026-09-12 与甲方确认，见 docs/旧库迁移说明.md）：
  * 只迁主站口径：Type 与新站同号，且 Region 与栏目口径一致（默认 22，902→55、903→33）；
  * 未映射 Type 只出报表，不入库；
  * 公开年限按关停日 2026-11-20 回溯 3 年：该日之后 public，之前 archive；
  * 图片与视频由人工从旧站服务器拷到 backend/public/uploads/legacy/；render 时用
    --local-check 按本地文件是否存在判定，只有到位的才改写正文地址（不到位的保留旧站外链）。
"""

import argparse
import csv
import gzip
import hashlib
import html
import json
import os
import re
import sys
import time
import urllib.error
import urllib.request
from datetime import datetime, timedelta, timezone

CST = timezone(timedelta(hours=8))

# 旧库 rd_news 字段（与 Navicat 导出的列顺序一致）
NEWS_FIELDS = ['ID', 'Title', 'Title1', 'Title2', 'Pic', 'Word', 'Audit', 'Region',
               'Hot', 'Top', 'Type', 'Num', 'Order', 'Time', 'From', 'Author', 'Edit']
VIDEO_FIELDS = ['ID', 'Title', 'Title1', 'Audit', 'Hot', 'Type', 'Region', 'From', 'Url']
LINK_FIELDS = ['ID', 'Name', 'Url', 'Pic', 'Audit', 'Order', 'Region']
HUDONG_FIELDS = ['ID', 'Title', 'Title1', 'Pic', 'Word', 'Audit', 'Region', 'Hot', 'Top',
                 'Type', 'Num', 'Order', 'Time', 'From', 'Author', 'Edit', 'Ask', 'Huifu',
                 'Send', 'SendTime', 'Zhaiyao', 'ReplyTime', 'Banli', 'gender', 'age', 'job',
                 'zjt', 'zcode', 'phone', 'email', 'address', 'xx']

# 栏目口径：Type → 旧库 Region（其余栏目默认主站 22）
DEFAULT_REGION_MAP = {'902': '55', '903': '33'}
MAIN_REGIONS = {'22', '55', '33'}
# 县区 Region：1 金城江、2 宜州、3 罗城、4 环江、5 南丹、6 天峨、7 东兰、8 巴马、9 凤山、10 都安、11 大化
COUNTY_REGIONS = {str(i) for i in range(1, 12)}

# 公开年限切分点（关停日 2026-11-20 回溯 3 年）
PUBLIC_CUTOFF = datetime(2023, 11, 20, 0, 0, 0, tzinfo=CST)

# 站内域名（含裸域与 www），其余按外站处理
SITE_HOSTS = {'gxhczx.gov.cn', 'www.gxhczx.gov.cn'}
LEGACY_UPLOAD_PREFIX = 'uploads/legacy/'

# 旧库把领导职务写在 Edit 字段（旧站模板就是 `AND Edit LIKE '%主席%' ORDER BY Order`），
# Title1 在主站这批领导稿里是空的；只有这些字样才当职务，避免把"编辑：刁海音"当职务。
ROLE_MARKERS = ('主席', '秘书长', '主任', '党组')

# 正文末尾署名：判据与 PHP 侧 backend/src/Content/AuthorSignature.php 保持一致
# （旧站正文结尾普遍带「（黄荞丹 覃可论）」「口黄正华」这类署名，与作者栏重复）
SIGN_TAIL_CHARS = 160
SIGN_MARKERS = '口□◇■◎○'
SIGN_LABELS = ['本版图片均由', '本报记者', '首席记者', '见习记者', '摄影报道', '本版图片',
               '作者', '摄影', '报道', '供稿', '记者', '通讯员', '图文', '文/图', '图/文', '文', '图', '摄']
SIGN_KEEP = ('系', '单位', '来源', '原载', '刊登', '转自')
SIGN_ORG_TAILS = ('社', '报', '会', '协', '网', '厅', '局', '委', '部', '室', '站', '台', '校', '院',
                  '中心', '公司', '集团', '单位', '频道', '协会', '委员会', '办公厅', '研究院', '工作室')
SIGN_NAME_STOP = ('新华社', '中新社', '人民日报', '广西日报', '河池日报', '本报', '综合', '转载',
                  '壮族', '汉族', '毛南族', '仫佬族', '苗族', '侗族', '瑶族', '回族', '京族', '水族', '彝族', '女', '男')

MANIFEST_HEADER = ['url', 'target', 'status', 'bytes', 'sha256', 'error']

# 抓旧站页面用（ASCII UA；中文写进 UA 会让 urllib 抛 UnicodeEncodeError）
USER_AGENT = 'hechi-zhengxie-migration/1.0 (+https://www.gxhczx.gov.cn)'
NTITLE = re.compile(r'<div class="Ntitle">(.*?)</div>', re.S)
NTIME = re.compile(r'<div class="Ntime">(.*?)</div>', re.S)
NWORD_START = '<div class="Nword">'
BREADCRUMB_TYPE = re.compile(r'news_list\.php\?id=(\d+)')
BOX_WHERE = re.compile(r'<div class="box_where">(.*?)</div>', re.S)


# ---------------------------------------------------------------- SQL 解析

def parse_tuple(body):
    """解析 SQL VALUES 里的单行元组（与 tools/prototype 的实现同源）。"""
    fields, cur, in_quote, i = [], [], False, 0
    while i < len(body):
        ch = body[i]
        if in_quote:
            if ch == '\\':
                cur.append(body[i:i + 2])
                i += 2
                continue
            if ch == "'":
                if i + 1 < len(body) and body[i + 1] == "'":
                    cur.append("''")
                    i += 2
                    continue
                in_quote = False
                i += 1
                continue
            cur.append(ch)
            i += 1
            continue
        if ch == "'":
            in_quote = True
            i += 1
            continue
        if ch == ',':
            fields.append(''.join(cur).strip())
            cur = []
            i += 1
            continue
        cur.append(ch)
        i += 1
    fields.append(''.join(cur).strip())
    return fields


def unescape(raw):
    """SQL 字符串字面量 → Python 字符串。"""
    if raw is None:
        return ''
    if raw.upper() == 'NULL':
        return ''
    if raw.startswith("'") and raw.endswith("'") and len(raw) >= 2:
        raw = raw[1:-1]
    raw = raw.replace("''", "'")
    raw = raw.replace("\\'", "'").replace('\\"', '"')
    raw = raw.replace('\\r', '').replace('\\n', '\n').replace('\\t', ' ')
    return raw


def iter_rows(sql_path, table, fields):
    """逐行读某张表的 INSERT，产出 dict（列数与表定义不一致时跳过并计数）。

    支持两种导出格式：
      * Navicat：一条语句一行、一行一个元组（4/28 那份导出）；
      * mysqldump／宝塔每日备份：一条语句一行、一行多个元组 `(..),(..),…`（9/5 那份全量备份）。
    文件后缀是 .gz 时自动按 gzip 解压读（宝塔备份是 .sql.gz）。
    """
    prefix = 'INSERT INTO `%s`' % table
    skipped = 0
    with open_sql(sql_path) as handle:
        for line in handle:
            if not line.startswith(prefix):
                continue
            head, _, body = line.partition(' VALUES ')
            if not _:
                skipped += 1
                continue
            for inner in split_tuples(body.rstrip().rstrip(';')):
                values = parse_tuple(inner)
                if len(values) != len(fields):
                    skipped += 1
                    continue
                yield {key: unescape(value) for key, value in zip(fields, values)}
    if skipped:
        print('  警告：%s 有 %d 行没能解析，已跳过' % (table, skipped), file=sys.stderr)


def open_sql(sql_path):
    """打开旧库导出：.sql.gz 走 gzip，其余当纯文本。"""
    if str(sql_path).lower().endswith('.gz'):
        return gzip.open(sql_path, 'rt', encoding='utf-8', errors='replace')
    return open(sql_path, encoding='utf-8', errors='replace')


def split_tuples(body):
    """把 `(..),(..),…` 拆成每个顶层元组的内部文本（引号里的括号不算）。"""
    tuples, cur, in_quote, depth, i = [], [], False, 0, 0
    while i < len(body):
        ch = body[i]
        if in_quote:
            if ch == '\\' and i + 1 < len(body):
                cur.append(body[i:i + 2])
                i += 2
                continue
            if ch == "'":
                if i + 1 < len(body) and body[i + 1] == "'":
                    cur.append("''")
                    i += 2
                    continue
                in_quote = False
            cur.append(ch)
            i += 1
            continue
        if ch == "'":
            in_quote = True
            cur.append(ch)
            i += 1
            continue
        if ch == '(':
            depth += 1
            if depth == 1:
                cur = []
                i += 1
                continue
        elif ch == ')':
            depth -= 1
            if depth == 0:
                tuples.append(''.join(cur))
                cur = []
                i += 1
                continue
        if depth > 0:
            cur.append(ch)
        i += 1
    return tuples


# ---------------------------------------------------------------- 文本清洗

TAG_DROP = re.compile(r'</?(?:span|font|o:p|st1:[^>\s/]*|v:[^>\s/]*|w:[^>\s/]*)[^>]*>', re.I)
ATTR_DROP = re.compile(
    r'\s(?:style|class|align|valign|color|face|size|border|bgcolor|cellpadding|cellspacing|lang|xml:lang)'
    r'\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)',
    re.I,
)
EMPTY_P = re.compile(r'<p[^>]*>\s*(?:&nbsp;|<br\s*/?>|\s)*</p>', re.I)
MANY_BR = re.compile(r'(?:\s*<br\s*/?>\s*){3,}', re.I)
MEDIA_ATTR = re.compile(
    r'<\s*(img|video|source|a)\b[^>]*?\s(src|href|poster)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))',
    re.I | re.S,
)
SOURCE_TRAIL = re.compile(r'\s*\d{4}[/-]\d{1,2}(?:[/-]\d{1,2})?.*$')
ATTACH_EXT = re.compile(r'\.(?:docx?|xlsx?|pptx?|pdf|zip|rar|7z|txt)(?:[?#]|$)', re.I)


def clean_source(raw):
    """旧库 From 字段混入的日期与版面（约 1,618 条）在这里去掉。"""
    return SOURCE_TRAIL.sub('', (raw or '').strip()).strip()


def plain_text(raw):
    text = re.sub(r'<[^>]+>', '', raw or '')
    text = html.unescape(text)
    return re.sub(r'\s+', ' ', text).strip()


def clean_body(raw, media_map):
    """旧库正文清洗：去内联样式与冗余空段落，媒体地址按抓取结果改写。

    media_map: 原始地址 → 新地址（只放抓取成功的），不在表里的保持原样。
    """
    text = raw or ''
    text = TAG_DROP.sub('', text)
    text = ATTR_DROP.sub('', text)
    text = re.sub(r'<p\b[^>]*>', '<p>', text, flags=re.I)
    for _ in range(3):
        text = EMPTY_P.sub('', text)
    text = MANY_BR.sub('\n', text)
    text = text.replace('&nbsp;', ' ')
    text = re.sub(r'[ \t]{2,}', ' ', text)
    if media_map:
        text = MEDIA_ATTR.sub(_rewrite_attr(media_map), text)
    return text.strip()


# ---------------------------------------------------------------- 末尾署名

def text_map(raw):
    """HTML → (纯文本, 每个字符对应的原文区间)。

    旧库正文里换行、<br>、空段很多，按字节估算的位置跟可见文字对不上；这里把每个可见字符
    映射回它在原文里的下标区间，定位到署名后就能精确回删。与 PHP 侧 textMap() 同口径。
    """
    text, spans, offset = [], [], 0
    while offset < len(raw):
        lt = raw.find('<', offset)
        chunk_end = len(raw) if lt < 0 else lt
        if chunk_end > offset:
            chunk = raw[offset:chunk_end]
            # 只收可见字符（理由见 PHP 侧 textMap）：旧库正文里的换行与全角空格会把署名挤出窗口
            for token in re.finditer(r'&[#a-zA-Z0-9]{2,8};|[^\s\u00a0\u2000-\u200b\u202f\u205f\u3000]', chunk):
                piece = token.group(0)
                decoded = html.unescape(piece) if piece.startswith('&') else piece
                for char in decoded:
                    text.append(char)
                    spans.append((offset + token.start(), offset + token.end()))
        if lt < 0:
            break
        gt = raw.find('>', lt)
        offset = len(raw) if gt < 0 else gt + 1
    return ''.join(text), spans


def _squash(text):
    return re.sub(r'[\s\u3000\u00a0\u2002\u2003\u2009、,，·•/／:：]+', '', text or '')


def _collapse(text):
    text = re.sub(r'[\u00a0\u2002\u2003\u2009\u3000]+', ' ', text or '')
    return re.sub(r'\s+', ' ', text).strip()


def _sign_tokens(author, min_length=1):
    tokens = []
    for token in re.split(r'[\s\u3000\u00a0、,，]+', (author or '').strip()):
        token = re.sub(r'等+$', '', token)
        if len(token) >= min_length:
            tokens.append(token)
    return tokens


def _drop_sign_labels(text):
    labels = sorted(SIGN_LABELS, key=len, reverse=True)
    for _ in range(4):
        before = text
        for label in labels:
            if text == label:
                return text
            if text.startswith(label):
                text = text[len(label):]
            if text.endswith(label):
                text = text[:-len(label)]
        text = text.strip(' \t\n\r、,，·:：/／（）()《》')
        if text == before:
            break
    return text


def _name_like(text):
    return bool(text) and len(text) <= 12 and re.fullmatch(r'[\u4e00-\u9fa5·•]+', text) is not None


def _matches_author(inner, author):
    raw = _collapse(inner)
    if any(mark in raw for mark in SIGN_KEEP):
        return False
    if '电' in raw and ('记者' in raw or '新华社' in raw):
        return False
    text, target = _drop_sign_labels(_squash(raw)), _squash(author)
    if not text or not target:
        return False
    if text == target:
        return True
    if len(text) >= 2 and text in target:
        return True
    tokens = _sign_tokens(author, 2)
    if not tokens:
        return False
    extra = text
    for token in tokens:
        if token not in extra:
            return False
        extra = extra.replace(token, '', 1)
    return extra == '' or _name_like(extra)


def _marker_pattern(author):
    tokens = _sign_tokens(author)
    if not tokens:
        return None
    parts = ['\\s*'.join(re.escape(char) for char in token) for token in tokens]
    run = r'[\s、，,·/／]*'.join(parts)
    return re.compile('[' + SIGN_MARKERS + '](' + run + r')(\s*(?:图\s*/\s*文|文\s*/\s*图|图文|摄影报道|报道|摄))?')


def signature_spans(raw, author):
    """正文末尾与作者栏对得上的署名区间（字符下标，[起, 止)），与 PHP 侧同口径。"""
    if not (author or '').strip():
        return []
    text, _ = text_map(raw)
    if not text:
        return []
    tail_from = max(0, len(text) - SIGN_TAIL_CHARS)
    tail = text[tail_from:]
    spans = []
    hits = [m for m in re.finditer(r'[（(]([^（()）]{1,60})[)）]', tail) if _matches_author(m.group(1), author)]
    if hits:
        last = hits[-1]
        spans.append((tail_from + last.start(), tail_from + last.end()))
    pattern = _marker_pattern(author)
    if pattern is not None:
        hits = list(pattern.finditer(tail))
        if hits:
            last = hits[-1]
            spans.append((tail_from + last.start(), tail_from + last.end()))
    if not spans:
        return []
    spans.sort()
    merged = []
    for span in spans:
        if merged and span[0] <= merged[-1][1]:
            merged[-1] = (merged[-1][0], max(merged[-1][1], span[1]))
            continue
        merged.append(span)
    return merged


def extract_author_name(raw):
    """作者栏为空时从末尾署名里取名字：「口姓名」或「（作者：X）」，取不到返回空串。"""
    text, mapping = text_map(raw)
    tail = text[-SIGN_TAIL_CHARS:]
    match = re.search(r'[（(]\s*作者\s*[：:]?\s*([^（()）]{1,20}?)\s*[)）]\s*$', tail)
    if match:
        start = len(text) - len(tail) + match.start(1)
        # 「作者：本报首席记者 罗昌亮」→「罗昌亮」：署名标签在填入作者栏前先去掉
        name = _drop_sign_labels(_squash(_raw_between(raw, mapping, start, start + len(match.group(1)))))
        if not any(mark in name for mark in SIGN_KEEP) and _name_like(name):
            return name
    match = re.search('[' + SIGN_MARKERS + r']\s*([\u4e00-\u9fa5·]{2,10})\s*$', tail)
    if match:
        return match.group(1)
    # 末尾就是一个光括号姓名（「（韦立辉）」「（韦瑞展 袁文展）」）：挡住民族成分、通稿署名与机构名。
    # 姓名回原 HTML 里取：文本映射去掉了空白，直接读文本会把「韦瑞展 袁文展」粘成一个词
    match = re.search(r'[（(]([^（()）]{2,12}?)[)）]\s*$', tail)
    if match:
        start = len(text) - len(tail) + match.start(1)
        name = _raw_between(raw, mapping, start, start + len(match.group(1)))
        if _looks_like_person_name(name):
            return name
    return ''


def _raw_between(raw, mapping, start, end):
    """把「纯文本里的片段」还原成原始 HTML 里的文字（保留词间空格，去标签、压空白）。"""
    byte_from, _ = mapping[start]
    _, byte_to = mapping[end - 1]
    return _collapse(re.sub(r'<[^>]+>', '', raw[byte_from:byte_to]))


def _looks_like_person_name(text):
    """括号里的文字是否像一个／组人名（1～3 个 2～4 字的姓名，且不是机构名与常见非人名）。"""
    if not text or text in SIGN_NAME_STOP:
        return False
    if any(text.endswith(tail) for tail in SIGN_ORG_TAILS):
        return False
    tokens = [t for t in re.split(r'[\s\u3000]+', text) if t]
    if not tokens or len(tokens) > 3:
        return False
    for token in tokens:
        # 文本映射已去掉空白，「韦瑞展 袁文展」在这里是 6 个字，所以上限放到 8
        if not (2 <= len(token) <= 8) or re.fullmatch(r'[\u4e00-\u9fa5·]+', token) is None:
            return False
    return True


def strip_author_signature(raw, author):
    """删掉正文末尾与作者栏重复的署名，返回 (新正文, 入库用的作者栏)。

    判据同 PHP 侧 AuthorSignature；作者栏为空时先从末尾署名取名（「口潘剑」「（作者：X）」），
    取到就回填作者栏再删，取不到则一个字节都不动。
    """
    author = (author or '').strip()
    effective = author
    if not author:
        effective = extract_author_name(raw)
        if not effective:
            return raw, author
    spans = signature_spans(raw, effective)
    if not spans:
        return raw, author
    _, mapping = text_map(raw)
    out = raw
    for start, end in reversed(spans):
        out = out[:mapping[start][0]] + out[mapping[end - 1][1]:]
    return tidy_tail(out), effective


def tidy_tail(raw):
    """删署名后留下的空块与多余空白（`<div>（黄炼）</div>` 这种壳子不再占位），同 PHP 侧 tidyTail()。"""
    while True:
        before = raw
        raw = re.sub(r'<(div|p|span|strong|b)\b[^>]*>(?:\s|&nbsp;|&#\d+;|<br\s*/?>)*</\1>\s*$',
                     '', raw, flags=re.I)
        raw = re.sub(r'(?:\s|<br\s*/?>)+$', '', raw, flags=re.I)
        if raw == before:
            return raw


def _rewrite_attr(media_map):
    def replace(match):
        raw = match.group(0)
        url = match.group(3) or match.group(4) or match.group(5) or ''
        url = url.strip()
        target = media_map.get(url)
        if not target:
            return raw
        at = raw.find(url)
        if at < 0:
            return raw
        return raw[:at] + target + raw[at + len(url):]
    return replace


def media_urls(raw):
    """正文里出现过的媒体地址（按出现顺序去重），含 img/video/source 与附件链接。"""
    seen, out = set(), []
    for match in MEDIA_ATTR.finditer(raw or ''):
        url = (match.group(3) or match.group(4) or match.group(5) or '').strip()
        if not url or url.startswith('data:') or url.startswith('#'):
            continue
        tag, attr = match.group(1).lower(), match.group(2).lower()
        if tag == 'a' and not ATTACH_EXT.search(url):
            continue
        if url not in seen:
            seen.add(url)
            out.append(url)
    return out


# ---------------------------------------------------------------- 媒体路径

def host_of(url):
    match = re.match(r'^(?:https?:)?//([^/]+)', url, re.I)
    return match.group(1).lower() if match else ''


def local_target(url, media_index):
    """原始地址 → (仓库相对落盘路径, 站内访问地址)。外站图片统一放 remote/。"""
    url = url.strip()
    host = host_of(url)
    path = re.sub(r'^(?:https?:)?//[^/]+', '', url).split('?')[0].split('#')[0]
    path = path if path.startswith('/') else '/' + path
    if host in SITE_HOSTS:
        rel = LEGACY_UPLOAD_PREFIX + path.lstrip('/')
    else:
        base = os.path.basename(path) or 'file'
        digest = hashlib.sha1(url.encode('utf-8')).hexdigest()[:10]
        rel = LEGACY_UPLOAD_PREFIX + 'remote/' + re.sub(r'[^A-Za-z0-9._-]', '_', host or 'site') + '_' + digest + '_' + base
    rel = re.sub(r'/+', '/', rel)
    return 'backend/public/' + rel, '/' + rel


# ---------------------------------------------------------------- 命令：parse

def load_channels(path):
    with open(path, encoding='utf-8') as handle:
        data = json.load(handle)
    channels = {}
    for channel in data.get('channels', []):
        channels[str(channel['type'])] = {
            'type': str(channel['type']),
            'name': str(channel.get('name', '')),
            'inner': str(channel.get('inner', '')),
            'slug': str(channel.get('slug', '')),
        }
    return channels


def to_datetime(ts):
    try:
        return datetime.fromtimestamp(int(ts), CST)
    except (ValueError, OverflowError, OSError):
        return None


def build_record(row, channel, manual_region_map):
    published = to_datetime(row['Time'])
    audit = '1' if (row['Audit'] or '').strip() == '1' else '0'
    return {
        'article_id': int(row['ID']),
        'channel_type': channel['type'],
        'title': plain_text(row['Title']),
        'title1': (row['Title1'] or '').strip(),
        'title2': (row['Title2'] or '').strip(),
        'content_raw': row['Word'] or '',
        'source': clean_source(row['From']),
        'author': (row['Author'] or '').strip(),
        'editor': (row['Edit'] or '').strip(),
        'views': (row['Num'] or '0').strip() or '0',
        'pic': (row['Pic'] or '').strip(),
        'audit': audit,
        'status': 'published' if audit == '1' else 'draft',
        'public_scope': 'public' if (published is not None and published >= PUBLIC_CUTOFF) else 'archive',
        'published_at': published.strftime('%Y-%m-%d %H:%M:%S') if published else '',
        'old_order': (row['Order'] or '0').strip() or '0',
        'old_type': (row['Type'] or '').strip(),
        'old_region': (row['Region'] or '').strip(),
        'region_rule': manual_region_map.get(channel['type'], '22'),
    }


def fetch_raw(url, timeout=15, retries=3):
    """取旧站页面（现网）原文，失败抛异常。"""
    last = None
    for attempt in range(1, retries + 1):
        try:
            request = urllib.request.Request(url, headers={'User-Agent': USER_AGENT})
            with urllib.request.urlopen(request, timeout=timeout) as response:
                payload = response.read()
            return payload.decode('utf-8', errors='replace')
        except Exception as exc:  # noqa: BLE001 - 网络异常统一重试
            last = exc
        if attempt < retries:
            time.sleep(0.5 * attempt)
    raise RuntimeError('抓取 %s 失败：%s' % (url, last))


def fetch_html(url, timeout=15, retries=3):
    """取旧站稿件页 HTML（现网），并确认确实是稿件页。"""
    text = fetch_raw(url, timeout, retries)
    if '<div class="Ntitle">' in text or NWORD_START in text:
        return text
    raise RuntimeError('%s 不像旧站稿件页（没有 Ntitle/Nword 容器）' % url)


# 面包屑里没有栏目链接时的探测顺序（旧站 news_view.php 查 menu 表查不到 902—906，
# 这几个栏目的稿件面包屑是空的，只能回列表页认领）
PROBE_CHANNELS = ['904', '906', '306', '314', '902', '903', '302', '311', '308', '317']


def probe_channel(news_id, channels, base, max_pages=3):
    """在候选栏目的列表页里找这篇稿件，返回栏目号（找不到返回空串）。"""
    needle_a = 'news_view.php?id=%s' % news_id
    needle_b = 'news-view-%s.html' % news_id
    for type_code in PROBE_CHANNELS:
        if type_code not in channels:
            continue
        for page in range(1, max_pages + 1):
            try:
                text = fetch_raw('%s/news_list.php?id=%s&page=%d' % (base.rstrip('/'), type_code, page))
            except Exception:  # noqa: BLE001 - 探测失败就换下一个栏目
                break
            if needle_a in text or needle_b in text:
                return type_code
    return ''


def extract_old_article(news_id, raw):
    """从旧站稿件页 HTML 里抽字段；栏目号只认面包屑里的（全文第一个 id 往往是导航项）。"""
    title_match = NTITLE.search(raw)
    time_match = NTIME.search(raw)
    start = raw.find(NWORD_START)
    body = raw[start + len(NWORD_START):]
    cut = body.find('<!--')
    if cut >= 0:
        body = body[:cut]
    body = re.sub(r'(?:\s*</div>\s*)+$', '', body)

    meta = html.unescape(re.sub(r'<[^>]+>', '', time_match.group(1))) if time_match else ''
    meta = meta.replace('\xa0', ' ')
    title = html.unescape(re.sub(r'<[^>]+>', '', title_match.group(1))).strip() if title_match else ''
    box = BOX_WHERE.search(raw)
    types = BREADCRUMB_TYPE.findall(box.group(1)) if box else []
    published = ''
    match = re.search(r'时间：\s*(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})', meta)
    if match:
        published = match.group(1)

    def field(label):
        others = [x for x in ['阅读', '来源', '作者', '编辑', '时间'] if x != label]
        hit = re.search(r'%s：\s*(.*?)(?=(?:%s)：|$)' % (label, '|'.join(others)), meta, re.S)
        return hit.group(1).strip() if hit else ''

    return {
        'id': str(news_id),
        'title': title,
        'body': body,
        'published_at': published,
        'views': field('阅读'),
        'source': clean_source(field('来源')),
        'author': field('作者'),
        'editor': field('编辑'),
        'channel_type': types[-1] if types else '',
    }


def parse_old_article(news_id, site_root, base):
    """解析旧站稿件页：优先本地静态页（省抓网），面包屑缺栏目链接时回落到现网页面。

    本地那批 html/news-view-<id>.html 的面包屑只有"首页 >> 标题"，拿不到栏目号；
    现网 news_view.php 的面包屑带着栏目链接（如 news_list.php?id=314），所以这种情况下抓现网。
    """
    local = os.path.join(site_root, 'html', 'news-view-%s.html' % news_id) if site_root else ''
    if local and os.path.isfile(local) and os.path.getsize(local) > 500:
        with open(local, encoding='utf-8', errors='replace') as handle:
            parsed = extract_old_article(news_id, handle.read())
        if parsed['channel_type']:
            parsed['from'] = '本地静态页'
            return parsed
    parsed = extract_old_article(news_id, fetch_html('%s/news_view.php?id=%s' % (base.rstrip('/'), news_id)))
    parsed['from'] = '旧站现网'
    return parsed


def extra_row(parsed):
    """把解析结果转成 rd_news 形状，交给 build_record 走同一套口径。"""
    stamp = 0
    if parsed['published_at']:
        stamp = int(datetime.strptime(parsed['published_at'], '%Y-%m-%d %H:%M:%S')
                    .replace(tzinfo=CST).timestamp())
    return {
        'ID': parsed['id'], 'Title': parsed['title'], 'Title1': '', 'Title2': '',
        'Pic': '', 'Word': parsed['body'], 'Audit': '1', 'Region': '22',
        'Hot': '0', 'Top': '0', 'Type': parsed['channel_type'], 'Num': parsed['views'] or '0',
        'Order': '0', 'Time': str(stamp), 'From': parsed['source'],
        'Author': parsed['author'], 'Edit': parsed['editor'],
    }


def slides_missing_ids(db_path, channels):
    """从轮播表里找出"指向旧站详情、但新库还没有"的稿件号（首页轮换图常见）。"""
    import sqlite3
    ids = []
    if not db_path or not os.path.isfile(db_path):
        return ids
    con = sqlite3.connect(db_path)
    rows = con.execute('SELECT article_id, link_url FROM cms_home_slide').fetchall()
    have = {int(r[0]) for r in con.execute('SELECT article_id FROM cms_article')}
    for article_id, link in rows:
        if int(article_id or 0) > 0:
            continue
        match = re.search(r'news_view\.php\?[^"\']*?\bid=(\d+)', link or '') or re.search(r'news-view-(\d+)\.html', link or '')
        if match and int(match.group(1)) not in have:
            ids.append(match.group(1))
    return sorted(set(ids), key=int)


def classify_old_row(row, channels, region_map):
    """旧库一行属于哪一类：public / archive / draft（范围内）或 unmapped / county / other（范围外）。"""
    type_code = (row['Type'] or '').strip()
    region = (row['Region'] or '').strip()
    if type_code in channels and region == region_map.get(type_code, '22'):
        if (row['Audit'] or '').strip() != '1':
            return 'draft'
        published = to_datetime(row['Time'])
        return 'public' if (published is not None and published >= PUBLIC_CUTOFF) else 'archive'
    if region in MAIN_REGIONS:
        return 'unmapped'
    if region in COUNTY_REGIONS:
        return 'county'
    return 'other'


def sync_deleted(args, channels, region_map, out_dir, current_ids):
    """--deleted-from：旧站已删稿件（基准导出有、本次导出没有）→ 清单 + 分类小计。

    返回 (删除稿件号列表, 分类计数)。分类只说明这批稿件原本是哪一类，导入端统一按
    "既存稿件转 archive" 处理（见 legacy_import.php 的 --archive-ids）。
    """
    old_rows = {}
    for row in iter_rows(args.deleted_from, 'rd_news', NEWS_FIELDS):
        old_rows[int(row['ID'] or 0)] = row
    deleted_ids = sorted(set(old_rows) - set(current_ids))
    buckets = {'public': 0, 'archive': 0, 'draft': 0, 'unmapped': 0, 'county': 0, 'other': 0}
    for article_id in deleted_ids:
        buckets[classify_old_row(old_rows[article_id], channels, region_map)] += 1

    with open(os.path.join(out_dir, 'deleted_ids.txt'), 'w', encoding='utf-8') as handle:
        if deleted_ids:
            handle.write('\n'.join(str(i) for i in deleted_ids) + '\n')

    lines = [
        '删除同步（--deleted-from）',
        '对比基准：' + args.deleted_from,
        '旧站已删稿件：%d 篇' % len(deleted_ids),
        '  范围内-前台可见（public）：%d 篇（入库后转 archive，前台下线）' % buckets['public'],
        '  范围内-归档（archive）：%d 篇' % buckets['archive'],
        '  范围内-草稿（draft）：%d 篇' % buckets['draft'],
        '  主站未映射栏目：%d 篇（本来就没入库）' % buckets['unmapped'],
        '  县区（Region 1-11）：%d 篇（本来就没入库）' % buckets['county'],
        '  其他（Region 33/55 等）：%d 篇' % buckets['other'],
        '',
        '稿件号清单：deleted_ids.txt（%d 个），导入时用 --archive-ids 指向它' % len(deleted_ids),
    ]
    with open(os.path.join(out_dir, 'deleted_summary.txt'), 'w', encoding='utf-8') as handle:
        handle.write('\n'.join(lines) + '\n')
    print('  删除同步：旧站已删 %d 篇（public %d、archive %d、draft %d、未映射 %d、县区 %d、其他 %d）'
          % (len(deleted_ids), buckets['public'], buckets['archive'], buckets['draft'],
             buckets['unmapped'], buckets['county'], buckets['other']))
    return deleted_ids, buckets


def cmd_parse(args):
    channels = load_channels(args.channels)
    region_map = dict(DEFAULT_REGION_MAP)
    out_dir = args.out
    os.makedirs(out_dir, exist_ok=True)

    articles, unmapped, in_scope_rows = [], [], 0
    media = {}
    per_channel = {}
    out_of_scope = 0
    old_total = 0
    max_id = 0
    all_ids = set()
    extra_report = []

    for row in iter_rows(args.sql, 'rd_news', NEWS_FIELDS):
        old_total += 1
        article_id = int(row['ID'] or 0)
        max_id = max(max_id, article_id)
        all_ids.add(article_id)
        type_code = (row['Type'] or '').strip()
        region = (row['Region'] or '').strip()
        channel = channels.get(type_code)
        if channel is None:
            if region in MAIN_REGIONS:
                unmapped.append({
                    'article_id': row['ID'], 'old_type': type_code, 'old_region': region,
                    'audit': row['Audit'], 'title': plain_text(row['Title']),
                })
            else:
                out_of_scope += 1
            continue
        if region != region_map.get(type_code, '22'):
            out_of_scope += 1
            continue

        record = build_record(row, channel, region_map)
        articles.append(record)
        in_scope_rows += 1
        per_channel[type_code] = per_channel.get(type_code, 0) + 1

        for url in [record['pic']] + media_urls(record['content_raw']):
            if url and url not in media:
                target, url_path = local_target(url, len(media))
                media[url] = {'target': target, 'url_path': url_path}

    # 导出完整性自检：行数与最大稿件号对不上就停，避免拿半截库跑后面的流程
    if args.expect_rows and old_total != args.expect_rows:
        print('  自检失败：rd_news 实际 %d 行，期望 %d 行' % (old_total, args.expect_rows), file=sys.stderr)
        return 2
    if args.expect_max_id and max_id != args.expect_max_id:
        print('  自检失败：rd_news 最大稿件号 %d，期望 %d' % (max_id, args.expect_max_id), file=sys.stderr)
        return 2

    # 补充稿件：旧站有、导出库里没有的（首页轮换图指向 8—9 月新稿就是这种情况）
    extra_ids = [i.strip() for i in (args.extra_ids or '').split(',') if i.strip()]
    if args.extra_from_slides:
        discovered = slides_missing_ids(args.db, channels)
        extra_ids = sorted(set(extra_ids) | set(discovered), key=int)
        if discovered:
            print('  从轮播表发现 %d 篇旧站有、新库没有的稿件：%s' % (len(discovered), '、'.join(discovered)))
    for news_id in extra_ids:
        try:
            parsed = parse_old_article(news_id, args.site, args.base)
        except Exception as exc:  # noqa: BLE001 - 单篇失败不影响整批
            extra_report.append('%s 抓取失败：%s' % (news_id, exc))
            continue
        channel = channels.get(parsed['channel_type'])
        if channel is None and not parsed['channel_type']:
            probed = probe_channel(news_id, channels, args.base)
            if probed:
                parsed['channel_type'] = probed
                channel = channels.get(probed)
                parsed['from'] += '＋列表页认领'
        if channel is None:
            extra_report.append('%s 栏目号 %s 不在新站栏目里，跳过' % (news_id, parsed['channel_type'] or '空'))
            continue
        record = build_record(extra_row(parsed), channel, region_map)
        record['meta'] = {'extra': True, 'from': parsed['from']}
        articles.append(record)
        in_scope_rows += 1
        per_channel[channel['type']] = per_channel.get(channel['type'], 0) + 1
        for url in [record['pic']] + media_urls(record['content_raw']):
            if url and url not in media:
                target, url_path = local_target(url, len(media))
                media[url] = {'target': target, 'url_path': url_path}
        extra_report.append('%s %s（栏目 %s，%s，%s）' % (
            news_id, record['title'][:24], channel['type'], parsed['published_at'], parsed['from']))

    # 删除同步：旧站删掉的稿件，本次导出里没有 → 清单交给导入端转 archive
    deleted_ids, deleted_buckets = [], {}
    if args.deleted_from:
        if not os.path.isfile(args.deleted_from):
            print('  找不到删除对比基准：%s' % args.deleted_from, file=sys.stderr)
            return 1
        deleted_ids, deleted_buckets = sync_deleted(args, channels, region_map, out_dir, all_ids)

    with open(os.path.join(out_dir, 'articles.jsonl'), 'w', encoding='utf-8') as handle:
        for record in articles:
            handle.write(json.dumps(record, ensure_ascii=False) + '\n')

    with open(os.path.join(out_dir, 'media_manifest.csv'), 'w', encoding='utf-8', newline='') as handle:
        writer = csv.writer(handle)
        writer.writerow(MANIFEST_HEADER)
        for url, info in media.items():
            writer.writerow([url, info['target'], 'pending', '', '', ''])

    with open(os.path.join(out_dir, 'unmapped.csv'), 'w', encoding='utf-8', newline='') as handle:
        writer = csv.writer(handle)
        writer.writerow(['article_id', 'old_type', 'old_region', 'audit', 'title'])
        for item in unmapped:
            writer.writerow([item['article_id'], item['old_type'], item['old_region'], item['audit'], item['title']])

    with open(os.path.join(out_dir, 'mapping_report.csv'), 'w', encoding='utf-8', newline='') as handle:
        writer = csv.writer(handle)
        writer.writerow(['old_type', 'channel_type', 'channel_inner', 'region_rule', 'count'])
        for type_code in sorted(per_channel, key=lambda t: -per_channel[t]):
            channel = channels[type_code]
            writer.writerow([type_code, channel['type'], channel['inner'], region_map.get(type_code, '22'), per_channel[type_code]])

    side = build_side_tables(args)
    with open(os.path.join(out_dir, 'side_tables.json'), 'w', encoding='utf-8') as handle:
        json.dump(side, handle, ensure_ascii=False, indent=2)
        handle.write('\n')

    stats = {
        'old_news_rows': old_total,
        'in_scope': in_scope_rows,
        'published_public': sum(1 for r in articles if r['status'] == 'published' and r['public_scope'] == 'public'),
        'published_archive': sum(1 for r in articles if r['status'] == 'published' and r['public_scope'] == 'archive'),
        'draft': sum(1 for r in articles if r['status'] == 'draft'),
        'unmapped_main': len(unmapped),
        'county_skipped': out_of_scope,
        'channels_with_data': len(per_channel),
        'media_urls': len(media),
        'media_site': sum(1 for u in media if host_of(u) in SITE_HOSTS),
        'media_remote': sum(1 for u in media if host_of(u) not in SITE_HOSTS),
        'side_videos': len(side['videos']),
        'side_links': len(side['links']),
        'side_hudong': len(side['hudong']),
        'public_cutoff': PUBLIC_CUTOFF.strftime('%Y-%m-%d'),
        'max_news_id': max_id,
        'deleted_total': len(deleted_ids),
        'deleted_public': deleted_buckets.get('public', 0),
        'deleted_archive': deleted_buckets.get('archive', 0),
        'deleted_draft': deleted_buckets.get('draft', 0),
        'deleted_unmapped': deleted_buckets.get('unmapped', 0),
        'deleted_county': deleted_buckets.get('county', 0),
        'deleted_other': deleted_buckets.get('other', 0),
    }
    with open(os.path.join(out_dir, 'stats.json'), 'w', encoding='utf-8') as handle:
        json.dump(stats, handle, ensure_ascii=False, indent=2)
        handle.write('\n')

    if extra_report:
        with open(os.path.join(out_dir, 'extra_articles.txt'), 'w', encoding='utf-8') as handle:
            handle.write('\n'.join(extra_report) + '\n')

    print('旧库 rd_news 共 %d 篇' % old_total)
    print('  稿件号范围：最大 ID %d，AUTO_INCREMENT 参考值 %d'
          % (max_id, max_id + 1))
    print('  本次迁移 %d 篇：published+public %d、published+archive %d、draft %d'
          % (stats['in_scope'], stats['published_public'], stats['published_archive'], stats['draft']))
    print('  未映射（主站口径）%d 篇，县区/口径外跳过 %d 篇' % (stats['unmapped_main'], stats['county_skipped']))
    if deleted_ids:
        print('  删除同步清单：deleted_ids.txt（%d 个稿件号）' % len(deleted_ids))
    print('  媒体 %d 个（站内 %d、外站 %d）；别名表 %d 个栏目'
          % (stats['media_urls'], stats['media_site'], stats['media_remote'], stats['channels_with_data']))
    print('  次要表：视频 %d、友情链接 %d、互动 %d' % (stats['side_videos'], stats['side_links'], stats['side_hudong']))
    print('  产物目录：%s' % out_dir)
    return 0


def build_side_tables(args):
    """次要表：只取与新站对得上的三项（视频／友情链接／互动）。"""
    videos, links, hudong = [], [], []
    if args.skip_side_tables:
        return {'videos': [], 'links': [], 'hudong': []}
    for row in iter_rows(args.sql, 'rd_video', VIDEO_FIELDS):
        if (row['Audit'] or '').strip() == '1':
            videos.append({'title': plain_text(row['Title']), 'url': (row['Url'] or '').strip(), 'img': ''})
    for row in iter_rows(args.sql, 'rd_links', LINK_FIELDS):
        if (row['Audit'] or '').strip() == '1':
            links.append({'title': plain_text(row['Name']), 'url': (row['Url'] or '').strip(),
                          'img': (row['Pic'] or '').strip()})
    for row in iter_rows(args.sql, 'rd_hudong', HUDONG_FIELDS):
        published = to_datetime(row['Time'])
        parts = []
        if (row['Zhaiyao'] or '').strip():
            parts.append('<p>来信内容：%s</p>' % html.escape(plain_text(row['Zhaiyao'])))
        if (row['Word'] or '').strip():
            parts.append(row['Word'])
        if (row['Banli'] or '').strip():
            parts.append('<p>办理回复：%s</p>' % html.escape(plain_text(row['Banli'])))
        reply = to_datetime(row['ReplyTime'])
        hudong.append({
            'article_id': int(row['ID']),
            'channel_type': 'interactive',
            'title': plain_text(row['Title']),
            'content_html': '\n'.join(parts),
            'source': (row['From'] or '').strip(),
            'author': (row['Send'] or '').strip(),
            'published_at': published.strftime('%Y-%m-%d %H:%M:%S') if published else '',
            'reply_at': reply.strftime('%Y-%m-%d %H:%M:%S') if reply else '',
            'status': 'published' if (row['Audit'] or '').strip() == '1' else 'draft',
            'public_scope': 'public' if (published is not None and published >= PUBLIC_CUTOFF) else 'archive',
        })
    return {'videos': videos, 'links': links, 'hudong': hudong}


# ---------------------------------------------------------------- 命令：render

def read_manifest(path, local_check=False):
    """媒体清单 → {原始地址: 站内地址}。

    只收 status=ok 的条目；带 local_check 时再按 target 在本地是否存在判断——从服务器
    拷过来的文件不用先改清单就能直接改写地址。
    返回 (映射, 失败数, 按本地文件判定的条数, 清单总行数)；清单文件不存在时抛 OSError。
    """
    mapping, failed, local_ready, total_rows = {}, 0, 0, 0
    if not path:
        return mapping, failed, local_ready, total_rows
    with open(path, encoding='utf-8', newline='') as handle:  # 文件不存在时由调用方兜住
        for row in csv.DictReader(handle):
            total_rows += 1
            status = (row.get('status') or '').strip().lower()
            target = (row.get('target') or '').strip()
            ready = status == 'ok'
            if not ready and local_check and target:
                absolute = target if os.path.isabs(target) else os.path.join(os.getcwd(), target)
                if os.path.isfile(absolute) and os.path.getsize(absolute) > 0:
                    ready = True
                    local_ready += 1
            if not ready:
                if status not in ('', 'pending'):
                    failed += 1
                continue
            rel = os.path.relpath(target, 'backend/public').replace(os.sep, '/')
            if rel.startswith('..'):
                failed += 1
                continue
            url_path = '/' + rel
            mapping[row['url'].strip()] = url_path
    return mapping, failed, local_ready, total_rows


def cmd_render(args):
    try:
        media_map, failed, local_ready, total_rows = read_manifest(args.manifest, args.local_check)
    except OSError as exc:
        print('读不到媒体清单：%s（清单由 parse 产出，重跑一次 parse 可再生）' % exc, file=sys.stderr)
        return 1
    if args.local_check and total_rows and not media_map:
        print('提示：清单 %d 条里本地一个都没找到。--local-check 按仓库根目录的相对路径找文件，'
              '请在仓库根目录运行，或确认图片是否真的拷到了 backend/public/uploads/legacy/ 下。' % total_rows,
              file=sys.stderr)
    articles, stats = [], {'rendered': 0, 'images': 0, 'attachments': 0, 'media_rewritten': 0,
                           'content_chars': 0, 'signature_stripped': 0, 'author_filled': 0}

    with open(args.infile, encoding='utf-8') as handle:
        for line in handle:
            line = line.strip()
            if not line:
                continue
            record = json.loads(line)
            body = clean_body(record['content_raw'], media_map)
            # 末尾署名归口作者栏：删掉与作者栏重复的署名；作者栏为空的按能确定的署名回填
            clean_body_text, author = strip_author_signature(body, record['author'])
            if clean_body_text != body:
                stats['signature_stripped'] += 1
            if author != record['author']:
                stats['author_filled'] += 1
            body = clean_body_text
            images = [u for u in media_urls(body) if not ATTACH_EXT.search(u)]
            attachments = []
            for match in MEDIA_ATTR.finditer(record['content_raw']):
                tag, attr = match.group(1).lower(), match.group(2).lower()
                url = (match.group(3) or match.group(4) or match.group(5) or '').strip()
                if tag == 'a' and ATTACH_EXT.search(url):
                    attachments.append({'url': media_map.get(url, url), 'ext': ATTACH_EXT.search(url).group(0).lstrip('.').lower()})

            thumb = media_map.get(record['pic'], record['pic'])
            is_leader = record['channel_type'] == '202'
            summary = '' if is_leader else record['title1']
            # 领导职务优先取 Title1；Title1 为空时（主站这批领导稿都是空的）取 Edit 里的职务字样
            role = ''
            if is_leader:
                role = record['title1'].strip()
                if role == '' and any(mark in record['editor'] for mark in ROLE_MARKERS):
                    role = record['editor'].strip()

            stats['media_rewritten'] += sum(1 for u in images if u.startswith('/' + LEGACY_UPLOAD_PREFIX))
            stats['images'] += len(images)
            stats['attachments'] += len(attachments)
            stats['content_chars'] += len(body)
            stats['rendered'] += 1

            articles.append({
                'article_id': record['article_id'],
                'channel_type': record['channel_type'],
                'title': record['title'],
                'summary': summary,
                'role': role,
                'content_html': body,
                'has_body': 1 if body.strip() else 0,
                'source': record['source'],
                'author': author,
                'editor': record['editor'],
                'published_at': record['published_at'],
                'views': record['views'],
                'thumb': thumb,
                'status': record['status'],
                'public_scope': record['public_scope'],
                'sort_no': int(record['old_order']) if is_leader and record['old_order'].isdigit() else 0,
                'images': images,
                'attachments': attachments,
                'meta': {
                    'old_type': record['old_type'],
                    'old_region': record['old_region'],
                    'audit': record['audit'],
                    'title2_dropped': bool(record['title2']),
                },
            })

    final_path = os.path.join(args.out, 'articles.final.jsonl')
    with open(final_path, 'w', encoding='utf-8') as handle:
        for record in articles:
            handle.write(json.dumps(record, ensure_ascii=False) + '\n')

    stats['media_failed'] = failed
    stats['media_in_manifest'] = len(media_map)
    with open(os.path.join(args.out, 'render_stats.json'), 'w', encoding='utf-8') as handle:
        json.dump(stats, handle, ensure_ascii=False, indent=2)
        handle.write('\n')

    print('渲染 %d 篇；正文图片 %d 张（其中 %d 张已改写为站内地址）、附件 %d 条、正文合计 %d 字'
          % (stats['rendered'], stats['images'], stats['media_rewritten'], stats['attachments'], stats['content_chars']))
    print('末尾署名：删除署名 %d 篇、按署名回填作者栏 %d 篇'
          % (stats['signature_stripped'], stats['author_filled']))
    if args.manifest:
        extra = ('，其中按本地文件判定可用 %d 条' % local_ready) if args.local_check else ''
        print('媒体清单：可用 %d 条%s，不可用 %d 条（不可用的保留原地址）'
              % (len(media_map), extra, failed))
    else:
        print('未提供媒体清单：正文图片保持旧站原地址')
    print('产物：%s' % final_path)
    return 0


def main():
    parser = argparse.ArgumentParser(description='旧库迁移第一段：解析与清洗（只读旧库导出）')
    sub = parser.add_subparsers(dest='command', required=True)

    common = argparse.ArgumentParser(add_help=False)
    common.add_argument('--sql', required=True,
                        help='旧库 SQL 导出文件（Navicat 单行 INSERT 或 mysqldump 多行 INSERT；.sql.gz 自动解压）')
    common.add_argument('--out', default=os.path.join(os.path.dirname(os.path.abspath(__file__)), 'out'),
                        help='中间产物目录，默认 tools/migrate/out')

    parse_cmd = sub.add_parser('parse', parents=[common], help='旧 SQL → articles.jsonl + 清单 + 报表')
    parse_cmd.add_argument('--channels', default='frontend/home/data/channel.json',
                           help='新站栏目快照（取其 type 作为同号栏目集合）')
    parse_cmd.add_argument('--skip-side-tables', action='store_true', help='不解析视频／链接／互动三张表')
    parse_cmd.add_argument('--extra-ids', default='',
                           help='额外补抓的旧站稿件号（逗号分隔，用于导出库里没有的新稿）')
    parse_cmd.add_argument('--extra-from-slides', action='store_true',
                           help='自动补抓"轮播表里指向旧站、但新库还没有"的稿件')
    parse_cmd.add_argument('--db', default='backend/storage/hechi_zx.sqlite', help='--extra-from-slides 读哪个库')
    parse_cmd.add_argument('--site', default='', help='旧站目录（有 html/news-view-<id>.html 时优先用它，省抓网）')
    parse_cmd.add_argument('--base', default='http://www.gxhczx.gov.cn', help='旧站地址，本地静态页缺失时从这里抓')
    parse_cmd.add_argument('--expect-rows', type=int, default=0,
                           help='自检：rd_news 期望行数，对不上直接退出（0 表示不校验）')
    parse_cmd.add_argument('--expect-max-id', type=int, default=0,
                           help='自检：rd_news 期望最大稿件号，对不上直接退出（0 表示不校验）')
    parse_cmd.add_argument('--deleted-from', default='',
                           help='删除同步：拿一份更早的旧库导出做基准，产出 deleted_ids.txt（旧站已删稿件号）')
    parse_cmd.set_defaults(func=cmd_parse)

    render_cmd = sub.add_parser('render', help='articles.jsonl + 媒体清单 → articles.final.jsonl')
    render_cmd.add_argument('--in', dest='infile', default=os.path.join(os.path.dirname(os.path.abspath(__file__)), 'out/articles.jsonl'))
    render_cmd.add_argument('--out', default=os.path.join(os.path.dirname(os.path.abspath(__file__)), 'out'))
    render_cmd.add_argument('--manifest', default='',
                            help='媒体清单 csv（parse 产出；status=ok 的条目会改写为站内地址）')
    render_cmd.add_argument('--local-check', action='store_true',
                            help='按清单 target 检查本地是否已有文件，已有的按就绪处理（服务器拷图场景，不联网）')
    render_cmd.set_defaults(func=cmd_render)

    args = parser.parse_args()
    return args.func(args)


if __name__ == '__main__':
    sys.exit(main())

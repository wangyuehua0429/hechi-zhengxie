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
    articles, stats = [], {'rendered': 0, 'images': 0, 'attachments': 0, 'media_rewritten': 0, 'content_chars': 0}

    with open(args.infile, encoding='utf-8') as handle:
        for line in handle:
            line = line.strip()
            if not line:
                continue
            record = json.loads(line)
            body = clean_body(record['content_raw'], media_map)
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
                'author': record['author'],
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

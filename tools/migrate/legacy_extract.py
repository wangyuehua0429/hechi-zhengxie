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
  * 图片与视频从旧站现网抓回本地（fetch_media.py），抓成功的才改写正文地址。
"""

import argparse
import csv
import hashlib
import html
import json
import os
import re
import sys
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

# 公开年限切分点（关停日 2026-11-20 回溯 3 年）
PUBLIC_CUTOFF = datetime(2023, 11, 20, 0, 0, 0, tzinfo=CST)

# 站内域名（含裸域与 www），其余按外站处理
SITE_HOSTS = {'gxhczx.gov.cn', 'www.gxhczx.gov.cn'}
LEGACY_UPLOAD_PREFIX = 'uploads/legacy/'

# 旧库把领导职务写在 Edit 字段（旧站模板就是 `AND Edit LIKE '%主席%' ORDER BY Order`），
# Title1 在主站这批领导稿里是空的；只有这些字样才当职务，避免把"编辑：刁海音"当职务。
ROLE_MARKERS = ('主席', '秘书长', '主任', '党组')

MANIFEST_HEADER = ['url', 'target', 'status', 'bytes', 'sha256', 'error']


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
    """逐行读某张表的 INSERT，产出 dict（列数与表定义不一致时跳过并计数）。"""
    prefix = 'INSERT INTO `%s`' % table
    pattern = re.compile(r'^INSERT INTO `%s` VALUES \((.*)\);\s*$' % re.escape(table))
    skipped = 0
    with open(sql_path, encoding='utf-8', errors='replace') as handle:
        for line in handle:
            if not line.startswith(prefix):
                continue
            match = pattern.match(line.strip())
            if match is None:
                skipped += 1
                continue
            values = parse_tuple(match.group(1))
            if len(values) < len(fields):
                skipped += 1
                continue
            yield {key: unescape(value) for key, value in zip(fields, values)}
    if skipped:
        print('  警告：%s 有 %d 行没能解析，已跳过' % (table, skipped), file=sys.stderr)


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

    for row in iter_rows(args.sql, 'rd_news', NEWS_FIELDS):
        old_total += 1
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
    }
    with open(os.path.join(out_dir, 'stats.json'), 'w', encoding='utf-8') as handle:
        json.dump(stats, handle, ensure_ascii=False, indent=2)
        handle.write('\n')

    print('旧库 rd_news 共 %d 篇' % old_total)
    print('  本次迁移 %d 篇：published+public %d、published+archive %d、draft %d'
          % (stats['in_scope'], stats['published_public'], stats['published_archive'], stats['draft']))
    print('  未映射（主站口径）%d 篇，县区/口径外跳过 %d 篇' % (stats['unmapped_main'], stats['county_skipped']))
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

def read_manifest(path):
    """媒体清单 → {原始地址: 站内地址}（只收 status=ok 的）。"""
    mapping, failed = {}, 0
    if not path:
        return mapping, 0
    with open(path, encoding='utf-8', newline='') as handle:
        for row in csv.DictReader(handle):
            if (row.get('status') or '').strip().lower() != 'ok':
                if (row.get('status') or '').strip().lower() not in ('', 'pending'):
                    failed += 1
                continue
            rel = os.path.relpath(row['target'], 'backend/public').replace(os.sep, '/')
            if rel.startswith('..'):
                failed += 1
                continue
            url_path = '/' + rel
            mapping[row['url'].strip()] = url_path
    return mapping, failed


def cmd_render(args):
    media_map, failed = read_manifest(args.manifest)
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
        print('媒体清单：可用 %d 条，抓取失败 %d 条（失败的保留原地址）' % (len(media_map), failed))
    else:
        print('未提供媒体清单：正文图片保持旧站原地址')
    print('产物：%s' % final_path)
    return 0


def main():
    parser = argparse.ArgumentParser(description='旧库迁移第一段：解析与清洗（只读旧库导出）')
    sub = parser.add_subparsers(dest='command', required=True)

    common = argparse.ArgumentParser(add_help=False)
    common.add_argument('--sql', required=True, help='旧库 SQL 导出文件（Navicat 导出）')
    common.add_argument('--out', default=os.path.join(os.path.dirname(os.path.abspath(__file__)), 'out'),
                        help='中间产物目录，默认 tools/migrate/out')

    parse_cmd = sub.add_parser('parse', parents=[common], help='旧 SQL → articles.jsonl + 清单 + 报表')
    parse_cmd.add_argument('--channels', default='frontend/home/data/channel.json',
                           help='新站栏目快照（取其 type 作为同号栏目集合）')
    parse_cmd.add_argument('--skip-side-tables', action='store_true', help='不解析视频／链接／互动三张表')
    parse_cmd.set_defaults(func=cmd_parse)

    render_cmd = sub.add_parser('render', help='articles.jsonl + 媒体清单 → articles.final.jsonl')
    render_cmd.add_argument('--in', dest='infile', default=os.path.join(os.path.dirname(os.path.abspath(__file__)), 'out/articles.jsonl'))
    render_cmd.add_argument('--out', default=os.path.join(os.path.dirname(os.path.abspath(__file__)), 'out'))
    render_cmd.add_argument('--manifest', default='', help='媒体清单 csv（fetch_media.py 回填后）')
    render_cmd.set_defaults(func=cmd_render)

    args = parser.parse_args()
    return args.func(args)


if __name__ == '__main__':
    sys.exit(main())

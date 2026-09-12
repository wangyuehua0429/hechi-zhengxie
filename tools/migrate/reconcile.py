#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""迁移对账：旧库（Type, 对应 Region）与新库逐栏目比对稿件号，证明"一篇没少"。

  ~/py-tools/bin/python tools/migrate/reconcile.py \
      --sql "/path/to/gxhczx_db.sql" --db backend/storage/hechi_zx.sqlite

输出每个栏目的：旧库条数 / 新库条数 / 缺失（旧有新无）/ 多出（新有旧无）/
前台可见（published + public）/ 归档（published + archive）/ 草稿。
缺失不为 0 时退出码为 1，可挂进上线前的检查清单。
"""

import argparse
import collections
import json
import os
import re
import sqlite3
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from legacy_extract import DEFAULT_REGION_MAP, NEWS_FIELDS, iter_rows  # noqa: E402


def main():
    parser = argparse.ArgumentParser(description='迁移对账：逐栏目比对旧库与新库稿件号')
    parser.add_argument('--sql', required=True, help='旧库 SQL 导出文件')
    parser.add_argument('--db', default='backend/storage/hechi_zx.sqlite', help='新库（SQLite）文件')
    parser.add_argument('--channels', default='frontend/home/data/channel.json', help='栏目快照，取其 type 作为同号栏目集合')
    parser.add_argument('--site-id', type=int, default=1)
    parser.add_argument('--out', default='', help='同时写出 CSV 报告')
    args = parser.parse_args()

    channels = {}
    with open(args.channels, encoding='utf-8') as handle:
        for channel in json.load(handle).get('channels', []):
            channels[str(channel['type'])] = str(channel.get('inner', ''))

    old = collections.defaultdict(set)
    for row in iter_rows(args.sql, 'rd_news', NEWS_FIELDS):
        type_code = (row['Type'] or '').strip()
        region = (row['Region'] or '').strip()
        if type_code in channels and region == DEFAULT_REGION_MAP.get(type_code, '22'):
            old[type_code].add(int(row['ID']))

    con = sqlite3.connect(args.db)
    new = collections.defaultdict(set)
    visibility = collections.defaultdict(collections.Counter)
    for article_id, channel_type, status, scope in con.execute(
        'SELECT article_id, channel_type, status, public_scope FROM cms_article WHERE site_id = ?',
        (args.site_id,),
    ):
        new[channel_type].add(int(article_id))
        if status == 'published' and scope == 'public':
            visibility[channel_type]['public'] += 1
        elif status == 'published':
            visibility[channel_type]['archive'] += 1
        elif status == 'draft':
            visibility[channel_type]['draft'] += 1
        else:
            visibility[channel_type]['other'] += 1

    rows = []
    missing_total = extra_total = 0
    for type_code in sorted(old, key=lambda t: -len(old[t])):
        missing = sorted(old[type_code] - new[type_code])
        extra = sorted(new[type_code] - old[type_code])
        missing_total += len(missing)
        extra_total += len(extra)
        rows.append({
            'old_type': type_code,
            'channel_inner': channels[type_code],
            'old_count': len(old[type_code]),
            'new_count': len(new[type_code]),
            'missing': len(missing),
            'extra': len(extra),
            'public': visibility[type_code]['public'],
            'archive': visibility[type_code]['archive'],
            'draft': visibility[type_code]['draft'],
            'missing_ids': ','.join(str(i) for i in missing[:20]),
            'extra_ids': ','.join(str(i) for i in extra[:20]),
        })

    header = ['旧栏目号', '栏目', '旧库', '新库', '缺失', '多出', '前台可见', '归档', '草稿']
    print('%-8s %-14s %6s %6s %6s %6s %8s %6s %6s' % tuple(header))
    for row in rows:
        print('%-8s %-14s %6d %6d %6d %6d %8d %6d %6d' % (
            row['old_type'], row['channel_inner'][:12], row['old_count'], row['new_count'],
            row['missing'], row['extra'], row['public'], row['archive'], row['draft'],
        ))
    print('-' * 80)
    print('合计：旧库范围内 %d 篇，缺失 %d 篇，多出 %d 篇（多出多为旧站静态页专属内容或库内测试稿）'
          % (sum(len(v) for v in old.values()), missing_total, extra_total))

    if args.out:
        import csv
        with open(args.out, 'w', encoding='utf-8', newline='') as handle:
            writer = csv.DictWriter(handle, fieldnames=list(rows[0].keys()))
            writer.writeheader()
            writer.writerows(rows)
        print('CSV 已写出：' + args.out)

    return 1 if missing_total else 0


if __name__ == '__main__':
    sys.exit(main())

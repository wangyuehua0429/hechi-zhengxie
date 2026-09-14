#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""由未映射清单生成《栏目与 Type 映射总表》，交甲方确认栏目归属。

  ~/py-tools/bin/python tools/migrate/type_mapping_report.py \
      --sql "<gxhczx_db_2026-09-05.sql.gz>" \
      --unmapped tools/migrate/out/unmapped.csv \
      --channels frontend/home/data/channel.json \
      --out "docs/栏目与 Type 映射总表.md"

数字全部来自实读：`--unmapped` 是 parse 产出的未映射稿件清单（一篇一行），
`--sql` 用来取旧栏目名（rd_menu）与各 Type 的实际稿量；脚本只读不写库。
建议归属是给甲方勾选用的初稿，规则集中在 suggest()，改规则要同步改这份文档。
"""

import argparse
import collections
import csv
import json
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from legacy_extract import DEFAULT_REGION_MAP, NEWS_FIELDS, iter_rows  # noqa: E402

# 两会专题：旧站按「届次 × 栏目」建了一整套子栏目，新站没有同号栏目
SESSION_PARENTS = {
    '5600': '五届一次会议',
    '5800': '五届二次会议',
    '6000': '五届三次会议',
    '7000': '五届四次会议',
    '7200': '五届五次会议',
    '7300': '五届六次会议',
}
# 子栏目号前缀 → 所属届次
SESSION_PREFIX = {'56': '5600', '58': '5800', '60': '6000', '70': '7000', '72': '7200', '73': '7300'}


def suggest(type_code):
    """建议归属（初稿）：返回 (归属类别, 父栏目名, 建议做法)。"""
    parent_name = SESSION_PARENTS.get(SESSION_PREFIX.get(type_code[:2], ''), '')
    if parent_name:
        return ('两会专题', parent_name, '按届次建专题页，稿子并入对应专题；不新增常设栏目')
    if type_code in ('7101', '5701', '320'):
        return ('专栏', '', '新站建同名专栏（或并入“专题”栏目下的专题页），随下一批补迁')
    if type_code in ('5901', '5903'):
        return ('专栏', '协商在河池', '并入《协商在河池》专题（旧站父栏目为“协商在河池”）')
    if type_code in ('205', '210'):
        return ('政协概况', '', '并入“政协概况”栏目（常委／委员名单类内容）')
    if type_code == '207':
        return ('文件汇编', '', '并入“规章制度”，或新站新建“文件汇编”栏目')
    if type_code.startswith('5'):
        return ('都安子站', '', '属都安政协子站内容，本期不迁（新站 site_id 预留）')
    return ('其他', '', '待甲方确认归属')


def main():
    parser = argparse.ArgumentParser(description='生成《栏目与 Type 映射总表》')
    parser.add_argument('--sql', required=True, help='旧库 SQL 导出（.sql / .sql.gz）')
    parser.add_argument('--unmapped', required=True, help='parse 产出的 unmapped.csv')
    parser.add_argument('--channels', default='frontend/home/data/channel.json', help='新站栏目快照')
    parser.add_argument('--out', required=True, help='输出 Markdown 路径')
    parser.add_argument('--date', default='', help='文档标注日期（默认留空由人工填）')
    args = parser.parse_args()

    menu = {}
    for row in iter_rows(args.sql, 'rd_menu', ['ID', 'Name', 'Type', 'Level', 'Order', 'A', 'B']):
        menu[(row['ID'] or '').strip()] = (row['Name'] or '').strip()

    old_types = collections.Counter()
    old_main = collections.Counter()
    for row in iter_rows(args.sql, 'rd_news', NEWS_FIELDS):
        type_code = (row['Type'] or '').strip()
        region = (row['Region'] or '').strip()
        old_types[type_code] += 1
        if region == DEFAULT_REGION_MAP.get(type_code, '22'):
            old_main[type_code] += 1

    new_channels = {}
    with open(args.channels, encoding='utf-8') as handle:
        for channel in json.load(handle).get('channels', []):
            new_channels[str(channel['type'])] = str(channel.get('inner') or channel.get('name') or '')

    groups = collections.OrderedDict()
    with open(args.unmapped, encoding='utf-8', newline='') as handle:
        for row in csv.DictReader(handle):
            key = ((row['old_type'] or '').strip(), (row['old_region'] or '').strip())
            groups[key] = groups.get(key, 0) + 1

    lines = []
    lines.append('# 栏目与 Type 映射总表（待甲方确认）')
    lines.append('')
    lines.append('> 用途：新站没有同号栏目、本次迁移进不去的旧稿，逐条列出建议归属，请甲方勾选确认后再补迁。')
    lines.append('> 数据来源：旧库导出 `rd_news`（未映射稿件清单由 `tools/migrate/legacy_extract.py parse` 产出）。')
    if args.date:
        lines.append('> 编制日期：' + args.date)
    lines.append('')
    lines.append('## 一、汇总')
    lines.append('')
    totals = collections.Counter()
    for (type_code, region), count in groups.items():
        category = suggest(type_code)[0]
        totals[category] += count
    lines.append('| 建议归属 | 篇数 |')
    lines.append('| --- | --- |')
    for category, count in totals.most_common():
        lines.append('| %s | %d |' % (category, count))
    lines.append('| **合计** | **%d** |' % sum(totals.values()))
    lines.append('')
    lines.append('## 二、明细')
    lines.append('')
    lines.append('| 旧 Type | 旧栏目 | 父栏目 | Region | 篇数 | 新站现状 | 建议归属 | 建议做法 |')
    lines.append('| --- | --- | --- | --- | --- | --- | --- | --- |')
    ordered = sorted(groups.items(), key=lambda item: (-item[1], item[0][0]))
    for (type_code, region), count in ordered:
        menu_name = menu.get(type_code, '（旧库无菜单记录）')
        category, parent_name, action = suggest(type_code)
        status = '已有同号栏目' if type_code in new_channels else '无同号栏目'
        lines.append('| %s | %s | %s | %s | %d | %s | %s | %s |' % (
            type_code, menu_name, parent_name or '—', region or '—', count, status, category, action))
    lines.append('')
    lines.append('## 三、新站有栏目、旧库主站口径没有稿件的栏目')
    lines.append('')
    empty = [code for code in sorted(new_channels)
             if code.isdigit() and old_main.get(code, 0) == 0]
    if empty:
        lines.append('| 栏目号 | 栏目 | 旧库主站口径稿件数 | 旧库全部 Region 稿件数 |')
        lines.append('| --- | --- | --- | --- |')
        for code in empty:
            lines.append('| %s | %s | 0 | %d |' % (code, new_channels[code], old_types.get(code, 0)))
        lines.append('')
        lines.append('这些栏目迁移后仍是空栏目，需要编辑补内容，或由甲方确认是否下线。')
    else:
        lines.append('（无）')
    lines.append('')
    lines.append('## 四、说明')
    lines.append('')
    lines.append('- 篇数按旧库导出的稿件行数统计，含未审稿件；`Region` 22 为主站，其他为县区或子站口径。')
    lines.append('- “两会专题”指旧站为每次全会单建的整套子栏目（今日焦点／新闻动态／建言献策等），新站按届次建专题页承接即可，不必新增常设栏目。')
    lines.append('- 县区 11 个 Region 的稿件（含都安政协子站）按既定口径本期不迁，新站 `site_id` 预留。')
    lines.append('- 甲方确认后，把确认结果写回本表“建议做法”列，再按 `docs/旧库迁移说明.md` 的同一套命令补迁。')
    lines.append('')

    with open(args.out, 'w', encoding='utf-8') as handle:
        handle.write('\n'.join(lines))
    print('已写出 %s：%d 个（Type, Region）组合、%d 篇' % (args.out, len(groups), sum(groups.values())))
    return 0


if __name__ == '__main__':
    sys.exit(main())

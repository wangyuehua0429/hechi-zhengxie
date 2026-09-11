#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""从旧站数据库导出物生成前端二级页/详情页原型用的样例数据。

用途：仅服务前端静态原型（channel.html / detail.html），不是正式迁移工具。
正式迁移脚本后续放在 tools/migrate/。

输入：--sql 旧库 Navicat 导出文件（rd_news 表）
输出：
  frontend/home/data/channel.json   栏目列表页数据（含栏目信息、列表、分页、侧栏）
  frontend/home/data/article.json   详情页数据（含正文、上下篇、相关阅读）
  frontend/home/data/_images.json   缩略图下载清单（供 curl 批量拉取）

用法：
  ~/py-tools/bin/python tools/prototype/extract_sample_data.py \
      --sql "/path/to/gxhczx_db.sql" --root /path/to/repo
重复执行是幂等的：本地已存在缩略图时，img 字段会写成站内相对路径。
"""

import argparse
import html
import json
import os
import re
import sys
from datetime import datetime, timezone, timedelta

CST = timezone(timedelta(hours=8))

# 主站（河池市）内容：旧库 rd_news.Region = 22
MAIN_REGION = "22"

# 本次原型覆盖的栏目：旧库 Type -> 新站栏目
CHANNELS = [
    {
        "type": "904",
        "slug": "zhengxie-dongtai",
        "name": "政协动态",
        "inner": "市政协动态",
        "intro": "聚焦市政协重要会议、调研视察与履职活动，及时反映全市政协工作进展。",
        "siblings": [
            {"type": "902", "name": "全国政协动态"},
            {"type": "903", "name": "区（广西）政协动态"},
            {"type": "904", "name": "市政协动态"},
            {"type": "905", "name": "政协新闻"},
            {"type": "906", "name": "县区政协工作动态"},
        ],
    },
    {
        "type": "306",
        "slug": "shizheng-yaowen",
        "name": "时政要闻",
        "inner": "时政要闻",
        "intro": "汇集全市时政要闻与重大部署，反映河池经济社会发展动态。",
        "siblings": [],
    },
    {
        "type": "314",
        "slug": "tupian-xinwen",
        "name": "图片新闻",
        "inner": "图片新闻",
        "intro": "以图片记录政协履职与全市发展现场，直观呈现重要时刻。",
        "siblings": [],
    },
]

# 详情页样例：每个栏目取若干篇带正文的文章；列表页每个栏目取若干条
ARTICLE_PER_CHANNEL = 2
LIST_LIMIT = 24

FIELDS = ["ID", "Title", "Title1", "Title2", "Pic", "Word", "Audit", "Region",
          "Hot", "Top", "Type", "Num", "Order", "Time", "From", "Author", "Edit"]


def parse_tuple(body):
    """解析 SQL VALUES 里的单行元组，返回字段列表（保留原始字符串内容）。"""
    fields, cur, in_quote, i = [], [], False, 0
    while i < len(body):
        ch = body[i]
        if in_quote:
            if ch == "\\":
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
        if ch == ",":
            fields.append("".join(cur).strip())
            cur = []
            i += 1
            continue
        cur.append(ch)
        i += 1
    fields.append("".join(cur).strip())
    return fields


def unescape(raw):
    """SQL 字符串字面量 -> Python 字符串。"""
    if raw is None:
        return ""
    if raw.upper() == "NULL":
        return ""
    if raw.startswith("'") and raw.endswith("'") and len(raw) >= 2:
        raw = raw[1:-1]
    raw = raw.replace("''", "'")
    raw = raw.replace("\\'", "'").replace('\\"', '"')
    raw = raw.replace("\\r", "").replace("\\n", "\n").replace("\\t", " ")
    return raw


def load_news(sql_path):
    pattern = re.compile(r"^INSERT INTO `rd_news` VALUES \((.*)\);\s*$")
    rows = []
    with open(sql_path, encoding="utf-8", errors="replace") as handle:
        for line in handle:
            if not line.startswith("INSERT INTO `rd_news`"):
                continue
            match = pattern.match(line.strip())
            if not match:
                continue
            fields = parse_tuple(match.group(1))
            if len(fields) < len(FIELDS):
                continue
            row = {key: unescape(value) for key, value in zip(FIELDS, fields)}
            try:
                row["ID"] = int(row["ID"])
                row["Time"] = int(row["Time"])
            except ValueError:
                continue
            rows.append(row)
    return rows


TAG_STYLE = re.compile(r"\s(?:style|class|align|color|face|size|width|height)="
                       r"(?:\"[^\"]*\"|'[^']*'|[^\s>]+)", re.I)
EMPTY_P = re.compile(r"<p[^>]*>\s*(?:&nbsp;|<br\s*/?>|\s)*\s*</p>", re.I)


def clean_body(raw):
    """旧库正文清洗：去内联样式与冗余空段落，保留段落/图片/表格结构。

    这是正式迁移清洗器的原型，规则会在 tools/migrate 中继续完善。
    """
    text = raw or ""
    text = re.sub(r"</?(?:span|font|o:p|st1:[^>]*)[^>]*>", "", text, flags=re.I)
    text = TAG_STYLE.sub("", text)
    text = re.sub(r"<p([^>]*)>", lambda m: "<p>", text)
    for _ in range(3):
        text = EMPTY_P.sub("", text)
    text = re.sub(r"(?:\s*<br\s*/?>\s*){3,}", "\n", text)
    text = text.replace("&nbsp;", " ")
    text = re.sub(r"[ \t]{2,}", " ", text)
    return text.strip()


def plain_summary(body, limit=110):
    text = re.sub(r"<[^>]+>", "", body or "")
    text = html.unescape(text)
    text = re.sub(r"\s+", " ", text).strip()
    return text[:limit]


# 旧库 From 字段污染：约 7.8% 记录把日期/版面拼在来源后，如「河池日报 2026/3/2 1版」
SOURCE_TRAIL = re.compile(r"\s*\d{4}[/-]\d{1,2}(?:[/-]\d{1,2})?.*$")


def clean_source(raw):
    return SOURCE_TRAIL.sub("", (raw or "").strip()).strip()


def fmt_date(ts):
    return datetime.fromtimestamp(ts, CST).strftime("%Y-%m-%d")


def fmt_datetime(ts):
    return datetime.fromtimestamp(ts, CST).strftime("%Y-%m-%d %H:%M")


def local_image(root, news_id):
    path = os.path.join(root, "frontend/home/images/channel/%s.jpg" % news_id)
    return os.path.exists(path)


IMG_SRC = re.compile(r'<img[^>]*\ssrc=(["\'])([^"\']+)\1[^>]*>', re.I)


def first_body_image(body):
    match = IMG_SRC.search(body or "")
    return match.group(2) if match else ""


def rewrite_body_images(root, body, news_id, downloads):
    """正文图片本地化：<img src> 换成站内相对路径，并登记下载任务。"""
    counter = [0]

    def replace(match):
        src = match.group(2)
        if not src or src.startswith("data:"):
            return match.group(0)
        if src.startswith("images/"):
            return match.group(0)
        counter[0] += 1
        name = "art%s-%d.jpg" % (news_id, counter[0])
        target = "frontend/home/images/channel/%s" % name
        downloads.append({"id": "%s-%d" % (news_id, counter[0]), "url": src, "target": target})
        return match.group(0).replace(src, "images/channel/%s" % name, 1)

    return IMG_SRC.sub(replace, body)


def build(args):
    rows = load_news(args.sql)
    if not rows:
        sys.exit("未从 %s 解析到 rd_news 记录" % args.sql)
    published = [r for r in rows if r["Audit"] == "1"]
    by_type = {}
    for row in published:
        by_type.setdefault(row["Type"], []).append(row)
    for items in by_type.values():
        items.sort(key=lambda r: r["Time"], reverse=True)

    root = args.root
    channels, articles, downloads = [], [], []

    for conf in CHANNELS:
        items = [r for r in by_type.get(conf["type"], []) if r["Region"] == MAIN_REGION]
        if not items:
            print("跳过栏目 %s（无主站内容）" % conf["name"])
            continue
        picked = items[:LIST_LIMIT]
        entries = []
        for row in picked:
            img = ""
            pic = row["Pic"] if (row["Pic"] and "." in row["Pic"]) else first_body_image(row["Word"])
            if pic:
                name = "%s.jpg" % row["ID"]
                if local_image(root, row["ID"]):
                    img = "images/channel/%s" % name
                else:
                    downloads.append({"id": row["ID"], "url": pic,
                                      "target": "frontend/home/images/channel/%s" % name})
                    img = "images/channel/%s" % name
            entries.append({
                "id": row["ID"],
                "title": row["Title"],
                "url": "detail.html?id=%s" % row["ID"],
                "date": fmt_date(row["Time"]),
                "source": clean_source(row["From"]),
                "views": int(row["Num"] or 0),
                "img": img,
                "hasBody": bool(clean_body(row["Word"])),
            })
        channels.append({
            "type": conf["type"],
            "slug": conf["slug"],
            "name": conf["name"],
            "inner": conf["inner"],
            "intro": conf["intro"],
            "siblings": conf["siblings"],
            "total": len(items),
            "list": entries,
        })

        for row in picked:
            body = clean_body(row["Word"])
            if not body or len(body) < 120:
                continue
            if len([a for a in articles
                    if a["channelType"] == conf["type"]]) >= ARTICLE_PER_CHANNEL:
                break
            body = rewrite_body_images(root, body, row["ID"], downloads)
            articles.append({
                "id": row["ID"],
                "channelType": conf["type"],
                "channelName": conf["name"],
                "title": row["Title"],
                "subtitle": row["Title1"],
                "date": fmt_datetime(row["Time"]),
                "dateText": fmt_date(row["Time"]),
                "source": clean_source(row["From"]),
                "author": row["Author"],
                "editor": row["Edit"],
                "views": row["Num"],
                "summary": plain_summary(body),
                "content": body,
            })

    write(os.path.join(root, "frontend/home/data/channel.json"),
          {"channels": channels},
          "栏目列表页样例数据（来源：旧库 rd_news，Region=22 主站内容）")
    write(os.path.join(root, "frontend/home/data/article.json"),
          {"articles": articles},
          "详情页样例数据（旧库 rd_news，正文已做样式清洗原型处理）")
    write(os.path.join(root, "frontend/home/data/_images.json"),
          downloads,
          "缩略图下载清单：curl 拉取到 frontend/home/images/channel/")

    print("栏目 %d 个、列表条目 %d 条、详情样例 %d 篇、待下载缩略图 %d 张"
          % (len(channels), sum(len(c["list"]) for c in channels),
             len(articles), len(downloads)))


def write(path, payload, note):
    os.makedirs(os.path.dirname(path), exist_ok=True)
    with open(path, "w", encoding="utf-8") as handle:
        json.dump(payload, handle, ensure_ascii=False, indent=2)
        handle.write("\n")
    print("已写出 %s（%s）" % (path, note))


def main():
    parser = argparse.ArgumentParser(description="生成前端二级页/详情页原型样例数据")
    parser.add_argument("--sql", required=True, help="旧库 SQL 导出文件路径")
    parser.add_argument("--root", default=os.getcwd(), help="仓库根目录")
    build(parser.parse_args())


if __name__ == "__main__":
    main()

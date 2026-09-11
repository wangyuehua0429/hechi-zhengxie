#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""从旧站数据库导出物与旧站静态页生成前端二级页/详情页原型用的样例数据。

用途：仅服务前端静态原型（channel.html / detail.html），不是正式迁移工具。
正式迁移脚本后续放在 tools/migrate/。

输入：
  --sql   旧库 Navicat 导出文件（rd_news 表）
  --site  旧站静态目录（内含 html/news-view-<id>.html），用于「政协领导」等栏目的简介正文
输出：
  frontend/home/data/channel.json   栏目列表页数据（含栏目信息、列表、分页、侧栏）
  frontend/home/data/article.json   详情页数据（含正文、上下篇、相关阅读）
  frontend/home/data/_images.json   缩略图下载清单（供 curl 批量拉取）

用法：
  ~/py-tools/bin/python tools/prototype/extract_sample_data.py \
      --sql "/path/to/gxhczx_db.sql" \
      --site "/path/to/zhengxie2026/gxhczx.gov.cn" \
      --root /path/to/repo
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

# 列表页每栏目取多少条、每栏目生成多少篇带正文的详情样例
LIST_LIMIT = 24
ARTICLE_PER_CHANNEL = 2

# 一级栏目（对应旧站主导航 17 项）与各自的子栏目。
#   id       栏目在旧站导航里的入口参数（news_list.php?id= / news_list_about.php?id= / qy_list.php）
#   tabs     该栏目下的子栏目：type 为旧库 rd_news.Type，region 为 rd_news.Region（默认主站 22）
#   layout   list（稿件列表，默认）/ about（一页式）/ county（县区入口 + 动态列表）
#   children 一页式栏目的固定子导航（指向本地详情页）
COLUMNS = [
    {
        "id": "202",
        "slug": "zhengxie-gaikuang",
        "name": "政协领导",
        "intro": "中国人民政治协商会议河池市委员会主席、副主席、秘书长名单及简介。",
        "layout": "leaders",
        "tabs": [{"type": "202", "name": "政协领导"}],
        # 政协领导的稿件是领导简介，按职务分组与排序（主席 → 副主席 → 秘书长），
        # 名单取自 data/home.json 的 leaders，不按发布时间倒序
        "roster": True,
        "children": [
            {"id": "202", "name": "政协领导"},
            {"id": "151", "name": "政协简介"},
            {"id": "39588", "name": "五届政协领导简介"},
            {"id": "154", "name": "政协章程"},
            {"id": "153", "name": "机构设置"},
            {"id": "39588", "name": "政协常委"},
            {"id": "2522", "name": "政协委员"},
        ],
    },
    {
        "id": "904",
        "slug": "zhengxie-dongtai",
        "name": "政协动态",
        "intro": "聚焦市政协重要会议、调研视察与履职活动，及时反映全市政协工作进展。",
        "tabs": [
            {"type": "902", "name": "全国政协动态", "region": "55"},
            {"type": "903", "name": "区（广西）政协动态", "region": "33"},
            {"type": "904", "name": "市政协动态"},
            {"type": "905", "name": "政协新闻"},
            {"type": "906", "name": "县区政协工作动态"},
        ],
    },
    {
        "id": "401",
        "slug": "zhengxie-huiyi",
        "name": "政协会议",
        "intro": "发布市政协全体会议、常委会议、主席会议及其它会议的议程与报道。",
        "tabs": [
            {"type": "401", "name": "全体会议"},
            {"type": "402", "name": "常委会议"},
            {"type": "403", "name": "主席会议"},
            {"type": "404", "name": "其它会议"},
        ],
    },
    {
        "id": "307",
        "slug": "guizhang-zhidu",
        "name": "规章制度",
        "intro": "汇集政协工作相关规章、制度与规范性文件。",
        "tabs": [{"type": "307", "name": "规章制度"}],
    },
    {
        "id": "501",
        "slug": "zhengxie-tian",
        "name": "政协提案",
        "intro": "反映提案工作动态，提供提案查询、答复与督办情况。",
        "tabs": [
            {"type": "501", "name": "提案工作"},
            {"type": "502", "name": "提案查询"},
            {"type": "503", "name": "提案答复"},
            {"type": "504", "name": "提案督办"},
        ],
    },
    {
        "id": "310",
        "slug": "sheqing-minyi",
        "name": "社情民意",
        "intro": "汇集委员反映的社情民意信息与意见建议。",
        "tabs": [{"type": "310", "name": "社情民意"}],
    },
    {
        "id": "308",
        "slug": "zhuanweihui-gongzuo",
        "name": "专委会工作",
        "intro": "展示各专门委员会调研、视察与协商议政工作。",
        "tabs": [{"type": "308", "name": "专委会工作"}],
    },
    {
        "id": "601",
        "slug": "dangpai-tuanti",
        "name": "党派团体",
        "intro": "汇聚各民主党派河池市委会、工商联等党派团体的履职动态。",
        "tabs": [
            {"type": "601", "name": "民盟河池市委员会"},
            {"type": "602", "name": "民革河池市委员会"},
            {"type": "603", "name": "民进河池市总支部"},
            {"type": "604", "name": "河池工商业联合会"},
            {"type": "605", "name": "农工党河池市委员会"},
            {"type": "606", "name": "民建河池市委员会"},
            {"type": "607", "name": "九三学社河池委员会"},
        ],
    },
    {
        "id": "312",
        "slug": "shicha-diaoyan",
        "name": "视察调研",
        "intro": "记录市政协组织的视察、调研与专题协商活动。",
        "tabs": [{"type": "312", "name": "视察调研"}],
    },
    {
        "id": "311",
        "slug": "weiyuan-fengcai",
        "name": "委员风采",
        "intro": "展示政协委员的履职故事、发言与建言成果。",
        "tabs": [{"type": "311", "name": "委员风采"}],
    },
    {
        "id": "313",
        "slug": "youhao-wanglai",
        "name": "友好往来",
        "intro": "记录市政协与各地政协及社会各界的交流往来。",
        "tabs": [{"type": "313", "name": "友好往来"}],
    },
    {
        "id": "qy",
        "slug": "xianqu-zhengxie",
        "name": "县（区）政协",
        "intro": "汇集各县（区）政协工作动态，并提供县级政协站点入口。",
        "layout": "county",
        # 县（区）政协栏目与「政协动态 > 县区政协工作动态」同源，用 link 区分页面参数，
        # 避免与 906 撞号；列表内容不重复取详情样例。
        "tabs": [{"type": "906", "name": "县（区）政协", "link": "qy", "samples": False}],
        "counties": [
            {"name": "金城江", "url": ""},
            {"name": "宜州", "url": ""},
            {"name": "罗城", "url": "http://lc.gxhczx.gov.cn/"},
            {"name": "环江", "url": "http://hj.gxhczx.gov.cn/"},
            {"name": "南丹", "url": "http://nd.gxhczx.gov.cn/"},
            {"name": "天峨", "url": "http://te.gxhczx.gov.cn/"},
            {"name": "东兰", "url": ""},
            {"name": "巴马", "url": ""},
            {"name": "凤山", "url": ""},
            {"name": "都安", "url": "http://da.gxhczx.gov.cn/"},
            {"name": "大化", "url": "http://dh.gxhczx.gov.cn/"},
        ],
    },
    {
        "id": "317",
        "slug": "lilun-yanjiu",
        "name": "理论研究",
        "intro": "刊载政协理论研究文章与学习成果。",
        "tabs": [{"type": "317", "name": "理论研究"}],
    },
    {
        "id": "315",
        "slug": "hechi-wenshi",
        "name": "河池文史",
        "intro": "整理发布河池文史资料与地方史料。",
        "tabs": [{"type": "315", "name": "河池文史"}],
    },
    {
        "id": "803",
        "slug": "zhengxie-yiyuan",
        "name": "政协艺苑",
        "intro": "展示政协委员与机关干部的摄影、书画与文学创作。",
        "tabs": [
            {"type": "801", "name": "政协摄影"},
            {"type": "802", "name": "政协书画"},
            {"type": "803", "name": "文学创作"},
        ],
    },
    {
        "id": "319",
        "slug": "tashan-zhishi",
        "name": "他山之石",
        "intro": "转载各地政协工作的经验与做法。",
        "tabs": [{"type": "319", "name": "他山之石"}],
    },
    {
        "id": "314",
        "slug": "tupian-xinwen",
        "name": "图片新闻",
        "intro": "以图片记录政协履职与全市发展现场，直观呈现重要时刻。",
        "layout": "gallery",
        "tabs": [{"type": "314", "name": "图片新闻"}],
    },
    # 以下栏目不在主导航，但首页模块的「更多」与卡片入口需要能打开，
    # 一并登记为独立栏目页（source=home 表示列表取自首页模块数据）。
    {
        "id": "306",
        "slug": "shizheng-yaowen",
        "name": "时政要闻",
        "intro": "转载上级主流媒体的时政要闻，供委员与机关干部学习参考。",
        "tabs": [{"type": "306", "name": "时政要闻"}],
    },
    {
        "id": "302",
        "slug": "gonggao-tongzhi",
        "name": "公告通知",
        "intro": "发布市政协及机关需要社会周知的公告、通知与名单。",
        # 公告通知量小（40 条），列表全取；旧库仅 40029 一篇带附件，补入详情演示下载区
        "list_limit": 40,
        "extra_samples": [40029],
        "tabs": [{"type": "302", "name": "公告通知"}],
    },
    {
        "id": "1301",
        "slug": "wangshang-shuyuan",
        "name": "网上书院",
        "intro": "发布“书香政协”委员读书实践活动推荐书目与学习资料。",
        "tabs": [{"type": "1301", "name": "网上书院"}],
    },
    {
        "id": "400",
        "slug": "saochu-heie",
        "name": "扫黑除恶",
        "intro": "发布扫黑除恶专项斗争相关部署、进展与应知应会内容。",
        "tabs": [{"type": "400", "name": "扫黑除恶"}],
    },
    {
        "id": "316",
        "slug": "zhengxie-shipin",
        "name": "政协视频",
        "intro": "以视频形式呈现政协重要活动与协商议政现场。",
        "layout": "video",
        "source": "home",
        "module": "videos",
        "tabs": [],
    },
    {
        "id": "topic",
        "slug": "zhuanti",
        "name": "专题",
        "intro": "聚合政协重要会议与重大主题的宣传专题。",
        "layout": "topic",
        "source": "home",
        "module": "topic",
        "tabs": [],
    },
    {
        "id": "901",
        "slug": "hechi-fengguang",
        "name": "河池风光",
        "intro": "展示河池自然山水与人文景观的图片集。",
        "layout": "gallery",
        "tabs": [{"type": "901", "name": "河池风光"}],
    },
    {
        "id": "interactive",
        "slug": "weiyuan-zhitongche",
        "name": "委员直通车",
        "intro": "委员在线建言与来信回复的互动栏目。",
        "layout": "interactive",
        "tabs": [],
        "note": "本栏目用于委员在线建言与来信办理结果公开。旧站无对应页面，"
                "旧库 rd_hudong 现存记录均为测试数据（“测试互动”“go home”等），"
                "故此处只出栏目模板与入口说明，正式内容与提交链路由后端接入时提供。",
    },
]

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


def body_images(body):
    """正文里出现过的图片地址（按出现顺序去重）。"""
    seen, out = set(), []
    for match in IMG_SRC.finditer(body or ""):
        src = match.group(2)
        if src and src not in seen:
            seen.add(src)
            out.append(src)
    return out


# 正文内的附件下载链接（旧站用 kindeditor 的 ke-insertfile 类挂 doc/pdf/xls 等原件）
ATTACH_LINK = re.compile(r'<a[^>]*\shref=["\']([^"\']+)["\'][^>]*>(.*?)</a>', re.I | re.S)
ATTACH_EXT = re.compile(r"\.(docx?|xlsx?|pptx?|pdf|zip|rar|7z)(?:[?#]|$)", re.I)


def extract_attachments(body):
    """从正文里抽出附件下载项；无附件返回空列表。"""
    out = []
    for match in ATTACH_LINK.finditer(body or ""):
        url = match.group(1)
        ext = ATTACH_EXT.search(url)
        if not ext:
            continue
        name = html.unescape(re.sub(r"<[^>]+>", "", match.group(2))).strip()
        if not name:
            name = os.path.basename(url.split("?")[0])
        out.append({"name": name, "url": url, "ext": ext.group(1).lower()})
    return out


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
        if not os.path.exists(os.path.join(root, target)):
            downloads.append({"id": "%s-%d" % (news_id, counter[0]), "url": src, "target": target})
        return match.group(0).replace(src, "images/channel/%s" % name, 1)

    return IMG_SRC.sub(replace, body)


STATIC_TIME_LABELS = ["阅读", "来源", "作者", "编辑"]


def static_field(text, label):
    """从旧站静态页的 Ntime 行里取「标签：值」。"""
    others = [lab for lab in STATIC_TIME_LABELS if lab != label] + ["时间"]
    pattern = r"%s：\s*(.*?)(?=(?:%s)：|$)" % (label, "|".join(others))
    match = re.search(pattern, text, re.S)
    return match.group(1).strip() if match else ""


def parse_static_article(site_root, news_id, channel_type, channel_name,
                         root=None, downloads=None):
    """解析旧站静态文章页 html/news-view-<id>.html，用于一页式与领导型栏目的正文。"""
    if not site_root:
        return None
    path = os.path.join(site_root, "html", "news-view-%s.html" % news_id)
    if not os.path.exists(path):
        print("未找到旧站静态页 %s" % path)
        return None
    with open(path, encoding="utf-8", errors="replace") as handle:
        raw = handle.read()
    title_match = re.search(r'<div class="Ntitle">(.*?)</div>', raw, re.S)
    time_match = re.search(r'<div class="Ntime">(.*?)</div>', raw, re.S)
    start = raw.find('<div class="Nword">')
    if start < 0:
        print("旧站静态页 %s 未找到正文容器" % path)
        return None
    body = raw[start + len('<div class="Nword">'):]
    cut = body.find("<!--")
    if cut >= 0:
        body = body[:cut]
    body = re.sub(r"(?:\s*</div>\s*)+$", "", body)
    body = clean_body(body)
    if root and downloads is not None:
        body = rewrite_body_images(root, body, news_id, downloads)
    time_text = html.unescape(re.sub(r"<[^>]+>", "", time_match.group(1))) if time_match else ""
    time_text = time_text.replace("\xa0", " ")
    published = static_field(time_text, "时间")
    source = static_field(time_text, "来源")
    author = static_field(time_text, "作者")
    editor = static_field(time_text, "编辑")
    title = html.unescape(re.sub(r"<[^>]+>", "", title_match.group(1))).strip() if title_match else ""
    if not title and not body:
        return None
    return {
        "id": news_id,
        "channelType": channel_type,
        "channelName": channel_name,
        "title": title,
        "subtitle": "",
        "date": published,
        "dateText": published[:10],
        "source": source,
        "author": author,
        "editor": editor,
        "views": "",
        "summary": plain_summary(body),
        "content": body,
        "attachments": extract_attachments(body),
        "images": body_images(body),
        "fromStaticPage": True,
    }


def channel_entries(rows, root, downloads, limit=LIST_LIMIT):
    """把一个子栏目的稿件转成列表页条目。"""
    entries = []
    for row in rows[:limit]:
        # 列表缩略图只取旧库 Pic 字段（与旧站一致），不改从正文抓首图，避免无谓的图片下载
        pic = row["Pic"] if (row["Pic"] and "." in row["Pic"]) else ""
        img = ""
        if pic:
            name = "%s.jpg" % row["ID"]
            if not local_image(root, row["ID"]):
                downloads.append({"id": str(row["ID"]), "url": pic,
                                  "target": "frontend/home/images/channel/%s" % name})
            img = "images/channel/%s" % name
        entries.append({
            "id": row["ID"],
            "title": row["Title"],
            "url": "detail.html?id=%s" % row["ID"],
            "date": fmt_date(row["Time"]),
            "datetime": fmt_datetime(row["Time"]) + ":" +
                        datetime.fromtimestamp(row["Time"], CST).strftime("%S"),
            "source": clean_source(row["From"]),
            "views": int(row["Num"] or 0),
            "img": img,
            "hasBody": bool(clean_body(row["Word"])),
        })
    return entries


def leader_roster(home):
    """首页领导名单（主席 → 副主席 → 秘书长）-> [(稿件 ID, 职务)] 顺序。

    用于「政协领导」的列表排序：这些稿件是领导简介，应按职务先后排，
    不能按发布时间倒序（旧站发布时间是历年陆续补录的，与职务无关）。
    """
    lead = (home or {}).get("leaders") or {}
    roster = []

    def add(node, role):
        match = re.search(r"[?&]id=(\d+)", (node or {}).get("url") or "")
        if match:
            roster.append((match.group(1), role))

    add(lead.get("chairman"), "主席")
    for person in lead.get("viceChairmen") or []:
        add(person, "副主席")
    add(lead.get("secretaryGeneral"), "秘书长")
    return roster


def order_by_roster(items, order):
    """按名单顺序重排：名单内的按名单先后，名单外的按发布时间倒序附后。"""
    rank = {str(news_id): index for index, news_id in enumerate(order)}
    listed = sorted((r for r in items if str(r["ID"]) in rank),
                    key=lambda r: rank[str(r["ID"])])
    rest = [r for r in items if str(r["ID"]) not in rank]
    return listed + rest


def home_entries(items, prefix):
    """首页模块条目 -> 栏目列表条目；地址保持旧站外链，原型不内置这些正文。"""
    entries = []
    for index, item in enumerate(items, start=1):
        date = (item.get("date") or "").strip()
        entries.append({
            "id": "%s-%d" % (prefix, index),
            "title": item.get("title", ""),
            "url": item.get("url", ""),
            "date": date[:10],
            "datetime": date,
            "source": "",
            "views": "",
            "img": item.get("img", ""),
            "hasBody": False,
        })
    return entries


def article_sample(row, tab, root, downloads):
    """旧库一行稿件 -> 详情样例；正文过短时不生成。"""
    body = clean_body(row["Word"])
    if not body or len(body) < 120:
        return None
    body = rewrite_body_images(root, body, row["ID"], downloads)
    return {
        "id": row["ID"],
        "channelType": tab["type"],
        "channelName": tab["name"],
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
        "attachments": extract_attachments(row["Word"]),
        "images": body_images(body),
    }


def build(args):
    rows = load_news(args.sql)
    if not rows:
        sys.exit("未从 %s 解析到 rd_news 记录" % args.sql)
    published = [r for r in rows if r["Audit"] == "1"]
    by_type_region = {}
    for row in published:
        by_type_region.setdefault((row["Type"], row["Region"]), []).append(row)
    for items in by_type_region.values():
        items.sort(key=lambda r: r["Time"], reverse=True)

    root = args.root
    home = {}
    home_path = os.path.join(root, "frontend/home/data/home.json")
    if os.path.exists(home_path):
        with open(home_path, encoding="utf-8") as handle:
            home = json.load(handle)
    channels, articles, downloads, roster_channels = [], [], [], []
    news_by_id = {}
    for row in published:
        news_by_id.setdefault(str(row["ID"]), row)
    roster = leader_roster(home)

    for column in COLUMNS:
        layout = column.get("layout", "list")
        tabs = column["tabs"]
        siblings = []
        if layout in ("about", "leaders"):
            siblings = [{"type": child["id"], "name": child["name"],
                         "url": "detail.html?id=%s" % child["id"],
                         "active": index == 0}
                        for index, child in enumerate(column["children"])]
        elif len(tabs) > 1:
            siblings = [{"type": tab["type"], "name": tab["name"],
                         "url": "channel.html?id=%s" % tab["type"]}
                        for tab in tabs]

        # 首页模块栏目（政协视频 / 专题 / 河池风光等）：列表取自首页数据，不查旧库
        if not tabs:
            items = home.get(column.get("module", "")) or []
            if not isinstance(items, list):
                items = []
            channel = {
                "type": column["id"],
                "columnId": column["id"],
                "slug": column["slug"],
                "name": column["name"],
                "inner": column["name"],
                "intro": column["intro"],
                "layout": layout,
                "siblings": siblings,
                "homeSourced": True,
                "total": len(items),
                "list": home_entries(items, column["id"]),
            }
            if column.get("note"):
                channel["note"] = column["note"]
            channels.append(channel)
            continue

        for tab in tabs:
            region = tab.get("region", MAIN_REGION)
            items = by_type_region.get((tab["type"], region), [])
            if column.get("roster"):
                items = order_by_roster(items, [news_id for news_id, _ in roster])
            channel = {
                "type": tab.get("link", tab["type"]),
                "columnId": column["id"],
                "slug": column["slug"],
                "name": column["name"],
                "inner": tab["name"],
                "intro": column["intro"],
                "layout": layout,
                "siblings": siblings,
                "total": len(items),
                "list": channel_entries(items, root, downloads,
                                        column.get("list_limit", LIST_LIMIT)),
            }
            if layout == "about" and column.get("feature"):
                feature = column["feature"]
                featured = parse_static_article(args.site, feature["id"],
                                                column["id"], column["name"],
                                                root, downloads)
                if featured:
                    channel["feature"] = {
                        "title": featured["title"],
                        "summary": plain_summary(featured["content"], feature["summary_limit"]),
                        "url": "detail.html?id=%s" % feature["id"],
                    }
            if layout == "county" and column.get("counties"):
                channel["counties"] = column["counties"]
            if column.get("roster"):
                roster_channels.append(channel)
            channels.append(channel)

            # 详情样例：每个子栏目取前若干篇带正文的稿件
            taken = 0
            for row in items:
                if not tab.get("samples", True) or taken >= ARTICLE_PER_CHANNEL:
                    break
                sample = article_sample(row, tab, root, downloads)
                if sample:
                    articles.append(sample)
                    taken += 1

            # 指定补入的详情样例：用于演示低频形态（例如公告通知里的附件下载）
            for extra_id in column.get("extra_samples", []):
                if any(str(item["id"]) == str(extra_id) for item in articles):
                    continue
                row = news_by_id.get(str(extra_id))
                if not row:
                    print("未在旧库找到补入样例 %s" % extra_id)
                    continue
                sample = article_sample(row, tab, root, downloads)
                if sample:
                    articles.append(sample)

        # 一页式/领导型栏目：固定子导航与领导简介的正文都取自旧站静态页，保证内页可打开
        if layout in ("about", "leaders"):
            roster_children = [{"id": news_id, "name": ""}
                               for news_id, _ in (roster if column.get("roster") else [])]
            # 子导航里指向本栏目自身的项（如「政协领导」）不是独立文档，不抽正文
            doc_children = [c for c in column["children"]
                            if str(c["id"]) != str(column["id"])]
            for child in doc_children + roster_children:
                if any(str(item["id"]) == str(child["id"]) for item in articles):
                    continue
                article = parse_static_article(args.site, child["id"],
                                               column["id"], column["name"],
                                               root, downloads)
                if article:
                    article["title"] = article["title"] or child["name"]
                    articles.append(article)

        # 领导型栏目：列表条目补职务，照片取个人简介正文首图（已本地化）
        if column.get("roster"):
            role_of = {news_id: role for news_id, role in roster}
            for channel in roster_channels:
                for entry in channel["list"]:
                    entry["role"] = role_of.get(str(entry["id"]), "")
                    art = "frontend/home/images/channel/art%s-1.jpg" % entry["id"]
                    if os.path.exists(os.path.join(root, art)):
                        entry["img"] = "images/channel/art%s-1.jpg" % entry["id"]

    write(os.path.join(root, "frontend/home/data/channel.json"),
          {"channels": channels},
          "栏目列表页样例数据（来源：旧库 rd_news，按 Region 分栏取内容）")
    unique = []
    seen = set()
    for article in articles:
        if str(article["id"]) in seen:
            continue
        seen.add(str(article["id"]))
        unique.append(article)
    write(os.path.join(root, "frontend/home/data/article.json"),
          {"articles": unique},
          "详情页样例数据（旧库 rd_news，正文已做样式清洗原型处理）")
    write(os.path.join(root, "frontend/home/data/_images.json"),
          downloads,
          "缩略图下载清单：curl 拉取到 frontend/home/images/channel/")

    print("栏目 %d 个、列表条目 %d 条、详情样例 %d 篇、待下载缩略图 %d 张"
          % (len(channels), sum(len(c["list"]) for c in channels),
             len(unique), len(downloads)))


def write(path, payload, note):
    os.makedirs(os.path.dirname(path), exist_ok=True)
    with open(path, "w", encoding="utf-8") as handle:
        json.dump(payload, handle, ensure_ascii=False, indent=2)
        handle.write("\n")
    print("已写出 %s（%s）" % (path, note))


def main():
    parser = argparse.ArgumentParser(description="生成前端二级页/详情页原型样例数据")
    parser.add_argument("--sql", required=True, help="旧库 SQL 导出文件路径")
    parser.add_argument("--site", default="", help="旧站静态目录（含 html/news-view-<id>.html）")
    parser.add_argument("--root", default=os.getcwd(), help="仓库根目录")
    build(parser.parse_args())


if __name__ == "__main__":
    main()

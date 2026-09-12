#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""阶段 D 迁移：把旧站图片与视频抓回本地（媒体清单是 legacy_extract.py parse 产出的）。

  ~/py-tools/bin/python tools/migrate/fetch_media.py \
      --manifest tools/migrate/out/media_manifest.csv --base http://www.gxhczx.gov.cn

固定参数：4 并发、超时 15s、失败重试 3 次、请求间隔 200ms；成功的把 status 写成 ok 并回填
字节数与 sha256，失败的写 failed + 原因（渲染阶段会保留这些地址的旧站外链，不改写成死链）。
只连旧站，不动仓库里已入库的内容；清单可反复跑（已 ok 的默认跳过，--force 可重下）。

如果改从服务器直接拷文件（不抓网），拷完在仓库根目录跑离线核对即可：

  ~/py-tools/bin/python tools/migrate/fetch_media.py \
      --manifest tools/migrate/out/media_manifest.csv --verify

它按清单里的 target 逐个看本地有没有文件，有就标 ok 并算 sha256，没有就留 pending，不联网。
"""

import argparse
import csv
import hashlib
import os
import shutil
import sys
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
from concurrent.futures import ThreadPoolExecutor

HEADER = ['url', 'target', 'status', 'bytes', 'sha256', 'error']
# HTTP 头必须是 ASCII：早前一版把中文写进 UA，urllib 会抛 UnicodeEncodeError
USER_AGENT = 'hechi-zhengxie-migration/1.0 (+https://www.gxhczx.gov.cn)'


def absolute_url(url, base):
    url = (url or '').strip()
    if not url:
        return ''
    if url.startswith('//'):
        return 'http:' + url
    if url.startswith('http://') or url.startswith('https://'):
        return url
    return base.rstrip('/') + '/' + url.lstrip('/')


def download(url, target, timeout, retries, delay):
    """下载一个文件到 target，返回 (ok, bytes, sha256, error)。"""
    last_error = ''
    for attempt in range(1, retries + 1):
        try:
            request = urllib.request.Request(url, headers={'User-Agent': USER_AGENT})
            with urllib.request.urlopen(request, timeout=timeout) as response:
                payload = response.read()
                content_type = (response.headers.get('Content-Type') or '').lower()
            if not payload:
                last_error = '空响应'
            elif 'text/html' in content_type or payload.lstrip()[:1] == b'<':
                # 旧站对不存在的文件有时会回一个 200 的错误页，别把网页当图片存下来
                last_error = '返回的是网页而不是文件（Content-Type: %s）' % (content_type or '未知')
            else:
                os.makedirs(os.path.dirname(target), exist_ok=True)
                with open(target, 'wb') as handle:
                    handle.write(payload)
                return True, len(payload), hashlib.sha256(payload).hexdigest(), ''
        except urllib.error.HTTPError as exc:
            last_error = 'HTTP %s' % exc.code
            if exc.code in (404, 403, 410):
                break
        except Exception as exc:  # noqa: BLE001 - 网络异常统一记为失败原因
            last_error = type(exc).__name__ + ': ' + str(exc)[:120]
        if attempt < retries:
            time.sleep(delay * attempt)
    return False, 0, '', last_error or '未知错误'


def load(manifest_path):
    with open(manifest_path, encoding='utf-8', newline='') as handle:
        reader = csv.DictReader(handle)
        rows = list(reader)
    for row in rows:
        for key in HEADER:
            row.setdefault(key, '')
    return rows


def save(manifest_path, rows):
    tmp = manifest_path + '.tmp'
    with open(tmp, 'w', encoding='utf-8', newline='') as handle:
        writer = csv.DictWriter(handle, fieldnames=HEADER)
        writer.writeheader()
        for row in rows:
            writer.writerow({key: row.get(key, '') for key in HEADER})
    shutil.move(tmp, manifest_path)


def main():
    parser = argparse.ArgumentParser(description='抓取旧站图片与视频到本地（回填媒体清单）')
    parser.add_argument('--manifest', required=True, help='media_manifest.csv 路径')
    parser.add_argument('--base', default='http://www.gxhczx.gov.cn', help='相对地址的站点前缀')
    parser.add_argument('--root', default=os.getcwd(), help='仓库根目录（清单里的 target 是相对它写的）')
    parser.add_argument('--concurrency', type=int, default=4)
    parser.add_argument('--timeout', type=float, default=15)
    parser.add_argument('--retries', type=int, default=3)
    parser.add_argument('--delay', type=float, default=0.2, help='每个请求前的间隔秒数')
    parser.add_argument('--limit', type=int, default=0, help='只处理前 N 条（抽样验证用）')
    parser.add_argument('--force', action='store_true', help='已 ok 的也重下')
    parser.add_argument('--dry-run', action='store_true', help='只打印将要抓取的地址，不下载')
    parser.add_argument('--verify', action='store_true',
                        help='离线核对：只检查 target 文件是否已在本地（服务器拷贝场景），不联网')
    args = parser.parse_args()

    rows = load(args.manifest)

    if args.verify:
        ready, missing = 0, 0
        for row in rows:
            if not row['url'].strip():
                continue
            target = os.path.join(args.root, row['target'])
            if os.path.isfile(target) and os.path.getsize(target) > 0:
                with open(target, 'rb') as handle:
                    payload = handle.read()
                row['status'], row['bytes'] = 'ok', str(len(payload))
                row['sha256'] = hashlib.sha256(payload).hexdigest()
                row['error'] = ''
                ready += 1
            else:
                if row['status'].strip().lower() == 'ok':
                    row['status'], row['bytes'], row['sha256'] = 'pending', '', ''
                if row['status'].strip().lower() != 'failed':
                    row['error'] = '本地没有该文件（等待从服务器拷贝）'
                missing += 1
        save(args.manifest, rows)
        print('离线核对完成：本地已有 %d 个、缺 %d 个（缺的保留旧站地址，拷贝到位后再跑一次本命令）'
              % (ready, missing))
        return 0

    todo = []
    for row in rows:
        if not row['url'].strip():
            continue
        if row['status'].strip().lower() == 'ok' and not args.force:
            continue
        todo.append(row)
    if args.limit:
        todo = todo[:args.limit]
    print('媒体清单 %d 条，本次待处理 %d 条（并发 %d，超时 %.0fs，重试 %d 次）'
          % (len(rows), len(todo), args.concurrency, args.timeout, args.retries))

    if args.dry_run:
        for row in todo[:20]:
            print('  %s → %s' % (absolute_url(row['url'], args.base), row['target']))
        if len(todo) > 20:
            print('  ……另有 %d 条' % (len(todo) - 20))
        return 0

    lock = threading.Lock()
    done = {'ok': 0, 'failed': 0, 'bytes': 0}

    def worker(row):
        url = absolute_url(row['url'], args.base)
        target = os.path.join(args.root, row['target'])
        time.sleep(args.delay)
        ok, size, digest, error = download(url, target, args.timeout, args.retries, args.delay)
        with lock:
            if ok:
                row['status'], row['bytes'], row['sha256'], row['error'] = 'ok', str(size), digest, ''
                done['ok'] += 1
                done['bytes'] += size
            else:
                row['status'], row['error'] = 'failed', error
                done['failed'] += 1
            total = done['ok'] + done['failed']
            if total % 25 == 0:
                save(args.manifest, rows)
            if total % 100 == 0 or total == len(todo):
                print('  进度 %d/%d（成功 %d、失败 %d）' % (total, len(todo), done['ok'], done['failed']))

    with ThreadPoolExecutor(max_workers=max(1, args.concurrency)) as pool:
        list(pool.map(worker, todo))

    save(args.manifest, rows)
    print('完成：成功 %d 个、失败 %d 个，共 %.1f MB；清单已回填 %s'
          % (done['ok'], done['failed'], done['bytes'] / 1048576, args.manifest))
    if done['failed']:
        print('  失败的地址会在 render 阶段保留旧站外链，并在报告里列出。', file=sys.stderr)
    return 0


if __name__ == '__main__':
    sys.exit(main())

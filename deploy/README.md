# 本地与生产环境

两条路都能跑通本项目，按手上有没有 Docker 选：

## 一、SQLite（推荐先跑通接口与发布）

不需要 Docker，也不需要 MySQL。步骤见 [../backend/README.md](../backend/README.md)，三步：

```bash
php backend/bin/migrate.php
php backend/bin/seed.php
php -S 127.0.0.1:8080 -t backend/public backend/public/router.php
```

## 二、Docker Compose（生产同构：Nginx + PHP-FPM 8 + MySQL 8 + Redis）

```bash
docker compose up -d --build      # 首次会拉镜像、建库、执行 database/migrations/mysql
docker compose exec php php bin/migrate.php
docker compose exec php php bin/seed.php --snapshots=/var/www/snapshots
```

- 站点：<http://127.0.0.1:8080>（`/api/v1/health` 可自检）
- MySQL：`127.0.0.1:3307`，库 `hechi_zx`，账号见 `docker-compose.yml`（**示例口令，上线前必须换成密钥管理下发的强口令**）
- Redis：`127.0.0.1:6380`
- 静态化产物：`backend/storage/publish/`，由 Nginx 直出 `/channel/`、`/article/`

> 本目录的编排文件尚未在本机验证（开发机未安装 Docker），首次在有 Docker 的机器上跑时，先确认 `deploy/nginx/default.conf` 里的 `fastcgi_pass php:9000` 与实际服务名一致。

## 三、上线前的必改项

1. 数据库口令、`APP_DEBUG=0` 走 `.env`，不进仓库。
2. `deploy/nginx/default.conf` 的旧地址 301 规则要按 `sys_url_redirect` 表生成后的实际映射替换。
3. HTTPS、WAF、等保二级相关配置（日志留存 180 天、防篡改、主备与异地备份）在此模板之外单独落地。

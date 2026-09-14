FROM php:8.3-fpm-alpine

# 主站只要 PDO（MySQL）与常用扩展；编校微服务独立部署，不塞进这个镜像。
# zip 是提案系统导出 Word／Excel 用的（XlsxExporter / WordExporter 走 ZipArchive）。
RUN apk add --no-cache tzdata libzip-dev \
    && docker-php-ext-install pdo_mysql opcache zip \
    && cp /usr/share/zoneinfo/Asia/Shanghai /etc/localtime \
    && echo "Asia/Shanghai" > /etc/timezone

COPY php.ini /usr/local/etc/php/conf.d/zz-app.ini

WORKDIR /var/www/html

# =============================================================================
# 网盘资源搜索引擎 - Dockerfile
# PHP 8.2-FPM + Nginx + Composer + 所需扩展
# 一个容器内同时运行 PHP-FPM 与 Nginx（supervisord 托管）
# =============================================================================

FROM php:8.2-fpm-alpine

LABEL maintainer="laobi465"
LABEL description="轻量网盘资源搜索引擎 - PHP-FPM + Nginx"

# 时区
ENV TZ=Asia/Shanghai
ENV LANG=C.UTF-8
ENV COMPOSER_ALLOW_SUPERUSER=1
ENV COMPOSER_HOME=/tmp/composer

# 系统依赖（含 Nginx / supervisor / mysql-client / 各类 dev 库）
RUN set -eux \
    && sed -i 's#dl-cdn.alpinelinux.org#mirrors.aliyun.com#g' /etc/apk/repositories \
    && apk add --no-cache \
        nginx \
        supervisor \
        bash \
        curl \
        wget \
        git \
        zip \
        unzip \
        tzdata \
        mysql-client \
        busybox-suid \
        libzip-dev \
        libpng-dev \
        libjpeg-turbo-dev \
        freetype-dev \
        libxml2-dev \
        oniguruma-dev \
        icu-dev \
        bzip2-dev \
        $PHPIZE_DEPS \
    && cp /usr/share/zoneinfo/$TZ /etc/localtime \
    && echo $TZ > /etc/timezone

# 编译安装 PHP 扩展（拆分为独立 RUN 层，便于定位失败 + 利用 Docker 缓存）
# GD（需要 freetype + jpeg）
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" gd

# 数据库 + 数学
RUN docker-php-ext-install -j"$(nproc)" pdo_mysql mysqli bcmath

# 压缩 + 国际化 + 多字节
RUN docker-php-ext-install -j"$(nproc)" zip bz2 intl mbstring

# 进程 + 信号
RUN docker-php-ext-install -j"$(nproc)" pcntl sockets

# opcache（PHP 8.2 镜像已内置，只需启用）
RUN docker-php-ext-enable opcache

# Redis（PECL）
RUN pecl install redis && docker-php-ext-enable redis

# Composer（国内镜像加速）
COPY --from=composer:2.6 /usr/bin/composer /usr/bin/composer
RUN composer config -g repo.packagist composer https://mirrors.aliyun.com/composer/

WORKDIR /var/www/html

# 复制项目代码
COPY . /var/www/html

# 安装依赖
RUN set -eux \
    && composer install --no-dev --optimize-autoloader --no-interaction --no-progress || \
       composer install --no-dev --no-interaction --no-progress

# 运行时目录与权限
RUN set -eux \
    && mkdir -p /var/www/html/runtime/{cache,session,temp,log} \
    && mkdir -p /var/www/html/public/static/uploads \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 0755 /var/www/html \
    && chmod -R 0775 /var/www/html/runtime \
    && chmod -R 0775 /var/www/html/public/static/uploads

# 配置文件
COPY docker/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/worker-supervisord.conf /etc/worker-supervisord.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/99-custom.ini
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-custom.conf
COPY docker/crontab /etc/crontab
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
COPY docker/worker-entrypoint.sh /usr/local/bin/worker-entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh /usr/local/bin/worker-entrypoint.sh

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["supervisord", "-c", "/etc/supervisord.conf", "-n"]

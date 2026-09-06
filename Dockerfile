FROM php:8.2-apache

# 安装 curl 扩展（Duolingo API 依赖）
RUN apt-get update \
    && apt-get install -y libcurl4-openssl-dev pkg-config \
    && docker-php-ext-install curl \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

# 复制代码
COPY . /var/www/html/

# 初始化数据目录并设置权限
RUN mkdir -p /var/www/html/data/accounts \
             /var/www/html/data/tokens \
             /var/www/html/data/free \
             /var/www/html/data/mid \
             /var/www/html/data/vip \
    && chown -R www-data:www-data /var/www/html/data \
    && chmod -R 777 /var/www/html/data

EXPOSE 80
CMD ["apache2-foreground"]

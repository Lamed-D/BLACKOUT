# 빌드 컨텍스트 = labs/  (theme/ 공유 자산 접근)
FROM php:8.4-apache

# SQLite(PDO) 확장 + mod_rewrite + .htaccess 허용
RUN apt-get update \
 && apt-get install -y --no-install-recommends libsqlite3-dev \
 && rm -rf /var/lib/apt/lists/* \
 && docker-php-ext-install pdo_sqlite \
 && a2enmod rewrite \
 && sed -ri 's/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

# 앱 소스 + 공유 테마 자산
COPY php/src/ /var/www/html/
COPY theme/style.css  /var/www/html/style.css
COPY theme/vulns.json /var/www/html/vulns.json

# 쓰기 가능한 DB·업로드 디렉터리
RUN mkdir -p /var/www/db /var/www/html/uploads \
 && chown -R www-data:www-data /var/www/db /var/www/html/uploads

EXPOSE 80

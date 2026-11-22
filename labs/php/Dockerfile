# 빌드 컨텍스트 = labs/  (theme/ 공유 자산 접근)
FROM php:8.4-apache

# MySQL(PDO) 확장 + mod_rewrite + .htaccess 허용
RUN docker-php-ext-install pdo_mysql \
 && a2enmod rewrite \
 && sed -ri 's/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

# [VULN] 원격/래퍼 include 허용 — php://input, data:// 래퍼로 LFI→RCE (#51, 의도적 취약)
RUN { echo 'allow_url_include = On'; echo 'allow_url_fopen = On'; } > /usr/local/etc/php/conf.d/lab-vuln.ini

# 앱 소스 + 공유 테마 자산
COPY php/src/ /var/www/html/
COPY theme/style.css  /var/www/html/style.css
COPY theme/vulns.json /var/www/html/vulns.json
COPY theme/exploits.json /var/www/html/exploits.json
COPY theme/approaches.json /var/www/html/approaches.json
COPY theme/exploits/ /var/www/html/exploit_files/
COPY php/community.css /var/www/html/community.css
COPY php/community.js /var/www/html/community.js

# 쓰기 가능한 업로드 디렉터리
RUN mkdir -p /var/www/html/uploads \
 && chown -R www-data:www-data /var/www/html/uploads

EXPOSE 80

# 빌드 컨텍스트 = labs/  (theme/ 공유 자산 접근용)
FROM nginx:1.27-alpine
# 정적 포털(nginx): 랜딩 페이지 + 공유 테마 CSS
COPY nginx/index.html /usr/share/nginx/html/index.html
COPY theme/style.css  /usr/share/nginx/html/style.css
EXPOSE 80

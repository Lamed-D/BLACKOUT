# BLACKOUT 실행
저장소 루트에서 실행합니다. Compose의 include를 지원하는 Docker Compose v2가 필요합니다.

~~~sh
docker compose up -d
docker compose ps
docker compose logs -f cloudflared
~~~

터널 로그에 표시된 https://…trycloudflare.com 주소를 열면 실습 포털이 표시됩니다. 터널 재시작 시 주소가 바뀔 수 있습니다. Ctrl+C는 로그 보기만 종료합니다. PC에서는 http://127.0.0.1:8088 에서 같은 화면을 확인합니다.

기존 게시판도 실행하려면 다음 명령을 사용하고 http://127.0.0.1:9999 를 엽니다.

~~~sh
docker compose --profile board up -d --build
docker compose logs --tail 100 portal
docker compose --profile board logs --tail 100 apache-server flask-app mariadb
docker compose --profile board down
~~~

MariaDB와 신규 첨부파일은 Docker 볼륨에 보관됩니다. 기존 uploads 폴더의 자료는 자동 이전되지 않습니다. init_db.sql은 빈 DB 볼륨을 처음 시작할 때 적용됩니다.

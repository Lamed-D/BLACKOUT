# BLACKOUT Django 커뮤니티 랩

이 폴더에서 `docker compose up -d --build` 후 `docker compose logs -f cloudflared`에 표시되는 HTTPS 주소를 엽니다. 터널을 다시 만들면 주소가 바뀝니다. Docker 호스트에서는 http://127.0.0.1:8093 으로도 접속할 수 있습니다. 회원가입부터 시작하세요. 홈의 공지와 상단 실습 가이드에 세션, 권한, 출력 처리 과제가 있습니다.

Django 및 Gunicorn이 HTTP 요청을 처리하고 PostgreSQL이 계정, 세션, 게시글, 댓글을 보관합니다. DB와 서명 키는 이 프로젝트의 전용 볼륨에 저장됩니다.

`docker compose logs -f web`으로 요청과 오류를 확인합니다. `docker compose down`으로 종료하면 다음 실행에서도 데이터가 유지됩니다.

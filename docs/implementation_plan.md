# Docker 배포 구성
공개 요청은 cloudflared에서 정적 실습 포털로 전달됩니다. 선택형 board 프로필의 Apache, Gunicorn, MariaDB는 별도 네트워크에서 통신합니다. Apache는 호스트 루프백에서 접속할 수 있습니다.

실행과 로그 조회 명령은 [운영 안내](run.md)를 따릅니다.

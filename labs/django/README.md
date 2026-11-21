# BLACKOUT Django 커뮤니티

Django 5 + PostgreSQL로 만든 로컬 커뮤니티 앱 셸입니다. 의도적 취약점 연결은 이 앱의 범위 밖입니다. 실습실은 공유 카탈로그를 읽어 표시합니다.

## 실행

저장소 루트에서 다음 명령을 실행합니다.

```sh
docker compose -f labs/django/compose.yaml config --quiet
docker compose -f labs/django/compose.yaml up -d --build
```

브라우저에서 <http://127.0.0.1:8103>을 엽니다. 종료하려면 `docker compose -f labs/django/compose.yaml down`을 실행합니다. PostgreSQL은 호스트에 포트를 공개하지 않고 볼륨도 사용하지 않습니다. 새 DB 컨테이너에서 마이그레이션과 시드가 자동 실행됩니다.

시드 계정은 `admin/admin123`, `testuser/testpass`, `alice/alice1234`입니다. Django의 기본 비밀번호 해시와 인증·CSRF 설정을 사용합니다. 계정·포인트·게시글·상품 데이터는 공유 시드와 맞춥니다.

## 기능

1. 로그인하거나 새 계정을 등록합니다.
2. 최신 글 목록을 검색하고 번호형 페이지를 탐색합니다. 로그인 후 글을 작성·수정하고 댓글과 좋아요를 남길 수 있습니다.
3. 마이페이지에서 글·댓글·포인트를 확인하고 상점에서 포인트로 상품을 구매합니다.
4. 관리자 계정은 회원 목록을 보고 공지를 작성합니다.
5. 실습실은 `theme/vulns.json` 중 공통과 Django 항목을 읽기 전용으로 표시합니다.

## 구조

```text
labs/django/
├─ compose.yaml       Django와 내부 PostgreSQL
├─ Dockerfile         labs/를 빌드 컨텍스트로 사용
├─ settings.py        Django 설정
├─ urls.py            라우트
├─ board/
│  ├─ models.py       커뮤니티 데이터 모델
│  ├─ migrations/     기존 모델을 보존하는 증분 변경
│  └─ management/     멱등 시드 명령
└─ templates/         게시판·계정·상점·관리자·실습실
```

`/style.css`는 빌드할 때 `labs/theme/style.css`를 그대로 복사합니다.

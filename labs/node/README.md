# BLACKOUT Node 커뮤니티

Express + EJS + MariaDB로 만든 로컬 커뮤니티 앱 셸입니다. 의도적 취약점 연결은 이 앱의 범위 밖이며, 실습실은 공유 카탈로그를 읽어 표시합니다.

## 실행

저장소 루트에서 다음 명령을 실행합니다.

```sh
docker compose -f labs/node/compose.yaml config --quiet
docker compose -f labs/node/compose.yaml up -d --build
```

브라우저에서 <http://127.0.0.1:8102>를 엽니다. 종료하려면 `docker compose -f labs/node/compose.yaml down`을 실행합니다. MariaDB는 호스트에 포트를 공개하지 않으며, 볼륨 없이 새 컨테이너에서 시드가 재생성됩니다.

시드 계정은 `admin/admin123`, `testuser/testpass`, `alice/alice1234`입니다. 이 앱의 암호 저장은 신규 코드용 안전한 scrypt 형식을 사용하며 계정·포인트·게시글·상품 데이터는 공유 시드와 맞춥니다.

## 기능

1. 로그인 또는 새 계정을 등록합니다.
2. 최신 글 목록에서 글을 열고 댓글과 좋아요를 남깁니다. 글쓰기는 로그인 후 사용할 수 있습니다.
3. 마이페이지에서 활동과 포인트를 확인하고, 상점에서 포인트로 상품을 구매합니다.
4. 관리자 계정은 회원 목록을 보고 공지를 작성할 수 있습니다.
5. 실습실은 `theme/vulns.json` 중 공통과 Node 항목을 표시합니다. 카탈로그 내용은 변경하지 않습니다.

## 구조

```text
labs/node/
├─ compose.yaml       Node와 내부 MariaDB
├─ Dockerfile         labs/를 빌드 컨텍스트로 사용
├─ db.js              스키마·시드와 DB 연결
├─ server.js          라우트와 세션
└─ views/             EJS 화면
    ├─ header.ejs     공통 네비게이션·테마
    └─ *.ejs          게시판·계정·상점·관리자·실습실
```

`/style.css`는 빌드할 때 `labs/theme/style.css`를 그대로 복사합니다.

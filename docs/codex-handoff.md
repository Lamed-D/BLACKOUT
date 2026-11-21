# BLACKOUT — Codex 작업 인수인계 (핸드오프 프롬프트)

이 문서는 남은 작업을 Codex(또는 다른 에이전트)에 넘기기 위한 **그대로 붙여 쓸 수 있는 프롬프트**와 공통 규약을 담는다.
각 Task 의 "프롬프트" 블록을 Codex에 전달하면 된다.

---

## 현재 상태 (완료, main 에 머지됨)
- **PHP 엔진** (`labs/php/`, 상용 MariaDB): BLACKOUT 커뮤니티(게시판·인증·마이페이지·관리자·상점·API·실습실) 구현 완료.
  - 취약점 **43종**(공통 39 + PHP 전용 4: 매직해시·extract·변수함수 RCE·phpinfo) 모두 실제 동작 검증됨.
  - 게시판: 최신순 + 번호형 페이지네이션(윈도우+말줄임).
  - 실습실(`/practice`): 공유 카탈로그 `labs/theme/vulns.json` 를 엔진별로 필터링해 렌더. 도식(diagram)·단계(steps)·상세(detail) 지원.
  - 로그인 화면의 쿼리 노출 제거(현실화). SQLi 발견 단서는 검색의 verbose DB 오류 '하나'만 유지.
- **포털** `labs/nginx/` (:8090), **통합** `labs/compose.yaml`, **랩별 독립 compose** 존재.
- **공유 자산** `labs/theme/`: `style.css`(테마), `vulns.json`(취약점 카탈로그 44개, `engine` 필드로 php/node/django 전용 구분), `schema.md`(공통 논리 스키마+시드).
- `labs/django/` 는 **임시 커뮤니티(안전 버전)** — 취약 엔진으로 전면 재작성 필요(Task C).

## 공통 규약 (모든 Task 필수 준수)
1. **로컬 전용**: 모든 published 포트는 `127.0.0.1` 바인딩. **cloudflared/터널 절대 추가 금지**(사용자가 추후 직접 추가).
2. **상용 DB 사용(SQLite 금지)**: PHP/Node → MariaDB, Django → PostgreSQL. DB 컨테이너는 **호스트에 포트 미노출**(내부 통신 전용), 볼륨 없는 리셋형, healthcheck + depends_on. 부팅 시 스키마+시드 자동 생성(멱등).
3. **공유 테마 계승**: `labs/theme/style.css` 를 그대로 서빙하고, base 레이아웃(네비바/푸터/패널)·Bootstrap5 CDN 을 PHP 엔진과 동일한 룩앤필로 재현. 네비바에 엔진 뱃지(`engine-node`/`engine-django`).
4. **공유 취약점 카탈로그**: `/practice` 는 `labs/theme/vulns.json` 을 읽어 `engine` 이 `all`(또는 미지정) 또는 해당 엔진인 항목만 표시. 도식(diagram)·단계(steps)·상세(detail) 모두 렌더. (PHP 의 `labs/php/src/pages/practice.php` 가 레퍼런스)
5. **시드/계정 동일**: `labs/theme/schema.md` 의 논리 스키마·시드(users: admin/admin123, testuser/testpass, alice/alice1234, admin role=admin, points 등)를 동일하게 사용.
6. **웹 완성도 우선(토이 금지)**: 해킹 기능만 나열하지 말 것. 평범한 커뮤니티로 자연스럽게 동작하고, 취약점은 "현실적인 실수"처럼 숨어 있게. 앱 화면에 쿼리/페이로드를 대놓고 출력하지 말 것(실습 설명은 `/practice` 가이드로).
7. **실습 가이드 품질**: 사람이 그대로 따라 실습 가능하도록 단계별(어디서/무엇을 입력/어떤 결과)로 작성. 구조 설명이 필요한 곳엔 ASCII 도식. 불필요한 곳엔 억지로 넣지 말 것.
8. **검증 필수**: `docker compose -f labs/<engine>/compose.yaml config --quiet` 통과 + `up -d --build` 후 대표 취약점 페이로드가 **실제로 동작**하는지 curl 등으로 확인하고 결과를 PR 본문에 기록.
9. **각 랩 README**: `labs/<engine>/README.md` 에 기능·취약점(공통+엔진 전용)·실행법·구조를 정리.
10. **워크플로**: 작업 브랜치 → 증분 커밋 → `gh pr create` → 머지 → 브랜치 삭제(최종 main 만). 커밋 꼬리말 `Co-Authored-By: ...`, PR 꼬리말 `Generated with Claude Code` 규칙은 저장소 관례를 따름.

---

## Task A — PHP 웹 완성도 고도화 (SSO · 에디터 · 첨부 · 게시판)
대상: `labs/php/`. 기존 43종 취약점이 **계속 동작**해야 한다(회귀 금지).

### 프롬프트 (Codex에 전달)
```
BLACKOUT 저장소의 labs/php (PHP 8.4 + MariaDB, 프런트 컨트롤러 src/index.php, 페이지는 src/pages/*.php,
공통은 src/lib.php) 를 "토이가 아닌 완성도 높은 커뮤니티 웹앱"으로 고도화하라. docs/codex-handoff.md 의
공통 규약을 모두 지키고, 기존 43종 취약점(labs/php/README.md 참고)은 전부 그대로 동작해야 한다.

1) SSO 로그인 (Google / Kakao / Naver):
   - users 테이블에 avatar, oauth_provider, oauth_id 컬럼 추가(lib.php init).
   - 환경변수로 키 설정: GOOGLE_CLIENT_ID/SECRET, KAKAO_CLIENT_ID/SECRET, NAVER_CLIENT_ID/SECRET,
     OAUTH_REDIRECT_BASE(기본 http://127.0.0.1:8101). compose 에 env 전달(빈 기본값).
   - 라우트: /oauth/<provider>/login (state 생성 후 공급자 authorize 로 리다이렉트),
     /oauth/<provider>/callback (state 검증 → code 로 토큰 교환 → 프로필 조회 → 로그인/자동가입/이메일 연동).
   - curl 확장 없이 file_get_contents + stream context 로 토큰/프로필 요청.
   - 로그인 페이지에 3개 공급자 버튼. 키 미설정이면 "키가 설정되지 않았습니다" 안내 후 /login 로 복귀.
   - 각 공급자 엔드포인트/스코프:
     Google  authorize https://accounts.google.com/o/oauth2/v2/auth  token https://oauth2.googleapis.com/token
             userinfo https://www.googleapis.com/oauth2/v2/userinfo  scope "openid email profile"
     Kakao   authorize https://kauth.kakao.com/oauth/authorize  token https://kauth.kakao.com/oauth/token
             profile https://kapi.kakao.com/v2/user/me
     Naver   authorize https://nid.naver.com/oauth2.0/authorize  token https://nid.naver.com/oauth2.0/token
             profile https://openapi.naver.com/v1/nid/me
   - (선택) 취약점: OAuth state 미검증/약한 검증, 이메일만으로 계정 연동(계정 탈취) 등은 "현실적 실수"로 녹여도 좋다.

2) 리치 텍스트 에디터:
   - 글쓰기/수정(post/write, post/<id>/edit)에 Quill(CDN) WYSIWYG 적용. 본문을 HTML 로 저장.
   - 저장형 XSS 취약점은 유지(본문을 이스케이프 없이 렌더). 단 앱 UX 는 자연스럽게.

3) 첨부파일 디자인 강화:
   - 다중 첨부(여러 파일), 드래그앤드롭 업로드 UI. files 테이블에 다중 저장.
   - 글 보기에서 이미지 썸네일/미리보기, 그 외 파일은 확장자 아이콘 + 원본명 + 용량 표시 카드.
   - 무제한 업로드(웹셸) 취약점은 유지하되, 일반 첨부 UX 는 세련되게.

4) 게시판 강화:
   - 카테고리(예: 공지/자유/질문/정보) 컬럼 추가 + 필터 탭, 대댓글(parent_id) 계층 렌더,
     상대시간 표시, 작성자 프로필 링크, 좋아요/조회수 노출, 빈 상태/스켈레톤 등 완성도.
   - 관리자/상점/마이페이지 UI 도 함께 다듬기.

검증: docker compose -f labs/php/compose.yaml up -d --build 후 기존 취약점(SQLi 로그인우회/UNION, 웹셸 RCE,
JWT, LFI, 변수함수 등) 동작 + 신규 기능(SSO 버튼/흐름, 에디터 저장, 다중첨부/썸네일, 카테고리/대댓글) 확인.
labs/php/README.md 에 추가 기능/취약점 정리. 결과를 PR 본문에 기록.
```

---

## Task B — Node.js 엔진 (Express + MariaDB)
대상: `labs/node/` (신규). 포트 **8102**.

### 프롬프트 (Codex에 전달)
```
BLACKOUT 저장소에 labs/node (Express + EJS + MariaDB[mysql2]) 엔진을 신규 구현하라. docs/codex-handoff.md 의
공통 규약을 지키고, PHP 엔진(labs/php)과 동일한 BLACKOUT 커뮤니티 경험을 Node 로 재현하라.

- 스택: node:22 이미지, express, ejs, express-session, cookie-parser, multer, mysql2, (역직렬화 데모용) node-serialize.
  DB 는 MariaDB 컨테이너(호스트 미노출). 포트 127.0.0.1:8102:3000. cloudflared 금지.
- 구조: labs/node/{Dockerfile, compose.yaml, package.json, server.js, db.js, views/ 또는 레이아웃 헬퍼, README.md}.
  빌드 컨텍스트는 labs/ (theme/ 공유 자산 COPY). /style.css 와 /practice 의 vulns.json 을 PHP 와 동일하게 서빙.
- 기능: 게시판(최신순+번호형 페이지네이션)·회원/로그인·마이페이지·관리자·상점·/api/*·실습실(engine='node' 필터).
  시드/스키마는 labs/theme/schema.md 동일.
- 공통 취약점(Node 관용구로): SQLi(쿼리 문자열 연결, 스키마 열거는 information_schema), 저장/반사/DOM XSS,
  약한 MD5, JWT(약한 시크릿·alg=none), 쿠키 권한상승, IDOR, 접근통제 미흡, 무제한 업로드(.js/.html 등),
  경로탐색/다운로드, 명령주입(child_process), SSRF, 오픈리다이렉트, XXE(libxml 계열), CORS, 상점 로직,
  Host 헤더, 민감정보 노출 등.
- Node 전용 취약점: 프로토타입 오염(/api/merge, __proto__ 병합), node-serialize 역직렬화 RCE,
  EJS/템플릿 SSTI, (선택) ReDoS. vulns.json 에 engine='node' 로 항목 추가.
- 웹 완성도(Task A 수준): 가능하면 SSO·에디터·첨부 디자인·게시판 강화도 적용(최소한 게시판 완성도는 맞출 것).
- 포털(labs/nginx/index.html)과 labs/compose.yaml 에 node(:8102) 서비스/카드 활성화. 포털 "준비중" 해제.

검증: config --quiet + up -d --build 후 대표 취약점(프로토타입 오염 isAdmin 오염, SQLi, 웹셸, JWT 등) 실제 동작 확인.
labs/node/README.md 작성. PR 본문에 검증 기록.
```

---

## Task C — Django 엔진 (Django 5 + PostgreSQL)
대상: `labs/django/` (현재 임시 안전 커뮤니티 → **취약 엔진으로 전면 재작성**). 포트 **8103**.

### 프롬프트 (Codex에 전달)
```
BLACKOUT 저장소의 labs/django 를 "의도적으로 취약한 BLACKOUT 커뮤니티(Django 5 + PostgreSQL)"로 전면
재작성하라. docs/codex-handoff.md 의 공통 규약을 지키고, PHP/Node 엔진과 동일한 경험을 Django 로 재현하라.

- 스택: python:3.12-slim, Django 5, psycopg(PostgreSQL). DB 컨테이너 호스트 미노출. 포트 127.0.0.1:8103:8000.
  cloudflared 금지(기존 compose 의 터널/public 네트워크 이미 제거됨). 볼륨 없는 리셋형 + healthcheck.
- 기능: 게시판(최신순+번호형 페이지네이션)·회원/로그인·마이페이지·관리자·상점·/api/*·실습실(engine='django' 필터).
  시드/스키마는 labs/theme/schema.md 동일. 공유 theme/style.css·vulns.json 서빙, 룩앤필 동일.
- 공통 취약점(Django 관용구로): raw()/extra()/문자열 포맷으로 SQLi, |safe/format 으로 XSS·SSTI,
  약한 MD5, JWT, 쿠키 권한상승, IDOR, 접근통제 미흡, 무제한 업로드, 경로탐색, 명령주입(os.system),
  SSRF, 오픈리다이렉트, XXE(lxml resolve_entities), CORS, 상점 로직, Host 헤더, DEBUG/verbose 등.
- Django 전용 취약점: pickle 역직렬화(/api/config), Django 템플릿/문자열 포맷 SSTI, ORM .extra() SQLi,
  SECRET_KEY 하드코딩/DEBUG=True/ALLOWED_HOSTS=* 설정 오류, mass assignment. vulns.json 에 engine='django' 추가.
- 웹 완성도(Task A 수준): SSO·에디터·첨부 디자인·게시판 강화 적용(최소 게시판 완성도).
- 포털/통합 compose 에 django(:8103) 서비스/카드 활성화, "준비중" 해제.

검증: config --quiet + up -d --build 후 대표 취약점(pickle RCE, ORM SQLi, 템플릿 인젝션 등) 동작 확인.
labs/django/README.md 작성. PR 본문에 검증 기록.
```

---

## 참고 파일 (레퍼런스)
- 레이아웃/헬퍼/DB: `labs/php/src/lib.php`
- 라우팅: `labs/php/src/index.php`
- 취약 페이지: `labs/php/src/pages/{board,auth,tools,admin,shop,api,phpvuln,practice}.php`
- 약한 JWT: `labs/php/src/jwt.php`
- 실습실 렌더(도식/단계/엔진필터): `labs/php/src/pages/practice.php`
- 공유 카탈로그/스키마/테마: `labs/theme/{vulns.json, schema.md, style.css}`

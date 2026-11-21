# BLACKOUT — Codex 작업 인수인계 (핸드오프 프롬프트)

이 문서는 남은 작업을 Codex(또는 다른 에이전트)에 넘기기 위한 **그대로 붙여 쓸 수 있는 프롬프트**와 공통 규약을 담는다.
각 Task 의 "프롬프트" 블록을 Codex에 전달하면 된다.

> **이 핸드오프의 전제**: Codex 에게 맡기는 일은 **순수 웹 개발(UI·UX·일반 기능)뿐**이다.
> 취약점 설계·주입·공격·보안 우회 같은 **사이버보안 작업은 Codex 범위에 포함되지 않는다**.
> 기존 취약점(보안 동작)은 **동결**이며, 아래 "🔒 보안/취약점 동결 규칙"이 다른 모든 지시보다 우선한다.

---

## 🔒 보안/취약점 동결 규칙 (최우선 · 모든 Task 위에 있음)

BLACKOUT 은 **의도적으로 취약하게 설계된 로컬 학습용 랩**이다. 기존 취약점은 커리큘럼 자체이므로 **그대로 보존**해야 한다.
Codex 는 **보안을 다루지 않는다**. 기능/화면만 개선하고, 보안 동작은 **읽기 전용**으로 취급한다.

1. **패치 금지 (기존 취약 동작을 고치지 말 것)**
   아래 "보안성 강화"를 기존 코드 경로에 **새로 추가하지 말 것**:
   - 기존 문자열 연결 쿼리에 Prepared Statement/파라미터 바인딩 추가
   - 기존에 이스케이프 없이 출력하는 곳(`echo $post['content']`, 댓글·제목·bio 등)에 `htmlspecialchars`/escape/sanitize/정화 추가
   - 파일 업로드 확장자·MIME·크기 검증, 업로드 경로 제한
   - CSRF 토큰, `session_regenerate_id()`, 리다이렉트 화이트리스트(오픈 리다이렉트 차단)
   - 경로 정규화·`realpath`·basename 강제, LFI/경로탐색 방어
   - 서버측 권한 재검증(IDOR·접근통제 보강), 쿠키 신뢰 제거
   - 비밀번호 강한 해시(`password_hash`/bcrypt), 강한 JWT 시크릿·alg 제한, 쿠키 `HttpOnly/Secure/SameSite`
   - 명령 실행 인자 이스케이프(`escapeshellarg` 등), SSRF 목적지 차단
   한마디로: **보안이 "좋아지는" 변경은 전부 금지.** 지금의 (일부러) 허술한 동작을 그대로 둔다.

2. **신규 취약점 추가도 금지**
   새 취약점을 **의도적으로든 실수로든 만들지 말 것**. 신규 기능은 **안전하게** 구현한다
   (신규 쿼리는 파라미터 바인딩, 신규 출력은 이스케이프, OAuth `state`는 반드시 검증 등).
   즉 "기존 취약점은 손대지 않고, 새 코드는 깨끗하게."

3. **기존 취약 코드 경로에 손대지 않기**
   신규 기능이 기존 취약 함수/쿼리/렌더를 **반드시 수정해야만** 구현되는 경우 →
   **구현을 멈추고** 그 부분만 남긴 채, PR 본문에 `동결 충돌: <위치/이유>` 로 적어 소유자에게 넘긴다.
   (예: 글 목록 디자인을 바꾸되 검색 쿼리/제목 출력 로직은 건드리지 않는다.)

4. **동결 대상 (권위 목록)**
   `labs/php/README.md` 와 `labs/theme/vulns.json` 이 설명하는 **모든 동작**은 동결이다.
   PHP 엔진에서 특히 손대지 말 것:
   - `src/lib.php` — `md5()` 비밀번호, `is_admin()`(쿠키 role 신뢰), `JWT_SECRET='secret'`
   - `src/jwt.php` — 약한 시크릿 + `alg=none` 허용
   - `src/pages/auth.php` — 로그인 SQLi, role/jwt 쿠키 발급, 세션 고정, `next` 오픈 리다이렉트,
     회원가입/프로필의 mass assignment(role·points), 아이디/비번 찾기 열거·Host 헤더, `change_pw` CSRF, 프로필 IDOR·bio 저장형 XSS
   - `src/pages/board.php` — 검색 SQLi·verbose 오류·반사형 XSS, 제목/본문/댓글 저장형 XSS, 무제한 파일 업로드
   - `src/pages/tools.php` — ping 명령주입, download 경로탐색, url_preview SSRF·오픈리다이렉트, theme LFI, xss 반사/DOM
   - `src/pages/{admin,shop,api,phpvuln}.php` — 접근통제·IDOR·상점 로직·정보노출 및 PHP 전용(매직해시·extract·변수함수 RCE·phpinfo)

5. **검증의 의미**
   "기존 취약점이 여전히 동작하는지" 확인하는 것은 **동작 보존(회귀 방지) 확인**이 목적이다. 공격 수행/확장이 아니다.
   새 기능 추가 전후로 기존 동작이 **바뀌지 않았음**을 확인하는 용도로만 쓴다.

---

## 현재 상태 (완료, main 에 머지됨 — 참고용, 수정 대상 아님)
- **PHP 엔진** (`labs/php/`, 상용 MariaDB): BLACKOUT 커뮤니티(게시판·인증·마이페이지·관리자·상점·API·실습실) 구현 완료.
  - 게시판: 최신순 + 번호형 페이지네이션(윈도우+말줄임).
  - 실습실(`/practice`): 공유 카탈로그 `labs/theme/vulns.json` 를 엔진별로 필터링해 렌더. 도식·단계·상세 지원.
  - (기존 보안 동작은 위 동결 규칙에 따라 그대로 둔다.)
- **포털** `labs/nginx/` (:8090), **통합** `labs/compose.yaml`, **랩별 독립 compose** 존재.
- **공유 자산** `labs/theme/`: `style.css`(테마), `vulns.json`(실습 카탈로그), `schema.md`(공통 논리 스키마+시드).
- `labs/django/` 는 **임시 커뮤니티(안전 버전)**.

## 공통 규약 (모든 Task 필수 준수)
0. **🔒 보안/취약점 동결 규칙(위 섹션)이 최우선.** 아래 규약과 충돌하면 동결 규칙을 따른다.
1. **로컬 전용**: 모든 published 포트는 `127.0.0.1` 바인딩. **cloudflared/터널 절대 추가 금지**(사용자가 추후 직접 추가).
2. **상용 DB 사용(SQLite 금지)**: PHP/Node → MariaDB, Django → PostgreSQL. DB 컨테이너는 **호스트에 포트 미노출**(내부 통신 전용), 볼륨 없는 리셋형, healthcheck + depends_on. 부팅 시 스키마+시드 자동 생성(멱등). **신규 스키마 변경은 기존 컬럼/시드를 보존**하며 `ADD COLUMN IF NOT EXISTS` 등 멱등하게.
3. **공유 테마 계승**: `labs/theme/style.css` 를 그대로 서빙하고, base 레이아웃(네비바/푸터/패널)·Bootstrap5 CDN 을 PHP 엔진과 동일한 룩앤필로 재현. 네비바에 엔진 뱃지(`engine-node`/`engine-django`).
4. **공유 실습 카탈로그 읽기 전용**: `/practice` 는 `labs/theme/vulns.json` 을 읽어 렌더한다. **`vulns.json` 에 새 항목을 추가하지 말 것**(취약점 카탈로그는 소유자 관리 영역·동결).
5. **시드/계정 동일**: `labs/theme/schema.md` 의 논리 스키마·시드(users: admin/admin123, testuser/testpass, alice/alice1234, admin role=admin, points 등)를 동일하게 사용.
6. **웹 완성도 우선(토이 금지)**: 평범한 커뮤니티로 자연스럽게 동작하도록 화면·흐름을 완성한다. 앱 화면에 쿼리/페이로드/디버그 문자열을 노출하지 말 것(실습 설명은 `/practice` 가이드가 담당).
7. **문서 품질**: README·가이드는 사람이 그대로 따라 할 수 있도록 단계별로. 구조 설명이 필요한 곳엔 ASCII 도식, 불필요한 곳엔 억지로 넣지 말 것.
8. **검증 필수**: `docker compose -f labs/<engine>/compose.yaml config --quiet` 통과 + `up -d --build` 후 **신규 기능이 동작**하고 **기존 동작이 회귀 없이 보존**됨을 curl 등으로 확인하고 결과를 PR 본문에 기록.
9. **각 랩 README**: `labs/<engine>/README.md` 에 기능·실행법·구조를 정리(보안 설명은 기존 서술 유지, 새로 늘리지 않음).
10. **워크플로**: 작업 브랜치 → 증분 커밋 → `gh pr create` → 머지 → 브랜치 삭제(최종 main 만). 커밋/PR 꼬리말은 저장소 관례를 따름.

---

## Task A — PHP 웹 완성도 고도화 (SSO · 에디터 · 첨부 · 게시판 · 프로필/알림/UX)
대상: `labs/php/`. **기존 보안 동작은 전부 동결**(위 규칙). 아래는 전부 **순수 기능/디자인** 작업이다.

### 프롬프트 (Codex에 전달)
```
BLACKOUT 저장소의 labs/php (PHP 8.4 + MariaDB, 프런트 컨트롤러 src/index.php, 페이지 src/pages/*.php,
공통 src/lib.php) 를 "토이가 아닌 완성도 높은 커뮤니티 웹앱"으로 고도화하라.
docs/codex-handoff.md 의 "🔒 보안/취약점 동결 규칙"과 공통 규약을 반드시 지켜라.

[중요] 너는 보안을 다루지 않는다. 아래 작업은 모두 일반 웹 기능·디자인이다.
기존 취약 동작(로그인 쿼리, 본문/댓글/제목/bio 출력, 파일 업로드 처리, 쿠키 role, JWT, 명령/경로/LFI/SSRF 등)은
"읽기 전용"으로 취급하고, 그 코드 경로를 수정하지 마라. 보안이 좋아지는 변경(이스케이프·바인딩·검증·CSRF·해시 강화 등)을
기존 경로에 추가하지 마라. 신규 코드는 깨끗하고 안전하게 작성하되 기존 경로는 건드리지 마라.
어떤 기능이 기존 취약 경로를 반드시 바꿔야만 된다면, 그 부분은 멈추고 PR 본문에 "동결 충돌"로 남겨라.

1) SSO 로그인 (Google / Kakao / Naver) — 신규 기능, 안전하게 구현:
   - users 테이블에 avatar, oauth_provider, oauth_id 컬럼 추가(lib.php init, ADD COLUMN IF NOT EXISTS).
   - 환경변수로 키 설정: GOOGLE_CLIENT_ID/SECRET, KAKAO_CLIENT_ID/SECRET, NAVER_CLIENT_ID/SECRET,
     OAUTH_REDIRECT_BASE(기본 http://127.0.0.1:8101). compose 에 빈 기본값으로 env 전달.
   - 라우트: /oauth/<provider>/login (CSRF 방지용 state 를 세션에 저장 후 authorize 로 리다이렉트),
     /oauth/<provider>/callback (state 를 반드시 검증 → code 로 토큰 교환 → 프로필 조회 → 로그인/자동가입).
     ※ state 검증은 생략/약화하지 말 것(신규 취약점 금지). 계정 연동은 (provider, oauth_id) 우선, 공급자 검증 이메일로만 보조 매칭.
   - curl 확장 없이 file_get_contents + stream context 로 토큰/프로필 요청.
   - 로그인 페이지에 3개 공급자 버튼(공식 색/아이콘). 키 미설정이면 "키가 설정되지 않았습니다" 안내 후 /login 복귀.
   - 공급자 엔드포인트/스코프:
     Google  authorize https://accounts.google.com/o/oauth2/v2/auth  token https://oauth2.googleapis.com/token
             userinfo https://www.googleapis.com/oauth2/v2/userinfo  scope "openid email profile"
     Kakao   authorize https://kauth.kakao.com/oauth/authorize  token https://kauth.kakao.com/oauth/token
             profile https://kapi.kakao.com/v2/user/me
     Naver   authorize https://nid.naver.com/oauth2.0/authorize  token https://nid.naver.com/oauth2.0/token
             profile https://openapi.naver.com/v1/nid/me
   - 주의: 기존 로컬 로그인(page_login)의 SQLi·쿠키 발급 로직은 절대 수정하지 말 것. SSO 는 별도 함수/파일로 추가.

2) 리치 텍스트 에디터 (UI만):
   - 글쓰기/수정(post/write, post/<id>/edit)에 Quill(CDN) WYSIWYG 적용. 본문을 HTML 로 저장.
   - [동결] 본문 렌더 로직(render_post 의 본문 출력)은 그대로 둔다. 이스케이프를 새로 추가하지 말 것.
     즉 저장/렌더 동작은 기존과 동일하게 유지하고, 작성 화면의 UX만 개선한다.

3) 첨부파일 디자인 강화 (UI만):
   - 다중 첨부(여러 파일), 드래그앤드롭 업로드 UI. files 테이블에 다중 저장(기존 테이블/서버 저장 방식 유지).
   - 글 보기에서 이미지 썸네일/미리보기, 그 외 파일은 확장자 아이콘 + 원본명 + 용량 표시 카드.
   - [동결] 업로드 처리(move_uploaded_file)와 저장 경로/파일명 처리는 그대로 둔다.
     확장자/타입/크기 검증을 새로 추가하지 말 것. 화면 UX만 세련되게.

4) 게시판 강화 (일반 기능):
   - 카테고리(예: 자유/질문/정보) 컬럼 추가 + 필터 탭(신규 필터 쿼리는 화이트리스트/바인딩으로 안전하게),
     대댓글(parent_id) 계층 렌더, 상대시간 표시, 작성자 프로필 링크, 좋아요/조회수 노출, 빈 상태/스켈레톤.
   - [동결] 기존 검색 기능(q 파라미터 쿼리/결과 출력)과 제목·본문·댓글 렌더는 수정하지 말 것.
     새 카테고리/대댓글 쿼리만 깨끗하게 추가한다.

5) 프로필 · 마이페이지 · 알림 · 전반 UX (일반 기능, 토큰 여유만큼 확장):
   - 아바타(SSO avatar/기본 이니셜), 활동 요약(내 글/댓글/좋아요), 가입일·포인트 카드.
   - 간단한 알림/토스트 시스템(댓글·좋아요 등 이벤트), 헤더 알림 뱃지(앱 내 데이터 기반).
   - 반응형(모바일) 레이아웃 점검, 다크모드 토글(테마 변수), 접근성(aria/대비), 로딩 스켈레톤, 공통 빈 상태 컴포넌트.
   - 관리자/상점/마이페이지 화면도 함께 다듬기(표·카드·필터·페이지네이션 일관화). 단 권한/로직 동작은 동결.

검증: docker compose -f labs/php/compose.yaml config --quiet 통과 + up -d --build 후
      (a) 신규 기능(SSO 버튼/흐름·키미설정 안내, 에디터 저장, 다중첨부/썸네일, 카테고리/대댓글, 프로필/알림/다크모드) 동작,
      (b) 기존 동작이 회귀 없이 그대로인지(대표 플로우 몇 개를 변경 전과 동일하게 재현)
      를 curl/브라우저로 확인. labs/php/README.md 의 "기능" 섹션에 신규 기능만 추가(보안 서술은 기존 유지).
      결과와 "동결 규칙 준수(기존 보안 코드 미변경)"를 PR 본문에 명시.
```

---

## Task B — (선택) Node.js 커뮤니티 앱 셸 (Express + MariaDB) · **보안 작업 제외**
대상: `labs/node/` (신규). 포트 **8102**. **이 Task 는 평범한 커뮤니티 앱 "껍데기"만 만든다. 취약점/보안 와이어링은 Codex 범위 밖(소유자가 별도 처리).**

### 프롬프트 (Codex에 전달)
```
BLACKOUT 저장소에 labs/node (Express + EJS + MariaDB[mysql2]) 로 "평범한 커뮤니티 웹앱 셸"을 구현하라.
docs/codex-handoff.md 의 동결 규칙과 공통 규약을 지켜라. 너는 보안/취약점을 구현하지 않는다.
(취약점 주입은 소유자가 나중에 별도로 처리한다. 네가 추가하는 모든 코드는 안전하게 작성하라.)

- 스택: node:22 이미지, express, ejs, express-session, cookie-parser, multer, mysql2.
  DB 는 MariaDB 컨테이너(호스트 미노출). 포트 127.0.0.1:8102:3000. cloudflared 금지.
- 구조: labs/node/{Dockerfile, compose.yaml, package.json, server.js, db.js, views/ 또는 레이아웃 헬퍼, README.md}.
  빌드 컨텍스트는 labs/ (theme/ 공유 자산 COPY). /style.css 와 /practice 의 vulns.json 을 PHP 와 동일하게 서빙(읽기 전용).
- 기능(일반 웹): 게시판(최신순+번호형 페이지네이션)·회원/로그인(세션)·마이페이지·관리자·상점·실습실 뷰(engine='node' 필터 렌더만).
  시드/스키마는 labs/theme/schema.md 와 동일한 '데이터'를 사용(계정/상품/글). DB 접근은 파라미터 바인딩으로 안전하게.
- 웹 완성도(Task A 수준): 게시판 완성도·반응형·공유 룩앤필을 맞춘다.
- 포털(labs/nginx/index.html)과 labs/compose.yaml 에 node(:8102) 서비스/카드 활성화, "준비중" 해제.
- [범위 밖] 취약점(주입·역직렬화·SSTI 등)은 구현하지 말 것. vulns.json 도 수정하지 말 것.

검증: config --quiet + up -d --build 후 일반 기능(가입/로그인/글쓰기/목록/상점/실습실 뷰)이 동작하는지 확인.
labs/node/README.md 작성(기능/실행/구조). PR 본문에 검증 기록 + "보안 와이어링은 범위 밖" 명시.
```

---

## Task C — (선택) Django 커뮤니티 앱 셸 (Django 5 + PostgreSQL) · **보안 작업 제외**
대상: `labs/django/` (현재 임시 안전 커뮤니티 → 완성도 높은 **평범한** 커뮤니티로 정리). 포트 **8103**. **취약점/보안 와이어링은 Codex 범위 밖.**

### 프롬프트 (Codex에 전달)
```
BLACKOUT 저장소의 labs/django 를 "평범한(안전한) BLACKOUT 커뮤니티 앱"으로 완성도 있게 정리하라.
docs/codex-handoff.md 의 동결 규칙과 공통 규약을 지켜라. 너는 보안/취약점을 구현하지 않는다.
(의도적 취약화는 소유자가 나중에 별도로 처리한다.)

- 스택: python:3.12-slim, Django 5, psycopg(PostgreSQL). DB 컨테이너 호스트 미노출. 포트 127.0.0.1:8103:8000.
  cloudflared 금지. 볼륨 없는 리셋형 + healthcheck. Django 기본 보안 설정은 그대로 둔다(일부러 약화 금지).
- 기능(일반 웹): 게시판(최신순+번호형 페이지네이션)·회원/로그인·마이페이지·관리자·상점·실습실 뷰(engine='django' 필터 렌더만).
  시드/스키마는 labs/theme/schema.md 의 '데이터'를 사용. ORM/파라미터 바인딩으로 안전하게.
  공유 theme/style.css·vulns.json 서빙(읽기 전용), 룩앤필 동일.
- 웹 완성도(Task A 수준): 게시판 완성도·반응형·공유 룩앤필.
- 포털/통합 compose 에 django(:8103) 서비스/카드 활성화, "준비중" 해제.
- [범위 밖] 취약점(raw SQLi·pickle·SSTI·설정 약화 등)은 구현하지 말 것. vulns.json 도 수정하지 말 것.

검증: config --quiet + up -d --build 후 일반 기능이 동작하는지 확인.
labs/django/README.md 작성. PR 본문에 검증 기록 + "보안 와이어링은 범위 밖" 명시.
```

---

## 참고 파일 (레퍼런스 · 읽기용, 보안 코드는 동결)
- 레이아웃/헬퍼/DB: `labs/php/src/lib.php`
- 라우팅: `labs/php/src/index.php`
- 페이지: `labs/php/src/pages/{board,auth,tools,admin,shop,api,phpvuln,practice}.php`
- JWT: `labs/php/src/jwt.php`
- 실습실 렌더(도식/단계/엔진필터): `labs/php/src/pages/practice.php`
- 공유 카탈로그/스키마/테마: `labs/theme/{vulns.json, schema.md, style.css}`

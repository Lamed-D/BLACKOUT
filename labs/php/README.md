# BLACKOUT — PHP 엔진 (로컬 전용 · 의도적 취약)

PHP 8.4 + MariaDB 로 구현한 BLACKOUT 커뮤니티. 공유 테마/취약점 카탈로그(`../theme/`)를 사용합니다.

> ⚠️ 의도적으로 취약합니다. **로컬에서만** 구동하세요(터널 없음).

## 실행
```sh
docker compose -f labs/php/compose.yaml up -d --build   # http://127.0.0.1:8101
```
- DB: MariaDB 컨테이너(호스트 미노출, 볼륨 없는 리셋형). 부팅 시 스키마+시드 자동 생성.
- 시드 계정: `admin/admin123`, `testuser/testpass`, `alice/alice1234`
- 실습실(취약점 가이드): http://127.0.0.1:8101/practice

## 기능
게시판(최신순 + `1 2 3 … N` 페이지네이션 · 조회/댓글수) · 회원/로그인 · 마이페이지 ·
관리자 대시보드 · 포인트 상점 · 파일 업로드/다운로드 · `/api/*` · 실습실.

## 취약점 (PHP 실습실 43종 = 공통 39 + PHP 전용 4)
공통 카탈로그는 [`../theme/vulns.json`](../theme/vulns.json) 참고(SQLi·XSS·인증/권한·파일/명령·고급/로직).
프로토타입 오염은 Node 전용이라 PHP 실습실에는 표시되지 않습니다.

### PHP 전용 취약점
| # | 취약점 | 경로 | 요점 |
| --- | --- | --- | --- |
| 41 | 타입 저글링 — 매직 해시 | `/verify_token` | 느슨한 비교(`==`)에서 `0e[숫자]` 해시가 0으로 취급 → 매직 해시(`240610708`)로 우회 |
| 42 | `extract()` 변수 주입 | `/settings?is_admin=1` | 사용자 입력을 `extract()` 로 전개해 권한 플래그 변수 덮어쓰기 |
| 43 | 변수 함수 (Dynamic Call) | `/api/call?fn=system&arg=id` | 사용자 입력을 함수명으로 호출(`$fn()`) → 임의 PHP 함수 실행(RCE) |
| 44 | `phpinfo()` 정보 노출 | `/phpinfo` | 운영 환경 진단 페이지 노출로 경로·확장·환경변수 유출 |

### 대표 공통 취약점 빠른 확인
- SQLi 로그인 우회: 아이디 `admin'-- `
- SQLi UNION: `/?q=x' UNION SELECT username, password, role FROM users-- `
- 스키마 열거: `/?q=x' UNION SELECT table_name, table_schema, '3' FROM information_schema.tables WHERE table_schema=database()-- `
- 경로 탐색: `/download?file=../lib.php` · LFI: `/theme?name=php://filter/convert.base64-encode/resource=lib.php`
- 명령 주입: `/ping` 에 `127.0.0.1; id` · 관리자 우회: 쿠키 `role=admin` 으로 `/admin`
- 웹셸 업로드: 글쓰기 첨부로 `shell.php` 업로드 → `/uploads/shell.php?c=id`

## 구조
```
labs/php/
├─ Dockerfile  compose.yaml  README.md
└─ src/
   ├─ index.php        ← 프런트 컨트롤러(라우팅)
   ├─ lib.php          ← DB(MariaDB)·시드·헬퍼·레이아웃
   ├─ jwt.php          ← 약한 JWT(HS256/alg=none)
   └─ pages/           ← board·auth·tools·admin·shop·api·practice·phpvuln
```

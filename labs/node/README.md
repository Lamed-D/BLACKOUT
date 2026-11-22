# BLACKOUT Node 커뮤니티 (취약 실습 엔진)

Express + EJS + MariaDB로 만든 **의도적으로 취약한** 로컬 커뮤니티 앱입니다. PHP 엔진과 동등하게 공유 카탈로그(`theme/vulns.json`)의 Node 노출 40종 취약점이 실제로 동작하며, 실습실(`/practice`)에서 각 항목의 목표·단계·페이로드·대응책을 확인할 수 있습니다.

> ⚠️ 모든 취약점은 학습 목적의 의도적 결함입니다. **로컬에서만** 구동하세요(터널 금지).

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
5. 실습실은 `theme/vulns.json` 중 공통과 Node 항목(40종)을 표시합니다. 카탈로그 내용은 변경하지 않습니다.
6. 로그인 화면의 **간편 로그인(Google/Kakao/Naver SSO)** 으로 소셜 계정 로그인이 가능합니다.

## 취약점 표면(요약)

SQLi(인증우회·UNION·스키마열거·블라인드), XSS(반사·저장·DOM·UA저장형), 약한 MD5 해시, JWT 약한시크릿·alg=none,
쿠키 권한상승, 세션 고정, 사용자 열거, Mass Assignment, IDOR, 수평/수직 권한상승, 오픈 리다이렉트, Host 헤더 인젝션,
무제한 업로드(HTML/SVG 저장형 XSS), 경로 탐색/LFI, OS 명령주입, SSRF, XXE, 안전하지 않은 역직렬화, SSTI, CORS,
상점 가격/수량 조작, 경쟁 조건, 클릭재킹, 보안헤더 누락, 디버그 노출, CSV 수식 인젝션, **프로토타입 오염(Node 전용)**.

- 취약 로직은 `server.js`, 약한 JWT는 `jwt.js`, 고급(XXE/역직렬화/프로토타입오염/SSTI)은 `advuln.js`.
- 각 항목의 공격 경로/페이로드는 `/practice` 참고.

## 소셜 로그인(SSO)

상용 웹처럼 OAuth 2.0 기반의 소셜 로그인을 지원합니다(PHP 엔진과 동일한 흐름). 공급자 키는 환경변수로 주입합니다.

| 환경변수 | 설명 |
| --- | --- |
| `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` | Google OAuth 클라이언트 |
| `KAKAO_CLIENT_ID` / `KAKAO_CLIENT_SECRET` | Kakao OAuth 클라이언트 |
| `NAVER_CLIENT_ID` / `NAVER_CLIENT_SECRET` | Naver OAuth 클라이언트 |
| `NODE_OAUTH_REDIRECT_BASE` | 콜백 베이스 URL(기본 `http://127.0.0.1:8102`) |

- 콜백 경로: `/oauth/{google|kakao|naver}/callback`
- 키가 없으면 버튼을 눌러도 안내 후 로그인 화면으로 돌아오며, 로컬 로그인에는 영향이 없습니다.
- CSRF 방지를 위해 `state` 파라미터를 세션에 저장·검증하고, 로그인 성공 시 세션을 재생성합니다.
- 공급자 ID(`oauth_provider`+`oauth_id`)와 **검증된 이메일**로만 기존 계정을 연결합니다(Naver는 검증 이메일 미표시로 매칭 제외).
- 엔드포인트는 `<PREFIX>_AUTHORIZE_URL`/`_TOKEN_URL`/`_PROFILE_URL` 환경변수로 덮어쓸 수 있습니다(미설정 시 실제 공급자 URL). 자체호스팅·로컬 테스트용.

### E2E (로컬 Mock IdP)

실제 공급자 키 없이 `code→token→profile→세션` 전 구간을 돌려볼 수 있는 Mock OAuth 공급자(`mock-oauth/`, 의존성 없음)를 제공합니다.

```sh
docker compose -f labs/node/compose.yaml -f labs/node/compose.e2e.yaml up -d --build
# 브라우저(또는 curl -L)로 http://127.0.0.1:8102/oauth/google/login 접속 → 콜백까지 왕복 후 로그인됨
docker compose -f labs/node/compose.yaml -f labs/node/compose.e2e.yaml down   # 정리
```

- `authorize` URL은 호스트에서 접근(브라우저가 따라감), `token`/`profile` URL은 컨테이너 내부(`mock:9000`)로 분리.
- google/kakao/naver 세 공급자 형태를 모두 모방하므로 공급자별 프로필 파싱까지 검증됩니다.

## 구조

```text
labs/node/
├─ compose.yaml       Node와 내부 MariaDB
├─ Dockerfile         labs/를 빌드 컨텍스트로 사용
├─ db.js              스키마·시드(약한 MD5)와 DB 연결(SSO 컬럼 포함)
├─ jwt.js             약한 JWT(시크릿='secret', alg=none 허용)
├─ advuln.js          XXE·역직렬화·프로토타입오염·SSTI 헬퍼
├─ oauth.js           Google/Kakao/Naver SSO 라우터
├─ server.js          취약 라우트·세션·업로드
└─ views/             EJS 화면(header/footer/page/message/practice)
```

`/style.css`는 빌드할 때 `labs/theme/style.css`를 그대로 복사합니다.

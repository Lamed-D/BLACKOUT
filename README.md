# BLACKOUT — 웹 보안 실습 플랫폼

> ⚠️ **교육용 · 의도적 취약 환경.** 모든 구성요소는 웹 취약점 학습을 위해 **일부러 취약하게** 만들어졌습니다.
> 반드시 **본인의 로컬 환경에서만** 구동하세요. 공개망 노출은 금지합니다.

BLACKOUT은 회원·게시판·관리자 기능을 갖춘 커뮤니티를 토대로 웹 취약점을 실습하는 모의해킹 랩입니다.
두 축으로 구성됩니다.

| 구분 | 설명 | 스택 | 위치 |
| --- | --- | --- | --- |
| **원본 랩** | 최초 구현. 커뮤니티 + 24종 취약점 + 실습실 | Flask · MariaDB · Apache | [`blackout_lab/`](blackout_lab/) |
| **멀티엔진 랩** | 같은 UI를 백엔드 엔진별로 재현(비교 학습), 40종 취약점 | PHP · Node.js · Django · MariaDB/PostgreSQL | [`labs/`](labs/) |

## 빠른 시작 (멀티엔진 랩 · 로컬 전용 권장)

```sh
# 포털 + 가용 엔진 일괄 기동 (cloudflared/터널 없음)
docker compose -f labs/compose.yaml up -d --build
```
- 포털: http://127.0.0.1:8090 → 엔진 선택
- PHP 엔진: http://127.0.0.1:8101 · Node 엔진: http://127.0.0.1:8102 (Django :8103 준비중)
- 시드 계정: `admin/admin123`, `testuser/testpass`, `alice/alice1234`
- 자세한 내용: [`labs/README.md`](labs/README.md)

> 원본 `blackout_lab/` 의 Compose 에는 Cloudflare 터널 설정이 포함되어 있습니다.
> 로컬 학습에는 **터널이 없는 `labs/` 환경 사용을 권장**합니다.

## 저장소 구조
```
BLACKOUT/
├─ README.md            ← 이 문서
├─ AGENTS.md            ← 개발 절차(브랜치/검증/PR)
├─ compose.yaml         ← 원본 랩 통합 include
├─ blackout_lab/        ← 원본(Flask) 랩
├─ labs/                ← 멀티엔진 랩(nginx 포털 + PHP/Node/Django + 공유 theme)
│  ├─ nginx/  theme/  php/  node/  django/   (각 랩 독립 compose + 통합 compose.yaml)
│  └─ README.md
├─ docs/                ← 기획·운영 문서
│  ├─ 개발계획서.md  요구사항명세서.docx
│  ├─ implementation_plan.md  run.md  가이드.md
└─ code_review/         ← 코드 리뷰/발표 자료(PDF)
```

## 학습 범위 (40종 취약점)
SQL Injection(인증우회·UNION·스키마열거·블라인드/2차), XSS(반사·저장·DOM·UA저장형),
인증·권한(약한 MD5, JWT 약한시크릿·alg=none, 쿠키 권한상승, 세션 고정, 사용자 열거, Mass Assignment,
IDOR, 수평/수직 권한상승, 오픈 리다이렉트, Host 헤더 포이즈닝), 파일·명령(무제한 업로드·웹셸,
HTML/SVG 업로드, 경로 탐색, LFI, OS 명령 주입), 고급·로직(SSRF, XXE, 역직렬화, SSTI, CORS,
상점 가격/수량 조작, 경쟁 조건, 클릭재킹, 보안 설정/헤더 오류, CSV 수식 인젝션, 민감정보 하드코딩,
프로토타입 오염). 각 엔진의 **실습실(`/practice`)**에서 목표·단계·페이로드·대응책을 제공합니다.

## 문서
- [개발계획서](docs/개발계획서.md) · [요구사항명세서](docs/요구사항명세서.docx)
- [배포 구성](docs/implementation_plan.md) · [실행 안내](docs/run.md) · [실습 가이드](docs/가이드.md)

---
교육·연구 목적 외 사용을 금합니다. 취약점은 반드시 본인이 구동한 로컬 랩에서만 실습하세요.

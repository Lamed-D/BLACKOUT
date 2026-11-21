# BLACKOUT 멀티엔진 웹해킹 실습 랩 (로컬 전용)

`blackout_lab`(Flask 원본)의 UI/경험을 **계승**한 하나의 통합 모의해킹 실습 환경을,
**백엔드 엔진별(PHP · Node.js · Django)**로 구현해 같은 취약점이 스택마다 어떻게 나타나는지 비교 학습합니다.

> ⚠️ 모든 엔진은 **의도적으로 취약**합니다. **로컬에서만** 구동하세요. cloudflared/터널은 포함하지 않습니다(필요 시 사용자가 직접 추가).

## 구성
| 구성요소 | 스택 | 접속(로컬) | 상태 |
| --- | --- | --- | --- |
| 포털 | nginx 정적 | http://127.0.0.1:8090 | ✅ |
| PHP 엔진 | PHP 8.4 + SQLite(PDO) | http://127.0.0.1:8101 | ✅ |
| Node 엔진 | Express + EJS + SQLite | http://127.0.0.1:8102 | 🚧 준비중 |
| Django 엔진 | Django 5 + SQLite | http://127.0.0.1:8103 | 🚧 준비중 |

## 실행
```sh
# 전체(포털 + 가용 엔진) 일괄 기동
docker compose -f labs/compose.yaml up -d --build

# 개별 엔진만
docker compose -f labs/php/compose.yaml up -d --build      # PHP  :8101
```
종료: 위 명령의 `up -d --build` 를 `down` 으로 바꿔 실행.

## 시드 계정
| 아이디 | 비밀번호 | 권한 |
| --- | --- | --- |
| admin | admin123 | admin |
| testuser | testpass | user |
| alice | alice1234 | user |

DB는 SQLite이며, 각 엔진이 부팅 시 스키마+시드를 자동 생성합니다(멱등). 시드된 기본 DB도 저장소에 포함됩니다.

## 공유 자산 (`theme/`)
- `theme/style.css` — 모든 엔진 공통 테마(blackout 계승)
- `theme/vulns.json` — 실습실(`/practice`)의 **단일 취약점 카탈로그**(40종). 항목 추가는 이 파일만 수정하면 전 엔진에 반영.
- `theme/schema.md` — 공통 논리 스키마 + 시드 정의

## 취약점 카탈로그 (40종)
SQLi(인증우회/UNION/스키마열거/블라인드·2차), XSS(반사/저장/DOM/UA저장형), 인증·권한(약한 MD5 해시,
JWT 약한시크릿·alg=none, 쿠키 권한상승, 세션 고정, 쿠키속성 누락, 사용자 열거·브루트포스, Mass Assignment,
IDOR, 수평/수직 권한상승, 오픈 리다이렉트, Host 헤더 포이즈닝), 파일·명령(무제한 업로드/웹셸, HTML·SVG 업로드,
경로 탐색, LFI, OS 명령 주입), 고급·로직(SSRF, XXE, 역직렬화, SSTI, CORS, 상점 가격/수량 조작, 경쟁조건,
클릭재킹, 보안헤더·설정 오류, CSV 수식 인젝션, 민감정보 하드코딩, 프로토타입 오염[Node]).

각 항목의 목표·단계·페이로드·대응책은 각 엔진의 **실습실(`/practice`)**에서 확인하세요.

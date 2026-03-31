# 도커 기반 웹 서버 및 인프라 구축 계획 (Windows 환경)

이 계획은 `개발계획서.md`의 **[Phase 1]** 목표와 `가이드.md`의 **[방법 1: Docker를 활용한 배포]** 섹션을 바탕으로, 현재 Windows에 위치한 `blackout_lab` 프로젝트를 도커 생태계(Apache2 리버스 프록시 + Gunicorn + MariaDB)로 이관하는 작업입니다.

`가이드.md`에 제시된 [방법 2: 로컬 계정 배포]는 리눅스 사용자(User Privileges)를 전제로 한 구성이므로, 윈도우 환경에서는 개발계획서의 근원적 목표에 부합하는 [방법 1: 도커 기반 배포]를 따르는 것이 가장 적합하고 표준적인 순서입니다.

## User Review Required

> [!CAUTION]
> **현재 Windows 개발 환경에 Docker가 설치되어 있지 않습니다.**
> 이 계획을 실제로 구동(Run)하려면, 사용자가 직접 [Docker Desktop for Windows](https://www.docker.com/products/docker-desktop/)를 다운로드하여 윈도우에 설치 및 실행해 주셔야 합니다. 도커 데스크탑이 켜져 있어야만 제가 `docker-compose` 명령어를 수행할 수 있습니다.

## Proposed Changes

현재 `blackout_lab`의 파일 구조를 유지하면서, 다음의 Docker 구성 파일들을 상위 디렉토리(또는 `blackout_lab` 내부)에 생성 및 수정합니다.

### 🐳 도커 인프라 환경 구성 (Infrastructure)

#### [NEW] `blackout_lab/Dockerfile`
Flask 애플리케이션을 구동할 `gunicorn` 프로세스를 실행하기 위한 파이썬 기반 컨테이너 빌드 명세서입니다.

#### [NEW] `blackout_lab/my-site.conf`
`Apache2` 리버스 프록시 서버 설정을 담당합니다. 80번 포트(외부)로 들어온 브라우저 요청을 내부망의 `flask-app` 컨테이너의 `5000`번 포트로 포워딩합니다. 모듈 활성화 등 도커 공식 `httpd` 연동에 맞춰 프록시 룰을 설정합니다.

#### [NEW] `blackout_lab/docker-compose.yml`
개발계획서에 명시된 인프라(DB분리 및 웹서버 연동)를 반영하여 동시에 세 가지 컨테이너를 하나로 묶어 오케스트레이션합니다.
1. **`flask-app`**: 백엔드 엔진 (Dockerfile 기반 커스텀 빌드)
2. **`apache-server`**: 리버스 프록시 역할을 수행할 웹 서버 엔진본체 (`httpd` 이미지 활용)
3. **`mariadb`**: 개발계획서 2.2항의 "DB 분리" 요구사항을 충족하기 위한 데이터베이스 (향후 Phase 2에서 SQLite 대신 연동됨)

### 🐍 애플리케이션 의존성 업데이트

#### [MODIFY] `blackout_lab/requirements.txt`
도커 컨테이너 구동과 향후 데이터베이스 연동을 위한 파이썬 필수 의존성을 추가합니다.
- 추가 라이브러리: `gunicorn` (WSGI 서버), `pymysql` 또는 `mysqlclient` (MariaDB 연동)

## Open Questions

> [!IMPORTANT]
> 1. 지금 당장 위 구조에 맞춰 관련 파일(`Dockerfile`, `docker-compose.yml` 등)을 코딩하여 세팅해 둘까요?
> 2. 실제 서버 실행을 위해 **Docker Desktop 앱**을 직접 설치해주실 수 있으신가요? (설치가 완료되고 도커가 켜져야 테스트 단계로 넘어갈 수 있습니다.)

## Verification Plan

### Automated Tests
- 컨테이너 빌드 준비 확인: `docker-compose config` 명령어를 통해 작성된 파일들의 문법 검증

### Manual Verification
- 도커 데스크탑 실행 후, 브라우저에서 `http://localhost` (또는 설정된 포트)로 접속하여 기존 SQLite로 작동하던 `blackout_lab` 화면이 Apache2를 거쳐 정상적으로 뜨는지 최종 확인.

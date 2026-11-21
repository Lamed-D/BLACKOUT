# 개발 절차
기본은 단독 에이전트 모드입니다. 작업별 브랜치를 사용하고 변경 전 git status를 확인합니다.
Compose 구성은 docker compose config --quiet 및 docker compose --profile board config --quiet 로 검증합니다.
PR 요청 시 검증한 변경을 커밋하고 브랜치를 푸시한 다음 gh pr create로 PR을 생성합니다. 검증 결과를 본문에 기록합니다.

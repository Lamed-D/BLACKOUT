# 1. Python 3.9 slim 베이스 이미지
FROM python:3.9-slim

# 2. 작업 디렉토리 설정
WORKDIR /blackout_lab

# 3. 시스템 패키지 업데이트 (MariaDB 클라이언트 라이브러리 포함)
RUN apt-get update && apt-get install -y \
    default-libmysqlclient-dev \
    gcc \
    pkg-config \
    && rm -rf /var/lib/apt/lists/*

# 4. requirements.txt를 먼저 복사하여 의존성 레이어 캐싱 최적화
COPY requirements.txt .

# 5. 파이썬 라이브러리 설치
RUN pip install --no-cache-dir -r requirements.txt

# 6. 나머지 앱 코드 전체 복사
COPY . .

# 7. uploads 디렉토리 생성 (컨테이너 내부에서 파일 업로드 경로 보장)
RUN mkdir -p uploads

# 8. Gunicorn WSGI 서버로 실행
#    run.py 안의 app 객체를 진입점으로 사용
#    0.0.0.0:5000 으로 바인딩하여 도커 내부 네트워크에서 접근 가능하게 함
CMD ["gunicorn", "--workers", "4", "--bind", "0.0.0.0:5000", "run:app"]

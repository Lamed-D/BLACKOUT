FROM python:3.12-slim
WORKDIR /app
RUN pip install --no-cache-dir "Django>=5.2,<5.3" "psycopg[binary]>=3.2,<4" "gunicorn>=23,<27"
ENV PYTHONPATH=/app DJANGO_SETTINGS_MODULE=settings PYTHONUNBUFFERED=1
COPY django/ ./
COPY theme/style.css ./public/style.css
COPY theme/vulns.json ./data/vulns.json
COPY theme/exploits.json ./data/exploits.json
COPY theme/applicability.json ./data/applicability.json
COPY theme/approaches.json ./data/approaches.json
COPY theme/exploits/ ./data/exploits/
CMD ["sh","-ec","umask 077; python -c 'import secrets; print(secrets.token_urlsafe(64))' > /tmp/blackout-django-secret; django-admin migrate --noinput; django-admin seed_community; exec gunicorn --bind 0.0.0.0:8000 --access-logfile - wsgi:application"]

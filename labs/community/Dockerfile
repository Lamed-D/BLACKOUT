FROM python:3.12-slim
WORKDIR /app
RUN pip install --no-cache-dir "Django>=5.2,<5.3" "psycopg[binary]>=3.2,<4" "gunicorn>=23,<27"
ENV PYTHONPATH=/app DJANGO_SETTINGS_MODULE=settings PYTHONUNBUFFERED=1
COPY . .
CMD ["sh","-ec","umask 077; test -s /keys/secret || python -c 'import secrets; print(secrets.token_urlsafe(64))' > /keys/secret; django-admin migrate --noinput && exec gunicorn --bind 0.0.0.0:8000 --access-logfile - wsgi:application"]

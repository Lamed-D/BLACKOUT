"""
소셜 OAuth 로그인 라우트 (Google / Naver / Kakao)
────────────────────────────────────────────────────────────
환경변수 설정 (docker-compose.yml 의 environment 또는 .env 파일):
  GOOGLE_CLIENT_ID      : Google Cloud Console → OAuth 2.0 클라이언트 ID
  GOOGLE_CLIENT_SECRET  : Google Cloud Console → OAuth 2.0 클라이언트 시크릿
  NAVER_CLIENT_ID       : https://developers.naver.com/apps/
  NAVER_CLIENT_SECRET   : 위와 동일
  KAKAO_CLIENT_ID       : https://developers.kakao.com/ → REST API 키
  KAKAO_CLIENT_SECRET   : 카카오 앱 → 카카오 로그인 → 보안 → Client Secret

리다이렉트 URI 등록:
  Google  → 승인된 리다이렉트: http://localhost/login/google/authorized
  Naver   → Callback URL    : http://localhost/oauth/naver/callback
  Kakao   → Redirect URI    : http://localhost/oauth/kakao/callback
────────────────────────────────────────────────────────────
"""

import os, secrets
from flask import Blueprint, redirect, url_for, session, flash, request, make_response
from flask_dance.contrib.google import make_google_blueprint, google
from app.database import get_db_connection
import requests as http_requests

bp = Blueprint('oauth', __name__)

# ══════════════════════════════════════════════════════════
# 1. Google OAuth (Flask-Dance)
# ══════════════════════════════════════════════════════════
google_bp = make_google_blueprint(
    client_id=os.environ.get('GOOGLE_CLIENT_ID', ''),
    client_secret=os.environ.get('GOOGLE_CLIENT_SECRET', ''),
    scope=['openid', 'email', 'profile'],
    redirect_to='oauth.google_callback', # 인증 성공 후 이동할 엔드포인트
)


@bp.route('/oauth/google/callback')
def google_callback():
    if not google.authorized:
        flash('Google 로그인이 취소되었습니다.', 'warning')
        return redirect(url_for('auth.login'))
    resp = google.get('/oauth2/v2/userinfo')
    if not resp.ok:
        flash('Google 사용자 정보를 가져오지 못했습니다.', 'danger')
        return redirect(url_for('auth.login'))
    info = resp.json()
    return _oauth_login_or_register(
        provider='google',
        oauth_id=info['id'],
        username=f"google_{info['id']}",
        name=info.get('name', ''),
        email=info.get('email', ''),
    )


# ══════════════════════════════════════════════════════════
# 2. Naver OAuth
# ══════════════════════════════════════════════════════════
NAVER_AUTH_URL    = 'https://nid.naver.com/oauth2.0/authorize'
NAVER_TOKEN_URL   = 'https://nid.naver.com/oauth2.0/token'
NAVER_PROFILE_URL = 'https://openapi.naver.com/v1/nid/me'


@bp.route('/oauth/naver/login')
def naver_login():
    client_id = os.environ.get('NAVER_CLIENT_ID', '')
    if not client_id:
        flash('네이버 API 키(Client ID)가 설정되지 않았습니다. .env 파일을 확인해주세요.', 'warning')
        return redirect(url_for('auth.login'))

    state = secrets.token_urlsafe(16)
    session['naver_oauth_state'] = state
    params = {
        'response_type': 'code',
        'client_id':     os.environ.get('NAVER_CLIENT_ID', ''),
        'redirect_uri':  url_for('oauth.naver_callback', _external=True),
        'state':         state,
    }
    query = '&'.join(f'{k}={v}' for k, v in params.items())
    return redirect(f'{NAVER_AUTH_URL}?{query}')


@bp.route('/oauth/naver/callback')
def naver_callback():
    code  = request.args.get('code')
    state = request.args.get('state')
    if state != session.pop('naver_oauth_state', None):
        flash('상태값 검증 실패. 다시 시도해 주세요.', 'danger')
        return redirect(url_for('auth.login'))

    token_resp = http_requests.get(NAVER_TOKEN_URL, params={
        'grant_type':    'authorization_code',
        'client_id':     os.environ.get('NAVER_CLIENT_ID', ''),
        'client_secret': os.environ.get('NAVER_CLIENT_SECRET', ''),
        'code': code, 'state': state,
    })
    access_token = token_resp.json().get('access_token')
    if not access_token:
        flash('네이버 토큰 교환에 실패했습니다.', 'danger')
        return redirect(url_for('auth.login'))

    profile = http_requests.get(
        NAVER_PROFILE_URL,
        headers={'Authorization': f'Bearer {access_token}'},
    ).json().get('response', {})

    naver_id = profile.get('id', '')
    email    = profile.get('email', '')
    name     = profile.get('name', f'naver_{naver_id}')
    return _oauth_login_or_register(
        provider='naver',
        oauth_id=naver_id,
        username=f"naver_{naver_id}",
        name=name, email=email,
    )


# ══════════════════════════════════════════════════════════
# 3. Kakao OAuth
# ══════════════════════════════════════════════════════════
KAKAO_AUTH_URL    = 'https://kauth.kakao.com/oauth/authorize'
KAKAO_TOKEN_URL   = 'https://kauth.kakao.com/oauth/token'
KAKAO_PROFILE_URL = 'https://kapi.kakao.com/v2/user/me'


@bp.route('/oauth/kakao/login')
def kakao_login():
    client_id = os.environ.get('KAKAO_CLIENT_ID', '')
    if not client_id:
        flash('카카오 API 키(REST API 키)가 설정되지 않았습니다. .env 파일을 확인해주세요.', 'warning')
        return redirect(url_for('auth.login'))

    state = secrets.token_urlsafe(16)
    session['kakao_oauth_state'] = state
    params = {
        'client_id':     os.environ.get('KAKAO_CLIENT_ID', ''),
        'redirect_uri':  url_for('oauth.kakao_callback', _external=True),
        'response_type': 'code',
        'state':         state,
    }
    query = '&'.join(f'{k}={v}' for k, v in params.items())
    return redirect(f'{KAKAO_AUTH_URL}?{query}')


@bp.route('/oauth/kakao/callback')
def kakao_callback():
    code  = request.args.get('code')
    state = request.args.get('state')
    if state != session.pop('kakao_oauth_state', None):
        flash('상태값 검증 실패. 다시 시도해 주세요.', 'danger')
        return redirect(url_for('auth.login'))

    token_resp = http_requests.post(KAKAO_TOKEN_URL, data={
        'grant_type':    'authorization_code',
        'client_id':     os.environ.get('KAKAO_CLIENT_ID', ''),
        'client_secret': os.environ.get('KAKAO_CLIENT_SECRET', ''),
        'redirect_uri':  url_for('oauth.kakao_callback', _external=True),
        'code':          code,
    })
    access_token = token_resp.json().get('access_token')
    if not access_token:
        flash('카카오 토큰 교환에 실패했습니다.', 'danger')
        return redirect(url_for('auth.login'))

    profile_resp = http_requests.get(
        KAKAO_PROFILE_URL,
        headers={'Authorization': f'Bearer {access_token}'},
    ).json()

    kakao_id     = str(profile_resp.get('id', ''))
    kakao_acct   = profile_resp.get('kakao_account', {})
    email        = kakao_acct.get('email', '')
    profile      = kakao_acct.get('profile', {})
    name         = profile.get('nickname', f'kakao_{kakao_id}')

    return _oauth_login_or_register(
        provider='kakao',
        oauth_id=kakao_id,
        username=f"kakao_{kakao_id}",
        name=name, email=email,
    )


# ══════════════════════════════════════════════════════════
# 공통 헬퍼: DB 조회 → 없으면 자동 회원가입 → 세션 설정
# ══════════════════════════════════════════════════════════
def _oauth_login_or_register(provider, oauth_id, username, name, email):
    conn = get_db_connection()
    cur  = conn.cursor()

    # 1) 이미 소셜 계정으로 가입한 적 있는지 확인
    cur.execute("SELECT * FROM users WHERE oauth_provider=%s AND oauth_id=%s", (provider, oauth_id))
    user = cur.fetchone()

    if not user and email:
        # 2) 같은 이메일로 일반 가입된 계정이 있으면 소셜 연동 처리
        cur.execute("SELECT * FROM users WHERE email=%s", (email,))
        user = cur.fetchone()
        if user:
            cur.execute(
                "UPDATE users SET oauth_provider=%s, oauth_id=%s WHERE id=%s",
                (provider, oauth_id, user['id'])
            )
            conn.commit()

    if not user:
        # 3) 처음 이용 → username 중복 처리 후 자동 회원가입
        base, suffix = username, 1
        while True:
            cur.execute("SELECT id FROM users WHERE username=%s", (username,))
            if not cur.fetchone():
                break
            username = f"{base}_{suffix}"; suffix += 1

        cur.execute(
            "INSERT INTO users (username, password, name, email, role, last_ip, oauth_provider, oauth_id) "
            "VALUES (%s, '', %s, %s, 'user', %s, %s, %s)",
            (username, name, email, request.remote_addr, provider, oauth_id)
        )
        conn.commit()
        cur.execute("SELECT * FROM users WHERE oauth_provider=%s AND oauth_id=%s", (provider, oauth_id))
        user = cur.fetchone()

    # 4) 세션 + 쿠키 설정
    session['user']           = user['username']
    session['user_id']        = user['id']
    session['role']           = user['role']
    session['oauth_provider'] = provider  # UI에서 아이콘 표시용

    cur.execute(
        "INSERT INTO login_logs (user_id, username, ip_address, user_agent) VALUES (%s,%s,%s,%s)",
        (user['id'], user['username'], request.remote_addr, str(request.user_agent))
    )
    conn.commit(); cur.close(); conn.close()

    resp = make_response(redirect(url_for('board.index')))
    resp.set_cookie('role', user['role'])
    return resp

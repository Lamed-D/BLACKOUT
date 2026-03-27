from flask import (Blueprint, render_template, request, redirect,
                   url_for, session, make_response, current_app)
from app.database import get_db_connection
import hashlib, jwt

bp = Blueprint('auth', __name__)

def log_telemetry(tag, message):
    entries = session.get('telemetry', [])
    session['telemetry'] = entries[-19:] + [{'tag': tag, 'msg': message}]
    session.modified = True

def md5(s): return hashlib.md5(s.encode()).hexdigest()


# ── Login ─────────────────────────────────────────────────────
@bp.route('/login', methods=['GET', 'POST'])
def login():
    if request.method == 'POST':
        username = request.form.get('username', '')
        password = request.form.get('password', '')
        hashed   = md5(password)                          # [VULN] MD5 no-salt

        conn = get_db_connection()
        # [VULNERABILITY] SQL Injection — username not parameterised
        query = f"SELECT * FROM users WHERE username = '{username}' AND password = '{hashed}'"
        log_telemetry('SQL', query)
        try:
            user = conn.execute(query).fetchone()
            conn.close()
        except Exception as e:
            conn.close()
            return f"<h2>DB Error</h2><pre>{e}</pre><p>Query: {query}</p>"

        if user:
            session['user']    = user['username']
            session['user_id'] = user['id']
            session['role']    = user['role']

            payload = {'username': user['username'], 'role': user['role']}
            token   = jwt.encode(payload, current_app.config['SECRET_KEY'], algorithm='HS256')

            conn2 = get_db_connection()
            conn2.execute("INSERT INTO login_logs (user_id, username, ip_address, user_agent) VALUES (?,?,?,?)",
                          (user['id'], user['username'], request.remote_addr, str(request.user_agent)))
            conn2.execute("UPDATE users SET last_ip=? WHERE id=?", (request.remote_addr, user['id']))
            conn2.commit()
            conn2.close()

            resp = make_response(redirect(url_for('board.index')))
            resp.set_cookie('role',      user['role'])     # [VULN] Cookie manipulation
            resp.set_cookie('jwt_token', token)            # [VULN] Weak secret
            return resp
        else:
            return render_template('login.html', error='아이디 또는 비밀번호가 틀렸습니다.', query=query)

    return render_template('login.html')


# ── Register ──────────────────────────────────────────────────
@bp.route('/register', methods=['GET', 'POST'])
def register():
    if request.method == 'POST':
        username = request.form.get('username', '').strip()
        name     = request.form.get('name', '').strip()
        email    = request.form.get('email', '').strip()
        password = request.form.get('password', '')
        confirm  = request.form.get('confirm', '')

        if not username or not password:
            return render_template('register.html', error='아이디와 비밀번호를 입력해 주세요.')
        if password != confirm:
            return render_template('register.html', error='비밀번호가 일치하지 않습니다.')

        conn = get_db_connection()
        # [VULNERABILITY] SQL Injection in registration (username check)
        check_q = f"SELECT id FROM users WHERE username = '{username}'"
        log_telemetry('SQL', check_q)
        try:
            existing = conn.execute(check_q).fetchone()
        except Exception as e:
            conn.close()
            return f"<h2>DB Error</h2><pre>{e}</pre><p>Query: {check_q}</p>"

        if existing:
            conn.close()
            return render_template('register.html', error='이미 사용 중인 아이디입니다.')

        hashed = md5(password)
        conn.execute("INSERT INTO users (username, name, email, password, last_ip) VALUES (?,?,?,?,?)",
                     (username, name, email, hashed, request.remote_addr))
        conn.commit(); conn.close()
        return redirect(url_for('auth.login'))

    return render_template('register.html')


# ── Logout ────────────────────────────────────────────────────
@bp.route('/logout')
def logout():
    session.clear()
    resp = make_response(redirect(url_for('board.index')))
    resp.set_cookie('role', 'user')
    return resp


# ── Find ID ───────────────────────────────────────────────────
@bp.route('/find_id', methods=['GET', 'POST'])
def find_id():
    result = None
    if request.method == 'POST':
        email = request.form.get('email', '').strip()
        conn  = get_db_connection()
        user  = conn.execute("SELECT username FROM users WHERE email = ?", (email,)).fetchone()
        conn.close()
        result = user['username'] if user else '해당 이메일로 가입된 계정이 없습니다.'
    return render_template('find_id.html', result=result)


# ── Find PW / Reset PW ────────────────────────────────────────
@bp.route('/find_pw', methods=['GET', 'POST'])
def find_pw():
    message = None
    if request.method == 'POST':
        username = request.form.get('username', '').strip()
        email    = request.form.get('email', '').strip()
        conn     = get_db_connection()
        user     = conn.execute("SELECT id FROM users WHERE username=? AND email=?",
                                (username, email)).fetchone()
        conn.close()
        if user:
            session['reset_user_id'] = user['id']
            return redirect(url_for('auth.reset_pw'))
        message = '아이디 또는 이메일이 일치하지 않습니다.'
    return render_template('find_pw.html', message=message)


@bp.route('/reset_pw', methods=['GET', 'POST'])
def reset_pw():
    if 'reset_user_id' not in session:
        return redirect(url_for('auth.find_pw'))
    if request.method == 'POST':
        new_pw  = request.form.get('new_pw', '')
        confirm = request.form.get('confirm', '')
        if new_pw != confirm:
            return render_template('reset_pw.html', error='비밀번호가 일치하지 않습니다.')
        hashed = md5(new_pw)
        conn = get_db_connection()
        conn.execute("UPDATE users SET password=? WHERE id=?", (hashed, session['reset_user_id']))
        conn.commit(); conn.close()
        session.pop('reset_user_id', None)
        return redirect(url_for('auth.login'))
    return render_template('reset_pw.html')


# ── Change PW (logged in) ─────────────────────────────────────
@bp.route('/change_pw', methods=['GET', 'POST'])
def change_pw():
    if 'user' not in session:
        return redirect(url_for('auth.login'))
    if request.method == 'POST' or request.args.get('new_pw'):
        # [VULNERABILITY] CSRF — no token, GET also accepted
        new_pw = request.args.get('new_pw') or request.form.get('new_pw', '')
        if new_pw:
            hashed = md5(new_pw)
            conn   = get_db_connection()
            conn.execute("UPDATE users SET password=? WHERE username=?", (hashed, session['user']))
            conn.commit(); conn.close()
            return "<script>alert('비밀번호가 변경되었습니다.'); location.href='/';</script>"
    return render_template('change_pw.html')


# ── Profile View ──────────────────────────────────────────────
@bp.route('/profile')
def profile():
    # [VULNERABILITY] IDOR — user_id from GET param, no auth check
    user_id = request.args.get('user_id') or session.get('user_id')
    if not user_id:
        return redirect(url_for('auth.login'))
    conn = get_db_connection()
    user = conn.execute("SELECT * FROM users WHERE id = ?", (user_id,)).fetchone()
    conn.close()
    if not user:
        return "사용자를 찾을 수 없습니다."
    return render_template('profile.html', user=user)


# ── Profile Edit ──────────────────────────────────────────────
@bp.route('/profile/edit', methods=['GET', 'POST'])
def profile_edit():
    if 'user' not in session:
        return redirect(url_for('auth.login'))
    conn = get_db_connection()
    user = conn.execute("SELECT * FROM users WHERE id=?", (session['user_id'],)).fetchone()
    if request.method == 'POST':
        email = request.form.get('email', '').strip()
        bio   = request.form.get('bio', '').strip()
        conn.execute("UPDATE users SET email=?, bio=? WHERE id=?",
                     (email, bio, session['user_id']))
        conn.commit(); conn.close()
        return redirect(url_for('auth.profile', user_id=session['user_id']))
    conn.close()
    return render_template('profile_edit.html', user=user)

from flask import (Blueprint, render_template, request, redirect,
                   url_for, session, current_app, jsonify)
from app.database import get_db_connection
import jwt

bp = Blueprint('admin', __name__)


def is_admin():
    """Check role via cookie (vuln) OR session."""
    role  = request.cookies.get('role', session.get('role', 'user'))
    token = request.cookies.get('jwt_token')
    if token:
        try:
            decoded = jwt.decode(token, current_app.config['SECRET_KEY'],
                                 algorithms=['HS256'])
            role = decoded.get('role', role)
        except Exception:
            pass
    return role == 'admin'


# ── Admin Dashboard ───────────────────────────────────────────
@bp.route('/admin')
def admin():
    # [VULNERABILITY] Cookie bypass / JWT forgery to gain admin
    if not is_admin():
        return (f"<h3>접근 거부: 관리자 권한(role=admin)이 필요합니다.</h3>"
                f"<p>현재 권한: {request.cookies.get('role','user')}</p>"
                f"<p><a href='/'>돌아가기</a></p>")
    conn = get_db_connection()
    cur  = conn.cursor()
    cur.execute("SELECT id, username, email, role, created_at FROM users ORDER BY id")
    users = cur.fetchall()
    cur.execute("SELECT p.*, u.email FROM posts p LEFT JOIN users u ON p.author_id=u.id ORDER BY p.created_at DESC")
    posts = cur.fetchall()
    cur.execute("SELECT * FROM notices ORDER BY created_at DESC")
    notices = cur.fetchall()

    cur.execute("SELECT COUNT(*) as cnt FROM users")
    u_cnt = cur.fetchone()['cnt']
    cur.execute("SELECT COUNT(*) as cnt FROM posts")
    p_cnt = cur.fetchone()['cnt']
    cur.execute("SELECT COUNT(*) as cnt FROM notices")
    n_cnt = cur.fetchone()['cnt']
    cur.execute("SELECT COUNT(*) as cnt FROM comments")
    c_cnt = cur.fetchone()['cnt']
    stats = {'users': u_cnt, 'posts': p_cnt, 'notices': n_cnt, 'comments': c_cnt}

    # 소셜 가입 경로 집계 (차트용)
    cur.execute("SELECT oauth_provider, COUNT(*) as cnt FROM users GROUP BY oauth_provider")
    oauth_rows = cur.fetchall()
    oauth_stats = {'google': 0, 'naver': 0, 'kakao': 0, 'normal': 0}
    for row in oauth_rows:
        p = row['oauth_provider']
        if p in oauth_stats:
            oauth_stats[p] = row['cnt']
        else:
            oauth_stats['normal'] += row['cnt']

    # 권한 분포 집계 (차트용)
    cur.execute("SELECT role, COUNT(*) as cnt FROM users GROUP BY role")
    role_rows = cur.fetchall()
    role_stats = {'admin': 0, 'user': 0}
    for row in role_rows:
        role_stats[row['role']] = row['cnt']

    cur.execute("SELECT * FROM login_logs ORDER BY created_at DESC LIMIT 100")
    logs = cur.fetchall()
    cur.close(); conn.close()
    return render_template('admin.html', users=users, posts=posts, notices=notices,
                           stats=stats, logs=logs,
                           oauth_stats=oauth_stats, role_stats=role_stats)


# ── User Management ───────────────────────────────────────────
@bp.route('/admin/user/<int:uid>/delete', methods=['POST'])
def admin_delete_user(uid):
    if not is_admin():
        return "권한 없음", 403
    conn = get_db_connection()
    cur  = conn.cursor()
    cur.execute("DELETE FROM comments WHERE author_id=%s", (uid,))
    cur.execute("DELETE FROM post_likes WHERE user_id=%s", (uid,))
    cur.execute("DELETE FROM posts WHERE author_id=%s", (uid,))
    cur.execute("DELETE FROM users WHERE id=%s", (uid,))
    conn.commit(); cur.close(); conn.close()
    return redirect(url_for('admin.admin') + '#users')


@bp.route('/admin/user/<int:uid>/role', methods=['POST'])
def admin_set_role(uid):
    if not is_admin():
        return "권한 없음", 403
    new_role = request.form.get('role', 'user')
    if new_role not in ('admin', 'user'):
        return "잘못된 역할입니다.", 400
    conn = get_db_connection()
    cur  = conn.cursor()
    cur.execute("UPDATE users SET role=%s WHERE id=%s", (new_role, uid))
    conn.commit(); cur.close(); conn.close()
    return redirect(url_for('admin.admin') + '#users')


# ── Post Management ───────────────────────────────────────────
@bp.route('/admin/post/<int:pid>/delete', methods=['POST'])
def admin_delete_post(pid):
    if not is_admin():
        return "권한 없음", 403
    conn = get_db_connection()
    cur  = conn.cursor()
    cur.execute("DELETE FROM comments  WHERE post_id=%s", (pid,))
    cur.execute("DELETE FROM post_likes WHERE post_id=%s", (pid,))
    cur.execute("DELETE FROM files     WHERE post_id=%s", (pid,))
    cur.execute("DELETE FROM posts     WHERE id=%s",      (pid,))
    conn.commit(); cur.close(); conn.close()
    return redirect(url_for('admin.admin') + '#posts')


# ── Notice Management ─────────────────────────────────────────
@bp.route('/admin/notice/create', methods=['POST'])
def admin_create_notice():
    if not is_admin():
        return "권한 없음", 403
    title   = request.form.get('title', '').strip()
    content = request.form.get('content', '').strip()
    if title and content:
        conn = get_db_connection()
        cur  = conn.cursor()
        cur.execute("INSERT INTO notices (title, content, author_id, author) VALUES (%s,%s,%s,%s)",
                    (title, content, session.get('user_id', 1), session.get('user', 'admin')))
        conn.commit(); cur.close(); conn.close()
    return redirect(url_for('admin.admin') + '#notices')


@bp.route('/admin/notice/<int:pid>/delete', methods=['POST'])
def admin_delete_notice(pid):
    if not is_admin():
        return "권한 없음", 403
    conn = get_db_connection()
    cur  = conn.cursor()
    cur.execute("DELETE FROM notices WHERE id=%s", (pid,))
    conn.commit(); cur.close(); conn.close()
    return redirect(url_for('admin.admin') + '#notices')

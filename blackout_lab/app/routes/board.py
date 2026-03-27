from flask import (Blueprint, render_template, request, redirect,
                   url_for, session, send_from_directory, make_response,
                   current_app, jsonify)
from app.database import get_db_connection
import os, uuid

bp = Blueprint('board', __name__)

# ── Index / Board List ────────────────────────────────────────
@bp.route('/')
def index():
    conn    = get_db_connection()
    notices = conn.execute('SELECT * FROM notices ORDER BY created_at DESC').fetchall()
    page    = max(1, request.args.get('page', 1, type=int))
    search  = request.args.get('q', '').strip()
    per     = 10

    if search:
        # [VULNERABILITY] Stored XSS — search term reflected without escaping in index.html
        total = conn.execute("SELECT COUNT(*) FROM posts WHERE (title LIKE ? OR content LIKE ?)",
                             (f'%{search}%', f'%{search}%')).fetchone()[0]
        posts = conn.execute(
            "SELECT * FROM posts WHERE (title LIKE ? OR content LIKE ?) "
            "ORDER BY created_at DESC LIMIT ? OFFSET ?",
            (f'%{search}%', f'%{search}%', per, (page-1)*per)).fetchall()
    else:
        total = conn.execute("SELECT COUNT(*) FROM posts").fetchone()[0]
        posts = conn.execute(
            "SELECT * FROM posts ORDER BY created_at DESC LIMIT ? OFFSET ?",
            (per, (page-1)*per)).fetchall()
    conn.close()

    total_pages = max(1, (total + per - 1) // per)
    resp = make_response(render_template('index.html',
                                         posts=posts,
                                         notices=notices,
                                         page=page,
                                         total_pages=total_pages,
                                         search=search))
    if 'role' not in request.cookies:
        resp.set_cookie('role', 'user')
    return resp


# ── Write Post ────────────────────────────────────────────────
@bp.route('/post/write', methods=['GET', 'POST'])
def board_write():
    if 'user' not in session:
        return redirect(url_for('auth.login'))

    if request.method == 'POST':
        title   = request.form.get('title', '')
        content = request.form.get('content', '')          # [VULN] Stored XSS — no sanitisation

        conn = get_db_connection()
        conn.execute('INSERT INTO posts (title, content, author_id, author) VALUES (?,?,?,?)',
                     (title, content, session['user_id'], session['user']))
        post_id = conn.execute('SELECT last_insert_rowid()').fetchone()[0]

        # File attachments
        for f in request.files.getlist('files'):
            if f and f.filename:
                ext      = os.path.splitext(f.filename)[1]
                saved    = uuid.uuid4().hex + ext
                # [VULNERABILITY] Unrestricted File Upload — no extension whitelist
                f.save(os.path.join(current_app.config['UPLOAD_FOLDER'], saved))
                conn.execute('INSERT INTO files (post_id, filename, orig_name, size) VALUES (?,?,?,?)',
                             (post_id, saved, f.filename, 0))
        conn.commit(); conn.close()
        return redirect(url_for('board.board_view', post_id=post_id))

    return render_template('board_write.html')


# ── View Post ─────────────────────────────────────────────────
@bp.route('/post/<int:post_id>')
def board_view(post_id):
    conn = get_db_connection()
    post = conn.execute('SELECT * FROM posts WHERE id=?', (post_id,)).fetchone()
    if not post:
        conn.close()
        return "게시글을 찾을 수 없습니다.", 404

    # Increment view count
    conn.execute('UPDATE posts SET views=views+1 WHERE id=?', (post_id,))

    files    = conn.execute('SELECT * FROM files WHERE post_id=?', (post_id,)).fetchall()
    likes    = conn.execute('SELECT COUNT(*) FROM post_likes WHERE post_id=?', (post_id,)).fetchone()[0]
    user_liked = False
    if 'user_id' in session:
        row = conn.execute('SELECT 1 FROM post_likes WHERE post_id=? AND user_id=?',
                           (post_id, session['user_id'])).fetchone()
        user_liked = row is not None

    # Comments (flat fetch, parent_id used for nesting in template)
    comments = conn.execute(
        'SELECT * FROM comments WHERE post_id=? ORDER BY created_at ASC', (post_id,)).fetchall()
    conn.commit(); conn.close()

    return render_template('board_view.html',
                           post=post, files=files,
                           likes=likes, user_liked=user_liked,
                           comments=comments)


# ── View Notice ─────────────────────────────────────────────────
@bp.route('/notice/<int:notice_id>')
def notice_view(notice_id):
    conn = get_db_connection()
    post = conn.execute('SELECT * FROM notices WHERE id=?', (notice_id,)).fetchone()
    if not post:
        conn.close()
        return "공지사항을 찾을 수 없습니다.", 404

    conn.execute('UPDATE notices SET views=views+1 WHERE id=?', (notice_id,))
    files = conn.execute('SELECT * FROM files WHERE notice_id=?', (notice_id,)).fetchall()
    conn.commit(); conn.close()

    return render_template('board_view.html', post=post, files=files, is_notice=True)


# ── Edit Post ─────────────────────────────────────────────────
@bp.route('/post/<int:post_id>/edit', methods=['GET', 'POST'])
def board_edit(post_id):
    if 'user' not in session:
        return redirect(url_for('auth.login'))
    conn = get_db_connection()
    post = conn.execute('SELECT * FROM posts WHERE id=?', (post_id,)).fetchone()
    if not post:
        conn.close()
        return "게시글을 찾을 수 없습니다.", 404

    # Only author or admin can edit
    role = request.cookies.get('role', session.get('role', 'user'))
    if post['author_id'] != session.get('user_id') and role != 'admin':
        conn.close()
        return "수정 권한이 없습니다.", 403

    if request.method == 'POST':
        title   = request.form.get('title', '')
        content = request.form.get('content', '')
        conn.execute('UPDATE posts SET title=?, content=?, updated_at=CURRENT_TIMESTAMP WHERE id=?',
                     (title, content, post_id))
        # New attachments
        for f in request.files.getlist('files'):
            if f and f.filename:
                ext   = os.path.splitext(f.filename)[1]
                saved = uuid.uuid4().hex + ext
                f.save(os.path.join(current_app.config['UPLOAD_FOLDER'], saved))
                conn.execute('INSERT INTO files (post_id, filename, orig_name) VALUES (?,?,?)',
                             (post_id, saved, f.filename))
        conn.commit(); conn.close()
        return redirect(url_for('board.board_view', post_id=post_id))

    files = conn.execute('SELECT * FROM files WHERE post_id=?', (post_id,)).fetchall()
    conn.close()
    return render_template('board_edit.html', post=post, files=files)


# ── Delete Post ───────────────────────────────────────────────
@bp.route('/post/<int:post_id>/delete', methods=['POST'])
def board_delete(post_id):
    if 'user' not in session:
        return redirect(url_for('auth.login'))
    conn = get_db_connection()
    post = conn.execute('SELECT * FROM posts WHERE id=?', (post_id,)).fetchone()
    if not post:
        conn.close()
        return "게시글을 찾을 수 없습니다.", 404
    role = request.cookies.get('role', session.get('role', 'user'))
    if post['author_id'] != session.get('user_id') and role != 'admin':
        conn.close()
        return "삭제 권한이 없습니다.", 403
    conn.execute('DELETE FROM comments WHERE post_id=?', (post_id,))
    conn.execute('DELETE FROM post_likes WHERE post_id=?', (post_id,))
    conn.execute('DELETE FROM files WHERE post_id=?', (post_id,))
    conn.execute('DELETE FROM posts WHERE id=?', (post_id,))
    conn.commit(); conn.close()
    return redirect(url_for('board.index'))


# ── Like Post ─────────────────────────────────────────────────
@bp.route('/post/<int:post_id>/like', methods=['POST'])
def post_like(post_id):
    if 'user' not in session:
        return jsonify({'error': 'login required'}), 401
    conn = get_db_connection()
    existing = conn.execute('SELECT 1 FROM post_likes WHERE post_id=? AND user_id=?',
                            (post_id, session['user_id'])).fetchone()
    if existing:
        conn.execute('DELETE FROM post_likes WHERE post_id=? AND user_id=?',
                     (post_id, session['user_id']))
        liked = False
    else:
        conn.execute('INSERT INTO post_likes (post_id, user_id) VALUES (?,?)',
                     (post_id, session['user_id']))
        liked = True
    count = conn.execute('SELECT COUNT(*) FROM post_likes WHERE post_id=?', (post_id,)).fetchone()[0]
    conn.commit(); conn.close()
    return jsonify({'liked': liked, 'count': count})


# ── Add Comment ───────────────────────────────────────────────
@bp.route('/post/<int:post_id>/comment', methods=['POST'])
def add_comment(post_id):
    if 'user' not in session:
        return redirect(url_for('auth.login'))
    content   = request.form.get('content', '').strip()
    parent_id = request.form.get('parent_id') or None  # for nested replies
    if content:
        conn = get_db_connection()
        # [VULNERABILITY] Stored XSS — comment content not sanitised
        conn.execute('INSERT INTO comments (post_id, author_id, author, content, parent_id) VALUES (?,?,?,?,?)',
                     (post_id, session['user_id'], session['user'], content, parent_id))
        conn.commit(); conn.close()
    return redirect(url_for('board.board_view', post_id=post_id) + '#comments')


# ── Edit Comment ──────────────────────────────────────────────
@bp.route('/comment/<int:cid>/edit', methods=['POST'])
def edit_comment(cid):
    if 'user' not in session:
        return redirect(url_for('auth.login'))
    content = request.form.get('content', '').strip()
    conn    = get_db_connection()
    c       = conn.execute('SELECT * FROM comments WHERE id=?', (cid,)).fetchone()
    if c and (c['author_id'] == session.get('user_id') or request.cookies.get('role', session.get('role')) == 'admin'):
        conn.execute('UPDATE comments SET content=?, updated_at=CURRENT_TIMESTAMP WHERE id=?',
                     (content, cid))
        conn.commit()
    post_id = c['post_id'] if c else 1
    conn.close()
    return redirect(url_for('board.board_view', post_id=post_id) + '#comments')


# ── Delete Comment ────────────────────────────────────────────
@bp.route('/comment/<int:cid>/delete', methods=['POST'])
def delete_comment(cid):
    if 'user' not in session:
        return redirect(url_for('auth.login'))
    conn = get_db_connection()
    c    = conn.execute('SELECT * FROM comments WHERE id=?', (cid,)).fetchone()
    if c and (c['author_id'] == session.get('user_id') or request.cookies.get('role', session.get('role')) == 'admin'):
        conn.execute('DELETE FROM comments WHERE parent_id=?', (cid,))
        conn.execute('DELETE FROM comments WHERE id=?', (cid,))
        conn.commit()
    post_id = c['post_id'] if c else 1
    conn.close()
    return redirect(url_for('board.board_view', post_id=post_id) + '#comments')


# ── File Download ─────────────────────────────────────────────
@bp.route('/file/<int:fid>')
def file_download(fid):
    conn = get_db_connection()
    f    = conn.execute('SELECT * FROM files WHERE id=?', (fid,)).fetchone()
    conn.close()
    if not f:
        return "파일을 찾을 수 없습니다.", 404
    return send_from_directory(current_app.config['UPLOAD_FOLDER'], f['filename'],
                               as_attachment=True, download_name=f['orig_name'])


# ── Legacy uploads (direct path) — [VULN] Path Traversal ─────
@bp.route('/uploads/<filename>')
def uploaded_file(filename):
    return send_from_directory(current_app.config['UPLOAD_FOLDER'], filename)


# ── XSS Test Page ─────────────────────────────────────────────
@bp.route('/xss')
def xss_page():
    # [VULNERABILITY] Reflected XSS — input echoed without escaping
    return render_template('xss.html', input=request.args.get('input', ''))

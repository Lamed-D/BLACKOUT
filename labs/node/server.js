// ============================================================
// BLACKOUT Node 엔진 — 취약 커뮤니티 앱 (PHP 엔진과 동등한 실습 표면)
//   ⚠️ 의도적으로 취약한 학습용 코드. 로컬에서만 구동할 것. 터널 금지.
// ============================================================
const express = require('express');
const session = require('express-session');
const cookieParser = require('cookie-parser');
const multer = require('multer');
const crypto = require('node:crypto');
const cp = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const {pool, initialize, hashPassword} = require('./db');
const {jwtEncode, jwtDecode, flag} = require('./jwt');
const adv = require('./advuln');
const {createRouter: createOAuthRouter} = require('./oauth');

const app = express();
const catalog = JSON.parse(fs.readFileSync(path.join(__dirname, 'data/vulns.json'), 'utf8'));
const exploits = JSON.parse(fs.readFileSync(path.join(__dirname, 'data/exploits.json'), 'utf8'));
const approaches = JSON.parse(fs.readFileSync(path.join(__dirname, 'data/approaches.json'), 'utf8'));
const EXPLOIT_DIR = path.join(__dirname, 'data/exploits');
const APP_DIR = __dirname;
const UPLOAD_DIR = path.join(APP_DIR, 'uploads');
fs.mkdirSync(UPLOAD_DIR, {recursive: true});

app.set('view engine', 'ejs');
app.set('views', path.join(__dirname, 'views'));
app.use('/style.css', express.static(path.join(__dirname, 'public/style.css')));
// [VULN] 업로드물을 제한 없이 정적 서빙 → HTML/SVG 저장형 XSS
app.use('/uploads', express.static(UPLOAD_DIR));
app.use(express.urlencoded({extended: false, limit: '2mb'}));
app.use(express.json({limit: '2mb'}));
app.use(cookieParser());
app.use(session({
  secret: process.env.SESSION_SECRET || 'blackout-node',
  resave: false, saveUninitialized: true,
  // [VULN] 쿠키 보안 속성 미설정에 준하는 기본값(secure 미설정). role 쿠키는 별도로 httpOnly 미적용.
  cookie: {httpOnly: true, sameSite: 'lax'}
}));
const upload = multer({storage: multer.diskStorage({
  destination: (_r, _f, cb) => cb(null, UPLOAD_DIR),
  filename: (_r, file, cb) => cb(null, path.basename(file.originalname)) // [VULN] 원본 파일명/확장자 그대로
})});

// 현재 사용자 + 권한(쿠키 role 신뢰) 로드. [VULN] 보안 헤더 전부 미설정(클릭재킹/nosniff/CSP/HSTS 누락)
app.use(async (req, res, next) => {
  try {
    req.user = null;
    if (req.session.userId) {
      const [rows] = await pool.query('SELECT * FROM users WHERE id=' + pool.escape(req.session.userId));
      req.user = rows[0] || null;
    }
    // [VULN] 클라이언트 쿠키 role 값을 신뢰
    const role = req.cookies.role || req.session.role || (req.user && req.user.role) || 'user';
    res.locals.user = req.user;
    res.locals.isAdmin = role === 'admin';
    res.locals.notice = req.session.notice || null;
    delete req.session.notice;
    next();
  } catch (error) { next(error); }
});

app.use(createOAuthRouter(pool, hashPassword));

// ── 헬퍼 ────────────────────────────────────────────────────
function h(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, c =>
  ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c])); }
function page(req, res, title, body, code = 200) { res.status(code).render('page', {title, body}); }
function num(v, d = 0) { const n = Number.parseInt(v, 10); return Number.isSafeInteger(n) ? n : d; }
function clientIp(req) { return (req.headers['x-forwarded-for'] || req.socket.remoteAddress || '0.0.0.0').split(',')[0].trim(); }
function isAdminReq(req) { return (req.cookies.role || req.session.role || (req.user && req.user.role) || 'user') === 'admin'; }

// ============================================================
// 게시판 / 홈 — [VULN] SQLi(검색) + 반사/저장형 XSS + verbose 오류
// ============================================================
app.get('/', async (req, res, next) => {
  try {
    const q = String(req.query.q || '');
    let searchHtml = '';
    if (q !== '') {
      // [VULN] SQL Injection — 문자열 연결 (UNION/스키마열거/블라인드)
      const sql = `SELECT id, title, author FROM posts WHERE title LIKE '%${q}%' OR content LIKE '%${q}%' ORDER BY id DESC`;
      try {
        const [rows] = await pool.query(sql);
        // [VULN] Reflected XSS — q 미이스케이프
        searchHtml = `<div class="card mb-4"><div class="card-body"><h5 class="fw-bold">검색 결과: <span class="text-info">${q}</span></h5><ul class="list-group">`;
        // [VULN] 결과(title) 미이스케이프 → UNION 데이터/저장형 XSS 노출
        for (const r of rows) searchHtml += `<li class="list-group-item"><b>#${h(r.id)}</b> ${r.title} <span class="text-secondary small">(${h(r.author)})</span></li>`;
        if (!rows.length) searchHtml += '<li class="list-group-item text-secondary">결과가 없습니다.</li>';
        searchHtml += '</ul></div></div>';
      } catch (e) {
        // [VULN] verbose 오류 — 쿼리/메시지 노출
        searchHtml = `<div class="card mb-4"><div class="card-body"><h5 class="fw-bold">검색 결과: <span class="text-info">${q}</span></h5><pre class="text-danger">${h(e.message)}\n쿼리: ${h(sql)}</pre></div></div>`;
      }
    }
    const per = 6;
    const [[{total}]] = await pool.query('SELECT COUNT(*) AS total FROM posts');
    const pages = Math.max(1, Math.ceil(total / per));
    const pageN = Math.max(1, Math.min(pages, num(req.query.page, 1)));
    const [notices] = await pool.query('SELECT * FROM notices ORDER BY id DESC');
    const [posts] = await pool.query(
      `SELECT p.*, (SELECT COUNT(*) FROM comments c WHERE c.post_id=p.id) AS ccount FROM posts p ORDER BY p.id DESC LIMIT ${per} OFFSET ${(pageN - 1) * per}`);

    let body = `<div class="card mb-4"><div class="card-body p-5"><h2 class="fw-bold mb-2"><i class="bi bi-chat-square-text text-info"></i> 자유 게시판</h2>
      <p class="text-secondary mb-3">자유롭게 글을 남겨주세요. 제목/본문/댓글/검색이 다양한 공격(XSS·SQLi)에 취약합니다.</p>
      <form class="d-flex gap-2" method="get" action="/"><input class="form-control" name="q" placeholder="게시글 검색 (SQLi 실습)" value="${h(q)}"><button class="btn btn-primary">검색</button></form></div></div>${searchHtml}`;
    body += '<div class="card"><div class="card-body p-0"><table class="table table-hover text-center align-middle mb-0"><thead class="table-dark"><tr><th>번호</th><th>제목</th><th>작성자</th><th>조회</th><th>작성일</th></tr></thead><tbody>';
    for (const n of notices)
      body += `<tr class="table-warning"><td class="text-danger fw-bold"><i class="bi bi-megaphone-fill"></i> 공지</td><td class="text-start ps-4"><a class="fw-bold text-dark text-decoration-none" href="/notice/${n.id}">${n.title}</a></td><td><span class="badge bg-danger">관리자</span></td><td class="small text-secondary">${n.views}</td><td class="small text-secondary">${h(n.created_at)}</td></tr>`;
    for (const p of posts)
      // [VULN] Stored XSS — 제목 미이스케이프
      body += `<tr><td class="text-secondary fw-bold">${p.id}</td><td class="text-start ps-4"><a class="fw-bold text-info text-decoration-none" href="/post/${p.id}">${p.title}</a>${p.ccount > 0 ? ` <span class="badge bg-info-subtle text-info">${p.ccount}</span>` : ''}</td><td><span class="badge bg-secondary">${h(p.author)}</span></td><td class="small text-secondary">${p.views}</td><td class="small text-secondary">${h(p.created_at)}</td></tr>`;
    body += '</tbody></table></div>';
    if (pages > 1) {
      body += '<div class="card-footer d-flex justify-content-center"><nav><ul class="pagination flex-wrap justify-content-center gap-1 mb-0">';
      for (let i = 1; i <= pages; i++) if (i === 1 || i === pages || Math.abs(i - pageN) <= 2)
        body += `<li class="page-item${i === pageN ? ' active' : ''}"><a class="page-link" href="/?page=${i}">${i}</a></li>`;
      body += '</ul></nav></div>';
    }
    body += '<div class="card-footer text-end"><a class="btn btn-warning fw-bold text-dark" href="/post/write"><i class="bi bi-pencil-fill"></i> 글쓰기</a></div></div>';
    page(req, res, '자유 게시판', body);
  } catch (error) { next(error); }
});

// ── 게시글/공지 보기 — [VULN] 저장형 XSS ──
function renderPost(req, res, post, files, comments, likes, isNotice) {
  let body = `<div class="card mb-3"><div class="card-body p-4"><h3 class="fw-bold">${post.title}</h3>
    <p class="text-secondary small">작성자 ${h(post.author)} · 조회 ${post.views} · ${h(post.created_at)}</p><hr>
    <div class="post-body" style="min-height:80px">${post.content}</div>`; // [VULN] Stored XSS (title/content)
  if (files.length) { body += '<hr><h6>첨부파일</h6><ul>'; for (const f of files) body += `<li><a href="/file/${f.id}">${h(f.orig_name)}</a></li>`; body += '</ul>'; }
  if (!isNotice) {
    body += `<div class="mt-3 d-flex gap-2">
      <form method="post" action="/post/${post.id}/like"><button class="btn btn-outline-danger btn-sm"><i class="bi bi-heart"></i> 좋아요 ${likes}</button></form>
      <a class="btn btn-outline-secondary btn-sm" href="/post/${post.id}/edit">수정</a>
      <form method="post" action="/post/${post.id}/delete" onsubmit="return confirm('삭제?')"><button class="btn btn-outline-danger btn-sm">삭제</button></form></div>`;
  }
  body += '</div></div>';
  if (!isNotice) {
    body += '<div class="card"><div class="card-body"><h5 class="fw-bold">댓글</h5>';
    for (const c of comments) body += `<div class="border-bottom py-2"><b>${h(c.author)}</b> <span class="text-secondary small">${h(c.created_at)}</span><br>${c.content}</div>`; // [VULN] Stored XSS (댓글)
    if (req.user) body += `<form method="post" action="/post/${post.id}/comment" class="mt-3 d-flex gap-2"><input class="form-control" name="content" placeholder="댓글 입력"><button class="btn btn-primary">등록</button></form>`;
    else body += '<p class="text-secondary mt-2">로그인 후 댓글을 쓸 수 있습니다.</p>';
    body += '</div></div>';
  }
  page(req, res, post.title, body);
}
app.get('/post/:id', async (req, res, next) => {
  try {
    const id = num(req.params.id);
    const [rows] = await pool.query('SELECT * FROM posts WHERE id=' + pool.escape(id));
    if (!rows[0]) return page(req, res, '404', '<div class="card"><div class="card-body p-5 text-center"><h3>게시글을 찾을 수 없습니다.</h3></div></div>', 404);
    await pool.query('UPDATE posts SET views=views+1 WHERE id=' + pool.escape(id));
    rows[0].views += 1;
    const [files] = await pool.query('SELECT * FROM files WHERE post_id=' + pool.escape(id));
    const [comments] = await pool.query('SELECT * FROM comments WHERE post_id=' + pool.escape(id) + ' ORDER BY id');
    const [[{likes}]] = await pool.query('SELECT COUNT(*) AS likes FROM post_likes WHERE post_id=' + pool.escape(id));
    renderPost(req, res, rows[0], files, comments, likes, false);
  } catch (error) { next(error); }
});
app.get('/notice/:id', async (req, res, next) => {
  try {
    const id = num(req.params.id);
    const [rows] = await pool.query('SELECT * FROM notices WHERE id=' + pool.escape(id));
    if (!rows[0]) return page(req, res, '404', '<div class="card"><div class="card-body p-5 text-center"><h3>공지를 찾을 수 없습니다.</h3></div></div>', 404);
    await pool.query('UPDATE notices SET views=views+1 WHERE id=' + pool.escape(id));
    renderPost(req, res, rows[0], [], [], 0, true);
  } catch (error) { next(error); }
});

// ── 글쓰기 (무제한 업로드) / 수정·삭제 (수평 권한상승) ──
app.get('/post/write', (req, res) => {
  if (!req.user) return res.redirect('/login');
  page(req, res, '새 글 쓰기', `<div class="card"><div class="card-body p-4"><h4 class="fw-bold mb-3">새 글 쓰기</h4>
    <form method="post" enctype="multipart/form-data" action="/post/write">
    <input class="form-control mb-2" name="title" placeholder="제목" required>
    <textarea class="form-control mb-2" name="content" rows="8" placeholder="내용 (HTML 허용 — 저장형 XSS 실습)"></textarea>
    <input class="form-control mb-3" type="file" name="file"><button class="btn btn-primary">등록</button></form></div></div>`);
});
app.post('/post/write', upload.single('file'), async (req, res, next) => {
  try {
    if (!req.user) return res.redirect('/login');
    const [r] = await pool.query('INSERT INTO posts (title,content,author_id,author) VALUES (?,?,?,?)',
      [String(req.body.title || ''), String(req.body.content || ''), req.user.id, req.user.username]);
    if (req.file) // [VULN] 무제한 파일 업로드 (확장자/타입 검증 없음 → /uploads 로 직접 접근 가능)
      await pool.query('INSERT INTO files (post_id,filename,orig_name,size) VALUES (?,?,?,?)',
        [r.insertId, req.file.filename, req.file.originalname, req.file.size]);
    res.redirect('/post/' + r.insertId);
  } catch (error) { next(error); }
});
app.get('/post/:id/edit', async (req, res, next) => {
  try {
    if (!req.user) return res.redirect('/login');
    const id = num(req.params.id);
    const [rows] = await pool.query('SELECT * FROM posts WHERE id=' + pool.escape(id));
    if (!rows[0]) return page(req, res, '404', '없음', 404);
    const p = rows[0];
    // [VULN] 수평 권한상승 — 작성자 확인 없이 누구나 수정 폼 접근
    page(req, res, '글 수정', `<div class="card"><div class="card-body p-4"><h4 class="fw-bold mb-3">글 수정</h4>
      <form method="post" action="/post/${id}/edit"><input class="form-control mb-2" name="title" value="${h(p.title)}">
      <textarea class="form-control mb-3" name="content" rows="8">${h(p.content)}</textarea><button class="btn btn-primary">저장</button></form></div></div>`);
  } catch (error) { next(error); }
});
app.post('/post/:id/edit', async (req, res, next) => {
  try {
    if (!req.user) return res.redirect('/login');
    // [VULN] 수평 권한상승 — 소유자 검사 없음
    await pool.query('UPDATE posts SET title=?,content=?,updated_at=CURRENT_TIMESTAMP WHERE id=?',
      [String(req.body.title || ''), String(req.body.content || ''), num(req.params.id)]);
    res.redirect('/post/' + num(req.params.id));
  } catch (error) { next(error); }
});
app.post('/post/:id/delete', async (req, res, next) => {
  try {
    if (!req.user) return res.redirect('/login');
    const id = num(req.params.id);
    // [VULN] 수평 권한상승 — 소유자 검사 없이 삭제
    await pool.query('DELETE FROM comments WHERE post_id=' + pool.escape(id));
    await pool.query('DELETE FROM post_likes WHERE post_id=' + pool.escape(id));
    await pool.query('DELETE FROM posts WHERE id=' + pool.escape(id));
    res.redirect('/');
  } catch (error) { next(error); }
});
app.post('/post/:id/comment', async (req, res, next) => {
  try {
    if (!req.user) return res.redirect('/login');
    const content = String(req.body.content || '');
    if (content) await pool.query('INSERT INTO comments (post_id,author_id,author,content) VALUES (?,?,?,?)',
      [num(req.params.id), req.user.id, req.user.username, content]);
    res.redirect('/post/' + num(req.params.id));
  } catch (error) { next(error); }
});
app.post('/post/:id/like', async (req, res, next) => {
  try {
    if (!req.user) return res.status(401).send('login required');
    const id = num(req.params.id);
    const [ex] = await pool.query('SELECT 1 FROM post_likes WHERE post_id=? AND user_id=?', [id, req.user.id]);
    if (ex.length) await pool.query('DELETE FROM post_likes WHERE post_id=? AND user_id=?', [id, req.user.id]);
    else await pool.query('INSERT INTO post_likes (post_id,user_id) VALUES (?,?)', [id, req.user.id]);
    res.redirect('/post/' + id);
  } catch (error) { next(error); }
});

// ============================================================
// 인증/계정
// ============================================================
function ssoButtons() {
  return `<div class="sso-options mt-4"><p class="small text-secondary text-center mb-2">간편 로그인</p>
    <a class="sso-button sso-google" href="/oauth/google/login">Google</a>
    <a class="sso-button sso-kakao" href="/oauth/kakao/login">Kakao</a>
    <a class="sso-button sso-naver" href="/oauth/naver/login">Naver</a></div>`;
}
app.get('/login', (req, res) => {
  const next = h(req.query.next || '/');
  page(req, res, '로그인', `<div class="card mx-auto" style="max-width:430px"><div class="card-body p-4"><h4 class="fw-bold mb-3">로그인</h4>
    <form method="post" action="/login?next=${next}"><input class="form-control mb-2" name="username" placeholder="아이디">
    <input class="form-control mb-3" type="password" name="password" placeholder="비밀번호"><button class="btn btn-primary w-100">로그인</button></form>
    ${ssoButtons()}<div class="mt-3 small"><a href="/register">회원가입</a> · <a href="/find_id">아이디 찾기</a> · <a href="/find_pw">비밀번호 찾기</a></div></div></div>`);
});
app.post('/login', async (req, res, next) => {
  try {
    const username = String(req.body.username || '');
    const password = String(req.body.password || '');
    // [VULN] SQL Injection — 인증 우회 (username/password 미파라미터화) + 약한 MD5
    const sql = `SELECT * FROM users WHERE username='${username}' AND password='${hashPassword(password)}'`;
    let user = null;
    try { const [rows] = await pool.query(sql); user = rows[0] || null; } catch { /* 로그인 화면은 쿼리 오류 숨김 */ }
    if (user) {
      // [VULN] 세션 고정 — 로그인 성공 시 regenerate 미호출
      req.session.userId = user.id;
      req.session.role = user.role;
      // [VULN] 클라이언트 쿠키로 권한 전달 + httpOnly 미설정 + 약한 JWT
      res.cookie('role', user.role, {httpOnly: false, path: '/'});
      res.cookie('jwt_token', jwtEncode({username: user.username, role: user.role}), {httpOnly: false, path: '/'});
      await pool.query('INSERT INTO login_logs (user_id,username,ip_address,user_agent) VALUES (?,?,?,?)',
        [user.id, user.username, clientIp(req), String(req.headers['user-agent'] || '')]);
      // [VULN] 오픈 리다이렉트 — next 값을 검증 없이 사용
      return res.redirect(String(req.query.next || req.body.next || '/'));
    }
    page(req, res, '로그인', `<div class="card mx-auto" style="max-width:430px"><div class="card-body p-4"><h4 class="fw-bold mb-3">로그인</h4>
      <div class="alert alert-danger">아이디 또는 비밀번호가 틀렸습니다.</div>
      <form method="post" action="/login"><input class="form-control mb-2" name="username" placeholder="아이디">
      <input class="form-control mb-3" type="password" name="password" placeholder="비밀번호"><button class="btn btn-primary w-100">로그인</button></form>${ssoButtons()}</div></div>`, 401);
  } catch (error) { next(error); }
});
app.get('/logout', (req, res) => {
  res.clearCookie('role'); res.clearCookie('jwt_token');
  req.session.destroy(() => res.redirect('/'));
});
app.get('/register', (req, res) => page(req, res, '회원가입',
  `<div class="card mx-auto" style="max-width:430px"><div class="card-body p-4"><h4 class="fw-bold mb-3">회원가입</h4>
   <form method="post" action="/register"><input class="form-control mb-2" name="username" placeholder="아이디">
   <input class="form-control mb-2" name="name" placeholder="이름"><input class="form-control mb-2" name="email" placeholder="이메일">
   <input class="form-control mb-3" type="password" name="password" placeholder="비밀번호"><button class="btn btn-primary w-100">가입</button></form></div></div>`));
app.post('/register', async (req, res, next) => {
  try {
    const username = String(req.body.username || '').trim();
    const password = String(req.body.password || '');
    if (!username || !password) return page(req, res, '회원가입', '<div class="alert alert-danger">아이디/비밀번호를 입력하세요.</div><a href="/register">돌아가기</a>', 400);
    const [ex] = await pool.query('SELECT id FROM users WHERE username=?', [username]);
    if (ex.length) return page(req, res, '회원가입', '<div class="alert alert-danger">이미 사용 중인 아이디입니다.</div><a href="/register">돌아가기</a>', 409);
    // [VULN] Mass Assignment — role/points 를 사용자 입력에서 그대로 수용
    const role = String(req.body.role || 'user');
    const points = num(req.body.points, 1000);
    await pool.query('INSERT INTO users (username,password,name,email,role,points,last_ip) VALUES (?,?,?,?,?,?,?)',
      [username, hashPassword(password), String(req.body.name || ''), String(req.body.email || ''), role, points, clientIp(req)]);
    res.redirect('/login');
  } catch (error) { next(error); }
});

app.get('/find_id', (req, res) => page(req, res, '아이디 찾기',
  `<div class="card mx-auto" style="max-width:420px"><div class="card-body p-4"><h4 class="fw-bold mb-3">아이디 찾기</h4>
   <form method="post" action="/find_id"><input class="form-control mb-3" name="email" placeholder="이메일"><button class="btn btn-primary w-100">찾기</button></form></div></div>`));
app.post('/find_id', async (req, res, next) => {
  try {
    const [rows] = await pool.query('SELECT username FROM users WHERE email=?', [String(req.body.email || '').trim()]);
    // [VULN] 사용자 열거 — 존재/비존재 응답 차이
    const result = rows[0] ? '가입된 아이디: ' + rows[0].username : '해당 이메일로 가입된 계정이 없습니다.';
    page(req, res, '아이디 찾기', `<div class="card mx-auto" style="max-width:420px"><div class="card-body p-4"><h4 class="fw-bold mb-3">아이디 찾기</h4>
      <div class="alert alert-info">${h(result)}</div><form method="post" action="/find_id"><input class="form-control mb-3" name="email" placeholder="이메일"><button class="btn btn-primary w-100">찾기</button></form></div></div>`);
  } catch (error) { next(error); }
});
app.get('/find_pw', (req, res) => page(req, res, '비밀번호 찾기',
  `<div class="card mx-auto" style="max-width:480px"><div class="card-body p-4"><h4 class="fw-bold mb-3">비밀번호 찾기</h4>
   <form method="post" action="/find_pw"><input class="form-control mb-3" name="username" placeholder="아이디"><button class="btn btn-primary w-100">재설정 링크 생성</button></form></div></div>`));
app.post('/find_pw', async (req, res, next) => {
  try {
    const username = String(req.body.username || '').trim();
    const [rows] = await pool.query('SELECT id FROM users WHERE username=?', [username]);
    let message;
    if (rows[0]) {
      req.session.resetUserId = rows[0].id;
      const token = crypto.randomBytes(8).toString('hex');
      // [VULN] Host 헤더 인젝션 — 재설정 링크에 신뢰 불가 Host 사용
      message = `비밀번호 재설정 링크가 생성되었습니다:\nhttp://${req.headers.host}/reset_pw?token=${token}`;
    } else { message = '아이디가 존재하지 않습니다.'; } // [VULN] 열거
    page(req, res, '비밀번호 찾기', `<div class="card mx-auto" style="max-width:480px"><div class="card-body p-4"><h4 class="fw-bold mb-3">비밀번호 찾기</h4>
      <pre class="alert alert-info">${h(message)}</pre><form method="post" action="/find_pw"><input class="form-control mb-3" name="username" placeholder="아이디"><button class="btn btn-primary w-100">재설정 링크 생성</button></form></div></div>`);
  } catch (error) { next(error); }
});
app.get('/reset_pw', (req, res) => {
  if (!req.session.resetUserId) return res.redirect('/find_pw');
  page(req, res, '비밀번호 재설정', `<div class="card mx-auto" style="max-width:420px"><div class="card-body p-4"><h4 class="fw-bold mb-3">새 비밀번호</h4>
    <form method="post" action="/reset_pw"><input class="form-control mb-3" type="password" name="new_pw" placeholder="새 비밀번호"><button class="btn btn-primary w-100">변경</button></form></div></div>`);
});
app.post('/reset_pw', async (req, res, next) => {
  try {
    if (!req.session.resetUserId) return res.redirect('/find_pw');
    await pool.query('UPDATE users SET password=? WHERE id=?', [hashPassword(String(req.body.new_pw || '')), req.session.resetUserId]);
    delete req.session.resetUserId;
    res.redirect('/login');
  } catch (error) { next(error); }
});
app.all('/change_pw', async (req, res, next) => {
  try {
    if (!req.user) return res.redirect('/login');
    // [VULN] CSRF — 토큰 없음, GET 으로도 비밀번호 변경 가능
    const nw = String(req.query.new_pw || req.body.new_pw || '');
    if (nw) {
      await pool.query('UPDATE users SET password=? WHERE id=?', [hashPassword(nw), req.user.id]);
      return res.send("<script>alert('비밀번호가 변경되었습니다.');location.href='/';</script>");
    }
    page(req, res, '비밀번호 변경', `<div class="card mx-auto" style="max-width:420px"><div class="card-body p-4"><h4 class="fw-bold mb-3">비밀번호 변경</h4>
      <p class="text-secondary small">※ CSRF 실습: GET <code>/change_pw?new_pw=해킹</code> 으로도 변경됩니다.</p>
      <form method="post" action="/change_pw"><input class="form-control mb-3" type="password" name="new_pw" placeholder="새 비밀번호"><button class="btn btn-primary w-100">변경</button></form></div></div>`);
  } catch (error) { next(error); }
});

// ── 프로필 — [VULN] IDOR + 저장형 XSS(bio) + Mass Assignment(수정) ──
app.get('/profile', async (req, res, next) => {
  try {
    // [VULN] IDOR — user_id 파라미터로 타인 프로필 열람, 권한 검사 없음
    const uid = req.query.user_id || (req.user && req.user.id);
    if (!uid) return res.redirect('/login');
    const [rows] = await pool.query('SELECT * FROM users WHERE id=' + pool.escape(uid));
    const u = rows[0];
    if (!u) return page(req, res, '프로필', '<div class="card"><div class="card-body p-4">사용자를 찾을 수 없습니다.</div></div>', 404);
    let body = `<div class="card mx-auto" style="max-width:560px"><div class="card-body p-4"><h4 class="fw-bold mb-3"><i class="bi bi-person-badge"></i> ${h(u.username)} 프로필</h4>
      <table class="table"><tr><th>ID</th><td>${u.id}</td></tr><tr><th>이름</th><td>${h(u.name)}</td></tr><tr><th>이메일</th><td>${h(u.email)}</td></tr>
      <tr><th>권한</th><td>${h(u.role)}</td></tr><tr><th>포인트</th><td>${u.points}</td></tr>
      <tr><th>소개</th><td>${u.bio || ''}</td></tr>`; // [VULN] Stored XSS (bio)
    body += `<tr><th>마지막 IP</th><td>${h(u.last_ip)}</td></tr></table>`;
    if (req.user && req.user.id === u.id) body += '<a class="btn btn-outline-primary" href="/profile/edit">프로필 수정</a>';
    body += '</div></div>';
    page(req, res, '프로필', body);
  } catch (error) { next(error); }
});
app.get('/profile/edit', (req, res) => {
  if (!req.user) return res.redirect('/login');
  const u = req.user;
  page(req, res, '프로필 수정', `<div class="card mx-auto" style="max-width:480px"><div class="card-body p-4"><h4 class="fw-bold mb-3">프로필 수정</h4>
    <form method="post" action="/profile/edit"><input class="form-control mb-2" name="email" value="${h(u.email)}" placeholder="이메일">
    <textarea class="form-control mb-3" name="bio" placeholder="소개 (HTML 허용 — 저장형 XSS)">${h(u.bio || '')}</textarea><button class="btn btn-primary w-100">저장</button></form></div></div>`);
});
app.post('/profile/edit', async (req, res, next) => {
  try {
    if (!req.user) return res.redirect('/login');
    // [VULN] Mass Assignment — role/points 도 폼으로 덮어쓰기 가능
    const role = String(req.body.role || req.user.role);
    const points = req.body.points !== undefined ? num(req.body.points, req.user.points) : req.user.points;
    await pool.query('UPDATE users SET email=?, bio=?, role=?, points=? WHERE id=?',
      [String(req.body.email || ''), String(req.body.bio || ''), role, points, req.user.id]);
    res.redirect('/profile?user_id=' + req.user.id);
  } catch (error) { next(error); }
});

// ============================================================
// 서버 도구 — 명령주입 / 경로탐색 / SSRF·오픈리다이렉트 / XSS / LFI
// ============================================================
app.all('/ping', (req, res) => {
  let output = '';
  if (req.method === 'POST') {
    const ip = String(req.body.ip || '');
    // [VULN] OS Command Injection — 입력을 셸에 그대로 전달
    try { output = cp.execSync(`ping -c 2 ${ip} 2>&1`, {timeout: 8000}).toString(); }
    catch (e) { output = String((e.stdout || '') + (e.stderr || '') || e.message); }
  }
  page(req, res, '서버 진단(ping)', `<div class="card"><div class="card-body p-4"><h4 class="fw-bold mb-3"><i class="bi bi-terminal"></i> 서버 진단 도구</h4>
    <p class="text-secondary small">호스트로 ping 을 보냅니다. (명령 주입 실습: <code>127.0.0.1; id</code>)</p>
    <form method="post" action="/ping" class="d-flex gap-2 mb-3"><input class="form-control" name="ip" placeholder="127.0.0.1"><button class="btn btn-primary">실행</button></form>
    ${output ? `<pre>${h(output)}</pre>` : ''}</div></div>`);
});
app.get('/download', (req, res) => {
  const file = String(req.query.file || '');
  if (!file) return page(req, res, '자료 다운로드', `<div class="card"><div class="card-body p-4"><h4 class="fw-bold mb-3">자료실</h4>
    <p class="text-secondary small">파일명을 지정해 다운로드합니다. (경로 탐색 실습: <code>?file=../db.js</code>)</p>
    <a class="btn btn-outline-primary" href="/download?file=sample.txt">sample.txt 받기</a></div></div>`);
  // [VULN] Path Traversal — 기준 디렉터리 검증 없이 경로 결합
  const target = path.resolve(UPLOAD_DIR, file);
  if (fs.existsSync(target) && fs.statSync(target).isFile()) {
    res.setHeader('Content-Disposition', `attachment; filename="${path.basename(file)}"`);
    return res.sendFile(target);
  }
  res.status(404).send('파일을 찾을 수 없습니다: ' + h(target));
});
app.get('/file/:id', async (req, res, next) => {
  try {
    const [rows] = await pool.query('SELECT * FROM files WHERE id=' + pool.escape(num(req.params.id)));
    const f = rows[0];
    if (!f) return res.status(404).send('파일 없음');
    const target = path.join(UPLOAD_DIR, f.filename);
    if (!fs.existsSync(target)) return res.status(404).send('파일 없음');
    res.setHeader('Content-Disposition', `attachment; filename="${path.basename(f.orig_name)}"`);
    res.sendFile(target);
  } catch (error) { next(error); }
});
app.get('/utils/url_preview', async (req, res) => {
  const target = String(req.query.url || '');
  if (!target) return res.send('No URL provided. 예: /utils/url_preview?url=http://127.0.0.1:3000/');
  // [VULN] SSRF / Open Redirect — 임의 URL 요청/리다이렉트
  try {
    if (/^https?:\/\//i.test(target)) {
      const ctrl = new AbortController(); const t = setTimeout(() => ctrl.abort(), 4000);
      const r = await fetch(target, {signal: ctrl.signal, headers: {'User-Agent': 'BLACKOUT-Lab'}}).finally(() => clearTimeout(t));
      res.setHeader('Content-Type', 'text/plain; charset=utf-8');
      return res.send(await r.text());
    }
  } catch { /* fall through to redirect */ }
  res.redirect(target); // [VULN] Open Redirect
});
app.get('/xss', (req, res) => {
  const input = String(req.query.input || '');
  // [VULN] Reflected XSS (input 미이스케이프) + [VULN] DOM XSS (location.hash → innerHTML)
  page(req, res, 'XSS 실습', `<div class="card"><div class="card-body p-4"><h4 class="fw-bold mb-3"><i class="bi bi-bug"></i> XSS 실습</h4>
    <form class="d-flex gap-2 mb-3" method="get" action="/xss"><input class="form-control" name="input" value="${h(input)}" placeholder="입력"><button class="btn btn-primary">반영</button></form>
    <div class="alert alert-secondary"><b>Reflected 출력:</b><br>${input}</div>
    <div class="alert alert-secondary"><b>DOM 출력(#해시):</b> <span id="domsink"></span></div>
    <p class="text-secondary small">DOM 실습: 주소 끝에 <code>#&lt;img src=x onerror=alert(1)&gt;</code> 추가</p>
    <script>document.getElementById("domsink").innerHTML = decodeURIComponent(location.hash.slice(1));</script></div></div>`);
});
app.get('/theme', (req, res) => {
  const name = String(req.query.name || '');
  let out = '';
  if (name) {
    // [VULN] LFI / 소스 노출 — 사용자 입력 경로를 그대로 읽어 출력 (경로 탐색 허용)
    try { out = fs.readFileSync(path.resolve(APP_DIR, name), 'utf8'); }
    catch (e) { out = '[read error] ' + e.message; }
  }
  page(req, res, '테마 미리보기', `<div class="card"><div class="card-body p-4"><h4 class="fw-bold mb-3"><i class="bi bi-palette"></i> 테마 미리보기</h4>
    <p class="text-secondary small">테마 파일을 불러옵니다. (LFI 실습: <code>?name=/etc/passwd</code>, 소스: <code>?name=../db.js</code>)</p>
    <form class="d-flex gap-2 mb-3" method="get" action="/theme"><input class="form-control" name="name" value="${h(name)}" placeholder="테마 이름"><button class="btn btn-primary">불러오기</button></form>
    ${name ? `<div class="border rounded p-3 bg-light"><pre>${h(out)}</pre></div>` : ''}</div></div>`);
});
// [VULN] 보안 설정 오류 / 민감정보·디버그 노출
app.get('/debug', (req, res) => {
  res.setHeader('Content-Type', 'text/plain; charset=utf-8');
  res.send('=== BLACKOUT Node DEBUG ===\n' + JSON.stringify({
    env: process.env, versions: process.versions, cwd: process.cwd(),
    jwt_secret_hint: 'weak', uploads: UPLOAD_DIR
  }, null, 2));
});

// ============================================================
// 상점 — [VULN] 가격/수량/합계 조작 + 음수 결제 + 경쟁 조건
// ============================================================
app.get('/shop', async (req, res, next) => {
  try {
    if (!req.user) return res.redirect('/login');
    const [products] = await pool.query('SELECT * FROM products ORDER BY id');
    let body = `<div class="card mb-3"><div class="card-body"><h4 class="fw-bold"><i class="bi bi-bag"></i> 포인트 상점</h4>
      <p class="text-secondary mb-0">보유 포인트: <b>${req.user.points}P</b> · 실습: 구매 요청의 <code>unit_price</code>/<code>quantity</code>/<code>total</code> 을 조작해 보세요.</p></div></div><div class="row g-3">`;
    for (const p of products)
      body += `<div class="col-md-4"><div class="card h-100"><div class="card-body"><h5 class="fw-bold">${h(p.name)}</h5>
        <p class="text-secondary small">${h(p.description)}</p><p class="fw-bold">${p.price}P <span class="text-secondary small">재고 ${p.stock}</span></p>
        <form method="post" action="/shop/buy"><input type="hidden" name="product_id" value="${p.id}">
        <input type="hidden" name="unit_price" value="${p.price}">
        <div class="input-group input-group-sm mb-2"><span class="input-group-text">수량</span><input class="form-control" type="number" name="quantity" value="1"></div>
        <button class="btn btn-success w-100">구매</button></form></div></div></div>`;
    body += '</div>';
    page(req, res, '상점', body);
  } catch (error) { next(error); }
});
app.post('/shop/buy', async (req, res, next) => {
  try {
    if (!req.user) return res.redirect('/login');
    const pid = num(req.body.product_id);
    // [VULN] Insecure Design — 가격/수량/합계를 서버가 재계산하지 않고 입력을 신뢰 + 락 없음(경쟁조건)
    const qty = num(req.body.quantity, 1);
    const unit = num(req.body.unit_price, 0);
    const total = req.body.total !== undefined ? num(req.body.total) : unit * qty;
    await pool.query('INSERT INTO orders (user_id,product_id,quantity,unit_price,total) VALUES (?,?,?,?,?)',
      [req.user.id, pid, qty, unit, total]);
    await pool.query('UPDATE users SET points = points - ? WHERE id=?', [total, req.user.id]); // 음수 total → 포인트 증가
    const [[{points}]] = await pool.query('SELECT points FROM users WHERE id=' + pool.escape(req.user.id));
    let body = `<div class="card"><div class="card-body p-4"><h4 class="fw-bold">구매 완료</h4>
      <p>결제 금액: <b>${total}P</b> (단가 ${unit} × 수량 ${qty})</p><p>남은 포인트: <b>${points}P</b></p>`;
    if (points >= 1000000) body += `<div class="alert alert-success">${h(flag('shop_logic_bypass'))}</div>`;
    body += '<a class="btn btn-outline-primary" href="/shop">상점으로</a></div></div>';
    page(req, res, '구매 완료', body);
  } catch (error) { next(error); }
});

// ============================================================
// 관리자 — [VULN] 쿠키 우회 / SQLi 검색 / 저장형 XSS(UA) / 강제 브라우징 / CSV 인젝션
// ============================================================
function denyAdmin(req, res) {
  page(req, res, '접근 거부', `<div class="card"><div class="card-body p-4"><h4 class="text-danger">접근 거부: 관리자(role=admin) 권한 필요</h4>
    <p>현재 권한: <code>${h(req.cookies.role || 'user')}</code></p>
    <p class="text-secondary small">실습: 쿠키 <code>role=admin</code> 으로 바꾸면 접근됩니다.</p></div></div>`, 403);
}
app.get('/admin', async (req, res, next) => {
  try {
    if (!isAdminReq(req)) return denyAdmin(req, res);
    const qUser = String(req.query.q_user || '');
    const qLog = String(req.query.q_log || '');
    // [VULN] SQL Injection (관리자 사용자/로그 검색)
    const [users] = await pool.query(qUser
      ? `SELECT id,username,email,role,points FROM users WHERE username LIKE '%${qUser}%' OR email LIKE '%${qUser}%' ORDER BY id`
      : 'SELECT id,username,email,role,points FROM users ORDER BY id');
    const [logs] = await pool.query(qLog
      ? `SELECT * FROM login_logs WHERE username LIKE '%${qLog}%' OR ip_address LIKE '%${qLog}%' ORDER BY id DESC LIMIT 100`
      : 'SELECT * FROM login_logs ORDER BY id DESC LIMIT 100');
    const [[s1]] = await pool.query('SELECT COUNT(*) c FROM users');
    const [[s2]] = await pool.query('SELECT COUNT(*) c FROM posts');
    const [[s3]] = await pool.query('SELECT COUNT(*) c FROM orders');
    let body = `<div class="card mb-3"><div class="card-body"><h4 class="fw-bold"><i class="bi bi-shield-lock-fill text-danger"></i> 관리자 대시보드</h4>
      <p class="text-secondary small">민감정보: ${h(flag('admin_cookie_bypass'))}</p>
      <div class="d-flex gap-3"><span class="badge bg-primary">사용자 ${s1.c}</span><span class="badge bg-info">게시글 ${s2.c}</span><span class="badge bg-success">주문 ${s3.c}</span>
      <a class="btn btn-sm btn-outline-secondary" href="/admin/export.csv">사용자 CSV 내보내기</a></div></div></div>`;
    body += `<div class="card mb-3"><div class="card-body"><h5 class="fw-bold">사용자</h5>
      <form class="d-flex gap-2 mb-2" method="get" action="/admin"><input class="form-control" name="q_user" value="${h(qUser)}" placeholder="사용자 검색(SQLi)"><button class="btn btn-primary">검색</button></form>
      <table class="table table-sm"><thead><tr><th>ID</th><th>아이디</th><th>이메일</th><th>권한</th><th>P</th><th>작업</th></tr></thead><tbody>`;
    for (const x of users)
      body += `<tr><td>${x.id}</td><td>${h(x.username)}</td><td>${h(x.email)}</td><td>${h(x.role)}</td><td>${x.points}</td><td>
        <form method="post" action="/admin/user/${x.id}/role" class="d-inline"><input type="hidden" name="role" value="admin"><button class="btn btn-sm btn-outline-danger">admin화</button></form>
        <form method="post" action="/admin/user/${x.id}/delete" class="d-inline"><button class="btn btn-sm btn-outline-secondary">삭제</button></form></td></tr>`;
    body += '</tbody></table></div></div>';
    body += `<div class="card mb-3"><div class="card-body"><h5 class="fw-bold">공지 작성</h5>
      <form method="post" action="/admin/notice/create"><input class="form-control mb-2" name="title" placeholder="제목"><textarea class="form-control mb-2" name="content" placeholder="내용"></textarea><button class="btn btn-primary">등록</button></form></div></div>`;
    body += `<div class="card"><div class="card-body"><h5 class="fw-bold">로그인 로그</h5>
      <form class="d-flex gap-2 mb-2" method="get" action="/admin"><input class="form-control" name="q_log" value="${h(qLog)}" placeholder="로그 검색(SQLi)"><button class="btn btn-primary">검색</button></form>
      <table class="table table-sm"><thead><tr><th>시각</th><th>아이디</th><th>IP</th><th>UA</th></tr></thead><tbody>`;
    // [VULN] 저장형 XSS — User-Agent 를 이스케이프 없이 렌더(관리자 화면)
    for (const l of logs) body += `<tr><td class="small">${h(l.created_at)}</td><td>${h(l.username)}</td><td>${h(l.ip_address)}</td><td class="small">${l.user_agent}</td></tr>`;
    body += '</tbody></table></div></div>';
    page(req, res, '관리자', body);
  } catch (error) { next(error); }
});
app.post('/admin/user/:id/role', async (req, res, next) => {
  try { // [VULN] 강제 브라우징 — role 검사만, CSRF/계정검증 없음
    if (!isAdminReq(req)) return res.status(403).send('권한 없음');
    await pool.query('UPDATE users SET role=? WHERE id=?', [String(req.body.role || 'user'), num(req.params.id)]);
    res.redirect('/admin');
  } catch (error) { next(error); }
});
app.post('/admin/user/:id/delete', async (req, res, next) => {
  try {
    if (!isAdminReq(req)) return res.status(403).send('권한 없음');
    await pool.query('DELETE FROM users WHERE id=' + pool.escape(num(req.params.id)));
    res.redirect('/admin');
  } catch (error) { next(error); }
});
app.post('/admin/notice/create', async (req, res, next) => {
  try {
    if (!isAdminReq(req)) return res.status(403).send('권한 없음');
    const t = String(req.body.title || '').trim();
    const c = String(req.body.content || '').trim();
    if (t && c) await pool.query('INSERT INTO notices (title,content,author_id,author) VALUES (?,?,?,?)', [t, c, 1, 'admin']);
    res.redirect('/admin');
  } catch (error) { next(error); }
});
app.get('/admin/export.csv', async (req, res, next) => {
  try {
    if (!isAdminReq(req)) return res.status(403).send('권한 없음');
    const [rows] = await pool.query('SELECT id,username,email,role,points FROM users ORDER BY id');
    res.setHeader('Content-Type', 'text/csv; charset=utf-8');
    res.setHeader('Content-Disposition', 'attachment; filename="users.csv"');
    // [VULN] CSV/수식 인젝션 — =,+,-,@ 로 시작하는 값을 이스케이프 없이 기록
    let csv = 'id,username,email,role,points\n';
    for (const r of rows) csv += `${r.id},${r.username},${r.email},${r.role},${r.points}\n`;
    res.send(csv);
  } catch (error) { next(error); }
});

// ============================================================
// API — JWT / CORS / XXE / 역직렬화 / SSTI / 프로토타입 오염 / 수집
// ============================================================
app.post('/api/jwt_verify', (req, res) => {
  const token = req.body.token || '';
  if (!token) return res.status(400).json({error: 'No token'});
  const p = jwtDecode(token);
  if (p === null) return res.status(401).json({error: 'invalid token'});
  if ((p.role || '') === 'admin')
    return res.json({status: 'SUCCESS', message: '관리자 권한 획득!', flag: flag('jwt_forged'), payload: p});
  res.json({status: 'OK', message: '일반 사용자 토큰', payload: p});
});
app.all('/api/user_data', (req, res) => {
  // [VULN] CORS 오설정 — Origin 반사 + credentials 허용
  res.setHeader('Access-Control-Allow-Origin', req.headers.origin || '*');
  res.setHeader('Access-Control-Allow-Credentials', 'true');
  res.json({sensitive: true, api_key: 'USER_API_KEY_54321', flag: flag('cors_leak'), credit: 500});
});
app.post('/api/import', express.text({type: () => true, limit: '1mb'}), (req, res) => {
  const raw = typeof req.body === 'string' ? req.body : '';
  if (!raw) return res.status(400).json({error: 'No XML'});
  try {
    // [VULN] XXE — 외부/SYSTEM 엔티티 해석
    res.setHeader('Content-Type', 'text/plain; charset=utf-8');
    res.send('XML 파싱 결과:\n' + adv.vulnerableXmlParse(raw));
  } catch (e) { res.status(500).json({error: 'XML parse error', detail: e.message}); }
});
app.post('/api/config', (req, res) => {
  const data = req.body.data || '';
  if (!data) return res.status(400).json({error: 'No data'});
  try {
    // [VULN] 안전하지 않은 역직렬화 — base64(JSON) 를 신뢰 불가 상태로 복원
    const obj = adv.vulnerableDeserialize(Buffer.from(String(data), 'base64').toString('utf8'));
    res.setHeader('Content-Type', 'text/plain; charset=utf-8');
    res.send('역직렬화 완료: ' + typeof obj + '\n' + JSON.stringify(obj, (k, v) => typeof v === 'function' ? '[Function]' : v, 2));
  } catch (e) { res.status(500).json({error: 'deserialize error', detail: e.message}); }
});
app.all('/api/render', (req, res) => {
  // [VULN] SSTI 유사 — {{ expr }} 를 서버에서 평가
  const theme = (req.body && req.body.theme) || req.query.theme || '';
  res.setHeader('Content-Type', 'text/plain; charset=utf-8');
  res.send(adv.sstiRender(theme));
});
app.post('/api/merge', (req, res) => {
  // [VULN] 프로토타입 오염 (Node 전용)
  const base = {};
  try { adv.unsafeMerge(base, req.body || {}); } catch { /* ignore */ }
  const polluted = {}; // 새 객체가 오염된 프로토타입 속성을 상속하는지 확인
  const leaked = polluted.polluted !== undefined || polluted.isAdmin !== undefined;
  res.json({
    merged: base,
    polluted_proto: {polluted: ({}).polluted, isAdmin: ({}).isAdmin},
    flag: leaked ? flag('prototype_pollution') : null,
    hint: leaked ? '프로토타입이 오염되었습니다!' : '{"__proto__":{"isAdmin":true}} 를 JSON 으로 전송해 보세요.'
  });
});
// [VULN] ReDoS (Node 전용) — 치명적 백트래킹 정규식을 사용자 입력에 적용 → 이벤트 루프 블로킹
app.get('/api/validate', (req, res) => {
  const value = String(req.query.value || '').slice(0, 100);
  const re = /^(a+)+$/; // 취약한 패턴
  const t = Date.now();
  const matched = re.test(value);
  res.json({matched, elapsed_ms: Date.now() - t,
    hint: 'value 앞에 a 를 늘리고 끝에 "!" 를 붙이면 elapsed_ms 가 폭증한다(이벤트 루프 블로킹).'});
});
app.get('/api/collect', (req, res) => {
  try { fs.appendFileSync('/tmp/collected.log', new Date().toISOString() + ' ' + String(req.query.c || '') + '\n'); } catch { /* ignore */ }
  res.json({ok: true});
});

// ── 실습실 ──
app.get('/practice', (_req, res) => {
  const vulns = catalog.vulns.filter(item => !item.engine || item.engine === 'all' || item.engine === 'node');
  res.render('practice', {title: '실습실', categories: catalog.categories, vulns, exploits: exploits.files,
    user: res.locals.user, isAdmin: res.locals.isAdmin, notice: res.locals.notice});
});

// ── 예제 공격 파일 다운로드/소스 (?raw=1 은 인라인 표시) ──
app.get('/exploit', (req, res) => {
  const name = path.basename(String(req.query.name || ''));
  const meta = exploits.files.find(x => x.name === name);
  if (!meta) return res.status(404).send('예제 파일을 찾을 수 없습니다.');
  const target = path.join(EXPLOIT_DIR, meta.store || name);
  if (!fs.existsSync(target)) return res.status(404).send('예제 파일을 찾을 수 없습니다.');
  // 일부 파일은 호스트 보안 오탐 방지를 위해 .b64 로 저장 → 서빙 시 원본으로 복원
  const data = meta.encoded ? Buffer.from(fs.readFileSync(target, 'utf8'), 'base64') : fs.readFileSync(target);
  res.setHeader('Content-Type', 'text/plain; charset=utf-8');
  if (!req.query.raw) res.setHeader('Content-Disposition', `attachment; filename="${name}"`);
  res.send(data);
});

// ── 사고들 (AI 공격 접근 기록) ──
app.get('/thoughts', (_req, res) => {
  res.render('thoughts', {title: '사고들', approaches, user: res.locals.user, isAdmin: res.locals.isAdmin, notice: res.locals.notice});
});

// 404 / 오류 — [VULN] verbose 오류(스택) 노출
app.use((req, res) => page(req, res, '404', '<div class="card"><div class="card-body p-5 text-center"><h3>404 · 페이지를 찾을 수 없습니다</h3><a href="/">홈으로</a></div></div>', 404));
app.use((error, req, res, _next) => {
  console.error(error);
  res.status(500).type('html').send(`<pre style="padding:1rem">500 Internal Error\n\n${h(error.stack || error.message)}</pre>`);
});

initialize().then(() => app.listen(3000, '0.0.0.0', () => console.log('BLACKOUT Node (vuln lab) ready on 3000')))
  .catch(error => { console.error(error); process.exit(1); });

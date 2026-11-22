const express = require('express');
const session = require('express-session');
const cookieParser = require('cookie-parser');
const crypto = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');
const {pool, initialize, hashPassword, checkPassword} = require('./db');
const {createRouter: createOAuthRouter} = require('./oauth');

const app = express();
const catalog = JSON.parse(fs.readFileSync(path.join(__dirname, 'data/vulns.json'), 'utf8'));
app.set('view engine', 'ejs');
app.set('views', path.join(__dirname, 'views'));
app.use(express.urlencoded({extended: false, limit: '64kb'}));
app.use(cookieParser());
app.use(session({
  secret: process.env.SESSION_SECRET || crypto.randomBytes(32).toString('hex'),
  resave: false, saveUninitialized: false,
  cookie: {httpOnly: true, sameSite: 'lax'}
}));
app.get('/style.css', (_req, res) => res.sendFile(path.join(__dirname, 'public/style.css')));

app.use(async (req, res, next) => {
  try {
    req.user = null;
    if (req.session.userId) {
      const [rows] = await pool.execute('SELECT id,username,name,email,role,bio,points,created_at FROM users WHERE id=?',
        [req.session.userId]);
      req.user = rows[0] || null;
    }
    if (!req.session.csrf) req.session.csrf = crypto.randomBytes(24).toString('hex');
    res.locals.user = req.user;
    res.locals.csrf = req.session.csrf;
    res.locals.notice = req.session.notice || null;
    delete req.session.notice;
    next();
  } catch (error) { next(error); }
});

app.use((req, res, next) => {
  if (req.method === 'POST' && (typeof req.body.csrf !== 'string' || req.body.csrf.length !== 48 ||
    !crypto.timingSafeEqual(Buffer.from(req.body.csrf), Buffer.from(req.session.csrf))))
    return res.status(403).send('요청을 확인할 수 없습니다.');
  next();
});

app.use(createOAuthRouter(pool, hashPassword));

function requireUser(req, res, next) {
  if (!req.user) return res.redirect('/login');
  next();
}
function number(value, fallback = 1) {
  const parsed = Number.parseInt(value, 10);
  return Number.isSafeInteger(parsed) && parsed > 0 ? parsed : fallback;
}
function render(res, view, data = {}) { res.render(view, data); }

app.get('/', async (req, res, next) => {
  try {
    const page = number(req.query.page);
    const per = 10;
    const [[{total}]] = await pool.execute('SELECT COUNT(*) AS total FROM posts');
    const pages = Math.max(1, Math.ceil(total / per));
    const current = Math.min(page, pages);
    const [posts] = await pool.query(
      'SELECT p.*, (SELECT COUNT(*) FROM comments c WHERE c.post_id=p.id) AS comment_count FROM posts p ORDER BY p.id DESC LIMIT ? OFFSET ?',
      [per, (current - 1) * per]);
    const [notices] = await pool.execute('SELECT id,title,author,created_at FROM notices ORDER BY id DESC LIMIT 3');
    render(res, 'index', {title: '게시판', posts, notices, page: current, pages});
  } catch (error) { next(error); }
});

app.get('/register', (_req, res) => render(res, 'register', {title: '회원가입', error: null}));
app.post('/register', async (req, res, next) => {
  try {
    const username = String(req.body.username || '').trim();
    const password = String(req.body.password || '');
    const name = String(req.body.name || '').trim();
    const email = String(req.body.email || '').trim();
    if (!/^[a-zA-Z0-9_]{3,40}$/.test(username) || password.length < 8 || name.length > 80 ||
      email.length > 120 || (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)))
      return res.status(400).render('register', {title: '회원가입', error: '입력값을 확인해 주세요.'});
    try {
      await pool.execute('INSERT INTO users (username,password,name,email) VALUES (?,?,?,?)',
        [username, hashPassword(password), name || username, email || null]);
    } catch (error) {
      if (error.code === 'ER_DUP_ENTRY')
        return res.status(409).render('register', {title: '회원가입', error: '이미 사용 중인 아이디입니다.'});
      throw error;
    }
    req.session.notice = '가입이 완료되었습니다. 로그인해 주세요.';
    res.redirect('/login');
  } catch (error) { next(error); }
});

app.get('/login', (_req, res) => render(res, 'login', {title: '로그인', error: null}));
app.post('/login', async (req, res, next) => {
  try {
    const [rows] = await pool.execute('SELECT id,username,password FROM users WHERE username=? LIMIT 1',
      [String(req.body.username || '')]);
    const account = rows[0];
    if (!account || !checkPassword(String(req.body.password || ''), account.password))
      return res.status(401).render('login', {title: '로그인', error: '아이디 또는 비밀번호를 확인해 주세요.'});
    req.session.regenerate(error => {
      if (error) return next(error);
      req.session.userId = account.id;
      req.session.notice = '다시 오신 것을 환영합니다.';
      res.redirect('/');
    });
  } catch (error) { next(error); }
});
app.post('/logout', requireUser, (req, res, next) => req.session.destroy(error => {
  if (error) return next(error);
  res.clearCookie('connect.sid');
  res.redirect('/');
}));

app.get('/post/write', requireUser, (_req, res) => render(res, 'post_form', {title: '새 글', error: null}));
app.post('/post/write', requireUser, async (req, res, next) => {
  try {
    const title = String(req.body.title || '').trim();
    const content = String(req.body.content || '').trim();
    if (!title || title.length > 200 || !content || content.length > 30000)
      return res.status(400).render('post_form', {title: '새 글', error: '제목과 내용을 확인해 주세요.'});
    const [result] = await pool.execute('INSERT INTO posts (title,content,author_id,author) VALUES (?,?,?,?)',
      [title, content, req.user.id, req.user.username]);
    res.redirect('/post/' + result.insertId);
  } catch (error) { next(error); }
});
app.get('/post/:id', async (req, res, next) => {
  try {
    const id = number(req.params.id, 0);
    if (!id) return res.sendStatus(404);
    const [rows] = await pool.execute('SELECT * FROM posts WHERE id=?', [id]);
    const post = rows[0];
    if (!post) return res.sendStatus(404);
    await pool.execute('UPDATE posts SET views=views+1 WHERE id=?', [id]);
    post.views += 1;
    const [comments] = await pool.execute('SELECT id,author,content,created_at FROM comments WHERE post_id=? ORDER BY id', [id]);
    const [[{likes}]] = await pool.execute('SELECT COUNT(*) AS likes FROM post_likes WHERE post_id=?', [id]);
    render(res, 'post', {title: post.title, post, comments, likes});
  } catch (error) { next(error); }
});
app.post('/post/:id/comment', requireUser, async (req, res, next) => {
  try {
    const id = number(req.params.id, 0);
    const content = String(req.body.content || '').trim();
    if (!id || !content || content.length > 2000) return res.sendStatus(400);
    const [posts] = await pool.execute('SELECT id FROM posts WHERE id=?', [id]);
    if (!posts.length) return res.sendStatus(404);
    await pool.execute('INSERT INTO comments (post_id,author_id,author,content) VALUES (?,?,?,?)',
      [id, req.user.id, req.user.username, content]);
    res.redirect('/post/' + id);
  } catch (error) { next(error); }
});
app.post('/post/:id/like', requireUser, async (req, res, next) => {
  try {
    const id = number(req.params.id, 0);
    const [posts] = await pool.execute('SELECT id FROM posts WHERE id=?', [id]);
    if (!posts.length) return res.sendStatus(404);
    await pool.execute('INSERT IGNORE INTO post_likes (post_id,user_id) VALUES (?,?)', [id, req.user.id]);
    res.redirect('/post/' + id);
  } catch (error) { next(error); }
});

app.get('/profile', requireUser, async (req, res, next) => {
  try {
    const [[{posts}]] = await pool.execute('SELECT COUNT(*) AS posts FROM posts WHERE author_id=?', [req.user.id]);
    const [[{comments}]] = await pool.execute('SELECT COUNT(*) AS comments FROM comments WHERE author_id=?', [req.user.id]);
    const [recent] = await pool.execute('SELECT id,title,created_at FROM posts WHERE author_id=? ORDER BY id DESC LIMIT 5',
      [req.user.id]);
    render(res, 'profile', {title: '마이페이지', posts, comments, recent});
  } catch (error) { next(error); }
});
app.get('/shop', async (_req, res, next) => {
  try {
    const [products] = await pool.execute('SELECT * FROM products ORDER BY id');
    render(res, 'shop', {title: '포인트 상점', products});
  } catch (error) { next(error); }
});
app.post('/shop/:id/buy', requireUser, async (req, res, next) => {
  const connection = await pool.getConnection();
  try {
    await connection.beginTransaction();
    const [products] = await connection.execute('SELECT * FROM products WHERE id=? FOR UPDATE', [number(req.params.id, 0)]);
    const [users] = await connection.execute('SELECT points FROM users WHERE id=? FOR UPDATE', [req.user.id]);
    const product = products[0];
    if (!product || !users.length || product.stock < 1 || users[0].points < product.price) {
      await connection.rollback();
      req.session.notice = '재고 또는 포인트가 부족합니다.';
      return res.redirect('/shop');
    }
    await connection.execute('UPDATE products SET stock=stock-1 WHERE id=?', [product.id]);
    await connection.execute('UPDATE users SET points=points-? WHERE id=?', [product.price, req.user.id]);
    await connection.execute('INSERT INTO orders (user_id,product_id,quantity,unit_price,total) VALUES (?,?,?,?,?)',
      [req.user.id, product.id, 1, product.price, product.price]);
    await connection.commit();
    req.session.notice = '구매가 완료되었습니다.';
    res.redirect('/shop');
  } catch (error) { await connection.rollback(); next(error); }
  finally { connection.release(); }
});

app.get('/admin', requireUser, async (req, res, next) => {
  if (req.user.role !== 'admin') return res.sendStatus(403);
  try {
    const [users] = await pool.execute('SELECT id,username,role,points,created_at FROM users ORDER BY id DESC LIMIT 50');
    const [notices] = await pool.execute('SELECT id,title,created_at FROM notices ORDER BY id DESC LIMIT 10');
    render(res, 'admin', {title: '관리자', users, notices});
  } catch (error) { next(error); }
});
app.post('/admin/notice', requireUser, async (req, res, next) => {
  if (req.user.role !== 'admin') return res.sendStatus(403);
  try {
    const title = String(req.body.title || '').trim();
    const content = String(req.body.content || '').trim();
    if (!title || title.length > 200 || !content || content.length > 10000) return res.sendStatus(400);
    await pool.execute('INSERT INTO notices (title,content,author_id,author) VALUES (?,?,?,?)',
      [title, content, req.user.id, req.user.username]);
    res.redirect('/admin');
  } catch (error) { next(error); }
});
app.get('/practice', (_req, res) => {
  const vulns = catalog.vulns.filter(item => !item.engine || item.engine === 'all' || item.engine === 'node');
  render(res, 'practice', {title: '실습실', categories: catalog.categories, vulns});
});

app.use((_req, res) => res.status(404).render('message', {title: '페이지 없음', message: '요청한 페이지를 찾을 수 없습니다.'}));
app.use((error, _req, res, _next) => {
  console.error(error);
  res.status(500).render('message', {title: '오류', message: '요청을 처리하지 못했습니다.'});
});

initialize().then(() => app.listen(3000, '0.0.0.0', () => console.log('Node community ready on 3000')))
  .catch(error => { console.error(error); process.exit(1); });

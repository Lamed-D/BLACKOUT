// 독립 SSO 경로(Google/Kakao/Naver). 기존 로컬 로그인 흐름은 변경하지 않는다.
// PHP 엔진의 oauth.php 동작을 Node 환경(네이티브 fetch)으로 그대로 옮긴 안전한 구현.
const express = require('express');
const crypto = require('node:crypto');

const PROVIDERS = {
  google: {
    prefix: 'GOOGLE',
    authorize: 'https://accounts.google.com/o/oauth2/v2/auth',
    token: 'https://oauth2.googleapis.com/token',
    profile: 'https://www.googleapis.com/oauth2/v2/userinfo',
    scope: 'openid email profile',
  },
  kakao: {
    prefix: 'KAKAO',
    authorize: 'https://kauth.kakao.com/oauth/authorize',
    token: 'https://kauth.kakao.com/oauth/token',
    profile: 'https://kapi.kakao.com/v2/user/me',
    scope: 'profile_nickname account_email',
  },
  naver: {
    prefix: 'NAVER',
    authorize: 'https://nid.naver.com/oauth2.0/authorize',
    token: 'https://nid.naver.com/oauth2.0/token',
    profile: 'https://openapi.naver.com/v1/nid/me',
    scope: '',
  },
};

function config(provider) {
  const base = PROVIDERS[provider];
  if (!base) return null;
  const p = base.prefix;
  // 엔드포인트는 환경변수로 덮어쓸 수 있다(로컬 Mock IdP·자체호스팅 테스트용). 미설정 시 실제 공급자 URL.
  return {
    ...base,
    id: process.env[p + '_CLIENT_ID'] || '',
    secret: process.env[p + '_CLIENT_SECRET'] || '',
    authorize: process.env[p + '_AUTHORIZE_URL'] || base.authorize,
    token: process.env[p + '_TOKEN_URL'] || base.token,
    profile: process.env[p + '_PROFILE_URL'] || base.profile,
  };
}

function callbackUrl(provider) {
  const root = (process.env.OAUTH_REDIRECT_BASE || 'http://127.0.0.1:8102').replace(/\/+$/, '');
  return `${root}/oauth/${provider}/callback`;
}

async function request(url, { form = null, bearer = null } = {}) {
  const headers = { Accept: 'application/json' };
  if (form) headers['Content-Type'] = 'application/x-www-form-urlencoded';
  if (bearer) headers.Authorization = 'Bearer ' + bearer;
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), 8000);
  let response;
  try {
    response = await fetch(url, {
      method: form ? 'POST' : 'GET',
      headers,
      body: form ? new URLSearchParams(form).toString() : undefined,
      signal: controller.signal,
      redirect: 'error',
    });
  } finally {
    clearTimeout(timer);
  }
  if (!response.ok) throw new Error('공급자 요청이 실패했습니다.');
  const text = await response.text();
  if (text.length > 1048576) throw new Error('공급자 응답이 너무 큽니다.');
  let data;
  try { data = JSON.parse(text); } catch { throw new Error('공급자 응답 형식이 올바르지 않습니다.'); }
  if (!data || typeof data !== 'object') throw new Error('공급자 응답 형식이 올바르지 않습니다.');
  return data;
}

// [remoteId, name, avatar, verifiedEmail] — 검증되지 않은 이메일은 기존 계정 매칭에 사용하지 않는다.
function parseProfile(provider, data) {
  if (provider === 'google') {
    return [String(data.id || ''), String(data.name || ''), String(data.picture || ''),
      data.verified_email ? String(data.email || '') : ''];
  }
  if (provider === 'kakao') {
    const account = data.kakao_account || {};
    const profile = account.profile || {};
    const emailOk = account.is_email_verified && account.is_email_valid;
    return [String(data.id || ''), String(profile.nickname || ''), String(profile.profile_image_url || ''),
      emailOk ? String(account.email || '') : ''];
  }
  const response = data.response || {};
  // Naver 응답에는 검증된 이메일 표시가 없어 기존 계정 이메일 매칭에 사용하지 않는다.
  return [String(response.id || ''), String(response.name || response.nickname || ''),
    String(response.profile_image || ''), ''];
}

function createRouter(pool, hashPassword) {
  const router = express.Router();

  router.get('/oauth/:provider/login', (req, res) => {
    const provider = req.params.provider;
    const cfg = config(provider);
    if (!cfg) return res.sendStatus(404);
    if (!cfg.id || !cfg.secret) {
      req.session.notice = '소셜 로그인 키가 설정되지 않았습니다. 관리자에게 문의하세요.';
      return res.redirect('/login');
    }
    const state = crypto.randomBytes(32).toString('hex');
    req.session['oauthState_' + provider] = state;
    const params = new URLSearchParams({
      client_id: cfg.id,
      redirect_uri: callbackUrl(provider),
      response_type: 'code',
      state,
    });
    if (cfg.scope) params.set('scope', cfg.scope);
    res.redirect(cfg.authorize + '?' + params.toString());
  });

  router.get('/oauth/:provider/callback', async (req, res, next) => {
    const provider = req.params.provider;
    const cfg = config(provider);
    if (!cfg) return res.sendStatus(404);
    const expected = req.session['oauthState_' + provider] || '';
    delete req.session['oauthState_' + provider];
    const state = typeof req.query.state === 'string' ? req.query.state : '';
    const code = typeof req.query.code === 'string' ? req.query.code : '';
    if (!expected || !state || !code ||
      expected.length !== state.length ||
      !crypto.timingSafeEqual(Buffer.from(expected), Buffer.from(state))) {
      return res.status(400).render('message', { title: 'SSO 오류', message: '로그인 요청을 확인할 수 없습니다.' });
    }
    try {
      const form = {
        grant_type: 'authorization_code',
        code,
        redirect_uri: callbackUrl(provider),
        client_id: cfg.id,
        client_secret: cfg.secret,
      };
      if (provider === 'naver') form.state = state;
      const token = await request(cfg.token, { form });
      if (!token.access_token || typeof token.access_token !== 'string') throw new Error('토큰이 없습니다.');
      const profile = await request(cfg.profile, { bearer: token.access_token });
      const [remoteId, name, avatar, verifiedEmail] = parseProfile(provider, profile);
      if (!remoteId || remoteId.length > 255) throw new Error('계정 ID가 없습니다.');

      let [rows] = await pool.execute(
        'SELECT * FROM users WHERE oauth_provider=? AND oauth_id=? LIMIT 1', [provider, remoteId]);
      let user = rows[0];
      if (!user && verifiedEmail) {
        [rows] = await pool.execute(
          'SELECT * FROM users WHERE email=? AND (oauth_provider IS NULL OR oauth_provider=?) LIMIT 1',
          [verifiedEmail, provider]);
        user = rows[0];
      }
      if (user) {
        await pool.execute('UPDATE users SET oauth_provider=?, oauth_id=?, avatar=? WHERE id=?',
          [provider, remoteId, avatar || null, user.id]);
      } else {
        const username = provider + '_' + crypto.randomBytes(8).toString('hex');
        const safeName = name && name.length <= 80 ? name : username;
        const [result] = await pool.execute(
          'INSERT INTO users (username,password,name,email,role,avatar,oauth_provider,oauth_id) VALUES (?,?,?,?,?,?,?,?)',
          [username, hashPassword(crypto.randomBytes(32).toString('hex')), safeName,
            verifiedEmail || null, 'user', avatar || null, provider, remoteId]);
        [rows] = await pool.execute('SELECT * FROM users WHERE id=?', [result.insertId]);
        user = rows[0];
      }
      req.session.regenerate(error => {
        if (error) return next(error);
        req.session.userId = user.id;
        req.session.notice = '소셜 계정으로 로그인되었습니다.';
        res.redirect('/');
      });
    } catch {
      req.session.notice = '소셜 로그인에 실패했습니다. 다시 시도해 주세요.';
      res.redirect('/login');
    }
  });

  return router;
}

module.exports = { createRouter };

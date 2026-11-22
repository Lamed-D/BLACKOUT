// 로컬 Mock OAuth 2.0 공급자 — 의존성 없음(node:http). E2E 테스트 전용. 로컬에서만 사용.
// 경로: /:provider/authorize, /:provider/token, /:provider/userinfo  (provider = google|kakao|naver)
const http = require('node:http');
const {URL} = require('node:url');

const PORT = process.env.PORT || 9000;
const CODE = 'mock-auth-code';
const TOKEN = 'mock-access-token';

// 공급자별 userinfo 형태 (실제 응답 구조를 모방)
function userinfo(provider) {
  const base = {id: '100200300', name: 'Mock User', picture: 'https://example.test/avatar.png', email: 'mockuser@oauth.test'};
  if (provider === 'google') return {id: base.id, email: base.email, verified_email: true, name: base.name, picture: base.picture};
  if (provider === 'kakao') return {id: base.id, kakao_account: {is_email_verified: true, is_email_valid: true, email: base.email, profile: {nickname: base.name, profile_image_url: base.picture}}};
  if (provider === 'naver') return {resultcode: '00', message: 'success', response: {id: base.id, name: base.name, profile_image: base.picture}};
  return {};
}

function body(req) {
  return new Promise(resolve => { let d = ''; req.on('data', c => d += c); req.on('end', () => resolve(d)); });
}
function json(res, code, obj) { res.writeHead(code, {'Content-Type': 'application/json'}); res.end(JSON.stringify(obj)); }

const server = http.createServer(async (req, res) => {
  const u = new URL(req.url, `http://${req.headers.host}`);
  const m = u.pathname.match(/^\/(google|kakao|naver)\/(authorize|token|userinfo)$/);
  if (!m) return json(res, 404, {error: 'not_found', path: u.pathname});
  const [, provider, action] = m;

  if (action === 'authorize') {
    const redirect = u.searchParams.get('redirect_uri');
    const state = u.searchParams.get('state') || '';
    if (!redirect || u.searchParams.get('response_type') !== 'code') return json(res, 400, {error: 'invalid_request'});
    const back = new URL(redirect);
    back.searchParams.set('code', CODE);
    back.searchParams.set('state', state);
    res.writeHead(302, {Location: back.toString()}); // 브라우저를 콜백으로 되돌려보냄
    return res.end();
  }
  if (action === 'token') {
    await body(req); // grant_type/code/client_id 등은 모의라 검증 생략
    return json(res, 200, {access_token: TOKEN, token_type: 'Bearer', expires_in: 3600, id_token: 'mock'});
  }
  if (action === 'userinfo') {
    const auth = req.headers.authorization || '';
    if (!auth.startsWith('Bearer ')) return json(res, 401, {error: 'unauthorized'});
    return json(res, 200, userinfo(provider));
  }
});
server.listen(PORT, '0.0.0.0', () => console.log(`Mock OAuth provider on ${PORT}`));

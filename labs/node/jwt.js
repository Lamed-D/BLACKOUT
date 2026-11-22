// 경량 JWT (HS256) — [VULN] 약한 시크릿('secret') + alg=none 허용 (PHP 엔진과 동일, 의도적 취약)
const crypto = require('node:crypto');

const JWT_SECRET = 'secret'; // [VULN] 약한 시크릿

function b64urlEncode(buf) {
  return Buffer.from(buf).toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}
function b64urlDecode(str) {
  str = String(str).replace(/-/g, '+').replace(/_/g, '/');
  while (str.length % 4) str += '=';
  return Buffer.from(str, 'base64').toString('utf8');
}

function jwtEncode(payload, alg = 'HS256') {
  const h = b64urlEncode(JSON.stringify({alg, typ: 'JWT'}));
  const p = b64urlEncode(JSON.stringify(payload));
  if (alg === 'none') return `${h}.${p}.`;
  const sig = b64urlEncode(crypto.createHmac('sha256', JWT_SECRET).update(`${h}.${p}`).digest());
  return `${h}.${p}.${sig}`;
}

// [VULN] alg=none 을 허용하고, HS256 은 약한 시크릿으로 검증
function jwtDecode(token) {
  const parts = String(token).split('.');
  if (parts.length < 2) return null;
  const [h, p] = parts;
  const sig = parts[2] || '';
  let header, payload;
  try { header = JSON.parse(b64urlDecode(h)); payload = JSON.parse(b64urlDecode(p)); }
  catch { return null; }
  if (typeof header !== 'object' || typeof payload !== 'object') return null;
  const alg = header.alg || 'HS256';
  if (alg === 'none') return payload; // [VULN] 서명 검증 생략
  const expect = b64urlEncode(crypto.createHmac('sha256', JWT_SECRET).update(`${h}.${p}`).digest());
  try {
    if (expect.length === sig.length && crypto.timingSafeEqual(Buffer.from(expect), Buffer.from(sig))) return payload;
  } catch { return null; }
  return null;
}

module.exports = {JWT_SECRET, jwtEncode, jwtDecode, flag: t => `BLACKOUT{node_${t}}`};

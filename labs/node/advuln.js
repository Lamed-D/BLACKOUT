// 고급 취약점 데모 헬퍼 — [VULN] 전부 의도적 취약. 로컬 학습 전용.
const fs = require('node:fs');

// ── XXE: 내부 DTD의 SYSTEM 엔티티를 해석해 로컬 파일을 읽어들인다 ──
// 안전한 파서는 외부 엔티티를 막지만, 여기서는 일부러 해석한다.
function vulnerableXmlParse(xml) {
  const entities = {};
  // <!ENTITY name SYSTEM "file:///etc/passwd"> / <!ENTITY name "literal">
  const re = /<!ENTITY\s+(\S+)\s+(?:SYSTEM\s+)?["']([^"']+)["']\s*>/g;
  let m;
  while ((m = re.exec(xml)) !== null) {
    const [full, name, value] = m;
    if (/SYSTEM/.test(full)) {
      const file = value.replace(/^file:\/\//, '');
      try { entities[name] = fs.readFileSync(file, 'utf8'); } // [VULN] 임의 파일 읽기
      catch (e) { entities[name] = `[entity read error: ${e.code || e.message}]`; }
    } else {
      entities[name] = value;
    }
  }
  // 엔티티 치환 후 태그 제거 → 텍스트 콘텐츠 반환
  let body = xml.replace(/<!DOCTYPE[\s\S]*?\]>/i, '').replace(/<\?xml[\s\S]*?\?>/i, '');
  body = body.replace(/&(\w+);/g, (_, n) => (n in entities ? entities[n] : `&${n};`));
  return body.replace(/<[^>]+>/g, '').trim();
}

// ── 안전하지 않은 역직렬화 (node-serialize 계열 _$$ND_FUNC$$_ 패턴) ──
function vulnerableDeserialize(obj) {
  if (typeof obj === 'string') obj = JSON.parse(obj);
  const FUNCFLAG = '_$$ND_FUNC$$_';
  (function walk(o) {
    for (const key of Object.keys(o)) {
      const v = o[key];
      if (v && typeof v === 'object') walk(v);
      else if (typeof v === 'string' && v.startsWith(FUNCFLAG)) {
        // [VULN] 신뢰 불가 문자열을 함수로 eval → 즉시실행(IIFE) 페이로드면 RCE
        o[key] = eval('(' + v.slice(FUNCFLAG.length) + ')'); // eslint-disable-line no-eval
      }
    }
  })(obj);
  return obj;
}

// ── 프로토타입 오염 (Node 전용) — __proto__ 가드 없는 재귀 병합 ──
function unsafeMerge(target, source) {
  for (const key of Object.keys(source)) {
    if (source[key] && typeof source[key] === 'object') {
      if (!target[key] || typeof target[key] !== 'object') target[key] = {};
      unsafeMerge(target[key], source[key]); // [VULN] key === '__proto__' 를 막지 않음
    } else {
      target[key] = source[key];
    }
  }
  return target;
}

// ── SSTI 유사 — {{ expr }} 를 서버에서 평가 ──
function sstiRender(template) {
  return String(template).replace(/\{\{(.+?)\}\}/g, (_, expr) => {
    try { return String(eval(expr)); } catch { return '[err]'; } // eslint-disable-line no-eval
  });
}

module.exports = {vulnerableXmlParse, vulnerableDeserialize, unsafeMerge, sstiRender};

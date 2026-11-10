const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/126.0.0.0 Safari/537.36';
async function withAes() {
  const res = await fetch('https://workfolio.gt.tc/aes.js', { headers: { 'User-Agent': UA } });
  const code = await res.text();
  global.slowAES = new Function(code + '; return slowAES;')();
}
function toNumbers(d) { const e = []; d.replace(/(..)/g, (m) => e.push(parseInt(m, 16))); return e; }
function toHex(arr) { let e = ''; for (let f = 0; f < arr.length; f++) e += (16 > arr[f] ? '0' : '') + arr[f].toString(16); return e.toLowerCase(); }
function makeCookie(html) {
  const key = (html.match(/a=toNumbers\("([0-9a-f]+)"\)/) || [])[1];
  const iv = (html.match(/b=toNumbers\("([0-9a-f]+)"\)/) || [])[1];
  const c = (html.match(/c=toNumbers\("([0-9a-f]+)"\)/) || [])[1];
  if (!key || !iv || !c) return null;
  return toHex(slowAES.decrypt(toNumbers(c), 2, toNumbers(key), toNumbers(iv)));
}

let jar = '';
async function req(label, path, opts) {
  const headers = { 'User-Agent': UA, ...(opts && opts.headers ? opts.headers : {}) };
  if (jar) headers['Cookie'] = jar;
  console.log('--- ' + label + ' ' + path);
  console.log('    send Cookie: ' + (headers['Cookie'] || '(none)'));
  const res = await fetch('https://workfolio.gt.tc' + path, {
    method: (opts && opts.method) || 'GET',
    headers,
    body: opts && opts.body,
    redirect: 'manual',
  });
  const rawSc = res.headers.get('set-cookie') || '<none>';
  console.log('    status: ' + res.status + ' location: ' + (res.headers.get('location') || ''));
  console.log('    set-cookie: ' + rawSc);
  const all = res.headers.getSetCookie ? res.headers.getSetCookie() : [];
  if (all.length) console.log('    set-cookie[]: ' + JSON.stringify(all));
  const ck = res.headers.getSetCookie ? res.headers.getSetCookie() : [];
  const cs = [];
  for (const s of ck) cs.push(s.split(';')[0]);
  if (cs.length) jar = cs.join('; ');
  const text = await res.text();
  const isChal = text.indexOf('aes.js') >= 0 || text.indexOf('toNumbers("a=') >= 0 || text.indexOf('slowAES') >= 0;
  console.log('    bodyIsChallenge: ' + isChal + ' len=' + text.length);
  return { status: res.status, text, loc: res.headers.get('location') || '', isChal };
}

async function main() {
  await withAes();
  let r = await req('GET-1', '/login', {});
  let c = makeCookie(r.text);
  console.log('    solved __test: ' + c);
  if (c) jar = '__test=' + c;
  r = await req('GET-2', '/login?i=1', {});
  const c2 = makeCookie(r.text);
  console.log('    second makeCookie: ' + c2);
  if (c2) jar = '__test=' + c2;

  const form = new URLSearchParams();
  form.set('username', 'admin@workfolio.com');
  form.set('password', 'admin123');
  r = await req('POST-login', '/login', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: form.toString() });
  console.log('login loc: ' + r.loc + ' isChal=' + r.isChal);
  if (!r.isChal && r.loc.indexOf('/dashboard') >= 0) console.log('LOGIN OK');
  else console.log('LOGIN FAIL, body head: ' + r.text.slice(0, 120));
}
main().catch((e) => { console.error('ERR', e); process.exit(1); });
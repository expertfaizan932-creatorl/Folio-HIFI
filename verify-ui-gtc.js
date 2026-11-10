// Production check of the new WorkFolio UI shell on workfolio.gt.tc.
const BASE = 'https://workfolio.gt.tc';
const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/126.0.0.0 Safari/537.36';

async function withAes() {
  const res = await fetch(BASE + '/aes.js', { headers: { 'User-Agent': UA } });
  global.slowAES = new Function(await res.text() + '; return slowAES;')();
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

const SHELL = ['wf-shell', 'wf-side', 'wf-top', 'wf-clock-time', 'data-theme-toggle', '/assets/wf.css', '/assets/wf.js', 'wf-nav-label'];
const STAT = ['stat-num', 'stat-ico'];
const STAT_PAGES = ['/dashboard', '/time'];
const PAGES = ['/dashboard', '/monitor', '/time', '/profile', '/employees', '/portal/history', '/admin/usage', '/admin/agent-config', '/portal/users', '/admin/activity'];

(async () => {
  await withAes();
  let r = await fetch(BASE + '/login', { headers: { 'User-Agent': UA }, redirect: 'manual' });
  let t = makeCookie(await r.text()) || '';
  r = await fetch(BASE + '/login?i=1', { headers: { 'User-Agent': UA, Cookie: '__test=' + t }, redirect: 'manual' });
  const again = makeCookie(await r.text()); if (again) t = again;

  const lr = await fetch(BASE + '/login', {
    method: 'POST',
    headers: { 'User-Agent': UA, Cookie: '__test=' + t, 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'username=admin%40workfolio.com&password=admin123', redirect: 'manual',
  });
  const sids = (lr.headers.get('set-cookie') || '').match(/WORKFOLIOSID=[0-9a-f]+/g) || [];
  const ck = '__test=' + t + '; ' + (sids.length ? sids[sids.length - 1] : '');
  console.log('admin login:', (lr.headers.get('location') || '').indexOf('/dashboard') >= 0 ? 'OK' : 'FAIL');

  // static assets on the host
  for (const a of ['/assets/wf.css', '/assets/wf.js']) {
    const res = await fetch(BASE + a, { headers: { 'User-Agent': UA, Cookie: ck } });
    const b = Buffer.from(await res.arrayBuffer());
    console.log(`${res.status === 200 ? 'OK  ' : 'FAIL'} ${a} HTTP ${res.status} ${(b.length / 1024).toFixed(1)} KB bom=${b[0] === 0xEF ? 'YES(bad)' : 'no'}`);
  }

  let pass = 0;
  for (const p of PAGES) {
    const res = await fetch(BASE + p, { headers: { 'User-Agent': UA, Cookie: ck } });
    const html = await res.text();
    const miss = SHELL.filter((m) => html.indexOf(m) < 0);
    if (STAT_PAGES.indexOf(p) >= 0) STAT.forEach((m) => { if (html.indexOf(m) < 0) miss.push(m); });
    const bad = /Warning:|Fatal error|Parse error|Notice:|Undefined /.test(html);
    const ok = res.status === 200 && !miss.length && !bad;
    if (ok) pass++;
    console.log(`${ok ? 'OK  ' : 'FAIL'} ${p.padEnd(22)} HTTP ${res.status} missing=[${miss.join(',')}] ${bad ? 'PHP-NOTICE' : ''}`);
  }
  console.log(`\nnew UI live on ${pass}/${PAGES.length} pages`);

  // login page look
  const lp = await fetch(BASE + '/login?logout=1', { headers: { 'User-Agent': UA, Cookie: '__test=' + t }, redirect: 'manual' });
  const lh = await lp.text();
  const lmarks = ['login-card', 'login-logo', 'wf.css', 'wf.js', 'bi-eye'].filter((m) => lh.indexOf(m) >= 0);
  console.log('login page markers:', lmarks.length + '/5', lmarks.join(', '));
})();

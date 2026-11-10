// Local smoke test for the new WorkFolio UI shell (http://127.0.0.1:8899).
// Logs in as ADMIN, then checks every page renders the sidebar/topbar shell
// and that the new assets are reachable.
const BASE = 'http://127.0.0.1:8899';
const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/126.0.0.0 Safari/537.36';

let cookie = '';
async function req(path, opts) {
  const headers = { 'User-Agent': UA, ...(opts && opts.headers ? opts.headers : {}) };
  if (cookie) headers.Cookie = cookie;
  const res = await fetch(BASE + path, { method: (opts && opts.method) || 'GET', headers, body: opts && opts.body, redirect: 'manual' });
  const sc = res.headers.getSetCookie ? res.headers.getSetCookie() : [];
  for (const s of sc) {
    const m = s.match(/^([^=]+)=([^;]*)/);
    if (m) cookie = m[1] + '=' + m[2];
  }
  const text = await res.text();
  return { status: res.status, text, loc: res.headers.get('location') };
}

const SHELL = ['wf-shell', 'wf-side', 'wf-top', 'wf-clock-time', 'data-theme-toggle', '/assets/wf.css', '/assets/wf.js'];
const PAGES = ['/dashboard', '/monitor', '/time', '/profile', '/employees', '/portal/history', '/admin/usage', '/admin/agent-config', '/portal/users', '/admin/activity'];

(async () => {
  let r = await req('/login', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'username=admin%40workfolio.com&password=admin123' });
  console.log('login ->', r.status, r.loc || '');
  if (r.status !== 302) { console.log('LOGIN FAILED, first 300:', r.text.slice(0, 300)); return; }

  let pass = 0;
  for (const p of PAGES) {
    r = await req(p);
    const miss = SHELL.filter((m) => r.text.indexOf(m) < 0);
    const h4 = (r.text.match(/<h4[^>]*>/g) || []).length;
    const bad = /Warning:|Fatal error|Parse error|Notice:/.test(r.text);
    const ok = r.status === 200 && !miss.length && !bad;
    if (ok) pass++;
    console.log(`${ok ? 'OK  ' : 'FAIL'} ${p.padEnd(22)} HTTP ${r.status} h4=${h4} missing=[${miss.join(',')}] ${bad ? 'PHP-NOTICE' : ''}`);
  }
  console.log(`\nshell + assets OK on ${pass}/${PAGES.length} pages`);

  for (const a of ['/assets/wf.css', '/assets/wf.js', '/icons/websitelogo.png']) {
    const res = await fetch(BASE + a, { headers: { 'User-Agent': UA } });
    const b = Buffer.from(await res.arrayBuffer());
    console.log(`${res.status === 200 ? 'OK  ' : 'FAIL'} ${a} HTTP ${res.status} ${(b.length / 1024).toFixed(1)} KB`);
  }

  // employee view must not leak management links
  r = await req('/logout');
  cookie = '';
  await req('/login', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'username=rahul%40workfolio.com&password=emp123' });
  r = await req('/dashboard');
  const leak = ['/monitor', '/employees', '/admin/usage'].filter((l) => r.text.indexOf('href="' + l + '"') >= 0);
  console.log((leak.length ? 'FAIL' : 'OK  ') + ' employee sidebar hides management links' + (leak.length ? ': ' + leak.join(',') : ''));
  console.log((r.text.indexOf('wf-link') >= 0 ? 'OK  ' : 'FAIL') + ' employee sees the new shell');
})();

// Set liveStreamIntervalSeconds=1 (1 fps live video frames) for all employees on workfolio.gt.tc so every
// agent (even machines without a local agent.config override) livestreams.
// Passes InfinityFree's JS challenge (keeps __test cookie), logs in as ADMIN,
// saves policies, then verifies the settings table.
const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/126.0.0.0 Safari/537.36';

const EMPLOYEES = ['rahul@workfolio.com', 'sneha@workfolio.com', 'hr@workfolio.com', 'admin@workfolio.com'];

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

// Cookie jar: always keep __test (challenge), keep the LAST WORKFOLIOSID (session_regenerate_id).
let jar = [];
function jarString() { return jar.map((c) => c.name + '=' + c.value).join('; '); }
function putCookie(name, value) { jar = jar.filter((c) => c.name !== name); jar.push({ name, value }); }
function hasTest() { return jar.some((c) => c.name === '__test'); }

async function req(path, opts) {
  const headers = { 'User-Agent': UA, ...(opts && opts.headers ? opts.headers : {}) };
  if (jar.length) headers['Cookie'] = jarString();
  const res = await fetch('https://workfolio.gt.tc' + path, {
    method: (opts && opts.method) || 'GET',
    headers,
    body: opts && opts.body,
    redirect: 'manual',
  });
  const setCookies = res.headers.getSetCookie ? res.headers.getSetCookie() : [];
  for (const s of setCookies) {
    const m = s.trim().match(/^([^=]+)=([^;]*)/);
    if (!m) continue;
    const name = m[1].trim();
    if (name === '__test' || name === 'WORKFOLIOSID') putCookie(name, m[2]);
  }
  const text = await res.text();
  return { status: res.status, text, loc: res.headers.get('location') || '' };
}

function csrfOf(html) {
  const m = html.match(/name="_csrf" value="([0-9a-f]+)"/);
  return m ? m[1] : null;
}

async function main() {
  await withAes();

  // 1) pass challenge + login as admin
  let r = await req('/login');
  let c = makeCookie(r.text);
  if (c) putCookie('__test', c);
  r = await req('/login?i=1');
  const c2 = makeCookie(r.text);
  if (c2) putCookie('__test', c2);
  if (!hasTest()) { console.log('Could not solve challenge.'); return; }

  const form = new URLSearchParams();
  form.set('username', 'admin@workfolio.com');
  form.set('password', 'admin123');
  r = await req('/login', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: form.toString() });
  const loginOk = r.loc.indexOf('/dashboard') >= 0;
  console.log('ADMIN login on workfolio.gt.tc:', loginOk ? 'OK' : 'FAIL', '| location:', r.loc || r.status);
  if (!loginOk) { console.log(r.text.slice(0, 200)); return; }

  // 2) save live-stream 15s for each employee
  for (const email of EMPLOYEES) {
    r = await req('/admin/agent-config?email=' + encodeURIComponent(email));
    const csrf = csrfOf(r.text);
    if (!csrf) { console.log(email, '-> CSRF NOT FOUND (page:', r.status, 'loc:', r.loc + ')'); continue; }
    const p = new URLSearchParams();
    p.set('_csrf', csrf);
    p.set('email', email);
    p.set('screenshotIntervalSeconds', '300');
    p.set('liveStreamIntervalSeconds', '1');
    p.set('idleThresholdSeconds', '180');
    p.set('imageQuality', '55');
    p.set('imageMaxWidth', '1280');
    p.set('sampleIntervalSeconds', '10');
    p.set('captureScreenshots', '1');
    p.set('trackMouseKeyboard', '1');
    p.set('trackAppUsage', '1');
    p.set('trackWebsites', '1');
    p.set('excludedProcesses', '');
    p.set('blockedSites', '');
    r = await req('/admin/agent-config/save', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: p.toString() });
    const ok = r.loc.indexOf('/admin/agent-config?email=') >= 0;
    console.log(email.padEnd(26), 'save ->', ok ? 'OK (redirect to settings)' : 'FAIL (' + (r.loc || r.status) + ')');
  }

  // 3) verify the policy table shows Live=15s
  r = await req('/admin/agent-config');
  const seen = [];
  const re = /<tr>\s*<td><a[^>]*>([^<]+)<\/a><\/td>\s*<td>\d+s<\/td>\s*<td>(\d+)s<\/td>/g;
  let m;
  while ((m = re.exec(r.text)) !== null) seen.push(m[1].trim() + ' -> Live ' + m[2] + 's');
  console.log('Policies on server (Live col):');
  for (const line of seen) console.log('  ' + line);
  if (!seen.some((l) => l.indexOf('-> Live 15s') >= 0)) console.log('WARNING: no policy shows 15s live interval.');
}
main().catch((e) => { console.error('ERR', e); process.exit(1); });
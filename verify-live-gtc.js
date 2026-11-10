// End-to-end check of the live video stream on workfolio.gt.tc:
//   agent login -> POST /api/agent/live-frame (x3) -> HR/Admin read the frames
//   -> 304 on a replayed seq -> visibility rules -> /monitor player markers.
const fs = require('fs');

const BASE = 'https://workfolio.gt.tc';
const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/126.0.0.0 Safari/537.36';
const FRAMES = JSON.parse(fs.readFileSync(__dirname + '/live-test-frames.json', 'utf8').replace(/^\uFEFF/, ''));

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
async function challenge() {
  let r = await fetch(BASE + '/login', { headers: { 'User-Agent': UA }, redirect: 'manual' });
  let c = makeCookie(await r.text()) || '';
  r = await fetch(BASE + '/login?i=1', { headers: { 'User-Agent': UA, Cookie: '__test=' + c }, redirect: 'manual' });
  const again = makeCookie(await r.text());
  if (again) c = again;
  return c;
}
async function webLogin(test, user, pass) {
  const res = await fetch(BASE + '/login', {
    method: 'POST',
    headers: { 'User-Agent': UA, Cookie: '__test=' + test, 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'username=' + encodeURIComponent(user) + '&password=' + encodeURIComponent(pass),
    redirect: 'manual',
  });
  const sids = (res.headers.get('set-cookie') || '').match(/WORKFOLIOSID=[0-9a-f]+/g) || [];
  return { ok: (res.headers.get('location') || '').indexOf('/dashboard') >= 0, cookie: '__test=' + test + '; ' + (sids.length ? sids[sids.length - 1] : '') };
}
function get(path, cookie) {
  return fetch(BASE + path, { headers: { 'User-Agent': UA, Cookie: cookie, Accept: 'application/json,image/*' }, redirect: 'manual' });
}
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

async function main() {
  await withAes();
  const test = await challenge();
  console.log('challenge cookie:', test ? 'OK' : 'FAIL');

  // 1) agent login for rahul (stands in for the rebuilt EXE)
  const login = await fetch(BASE + '/api/agent/login', {
    method: 'POST',
    headers: { 'User-Agent': UA, Cookie: '__test=' + test, 'Content-Type': 'application/json' },
    body: JSON.stringify({ email: 'rahul@workfolio.com', password: 'emp123' }),
  }).then((r) => r.json());
  console.log('agent login:', login.ok ? 'OK' : 'FAIL ' + login.error);
  if (!login.ok) return;
  console.log('  server policy liveStreamIntervalSeconds =', login.config && login.config.liveStreamIntervalSeconds);

  // 2) push three live frames 1.2s apart, exactly like the agent's live thread
  for (const f of FRAMES) {
    const res = await fetch(BASE + '/api/agent/live-frame', {
      method: 'POST',
      headers: { 'User-Agent': UA, Cookie: '__test=' + test, 'Content-Type': 'application/json' },
      body: JSON.stringify({
        token: login.token, frame: f.bytes, seq: f.seq, status: 'ACTIVE',
        app: 'Code.exe', title: 'MonitoringEngine.cs - WorkFolio', host: 'github.com',
        category: 'DEVELOPMENT', hostName: 'HR-LAPTOP', agentVersion: login.config && 'test', ip: '10.0.0.9',
      }),
    }).then((r) => r.json());
    console.log(`  frame seq=${f.seq} (${f.kb} KB) ->`, res.ok ? `OK seq=${res.seq} at ${res.at}` : 'FAIL ' + res.error);
    await sleep(1200);
  }

  // 3) HR view
  const hr = await webLogin(test, 'hr@workfolio.com', 'hr123');
  const admin = await webLogin(test, 'admin@workfolio.com', 'admin123');
  console.log('HR login:', hr.ok ? 'OK' : 'FAIL', '| Admin login:', admin.ok ? 'OK' : 'FAIL');

  const liveRes = await get('/api/monitor/live', hr.cookie);
  const rows = await liveRes.json();
  console.log('/api/monitor/live -> HTTP', liveRes.status, '| people visible to HR:', rows.length);
  for (const p of rows) {
    console.log(`  ${p.email.padEnd(24)} ${(p.role || '').padEnd(9)} streaming=${p.streaming} frameSeq=${p.frameSeq} frameAt=${p.frameAt} age=${p.frameAgeSec}s kb=${p.frameKb} app=${p.currentApp || '-'} idle=${p.frameIdle}`);
  }
  const rahul = rows.find((r) => r.email === 'rahul@workfolio.com');
  const rahulAdmin = (await (await get('/api/monitor/live', admin.cookie)).json()).find((r) => r.email === 'rahul@workfolio.com');
  console.log('  HR sees only employees+self:', rows.every((r) => r.email !== 'admin@workfolio.com') ? 'OK' : 'FAIL');
  console.log('  Admin sees rahul:', rahulAdmin ? 'OK' : 'FAIL');

  // 4) the frame endpoint: fresh fetch, then a replay that must 304
  const f1 = await get('/api/monitor/live-frame?email=rahul%40workfolio.com&seq=0', hr.cookie);
  const b1 = Buffer.from(await f1.arrayBuffer());
  const seq1 = f1.headers.get('x-frame-seq');
  console.log('/api/monitor/live-frame -> HTTP', f1.status, f1.headers.get('content-type'), (b1.length / 1024).toFixed(1) + ' KB',
    '| X-Frame-Seq=' + seq1, '| JPEG magic=' + (b1.slice(0, 2).toString('hex') === 'ffd8' ? 'OK' : 'FAIL'),
    '| cache=' + f1.headers.get('cache-control'));
  const f2 = await get('/api/monitor/live-frame?email=rahul%40workfolio.com&seq=' + seq1, hr.cookie);
  console.log('  replay with same seq -> HTTP', f2.status, f2.status === 304 ? 'OK (conditional)' : 'FAIL');

  // 5) visibility
  const f3 = await get('/api/monitor/live-frame?email=admin%40workfolio.com&seq=0', hr.cookie);
  console.log('HR fetching admin frame -> HTTP', f3.status, f3.status === 403 ? 'OK (blocked)' : 'FAIL');
  const f4 = await get('/api/monitor/live-frame?email=rahul%40workfolio.com&seq=0', admin.cookie);
  console.log('Admin fetching rahul frame -> HTTP', f4.status, f4.status === 200 ? 'OK' : 'FAIL');

  // 6) the player UI actually deployed
  const page = await (await get('/monitor', hr.cookie)).text();
  const marks = ['mon-count', 'main-canvas', 'createImageBitmap', 'X-Frame-Seq', '/api/monitor/live-frame', 'Pause all streams', "toLocaleTimeString('en-US'"]
    .filter((m) => page.indexOf(m) >= 0);
  console.log('/monitor player markers:', marks.length + '/7', marks.join(', '));
  const old = ['shot-tile', 'shotModal'].filter((m) => page.indexOf(m) >= 0);
  if (old.length) console.log('  (old still-image UI leftovers:', old.join(', ') + ')');

  // 7) storage: one overwrite slot, not an archive
  const before = rahul ? rahul.screenshots : '?';
  console.log('rahul screenshot_count (live frames must not be counted):', before);
}
main().catch((e) => { console.error('ERR', e); process.exit(1); });

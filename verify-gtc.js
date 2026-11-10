// Verify WorkFolio production monitor (workfolio.gt.tc) as HR.
// Passes InfinityFree's JS challenge, logs in as HR, prints /api/monitor/live.
// Usage: node verify-gtc.js [rahul@workfolio.com|all]
const fs = require('fs');
const path = require('path');

const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/126.0.0.0 Safari/537.36';

async function withAes() {
  const res = await fetch('https://workfolio.gt.tc/aes.js', { headers: { 'User-Agent': UA } });
  const code = await res.text();
  // aes.js declares `var slowAES = {...}`; capture the returned object directly.
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
async function get(url, extraHeaders, follow) {
  const headers = { 'User-Agent': UA, ...(extraHeaders || {}) };
  const res = await fetch(url, { headers, redirect: follow ? 'follow' : 'manual' });
  const text = await res.text();
  return { status: res.status, text, url: res.url, sc: res.headers.get('set-cookie') || '' };
}

const ADMIN = process.argv.indexOf('--admin') >= 0;

async function main() {
  await withAes();
  // 1) pass challenge
  let r = await get('https://workfolio.gt.tc/login', {}, false);
  let c = makeCookie(r.text) || '';
  r = await get('https://workfolio.gt.tc/login?i=1', { Cookie: '__test=' + c }, false);
  if (makeCookie(r.text)) c = makeCookie(r.text);
  // 2) login
  const who = ADMIN ? { u: 'admin@workfolio.com', p: 'admin123' } : { u: 'hr@workfolio.com', p: 'hr123' };
  const logRes = await fetch('https://workfolio.gt.tc/login', {
    method: 'POST',
    headers: { 'User-Agent': UA, 'Cookie': '__test=' + c, 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'username=' + encodeURIComponent(who.u) + '&password=' + encodeURIComponent(who.p),
    redirect: 'manual',
  });
  const rawSc = logRes.headers.get('set-cookie') || '';
  const sids = rawSc.match(/WORKFOLIOSID=[0-9a-f]+/g) || [];
  const sessCookie = '__test=' + c + '; ' + (sids.length ? sids[sids.length - 1] : '');
  const loginOk = (logRes.headers.get('location') || '').indexOf('/dashboard') >= 0;
  console.log((ADMIN ? 'ADMIN' : 'HR') + ' login on workfolio.gt.tc:', loginOk ? 'OK' : 'FAIL', '| location:', logRes.headers.get('location'));
  if (!loginOk) return;
  // 3) live monitor
  r = await get('https://workfolio.gt.tc/api/monitor/live', { Cookie: sessCookie, Accept: 'application/json' }, false);
  console.log('/api/monitor/live -> HTTP', r.status);
  try {
    const rows = JSON.parse(r.text);
    for (const p of rows) {
      const liveNow = p.shotLive ? 'LIVE-SHOT' : (p.shotId ? 'shot' : '-');
      console.log(`  ${p.email.padEnd(26)} ${p.status.padEnd(8)} ${p.name.padEnd(16)} worked=${p.worked} lastSeen=${p.lastSeenAgo} currentHost=${p.currentHost || '-'} ${liveNow}=${p.shotAgeSec == null ? '-' : p.shotAgeSec + 's'}@${p.shotAtFmt || '-'}`);
    }
  } catch (e) {
    console.log('  (non-JSON response, first 200 chars):', r.text.slice(0, 200));
  }
  // 4) monitor page markers (live UI deployed?)
  r = await get('https://workfolio.gt.tc/monitor', { Cookie: sessCookie }, false);
  const marks = ['mon-clock', 'shot-tile', 'liveTag', 'shotModal', 'toLocaleTimeString(\'en-US\''].filter((m) => r.text.indexOf(m) >= 0);
  console.log('/monitor -> HTTP', r.status, '| live-UI markers:', marks.length + '/5', marks.join(', '));
}
main().catch((e) => { console.error('ERR', e); process.exit(1); });
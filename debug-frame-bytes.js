// Dumps the first bytes of the served live frame to confirm it is a real JPEG.
const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/126.0.0.0 Safari/537.36';
const BASE = 'https://workfolio.gt.tc';
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
(async () => {
  await withAes();
  let r = await fetch(BASE + '/login', { headers: { 'User-Agent': UA }, redirect: 'manual' });
  let t = makeCookie(await r.text());
  r = await fetch(BASE + '/login?i=1', { headers: { 'User-Agent': UA, Cookie: '__test=' + t }, redirect: 'manual' });
  const a2 = makeCookie(await r.text()); if (a2) t = a2;
  const lr = await fetch(BASE + '/login', {
    method: 'POST',
    headers: { 'User-Agent': UA, Cookie: '__test=' + t, 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'username=hr%40workfolio.com&password=hr123', redirect: 'manual',
  });
  const sid = ((lr.headers.get('set-cookie') || '').match(/WORKFOLIOSID=[0-9a-f]+/g) || []).pop();
  const ck = '__test=' + t + '; ' + sid;
  const f = await fetch(BASE + '/api/monitor/live-frame?email=rahul%40workfolio.com&seq=0', { headers: { 'User-Agent': UA, Cookie: ck } });
  const b = Buffer.from(await f.arrayBuffer());
  console.log('status', f.status, '| content-type', f.headers.get('content-type'), '| content-length hdr', f.headers.get('content-length'), '| bytes', b.length);
  console.log('first 24 bytes hex:', b.slice(0, 24).toString('hex'));
  console.log('as text:', JSON.stringify(b.slice(0, 60).toString('latin1')));
})();

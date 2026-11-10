// Current state check: does node fetch + AES cookie still pass the POST login?
const crypto = require('crypto');
function extract(html) {
  return {
    a: ((html.match(/a=toNumbers\("([0-9a-f]+)"\)/) || [])[1] || ''),
    b: ((html.match(/b=toNumbers\("([0-9a-f]+)"\)/) || [])[1] || ''),
    c: ((html.match(/c=toNumbers\("([0-9a-f]+)"\)/) || [])[1] || ''),
  };
}
async function main() {
  // step 1: GET /login challenge
  const r0 = await fetch('https://workfolio.gt.tc/login', { headers: { 'User-Agent': 'WorkFolioAgent/1.0.0' }, redirect: 'manual' });
  const html0 = await r0.text();
  const { a, b, c } = extract(html0);
  const d = crypto.createDecipheriv('aes-128-cbc', Buffer.from(a, 'hex'), Buffer.from(b, 'hex'));
  d.setAutoPadding(false);
  const cookie = Buffer.concat([d.update(Buffer.from(c, 'hex')), d.final()]).toString('hex');
  console.log('cookie:', cookie);

  // step 2: POST login with cookie (mirror agent-smoke)
  const r1 = await fetch('https://workfolio.gt.tc/api/agent/login', {
    method: 'POST',
    headers: { 'User-Agent': 'WorkFolioAgent/1.0.0', 'Cookie': '__test=' + cookie, 'Content-Type': 'application/json' },
    body: JSON.stringify({ email: 'rahul@workfolio.com', password: 'emp123' }),
    redirect: 'manual',
  });
  const t1 = await r1.text();
  console.log('POST login status:', r1.status, '| body[:120]:', t1.slice(0, 120));

  // step 3: with ?i=1 on the url
  const r2 = await fetch('https://workfolio.gt.tc/api/agent/login?i=1', {
    method: 'POST',
    headers: { 'User-Agent': 'WorkFolioAgent/1.0.0', 'Cookie': '__test=' + cookie, 'Content-Type': 'application/json' },
    body: JSON.stringify({ email: 'rahul@workfolio.com', password: 'emp123' }),
    redirect: 'manual',
  });
  const t2 = await r2.text();
  console.log('POST login?i=1 status:', r2.status, '| body[:120]:', t2.slice(0, 120));
}
main().catch((e) => { console.error('ERR', e); process.exit(1); });
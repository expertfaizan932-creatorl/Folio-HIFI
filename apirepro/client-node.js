// Node-fetch through the logging proxy (absolute-form, like a client would via proxy)
const http = require('http');
const crypto = require('crypto');

const cookie = 'bfffd8749af763522b3b4ea9bcc3ee7d';

const body = JSON.stringify({ email: 'rahul@workfolio.com', password: 'emp123' });
const req = http.request(
  {
    host: '127.0.0.1',
    port: 8890,
    method: 'POST',
    path: 'https://workfolio.gt.tc/api/agent/login',
    headers: {
      Host: 'workfolio.gt.tc',
      'User-Agent': 'WorkFolioAgent/1.0.0',
      'Content-Type': 'application/json',
      'Content-Length': Buffer.byteLength(body),
      Cookie: '__test=' + cookie,
    },
  },
  (res) => {
    let t = '';
    res.on('data', (d) => (t += d));
    res.on('end', () => {
      console.log('CLIENT got status ' + res.statusCode);
      console.log('CLIENT body[:160]: ' + t.slice(0, 160));
    });
  }
);
req.on('error', (e) => console.log('client error: ' + e.message));
req.end(body);
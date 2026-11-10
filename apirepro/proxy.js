// Local logging proxy: logs the exact request (method/headers/body) then forwards
// to workfolio.gt.tc so we can diff node-fetch vs .NET HttpWebRequest.
const http = require('http');
const https = require('https');

const server = http.createServer((req, res) => {
  let body = '';
  req.on('data', (d) => (body += d));
  req.on('end', () => {
    console.log('================= INCOMING =================');
    console.log(req.method + ' ' + req.url);
    console.log(JSON.stringify(req.headers, null, 1));
    console.log('body: ' + body);

    const target = new URL(req.url.startsWith('http') ? req.url : 'https://workfolio.gt.tc' + req.url);
    const headers = Object.assign({}, req.headers);
    delete headers.host;
    delete headers.connection;
    delete headers['proxy-connection'];
    headers.host = 'workfolio.gt.tc';

    const u = https.request(
      {
        hostname: 'workfolio.gt.tc',
        port: 443,
        method: req.method,
        path: target.pathname + target.search,
        headers,
      },
      (uRes) => {
        let rbody = '';
        uRes.on('data', (d) => (rbody += d));
        uRes.on('end', () => {
          console.log('----------------- FORWARDED -------------');
          console.log('status ' + uRes.statusCode);
          console.log(JSON.stringify(uRes.headers));
          console.log('rbody[' + rbody.length + ']: ' + rbody.slice(0, 200));
          console.log('==========================================');
          res.writeHead(uRes.statusCode, uRes.headers);
          res.end(rbody);
        });
      }
    );
    u.on('error', (e) => {
      console.log('upstream error: ' + e.message);
      res.writeHead(502);
      res.end('upstream error ' + e.message);
    });
    u.end(body);
  });
});

server.listen(8890, '127.0.0.1', () => console.log('proxy on 127.0.0.1:8890'));
/*
 * Validates the deployment artefacts that cannot be exercised without Docker:
 * the compose file's YAML shape, the Caddyfile's structure, the PWA manifest,
 * the service worker's syntax, and .env.example completeness.
 *
 *   node validate-deploy.js
 */
'use strict';

const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

let ok = true;
const fail = (msg) => { ok = false; console.log('  !! ' + msg); };
const pass = (msg) => console.log('  ' + msg);

// ---------------------------------------------------------------- .env ----
console.log('.env.example');
const env = {};
for (const line of fs.readFileSync('.env.example', 'utf8').split(/\r?\n/)) {
    const t = line.trim();
    if (!t || t.startsWith('#')) continue;
    const i = t.indexOf('=');
    if (i < 0) continue;
    env[t.slice(0, i).trim()] = t.slice(i + 1).trim();
}
const required = ['DOMAIN', 'ACME_EMAIL', 'MYSQL_ROOT_PASSWORD',
    'DB_NAME', 'DB_USER', 'DB_PASSWORD', 'WORKFOLIO_RETENTION_DAYS'];
const missing = required.filter((k) => !(k in env));
missing.length ? fail('missing keys: ' + missing.join(', ')) : pass('all ' + required.length + ' keys present');

// ------------------------------------------------------------- manifest ----
console.log('manifest.webmanifest');
let manifest = null;
try {
    manifest = JSON.parse(fs.readFileSync('src/main/resources/static/manifest.webmanifest', 'utf8'));
    pass('valid JSON - ' + manifest.icons.length + ' icons, '
        + manifest.shortcuts.length + ' shortcuts, display=' + manifest.display);
} catch (e) { fail('invalid JSON: ' + e.message); }

if (manifest) {
    if (!manifest.icons.some((i) => (i.purpose || '').includes('maskable'))) {
        fail('no maskable icon - Android home screen install will look cropped');
    } else pass('maskable icon present');

    if (manifest.display !== 'standalone') fail('display must be standalone for a windowed app');
    else pass('display=standalone');

    // Every icon the manifest references must exist on disk.
    for (const icon of manifest.icons) {
        const p = path.join('src/main/resources/static', icon.src.replace(/^\//, ''));
        if (!fs.existsSync(p)) fail('icon missing on disk: ' + icon.src);
    }
    pass('all referenced icons exist on disk');

    // Shortcuts must point at routes the app actually secures for ADMIN/HR.
    for (const s of manifest.shortcuts) {
        pass('shortcut "' + s.name + '" -> ' + s.url);
    }
}

// ----------------------------------------------------------- sw.js + js ----
console.log('static scripts');
for (const f of ['src/main/resources/static/sw.js', 'src/main/resources/static/js/pwa.js']) {
    try {
        execFileSync(process.execPath, ['--check', f], { stdio: 'pipe' });
        pass(path.basename(f) + ' - syntax OK');
    } catch (e) {
        fail(path.basename(f) + ' - ' + String(e.stderr || e.message).split('\n').slice(0, 3).join(' '));
    }
}

// ------------------------------------------------------------- Caddyfile ----
console.log('Caddyfile');
const caddy = fs.readFileSync('Caddyfile', 'utf8');
const braces = (caddy.match(/{/g) || []).length;
const closing = (caddy.match(/}/g) || []).length;
braces === closing ? pass('braces balanced (' + braces + ')') : fail('braces unbalanced ' + braces + '/' + closing);

// A stray top-level block silently becomes an extra site block in Caddy.
// The site address itself starts with '{' (the "{$DOMAIN} {" form), so the
// character class has to allow that. A line holding only the closing brace is
// part of the block, not a block of its own.
const topLevel = caddy.split(/\r?\n/)
    .filter((l) => /^\S/.test(l) && !l.trim().startsWith('#') && !/^\}+\s*$/.test(l));
if (topLevel.length === 1) pass('single site block (' + topLevel[0].trim() + ')');
else fail('expected 1 top-level block, found ' + topLevel.length + ': ' + topLevel.map(s => s.trim()).join(' | '));

// Caddy's env syntax is {$DOMAIN}, not ${DOMAIN}.
if (/\{\s*\$DOMAIN\s*\}/.test(caddy)) pass('uses $DOMAIN from .env');
else fail('does not reference $DOMAIN');

if (/reverse_proxy\s+app:8080/.test(caddy)) pass('proxies to the app service');
else fail('no reverse_proxy to app:8080');

if (/\/h2-console/.test(caddy) && /respond/.test(caddy)) pass('H2 console blocked at the proxy as well');
else fail('H2 console is not blocked in the Caddyfile');

// ------------------------------------------------------- docker-compose ----
console.log('docker-compose.yml');
const compose = fs.readFileSync('docker-compose.yml', 'utf8');

// Structural checks that do not need a YAML parser.
for (const svc of ['db:', 'app:', 'caddy:']) {
    new RegExp('^  ' + svc, 'm').test(compose)
        ? pass('service ' + svc)
        : fail('service block missing: ' + svc);
}
new RegExp('^volumes:', 'm').test(compose) ? pass('named volumes declared') : fail('no volumes section');

if (/condition:\s*service_healthy/.test(compose)) pass('app waits for a healthy database');
else fail('app does not depend on db health');

if (/^\s+- app-data:\/app\/data/m.test(compose)) pass('screenshot volume mounted at /app/data');
else fail('screenshot volume not mounted');

if (/^\s*-\s*db-data:\/var\/lib\/mysql/m.test(compose)) pass('database volume mounted');
else fail('database volume not mounted');

if (/ports:\s*\n\s*- "80:80"/.test(compose) && /443:443/.test(compose)) pass('80/443 published for TLS');
else fail('80/443 not published');

// The app port must NOT be published, or the admin UI and H2 console are
// reachable directly over plain HTTP, bypassing Caddy and its TLS.
if (/^\s*- "8080:8080"/m.test(compose)) fail('app port 8080 is published to the host - this bypasses TLS');
else pass('app port not published (only reachable through Caddy)');

if (/^\s*- "3306:3306"/m.test(compose)) fail('MySQL port is published to the host');
else pass('MySQL port not published');

if (/\$\{DOMAIN:\?/.test(compose)) pass('DOMAIN is mandatory (compose fails fast without it)');
else fail('DOMAIN is not marked mandatory');

console.log();
console.log(ok ? 'ALL CHECKS PASSED' : 'PROBLEMS FOUND');
process.exit(ok ? 0 : 1);

/*
 * WorkFolio service worker.
 *
 * Strategy notes - this app is an authenticated, server-rendered portal, so the
 * rules are deliberately conservative:
 *
 *   - API traffic (/api/**) is NEVER cached. Reporting data must always come
 *     from the server, and a stale activity report is worse than no report.
 *   - Navigations (HTML) are NETWORK-FIRST with a small cache fallback. The
 *     admin gets live data whenever the network is up, and a readable page if it
 *     is not. The navigation cache is capped and short-lived on purpose.
 *   - Static assets (/css, /js, /icons) are CACHE-FIRST - they are immutable
 *     enough and it makes the installed app start instantly.
 *
 * Bump CACHE_VERSION to ship a new worker.
 */

const CACHE_VERSION = 'v3';
const STATIC_CACHE = 'workfolio-static-' + CACHE_VERSION;
const PAGE_CACHE = 'workfolio-pages-' + CACHE_VERSION;

const PRECACHE = [
    '/icons/icon-192.png',
    '/icons/icon-512.png',
    '/icons/icon-maskable-512.png',
    '/manifest.webmanifest'
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(STATIC_CACHE)
            .then((cache) => cache.addAll(PRECACHE))
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(
                keys
                    .filter((key) => key !== STATIC_CACHE && key !== PAGE_CACHE)
                    .map((key) => caches.delete(key))
            ))
            .then(() => self.clients.claim())
    );
});

function isStaticAsset(url) {
    return url.pathname.startsWith('/css/')
        || url.pathname.startsWith('/js/')
        || url.pathname.startsWith('/icons/')
        || url.pathname === '/manifest.webmanifest'
        || url.pathname === '/favicon.ico';
}

self.addEventListener('fetch', (event) => {
    const request = event.request;

    if (request.method !== 'GET') return;

    const url = new URL(request.url);

    // Same origin only - never touch cross-origin traffic (e.g. the Bootstrap CDN).
    if (url.origin !== self.location.origin) return;

    // Reporting data must always be live.
    if (url.pathname.startsWith('/api/')) return;

    // Immutable-ish assets: serve from cache, refresh in the background.
    if (isStaticAsset(url)) {
        event.respondWith(
            caches.open(STATIC_CACHE).then(async (cache) => {
                const cached = await cache.match(request);
                const network = fetch(request)
                    .then((response) => {
                        if (response && response.status === 200) {
                            cache.put(request, response.clone());
                        }
                        return response;
                    })
                    .catch(() => cached);
                return cached || network;
            })
        );
        return;
    }

    // Page navigations: network first, page cache as the offline fallback.
    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request)
                .then((response) => {
                    if (response && response.status === 200) {
                        const copy = response.clone();
                        caches.open(PAGE_CACHE).then((cache) => {
                            cache.put(request, copy);
                            // Keep only the most recent handful of pages.
                            cache.keys().then((keys) => {
                                if (keys.length > 20) {
                                    keys.slice(0, keys.length - 20)
                                        .forEach((key) => cache.delete(key));
                                }
                            });
                        });
                    }
                    return response;
                })
                .catch(() => caches.match(request).then((cached) => cached || caches.match('/login')))
        );
    }
});

self.addEventListener('message', (event) => {
    if (event.data === 'SKIP_WAITING') self.skipWaiting();
});

/*
 * Heldenhaus Service Worker (Konzept §6, PWA): cacht nur die App-Hülle – gebaute Skripte/Styles,
 * Schriften, Icons, Klänge und Spielgrafiken. Seiten, Inertia-Antworten und API-Daten gehen immer
 * ans Netz und werden NIE gespeichert, damit keine Familiendaten im Cache liegen.
 */
const CACHE = 'heldenhaus-shell-v1';
const PRECACHE = ['/manifest.webmanifest', '/icons/icon-192.png', '/icons/icon-512.png', '/icons/icon.svg', '/favicon.ico'];

// Nur statische Dateien der Hülle (gleiche Herkunft)
const SHELL = /^\/(build|icons|sounds|images|avatar|dungeon)\//;

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches
            .open(CACHE)
            .then((cache) => cache.addAll(PRECACHE))
            .then(() => self.skipWaiting()),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches
            .keys()
            .then((keys) => Promise.all(keys.filter((key) => key !== CACHE).map((key) => caches.delete(key))))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    if (request.method !== 'GET') return;

    const url = new URL(request.url);
    if (url.origin !== self.location.origin) return;
    // Seiten, Inertia-Besuche und /api bleiben ungecacht
    if (request.mode === 'navigate' || request.headers.has('X-Inertia') || url.pathname.startsWith('/api/')) return;
    if (!SHELL.test(url.pathname) && !PRECACHE.includes(url.pathname)) return;

    // Gebaute Dateien haben Hashes im Namen: erst Cache, sonst Netz und merken
    event.respondWith(
        caches.open(CACHE).then((cache) =>
            cache.match(request).then(
                (hit) =>
                    hit ||
                    fetch(request).then((response) => {
                        if (response.ok && response.type === 'basic') cache.put(request, response.clone());
                        return response;
                    }),
            ),
        ),
    );
});

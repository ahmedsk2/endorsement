/*
 * Paediatric Endorsement — service worker. APP SHELL ONLY.
 *
 * Doctrine (spec §10.1): never cache /endorsement data. An offline queue or a cached
 * census is this domain's documented data-loss failure mode — a stale sheet read as
 * current is worse than no sheet. So:
 *   - navigations are NETWORK-FIRST; when offline, a static offline page renders;
 *   - built static assets (/build/*, icons, fonts) are cache-first (they are hashed);
 *   - anything under /endorsement is never written to any cache here, and the server
 *     additionally marks those responses Cache-Control: no-store.
 */
const SHELL_CACHE = 'pe-shell-v1';
const OFFLINE_URL = '/offline.html';

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(SHELL_CACHE).then((cache) => cache.addAll([OFFLINE_URL, '/icons/icon.svg'])),
    );
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) => Promise.all(
            keys.filter((k) => k !== SHELL_CACHE).map((k) => caches.delete(k)),
        )).then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const url = new URL(event.request.url);

    if (event.request.method !== 'GET' || url.origin !== self.location.origin) {
        return; // never intercept writes or cross-origin requests
    }

    // Navigations: network-first, offline fallback. Never served from cache when online.
    if (event.request.mode === 'navigate') {
        event.respondWith(
            fetch(event.request).catch(() => caches.match(OFFLINE_URL)),
        );
        return;
    }

    // Hashed build assets + icons: cache-first (immutable by content hash).
    if (url.pathname.startsWith('/build/') || url.pathname.startsWith('/icons/')) {
        event.respondWith(
            caches.match(event.request).then((hit) => hit ?? fetch(event.request).then((response) => {
                const copy = response.clone();
                caches.open(SHELL_CACHE).then((cache) => cache.put(event.request, copy));
                return response;
            })),
        );
    }

    // Everything else (including every /endorsement XHR) goes straight to the network.
});

/*
 * Push reminders (spec §10.2). The payload is unit + date + status ONLY — never patient
 * data — composed server-side by the endorsement:remind command.
 */
self.addEventListener('push', (event) => {
    let data = {};
    try {
        data = event.data ? event.data.json() : {};
    } catch {
        data = { title: 'Endorsement', body: 'Handover reminder' };
    }

    event.waitUntil(self.registration.showNotification(data.title || 'Endorsement', {
        body: data.body || '',
        icon: '/icons/icon.svg',
        badge: '/icons/icon.svg',
        data: { url: data.url || '/endorsement/today' },
    }));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const url = event.notification.data?.url || '/endorsement/today';
    event.waitUntil(self.clients.openWindow(url));
});

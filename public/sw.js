/*
 * Diwan POS service worker: keeps the cashier screen (its page and files) so the till opens again with no connection.
 * Scope: /pos only. It never caches anything but the till page itself and the built files (/build, fonts, images);
 * sales are not sent through it (the screen keeps its own queue and sends them when the link is back).
 */
const VERSION = 'diwan-pos-v1';
const PAGES = `${VERSION}-pages`;
const FILES = `${VERSION}-files`;
const TERMINAL = /POS(\\\/|\/)Terminal/; // the page's data-page JSON names its component

self.addEventListener('install', () => self.skipWaiting());

self.addEventListener('activate', (event) => {
    event.waitUntil((async () => {
        for (const key of await caches.keys()) {
            if (key.startsWith('diwan-pos-') && ![PAGES, FILES].includes(key)) await caches.delete(key);
        }
        await self.clients.claim();
    })());
});

self.addEventListener('message', (event) => {
    // Sent when the cashier signs out: nothing of the till stays behind for the next person on this computer.
    if (event.data === 'clear') event.waitUntil(Promise.all([caches.delete(PAGES), caches.delete(FILES)]));
});

const offlinePage = () => new Response(
    '<!doctype html><html lang="ar" dir="rtl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
    + '<title>Diwan POS</title><body style="font-family:sans-serif;text-align:center;padding:3rem">'
    + '<h2>لا يوجد اتصال</h2><p>لم تُحفظ شاشة الكاشير على هذا الجهاز بعد. افتحها مرة واحدة مع الاتصال ثم ستعمل دونه.</p></body></html>',
    { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } },
);

async function till(request) {
    try {
        const response = await fetch(request);
        // Only the till itself: not a login page it was redirected to, not another screen.
        if (response.ok && !response.redirected && new URL(response.url).pathname.replace(/\/$/, '') === '/pos') {
            const copy = response.clone();
            if (TERMINAL.test(await copy.clone().text())) await (await caches.open(PAGES)).put('/pos', copy);
        }
        return response;
    } catch (e) {
        return (await (await caches.open(PAGES)).match('/pos')) || offlinePage();
    }
}

async function file(request) {
    const cache = await caches.open(FILES);
    const hit = await cache.match(request);
    if (hit) return hit;
    const response = await fetch(request);
    if (response.ok) cache.put(request, response.clone());
    return response;
}

self.addEventListener('fetch', (event) => {
    const request = event.request;
    const url = new URL(request.url);
    if (request.method !== 'GET' || url.origin !== self.location.origin) return;

    if (request.mode === 'navigate' && url.pathname.replace(/\/$/, '') === '/pos') {
        event.respondWith(till(request));
    } else if (url.pathname.startsWith('/build/') || ['font', 'image'].includes(request.destination)) {
        event.respondWith(file(request));
    }
});

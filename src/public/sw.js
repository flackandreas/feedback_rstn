const CACHE_NAME = 'schul-app-v3';
const urlsToCache = [
  '/css/app_styles.css',
  '/js/app.js'
];

self.addEventListener('install', event => {
  self.skipWaiting();
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then(cache => {
        return cache.addAll(urlsToCache);
      })
  );
});

self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(cacheNames => {
      return Promise.all(
        cacheNames.filter(name => name !== CACHE_NAME).map(name => caches.delete(name))
      );
    }).then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', event => {
  if (event.request.method !== 'GET') return;

  // Seitenaufrufe gehen am Service Worker vorbei direkt ans Netz. Sonst
  // endeten der Klick auf die Kachel im Portal und der Rueckweg der
  // Anmeldung mit "Offline" - die Anfrage erreichte den Server nie. Seiten
  // lagen ohnehin nie im Cache, nur das Stylesheet und app.js.
  if (event.request.mode === 'navigate') return;

  // Network first, fallback to cache for CSS/JS
  event.respondWith(
    fetch(event.request)
      .then(response => {
        return response;
      })
      .catch(() => {
        return caches.match(event.request)
          .then(cachedResponse => {
            if (cachedResponse) return cachedResponse;
            return new Response('Offline: Bitte stellen Sie eine Internetverbindung her.');
          });
      })
  );
});

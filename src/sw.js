const CACHE_NAME = 'schul-app-v1';
const urlsToCache = [
  '/',
  '/index.php',
  '/css/app_styles.css',
  '/js/app.js'
];

self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then(cache => {
        return cache.addAll(urlsToCache);
      })
  );
});

self.addEventListener('fetch', event => {
  event.respondWith(
    caches.match(event.request)
      .then(response => {
        if (response) {
          return response;
        }
        return fetch(event.request).catch(() => {
            return new Response('Offline: Bitte stellen Sie eine Internetverbindung her.');
        });
      }
    )
  );
});

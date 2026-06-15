const CACHE = 'cw-v33';
const ASSETS = [
  'assets/style.css', 'assets/chart.min.js',
  'assets/icon-192.png', 'assets/icon-512.png',
  'assets/fonts/inter-v20-latin-regular.woff2',
  'assets/fonts/inter-v20-latin-500.woff2',
  'assets/fonts/inter-v20-latin-600.woff2',
  'assets/fonts/baloo-2-v23-latin-500.woff2',
  'assets/fonts/baloo-2-v23-latin-600.woff2',
  'assets/fonts/baloo-2-v23-latin-700.woff2'
];

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(CACHE).then((c) => c.addAll(ASSETS)).then(() => self.skipWaiting()));
});
self.addEventListener('activate', (e) => {
  e.waitUntil(caches.keys().then((keys) =>
    Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)))
  ).then(() => self.clients.claim()));
});
self.addEventListener('fetch', (e) => {
  const url = new URL(e.request.url);
  // Solo GET di asset statici dalla cache; tutto il resto va alla rete (dati freschi)
  if (e.request.method === 'GET' && url.pathname.includes('/assets/')) {
    e.respondWith(caches.match(e.request).then((r) => r || fetch(e.request)));
  }
});

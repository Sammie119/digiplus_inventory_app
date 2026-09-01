// Kill switch: this app has no legitimate use for a service worker.
// A stale/rogue SW was found registered at this exact URL, intercepting
// fetch requests (including /livewire/livewire.js) and breaking Livewire.
// This replacement unregisters itself and clears all caches on every
// browser that still has the old one installed. Safe to delete once
// confirmed propagated (see the plan doc for context).

self.addEventListener('install', () => {
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((names) => Promise.all(names.map((name) => caches.delete(name))))
      .then(() => self.registration.unregister())
      .then(() => self.clients.matchAll())
      .then((clients) => clients.forEach((client) => client.navigate(client.url)))
  );
});

// Minimaler Service Worker — ausschließlich für Web Push, kein Offline-Caching (siehe
// settings.component.ts, wo er registriert wird). Payload wird von
// PushSubscriptionTrait::sendPushNotification() als JSON {title, body, url} gesendet.

self.addEventListener('push', (event) => {
  let data = { title: 'Die Bestesten', body: '' };
  try {
    if (event.data) data = { ...data, ...event.data.json() };
  } catch (e) {
    data.body = event.data ? event.data.text() : '';
  }

  event.waitUntil(
    self.registration.showNotification(data.title, {
      body: data.body,
      icon: 'img/logos/die-bestesten.png',
      badge: 'img/logos/die-bestesten.png',
      data: { url: data.url || '/' },
    })
  );
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const url = event.notification.data?.url || '/';

  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clientList) => {
      for (const client of clientList) {
        if ('focus' in client) return client.focus();
      }
      if (self.clients.openWindow) return self.clients.openWindow(url);
    })
  );
});

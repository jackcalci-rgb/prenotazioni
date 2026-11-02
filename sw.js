/* Osteria Dvor · service worker dell'area staff: riceve le notifiche push delle nuove prenotazioni.
   Non salva nulla in cache: la pagina si carica sempre dal server, così ogni aggiornamento arriva subito. */
self.addEventListener("install", function () { self.skipWaiting() });
self.addEventListener("activate", function (e) { e.waitUntil(self.clients.claim()) });

self.addEventListener("push", function (e) {
  var d = {};
  try { d = e.data ? e.data.json() : {} } catch (x) { d = { body: e.data ? e.data.text() : "" } }
  var title = d.title || "Dvor · staff";
  e.waitUntil(Promise.all([
    /* iPhone: every push must show a notification, or the system stops delivering them */
    self.registration.showNotification(title, {
      body: d.body || "", tag: d.tag || undefined, renotify: !!d.tag, icon: "icone/icona-192.png", badge: "icone/icona-192.png",
      data: { url: d.url || "./#staff", id: d.id || "", date: d.date || "" }
    }).then(function () {
      if (!self.navigator.setAppBadge) return;
      return self.registration.getNotifications().then(function (n) { return self.navigator.setAppBadge(n.length) }).catch(function () { });
    }),
    /* an open staff page reloads the bookings at once instead of waiting for the next refresh */
    self.clients.matchAll({ type: "window", includeUncontrolled: true }).then(function (list) {
      list.forEach(function (c) { c.postMessage({ type: "dvor-push", id: d.id || "", date: d.date || "" }) });
    })
  ]));
});

self.addEventListener("notificationclick", function (e) {
  e.notification.close();
  var data = e.notification.data || {}, url = new URL(data.url || "./#staff", self.registration.scope).href;
  e.waitUntil(self.clients.matchAll({ type: "window", includeUncontrolled: true }).then(function (list) {
    for (var i = 0; i < list.length; i++) {
      if (list[i].url.indexOf(self.registration.scope) === 0 && "focus" in list[i]) {
        list[i].postMessage({ type: "dvor-push-open", id: data.id || "", date: data.date || "" });
        return list[i].focus();
      }
    }
    return self.clients.openWindow(url);
  }));
});

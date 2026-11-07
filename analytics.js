/* Google Analytics 4 + Google Tag Manager + Pixel di Meta per prenota.osteriadvor.com, con Consent Mode v2 (GDPR):
   cookie e dati personali SOLO se l'ospite accetta. Stessi ID di osteriadvor.com.
   - Non parte nell'area staff (#staff) ne' quando la pagina e' incorporata nel sito (?embed=1):
     in quel caso la prenotazione viene passata al sito con postMessage e la conta osteriadvor.com.
   - Quando una prenotazione va a buon fine, index.html chiama window.dvorConversione(...). */
(function () {
  var GA_ID = 'G-YPB97TQ2E8', GTM_ID = 'GTM-5Z9QVWH7', FB_ID = '181965757227814', KEY = 'dvor-consent';
  var PRIVACY = 'https://osteriadvor.com/privacy.html';
  var embed = false;
  try { embed = new URLSearchParams(location.search).get('embed') === '1' || window.top !== window.self; } catch (e) { embed = true; }

  // Valore della prenotazione: 60 EUR a persona per il menu di San Martino, altrimenti nessun valore
  function valore(d) { return /martino/i.test(d.evento || '') ? 60 * (d.persone || 0) : 0; }

  window.dvorConversione = function (d) {
    d = d || {};
    var v = valore(d), info = { persone: d.persone || 0, evento: d.evento || '', valore: v };
    if (embed) { try { window.parent.postMessage({ type: 'dvor-prenota-booked', persone: info.persone, evento: info.evento, valore: v }, '*'); } catch (e) {} return; }
    try { if (window.dataLayer) dataLayer.push({ event: 'prenotazione', persone: info.persone, evento: info.evento, valore: v, currency: 'EUR' }); } catch (e) {}
    try { if (window.gtag) gtag('event', 'prenotazione', v ? { currency: 'EUR', value: v, persone: info.persone, evento: info.evento } : { persone: info.persone, evento: info.evento }); } catch (e) {}
    try { if (window.fbq) fbq('track', 'Schedule', v ? { currency: 'EUR', value: v, content_name: info.evento } : { content_name: info.evento || 'Tavolo' }); } catch (e) {}
  };

  if (embed || location.hash === '#staff') return;

  function get() { try { return localStorage.getItem(KEY); } catch (e) { return null; } }
  function set(v) { try { localStorage.setItem(KEY, v); } catch (e) {} }
  function boot() {
    if (window.__dvorGA) return; window.__dvorGA = 1;
    window.dataLayer = window.dataLayer || [];
    window.gtag = function () { dataLayer.push(arguments); };
    gtag('consent', 'default', { ad_storage: 'denied', ad_user_data: 'denied', ad_personalization: 'denied', analytics_storage: 'denied', wait_for_update: 500 });
    gtag('set', 'ads_data_redaction', true);
    gtag('js', new Date());
    gtag('config', GA_ID, { anonymize_ip: true });
    var s = document.createElement('script'); s.async = true;
    s.src = 'https://www.googletagmanager.com/gtag/js?id=' + GA_ID; document.head.appendChild(s);
    // Google Tag Manager: caricato DOPO il consenso di default "negato", cosi' i tag in GTM rispettano il banner.
    // Attenzione: in GTM non aggiungere un altro tag GA4 con G-YPB97TQ2E8, altrimenti le visite vengono contate due volte.
    if (GTM_ID) {
      dataLayer.push({ 'gtm.start': new Date().getTime(), event: 'gtm.js' });
      var g = document.createElement('script'); g.async = true;
      g.src = 'https://www.googletagmanager.com/gtm.js?id=' + GTM_ID; document.head.appendChild(g);
    }
  }
  function loadFB() {
    if (!FB_ID || window.__dvorFB) return; window.__dvorFB = 1;
    !function (f, b, e, v, n, t, s) { if (f.fbq) return; n = f.fbq = function () { n.callMethod ? n.callMethod.apply(n, arguments) : n.queue.push(arguments); };
      if (!f._fbq) f._fbq = n; n.push = n; n.loaded = !0; n.version = '2.0'; n.queue = []; t = b.createElement(e); t.async = !0; t.src = v;
      s = b.getElementsByTagName(e)[0]; s.parentNode.insertBefore(t, s); }(window, document, 'script', 'https://connect.facebook.net/en_US/fbevents.js');
    fbq('init', FB_ID); fbq('track', 'PageView');
  }
  function load() { loadFB(); boot(); gtag('consent', 'update', { analytics_storage: 'granted', ad_storage: 'granted', ad_user_data: 'granted' }); }
  function drop() {
    document.cookie.split(';').forEach(function (c) {
      var n = c.split('=')[0].trim();
      if (n === '_ga' || n.indexOf('_ga_') === 0 || n === '_fbp' || n === '_fbc') {
        ['', '.' + location.hostname, '.osteriadvor.com'].forEach(function (d) {
          document.cookie = n + '=; Max-Age=0; path=/' + (d ? '; domain=' + d : '');
        });
      }
    });
  }
  var L = ['it', 'en', 'de', 'sl', 'nl'];
  var T = {
    t: ['Usiamo Google Analytics e il Pixel di Meta per capire come viene usato il sito e misurare le nostre campagne, solo se acconsenti.',
        'We use Google Analytics and the Meta Pixel to understand how the site is used and to measure our campaigns, only if you agree.',
        'Wir verwenden Google Analytics und den Meta-Pixel, um die Nutzung der Website zu verstehen und unsere Kampagnen zu messen, nur mit deiner Einwilligung.',
        'Uporabljamo Google Analytics in Meta Pixel, da razumemo uporabo spletnega mesta in merimo svoje kampanje, le z vašim soglasjem.',
        'We gebruiken Google Analytics en de Meta-pixel om te begrijpen hoe de site wordt gebruikt en om onze campagnes te meten, alleen met je toestemming.'],
    p: ['Privacy policy', 'Privacy policy', 'Datenschutz', 'Zasebnost', 'Privacy'],
    a: ['Accetta', 'Accept', 'Akzeptieren', 'Sprejmem', 'Accepteren'],
    r: ['Rifiuta', 'Decline', 'Ablehnen', 'Zavrnem', 'Weigeren']
  };
  function lang() { var s = document.getElementById('lang'), v = (s && s.value) || (navigator.language || 'it').slice(0, 2); return Math.max(0, L.indexOf(v)); }
  function banner() {
    if (document.getElementById('dvor-cc')) return;
    var i = lang(), d = document.createElement('div'); d.id = 'dvor-cc'; d.setAttribute('role', 'dialog'); d.setAttribute('aria-label', 'Cookie');
    d.style.cssText = 'position:fixed;left:0;right:0;bottom:0;z-index:99999;background:#F6EFE4;color:#2B1D14;border-top:1px solid #D8C6AA;box-shadow:0 -6px 24px rgba(0,0,0,.18);padding:1rem 1.25rem calc(1rem + env(safe-area-inset-bottom,0px));font:400 .95rem/1.5 system-ui,sans-serif';
    d.innerHTML = '<div style="max-width:1180px;margin:0 auto;display:flex;flex-wrap:wrap;gap:.75rem 1.5rem;align-items:center;justify-content:space-between"><p style="margin:0;flex:1 1 320px">' + T.t[i] + ' <a href="' + PRIVACY + '" target="_blank" rel="noopener" style="color:inherit;text-decoration:underline">' + T.p[i] + '</a></p><div style="display:flex;gap:.6rem"><button type="button" id="dvor-cc-n" style="cursor:pointer;background:transparent;color:inherit;border:1px solid currentColor;padding:.6rem 1.2rem;font:inherit">' + T.r[i] + '</button><button type="button" id="dvor-cc-y" style="cursor:pointer;background:#7A2E22;color:#FBF3E6;border:1px solid #7A2E22;padding:.6rem 1.2rem;font:inherit">' + T.a[i] + '</button></div></div>';
    document.body.appendChild(d);
    d.querySelector('#dvor-cc-y').onclick = function () { set('granted'); d.remove(); load(); };
    d.querySelector('#dvor-cc-n').onclick = function () { set('denied'); d.remove(); gtag('consent', 'update', { analytics_storage: 'denied' }); drop(); };
  }
  function init() { boot(); var c = get(); if (c === 'granted') load(); else if (c === 'denied') drop(); else banner(); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();

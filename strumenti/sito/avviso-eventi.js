/* Osteria Dvor · avviso a comparsa quando un evento viene annullato dall'area staff (pulsante «Annulla evento").
   Questo file lo copia sul sito principale strumenti/sito-eventi.php (lanciato da aggiorna.sh): non modificarlo sul sito.
   Legge gli eventi da prenota.osteriadvor.com/evento.php?json=1 e, se un evento dei prossimi 60 giorni è cancellato,
   mostra l'avviso una volta per visita. Compare sopra l'eventuale altro avviso del sito (notice.js), che resta dietro. */
(function () {
  var SRC = 'https://prenota.osteriadvor.com/evento.php?json=1', DAYS = 60, KEY = 'dvor-annullato-';
  var LG = ['it', 'en', 'de', 'sl', 'nl'];
  var T = {
    k: ['Avviso', 'Notice', 'Hinweis', 'Obvestilo', 'Mededeling'],
    rib: ['Cancellato', 'Cancelled', 'Abgesagt', 'Odpovedano', 'Geannuleerd'],
    h1: ['Evento annullato', 'Event cancelled', 'Veranstaltung abgesagt', 'Dogodek odpovedan', 'Evenement geannuleerd'],
    hn: ['Eventi annullati', 'Events cancelled', 'Veranstaltungen abgesagt', 'Odpovedani dogodki', 'Evenementen geannuleerd'],
    p: ['Ci dispiace: questo appuntamento non si terrà. Ci scusiamo per il disagio.',
        'We are sorry: this event will not take place. We apologise for the inconvenience.',
        'Es tut uns leid: Diese Veranstaltung findet nicht statt. Wir entschuldigen uns für die Unannehmlichkeiten.',
        'Žal nam je: dogodek ne bo izveden. Opravičujemo se za nevšečnosti.',
        'Het spijt ons: dit evenement gaat niet door. Excuses voor het ongemak.'],
    pn: ['Ci dispiace: questi appuntamenti non si terranno. Ci scusiamo per il disagio.',
        'We are sorry: these events will not take place. We apologise for the inconvenience.',
        'Es tut uns leid: Diese Veranstaltungen finden nicht statt. Wir entschuldigen uns für die Unannehmlichkeiten.',
        'Žal nam je: ti dogodki ne bodo izvedeni. Opravičujemo se za nevšečnosti.',
        'Het spijt ons: deze evenementen gaan niet door. Excuses voor het ongemak.'],
    b: ['Gli altri eventi', 'Other events', 'Weitere Veranstaltungen', 'Drugi dogodki', 'Andere evenementen'],
    c: ['Ho capito', 'Got it', 'Verstanden', 'Razumem', 'Begrepen'],
    n: ['Per informazioni:', 'Information:', 'Informationen:', 'Informacije:', 'Informatie:']
  };
  var LOC = ['it-IT', 'en-GB', 'de-DE', 'sl-SI', 'nl-NL'];
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function sp(k) { return LG.map(function (l, i) { return '<span class="' + l + '" lang="' + l + '">' + T[k][i] + '</span>'; }).join(''); }
  function today() { try { return new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/Rome' }).format(new Date()); } catch (e) { return new Date().toISOString().slice(0, 10); } }
  function plus(d, n) { var x = new Date(d + 'T12:00:00Z'); x.setUTCDate(x.getUTCDate() + n); return x.toISOString().slice(0, 10); }
  function day(d, i) { var s = new Date(d + 'T12:00:00Z').toLocaleDateString(LOC[i], { timeZone: 'UTC', weekday: 'long', day: 'numeric', month: 'long' }); return s.charAt(0).toUpperCase() + s.slice(1); }
  function seen(id) { try { return !!sessionStorage.getItem(KEY + id); } catch (e) { return false; } }
  function mark(list) { try { list.forEach(function (e) { sessionStorage.setItem(KEY + e.id, '1'); }); } catch (e) {} }
  function item(e) {
    return '<li class="nx-ev">' + LG.map(function (l, i) {
      var t = i === 0 ? (e.title_it || e.title_en) : (e.title_en || e.title_it);
      return '<span class="' + l + '" lang="' + l + '"><b>' + esc(t) + '</b><em>' + esc(day(e.date, i)) + '</em></span>';
    }).join('') + '</li>';
  }
  var CSS = '#dvor-nx{position:fixed;inset:0;z-index:99995;background:rgba(20,12,6,.66);display:flex;align-items:center;justify-content:center;padding:1rem;opacity:0;transition:opacity .3s}'
    + '#dvor-nx .nx-box{max-width:28rem;width:100%;max-height:calc(100vh - 2rem);overflow:auto;background:var(--bg,#F6EFE4);color:var(--ink,#2B1D14);box-shadow:0 20px 60px rgba(0,0,0,.5);position:relative;overflow:hidden;font:400 1rem/1.6 var(--sans,system-ui,sans-serif);border-top:6px solid #B3261E}'
    + '#dvor-nx .nx-rib{position:absolute;top:1.25rem;left:-3.6rem;width:13rem;transform:rotate(-35deg);background:#B3261E;color:#fff;text-align:center;font:500 .72rem/2.2 var(--sans,system-ui,sans-serif);letter-spacing:.26em;text-transform:uppercase;box-shadow:0 3px 10px rgba(0,0,0,.3);pointer-events:none}'
    + '#dvor-nx .nx-in{padding:2.2rem 2rem 1.6rem;text-align:center}'
    + '#dvor-nx .nx-kick{display:block;font:400 .78rem/1 var(--sans,system-ui,sans-serif);letter-spacing:.28em;text-transform:uppercase;color:#B3261E;margin-bottom:.7rem}'
    + '#dvor-nx h2{margin:0 0 .9rem;font:400 1.9rem/1.15 var(--serif,Georgia,serif);color:var(--ink,#2B1D14)}#dvor-nx h2::before,#dvor-nx h2::after{display:none!important;content:none!important}'
    + '#dvor-nx ul{list-style:none;margin:0 0 1rem;padding:0}'
    + '#dvor-nx .nx-ev{border:1px solid var(--line,#D8C6AA);padding:.7rem .9rem;margin:0 0 .5rem}'
    + '#dvor-nx .nx-ev b{display:block;font:400 1.25rem/1.25 var(--serif,Georgia,serif);text-decoration:line-through;text-decoration-color:#B3261E;text-decoration-thickness:2px}'
    + '#dvor-nx .nx-ev em{display:block;font-style:normal;font-size:.9rem;color:var(--gold,#9C7A3C);margin-top:.15rem}'
    + '#dvor-nx .nx-p{margin:0 0 1.2rem}'
    + '#dvor-nx .nx-cta{display:inline-block;cursor:pointer;background:var(--green,#7A2E22);color:var(--on-green,#FBF3E6);border:1px solid var(--green,#7A2E22);padding:.8rem 1.7rem;font:inherit;letter-spacing:.1em;text-transform:uppercase;font-size:.85rem;text-decoration:none}#dvor-nx .nx-cta:hover{filter:brightness(1.15)}'
    + '#dvor-nx .nx-later{display:block;margin:.75rem auto 0;background:none;border:0;color:inherit;opacity:.65;text-decoration:underline;cursor:pointer;font:inherit;font-size:.9rem}'
    + '#dvor-nx .nx-note{margin:1.1rem 0 0;padding-top:.8rem;border-top:1px solid var(--line,#D8C6AA);font-size:.84rem;line-height:1.5;opacity:.8}'
    + '#dvor-nx .nx-x{position:absolute;top:.5rem;right:.5rem;background:none;border:0;width:2rem;height:2rem;color:inherit;font-size:1.6rem;line-height:1;cursor:pointer;padding:0}'
    + '@media (max-width:420px){#dvor-nx h2{font-size:1.6rem}#dvor-nx .nx-in{padding:2rem 1.2rem 1.2rem}}';
  function show(list) {
    if (document.getElementById('dvor-nx')) return;
    var one = list.length === 1;
    var st = document.createElement('style'); st.textContent = CSS; document.head.appendChild(st);
    var o = document.createElement('div'); o.id = 'dvor-nx';
    o.innerHTML = '<div class="nx-box" role="alertdialog" aria-modal="true" aria-labelledby="dvor-nx-h">'
      + '<span class="nx-rib" aria-hidden="true">' + sp('rib') + '</span>'
      + '<button type="button" class="nx-x" aria-label="Close">&times;</button>'
      + '<div class="nx-in"><span class="nx-kick">' + sp('k') + '</span>'
      + '<h2 id="dvor-nx-h">' + sp(one ? 'h1' : 'hn') + '</h2>'
      + '<ul>' + list.map(item).join('') + '</ul>'
      + '<p class="nx-p">' + sp(one ? 'p' : 'pn') + '</p>'
      + (/eventi\.html/.test(location.pathname) ? '' : '<a class="nx-cta" href="eventi.html">' + sp('b') + '</a>')
      + '<button type="button" class="nx-later">' + sp('c') + '</button>'
      + '<p class="nx-note">' + sp('n') + ' <a href="tel:+390481090740" style="text-decoration:underline;white-space:nowrap">+39 0481 090740</a> · <a href="https://wa.me/393923883900" style="text-decoration:underline">WhatsApp</a></p></div></div>';
    document.body.appendChild(o);
    var prev = document.activeElement;
    function close() { mark(list); document.removeEventListener('keydown', key); o.style.opacity = 0; setTimeout(function () { o.remove(); if (prev && prev.focus) prev.focus(); }, 300); }
    function key(e) { if (e.key === 'Escape') close(); }
    document.addEventListener('keydown', key);
    o.addEventListener('click', function (e) { if (e.target === o) close(); });
    o.querySelector('.nx-x').onclick = close; o.querySelector('.nx-later').onclick = close;
    var cta = o.querySelector('.nx-cta'); if (cta) cta.addEventListener('click', function () { mark(list); });
    requestAnimationFrame(function () { o.style.opacity = 1; o.querySelector('.nx-later').focus(); });
  }
  function init() {
    var t = today(), lim = plus(t, DAYS);
    fetch(SRC, { cache: 'no-cache' }).then(function (r) { return r.ok ? r.json() : []; }).then(function (j) {
      var list = (Array.isArray(j) ? j : []).filter(function (e) { return e && e.cancelled && e.date >= t && e.date <= lim && !seen(e.id); })
        .sort(function (a, b) { return a.date < b.date ? -1 : 1; }).slice(0, 4);
      if (list.length) setTimeout(function () { show(list); }, 600);
    }).catch(function () {});
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();

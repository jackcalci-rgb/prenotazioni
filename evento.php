<?php
/* Osteria Dvor · la pagina pubblica di ogni evento e l'elenco per il sito
   evento.php?e=natale-2026        pagina dell'evento: il link unico da usare in email, social, WhatsApp, QR code
                                   (con anteprima per WhatsApp e Facebook; i parametri utm_* passano al pulsante «Prenota»)
   evento.php?e=natale-2026&l=en   la stessa pagina in inglese
   evento.php?json=1               tutti gli eventi in JSON, per la pagina eventi.html del sito (letta anche da un altro dominio) */
declare(strict_types=1);
const DVOR_NO_REQUEST = true;
require __DIR__ . '/api.php';
header_remove('Content-Type');

$site = rtrim((string)($C['site_url'] ?? 'https://www.osteriadvor.com'), '/');
$base = rtrim((string)($C['booking_url'] ?? ''), '/');
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
function evImg(string $base, array $ev): string { $i = (string)($ev['imageId'] ?? ''); return preg_match('/^[A-Za-z0-9_-]{1,64}\.(jpg|jpeg|png|webp|gif)$/', $i) ? $base . '/uploads/' . $i : ''; }

/* ---------- the list for the website ---------- */
if (isset($_GET['json'])) {
  header('Content-Type: application/json; charset=utf-8');
  header('Access-Control-Allow-Origin: *'); // public data, read by www.osteriadvor.com/eventi.html
  header('Cache-Control: public, max-age=60');
  $list = [];
  foreach (load('events') as $id => $ev) {
    $id = (string)$id;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($ev['date'] ?? '')) || trim((string)($ev['title_it'] ?? '') . (string)($ev['title_en'] ?? '')) === '' || !empty($ev['hidden'])) continue;
    $list[] = [
      'id' => eventSlug($id, $ev), 'date' => $ev['date'], 'time' => !empty($ev['allDay']) ? '' : (string)($ev['time'] ?? ''), 'allDay' => !empty($ev['allDay']),
      'title_it' => (string)($ev['title_it'] ?? ''), 'title_en' => (string)($ev['title_en'] ?? ''),
      'desc_it' => (string)($ev['desc_it'] ?? ''), 'desc_en' => (string)($ev['desc_en'] ?? ''),
      'price' => (string)($ev['price'] ?? ''), 'flyer' => evImg($base, $ev),
      'link' => eventBookUrl($C, $id, $ev), 'page' => eventPageUrl($C, $id, $ev), 'source' => 'prenota',
    ];
  }
  usort($list, fn($a, $b) => strcmp($a['date'] . $a['time'], $b['date'] . $b['time']));
  echo json_encode($list, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

/* ---------- the page of one event ---------- */
$l = (string)($_GET['l'] ?? $_GET['lang'] ?? '');
if (!preg_match('/^(it|en|de|sl|nl)$/', $l)) $l = strtolower(substr((string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'it'), 0, 2));
$lang = $l === 'it' || !preg_match('/^(en|de|sl|nl)$/', $l) ? 'it' : 'en'; // the event form has Italian and English
$T = [
  'it' => ['book' => 'Prenota il tuo posto', 'all' => 'Tutti gli eventi', 'at' => 'ore', 'allDay' => 'tutto il giorno', 'past' => 'Questo evento si è già svolto.', 'share' => 'Condividi', 'copy' => 'Copia link', 'copied' => 'Link copiato',
    'nf' => 'Evento non trovato', 'nfText' => 'Questo evento non c\'è più o il link non è completo. Trovi tutti i prossimi appuntamenti sul nostro sito.', 'event' => 'Evento', 'call' => 'Per informazioni', 'other' => 'English'],
  'en' => ['book' => 'Book your place', 'all' => 'All events', 'at' => 'at', 'allDay' => 'all day', 'past' => 'This event has already taken place.', 'share' => 'Share', 'copy' => 'Copy link', 'copied' => 'Link copied',
    'nf' => 'Event not found', 'nfText' => 'This event is no longer available or the link is incomplete. You can find all our upcoming events on our website.', 'event' => 'Event', 'call' => 'Information', 'other' => 'Italiano'],
][$lang];

$found = eventFind((string)($_GET['e'] ?? ''));
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: public, max-age=60');
header('Referrer-Policy: strict-origin-when-cross-origin');
if (!$found) http_response_code(404);

/* campaign parameters go on to the booking page, which saves them with the booking */
$keep = [];
foreach ($_GET as $k => $v) if (is_string($v) && preg_match('/^(utm_(source|medium|campaign|content|term)|gclid|fbclid)$/', (string)$k)) $keep[$k] = mb_substr($v, 0, 120);
$qs = $keep ? '&' . http_build_query($keep) : '';

if ($found) {
  [$id, $ev] = $found;
  $pick = fn($k) => trim((string)($ev[$k . '_' . $lang] ?? '')) ?: trim((string)($ev[$k . '_it'] ?? '') ?: (string)($ev[$k . '_en'] ?? ''));
  $title = $pick('title'); $desc = $pick('desc'); $img = evImg($base, $ev); $slug = eventSlug($id, $ev);
  $ts = strtotime((string)$ev['date'] . ' 12:00');
  $WD = $lang === 'it' ? ['domenica', 'lunedì', 'martedì', 'mercoledì', 'giovedì', 'venerdì', 'sabato'] : ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
  $MO = $lang === 'it' ? ['gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre'] : ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
  $day = $ts ? $WD[(int)date('w', $ts)] . ' ' . date('j', $ts) . ' ' . $MO[(int)date('n', $ts) - 1] . ' ' . date('Y', $ts) : (string)$ev['date'];
  $day = mb_strtoupper(mb_substr($day, 0, 1)) . mb_substr($day, 1);
  $when = $day . (!empty($ev['allDay']) ? ' · ' . $T['allDay'] : (!empty($ev['time']) ? ' · ' . $T['at'] . ' ' . $ev['time'] : ''));
  $past = (string)$ev['date'] < date('Y-m-d');
  $canon = eventPageUrl($C, $id, $ev) . ($lang === 'en' ? '&l=en' : '');
  $book = eventBookUrl($C, $id, $ev) . '&lang=' . $lang . $qs;
  $allUrl = $site . '/eventi.html#ev-' . $slug;
  $short = trim((string)preg_replace('/\s+/', ' ', $desc)); if (mb_strlen($short) > 200) $short = rtrim(mb_substr($short, 0, 195)) . '…';
  $metaDesc = trim($when . '. ' . (!empty($ev['price']) ? $ev['price'] . '. ' : '') . $short);
  $pageTitle = $title . ' · Osteria Enoteca Dvor';
  $ld = ['@context' => 'https://schema.org', '@type' => 'Event', 'name' => $title, 'startDate' => $ev['date'] . (!empty($ev['time']) && empty($ev['allDay']) ? 'T' . $ev['time'] . ':00' : ''),
    'eventStatus' => 'https://schema.org/EventScheduled', 'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode', 'description' => $desc ?: null, 'url' => $canon,
    'location' => ['@type' => 'Place', 'name' => 'Osteria Enoteca Dvor', 'address' => ['@type' => 'PostalAddress', 'streetAddress' => 'Via Castello 5', 'addressLocality' => 'San Floriano del Collio', 'postalCode' => '34070', 'addressCountry' => 'IT']],
    'organizer' => ['@type' => 'Organization', 'name' => 'Osteria Enoteca Dvor', 'url' => $site]];
  if ($img) $ld['image'] = [$img];
  if (!empty($ev['price'])) $ld['offers'] = ['@type' => 'Offer', 'description' => $ev['price'], 'url' => eventBookUrl($C, $id, $ev)];
  $ld = array_filter($ld, fn($v) => $v !== null);
} else {
  $title = $T['nf']; $pageTitle = $T['nf'] . ' · Osteria Enoteca Dvor'; $metaDesc = $T['nfText']; $canon = ''; $img = '';
}

$paras = '';
if ($found) foreach (preg_split('/\n+/', $desc) as $p) if (trim($p) !== '') $paras .= '<p>' . $h(trim($p)) . '</p>';
$otherUrl = $found ? eventPageUrl($C, $id, $ev) . ($lang === 'it' ? '&l=en' : '&l=it') . $qs : '';
?><!doctype html>
<html lang="<?= $lang ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= $h($pageTitle) ?></title>
<meta name="description" content="<?= $h($metaDesc) ?>">
<?php if ($found): ?>
<link rel="canonical" href="<?= $h($canon) ?>">
<meta property="og:type" content="website">
<meta property="og:site_name" content="Osteria Enoteca Dvor">
<meta property="og:locale" content="<?= $lang === 'it' ? 'it_IT' : 'en_GB' ?>">
<meta property="og:title" content="<?= $h($title) ?>">
<meta property="og:description" content="<?= $h($metaDesc) ?>">
<meta property="og:url" content="<?= $h($canon) ?>">
<?php if ($img): ?><meta property="og:image" content="<?= $h($img) ?>">
<meta name="twitter:image" content="<?= $h($img) ?>"><?php endif ?>
<meta name="twitter:card" content="<?= $img ? 'summary_large_image' : 'summary' ?>">
<meta name="twitter:title" content="<?= $h($title) ?>">
<meta name="twitter:description" content="<?= $h($metaDesc) ?>">
<script type="application/ld+json"><?= json_encode($ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>
<?php else: ?>
<meta name="robots" content="noindex">
<?php endif ?>
<meta name="theme-color" content="#13100E">
<link rel="icon" type="image/png" href="<?= $h($site) ?>/images/favicon.png">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;1,300&family=Jost:wght@300;400;500&display=swap" rel="stylesheet">
<style>
:root{--bg:#FAF7F2;--alt:#F2EDE4;--ink:#1F1A16;--muted:#6C645B;--line:#DED6C9;--gold:#9A7B43;--fill:#1F1A16;--on-fill:#FAF7F2;
  --serif:"Cormorant Garamond","Iowan Old Style",Georgia,serif;--sans:"Jost","Helvetica Neue",Arial,sans-serif}
@media (prefers-color-scheme:dark){:root{--bg:#13100E;--alt:#1A1613;--ink:#EEE7DB;--muted:#ABA195;--line:#312A24;--gold:#C6A56A;--fill:#C6A56A;--on-fill:#13100E}}
*{box-sizing:border-box}
html{-webkit-text-size-adjust:100%}
body{margin:0;background:var(--bg);color:var(--ink);font:300 1.0625rem/1.85 var(--sans);letter-spacing:.01em;-webkit-font-smoothing:antialiased}
a{color:inherit}
header{display:flex;align-items:center;justify-content:space-between;gap:1rem;padding:1rem 16px;max-width:1180px;margin:0 auto;border-bottom:1px solid var(--line)}
.mark{display:block;line-height:0}.mark img{height:44px;width:auto}
.nav{display:flex;gap:1.2rem;font-size:.7rem;letter-spacing:.24em;text-transform:uppercase}
.nav a{text-decoration:none;color:var(--muted)}.nav a:hover{color:var(--gold)}
main{max-width:1180px;margin:0 auto;padding:3rem 16px 4rem}
.ev{display:grid;grid-template-columns:minmax(0,5fr) minmax(0,6fr);gap:2.6rem;align-items:start;border:1px solid var(--gold);padding:1.6rem;box-shadow:0 24px 60px rgba(0,0,0,.08)}
.ev.noimg{grid-template-columns:1fr;max-width:760px;margin:0 auto}
.pic img{display:block;width:100%;height:auto;background:var(--alt)}
.caps{display:inline-block;font-size:.76rem;letter-spacing:.24em;text-transform:uppercase;font-weight:400}
.cd{margin-left:.9rem;color:var(--gold);font-size:.8rem;letter-spacing:.18em;text-transform:uppercase}
h1{font-family:var(--serif);font-weight:300;font-size:clamp(2.1rem,4.4vw,3.2rem);line-height:1.12;margin:1rem 0 .6rem}
.dt{font-size:1.05rem;color:var(--gold);letter-spacing:.04em;margin:0 0 1.2rem}
.desc{color:var(--muted);line-height:1.8}.desc p{margin:0 0 .9rem}
.pr{display:inline-block;border:1px solid var(--line);padding:.3rem .8rem;margin:.4rem 0 1rem;font-size:.92rem}
.bt{display:flex;gap:.8rem;flex-wrap:wrap;margin-top:1.2rem}
.btn{display:inline-block;padding:1rem 2.2rem;border:1px solid var(--ink);color:var(--ink);background:transparent;text-decoration:none;font:400 .68rem/1.4 var(--sans);letter-spacing:.3em;text-transform:uppercase;cursor:pointer;transition:background .25s,color .25s,border-color .25s}
.btn.fill{background:var(--fill);border-color:var(--fill);color:var(--on-fill)}
.btn:hover{background:var(--gold);border-color:var(--gold);color:#fff}
.past{color:var(--muted);font-style:italic;margin:.6rem 0 0}
.share{display:flex;gap:1rem;flex-wrap:wrap;align-items:center;margin-top:1.8rem;padding-top:1.2rem;border-top:1px solid var(--line);font-size:.8rem;letter-spacing:.12em;text-transform:uppercase;color:var(--muted)}
.share a,.share button{color:var(--ink);background:none;border:0;border-bottom:1px solid var(--gold);padding:0;font:inherit;letter-spacing:inherit;text-transform:inherit;cursor:pointer;text-decoration:none}
footer{max-width:1180px;margin:0 auto;padding:2rem 16px 3rem;border-top:1px solid var(--line);color:var(--muted);font-size:.9rem;text-align:center}
footer a{text-decoration:none;border-bottom:1px solid var(--line)}
@media (max-width:820px){.ev{grid-template-columns:1fr;gap:1.4rem;padding:1rem}.nav .hide{display:none}main{padding-top:1.6rem}}
</style>
</head>
<body>
<header>
  <a class="mark" href="<?= $h($site) ?>/" aria-label="Osteria Enoteca Dvor"><picture><source srcset="<?= $h($site) ?>/images/logo-light.png" media="(prefers-color-scheme: dark)"><img src="<?= $h($site) ?>/images/logo-dark.png" width="1067" height="248" alt="Dvor"></picture></a>
  <nav class="nav"><a class="hide" href="<?= $h($site) ?>/eventi.html"><?= $h($T['all']) ?></a><?php if ($found): ?><a href="<?= $h($otherUrl) ?>" hreflang="<?= $lang === 'it' ? 'en' : 'it' ?>"><?= $h($T['other']) ?></a><?php endif ?></nav>
</header>
<main>
<?php if ($found): ?>
  <article class="ev<?= $img ? '' : ' noimg' ?>">
    <?php if ($img): ?><div class="pic"><img src="<?= $h($img) ?>" alt="<?= $h($title) ?>"></div><?php endif ?>
    <div>
      <span class="caps"><?= $h($T['event']) ?></span><?php if (!$past): ?><span class="cd" id="cd" data-date="<?= $h($ev['date']) ?>"></span><?php endif ?>
      <h1><?= $h($title) ?></h1>
      <p class="dt"><?= $h($when) ?></p>
      <div class="desc"><?= $paras ?></div>
      <?php if (!empty($ev['price'])): ?><span class="pr"><?= $h($ev['price']) ?></span><?php endif ?>
      <div class="bt">
        <?php if (!$past): ?><a class="btn fill" href="<?= $h($book) ?>"><?= $h($T['book']) ?></a><?php endif ?>
        <a class="btn" href="<?= $h($allUrl) ?>"><?= $h($T['all']) ?></a>
      </div>
      <?php if ($past): ?><p class="past"><?= $h($T['past']) ?></p><?php endif ?>
      <div class="share"><span><?= $h($T['share']) ?>:</span>
        <a href="https://wa.me/?text=<?= rawurlencode($title . ' · ' . $canon) ?>" target="_blank" rel="noopener">WhatsApp</a>
        <a href="https://www.facebook.com/sharer/sharer.php?u=<?= rawurlencode($canon) ?>" target="_blank" rel="noopener">Facebook</a>
        <button type="button" id="cp" data-url="<?= $h($canon) ?>" data-done="<?= $h($T['copied']) ?>"><?= $h($T['copy']) ?></button>
      </div>
    </div>
  </article>
<?php else: ?>
  <article class="ev noimg">
    <div>
      <h1><?= $h($T['nf']) ?></h1>
      <p class="desc"><?= $h($T['nfText']) ?></p>
      <div class="bt"><a class="btn fill" href="<?= $h($site) ?>/eventi.html"><?= $h($T['all']) ?></a></div>
    </div>
  </article>
<?php endif ?>
</main>
<footer>Osteria Enoteca Dvor · Via Castello 5, San Floriano del Collio (GO) · <?= $h($T['call']) ?>: <a href="tel:+390481090740">+39 0481 090740</a> · <a href="https://wa.me/393923883900">WhatsApp</a> · <a href="<?= $h($site) ?>/">osteriadvor.com</a></footer>
<script>
(function(){
  var c=document.getElementById("cd");
  if(c){var t=new Intl.DateTimeFormat("en-CA",{timeZone:"Europe/Rome"}).format(new Date()),n=Math.round((Date.parse(c.getAttribute("data-date")+"T00:00:00Z")-Date.parse(t+"T00:00:00Z"))/864e5),it=document.documentElement.lang==="it";
    c.textContent=n===0?(it?"Oggi":"Today"):n===1?(it?"Domani":"Tomorrow"):(it?"Tra "+n+" giorni":"In "+n+" days")}
  var b=document.getElementById("cp");
  if(b)b.addEventListener("click",function(){var u=b.getAttribute("data-url");
    if(navigator.share){navigator.share({title:document.title,url:u}).catch(function(){});return}
    (navigator.clipboard?navigator.clipboard.writeText(u):Promise.reject()).then(function(){b.textContent=b.getAttribute("data-done")},function(){prompt("",u)})});
})();
</script>
<script src="analytics.js" defer></script>
</body>
</html>

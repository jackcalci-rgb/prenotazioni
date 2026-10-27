<?php
/* Osteria Dvor · prenotazioni · API
   Archivio su file JSON in data/, email di conferma con file calendario, accesso staff con password. */
declare(strict_types=1);
if (!is_file(__DIR__ . '/config.php')) { header('Content-Type: application/json'); http_response_code(500); echo '{"error":{"code":"no_config","message":"Manca config.php: copia config.example.php in config.php e compilalo"}}'; exit; }
$C = require __DIR__ . '/config.php';
date_default_timezone_set($C['timezone'] ?? 'Europe/Rome');
$C['_logo_cid'] = is_file(__DIR__ . '/email-logo.png') ? 'dvor-logo@osteriadvor' : '';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

const COLLS = ['bookings', 'events', 'overrides', 'settings'];
const GUEST_FIELDS = ['date', 'time', 'party', 'status', 'createdAt', 'source', 'example'];
const GUEST_UPDATABLE = ['status', 'cancelledAt', 'date', 'time', 'movedAt', 'retained'];

function out($x, int $code = 200): void { http_response_code($code); echo json_encode($x, JSON_UNESCAPED_UNICODE); exit; }
function fail(string $code, string $msg, int $http = 400): void { out(['error' => ['code' => $code, 'message' => $msg]], $http); }

/* ---------- storage ---------- */
function dataDir(): string { global $C; $d = $C['data_dir'] ?? (__DIR__ . '/data'); if (!is_dir($d)) mkdir($d, 0750, true); return $d; }
function withLock(callable $fn) {
  $h = fopen(dataDir() . '/.lock', 'c'); flock($h, LOCK_EX);
  try { return $fn(); } finally { flock($h, LOCK_UN); fclose($h); }
}
function load(string $coll): array {
  $f = dataDir() . "/$coll.json";
  if (!is_file($f)) $f = __DIR__ . "/seed/$coll.json"; // first start: events and settings copied from the prototype
  if (!is_file($f)) return [];
  $x = json_decode((string)file_get_contents($f), true);
  return is_array($x) ? $x : [];
}
function save(string $coll, array $rows): void {
  $f = dataDir() . "/$coll.json"; $tmp = $f . '.tmp';
  file_put_contents($tmp, json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
  rename($tmp, $f);
}
function newId(): string { return bin2hex(random_bytes(10)); }
function splitPath(string $p): array {
  $a = explode('/', $p);
  if (count($a) !== 2 || !in_array($a[0], COLLS, true) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $a[1])) fail('bad_path', 'Percorso non valido');
  return $a;
}
function checkColl(string $c): void { if (!in_array($c, COLLS, true)) fail('bad_path', 'Raccolta non valida'); }

/* ---------- who is calling ---------- */
function isStaff(array $C): bool {
  $t = $_SERVER['HTTP_X_STAFF_TOKEN'] ?? '';
  if (!preg_match('/^([a-f0-9]{64})\.(\d+)$/', $t, $m)) return false;
  if ((int)$m[2] < time()) return false;
  return hash_equals(hash_hmac('sha256', 'staff|' . $m[2], $C['secret']), $m[1]);
}
function clientId(): string { $c = $_SERVER['HTTP_X_CLIENT_ID'] ?? ''; return preg_match('/^[A-Za-z0-9_-]{8,64}$/', $c) ? $c : ''; }
function rateLimit(string $key, int $max, int $windowSec): void {
  $ip = $_SERVER['REMOTE_ADDR'] ?? 'x'; $k = $key . '|' . $ip; $now = time();
  $over = withLock(function () use ($k, $max, $windowSec, $now) {
    $r = load('_rate'); $list = array_values(array_filter($r[$k] ?? [], fn($t) => $t > $now - $windowSec));
    if (count($list) >= $max) return true;
    $list[] = $now; $r[$k] = $list;
    foreach ($r as $kk => $v) if (!$v || max($v) < $now - 86400) unset($r[$kk]);
    save('_rate', $r); return false;
  });
  if ($over) fail('rate_limited', 'Troppe richieste, riprova tra poco', 429);
}

/* ---------- queries ---------- */
function matches(array $doc, array $where): bool {
  foreach ($where as $w) {
    [$f, $op, $v] = $w; $x = $doc[$f] ?? null;
    $ok = match ($op) { '==' => $x === $v, '!=' => $x !== $v, '>=' => $x !== null && $x >= $v, '<=' => $x !== null && $x <= $v, '>' => $x !== null && $x > $v, '<' => $x !== null && $x < $v, default => false };
    if (!$ok) return false;
  }
  return true;
}
function redact(array $doc, bool $staff, string $cid): array {
  if ($staff || ($cid !== '' && ($doc['createdBy'] ?? '') === $cid)) return $doc;
  return array_intersect_key($doc, array_flip(GUEST_FIELDS));
}

/* ---------- email ---------- */
function ymdParts(string $date, string $time, int $holdMin): array {
  $tz = new DateTimeZone(date_default_timezone_get());
  $s = new DateTime("$date $time:00", $tz); $e = (clone $s)->modify("+$holdMin minutes");
  return [$s, $e];
}
function calLinks(array $b, array $C): array {
  [$s, $e] = ymdParts($b['date'], $b['time'], (int)$C['hold_min']);
  $title = 'Osteria Dvor · ' . $b['party'] . ' ' . L($b)['people'];
  $addr = 'Osteria Enoteca Dvor, Via Castello 5, 34070 San Floriano del Collio (GO)';
  $det = "Tel. +39 0481 090740 · WhatsApp +39 392 388 3900\nhttps://www.osteriadvor.com";
  return [
    'google' => 'https://calendar.google.com/calendar/render?action=TEMPLATE&text=' . rawurlencode($title) . '&dates=' . $s->format('Ymd\THis') . '/' . $e->format('Ymd\THis') . '&ctz=' . rawurlencode(date_default_timezone_get()) . '&location=' . rawurlencode($addr) . '&details=' . rawurlencode($det),
    'outlook' => 'https://outlook.live.com/calendar/0/deeplink/compose?path=%2Fcalendar%2Faction%2Fcompose&rru=addevent&subject=' . rawurlencode($title) . '&startdt=' . $s->format('Y-m-d\TH:i:s') . '&enddt=' . $e->format('Y-m-d\TH:i:s') . '&location=' . rawurlencode($addr) . '&body=' . rawurlencode($det),
  ];
}
function icsFor(array $b, array $C, string $method = 'PUBLISH', bool $cancel = false, bool $tentative = false): string {
  [$s, $e] = ymdParts($b['date'], $b['time'], (int)$C['hold_min']);
  $s->setTimezone(new DateTimeZone('UTC')); $e->setTimezone(new DateTimeZone('UTC'));
  $esc = fn($t) => str_replace(["\\", ";", ",", "\n"], ["\\\\", "\\;", "\\,", "\\n"], $t);
  $lines = [
    'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Osteria Dvor//Prenotazioni//IT', 'CALSCALE:GREGORIAN', 'METHOD:' . $method,
    'BEGIN:VEVENT',
    'UID:' . $b['id'] . '@osteriadvor.com',
    'DTSTAMP:' . gmdate('Ymd\THis\Z'),
    'DTSTART:' . $s->format('Ymd\THis\Z'),
    'DTEND:' . $e->format('Ymd\THis\Z'),
    'SEQUENCE:' . (int)($b['seq'] ?? 0),
    'SUMMARY:' . $esc('Osteria Dvor · ' . $b['party'] . ' ' . (L($b)['people'])),
    'LOCATION:' . $esc('Osteria Enoteca Dvor, Via Castello 5, 34070 San Floriano del Collio (GO), Italia'),
    'DESCRIPTION:' . $esc(($b['eventTitle'] ?? '' ? $b['eventTitle'] . "\n" : '') . "Tel. +39 0481 090740 · WhatsApp +39 392 388 3900\nhttps://www.osteriadvor.com"),
    'STATUS:' . ($cancel ? 'CANCELLED' : ($tentative ? 'TENTATIVE' : 'CONFIRMED')),
    'BEGIN:VALARM', 'ACTION:DISPLAY', 'DESCRIPTION:Osteria Dvor', 'TRIGGER:-PT3H', 'END:VALARM',
    'END:VEVENT', 'END:VCALENDAR',
  ];
  $outL = [];
  foreach ($lines as $l) { while (strlen($l) > 74) { $cut = 74; while ($cut > 0 && (ord($l[$cut]) & 0xC0) === 0x80) $cut--; $outL[] = substr($l, 0, $cut); $l = ' ' . substr($l, $cut); } $outL[] = $l; }
  return implode("\r\n", $outL) . "\r\n";
}
function L(array $b): array {
  static $T = [
    'it' => ['people' => 'persone', 'loc' => 'it_IT', 'ok' => 'Prenotazione confermata', 'rec' => 'Prenotazione ricevuta', 'moved' => 'Prenotazione spostata', 'canc' => 'Prenotazione annullata',
      'hi' => 'Ciao {n},', 'body_ok' => 'la tua prenotazione da Dvor è confermata:', 'body_rec' => 'abbiamo ricevuto la tua prenotazione da Dvor:', 'pending' => 'Ti confermiamo la cena via email il giorno prima.',
      'body_moved' => 'abbiamo spostato la tua prenotazione:', 'body_canc' => 'la tua prenotazione è stata annullata:',
      'add' => 'Aggiungila al tuo calendario', 'ics' => 'Oppure apri il file allegato (Apple, Outlook e altri calendari).', 'where' => 'Ci trovi in Via Castello 5, San Floriano del Collio (GO).',
      'change' => 'Per modifiche o disdette: tel. +39 0481 090740, WhatsApp +39 392 388 3900.', 'bye' => 'A presto!', 'at' => 'alle', 'tast' => 'Menù degustazione', 'carta' => 'Alla carta', 'disc' => 'Sconto del {p}% sul conto'],
    'en' => ['people' => 'guests', 'loc' => 'en_GB', 'ok' => 'Booking confirmed', 'rec' => 'Booking received', 'moved' => 'Booking moved', 'canc' => 'Booking cancelled',
      'hi' => 'Hello {n},', 'body_ok' => 'your table at Dvor is confirmed:', 'body_rec' => 'we have received your booking at Dvor:', 'pending' => 'We will confirm the dinner by email the day before.',
      'body_moved' => 'we have moved your booking:', 'body_canc' => 'your booking has been cancelled:',
      'add' => 'Add it to your calendar', 'ics' => 'Or open the attached file (Apple, Outlook and other calendars).', 'where' => 'Find us at Via Castello 5, San Floriano del Collio (GO), Italy.',
      'change' => 'To change or cancel: tel. +39 0481 090740, WhatsApp +39 392 388 3900.', 'bye' => 'See you soon!', 'at' => 'at', 'tast' => 'Tasting menu', 'carta' => 'À la carte', 'disc' => '{p}% off your bill'],
    'de' => ['people' => 'Personen', 'loc' => 'de_DE', 'ok' => 'Reservierung bestätigt', 'rec' => 'Reservierung erhalten', 'moved' => 'Reservierung verschoben', 'canc' => 'Reservierung storniert',
      'hi' => 'Hallo {n},', 'body_ok' => 'Ihr Tisch im Dvor ist bestätigt:', 'body_rec' => 'wir haben Ihre Reservierung im Dvor erhalten:', 'pending' => 'Das Abendessen bestätigen wir am Vortag per E-Mail.',
      'body_moved' => 'wir haben Ihre Reservierung verschoben:', 'body_canc' => 'Ihre Reservierung wurde storniert:',
      'add' => 'Zum Kalender hinzufügen', 'ics' => 'Oder öffnen Sie die angehängte Datei (Apple, Outlook und andere Kalender).', 'where' => 'Sie finden uns in der Via Castello 5, San Floriano del Collio (GO), Italien.',
      'change' => 'Änderungen oder Stornierung: Tel. +39 0481 090740, WhatsApp +39 392 388 3900.', 'bye' => 'Bis bald!', 'at' => 'um', 'tast' => 'Degustationsmenü', 'carta' => 'À la carte', 'disc' => '{p}% Rabatt auf die Rechnung'],
    'sl' => ['people' => 'oseb', 'loc' => 'sl_SI', 'ok' => 'Rezervacija potrjena', 'rec' => 'Rezervacija prejeta', 'moved' => 'Rezervacija prestavljena', 'canc' => 'Rezervacija preklicana',
      'hi' => 'Pozdravljeni {n},', 'body_ok' => 'vaša miza v Dvoru je potrjena:', 'body_rec' => 'prejeli smo vašo rezervacijo v Dvoru:', 'pending' => 'Večerjo vam potrdimo po e-pošti dan prej.',
      'body_moved' => 'vašo rezervacijo smo prestavili:', 'body_canc' => 'vaša rezervacija je preklicana:',
      'add' => 'Dodajte v koledar', 'ics' => 'Ali odprite priloženo datoteko (Apple, Outlook in drugi koledarji).', 'where' => 'Najdete nas na Via Castello 5, San Floriano del Collio (GO), Italija.',
      'change' => 'Za spremembe ali odpoved: tel. +39 0481 090740, WhatsApp +39 392 388 3900.', 'bye' => 'Se vidimo!', 'at' => 'ob', 'tast' => 'Degustacijski meni', 'carta' => 'À la carte', 'disc' => '{p}% popusta na račun'],
    'nl' => ['people' => 'personen', 'loc' => 'nl_NL', 'ok' => 'Reservering bevestigd', 'rec' => 'Reservering ontvangen', 'moved' => 'Reservering verplaatst', 'canc' => 'Reservering geannuleerd',
      'hi' => 'Hallo {n},', 'body_ok' => 'je tafel bij Dvor is bevestigd:', 'body_rec' => 'we hebben je reservering bij Dvor ontvangen:', 'pending' => 'We bevestigen het diner de dag ervoor per e-mail.',
      'body_moved' => 'we hebben je reservering verplaatst:', 'body_canc' => 'je reservering is geannuleerd:',
      'add' => 'Zet het in je agenda', 'ics' => 'Of open het bijgevoegde bestand (Apple, Outlook en andere agenda\'s).', 'where' => 'Je vindt ons op Via Castello 5, San Floriano del Collio (GO), Italië.',
      'change' => 'Wijzigen of annuleren: tel. +39 0481 090740, WhatsApp +39 392 388 3900.', 'bye' => 'Tot snel!', 'at' => 'om', 'tast' => 'Proeverijmenu', 'carta' => 'À la carte', 'disc' => '{p}% korting op de rekening'],
  ];
  return $T[$b['lang'] ?? 'it'] ?? $T['it'];
}
function dayLabel(array $b): string {
  $t = L($b);
  if (class_exists('IntlDateFormatter')) {
    $f = new IntlDateFormatter($t['loc'], IntlDateFormatter::FULL, IntlDateFormatter::NONE, date_default_timezone_get(), null, 'EEEE d MMMM y');
    return $f->format(new DateTime($b['date']));
  }
  return (new DateTime($b['date']))->format('d/m/Y');
}
function LX(array $b): array {
  static $X = [
    'it' => ['site' => 'Scopri menù, vini ed eventi', 'tip' => 'Se puoi, arriva qualche minuto prima: il panorama sul Collio dal nostro giardino merita una sosta.',
      'occasion' => 'Festeggi qualcosa? Rispondi a questa email e prepariamo una piccola sorpresa per la tua tavola.',
      'diet' => 'Allergie o esigenze particolari? Scrivici in risposta, la cucina si organizza per tempo.',
      'events' => 'Serate a tema, San Martino, il pranzo di Natale: scopri i prossimi eventi sul nostro sito.',
      'thanks' => 'Grazie per averci scelto. Non vediamo l\'ora di accoglierti da Dvor.',
      'canc_more' => 'Ci dispiace non vederti questa volta. La tua tavola ti aspetta quando vuoi: prenota di nuovo in un minuto.',
      'book_again' => 'Prenota di nuovo', 'footer' => 'Cucina di confine e friulana nel cuore del Collio'],
    'en' => ['site' => 'Explore our menu, wines and events', 'tip' => 'If you can, arrive a few minutes early: the view over the Collio from our garden is worth a pause.',
      'occasion' => 'Celebrating something? Reply to this email and we will prepare a little surprise for your table.',
      'diet' => 'Allergies or special requests? Reply to this email so the kitchen can plan ahead.',
      'events' => 'Themed evenings, St Martin\'s feast, Christmas lunch: see our upcoming events on our website.',
      'thanks' => 'Thank you for choosing us. We look forward to welcoming you at Dvor.',
      'canc_more' => 'We are sorry not to see you this time. Your table is waiting whenever you like: book again in a minute.',
      'book_again' => 'Book again', 'footer' => 'Border and Friulian cuisine in the heart of the Collio'],
    'de' => ['site' => 'Speisekarte, Weine und Veranstaltungen entdecken', 'tip' => 'Wenn möglich, kommen Sie ein paar Minuten früher: der Blick über den Collio aus unserem Garten lohnt sich.',
      'occasion' => 'Gibt es etwas zu feiern? Antworten Sie auf diese E-Mail und wir bereiten eine kleine Überraschung vor.',
      'diet' => 'Allergien oder besondere Wünsche? Antworten Sie auf diese E-Mail, damit sich die Küche vorbereiten kann.',
      'events' => 'Themenabende, Martinsfest, Weihnachtsessen: entdecken Sie unsere nächsten Veranstaltungen auf unserer Website.',
      'thanks' => 'Danke, dass Sie uns gewählt haben. Wir freuen uns auf Ihren Besuch im Dvor.',
      'canc_more' => 'Schade, dass es diesmal nicht klappt. Ihr Tisch wartet, wann immer Sie möchten: in einer Minute neu reservieren.',
      'book_again' => 'Erneut reservieren', 'footer' => 'Grenz- und friaulische Küche im Herzen des Collio'],
    'sl' => ['site' => 'Odkrijte jedilnik, vina in dogodke', 'tip' => 'Če lahko, pridite nekaj minut prej: razgled na Brda z našega vrta je vreden postanka.',
      'occasion' => 'Kaj praznujete? Odgovorite na to sporočilo in pripravili bomo majhno presenečenje.',
      'diet' => 'Alergije ali posebne želje? Odgovorite na to sporočilo, da se kuhinja pravočasno pripravi.',
      'events' => 'Tematski večeri, martinovanje, božično kosilo: oglejte si prihajajoče dogodke na naši spletni strani.',
      'thanks' => 'Hvala, da ste nas izbrali. Veselimo se vašega obiska v Dvoru.',
      'canc_more' => 'Škoda, da se tokrat ne vidimo. Vaša miza vas čaka, kadar želite: ponovno rezervirajte v minuti.',
      'book_again' => 'Ponovno rezerviraj', 'footer' => 'Obmejna in furlanska kuhinja v srcu Brd'],
    'nl' => ['site' => 'Ontdek ons menu, wijnen en evenementen', 'tip' => 'Kom als het kan een paar minuten eerder: het uitzicht over de Collio vanuit onze tuin is de moeite waard.',
      'occasion' => 'Iets te vieren? Beantwoord deze e-mail en we bereiden een kleine verrassing voor.',
      'diet' => 'Allergieën of speciale wensen? Beantwoord deze e-mail zodat de keuken zich kan voorbereiden.',
      'events' => 'Themaavonden, Sint-Maarten, kerstlunch: bekijk onze komende evenementen op onze website.',
      'thanks' => 'Bedankt dat je voor ons kiest. We kijken ernaar uit je bij Dvor te verwelkomen.',
      'canc_more' => 'Jammer dat we je deze keer niet zien. Je tafel wacht wanneer je wilt: reserveer opnieuw in een minuut.',
      'book_again' => 'Opnieuw reserveren', 'footer' => 'Grens- en Friulische keuken in het hart van de Collio'],
  ];
  return $X[$b['lang'] ?? 'it'] ?? $X['it'];
}
/* kind: ok | rec | moved | canc */
function guestMail(array $b, array $C, string $kind): array {
  $t = L($b); $x = LX($b); $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
  $site = rtrim($C['site_url'] ?? 'https://www.osteriadvor.com', '/');
  $bookUrl = $C['booking_url'] ?? '';
  $day = dayLabel($b); $when = "$day {$t['at']} {$b['time']}";
  $lines = [$b['party'] . ' ' . $t['people']];
  if (!empty($b['menuTasting'])) $lines[] = $t['tast'] . ': ' . (int)$b['menuTasting'];
  if (!empty($b['menuCarta'])) $lines[] = $t['carta'] . ': ' . (int)$b['menuCarta'];
  if (!empty($b['discount']) && $kind !== 'canc') $lines[] = str_replace('{p}', (string)(int)$b['discount'], $t['disc']);
  if (!empty($b['eventTitle'])) $lines[] = '✦ ' . $b['eventTitle'];
  $intro = $t['body_' . $kind] ?? $t['body_ok'];
  $hi = str_replace('{n}', $b['name'] ?? '', $t['hi']);
  $subject = $t[$kind] . ' · Osteria Dvor · ' . $day . ' ' . $b['time'];
  $l = calLinks($b, $C);
  $care = $kind === 'canc' ? [$x['canc_more']] : [$x['tip'], $x['occasion'], $x['diet'], $x['events']];

  /* plain text */
  $txt = "$hi\n\n$intro\n\n$when\n" . implode("\n", $lines) . "\n";
  if ($kind === 'rec') $txt .= "\n" . $t['pending'] . "\n";
  if ($kind !== 'canc') $txt .= "\n{$t['add']}:\nGoogle Calendar: {$l['google']}\nOutlook: {$l['outlook']}\n{$t['ics']}\n";
  $txt .= "\n" . implode("\n", $care) . "\n";
  if ($kind === 'canc' && $bookUrl) $txt .= "{$x['book_again']}: $bookUrl\n";
  $txt .= "\n{$x['site']}: $site\n\n{$t['where']}\n{$t['change']}\n\n" . ($kind === 'canc' ? $t['bye'] : $x['thanks']) . "\nOsteria Enoteca Dvor\n";

  /* html: tables and inline styles, readable in Gmail, Outlook, Apple Mail */
  $wine = '#7a1f2b'; $ink = '#2b211a'; $muted = '#6a5645'; $cream = '#f6efe4'; $paper = '#fffaf2'; $line = '#e5d8c4';
  $btn = fn($href, $label, $solid = false) => '<a href="' . $h($href) . '" style="display:inline-block;margin:4px 6px 4px 0;padding:11px 18px;border:1px solid ' . $wine . ';border-radius:6px;background:' . ($solid ? $wine : 'transparent') . ';color:' . ($solid ? '#ffffff' : $wine) . ';text-decoration:none;font-family:Helvetica,Arial,sans-serif;font-size:14px">' . $h($label) . '</a>';
  $logo = !empty($C['_logo_cid']) ? '<img src="cid:' . $C['_logo_cid'] . '" width="220" alt="DVOR" style="display:block;margin:0 auto;width:220px;max-width:70%;height:auto;border:0">' : '<div style="font-size:30px;letter-spacing:.2em;text-align:center">DVOR</div>';
  $html = '<!doctype html><html lang="' . $h($b['lang'] ?? 'it') . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light only"><title>' . $h($subject) . '</title></head>'
    . '<body style="margin:0;padding:0;background:' . $cream . '">'
    . '<div style="display:none;max-height:0;overflow:hidden">' . $h($when . ' · ' . $lines[0]) . '</div>'
    . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:' . $cream . '"><tr><td align="center" style="padding:28px 12px">'
    . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:580px;background:' . $paper . ';border:1px solid ' . $line . ';border-top:4px solid ' . $wine . ';border-radius:8px;font-family:Georgia,\'Times New Roman\',serif;color:' . $ink . '">'
    . '<tr><td style="padding:30px 28px 8px">' . $logo
    . '<div style="text-align:center;font-family:Helvetica,Arial,sans-serif;font-size:11px;letter-spacing:.22em;text-transform:uppercase;color:' . $muted . ';margin-top:10px">Osteria Enoteca · San Floriano del Collio</div></td></tr>'
    . '<tr><td style="padding:18px 28px 0"><h1 style="margin:0 0 14px;font-weight:normal;font-size:26px;line-height:1.25;color:' . ($kind === 'canc' ? $muted : $wine) . '">' . $h($t[$kind]) . '</h1>'
    . '<p style="margin:0 0 6px;font-size:16px;line-height:1.55">' . $h($hi) . '</p><p style="margin:0;font-size:16px;line-height:1.55">' . $h($intro) . '</p></td></tr>'
    . '<tr><td style="padding:18px 28px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#ffffff;border:1px solid ' . $line . ';border-left:4px solid ' . $wine . ';border-radius:6px"><tr><td style="padding:16px 18px">'
    . '<div style="font-size:21px;line-height:1.3;' . ($kind === 'canc' ? 'text-decoration:line-through;color:' . $muted : '') . '">' . $h($when) . '</div>'
    . '<div style="margin-top:6px;font-family:Helvetica,Arial,sans-serif;font-size:14px;line-height:1.6;color:' . $muted . '">' . implode('<br>', array_map($h, $lines)) . '</div>'
    . '</td></tr></table>'
    . ($kind === 'rec' ? '<p style="margin:12px 0 0;font-size:15px;line-height:1.5">' . $h($t['pending']) . '</p>' : '')
    . '</td></tr>'
    . ($kind !== 'canc' ? '<tr><td style="padding:0 28px 6px"><div style="font-family:Helvetica,Arial,sans-serif;font-size:12px;letter-spacing:.14em;text-transform:uppercase;color:' . $muted . ';margin-bottom:6px">' . $h($t['add']) . '</div>'
      . $btn($l['google'], 'Google Calendar') . $btn($l['outlook'], 'Outlook')
      . '<p style="margin:6px 0 0;font-family:Helvetica,Arial,sans-serif;font-size:12px;color:' . $muted . '">' . $h($t['ics']) . '</p></td></tr>' : '')
    . '<tr><td style="padding:18px 28px 4px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-top:1px solid ' . $line . '"><tr><td style="padding-top:16px">'
    . implode('', array_map(fn($p) => '<p style="margin:0 0 10px;font-size:15px;line-height:1.55">' . $h($p) . '</p>', $care))
    . '</td></tr></table></td></tr>'
    . '<tr><td style="padding:6px 28px 24px">' . ($kind === 'canc' && $bookUrl ? $btn($bookUrl, $x['book_again'], true) : '') . $btn($site, $x['site'], $kind !== 'canc') . '</td></tr>'
    . '<tr><td style="padding:0 28px 26px"><p style="margin:0;font-size:16px;line-height:1.55">' . $h($kind === 'canc' ? $t['bye'] : $x['thanks']) . '</p><p style="margin:6px 0 0;font-size:16px;color:' . $wine . '">Osteria Enoteca Dvor</p></td></tr>'
    . '</table>'
    . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:580px"><tr><td style="padding:16px 12px;text-align:center;font-family:Helvetica,Arial,sans-serif;font-size:12px;line-height:1.7;color:' . $muted . '">'
    . $h($x['footer']) . '<br>Via Castello 5, 34070 San Floriano del Collio (GO)<br>'
    . '<a href="tel:+390481090740" style="color:' . $muted . '">+39 0481 090740</a> · <a href="https://wa.me/393923883900" style="color:' . $muted . '">WhatsApp +39 392 388 3900</a><br>'
    . '<a href="' . $h($site) . '" style="color:' . $wine . '">' . $h(preg_replace('#^https?://#', '', $site)) . '</a>'
    . '</td></tr></table>'
    . '</td></tr></table></body></html>';
  $ics = icsFor($b, $C, $kind === 'canc' ? 'CANCEL' : 'PUBLISH', $kind === 'canc', $kind === 'rec');
  return ['subject' => $subject, 'text' => $txt, 'html' => $html, 'ics' => $ics, 'logo' => !empty($C['_logo_cid'])];
}
function encHeader(string $s): string { return preg_match('/[^\x20-\x7E]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s; }
function buildMime(array $C, string $to, array $m): array {
  $b1 = 'b1_' . bin2hex(random_bytes(8)); $b2 = 'b2_' . bin2hex(random_bytes(8)); $b3 = 'b3_' . bin2hex(random_bytes(8));
  $headers = [
    'From: ' . encHeader($C['mail_from_name']) . ' <' . $C['mail_from'] . '>',
    'Reply-To: ' . ($C['reply_to'] ?: $C['mail_from']),
    'MIME-Version: 1.0',
    'Date: ' . date('r'),
    'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . (explode('@', $C['mail_from'])[1] ?? 'localhost') . '>',
  ];
  $logoFile = __DIR__ . '/email-logo.png';
  $withLogo = !empty($m['logo']) && is_file($logoFile);
  $body = '';
  if (!empty($m['ics'])) {
    $headers[] = "Content-Type: multipart/mixed; boundary=\"$b1\"";
    $body .= "--$b1\r\nContent-Type: multipart/alternative; boundary=\"$b2\"\r\n\r\n";
  } else {
    $headers[] = "Content-Type: multipart/alternative; boundary=\"$b2\"";
  }
  $body .= "--$b2\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($m['text'])) . "\r\n";
  if (!empty($m['html'])) {
    $htmlPart = "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($m['html'])) . "\r\n";
    if ($withLogo) {
      $body .= "--$b2\r\nContent-Type: multipart/related; boundary=\"$b3\"\r\n\r\n"
        . "--$b3\r\n$htmlPart"
        . "--$b3\r\nContent-Type: image/png; name=\"dvor-logo.png\"\r\nContent-ID: <" . $C['_logo_cid'] . ">\r\nContent-Disposition: inline; filename=\"dvor-logo.png\"\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode((string)file_get_contents($logoFile))) . "\r\n"
        . "--$b3--\r\n";
    } else {
      $body .= "--$b2\r\n$htmlPart";
    }
  }
  $body .= "--$b2--\r\n";
  if (!empty($m['ics'])) {
    $body .= "--$b1\r\nContent-Type: text/calendar; charset=UTF-8; method=" . (str_contains($m['ics'], 'METHOD:CANCEL') ? 'CANCEL' : 'PUBLISH') . "; name=\"dvor.ics\"\r\nContent-Disposition: attachment; filename=\"dvor.ics\"\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($m['ics'])) . "\r\n--$b1--\r\n";
  }
  return [$headers, $body];
}
function smtpSend(array $C, string $to, string $subject, array $headers, string $body): void {
  $host = $C['smtp_host']; $port = (int)$C['smtp_port']; $secure = $C['smtp_secure'];
  $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
  $s = @stream_socket_client(($secure === 'ssl' ? 'ssl://' : 'tcp://') . "$host:$port", $en, $es, 20, STREAM_CLIENT_CONNECT, $ctx);
  if (!$s) throw new RuntimeException("SMTP connessione fallita a $host:$port ($en $es)");
  stream_set_timeout($s, 20);
  $read = function () use ($s) { $r = ''; while (($l = fgets($s, 1024)) !== false) { $r .= $l; if (strlen($l) < 4 || $l[3] === ' ') break; } return $r; };
  $cmd = function (string $c, array $okCodes) use ($s, $read) { if ($c !== '') fwrite($s, $c . "\r\n"); $r = $read(); if (!in_array((int)substr($r, 0, 3), $okCodes, true)) throw new RuntimeException('SMTP: ' . trim($r)); return $r; };
  $cmd('', [220]);
  $ehloHost = explode('@', $C['mail_from'])[1] ?? 'localhost';
  $cmd("EHLO $ehloHost", [250]);
  if ($secure === 'tls') {
    $cmd('STARTTLS', [220]);
    if (!stream_socket_enable_crypto($s, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new RuntimeException('SMTP: STARTTLS fallito');
    $cmd("EHLO $ehloHost", [250]);
  }
  if ($C['smtp_user'] !== '') {
    $cmd('AUTH LOGIN', [334]); $cmd(base64_encode($C['smtp_user']), [334]); $cmd(base64_encode($C['smtp_pass']), [235]);
  }
  $cmd('MAIL FROM:<' . $C['mail_from'] . '>', [250]);
  $cmd('RCPT TO:<' . $to . '>', [250, 251]);
  $cmd('DATA', [354]);
  $msg = implode("\r\n", array_merge(['To: <' . $to . '>', 'Subject: ' . encHeader($subject)], $headers)) . "\r\n\r\n" . $body;
  $msg = preg_replace('/^\./m', '..', str_replace(["\r\n", "\n"], ["\n", "\r\n"], $msg));
  $cmd($msg . "\r\n.", [250]);
  $cmd('QUIT', [221, 250]);
  fclose($s);
}
function sendMail(array $C, string $to, array $m): void {
  if (!filter_var($to, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Email non valida');
  [$headers, $body] = buildMime($C, $to, $m);
  if (($C['mail_method'] ?? 'smtp') === 'mail') {
    if (!mail($to, encHeader($m['subject']), $body, implode("\r\n", $headers), '-f' . $C['mail_from'])) throw new RuntimeException('mail() fallita');
  } else {
    smtpSend($C, $to, $m['subject'], $headers, $body);
  }
}
function logMail(string $line): void { @file_put_contents(dataDir() . '/mail.log', date('c') . ' ' . $line . "\n", FILE_APPEND); }
function notifyStaff(array $C, array $b, string $what): void {
  if (empty($C['notify_to'])) return;
  $txt = "$what\n\n{$b['date']} {$b['time']} · {$b['party']} persone\n{$b['name']} · {$b['phone']} · {$b['email']}\n"
    . 'Menù: carta ' . (int)($b['menuCarta'] ?? 0) . ', degustazione ' . (int)($b['menuTasting'] ?? 0) . "\n"
    . (!empty($b['notes']) ? "Note: {$b['notes']}\n" : '') . (!empty($b['discount']) ? "Sconto {$b['discount']}%\n" : '') . (!empty($b['eventTitle']) ? "Evento: {$b['eventTitle']}\n" : '');
  foreach (array_filter(array_map('trim', explode(',', (string)$C['notify_to']))) as $to) {
    try { sendMail($C, $to, ['subject' => "$what · {$b['date']} {$b['time']} · {$b['party']} pers. · {$b['name']}", 'text' => $txt]); logMail("staff ok $what {$b['id']} $to"); }
    catch (Throwable $e) { logMail("staff ERR {$b['id']} $to " . $e->getMessage()); }
  }
}
function mailGuest(array $C, array &$b, string $kind): bool {
  if (empty($b['email']) || ($b['source'] ?? '') === 'test' || !empty($b['example'])) return false;
  try { sendMail($C, $b['email'], guestMail($b, $C, $kind)); $b['emailSentAt'] = date('c'); $b['emailKind'] = $kind; unset($b['emailError']); logMail("guest ok $kind {$b['id']}"); return true; }
  catch (Throwable $e) { $b['emailError'] = $e->getMessage(); logMail("guest ERR $kind {$b['id']} " . $e->getMessage()); return false; }
}

/* ---------- validation ---------- */
function cleanBooking(array $x, bool $staff = false): array {
  $s = fn($v, $n = 200) => mb_substr(trim((string)$v), 0, $n);
  $b = [
    'date' => $s($x['date'] ?? '', 10), 'time' => $s($x['time'] ?? '', 5), 'party' => (int)($x['party'] ?? 0),
    'name' => $s($x['name'] ?? '', 120), 'phone' => $s($x['phone'] ?? '', 40), 'email' => $s($x['email'] ?? '', 160),
    'eventTitle' => $s($x['eventTitle'] ?? '', 200), 'menuCarta' => max(0, (int)($x['menuCarta'] ?? 0)), 'menuTasting' => max(0, (int)($x['menuTasting'] ?? 0)),
    'discount' => max(0, min(50, (int)($x['discount'] ?? 0))), 'notes' => $s($x['notes'] ?? '', 1000), 'lang' => preg_match('/^[a-z]{2}$/', (string)($x['lang'] ?? '')) ? $x['lang'] : 'it',
    'status' => 'active', 'offer' => $s($x['offer'] ?? '', 300), 'insight' => $s($x['insight'] ?? '', 500),
    'createdAt' => date('c'), 'source' => in_array($x['source'] ?? '', $staff ? ['assistant', 'form', 'test', 'phone', 'whatsapp', 'walkin'] : ['assistant', 'form', 'test'], true) ? $x['source'] : 'form',
    'pending' => !empty($x['pending']),
  ];
  if (!empty($x['example'])) $b['example'] = true;
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $b['date']) || !preg_match('/^\d{2}:\d{2}$/', $b['time'])) fail('bad_request', 'Data o ora non valida');
  if ($b['party'] < 1 || $b['party'] > 74) fail('bad_request', 'Numero di persone non valido');
  if (in_array($b['source'], ['phone', 'whatsapp', 'walkin'], true)) { // taken by the staff: only the name is required
    if ($b['name'] === '') fail('bad_request', 'Il nome è obbligatorio');
    if ($b['email'] !== '' && !filter_var($b['email'], FILTER_VALIDATE_EMAIL)) fail('bad_request', 'Email non valida');
    return $b;
  }
  if ($b['source'] !== 'test' && ($b['name'] === '' || $b['phone'] === '' || !filter_var($b['email'], FILTER_VALIDATE_EMAIL))) fail('bad_request', 'Nome, telefono ed email sono obbligatori');
  return $b;
}

/* ---------- request ---------- */
$staff = isStaff($C); $cid = clientId();

if (($_GET['op'] ?? '') === 'upload') {
  if (!$staff) fail('not_granted', 'Solo staff', 403);
  $f = $_FILES['file'] ?? null;
  if (!$f || $f['error'] !== UPLOAD_ERR_OK || $f['size'] > 8 * 1024 * 1024) fail('bad_request', 'File non valido (max 8 MB)');
  $types = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
  $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
  if (!isset($types[$mime])) fail('bad_request', 'Solo immagini JPG, PNG, WEBP o GIF');
  $name = bin2hex(random_bytes(12)) . '.' . $types[$mime];
  if (!is_dir(__DIR__ . '/uploads')) mkdir(__DIR__ . '/uploads', 0755, true);
  move_uploaded_file($f['tmp_name'], __DIR__ . '/uploads/' . $name);
  out(['id' => $name, 'url' => 'uploads/' . $name]);
}

$in = json_decode((string)file_get_contents('php://input'), true) ?: [];
$op = $in['op'] ?? ($_GET['op'] ?? '');

switch ($op) {
  case 'info':
    out(['ai' => !empty($C['anthropic_api_key']), 'staff' => $staff]);

  case 'login':
    usleep(400000);
    if (!is_string($in['password'] ?? null) || $C['staff_password'] === 'CAMBIAMI' || !hash_equals($C['staff_password'], $in['password'])) fail('denied', 'Password errata', 403);
    $exp = time() + 12 * 3600;
    out(['token' => hash_hmac('sha256', 'staff|' . $exp, $C['secret']) . '.' . $exp]);

  case 'get': {
    $coll = (string)($in['coll'] ?? ''); checkColl($coll);
    $rows = load($coll); $res = [];
    foreach ($rows as $id => $doc) {
      if (!matches($doc, is_array($in['where'] ?? null) ? $in['where'] : [])) continue;
      $res[] = ['id' => (string)$id, 'data' => $coll === 'bookings' ? redact($doc, $staff, $cid) : $doc];
    }
    $lim = (int)($in['limit'] ?? 0); if ($lim > 0) $res = array_slice($res, 0, $lim);
    out(['docs' => $res]);
  }

  case 'getdoc': {
    [$coll, $id] = splitPath((string)($in['path'] ?? ''));
    $rows = load($coll);
    if (!isset($rows[$id])) out(['exists' => false]);
    out(['exists' => true, 'data' => $coll === 'bookings' ? redact($rows[$id], $staff, $cid) : $rows[$id]]);
  }

  case 'add': {
    $coll = (string)($in['coll'] ?? ''); checkColl($coll);
    $data = is_array($in['data'] ?? null) ? $in['data'] : [];
    if ($coll === 'bookings') {
      $isTest = ($data['source'] ?? '') === 'test';
      if ($isTest && !$staff) fail('not_granted', 'Solo staff', 403);
      $b = cleanBooking($data, $staff); $b['createdBy'] = $cid;
      $manual = in_array($b['source'], ['phone', 'whatsapp', 'walkin'], true);
      if (!$isTest && !$manual) rateLimit('book', 12, 3600);
      $id = newId(); $b['id'] = $id;
      if ($manual) { if (!empty($data['sendMail']) && $b['email'] !== '') mailGuest($C, $b, $b['pending'] ? 'rec' : 'ok'); }
      elseif (!$isTest) { mailGuest($C, $b, $b['pending'] ? 'rec' : 'ok'); notifyStaff($C, $b, 'Nuova prenotazione'); }
      unset($b['id']);
      withLock(function () use ($id, $b) { $r = load('bookings'); $r[$id] = $b; save('bookings', $r); });
      out(['id' => $id]);
    }
    if (!$staff) fail('not_granted', 'Solo staff', 403);
    $id = newId();
    withLock(function () use ($coll, $id, $data) { $r = load($coll); $r[$id] = $data; save($coll, $r); });
    out(['id' => $id]);
  }

  case 'set':
  case 'update': {
    [$coll, $id] = splitPath((string)($in['path'] ?? ''));
    $data = is_array($in['data'] ?? null) ? $in['data'] : [];
    if ($coll !== 'bookings') {
      if (!$staff) fail('not_granted', 'Solo staff', 403);
      withLock(function () use ($coll, $id, $data, $op) { $r = load($coll); $r[$id] = $op === 'set' ? $data : array_merge($r[$id] ?? [], $data); save($coll, $r); });
      out(['ok' => true]);
    }
    $res = withLock(function () use ($id, $data, $staff, $cid, $op) {
      $r = load('bookings'); if (!isset($r[$id])) return ['err' => 'not_found'];
      $old = $r[$id];
      if (!$staff) {
        if ($cid === '' || ($old['createdBy'] ?? '') !== $cid) return ['err' => 'not_granted'];
        $data = array_intersect_key($data, array_flip(GUEST_UPDATABLE));
      }
      $new = $op === 'set' && $staff ? $data : array_merge($old, $data);
      $r[$id] = $new; save('bookings', $r);
      return ['old' => $old, 'new' => $new];
    });
    if (isset($res['err'])) fail($res['err'], 'Non consentito', $res['err'] === 'not_found' ? 404 : 403);
    $old = $res['old']; $b = $res['new']; $b['id'] = $id; $kind = '';
    if (($old['status'] ?? '') !== 'cancelled' && ($b['status'] ?? '') === 'cancelled') { $kind = 'canc'; notifyStaff($C, $b, 'Prenotazione annullata'); }
    elseif (($old['date'] ?? '') !== ($b['date'] ?? '') || ($old['time'] ?? '') !== ($b['time'] ?? '')) { $kind = 'moved'; $b['seq'] = (int)($old['seq'] ?? 0) + 1; notifyStaff($C, $b, 'Prenotazione spostata'); }
    if ($kind !== '') {
      mailGuest($C, $b, $kind); unset($b['id']);
      withLock(function () use ($id, $b) { $r = load('bookings'); if (isset($r[$id])) { $r[$id] = array_merge($r[$id], array_intersect_key($b, array_flip(['emailSentAt', 'emailKind', 'emailError', 'seq']))); save('bookings', $r); } });
    }
    out(['ok' => true]);
  }

  case 'delete': {
    [$coll, $id] = splitPath((string)($in['path'] ?? ''));
    if (!$staff) fail('not_granted', 'Solo staff', 403);
    withLock(function () use ($coll, $id) { $r = load($coll); unset($r[$id]); save($coll, $r); });
    out(['ok' => true]);
  }

  case 'resend': {
    if (!$staff) fail('not_granted', 'Solo staff', 403);
    $id = (string)($in['id'] ?? ''); $r = load('bookings');
    if (!isset($r[$id])) fail('not_found', 'Prenotazione non trovata', 404);
    $b = $r[$id]; $b['id'] = $id; $b['source'] = ($b['source'] ?? '') === 'test' ? 'test-resend' : $b['source']; unset($b['example']);
    $kind = ($b['status'] ?? '') === 'cancelled' ? 'canc' : (!empty($in['confirm']) || empty($b['pending']) ? 'ok' : 'rec');
    $ok = mailGuest($C, $b, $kind);
    withLock(function () use ($id, $b) { $r = load('bookings'); if (isset($r[$id])) { $r[$id] = array_merge($r[$id], array_intersect_key($b, array_flip(['emailSentAt', 'emailKind', 'emailError']))); if (!isset($b['emailError'])) unset($r[$id]['emailError']); save('bookings', $r); } });
    if (!$ok) fail('mail_failed', $b['emailError'] ?? 'Invio non riuscito', 502);
    out(['ok' => true]);
  }

  case 'claude': {
    if (empty($C['anthropic_api_key'])) fail('not_granted', 'Assistente non configurato', 403);
    rateLimit('ai', 40, 600);
    $body = [
      'model' => $C['anthropic_model'] ?: 'claude-haiku-5-5',
      'max_tokens' => 1024,
      'messages' => $in['messages'] ?? [],
    ];
    if (!empty($in['system'])) $body['system'] = (string)$in['system'];
    if (!empty($in['tools'])) $body['tools'] = $in['tools'];
    if (strlen(json_encode($body)) > 200000) fail('bad_request', 'Richiesta troppo grande');
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
      CURLOPT_HTTPHEADER => ['content-type: application/json', 'x-api-key: ' . $C['anthropic_api_key'], 'anthropic-version: 2023-06-01'],
      CURLOPT_POSTFIELDS => json_encode($body)]);
    $res = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($res === false) fail('upstream', 'Assistente non raggiungibile', 502);
    http_response_code($code ?: 502); echo $res; exit;
  }

  default:
    fail('bad_request', 'Operazione sconosciuta');
}

<?php
/* Osteria Dvor · email marketing: campagne, template a blocchi, coda di invio, aperture, clic, disiscrizioni e rimbalzi.
   Incluso da api.php (operazioni camp_* e tpl_*), da m.php (link nelle email) e dagli strumenti da cron
   (strumenti/campagne.php invia la coda, strumenti/rimbalzi.php legge i rimbalzi dal log di Postfix).

   Campaign document (docs, coll «campaigns»): name, subject, preheader, fromName, blocks[], audience{seg,tag,lang,origin,ids},
   status draft | scheduled | sending | paused | sent, scheduledAt, startedAt, sentAt, code (utm_campaign), total.
   One row per recipient in mail_sends: its random id is the personal token in the open pixel, the links and the unsubscribe link. */
declare(strict_types=1);

const CAMP_TEST = 'prova'; // token of test emails: links work, nothing is counted
const CAMP_BLOCKS = ['heading', 'text', 'image', 'button', 'book', 'event', 'divider', 'spacer'];
const CAMP_SEGS = ['all', 'regular', 'once', 'lapsed', 'upcoming', 'recent', 'bday', 'tasting', 'events', 'noshow', 'engaged', 'unengaged', 'never'];
const CAMP_LANGS = ['', 'it', 'en', 'de', 'sl', 'nl', 'xit'];
const CAMP_ORIGINS = ['all', 'site', 'staff', 'import', 'signup'];

function campBase(array $C): string { return rtrim((string)($C['booking_url'] ?? ''), '/'); }
function campSig(string $tok, string $url): string { global $C; return substr(hash_hmac('sha256', "click|$tok|$url", (string)$C['secret']), 0, 16); }
function campNewToken(): string { return bin2hex(random_bytes(12)); }

/* words around the content, in the language of the guest */
function campT(string $lang): array {
  $T = [
    'it' => ['why' => 'Ricevi questa email perché sei ospite di Osteria Dvor e hai scelto di ricevere le nostre novità.', 'unsub' => 'Disiscriviti', 'pre_unsub' => 'Non vuoi più riceverle?',
      'book' => 'Prenota il tuo tavolo', 'event' => 'Scopri e prenota', 'friend' => '',
      'u_title' => 'Disiscrizione', 'u_ask' => 'Vuoi smettere di ricevere le email con le novità di Osteria Dvor all\'indirizzo {e}?', 'u_btn' => 'Sì, disiscrivimi',
      'u_done' => 'Fatto: non riceverai più le nostre email con novità e offerte all\'indirizzo {e}. Le conferme delle tue prenotazioni continueranno ad arrivare.',
      'u_again' => 'Ti sei disiscritto per sbaglio?', 'u_re' => 'Iscrivimi di nuovo', 'u_back' => 'Sei di nuovo iscritto. Grazie, a presto da Dvor!',
      'u_bad' => 'Questo link non è valido. Per non ricevere più le nostre email rispondi a una di esse scrivendo «cancellami».', 'u_test' => 'Questa è un\'email di prova: qui l\'ospite si disiscrive con un tocco.',
      'site' => 'Vai al sito di Osteria Dvor',
      's_title' => 'Iscriviti alle novità di Dvor', 's_text' => 'Serate a tema, menù di stagione, eventi e offerte riservate. Poche email, solo quando c\'è qualcosa di buono.',
      's_name' => 'Nome', 's_email' => 'Email', 's_ok' => 'Iscrivimi', 's_priv' => 'Puoi disiscriverti quando vuoi con il link in fondo a ogni email.',
      's_sent' => 'Quasi fatto: ti abbiamo mandato un\'email a {e}. Tocca il pulsante che trovi dentro per confermare l\'iscrizione.',
      's_conf' => 'Iscrizione confermata. Benvenuto da Dvor!', 's_err' => 'Controlla l\'indirizzo email e riprova.',
      'c_subj' => 'Conferma la tua iscrizione · Osteria Dvor', 'c_h' => 'Conferma l\'iscrizione', 'c_body' => 'Tocca il pulsante per ricevere le novità di Osteria Dvor. Se non sei stato tu, ignora questa email: senza conferma non ti scriveremo.', 'c_btn' => 'Confermo, iscrivimi'],
    'en' => ['why' => 'You are receiving this email because you are a guest of Osteria Dvor and chose to hear our news.', 'unsub' => 'Unsubscribe', 'pre_unsub' => 'No longer want these emails?',
      'book' => 'Book your table', 'event' => 'Find out more and book',
      'u_title' => 'Unsubscribe', 'u_ask' => 'Stop receiving news emails from Osteria Dvor at {e}?', 'u_btn' => 'Yes, unsubscribe me',
      'u_done' => 'Done: you will no longer receive our news and offers at {e}. Confirmations of your bookings will still arrive.',
      'u_again' => 'Unsubscribed by mistake?', 'u_re' => 'Subscribe me again', 'u_back' => 'You are subscribed again. Thank you, see you soon at Dvor!',
      'u_bad' => 'This link is not valid. To stop our emails, reply to one of them with «unsubscribe».', 'u_test' => 'This is a test email: here the guest unsubscribes with one tap.',
      'site' => 'Visit the Osteria Dvor website',
      's_title' => 'Get the news from Dvor', 's_text' => 'Themed evenings, seasonal menus, events and special offers. Few emails, only when there is something good.',
      's_name' => 'Name', 's_email' => 'Email', 's_ok' => 'Subscribe', 's_priv' => 'You can unsubscribe at any time with the link at the bottom of every email.',
      's_sent' => 'Almost done: we sent an email to {e}. Tap the button inside to confirm.',
      's_conf' => 'Subscription confirmed. Welcome to Dvor!', 's_err' => 'Please check the email address and try again.',
      'c_subj' => 'Confirm your subscription · Osteria Dvor', 'c_h' => 'Confirm your subscription', 'c_body' => 'Tap the button to receive news from Osteria Dvor. If this was not you, ignore this email: without confirmation we will not write to you.', 'c_btn' => 'Yes, subscribe me'],
    'de' => ['why' => 'Sie erhalten diese E-Mail, weil Sie Gast der Osteria Dvor sind und unsere Neuigkeiten erhalten möchten.', 'unsub' => 'Abmelden', 'pre_unsub' => 'Keine E-Mails mehr erhalten?',
      'book' => 'Tisch reservieren', 'event' => 'Mehr erfahren und reservieren',
      'u_title' => 'Abmelden', 'u_ask' => 'Keine Neuigkeiten der Osteria Dvor mehr an {e} erhalten?', 'u_btn' => 'Ja, abmelden',
      'u_done' => 'Erledigt: Sie erhalten keine Neuigkeiten und Angebote mehr an {e}. Bestätigungen Ihrer Reservierungen kommen weiterhin.',
      'u_again' => 'Aus Versehen abgemeldet?', 'u_re' => 'Wieder anmelden', 'u_back' => 'Sie sind wieder angemeldet. Danke, bis bald im Dvor!',
      'u_bad' => 'Dieser Link ist ungültig. Antworten Sie auf eine unserer E-Mails mit «abmelden».', 'u_test' => 'Dies ist eine Test-E-Mail: hier meldet sich der Gast mit einem Klick ab.',
      'site' => 'Zur Website der Osteria Dvor',
      's_title' => 'Neuigkeiten von Dvor', 's_text' => 'Themenabende, saisonale Menüs, Veranstaltungen und Angebote. Wenige E-Mails, nur wenn es etwas Gutes gibt.',
      's_name' => 'Name', 's_email' => 'E-Mail', 's_ok' => 'Anmelden', 's_priv' => 'Abmelden jederzeit über den Link am Ende jeder E-Mail.',
      's_sent' => 'Fast geschafft: wir haben eine E-Mail an {e} geschickt. Tippen Sie auf die Schaltfläche darin, um zu bestätigen.',
      's_conf' => 'Anmeldung bestätigt. Willkommen im Dvor!', 's_err' => 'Bitte prüfen Sie die E-Mail-Adresse.',
      'c_subj' => 'Anmeldung bestätigen · Osteria Dvor', 'c_h' => 'Anmeldung bestätigen', 'c_body' => 'Tippen Sie auf die Schaltfläche, um Neuigkeiten der Osteria Dvor zu erhalten. Wenn Sie das nicht waren, ignorieren Sie diese E-Mail.', 'c_btn' => 'Ja, anmelden'],
    'sl' => ['why' => 'To e-pošto prejemate, ker ste gost Osteria Dvor in ste želeli prejemati naše novice.', 'unsub' => 'Odjava', 'pre_unsub' => 'Ne želite več prejemati teh sporočil?',
      'book' => 'Rezervirajte mizo', 'event' => 'Več in rezervacija',
      'u_title' => 'Odjava', 'u_ask' => 'Ne želite več prejemati novic Osteria Dvor na {e}?', 'u_btn' => 'Da, odjavi me',
      'u_done' => 'Opravljeno: novic in ponudb na {e} ne boste več prejemali. Potrditve rezervacij bodo še naprej prihajale.',
      'u_again' => 'Ste se odjavili pomotoma?', 'u_re' => 'Ponovno me prijavi', 'u_back' => 'Ponovno ste prijavljeni. Hvala, se vidimo v Dvoru!',
      'u_bad' => 'Ta povezava ni veljavna. Odgovorite na eno od naših sporočil z «odjava».', 'u_test' => 'To je testno sporočilo: tukaj se gost odjavi z enim dotikom.',
      'site' => 'Obiščite spletno stran Osteria Dvor',
      's_title' => 'Novice iz Dvora', 's_text' => 'Tematski večeri, sezonski meniji, dogodki in posebne ponudbe. Malo sporočil, samo ko je kaj dobrega.',
      's_name' => 'Ime', 's_email' => 'E-pošta', 's_ok' => 'Prijava', 's_priv' => 'Odjavite se lahko kadar koli s povezavo na dnu vsakega sporočila.',
      's_sent' => 'Skoraj končano: poslali smo sporočilo na {e}. Za potrditev se dotaknite gumba v njem.',
      's_conf' => 'Prijava potrjena. Dobrodošli v Dvoru!', 's_err' => 'Preverite e-poštni naslov.',
      'c_subj' => 'Potrdite prijavo · Osteria Dvor', 'c_h' => 'Potrdite prijavo', 'c_body' => 'Dotaknite se gumba za prejemanje novic Osteria Dvor. Če to niste bili vi, sporočilo prezrite.', 'c_btn' => 'Da, prijavi me'],
    'nl' => ['why' => 'Je ontvangt deze e-mail omdat je gast bent van Osteria Dvor en ons nieuws wilde ontvangen.', 'unsub' => 'Afmelden', 'pre_unsub' => 'Wil je deze e-mails niet meer?',
      'book' => 'Reserveer je tafel', 'event' => 'Meer info en reserveren',
      'u_title' => 'Afmelden', 'u_ask' => 'Geen nieuws van Osteria Dvor meer ontvangen op {e}?', 'u_btn' => 'Ja, meld me af',
      'u_done' => 'Klaar: je ontvangt geen nieuws en aanbiedingen meer op {e}. Bevestigingen van je reserveringen blijven komen.',
      'u_again' => 'Per ongeluk afgemeld?', 'u_re' => 'Meld me weer aan', 'u_back' => 'Je bent weer aangemeld. Bedankt, tot snel bij Dvor!',
      'u_bad' => 'Deze link is niet geldig. Beantwoord een van onze e-mails met «afmelden».', 'u_test' => 'Dit is een test-e-mail: hier meldt de gast zich met één tik af.',
      'site' => 'Naar de website van Osteria Dvor',
      's_title' => 'Nieuws van Dvor', 's_text' => 'Themaavonden, seizoensmenu\'s, evenementen en aanbiedingen. Weinig e-mails, alleen als er iets lekkers is.',
      's_name' => 'Naam', 's_email' => 'E-mail', 's_ok' => 'Aanmelden', 's_priv' => 'Afmelden kan altijd via de link onderaan elke e-mail.',
      's_sent' => 'Bijna klaar: we hebben een e-mail gestuurd naar {e}. Tik op de knop erin om te bevestigen.',
      's_conf' => 'Aanmelding bevestigd. Welkom bij Dvor!', 's_err' => 'Controleer het e-mailadres.',
      'c_subj' => 'Bevestig je aanmelding · Osteria Dvor', 'c_h' => 'Bevestig je aanmelding', 'c_body' => 'Tik op de knop om nieuws van Osteria Dvor te ontvangen. Was jij dit niet, negeer dan deze e-mail.', 'c_btn' => 'Ja, meld me aan'],
  ];
  return $T[$lang] ?? $T['en'] ?? $T['it'];
}
function campLang(array $L): string { $l = (string)($L['lang'] ?? ''); return preg_match('/^(it|en|de|sl|nl)$/', $l) ? $l : 'it'; }

/* ---------- validation of what the page sends ---------- */
function campUrl(string $u, bool $img = false): string {
  $u = trim($u);
  if ($img && preg_match('#^uploads/[a-f0-9]{24,40}\.(jpg|png|webp|gif)$#', $u)) return $u;
  if (preg_match('#^https?://[^\s<>"]{3,1000}$#i', $u)) return $u;
  if (!$img && preg_match('#^(mailto:[^\s<>"]{3,200}|tel:\+?[\d\s]{5,20})$#i', $u)) return $u;
  return '';
}
function campCleanBlocks($blocks): array {
  $out = []; $s = fn($v, $n) => mb_substr(trim((string)$v), 0, $n);
  foreach (array_slice(is_array($blocks) ? $blocks : [], 0, 60) as $b) {
    if (!is_array($b) || !in_array($b['t'] ?? '', CAMP_BLOCKS, true)) continue;
    $x = ['t' => $b['t']];
    switch ($b['t']) {
      case 'heading': $x['text'] = $s($b['text'] ?? '', 300); $x['size'] = ($b['size'] ?? '') === 'small' ? 'small' : 'big'; break;
      case 'text': $x['text'] = $s($b['text'] ?? '', 8000); break;
      case 'image': $x['src'] = campUrl((string)($b['src'] ?? ''), true); $x['alt'] = $s($b['alt'] ?? '', 200); $x['href'] = campUrl((string)($b['href'] ?? '')); break;
      case 'button': $x['label'] = $s($b['label'] ?? '', 80); $x['href'] = campUrl((string)($b['href'] ?? '')); break;
      case 'book': $x['label'] = $s($b['label'] ?? '', 80); break;
      case 'event': $x['id'] = preg_match('/^[A-Za-z0-9_-]{1,64}$/', (string)($b['id'] ?? '')) ? (string)$b['id'] : ''; break;
    }
    if (isset($b['align']) && in_array($b['align'], ['left', 'center'], true)) $x['align'] = $b['align'];
    $out[] = $x;
  }
  return $out;
}
function campCleanAudience($a): array {
  $a = is_array($a) ? $a : [];
  $r = ['seg' => in_array($a['seg'] ?? 'all', CAMP_SEGS, true) ? (string)($a['seg'] ?? 'all') : 'all',
    'tag' => mb_substr(trim((string)($a['tag'] ?? '')), 0, 40),
    'lang' => in_array($a['lang'] ?? '', CAMP_LANGS, true) ? (string)($a['lang'] ?? '') : '',
    'origin' => in_array($a['origin'] ?? 'all', CAMP_ORIGINS, true) ? (string)($a['origin'] ?? 'all') : 'all'];
  if (is_array($a['ids'] ?? null)) { // the guests chosen on the Contatti page
    $r['ids'] = array_values(array_unique(array_filter(array_slice($a['ids'], 0, 20000), fn($x) => is_string($x) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $x))));
    $r['idsLabel'] = mb_substr(trim((string)($a['idsLabel'] ?? '')), 0, 120);
  }
  return $r;
}
function campClean(array $x): array {
  $s = fn($k, $n) => mb_substr(trim((string)($x[$k] ?? '')), 0, $n);
  return ['name' => $s('name', 120), 'subject' => $s('subject', 200), 'preheader' => $s('preheader', 200), 'fromName' => $s('fromName', 80),
    'blocks' => campCleanBlocks($x['blocks'] ?? []), 'audience' => campCleanAudience($x['audience'] ?? [])];
}

/* ---------- the email ---------- */
function campMerge(string $s, array $r): string {
  return preg_replace_callback('/\{\{\s*(nome|nome_completo|email)\s*(?:\|([^}]*))?\}\}/u', function ($m) use ($r) {
    $full = trim((string)($r['name'] ?? ''));
    $v = $m[1] === 'email' ? (string)($r['email'] ?? '') : ($m[1] === 'nome' ? (trim((string)($r['firstName'] ?? '')) ?: (preg_split('/\s+/', $full)[0] ?? '')) : $full);
    if ($v !== '' && $m[1] !== 'email' && ($v === mb_strtoupper($v) || $v === mb_strtolower($v))) $v = mb_convert_case(mb_strtolower($v), MB_CASE_TITLE); // «MARIO ROSSI» from an old list reads as «Mario Rossi»
    return $v !== '' ? $v : trim($m[2] ?? '');
  }, $s) ?? $s;
}
/* text with **bold**, *italic*, [label](https://…) and plain links; blank line = new paragraph */
function campInline(string $s, callable $href, string $color): string {
  $links = [];
  $s = preg_replace_callback('/\[([^\]\n]{1,200})\]\(((?:https?:\/\/|mailto:|tel:)[^\s)]+)\)/u', function ($m) use (&$links) { $links[] = [$m[1], $m[2]]; return "\x01" . (count($links) - 1) . "\x02"; }, $s) ?? $s;
  $s = preg_replace_callback('/https?:\/\/[^\s<>"\x01\x02]*[^\s<>"\x01\x02.,;:!?)\]]/u', function ($m) use (&$links) { $links[] = [preg_replace('#^https?://(www\.)?#', '', $m[0]), $m[0]]; return "\x01" . (count($links) - 1) . "\x02"; }, $s) ?? $s;
  $s = htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
  $s = preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/u', '<strong>$1</strong>', $s) ?? $s;
  $s = preg_replace('/(?<![*\w])\*(?=\S)([^*\n]+?)(?<=\S)\*(?![*\w])/u', '<em>$1</em>', $s) ?? $s;
  $s = preg_replace_callback('/\x01(\d+)\x02/', function ($m) use ($links, $href, $color) {
    [$label, $url] = $links[(int)$m[1]];
    return '<a href="' . htmlspecialchars($href($url), ENT_QUOTES, 'UTF-8') . '" style="color:' . $color . ';text-decoration:underline">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a>';
  }, $s) ?? $s;
  return nl2br($s, false);
}
function campPlain(string $s): string {
  $s = preg_replace('/\[([^\]\n]{1,200})\]\(((?:https?:\/\/|mailto:|tel:)[^\s)]+)\)/u', '$1 ($2)', $s) ?? $s;
  return preg_replace('/\*\*(.+?)\*\*|\*([^*\n]+)\*/u', '$1$2', $s) ?? $s;
}
function campAbs(array $C, string $src): string { return str_starts_with($src, 'uploads/') ? campBase($C) . '/' . $src : $src; }
/* utm on the links to our own sites, so a booking made from the email is tied to the campaign */
function campUtm(string $url, string $code): string {
  if ($code === '' || !preg_match('#^https?://([^/?\#]+)#i', $url, $m)) return $url;
  $host = strtolower($m[1]);
  if (!preg_match('/(^|\.)(osteriadvor\.com|profumidistria\.com)$/', $host) || preg_match('/[?&]utm_source=/', $url)) return $url;
  [$base, $frag] = array_pad(explode('#', $url, 2), 2, null);
  return $base . (str_contains($base, '?') ? '&' : '?') . 'utm_source=newsletter&utm_medium=email&utm_campaign=' . rawurlencode($code) . ($frag !== null ? '#' . $frag : '');
}
/* the link without our utm, for the click report */
function campNoUtm(string $url): string {
  $u = preg_replace('/(?<=[?&])utm_(source|medium|campaign)=[^&#]*(&|(?=#)|$)/', '', $url) ?? $url;
  return preg_replace('/[?&]+(?=#|$)/', '', $u) ?? $u;
}
function campEventDoc(string $id): ?array { return $id !== '' ? getDoc('events', $id) : null; }
function campEventDay(array $ev, string $lang): string {
  if (empty($ev['date'])) return '';
  $loc = ['it' => 'it_IT', 'en' => 'en_GB', 'de' => 'de_DE', 'sl' => 'sl_SI', 'nl' => 'nl_NL'][$lang] ?? 'it_IT';
  $ts = strtotime((string)$ev['date']);
  if (class_exists('IntlDateFormatter')) { $f = new IntlDateFormatter($loc, IntlDateFormatter::FULL, IntlDateFormatter::NONE, null, null, 'EEEE d MMMM'); $d = (string)$f->format($ts); }
  else $d = date('d/m/Y', $ts);
  return $d . (!empty($ev['time']) && empty($ev['allDay']) ? ' · ' . $ev['time'] : '');
}

/* the whole email for one recipient. $r: name, firstName, email, lang. $tok: the row in mail_sends, CAMP_TEST for tests and previews */
function campRender(array $camp, array $r, array $C, string $tok, bool $preview = false): array {
  $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
  $lang = preg_match('/^(it|en|de|sl|nl)$/', (string)($r['lang'] ?? '')) ? $r['lang'] : 'it';
  $T = campT($lang); $base = campBase($C); $code = (string)($camp['code'] ?? '');
  $site = rtrim($C['site_url'] ?? 'https://www.osteriadvor.com', '/');
  $track = $tok !== CAMP_TEST && $base !== '';
  $href = function (string $url) use ($track, $tok, $base, $code): string {
    $url = campUtm($url, $code);
    if (!$track || !preg_match('#^https?://#i', $url)) return $url;
    return $base . '/m.php?c=' . $tok . '&s=' . campSig($tok, $url) . '&u=' . rawurlencode($url);
  };
  $unsub = $base . '/m.php?u=' . ($track ? $tok : CAMP_TEST);
  $wine = '#cf4731'; $ink = '#1b1918'; $muted = '#7c736c'; $cream = '#f6f2ec'; $paper = '#ffffff'; $line = '#e8e1d8'; // the colours of the Mescita app and the DVOR logo
  $btn = fn($url, $label, $align) => '<table role="presentation" cellpadding="0" cellspacing="0" ' . ($align === 'center' ? 'align="center" ' : '') . 'style="margin:4px 0"><tr><td style="border-radius:12px;background:' . $wine . '">'
    . '<a href="' . $h($href($url)) . '" style="display:inline-block;padding:13px 22px;border:1px solid ' . $wine . ';border-radius:12px;color:#ffffff;text-decoration:none;font-family:Helvetica,Arial,sans-serif;font-size:15px;font-weight:bold">' . $h($label) . '</a></td></tr></table>';
  $m = fn($s) => campMerge((string)$s, $r);
  $rows = ''; $txt = '';
  foreach ((array)($camp['blocks'] ?? []) as $b) {
    $al = ($b['align'] ?? '') === 'center' ? 'center' : 'left';
    switch ($b['t'] ?? '') {
      case 'heading':
        $big = ($b['size'] ?? 'big') !== 'small';
        $rows .= '<tr><td style="padding:14px 28px 4px;text-align:' . $al . '"><h' . ($big ? 1 : 2) . ' style="margin:0;font-weight:normal;font-size:' . ($big ? 27 : 21) . 'px;line-height:1.2;font-weight:600;color:' . $ink . '">' . $h($m($b['text'] ?? '')) . '</h' . ($big ? 1 : 2) . '></td></tr>';
        $txt .= mb_strtoupper($m($b['text'] ?? '')) . "\n\n"; break;
      case 'text':
        $paras = preg_split('/\n\s*\n/', trim($m($b['text'] ?? '')));
        $rows .= '<tr><td style="padding:8px 28px;text-align:' . $al . '">' . implode('', array_map(fn($p) => '<p style="margin:0 0 12px;font-size:16px;line-height:1.6">' . campInline($p, $href, $wine) . '</p>', array_filter($paras, fn($p) => trim($p) !== ''))) . '</td></tr>';
        $txt .= campPlain(trim($m($b['text'] ?? ''))) . "\n\n"; break;
      case 'image':
        if (empty($b['src'])) break;
        $img = '<img src="' . $h(campAbs($C, $b['src'])) . '" width="524" alt="' . $h($b['alt'] ?? '') . '" style="display:block;width:100%;max-width:524px;height:auto;border:0;border-radius:6px' . ($al === 'center' ? ';margin:0 auto' : '') . '">';
        $rows .= '<tr><td style="padding:10px 28px">' . (!empty($b['href']) ? '<a href="' . $h($href($b['href'])) . '">' . $img . '</a>' : $img) . '</td></tr>';
        if (!empty($b['href'])) $txt .= ($b['alt'] ?? '') . ' ' . campUtm($b['href'], $code) . "\n\n"; break;
      case 'button':
        if (empty($b['href']) || trim((string)($b['label'] ?? '')) === '') break;
        $rows .= '<tr><td style="padding:10px 28px">' . $btn($b['href'], $m($b['label']), $al) . '</td></tr>';
        $txt .= $m($b['label']) . ': ' . campUtm($b['href'], $code) . "\n\n"; break;
      case 'book':
        $u = (string)($C['booking_url'] ?? $site); $label = trim((string)($b['label'] ?? '')) ?: $T['book'];
        $rows .= '<tr><td style="padding:10px 28px">' . $btn($u, $m($label), $al) . '</td></tr>';
        $txt .= $m($label) . ': ' . campUtm($u, $code) . "\n\n"; break;
      case 'event':
        $ev = campEventDoc((string)($b['id'] ?? '')); if (!$ev) break;
        $pick = fn($k) => trim((string)($ev[$k . '_' . $lang] ?? '')) ?: (trim((string)($ev[$k . '_' . ($lang === 'it' ? 'it' : 'en')] ?? '')) ?: trim((string)($ev[$k . '_it'] ?? '')));
        $title = $pick('title'); $desc = $pick('desc'); $day = campEventDay($ev, $lang);
        $short = mb_strlen($desc) > 420 ? rtrim(mb_substr($desc, 0, 400)) . '…' : $desc;
        $link = (string)($ev['link'] ?? '') ?: (string)($C['booking_url'] ?? $site);
        $img = !empty($ev['imageId']) ? '<img src="' . $h($base . '/uploads/' . $ev['imageId']) . '" width="522" alt="' . $h($title) . '" style="display:block;width:100%;max-width:522px;height:auto;border:0;border-radius:12px 12px 0 0">' : '';
        $rows .= '<tr><td style="padding:10px 28px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:' . $cream . ';border:1px solid ' . $line . ';border-radius:12px">'
          . ($img ? '<tr><td>' . $img . '</td></tr>' : '')
          . '<tr><td style="padding:16px 18px 18px">'
          . ($day ? '<div style="font-family:Helvetica,Arial,sans-serif;font-size:12px;letter-spacing:.14em;text-transform:uppercase;color:' . $muted . '">' . $h($day) . '</div>' : '')
          . '<div style="margin:6px 0 8px;font-size:22px;line-height:1.3;font-weight:600;color:' . $ink . '">' . $h($title) . '</div>'
          . ($short !== '' ? '<p style="margin:0 0 10px;font-size:15px;line-height:1.55">' . nl2br($h($short), false) . '</p>' : '')
          . (!empty($ev['price']) ? '<p style="margin:0 0 12px;font-family:Helvetica,Arial,sans-serif;font-size:14px;color:' . $muted . '">' . $h($ev['price']) . '</p>' : '')
          . $btn($link, $T['event'], 'left') . '</td></tr></table></td></tr>';
        $txt .= mb_strtoupper($title) . ($day ? "\n$day" : '') . "\n$short\n" . (!empty($ev['price']) ? $ev['price'] . "\n" : '') . $T['event'] . ': ' . campUtm($link, $code) . "\n\n"; break;
      case 'divider':
        $rows .= '<tr><td style="padding:12px 28px"><div style="border-top:1px solid ' . $line . ';font-size:0;line-height:0">&nbsp;</div></td></tr>'; $txt .= "———\n\n"; break;
      case 'spacer':
        $rows .= '<tr><td style="height:22px;font-size:0;line-height:0">&nbsp;</td></tr>'; break;
    }
  }
  $subject = $m($camp['subject'] ?? '') ?: 'Osteria Dvor';
  $pre = $m($camp['preheader'] ?? '');
  $logo = $preview ? '<img src="' . $h(($C['_logo_url'] ?? 'email-logo.png')) . '" width="200" alt="DVOR" style="display:block;margin:0 auto;width:200px;max-width:60%;height:auto;border:0">'
    : (!empty($C['_logo_cid']) ? '<img src="cid:' . $C['_logo_cid'] . '" width="200" alt="DVOR" style="display:block;margin:0 auto;width:200px;max-width:60%;height:auto;border:0">' : '<div style="font-size:30px;letter-spacing:.2em;text-align:center">DVOR</div>');
  $pixel = $track ? '<img src="' . $h($base . '/m.php?o=' . $tok) . '" width="1" height="1" alt="" style="display:block;width:1px;height:1px;border:0">' : '';
  $html = '<!doctype html><html lang="' . $lang . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light only"><title>' . $h($subject) . '</title></head>'
    . '<body style="margin:0;padding:0;background:' . $cream . '">'
    . ($pre !== '' ? '<div style="display:none;max-height:0;overflow:hidden;opacity:0">' . $h($pre) . str_repeat('&#8199;&#847; ', 40) . '</div>' : '')
    . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:' . $cream . '"><tr><td align="center" style="padding:28px 12px">'
    . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:580px;background:' . $paper . ';border:1px solid ' . $line . ';border-top:4px solid ' . $wine . ';border-radius:16px;font-family:Georgia,\'Times New Roman\',serif;color:' . $ink . '">'
    . '<tr><td style="padding:28px 28px 10px">' . $logo
    . '<div style="text-align:center;font-family:Helvetica,Arial,sans-serif;font-size:11px;letter-spacing:.22em;text-transform:uppercase;color:' . $muted . ';margin-top:10px">Osteria Enoteca · San Floriano del Collio</div></td></tr>'
    . $rows
    . '<tr><td style="height:18px;font-size:0;line-height:0">&nbsp;</td></tr></table>'
    . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:580px"><tr><td style="padding:16px 12px;text-align:center;font-family:Helvetica,Arial,sans-serif;font-size:12px;line-height:1.7;color:' . $muted . '">'
    . 'Osteria Enoteca Dvor · Via Castello 5, 34070 San Floriano del Collio (GO)<br>'
    . '<a href="tel:+390481090740" style="color:' . $muted . '">+39 0481 090740</a> · <a href="' . $h($href($site)) . '" style="color:' . $wine . '">' . $h(preg_replace('#^https?://#', '', $site)) . '</a><br><br>'
    . $h($T['why']) . '<br>' . $h($T['pre_unsub']) . ' <a href="' . $h($unsub) . '" style="color:' . $muted . ';text-decoration:underline">' . $h($T['unsub']) . '</a>'
    . '</td></tr></table>' . $pixel
    . '</td></tr></table></body></html>';
  $txt = trim($txt) . "\n\n—\nOsteria Enoteca Dvor · Via Castello 5, 34070 San Floriano del Collio (GO) · +39 0481 090740\n$site\n\n" . $T['why'] . "\n" . $T['pre_unsub'] . ' ' . $T['unsub'] . ": $unsub\n";
  $headers = ['List-Unsubscribe: <' . $unsub . '>' . (!empty($C['reply_to']) ? ', <mailto:' . $C['reply_to'] . '?subject=' . rawurlencode('Disiscrizione') . '>' : '')];
  if ($track) $headers[] = 'List-Unsubscribe-Post: List-Unsubscribe=One-Click';
  return ['subject' => $subject, 'text' => $txt, 'html' => $html, 'logo' => !empty($C['_logo_cid']) && !$preview, 'headers' => $headers,
    'fromName' => trim((string)($camp['fromName'] ?? '')) ?: (string)($C['mail_from_name'] ?? 'Osteria Dvor')];
}
/* name and language of a lead for the email; the test uses the staff's own address */
function campRecipient(array $L, string $email = ''): array {
  return ['name' => (string)($L['name'] ?? ''), 'firstName' => (string)($L['firstName'] ?? ''), 'email' => $email ?: (string)($L['email'] ?? ''), 'lang' => campLang($L)];
}

/* ---------- audience ---------- */
/* per lead: emails received, opened, clicked, last open (seconds) */
function campLeadMail(PDO $pdo): array {
  $m = [];
  foreach ($pdo->query("SELECT lead_id, COUNT(*), SUM(opened_at > 0), SUM(clicked_at > 0), MAX(opened_at), MAX(sent_at) FROM mail_sends WHERE status IN ('sent', 'bounced') AND lead_id <> '' GROUP BY lead_id")->fetchAll(PDO::FETCH_NUM) as $r)
    $m[(string)$r[0]] = ['r' => (int)$r[1], 'o' => (int)$r[2], 'c' => (int)$r[3], 'lo' => (int)$r[4], 'ls' => (int)$r[5]];
  return $m;
}
/* why a lead cannot get a campaign: '' = it can */
function campBlocked(array $L): string {
  $e = leadEmailKey((string)($L['email'] ?? ''));
  if ($e === '' || !filter_var($e, FILTER_VALIDATE_EMAIL)) return 'noemail';
  if (($L['consent'] ?? '') !== 'yes') return ($L['consent'] ?? '') === 'no' ? 'unsub' : 'noconsent';
  if (!empty($L['emailBad']) && strtolower((string)$L['emailBad']) === $e) return 'bounced';
  return '';
}
function campBday(string $b, int $days): bool {
  if (!preg_match('/^\d{4}-(\d{2})-(\d{2})$/', $b, $m)) return false;
  $y = (int)date('Y'); $t = mktime(0, 0, 0, (int)$m[1], (int)$m[2], $y); $now = strtotime('today');
  if ($t < $now) $t = mktime(0, 0, 0, (int)$m[1], (int)$m[2], $y + 1);
  return ($t - $now) / 86400 <= $days;
}
/* the same groups as the Contatti page (MK_SEG in index.html) */
function campSegMatch(array $L, string $seg, array $mail): bool {
  $s = (array)($L['sum'] ?? []); $vis = (array)($L['visits'] ?? []); $mm = $mail[$L['id'] ?? ''] ?? ['r' => 0, 'o' => 0, 'lo' => 0];
  $ago = fn($n) => date('Y-m-d', strtotime("-$n days"));
  switch ($seg) {
    case 'regular': return (int)($s['visits'] ?? 0) >= 2;
    case 'once': return (int)($s['visits'] ?? 0) === 1 && empty($s['next']);
    case 'lapsed': return !empty($s['last']) && $s['last'] < $ago(182) && empty($s['next']);
    case 'upcoming': return !empty($s['next']);
    case 'recent': return substr((string)($L['createdAt'] ?? ''), 0, 10) >= $ago(30);
    case 'bday': return campBday((string)($L['birthday'] ?? ''), 30);
    case 'tasting': foreach ($vis as $v) if ((int)($v['deg'] ?? 0) > 0) return true; return false;
    case 'events': foreach ($vis as $v) if (!empty($v['ev'])) return true; return false;
    case 'noshow': return (int)($s['noShows'] ?? 0) > 0;
    case 'engaged': return $mm['lo'] > time() - 90 * 86400;
    case 'unengaged': return $mm['r'] >= 3 && $mm['o'] === 0;
    case 'never': return $mm['r'] === 0;
  }
  return true;
}
function campOrigins(array $L): array {
  $o = [];
  if (($L['source'] ?? '') === 'import') $o['import'] = 1;
  if (($L['source'] ?? '') === 'signup') $o['signup'] = 1;
  foreach ((array)($L['visits'] ?? []) as $v) if (!empty($v['src'])) $o[$v['src']] = 1;
  return array_keys($o);
}
/* who gets the campaign: list of [lead id, email, recipient], plus how many were left out and why */
function campAudience(array $aud): array {
  $pdo = db(); $mail = campLeadMail($pdo);
  $ids = isset($aud['ids']) ? array_flip((array)$aud['ids']) : null;
  $out = []; $seen = []; $ex = ['noemail' => 0, 'noconsent' => 0, 'unsub' => 0, 'bounced' => 0, 'dup' => 0]; $matched = 0;
  foreach ($pdo->query('SELECT id, data FROM leads ORDER BY n')->fetchAll(PDO::FETCH_NUM) as [$id, $data]) {
    $L = json_decode((string)$data, true); if (!is_array($L)) continue; $L['id'] = (string)$id;
    if ($ids !== null) { if (!isset($ids[$L['id']])) continue; }
    else {
      if (!campSegMatch($L, $aud['seg'] ?? 'all', $mail)) continue;
      if (($aud['tag'] ?? '') !== '' && !in_array($aud['tag'], (array)($L['tags'] ?? []), true)) continue;
      $lg = campLang($L); $want = $aud['lang'] ?? '';
      if ($want === 'xit' ? $lg === 'it' : ($want !== '' && $lg !== $want)) continue;
      $o = $aud['origin'] ?? 'all';
      if ($o !== 'all') {
        $want = $o === 'site' ? ['form', 'assistant'] : ($o === 'staff' ? ['phone', 'whatsapp', 'walkin'] : [$o]);
        if (!array_intersect($want, campOrigins($L))) continue;
      }
    }
    $matched++;
    if ($why = campBlocked($L)) { $ex[$why]++; continue; }
    $e = leadEmailKey((string)$L['email']);
    if (isset($seen[$e])) { $ex['dup']++; continue; }
    $seen[$e] = 1; $out[] = [$L['id'], $e, campRecipient($L, $e)];
  }
  return ['list' => $out, 'matched' => $matched, 'excluded' => $ex];
}

/* ---------- sending ---------- */
function campCode(string $name, string $id): string {
  $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: $name) ?? '', '-'));
  return mb_substr($slug !== '' ? $slug : 'newsletter', 0, 40) . '-' . substr($id, 0, 4);
}
/* scheduled or «send now»: the recipients are fixed here, one queued row each */
function campStart(string $id): int {
  return tx(function (PDO $pdo) use ($id) {
    $c = getDoc('campaigns', $id, true);
    if (!$c || !in_array($c['status'] ?? 'draft', ['draft', 'scheduled'], true)) return -1;
    $a = campAudience((array)($c['audience'] ?? []));
    $ins = $pdo->prepare('INSERT IGNORE INTO mail_sends (id, campaign, lead_id, email) VALUES (?, ?, ?, ?)');
    foreach ($a['list'] as [$lid, $email]) $ins->execute([campNewToken(), $id, $lid, $email]);
    $c['status'] = 'sending'; $c['startedAt'] = date('c'); $c['total'] = count($a['list']); $c['excluded'] = $a['excluded'];
    $c['code'] = $c['code'] ?? campCode((string)($c['name'] ?? ''), $id);
    putDoc('campaigns', $id, $c);
    return count($a['list']);
  });
}
/* sends what is queued, without going over campaign_per_minute (config.php, default 40).
   From cron ($wait = true) it waits for the next minute; from the page it stops and the page calls again. */
function campRun(array $C, int $budget = 20, bool $wait = false): array {
  $pdo = db(); $res = ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'started' => 0];
  if ((int)$pdo->query("SELECT GET_LOCK('dvor_camp', 0)")->fetchColumn() !== 1) return $res + ['busy' => true];
  try {
    @set_time_limit($budget + 40); ignore_user_abort(true);
    foreach (load('campaigns') as $id => $c) {
      if (($c['status'] ?? '') === 'scheduled' && ($t = strtotime((string)($c['scheduledAt'] ?? ''))) && $t <= time()) { campStart((string)$id); $res['started']++; }
    }
    $sending = fn() => array_keys(array_filter(load('campaigns'), fn($c) => ($c['status'] ?? '') === 'sending'));
    $camps = $sending(); $all = $camps;
    if (!$camps) return $res;
    $rate = max(1, (int)($C['campaign_per_minute'] ?? 40)); $t0 = time(); $docs = []; $leadQ = $pdo->prepare('SELECT data FROM leads WHERE id = ?');
    while (time() - $t0 < $budget) {
      $camps = $sending(); if (!$camps) break; // paused from the page meanwhile
      $in = implode(',', array_fill(0, count($camps), '?'));
      $q = $pdo->prepare('SELECT COUNT(*) FROM mail_sends WHERE sent_at > ?'); $q->execute([time() - 60]);
      $room = $rate - (int)$q->fetchColumn();
      if ($room <= 0) { if ($wait && time() - $t0 + 5 < $budget) { sleep(5); continue; } $res['wait'] = true; break; }
      $q = $pdo->prepare("SELECT id, campaign, lead_id, email FROM mail_sends WHERE status = 'queued' AND campaign IN ($in) ORDER BY n LIMIT " . min($room, 25));
      $q->execute($camps); $rows = $q->fetchAll(PDO::FETCH_NUM);
      if (!$rows) break;
      foreach ($rows as [$tok, $cid, $lid, $email]) {
        if (time() - $t0 >= $budget) break 2;
        $c = $docs[$cid] ??= (getDoc('campaigns', $cid) ?? []);
        $leadQ->execute([$lid]); $L = json_decode((string)$leadQ->fetchColumn(), true);
        $why = !is_array($L) ? 'contatto eliminato' : (($b = campBlocked($L)) !== '' ? $b : (leadEmailKey((string)$L['email']) !== $email ? 'email cambiata' : ''));
        if ($why !== '') { // unsubscribed or changed after the campaign started
          $pdo->prepare("UPDATE mail_sends SET status = 'skipped', error = ? WHERE id = ?")->execute([$why, $tok]); $res['skipped']++; continue;
        }
        try {
          sendMail($C, $email, campRender($c, campRecipient($L, $email), $C, (string)$tok));
          $pdo->prepare("UPDATE mail_sends SET status = 'sent', sent_at = ?, error = '' WHERE id = ?")->execute([time(), $tok]); $res['sent']++;
        } catch (Throwable $e) {
          $msg = $e->getMessage(); logMail("camp ERR $cid $email $msg");
          /* the mail server is down or says «try later»: the email stays in the queue and the run stops; after 5 tries it counts as failed */
          if (preg_match('/^(SMTP connessione|mail\(\) fallita|SMTP: 4\d\d)/', $msg)) {
            $pdo->prepare("UPDATE mail_sends SET tries = tries + 1, error = ?, status = IF(tries >= 5, 'failed', status) WHERE id = ?")->execute([mb_substr($msg, 0, 300), $tok]);
            $res['failed']++; $res['error'] = $msg; break 2;
          }
          $pdo->prepare("UPDATE mail_sends SET status = 'failed', sent_at = ?, error = ? WHERE id = ?")->execute([time(), mb_substr($msg, 0, 300), $tok]); $res['failed']++;
        }
      }
    }
    foreach ($all as $cid) { // nothing left in the queue: sent
      $q = $pdo->prepare("SELECT COUNT(*) FROM mail_sends WHERE campaign = ? AND status = 'queued'"); $q->execute([$cid]);
      if ((int)$q->fetchColumn() === 0) tx(function () use ($cid) { $c = getDoc('campaigns', $cid, true); if ($c && ($c['status'] ?? '') === 'sending') { $c['status'] = 'sent'; $c['sentAt'] = date('c'); putDoc('campaigns', $cid, $c); } });
    }
    if ($res['sent'] || $res['failed']) logMail("camp ok sent {$res['sent']} failed {$res['failed']} skipped {$res['skipped']}");
  } finally { $pdo->query("SELECT RELEASE_LOCK('dvor_camp')"); }
  return $res;
}

/* ---------- numbers ---------- */
function campStats(?string $id = null): array {
  $sql = "SELECT campaign, COUNT(*), SUM(status = 'queued'), SUM(status = 'sent'), SUM(status = 'failed'), SUM(status = 'bounced'), SUM(status = 'skipped'),
    SUM(opened_at > 0), SUM(clicked_at > 0), SUM(unsub_at > 0), SUM(opens), SUM(clicks) FROM mail_sends" . ($id !== null ? ' WHERE campaign = ?' : '') . ' GROUP BY campaign';
  $q = db()->prepare($sql); $q->execute($id !== null ? [$id] : []); $out = [];
  foreach ($q->fetchAll(PDO::FETCH_NUM) as $r) {
    $k = ['total', 'queued', 'delivered', 'failed', 'bounced', 'skipped', 'opened', 'clicked', 'unsub', 'opens', 'clicks']; $x = [];
    foreach ($k as $i => $name) $x[$name] = (int)$r[$i + 1];
    $out[(string)$r[0]] = $x;
  }
  return $out;
}
/* the report of one campaign: links, opens by hour, recipients, bookings that came after */
function campReport(string $id, array $c): array {
  $pdo = db();
  $q = $pdo->prepare("SELECT url, COUNT(*), COUNT(DISTINCT send_id) FROM mail_events WHERE campaign = ? AND type = 'click' GROUP BY url ORDER BY 3 DESC, 2 DESC LIMIT 50"); $q->execute([$id]);
  $links = array_map(fn($r) => ['url' => (string)$r[0], 'clicks' => (int)$r[1], 'people' => (int)$r[2]], $q->fetchAll(PDO::FETCH_NUM));
  $names = [];
  foreach ($pdo->query('SELECT id, data FROM leads')->fetchAll(PDO::FETCH_NUM) as [$lid, $d]) { $L = json_decode((string)$d, true); $names[(string)$lid] = (string)($L['name'] ?? ''); }
  $q = $pdo->prepare('SELECT lead_id, email, status, error, sent_at, opened_at, opens, clicked_at, clicks, unsub_at, bounced_at FROM mail_sends WHERE campaign = ? ORDER BY n LIMIT 20000'); $q->execute([$id]);
  $rec = []; $emails = [];
  foreach ($q->fetchAll(PDO::FETCH_NUM) as $r) {
    $rec[] = ['lead' => (string)$r[0], 'name' => $names[(string)$r[0]] ?? '', 'email' => (string)$r[1], 'status' => (string)$r[2], 'error' => (string)$r[3], 'sent' => (int)$r[4], 'opened' => (int)$r[5], 'opens' => (int)$r[6], 'clicked' => (int)$r[7], 'clicks' => (int)$r[8], 'unsub' => (int)$r[9], 'bounced' => (int)$r[10]];
    if ($r[2] === 'sent') $emails[strtolower((string)$r[1])] = 1;
  }
  /* bookings: with this campaign's link (utm_campaign), and by recipients within 14 days of the sending */
  $book = ['link' => 0, 'linkPeople' => 0, 'recipients' => 0, 'recipientsPeople' => 0, 'list' => []];
  $from = strtotime((string)($c['startedAt'] ?? '')) ?: 0;
  if ($from) {
    $until = $from + 14 * 86400; $code = (string)($c['code'] ?? '');
    $q = $pdo->prepare("SELECT id, data FROM bookings WHERE updated_at >= ? AND status <> 'cancelled'"); $q->execute([gmdate('Y-m-d H:i:s', $from)]);
    foreach ($q->fetchAll(PDO::FETCH_NUM) as [$bid, $d]) {
      $b = json_decode((string)$d, true); if (!is_array($b)) continue;
      $made = strtotime((string)($b['createdAt'] ?? '')) ?: 0; if ($made < $from) continue;
      $byLink = $code !== '' && (string)($b['utm']['campaign'] ?? '') === $code;
      $byGuest = $made <= $until && isset($emails[strtolower(trim((string)($b['email'] ?? '')))]);
      if (!$byLink && !$byGuest) continue;
      if ($byLink) { $book['link']++; $book['linkPeople'] += (int)($b['party'] ?? 0); }
      if ($byGuest) { $book['recipients']++; $book['recipientsPeople'] += (int)($b['party'] ?? 0); }
      if (count($book['list']) < 200) $book['list'][] = ['name' => (string)($b['name'] ?? ''), 'date' => (string)($b['date'] ?? ''), 'time' => (string)($b['time'] ?? ''), 'party' => (int)($b['party'] ?? 0), 'createdAt' => (string)($b['createdAt'] ?? ''), 'link' => $byLink];
    }
  }
  return ['links' => $links, 'recipients' => $rec, 'bookings' => $book];
}

/* ---------- what the guest does with the email (m.php) ---------- */
function campRow(string $tok): ?array {
  if (!preg_match('/^[a-f0-9]{24}$/', $tok)) return null;
  $q = db()->prepare('SELECT id, campaign, lead_id, email, opened_at, unsub_at FROM mail_sends WHERE id = ?'); $q->execute([$tok]);
  $r = $q->fetch(PDO::FETCH_ASSOC); return $r ?: null;
}
function campOpen(string $tok): void {
  if (!preg_match('/^[a-f0-9]{24}$/', $tok)) return;
  db()->prepare('UPDATE mail_sends SET opens = LEAST(opens + 1, 100000), opened_at = IF(opened_at = 0, ?, opened_at) WHERE id = ?')->execute([time(), $tok]);
}
function campClick(string $tok, string $url): void {
  $r = campRow($tok); if (!$r) return;
  $pdo = db(); $now = time();
  $pdo->prepare('UPDATE mail_sends SET clicks = LEAST(clicks + 1, 100000), clicked_at = IF(clicked_at = 0, ?, clicked_at), opened_at = IF(opened_at = 0, ?, opened_at), opens = IF(opens = 0, 1, opens) WHERE id = ?')->execute([$now, $now, $tok]);
  $q = $pdo->prepare("SELECT COUNT(*) FROM mail_events WHERE send_id = ? AND type = 'click'"); $q->execute([$tok]);
  if ((int)$q->fetchColumn() < 100) $pdo->prepare("INSERT INTO mail_events (send_id, campaign, type, url, ts) VALUES (?, ?, 'click', ?, ?)")->execute([$tok, $r['campaign'], mb_substr(campNoUtm($url), 0, 600), $now]);
}
/* every lead with this email stops (or starts again) getting campaigns */
function campSetConsent(PDO $pdo, string $email, bool $yes, string $source): ?string {
  $q = $pdo->prepare('SELECT id, data FROM leads WHERE email_key = ? FOR UPDATE'); $q->execute([leadEmailKey($email)]); $lang = null;
  foreach ($q->fetchAll(PDO::FETCH_NUM) as [$id, $d]) {
    $L = json_decode((string)$d, true) ?: []; $lang = $lang ?? campLang($L);
    $L['consent'] = $yes ? 'yes' : 'no'; $L['consentAt'] = date('c'); $L['consentSource'] = $source;
    if ($yes) unset($L['unsubAt']); else $L['unsubAt'] = date('c');
    leadSave($pdo, (string)$id, $L);
  }
  return $lang;
}
function campUnsub(string $tok, bool $again = false): ?array {
  $r = campRow($tok); if (!$r) return null;
  return tx(function (PDO $pdo) use ($r, $again) {
    $lang = campSetConsent($pdo, (string)$r['email'], $again, $again ? 'iscritto di nuovo dal link nell\'email' : 'disiscrizione dal link nell\'email');
    $pdo->prepare('UPDATE mail_sends SET unsub_at = ? WHERE id = ?')->execute([$again ? 0 : time(), $r['id']]);
    $pdo->prepare('INSERT INTO mail_events (send_id, campaign, type, ts) VALUES (?, ?, ?, ?)')->execute([$r['id'], $r['campaign'], $again ? 'resub' : 'unsub', time()]);
    return ['email' => (string)$r['email'], 'lang' => $lang ?? 'it'];
  });
}
/* a bounce from the Postfix log: the latest campaign email to that address is marked, and the address is not used again */
function campBounce(string $email, string $why, int $ts): bool {
  $email = leadEmailKey($email); if ($email === '') return false;
  return tx(function (PDO $pdo) use ($email, $why, $ts) {
    $q = $pdo->prepare("SELECT id FROM mail_sends WHERE email = ? AND status = 'sent' AND sent_at BETWEEN ? AND ? ORDER BY sent_at DESC LIMIT 1 FOR UPDATE");
    $q->execute([$email, $ts - 6 * 86400, $ts + 3 * 3600]); $id = $q->fetchColumn(); // the log clock may be in another time zone
    if ($id === false) return false;
    $pdo->prepare("UPDATE mail_sends SET status = 'bounced', bounced_at = ?, error = ? WHERE id = ?")->execute([$ts, mb_substr($why, 0, 300), $id]);
    $q = $pdo->prepare('SELECT id, data FROM leads WHERE email_key = ? FOR UPDATE'); $q->execute([$email]);
    foreach ($q->fetchAll(PDO::FETCH_NUM) as [$lid, $d]) { $L = json_decode((string)$d, true) ?: []; $L['emailBad'] = $email; $L['emailBadAt'] = date('c', $ts); $L['emailBadWhy'] = mb_substr($why, 0, 200); leadSave($pdo, (string)$lid, $L); }
    return true;
  });
}

/* ---------- sign-up form for the website (m.php?iscriviti), with confirmation by email ---------- */
function campSignupKey(string $email): string { global $C; return substr(hash_hmac('sha256', 'signup|' . strtolower($email), (string)$C['secret']), 0, 24); }
/* returns false when this address already asked in the last 12 hours (no second email) */
function campSignup(array $C, string $email, string $name, string $lang): bool {
  $email = leadEmailKey($email); $lang = preg_match('/^(it|en|de|sl|nl)$/', $lang) ? $lang : 'it';
  $ask = tx(function (PDO $pdo) use ($email, $name, $lang) {
    $found = leadFind($pdo, '', $email);
    [$id, $L] = $found ?? [leadNewId(), ['source' => 'signup', 'createdAt' => date('c'), 'email' => $email]];
    if (($L['consent'] ?? '') === 'yes') return false; // already subscribed
    if (!empty($L['signupAskedAt']) && strtotime((string)$L['signupAskedAt']) > time() - 12 * 3600) return false;
    if ($name !== '' && trim((string)($L['name'] ?? '')) === '') $L['name'] = $name;
    if (empty($L['lang'])) $L['lang'] = $lang;
    $L['signupAskedAt'] = date('c');
    $L['tags'] = array_values(array_unique(array_merge((array)($L['tags'] ?? []), ['newsletter'])));
    leadSave($pdo, $id, $L);
    return true;
  });
  if (!$ask) return false;
  $T = campT($lang); $url = campBase($C) . '/m.php?k=' . campSignupKey($email) . '&e=' . rawurlencode($email) . '&l=' . $lang;
  $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
  $html = '<!doctype html><html lang="' . $lang . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body style="margin:0;background:#f6f2ec">'
    . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:28px 12px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;background:#ffffff;border:1px solid #e8e1d8;border-top:4px solid #cf4731;border-radius:8px;font-family:Georgia,serif;color:#1b1918">'
    . '<tr><td style="padding:28px">' . (!empty($C['_logo_cid']) ? '<img src="cid:' . $C['_logo_cid'] . '" width="180" alt="DVOR" style="display:block;margin:0 auto 18px;width:180px;height:auto;border:0">' : '')
    . '<h1 style="margin:0 0 12px;font-weight:normal;font-size:24px;color:#cf4731">' . $h($T['c_h']) . '</h1><p style="margin:0 0 18px;font-size:16px;line-height:1.55">' . $h($T['c_body']) . '</p>'
    . '<a href="' . $h($url) . '" style="display:inline-block;padding:13px 22px;border-radius:6px;background:#cf4731;color:#ffffff;text-decoration:none;font-family:Helvetica,Arial,sans-serif;font-size:15px">' . $h($T['c_btn']) . '</a>'
    . '</td></tr></table></td></tr></table></body></html>';
  sendMail($C, $email, ['subject' => $T['c_subj'], 'text' => $T['c_body'] . "\n\n" . $T['c_btn'] . ": $url\n\nOsteria Enoteca Dvor", 'html' => $html, 'logo' => !empty($C['_logo_cid'])]);
  logMail("signup ask $email");
  return true;
}
function campSignupConfirm(string $email, string $key): bool {
  $email = leadEmailKey($email);
  if ($email === '' || !hash_equals(campSignupKey($email), $key)) return false;
  tx(function (PDO $pdo) use ($email) {
    $found = leadFind($pdo, '', $email);
    [$id, $L] = $found ?? [leadNewId(), ['source' => 'signup', 'createdAt' => date('c'), 'email' => $email]];
    if (($L['consent'] ?? '') !== 'yes') { $L['consent'] = 'yes'; $L['consentAt'] = date('c'); $L['consentSource'] = 'iscrizione dal sito (confermata via email)'; }
    unset($L['unsubAt'], $L['signupAskedAt']);
    leadSave($pdo, $id, $L);
  });
  return true;
}

/* ---------- Claude writes the campaign (Marketing → Campagne email → «Scrivi con Claude») ----------
   The staff describes what to say; Claude returns subject ideas, preheader and blocks in the editor's own format.
   Model: campaign_model in config.php, otherwise anthropic_model. */
function campAi(array $C, array $in, array $events): array {
  if (empty($C['anthropic_api_key'])) throw new RuntimeException('Manca la chiave anthropic_api_key in config.php');
  $s = fn($k, $n) => mb_substr(trim((string)($in[$k] ?? '')), 0, $n);
  $lang = preg_match('/^(it|en|de|sl|nl)$/', (string)($in['lang'] ?? '')) ? $in['lang'] : 'it';
  $LN = ['it' => 'italiano', 'en' => 'inglese', 'de' => 'tedesco', 'sl' => 'sloveno', 'nl' => 'olandese'][$lang];
  $cur = campClean(is_array($in['data'] ?? null) ? $in['data'] : []);
  $ev = [];
  foreach ($events as $id => $e) {
    if ((string)($e['date'] ?? '') < date('Y-m-d')) continue;
    $ev[] = ['id' => (string)$id, 'data' => $e['date'] ?? '', 'ora' => !empty($e['allDay']) ? 'tutto il giorno' : ($e['time'] ?? ''), 'titolo' => $e['title_it'] ?? '', 'descrizione' => mb_substr((string)($e['desc_it'] ?? ''), 0, 600), 'prezzo' => $e['price'] ?? ''];
  }
  $ev = array_slice($ev, 0, 12);
  $system = "Sei il copywriter di Osteria Enoteca Dvor, a San Floriano del Collio (Gorizia): cucina di confine e friulana, vini del Collio, una terrazza e un parco sulle colline, eventi e serate a tema. "
    . "Scrivi email promozionali per gli ospiti che hanno dato il consenso. Voce: calda, ospitale, concreta, come il titolare che scrive a un cliente che conosce. Frasi brevi, niente toni da televendita, niente maiuscole urlate, niente punti esclamativi a raffica, niente parole da spam (gratis!!!, urgente, offerta imperdibile). "
    . "Non inventare prezzi, date, piatti, sconti o regali che non ti vengono dati: se servono e mancano, usa un segnaposto tra parentesi quadre, per esempio [piatto del giorno]. Niente urgenza finta. "
    . "Formato del testo: **grassetto**, *corsivo*, [parola](https://…) per i link, una riga vuota tra i paragrafi. {{nome}} è il nome dell'ospite; usa {{nome|alternativa}} nell'oggetto e nei saluti, così l'email funziona anche senza nome. "
    . "Blocchi disponibili: heading (titolo, size big o small), text, button (label e href, solo link veri come https://www.osteriadvor.com), book (pulsante per prenotare il tavolo), event (id di uno degli eventi dati), image (una foto: mettila solo se lo staff ne ha già messa una o se chiede una foto; la foto la sceglie lui), divider. "
    . "Di solito: un titolo, uno o due testi brevi (in tutto 60-150 parole), eventualmente l'evento, e un pulsante book alla fine. Oggetto sotto i 55 caratteri, anteprima sotto i 90 che completa l'oggetto senza ripeterlo. Scrivi tutto in $LN.";
  $ctx = ['richiesta dello staff' => $s('brief', 3000) ?: 'Migliora la campagna attuale.', 'tono' => $s('tone', 60) ?: 'caldo e ospitale',
    'destinatari' => $s('audience', 300) ?: 'tutti gli ospiti iscritti', 'offerta del momento nelle impostazioni' => (string)(settingsMain()['offer'] ?? ''),
    'oggi' => date('Y-m-d'), 'eventi in programma' => $ev, 'campagna attuale' => ['oggetto' => $cur['subject'], 'anteprima' => $cur['preheader'], 'blocchi' => $cur['blocks']]];
  $tool = ['name' => 'scrivi_campagna', 'description' => 'Restituisce la campagna email scritta.',
    'input_schema' => ['type' => 'object', 'required' => ['subjects', 'preheader', 'blocks'], 'properties' => [
      'subjects' => ['type' => 'array', 'description' => '5 oggetti diversi tra loro, il migliore per primo', 'items' => ['type' => 'string']],
      'preheader' => ['type' => 'string'],
      'name' => ['type' => 'string', 'description' => 'nome interno breve della campagna'],
      'blocks' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['t'], 'properties' => [
        't' => ['type' => 'string', 'enum' => ['heading', 'text', 'button', 'book', 'event', 'image', 'divider']],
        'text' => ['type' => 'string'], 'size' => ['type' => 'string', 'enum' => ['big', 'small']], 'label' => ['type' => 'string'], 'href' => ['type' => 'string'],
        'id' => ['type' => 'string', 'description' => 'id dell\'evento'], 'align' => ['type' => 'string', 'enum' => ['left', 'center']]]]],
      'note' => ['type' => 'string', 'description' => 'una frase allo staff: cosa completare (segnaposto) o un consiglio, facoltativa']]]];
  $body = ['model' => $C['campaign_model'] ?? ($C['anthropic_model'] ?: 'claude-haiku-5-5'), 'max_tokens' => 3000, 'system' => $system,
    'tools' => [$tool], 'tool_choice' => ['type' => 'tool', 'name' => 'scrivi_campagna'],
    'messages' => [['role' => 'user', 'content' => json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)]]];
  $ch = curl_init((string)($C['anthropic_url'] ?? 'https://api.anthropic.com/v1/messages'));
  curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90,
    CURLOPT_HTTPHEADER => ['content-type: application/json', 'x-api-key: ' . $C['anthropic_api_key'], 'anthropic-version: 2023-06-01'], CURLOPT_POSTFIELDS => json_encode($body)]);
  $res = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
  $j = is_string($res) ? json_decode($res, true) : null;
  if ($code !== 200 || !is_array($j)) { error_log('dvor camp ai: ' . $code . ' ' . substr((string)$res, 0, 300)); throw new RuntimeException('Claude non risponde, riprova tra poco'); }
  foreach ((array)($j['content'] ?? []) as $c) if (($c['type'] ?? '') === 'tool_use') {
    $x = (array)$c['input']; $ids = array_column($ev, 'id');
    $blocks = array_values(array_filter(campCleanBlocks($x['blocks'] ?? []), fn($b) => $b['t'] !== 'event' || in_array($b['id'], $ids, true)));
    return ['subjects' => array_slice(array_values(array_filter(array_map(fn($v) => mb_substr(trim((string)$v), 0, 200), (array)($x['subjects'] ?? [])))), 0, 6),
      'preheader' => mb_substr(trim((string)($x['preheader'] ?? '')), 0, 200), 'name' => mb_substr(trim((string)($x['name'] ?? '')), 0, 120),
      'blocks' => $blocks, 'note' => mb_substr(trim((string)($x['note'] ?? '')), 0, 400)];
  }
  throw new RuntimeException('Claude non ha scritto la campagna, riprova');
}

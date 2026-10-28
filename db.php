<?php
/* Osteria Dvor · prenotazioni · collegamento al database MySQL / MariaDB
   Usato da api.php e da strumenti/importa-json.php. Le tabelle si creano da sole al primo avvio. */
declare(strict_types=1);

function dbConnect(array $C): PDO {
  $d = $C['db'] ?? [];
  if (empty($d['name']) || empty($d['user'])) throw new PDOException('config.php: manca la sezione db (nome, utente, password)');
  $dsn = 'mysql:host=' . ($d['host'] ?? 'localhost') . (!empty($d['port']) ? ';port=' . (int)$d['port'] : '') . ';dbname=' . $d['name'] . ';charset=utf8mb4';
  return new PDO($dsn, $d['user'], $d['pass'] ?? '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4, time_zone = '+00:00'",
  ]);
}

/* creates the tables if they are missing; on a new database it also loads events and settings from seed/ */
function dbInstall(PDO $pdo): void {
  if ($pdo->query("SHOW TABLES LIKE 'leads'")->fetchColumn() !== false) return;
  /* two first requests at the same moment: only one fills the tables */
  $pdo->query("SELECT GET_LOCK('dvor_install', 20)");
  try { if ($pdo->query("SHOW TABLES LIKE 'leads'")->fetchColumn() === false) dbCreate($pdo); }
  finally { $pdo->query("SELECT RELEASE_LOCK('dvor_install')"); }
}
function dbCreate(PDO $pdo): void {
  $opt = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin';
  $pdo->exec("CREATE TABLE IF NOT EXISTS bookings (
    id VARCHAR(64) NOT NULL PRIMARY KEY,
    n BIGINT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE,
    bdate CHAR(10) NOT NULL DEFAULT '',
    btime CHAR(5) NOT NULL DEFAULT '',
    party SMALLINT NOT NULL DEFAULT 0,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    created_by VARCHAR(64) NOT NULL DEFAULT '',
    data LONGTEXT NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY by_day (bdate, status)
  ) $opt");
  $pdo->exec("CREATE TABLE IF NOT EXISTS docs (
    coll VARCHAR(32) NOT NULL,
    id VARCHAR(64) NOT NULL,
    n BIGINT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE,
    data LONGTEXT NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (coll, id)
  ) $opt");
  $pdo->exec("CREATE TABLE IF NOT EXISTS rate_hits (
    k VARCHAR(190) NOT NULL,
    ts INT UNSIGNED NOT NULL,
    KEY by_key (k, ts)
  ) $opt");
  $pdo->exec("CREATE TABLE IF NOT EXISTS day_locks (d CHAR(10) NOT NULL PRIMARY KEY) $opt");
  $pdo->exec("CREATE TABLE IF NOT EXISTS leads (
    id VARCHAR(64) NOT NULL PRIMARY KEY,
    n BIGINT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE,
    phone_key VARCHAR(20) NOT NULL DEFAULT '',
    email_key VARCHAR(160) NOT NULL DEFAULT '',
    data LONGTEXT NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY by_phone (phone_key),
    KEY by_email (email_key)
  ) $opt");
  foreach (['events', 'settings'] as $coll) {
    $f = __DIR__ . "/seed/$coll.json";
    $q = $pdo->prepare('SELECT COUNT(*) FROM docs WHERE coll = ?'); $q->execute([$coll]);
    if ((int)$q->fetchColumn() > 0 || !is_file($f)) continue;
    foreach ((array)json_decode((string)file_get_contents($f), true) as $id => $doc) if (is_array($doc)) dbPut($pdo, $coll, (string)$id, $doc);
  }
  leadRebuild($pdo); // the first time: one lead per guest of the bookings already in the database
}

/* insert or replace one document */
function dbPut(PDO $pdo, string $coll, string $id, array $doc): void {
  $json = json_encode($doc, JSON_UNESCAPED_UNICODE);
  if ($coll === 'bookings') {
    $pdo->prepare('INSERT INTO bookings (id, bdate, btime, party, status, created_by, data) VALUES (?, ?, ?, ?, ?, ?, ?)
      ON DUPLICATE KEY UPDATE bdate = VALUES(bdate), btime = VALUES(btime), party = VALUES(party), status = VALUES(status), created_by = VALUES(created_by), data = VALUES(data)')
      ->execute([$id, substr((string)($doc['date'] ?? ''), 0, 10), substr((string)($doc['time'] ?? ''), 0, 5), (int)($doc['party'] ?? 0), substr((string)($doc['status'] ?? 'active'), 0, 20), substr((string)($doc['createdBy'] ?? ''), 0, 64), $json]);
    leadSync($pdo, $id, $doc);
  } else {
    $pdo->prepare('INSERT INTO docs (coll, id, data) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE data = VALUES(data)')->execute([$coll, $id, $json]);
  }
}

/* ---------- marketing leads ----------
   One row per guest, found by phone (last 9 digits) or email. Every booking saved with dbPut updates its guest:
   name and contacts, the visit (date, time, guests, outcome), marketing consent and campaign. Leads from an imported
   list keep their past visits in `past`. Test and example bookings are left out. */
function leadPhoneKey(string $p): string { $d = preg_replace('/\D/', '', $p); return strlen($d) >= 6 ? substr($d, -9) : ''; }
function leadEmailKey(string $e): string { $e = strtolower(trim($e)); return str_contains($e, '@') ? mb_substr($e, 0, 160) : ''; }
/* call inside a transaction: the row stays locked until it ends */
function leadFind(PDO $pdo, string $pk, string $ek): ?array {
  foreach ([['phone_key', $pk], ['email_key', $ek]] as [$col, $key]) {
    if ($key === '') continue;
    $q = $pdo->prepare("SELECT id, data FROM leads WHERE $col = ? ORDER BY n LIMIT 1 FOR UPDATE"); $q->execute([$key]);
    if ($r = $q->fetch(PDO::FETCH_NUM)) { $x = json_decode((string)$r[1], true); return [(string)$r[0], is_array($x) ? $x : []]; }
  }
  return null;
}
function leadNewId(): string { return bin2hex(random_bytes(10)); }
/* visits, no shows, first and last visit, next booking: kept with the lead so the page can filter and sort */
function leadSummary(array $L): array {
  $today = date('Y-m-d'); $s = ['bookings' => 0, 'visits' => 0, 'noShows' => 0, 'cancelled' => 0, 'first' => '', 'last' => '', 'next' => '', 'guests' => 0];
  foreach ((array)($L['visits'] ?? []) as $v) {
    $d = (string)($v['d'] ?? ''); $st = (string)($v['s'] ?? ''); $s['bookings']++;
    if ($st === 'cancelled') { $s['cancelled']++; continue; }
    if ($st === 'noshow') { $s['noShows']++; continue; }
    if ($d >= $today && $st !== 'came') { if ($s['next'] === '' || $d < $s['next']) $s['next'] = $d; continue; }
    $s['visits']++; $s['guests'] += (int)($v['p'] ?? 0);
    if ($s['first'] === '' || $d < $s['first']) $s['first'] = $d;
    if ($d > $s['last']) $s['last'] = $d;
  }
  $p = (array)($L['past'] ?? []);
  if (!empty($p['count'])) { $s['visits'] += (int)$p['count']; $s['guests'] += (int)round((float)($p['party'] ?? 0) * (int)$p['count']); }
  if (!empty($p['last']) && $p['last'] > $s['last']) $s['last'] = $p['last'];
  if (!empty($p['first']) && ($s['first'] === '' || $p['first'] < $s['first'])) $s['first'] = $p['first'];
  $s['avgParty'] = $s['visits'] ? round($s['guests'] / $s['visits'], 1) : 0;
  return $s;
}
function leadSave(PDO $pdo, string $id, array $L): void {
  $L['sum'] = leadSummary($L); $L['updatedAt'] = date('c');
  $pdo->prepare('INSERT INTO leads (id, phone_key, email_key, data) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE phone_key = VALUES(phone_key), email_key = VALUES(email_key), data = VALUES(data)')
    ->execute([$id, leadPhoneKey((string)($L['phone'] ?? '')), leadEmailKey((string)($L['email'] ?? '')), json_encode($L, JSON_UNESCAPED_UNICODE)]);
}
function leadSync(PDO $pdo, string $bid, array $b): void {
  if (in_array($b['source'] ?? '', ['test', 'test-resend'], true) || !empty($b['example'])) return;
  $pk = leadPhoneKey((string)($b['phone'] ?? '')); $ek = leadEmailKey((string)($b['email'] ?? ''));
  if ($pk === '' && $ek === '') return; // only a name: nothing to reach the guest with
  [$id, $L] = leadFind($pdo, $pk, $ek) ?? [leadNewId(), ['source' => 'booking', 'createdAt' => (string)($b['createdAt'] ?? date('c'))]];
  $name = trim((string)($b['name'] ?? ''));
  if ($name !== '' && $name !== ($L['name'] ?? '')) { $L['name'] = $name; unset($L['firstName'], $L['lastName']); }
  foreach (['phone', 'email', 'lang'] as $k) if (trim((string)($b[$k] ?? '')) !== '') $L[$k] = trim((string)$b[$k]);
  $first = !isset($L['visits'][$bid]);
  $st = ($b['status'] ?? '') === 'cancelled' ? 'cancelled' : (($b['arrival'] ?? '') === 'noshow' ? 'noshow' : (($b['arrival'] ?? '') === 'in' ? 'came' : 'booked'));
  $L['visits'][$bid] = array_filter(['d' => (string)($b['date'] ?? ''), 't' => (string)($b['time'] ?? ''), 'p' => (int)($b['party'] ?? 0), 's' => $st, 'src' => (string)($b['source'] ?? ''), 'ev' => (string)($b['eventTitle'] ?? ''), 'deg' => (int)($b['menuTasting'] ?? 0)], fn($v) => $v !== '' && $v !== 0);
  if ($first) { // consent and campaign count once, when the booking is made; later the staff can change them on the lead
    if (!empty($b['marketing'])) { $L['consent'] = 'yes'; $L['consentAt'] = (string)($b['createdAt'] ?? date('c')); $L['consentSource'] = 'prenotazione'; }
    elseif (array_key_exists('marketing', $b) && ($L['consent'] ?? '') === '') $L['consent'] = 'no';
    if (!empty($b['utm']) && is_array($b['utm'])) { $L['utm'] = $b['utm']; $L['firstUtm'] = $L['firstUtm'] ?? $b['utm']; }
  }
  leadSave($pdo, $id, $L);
}
/* one row of an imported list, already mapped by the page: returns new, updated or skipped */
function leadImport(PDO $pdo, array $r, string $label, bool $consentAll): string {
  $s = fn($k, $n = 200) => mb_substr(trim((string)($r[$k] ?? '')), 0, $n);
  $date = fn($k) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $s($k, 10)) ? $s($k, 10) : '';
  $phone = $s('phone', 40); $email = strtolower($s('email', 160));
  if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $email = '';
  $pk = leadPhoneKey($phone); $ek = leadEmailKey($email);
  if ($pk === '' && $ek === '') return 'skipped';
  $found = leadFind($pdo, $pk, $ek);
  [$id, $L] = $found ?? [leadNewId(), ['source' => 'import', 'createdAt' => date('c')]];
  $name = $s('name', 120) ?: trim($s('firstName', 60) . ' ' . $s('lastName', 60));
  $fields = ['name' => $name, 'firstName' => $s('firstName', 60), 'lastName' => $s('lastName', 60), 'phone' => $pk ? $phone : '', 'email' => $email,
    'lang' => preg_match('/^[a-z]{2}$/', $s('lang', 2)) ? $s('lang', 2) : '', 'country' => strtoupper($s('country', 2)), 'city' => $s('city', 80), 'birthday' => $date('birthday')];
  foreach ($fields as $k => $v) if ($v !== '' && trim((string)($L[$k] ?? '')) === '') $L[$k] = $v; // what the bookings say stays
  $tags = array_filter(array_map(fn($t) => mb_substr(trim($t), 0, 40), preg_split('/[,;|]/', $s('tags', 400))));
  if ($tags) $L['tags'] = array_values(array_unique(array_merge((array)($L['tags'] ?? []), $tags)));
  $notes = $s('notes', 1000);
  if ($notes !== '' && !str_contains((string)($L['notes'] ?? ''), $notes)) $L['notes'] = trim(($L['notes'] ?? '') . "\n" . $notes);
  $p = (array)($L['past'] ?? []); // the same list imported twice does not double the visits
  $cnt = max(0, (int)($r['visits'] ?? 0)); $last = $date('lastVisit'); $firstV = $date('firstVisit') ?: $last;
  if (!$cnt && $last) $cnt = 1;
  if ($cnt > (int)($p['count'] ?? 0)) { $p['count'] = $cnt; if ((float)($r['party'] ?? 0) > 0) $p['party'] = round((float)$r['party'], 1); }
  if ($last && $last > ($p['last'] ?? '')) $p['last'] = $last;
  if ($firstV && (empty($p['first']) || $firstV < $p['first'])) $p['first'] = $firstV;
  if ($p) $L['past'] = $p;
  $c = strtolower($s('consent', 20));
  $yes = $c !== '' ? (bool)preg_match('/^(s|si|sì|y|yes|ja|da|1|x|true|vero|ok)/u', $c) : $consentAll;
  $no = $c !== '' && !$yes;
  if ($yes && ($L['consent'] ?? '') !== 'yes') { $L['consent'] = 'yes'; $L['consentAt'] = date('c'); $L['consentSource'] = 'importazione' . ($label !== '' ? ': ' . $label : ''); }
  elseif ($no && ($L['consent'] ?? '') === '') $L['consent'] = 'no';
  if (!$found) { $L['importFile'] = $label; $L['importedAt'] = date('c'); }
  leadSave($pdo, $id, $L);
  return $found ? 'updated' : 'new';
}
/* again from all the bookings: each one updates its visit, nothing is doubled */
function leadRebuild(PDO $pdo): int {
  $n = 0;
  foreach ($pdo->query('SELECT id, data FROM bookings ORDER BY n')->fetchAll(PDO::FETCH_NUM) as [$id, $data]) {
    $b = json_decode((string)$data, true); if (!is_array($b)) continue;
    leadSync($pdo, (string)$id, $b); $n++;
  }
  return $n;
}

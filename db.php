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
  if ($pdo->query("SHOW TABLES LIKE 'day_locks'")->fetchColumn() !== false) return;
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
  foreach (['events', 'settings'] as $coll) {
    $f = __DIR__ . "/seed/$coll.json";
    $q = $pdo->prepare('SELECT COUNT(*) FROM docs WHERE coll = ?'); $q->execute([$coll]);
    if ((int)$q->fetchColumn() > 0 || !is_file($f)) continue;
    foreach ((array)json_decode((string)file_get_contents($f), true) as $id => $doc) if (is_array($doc)) dbPut($pdo, $coll, (string)$id, $doc);
  }
}

/* insert or replace one document */
function dbPut(PDO $pdo, string $coll, string $id, array $doc): void {
  $json = json_encode($doc, JSON_UNESCAPED_UNICODE);
  if ($coll === 'bookings') {
    $pdo->prepare('INSERT INTO bookings (id, bdate, btime, party, status, created_by, data) VALUES (?, ?, ?, ?, ?, ?, ?)
      ON DUPLICATE KEY UPDATE bdate = VALUES(bdate), btime = VALUES(btime), party = VALUES(party), status = VALUES(status), created_by = VALUES(created_by), data = VALUES(data)')
      ->execute([$id, substr((string)($doc['date'] ?? ''), 0, 10), substr((string)($doc['time'] ?? ''), 0, 5), (int)($doc['party'] ?? 0), substr((string)($doc['status'] ?? 'active'), 0, 20), substr((string)($doc['createdBy'] ?? ''), 0, 64), $json]);
  } else {
    $pdo->prepare('INSERT INTO docs (coll, id, data) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE data = VALUES(data)')->execute([$coll, $id, $json]);
  }
}

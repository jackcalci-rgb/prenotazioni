<?php
/* Osteria Dvor · copia nel database MySQL le prenotazioni salvate nei vecchi file JSON.
   Uso (sul VPS):  php strumenti/importa-json.php [cartella-dei-json]
   Senza cartella usa data_dir di config.php. Si può rilanciare: le righe già nel database non vengono toccate. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$root = dirname(__DIR__);
if (!is_file("$root/config.php")) { fwrite(STDERR, "Manca config.php\n"); exit(1); }
$C = require "$root/config.php";
require "$root/db.php";

$dir = rtrim($argv[1] ?? ($C['data_dir'] ?? "$root/data"), '/');
if (!is_dir($dir)) { fwrite(STDERR, "Cartella non trovata: $dir\n"); exit(1); }

try { $pdo = dbConnect($C); dbInstall($pdo); }
catch (PDOException $e) { fwrite(STDERR, 'Database non raggiungibile: ' . $e->getMessage() . "\n"); exit(1); }

echo "Importo da $dir\n";
$pdo->beginTransaction();
foreach (['bookings', 'events', 'overrides', 'settings', 'blacklist'] as $coll) {
  $f = "$dir/$coll.json";
  if (!is_file($f)) { printf("  %-10s nessun file\n", $coll); continue; }
  $rows = json_decode((string)file_get_contents($f), true);
  if (!is_array($rows)) { fwrite(STDERR, "  $coll.json non è JSON valido, saltato\n"); continue; }
  $q = $pdo->prepare($coll === 'bookings' ? 'SELECT data FROM bookings WHERE id = ?' : 'SELECT data FROM docs WHERE coll = ? AND id = ?');
  /* events and settings loaded from seed/ at the first start are replaced by the real ones */
  $seed = is_file("$root/seed/$coll.json") ? (array)json_decode((string)file_get_contents("$root/seed/$coll.json"), true) : [];
  $new = 0; $kept = 0;
  foreach ($rows as $id => $doc) {
    $id = (string)$id;
    if (!is_array($doc) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id)) continue;
    $q->execute($coll === 'bookings' ? [$id] : [$coll, $id]);
    $cur = $q->fetchColumn();
    if ($cur !== false && !(isset($seed[$id]) && json_decode((string)$cur, true) == $seed[$id])) { $kept++; continue; }
    dbPut($pdo, $coll, $id, $doc); $new++;
  }
  printf("  %-10s %d nuove, %d già presenti\n", $coll, $new, $kept);
}
$pdo->commit();
echo "Fatto. I file JSON restano dove sono: puoi tenerli come copia.\n";

<?php
/* Osteria Dvor · rimbalzi delle campagne email, letti dal registro di Postfix (/var/log/maillog, o 'maillog' in config.php).
   Ogni riga «status=bounced» di un indirizzo che ha appena ricevuto una campagna segna l'email come rimbalzata
   e quell'indirizzo non riceve più campagne finché non cambia.
   Lo lancia cron ogni 10 minuti come root, perché il registro è leggibile solo da root (aggiorna.sh crea /etc/cron.d/dvor-rimbalzi).
   A mano:  php strumenti/rimbalzi.php      Da capo, tutto il registro:  php strumenti/rimbalzi.php tutto */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
const DVOR_NO_REQUEST = true;
require __DIR__ . '/../api.php';
$log = (string)($C['maillog'] ?? (is_file('/var/log/maillog') ? '/var/log/maillog' : '/var/log/mail.log'));
if (!is_readable($log)) { fwrite(STDERR, "Non riesco a leggere $log (serve root)\n"); exit(1); }
$posFile = (is_writable('/var/lib') ? '/var/lib' : sys_get_temp_dir()) . '/dvor-rimbalzi.pos'; // root writes it: not in the site folders
[$ino, $pos] = array_map('intval', explode(' ', (string)@file_get_contents($posFile) . ' 0 0'));
$st = stat($log);
if (($argv[1] ?? '') === 'tutto' || $ino !== (int)$st['ino'] || $pos > (int)$st['size']) $pos = 0; // the log was rotated
$f = fopen($log, 'r'); fseek($f, $pos); $n = 0; $seen = 0;
while (($line = fgets($f)) !== false) {
  if (!str_contains($line, 'status=bounced') || !preg_match('/\bto=<([^>]+)>/', $line, $m)) continue;
  $seen++;
  $why = preg_match('/status=bounced \((.*)\)\s*$/', $line, $w) ? $w[1] : 'bounced';
  /* «Nov  4 17:20:01 host …» or «2026-11-04T17:20:01.123+01:00 host …» */
  $ts = preg_match('/^(\d{4}-\d\d-\d\dT[\d:.]+(?:Z|[+-]\d\d:?\d\d)?)/', $line, $d) ? strtotime($d[1]) : (preg_match('/^([A-Z][a-z]{2}\s+\d+\s+[\d:]{8})/', $line, $d) ? strtotime($d[1]) : time());
  if ($ts > time() + 86400) $ts = strtotime('-1 year', $ts); // December log read in January
  if (campBounce($m[1], $why, $ts ?: time())) $n++;
}
file_put_contents($posFile, $st['ino'] . ' ' . ftell($f));
fclose($f);
if ($n) echo date('c') . " rimbalzi: $n email di campagne segnate (su $seen righe status=bounced)\n";

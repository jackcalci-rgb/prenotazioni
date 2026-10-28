<?php
/* Osteria Dvor · promemoria via email qualche ora prima della prenotazione (Impostazioni → «Promemoria email», di solito 4 ore).
   Lo lancia cron ogni 15 minuti (aggiorna.sh crea /etc/cron.d/dvor-promemoria). A mano:  php strumenti/promemoria.php */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
const DVOR_NO_REQUEST = true;
require __DIR__ . '/../api.php';
$lock = fopen(dataDir() . '/promemoria.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit(0); // the previous run is still sending
$sent = sendReminders($C);
echo date('c') . ' promemoria inviati: ' . count($sent) . ($sent ? "\n" . implode("\n", $sent) : '') . "\n";

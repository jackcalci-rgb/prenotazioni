<?php
/* Osteria Dvor · invio delle campagne email: fa partire quelle programmate e manda la coda,
   al massimo campaign_per_minute email al minuto (config.php, di solito 40).
   Lo lancia cron ogni minuto (aggiorna.sh crea /etc/cron.d/dvor-campagne). A mano:  php strumenti/campagne.php */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
const DVOR_NO_REQUEST = true;
require __DIR__ . '/../api.php';
$r = campRun($C, 50, true);
if (!empty($r['busy'])) exit(0); // the page or the previous run is sending
if ($r['sent'] || $r['failed'] || $r['skipped'] || $r['started']) echo date('c') . " campagne: partite {$r['started']}, inviate {$r['sent']}, errori {$r['failed']}, saltate {$r['skipped']}\n";

<?php
/* Osteria Dvor · pulizia per l'uso reale. La lancia aggiorna.sh a ogni aggiornamento; si può rilanciare senza danni.
   - cancella le prenotazioni di prova create dal vecchio pulsante «Genera prenotazioni di prova» (segnate example o source test), mai quelle vere
   - toglie dalla lista nera i numeri finti di quelle prove (000 000 0000, prova@example.com)
   - una volta sola: mette la copertina all'evento di San Martino (11 novembre 2026)
   A mano:  sudo -u <utente> php strumenti/vai-live.php */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
const DVOR_NO_REQUEST = true;
require __DIR__ . '/../api.php';

const SAN_MARTINO_COVER = '9ecdaac6ea4da1f311b577a447b1da80.jpg';

$gone = 0;
foreach (load('bookings') as $id => $b) {
  if (empty($b['example']) && !in_array($b['source'] ?? '', ['test', 'test-resend'], true)) continue;
  delDoc('bookings', (string)$id); $gone++;
}
echo "Prenotazioni di prova cancellate: $gone\n";

$gone = 0;
foreach (load('blacklist') as $id => $x) {
  $digits = preg_replace('/\D/', '', (string)($x['phone'] ?? ''));
  $fake = strtolower(trim((string)($x['email'] ?? ''))) === 'prova@example.com' || ($digits !== '' && trim($digits, '0') === '');
  if (!$fake) continue;
  delDoc('blacklist', (string)$id); $gone++;
}
echo "Lista nera, voci di prova tolte: $gone\n";

$mark = dataDir() . '/copertina-san-martino-2026.fatto';
if (!is_file($mark) && is_file(__DIR__ . '/../uploads/' . SAN_MARTINO_COVER)) {
  $n = 0;
  foreach (load('events') as $id => $e) {
    if (($e['date'] ?? '') !== '2026-11-11' || stripos((string)($e['title_it'] ?? '') . ' ' . $id, 'martino') === false) continue;
    $e['imageId'] = SAN_MARTINO_COVER; putDoc('events', (string)$id, $e); $n++;
  }
  if ($n) { file_put_contents($mark, date('c') . "\n"); echo "Copertina di San Martino impostata\n"; }
  else echo "Evento di San Martino non trovato: carica la copertina dall'area staff (⚙ → Eventi)\n";
}

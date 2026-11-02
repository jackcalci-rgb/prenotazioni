<?php
/* Osteria Dvor · notifiche push all'area staff (Web Push con chiavi VAPID, senza librerie esterne).
   Ogni telefono o computer dello staff che attiva le notifiche lascia qui il suo indirizzo (docs, raccolta «pushsubs»,
   mai leggibile dalla pagina). Le chiavi VAPID si creano da sole la prima volta (docs, «pushkeys»).
   Su iPhone le notifiche arrivano solo all'app aggiunta alla schermata Home (iOS 16.4 o più recente).
   Cifratura del messaggio: RFC 8291 (aes128gcm); firma: RFC 8292 (ES256). Serve solo l'estensione openssl di PHP. */
declare(strict_types=1);
if (realpath(__FILE__) === realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? ''))) { http_response_code(404); exit; }

/* push services accepted as subscription endpoints (Apple, Google/Chrome, Mozilla, Microsoft) */
const PUSH_HOSTS = '/(^|\.)(push\.apple\.com|fcm\.googleapis\.com|android\.googleapis\.com|push\.services\.mozilla\.com|notify\.windows\.com)$/';

function b64u(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function b64uDec(string $s): string { return (string)base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true); }
function pushId(string $endpoint): string { return substr(hash('sha256', $endpoint), 0, 40); }

/* raw uncompressed P-256 point (65 bytes) of an EC key */
function pushRawPub($key): string {
  $d = openssl_pkey_get_details($key)['ec'];
  return "\x04" . str_pad($d['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['y'], 32, "\0", STR_PAD_LEFT);
}
/* PEM public key from a raw P-256 point (the browser's p256dh) */
function pushPubPem(string $raw): string {
  $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $raw;
  return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
}
function pushNewKey() {
  $k = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
  if ($k === false) throw new RuntimeException('openssl: chiave EC non creata');
  return $k;
}

/* VAPID keys of this server: created once and kept in the database */
function pushKeys(): array {
  static $k = null; if ($k) return $k;
  $k = getDoc('pushkeys', 'vapid');
  if ($k === null || empty($k['pem'])) {
    $k = tx(function () {
      $k = getDoc('pushkeys', 'vapid', true);
      if ($k !== null && !empty($k['pem'])) return $k;
      $key = pushNewKey(); openssl_pkey_export($key, $pem);
      $k = ['pem' => $pem, 'pub' => b64u(pushRawPub($key)), 'createdAt' => date('c')];
      putDoc('pushkeys', 'vapid', $k); return $k;
    });
  }
  return $k;
}

/* DER ECDSA signature -> 64 bytes r||s, as JWT ES256 wants */
function pushDerToJose(string $der): string {
  $p = 2; if (ord($der[1]) & 0x80) $p += ord($der[1]) & 0x7f;
  $out = '';
  for ($i = 0; $i < 2; $i++) {
    $len = ord($der[$p + 1]); $int = substr($der, $p + 2, $len); $p += 2 + $len;
    $out .= str_pad(ltrim($int, "\0"), 32, "\0", STR_PAD_LEFT);
  }
  return $out;
}
function pushVapidHeader(string $endpoint, array $keys, string $subject): string {
  $u = parse_url($endpoint);
  $h = b64u(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
  $c = b64u(json_encode(['aud' => $u['scheme'] . '://' . $u['host'], 'exp' => time() + 12 * 3600, 'sub' => $subject], JSON_UNESCAPED_SLASHES));
  if (!openssl_sign("$h.$c", $sig, $keys['pem'], OPENSSL_ALGO_SHA256)) throw new RuntimeException('openssl: firma VAPID non riuscita');
  return 'vapid t=' . "$h.$c." . b64u(pushDerToJose($sig)) . ', k=' . $keys['pub'];
}

/* RFC 8291: the message encrypted for one browser (p256dh and auth from its subscription) */
function pushEncrypt(string $payload, string $uaPub, string $auth): string {
  if (strlen($uaPub) !== 65 || strlen($auth) < 16) throw new RuntimeException('Chiavi del dispositivo non valide');
  $as = pushNewKey(); $asPub = pushRawPub($as);
  $peer = openssl_pkey_get_public(pushPubPem($uaPub));
  $ecdh = $peer ? openssl_pkey_derive($peer, $as, 32) : false;
  if ($ecdh === false) throw new RuntimeException('openssl: ECDH non riuscito');
  $prkKey = hash_hmac('sha256', $ecdh, $auth, true);
  $ikm = hash_hmac('sha256', "WebPush: info\0" . $uaPub . $asPub . "\x01", $prkKey, true);
  $salt = random_bytes(16);
  $prk = hash_hmac('sha256', $ikm, $salt, true);
  $cek = substr(hash_hmac('sha256', "Content-Encoding: aes128gcm\0\x01", $prk, true), 0, 16);
  $nonce = substr(hash_hmac('sha256', "Content-Encoding: nonce\0\x01", $prk, true), 0, 12);
  $ct = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
  if ($ct === false) throw new RuntimeException('openssl: cifratura non riuscita');
  return $salt . pack('N', 4096) . chr(65) . $asPub . $ct . $tag;
}

/* sends one message to many subscriptions at once; returns [subId => http code (0 = not reached)] */
function pushSendMany(array $subs, array $msg, array $C): array {
  if (!$subs) return [];
  $keys = pushKeys();
  $subject = filter_var($C['reply_to'] ?? '', FILTER_VALIDATE_EMAIL) ? 'mailto:' . $C['reply_to'] : (string)($C['booking_url'] ?? 'https://prenota.osteriadvor.com');
  $payload = json_encode($msg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  $mh = curl_multi_init(); $hs = []; $res = [];
  foreach ($subs as $id => $s) {
    try {
      $body = pushEncrypt($payload, b64uDec((string)$s['p256dh']), b64uDec((string)$s['auth']));
      $ch = curl_init((string)$s['endpoint']);
      curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => 8, CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['Content-Type: application/octet-stream', 'Content-Encoding: aes128gcm', 'TTL: 43200', 'Urgency: high',
          'Authorization: ' . pushVapidHeader((string)$s['endpoint'], $keys, $subject)]]);
      curl_multi_add_handle($mh, $ch); $hs[$id] = $ch;
    } catch (Throwable $e) { $res[$id] = 0; error_log('dvor push: ' . $e->getMessage()); }
  }
  do { $st = curl_multi_exec($mh, $run); if ($run) curl_multi_select($mh, 1.0); } while ($run && $st === CURLM_OK);
  foreach ($hs as $id => $ch) {
    $res[$id] = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($res[$id] >= 300) error_log('dvor push: ' . $res[$id] . ' ' . substr((string)curl_multi_getcontent($ch), 0, 200));
    curl_multi_remove_handle($mh, $ch); curl_close($ch);
  }
  curl_multi_close($mh);
  return $res;
}

/* the staff devices that want this kind of message ('new' = new booking from a guest, 'change' = guest moved, changed or cancelled) */
function pushTargets(string $kind): array {
  $out = [];
  foreach (load('pushsubs') as $id => $s) {
    if (empty($s['endpoint']) || ($kind === 'change' && empty($s['changes'])) || ($kind === 'new' && isset($s['news']) && !$s['news'])) continue;
    if (staffUser((string)($s['user'] ?? '')) === null) continue; // account deleted: no more messages to its devices
    $out[(string)$id] = $s;
  }
  return $out;
}
/* a subscription the push service no longer knows (app removed, permission taken back) is forgotten */
function pushCleanup(array $res): void {
  foreach ($res as $id => $code) if ($code === 404 || $code === 410) delDoc('pushsubs', (string)$id);
}

/* text of the notification for a booking */
function pushBookingMsg(array $b, string $what): array {
  static $mesi = ['', 'gen', 'feb', 'mar', 'apr', 'mag', 'giu', 'lug', 'ago', 'set', 'ott', 'nov', 'dic'];
  static $gg = ['dom', 'lun', 'mar', 'mer', 'gio', 'ven', 'sab'];
  $t = strtotime((string)($b['date'] ?? '')) ?: time();
  $day = $b['date'] === date('Y-m-d') ? 'oggi' : ($b['date'] === date('Y-m-d', strtotime('+1 day')) ? 'domani' : $gg[(int)date('w', $t)] . ' ' . (int)date('j', $t) . ' ' . $mesi[(int)date('n', $t)]);
  $party = (int)($b['party'] ?? 0);
  $bits = [trim((string)($b['name'] ?? ''))];
  if (!empty($b['menuTasting'])) $bits[] = 'degustazione ' . (int)$b['menuTasting'];
  if (!empty($b['eventTitle'])) $bits[] = (string)$b['eventTitle'];
  if (!empty($b['pending'])) $bits[] = 'cena in attesa';
  if (!empty($b['notes'])) $bits[] = mb_substr((string)$b['notes'], 0, 120);
  if (function_exists('blacklisted') && blacklisted($b)) array_unshift($bits, '⚠ lista nera');
  return [
    'title' => "$what · $day {$b['time']} · $party " . ($party === 1 ? 'persona' : 'persone'),
    'body' => implode(' · ', array_filter($bits, 'strlen')),
    'tag' => 'b' . preg_replace('/[^A-Za-z0-9]/', '', (string)($b['id'] ?? '')),
    'url' => './#staff',
    'id' => (string)($b['id'] ?? ''),
    'date' => (string)($b['date'] ?? ''),
  ];
}

/* queued and sent after the answer has gone to the guest, so booking stays quick even with a slow push service */
function pushStaff(array $C, array $b, string $what, string $kind): void {
  static $queue = null;
  if (($b['source'] ?? '') === 'test' || !empty($b['example'])) return;
  if ($queue === null) {
    $queue = [];
    register_shutdown_function(function () use (&$queue, $C) {
      if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
      foreach ($queue as [$msg, $kind]) {
        try { pushCleanup(pushSendMany(pushTargets($kind), $msg, $C)); }
        catch (Throwable $e) { error_log('dvor push: ' . $e->getMessage()); }
      }
    });
  }
  $queue[] = [pushBookingMsg($b, $what), $kind];
}

/* api: subscription from the staff page */
function pushValidSub($s): ?array {
  if (!is_array($s)) return null;
  $ep = (string)($s['endpoint'] ?? ''); $k = is_array($s['keys'] ?? null) ? $s['keys'] : [];
  $u = parse_url($ep);
  if (strlen($ep) > 1000 || ($u['scheme'] ?? '') !== 'https' || !preg_match(PUSH_HOSTS, strtolower((string)($u['host'] ?? '')))) return null;
  $p = (string)($k['p256dh'] ?? ''); $a = (string)($k['auth'] ?? '');
  if (strlen(b64uDec($p)) !== 65 || strlen(b64uDec($a)) < 16) return null;
  return ['endpoint' => $ep, 'p256dh' => $p, 'auth' => $a];
}

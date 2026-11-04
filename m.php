<?php
/* Osteria Dvor · i link delle email promozionali (mailing.php)
   m.php?o=…            pixel di apertura
   m.php?c=…&s=…&u=…    clic su un link: conta e porta alla pagina
   m.php?u=…            disiscrizione (pagina con conferma; POST da «Disiscriviti» del programma di posta)
   m.php?iscriviti      modulo di iscrizione per il sito (anche dentro un iframe), con conferma via email
   m.php?k=…&e=…        conferma dell'iscrizione */
declare(strict_types=1);
const DVOR_NO_REQUEST = true;
require __DIR__ . '/api.php';
header_remove('Content-Type');

function mPage(string $lang, string $title, string $body, bool $embed = false): void {
  header('Content-Type: text/html; charset=utf-8');
  header('Cache-Control: no-store');
  header('Referrer-Policy: no-referrer');
  if (!$embed) header('X-Frame-Options: DENY');
  $h = fn($s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
  echo '<!doctype html><html lang="' . $h($lang) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">'
    . '<title>' . $h($title) . ' · Osteria Dvor</title><link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@600&family=Inter:wght@400;600&display=swap" rel="stylesheet"><style>'
    . ':root{--bg:#f6f2ec;--card:#fff;--ink:#1b1918;--muted:#7c736c;--line:#e8e1d8;--wine:#cf4731;--on:#fff}'
    . '@media (prefers-color-scheme:dark){:root{--bg:#151210;--card:#1e1a17;--ink:#f2ede7;--muted:#a99e94;--line:#332d28;--wine:#e2614b;--on:#fff}}'
    . '*{box-sizing:border-box}body{margin:0;background:' . ($embed ? 'transparent' : 'var(--bg)') . ';color:var(--ink);font:400 15px/1.55 Inter,system-ui,-apple-system,"Segoe UI",Arial,sans-serif;padding:' . ($embed ? '0' : '2.5rem 16px') . '}'
    . '.c{max-width:30rem;margin:0 auto;background:var(--card);border:1px solid var(--line);border-top:4px solid var(--wine);border-radius:16px;padding:1.6rem;box-shadow:0 1px 2px rgba(27,25,24,.04),0 4px 16px rgba(27,25,24,.06)}'
    . 'h1{font:600 1.9rem/1.15 "Cormorant Garamond",Georgia,serif;margin:0 0 .8rem;color:var(--ink)}p{margin:0 0 1rem}.m{color:var(--muted);font-size:.85rem}'
    . 'button,.b{font:600 15px Inter,system-ui,Arial,sans-serif;border:1px solid var(--wine);background:var(--wine);color:var(--on);padding:.7rem 1.2rem;border-radius:12px;cursor:pointer;text-decoration:none;display:inline-block}'
    . '.g{background:var(--card);color:var(--ink);border-color:var(--line)}label{display:flex;flex-direction:column;gap:.25rem;font-size:.8rem;color:var(--muted);margin-bottom:.8rem}'
    . 'input{font:400 1rem Arial,sans-serif;color:var(--ink);background:var(--bg);border:1px solid var(--line);border-radius:12px;padding:.65rem .75rem;width:100%}.hp{position:absolute;left:-9999px}'
    . '</style></head><body><div class="c">' . (!$embed ? '<div class="m" style="letter-spacing:.2em;text-transform:uppercase;margin-bottom:.6rem">Osteria Enoteca Dvor</div>' : '') . $body . '</div></body></html>';
  exit;
}
function mLang(): string { $l = (string)($_GET['l'] ?? $_GET['lang'] ?? ''); if (preg_match('/^(it|en|de|sl|nl)$/', $l)) return $l; $a = strtolower(substr((string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'it'), 0, 2)); return preg_match('/^(it|en|de|sl|nl)$/', $a) ? $a : 'it'; }
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$site = rtrim($C['site_url'] ?? 'https://www.osteriadvor.com', '/');
$post = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

/* open: a 1×1 transparent gif, whatever happens */
if (isset($_GET['o'])) {
  try { campOpen((string)$_GET['o']); } catch (Throwable $e) { error_log('dvor m open: ' . $e->getMessage()); }
  header('Content-Type: image/gif'); header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'); exit;
}

/* click: only links signed for that email, so this is not an open redirect */
if (isset($_GET['c'])) {
  $tok = (string)$_GET['c']; $url = (string)($_GET['u'] ?? '');
  $ok = preg_match('#^https?://#i', $url) && hash_equals(campSig($tok, $url), (string)($_GET['s'] ?? ''));
  if ($ok) { try { campClick($tok, $url); } catch (Throwable $e) { error_log('dvor m click: ' . $e->getMessage()); } }
  header('Cache-Control: no-store'); header('Referrer-Policy: no-referrer');
  header('Location: ' . ($ok ? $url : $site), true, 302); exit;
}

/* unsubscribe */
if (isset($_GET['u'])) {
  $tok = (string)$_GET['u'];
  if ($tok === CAMP_TEST) { $T = campT(mLang()); mPage(mLang(), $T['u_title'], '<h1>' . $h($T['u_title']) . '</h1><p>' . $h($T['u_test']) . '</p><a class="b g" href="' . $h($site) . '">' . $h($T['site']) . '</a>'); }
  $r = campRow($tok);
  if (!$r) { $T = campT(mLang()); mPage(mLang(), $T['u_title'], '<h1>' . $h($T['u_title']) . '</h1><p>' . $h($T['u_bad']) . '</p><a class="b g" href="' . $h($site) . '">' . $h($T['site']) . '</a>'); }
  $q = db()->prepare('SELECT data FROM leads WHERE id = ?'); $q->execute([$r['lead_id']]); $L = json_decode((string)$q->fetchColumn(), true);
  $lang = is_array($L) ? campLang($L) : mLang(); $T = campT($lang); $e = $h($r['email']);
  $self = 'm.php?u=' . $h($tok);
  if ($post) {
    $again = ($_POST['a'] ?? '') === 're';
    campUnsub($tok, $again);
    if (($_POST['List-Unsubscribe'] ?? '') === 'One-Click') { http_response_code(200); header('Content-Type: text/plain'); echo 'ok'; exit; } // «Unsubscribe» button of Gmail, Apple Mail…
    if ($again) mPage($lang, $T['u_title'], '<h1>✓</h1><p>' . $h($T['u_back']) . '</p><a class="b g" href="' . $h($site) . '">' . $h($T['site']) . '</a>');
    mPage($lang, $T['u_title'], '<h1>' . $h($T['u_title']) . '</h1><p>' . str_replace('{e}', '<b>' . $e . '</b>', $h($T['u_done'])) . '</p>'
      . '<p class="m">' . $h($T['u_again']) . '</p><form method="post" action="' . $self . '"><input type="hidden" name="a" value="re"><button class="g" type="submit">' . $h($T['u_re']) . '</button></form>');
  }
  /* a link scanner opening the page does not unsubscribe anyone: it takes the button */
  if ((int)$r['unsub_at'] > 0) mPage($lang, $T['u_title'], '<h1>' . $h($T['u_title']) . '</h1><p>' . str_replace('{e}', '<b>' . $e . '</b>', $h($T['u_done'])) . '</p>'
      . '<p class="m">' . $h($T['u_again']) . '</p><form method="post" action="' . $self . '"><input type="hidden" name="a" value="re"><button class="g" type="submit">' . $h($T['u_re']) . '</button></form>');
  mPage($lang, $T['u_title'], '<h1>' . $h($T['u_title']) . '</h1><p>' . str_replace('{e}', '<b>' . $e . '</b>', $h($T['u_ask'])) . '</p>'
    . '<form method="post" action="' . $self . '"><button type="submit">' . $h($T['u_btn']) . '</button></form>');
}

/* sign-up form for the website: <iframe src="https://prenota.osteriadvor.com/m.php?iscriviti&lang=it"> */
if (isset($_GET['iscriviti'])) {
  $lang = mLang(); $T = campT($lang); $embed = isset($_GET['embed']) || isset($_GET['iscriviti']) && ($_GET['iscriviti'] === 'embed');
  $form = function (string $msg = '', string $name = '', string $email = '') use ($h, $T, $lang, $embed) {
    return '<h1>' . $h($T['s_title']) . '</h1><p>' . $h($T['s_text']) . '</p>' . ($msg !== '' ? '<p><b>' . $h($msg) . '</b></p>' : '')
      . '<form method="post" action="m.php?iscriviti' . ($embed ? '=embed' : '') . '&amp;lang=' . $lang . '"><label>' . $h($T['s_name']) . '<input name="name" autocomplete="given-name" maxlength="80" value="' . $h($name) . '"></label>'
      . '<label>' . $h($T['s_email']) . '<input name="email" type="email" required autocomplete="email" maxlength="160" value="' . $h($email) . '"></label>'
      . '<label class="hp" aria-hidden="true">Website<input name="website" tabindex="-1" autocomplete="off"></label>'
      . '<button type="submit">' . $h($T['s_ok']) . '</button><p class="m" style="margin-top:1rem">' . $h($T['s_priv']) . '</p></form>';
  };
  if ($post) {
    $email = strtolower(trim((string)($_POST['email'] ?? ''))); $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 80);
    if (trim((string)($_POST['website'] ?? '')) !== '') mPage($lang, $T['s_title'], '<h1>✓</h1><p>' . $h(str_replace('{e}', $email, $T['s_sent'])) . '</p>', $embed); // a bot filled the hidden field
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 160) mPage($lang, $T['s_title'], $form($T['s_err'], $name, $email), $embed);
    rateLimit('signup', 8, 3600);
    try { campSignup($C, $email, $name, $lang); } catch (Throwable $e) { error_log('dvor signup: ' . $e->getMessage()); }
    mPage($lang, $T['s_title'], '<h1>✓</h1><p>' . str_replace('{e}', '<b>' . $h($email) . '</b>', $h($T['s_sent'])) . '</p>', $embed);
  }
  mPage($lang, $T['s_title'], $form(), $embed);
}
if (isset($_GET['k'])) {
  $lang = mLang(); $T = campT($lang);
  $ok = campSignupConfirm((string)($_GET['e'] ?? ''), (string)$_GET['k']);
  mPage($lang, $T['s_title'], $ok ? '<h1>✓</h1><p>' . $h($T['s_conf']) . '</p><a class="b" href="' . $h($C['booking_url'] ?? $site) . '">' . $h($T['book']) . '</a> <a class="b g" href="' . $h($site) . '">' . $h($T['site']) . '</a>'
    : '<h1>' . $h($T['s_title']) . '</h1><p>' . $h($T['u_bad']) . '</p>');
}
header('Location: ' . $site, true, 302);

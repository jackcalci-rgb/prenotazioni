<?php
/* Osteria Dvor · collega la pagina Eventi del sito principale (osteriadvor.com/eventi.html) agli eventi dell'area staff.
   La lancia aggiorna.sh a ogni aggiornamento; si può rilanciare quante volte si vuole (se è già collegata non tocca nulla).
   Uso (come root o come utente del sito):  php sito-eventi.php /home/UTENTE/public_html/eventi.html [cartella-copie]
   La pagina continua a leggere i suoi eventi (data/events.json) e in più legge prenota.osteriadvor.com/evento.php?json=1:
   un evento nuovo dell'area staff compare da solo; quelli che il sito ha già restano come sono.
   Poi prepara eventi.html e la home (index.html nella stessa cartella) per gli eventi cancellati: striscia rossa «Cancellato»,
   e aggiunge alle pagine del sito l'avviso a comparsa avviso-eventi.js (copiato da strumenti/sito/). */
declare(strict_types=1);
$f = $argv[1] ?? ''; $bk = $argv[2] ?? dirname($f);
if ($f === '' || !is_file($f)) { fwrite(STDERR, "Non trovo $f\n"); exit(1); }
$s = (string)file_get_contents($f);
if (str_contains($s, 'evento.php?json=1')) echo "eventi.html del sito: già collegata agli eventi dell'area staff\n";
else {
$R = [  [<<<'EOT'
function load(cb){fetch('data/events.json',{cache:'no-cache'}).then(function(r){return r.ok?r.json():SEED}).catch(function(){return SEED}).then(function(j){
  var t=today(),ev=(Array.isArray(j)?j:[]).filter(
EOT, <<<'EOT'
/* eventi del sito (data/events.json) + eventi creati nell'area staff di prenota.osteriadvor.com (evento.php?json=1).
   Un evento che c'è già qui (stessa data, titolo simile) resta com'è qui: dalle prenotazioni prende solo i campi vuoti e il link. */
var PREN='https://prenota.osteriadvor.com/evento.php?json=1';
function nrm(s){return String(s||'').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g,'').replace(/[^a-z0-9]+/g,' ').trim()}
function same(a,b){if(a.date!==b.date)return false;var x=nrm(a.title_it||a.title_en),y=nrm(b.title_it||b.title_en);if(!x||!y)return true;if(x===y||x.indexOf(y)>=0||y.indexOf(x)>=0)return true;
  var w=y.split(' ').filter(function(z){return z.length>=4});return x.split(' ').filter(function(z){return z.length>=4&&w.indexOf(z)>=0}).length>0}
function merge(site,pren){var out=site.slice();
  pren.forEach(function(p){if(!p||!p.date)return;var s=null;for(var i=0;i<out.length;i++)if(!out[i]._p&&same(out[i],p)){s=out[i];break}
    if(!s){var n=Object.assign({},p);n._p=1;out.push(n);return}
    ['title_it','title_en','desc_it','desc_en','price','time','flyer'].forEach(function(k){if(!s[k]&&p[k])s[k]=p[k]});
    if(!s.link)s.link=p.link;s.page=p.page;s.alias=p.id;s._p=1});
  return out}
function getJ(u,fb){return fetch(u,{cache:'no-cache'}).then(function(r){return r.ok?r.json():fb}).catch(function(){return fb})}
function load(cb){Promise.all([getJ('data/events.json',SEED),Promise.race([getJ(PREN,[]),new Promise(function(ok){setTimeout(function(){ok([])},5000)})])]).then(function(x){
  var j=merge(Array.isArray(x[0])?x[0]:SEED,Array.isArray(x[1])?x[1]:[]);
  var t=today(),ev=(Array.isArray(j)?j:[]).filter(
EOT],
  [<<<'EOT'
if(r.flyer)e.image=[o+upf(r.flyer)];
EOT, <<<'EOT'
if(r.flyer)e.image=[/^https?:/.test(r.flyer)?r.flyer:o+upf(r.flyer)];
EOT],
  [<<<'EOT'
load(function(u,p){UP=u;PAST=p;render();ld();if(location.hash){var t=document.getElementById(location.hash.slice(1));if(t)t.scrollIntoView()}});
EOT, <<<'EOT'
load(function(u,p){UP=u;PAST=p;render();ld();if(location.hash){var hs=location.hash.slice(1),t=document.getElementById(hs);
  if(!t)UP.concat(PAST).some(function(r){if('ev-'+r.alias===hs){t=document.getElementById('ev-'+r.id);return true}});if(t)t.scrollIntoView()}});
EOT],
];
foreach ($R as [$a, $b]) {
  if (substr_count($s, $a) !== 1) { fwrite(STDERR, "eventi.html del sito: la pagina è cambiata e non so dove collegare gli eventi. Manda questo messaggio a Claude.\n"); exit(2); }
  $s = str_replace($a, $b, $s);
}
writeSite($f, $s, $bk);
echo "eventi.html del sito: collegata agli eventi dell'area staff\n";
}

/* eventi cancellati: striscia rossa «Cancellato» e niente pulsante «Prenota», su eventi.html e sulla home (index.html accanto) */
$CSS = <<<'EOT'
<style id="evx-css">
.evx{position:relative;overflow:hidden}
.evx>.evrib{position:absolute;z-index:5;top:1.6rem;left:-3.8rem;width:14rem;transform:rotate(-35deg);background:#B3261E;color:#fff;text-align:center;font:500 .78rem/2.3 var(--sans,"Jost",Arial,sans-serif);letter-spacing:.28em;text-transform:uppercase;box-shadow:0 3px 12px rgba(0,0,0,.3);pointer-events:none}
.evfeat.evx>.evrib{top:2.2rem;left:-4.4rem;width:18rem;font-size:.9rem;line-height:2.6}
.evx .evimg{filter:grayscale(.55)}
.evx h2,.evx h3{text-decoration:line-through;text-decoration-color:#B3261E;text-decoration-thickness:2px}
@media (max-width:820px){.evfeat.evx>.evrib{top:1.5rem;left:-3.8rem;width:14rem;font-size:.78rem;line-height:2.3}}
</style>
</head>
EOT;
$JS = <<<'EOT'
var RIB={it:'Cancellato',en:'Cancelled',de:'Abgesagt',sl:'Odpovedano',nl:'Geannuleerd'};
function rib(){return el('span','evrib',RIB[L()]||RIB.it)}
function onLang(f){
EOT;
$common = [
  ['</head>', $CSS],
  ['function onLang(f){', $JS],
  ['if(!s.link)s.link=p.link;s.page=p.page;', 'if(p.cancelled)s.cancelled=1;if(!s.link)s.link=p.link;s.page=p.page;'],
];
$pages = [
  $f => [
    ["function card(r,past){var c=el('article','evc'+(past?' evpast':''));", "function card(r,past){var c=el('article','evc'+(past?' evpast':'')+(r.cancelled?' evx':''));if(r.cancelled)c.appendChild(rib());"],
    ["if(!past)b.appendChild(el('span','evcd',cd(r.date)));", "if(!past&&!r.cancelled)b.appendChild(el('span','evcd',cd(r.date)));"],
    ["if(!past){var a=el('a','btn fill',T('book'))", "if(!past&&!r.cancelled){var a=el('a','btn fill',T('book'))"],
    ["var f=el('article','evfeat');", "var f=el('article','evfeat'+(r.cancelled?' evx':''));if(r.cancelled)f.appendChild(rib());"],
    ["top.appendChild(el('span','evcd',cd(r.date)));", "if(!r.cancelled)top.appendChild(el('span','evcd',cd(r.date)));"],
    ["bt.appendChild(a);var m=menuBtn(r);", "if(!r.cancelled)bt.appendChild(a);var m=menuBtn(r);"],
    ["eventStatus:'https://schema.org/EventScheduled'", "eventStatus:r.cancelled?'https://schema.org/EventCancelled':'https://schema.org/EventScheduled'"],
  ],
  dirname($f) . '/index.html' => [
    ["function mini(r){var c=el('a','evc');", "function mini(r){var c=el('a','evc'+(r.cancelled?' evx':''));if(r.cancelled)c.appendChild(rib());"],
    ["b.appendChild(el('span','evcd',cd(r.date)));b.appendChild(el('h3',null,ttl(r)));", "if(!r.cancelled)b.appendChild(el('span','evcd',cd(r.date)));b.appendChild(el('h3',null,ttl(r)));"],
  ],
];
foreach ($pages as $file => $own) {
  $name = basename($file);
  if (!is_file($file)) { echo "$name del sito: non trovata, salto la striscia «Cancellato»\n"; continue; }
  $s = (string)file_get_contents($file);
  if (str_contains($s, 'id="evx-css"')) { echo "$name del sito: eventi cancellati già pronti\n"; continue; }
  if (!str_contains($s, 'evento.php?json=1')) { echo "$name del sito: non legge gli eventi dell'area staff, salto\n"; continue; }
  $ok = true;
  foreach (array_merge($common, $own) as [$a, $b]) {
    if (substr_count($s, $a) !== 1) { $ok = false; break; }
    $s = str_replace($a, $b, $s);
  }
  if (!$ok) { fwrite(STDERR, "$name del sito: la pagina è cambiata e non so dove mettere la striscia «Cancellato». Manda questo messaggio a Claude.\n"); continue; }
  writeSite($file, $s, $bk);
  echo "$name del sito: pronta per gli eventi cancellati (striscia rossa)\n";
}

/* avviso a comparsa sul sito quando un evento è annullato: copia avviso-eventi.js e lo aggiunge alle pagine che hanno notice.js */
$dir = dirname($f); $js = __DIR__ . '/sito/avviso-eventi.js'; $dst = $dir . '/avviso-eventi.js';
if (is_file($js)) {
  if (!is_file($dst) || md5_file($js) !== md5_file($dst)) { copy($js, $dst); @chown($dst, (int)fileowner($f)); @chgrp($dst, (int)filegroup($f)); echo "avviso-eventi.js del sito: aggiornato\n"; }
  $tag = '<script src="avviso-eventi.js" defer></script>';
  $n = 0;
  foreach (glob($dir . '/*.html') ?: [] as $page) {
    $s = (string)file_get_contents($page);
    if (str_contains($s, 'avviso-eventi.js')) continue;
    if (substr_count($s, '<script src="notice.js" defer></script>') === 1) $s = str_replace('<script src="notice.js" defer></script>', $tag . "\n" . '<script src="notice.js" defer></script>', $s);
    elseif (substr_count($s, '</body>') === 1 && (str_contains($s, 'evento.php?json=1') || basename($page) === 'index.html')) $s = str_replace('</body>', $tag . "\n</body>", $s);
    else continue;
    if (file_put_contents($page, $s) !== false) $n++;
  }
  if ($n) echo "Avviso eventi annullati aggiunto a $n pagine del sito\n";
}

function writeSite(string $file, string $s, string $bk): void {
  if (!is_dir($bk)) @mkdir($bk, 0700, true);
  $copy = rtrim($bk, '/') . '/' . basename($file) . '.' . date('Ymd-His');
  copy($file, $copy);
  if (file_put_contents($file, $s) === false) { fwrite(STDERR, "Non riesco a scrivere $file\n"); exit(1); }
  echo "(copia di prima: $copy)\n";
}

<?php
/* Osteria Dvor · collega la pagina Eventi del sito principale (osteriadvor.com/eventi.html) agli eventi dell'area staff.
   La lancia aggiorna.sh a ogni aggiornamento; si può rilanciare quante volte si vuole (se è già collegata non tocca nulla).
   Uso (come root o come utente del sito):  php sito-eventi.php /home/UTENTE/public_html/eventi.html [cartella-copie]
   La pagina continua a leggere i suoi eventi (data/events.json) e in più legge prenota.osteriadvor.com/evento.php?json=1:
   un evento nuovo dell'area staff compare da solo; quelli che il sito ha già restano come sono. */
declare(strict_types=1);
$f = $argv[1] ?? ''; $bk = $argv[2] ?? dirname($f);
if ($f === '' || !is_file($f)) { fwrite(STDERR, "Non trovo $f\n"); exit(1); }
$s = (string)file_get_contents($f);
if (str_contains($s, 'evento.php?json=1')) { echo "eventi.html del sito: già collegata agli eventi dell'area staff\n"; exit(0); }
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
if (!is_dir($bk)) @mkdir($bk, 0700, true);
$copy = rtrim($bk, '/') . '/eventi.html.' . date('Ymd-His');
copy($f, $copy);
if (file_put_contents($f, $s) === false) { fwrite(STDERR, "Non riesco a scrivere $f\n"); exit(1); }
echo "eventi.html del sito: collegata agli eventi dell'area staff (copia di prima: $copy)\n";

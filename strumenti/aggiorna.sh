#!/bin/bash
# Osteria Dvor · aggiorna prenota.osteriadvor.com all'ultima versione su GitHub.
# Uso (come root sul VPS):
#   dvor-aggiorna              aggiorna (dopo la prima volta basta questo)
#   dvor-aggiorna indietro     torna alla versione di prima dell'ultimo aggiornamento
# La prima volta:  bash /home/*/domains/prenota.osteriadvor.com/public_html/strumenti/aggiorna.sh
#
# Non tocca config.php, le immagini caricate (uploads/) e i dati: le prenotazioni stanno nel database, e prima di ogni
# aggiornamento ne fa una copia in ../backup-prenota (fuori dalla parte pubblica, tiene le ultime 15).
# Se dopo l'aggiornamento la pagina non risponde, rimette da solo la versione di prima.
# Tutto è dentro main(): bash legge lo script intero prima di partire, così l'aggiornamento può sostituire anche questo file.

REPO="${DVOR_REPO:-https://github.com/jackcalci-rgb/prenotazioni.git}"
SITE="${DVOR_SITE:-https://prenota.osteriadvor.com}"

main() {
  set -e
  [ "$(id -u)" = 0 ] || { echo "Lancialo come root (sudo)."; exit 1; }
  local PUB
  PUB=$(cd "$(dirname "$(readlink -f "$0")")/.." 2>/dev/null && pwd)
  [ -f "$PUB/config.php" ] || PUB=$(ls -d /home/*/domains/prenota.osteriadvor.com/public_html 2>/dev/null | head -1)
  [ -n "$PUB" ] && [ -f "$PUB/config.php" ] || { echo "Non trovo la cartella di prenota.osteriadvor.com con config.php."; exit 1; }
  OWNER=$(stat -c %U "$PUB"); DIR=$(dirname "$PUB"); BK="$DIR/backup-prenota"
  cd "$PUB"
  mkdir -p "$BK"; chmod 700 "$BK"
  command -v git >/dev/null || dnf install -y -q git
  g() { sudo -u "$OWNER" git -c safe.directory="$PUB" "$@"; }

  # comando corto per le prossime volte
  if [ ! -x /usr/local/bin/dvor-aggiorna ] || ! grep -q "$PUB" /usr/local/bin/dvor-aggiorna; then
    printf '#!/bin/bash\nexec bash %q "$@"\n' "$PUB/strumenti/aggiorna.sh" > /usr/local/bin/dvor-aggiorna
    chmod 755 /usr/local/bin/dvor-aggiorna
  fi

  # cartella copiata a mano (senza git): copia di sicurezza e collegamento a GitHub, i file del server restano
  if [ ! -d .git ]; then
    echo "Collego la cartella a GitHub (copia dei file in $BK/file-prima-di-git.tar.gz)"
    tar -czf "$BK/file-prima-di-git.tar.gz" -C "$DIR" "$(basename "$PUB")"
    g init -q -b main; g remote add origin "$REPO"
  fi

  if [ "${1:-}" = "indietro" ]; then
    local PREV; PREV=$(cat "$BK/versione-precedente" 2>/dev/null || true)
    [ -n "$PREV" ] || { echo "Non c'è una versione precedente salvata."; exit 1; }
    g reset -q --hard "$PREV"
    echo "Tornato alla versione $(g log -1 --format='%h · %s · %cd' --date=format:'%d/%m %H:%M')"
    echo "Il database non è stato toccato. Le copie sono in $BK."
    exit 0
  fi

  echo "Scarico da GitHub…"
  local i; for i in 1 2 3; do g fetch -q origin main && break; [ $i = 3 ] && { echo "GitHub non risponde, riprova tra poco."; exit 1; }; sleep 3; done
  local OLD; OLD=$(g rev-parse -q --verify HEAD || true)
  if [ -n "$OLD" ] && [ "$OLD" = "$(g rev-parse origin/main)" ]; then echo "Sei già all'ultima versione ($(g log -1 --format='%h · %s'))."; finish; exit 0; fi
  [ -n "$OLD" ] && { echo "Novità:"; g log --oneline "$OLD..origin/main" || true; }

  backup_db

  [ -n "$OLD" ] && echo "$OLD" > "$BK/versione-precedente"
  g reset -q --hard origin/main
  echo "Aggiornato alla versione $(g log -1 --format='%h · %s · %cd' --date=format:'%d/%m %H:%M')"

  # controllo: i file PHP si leggono e la pagina risponde; se no, si torna alla versione di prima
  local bad=""
  for f in api.php db.php push.php mailing.php m.php evento.php strumenti/*.php; do php -l "$f" >/dev/null 2>&1 || bad="$bad $f"; done
  if [ -z "$bad" ] && ! curl -fsS -m 20 -X POST -H 'Content-Type: application/json' -d '{"op":"info"}' "$SITE/api.php" 2>/dev/null | grep -q '"staff"'; then bad="la pagina non risponde ($SITE/api.php)"; fi
  if [ -n "$bad" ]; then
    echo "ATTENZIONE: qualcosa non va dopo l'aggiornamento:$bad"
    if [ -n "$OLD" ]; then g reset -q --hard "$OLD"; echo "Rimessa la versione di prima ($(g log -1 --format='%h · %s')). Manda questo messaggio a Claude."; fi
    exit 1
  fi

  finish
  echo; echo "Fatto: $SITE  (area staff: $SITE/#staff)"
}

backup_db() {
  local H N U P F
  H=$(cfg host); N=$(cfg name); U=$(cfg user); P=$(cfg pass)
  if [ -z "$U" ] || [ -z "$N" ]; then echo "(Database non ancora configurato: nessuna copia)"; return 0; fi
  F="$BK/database-$(date +%Y%m%d-%H%M%S).sql.gz"
  if MYSQL_PWD="$P" mysqldump -h "${H:-localhost}" -u "$U" --single-transaction --no-tablespaces "$N" 2>/dev/null | gzip > "$F" && [ "$(zcat "$F" | head -c 100 | wc -c)" -gt 0 ]; then
    chmod 600 "$F"; echo "Copia del database: $F"
    ls -1t "$BK"/database-*.sql.gz 2>/dev/null | tail -n +16 | xargs -r rm -f
  else
    rm -f "$F"; echo "Non riesco a copiare il database: aggiornamento fermato, niente è cambiato."; exit 1
  fi
}
cfg() { php -r '$c = require "config.php"; echo $c["db"][$argv[1]] ?? "";' "$1"; }

# passi che si possono rilanciare senza danni
finish() {
  # dalla versione con MySQL: se config.php non ha ancora il database, lo prepara e importa i file JSON
  [ -n "$(cfg user)" ] || bash "$PUB/strumenti/database.sh"
  # via le prove (prenotazioni finte, lista nera di prova) e copertina di San Martino
  sudo -u "$OWNER" php "$PUB/strumenti/vai-live.php" || echo "Attenzione: pulizia delle prove non riuscita, rilancia: sudo -u $OWNER php $PUB/strumenti/vai-live.php"
  # promemoria email prima della prenotazione: cron ogni 15 minuti (una volta sola)
  if [ -d /etc/cron.d ] && [ ! -f /etc/cron.d/dvor-promemoria ]; then
    echo "*/15 * * * * $OWNER $(command -v php) $PUB/strumenti/promemoria.php >/dev/null 2>&1" > /etc/cron.d/dvor-promemoria
    chmod 644 /etc/cron.d/dvor-promemoria; echo "Promemoria email attivati (/etc/cron.d/dvor-promemoria)"
  fi
  # pagina Eventi del sito principale (stesso server): legge anche gli eventi dell'area staff, così ogni evento nuovo compare da solo
  local SITO_EV="" c
  for c in /home/*/public_html/eventi.html /home/*/domains/osteriadvor.com/public_html/eventi.html /home/*/domains/www.osteriadvor.com/public_html/eventi.html /var/www/osteriadvor.com/eventi.html /var/www/html/eventi.html; do
    [ -f "$c" ] && grep -q 'id="evjs"' "$c" && { SITO_EV="$c"; break; }
  done
  if [ -n "$SITO_EV" ]; then php "$PUB/strumenti/sito-eventi.php" "$SITO_EV" "$BK" || true
  else echo "Non trovo eventi.html del sito principale su questo server: gli eventi nuovi non compaiono da soli su osteriadvor.com/eventi.html."; fi
  # campagne email: la coda parte ogni minuto; i rimbalzi si leggono dal registro di Postfix (solo root) ogni 10 minuti
  if [ -d /etc/cron.d ] && [ ! -f /etc/cron.d/dvor-campagne ]; then
    printf '%s\n' "* * * * * $OWNER $(command -v php) $PUB/strumenti/campagne.php >/dev/null 2>&1" "*/10 * * * * root $(command -v php) $PUB/strumenti/rimbalzi.php >/dev/null 2>&1" > /etc/cron.d/dvor-campagne
    chmod 644 /etc/cron.d/dvor-campagne; echo "Invio campagne email e rimbalzi attivati (/etc/cron.d/dvor-campagne)"
  fi
}

main "$@"; exit

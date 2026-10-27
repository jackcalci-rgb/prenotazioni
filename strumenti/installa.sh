#!/bin/bash
# Osteria Dvor · prima installazione su prenota.osteriadvor.com da GitHub
# Uso (come root sul VPS):  bash installa.sh
# Copia la configurazione email già funzionante da prenotazioni.profumidistria.com (se c'è).
set -e
REPO="https://github.com/jackcalci-rgb/prenotazioni.git"
NOTIFY="info@osteriadvor.com"          # chi riceve la copia di ogni prenotazione (più indirizzi separati da virgola)
REPLY="info@osteriadvor.com"           # dove arrivano le risposte dei clienti
FROM="prenotazioni@osteriadvor.com"    # mittente delle email
OLD=/home/profumidistria/domains/prenotazioni.profumidistria.com

PUB=$(ls -d /home/*/domains/prenota.osteriadvor.com/public_html 2>/dev/null | head -1)
[ -z "$PUB" ] && { echo "Non trovo la cartella di prenota.osteriadvor.com: crea prima il sub-server in Virtualmin."; exit 1; }
DIR=$(dirname "$PUB"); OWNER=$(stat -c %U "$PUB")
echo "Installo in $PUB (utente $OWNER)"
command -v git >/dev/null || dnf install -y git

# 1. codice da GitHub (config.php e dati restano fuori da git)
cd "$PUB"
if [ ! -d .git ]; then
  sudo -u "$OWNER" git init -q -b main
  sudo -u "$OWNER" git remote add origin "$REPO"
fi
sudo -u "$OWNER" git fetch -q origin main
sudo -u "$OWNER" git reset -q --hard origin/main

# 2. configurazione: dalla prova su profumidistria se esiste, altrimenti dall'esempio
if [ ! -f config.php ]; then
  if [ -f "$OLD/public_html/config.php" ]; then cp "$OLD/public_html/config.php" config.php; else cp config.example.php config.php; fi
  sed -i -E \
    -e "s#('mail_from' *=> *)'[^']*'#\1'$FROM'#" \
    -e "s#('notify_to' *=> *)'[^']*'#\1'$NOTIFY'#" \
    -e "s#('reply_to' *=> *)'[^']*'#\1'$REPLY'#" \
    -e "s#('secret' *=> *)'[^']*'#\1'$(openssl rand -hex 32)'#" \
    -e "s#('data_dir' *=> *)[^,]*,#\1'$DIR/prenota-data',#" config.php
  grep -q "'booking_url'" config.php || sed -i "s#^\(  'reply_to'.*\)#\1\n  'site_url'    => 'https://www.osteriadvor.com',\n  'booking_url' => 'https://prenota.osteriadvor.com',#" config.php
  sed -i -E "s#('booking_url' *=> *)'[^']*'#\1'https://prenota.osteriadvor.com'#" config.php
fi

# 3. archivio dati fuori dalla parte pubblica: eventi e impostazioni dalla prova, niente prenotazioni di prova
mkdir -p "$DIR/prenota-data"
for f in events.json settings.json overrides.json; do
  [ -f "$OLD/prenota-data/$f" ] && [ ! -f "$DIR/prenota-data/$f" ] && cp "$OLD/prenota-data/$f" "$DIR/prenota-data/$f" || true
done
chown -R "$OWNER:$OWNER" "$PUB" "$DIR/prenota-data"; chmod 750 "$DIR/prenota-data"; chmod 640 config.php

echo; echo "== Configurazione =="
grep -E "staff_password|mail_from'|notify_to|reply_to|site_url|booking_url|data_dir|smtp_host|smtp_port" config.php
echo; echo "== DNS di osteriadvor.com (da mandare a Claude) =="
echo "NS:    $(dig +short NS osteriadvor.com @8.8.8.8 | tr '\n' ' ')"
echo "SPF:   $(dig +short TXT osteriadvor.com @8.8.8.8 | grep -i spf)"
echo "DMARC: $(dig +short TXT _dmarc.osteriadvor.com @8.8.8.8)"
echo "DKIM:  $(dig +short TXT 202409._domainkey.osteriadvor.com @8.8.8.8 | head -c 60)"
echo "prenota A: $(dig +short A prenota.osteriadvor.com @8.8.8.8)"
echo; echo "Fatto: https://prenota.osteriadvor.com  (area staff: /#staff)"
echo "Per gli aggiornamenti futuri: bash $PUB/strumenti/aggiorna.sh"

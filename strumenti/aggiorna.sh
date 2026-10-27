#!/bin/bash
# Osteria Dvor · scarica l'ultima versione da GitHub. config.php e prenotazioni non vengono toccati.
# Uso (come root sul VPS):  bash /home/…/prenota.osteriadvor.com/public_html/strumenti/aggiorna.sh
set -e
PUB=$(cd "$(dirname "$0")/.." && pwd); OWNER=$(stat -c %U "$PUB")
cd "$PUB"
sudo -u "$OWNER" git fetch -q origin main
echo "Novità:"; sudo -u "$OWNER" git log --oneline HEAD..origin/main || true
sudo -u "$OWNER" git reset -q --hard origin/main
echo "Aggiornato alla versione $(sudo -u "$OWNER" git log -1 --format='%h · %s · %cd' --date=format:'%d/%m %H:%M')"
# dalla versione con MySQL: se config.php non ha ancora il database, lo prepara e importa i file JSON
php -r '$c = require "config.php"; exit(empty($c["db"]["user"]) ? 1 : 0);' || bash "$PUB/strumenti/database.sh"

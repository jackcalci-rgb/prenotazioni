#!/bin/bash
# Osteria Dvor · prepara il database MySQL/MariaDB e ci copia le prenotazioni dei vecchi file JSON.
# Uso (come root sul VPS):  bash /home/…/prenota.osteriadvor.com/public_html/strumenti/database.sh
# Si può rilanciare: se config.php ha già il database, fa solo l'importazione (che non tocca le righe già presenti).
set -e
DB="prenota_dvor"; DBUSER="prenota_dvor"
PUB=$(cd "$(dirname "$0")/.." && pwd); OWNER=$(stat -c %U "$PUB")
DOM=$(basename "$(dirname "$PUB")")   # /home/x/domains/prenota.osteriadvor.com/public_html → prenota.osteriadvor.com
cd "$PUB"
[ -f config.php ] || { echo "Manca config.php: lancia prima installa.sh"; exit 1; }
cfg() { php -r '$c = require "config.php"; echo $c["db"][$argv[1]] ?? "";' "$1"; }

if [ -z "$(cfg user)" ]; then
  # accesso da root a MariaDB: prima diretto, poi con la password che Virtualmin tiene in Webmin
  MY=(mysql)
  if ! mysql -e 'SELECT 1' >/dev/null 2>&1; then
    L=$(grep -s '^login=' /etc/webmin/mysql/config | cut -d= -f2-); export MYSQL_PWD=$(grep -s '^pass=' /etc/webmin/mysql/config | cut -d= -f2-)
    MY=(mysql -u "${L:-root}")
    "${MY[@]}" -e 'SELECT 1' >/dev/null 2>&1 || { echo "Non riesco a entrare in MySQL come root. Crea il database a mano in Virtualmin (vedi LEGGIMI.md) e compila la sezione db di config.php."; exit 1; }
  fi
  PASS=$(openssl rand -hex 16)
  # database intestato al server virtuale, così finisce anche nei backup di Virtualmin
  virtualmin create-database --domain "$DOM" --name "$DB" --type mysql >/dev/null 2>&1 \
    || virtualmin create-database --domain "${DOM#*.}" --name "$DB" --type mysql >/dev/null 2>&1 || true
  "${MY[@]}" -e "CREATE DATABASE IF NOT EXISTS \`$DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    CREATE USER IF NOT EXISTS '$DBUSER'@'localhost' IDENTIFIED BY '$PASS';
    ALTER USER '$DBUSER'@'localhost' IDENTIFIED BY '$PASS';
    GRANT ALL PRIVILEGES ON \`$DB\`.* TO '$DBUSER'@'localhost'; FLUSH PRIVILEGES;"
  if grep -q "^  'db' *=>" config.php; then
    sed -i -E -e "s#^(    'host' *=> *)'[^']*'#\1'localhost'#" -e "s#^(    'name' *=> *)'[^']*'#\1'$DB'#" \
      -e "s#^(    'user' *=> *)'[^']*'#\1'$DBUSER'#" -e "s#^(    'pass' *=> *)'[^']*'#\1'$PASS'#" config.php
  else
    sed -i "\$s#^];#  'db' => [\n    'host' => 'localhost',\n    'name' => '$DB',\n    'user' => '$DBUSER',\n    'pass' => '$PASS',\n  ],\n];#" config.php
  fi
  chown "$OWNER:$OWNER" config.php; chmod 640 config.php
  [ "$(cfg user)" = "$DBUSER" ] || { echo "Non sono riuscito a scrivere la sezione db in config.php: aggiungila a mano (vedi config.example.php)."; exit 1; }
  echo "Database $DB pronto, utente $DBUSER (password salvata in config.php)."
fi

sudo -u "$OWNER" php strumenti/importa-json.php

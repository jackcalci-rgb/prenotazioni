# Prenotazioni · Osteria Enoteca Dvor

Sistema di prenotazione tavoli di **prenota.osteriadvor.com**. Contiene la pagina per i clienti, l'area staff e le email di conferma con il file calendario.

## Cosa c'è qui

| File | A cosa serve |
|---|---|
| `index.html` | Pagina clienti e area staff (`/#staff`) |
| `api.php` | Server: prenotazioni, eventi, impostazioni, email |
| `db.php` | Collegamento al database MySQL/MariaDB (le tabelle si creano da sole) |
| `email-logo.png` | Logo nelle email |
| `config.example.php` | Modello della configurazione |
| `seed/` | Eventi e impostazioni iniziali, usati solo al primo avvio |
| `strumenti/installa.sh` | Prima installazione sul VPS |
| `strumenti/aggiorna.sh` | Scarica l'ultima versione da GitHub |
| `strumenti/database.sh` | Crea il database MySQL e ci copia i vecchi file JSON |
| `strumenti/importa-json.php` | Solo l'importazione dei file JSON nel database |

**Mai su GitHub:** `config.php` (password, dati email e database) e le prenotazioni restano solo sul server, nel database MySQL `prenota_dvor`.

## Database

Prenotazioni, eventi, impostazioni e lista nera stanno in MySQL/MariaDB, quello che Virtualmin ha già sul VPS. Quando due clienti prenotano gli ultimi posti nello stesso momento, il server blocca la giornata per un istante. Così solo il primo passa, e il secondo vede subito le alternative. Lo staff può sempre aggiungere tavoli a mano anche oltre i posti.

Tabelle (create da sole al primo avvio):

| Tabella | Contenuto |
|---|---|
| `bookings` | Una riga per prenotazione: data, ora, persone, stato e tutti i dati |
| `docs` | Eventi, impostazioni, aperture straordinarie, lista nera |
| `rate_hits` | Limite di richieste per IP |
| `day_locks` | Blocco per giornata durante il controllo dei posti |

### Prepararlo con lo script (consigliato)

Come root sul VPS:

```bash
bash /home/*/domains/prenota.osteriadvor.com/public_html/strumenti/database.sh
```

Lo script fa tre cose:
1. Crea il database `prenota_dvor` intestato al server virtuale, così entra nei backup di Virtualmin.
2. Crea l'utente `prenota_dvor` e ne scrive la password in `config.php`.
3. Copia nel database le prenotazioni, gli eventi e le impostazioni dei vecchi file JSON di `prenota-data`. I file restano lì come copia, e rilanciarlo non duplica nulla.

### Oppure a mano da Virtualmin

1. **Virtualmin → prenota.osteriadvor.com → Modifica database → Crea un nuovo database**: nome `prenota_dvor`, tipo MySQL.
2. Nella stessa pagina, scheda **Accesso/Password**, annota utente e password MySQL del server virtuale.
3. In `config.php` compila la sezione `db` (vedi `config.example.php`):
   ```php
   'db' => ['host' => 'localhost', 'name' => 'prenota_dvor', 'user' => '…', 'pass' => '…'],
   ```
4. Importa i vecchi dati: `sudo -u <utente> php strumenti/importa-json.php` dalla cartella `public_html`.

PHP deve avere l'estensione `pdo_mysql` (in Virtualmin c'è già; altrimenti `dnf install php-mysqlnd`).

## Prima installazione (VPS Contabo + Virtualmin)

1. In Virtualmin crea il sub-server `prenota.osteriadvor.com` e attiva SSL (Let's Encrypt).
2. Sul VPS, come root:
   ```bash
   curl -sO https://raw.githubusercontent.com/jackcalci-rgb/prenotazioni/main/strumenti/installa.sh
   bash installa.sh
   ```
   Lo script scarica il codice e copia la configurazione email dalla prova su profumidistria.com. Poi crea il database MySQL e mostra il DNS da controllare.
3. Controlla `config.php` (`staff_password`, `notify_to`, `reply_to`).

## Aggiornare

Quando su GitHub c'è una nuova versione:

```bash
bash /home/*/domains/prenota.osteriadvor.com/public_html/strumenti/aggiorna.sh
```

`config.php` e le prenotazioni non vengono toccati. Al primo aggiornamento alla versione con MySQL, se `config.php` non ha ancora il database, lo script lancia da solo `database.sh`. Se non succede, lancialo tu una volta.

## Email

- **Invio:** le email partono dal Postfix del VPS (`smtp_host` `127.0.0.1`, porta 25).
- **Messaggi automatici:** «Prenotazione confermata», «Prenotazione ricevuta» (per le cene in attesa), «Spostata» e «Annullata», ognuna con Google Calendar, Outlook e il file `dvor.ics`.
- **Copia allo staff:** va a `notify_to`.
- **Se un'email non arriva:**
  - `tail prenota-data/mail.log` dice se è partita dal programma.
  - `grep <email> /var/log/maillog | tail` mostra la risposta del destinatario (`status=sent` oppure il motivo del rifiuto).
- **DNS necessario:** SPF con l'IP del VPS, DKIM (selettore `202409`), DMARC, e reverse DNS `mail.profumidistria.com`.

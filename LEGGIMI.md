# Prenotazioni · Osteria Enoteca Dvor

Sistema di prenotazione tavoli di **prenota.osteriadvor.com**. Contiene la pagina per i clienti, l'area staff e le email di conferma con il file calendario.

## Cosa c'è qui

| File | A cosa serve |
|---|---|
| `index.html` | Pagina clienti e area staff (`/#staff`): «Oggi» con cruscotto di sala e arrivi, «Calendario», «+ Nuova», «Marketing» con i lead; dietro l'ingranaggio ⚙ interruttore dell'assistente, regole, lista nera ed eventi |
| `api.php` | Server: prenotazioni, eventi, impostazioni, email |
| `db.php` | Collegamento al database MySQL/MariaDB (le tabelle si creano da sole) |
| `email-logo.png` | Logo nelle email |
| `config.example.php` | Modello della configurazione |
| `seed/` | Eventi e impostazioni iniziali, usati solo al primo avvio |
| `strumenti/installa.sh` | Prima installazione sul VPS |
| `strumenti/aggiorna.sh` | Scarica l'ultima versione da GitHub e attiva il cron dei promemoria |
| `strumenti/vai-live.php` | Cancella le prenotazioni di prova rimaste dai test e mette la copertina di San Martino (lo lancia `aggiorna.sh`) |
| `strumenti/promemoria.php` | Manda i promemoria via email prima dell'arrivo (da cron) |
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
| `leads` | Un ospite per riga (stesso telefono o stessa email = un solo lead), per il marketing |

## Marketing (lead)

Nell'area staff, scheda **Marketing**:

- **Da soli:** ogni prenotazione (dal sito, dall'assistente, al telefono, su WhatsApp, di persona) aggiorna il lead dell'ospite con data, ora, persone, esito (venuto, no show, annullata), menù degustazione, evento, campagna di provenienza (`utm_source`, `utm_campaign`, `gclid`, `fbclid`) e consenso marketing.
- **Consenso (GDPR):** nel modulo dell'ospite c'è la casella facoltativa «Voglio ricevere novità…», non spuntata. Nel modulo «+ Nuova» lo staff la spunta solo se l'ospite ha detto di sì. Sul singolo lead si può cambiare (per esempio «No, non contattare») o eliminarlo, se qualcuno chiede di cancellare i suoi dati.
- **Importare i clienti passati:** file Excel `.xlsx` o CSV (anche righe incollate dal foglio). Le colonne si riconoscono da sole e si possono correggere prima di importare. Chi c'è già viene completato, non doppiato.
- **Esportare:** Excel (CSV con tutti i dati), Meta Ads (pubblico personalizzato) e Google Ads (Customer Match), sempre sui lead filtrati. Nei file per Meta e Google vanno solo i lead con consenso, a meno di spuntare l'opzione apposita; chi ha detto no resta sempre fuori.
- **Gruppi pronti:** abituali, venuti una volta, da riconquistare (6 mesi), con prenotazione in arrivo, nuovi, compleanno nei prossimi 30 giorni, menù degustazione, eventi, no show.

Al primo avvio della nuova versione la tabella `leads` si crea e si riempie da sola con le prenotazioni già nel database. Il pulsante «Ricalcola dalle prenotazioni» rifà lo stesso passaggio senza doppiare nulla.

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

A ogni aggiornamento `aggiorna.sh` lancia anche `vai-live.php`, che cancella le eventuali prenotazioni di prova (quelle create dal vecchio pulsante «Genera prenotazioni di prova») e mai quelle vere.

## Assistente (chatbot)

Dall'area staff, ⚙ → **Assistente**: un pulsante lo spegne e lo riaccende. Spento, gli ospiti prenotano dal calendario scegliendo giorno e orario, e il server rifiuta le richieste al chatbot. Serve la chiave `anthropic_api_key` in `config.php`; senza chiave l'assistente resta spento e il pulsante lo segnala.

## Orari settimanali

Dall'area staff, ⚙ → **Regole e offerte → Orari settimanali**: una riga per giorno con gli orari di arrivo prenotabili, per esempio `12:00-15:00, 19:00-21:30` (vuoto = chiuso, ogni 30 minuti). Si salvano con «Salva regole e orari» in `settings/main.windows` e valgono subito per la pagina, per l'assistente e per il bot WhatsApp, che legge le stesse impostazioni. Finché non si salvano restano gli orari di sempre.

## Modificare una prenotazione

- **Staff:** ogni prenotazione, nel Calendario e in «Oggi», ha il tasto **✎ Modifica**. Si cambiano giorno, ora, persone, nome, telefono, email, lingua, menù, note e stato (anche per riattivare un'annullata). La finestra avvisa se si supera la capienza o se l'orario è fuori dagli arrivi online, ma lascia salvare. La casella «Avvisa l'ospite via email» manda la mail «Spostata» o «Modificata» con il calendario aggiornato; tolta la spunta, l'ospite non riceve nulla.
- **Cliente:** in «Le tue prenotazioni» ha **✎ Modifica** e **Annulla**. Può cambiare giorno, orario, persone (fino a 20), menù e note fino all'ora della prenotazione. Vede solo gli orari ancora liberi per il nuovo numero di persone, e il server ricontrolla i posti prima di salvare. Riceve l'email «Spostata» o «Modificata», e lo staff riceve la copia a `notify_to`.
- **Da qualsiasi telefono:** ogni email all'ospite (conferma, ricevuta, spostata, modificata, promemoria) ha il pulsante **Modifica o annulla**, con un link personale firmato (`?gestisci=…`). Così il cliente gestisce la prenotazione anche da un dispositivo diverso da quello con cui ha prenotato.

## Email

- **Invio:** le email partono dal Postfix del VPS (`smtp_host` `127.0.0.1`, porta 25).
- **Messaggi automatici:** «Prenotazione confermata», «Prenotazione ricevuta» (per le cene in attesa), «Spostata», «Modificata» e «Annullata», ognuna con Google Calendar, Outlook e il file `dvor.ics`.
- **Promemoria:** 4 ore prima dell'arrivo parte all'ospite un'email «Promemoria» (le ore si cambiano dall'⚙, 0 = nessun promemoria). Non parte per chi ha prenotato o spostato dopo l'inizio della finestra, per le cene ancora in attesa e per i tavoli già arrivati. La manda `strumenti/promemoria.php`, che `aggiorna.sh` mette in cron ogni 15 minuti (`/etc/cron.d/dvor-promemoria`). Prova a mano: `sudo -u <utente> php strumenti/promemoria.php`.
- **Copia allo staff:** va a `notify_to`.
- **Se un'email non arriva:**
  - `tail prenota-data/mail.log` dice se è partita dal programma.
  - `grep <email> /var/log/maillog | tail` mostra la risposta del destinatario (`status=sent` oppure il motivo del rifiuto).
- **DNS necessario:** SPF con l'IP del VPS, DKIM (selettore `202409`), DMARC, e reverse DNS `mail.profumidistria.com`.

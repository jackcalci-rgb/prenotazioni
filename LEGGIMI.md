# Prenotazioni · Osteria Enoteca Dvor

Sistema di prenotazione tavoli di **prenota.osteriadvor.com**. Contiene la pagina per i clienti, l'area staff e le email di conferma con il file calendario.

## Cosa c'è qui

| File | A cosa serve |
|---|---|
| `index.html` | Pagina clienti e area staff (`/#staff`) |
| `api.php` | Server: prenotazioni, eventi, impostazioni, email |
| `email-logo.png` | Logo nelle email |
| `config.example.php` | Modello della configurazione |
| `seed/` | Eventi e impostazioni iniziali, usati solo al primo avvio |
| `strumenti/installa.sh` | Prima installazione sul VPS |
| `strumenti/aggiorna.sh` | Scarica l'ultima versione da GitHub |

**Mai su GitHub:** `config.php` (password, dati email) e le prenotazioni restano solo sul server, nella cartella `prenota-data` fuori dalla parte pubblica.

## Prima installazione (VPS Contabo + Virtualmin)

1. In Virtualmin crea il sub-server `prenota.osteriadvor.com` e attiva SSL (Let's Encrypt).
2. Sul VPS, come root:
   ```bash
   curl -sO https://raw.githubusercontent.com/jackcalci-rgb/prenotazioni/main/strumenti/installa.sh
   bash installa.sh
   ```
   Lo script scarica il codice e copia la configurazione email dalla prova su profumidistria.com. Poi prepara l'archivio dati e mostra il DNS da controllare.
3. Controlla `config.php` (`staff_password`, `notify_to`, `reply_to`).

## Aggiornare

Quando su GitHub c'è una nuova versione:

```bash
bash /home/*/domains/prenota.osteriadvor.com/public_html/strumenti/aggiorna.sh
```

`config.php` e le prenotazioni non vengono toccati.

## Email

- **Invio:** le email partono dal Postfix del VPS (`smtp_host` `127.0.0.1`, porta 25).
- **Messaggi automatici:** «Prenotazione confermata», «Prenotazione ricevuta» (per le cene in attesa), «Spostata» e «Annullata», ognuna con Google Calendar, Outlook e il file `dvor.ics`.
- **Copia allo staff:** va a `notify_to`.
- **Se un'email non arriva:**
  - `tail prenota-data/mail.log` dice se è partita dal programma.
  - `grep <email> /var/log/maillog | tail` mostra la risposta del destinatario (`status=sent` oppure il motivo del rifiuto).
- **DNS necessario:** SPF con l'IP del VPS, DKIM (selettore `202409`), DMARC, e reverse DNS `mail.profumidistria.com`.

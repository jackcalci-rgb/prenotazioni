<?php
/* Osteria Dvor · prenotazioni · configurazione
   Compila questi valori sul server. Questo file non è leggibile dal browser. */
return [
  // Password per entrare nell'area staff (pagina index.html#staff)
  'staff_password' => 'CAMBIAMI',

  // Una frase segreta lunga a caso (serve per firmare l'accesso staff)
  'secret' => 'CAMBIAMI-con-una-frase-lunga-e-casuale',

  // Email: mittente e copia al ristorante
  'mail_from'      => 'prenotazioni@profumidistria.com',
  'mail_from_name' => 'Osteria Enoteca Dvor',
  'notify_to'      => 'prenotazioni@profumidistria.com', // copia allo staff di ogni prenotazione, spostamento e disdetta; più indirizzi separati da virgola
  'reply_to'       => 'info@osteriadvor.com',

  // Link nelle email: sito del ristorante e pagina di prenotazione
  'site_url'    => 'https://www.osteriadvor.com',
  'booking_url' => 'https://prenotazioni.profumidistria.com',

  // Invio: 'smtp' (consigliato) oppure 'mail' (funzione mail() del server)
  'mail_method'   => 'smtp',
  'smtp_host'     => 'smtp.profumidistria.com',  // dal pannello dell'hosting
  'smtp_port'     => 465,                        // 465 = SSL, 587 = STARTTLS
  'smtp_secure'   => 'ssl',                      // 'ssl' oppure 'tls'
  'smtp_user'     => 'prenotazioni@profumidistria.com',
  'smtp_pass'     => '',

  // Facoltativo: chat dello chef. Senza chiave la chat non compare.
  'anthropic_api_key' => '',
  'anthropic_model'   => 'claude-haiku-5-5',

  // Durata del tavolo nel file calendario (minuti)
  // Cartella dei dati (prenotazioni). Meglio fuori dalla cartella pubblica, es. '/var/lib/dvor-prenota'
  'data_dir' => __DIR__ . '/data',

  'hold_min' => 120,
  'timezone' => 'Europe/Rome',
];

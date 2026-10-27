=== DB Stazione Meteo ===
Contributors: dadebertolino
Tags: meteo, weather, stazione meteo, lora, scuola
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Mostra sul sito della scuola le misure della stazione meteo LoRa, inviate dal Raspberry collegato al ricevitore.

== Description ==

I nodi LoRa misurano temperatura, umidità e pressione; il ricevitore le passa via USB a un Raspberry, che aggiunge data e ora e le invia al sito. Il plugin le conserva in una tabella dedicata e le mostra con lo shortcode `[dbmt_meteo]`.

Niente servizi esterni, niente registrazioni, niente nag, niente trucchi.

== Changelog ==

= 0.4.0 =
* Limite di richieste per ogni token di lettura: 60 all'ora se non indicato, scelto alla creazione del token (da 1 a 3600). Oltre il limite il sito risponde 429 con Retry-After fino all'ora successiva.
* Ogni risposta dell'API di lettura porta gli header X-RateLimit-Limit, X-RateLimit-Remaining e X-RateLimit-Reset, leggibili anche dalle applicazioni web di altri siti.

= 0.3.0 =
* API di lettura: GET /wp-json/dbmt/v1/dati (misure grezze, filtri per data e nodo, JSON o CSV, anche CSV per Excel in italiano, pagine fino a 5000 misure) e GET /wp-json/dbmt/v1/nodi.
* Token di lettura con un nome, separati da quello del bridge: si generano e si revocano uno per uno in Impostazioni → Stazione Meteo, che mostra anche l'ultimo utilizzo.

= 0.2.0 =
* Pulizia dati in Impostazioni → Stazione Meteo: elimina tutte le misure, o quelle ricevute prima di una data e ora (nel fuso del sito), di tutti i nodi o di uno solo, con conferma.
* La pressione si scrive senza separatore delle migliaia (1015,6 hPa), nella scheda e nei grafici.

= 0.1.0 =
* Prima versione.
* Endpoint POST /wp-json/dbmt/v1/misure per il bridge del Raspberry, con token (salvato solo come hash), controllo di ogni misura e doppioni ignorati.
* Impostazioni → Stazione Meteo: indirizzo per il bridge, token, quota della stazione, stato dei nodi, avviso se il fuso orario del sito è UTC.
* Shortcode [dbmt_meteo]: ultimi valori, pressione al livello del mare, minima e massima delle 24 ore, avviso se i dati non sono aggiornati.
* Shortcode [dbmt_grafici]: andamento a 24 ore, 7 giorni e 30 giorni, con tabella dei dati; SVG senza librerie esterne.

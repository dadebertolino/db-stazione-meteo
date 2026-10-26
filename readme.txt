=== DB Stazione Meteo ===
Contributors: dadebertolino
Tags: meteo, weather, stazione meteo, lora, scuola
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Mostra sul sito della scuola le misure della stazione meteo LoRa, inviate dal Raspberry collegato al ricevitore.

== Description ==

I nodi LoRa misurano temperatura, umidità e pressione; il ricevitore le passa via USB a un Raspberry, che aggiunge data e ora e le invia al sito. Il plugin le conserva in una tabella dedicata e le mostra con lo shortcode `[dbmt_meteo]`.

Niente servizi esterni, niente registrazioni, niente nag, niente trucchi.

== Changelog ==

= 0.2.0 =
* Pulizia dati in Impostazioni → Stazione Meteo: elimina tutte le misure, o quelle ricevute prima di una data e ora (nel fuso del sito), di tutti i nodi o di uno solo, con conferma.
* La pressione si scrive senza separatore delle migliaia (1015,6 hPa), nella scheda e nei grafici.

= 0.1.0 =
* Prima versione.
* Endpoint POST /wp-json/dbmt/v1/misure per il bridge del Raspberry, con token (salvato solo come hash), controllo di ogni misura e doppioni ignorati.
* Impostazioni → Stazione Meteo: indirizzo per il bridge, token, quota della stazione, stato dei nodi, avviso se il fuso orario del sito è UTC.
* Shortcode [dbmt_meteo]: ultimi valori, pressione al livello del mare, minima e massima delle 24 ore, avviso se i dati non sono aggiornati.
* Shortcode [dbmt_grafici]: andamento a 24 ore, 7 giorni e 30 giorni, con tabella dei dati; SVG senza librerie esterne.

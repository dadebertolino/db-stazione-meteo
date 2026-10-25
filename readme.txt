=== DB Stazione Meteo ===
Contributors: dadebertolino
Tags: meteo, weather, stazione meteo, lora, scuola
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Mostra sul sito della scuola le misure della stazione meteo LoRa, inviate dal Raspberry collegato al ricevitore.

== Description ==

I nodi LoRa misurano temperatura, umidità e pressione; il ricevitore le passa via USB a un Raspberry, che aggiunge data e ora e le invia al sito. Il plugin le conserva in una tabella dedicata e le mostra con lo shortcode `[dbmt_meteo]`.

Niente servizi esterni, niente registrazioni, niente nag, niente trucchi.

== Changelog ==

= 0.1.0 =
* Struttura iniziale.

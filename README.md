# DB Stazione Meteo

Mostra sul sito della scuola le misure della stazione meteo LoRa: ultimi valori e andamento.
Niente servizi esterni, niente registrazioni, niente abbonamenti.

**Versione:** 0.1.0 (in sviluppo)  
**Autore:** [Davide Bertolino](https://www.davidebertolino.it)  
**Licenza:** GPL v2 or later  
**Richiede:** WordPress 5.8+, PHP 7.4+  
**GitHub:** [dadebertolino/db-stazione-meteo](https://github.com/dadebertolino/db-stazione-meteo)  

---

## Come arrivano i dati

```
nodi LoRa ──► ricevitore ──USB──► Raspberry (bridge) ──HTTPS──► questo plugin
                                  data e ora,           POST /wp-json/dbmt/v1/misure
                                  coda locale
```

Il bridge è nel repository del progetto, [dadebertolino/stazione-meteo-lora](https://github.com/dadebertolino/stazione-meteo-lora/tree/main/bridge). È lui a spingere i dati verso il sito: alla scuola non serve aprire nessuna porta.

## Struttura

| File | Ruolo |
|---|---|
| `db-stazione-meteo.php` | file principale: costanti, updater, avvio |
| `inc/class-updater.php` | aggiornamenti da GitHub Releases (`DB_GitHub_Updater_V2`) |
| `inc/class-dbmt-db.php` | tabella `{prefix}dbmt_misure`, creata all'attivazione |
| `inc/class-dbmt-rest.php` | endpoint `POST /dbmt/v1/misure`: ricezione dal bridge, con token |
| `inc/class-dbmt-validazione.php` | controllo di ogni misura, campo per campo |
| `inc/class-dbmt-token.php` | token del bridge: nel database solo l'hash |
| `inc/class-dbmt-admin.php` | Impostazioni → Stazione Meteo: token, quota, stato |
| `inc/class-dbmt-shortcode.php` | shortcode `[dbmt_meteo]` |
| `assets/css/db-admin-ui.css` | design system admin condiviso |
| `uninstall.php` | rimuove tabella e opzioni |
| `tests/run-tests.php` | test senza WordPress: `php tests/run-tests.php` |

## Dati

Una riga per misura, con l'ora di arrivo assegnata dal Raspberry. La chiave unica (nodo, ora di arrivo) scarta i reinvii: il bridge può rimandare un gruppo di misure senza creare doppioni.

L'ora si salva in **UTC**. In ora locale, nella notte del cambio d'ora di ottobre, l'ora fra le 2 e le 3 si ripete: due misure diverse avrebbero la stessa ora e la seconda verrebbe scartata come doppione.

La pressione si salva come misurata sul posto; la pagina la mostra riportata al livello del mare con la quota delle impostazioni (predefinita 395 m).

Nessun dato personale: solo misure ambientali e diagnostica dei nodi.

## Formato dei dati (bridge → plugin)

```
POST /wp-json/dbmt/v1/misure
X-DBMT-Token: <token>
Content-Type: application/json
```

```json
{
  "bridge": "0.1.0",
  "misure": [
    {
      "ricevuto": "2026-10-25T17:42:07Z",
      "nodo": 2,
      "tipo": "bme280",
      "seq": 41,
      "reset": null,
      "temp_c": 18.33,
      "ur_pct": 57.24,
      "press_hpa": 964.7,
      "vbat_mv": 3000,
      "vbat_tx_mv": 3000,
      "rssi": -51,
      "snr": 14
    }
  ]
}
```

| Campo | Tipo | Note |
|---|---|---|
| `ricevuto` | stringa ISO 8601 | ora di arrivo al Raspberry, in UTC (`Z`) |
| `nodo` | intero 1-15 | |
| `tipo` | `"bme280"` o `"sht45"` | |
| `seq` | intero 0-255 | 0 solo nel primo pacchetto dopo un riavvio del nodo |
| `reset` | intero 1-7 o `null` | causa del riavvio, solo con `seq` 0 |
| `temp_c`, `ur_pct`, `press_hpa`, `vbat_mv`, `vbat_tx_mv` | numero o `null` | `null` = misura non disponibile; `press_hpa` sempre `null` per l'SHT45 |
| `rssi`, `snr` | intero | qualità del segnale al ricevitore |

Il token si genera in **Impostazioni → Stazione Meteo** e si vede una sola volta. Va nell'header `X-DBMT-Token`; è accettato anche `Authorization: Bearer <token>`, ma alcuni hosting Apache tolgono quell'header prima di PHP e alcuni plugin di sicurezza lo intercettano.

Al massimo **500 misure per richiesta**. Dopo un periodo senza internet il bridge svuota la coda in più richieste, dalle più vecchie.

### Risposte

| Codice | Significato | Cosa fa il bridge |
|---|---|---|
| `200` | gruppo elaborato | segna come inviate le misure inserite e i doppioni; quelle in `scartate` le segna come rifiutate, senza riprovare |
| `400` | corpo non valido (JSON, campo `misure` mancante, più di 500 misure) | non riprova lo stesso gruppo: è un errore del bridge, va nel log |
| `401` | token mancante o sbagliato | si ferma e lo scrive nel log: va rigenerato il token |
| `5xx`, timeout, rete assente | sito non disponibile | riprova al giro successivo, senza perdere niente |

```json
{ "ricevute": 10, "inserite": 9, "doppioni": 1, "scartate": [ { "indice": 4, "motivo": "temp_c fuori campo" } ] }
```

Una misura viene scartata, e non bloccata in coda per sempre, se ha un campo fuori campo o di tipo sbagliato: per esempio una temperatura fuori da −40…85 °C o una data nel futuro.

# DB Stazione Meteo

Mostra sul sito della scuola le misure della stazione meteo LoRa: ultimi valori e andamento.
Niente servizi esterni, niente registrazioni, niente abbonamenti.

**Versione:** 0.3.0  
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
| `inc/class-dbmt-admin.php` | Impostazioni → Stazione Meteo: token del bridge, quota, stato, token di lettura, pulizia dati |
| `inc/class-dbmt-shortcode.php` | shortcode `[dbmt_meteo]` (ultimi valori) e `[dbmt_grafici]` (andamento) |
| `inc/class-dbmt-serie.php` | serie aggregate per i grafici: `GET /dbmt/v1/serie` |
| `inc/class-dbmt-meteo.php` | pressione riportata al livello del mare |
| `inc/class-dbmt-pulizia.php` | pulizia dati: eliminazione delle misure, tutte o fino a un istante |
| `inc/class-dbmt-api.php` | API di lettura: `GET /dbmt/v1/dati` e `/nodi`, JSON e CSV |
| `inc/class-dbmt-token-lettura.php` | token di lettura con nome, revocabili uno per uno |
| `assets/js/grafici.js` | grafici SVG, senza librerie né CDN |
| `assets/css/db-admin-ui.css` | design system admin condiviso |
| `uninstall.php` | rimuove tabella e opzioni |
| `tests/run-tests.php` | test senza WordPress: `php tests/run-tests.php` |

## Sulla pagina

```
[dbmt_meteo]              ultimi valori di ogni nodo, minima e massima delle 24 ore
[dbmt_meteo nodo="2"]     un nodo solo
[dbmt_grafici]            andamento: 24 ore, 7 giorni, 30 giorni
[dbmt_grafici periodo="7g"]
```

**I grafici** sono tre, uno sotto l'altro con lo stesso asse dei tempi: temperatura, umidità, pressione al livello del mare. Grandezze con unità diverse non condividono mai un asse. Ogni nodo è una linea con il colore del suo numero (la palette è verificata per il daltonismo); sui 7 e 30 giorni una fascia mostra minima e massima di ogni intervallo. Dove mancano misure la linea si interrompe. Il mouse, o le frecce della tastiera sul grafico selezionato, mostra in un solo riquadro i valori di tutti i nodi in quell'istante; "Mostra i dati in tabella" dà gli stessi dati senza grafico.

Sono disegnati in SVG da `assets/js/grafici.js`, senza librerie e senza CDN: la pagina della scuola non fa richieste a server esterni. Si aggiornano da soli ogni 5 minuti.

**Le serie** arrivano già aggregate da `GET /wp-json/dbmt/v1/serie?periodo=24h|7g|30g`, pubblico e in sola lettura: medie a 10 minuti (24 ore), a un'ora (7 giorni), a 3 ore (30 giorni), quindi al massimo qualche centinaio di punti anche dopo anni di dati. In cache da 1 a 15 minuti secondo il periodo.

## API di lettura

Per leggere le misure da un'altra applicazione, da un programma o da un foglio di calcolo. Serve un **token di lettura**, da generare in Impostazioni → Stazione Meteo → Accesso ai dati: ognuno ha un nome (per esempio "Classe 4AEE"), si vede una volta sola e si revoca da solo. Il token del bridge non vale per leggere, e un token di lettura non permette di inviare misure.

Il token va nell'header `X-DBMT-Token` (o `Authorization: Bearer`). Si può passare anche nell'indirizzo con `?token=…`, perché `IMPORTDATA` di Fogli Google ed Excel da Web non sanno aggiungere header; ma un indirizzo con il token finisce in cronologia e nei log, e chi lo vede può leggere i dati come il titolare del token.

### `GET /wp-json/dbmt/v1/dati`

| Parametro | Valori | |
|---|---|---|
| `da`, `a` | `2026-10-27` (mezzanotte), `2026-10-27T08:00` (ora del sito), `2026-10-27T07:00:00Z` (UTC) | `da` incluso, `a` escluso |
| `nodo` | 1-15 | tutti, se manca |
| `formato` | `json` (predefinito), `csv` (virgola e punto: Fogli Google, Python, R), `csv-it` (punto e virgola e virgola decimale: Excel in italiano) | |
| `limite` | 1-5000, predefinito 1000 | misure per pagina |
| `dopo` | il valore di `successivo` della pagina precedente | |

Le misure sono in ordine cronologico. Se ce ne sono altre, la risposta JSON ha `successivo` e `pagina_successiva` (l'indirizzo pronto, senza il token); in CSV le stesse informazioni sono negli header `X-DBMT-Successivo` e `Link`.

```json
{
  "misure": [
    { "id": 97, "ricevuto": "2026-10-25T16:45:26Z", "ora_locale": "2026-10-25T17:45:26+01:00",
      "nodo": 2, "tipo": "bme280", "seq": 182, "reset": null,
      "temp_c": 18.25, "ur_pct": 60.42, "press_hpa": 962.8, "press_slm_hpa": 1008.2,
      "vbat_mv": 3000, "vbat_tx_mv": 3000, "rssi": -56, "snr": 13 }
  ],
  "quota_m": 395,
  "successivo": "20261025172423-176",
  "pagina_successiva": "https://…/wp-json/dbmt/v1/dati?limite=80&dopo=20261025172423-176"
}
```

`press_hpa` è la pressione misurata sul posto, `press_slm_hpa` quella riportata al livello del mare con la quota delle impostazioni. `null` = misura non disponibile.

### `GET /wp-json/dbmt/v1/nodi`

```json
{ "nodi": [ { "nodo": 2, "tipo": "bme280", "misure": 194, "prima": "2026-10-25T16:45:26Z", "ultima": "2026-10-25T18:20:53Z" } ] }
```

### Esempi

```bash
curl -H "X-DBMT-Token: dbmtl_…" "https://…/wp-json/dbmt/v1/dati?da=2026-10-27&a=2026-10-28"
```

Fogli Google, in una cella: `=IMPORTDATA("https://…/wp-json/dbmt/v1/dati?formato=csv&da=2026-10-27&token=dbmtl_…")`

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

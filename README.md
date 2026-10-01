# maco-mocks

Mocks der MaKo-Backend-Schnittstellen **lesen**, **erstellen** und **aktualisieren**, dazu ein PHP-Proxy, der sie unter
**https://mocks.macoapp.de** ausliefert. Die Antworten stammen aus der API-Spezifikation und liegen hier als einzelne
JSON-Dateien. Welche Antwort kommt, kann je Parameter, Header oder Body gesteuert werden.

```bash
curl "https://mocks.macoapp.de/getMarketlocationBasic?command=LESEN_MARKTLOKATION_BASIS&parameter1=12345678901&parameter3=2024-06-28T12:18:00.000Z"
curl -X POST https://mocks.macoapp.de/updateProcessData -H 'Content-Type: application/json' -d '{}'   # -> 400
curl -X POST https://mocks.macoapp.de/identifyLocation -H 'Treffer-Max-Anzahl: 1' -d '{}' -i        # -> Treffer-* Header
```

Übersicht: **https://mocks.macoapp.de/** zeigt alle Methoden mit ihren Varianten. „Senden“ schickt jede Variante direkt ab und zeigt die Antwort in der Vorschau, GET-Varianten sind zusätzlich Links. Als JSON: `/_mocks`.

## Aufbau

```
mocks/
  _global.json                     Regeln für alle Endpunkte
  lesen/
    getMarketlocationBasic/
      mock.json                    Methode, Pfad, Command, Regeln
      200-leer.json                eigene Antwort
      quelle/                      aus der API-Spezifikation – wird beim Import überschrieben
        200.json
        400.json
        erwartungen.json           Mock-Erwartungen (Varianten je Parameter)
        200-erwartung-malo-50074561169.json …
  aktualisieren/
    updateProcessData/
      mock.json
      quelle/201.json, 400.json, anfrage-03002.json, anfrage-03003.json
  erstellen/
    createProcessData/
      mock.json
      quelle/200.json, 422.json, anfrage.json
proxy/                             PHP-Proxy für mocks.macoapp.de
tools/                             Import, Prüfung
tests/                             Tests
```

- **Antwortdateien** heißen `<status>.json` oder `<status>-<name>.json`, z. B. `200.json`, `200-leer.json`, `422.json`. Der Status steht im Namen.
- Liegt eine Datei gleichen Namens im Endpunkt-Ordner und in `quelle/`, gewinnt die im Endpunkt-Ordner. So lässt sich eine importierte Antwort anpassen, ohne dass der nächste Import sie überschreibt.
- `anfrage*.json` sind Anfragebeispiele aus der Spezifikation, zum Nachlesen und Testen.

## Pflege

### Neue Antwort für bestimmte Parameter

1. Datei anlegen, z. B. `mocks/lesen/getMarketlocationBasic/200-melo.json`.
2. Regel in `mock.json` ergänzen:

```json
"rules": [
  {
    "name": "Messlokation angefragt",
    "when": { "query.parameter2": "MELO" },
    "then": "200-melo.json"
  }
]
```

3. Pushen. Der Proxy lädt den neuen Stand automatisch (siehe unten).

Reihenfolge je Anfrage: eigene Regeln (`mock.json`) → globale Testdaten (`_global.json`) → Erwartungen (`quelle/erwartungen.json`) → `default` (sonst `200.json` bzw. die erste 2xx-Datei). Die erste passende Regel gewinnt. Parameter, die in keiner Regel vorkommen, werden ignoriert.

### Varianten (Mock-Erwartungen)

Die Mock-Erwartungen aus dem API-Tool (Endpunkt → Mock) liegen je Endpunkt in `quelle/erwartungen.json`, die Antworten daneben als `200-erwartung-<name>.json`. Die Regeln sehen aus wie in `mock.json` und werden beim Import überschrieben.

- Geprüft wird in der Reihenfolge aus dem API-Tool. Sammel-Erwartungen, die nur prüfen, ob ein Parameter da ist (z. B. „Default mit Platzhaltern“), oder gar keine Bedingung haben, kommen zuletzt. Sonst verdecken sie alles, was in der Liste darunter steht.
- Die Antworten kommen unverändert, `set` greift dort nicht.
- Body-Bedingungen ohne Pfad (`marktlokationsId`) prüfen das Feld auf oberster Ebene, `$.a.b` wird zu `a.b`.
- Eine Variante anders haben: eigene Regel in `mock.json` anlegen, die gewinnt. Dauerhaft ändern: im API-Tool und neu importieren.

### Bedingungen (`when`)

Alle Bedingungen einer Regel müssen zutreffen. Für „oder“ mehrere Regeln anlegen.

| Schlüssel | Bedeutung |
|---|---|
| `query.parameter1` | Query-Parameter |
| `header.treffer-offset` | Request-Header (Groß-/Kleinschreibung egal) |
| `body.transaktionsdaten.pruefidentifikator` | Wert im JSON-Body, Listen mit `[0]` |
| `path.id` | Pfad-Parameter bei Pfaden wie `/items/{id}` |
| `command` | Query-Parameter `command` |

| Wert | Bedeutung |
|---|---|
| `"MELO"` | gleich |
| `["MALO", "MELO"]` | einer davon |
| `{"not": "MALO"}`, `{"notIn": [...]}` | ungleich, keiner davon |
| `{"regex": "^5"}`, `{"contains": "x"}`, `{"notContains": "x"}` | regulärer Ausdruck, enthält, enthält nicht |
| `{"exists": true}`, `{"exists": false}` | vorhanden, fehlt |
| `{"gt": 10}`, `{"gte"}`, `{"lt"}`, `{"lte"}` | Zahlenvergleich |

### Antwort (`then`)

- `"200-leer.json"`: Datei aus dem Endpunkt-Ordner oder `quelle/`
- `{"file": "422.json", "headers": {"X-Grund": "..."}, "delay": 500}`: mit zusätzlichen Headern und Verzögerung in ms
- `{"status": 204}` oder `{"status": 200, "body": []}`: ohne Datei

### Werte aus der Anfrage übernehmen

In Antwortdateien und Headern werden Platzhalter ersetzt: `{{query.parameter1}}`, `{{header.x-id}}`, `{{path.id}}`,
`{{body.a.b[0]}}`, `{{command}}`, `{{now}}`, `{{uuid}}`. Mit Standardwert: `{{query.parameter1|50754496000}}`.

Um eine importierte Antwort nicht kopieren zu müssen, gibt es `set` in `mock.json`. Der Wert wird nur gesetzt, wenn der Parameter mitkommt und das Feld in der Antwort existiert:

```json
"set": { "stammdaten.MARKTLOKATION[0].marktlokationsId": "{{query.parameter1}}" }
```

### Eine bestimmte Antwort erzwingen

Header `X-Mock-Response: 422` oder Query `?__response=200-leer`, wahlweise mit Status oder Dateiname. Welche Antwort und Regel gegriffen haben, steht in den Antwort-Headern `X-Mock-Response`, `X-Mock-Reason` und `X-Mock-Version`.

### Vorhandene Regeln

| Endpunkt | Verhalten |
|---|---|
| 19 Endpunkte | 92 importierte Varianten, siehe Übersicht unter `/` |
| `POST /identifyLocation` | Paging über `Treffer-Max-Anzahl`/`Treffer-Offset` mit Antwort-Headern `Treffer-*`; MaLo-ID `00000000000` → `[]`; MaLo-ID vorhanden → eine Marktlokation; sonst mehrere |
| `POST /updateProcessData` | ohne `transaktionsdaten` → 400; 03002 und 03003 → 201 |
| `POST /createProcessData` | ohne `transaktionsdaten` → 422; sonst 200 |
| `GET /getMarketlocationBasic` | `parameter1=00000000000` → leere Liste |
| `GET /getMaloidentMarketlocation` | `parameter1` wird gespiegelt |
| alle (`_global.json`) | `parameter1=FEHLER400` → 400, `parameter1=FEHLER422` → 422, sofern der Endpunkt die Datei hat |

Gleicher Pfad mit verschiedenen Commands (z. B. `/identifyMarketlocation`): `command` entscheidet. Ein Aufruf mit `command=…` an einen unbekannten Pfad wird über den Command zugeordnet (generischer Endpunkt, Präfixe wie `SAP_` werden erkannt).

## Prüfen

```bash
php tools/validate.php   # JSON, Regeln, Dateinamen; baut jede Antwort einmal
php tests/run.php
```

Läuft auch bei jedem Push (`.github/workflows/check.yml`, PHP 8.1 und 8.4). Der Proxy prüft jeden Stand vor dem Umschalten genauso und behält bei Fehlern den alten.

## Import

Quelle ist das nächtliche Backup der API-Spezifikation (`openapi.json`, internes Repo). Auswahl und Gruppen stehen in `tools/import.json`.

Die Mock-Erwartungen stehen nicht im OpenAPI-Export. Sie kommen aus dem Browser:

1. Das Projekt im Web-Client des API-Tools öffnen (Branch main) und einmal neu laden.
2. Entwicklertools → Konsole, den Inhalt von `tools/erwartungen-export.js` einfügen. Das lädt `erwartungen-<projekt>.json` herunter.

```bash
php tools/import.php /pfad/zu/openapi.json --erwartungen ~/Downloads/erwartungen-<projekt>.json --stand <commit>
php tools/validate.php
```

Der Import schreibt nur `quelle/` und die Felder `summary`, `method`, `path`, `command`, `parameters`, `quelle` in `mock.json`. Regeln, `default`, `set`, `paging` und eigene Dateien bleiben. Ohne `--erwartungen` bleiben vorhandene Erwartungen stehen. Mit `"import": false` in `mock.json` wird ein Endpunkt übersprungen. Endpunkte, die in der Spezifikation fehlen, werden nur gemeldet. Kaputtes JSON in Beispielantworten (Komma am Ende, fehlendes Komma, Kommentare) wird repariert und gemeldet.

## Proxy (`proxy/`)

Reines PHP ≥ 8.1 mit den Erweiterungen `zip` und `curl`, ohne Composer, git oder Shell. Läuft damit auch auf einfachem Webspace (All-Inkl).

- Der Proxy liest aus einer **lokalen Kopie** unter `proxy/data/`, nicht bei jeder Anfrage von GitHub.
- **Bei jedem Push auf `main`** ruft GitHub per Webhook `POST /_update` auf. Der Proxy lädt dann den aktuellen `main` als ZIP von GitHub, übernimmt nur `mocks/`, prüft den Stand und schaltet atomar um. Die letzten drei Stände bleiben liegen.
- Der Webhook ist nur der Auslöser. Heruntergeladen wird immer der echte Stand von GitHub, nie Inhalte aus dem Aufruf. Aufrufe werden gedrosselt (10 s). Ein gedrosselter Push wird mit der nächsten Anfrage nachgeholt.
- Fehlt die Kopie noch, lädt der erste Aufruf sie.

| Pfad | Zweck |
|---|---|
| `POST /_update` | Webhook oder manuelles Update (`curl -X POST https://mocks.macoapp.de/_update`) |
| `GET /_status` | aktiver Commit, letztes Update, PHP-Umgebung |
| `GET /_mocks` | alle Endpunkte mit Antworten und Regeln |

### Einrichtung auf All-Inkl

1. Im KAS die Subdomain `mocks.macoapp.de` anlegen (PHP ≥ 8.1, gern 8.3) und das SSL-Zertifikat (Let's Encrypt) aktivieren.
2. Den Inhalt von `proxy/` in das Verzeichnis der Subdomain laden, inklusive `.htaccess`. Automatisch geht das über `.github/workflows/deploy-proxy.yml`, wenn die Secrets `FTP_SERVER`, `FTP_USERNAME` und `FTP_PASSWORD` gesetzt sind. Am besten einen eigenen FTP-Benutzer nur für dieses Verzeichnis anlegen; optional die Variable `FTP_SERVER_DIR`.
3. `https://mocks.macoapp.de/_status` aufrufen. Der erste Aufruf von `/` lädt die Mocks.
4. Webhook im Repo: Settings → Webhooks, Payload-URL `https://mocks.macoapp.de/_update`, Content type `application/json`, Event „Just the push event“.

Optional in `proxy/config.local.php` auf dem Server (wird nie überschrieben):

```php
<?php return [
    'updateSecret' => '…',   // dann auch im Webhook als Secret eintragen
    'token' => '…',          // Mocks nur mit Authorization: Bearer …
];
```

### Lokal starten

```bash
cd proxy
MOCKS_DIR=../mocks php -S localhost:8080 index.php
```

Im Entwicklungsmodus liest der Proxy direkt aus dem Arbeitsordner; Änderungen sind sofort sichtbar.

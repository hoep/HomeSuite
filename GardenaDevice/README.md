# GardenaDevice (HSGA)

HomeSuite-Modul fuer GARDENA-smart-system-Geraete (Feuchtesensoren, Bewaesserungscomputer /
smart Water Control). Ein Objekt je physischem Gardena-Geraet. Analog zu MowerDevice: Poll-Timer,
Reflect-Statusvariablen, Armed-Gate fuers Schalten.

## Architektur (zwei Module, ein Zugang)

- **GardenaConfigurator** (`HSGX`, Typ 4) — „Gardena Cloud". EINE Instanz haelt den Zugang
  (**Client ID + Client Secret** der Developer-App) im Formular und listet alle Cloud-Geraete in
  einer Tabelle. Pro Zeile wird eine Geraete-Instanz angelegt; bereits vorhandene werden erkannt.
- **GardenaDevice** (`HSGA`, Typ 3) — je EINE Instanz pro Geraet (Ventil, Sensor …) mit ihren
  Statusvariablen; liest und schaltet ueber die Developer-API.

### Zwei APIs — bewusst getrennt (Rate-Limit!)

- **LESEN (Liste + alle Statuswerte)** laeuft ueber die **ALTE** Cloud `smart.gardena.com/v1/`,
  Treiber `gardena-app`, authentifiziert mit dem Husqvarna-**Legacy-Token** (wie der Automower,
  `scripts/automower_token.json`). Diese API ist grosszuegig limitiert -> Poll im 60-s-Takt ist ok.
- **SCHALTEN (nur Ventil auf/zu)** laeuft ueber die **NEUE** Developer-API
  `api.smart.gardena.dev/v1/`, Treiber `gardena-dev`, OAuth2 `client_credentials` mit
  **Client ID/Secret** (`scripts/gardena_dev.json`, geschrieben vom Configurator; access_token-Cache
  `scripts/gardena_dev_token.json`). Grund: die alte Cloud nimmt keine Kommandos mehr an.

> Die neue Developer-API ist **streng limitiert** (~1 Aufruf/15 min, ~3000/Monat) und wird
> deshalb **ausschliesslich** fuers Ventil-Kommando benutzt, NIE zum Pollen. Client ID/Secret
> dienen nur der Ventil-Steuerung.

Ventil-Kommando: `PUT /v1/command/{valveServiceId}` JSON:API `VALVE_CONTROL`
`START_SECONDS_TO_OVERRIDE`/`STOP_UNTIL_NEXT_TASK`. Da die neue API andere Geraete-IDs
als die alte Cloud vergibt, findet der Treiber das Geraet ueber die **Seriennummer**
(`Serial`-Property, `matchSerial`).

## Einrichten

1. Auf <https://developer.husqvarnagroup.cloud> anmelden, neue Application anlegen; unter
   *Connect new API* **Authentication API** UND **GARDENA smart system API** verbinden.
2. **Application Key** (= Client ID) und **Application Secret** kopieren.
3. In der Instanz **„Gardena Cloud"** (GardenaConfigurator) in die Felder eintragen,
   *Verbindung testen* druecken.
4. In der Geraetetabelle je Geraet *Erstellen* — legt die HSGA-Instanz mit Variablen an.

## Properties

| Property       | Default        | Bedeutung                                             |
|----------------|----------------|-------------------------------------------------------|
| `Driver`       | `gardena-app`  | Lese-Treiber (Legacy-Cloud)                           |
| `WriteDriver`  | `gardena-dev`  | Schreib-Treiber (neue Developer-API)                  |
| `DeviceId`     | —              | Geraete-UUID der Legacy-Cloud                         |
| `Serial`       | —              | Seriennummer (Match in der neuen API fuers Schalten)  |
| `LocationId`   | —              | Standort (optional; sonst erster des Kontos)          |
| `Category`     | —              | `sensor2` \| `watering_computer` \| …                 |
| `PollInterval` | `60`           | Poll-Takt (s), min. 15                                |
| `Armed`        | `false`        | Erst `true` -> Ventil-Kommandos werden real gefeuert  |

## Statusvariablen (kategorie-adaptiv)

- **Gemeinsam:** Online, Batterie, Funkqualitaet, Fehler, Aktualisiert.
- **sensor2:** Bodenfeuchte, Bodentemperatur, Frostwarnung.
- **watering_computer:** Umgebungstemperatur, Ventil-Status, Restlaufzeit,
  **Bewaessern (min)** (`WaterMinutes`, T_SETPOINT) und **Bewaesserung stoppen**
  (`WaterStop`, T_COMMAND) — beide armed-gated.

## Management-Aktionen (RPC)

| Op               | Wirkung                                                        |
|------------------|---------------------------------------------------------------|
| `getState`       | Zustand des gebundenen Geraets lesen (Diagnose)               |
| `listDevices`    | alle Cloud-Geraete auflisten                                  |
| `importGardena`  | je Nicht-Gateway-Geraet eine Instanz anlegen (dedup ueber ID) |
| `saveDevCreds`   | Application-Key/-Secret der neuen API speichern               |
| `testDevRead`    | neue API testen (lesen, Serien-Match)                         |
| `backfillSerials`| Seriennummern bestehender Instanzen nachtragen                |

## Schalten — Ablauf

`WaterMinutes` (Minuten) -> `valveStart(sec)` -> `PUT /v1/command/{valveServiceId}`
`{"data":{"type":"VALVE_CONTROL","attributes":{"command":"START_SECONDS_TO_OVERRIDE","seconds":…}}}`.
`WaterStop` -> `STOP_UNTIL_NEXT_TASK`. Ausgefuehrt nur bei vorhandenen Creds UND `Armed=true`;
sonst Schatten-Log.

## Stand

Lesen live (5 Geraete, 4 Instanzen). Schreib-Pfad gebaut + verdrahtet; scharf, sobald
`scripts/gardena_dev.json` mit gueltigem Key/Secret hinterlegt und die jeweilige Instanz `Armed`
gesetzt ist.

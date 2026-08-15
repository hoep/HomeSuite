# MowerDevice (HSMW)

HomeSuite-Domaenenmodul **„Maeher"** — je Instanz ein physischer Maehroboter. Portierung
der alten `PHPAutomower`-Klasse auf die HomeSuite-Architektur (`EntityModule`): Manifest ->
Variablen, native `RequestAction`, RPC-Trio, armed-Gate.

- **Modul-GUID:** `{D1FB2D11-21F3-4B22-8341-E88D512A9B61}`
- **Prefix:** `HSMW`
- **Typ:** 3 (Geraet)
- **Aliase:** `MowerDevice`, `HSMW`, `HomeSuite Maeher`
- **Vendor:** Hoep · Repo: `https://github.com/hoep/HomeSuite`

## Zweck / Ueberblick

Eine Instanz bindet **einen** Husqvarna-Automower an die HomeSuite. Der Ist-Zustand
(Aktivitaet, Status, Akku, Position, Statistik, Mähplan, Geofence …) wird zyklisch aus der
Cloud gespiegelt; Steuerbefehle (Mähen/Parken/Pause/Weiter/Schnitthoehe/Scheinwerfer …) sind
verdrahtet, greifen aber **nur wenn scharf** (armed-Gate) real durch — sonst Schatten-Modus
(nur Log/Reflect, kein echtes Schalten).

## Architektur / HAL

Gebunden wird die Husqvarna-Cloud ueber den HAL-Treiber **`husqvarna-app`** (`IMower`,
`DriverFactory::create('husqvarna-app', …)`). Das ist die **undokumentierte dss-App-API**:
kein App-Key, **keine Rate-Limits** — daher ist „Echtzeit" hier ein schneller Poll im Modul
(Nutzer-Vorgabe), keine Event-Streams. Zugang = normales Husqvarna-Konto (E-Mail + Passwort)
plus Mäher-ID im Format `240201677-999999999`.

Treiber-Aufbau (`driver()`) ist lazy und wird nur aktiv, wenn `driver === 'husqvarna-app'`
und Username/Passwort/MowerId gesetzt sind; sonst bleibt die Instanz inaktiv (Schatten).

Die Karten-Darstellung ist self-contained in `mapRenderer.php` (`renderPositionMap()`,
Leaflet + Esri-Satellit/OSM, Bewegungspfad-Polyline, Positions-Pin, Geofence-Kreis) — keine
Abhaengigkeit mehr von `PHPAutomower/maps.php`.

## Konfiguration (Properties + Formular)

Konfiguration liegt **nativ als Properties** (kein FabricStore; `ConfigSchema = 1` von Anfang
an). `PROP_MAP`:

| Property       | Typ    | Default | Bedeutung |
|----------------|--------|---------|-----------|
| `Driver`       | String | `''`    | `''` = inaktiv (Schatten) · `husqvarna-app` = aktiv |
| `Username`     | String | `''`    | Husqvarna-Konto (E-Mail) |
| `Password`     | String | `''`    | Konto-Passwort (Secret, wird in `getConfig` maskiert) |
| `MowerId`      | String | `''`    | Mäher-ID (`240201677-999999999`) |
| `PollInterval` | Integer| `20`    | Poll-Intervall in Sekunden (min. 5) |
| `Armed`        | Boolean| `false` | scharf = schreibt echte Kommandos, sonst Schatten |

Attribute/Buffer: `LastFullPoll` (Cadence-Zeitstempel des letzten Voll-Polls).

**Konsolen-Formular** (`GetConfigurationForm`, Erstkonfiguration — Verwaltung sonst im LVB):
Treiber-Select, Benutzer, Passwort, Mäher-ID, Poll-Intervall (5–3600 s), Scharf-Checkbox,
Status-Label sowie zwei Buttons „Jetzt lesen (Diagnose)" und „Aus PHPAutomower uebernehmen".

### QueryInterval / Poll-Cadence

Ein einziger Timer **`Refresh`** (`HSMW_Refresh`) läuft im `PollInterval`-Takt (aktiv nur bei
gebundenem Treiber). Zwei Kadenzen:

- **Schnell** (jeder Tick): Kern-Status — Activity/State/Battery/Position/Einstellungen.
- **Voll-Poll** alle `FULL_POLL_S = 900 s` (15 Min): schwere Endpunkte — Statistik, Timer/
  Mähplan, Geofence, Fehlerhistorie, Arbeitsbereiche. Nach jedem Neustart einmalig ein
  Voll-Poll (gewollt).

Nach einem Steuerbefehl wird der Timer kurz auf **3 s** gestellt, um den Ist-Zustand zeitnah
nachzuziehen, und danach wieder auf das reguläre Intervall.

## Armed-Gate (Scharf / Schatten)

Jede Schreibaktion läuft durch `armed()` → `armedEffective()`. **Vorrang hat der zentrale
Hub-Master** je Domäne (3-Zustand `ArmMowerMode` bzw. Fallback-Bool `ArmMower`):

- `0 = Aus` → alle Mäher-Instanzen Schatten
- `1 = Auto` → jede Instanz entscheidet per eigenem `Armed`-Property
- `2 = Scharf` → alle Instanzen real

Fehlt ein Hub, gilt der Instanz-Wert `Armed`. Bei „nicht scharf" wird eine Aktion nur als
`HSMW.shadow`-Debug geloggt („WUERDE …"), nicht ausgeführt.

## Status-Variablen / Controls (Manifest)

Domäne `mower`, Icon `Move`. Alle Controls werden aus dem Manifest materialisiert.

**Kommandos** (transient, armed-gated): `Start` (Mähen), `Park` (Parken), `Pause`,
`Resume` (Weiterfahren), `ConfirmError` (Fehler quittieren).

**Gated schaltbar:** `CuttingHeight` (Schnitthoehe 1–9), `Headlight` (Scheinwerfer, Profil
`HSMW.Headlight`: Immer an / Immer aus / Nur abends / Abends & nachts).

**Lokal (nicht gated):** `AutoMode` (Automatik-Modus je Mäher für die Regen-Autologik, Profil
`HSMW.AutoMode`: Auto / Pause / Manuell / Logik) — reine lokale Einstellung, kein Mäher-Befehl.

**Reflect (read-only, nie gated):**
`Activity` (Profil `HSMW.Activity`), `State` (Profil `HSMW.State`), `Mode`, `Battery`
(`~Battery.100`), `Online`/`InChargingStation`/`UpdateRequired` (`~Switch`), `ErrorText`,
`ErrorCode`, `NextStart`/`LastUpdate` (`~UnixTimestamp`), `RunningTime`/`CuttingTime`/
`ChargingTime`/`SearchTime` (s), `Collisions`, `Lat`/`Lng`, `ChargingCycles`,
`BladeHours`/`SearchHours` (h), `BladeUsagePct`/`Efficiency` (%), `Model`, `Firmware`,
`Mission`, `CuttingHeight`.

**JSON-Reflects** (nur Voll-Poll, nicht geloggt): `Geofence`, `WorkAreasJson`,
`StayOutJson`, `ErrorHistory` (Fehlerhistorie), `TimersJson` (Mähplan).

**Farb-/Icon-Profile** werden vor der Variablen-Anlage angelegt: `HSMW.Activity`,
`HSMW.State`, `HSMW.Headlight`, `HSMW.AutoMode`.

### Archiv-Logging

`ensureLogging()` aktiviert idempotent das Logging fester Variablen (Parität zum Legacy-Baum),
mit fixer Aggregation (0 = Standard, 1 = Zähler): `State`, `Activity`, `Mode`, `Battery`,
`CuttingHeight`, `ChargingCycles`, `Collisions` (Standard) sowie `RunningTime`, `CuttingTime`,
`SearchTime`, `ChargingTime`, `BladeHours` (Zähler). Ein einmaliges `IPS_ApplyChanges` der
Archive-Control, nicht je Variable. `BindHealth` (String) zeigt den Bindungs-Status.

## Oeffentliche Skript-/RPC-Funktionen (HSMW_*)

Steuer-Prozeduren (gehen durch das armed-Gate):

- `HSMW_StartMowing(int $id, int $Minutes = 0): bool`
- `HSMW_Park($id)`, `HSMW_Pause($id)`, `HSMW_Resume($id)`, `HSMW_ConfirmError($id): bool`
- `HSMW_SetCuttingHeight($id, int $Level): bool`
- `HSMW_SetHeadlight($id, int $Mode): bool` (0=ALWAYS_ON, 1=ALWAYS_OFF, 2=EVENING_ONLY, 3=EVENING_AND_NIGHT)

Status / Betrieb:

- `HSMW_Refreshed($id): bool` — sofort pollen
- `HSMW_SetArmed($id, bool $Armed): bool` — Scharf/Schatten setzen (per mgmt `setArmed`)
- `HSMW_GetBattery($id): int`, `HSMW_GetActivity($id): string`, `HSMW_GetState($id): string`,
  `HSMW_IsOnline($id): bool`

Dazu das generische HomeSuite-RPC-Trio (u. a. `HSMW_Manage($id, json)` → mgmt-Ops).

## Management-Operationen (mgmt, via `HSMW_Manage`)

| Op | Zweck |
|----|-------|
| `configureDriver` | Zugang/Treiber setzen (username, password, mowerId, pollInterval) |
| `getConfig` | Konfiguration lesen (Passwort entfernt) |
| `validate` | Bindung prüfen (Health-Text + Issues) |
| `driverProbe` | Treiber-Status + Capabilities + Roh-State |
| `readNow` | Jetzt lesen (Schatten) + Reflect-Snapshot |
| `setArmed` | Scharfschalten / Schatten-Modus |
| `importLegacy` | Zugang aus PHPAutomower übernehmen (Token-Datei + Mäher-ID) |
| `migrateConfig` | No-op (startet nativ mit Properties) |
| `getTimers` | Mähplan + Arbeitsbereiche frisch lesen |
| `setTimers` | Mähplan schreiben (**gated**; Schatten liefert Ist-Timer) |
| `getMessages` | Fehlerhistorie lesen |
| `mapData` | Kartendaten: Positionen, Geofence, Aktivität (leichter Poll) |
| `resetBlade` | Messer-Nutzungszeit zurücksetzen (**gated**) |
| `updateWorkArea` | Arbeitsbereich aktualisieren: Schnitthoehe/aktiv (**gated**) |
| `updateStayOutZone` | Sperrzone schalten (**gated**) |

`importLegacy` liest Credentials aus `/var/lib/symcon/scripts/automower_token.json`
(Passwort base64) und die Mäher-ID aus `mowerId` bzw. `mowerIdVarId`; `armed` bleibt `false`.

## Besondere Hinweise

- **Keine API-Limits** beim `husqvarna-app`-Treiber (dss) — dennoch `PollInterval` nicht
  unter 5 s; Standard 20 s.
- **Schatten-Standard:** Bestands-/Neu-Instanzen sind zunächst nicht scharf; echtes Schalten
  erst nach `Armed=true` oder Hub-Master `Scharf`.
- **`AutoMode`** ist ein rein lokaler Zustand (Regen-Autologik je Mäher, unabhängig vom
  Mäher selbst) — er sendet nie ein Cloud-Kommando.
- **Position-Reihenfolge:** Der Karten-Renderer nimmt `positions[0]` als aktuelle Position
  (Pfad wie im Original), akzeptiert `lat/lng` wie `latitude/longitude`.
- Klassenname == `module.json`-name == GUID; kein Library-Reload nötig für reine
  Widget-/Poll-Änderungen.

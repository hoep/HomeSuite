# HomeSuite Hub (HSH)

Zentrale Singleton-Instanz der HomeSuite-Library — globale Einstellungen, Automatik-Gate,
Scharf-Master je Domäne, Standort/Sonne, Szenen, Sonnenprofil-Rotation, Medienquellen sowie
Registry/Provisionierung/Token für alle HomeSuite-Domänenmodule.

- **Prefix:** `HSH`
- **GUID:** `{A0C082B4-9E74-430E-BD97-F9CEBB364257}`
- **Aliase:** `HomeSuite Hub`, `HSH`
- **Typ:** Device (type 3), **parentless**, als **Singleton** genau einmal in der Konsole angelegt.
- **Basisklasse:** `\Hoep\HomeSuite\EntityModule` (liefert die generischen RPC
  `HSH_GetManifest` / `HSH_GetState` / `HSH_Manage`, den try/catch-gekapselten
  RequestAction-Dispatch, FabricStore/HoldState und den KR_READY-MessageSink).

## Überblick / Architektur

Der Hub ist bewusst **kein Monolith**. Die Kopplung an die Domänenmodule
(HeatingZone, ShadingDevice, AudioZone/-Bridge, IrrigationCircuit, LightDevice)
erfolgt lose über **GUID-Discovery** (`IPS_GetInstanceListByModuleID`), nicht über
`parentRequirements`-Chaining. Jedes Domänenmodul bleibt einzeln lauffähig.

Aufgaben (Spec §6.1):

- **Registry** — `HSH_ListEntities` entdeckt alle HomeSuite-Domänen-Instanzen.
- **Aggregat** — `HSH_GetSuiteManifest` sammelt die Manifeste aller Entitäten
  (einzelne defekte Entität wird übersprungen, nie das Aggregat gekippt).
- **Topologie** — `HSH_GetTopology` liest die Raumstruktur aus dem Objektbaum
  („HomeSuite Bereich" HSSP: Haus → Bereich → Raum) und ordnet Entitäten sowie
  verlinkte Rohgeräte dem nächsten Raum-Vorfahren zu (automatische Gewerk-Klassifikation).
- **Verwaltung** — `HSH_Manage` (geerbter Whitelist-Dispatch) führt die Hub-Ops aus.
- **Provisionierung** — `HSH_Provision` legt neue Instanzen **asynchron** an (Job in
  persistenter Queue, ein Modul-Timer arbeitet sie ab; nie synchrones
  `IPS_CreateInstance`/`ApplyChanges` im Hook-/RPC-Pfad).
- **WebHook** `/hook/homesuite` — erst bei Kernel-Ready registriert.
- **Token** — ein rotierbares Verwaltungs-Token (`X-HS-Token`) schützt alle
  Schreib-/Migrate-Endpunkte.

Der Hub selbst exponiert nur wenige Bedien-Controls; das eigentliche Anlegen/Provisionieren/
Migrieren läuft im LiveViewBuilder über das Manifest.

## Konfiguration

Das Konsolen-Formular dient v. a. Token-Verwaltung, Status/Diagnose und den **haus-weiten,
domänenübergreifenden Einstellungen**, die alle Instanzen einer Domäne teilen (Childs lesen
sie via `EntityModule::hubProp()` mit optionalem Instanz-Override, Instanzwert > 0 gewinnt).

### Properties

| Property | Typ | Default | Zweck |
|---|---|---|---|
| `SunSource` | string | `location` | Standortquelle: `location` (Location-Instanz) oder `coords` |
| `LocationId` | int | 0 | Location-Instanz (bei `SunSource=location`) |
| `Lat` / `Lon` | float | 0.0 | Eigene Koordinaten (bei `SunSource=coords`) |
| `ShadeWindId` | int | 0 | Windsensor (km/h), haus-weit |
| `ShadeRainId` | int | 0 | Regensensor, haus-weit |
| `ShadeBrightId` | int | 0 | Helligkeitssensor, haus-weit |
| `ShadeSunAzId` | int | 0 | Sonne Azimut |
| `ShadeSunElId` | int | 0 | Sonne Elevation |
| `ShadeWindStormKmh` | float | 50.0 | Sturm-Schwelle (km/h) |
| `ShadeRainClose` | bool | true | Bei Regen schließen |
| `ShadeSafePos` | int | 0 | Sichere Position (%) bei Sturm/Regen |
| `ShadeNorthDeg` | float | -12.6 | Nordausrichtung (Haus-Abweichung gegen Nord, °) |
| `HeatFrostTemp` | float | 8.0 | Frostschutz-Solltemperatur (°C), haus-weit |
| `IrrRainSensorId` | int | 0 | Regensensor (mm) für Bewässerung, grundstücksweit |

### Formular-Panels

- **Medienquellen (Audio-Provider)** — haus-weite Provider aktivieren + Zugangsdaten
  (Plex/Jellyfin/Audiobookshelf liefern direkte Stream-URLs, renderer-unabhängig;
  Spotify/Audible sind dienst-gebunden, DRM). Enthält den **Spotify-OAuth-Flow**
  (Redirect-URI-Anzeige + Ein-Klick-Login über `/hook/hsspotify`; Refresh-Token wird
  automatisch gespeichert).
- **Standort & Sonne (alle Domänen)** — ein Standort fürs ganze Haus.
- **Beschattung — Wetter/Sonne/Sicherheit (global)** — Sensoren, Sturm-Schwelle,
  sichere Position, Regen-schließen. **Nordausrichtung**: Beim Ändern werden ALLE
  Sonnenprofile (Hub-benannte + je Zone `geoProfile`) automatisch mitgedreht.
- **Heizung — Frostschutz (global)**.
- **Bewässerung — Klima (global)**.
- **Scharf-Schaltungen (je Domäne)** — 3-Zustand-Master pro Domäne (siehe unten).

### Aktions-Buttons (form.json)

- **Verwaltungs-Token neu erzeugen** (`HSH_RotateToken`) — invalidiert das alte
  `X-HS-Token`; alle Clients müssen das neue übernehmen.
- **Entitäten auflisten** (`HSH_ListEntities`).
- **Suite-Manifest anzeigen** (`HSH_GetSuiteManifest`).

## Status-Variablen / Controls

Der Hub trägt im Manifest gezielt Steuer-Controls plus die Verwaltungs-Whitelist;
die Statusvariablen werden von allen Domänen-Instanzen live gelesen.

| Ident | Typ | Rolle | Zweck |
|---|---|---|---|
| `AutomationEnabled` | Switch (~Switch) | `hub:automation` | Globales Automatik-Gate (HomeSuite-weit). Jede Entität liest es über `EntityModule::automationEnabled()`. Fail-safe Default **an** (einmalig geseedet). |
| `ShadeNorth` | Level (°, −180…180, Schritt 0.1) | `hub:northdeg` | Nordausrichtung als aktionierbare Slider-Variable; Setzen dreht alle Sonnenprofile (delegiert an die Property-Rotation in `ApplyChanges`). |
| `ArmLight` … `ArmMower` | Switch (~Switch) | `hub:arm` | Alter Bool-Scharf-Master je Domäne (Alt-Bindungen; bleibt synchron zum Mode). |
| `<Arm…>Mode` | Integer (Profil `HSSuite.ArmMode`) | — | 3-Zustand-Master je Domäne (siehe unten). |

### 3-Zustand-Scharf-Master (Aus / Auto / Scharf)

Für jede Domäne (`ArmLight`, `ArmHeating`, `ArmShading`, `ArmIrrigation`, `ArmAudio`,
`ArmPool`, `ArmMower`) gibt es eine Integer-Variable `<ident>Mode` mit dem Profil
`HSSuite.ArmMode`:

- **0 = Aus** — ganze Domäne im Schatten (nur Anzeige/Log, kein reales Schalten).
- **1 = Auto** — jede Zone entscheidet selbst (Instanz-Property `Armed`).
- **2 = Scharf** — ganze Domäne schaltet REAL.

Die Domänen-Instanzen lesen ihren Gate live über `EntityModule::armed()` (Hub-Vorrang,
`hubArmGate` liest den Mode zuerst). Der alte Bool-Master bleibt additiv erhalten und wird
bei Mode-Wechsel synchron gehalten (`bool = mode >= 2`). Beide werden beim ersten Lauf
verlustfrei aus dem bisherigen per-Instanz-`Armed`-Zustand geseedet, sodass der bestehende
Live/Schatten-Zustand exakt erhalten bleibt.

## Öffentliche Skript-/RPC-Funktionen

### Generisch (aus EntityModule)

| Funktion | Beschreibung |
|---|---|
| `string HSH_GetManifest(int $id)` | Hub-Manifest (Whitelist + Controls) |
| `string HSH_GetState(int $id)` | aktueller Statuswert-Satz |
| `string HSH_Manage(int $id, string $json)` | Whitelist-Dispatch der Hub-Ops (`{op, args}`) |
| `bool HSH_SetControl(int $id, string $Ident, mixed $Value)` | beliebigen actionable Control setzen |
| `mixed HSH_GetControlValue(int $id, string $Ident)` | aktuellen Statuswert lesen |

### Globale Bedienung

| Funktion | Zweck |
|---|---|
| `bool HSH_SetAutomationEnabled(int $id, bool $Enabled)` | globales Automatik-Gate (alle Module) |
| `bool HSH_GetAutomationEnabled(int $id)` | Gate lesen |
| `bool HSH_SetNorthAlignment(int $id, float $Degrees)` | Nordausrichtung; dreht alle Sonnenprofile additiv |
| `float HSH_GetNorthAlignment(int $id)` | Nordausrichtung lesen |
| `void HSH_SetArmMode(int $id, string $Ident, int $Mode)` | 3-Zustand-Master setzen (`ArmLight`…, 0/1/2); hält den Bool-Master synchron |

### Szenen & Licht-Automatik (Manage-Fassaden)

| Funktion | Zweck |
|---|---|
| `bool HSH_ApplyLightScene(int $id, string $SceneId)` | Licht-Szene anwenden |
| `string HSH_ListLightScenes(int $id)` | Szenen als JSON auflisten |
| `bool HSH_SetLightAutomationEnabled(int $id, bool $Enabled)` | Licht-Automatik an/aus **ohne** die Regeln zu verlieren |
| `string HSH_RotateSunProfiles(int $id, float $DeltaDeg)` | additive Rotation aller Sonnenprofile (Wartung) |

### Registry / Verwaltung / Provisionierung

| Funktion | Zweck |
|---|---|
| `string HSH_ListEntities(int $id)` | alle HomeSuite-Domänen-Instanzen auflisten |
| `string HSH_GetTopology(int $id)` | verschachtelter Raum-Baum (Haus→Bereich→Raum→Entitäten/Links) + `unassigned` |
| `string HSH_GetSuiteManifest(int $id)` | Manifeste aller Entitäten aggregiert |
| `string HSH_Provision(int $id, string $requestJson)` | Instanz **asynchron** provisionieren (Job einreihen, Timer startet) |
| `string HSH_RotateToken(int $id)` | neues `X-HS-Token` erzeugen |
| `void HSH_RunTimer(int $id, string $job)` | öffentliche Timer-Brücke (`provision` \| `lightauto`) |

### Manage-Ops (Whitelist, über `HSH_Manage`)

- **Verwaltung/Backup:** `provision`, `rotateToken`, `listSnapshots`,
  `restoreSnapshot` (destruktiv), `validate`, `organizeTree`, `detectContacts`,
  `detectSensors`.
- **Sonne/Beschattung:** `rotateSun` (Nordausrichtung, `deltaDeg`), `shadeLog`
  (Beschattungs-Log aller Räume).
- **Medienquellen:** `getSources`, `configureSources`, `spotifyAuthUrl`,
  `mediaProviders`, `mediaBrowse`, `mediaSearch`, `mediaResolve`.
- **Sonnenprofile (geteilte benannte Profile, ProfileEngine):** `profileTypes`,
  `profileList`, `profileGet`, `profileCreate`, `profileSetFields`, `profileRename`,
  `profileDuplicate`, `profileDelete` (destruktiv), `profileAssign`, `profileAssigned`.
- **Licht-Szenen (SceneEngine):** `lightSceneList`, `lightSceneGet`, `lightSceneSave`,
  `lightSceneCapture`, `lightSceneApply`, `lightSceneRename`, `lightSceneDelete` (destruktiv).
- **Licht-Automatik:** `lightAutoGet`, `lightAutoSet`, `lightAutoTick` (Test).

## WebHook `/hook/homesuite`

Wird erst bei Kernel-Ready registriert (die WebHook-Control existiert nicht in `Create()`).
Endpunkt-Matrix (§6.2):

| Methode | Endpunkt | Auth |
|---|---|---|
| GET | `?api=ping\|manifest\|suite\|entities\|state` | frei (Lesen) |
| POST | `?api=manage` | Header `X-HS-Token` |
| POST | `?api=provision` | Header `X-HS-Token` |
| GET | `?api=discover&module=&inst=&driver=` | Header `X-HS-Token` |
| * | `?api=migrate` | Header `X-HS-Token` |

Das Token wird für Schreib-/Migrate-Verben **ausschließlich** über den Header `X-HS-Token`
gelesen (Risiko K). `?api=manage` routet mit GUID-Whitelist + ModuleType-Check an das
Ziel-Modul (eigenes Hub-Manage oder ein HomeSuite-Device über dessen `Prefix_Manage`).

Ein zweiter Hook `/hook/hsspotify` nimmt den **Spotify-OAuth-Callback** entgegen (vor jeder
Token-Prüfung, da der Redirect von Spotify ohne Header-Token kommt) und speichert den
Refresh-Token.

## Besondere Hinweise

- **Nordausrichtung ist zentral:** Ändern von `ShadeNorthDeg` (Property oder `ShadeNorth`-
  Slider) dreht per Baseline-Delta-Vergleich in `ApplyChanges` ALLE Sonnenprofile mit —
  Hub-benannte (`profiles.sun`) und je ShadingDevice-Zone das `geoProfile`
  (via `HSSH_Manage`/`rotateGeo`). Beim ersten Lauf wird nur die Baseline gemerkt.
- **ShadingProfiles-Temp-Schema (innen/außen):** Die geteilten Beschattungsprofile tragen
  Temperatur-Gates innen und außen; ein ShadingDevice fährt erst, wenn beide Temp-Gates
  (innen + außen) erfüllt sind (Somfy-feedbacklose Rollos werden dabei über einen
  zeitbasierten Fahr-Executor mit Sekunden-Ramp gefahren und per Positions-Spiegel
  zurückgemeldet; Selbstkalibrierung am Endanschlag). Diese Profile werden zentral im Hub
  über die `profile*`-Ops verwaltet und den Zonen zugewiesen.
- **QueryInterval je Modul:** Die einzelnen Domänenmodule (nicht der Hub) tragen ihr eigenes
  Abfrageintervall; der Hub liefert nur die haus-weiten Sensoren/Schwellen und das
  Automatik-/Scharf-Gate.
- **BatteryManager:** eigenständiges Modul (Dedup/Erkennung aller Gerätebatterien,
  Register + Tabelle); kein Bestandteil des Hub, wird aber über dieselbe
  Registry-/Topologie-Logik im Objektbaum sichtbar.
- **Provisionierung nie synchron:** `HSH_Provision` reiht nur einen Job ein; ein
  Timer (Tick 500 ms) legt pro Tick genau eine Instanz idempotent an und stoppt bei leerer
  Queue. Offene Jobs werden nach Neustart automatisch wieder aufgenommen.
- **Baum-Transparenz:** `syncHubReferences()` legt sichtbare `bl_`-Links + Löschschutz auf
  alle vom Hub referenzierten physischen Objekte (globale Sensoren/Standort,
  Automatik-Regeln, Szenen-Mitglieder).

### Beispiele

```php
HSH_SetAutomationEnabled($hub, false);        // alle Automatiken pausieren
HSH_SetNorthAlignment($hub, -12.6);           // Nordausrichtung korrigieren (dreht Sonnenprofile)
HSH_SetArmMode($hub, 'ArmShading', 2);        // Beschattung scharf (ganze Domäne schaltet real)
HSH_ApplyLightScene($hub, 'abend');
echo HSH_GetTopology($hub);                    // Raum-Baum für Navigation/Widgets
```

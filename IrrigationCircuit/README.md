# IrrigationCircuit (HSIR)

Domänen-Modul „Bewässerung" der HomeSuite. **Eine Instanz = ein Bewässerungskreis.**
Portierung von IPSWatering. Das Modul startet/stoppt den Kreis, berechnet die effektive
Laufdauer (Basisdauer × Saison × Temperatur × Verdunstung), fährt einen zeitbasierten
Einzel-Lauf und berücksichtigt Regen-/Kälte-Gates. Der ROH-Aktor wird gebunden — **nie**
eine IPSWatering-Variable.

- **GUID:** `{D264A82B-DE31-45CC-8AF2-8F4C5D076508}`
- **Prefix:** `HSIR`
- **Typ:** 3 (Gerät) · **Vendor:** Hoep
- **Aliase:** `IrrigationCircuit`, `HSIR`, `HomeSuite Bewaesserung`
- **Basisklasse:** `\Hoep\HomeSuite\EntityModule` (Manifest → Variablen, native `RequestAction`, RPC-Trio, Hub-Anbindung, ScheduleEngine)

## Architektur / HAL

Der Kreis schaltet über den generischen **`IValve`**-Treiber `generic-valve`
(HAL, `DriverFactory::create`). Gebunden wird immer ein Roh-Aktor mit einem von drei Modi
(Nutzer-Vorgabe: universell, immer Variable **oder** Skript):

| Modus | Bindung | Timing |
|---|---|---|
| `duration` | aktionsfähige Sekunden-Variable (z. B. LinkTap `StartWateringImmediately`) + optional Stop | **Gerät timt selbst** (selfTiming), Modul überwacht nur per Watchdog |
| `switch` | bool-Schalt-Variable (on/off) | **Modul timt** die Dauer und schließt selbst |
| `script` | Start-/Stop-Skript (+ optionale Dauer-Variable) | je nach Skript |

Zusätzliche optionale Bindungen (modus-übergreifend): Ist-Zustand (`feedbackVarId`, bool),
Durchfluss (`flowVarId`, l/min), Regensensor (`sensorId`, mm).

Ist kein Treiber/keine Mindest-Bindung konfiguriert, bleibt das Modul im
**Schatten-Modus** (rechnet und protokolliert, schaltet aber nichts).

### Scharf-/Schatten-Modus (Hub-Vorrang)

Real geschaltet wird nur, wenn **scharf** (`Armed`). Der Hub ist der übergeordnete
3-Zustand-Scharf-Master je Domäne über `ArmIrrigationMode`:

- **0 = Aus** → alle Instanzen im Schatten-Modus
- **1 = Auto** → die einzelne Instanz entscheidet über ihre `Armed`-Property
- **2 = Scharf** → alle Instanzen schalten real

Fallback auf den alten Bool-Master `ArmIrrigation` (`hubArmGate()`), wenn kein Mode-Ident
existiert. `armedEffective()` gibt dem Hub-Master immer Vorrang vor der Instanz-Property.
Der globale Automatik-Schalter `AutomationEnabled` des Hubs kann zusätzlich alle
Zeitplan-Läufe stilllegen (`automationEnabled()`, fail-safe = true ohne Hub).

## Konfiguration

### Native Properties (`Create`)

Flache Bindungsfelder sind native Symcon-Properties; komplexe Strukturen (temp/rain/evap)
und Laufzeitzustand liegen im FabricStore.

| Property | Typ | Default | Bedeutung |
|---|---|---|---|
| `Driver` | string | `''` | `generic-valve` oder leer (Schatten) |
| `Mode` | string | `switch` | `duration` / `switch` / `script` |
| `SwitchVarId` | int | 0 | Schalt-Variable (Modus switch) |
| `StartVarId` | int | 0 | Sekunden-Startvariable (Modus duration) |
| `StopVarId` | int | 0 | Stop-Variable (optional, duration) |
| `OnScriptId` | int | 0 | Start-Skript (Modus script) |
| `OffScriptId` | int | 0 | Stop-Skript (optional, script) |
| `DurationVarId` | int | 0 | Dauer-Variable (optional, script) |
| `FeedbackVarId` | int | 0 | Ist-Zustand (bool) |
| `FlowVarId` | int | 0 | Durchfluss (l/min) |
| `SensorId` | int | 0 | Regensensor (mm); Hub-globaler `IrrRainSensorId` gewinnt, wenn gesetzt |
| `MaxRuntimeMin` | int | 120 | harter Laufzeit-Clamp |
| `Invert` | bool | false | Schaltlogik invertieren |
| `Armed` | bool | false | scharf/Schatten (Instanz-Ebene, Hub hat Vorrang) |
| `ConfigSchema` | int | 0 | Migrations-Marker (Store→Properties) |
| `QueryInterval` | int | 30 | **Abfrage-/Refresh-Intervall in Sekunden** (Boden 2 s gegen Hot-Loop) |

### Store-Config (komplex)

- `temp`: `enabled`, `tempVarId`, `blockBelowC` (10), `coldBelowC` (20), `coldPct` (80), `hotAboveC` (28), `hotPct` (120)
- `rain`: `enabled`, `thresholdMm` (2.0)
- `evap`: `enabled`, `et0VarId`, `et0RefMmPerDay` (4.0)
- `seeded`: Einmal-Marker, damit Duration/SeasonalAdjust nicht bei 0 starten (Defaults 15 min / 100 %)

### Konsolen-Formular (`GetConfigurationForm`)

Notfall-/Erstkonfiguration; Zeitpläne und Automatik laufen sonst im LiveViewBuilder.
Das Formular bietet: Aktor-Modus-Wahl, `QueryInterval`, die modus-spezifischen
Variablen-/Skript-Felder, gemeinsame Sensoren (Ist-Zustand, Durchfluss, Regensensor),
`MaxRuntimeMin`, `Invert` sowie Buttons „Bindung übernehmen" (ruft `configureDriver`),
„Jetzt bewässern (Testlauf)", „Stoppen", „Bindung prüfen". Der Status-Label zeigt Health
und Scharf-Zustand.

## Status-Variablen / Controls (Manifest)

Domäne `irrigation`, Icon `Drops`, Label „Bewässerung".

| Ident | Typ | Rolle | Bedienbar |
|---|---|---|---|
| `Active` | Switch | active | ja — Start/Stop mit konfig. Dauer |
| `Automatic` | Switch | automatic | ja — Zeitplan-Automatik |
| `Run` | Command | run | ja — Sofortlauf (Minuten) |
| `Stop` | Command | stop | ja — laufende Bewässerung abbrechen |
| `Duration` | Setpoint | duration | ja — Basisdauer 0–240 min |
| `SeasonalAdjust` | Level | adjust | ja — Saison-Faktor 0–200 % |
| `Program` | Select | program | ja — 0 Manuell / 1 Täglich / 2–4 jeden n-ten Tag / 5 Mo·Mi·Fr / 6 Mo·Do |
| `Running` | Reflect | running | nein |
| `RainBlocked` | Reflect | rainblocked | nein |
| `Rain` | Reflect (mm) | rain | nein |
| `LastRun` | Reflect (string) | lastrun | nein — Status/letzter Lauf |
| `Online` | Reflect | online | nein |
| `BindHealth` | String (verwaltet) | — | nein — Bindungs-Health-Text |

`Active`, `Duration`, `Program` sind automatisiert (`isAutomated`) → Bedienung öffnet ein
`manualHold`-Fenster (Automatik-Hoheit).

## Zeitplan-Engine

Der Zeitplan-Wahrheitsträger ist ein **nativer Symcon-Wochenplan** (Ereignis Typ 2, Ident
`WateringSchedule`, „Bewässerungsplan", Aktionen Aus/An). `ensureScheduleEvent()` legt ihn
an und migriert einmalig den ScheduleEngine-Plan („Standard", value-until-end) in
Wochenplan-Punkte (`migrateScheduleToEvent`). `scheduleOnAt($now)` liest, ob gerade „An"
aktiv ist.

Der Refresh-Timer wertet `runSchedule()` aus: Ist Automatik an **und** das Programm heute
fällig (`programDue`) **und** der Wochenplan „An" → Flanke 0→An startet einen Lauf über die
effektive Dauer (Gates aktiv), An→0 stoppt. `programDue` filtert „jeden n-ten Tag" über den
gespeicherten `lastRunDate`-Anker.

## Dauer- & Gate-Berechnung

`effectiveSeconds()` = Basisdauer × (SeasonalAdjust/100) × Temperatur-Faktor ×
Verdunstungs-Faktor, hart auf `maxRuntimeMin` geklemmt.

- **Temperatur-Faktor** (`ruleTempFactor`): > `hotAboveC` → `hotPct` %, < `coldBelowC` → `coldPct` %, sonst 100 %. Keine Temperatur/deaktiviert → 1.0.
- **Kälte-Sperre** (Start-Gate): < `blockBelowC` → kein Start.
- **Regen-Gate**: Regensensor ≥ `thresholdMm` → gesperrt (`RainBlocked`).
- **Verdunstungs-Faktor**: ET0_aktuell / ET0_ref, geklemmt auf 0.3–2.0; ohne Quelle 1.0.

Explizite Läufe (`Run`, `Active`, `runNow` mit force, `RunNow`) umgehen die Gates bewusst;
geplante Läufe prüfen sie.

## Timer

| Timer | Zweck |
|---|---|
| `Refresh` (`HSIR_Refresh`) | zyklisch (QueryInterval, min 2 s): Ist-Zustand spiegeln, Watchdog, `runSchedule` |
| `RunStop` (`HSIR_RunStop`) | Ein-Schuss: getimtes Schließen (switch-Modus) bzw. Watchdog-Ende (selfTiming) |

Der Watchdog stoppt einen Lauf hart, wenn er Dauer + Puffer überzieht.

## Verwaltung (mgmt-Ops, via `HSIR_Manage`)

| Op | Zweck |
|---|---|
| `configureDriver` | Aktor-Bindung schreiben (Modus + Variablen/Skript, validiert) |
| `getConfig` | Konfiguration lesen (Diagnose) |
| `validate` | Bindung prüfen / Health (Diagnose) |
| `driverProbe` | Treiber-Status: capabilities, isOpen, flow |
| `runNow` | Jetzt bewässern (`minutes`, `force`) |
| `stopNow` | Stoppen |
| `setArmed` | Scharfschalten / Schatten-Modus (setzt `Armed`-Property) |
| `configureAutomation` | Regeln setzen (temp/rain/evap), Store-only |
| `computeProbe` | Trockenlauf: berechnete Dauer + Gate-Entscheidung ohne Schalten |
| `migrateConfig` | Einmal-Migration FabricStore-config → native Properties |
| `updateProfile` / `getSchedule` | Wochenplan (ScheduleEngine „Standard") bearbeiten/lesen |
| `importLegacy` | Aus einer LinkTap-Instanz die aktionsfähigen Variablen als `duration`-Bindung übernehmen (armed bleibt false) |

## Baum-Transparenz & Löschschutz

`bindingTargets()` legt sichtbare Bindungs-Links (`bl_…`) auf die real genutzten
Roh-Variablen je Modus + gemeinsame Sensoren + temp/et0 an. `syncReferences()` registriert
`RegisterReference` nur auf diese Roh-Bindungen (nie IPSWatering-Variablen) als
Löschschutz. `refreshMirrors()` spiegelt die Klimaregeln als read-only `ClimateJson`.

## Skript-API (PHP-Befehlsreferenz)

> Alle `Set…`/`Run…`-Funktionen gehen intern über `RequestAction`.
> **Realer Effekt nur bei `Armed=true`** (bzw. Hub-Mode Scharf/Auto+armed).
> `RunNow` umgeht die Gates bewusst (expliziter Nutzerbefehl).

### Generisch (aus EntityModule)
| Funktion | Beschreibung |
|---|---|
| `bool HSIR_SetControl(int $id, string $Ident, mixed $Value)` | beliebigen actionable Control setzen |
| `mixed HSIR_GetControlValue(int $id, string $Ident)` | aktuellen Statuswert lesen |
| `string HSIR_Manage(int $id, string $json)` | mgmt-Op ausführen (siehe Tabelle oben) |

### Setzen / Aktionen
| Funktion | Zweck |
|---|---|
| `bool HSIR_SetActive(int $id, bool $On)` | Kreis ein/aus (Start/Stop mit konfig. Dauer) |
| `bool HSIR_SetAutomatic(int $id, bool $On)` | Zeitplan-Automatik |
| `bool HSIR_SetDuration(int $id, int $Minutes)` | Basisdauer (0–240 min) |
| `bool HSIR_SetSeasonalAdjust(int $id, int $Percent)` | saisonale Anpassung (0–200 %) |
| `bool HSIR_SetProgram(int $id, int $Program)` | Programm 0–6 |
| `bool HSIR_RunNow(int $id, int $Minutes = 0)` | Sofortlauf; 0 = konfigurierte Dauer (umgeht Gates) |
| `bool HSIR_Stop(int $id)` | laufende Bewässerung abbrechen |
| `bool HSIR_SetArmed(int $id, bool $Armed)` | scharf/Schatten (Cutover) |

### Lesen
| Funktion | Zweck |
|---|---|
| `bool HSIR_IsRunning(int $id)` | läuft gerade? |
| `bool HSIR_IsRainBlocked(int $id)` | durch Regen-/Kälte-Gate gesperrt? |
| `string HSIR_GetLastRun(int $id)` | Status/letzter Lauf |
| `float HSIR_GetEffectiveMinutes(int $id)` | berechnete Laufdauer (Basis × Saison × Temp × ET0) |

### Beispiele
```php
HSIR_RunNow(11743, 10);              // 10 Minuten sofort
HSIR_Stop(11743);
HSIR_SetSeasonalAdjust(11743, 120);  // 20 % mehr
HSIR_SetArmed(11743, true);          // scharf: schaltet real
echo HSIR_Manage(11743, json_encode(['op'=>'computeProbe']));  // Trockenlauf
```

## Besondere Hinweise

- **Roh-Aktor binden, nie IPSWatering-Variable.** Universell: immer Variable ODER Skript.
- **Schatten-Modus** ist der sichere Default (`Armed=false`): Läufe werden nur protokolliert (`LastRun` „Schatten: …"), es wird nichts real geschaltet.
- **Hub-Vorrang:** `ArmIrrigationMode` (0/1/2) und `AutomationEnabled` überstimmen die Instanz.
- **LinkTap** ist der typische `duration`-Aktor (Gerät timt selbst); `importLegacy` übernimmt `StartWateringImmediately`/`StopWatering`/`WateringActive` automatisch.
- **Config-Migration:** flache Felder liegen als native Properties (`migrateConfig` einmalig); temp/rain/evap/Zeitplan/Laufzeit bleiben im Store.
- **Keine Hausbatterie/kein Fremd-Rechnen** — Werte kommen aus gebundenen Quellen; ET0/Temperatur werden gelesen, nicht selbst modelliert.

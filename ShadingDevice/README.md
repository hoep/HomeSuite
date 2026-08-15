# ShadingDevice (HSSH)

Domänen-Modul „Beschattung" der HomeSuite. **Eine Instanz = ein Rollo / eine Markise / eine Jalousie.** Das Modul fährt auf eine Zielposition, bewegt richtungsweise (auf/ab/stop), steuert Modus, Positions-Wochenplan, Saison und das Sonnenstands-Profil und trifft eine regelbasierte Automatik-Entscheidung (Sturm > Sonne > Zeitplan) mit Aussperr-Schutz.

- **Modul-ID:** `{A9645ED8-CB55-43B8-869B-BFF6ACFC8DC1}`
- **Prefix:** `HSSH`
- **Aliase:** ShadingDevice, HSSH, HomeSuite Beschattung
- **Typ:** 3 (Instanz-Modul), Vendor Hoep
- **Basis:** `EntityModule` der HomeSuite (typisierte Controls, FabricStore, mgmt-Ops, geteilte Profile vom Hub)

## Überblick / Betriebsmodell

Das Modul ist ein **HAL-Aufsatz** über die real vorhandene Beschattungs-Hardware — es baut IPSShadowing nicht nach, sondern bindet dessen Positions-Variable bzw. spricht Somfy-RTS/Homematic direkt an.

Zentrale Sicherheitsmechanik ist der **Schatten-Modus (Armed=false)**: solange nicht scharfgeschaltet ist, rechnet und protokolliert die Automatik nur, schreibt aber **nichts** aufs Gerät. Erst `Armed=true` (Cutover) gibt reale Fahrbefehle frei. So laufen HSSH und die Altsteuerung kollisionsfrei parallel, bis pro Rollo übernommen wird.

Der Hub-Master (3-Zustand-Scharf-Schalter der Domäne) hat Vorrang: `armed()` ist `armedEffective(instance-Armed)`, d. h. der Hub kann alle Rollos zentral scharf/Schatten schalten.

## Architektur / HAL

| Schicht | Rolle |
|---|---|
| **Treiber (`IShutter`/`IDriver`)** | gerätespezifische Fahr-Primitive: `moveTo()`, `move(dir)`, `readPosition()`, `referenceRun()`, `capabilities()` |
| **Fahr-Executor** | absolut vs. zeitbasiert (siehe unten) |
| **Entscheidungslogik** | `computeDecision()` → `evalRules()` (Sturm/Sonne/Zeitplan), `reconcile()` fährt nach |
| **Reflect** | `reflectFromDriver()` spiegelt Ist-Position/Online in Statusvariablen |

Konfigurierbare Treiber (`configFields.driver` / Formular):

- **generic-shutter** — Absolutposition 0..100 % (generische Positions-Variable / Homematic-LEVEL); wird an die IPSShadowing-Positionsvariable gebunden.
- **somfy-rts** — Bus-Rollo über Client-Socket (RTS-Gateway, Default #<ID>): nur auf/ab/stop, **kein Positions-Feedback**, Position aus Fahrzeiten geschätzt.
- **hm-shutter** — Homematic-Rollo/Markise (LEVEL-Datenpunkt, absolut).

### Zeitbasierter Fahr-Executor (feedbacklose Treiber, z. B. Somfy)

Weil Somfy RTS keine Position zurückmeldet, rechnet das Modul die Lage selbst (`ShadeKinematics`):

- **Absolut-Treiber:** `driveTo()` ruft schlicht `moveTo(target)`.
- **Travel-only:** `move(dir)` jetzt + periodischer Fahr-Tick (`MoveDone`, ~1 s) interpoliert die Position live aus verstrichener Fahrzeit (**Sekunden-Ramp**) und meldet sie laufend zurück (`pushPosition` → `ActualPosition` **und** die gespiegelte IPSShadowing-Positionsvariable). Abschluss über `finishMove()`.
- **Selbstkalibrierung am Endanschlag:** ist die Position unbekannt und Ziel = 0 % oder 100 %, wird vom Gegen-Endanschlag voll durchgefahren; am Anschlag stoppt der Motor selbst (kein Stop-Telegramm nötig) → Position ist danach bekannt (`posKnown`). Mittelpositionen sind erst nach einer Referenzfahrt anfahrbar.
- **Referenzfahrt** (`referenceRun` / Formular-Buttons): fährt real in den Endanschlag und setzt `estPos` exakt auf 0 % (voll auf) bzw. 100 % (voll zu). Bei Sturm/Regen gesperrt.
- **Trockenlauf-Vorschau** (`drivePreview`): zeigt im Schatten-Modus/Log Richtung, rohes Telegramm (Hex) und geplante Fahrdauer — so ist vor dem Scharfschalten prüfbar, was real gesendet *würde*.

Feedbacklose Treiber fallen für die Anzeige auf die gespiegelte IPSShadowing-Position zurück statt „unbekannt".

## Konfiguration

### Native Properties (`Create()`)

| Property | Typ | Default | Zweck |
|---|---|---|---|
| `Driver` | string | `''` | Treiber-ID (leer = Schatten-Modus/kein Gerät) |
| `Invert` | bool | false | Richtung/LEVEL invertieren |
| `PositionId` | int | 0 | Positions-Variable (Ziel & Rückmeldung, generic/HM) |
| `AutomaticId` | int | 0 | IPSShadowing-Automatik-Bool (Cutover/Rollback) |
| `SocketId` | int | 0 | Client-Socket RTS-Gateway (Somfy) |
| `Channel` | int | 0 | RTS-Kanal 1..16 |
| `Repeat` / `StopRepeat` | int | 2 / 4 | Sende-Wiederholungen |
| `GapMs` | int | 50 | Telegramm-Pause |
| `TimeOpening` / `TimeClosing` | int | 0 | Fahrzeit AUF / ZU in s (Somfy-Kinematik) |
| `InstanceId` | int | 0 | Homematic-Instanz (LEVEL/STOP) |
| `LevelVarId` | int | 0 | LEVEL-Variable |
| `WindStormKmh` | float | 50.0 | Sturm-Schwelle (Hub-Global überschreibt) |
| `SafePos` | int | 0 | Sturm-/Regen-sichere Position |
| `RainClose` | bool | false | bei Regen schließen |
| `SunSource` | string | `location` | Sonnenzeit-Quelle: `location` \| `coords` |
| `LocationId` / `Lat` / `Lon` | int/float | 0 | Standort für Sonnen-Anker |
| `Armed` | bool | false | scharf (Cutover) — schaltet reale Telegramme frei |
| `ConfigSchema` | int | 0 | Migrations-Marker |
| `QueryInterval` | int | 30 | **Abfrage-/Refresh-Intervall in Sekunden** (Untergrenze 2 s) |

Flache Felder sind Properties; **komplexe/variable Strukturen** (`env`, `tempGate`, `dayBegin`/`dayEnd`, `doorIds`, `geoProfile`, `schedules`, `RtState`) bleiben im **FabricStore**. `cfg()` merged Store + Properties, wobei Properties für flache Keys gewinnen und **globale Hub-Defaults** (ein Sensorsatz/Standort/Sturmschwelle für alle Rollos) Vorrang haben, wenn gesetzt.

### Konfigurationsformular (`GetConfigurationForm`)

Rein für Geräte-Bindung + Automatik-Sensoren; Sicherheits-Schwellen und Sonnen-/Wetterprofile kommen aus den **geteilten Profilen (LiveViewBuilder / Hub)**:

- **Abfrage-Intervall (s)** → `QueryInterval`
- **Treiber** + Absolut-/Somfy-/Homematic-Felder → Button „Bindung übernehmen" (`configureDriver`)
- **Referenzfahrt voll AUF/ZU** + „Bindung prüfen" (`referenceRun` / `validate`)
- **Aussperr-Schutz:** Tür-/Fensterkontakte (markenübergreifend via `Contacts::detect`) → `configureAutomation.doorIds`
- **Umgebungs-Sensoren:** Sonnen-Azimut/Elevation, Wind, Regen, Helligkeit → `configureAutomation.env`
- **Sonnenzeit-Quelle:** Location-Instanz oder eigene Koordinaten
- **Scharfschalten / Schatten-Modus** (`setArmed`) mit Live-Status-Anzeige

## Status-Variablen / Controls (Manifest)

Domäne `shading`, Icon `Window`:

| Ident | Typ | Aktion | Bedeutung |
|---|---|---|---|
| `Position` | LEVEL 0..100 % (Step 5) | ja | Zielposition (0=offen … 100=zu) |
| `Movement` | COMMAND | ja | 1=Auf, 2=Ab, 0=Stop |
| `ActualPosition` | REFLECT 0..100 % | nein | Ist-Position (gespiegelt/geschätzt) |
| `Mode` | SELECT | ja | 0=Auto, 1=Manuell, 2=Sonne |
| `Plan` | SELECT | ja | 0=Anwesend, 1=Abwesend, 2=Urlaub |
| `Season` | SELECT | ja | 0=Sommer, 1=Winter |
| `SunAzBgn` | LEVEL 0..360° | ja | Sonnen-Azimut von — **Quelle der Wahrheit** fürs Sonnenprofil |
| `SunAzEnd` | LEVEL 0..360° | ja | Sonnen-Azimut bis |
| `SunElev` | LEVEL −10..90° | ja | Sonnen-Elevations-Schwelle |
| `SunClose` | LEVEL 0..100 % | ja | Sonne-Schließgrad je Rollo (überschreibt Profil, z. B. West 100 %) |
| `Online` | REFLECT bool | nein | Erreichbarkeit |

Zusätzliche Anzeige-Spiegel (Baum-Sichtbarkeit, JSON): `ScheduleJson` (Positions-Wochenplan), `AutomationJson` (env/tempGate/dayBegin/dayEnd/doorIds/geoProfile).

**Positions-Wochenplan** (`profileTypes.roomProfile`): 2 Achsen `Plan × Season × Wochentag`, Slots `end HH:MM → val 0..100`, mit Sonnen-Ankern (sunrise/sunset + Offset). Editor `weekedit-hm`.

## Automatik-Entscheidung

`computeDecision()` baut ein `evalRules`-Ruleset mit fester Priorität:

1. **Safety (Sturm):** Wind ≥ Schwelle **oder** (Regen + `rainClose`) → sichere Position (`safePos`). Läuft auch bei global abgeschalteter Komfort-Automatik.
2. **Sonne (comfort):** `evalGeo(az, el, bright, geoProfile)` vergleicht Sonnenstand gegen das Zonen-Sonnenprofil. **Min-Dwell-Entprellung** (`SUN_DWELL` 300 s) gegen Wolken-Flattern.
3. **Zeitplan (comfort):** aktiver Positions-Wochenplan (Sonnen-Anker aufgelöst), Fallback Tag/Nacht-Profil.

**Temp-Gate (innen + außen):** Sonnen-Beschattung greift nur, wenn die Temperatur über Schwelle liegt — zwei Schwellen (`sensorId ≥ aboveC` **innen** UND `outSensorId ≥ outAboveC` **außen**). Fehlender Sensor/Wert gilt als erfüllt (`ignore`), sodass z. B. das Schlafzimmer nur nach Außentemperatur schattet.

**Aussperr-Schutz (Tür-Guard):** bei offenem Kontakt wird **Zufahren** blockiert (Auffahren + Sturm-Rückzug bleiben erlaubt).

`reconcile()` fährt nur bei Drift > `POS_TOLERANCE` (3 %) und nur bei `Armed=true`; im Schatten-Modus wird nur die Trockenlauf-Vorschau geloggt. Echte Fahrten + manuelle Befehle landen im Ringpuffer-**DecisionLog** (max. 80 Einträge).

### Sonnenprofil (geoProfile)

Das Sonnenprofil je Zone lebt als **echte, editierbare Baum-Variablen** (`SunAzBgn/SunAzEnd/SunElev/SunClose`) — sie sind die Quelle der Wahrheit (ersetzen das früher im FabricStore versteckte `geoProfile`); `evalGeo` liest sie, die **Nordausrichtung** (`rotateGeo`) dreht die Azimute. Seeding aus dem Store erfolgt einmalig, solange die Variablen leer sind.

## Öffentliche Skript-/RPC-Funktionen

> Alle `Set…`-Funktionen gehen intern über `RequestAction`. **Realer Effekt nur bei `Armed=true`** — sonst Schatten-Modus.

### Generisch (EntityModule)
| Funktion | Beschreibung |
|---|---|
| `bool HSSH_SetControl(int $id, string $Ident, mixed $Value)` | beliebigen actionable Control setzen |
| `mixed HSSH_GetControlValue(int $id, string $Ident)` | aktuellen Statuswert lesen |

### Setzen
| Funktion | Zweck |
|---|---|
| `bool HSSH_SetPosition(int $id, int $Percent)` | Zielposition 0=offen … 100=zu |
| `bool HSSH_Move(int $id, string $Direction)` | `up`\|`auf`\|`1` / `down`\|`ab`\|`zu`\|`2` / `stop`\|`0` |
| `bool HSSH_MoveUp/MoveDown/MoveStop(int $id)` | Kurzformen |
| `bool HSSH_SetMode(int $id, int $Mode)` | 0=Auto, 1=Manuell, 2=Sonne |
| `bool HSSH_SetPlan(int $id, int $Plan)` | 0=Anwesend, 1=Abwesend, 2=Urlaub |
| `bool HSSH_SetSeason(int $id, int $Season)` | 0=Sommer, 1=Winter |
| `bool HSSH_SetSunAzimuthBegin/End(int $id, int $Deg)` | Sonnen-Azimut 0–360° |
| `bool HSSH_SetSunElevation(int $id, int $Deg)` | Sonnen-Elevation −10–90° |
| `bool HSSH_SetArmed(int $id, bool $Armed)` | scharf/Schatten (Cutover) — schaltet reale Telegramme frei |

### Lesen
| Funktion | Zweck |
|---|---|
| `int HSSH_GetPosition(int $id)` | Ziel-Position |
| `int HSSH_GetActualPosition(int $id)` | Ist-Position |
| `int HSSH_GetMode(int $id)` | Modus |
| `bool HSSH_IsOnline(int $id)` | Erreichbarkeit |

### Timer-Callbacks (SDK-public)
| Funktion | Zweck |
|---|---|
| `HSSH_Refresh(int $id)` | Refresh-Timer: Reflect + Reconcile (Intervall = `QueryInterval` s) |
| `HSSH_MoveDone(int $id)` | Fahr-Tick der zeitbasierten Fahrt (Ramp + Abschluss) |

### Verwaltung: `HSSH_Manage(int $id, string $json)`

`{"op":"…","args":{…}}`. Whitelist der `op`s:

| op | Zweck |
|---|---|
| `createEntity` / `renameEntity` / `deleteEntity` | Instanz-Lebenszyklus |
| `configureDriver` | Treiber/Bindung setzen (nur Store, kein Gerät) |
| `configureAutomation` | env/tempGate/dayBegin/dayEnd/doorIds/geoProfile/Sturm/Standort |
| `setArmed` | Scharfschalten / Schatten-Modus (setzt Property `Armed` + ApplyChanges) |
| `updateProfile` | Positions-Wochenplan schreiben (Variante/Tag) |
| `getSchedule` | Wochenplan + Varianten + Sonnen-Events lesen |
| `duplicateProfile` / `assignProfile` / `setActivePlan` | Plan duplizieren/zuweisen/setzen (Plan+Season) |
| `importLegacy` | aus IPSShadowing importieren |
| `migrateConfig` | Store-Config einmalig auf Properties migrieren |
| `rotateGeo` | Sonnenprofil-Azimut drehen (Nordausrichtung) |
| `referenceRun` | Referenzfahrt in den Endanschlag (real, kalibriert) |
| `driverProbe` / `reconcileProbe` | Diagnose: Treiber-Status / Regel-Entscheidung (Trockenlauf, read-only) |
| `getConfig` / `getLog` / `validate` | Konfiguration / DecisionLog / Bindungs-Gesundheit lesen |
| `command` | Bedienen (Position/Fahrt/Modus) |

### Beispiele
```php
HSSH_SetPosition(16537, 100);  // schließen
HSSH_MoveUp(16537);            // öffnen
HSSH_SetMode(16537, 2);        // Sonnenautomatik
HSSH_Manage(16537, json_encode(['op'=>'reconcileProbe']));   // Trockenlauf
```

## Zusammenspiel im HomeSuite-Verbund

- **Hub (3-Zustand-Scharf-Master):** je Domäne über `<ident>Mode` (Aus/Auto/Scharf) und `hubArmGate`; steuert `armedEffective()` und liefert globale Sensoren/Standort/Sturmschwelle sowie das **ShadingProfiles-Temp-Schema (innen/außen)**. Nordausrichtung + geteilte Sonnenprofile werden vom Hub gepusht.
- **QueryInterval** je Modul/Instanz steuert den Reflect-/Reconcile-Takt.
- **BatteryManager:** sammelt/dedupliziert Gerätebatterien (u. a. Homematic-Serial), erkennt sie modulübergreifend und liefert Register + Tabelle — betrifft batteriebetriebene Beschattungs-Aktoren (z. B. Markise).

## Besondere Hinweise

- **Nie am Live-Builder headless testen** — Beschattung ist produktiv (17 Instanzen migriert).
- Rollos = IPSShadowing (#<ID>) — HSSH **bindet**, baut nicht nach; 16/17 Rollos ohne Aktor-Variable (rohe Bus-Telegramme, Somfy), nur die Markise ist Homematic.
- Somfy ist **feedbacklos** — verlässliche Positionen erst nach Referenzfahrt; bis dahin nur Endanschläge kalibrierend anfahrbar.
- Cutover (`Armed`) rollout-weise; im Schatten-Modus schreibt HSSH **nichts** aufs Gerät.

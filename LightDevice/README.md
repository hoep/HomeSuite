# LightDevice (HSLT)

Domänen-Modul „Licht" der HomeSuite — **eine Instanz = eine Leuchte / ein Lichtkreis**.
Schaltet An/Aus, Helligkeit, Farbe und Farbtemperatur des gebundenen Leuchtmittels und
spiegelt dessen Ist-Zustand. Löst schrittweise die alte `IPSLight`-Struktur ab.

- **GUID:** `{B7E1C3A4-5D62-4F08-9A1E-2C7D6B4F0E93}`
- **Prefix:** `HSLT`  ·  Aliase: `LightDevice`, `HSLT`, `HomeSuite Licht`
- **Typ:** 3 (Geräte-/Entitätsinstanz)  ·  Vendor: Hoep
- **Basis:** `\Hoep\HomeSuite\EntityModule` (Manifest → Variablen, native `RequestAction`, RPC-Trio, Hub-Anbindung)

## Überblick / Architektur

LightDevice bindet **reale Symcon-Variablen ODER Skripte** über den generischen HAL-Treiber
`generic-light` (`\Hoep\HomeSuite\Drivers\Light\GenericBoundLight`, HAL-Interface `ILight`).
Nutzer-Vorgabe: universell — immer Variable **oder** Skript, nie ein Roh-Protokolltreiber.

Die **Fähigkeiten** (switch / dim / color / cct) ergeben sich automatisch daraus, welche
Kanäle gebunden sind — analog zu den LVB-Light-Kacheln (Schalter + optional Helligkeit),
nur erweitert um Farbe/CCT/Leistung.

### Schatten-Modus (Cutover-Sicherheit)

Zentrales Sicherheitskonzept aller HomeSuite-Domänenmodule:

- `Armed = false` → **Schatten-Modus**: Bedienung und Refresh rechnen und spiegeln nur in die
  Statusvariablen, es wird **nichts real geschaltet** (nur SendDebug-Protokoll).
- `Armed = true` → **scharf**: reale Aktoren (Variable/Skript) werden geschaltet.

So kann eine Instanz vollständig konfiguriert und beobachtet werden, bevor die reale
Umschaltung (Cutover, Projektstufe L12) freigegeben wird.

### Hub-Scharf-Master (3-Zustand) hat Vorrang

Der effektive Scharf-Zustand wird über `armedEffective()` aus dem **domänenweiten Scharf-Master**
im HomeSuite-Hub abgeleitet (`hubArmGate()`), der die Instanz-Property `Armed` **überstimmt**:

- Hub-Variable `ArmLightMode`: `0 = Aus` (alle Instanzen Schatten), `1 = Auto` (jede Instanz
  entscheidet per eigener `Armed`-Property), `2 = Scharf` (alle real).
- Fallback: alter Bool-Master `ArmLight` (ON = alle real, OFF = alle Schatten).

Existiert kein Hub bzw. keine Master-Variable, gilt die Instanz-Property `Armed`.

## Konfiguration (native Instanz-Properties)

Die gesamte Konfiguration liegt in **nativen Symcon-Instanz-Properties** (Instanz-Formular,
gespeichert bei „Änderungen übernehmen") — nicht mehr im FabricStore-JSON. `ApplyChanges`
triggert danach Referenz-Sync, Bindungs-Links und Timer neu.

| Property | Typ | Default | Bedeutung |
|---|---|---|---|
| `Driver` | string | `''` | Treiber: `''` = kein Treiber (Schatten), `generic-light` |
| `SwitchVarId` | int | 0 | Schalt-Variable (bool) für An/Aus |
| `Invert` | bool | false | Schaltlogik invertieren |
| `OnScriptId` | int | 0 | Ein-Skript (Alternative zu SwitchVarId) |
| `OffScriptId` | int | 0 | Aus-Skript (Alternative zu SwitchVarId) |
| `LevelVarId` | int | 0 | Helligkeits-Variable (optional) |
| `LevelMax` | float | 100 | Voll-Wert der Helligkeitsskala (100 / 255 / 1) |
| `ColorVarId` | int | 0 | Farb-Variable RGB (optional) |
| `ColorFormat` | string | `int` | `int` (0xRRGGBB) oder `hex` ("#RRGGBB") |
| `CctVarId` | int | 0 | Farbtemperatur-Variable (optional) |
| `CctFormat` | string | `kelvin` | `kelvin`, `mired` oder `percent` (0..100 warm→kalt) |
| `CctMin` | int | 2700 | untere CCT-Grenze (K) |
| `CctMax` | int | 6500 | obere CCT-Grenze (K) |
| `WattVarId` | int | 0 | gemessene Leistung (W, optional) |
| `WattRated` | float | 0 | Nennleistung (W) |
| `Circuit` | int | 0 | Stromkreis-Nummer |
| `Armed` | bool | false | scharf (real) — vom Hub-Master überstimmbar |
| `QueryInterval` | int | 30 | **Abfrage-/Refresh-Intervall in Sekunden** (Untergrenze 2 s gegen Hot-Loop) |
| `ConfigSchema` | int | 0 | Migrations-Marker: 0 = alte FabricStore-Config, 1 = auf Properties migriert |

**Mindest-Bindung:** Mindestens einer von `SwitchVarId`, `LevelVarId` oder das Paar
`OnScriptId`+`OffScriptId` muss gesetzt sein, sonst bleibt der Treiber inaktiv (Schatten).

### Konfigurationsformular (`GetConfigurationForm`)

Konsolen-Formular für Notfall/Erstkonfiguration (Verwaltung sonst im LiveViewBuilder):

- **Treiber**-Auswahl (kein Treiber / Generisch), **Abfrage-Intervall (s)**.
- Panel *An/Aus*: Schalt-Variable, Ein/Aus-Skript, Invertieren.
- Panel *Helligkeit*: Level-Variable + Voll-Wert.
- Panel *Farbe / Farbtemperatur*: Farb-Variable + Format, CCT-Variable + Format, CCT min/max.
- Panel *Leistung*: gemessene Leistung, Nennleistung, Stromkreis.
- Checkbox **Scharf** (`Armed`) + Live-Statuszeile (`computeHealth()`).
- **Actions** (Laufzeit-RPC, keine Konfig): Test An / Test Aus / Ist-Zustand / Bindung prüfen.

## Status-Variablen / Controls (Manifest)

Domäne `light`, Icon `Bulb`. Aus dem Manifest erzeugte Variablen:

| Ident | Typ | Rolle | Aktionierbar | Bedeutung |
|---|---|---|---|---|
| `Power` | Switch (`~Switch`) | `light:power` | ja | An/Aus |
| `Brightness` | Level 0–100 % | `light:brightness` | ja | Helligkeit |
| `ColorTemp` | Setpoint (K) | `light:cct` | ja | Farbtemperatur (CctMin..CctMax) |
| `Color` | Setpoint (`~HexColor`) | `light:color` | ja | RGB-Farbe 0..0xFFFFFF |
| `Watt` | Reflect (W, float) | `light:watt` | nein | gemessene Leistung |
| `Online` | Reflect (`~Switch`) | `light:online` | nein | Erreichbarkeit |
| `BindHealth` | String | — | nein | Bindungs-/Health-Text (Position 90) |

Die Bedienung von `Power`, `Brightness`, `ColorTemp`, `Color` gilt als **automatisierbar**
(`isAutomated`) und öffnet ein manualHold-Fenster (Automatik-Hoheit, Projektstufe A3).

### Refresh (Ist-Zustand spiegeln)

Timer `Refresh` (Intervall = `QueryInterval`, Standard 30 s) ruft `HSLT_Refresh()`. Der Treiber
liest `readState()` und spiegelt `Power`, `Brightness`, `ColorTemp` (aktionierbare Anzeigen)
sowie `Color`, `Watt`, `Online` (Reflects). Timer läuft nur bei aktivem Treiber.

### Baum-Transparenz (Bindungs-Links)

`bindingTargets()` legt sichtbare `bl_`-Links auf die gebundenen Quell-Objekte an
(`bl_SwitchVarId`, `bl_LevelVarId`, `bl_ColorVarId`, `bl_CctVarId`, `bl_WattVarId`,
`bl_OnScriptId`, `bl_OffScriptId`); zusätzlich werden diese via `RegisterReference` referenziert.

## Öffentliche Skript-/RPC-Funktionen

> Alle `Set…`-Funktionen gehen intern über `RequestAction` → `applyControl` (armed-Gate/Reflect
> bleiben erhalten). **Realer Effekt nur bei effektivem Armed=true + aktivem, gebundenem Treiber**,
> sonst Schatten-Modus. Rückgabe `bool` wie bei allen HomeSuite-Modulen.

### Setzen
| Funktion | Zweck |
|---|---|
| `bool HSLT_SetPower(int $id, bool $On)` | An/Aus |
| `bool HSLT_SetBrightness(int $id, int $Percent)` | Helligkeit 0–100 % |
| `bool HSLT_SetColorTemp(int $id, int $Kelvin)` | Farbtemperatur (2700–6500 K) |
| `bool HSLT_SetColor(int $id, int $Rgb)` | RGB `0xRRGGBB` |
| `bool HSLT_Toggle(int $id)` | Umschalten; liefert neuen Soll-Zustand |
| `bool HSLT_SetArmed(int $id, bool $Armed)` | scharf/Schatten (Cutover; liefert resultierenden Armed-Zustand) |

### Lesen
| Funktion | Zweck |
|---|---|
| `bool HSLT_IsOn(int $id)` | An/Aus |
| `int HSLT_GetBrightness(int $id)` | Helligkeit |
| `int HSLT_GetColorTemp(int $id)` | Farbtemperatur |
| `float HSLT_GetWatt(int $id)` | Leistung |
| `bool HSLT_IsOnline(int $id)` | Erreichbarkeit |

### Generisch (aus EntityModule)
| Funktion | Beschreibung |
|---|---|
| `bool HSLT_SetControl(int $id, string $Ident, mixed $Value)` | beliebigen aktionierbaren Control setzen |
| `mixed HSLT_GetControlValue(int $id, string $Ident)` | aktuellen Statuswert lesen |
| `void HSLT_Refresh(int $id)` | Ist-Zustand jetzt spiegeln |
| `string HSLT_Manage(int $id, string $json)` | Verwaltungs-RPC (siehe unten) |

### Beispiele
```php
HSLT_SetPower(10013, true);
HSLT_SetBrightness(10013, 40);
HSLT_SetColorTemp(10013, 2700);   // warmweiss
HSLT_SetColor(10013, 0xFF8800);
HSLT_Toggle(10013);
```

## Verwaltungs-RPC (`HSLT_Manage`, `mgmt`)

JSON `{"op": "...", "args": {...}}`. Verfügbare Operationen (auch als managementActions im Manifest):

| Op | Zweck |
|---|---|
| `configureDriver` | Aktor-Bindung schreiben (Treiber + Var/Skript-IDs, Formate, Grenzen); `dryrun` möglich |
| `getConfig` | aktuelle Konfiguration lesen (Diagnose) |
| `validate` | Bindung prüfen → Health-Text + Issue-Liste |
| `driverProbe` | Treiber-Status + Fähigkeiten (`caps`) |
| `readState` | Ist-Zustand des Treibers lesen (inkl. `armed`, `caps`) |
| `setPower` / `setLevel` / `setColor` / `setCct` | Test-Schaltung (nur bei armed real, sonst Schatten mit Hinweis) |
| `setArmed` | scharf/Schatten setzen (schreibt Property `Armed` + ApplyChanges) |
| `migrateConfig` | einmalige Migration alte FabricStore-Config → native Properties |
| *(Basis)* `createEntity` / `renameEntity` / `deleteEntity` | Entitäts-Verwaltung (aus EntityModule) |

## Health / Diagnose

`computeHealth()` bewertet die Bindung und schreibt sie nach `BindHealth`:

- `inaktiv (kein Treiber)` — kein Treiber gewählt.
- `FEHLER: …` — fehlende Bindung oder verwaiste Variablen-IDs.
- `OK · schalt+dim+farbe+cct · scharf` bzw. `· Schatten-Modus` — Fähigkeiten aus `driverCaps()`
  plus effektiver Scharf-Zustand.

## Migration (FabricStore → Properties)

Historische Instanzen trugen ihre Config im FabricStore-JSON. `migrateConfig` überträgt die alten
`config`-Keys einmalig in native Properties und setzt `ConfigSchema = 1`. Bereits migrierte
Instanzen liefern `already = true`. (Licht-Modul auf Properties abgeschlossen, v0.17.12.)

## Besondere Hinweise

- **Kein realer Schaltbefehl im Schatten-Modus** — optimistischer `SetValue` der Statusvariable
  erfolgt trotzdem, damit Frontend/Automatik konsistent bleiben.
- **Hub-Master vor Instanz-Property:** `Armed` einer Einzelinstanz wirkt nur, wenn der Hub im
  `Auto`-Modus (bzw. ohne Master) ist; `Aus`/`Scharf` überstimmen domänenweit.
- **CCT-Formate:** `kelvin` direkt, `mired` = 1e6/K, `percent` linear warm→kalt zwischen
  `CctMin` und `CctMax` — der Treiber rechnet um.
- **QueryInterval** ist pro Instanz einstellbar (Sekunden); Untergrenze 2 s verhindert Hot-Loops.
- **Gruppen / Szenen / Automatik** (SceneEngine, Trigger, Circadian, Bewegung/Wecker) laufen
  nicht in dieser Instanz, sondern im LiveViewBuilder bzw. den HomeSuite-Engines (Projektstufe L3+).
- Backup vor jeder Skriptänderung; keine Emojis in Git/README.

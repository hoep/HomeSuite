# HeatingZone (HSHT)

Domänen-Modul „Heizung" der HomeSuite. **Eine Instanz = ein Heizkreis/Raum.** Das Modul steuert das gebundene Thermostat (generischer Sollwert-Thermostat oder HomeMatic-Gerät) nach Solltemperatur, Modus und Präsenz-Wochenplan.

- **Modul-GUID:** `{AC059357-088A-4DF8-ABBC-F8724BC78769}`
- **Prefix:** `HSHT`
- **Aliase:** `HeatingZone`, `HSHT`, `HomeSuite Heizung`
- **Basisklasse:** `\Hoep\HomeSuite\EntityModule` (liefert Manifest-Variablenanlage, native `RequestAction`, das RPC-Trio `HSHT_GetManifest` / `HSHT_GetState` / `HSHT_Manage`, Hub-Anbindung, Bindungs-Links)

## Überblick / Zweck

HeatingZone kapselt einen einzelnen Heizkreis als HomeSuite-Entität. Bedienung (Sollwert, Modus, Präsenz) und Verwaltung (Treiber, Automatik, Wochenprofile) werden generisch aus dem **Manifest** abgeleitet und im LiveViewBuilder gerendert. Der reale Schreibpfad läuft ausschließlich über einen HAL-Treiber gegen die zugeordneten Symcon-Variablen bzw. die HomeMatic-CCU-Instanz.

Zentrales Sicherheitsprinzip ist der **Schatten-Modus**: Solange die Instanz nicht „scharf" (`Armed`) ist, bleiben alle optimistischen Statuswerte sichtbar, es wird jedoch **nichts real geschaltet**. So kann eine bestehende Altsteuerung Regler bleiben, bis der explizite Cutover erfolgt.

## Architektur / HAL

Zwei Thermostat-Klassen je nach Treiber (`capabilities().scheduleMode`, Leitprinzip 7):

| scheduleMode | Wer fährt den Wochenplan | Treiber |
|---|---|---|
| `controller` | **das Modul** (ScheduleEngine fährt den Sollwert nach) | `generic-thermostat` (dummer Sollwert-Thermostat) |
| `device` | **das Gerät** (Modul pusht nur das aktive Wochenprogramm) | `hm-*` (HomeMatic-Adapter) |

Manifest, Editor und Musterseite sind in beiden Fällen identisch.

- **`driver()`** baut aus den nativen Properties über die `DriverFactory` einen kernel-freien Treiber (`IThermostat`):
  - `generic-thermostat`: bindet `setpointVarId` (= `TargetId`) und optional `actualVarId` (= `SensorId`), Bereich 5–30 °C, Schritt 0,5.
  - `hm-*`: `deviceInstanceId` (= `TargetId`) ist die CCU-Geräteinstanz; der Treiber löst die Kanäle (`SET_TEMPERATURE`/`ACTUAL_TEMPERATURE`, `HUMIDITY`, `VALVE`) selbst auf. Optional separate Sensor-/Wandgerät-Instanz (`sensorInstanceId` = `SensorId`, z. B. HM-CC-TC „Raumklima"). **Kein direkter HM-Vendorcode im Modul** — die Kanalauflösung liegt im Adapter.
- **`applyControl()`** (Vertrag-1-Hook) routet `Setpoint → setSetpoint()` und (nur wenn der Treiber `hasMode` meldet) `Mode → setMode()`. Reflect-Controls werden nach jedem Schreiben aus `readLive()` nachgezogen; Präsenz-/Modus-Wechsel stoßen sofort `reconcile()` an.
- **Reflect** (`reflectFromDriver`): Ist-Temperatur, Feuchte, Online und der tatsächliche Geräte-Sollwert werden read-only gespiegelt — auch im Schatten-Modus, damit die Anzeige nicht 0 zeigt.

## Konfiguration (Properties + Formular)

Native Symcon-Properties (Wochenprofile bleiben im Store):

| Property | Typ | Default | Bedeutung |
|---|---|---|---|
| `Driver` | string | `''` | `''`, `generic-thermostat`, `hm-HM-TC-IT-WM-W-EU`, `hm-HM-CC-RT-DN`, `hm-HM-CC-TC` |
| `TargetId` | int | 0 | Ziel — bei `generic` die **Sollwert-Variable**, bei `hm-*` die **CCU-Instanz** |
| `SensorId` | int | 0 | Ist-Sensor (optional) — bei `generic` eine Variable, bei `hm-*` eine Instanz/Variable |
| `FrostTemp` | float | 8,0 | Frostschutz-Solltemperatur (°C), geklemmt 3–15 |
| `Armed` | bool | false | scharf = schaltet real; sonst Schatten-Modus |
| `QueryInterval` | int | 30 | Abfrage-/Refresh-Intervall in **Sekunden** (Boden 2 s gegen Hot-Loop) |
| `ConfigSchema` | int | 0 | Migrations-Marker (Store-config → Properties, einmalig) |

**`GetConfigurationForm()`** (native Konsole): Felder sind property-gebunden und werden bei „Änderungen übernehmen" gespeichert. Auswahl: Treiber (Select), `QueryInterval`, `TargetId`/`SensorId` (SelectObject), `FrostTemp` (NumberSpinner, 3–15), `Armed` (CheckBox). Buttons sind Laufzeit-Aktionen (RPC): **Ist-Zustand** (`getConfig`), **Wochenplan lesen** (`getSchedule`), **Aus Altsteuerung importieren** (`importLegacy` dryrun). Zeitplan-/Präsenz-Verwaltung erfolgt im LiveViewBuilder.

`configureDriver` validiert die Treiber-ID und dass `TargetId`/`SensorId` echte Objekte des erwarteten Typs sind (bei `hm-*` zusätzlich, dass die Instanz einen Sollwert-Kanal `SET_TEMPERATURE`/`SETPOINT` besitzt). `ApplyChanges` löst den Treiber neu auf, setzt den Refresh-Timer (`QueryInterval`), registriert den Sollwert-Watch und räumt evtl. alte native `HeatSchedule_*`-Ereignisse ab.

### Hub-Anbindung / Scharf-Master (3-Zustand)

Der zentrale HomeSuite-Hub kann die Domäne „Heizung" übersteuern. Der domänenweite Scharf-Master ist die Hub-Variable **`ArmHeatingMode`** (`<ident>Mode`):

| Wert | Bedeutung |
|---|---|
| 0 = Aus | alle Heizungs-Instanzen im Schatten-Modus |
| 1 = Auto | jede Instanz entscheidet über ihr eigenes `Armed` |
| 2 = Scharf | alle Instanzen schalten real |

Fallback ist der ältere Bool-Master `ArmHeating`. `armedEffective()` gibt dem Hub-Master Vorrang (`hubArmGate`), sonst gilt die Instanz-Property `Armed`. Zusätzlich respektiert `reconcile()` den globalen Automatik-Schalter `AutomationEnabled` des Hubs (fail-safe: ohne Hub läuft die Automatik weiter). Der Frostschutz-Sollwert kann haus-weit über die Hub-Property `HeatFrostTemp` vorgegeben werden (sonst gilt `FrostTemp` der Instanz).

## Status-Variablen / Controls (Manifest)

Domäne `heating`, Titel „Heizung", Icon `Temperature`.

| Ident | Typ | Rolle | actionable | Details |
|---|---|---|---|---|
| `Setpoint` | Setpoint | `heating:setpoint` | ja | Solltemperatur 5–30 °C, Schritt 0,5, `~Temperature` |
| `ActualTemp` | Reflect | `heating:actual` | nein | Ist-Temperatur, `~Temperature` |
| `Humidity` | Reflect | `heating:humidity` | nein | Luftfeuchte %, `~Humidity.F` |
| `Mode` | Select | `heating:mode` | ja | 0 Auto, 1 Manuell, 2 Boost, 3 Frostschutz |
| `Presence` | Select | `heating:presence` | ja | 0 Normal, 1 Erweitert, 2 Abgesenkt |
| `Online` | Reflect | `heating:online` | nein | Erreichbarkeit (bool) |

Zusätzlich spiegelt `ScheduleJson` (read-only) den aktiven Wochenplan als JSON für die Baum-Transparenz.

**Profiltyp `roomProfile`** (Wochenprofil): 2 Achsen — Präsenz (Normal/Erweitert/Abgesenkt) × Wochentag (MO–SO). Slots `{end:"HH:MM", val:float 5–30}`, Regeln: letzter Slot endet 24:00, aufsteigend; Editor `weekedit-hm`. Slots unterstützen **Sonnen-Anker** (`anchor` + `offset`, via `SunTimes`).

## Reconciler / Failsafe & Manual-Override

- **`Refresh()`** (Timer-Callback `HSHT_Refresh`, Intervall = `QueryInterval`): Reflect + `reconcile()`.
- **`reconcile()`** (nur bei `armed` + Hub-Automatik an):
  - `device`-Modus: `pushWeekIfChanged()` — pusht das Wochenprogramm der aktiven Präsenz-Variante ins Gerät, aber nur wenn sich der Hash geändert hat.
  - `controller`-Modus: fährt den Sollwert nach dem Plan; Re-Assert bei **Drift** (Ist-Sollwert weicht ab) oder periodisch (Failsafe, spätestens alle 300 s). Modus `Manuell` und aktiver `manualHold` blockieren das Nachfahren; `Frost` nutzt `HeatFrostTemp`/`FrostTemp`.
- **Manual-Override** (Block D): Das Modul lauscht per `VM_UPDATE` auf die geräteseitige Sollwert-Variable (`registerSetpointWatch`/`MessageSink`). Verstellt jemand den Wert am Gerät, ohne dass das Modul ihn geschrieben hat (Self-Write-Unterdrückung ~10 s), wird ein `manualHold` auf `Setpoint` bis zur nächsten Slot-Grenze gesetzt.

## Öffentliche Skript-/RPC-Funktionen

> Alle `Set…`-Funktionen gehen intern über `RequestAction` → `applyControl` (Wert-Härtung, `manualHold`, Reflect, Reconcile bleiben konsistent). **Realer Effekt nur bei `Armed=true` + gebundenem Treiber**, sonst Schatten-Modus. Rückgabe `bool`: `true` = validiert und dispatcht, `false` = unbekannter/nicht schaltbarer Ident oder unzulässiger Wert.

### Generisch (aus EntityModule, alle Module)
| Funktion | Beschreibung |
|---|---|
| `bool HSHT_SetControl(int $id, string $Ident, mixed $Value)` | beliebigen actionable Control setzen |
| `mixed HSHT_GetControlValue(int $id, string $Ident)` | aktuellen Statuswert lesen (null wenn keiner) |
| `string HSHT_GetManifest(int $id)` | Manifest (JSON) |
| `string HSHT_GetState(int $id)` | Gesamtzustand (JSON) |
| `string HSHT_Manage(int $id, string $json)` | Verwaltungs-Op (JSON `{op, args, ctx}`) |

### Setzen
| Funktion | Zweck |
|---|---|
| `bool HSHT_SetSetpoint(int $id, float $Celsius)` | Solltemperatur (5,0–30,0 °C) |
| `bool HSHT_SetMode(int $id, int $Mode)` | 0=Auto, 1=Manuell, 2=Boost, 3=Frost |
| `bool HSHT_SetModeName(int $id, string $Mode)` | Klartext: `auto`\|`manual`\|`manuell`\|`boost`\|`frost`\|`frostschutz` |
| `bool HSHT_SetPresence(int $id, int $Presence)` | Zeitplan-Variante 0=Normal, 1=Erweitert, 2=Abgesenkt |
| `bool HSHT_Boost(int $id)` | Kurzform Mode=2 |
| `bool HSHT_FrostProtect(int $id)` | Kurzform Mode=3 |
| `bool HSHT_SetArmed(int $id, bool $Armed)` | scharf/Schatten (Cutover); liefert resultierenden Zustand |

### Lesen
| Funktion | Zweck |
|---|---|
| `float HSHT_GetSetpoint(int $id)` | Sollwert |
| `float HSHT_GetActualTemp(int $id)` | Ist-Temperatur |
| `int HSHT_GetHumidity(int $id)` | Luftfeuchte |
| `int HSHT_GetMode(int $id)` | Modus |
| `int HSHT_GetPresence(int $id)` | Präsenz |
| `bool HSHT_IsOnline(int $id)` | Erreichbarkeit |
| `string HSHT_GetScheduleJson(int $id, int $Presence = -1)` | Wochenplan als JSON (Diagnose); −1 = aktive Präsenz |

### Verwaltungs-Ops (`HSHT_Manage`)
`configureDriver`, `configureAutomation` (Frostschutz), `updateProfile` (Tages-Slots einer Präsenz schreiben, unterstützt Sonnen-Anker), `getSchedule` (komplette Woche + Sonnen-Events/Anker), `setActivePresence`, `importLegacy` (Altsteuerungs-Wochenprofil aus serialisierter HM-Variable, idempotent, kein Geräteschreiben, `dryrun` möglich), `adoptDevice`/`loadFromDevice`/`syncToDevice`/`syncStatus` (generisch aus der Basis), `getConfig`, `setArmed`, `migrateConfig` (einmalige Store→Properties-Migration). Zeitplan-**Bearbeitung** bleibt `Manage`/LiveViewBuilder — die Heizpläne sind bewusst store-/JSON-basiert (native Symcon-Wochenplan-Ereignisse eignen sich wegen des Aktionslimits nicht für stufenlose Solltemperaturen).

### Beispiele
```php
HSHT_SetSetpoint(11383, 21.5);   // Wohnzimmer auf 21,5 °C
HSHT_Boost(11383);               // Boost
HSHT_SetPresence(11383, 2);      // Abgesenkt (z. B. Urlaub)
$t = HSHT_GetActualTemp(11383);  // Ist-Temperatur lesen
```

## Besondere Hinweise

- **Schatten-Modus zuerst:** Ohne `Armed` (und ohne Hub-Scharf) wird nie real geschaltet — Altsteuerung bleibt Regler. Cutover explizit via `HSHT_SetArmed` bzw. Hub `ArmHeatingMode`.
- **Wochenpläne bleiben im Store** (JSON), nicht als Symcon-Ereignisse — inkl. Sonnen-Anker-Slots.
- **`QueryInterval`** steuert das Reflect-/Reconcile-Intervall je Instanz (Sekunden, Boden 2 s).
- **HomeMatic-Adapter** ist aktor-vorsichtig; die Kanalauflösung und das Wochenprofil-Schreiben liegen im Treiber, nicht im Modul.
- **Migration** aus der Altsteuerung (`#<ID>`): `importLegacy` liest das serialisierte `HMWochenprofilDaten`-Profil je Präsenz/Wochentag — schaltet die Legacy jedoch NICHT ab (getrennter Cutover).
- **Baum-Transparenz:** `bindingTargets()` legt sichtbare `bl_`-Links auf Sollwert/Ist bzw. das HM-Gerät.

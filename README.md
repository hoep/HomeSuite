# HomeSuite

Modul-Library fuer IP-Symcon. Lizenz MIT.

HomeSuite fasst die Haussteuerungs-Domaenen **Heizung, Beschattung, Licht, Audio,
Bewaesserung, Pool, Maeher und Klima** in einer einheitlichen Architektur
zusammen. Jede Entitaet - ein Heizkreis, ein Rollo, eine Leuchte, ein
Lautsprecher - ist genau **eine Modul-Instanz**, die ihre Bedienelemente als
typisierte *Controls* beschreibt. Was eine Instanz kann, steht in ihrem
**Manifest**; Oberflaechen lesen das Manifest und bauen ihre Bedienung daraus,
statt jede Geraeteart einzeln zu kennen.

Zwischen Modul und Hardware liegt immer eine **HAL** (Hardware Abstraction
Layer). Fuer verbreitete Systeme gibt es eigene Treiber, fuer alles andere je
Domaene einen generischen Variablen-Treiber: gebunden wird an bestehende
Symcon-Variablen, nicht an ein bestimmtes Fabrikat.

## Voraussetzungen

- IP-Symcon ab Kernel 7.1 (entwickelt und betrieben auf 9.0), PHP 8
- Ein Geraet oder eine bestehende Symcon-Variable je Entitaet
- Fuer die Bedienoberflaeche: [LiveViewBuilder](https://github.com/hoep/LiveViewBuilder)
  (optional - die Module laufen auch ohne, dann ueber Konsole und Skript-API)

## Installation

Konsole → *Kern-Instanzen* → **Modules** → Hinzufuegen:

```
https://github.com/hoep/HomeSuite
```

Danach genau **eine Hub-Instanz** anlegen (`HomeSuite Hub`, Singleton). Alles
Weitere - Raeume, Bereiche, Entitaeten - legt der Hub auf Wunsch selbst an
(`HSH_Provision`, asynchron ueber eine Warteschlange); von Hand geht es ebenso.

## Erste Schritte

1. **Hub anlegen.** Er entdeckt alle spaeteren HomeSuite-Instanzen selbst
   (GUID-Discovery), es gibt keine Eltern-Kind-Kette zu pflegen.
2. **Struktur anlegen** (optional, aber empfohlen): `HomeSuite Bereich` (HSSP)
   bildet Haus → Bereich → Raum. Die Zuordnung einer Entitaet ist ihre
   Elternschaft im Objektbaum - keine zweite Liste, die auseinanderlaufen kann.
3. **Entitaet anlegen**, Treiber waehlen, Variablen binden.
4. **Scharf schalten.** Neue Entitaeten starten im **Schatten-Modus**: sie
   rechnen und zeigen alles, fahren aber nichts. Erst `Armed = true` laesst sie
   real schalten. Das ist Absicht - eine falsch gebundene Rolloinstanz soll beim
   ersten Versuch nicht das Haus verstellen.

## Module im Ueberblick

| Modul | Prefix | Wofuer |
|---|---|---|
| HomeSuite Hub | `HSH` | Singleton: Registry, Topologie, Provisionierung, Scharf-Master, Standort/Sonne, Szenen, Token |
| HomeSuite Bereich | `HSSP` | Struktur-Element: eine Ebene der Topologie (Haus / Bereich / Raum) |
| HeatingZone | `HSHT` | ein Heizkreis bzw. Raum - Soll/Ist, Wochenprofile, Praesenz |
| ShadingDevice | `HSSH` | ein Rollo, eine Markise, eine Jalousie - Position, Sonnen- und Windautomatik |
| LightDevice | `HSLT` | eine Leuchte oder ein Lichtkreis - schalten, dimmen, Farbe |
| IrrigationCircuit | `HSIR` | ein Bewaesserungskreis - Dauer, Zeitplan, Regen- und Temperatur-Tore |
| AudioZone | `HSAU` | ein Renderer = ein Raum-Lautsprecher - Wiedergabe, Lautstaerke, Quellen |
| AudioZoneBridged | `HSAUX` | Bridge-Variante dazu: ein Wiedergaberaum ueber eine Splitter-Instanz |
| HeosBridge | `HSBH` | Splitter fuer Denon/Marantz HEOS (HEOS-CLI, TCP 1255) |
| HomeSuiteSonosEvents | `HSSE` | Splitter fuer Sonos-Ereignisse (UPnP/GENA) |
| ClimateZone | `HSAC` | eine Klimazone - Klimageraet bzw. Raumklima-Regelung |
| PoolController | `HSPC` | ein Pool - Umwaelzung, Dosierung, Messwerte, Regeln |
| MowerDevice | `HSMW` | ein Maehroboter |
| GardenaDevice | `HSGA` | GARDENA-smart-system-Geraet (Sensor, Bewaesserungscomputer, Ventil) |
| GardenaConfigurator | `HSGX` | findet GARDENA-Geraete und legt sie an |
| RainRadar | `RR` | Regenradar auf eine Basiskarte komponiert, mit Vorhersagereihe |
| BatteryManager | `BM` | sammelt alle Geraetebatterien der Anlage und meldet schwache |
| HomeSuite Waechter | `HSSC` | Wachdienst: Zustaende und Meldungen der Suite |

Jedes Modul hat ein eigenes `README.md` mit Manifest, Controls und
Befehlsreferenz.

## Leitprinzipien (Kurzform)

- **Einmal richtig** — Wahrheit lebt an einer Stelle (Manifest, Musterseite,
  Treiber, ActuatorGate).
- **RequestAction** ist der einzige autoritative Bedien-Eingang.
- **Immer ueber die HAL** — nie direkt auf Vendor-Hardware. Fuer Hardware ohne
  Spezial-Treiber gibt es je HAL einen generischen Variablen-Treiber.
- **Strangler-Fig** — Neu neben Alt, per Entitaet umschaltbar, jederzeit
  rueckrollbar; Aktor-Safety vor Migrationskomfort.

## Verzeichnisstruktur

```
HomeSuite/
  library.json            Library-Manifest (id = Library-GUID)
  GUIDS.md                IMMUTABLES GUID-Register (Library + alle Module)
  LICENSE                 MIT
  README.md               dieses Dokument
  CLEANROOM.md            Clean-Room-Erklaerung (nur Protokollwissen, kein Copy)
  docs/
    SPEC.md               verbindliche Bau-Spezifikation
  libs/
    HomeSuite/            gemeinsame Basis, Namespace-Root Hoep\HomeSuite\
      autoload.php        explizite require_once (Store-Muster, kein PSR-4)
      Contracts/          Control, ControlContract, ActionContext, Manifest,
                          Store, EntityModule
      Engines/            ProfileEngine, ScheduleEngine, ShadeKinematics
      Provision/          Provisioner
      Migration/          ActuatorGate, Ledger, Backup, MigrateProvider
      HAL/                IDriver, IThermostat, IShutter, IValve, IAudioRenderer,
                          DriverFactory, GenericVariable{Thermostat,Shutter,Valve}
  Modules/
    Hub/                  HSH-Modul (Singleton, wird vom Hub-Agent gebaut)
```

## Namespace

Basis-Namespace `Hoep\HomeSuite\` (vendor-eindeutig) unter `libs/HomeSuite/`.
Grund: `libs/` hat kein PSR-4; ein vendor-eindeutiger Root verhindert Kollisionen,
wenn zwei Libraries mit gleichem Root im selben Kernel-Prozess geladen werden.
Geladen wird ueber `autoload.php` mit expliziten `require_once` (belegtes
Store-Muster).

## Skript-API (PHP-Befehlsreferenz)

Jedes Domänenmodul stellt öffentlich aufrufbare, typisierte Prozeduren bereit, um
seine Variablen aus Symcon-Skripten zu setzen/lesen (IP-Symcon exponiert jede
`public function` als `PREFIX_Methode($InstanceID, …)`). Setzen geht immer intern
über `RequestAction` → `applyControl`, damit armed-Gate, Wert-Härtung, manualHold,
Reflect und Reconcile konsistent bleiben. **Realer Effekt nur bei `Armed=true` +
gebundenem Treiber** (sonst Schatten-Modus).

Basis (in allen Modulen): `PREFIX_SetControl($id, string $Ident, $Value): bool`
und `PREFIX_GetControlValue($id, string $Ident)`.

Vollständige Befehlsreferenz je Modul in dessen `README.md`:
`HeatingZone` (HSHT) · `LightDevice` (HSLT) · `ShadingDevice` (HSSH) ·
`IrrigationCircuit` (HSIR) · `AudioZone` (HSAU) / `AudioZoneBridged` (HSAUX) ·
`PoolController` (HSPC) · `Hub` (HSH). 

## Bedienung

Die Konsole wird fuer Library und Hub gebraucht, danach kaum noch. Gedacht ist
die Suite fuer eine Oberflaeche, die das Manifest liest - dafuer gibt es den
**LiveViewBuilder**. Ohne ihn bleiben Konsole und Skript-API:

```php
HSHT_SetControl($id, 'Setpoint', 21.5);      // Soll setzen
HSSH_SetControl($id, 'Position', 40);        // Rollo auf 40 %
HSAU_SetPower($id, true); HSAU_Play($id);    // Zone ein, Wiedergabe starten
$wert = HSLT_GetControlValue($id, 'State');  // Zustand lesen
```

Setzen laeuft immer ueber `RequestAction` → `applyControl`, damit Scharf-Gate,
Wert-Haertung, manualHold und Reconcile in jedem Weg gleich greifen.

## Status

In Betrieb. Die Domaenen Heizung, Beschattung, Licht, Bewaesserung, Audio, Pool,
Klima und Maeher laufen produktiv; Neuerungen kommen ueber die Releases dieses
Repos. Die Versionsnummer ist mit dem LiveViewBuilder gemeinsam gefuehrt: beide
Repos tragen dieselbe Nummer, weil Modul und Oberflaeche zusammen entwickelt
werden.

## Lizenz

MIT - siehe `LICENSE`. `CLEANROOM.md` haelt fest, dass Fremdprotokolle
ausschliesslich aus oeffentlicher Dokumentation und eigener Beobachtung
nachgebaut wurden, ohne fremden Quelltext zu uebernehmen.

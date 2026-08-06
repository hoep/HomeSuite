# HomeSuite

Modul-Library fuer IP-Symcon (Kernel 9.0, PHP 8, Lizenz MIT).

HomeSuite buendelt die Haussteuerungs-Domaenen **Heizung, Beschattung, Audio und
Bewaesserung** in einer einheitlichen, HAL-gestuetzten Architektur. Jede Entitaet
ist genau eine Modul-Instanz (SDK type 3), die typisierte **Controls** ueber drei
formale Vertraege exponiert (Control-Contract, Manifest-JSON, IAudioRenderer-Codec).
Die vollstaendige Bedienung und Verwaltung erfolgt im LiveViewBuilder; die Konsole
wird nur einmalig fuer die Library und die Hub-Instanz benoetigt.

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

## Kompatibilitaet (Hinweis)

`library.json` traegt `compatibility.version = "1.0"` als **Platzhalter**. Die
reale Mindest-Kernel-API wird in Milestone **M0.1** festgelegt (niedrigste
tatsaechlich genutzte API, Default-Zielkernel 9.0). JSON erlaubt keine
Kommentare — daher steht der Hinweis hier statt in der Datei.

## Status

Milestone **M0.0** — Repo-Skelett. Klassen-Implementierungen folgen durch die
nachgelagerten Bau-Agenten (siehe `autoload.php` fuer die geplante Dateiliste).

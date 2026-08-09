# HomeSuite Lichtsteuerung — Plan (Freigabe ausstehend)

Stand 2026-08-09. Analoge Struktur zum Audio-Umbau (M0–M10 + Cutover).

## Entscheidungen (vom Nutzer bestätigt)
- Szenen: **Snapshot-Aufnahme UND Authored-Editor** (beides).
- Szenen-Scope: **Haus** (raumübergreifend; Raum/Geschoss als Untermenge technisch inbegriffen).
- Extras: **Circadian/adaptiv**, **Wecker Licht+Musik**, **Bewegung+Lux**, **Anwesenheitssimulation** — alle vier.
- Vorgehen: **erst Plan**, dann Freigabe, dann Bau.

## Ausgangslage (analysiert)
- IPSLight = leeres Gerüst (2 Demo-Lampen, keine Gruppen/Programme). Wird **nicht** genutzt → wird ignoriert/entfernt, nicht migriert.
- IPSLight-Modell dient nur als Blaupause: Switch/Dimmer/RGB, Gruppen, Programme(=Szenen), `Synchronize*`-Rückkanal, `GetPowerConsumption(circuit)`.
- Reales Inventar: **39 Lampen** als LVB-`light`-Kacheln, direkt variablengebunden. Davon **39 Schalter (varId)**, **20 dimmbar (varId2)**, **0 Farbe/CT**. Gruppiert nach Geschoss (EG 13 / OG 20 / DG 6).
- Ehrlich: Circadian/Farbe wirkt erst auf farb-/CT-fähige Lampen — heute keine. Für Dimmer bleibt „circadian" = tageszeitabhängige Helligkeitsrampe. Farb-/CT-Pfad wird vorgebaut, aktiv sobald solche Lampen existieren.

## Architektur
- Entity **LightDevice** (Modul HSLT, neue GUID) — eine Instanz je Lampe/Lichtkreis. Passt ins EntityModule-Muster (Manifest/Controls, FabricStore, ScheduleEngine, ProfileEngine, automationEnabled).
- Fähigkeiten aus Variablenprofil erkannt: **switch / level / color(RGB) / cct(Kelvin/Mired)** + optional **watt + Stromkreis**.
- **Universelle Bindung** (Variable und/oder Skript) je Kanal — wie Beschattung/Bewässerung.
- **SceneEngine** (libs): Szenen im FabricStore, Snapshot-Capture + Authored, Anwenden mit Überblendung/Rampe. Haus/Geschoss/Raum-Scope.
- **CircadianEngine**: nutzt verifizierte Sonnen-Ephemeride aus `suncompass`.
- **MotionEngine**: Bewegung + Lux-Schwelle + Nachlaufzeit.
- **PresenceSim**: realistische Muster bei Abwesenheit.
- Wiederverwendung: ScheduleEngine (Zeit/Sonne/Präsenz), Audio-Weck-Engine, RoomManager-Hierarchie, `shadeprofiles`-Editor-UX.

## Meilensteine

### Phase A — Fundament (Live: nur LESEN)
- **L0 HAL/Modell**: LightState/LightCapabilities Value-Objects + Fähigkeitserkennung aus Profilen; `GenericBoundLight`-Treiber (liest/schreibt switch/level/color/cct-Var oder Skript). DriverFactory-Eintrag.
- **L1 LightDevice-Modul (HSLT)**: module.json + module.php, Manifest (T_SWITCH/T_LEVEL/T_COLOR/T_SELECT für Szene), RequestAction-Dispatch, RPC-Trio (GetManifest/GetState/Manage), managementActions-Whitelist, automationEnabled.
- **L2 Discovery + Import (Dry-Run)**: die 39 LVB-`light`-Bindungen + Profile scannen → LightDevices je Raum/Geschoss vorschlagen; read-only Report; Instanzen mit **armed=false** anlegen.
- **L3 RoomManager-Zuordnung**: Devices in Haus/Bereich/Raum einhängen; Master je Geschoss/Haus.

### Phase B — Frontend + Szenen (Live: LESEN)
- **L4 ?api=light + Widgets**: Hook (list/getall/groups/state); `light`-Widget **erweitern** um Farb-Swatch + CT-Regler; `lightx`-Session-Familie; `lightroom` (Raum/Geschoss-Karte mit Master + Chips).
- **L5 SceneEngine**: Szenen-Datenmodell (Mitglieder: Ziel on/off/level/color je Device), Snapshot-Capture, Apply mit Überblendung. Haus + Geschoss + Raum.
- **L6 Szenen-Widgets**: `scenebar` (Chips + „aufnehmen") + `sceneeditor` (anlegen/umbenennen/duplizieren/zuweisen, shadeprofiles-UX). Doku-Seite nachziehen.

### Phase C — Automatik (Live: LESEN; Schreiben nur nach Freigabe je Regel)
- **L7 ScheduleEngine-Trigger**: Zeit/Sonne/Präsenz → Szene anwenden (Sonnenuntergang→„Abend", Abwesend→„Alles aus"). Globaler Automatik-Schalter + je-Szene Auto-Anwenden.
- **L8 Circadian/adaptiv**: je Device opt-in; CT+Level folgen Sonnenhöhe (suncompass-Ephemeride); Vorschau. (Dimmer-only: Helligkeitsrampe.)
- **L9 Weckszene Licht+Musik**: Licht-Rampe koppelt an Audio-Weck-Engine (ScheduleEngine) — eine Weckszene steuert beides.
- **L10 Bewegung+Lux**: Bewegungsmelder + Lux-Schwelle + Haltezeit → Auto An/Aus.
- **L11 Anwesenheitssimulation**: realistische Ein/Aus-Muster bei Abwesenheit (aus Verlauf oder Regeln).

### Phase D — Umstellung (Live: SCHREIBEND, Freigabe)
- **L12 Cutover**: reale Lampen auf HomeSuite scharf schalten (armed=true), IPSLight endgültig entfernen; LVB-Seite „Licht" auf die neuen Widgets/Bindungen umstellen.

## Gates
- Alles bis L11 ist read-only bzw. schreibt nur auf ausdrückliche Freigabe.
- Kein Test-/Worker-Trigger auf Produktion; jede Schreiboperation an realen Lampen erst nach OK.
- Versionierung: gemeinsam mit LVB über `release.sh` bei jedem Push.

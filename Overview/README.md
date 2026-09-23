# Overview (HSOV)

Die Lage aller Standorte auf einen Blick - Datenquelle fuer die Startseite und fuer ein
Symbol in der Kopfleiste. Eine Instanz fuer die ganze Anlage (am Hub).

## Drei Teile

**Standorte** - jede `HomeSuite Bereich`-Instanz mit Ebene *Haus/Wohnung*: Belegung aus der
Anwesenheit (`Presence`/HSPR), bei vermieteten Standorten der Tagesstatus (`Rental`/HSRT),
Zahl und Schwere der Hinweise.

**Hinweise** - nur was abweicht und jemanden braucht, jeder mit seiner Loesung:

| Hinweis | Aktion |
|---|---|
| Rollo/Markise auf Hand | zurueck auf Automatik |
| Bewaesserung laeuft deutlich laenger als geplant | stoppen |
| Fenster offen, Klima laeuft | Klima aus |
| Router eines Standorts meldet nicht | - |
| Geraete nicht erreichbar (Geraetegesundheit) | - |
| Batterien leer (BatteryManager) | - |

Jeder Hinweis laesst sich ignorieren oder fuer heute ausblenden.

**Fahrplan** - naechste Rollofahrten (je Standort und Minute zusammengefasst),
Bewaesserungsfenster des Wochenplans (mit "entfaellt" bei Sperre), Planwechsel der
Klimazonen, Wochenplan-Ereignisse der uebrigen HomeSuite-Module.

## Ausgabe

- `HSOV_GetState(int $id)` - alles als JSON (`sites`, `hints`, `timeline`, `counts`)
- Variablen `Hinweise` (Anzahl), `Dringlichkeit` (0 ruhig, 1 Hinweis, 2 Warnung,
  3 dringend), `Lage (JSON)`
- `HSOV_Manage(int $id, string $json)`:
  `{"op":"act","args":{"id":"<Hinweis>","action":"<Aktion>"}}`, `{"op":"refresh"}`

Das Modul schaltet nichts von sich aus - es fuehrt nur angetippte Aktionen ueber die
oeffentlichen Befehle der Fachmodule aus.

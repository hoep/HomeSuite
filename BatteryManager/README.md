# BatteryManager

Sammelt automatisch **alle Gerätebatterien** in IP-Symcon, dedupliziert sie (HomeMatic
meldet dasselbe physische Gerät mehrfach), klassifiziert den Zustand (OK / Bald tauschen /
Leer / Unbekannt) und stellt daraus Statistik-Variablen, ein JSON-Register, eine
JSON-Tabelle sowie nach Status gruppierte Links im Objektbaum bereit.

- Prefix: `BM`
- Modul-GUID: `{E1F670B8-2C59-493E-BE02-6C53779193CB}`
- Typ: 3 (Modul-Instanz ohne Eltern-Anforderung)
- Vendor: Hoep · Repo: https://github.com/hoep/BatteryManager
- Aliase: BatteryManager, Batterie Manager, Batterien, Akku

---

## Überblick / Zweck

Batteriebetriebene Geräte verteilen sich über viele Subsysteme (HomeMatic, Z-Wave, Zigbee,
Shelly, Gardena, LinkTap, MQTT, Withings …) und melden ihren Ladezustand höchst
unterschiedlich: als Prozent, als LOWBAT-Bool, als Spannung oder als Enum-/Statusprofil.
Der BatteryManager scannt den gesamten Variablenbaum, erkennt Batterie-Variablen anhand von
Profil/Ident/Name, führt mehrere Variablen desselben Geräts zu **einem** Eintrag zusammen
und leitet einen einheitlichen Zustand ab.

**Wichtig — Scan läuft asynchron, nicht im Kernel-Thread:** Der vollständige
Variablen-Scan ist teuer und darf den Kernel nicht blockieren. Daher legt das Modul ein
verstecktes Worker-Skript (Ident `Worker`) an, dessen Inhalt nur `BM_Scan(<InstanzID>)`
aufruft. `BM_Update()` (Timer/Button) stößt dieses Skript per `IPS_RunScript` an und kehrt
sofort zurück; der schwere Scan läuft dann im Skript-Thread (gleiches Muster wie RainRadar).

---

## Konfiguration (Properties)

| Property | Typ | Default | Bedeutung |
|---|---|---|---|
| `Interval` | int | 30 | Scan-Takt in **Minuten** (0 = automatischer Scan aus) |
| `EmptyThreshold` | int | 15 | Prozentwert, **unter** dem eine Batterie als *Leer* gilt |
| `WarnThreshold` | int | 30 | Prozentwert, **unter** dem *Bald tauschen* gilt (≥ WarnThreshold = OK) |
| `StaleDays` | int | 21 | 0%-Karenz: steht ein Prozentwert auf 0 und wurde länger als N Tage nicht aktualisiert, gilt er als *Unbekannt* statt *Leer* |
| `MaxAgeDays` | int | 60 | Max. Alter für Prozent/Enum/Spannung: länger nicht aktualisiert ⇒ tote/Geister-Variable wird verworfen (0 = aus). **Bool/LOWBAT ausgenommen** (wird nur bei Änderung geschrieben) |
| `IncludeHidden` | bool | true | Versteckte Variablen einbeziehen (viele Batterie-Vars sind hidden) |
| `CreateLinks` | bool | true | Nach Status gruppierte Links im Baum anlegen/pflegen |
| `ExcludeIdents` | string | "" | Komma-Liste von Namens-/Ident-Teilstrings, die übersprungen werden |
| `ExcludeVars` | string (JSON) | `[]` | Liste `[{VarID:int}]` — im Baum gewählte Variablen ausschließen; entfernt das **ganze zugehörige Gerät** |
| `RootCategory` | int | 0 | Optionaler Ziel-Ordner für die Links (0 = direkt unter der Instanz) |

### Konfigurationsformular (`GetConfigurationForm`)

- Scan-Intervall, Bald-/Leer-Schwelle, 0%-Karenz und Max. Alter als NumberSpinner-Reihe.
- Checkboxen: „Versteckte Variablen einbeziehen" und „Links im Baum pflegen".
- `SelectCategory` für den Ziel-Ordner.
- Textfeld für die Ausschluss-Teilstrings.
- `List`-Editor `ExcludeVars` mit `SelectVariable` je Zeile (schließt das ganze Gerät aus).
- Button **„Jetzt scannen"** ⇒ `BM_Update($id)`.
- Statuszeile unten zeigt Ergebnis des letzten Scans (Zeitpunkt + Zähler aus dem Register).

---

## Status-Variablen (Idents)

| Ident | Typ | Name | Inhalt |
|---|---|---|---|
| `Total` | int | Batterien gesamt | Anzahl erkannter Geräte |
| `Ok` | int | OK | Geräte im Zustand OK |
| `Warn` | int | Bald tauschen | Geräte im Zustand *Bald* |
| `Empty` | int | Leer / tauschen | Geräte im Zustand *Leer* |
| `Unknown` | int | Unbekannt | Geräte ohne belastbaren Zustand |
| `Weak` | int | Handlungsbedarf | `Warn + Empty` |
| `OkPercent` | int | OK-Anteil | Prozent OK (Profil `~Intensity.100`) |
| `Status` | int | Gesamtstatus | Profil `BATT.Status`: 0 OK / 1 Bald / 2 Leer / 3 Unbekannt |
| `Register` | string | Register (JSON) | vollständiges Geräte-Register (siehe unten) |
| `Table` | string | Tabelle (JSON) | Tabellendarstellung fürs LVB table-Widget |
| `LastRun` | int | Letzter Scan | Unix-Zeitstempel (`~UnixTimestamp`) |
| `Progress` | int | Scan-Fortschritt | 0 = idle, 1..99 = läuft (für Frontend-Anzeige) |

Zusätzlich versteckt: `Worker` (Skript, autogeneriert — nicht händisch ändern).

### Profil `BATT.Status`

Wird beim `Create`/`ApplyChanges` sichergestellt (Integer 0–3):
0 = OK (grün), 1 = Bald tauschen (gelb), 2 = Leer (rot), 3 = Unbekannt (grau).

`Status` (Gesamtstatus) wird pessimistisch gesetzt: Leer > Bald > OK.

---

## Öffentliche Skript-/RPC-Funktionen

| Funktion | Rückgabe | Beschreibung |
|---|---|---|
| `BM_Update(int $InstanzID)` | void | Stößt den Worker asynchron an (Timer/Button). Setzt `Progress` auf 1 und kehrt sofort zurück. |
| `BM_Scan(int $InstanzID)` | void | Führt den **schweren** Scan aus — nur aus dem Worker-Thread aufrufen. Schreibt Statistik, Register/Tabelle und (falls aktiviert) Links. |
| `BM_Preview(int $InstanzID)` | string (JSON) | **Read-only**: rechnet den Scan durch und liefert das Register-JSON zurück, **ohne** Variablen oder Links im Baum zu schreiben. Ideal zum Testen/Vorschauen. |

---

## Erkennung, Dedup und Klassifizierung

### Erkennung (`detect`)

Zunächst harte Ausschlüsse (kein Wechselakku), u. a.:
- USV / NUT Client (`NUTC.*`, `DP_battery_*`)
- Fahrzeug-Traktionsakkus (BMWConnectedDrive, `hvSoc`, `stateOfCharge`, `cd_vehicle_` …)
- Report/HTML/String-Variablen (`~HTMLBox`, Typ 3)
- Kapazität in mAh (`BatterieInteger`) — kein Ladestand
- Mähroboter-Ladeakkus (Automower/Mower)
- Tempest-Wetterstation (Solar/Hub, `Tempest_*`)

Danach Zuordnung zu einer **Klasse**:
- `pct` — Prozent (`~Battery.100`, `batteryLevel`, `Z2M_Battery`, `Gardena.Battery` …)
- `bool` — LOWBAT-Bool (`~Battery`, `LOWBAT`, `LOWBAT_REPORTING`, `BatteryLowVariable`)
- `volt` — Batteriespannung (nur wenn Ident/Name explizit Batterie meint; `~Volt` allein
  ist Netz-/Zählerspannung und wird **nicht** genommen). Info-only, kein Schwellwert.
- `enum` — Status-/Enum-Profil mit Assoziationen (leer/low/mittel/ok/voll …)

Übersprungen werden zusätzlich Variablen in `_deprecated`/`Papierkorb`-Pfaden sowie alles,
was auf der Ausschluss-Liste steht. Prozent/Enum/Spannung, die älter als `MaxAgeDays` sind
oder nie aktualisiert wurden, fallen als tot/Geist heraus (Bool ausgenommen).

### Dedup (Device-Key)

Mehrere Batterie-Variablen desselben physischen Geräts werden zu einem Key
zusammengefasst:
- **HomeMatic:** Serial vor dem `:` in der Adresse (`HM:<Serial>`) — so wird ein Gerät mit
  mehreren Kanälen/LOWBATs nur **einmal** gezählt.
- sonst Instanz (`I:<InstanzID>`), sonst Objekt-Parent (`O:<ParentID>:<vid>`).

Der **Repräsentant** je Gerät wird per Rangfolge gewählt: `pct` > `bool` (LOWBAT vor
LOWBAT_REPORTING) > `enum` > `volt`; Tie-Breaks über Aktualität und VarID.

Ein per `ExcludeVars` gewählte Variable entfernt das **ganze** Gerät (über den Key).
Phantom-Netzaktoren (einziger Träger ist ein nie geschriebenes LOWBAT ohne echte
Batterie-Messung, z. B. Schalt-Steckdose) werden ebenfalls verworfen.

### Klassifizierung (`classify`)

- **bool:** true ⇒ Leer/schwach, false ⇒ OK.
- **pct:** ≥ WarnThreshold ⇒ OK; ≥ EmptyThreshold ⇒ Bald; sonst Leer. 0% + länger als
  StaleDays nicht gemeldet ⇒ Unbekannt („keine Meldung").
- **enum:** Textmuster (leer/low/kritisch ⇒ Leer, mittel/warn ⇒ Bald, ok/voll/gut ⇒ OK).
- **volt:** informativ, Zustand Unbekannt (kein generisches Schwellwert-Mapping).

Sortierung im Register: schlechtester Zustand zuerst (Leer → Bald → Unbekannt → OK), dann
Wert aufsteigend, dann Name.

---

## Ausgabe-Formate

### Register (JSON)

```json
{
  "ts": 1700000000,
  "ver": 1,
  "counts": { "total": 42, "ok": 30, "warn": 6, "empty": 3, "unknown": 3, "weak": 9 },
  "devices": [
    {
      "name": "Feuchtesensor Böschung", "room": "Garten", "system": "HM",
      "state": "empty", "type": "pct", "value": 8, "unit": "%",
      "text": "8%", "varId": 12345, "low": true, "ts": 1699990000
    }
  ]
}
```

`state` ist einer von `ok` / `warn` / `empty` / `unknown`; `system` eines von
`HM` / `ZWave` / `Zigbee` / `Shelly` / `Gardena` / `LinkTap` / `MQTT` / `Withings` /
`Tempest` / Modulname / `Other`.

### Tabelle (JSON)

Zeilenweise Matrix fürs LVB `table`-Widget; Zeile 0 ist der Spaltenkopf:

```
["Gerät", "Ort", "System", "Status", "Wert", "Aktualisiert"]
```

### Links im Baum

Bei aktivem `CreateLinks` legt das Modul unter dem Ziel-Ordner (oder unter der Instanz,
Ident `BatRoot`) vier Kategorien an — **Leer / tauschen**, **Bald tauschen**, **OK**,
**Unbekannt** — und pflegt darin Links auf die jeweilige Repräsentanten-Variable. Der
Abgleich ist idempotent: Links werden in die passende Gruppe verschoben, umbenannt
(`Raum · Gerät — Wert`) oder bei verschwundenen/ausgeschlossenen Geräten entfernt.

---

## Besondere Hinweise

- **Nie im Kernel scannen:** Der eigentliche Scan muss über `BM_Update()` /
  Worker-Skript laufen. `BM_Scan()` direkt aus dem Kernel-Thread würde blockieren.
- **QueryInterval / Scan-Takt:** `Interval` steuert den automatischen Scan (Minuten,
  0 = aus). `ApplyChanges` setzt den Timer entsprechend.
- **Bezeichner-Findung (`labelFor`):** Generische Container (Hardware, Homematic, Device
  Information, Maintenance, Kanal 0 …) werden übersprungen, damit Gerätename und Raum das
  echte Gerät treffen statt eines strukturellen Ordners.
- Eigene Instanz-Variablen werden nie als Batterie gewertet.
- `BM_Preview()` schreibt nichts in den Baum — sicher für Tests und Frontend-Vorschau.

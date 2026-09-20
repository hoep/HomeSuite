# DeviceHealth

Gerätegesundheit, markenübergreifend: **Erreichbarkeit**, **Konfigurationsstau** und
**Funkqualität**. Ersetzt die IPSLibrary-Komponente `IPSHomematic`.

- Prefix: `HSDH`
- Modul-GUID: `{76B650A1-98ED-4B9D-8C86-ED3D6C2C95A7}`
- Typ: 3 (Instanz ohne Eltern-Anforderung)

---

## Abgrenzung zum BatteryManager

Zwei Module, zwei Fragen. Der BatteryManager beantwortet „hat das Gerät noch Strom",
DeviceHealth beantwortet „kommt man noch an das Gerät heran und wie gut". `LOWBAT` wird
hier zwar gelesen, aber **nie als Zustand gewertet** — es erscheint nur als Hinweis in der
Zeile, damit man beim Lesen nicht rätselt. So bewertet keine Batterie zweimal.

---

## Woher die Aussagen kommen

**1. Die CCU — und nur sie.** `UNREACH`, `STICKY_UNREACH` und `CONFIG_PENDING` stehen
nirgends in IP-Symcon; die CCU führt diese Meldungen selbst. Abgefragt wird per XML-RPC
`getServiceMessages` auf Port 2001 (BidCos-RF) und 2010 (HmIP). Gerätenamen kommen per
ReGaHss (Port 8181, `ID_DEVICES`) und sind 10 Minuten gepuffert — ohne sie steht in einer
Meldung nur die nackte Seriennummer, und die sagt niemandem etwas. Ohne hinterlegte
CCU-Adresse bleibt nur der generische Teil.

**Dieses Modul ist die einzige Stelle, die die CCU nach Servicemeldungen fragt.** Das
LiveViewBuilder-Widget `msglog` holte sie bis 19.09.2026 selbst — bei **jedem** Poll zwei
XML-RPC-Aufrufe, ungepuffert. Seither liest `?api=hmmsg` die Variable `CcuMessages`
(Antwort unter 2 ms statt eines CCU-Rundlaufs) und fällt nur dann auf den eigenen Weg
zurück, wenn dieses Modul fehlt oder länger als zwei Scan-Takte nichts geliefert hat.

**2. `rssiInfo` — mit der richtigen Lesart.** Die Antwort ist eine Matrix `A -> B -> [X, Y]`,
und hier steckt die Falle: **X ist, was A von B empfangen hat, Y, was B von A empfangen hat.**
Der Wert gehört also dem *Sender*, nicht dem Zeilenschlüssel. Wer das vertauscht, erhält
statt 98 Geräten nur die vier Gateways, die überhaupt messen — an dieser Anlage der
Unterschied zwischen einer brauchbaren und einer nutzlosen Tabelle.

Gewertet wird der **beste** Wert je Gerät. Dass ein entferntes Gateway ein Gerät schwach
hört, ist normal; kritisch wird es erst, wenn auch das nächstgelegene es kaum noch hört.
`65536` heißt „kein Wert", positive Zahlen sind keine dBm.

**3. Generischer Instanz-Scan.** Für alles ohne CCU (Z-Wave, Zigbee, Shelly …): Instanzen
mit Datenkette (`ConnectionID > 0`) werden auf zwei Dinge geprüft — Instanzstatus (ab 200
ist Fehler, 104 „deaktiviert" gilt bewusst als Nicht-Befund) und **Stille**: das neueste
`VariableUpdated` aller Kindvariablen. Wer länger als `SilentDays` nichts mehr gesagt hat,
meldet sich nicht mehr.

HomeMatic-Geräte erscheinen in IP-Symcon als mehrere Kanalinstanzen. Zusammengeführt wird
über die Seriennummer aus dem Feld `Address` (`SERIE:KANAL`), sodass ein physisches Gerät
genau eine Zeile bekommt. Eine Seriennummer, die nur die CCU kennt, bekommt trotzdem eine
Zeile — sonst ginge genau die Meldung verloren, auf die es ankommt.

---

## Zustände

Aufsteigend nach Dringlichkeit; der schwerste Befund gewinnt, auch für den Gesamtstatus.

| Wert | Zustand | Woher |
|---|---|---|
| 0 | In Ordnung | kein Befund |
| 1 | Unbekannt | keine Variable, kein Funkwert — es gibt schlicht keine Aussage |
| 2 | Schwacher Funk | bester Empfang unter `WeakRssiDbm` |
| 3 | Konfiguration steht an | CCU: `CONFIG_PENDING` |
| 4 | Meldet sich nicht | länger als `SilentDays` still |
| 5 | Instanz im Fehler | `InstanceStatus >= 200` |
| 6 | Nicht erreichbar | CCU: `UNREACH` / `STICKY_UNREACH` |

---

## Konfiguration

| Property | Typ | Default | Bedeutung |
|---|---|---|---|
| `Interval` | int | 15 | Scan-Takt in Minuten (0 = aus) |
| `CcuIp` | string | `''` | HomeMatic-CCU; leer = CCU nicht fragen |
| `CcuPorts` | string | `2001,2010` | BidCos-RF und HmIP |
| `WeakRssiDbm` | int | −80 | darunter gilt der Funk als schwach |
| `SilentDays` | int | 7 | so lange still ⇒ meldet sich nicht (0 = aus) |
| `ScanInstances` | bool | true | generischer Instanz-Scan |
| `IgnoreModules` | string | `''` | Modulnamen überspringen, Komma-getrennt |
| `ExcludeInstances` | string | `[]` | einzelne Instanzen ausnehmen |
| `CreateLinks` | bool | true | Links der auffälligen Geräte im Baum pflegen |
| `RootCategory` | int | 0 | Ziel-Ordner (0 = unter der Instanz) |

Praxiswerte HomeMatic: über −70 dBm gut, −70 bis −80 brauchbar, darunter kritisch.

---

## Variablen

Zähler: `Total`, `Ok`, `Unreachable`, `Faulted`, `Silent`, `ConfigPending`, `WeakSignal`,
`Attention` (Summe aller Befunde), `OkPercent`, `Status` (Profil `HSDH.Status`).

Ausgaben: `Register` (JSON, ein Eintrag je Gerät), `Table` (JSON-Tabelle fürs
LVB-`table`-Widget — **zeigt nur Auffälliges**, weder „in Ordnung" noch „unbekannt"),
`RadioTable` (Funkstrecken, schwächste zuerst), `CcuMessages` (die CCU-Meldungen im Format
des `msglog`-Widgets: `sev`, `type`, `addr`, `iface`, `name`, `m`, `t`), `LastRun`,
`Progress`.

---

## Scripting-API

| Funktion | Wirkung |
|---|---|
| `HSDH_Update($id)` | stößt den Scan asynchron an (Timer/Knopf) |
| `HSDH_Scan($id)` | der eigentliche Durchlauf — **nur aus dem Worker-Skript** |
| `HSDH_Preview($id)` | rechnet durch und gibt das Register zurück, ohne zu schreiben |
| `HSDH_CcuMeldungen($id)` | Diagnose: was die CCU gerade führt |
| `HSDH_Bestaetigen($id, $addr, $type)` | eine Servicemeldung gezielt quittieren |

`HSDH_Bestaetigen` quittiert über das ReGaHss-Alarmobjekt `AL-<Adresse>.<Typ>` — also
genau **diese** Meldung, nicht pauschal alle. `CONFIG_PENDING` lässt sich damit nicht
wegdrücken: das löst sich erst, wenn die wartende Konfiguration das Gerät erreicht.
Nach erfolgreichem Quittieren stößt das Modul sofort einen Scan an, sonst hinge die
Anzeige bis zum nächsten Takt auf der eben bestätigten Meldung.

Der Scan läuft **nicht** im Kernel-Thread. `HSDH_Update()` startet nur ein verstecktes
Worker-Skript (Ident `Worker`); CCU-Abfragen und Instanz-Scan passieren dort. Eine hängende
CCU blockiert damit nichts — dasselbe Muster wie BatteryManager und RainRadar.

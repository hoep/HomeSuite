# HomeSuite Bereich (HSSP)

Schlankes Struktur-Element der HomeSuite-Topologie. **Eine Instanz bildet genau EINE Ebene der Raumstruktur ab** — welche, sagt die Property `Kind`: `Haus` | `Bereich` | `Raum`.

- **Modul-GUID:** `{5598F752-886D-475F-91CE-5813A3C581E5}`
- **Prefix:** `HSSP`
- **Typ:** 3 (Instanz-/Geräte-Modul)
- **Aliase:** HomeSuite Bereich, HSSP, Haus, Raum, Ebene
- **Klasse:** `HomeSuiteBereich extends IPSModule`
- **Repo:** https://github.com/hoep/HomeSuite

## Zweck / Überblick

Der „Bereich" ist das reine Gerüst, aus dem der HomeSuite Hub die Navigations- und Widget-Topologie ableitet:

```
Haus / Wohnung
└── Bereich / Ebene   (EG, OG, DG, Keller, Garten, Außenbereich …)
    └── Raum
        └── Entitäten je Gewerk (HeatingZone, ShadingDevice, LightDevice, …)
```

„Bereich/Ebene" ist bewusst **weit** gefasst: nicht nur EG/OG/DG, sondern auch Keller, Garten oder Außenbereich sind einfach je eine weitere `Bereich`-Instanz.

## Architektur — Hierarchie = Objektbaum

Die **Hierarchie IST der Symcon-Objektbaum selbst** (`IPS_GetParent`). Es gibt kein zweites Register und keine Zuordnungs-Property:

- Eine `Bereich`-Instanz hängt im Objektbaum unter ihrem `Haus`.
- Ein `Raum` hängt unter seinem `Bereich`.
- Die Entitäten (HeatingZone, ShadingDevice …) hängen unter ihrem `Raum`.

Damit gilt: **Zuordnung = Elternschaft.** Die Struktur wird ausschließlich per Drag & Drop im Objektbaum gepflegt — kein Doppelregister, keine Drift, keine Synchronisation.

### Bewusst KEIN EntityModule / kein HAL

Anders als die Gewerke-Module (HeatingZone, ShadingDevice, …) erbt der Bereich **nicht** von `EntityModule`:

- keine Bedien-Controls, keine Status-Variablen,
- kein HAL / kein Treiber-Binding,
- keine Timer, keine RegisterVariable-Aufrufe,
- kein `manifest()`, keine `mgmt`-Ops, kein Reflect/Reconcile.

Das Element trägt nur **Name** (= Instanzname im Baum), **Kind** (Ebene) und ein optionales **Kürzel**. Der HomeSuite Hub liest den Baum und baut daraus automatisch die Topologie (Haus → Bereich → Raum → Entitäten je Gewerk) für Navigation und Widgets.

## Konfiguration (Properties + Formular)

`Create()` registriert genau zwei String-Properties:

| Property | Typ | Default | Bedeutung |
|---|---|---|---|
| `Kind` | String | `Raum` | Ebene dieses Elements: `Haus` \| `Bereich` \| `Raum` |
| `Abbr` | String | `''` | Kürzel (optional), z. B. für kompakte Anzeige in Widgets |

`GetConfigurationForm()` liefert eine minimale Konsole:

- **Select „Ebene"** mit den Optionen
  - `Haus/Wohnung` → Wert `Haus`
  - `Bereich/Ebene` → Wert `Bereich`
  - `Raum` → Wert `Raum`
- **ValidationTextBox „Kürzel (optional)"** → Property `Abbr`
- **Hinweis-Label:** Struktur = Objektbaum: dieses Element unter sein übergeordnetes hängen (Haus > Bereich > Raum), die Entitäten unter den Raum; der Hub liest die Struktur automatisch.

Keine `actions`, kein `status`. Es gibt bewusst keinen „Struktur bearbeiten"-Dialog im Formular — dafür ist der Objektbaum zuständig.

## Status-Variablen / Controls

**Keine.** Das Modul registriert keine Variablen, keine Profile, keine Timer und keine schaltbaren Controls. Der Bereich ist ein passives Struktur-Element.

## Öffentliche Skript-/RPC-Funktionen

| Funktion | Rückgabe | Zweck |
|---|---|---|
| `HSSP_GetInfo(int $id)` | `string` (JSON) | Struktur-Infos dieses Elements für Hub/LVB |

`HSSP_GetInfo` liefert ein JSON-Objekt:

```json
{
  "iid":  12345,          // InstanceID
  "kind": "Raum",         // Kind-Property (Haus | Bereich | Raum)
  "abbr": "WZ",           // Kürzel (Abbr), ggf. leer
  "name": "Wohnzimmer"    // IPS_GetName der Instanz
}
```

### Beispiel

```php
$info = json_decode(HSSP_GetInfo(12345), true);
// $info['kind']  === 'Raum'
// $info['name']  === 'Wohnzimmer'
```

## Besondere Hinweise

- **Klassenname-Konvention:** Die Klasse heißt `HomeSuiteBereich` = `module.json`-`name` „HomeSuite Bereich" ohne Leerzeichen. Nicht umbenennen, sonst lädt Symcon das Modul nicht.
- **Struktur nur im Objektbaum pflegen.** Neues Haus/Bereich/Raum = neue HSSP-Instanz anlegen und an die richtige Stelle im Baum ziehen; Entitäten unter den jeweiligen Raum hängen. Es gibt keinen zweiten Ort, an dem die Zugehörigkeit gepflegt wird.
- **Der Hub ist der Konsument.** Der HomeSuite Hub (3-Zustand-Scharf-Master je Gewerk, Topologie-Abfrage) liest diese Elemente; Änderungen an der Struktur werden dort automatisch reflektiert.
- **Minimalistisch by design.** Fehlende Controls/Variablen/Timer sind Absicht, kein Auslassen — dieses Modul soll leichtgewichtig bleiben.

# RainRadar (Modul RR)

Symcon-Modul, das das GeoSphere-INCA-Regenradar auf eine Basiskarte komponiert und die
Ergebnisse als Variablen bereitstellt — vor allem als `RadarMeta`-JSON fuer die LVB-Frontend-Widgets
`rainradar` (zentrierte Radar-Karte) und `rainintensity` (48h-Leiste).

- Library-GUID: `{474EB98E-68FB-4164-A939-EBDC074DA7A6}`
- Modul-GUID: `{9B5DB5AD-B39A-4270-873A-D168F52C9ECA}`
- Prefix: `RR` (Aliase: `RainRadar`, `RR`, `Regenradar`)
- Vendor: Hoep · Repo: https://github.com/hoep/RainRadar
- Typ: 3 (Geraet/Instanz ohne Eltern-/Kind-Anforderungen)

## Zweck / Ueberblick

Das Modul kapselt die bewaehrte Radar-Engine (GD-Bildkomposition + Regenerkennung, urspruenglich aus
dem Alt-Skript `PHPRainRadar.php`) sauber als Symcon-Instanz. Es ersetzt das lose Ereignis-Skript durch:

- **Konsolen-Konfiguration** (Standort, Radar-Quelle, Basiskarte, Stunden, Animation, Schwelle, Takt),
- einen **zyklischen Timer** statt eines externen Ereignisses,
- registrierte **Variablen** (`RadarMeta`, `Forecast`, `Intensity`).

Aus dem GeoSphere-INCA-Radar (`INCAL_VW1398`, 1398x798) werden die zeitlich aufeinanderfolgenden
Radarbilder heruntergeladen, auf die Basiskarte `austria.png` komponiert (Standortmarker, Zeitstempel)
und als animiertes GIF plus Einzelframes im Ausgabe-Ordner abgelegt. Der Ausgabe-Ordner liegt unter
`/usr/share/symcon/tile/...` und ist damit direkt web-erreichbar unter `/tile/<ordner>/` — es wird
kein Media-Objekt benoetigt.

## Architektur

Die Engine liegt in `libs/WeatherRadar.php` als Klasse **`RainRadarEngine`** (bewusst NICHT
`WeatherRadar`, um eine Fatal-Redeklaration gegen das noch existierende Legacy-Skript
`PHPRainRadar.php` im geteilten PHP-Prozess zu vermeiden).

### Asynchroner Worker (kritisch)

Die schwere Verarbeitung (bis zu ~48 HTTP-Downloads + GD-Komposition + ImageMagick/GIF) laeuft
**niemals** synchron in einer Modul-Methode (Kernel-Thread) — das hat in der Erstversion den Kernel
zum Absturz gebracht. Stattdessen:

- `ensureWorker()` legt bei `Create`/`ApplyChanges` ein **verstecktes Worker-Skript** (Ident `Worker`,
  Name „RainRadar Worker (async)") unter der Instanz an und schreibt dessen Inhalt autogeneriert per
  `IPS_SetScriptContent`.
- `RR_Update()` (Timer-/Button-Callback) startet dieses Skript nur **asynchron** via `IPS_RunScript`
  und kehrt sofort zurueck. Die Last liegt damit im Skript-Thread, nicht im Kernel.
- Das Worker-Skript liest die Konfiguration live per `IPS_GetProperty`, baut das `RainRadarEngine`-
  Config-Array, ruft `processRadarImages()` und schreibt die drei Modul-Variablen per ID.

Weitere gehaertete Punkte in der Engine: Einzellauf-Sperre (`.rainradar.lock`, 600 s stale) und kurze
Timeouts (Connect 5 s / Transfer 8 s) in `processRadarImages`/`downloadRadarImage`, damit eine traege
Quelle keine ueberlappenden Dauerlaeufe erzeugt. `processRadarImages` bricht nach 2 Fehlschlaegen ab,
sodass jenseits des echten Vorhersage-Horizonts keine 404-Platzhalter mehr angehaengt werden.

### Standort-Pixel aus Geokoordinaten

Der Standort-Pixel auf der Basiskarte wird aus Geokoordinaten berechnet (statt eines fest verdrahteten
Pixels). Quelle-Prioritaet im Worker:

1. Properties `Lat`/`Lon` — bevorzugt.
2. Sind beide 0: `RainRadarEngine::symconLocation(LocationID)` (0 = erste Instanz mit gueltiger
   Location-Konfiguration) liefert Lat/Lon aus dem Symcon-Standort-Modul.
3. Nur wenn beides fehlt: manueller Pixel-Fallback `LocX`/`LocY`.

Die Umrechnung erledigt `RainRadarEngine::geoToPixel($lat, $lon, $baseW, $baseH)`: Laengengrad linear,
Breitengrad per Mercator, kalibriert an der Landmaske der GeoSphere-`austria.png` (Ref 1398x798,
proportional skaliert). Verifiziert am Hausstandort 48.2082/16.3738 -> Pixel (874,313) = lx 62.52 % /
ly 39.22 %.

## Konfiguration (Properties + Formular)

Das Formular (`GetConfigurationForm()`) dient der manuellen Notfall-/Erstkonfiguration; im Betrieb wird
das Radar ueber das LVB-Frontend verwaltet. Es zeigt zusaetzlich eine Statuszeile aus dem letzten Lauf
(Zeitstempel, Standort in %, Frame-Anzahl) und einen Button „Jetzt aktualisieren" (`RR_Update`).

| Property | Typ | Default | Bedeutung |
|---|---|---|---|
| `Lat` | float | 48.2082 | Breite; primaere Standortquelle |
| `Lon` | float | 16.3738 | Laenge; primaere Standortquelle |
| `LocationID` | int | 0 | Symcon-Standort-Modul (nur wenn Lat/Lon = 0; 0 = auto) |
| `LocX` | int | 875 | Fallback-Pixel X (nur ohne Geokoordinaten) |
| `LocY` | int | 316 | Fallback-Pixel Y (nur ohne Geokoordinaten) |
| `BaseMap` | string | `/usr/share/symcon/tile/rainradar/basemap.png` | Basiskarte (Pfad) |
| `RadarUrl` | string | GeoSphere `INCAL_VW1398`-Endpunkt | Radar-Bildquelle (URL-Praefix) |
| `OutputDir` | string | `/usr/share/symcon/tile/rainradar` | Ausgabe-Ordner (web unter `/tile/rainradar/`) |
| `Hours` | int | 72 | Headroom-Stunden; der Lauf endet am echten Vorhersage-Horizont |
| `AnimDelay` | int | 750 | ms je Frame im animierten GIF |
| `Threshold` | float | 0.1 | mm/h-Schwelle fuer Regen (Intensitaets-/Forecast-Logik) |
| `Interval` | int | 15 | Update-Takt in Minuten (0 = Timer aus) |

`ApplyChanges()` ruft `ensureWorker()` auf und setzt den Timer `Update` auf `Interval` Minuten
(0 = deaktiviert).

## Status-Variablen / Controls

Beim `Create` registrierte Variablen:

| Ident | Typ | Name | Position | Inhalt |
|---|---|---|---|---|
| `RadarMeta` | string | Radar Meta (JSON) | 10 | JSON fuer die LVB-Widgets (siehe unten) |
| `Forecast` | string | Vorhersage | 20 | Textliche Regenvorhersage der Engine |
| `Intensity` | float | Regen aktuell | 30 | aktuelle Intensitaet (mm/h) am Standort |

`RadarMeta`-JSON (`getRadarMeta(0)`):

```json
{
  "img": { "w": 1398, "h": 798, "lx": 62.52, "ly": 39.22,
           "file": "radar_animation_<id>.gif",
           "url": "/tile/rainradar/radar_animation_<id>.gif",
           "ts": "14.08.26 12:00" },
  "frames": [ { "url": "/tile/rainradar/radar_<t>.png", "t": "14.08. 12:00" } ],
  "forecast": [ { "t": 1723632000, "v": 0.30 } ]
}
```

- `img.lx`/`img.ly` sind der Standort in Prozent der ausgelieferten Bildgroesse (Basis fuer das
  standort-zentrierte Cover-Clipping im `rainradar`-Widget).
- `frames[]` sind die Einzelframes mit echtem Radar-Zeitpunkt (`t`) fuer den Frame-Animationsmodus.
- `forecast[]` ist die Zeitreihe (Unix-`t`, Intensitaet `v`) fuer die `rainintensity`-48h-Leiste.

Modulstatus (`form.json`): 102 = Aktiv, 200 = Fehler beim Radar-Lauf.

## Oeffentliche Skript-/RPC-Funktionen

- **`RR_Update(int $InstanceID)`** — Timer-/Button-Callback. Loest den Worker rein asynchron aus
  (`IPS_RunScript`) und kehrt sofort zurueck; legt den Worker bei Bedarf zuvor an. Blockiert/craSht
  den Kernel nicht.

Die eigentliche Bild- und Analyselogik ist die Engine `RainRadarEngine` (in `libs/WeatherRadar.php`),
die vom Worker-Skript genutzt wird. Wichtige oeffentliche Methoden:

- `geoToPixel($lat, $lon, $baseW, $baseH)` (static) — Geo -> Basiskarten-Pixel.
- `symconLocation($locId = 0)` (static) — Lat/Lon aus dem Symcon-Standort-Modul.
- `processRadarImages($config = [])` — Downloads + Komposition + Animation (gesperrt, gehaertet).
- `createAnimation(...)` — GIF-Erzeugung.
- `getRain($coordinateIndex)` — Intensitaets-Zeitreihe am Standort.
- `getRainForecast($coordinateIndex = 0, $hours = 48)` — textliche Vorhersage.
- `getRadarMeta($coordinateIndex = 0)` — das oben beschriebene Frontend-JSON.
- `getImageSequence()`, `cleanOutputDirectory(...)`, `getDebugInfo()`, `getHTML(...)`.

## Besondere Hinweise

- **Kein synchrones Schwerlast-Update.** Nie die Engine direkt im Kernel-Thread laufen lassen — immer
  ueber das Worker-Skript. Auf der Produktivanlage keine manuellen Test-/Worker-Trigger absetzen und
  dem Timer erst nach einem beobachteten Einzellauf vertrauen.
- **Klassennamen-Kollision.** Die Engine heisst `RainRadarEngine`, nicht `WeatherRadar`, wegen des
  parallel existierenden Legacy-`PHPRainRadar.php`.
- **Ausgabe-Ordner-Rechte.** `OutputDir` unter `/usr/share/symcon/tile/...` wird vom symcon-Prozess
  angelegt; der Shell-User hat dort keine Schreibrechte. Frames/GIF sind ohne Media-Objekt direkt
  unter `/tile/<ordner>/` erreichbar.
- **Modul-Registrierung/Reload.** `MC_ReloadModule(<StoreID>, 'RainRadar')` laedt die Library ohne
  Kernel-Neustart; Doppel-Reloads vermeiden.
- **Live-Kontext.** Instanz `#<ID>`, `RadarMeta`-Var `#<ID>`, Worker-Skript `#<ID>`, Timer 15 min.
  Die alte Kette (Skript 57952 / Event 58110) ist als Fallback deaktiviert vorhanden. Die LVB-Widgets
  `rainradar`/`rainintensity` binden die `RadarMeta`-Variable.

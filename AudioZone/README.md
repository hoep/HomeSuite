# AudioZone (HSAU)

HomeSuite-Domaenen-Modul **Audio/Media** — eine Instanz = ein Renderer = ein Raum-Lautsprecher (Portierung von IPSSonos). Deckt Transport, Lautstaerke, Quellen (Favorit/Radio/Playlist), Multiroom, Sleep-Timer, Radio-Now-Playing/Direktstream sowie einen HomeSuite-eigenen Audio-Wochenplan (Wiedergabe/Wecken) ab.

- **GUID:** `{C4F2639D-2A87-453D-8175-B586BF605A38}`
- **Prefix:** `HSAU` · **Typ:** 3 (Instanz-Modul) · **Aliase:** AudioZone, HSAU, HomeSuite Audio
- **Basisklasse:** `Hoep\HomeSuite\EntityModule` (Manifest -> Variablen, native `RequestAction`, RPC-Trio, FabricStore, Hub-Anbindung)

> Die Bridge-Variante **AudioZoneBridged (HSAUX)** erbt AudioZone und stellt **dieselben** Prozeduren unter dem Prefix `HSAUX_` bereit.

---

## Architektur / HAL

Gesteuert wird ueber einen generischen **IAudioRenderer**-Treiber aus der `DriverFactory`:

- **`generic-audio`** (Default) — variablen-/skriptgebunden. Im Uebergang bindet er an die vorhandenen **IPSSonos-Raum-Dummy-Variablen** (TRANSPORT/VOLUME/MUTE/…). Spaeter ohne Code-Aenderung auf einen nativen Treiber (`sonos-upnp`, `heos`) umstellbar.
- **`sonos-upnp`** — nativer UPnP/SOAP-Treiber (Host-IP + RINCON), wird ad hoc fuer Radio-Now-Playing, Direktstream und `playContent` erzeugt.

Grundprinzipien:

- **Keine harte IPSSonos-Abhaengigkeit.** Loeschbarkeit von IPSSonos entsteht spaeter durch den Treiberwechsel, nicht durch Code-Kopplung.
- **"Power" ist keine Codec-Methode** (IAudioRenderer kennt kein `power()`), sondern konfigurierbar: gebundene bool-Variable, Ein-/Aus-Skript oder Play/Stop ueber den Treiber (Default).
- **Multiroom** deklarativ ueber `setGroupMembers` (Koordinator + Mitglieder-UIDs).
- **Zeitregeln/Wecken** laufen rein HomeSuite ueber `play`/`stop` — kein Geraete-Alarm-Sync.

---

## Scharf-Modus (Armed) & Hub-Master

Real geschaltet wird **nur bei `Armed = true`** (scharf). Ist die Instanz nicht scharf oder kein Treiber gebunden, laeuft alles im **Schatten-Modus**: die Statusvariable wird optimistisch gesetzt und ein Debug protokolliert, aber es wird nichts real ausgeloest.

Der effektive Scharf-Zustand kommt aus `armedEffective()`: der **Hub-3-Zustand-Scharf-Master je Domaene** (Aus/Auto/Scharf, `audioMode` + `hubArmGate`) hat Vorrang vor der lokalen `Armed`-Property. So kann eine ganze Domaene zentral scharf/still geschaltet werden.

---

## Konfiguration

### Native Properties (`Create`)

| Property | Typ | Default | Zweck |
|---|---|---|---|
| `Driver` | string | `''` | Treiber-ID (`generic-audio`, `sonos-upnp`, …); leer -> Schatten |
| `Armed` | bool | `false` | Scharf; real schalten nur wenn true (Hub-Master ueberstimmt) |
| `QueryInterval` | int (s) | `5` | Refresh-/Abfrage-Intervall; Boden effektiv 2 s (kein Hot-Loop) |
| `ConfigSchema` | int | `0` | Migrations-Marker (Store->Properties) |

Nur die flachen Felder (`driver`/`armed`) sind Properties. Die tief verschachtelte Bindung (`bind`/`reflect`/`power`/`group`/`caps`/`schedule`) liegt im **FabricStore** unter `config`. `cfg()` mergt beides.

### Bindungs-Struktur (`configureDriver` / Store `config`)

```
driver, armed,
bind    { transport{varId,map}, volume, mute, repeat, shuffle, position,
          source{ favorite, radio, playlist } },
reflect { title, artist, album, albumArtist, coverUri, positionTime,
          duration, playState, volume, mute, repeat, shuffle },
power   { mode: var|script|playstop, varId, invert, scriptOn, scriptOff },
group   { mode: script, scriptId, rinconVarId, masterVarId, slaveVarId,
          masterRinconVarId, masterNameVarId },
schedule{ enabled, sourceKind, sourceId, volume, rampMin, powerOffEnd,
          quietFrom, quietTo, quietCapVol }
```

Jede Steuergroesse ist **Variable ODER Skript**. Referenzen (RegisterReference/`bl_`-Links) werden nur auf real gebundene Objekte gelegt, nie hart auf IPSSonos-IDs.

### Konsolen-Formular (`GetConfigurationForm`)

Bewusst schlank (Verwaltung/Visu im LiveViewBuilder), nur Notfall/Diagnose:

- **Abfrage-Intervall (s)** — `QueryInterval`
- **IPSSonos-Raum-Instanz (Import)** + Button *Aus IPSSonos importieren (Schatten)*
- Statuszeile (Health + scharf/Schatten)
- Buttons *Bindung pruefen*, *Treiber-Status*, *Konfiguration lesen*

---

## Status-Variablen / Controls (Manifest)

Domaene `audio`, Icon `Speaker`.

**Schaltbar (actionable):**

| Ident | Typ | Rolle |
|---|---|---|
| `Transport` | Command | Zurueck/Play/Pause/Stop/Weiter (Codes 5/1/2/3/4) |
| `Volume` | Level 0–100 % | Lautstaerke |
| `Mute` | Switch | Stumm |
| `Power` | Switch | Ein/Aus (var/script/playstop) |
| `Repeat` | Select | Aus/Titel/Alle |
| `Shuffle` | Switch | Zufall |
| `Position` | Level 0–100 % | Position (in Sekunden umgerechnet aus Dauer) |
| `SourceFavorite` / `SourceRadio` / `SourcePlaylist` | Select | Quelle nach Index |

**Reflect (read-only Now-Playing/Zustand):** `Title`, `Artist`, `Album`, `AlbumArtist`, `CoverUri`, `PositionTime`, `Duration`, `PlayState`, `GroupRole`, `GroupCoordinator`, `Online`.

**Weitere:** `BindHealth` (Bindungs-Status-Text), `ProgramJson` (Audio-Programm als JSON zur Baum-Transparenz), Ereignis `AudioSchedule` (nativer Wochenplan).

Bedienung von `Volume`, `Power`, `SourceFavorite/Radio/Playlist` oeffnet ein `manualHold`-Fenster (Automatik-Hoheit): reflektierte Ist-Werte ueberschreiben in dieser Zeit nicht (kein Flackern).

---

## Refresh & Now-Playing

`Refresh` (Timer, `QueryInterval`) liest bei einem `IAudioStateReadable`-Treiber den Ist-Zustand (`readState`) und spiegelt Titel/Interpret/Album/Cover/Position/Dauer/PlayState/Online sowie Volume/Mute/Position-%. Danach `readGroup` (Rolle/Koordinator) und `runSchedule`.

---

## Audio-Wochenplan (Wiedergabe/Wecken)

Zeitplan-**Wahrheit** ist ein nativer Symcon-Wochenplan (Ereignis `AudioSchedule`, Typ 2, Aktionen Aus=0 / An=1). `ensureScheduleEvent()` legt ihn an und migriert die HomeSuite-Slots hinein.

`runSchedule` wertet Flanken aus:

- **0 -> an:** Power on -> Quelle (`sourceKind`/`sourceId` bzw. Slot-Index) -> Volume. Optional **sanftes Wecken (Ramp)**: Volume schrittweise ueber `rampMin` Minuten (Timer `Ramp`, 4 s-Schritte, ab 0 hoch).
- **an -> 0:** `stop` (+ optional Power off bei `powerOffEnd`).

**Ruhezeit / Nachtabsenkung:** `quietFrom`/`quietTo` (Minuten seit Mitternacht, ueber Mitternacht erlaubt) deckeln das Volume auf `quietCapVol`.

**Sleep-Timer:** `setSleep(minutes)` startet Einschuss-Timer `Sleep` -> `stop` + Power off; `cancelSleep` bricht ab.

Alle Schaltvorgaenge respektieren Scharf-Modus (im Schatten nur Debug-Protokoll "WUERDE …").

---

## Verwaltungs-Operationen (`HSAU_Manage`, RPC `mgmt`)

| Op | Zweck |
|---|---|
| `configureDriver` | Treiber/Bindung schreiben (`dryrun` moeglich) |
| `importLegacy` | Aus IPSSonos-Raum-Instanz binden (Schatten, armed=false) |
| `migrateConfig` | Flache Store-config -> native Properties (einmalig) |
| `getConfig` / `validate` / `driverProbe` | Diagnose (Config lesen / Bindung pruefen / Treiber-Status) |
| `setArmed` | Scharf/Schatten setzen |
| `group` / `ungroup` / `setGroupVolume` | Multiroom |
| `seek` / `playSource` | Position % / Quelle abspielen |
| `updateProfile` / `getSchedule` / `configureSchedule` | Wochenplan-Slots / lesen / Optionen (Quelle/Volume/Ramp/Ruhezeit) |
| `setSleep` / `cancelSleep` | Sleep-Timer |
| `computeProbe` | Trockenlauf: Zeitplan/Regel-Vorschau (onNow, VolumeCap, armed, sleepUntil) |
| `radioNow` | Radio: laufender Titel + Song-Cover (20 s gecacht, Fallback Sender-Logo) |
| `playDirect` | Werbefreien HQ-Direktstream eines Senders spielen (statt TuneIn) |
| `radioStations` | Senderliste (RadioNow) |
| `playContent` | Bibliotheks-Inhalt (ContentRef, ueber Hub `mediaResolve` aufgeloest) abspielen |

Standard-mgmt-Ops (`createEntity`/`renameEntity`/`deleteEntity`/`moveEntity`) kommen aus `EntityModule`.

---

## Skript-/RPC-Funktionen (`HSAU_…`, identisch `HSAUX_…`)

Alle Setter gehen intern ueber `RequestAction`; real nur bei `Armed`.

### Generisch (EntityModule)
| Funktion | Beschreibung |
|---|---|
| `bool HSAU_SetControl(int $id, string $Ident, mixed $Value)` | beliebigen actionable Control setzen |
| `mixed HSAU_GetControlValue(int $id, string $Ident)` | aktuellen Statuswert lesen |

### Transport & Wiedergabe
`HSAU_Play` · `HSAU_Pause` · `HSAU_StopPlayback` · `HSAU_Next` · `HSAU_Previous` (je `int $id` -> bool)
`bool HSAU_SetVolume(int $id, int $Percent)` (0–100) · `HSAU_SetMute(int $id, bool $On)` · `HSAU_SetPower(int $id, bool $On)` · `HSAU_SetRepeat(int $id, int $Mode)` (0=Aus,1=Titel,2=Alle) · `HSAU_SetShuffle(int $id, bool $On)` · `HSAU_Seek(int $id, int $Percent)`

### Quellen
`HSAU_PlayFavorite(int $id, int $Index)` · `HSAU_PlayRadio(int $id, int $Index)` · `HSAU_PlayPlaylist(int $id, int $Index)` · `HSAU_PlayDirectRadio(int $id, string $StationKey)`

### Sleep / Multiroom / Cutover
`HSAU_SetSleep(int $id, int $Minutes)` · `HSAU_CancelSleep(int $id)` · `HSAU_SetGroupVolume(int $id, int $Percent)` · `HSAU_Ungroup(int $id)` · `HSAU_SetArmed(int $id, bool $Armed)`

### Lesen
`int HSAU_GetVolume(int $id)` · `bool HSAU_IsPlaying(int $id)` · `bool HSAU_IsOnline(int $id)`

### Beispiele
```php
HSAU_SetVolume(11994, 20);
HSAU_Play(11994);
HSAU_PlayDirectRadio(11994, 'oe3');   // werbefreier HQ-Stream
HSAU_SetSleep(11994, 30);             // in 30 Min aus
```

---

## Besondere Hinweise

- **Real schaltet nur `Armed` (scharf).** Cutover ist ein eigener Schritt; sonst Schatten-Modus.
- **QueryInterval** je Instanz (Sekunden), effektiver Boden 2 s.
- **Baum-Transparenz:** fuer `generic-audio` werden sichtbare `bl_`-Links auf alle gebundenen/gespiegelten Objekte gelegt; `sonos-upnp`/`heos` binden an Netzwerkadressen -> keine Objekt-Links.
- **Import** aus IPSSonos setzt bewusst `armed=false` (Schatten), damit vor dem Cutover nichts real geschaltet wird.
- **Radio-Now-Playing** nutzt streamContent des Players, faellt bei Wortbeitrag auf ICY/RadioNow zurueck und zeigt bei fehlendem Song-Cover das Sender-Logo.

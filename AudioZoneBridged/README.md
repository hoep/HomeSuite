# AudioZoneBridged (HSAUX)

Bridge-Variante des Domänen-Moduls „Audio/Media" — **eine Instanz = ein Wiedergaberaum**,
angebunden nicht per Timer-Poll, sondern als **KIND einer Bridge** (HEOS/MusicCast/Denon).
`AudioZoneBridged` erbt die komplette Logik von [`AudioZone`](m_AudioZone.md) (identisches
Manifest, identische Controls, identische Skript-API) und tauscht nur den **Transport**:
Kommandos gehen über `SendDataToParent` an die Bridge, Zustands-Frames kommen via
`ReceiveData` zurück. So trägt dieselbe LVB-Seite/dieselben Widgets einen anderen Treiber.

- **GUID:** `{053E7017-584E-4F62-A246-EBA6CE3DE034}`
- **Prefix:** `HSAUX`  ·  Aliase: `AudioZoneBridged`, `HSAUX`, `HomeSuite Audio (Bridge)`
- **Typ:** 3 (Geräte-/Entitätsinstanz)  ·  Vendor: Hoep
- **Basisklasse:** `AudioZone` (→ `\Hoep\HomeSuite\EntityModule`)
- **Parent (erforderlich):** HeosBridge `HSBH` `{BCDCA10C-BDFD-4270-8D28-1CC690A130DB}`
- **Daten-GUID (Bridge ↔ Zone):** `{D7E6F5C4-B3A2-4190-8E7D-6C5B4A392817}`

## Überblick / Architektur (HAL, PUSH)

`AudioZone` fährt PULL: ein Refresh-Timer liest den Ist-Zustand aus einer gebundenen
Variable/einem Treiber und spiegelt ihn. HEOS dagegen ist ein **PUSH-Protokoll**
(persistenter TCP-Socket, Port 1255). Diesen Socket hält die **Bridge** (Splitter `HSBH`),
nicht der Treiber — Grundsatz B1: Treiber sind zustandslos.

```
LiveViewBuilder / Skript-API
        │  RequestAction / HSAUX_…
        ▼
AudioZoneBridged (HSAUX)  ── erbt AudioZone ──
        │  Command-Frame "heos://…\r\n"
        │  SendDataToParent(DataID CHILD_DL)
        ▼
HeosBridge (HSBH)  ── hält den TCP-Socket (Port 1255) ──
        ▲  Event-/Antwort-Frames (JSON)
        │  ReceiveData(DataID CHILD_DL)
        ▼
AudioZoneBridged.parseEvent → AudioState → setReflect(…)
```

- **Treiber:** `heos` (`\Hoep\HomeSuite\HAL\Heos`, HAL-Interface `IAudioRenderer`).
  Der Treiber baut nur HEOS-CLI-Command-Frames (`heos://player/set_volume?pid=…`) und
  übersetzt eingehende JSON-Frames per `parseEvent()` in ein `AudioState`-Delta. Er
  registriert sich selbst bei der `DriverFactory` (`DriverFactory::register('heos', …)`).
- **Command-Pfad:** `driver()` erhält als `$send`-Callback ein `SendDataToParent`, das
  jeden Frame in `{DataID: CHILD_DL, Buffer: <frame>}` an die Bridge schiebt.
- **Reflect-Pfad:** `ReceiveData()` filtert auf die Daten-GUID, ruft `parseEvent()` (fremde
  `pid` → `null`, wird ignoriert) und spiegelt **nur die vom jeweiligen HEOS-Command
  betroffenen Felder** (Now-Playing / Volume / Mute / PlayState / Progress) in die
  Statusvariablen — plus `Online = true`.
- **Snapshot / Sicherheitsnetz:** `Refresh()` fordert über `driver()->poll()` gezielt
  Snapshot-Requests (`get_play_state`, `get_now_playing_media`, `get_volume`, `get_mute`)
  bei der Bridge an; die Antworten kommen wieder als Push-Frames. Zusätzlich läuft der
  HomeSuite-Zeitplan (`runSchedule()`) mit.

### Schatten-Modus & Hub-Scharf-Master

Wie bei allen HomeSuite-Domänenmodulen (geerbt von `AudioZone`):

- `Armed = false` → **Schatten-Modus**: `applyControl` schaltet nichts real, protokolliert
  nur (`SendDebug`); die optimistisch gesetzte Statusvariable bleibt erhalten.
- `Armed = true` → **scharf**: Command-Frames gehen real an die Bridge.
- Der effektive Scharf-Zustand kommt über `armedEffective()` aus dem **3-Zustand-Scharf-
  Master** des Hubs (`hubArmGate()`): Hub-Variable `ArmAudioMode` mit `0 = Aus`, `1 = Auto`
  (Instanz-Property entscheidet), `2 = Scharf`. Der Hub-Master **überstimmt** die
  Instanz-Property `Armed` domänenweit.

## Konfiguration (native Instanz-Properties)

Zusätzlich zu den von `AudioZone` geerbten Properties (`Driver`, `Armed`, `ConfigSchema`)
registriert `AudioZoneBridged` in `Create()`:

| Property | Typ | Default | Bedeutung |
|---|---|---|---|
| `PID` | string | `''` | **HEOS Player-ID** aus der Bridge-Playerliste (`players/get_players`) — identifiziert den Raum-Player |
| `Vendor` | string | `heos` | Treiber-/Vendor-Kennung für die `DriverFactory` |
| `QueryInterval` | int | `15` | **Refresh-/Snapshot-Intervall in Sekunden** (Sicherheitsnetz-Poll; Untergrenze 2 s gegen Hot-Loop) |

Zusätzlich wirken die geerbten Properties `Armed` (scharf/Schatten, vom Hub überstimmbar)
und `ConfigSchema` (Migrations-Marker). In `Create()` bindet sich die Instanz über
`ConnectParent(HSBH)` automatisch unter die HEOS-Bridge.

**Treiber-Aktivierung:** `driver()` liefert nur dann einen aktiven `heos`-Treiber, wenn
`PID` gesetzt **und** der Vendor bei der `DriverFactory` registriert ist. Ohne PID bleibt
die Instanz treiberlos (Schatten).

### Konfigurationsformular (`GetConfigurationForm`)

Schlankes Konsolen-Formular (überschreibt das von `AudioZone`):

- Hinweis: HEOS-Player als Kind der HEOS-Bridge; Bedienung/Visu laufen wie bei `AudioZone`
  im LiveViewBuilder (`?api=audio` findet sowohl `HSAU` als auch `HSAUX`).
- `ValidationTextBox` **HEOS Player-ID (pid)** → Property `PID`.
- `NumberSpinner` **Abfrage-Intervall (s)** → Property `QueryInterval`.

## Status-Variablen / Controls (geerbtes Manifest)

Domäne `audio`, Icon `Speaker`. Vollständig identisch zu `AudioZone`. Aus dem Manifest
erzeugte Variablen:

**Schaltbar (aktionierbar):**

| Ident | Typ | Rolle | Bedeutung |
|---|---|---|---|
| `Transport` | Command | `audio:transport` | Zurück / Play / Pause / Stop / Weiter |
| `Volume` | Level 0–100 % | `audio:volume` | Lautstärke |
| `Mute` | Switch | `audio:mute` | Stumm |
| `Power` | Switch | `audio:power` | Ein/Aus (Play/Stop bzw. gebundene Variable/Skript) |
| `Repeat` | Select | `audio:repeat` | Aus / Titel / Alle |
| `Shuffle` | Switch | `audio:shuffle` | Zufall |
| `Position` | Level 0–100 % | `audio:position` | Springen (Prozent → Sekunden) |
| `SourceFavorite` | Select | `audio:source-favorite` | Favorit nach Index |
| `SourceRadio` | Select | `audio:source-radio` | Radio nach Index |
| `SourcePlaylist` | Select | `audio:source-playlist` | Playlist nach Index |

**Reflect (read-only Now-Playing / Zustand):**
`Title`, `Artist`, `Album`, `AlbumArtist`, `CoverUri`, `PositionTime`, `Duration`,
`PlayState`, `GroupRole`, `GroupCoordinator`, `Online` — sowie die String-Variable
`BindHealth` (Bindungs-/Health-Text).

> Push-Besonderheit: `AudioZoneBridged` füllt die Reflects nicht in `Refresh()`, sondern
> in `ReceiveData()` command-selektiv. `GroupRole`/`GroupCoordinator`/`AlbumArtist` werden
> aktuell vom HEOS-Treiber nicht befüllt (Gruppen-Reflect ist Ausbaustufe der Bridge).

Bedienung von `Volume`, `Power`, `SourceFavorite`, `SourceRadio`, `SourcePlaylist` gilt als
automatisierbar (`isAutomated`) und öffnet ein manualHold-Fenster (Automatik-Hoheit).

### Timer (mit HSAUX-Prefix)

`setupTimers()` ist überschrieben, damit die Timer-Skripte den richtigen Prefix tragen:

| Timer | Aufruf | Zweck |
|---|---|---|
| `Refresh` | `HSAUX_Refresh(...)` | Snapshot-Requests an die Bridge + Zeitplan (Intervall = `QueryInterval` s, nur bei aktivem Treiber) |
| `Sleep` | `HSAUX_RunSleep(...)` | Ein-Schuss Sleep-Timer → stop |
| `Ramp` | `HSAUX_RunRamp(...)` | sanftes Wecken: Lautstärke schrittweise hochfahren |

## Öffentliche Skript-/RPC-Funktionen

**Identisch zu `AudioZone`, nur unter dem Prefix `HSAUX_`.** Alle `Set…`/Transport-Setter
laufen intern über `RequestAction` → `applyControl` (armed-Gate + Reflect bleiben erhalten).
Realer Effekt nur bei effektivem `Armed = true` + aktivem `heos`-Treiber (PID gesetzt).

### Transport & Wiedergabe
| Funktion | Zweck |
|---|---|
| `bool HSAUX_Play(int $id)` / `HSAUX_Pause(int $id)` / `HSAUX_StopPlayback(int $id)` | Play / Pause / Stop |
| `bool HSAUX_Next(int $id)` / `HSAUX_Previous(int $id)` | nächster / vorheriger Titel |
| `bool HSAUX_SetVolume(int $id, int $Percent)` | Lautstärke 0–100 % |
| `bool HSAUX_SetMute(int $id, bool $On)` | stumm |
| `bool HSAUX_SetPower(int $id, bool $On)` | Ein/Aus |
| `bool HSAUX_SetRepeat(int $id, int $Mode)` | 0 = Aus, 1 = Titel, 2 = Alle |
| `bool HSAUX_SetShuffle(int $id, bool $On)` | Zufall |
| `bool HSAUX_Seek(int $id, int $Percent)` | Position in % springen *(HEOS: No-Op, `capabilities.seek = false`)* |

### Quellen
| Funktion | Zweck |
|---|---|
| `bool HSAUX_PlayFavorite(int $id, int $Index)` | Favorit nach Index |
| `bool HSAUX_PlayRadio(int $id, int $Index)` | Radiosender nach Index |
| `bool HSAUX_PlayPlaylist(int $id, int $Index)` | Playlist nach Index |
| `bool HSAUX_PlayDirectRadio(int $id, string $StationKey)` | werbefreier HQ-Direktstream (Sender-Key) |

### Sleep, Multiroom & Cutover
| Funktion | Zweck |
|---|---|
| `bool HSAUX_SetSleep(int $id, int $Minutes)` / `HSAUX_CancelSleep(int $id)` | Sleep-Timer |
| `bool HSAUX_SetGroupVolume(int $id, int $Percent)` | Gruppen-Lautstärke |
| `bool HSAUX_Ungroup(int $id)` | aus der Gruppe lösen |
| `bool HSAUX_SetArmed(int $id, bool $Armed)` | scharf/Schatten (Cutover) |

### Lesen
| Funktion | Zweck |
|---|---|
| `int HSAUX_GetVolume(int $id)` | Lautstärke |
| `bool HSAUX_IsPlaying(int $id)` | spielt gerade? |
| `bool HSAUX_IsOnline(int $id)` | erreichbar? |

### Generisch (aus EntityModule)
| Funktion | Zweck |
|---|---|
| `bool HSAUX_SetControl(int $id, string $Ident, mixed $Value)` | beliebigen aktionierbaren Control setzen |
| `mixed HSAUX_GetControlValue(int $id, string $Ident)` | aktuellen Statuswert lesen |
| `void HSAUX_Refresh(int $id)` | Snapshot-Requests jetzt anstoßen |
| `string HSAUX_Manage(int $id, string $json)` | Verwaltungs-RPC (siehe unten) |

### Beispiele
```php
HSAUX_SetVolume($id, 25);
HSAUX_Play($id);
HSAUX_PlayDirectRadio($id, 'oe3');
HSAUX_SetSleep($id, 30);   // in 30 Min aus
```

## Verwaltungs-RPC (`HSAUX_Manage`, `mgmt`)

JSON `{"op": "...", "args": {...}}`. Sämtliche Operationen von `AudioZone` gelten geerbt,
u. a.: `configureDriver`, `getConfig`, `validate`, `driverProbe`, `setArmed`, `migrateConfig`,
`importLegacy`, `group` / `ungroup` / `setGroupVolume`, `seek`, `playSource`, `updateProfile`
/ `getSchedule` / `configureSchedule`, `setSleep` / `cancelSleep`, `computeProbe`, `radioNow`
/ `playDirect` / `radioStations`, `playContent` sowie die Basis-Entitäts-Ops (`createEntity`
/ `renameEntity` / `deleteEntity` / `moveEntity`).

## Zeitregeln / Wecken / Sleep (geerbt)

Rein HomeSuite über die ScheduleEngine, gesteuert per `play`/`stop` (kein Geräte-Alarm-Sync):

- **Nativer Wochenplan** (Symcon-Ereignis `AudioSchedule`, Aus/An) ist die Zeitplan-Wahrheit;
  Flanke 0→an: Power on → Volume (ggf. Ramp/Ruhezeit-Cap) → `playSource`, an→0: stop.
- **Sanftes Wecken** über den `Ramp`-Timer (Lautstärke schrittweise bis Ziel).
- **Ruhezeit-Deckel** (`quietFrom`/`quietTo`/`quietCapVol`) begrenzt nachts die Lautstärke.
- **Sleep-Timer** stoppt nach N Minuten und schaltet Power aus.

## Besondere Hinweise

- **Kind der HEOS-Bridge:** eine `HSAUX`-Instanz ohne konfigurierten Parent `HSBH` bleibt
  ohne Transport. Die Bridge hält den Socket; die Zone hält nur die PID.
- **Kein realer Befehl im Schatten-Modus** — der optimistische `SetValue` der Statusvariable
  erfolgt trotzdem, damit Frontend/Automatik konsistent bleiben.
- **Hub-Master vor Instanz-Property:** `Armed` einer Einzelinstanz wirkt nur, wenn der Hub im
  `Auto`-Modus (bzw. ohne Master `ArmAudioMode`) ist; `Aus`/`Scharf` überstimmen domänenweit.
- **QueryInterval** ist pro Instanz einstellbar (Sekunden, Untergrenze 2 s). Der Poll ist bei
  HEOS nur ein Sicherheitsnetz — der Regelfall sind Push-Events.
- **HEOS-Grenzen:** kein direktes Seek (v1), Gruppen-/Favoriten-Listen laufen über die Bridge
  (`browse/…`) und sind Ausbaustufe; `GroupRole`/`GroupCoordinator` daher noch nicht gespiegelt.
- **`?api=audio`** im LiveViewBuilder listet `HSAU` und `HSAUX` gemeinsam — dieselben Widgets
  (audioroom / multiroom / session) und dieselbe Bedienung.
- Backup vor jeder Skriptänderung; keine Emojis in Git/README.

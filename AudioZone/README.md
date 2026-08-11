# AudioZone (HSAU)

Domänen-Modul „Audio/Media" — eine Instanz = ein Wiedergaberaum (Sonos/HEOS). Transport, Lautstärke, Quellen, Multiroom und Sleep-Timer.

> Die Bridge-Variante **AudioZoneBridged (HSAUX)** erbt AudioZone und stellt **dieselben** Prozeduren unter dem Prefix `HSAUX_` bereit.

## Skript-API (PHP-Befehlsreferenz)

> Alle `Set…`/Transport-Funktionen gehen intern über `RequestAction`. Audio ist scharf (`Armed=true`) → die Setter wirken real.

### Generisch (aus EntityModule)
| Funktion | Beschreibung |
|---|---|
| `bool HSAU_SetControl(int $id, string $Ident, mixed $Value)` | beliebigen actionable Control setzen |
| `mixed HSAU_GetControlValue(int $id, string $Ident)` | aktuellen Statuswert lesen |

### Transport & Wiedergabe
| Funktion | Zweck |
|---|---|
| `bool HSAU_Play(int $id)` / `HSAU_Pause(int $id)` / `HSAU_StopPlayback(int $id)` | Play / Pause / Stop |
| `bool HSAU_Next(int $id)` / `HSAU_Previous(int $id)` | nächster / vorheriger Titel |
| `bool HSAU_SetVolume(int $id, int $Percent)` | Lautstärke 0–100 % |
| `bool HSAU_SetMute(int $id, bool $On)` | stumm |
| `bool HSAU_SetPower(int $id, bool $On)` | Ein/Aus |
| `bool HSAU_SetRepeat(int $id, int $Mode)` | 0=Aus, 1=Titel, 2=Alle |
| `bool HSAU_SetShuffle(int $id, bool $On)` | Zufall |
| `bool HSAU_Seek(int $id, int $Percent)` | Position in % springen |

### Quellen
| Funktion | Zweck |
|---|---|
| `bool HSAU_PlayFavorite(int $id, int $Index)` | Favorit nach Index |
| `bool HSAU_PlayRadio(int $id, int $Index)` | Radiosender nach Index |
| `bool HSAU_PlayPlaylist(int $id, int $Index)` | Playlist nach Index |
| `bool HSAU_PlayDirectRadio(int $id, string $StationKey)` | werbefreier HQ-Direktstream (Sender-Key) |

### Sleep & Multiroom & Cutover
| Funktion | Zweck |
|---|---|
| `bool HSAU_SetSleep(int $id, int $Minutes)` / `HSAU_CancelSleep(int $id)` | Sleep-Timer |
| `bool HSAU_SetGroupVolume(int $id, int $Percent)` | Gruppen-Lautstärke |
| `bool HSAU_Ungroup(int $id)` | aus der Gruppe lösen |
| `bool HSAU_SetArmed(int $id, bool $Armed)` | scharf/Schatten |

### Lesen
| Funktion | Zweck |
|---|---|
| `int HSAU_GetVolume(int $id)` | Lautstärke |
| `bool HSAU_IsPlaying(int $id)` | spielt gerade? |
| `bool HSAU_IsOnline(int $id)` | erreichbar? |

### Beispiele
```php
HSAU_SetVolume(11994, 20);
HSAU_Play(11994);
HSAU_PlayDirectRadio(11994, 'oe3');
HSAU_SetSleep(11994, 30);   // in 30 Min aus
```

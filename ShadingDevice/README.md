# ShadingDevice (HSSH)

Domänen-Modul „Beschattung" — eine Instanz = ein Rollo/Markise/Jalousie. Fährt auf Zielposition, bewegt richtungsweise und steuert Modus/Plan/Season sowie das Sonnenstands-Profil.

## Skript-API (PHP-Befehlsreferenz)

> Alle `Set…`-Funktionen gehen intern über `RequestAction`. **Realer Effekt nur bei `Armed=true`** — solange `Armed=false` bleibt es Schatten-Modus (die Altsteuerung bleibt Regler; schützt vor kollidierenden Bus-Telegrammen).

### Generisch (aus EntityModule)
| Funktion | Beschreibung |
|---|---|
| `bool HSSH_SetControl(int $id, string $Ident, mixed $Value)` | beliebigen actionable Control setzen |
| `mixed HSSH_GetControlValue(int $id, string $Ident)` | aktuellen Statuswert lesen |

### Setzen
| Funktion | Zweck |
|---|---|
| `bool HSSH_SetPosition(int $id, int $Percent)` | Zielposition 0=offen … 100=zu |
| `bool HSSH_Move(int $id, string $Direction)` | `up`\|`auf`\|`1` / `down`\|`ab`\|`zu`\|`2` / `stop`\|`0` |
| `bool HSSH_MoveUp(int $id)` / `HSSH_MoveDown(int $id)` / `HSSH_MoveStop(int $id)` | Kurzformen |
| `bool HSSH_SetMode(int $id, int $Mode)` | 0=Auto, 1=Manuell, 2=Sonne |
| `bool HSSH_SetPlan(int $id, int $Plan)` | 0=Anwesend, 1=Abwesend, 2=Urlaub |
| `bool HSSH_SetSeason(int $id, int $Season)` | 0=Sommer, 1=Winter |
| `bool HSSH_SetSunAzimuthBegin(int $id, int $Deg)` | Sonnen-Azimut Beginn 0–360° |
| `bool HSSH_SetSunAzimuthEnd(int $id, int $Deg)` | Sonnen-Azimut Ende 0–360° |
| `bool HSSH_SetSunElevation(int $id, int $Deg)` | Sonnen-Elevation −10–90° |
| `bool HSSH_SetArmed(int $id, bool $Armed)` | scharf/Schatten (Cutover) |

### Lesen
| Funktion | Zweck |
|---|---|
| `int HSSH_GetPosition(int $id)` | Ziel-Position |
| `int HSSH_GetActualPosition(int $id)` | Ist-Position |
| `int HSSH_GetMode(int $id)` | Modus |
| `bool HSSH_IsOnline(int $id)` | Erreichbarkeit |

Wochenplan/Treiberbindung/Automatik/Diagnose laufen über `HSSH_Manage(op:…)`.

### Beispiele
```php
HSSH_SetPosition(16537, 100);  // schließen
HSSH_MoveUp(16537);            // öffnen
HSSH_SetMode(16537, 2);        // Sonnenautomatik
```

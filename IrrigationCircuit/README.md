# IrrigationCircuit (HSIR)

Domänen-Modul „Bewässerung" — eine Instanz = ein Bewässerungskreis. Startet/stoppt den Kreis, steuert Basisdauer, saisonale Anpassung und Programm; berücksichtigt Regen-Gate.

## Skript-API (PHP-Befehlsreferenz)

> Alle `Set…`/`Run…`-Funktionen gehen intern über `RequestAction`. **Realer Effekt nur bei `Armed=true`**. `RunNow` umgeht die Gates bewusst (expliziter Nutzerbefehl).

### Generisch (aus EntityModule)
| Funktion | Beschreibung |
|---|---|
| `bool HSIR_SetControl(int $id, string $Ident, mixed $Value)` | beliebigen actionable Control setzen |
| `mixed HSIR_GetControlValue(int $id, string $Ident)` | aktuellen Statuswert lesen |

### Setzen / Aktionen
| Funktion | Zweck |
|---|---|
| `bool HSIR_SetActive(int $id, bool $On)` | Kreis ein/aus (Start/Stop mit konfig. Dauer) |
| `bool HSIR_SetAutomatic(int $id, bool $On)` | Zeitplan-Automatik |
| `bool HSIR_SetDuration(int $id, int $Minutes)` | Basisdauer (0–240 min) |
| `bool HSIR_SetSeasonalAdjust(int $id, int $Percent)` | saisonale Anpassung (0–200 %) |
| `bool HSIR_SetProgram(int $id, int $Program)` | Programm 0–6 |
| `bool HSIR_RunNow(int $id, int $Minutes = 0)` | Sofortlauf; 0 = konfigurierte Dauer |
| `bool HSIR_Stop(int $id)` | laufende Bewässerung abbrechen |
| `bool HSIR_SetArmed(int $id, bool $Armed)` | scharf/Schatten (Cutover) |

### Lesen
| Funktion | Zweck |
|---|---|
| `bool HSIR_IsRunning(int $id)` | läuft gerade? |
| `bool HSIR_IsRainBlocked(int $id)` | durch Regen-Gate gesperrt? |
| `string HSIR_GetLastRun(int $id)` | Status/letzter Lauf |
| `float HSIR_GetEffectiveMinutes(int $id)` | berechnete Laufdauer (Basis × Saison, inkl. Gates) |

### Beispiele
```php
HSIR_RunNow(11743, 10);   // 10 Minuten sofort
HSIR_Stop(11743);
HSIR_SetSeasonalAdjust(11743, 120);  // 20 % mehr
```

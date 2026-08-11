# LightDevice (HSLT)

Domänen-Modul „Licht" — eine Instanz = eine Leuchte/Lichtgruppe. Schaltet An/Aus, Helligkeit und Farbtemperatur des gebundenen Leuchtmittels.

## Skript-API (PHP-Befehlsreferenz)

> Alle `Set…`-Funktionen gehen intern über `RequestAction`. **Realer Effekt nur bei `Armed=true` + gebundenem Treiber**. Rückgabe `bool` wie bei allen HomeSuite-Modulen.

### Generisch (aus EntityModule)
| Funktion | Beschreibung |
|---|---|
| `bool HSLT_SetControl(int $id, string $Ident, mixed $Value)` | beliebigen actionable Control setzen |
| `mixed HSLT_GetControlValue(int $id, string $Ident)` | aktuellen Statuswert lesen |

### Setzen
| Funktion | Zweck |
|---|---|
| `bool HSLT_SetPower(int $id, bool $On)` | An/Aus |
| `bool HSLT_SetBrightness(int $id, int $Percent)` | Helligkeit 0–100 % |
| `bool HSLT_SetColorTemp(int $id, int $Kelvin)` | Farbtemperatur (2700–6500 K) |
| `bool HSLT_Toggle(int $id)` | Umschalten; liefert neuen Soll-Zustand |
| `bool HSLT_SetColor(int $id, int $Rgb)` | RGB `0xRRGGBB` |
| `bool HSLT_SetArmed(int $id, bool $Armed)` | scharf/Schatten (Cutover) |

### Lesen
| Funktion | Zweck |
|---|---|
| `bool HSLT_IsOn(int $id)` | An/Aus |
| `int HSLT_GetBrightness(int $id)` | Helligkeit |
| `int HSLT_GetColorTemp(int $id)` | Farbtemperatur |
| `float HSLT_GetWatt(int $id)` | Leistung |
| `bool HSLT_IsOnline(int $id)` | Erreichbarkeit |

### Beispiele
```php
HSLT_SetPower(10013, true);
HSLT_SetBrightness(10013, 40);
HSLT_SetColorTemp(10013, 2700);   // warmweiß
HSLT_Toggle(10013);
```

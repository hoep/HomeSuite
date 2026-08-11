# HeatingZone (HSHT)

Domänen-Modul „Heizung" — eine Instanz = ein Heizkreis/Raum. Steuert das gebundene Thermostat (generischer Sollwert-Thermostat oder HomeMatic) nach Sollwert, Modus und Präsenz-Zeitplan.

## Skript-API (PHP-Befehlsreferenz)

> Alle `Set…`-Funktionen gehen intern über `RequestAction` → `applyControl` (Wert-Härtung, manualHold, Reflect, Reconcile bleiben konsistent). **Realer Effekt nur bei `Armed=true` + gebundenem Treiber**, sonst Schatten-Modus (nur Anzeige/Log). Rückgabe `bool`: `true` = validiert und dispatcht, `false` = unbekannter/nicht schaltbarer Ident oder unzulässiger Wert.

### Generisch (aus EntityModule, alle Module)
| Funktion | Beschreibung |
|---|---|
| `bool HSHT_SetControl(int $id, string $Ident, mixed $Value)` | beliebigen actionable Control setzen |
| `mixed HSHT_GetControlValue(int $id, string $Ident)` | aktuellen Statuswert lesen (null wenn keiner) |

### Setzen
| Funktion | Zweck |
|---|---|
| `bool HSHT_SetSetpoint(int $id, float $Celsius)` | Solltemperatur (5,0–30,0 °C) |
| `bool HSHT_SetMode(int $id, int $Mode)` | 0=Auto, 1=Manuell, 2=Boost, 3=Frost |
| `bool HSHT_SetModeName(int $id, string $Mode)` | Klartext: `auto`\|`manual`\|`boost`\|`frost` |
| `bool HSHT_SetPresence(int $id, int $Presence)` | Zeitplan-Variante 0=Normal, 1=Erweitert, 2=Abgesenkt |
| `bool HSHT_Boost(int $id)` | Kurzform Mode=2 |
| `bool HSHT_FrostProtect(int $id)` | Kurzform Mode=3 |
| `bool HSHT_SetArmed(int $id, bool $Armed)` | scharf/Schatten (Cutover); liefert resultierenden Zustand |

### Lesen
| Funktion | Zweck |
|---|---|
| `float HSHT_GetSetpoint(int $id)` | Sollwert |
| `float HSHT_GetActualTemp(int $id)` | Ist-Temperatur |
| `int HSHT_GetHumidity(int $id)` | Luftfeuchte |
| `int HSHT_GetMode(int $id)` | Modus |
| `int HSHT_GetPresence(int $id)` | Präsenz |
| `bool HSHT_IsOnline(int $id)` | Erreichbarkeit |
| `string HSHT_GetScheduleJson(int $id, int $Presence = -1)` | Wochenplan als JSON (Diagnose); −1 = aktive Präsenz |

Zeitplan-**Bearbeitung** läuft über `HSHT_Manage(op:"updateProfile"/"getSchedule")` bzw. den LiveViewBuilder — die Heizpläne bleiben store-/JSON-basiert.

### Beispiele
```php
HSHT_SetSetpoint(11383, 21.5);   // Wohnzimmer auf 21,5 °C
HSHT_Boost(11383);               // Boost
HSHT_SetPresence(11383, 2);      // Abgesenkt (z. B. Urlaub)
$t = HSHT_GetActualTemp(11383);  // Ist-Temperatur lesen
```

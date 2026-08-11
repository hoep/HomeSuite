# PoolController (HSPC)

Domänen-Modul „Pool" (ProCon.IP) — Dosier-Automatiken, Relais-Modi, Sollwerte, Dosierung und Gerätewartung. Konfiguration liegt in nativen Properties.

## Skript-API (PHP-Befehlsreferenz)

> Control-Setter gehen über `RequestAction`; Sollwerte/Dosierung/Wartung über `Manage`. **Realer Effekt nur bei `Armed=true`** (globales Gate).

### Generisch (aus EntityModule)
| Funktion | Beschreibung |
|---|---|
| `bool HSPC_SetControl(int $id, string $Ident, mixed $Value)` | beliebigen actionable Control setzen |
| `mixed HSPC_GetControlValue(int $id, string $Ident)` | aktuellen Statuswert lesen |
| `string HSPC_GetState(int $id)` | kompletter Live-State-Snapshot (JSON, alle Idents) |

### Setzen
| Funktion | Zweck |
|---|---|
| `bool HSPC_SetDosingRedoxAuto(int $id, bool $On)` | Cl/Redox-Dosierautomatik |
| `bool HSPC_SetDosingPHAuto(int $id, bool $On)` | pH-Dosierautomatik |
| `bool HSPC_SetCircAuto(int $id, bool $On)` | Umwälzautomatik |
| `bool HSPC_SetRelayMode(int $id, int $Index, int $Mode)` | Relais 0–7 → 0=Auto, 1=Manuell Aus, 2=Manuell Ein |
| `bool HSPC_SetArmed(int $id, bool $Armed)` | scharf/Schatten |

### Dosierung & Wartung (Manage-Fassaden)
| Funktion | Zweck |
|---|---|
| `bool HSPC_DoDosage(int $id, int $Type, int $Seconds)` | manuell dosieren (Type 0=Cl/Redox, 1=pH-, 2=pH+; 0 s = Stop) |
| `bool HSPC_SetDosageConfig(int $id, int $Type, string $ConfigJson)` | Sollwerte/Grenzen setzen (Config-Shape wie `getDosageConfig(type)` liefert) |
| `bool HSPC_ResetContainer(int $id, int $Type, float $Liters)` | Kanister-Füllstand zurücksetzen |
| `bool HSPC_SetRelayName(int $id, int $Index, string $Name)` | Relaisname |
| `bool HSPC_SendSchedule(int $id)` | Wochenplan ins Gerät schreiben |
| `bool HSPC_SetDeviceTime(int $id, int $Unix = 0)` | Geräteuhr stellen (0 = jetzt) |
| `bool HSPC_ClearErrors(int $id)` | Fehlerlog löschen |

### Lesen
| Funktion | Zweck |
|---|---|
| `float HSPC_GetTruePoolTemp(int $id)` | autoritative Wassertemperatur |

### Beispiele
```php
HSPC_SetRelayMode($id, 2, 2);       // Relais 2 manuell Ein
HSPC_DoDosage($id, 1, 15);          // 15 s pH-minus
$cfg = json_decode(HSPC_Manage($id, json_encode(['op'=>'getDosageConfig','args'=>['type'=>0]])), true);
```

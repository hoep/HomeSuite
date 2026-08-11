# HomeSuite Hub (HSH)

Zentrale Instanz (Singleton) — globale Einstellungen, Automatik-Gate, Szenen und Sonnenprofil-Rotation für alle HomeSuite-Module.

## Skript-API (PHP-Befehlsreferenz)

### Generisch (aus EntityModule)
| Funktion | Beschreibung |
|---|---|
| `bool HSH_SetControl(int $id, string $Ident, mixed $Value)` | beliebigen actionable Control setzen |
| `mixed HSH_GetControlValue(int $id, string $Ident)` | aktuellen Statuswert lesen |

### Setzen / Lesen
| Funktion | Zweck |
|---|---|
| `bool HSH_SetAutomationEnabled(int $id, bool $Enabled)` | globales Automatik-Gate (alle Module) |
| `bool HSH_GetAutomationEnabled(int $id)` | Gate lesen |
| `bool HSH_SetNorthAlignment(int $id, float $Degrees)` | Nordausrichtung; dreht alle Sonnenprofile additiv |
| `float HSH_GetNorthAlignment(int $id)` | Nordausrichtung lesen |

### Szenen & Licht-Automatik (Manage-Fassaden)
| Funktion | Zweck |
|---|---|
| `bool HSH_ApplyLightScene(int $id, string $SceneId)` | Licht-Szene anwenden |
| `string HSH_ListLightScenes(int $id)` | Szenen als JSON auflisten |
| `bool HSH_SetLightAutomationEnabled(int $id, bool $Enabled)` | Licht-Automatik an/aus **ohne** die Regeln zu verlieren |
| `string HSH_RotateSunProfiles(int $id, float $DeltaDeg)` | additive Rotation aller Sonnenprofile (Wartung) |

> Instanz-Provisionierung läuft weiterhin über die bestehende `HSH_Provision(int $id, string $requestJson)`.

### Beispiele
```php
HSH_SetAutomationEnabled($hub, false);        // alle Automatiken pausieren
HSH_SetNorthAlignment($hub, -12.6);           // Nordausrichtung korrigieren
HSH_ApplyLightScene($hub, 'abend');
```

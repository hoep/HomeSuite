# AudioZoneBridged (HSAUX)

Bridge-Variante von [AudioZone](../AudioZone/README.md): erbt die komplette Logik und überschreibt nur den Empfangspfad (Push über die DataFlow-Parent-Bridge statt Timer-Poll).

## Skript-API

Identisch zu AudioZone — **dieselben Prozeduren unter dem Prefix `HSAUX_`** (z. B. `HSAUX_Play`, `HSAUX_SetVolume`, `HSAUX_PlayDirectRadio`, `HSAUX_GetVolume`). Siehe [AudioZone/README.md](../AudioZone/README.md) für die vollständige Befehlsreferenz.

```php
HSAUX_SetVolume($id, 25);
HSAUX_Play($id);
```

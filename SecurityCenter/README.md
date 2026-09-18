# HomeSuite Waechter (HSSC)

Einbruchmeldung fuer das ganze Haus. **Eine Instanz fuer die gesamte Anlage** -
Geschosse, Bereiche und Melderrollen sind Eigenschaften dieser Instanz, keine
eigenen Entitaeten.

- **Prefix:** `HSSC`
- **GUID:** `{0E7216C3-FCCA-41FF-BE4E-E5939D9AA571}`
- **Typ:** Device (type 3), parentless, Singleton

## Warum eine einzige Instanz

Die abgeloeste Eigenbau-Loesung fuehrte je Bereich eine eigene Scharf-Variable.
Ueber die Jahre liefen sie auseinander: die Sammelvariable stand auf „scharf",
waehrend die Bereichsvariablen seit Jahren auf „unscharf" standen - ohne dass es
jemandem auffiel. **Eine Instanz kann sich nicht selbst widersprechen.**

## Keine Automatismen

Das Modul schaltet **nie von selbst** scharf oder unscharf. Es leitet nichts aus
Anwesenheit ab und schlaegt nichts vor, das sich nach einer Frist selbst
ausfuehrt. Scharfschalten ist eine Entscheidung, keine Vermutung. Konfigurierbar
sind Melderzuordnung, Verzoegerungen und Meldewege - mehr nicht.

## Controls

`Mode` (unscharf / anwesend / abwesend) sowie die Auskuenfte des Waechters:
welche Melder offen sind, was den letzten Alarm ausgeloest hat, seit wann der
Zustand gilt.

## Befehlsreferenz

```php
HSSC_SetControl($id, 'Mode', 'away');
$stand = json_decode(HSSC_GetState($id), true);
```

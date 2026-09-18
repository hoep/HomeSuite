# ClimateZone (HSAC)

Domaenen-Modul „Klima" der HomeSuite. **Eine Instanz = eine Klimazone**,
herstellerunabhaengig.

- **Prefix:** `HSAC`
- **GUID:** `{81B7257F-4B34-4024-89B3-98BC43E00E54}`
- **Typ:** Device (type 3), parentless
- **Basisklasse:** `\Hoep\HomeSuite\EntityModule`

## Treiber

Zwei Anbindungen liegen darunter und verhalten sich nach aussen gleich:

| Treiber | Anbindung |
|---|---|
| `toshiba-cloud` | Hersteller-Cloud: lesen ueber REST, schalten ueber einen Azure-IoT-Kanal (MQTT/TLS) |
| `generic-climate` | ueber die Variablen eines vorhandenen Fremdmoduls (z. B. tado) |

Der generische Treiber ist der Regelfall fuer alles, wofuer es keinen eigenen
gibt: gebunden wird an bestehende Symcon-Variablen, nicht an ein Fabrikat.

## Controls

`Power`, `Mode`, Soll- und Istwerte sowie die Zusatzfunktionen, die das jeweilige
Geraet meldet (Luefterstufe, Schwenken waagrecht/senkrecht, Beleuchtung,
Selbstreinigung, Zeitplan). Welche davon vorhanden sind, steht im Manifest -
Oberflaechen bauen ihre Bedienung daraus, statt Geraetearten einzeln zu kennen.

## Scharf, Schatten, Manuell

Wie in allen Domaenen: ein Befehl erreicht das Geraet **nur bei `Armed = true`**.
Vorher wird er lediglich vermerkt - so laesst sich eine neue Zone gefahrlos
einrichten und beobachten, bevor sie wirklich schaltet. Ein Eingriff von Hand
setzt den Manuell-Vorrang und haelt die Automatik zurueck.

## Befehlsreferenz

```php
HSAC_SetControl($id, 'Power', true);
HSAC_SetControl($id, 'Mode', 'cool');
$ist = HSAC_GetControlValue($id, 'Temperature');
$manifest = json_decode(HSAC_GetManifest($id), true);
```

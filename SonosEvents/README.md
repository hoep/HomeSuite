# HomeSuiteSonosEvents (HSSE)

Splitter-Modul: UPnP-Ereignisse von Sonos-Playern statt Abfragetakt.

- **Prefix:** `HSSE`
- **GUID:** `{5C1A7D28-9E44-4B31-8F62-2A7D0C6B4E93}`
- **Typ:** Splitter (type 2)

## Wozu

Sonos kann Zustandsaenderungen von sich aus melden (GENA): man meldet sich beim
Player fuer einen Dienst an und gibt eine Rueckruf-Adresse an; der Player
schickt daraufhin `NOTIFY`, sobald sich etwas aendert. HEOS tut das ueber seine
Steuerverbindung ohnehin - Sonos braucht dafuer einen Empfaenger, und genau den
stellt dieses Modul mit einem eigenen Server-Socket.

Der Gewinn ist nicht nur Sparsamkeit: Titel- und Lautstaerkewechsel stehen
sofort in der Oberflaeche, statt bis zur naechsten Abfrage zu warten.

## Ablauf

1. `ApplyChanges` sammelt alle `AudioZone`-Instanzen mit Treiber `sonos-upnp`,
   loest IP und RINCON auf und meldet sich je Player fuer **AVTransport**
   (Wiedergabe, Titel) und **RenderingControl** (Lautstaerke, Stumm) an.
2. Ein Timer erneuert die Anmeldungen, bevor sie ablaufen (`Renew`).
3. Eingehende `NOTIFY` werden zerlegt und an die zustaendige AudioZone gereicht.

## Befehlsreferenz

```php
HSSE_Renew($id);            // Anmeldungen sofort erneuern
$text = HSSE_Status($id);   // Klartext: wie viele Player, wie lange gueltig
```

## Hinweis

Das Modul braucht eine **vom Player erreichbare** Rueckruf-Adresse. In
getrennten Netzen (VLAN, Container mit eigener Adresse) muss der Weg vom Player
zurueck zu IP-Symcon offen sein, sonst bleiben die Meldungen aus.

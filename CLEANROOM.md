# Clean-Room-Erklaerung

HomeSuite steht unter der **MIT-Lizenz** und ist ausschliesslich Eigenentwicklung.

## Grundsatz

Bei der Umsetzung der Audio-Domaene (Sonos, HEOS, MusicCast, Denon) wird
**ausschliesslich Protokollwissen** verwendet — also oeffentlich dokumentierte
oder durch eigene Beobachtung ermittelte Fakten ueber die Draht-Protokolle:

- UPnP/SOAP-Aktionen und GENA-Events (Sonos),
- das HEOS-CLI-Telnet-Protokoll (Denon/HEOS),
- die MusicCast/YXC-HTTP-API und deren UDP-Events (Yamaha),
- SSDP/mDNS-Discovery-Muster.

## Verboten

Es wird **kein Quellcode** aus fremden Modulen (u. a. `SymconSonos`,
HEOS-/Denon-Module, MusicCast-Module) kopiert, adaptiert oder als Vorlage
zeilenweise uebernommen — **kein Snippet-Copy**. Keine Uebernahme von:

- Klassen-, Methoden- oder Funktionskoerpern,
- modulspezifischen Datenstrukturen/Property-Layouts,
- Kommentaren, Konstanten-Tabellen oder Konfigurationsformularen fremder Module.

## Was erlaubt ist

- Eigenstaendige Neuimplementierung anhand von Protokoll-Spezifikationen.
- Beobachtung des Draht-Verkehrs (z. B. Frames, Header, Payload-Schemata) zur
  Ermittlung von Protokolltatsachen.
- Verweise auf oeffentliche Protokolldokumentation.

## Verantwortung

Jeder Bau-Agent bestaetigt mit dem Erstellen von Audio-Treibern die Einhaltung
dieser Erklaerung. Die Vendor-Treiber in `libs/HomeSuite/Drivers/Audio/` sind
zustandslose Protokoll-Codecs (Frame rein / Delta raus) und enthalten
ausschliesslich eigenformulierten Code.

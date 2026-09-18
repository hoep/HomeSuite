# GardenaConfigurator (HSGX)

Zentrale Verbindung zur GARDENA-smart-system-Cloud und Configurator fuer die
Geraete-Instanzen.

- **Prefix:** `HSGX`
- **GUID:** `{7C3A1E44-2B9D-4F16-A8E1-9C0F5B7A2D31}`
- **Typ:** Configurator (type 4)

## Wozu

**Eine** Instanz haelt den Zugang (Client ID und Client Secret einer
GARDENA-Developer-Anwendung) und listet alle Geraete der Cloud in einer Tabelle.
Je Zeile legt ein Klick eine `GardenaDevice`-Instanz (HSGA) mit ihren Variablen
an. Lesen **und** Schalten laufen anschliessend ueber dieselbe Developer-API
(OAuth2, `client_credentials`).

## Einrichtung

1. Auf dem GARDENA-Entwicklerportal eine Anwendung anlegen und mit dem eigenen
   GARDENA-Konto verbinden.
2. Client ID und Client Secret in dieser Instanz eintragen.
3. **Verbindung testen** - der Knopf meldet Klartext, nicht nur „Fehler".
4. In der Tabelle je gewuenschtem Geraet eine Instanz anlegen.

Die Zugangsdaten werden in einer Datei im Symcon-Skriptverzeichnis abgelegt, auf
die nur der Dienstbenutzer Zugriff hat; der HAL-Treiber liest sie von dort. So
teilen sich Configurator und alle Geraete-Instanzen **einen** Zugang, statt ihn
mehrfach zu halten.

## Befehlsreferenz

```php
HSGX_TestConnection($id);   // Verbindung pruefen, Ergebnis als Meldung
```

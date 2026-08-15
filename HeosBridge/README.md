# HeosBridge (HSBH)

Splitter-Modul für Denon/Marantz **HEOS** über das HEOS-CLI-Protokoll (TCP-Port **1255**).

Die Bridge hält **eine** persistente HEOS-Verbindung zu einem beliebigen HEOS-Gerät im Netz und verteilt die eingehenden Event-/Antwort-Frames an die darunterhängenden Audio-Zonen. Kommandos der Zonen werden gebündelt über dieselbe Socket-Verbindung an HEOS weitergereicht. Damit bleibt der eigentliche Treiber (`Heos.php` in [AudioZoneBridged](../AudioZoneBridged/README.md)) zustandslos — Socket, Session, Handshake und Reconnect liegen zentral hier.

## Architektur / Datenfluss

```
ClientSocket (Symcon-I/O)         HEOS-Gerät :1255
        │  (persistente TCP-Verbindung)
        ▼
  ┌───────────────┐   ReceiveData / SendDataToParent
  │  HeosBridge   │  (Splitter, Typ 2)
  │    (HSBH)     │
  └───────────────┘
        │  SendDataToChildren  ▲ ForwardData
        ▼  (downstream)        │ (upstream, Kind → SendDataToParent)
  ┌───────────────┐  ┌───────────────┐  …
  │ AudioZoneBr.  │  │ AudioZoneBr.  │
  │   (HSAUX)     │  │   (HSAUX)     │
  │  Player pid1  │  │  Player pid2  │
  └───────────────┘  └───────────────┘
```

- **Modultyp:** Splitter (`type: 2`), Prefix `HSBH`, Aliase `HeosBridge`, `HSBH`, `HomeSuite HEOS Bridge`.
- **Parent (Requirement):** ClientSocket-Modul `{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}`. Wird über `RequireParent()` automatisch angelegt und mit Host/Port konfiguriert.
- **Kinder (implementiert):** downstream-DataID `{D7E6F5C4-B3A2-4190-8E7D-6C5B4A392817}` — daran hängen die `AudioZoneBridged`-Instanzen (je HEOS-Player eine).
- **Parent-Senden:** über I/O-DataID `{79827379-F36E-4ADA-8A95-5F8313DAE8DB}`.

### Frame-Verarbeitung

HEOS liefert zeilenweise (`\r\n`) UTF-8-JSON. Die Bridge puffert die Rohbytes im Attribut `RxBuffer`, framt zeilenweise, gibt den unvollständigen Rest zurück in den Puffer und verteilt jeden vollständigen JSON-Frame **unverändert** an alle Kinder. Frames vom Typ `player/get_players` werden zusätzlich abgegriffen, um die interne Player-Liste zu pflegen.

## HEOS-Handshake & Keepalive

Sobald der ClientSocket-Parent `IS_ACTIVE` meldet (`MessageSink`/`IM_CHANGESTATUS`) bzw. beim Kernel-Runlevel `KR_READY`, sendet die Bridge einmalig:

```
heos://system/register_for_change_events?enable=on
heos://player/get_players
```

Danach läuft der Timer **`HeosKeepalive`** mit **30 s**, der einen leichtgewichtigen Ping `heos://system/heart_beat` sendet, damit das HEOS-Gerät die Idle-Verbindung nicht kappt.

## Konfiguration (Properties + Formular)

| Property | Typ | Default | Bedeutung |
|---|---|---|---|
| `Host` | String | `''` | IP-Adresse eines beliebigen HEOS-Geräts (Einstiegspunkt in das HEOS-Netz) |
| `Port` | Integer | `1255` | HEOS-CLI-Port |

Der ClientSocket-Parent wird aus diesen Werten konfiguriert: `Host`, `Port` und `Open = (Host !== '')`; bei Änderungen erfolgt automatisch `IPS_ApplyChanges` am Parent.

### Konfigurationsformular (`GetConfigurationForm`)

- **Elements:** Hinweistext, `Host` (ValidationTextBox), `Port` (NumberSpinner).
- **Actions:**
  - Button **„Player abfragen"** → `echo HSBH_GetPlayers($id);`
  - Liste **„Gefundene Player"** (`PlayersList`) mit Spalten **PID / Name / Modell / IP**, gefüllt aus der zuletzt bekannten Player-Liste.

## Attribute (interner Zustand)

| Attribut | Zweck |
|---|---|
| `RxBuffer` (String) | Empfangspuffer für unvollständige Zeilen-Frames |
| `Players` (String, JSON) | zuletzt bekannte Player `[{pid,name,model,ip}]` aus `player/get_players` |

Es werden **keine** Status-Variablen im Objektbaum angelegt — die Bridge ist reiner Transport; sichtbare Zustände (Lautstärke, Titel etc.) liegen in den `AudioZoneBridged`-Kindern.

## Öffentliche Skript-/RPC-Funktionen

| Funktion | Rückgabe | Zweck |
|---|---|---|
| `string HSBH_GetPlayers(int $id)` | JSON `[{pid,name,model,ip}]` | fragt `heos://player/get_players` frisch an und liefert den zuletzt bekannten Player-Stand zurück (auch fürs Formular) |
| `void HSBH_Keepalive(int $id)` | – | sendet manuell einen `heart_beat`-Ping (wird i. d. R. vom Timer aufgerufen) |

```php
// Alle Player am HEOS-Netz auflisten
echo HSBH_GetPlayers($bridgeId);
```

## Besondere Hinweise

- **Eine Verbindung, viele Player:** HEOS bildet ein Geräte-Netz. Es genügt die IP **eines** Geräts als Einstieg; `get_players` liefert alle im Netz erreichbaren Player. Für jeden Player wird eine `AudioZoneBridged`-Instanz als Kind angelegt und filtert die für ihre `pid` relevanten Frames.
- **Idle-Trennung:** HEOS-Geräte trennen inaktive CLI-Verbindungen — deshalb der feste 30-s-Keepalive. Ohne ihn brechen Events nach kurzer Zeit ab.
- **Zustandsloser Treiber:** Session/Reconnect gehören ausschließlich hierher. Der Kind-Treiber `Heos.php` darf keinen eigenen Socket-Zustand halten.
- **Rohdurchreichung:** Frames werden unverändert an alle Kinder gebroadcastet; die Adressierung nach `pid` erfolgt in den Kindern, nicht in der Bridge.

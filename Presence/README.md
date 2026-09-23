# Presence (HSPR)

Anwesenheit und Belegung eines Standorts. Eine Instanz je Standort, eingehaengt unter die
`HomeSuite Bereich`-Instanz mit der Ebene *Haus/Wohnung*.

## Was die Instanz beantwortet

- Welche Bewohner sind da? Je Person eine Variable `<Name> da`.
- Sind Gaeste da? Fremde Telefone und Uhren im Hauptnetz plus die Geraete im Gaeste-WLAN.
- Belegung (`HSPR.Belegung`): 0 Leer, 1 Bewohner, 2 Gaeste, 3 Bewohner und Gaeste, 4 Unbekannt.

## Quelle

Die Host-Liste des Routers: je Geraet eine boolesche Online-Variable, deren Ident die MAC
ohne Trenner ist (so legt sie das FritzBox-Projekt fuer Symcon an). Jede Person darf eine
weitere, fertige Anwesenheitsvariable mitbringen (z. B. aus einem WLAN-Controller); beide
Quellen werden ODER-verknuepft.

## Warum Namen UND MAC-Adressen

Telefone verwenden je WLAN eine eigene private Adresse. Die MAC, unter der ein Telefon
zuhause bekannt ist, taucht an einem zweiten Standort nicht auf. Personen werden deshalb an
einer MAC-Liste und an Geraetenamen erkannt (`*` als Platzhalter erlaubt).

## Verhalten

- **Haltezeit** (Standard 20 min): ein Telefon im Ruhezustand verlaesst das WLAN fuer
  Minuten; die Person bleibt so lange anwesend.
- **Stummer Router**: meldet der Router laenger als `StaleMinutes` nichts, bleibt der
  letzte Stand stehen und die Belegung wird *Unbekannt* statt *Leer*.

## PHP-Befehle

| Befehl | Rueckgabe |
|---|---|
| `HSPR_Update(int $id)` | wertet sofort aus |
| `HSPR_IsPresent(int $id, string $Person)` | `bool` |
| `HSPR_GetOccupancy(int $id)` | `int` (siehe Belegung) |
| `HSPR_ListCandidates(int $id)` | JSON: Telefone/Uhren im Router mit Online-Stand, zum Einrichten |

Die Entscheidung steckt in `libs/HomeSuite/Engines/PresenceEngine.php` und ist ohne Symcon
testbar.

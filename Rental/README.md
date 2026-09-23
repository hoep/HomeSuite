# Rental (HSRT)

Belegung und Umsatz einer Ferienwohnung, die keinen Buchungskalender hat. Eine Instanz je
vermietetem Standort, eingehaengt unter die `HomeSuite Bereich`-Instanz des Standorts.

## Idee

Die Wohnung verraet ihre Belegung selbst:

- fremde Geraete im Gaestenetz (aus der Anwesenheit `Presence`/HSPR des Standorts),
- optional weitere Aktivitaet, z. B. ein Zaehler der Schaltvorgaenge der Klimaanlagen.

Je Tag entsteht aus den Tagesmitteln ein Wert. Mit drei Schwellen wird daraus der
Tagesstatus: frei, An-/Abreise (Wechseltag) oder belegt. Zwei Wechseltage in Folge ohne
sichtbare Belegung gelten als Beginn einer (vermuteten) Wochenbuchung.

## Rechnung

- Belegungen werden zu Wochen: 1-8 Tage = 1 Woche, 9-15 = 2, 16-22 = 3 ...
- Freie Zeitraeume ab 7 Tagen zaehlen als buchbare Wochen.
- Der Preis einer Woche richtet sich nach ihrem mittleren Tag (Wochenpreis-Liste).
- Umsatz brutto, nach Provision, netto (nach Steuer), Potenzial der freien Wochen,
  Maximum bei voller Saison.

## Variablen

`Heute` (HSRT.Status: 0 ausser Saison, 1 frei, 2 An-/Abreise, 3 belegt), `Tageswert heute`,
`Auslastung Saison`, Wochen (belegt/frei/moeglich), Umsatzwerte, `Register (JSON)` mit allen
Tageswerten, Buchungen und freien Wochen (fuer eine Kalender-/Heatmap-Anzeige).

`Gaeste-Geraete (jetzt)` wird minuetlich aus der Anwesenheit uebernommen und archiviert; das
Tagesmittel kommt aus dem Archiv. Eine aeltere archivierte Zaehlvariable kann als
Vorgeschichte angegeben werden.

## PHP-Befehle

| Befehl | Rueckgabe |
|---|---|
| `HSRT_Update(int $id)` | rechnet sofort neu |
| `HSRT_GetTodayStatus(int $id)` | `int` (siehe oben) |
| `HSRT_GetRegister(int $id)` | JSON |

Die Rechnung steckt in `libs/HomeSuite/Engines/OccupancyEngine.php`, ohne Symcon testbar.

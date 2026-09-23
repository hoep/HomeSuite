<?php

declare(strict_types=1);

namespace Hoep\HomeSuite\Engines;

/**
 * EnergySim — was kostet der Strom, und was braechte es, ihn anders zu verbrauchen.
 *
 * Reine Rechenmaschine: sie kennt weder Symcon noch das Archiv. Wer sie fuettert, liefert
 * eine Stundenreihe; sie liefert Zonen, Kosten und Vergleiche zurueck. Das ist Absicht -
 * so laesst sich dieselbe Rechnung mit gemessenen Daten, mit Hochrechnungen oder mit
 * erfundenen Reihen pruefen, ohne dass irgendwo eine Nebenwirkung entsteht.
 *
 * WARUM Zonen und nicht nur ein Preis: ein Zeitzonentarif berechnet dieselbe Kilowattstunde
 * je nach Uhrzeit verschieden - bei Ökostrom Smart zwischen 5,00 und 17,04 ct. Erst diese
 * Spanne macht Lastverschiebung ueberhaupt zu einer wirtschaftlichen Frage. Bei einem
 * Festtarif bleibt sie eine Illusion, und die Simulation zeigt das offen, statt es zu
 * verschweigen.
 *
 * WARUM nur die Energie verglichen wird: das Netzentgelt ist je Netzgebiet gleich, egal bei
 * wem man kauft. Es in den Anbietervergleich zu mischen, verkleinert die Unterschiede
 * kuenstlich und laesst jeden Wechsel harmloser aussehen, als er ist.
 */
final class EnergySim
{
    /** Vorgabe-Zonen: der Zeitzonentarif der Energie AG (Ökostrom Smart). */
    public const ZONEN_SMART = [
        ['name' => 'Sun',     'monate' => [4, 5, 6, 7, 8], 'stunden' => [12, 16], 'tage' => 'alle'],
        ['name' => 'Night',   'stunden' => [22, 6],  'tage' => 'werktag'],
        ['name' => 'Weekend', 'tage' => 'wochenende'],
        ['name' => 'Day',     'stunden' => [6, 22],  'tage' => 'werktag'],
    ];

    private function __construct()
    {
    }

    /**
     * Eine Stunde einer Zone zuordnen. Die ERSTE passende gewinnt.
     *
     * Die Reihenfolge ist Teil der Tarifdefinition, nicht Zufall: das Sonnenfenster liegt
     * mitten in der Tageszone und muss deshalb vorher geprueft werden, sonst verschwindet
     * der guenstigste Preis des Tarifs stillschweigend im teuersten.
     */
    public static function zoneVon(int $ts, array $zonen): string
    {
        $monat = (int) date('n', $ts);
        $wtag  = (int) date('N', $ts);   // 1=Mo .. 7=So
        $std   = (int) date('G', $ts);
        foreach ($zonen as $z) {
            if (isset($z['monate']) && !in_array($monat, (array) $z['monate'], true)) {
                continue;
            }
            $tage = (string) ($z['tage'] ?? 'alle');
            if ($tage === 'werktag'    && $wtag >= 6) { continue; }
            if ($tage === 'wochenende' && $wtag <  6) { continue; }
            if (isset($z['stunden'])) {
                [$a, $b] = array_map('intval', $z['stunden']);
                // Ueber Mitternacht hinweg (22..6) ist die Pruefung umgekehrt.
                $drin = ($a <= $b) ? ($std >= $a && $std < $b) : ($std >= $a || $std < $b);
                if (!$drin) { continue; }
            }
            return (string) $z['name'];
        }
        return 'Rest';
    }

    /**
     * Stundenreihe in Zonen-Kilowattstunden verwandeln.
     *
     * @param array $stunden Liste aus ['ts' => Unixzeit, 'kwh' => float]
     * @return array{zonen:array<string,float>, gesamt:float, stunden:int, von:int, bis:int}
     */
    public static function zonen(array $stunden, array $zonenDef): array
    {
        $z = ['Rest' => 0.0];
        foreach ($zonenDef as $d) { $z[(string) $d['name']] = 0.0; }
        $gesamt = 0.0; $n = 0; $von = PHP_INT_MAX; $bis = 0;
        foreach ($stunden as $h) {
            $kwh = (float) ($h['kwh'] ?? 0);
            $ts  = (int) ($h['ts'] ?? 0);
            if ($ts <= 0) { continue; }
            $n++;
            $von = min($von, $ts); $bis = max($bis, $ts);
            if ($kwh <= 0) { continue; }
            $z[self::zoneVon($ts, $zonenDef)] += $kwh;
            $gesamt += $kwh;
        }
        if ($z['Rest'] <= 0.0) { unset($z['Rest']); }
        return ['zonen' => $z, 'gesamt' => $gesamt, 'stunden' => $n,
                'von' => ($von === PHP_INT_MAX ? 0 : $von), 'bis' => $bis];
    }

    /**
     * Jahreskosten eines Tarifs fuer eine Zonenverteilung.
     *
     * @param array $tarif ['name', 'grundEur' (je Monat), und entweder 'ct' (fest) oder
     *                     'zonen' => [Zonenname => ct]]
     * @param float $faktor Hochrechnung auf ein Jahr (1.0 = die Reihe deckt genau ein Jahr)
     */
    public static function kosten(array $zonen, array $tarif, float $faktor = 1.0): array
    {
        $grund = ((float) ($tarif['grundEur'] ?? 0)) * 12.0;
        // Kombi-/Treuebonus als Prozentsatz auf den ARBEITSpreis. Der Grundpreis
        // bleibt unberuehrt - wo ein Bonus dort ansetzt, steht er direkt im
        // 'grundEur' des Tarifs.
        $rab = 1.0 - max(0.0, min(50.0, (float) ($tarif['rabattPct'] ?? 0))) / 100.0;
        // Treuebonus als GRATISTAGE: an so vielen Tagen im Jahr entfaellt der
        // Arbeitspreis. 30 Tage sind 8,2 Prozent des Jahres - und wirken NUR auf
        // die Arbeit, nicht auf den Grundpreis, der weiterlaeuft.
        $rab *= 1.0 - max(0.0, min(365.0, (float) ($tarif['gratisTage'] ?? 0))) / 365.0;
        $arbeit = 0.0; $offen = [];
        foreach ($zonen as $name => $kwh) {
            if (isset($tarif['zonen'][$name])) {
                $arbeit += $kwh * ((float) $tarif['zonen'][$name]) / 100.0;
            } elseif (isset($tarif['ct'])) {
                $arbeit += $kwh * ((float) $tarif['ct']) / 100.0;
            } else {
                // Eine Zone ohne Preis waere eine stille Untertreibung der Kosten.
                $offen[] = $name;
            }
        }
        return ['name' => (string) ($tarif['name'] ?? '?'),
                'arbeit' => $arbeit * $rab * $faktor, 'grund' => $grund,
                'gesamt' => $arbeit * $rab * $faktor + $grund,
                'unvollstaendig' => $offen];
    }

    /**
     * Jahreskosten eines BOERSENTARIFS.
     *
     * Ein Spot-Tarif hat keinen Preis, sondern eine Preisreihe - und was er kostet, haengt
     * davon ab, WANN verbraucht wird. Deshalb wird er nicht gegen einen Mittelwert gerechnet,
     * sondern Stunde fuer Stunde gegen den eigenen Verbrauch. Der Unterschied ist kein
     * Detail: wer viel verbraucht, wenn der Markt teuer ist, zahlt deutlich ueber dem
     * Jahresmittel, und ein Vergleich gegen den Mittelwert wuerde ihm einen Tarif empfehlen,
     * der fuer ihn nie guenstig war.
     *
     * @param array $stunden ['ts'=>int,'kwh'=>float]
     * @param array $spot    Unixzeit der Stunde => Boersenpreis in ct/kWh NETTO
     * @param array $tarif   ['aufschlagCt' => Aufschlag brutto, 'grundEur', 'ustProzent']
     */
    public static function kostenSpot(array $stunden, array $tarif, array $spot, float $faktor = 1.0): array
    {
        $auf  = (float) ($tarif['aufschlagCt'] ?? 0);
        $rab  = 1.0 - max(0.0, min(50.0, (float) ($tarif['rabattPct'] ?? 0))) / 100.0;
        $rab *= 1.0 - max(0.0, min(365.0, (float) ($tarif['gratisTage'] ?? 0))) / 365.0;
        $ust  = 1.0 + ((float) ($tarif['ustProzent'] ?? 20)) / 100.0;
        $arbeit = 0.0; $gedeckt = 0.0; $offen = 0.0;
        foreach ($stunden as $h) {
            $kwh = (float) ($h['kwh'] ?? 0);
            if ($kwh <= 0) { continue; }
            $ts = (int) ($h['ts'] ?? 0);
            if (!isset($spot[$ts])) { $offen += $kwh; continue; }
            $gedeckt += $kwh;
            $arbeit += $kwh * (((float) $spot[$ts]) * $ust + $auf) / 100.0;
        }
        // Stunden ohne Boersenpreis werden zum Schnitt der gedeckten bewertet - und gezaehlt,
        // damit niemand eine Luecke fuer einen guenstigen Tarif haelt.
        if ($offen > 0 && $gedeckt > 0) { $arbeit += $offen * ($arbeit / $gedeckt); }
        $grund = ((float) ($tarif['grundEur'] ?? 0)) * 12.0;
        return ['name' => (string) ($tarif['name'] ?? '?'),
                'arbeit' => $arbeit * $rab * $faktor, 'grund' => $grund,
                'gesamt' => $arbeit * $rab * $faktor + $grund,
                'unvollstaendig' => [],
                'ungedeckt_kwh' => round($offen, 0),
                'mittelpreis_ct' => $gedeckt > 0 ? round(100 * $arbeit / $gedeckt, 2) : null];
    }

    /**
     * Alle Tarife vergleichen, feste wie boersenabhaengige.
     *
     * Der Einstieg fuer Aufrufer: er entscheidet je Tarif, welche Rechnung passt, und
     * sortiert am Ende einheitlich.
     */
    public static function vergleichAlle(array $stunden, array $zonen, array $tarife,
                                         array $spot, float $faktor = 1.0, string $istId = ''): array
    {
        $out = [];
        foreach ($tarife as $t) {
            $istSpot = (($t['typ'] ?? '') === 'spot') || isset($t['aufschlagCt']);
            if ($istSpot && $spot === []) {
                // Ohne Preisreihe waere jede Zahl erfunden - der Tarif faellt sichtbar aus.
                $out[] = ['id' => (string) ($t['id'] ?? ''), 'name' => (string) ($t['name'] ?? '?'),
                          'gesamt' => null, 'arbeit' => null, 'grund' => null,
                          'fehlt' => 'keine Boersenpreisreihe', 'unvollstaendig' => []];
                continue;
            }
            // Ein Tarif darf EIGENE Zonengrenzen mitbringen. Bis 22.09.2026 galt eine
            // Definition fuer alle - damit liess sich "Oekostrom Smart" (12-16 Uhr)
            // und "Oekostrom Smart Loyal" (10-16 Uhr, Sommer/Winter getrennt) nicht
            // gleichzeitig rechnen. Fehlt 'zonenDef', bleibt alles wie bisher.
            // zonen() liefert eine STRUKTUR (zonen/gesamt/stunden/von/bis), nicht die
            // Zonenkarte selbst - wer sie ungeoeffnet weiterreicht, uebergibt kosten()
            // die Schluessel 'gesamt' und 'stunden' als waeren es Zonennamen. Dann
            // findet der Tarif keine seiner Zonen wieder und berechnet nur den
            // Grundpreis: 1.351 statt 3.261 Euro.
            $eigene = (isset($t['zonenDef']) && is_array($t['zonenDef']) && $t['zonenDef'] !== [])
                    ? (self::zonen($stunden, $t['zonenDef'])['zonen'] ?? $zonen)
                    : $zonen;
            $k = $istSpot ? self::kostenSpot($stunden, $t, $spot, $faktor)
                          : self::kosten($eigene, $t, $faktor);
            $k['id']   = (string) ($t['id'] ?? '');
            $k['spot'] = $istSpot;
            foreach (['stand', 'quelle', 'hinweis', 'bindung'] as $f) {
                if (isset($t[$f])) { $k[$f] = $t[$f]; }
            }
            $out[] = $k;
        }
        $ist = null;
        foreach ($out as $k) { if ($k['id'] === $istId && $k['gesamt'] !== null) { $ist = $k['gesamt']; } }
        foreach ($out as &$k) {
            $k['differenz'] = ($ist === null || $k['gesamt'] === null) ? null : round($k['gesamt'] - $ist, 2);
        }
        unset($k);
        usort($out, static function ($a, $b) {
            if ($a['gesamt'] === null) { return 1; }
            if ($b['gesamt'] === null) { return -1; }
            return $a['gesamt'] <=> $b['gesamt'];
        });
        return $out;
    }

    /**
     * Wie alt sind die Tarifangaben?
     *
     * Preisblaetter aendern sich laufend. Ein Vergleich, der das verschweigt, ist nach ein
     * paar Monaten kein Vergleich mehr, sondern eine Behauptung.
     */
    public static function alter(array $tarife, int $jetzt, int $warnTage = 90): array
    {
        $alt = []; $ohne = [];
        foreach ($tarife as $t) {
            $n = (string) ($t['name'] ?? '?');
            $st = (string) ($t['stand'] ?? '');
            if ($st === '') { $ohne[] = $n; continue; }
            $ts = strtotime($st);
            if ($ts === false) { $ohne[] = $n; continue; }
            $tage = (int) floor(($jetzt - $ts) / 86400);
            if ($tage > $warnTage) { $alt[] = ['name' => $n, 'stand' => $st, 'tage' => $tage]; }
        }
        return ['veraltet' => $alt, 'ohne_stand' => $ohne, 'schwelle_tage' => $warnTage];
    }

    /** Mehrere Tarife vergleichen, guenstigster zuerst, mit Abstand zum Ist-Tarif. */
    public static function vergleich(array $zonen, array $tarife, float $faktor = 1.0, string $istId = ''): array
    {
        $out = [];
        foreach ($tarife as $t) {
            $k = self::kosten($zonen, $t, $faktor);
            $k['id'] = (string) ($t['id'] ?? '');
            $out[] = $k;
        }
        $ist = null;
        foreach ($out as $k) { if ($k['id'] === $istId) { $ist = $k['gesamt']; } }
        foreach ($out as &$k) { $k['differenz'] = ($ist === null) ? null : round($k['gesamt'] - $ist, 2); }
        unset($k);
        usort($out, static fn($a, $b) => $a['gesamt'] <=> $b['gesamt']);
        return $out;
    }

    /**
     * Was braechte es, Verbrauch von einer Zone in eine andere zu verschieben?
     *
     * Beantwortet die Frage, die hinter jeder Lastverschiebung steht: lohnt sie sich
     * ueberhaupt, und ab wieviel. Bei einem Festtarif kommt hier null heraus - und das ist
     * die ehrlichste Antwort, die eine Simulation geben kann.
     *
     * @param float $kwh Menge, die jaehrlich umzieht
     */
    public static function verschiebung(array $zonen, array $tarif, string $vonZone, string $nachZone,
                                        float $kwh, float $faktor = 1.0): array
    {
        $vorher = self::kosten($zonen, $tarif, $faktor);
        $moeglich = min($kwh, (float) ($zonen[$vonZone] ?? 0) * $faktor);
        $neu = $zonen;
        // In der Reihe steht die ungerechnete Menge - deshalb durch den Faktor zurueck.
        $neu[$vonZone]  = max(0.0, ($neu[$vonZone] ?? 0) - $moeglich / max(0.0001, $faktor));
        $neu[$nachZone] = ($neu[$nachZone] ?? 0) + $moeglich / max(0.0001, $faktor);
        $nachher = self::kosten($neu, $tarif, $faktor);
        return [
            'tarif'      => $vorher['name'],
            'von'        => $vonZone, 'nach' => $nachZone,
            'gewuenscht' => round($kwh, 1),
            'moeglich'   => round($moeglich, 1),
            'vorher'     => round($vorher['gesamt'], 2),
            'nachher'    => round($nachher['gesamt'], 2),
            'ersparnis'  => round($vorher['gesamt'] - $nachher['gesamt'], 2),
            'je_kwh_ct'  => $moeglich > 0 ? round(100 * ($vorher['gesamt'] - $nachher['gesamt']) / $moeglich, 2) : 0.0,
        ];
    }
}

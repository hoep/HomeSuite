<?php

declare(strict_types=1);

namespace Hoep\HomeSuite\Drivers\Pool;

/**
 * Uebersetzt zwischen den TIMEC-Zeitregeln des ProCon.IP und einem Symcon-Wochenplan —
 * in BEIDE Richtungen und ohne Wissen ueber Symcon oder HTTP (reine Rechenlogik, testbar).
 *
 * Warum es diese Klasse gibt
 * --------------------------
 * Die beiden Darstellungen sind nicht deckungsgleich, und die frueheren Einbahn-Umrechnungen
 * im Modul haben das verschluckt:
 *
 * 1. Eine TIMEC-Regel traegt ihre TAGESMASKE SELBST (Bit0=Mo .. Bit6=So; 127 = Mo-So).
 *    Ein Wochenplan, der alle sieben Tage gleich schaltet, ist also EINE Regel — nicht sieben.
 *    Symcon legt im Wochenplan-Editor dagegen pro Tag eine eigene Gruppe an. Ohne Verschmelzen
 *    identischer Tage belegte ein ganz normaler Plan sieben Regeln.
 * 2. Eine Regel fasst maximal VIER Fenster. Ein Tag mit mehr Fenstern ist nicht unmoeglich,
 *    er braucht nur eine zweite Regel mit derselben Tagesmaske.
 * 3. Regeln gehoeren einem RELAIS, nicht einem Index. Wer feste Indizes fuer sich reserviert,
 *    ueberschreibt frueher oder later eine fremde Regel. Diese Klasse fasst ausschliesslich
 *    Regeln an, die auf das uebergebene Relais zeigen, und belegt sonst nur freie (ENA=0) Plaetze.
 *
 * Fenster sind ueberall Minuten seit Mitternacht als [start, ende] mit ende > start.
 * Tagesmasken sind nach aussen IMMER im Symcon-Schema (Bit0=Mo .. Bit6=So); die Umrechnung
 * auf das Controller-Schema passiert nur hier drin.
 */
class TimecSchedule
{
    public const RULE_LEN     = 15;  // ENA,RELAIS,DAY + 4x (ENA,Start,Ende)
    public const RULE_COUNT   = 16;  // RULE0..RULE15
    public const WIN_PER_RULE = 4;

    /**
     * TAGESMASKE: Controller und Symcon zaehlen GLEICH — Bit0=Mo .. Bit6=So, 127 = Mo-So.
     *
     * Belegt aus der Original-Oberflaeche (gui/phase2/timectrl.htm), die das Geraet selbst
     * ausliefert:
     *   Z. 354  write('<label ...>'+WochentageMoSo+'</label>')  // lang.js: "Wochentage (Mo-So)"
     *   Z. 355  for (k=0;k<7;k++) write('<input ... id="W'+i+k+'">')
     *   Z. 249  DAY_OFFS |= checked ? 0x1<<k : 0                // Kaestchen k -> Bit k
     * Das k-te Kaestchen der mit "Mo-So" beschrifteten Reihe ist also Bit k.
     *
     * ACHTUNG: In PROTOCOLS.md stand frueher "Bit0=So", und das Modul rechnete danach um.
     * Jede geschriebene Regel war dadurch um einen Wochentag verschoben; bei 127 (alle Tage)
     * faellt das nicht auf, weshalb es lange unbemerkt blieb. Die beiden Funktionen bleiben
     * als benannte Grenze zwischen den Welten bestehen - falls sich die Annahme je wieder
     * aendert, ist genau hier der eine Ort dafuer.
     */
    public static function ctrlToSymconDays(int $ctrl): int
    {
        return $ctrl & 0x7F;
    }

    /** Symcon-Maske -> Controller-Maske; identisch, siehe ctrlToSymconDays(). */
    public static function symconToCtrlDays(int $sym): int
    {
        return $sym & 0x7F;
    }

    /**
     * GERAET -> PLAN. Liest alle aktiven Regeln des Relais und liefert Tagesgruppen
     * [['days' => symconMaske, 'windows' => [[start, ende], ...]], ...].
     *
     * Mehrere Regeln auf demselben Relais werden als VEREINIGUNG gelesen (jedes Fenster
     * schaltet ein) - das ist die Lesart einer Zeitschaltuhr und die einzige, die sich mit
     * einem Wochenplan darstellen laesst. Ueberlappende und aneinandergrenzende Fenster
     * werden dabei zusammengezogen, weil ein Wochenplan sie nicht unterscheiden kann:
     * seine Punkte sind Umschaltzeitpunkte, und zwei Ein-Fenster, die sich in derselben
     * Minute beruehren, waeren derselbe Punkt.
     */
    public static function rulesToGroups(array $rules, int $relay): array
    {
        $perDay = array_fill(0, 7, []);
        foreach ($rules as $rule) {
            if (!is_array($rule) || count($rule) < self::RULE_LEN) {
                continue;
            }
            if ((int) $rule[0] !== 1 || (int) $rule[1] !== $relay) {
                continue;   // aus oder fremdes Relais
            }
            $days = self::ctrlToSymconDays((int) $rule[2]);
            $wins = [];
            for ($k = 0; $k < self::WIN_PER_RULE; $k++) {
                $o = 3 + $k * 3;
                if ((int) $rule[$o] !== 1) {
                    continue;
                }
                $s = (int) $rule[$o + 1];
                $e = (int) $rule[$o + 2];
                if ($e > $s) {
                    $wins[] = [$s, $e];
                }
            }
            if (!$wins) {
                continue;
            }
            for ($d = 0; $d < 7; $d++) {
                if ($days & (1 << $d)) {
                    $perDay[$d] = array_merge($perDay[$d], $wins);
                }
            }
        }

        $sig = [];
        for ($d = 0; $d < 7; $d++) {
            $perDay[$d] = self::normalizeWindows($perDay[$d]);
            $sig[$d]    = self::signature($perDay[$d]);
        }

        // Tage mit identischem Fensterbild zu einer Gruppe zusammenfassen.
        $out = [];
        for ($d = 0; $d < 7; $d++) {
            if ($sig[$d] === '') {
                continue;          // Tag ohne Fenster -> keine Gruppe
            }
            if (isset($out[$sig[$d]])) {
                $out[$sig[$d]]['days'] |= 1 << $d;
                continue;
            }
            $out[$sig[$d]] = ['days' => 1 << $d, 'windows' => $perDay[$d]];
        }
        $out = array_values($out);
        usort($out, static fn($a, $b) => $a['days'] <=> $b['days']);
        return $out;
    }

    /**
     * PLAN -> GERAET. Setzt die Tagesgruppen in die 16 Regeln ein, ohne fremde Regeln
     * anzufassen, und liefert den vollstaendigen neuen Regelsatz zurueck.
     *
     * $current ist der frisch gelesene Ist-Stand (Lesen-Aendern-Zurueckschreiben).
     * $warn nimmt Klartext-Hinweise auf, wenn der Plan nicht vollstaendig abbildbar war.
     */
    public static function groupsToRules(array $groups, int $relay, array $current, array &$warn = []): array
    {
        $rules = self::normalizeRules($current);
        $need  = [];

        foreach (self::mergeIdenticalDays($groups) as $g) {
            $wins = self::normalizeWindows($g['windows'] ?? []);
            $days = (int) ($g['days'] ?? 0);
            if (!$wins || !$days) {
                continue;
            }
            // Mehr als vier Fenster an einem Tag passen nicht in EINE Regel - dafuer gibt es
            // eine zweite Regel mit derselben Tagesmaske, statt den Rest stillschweigend
            // abzuschneiden (frueheres Verhalten: Plan sichtbar, Geraet fuhr ihn nie).
            foreach (array_chunk($wins, self::WIN_PER_RULE) as $chunk) {
                $need[] = self::buildRule($relay, $days, $chunk);
            }
        }

        // Besitzverhaeltnisse: unsere aktiven Regeln zuerst wiederverwenden (stabile Indizes),
        // danach freie Plaetze. Fremde aktive Regeln bleiben unangetastet.
        $ours = $free = [];
        for ($i = 0; $i < self::RULE_COUNT; $i++) {
            $r = $rules[$i];
            if ((int) $r[0] === 1 && (int) $r[1] === $relay) {
                $ours[] = $i;
            } elseif ((int) $r[0] !== 1) {
                $free[] = $i;
            }
        }
        $slots = array_merge($ours, $free);

        $used = 0;
        foreach ($need as $rule) {
            if (!isset($slots[$used])) {
                break;
            }
            $rules[$slots[$used]] = $rule;
            $used++;
        }
        if ($used < count($need)) {
            $warn[] = sprintf('Der Zeitplan braucht %d Regeln, am Controller sind nur %d Plaetze frei oder eigen - %d Regel(n) werden nicht gefahren.',
                count($need), $used, count($need) - $used);
        }
        // Eigene Regeln, die jetzt nicht mehr gebraucht werden, freigeben. NUR eigene.
        foreach (array_slice($ours, $used) as $idx) {
            $rules[$idx] = array_fill(0, self::RULE_LEN, 0);
        }
        return $rules;
    }

    /**
     * Fenster -> Schaltpunkte eines Symcon-Wochenplans: [['min','h','m','a'], ...].
     *
     * Symcon beschreibt den Tag nicht mit Fenstern, sondern mit UMSCHALTPUNKTEN, und verlangt
     * einen Punkt um 00:00 - sonst waere der Tagesanfang undefiniert. Ein Fenster wird also zu
     * "Ein" an seinem Beginn und "Aus" an seinem Ende; beginnt der Tag nicht mit einem Fenster,
     * kommt ein "Aus" um 00:00 davor.
     *
     * Ein Fenster bis 23:59 (1439) erzeugt bewusst KEINEN Aus-Punkt: der Tag ist ohnehin zu Ende,
     * und ein Punkt um 23:59 waere nur eine Minute lang gueltig.
     */
    public static function windowsToPoints(array $windows, int $actOn = 1, int $actOff = 0): array
    {
        $w   = self::normalizeWindows($windows);
        $pts = [];
        if (!$w || $w[0][0] > 0) {
            $pts[] = 0;                       // Tagesanfang: aus
        }
        foreach ($w as $x) {
            $pts[] = $x[0];
            if ($x[1] < 1439) {
                $pts[] = $x[1];
            }
        }
        $out = [];
        foreach ($pts as $i => $min) {
            // Punkte wechseln sich ab; der erste ist "Aus", wenn der Tag nicht mit einem
            // Fenster beginnt, sonst "Ein".
            $ein = (!$w || $w[0][0] > 0) ? ($i % 2 === 1) : ($i % 2 === 0);
            $out[] = ['min' => $min, 'h' => intdiv($min, 60), 'm' => $min % 60,
                      'a'   => $ein ? $actOn : $actOff];
        }
        return $out;
    }

    /** Tagesgruppen -> Fenster je Wochentag (Index 0=Mo .. 6=So), fuer die Rueckrichtung. */
    public static function groupsToPerDay(array $groups): array
    {
        $perDay = array_fill(0, 7, []);
        foreach ($groups as $g) {
            $days = (int) ($g['days'] ?? 0);
            for ($d = 0; $d < 7; $d++) {
                if ($days & (1 << $d)) {
                    $perDay[$d] = array_merge($perDay[$d], $g['windows'] ?? []);
                }
            }
        }
        for ($d = 0; $d < 7; $d++) {
            $perDay[$d] = self::normalizeWindows($perDay[$d]);
        }
        return $perDay;
    }

    /** Tagesgruppen mit identischem Fensterbild zu einer Gruppe verschmelzen (ODER der Tage). */
    public static function mergeIdenticalDays(array $groups): array
    {
        $out = [];
        foreach ($groups as $g) {
            $wins = self::normalizeWindows($g['windows'] ?? []);
            if (!$wins) {
                continue;
            }
            $sig = self::signature($wins);
            if (isset($out[$sig])) {
                $out[$sig]['days'] |= (int) ($g['days'] ?? 0);
                continue;
            }
            $out[$sig] = ['days' => (int) ($g['days'] ?? 0), 'windows' => $wins];
        }
        $out = array_values($out);
        usort($out, static fn($a, $b) => $a['days'] <=> $b['days']);
        return $out;
    }

    /** Eine Regel bauen: Relais, Tagesmaske (Symcon-Schema) und bis zu vier Fenster. */
    private static function buildRule(int $relay, int $symconDays, array $windows): array
    {
        $rule    = array_fill(0, self::RULE_LEN, 0);
        $rule[1] = $relay;
        $rule[2] = self::symconToCtrlDays($symconDays);
        $i       = 0;
        foreach ($windows as $w) {
            if ($i >= self::WIN_PER_RULE) {
                break;
            }
            $rule[3 + $i * 3] = 1;
            $rule[4 + $i * 3] = (int) $w[0];
            $rule[5 + $i * 3] = (int) $w[1];
            $i++;
        }
        $rule[0] = $i > 0 ? 1 : 0;
        return $rule;
    }

    /** Sortiert, verwirft Unsinn und zieht ueberlappende/angrenzende Fenster zusammen. */
    public static function normalizeWindows(array $windows): array
    {
        $w = [];
        foreach ($windows as $x) {
            $s = (int) ($x[0] ?? 0);
            $e = (int) ($x[1] ?? 0);
            if ($e > $s) {
                $w[] = [max(0, $s), min(1439, $e)];
            }
        }
        if (!$w) {
            return [];
        }
        usort($w, static fn($a, $b) => $a[0] <=> $b[0]);
        $out = [array_shift($w)];
        foreach ($w as $x) {
            $last = count($out) - 1;
            if ($x[0] <= $out[$last][1]) {              // beruehrt oder ueberlappt
                $out[$last][1] = max($out[$last][1], $x[1]);
                continue;
            }
            $out[] = $x;
        }
        return $out;
    }

    /** Auf genau 16 Regeln a 15 Feldern bringen (fehlende/kurze Regeln = leer). */
    private static function normalizeRules(array $current): array
    {
        $rules = [];
        for ($i = 0; $i < self::RULE_COUNT; $i++) {
            $r = $current[$i] ?? null;
            if (!is_array($r) || count($r) < self::RULE_LEN) {
                $rules[$i] = array_fill(0, self::RULE_LEN, 0);
                continue;
            }
            $rules[$i] = array_map('intval', array_slice(array_values($r), 0, self::RULE_LEN));
        }
        return $rules;
    }

    private static function signature(array $windows): string
    {
        return implode('|', array_map(static fn($w) => $w[0] . '-' . $w[1], $windows));
    }
}

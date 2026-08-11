<?php

declare(strict_types=1);

namespace Hoep\HomeSuite;

/**
 * ScheduleEngine — Wochen-/Slot-Logik, Geo- und Regel-Auswertung (§3).
 *
 * Zentrale Eigenschaft: eval() ist DETERMINISTISCH und saisonunabhaengig. Fuer
 * einen Zeitstempel + Variante liefert es reproduzierbar den aktiven Slot-Wert.
 * Genau das ist die Basis fuer die BERECHNUNGSbasierte Heizungs-verify (Blocker C):
 * fuer jeden der 7x3x13 Slots wird der Engine-Sollwert gegen den aus dem HM-Blob
 * rekonstruierten Alt-Wert verglichen — offline, ohne "hat der Regler diese Woche
 * geschrieben" (was im August falsch-gruen waere).
 *
 * scheduleMode == 'controller' (§4.1): fuer "dumme" Thermostate faehrt diese Engine
 * den Zeitplan und ruft an jeder Slot-Grenze IThermostat::setSetpoint().
 *
 * SLOT-MODELL:
 *   Ablage im Store unter "schedule.<variant>.<day>" als Liste [{end:int,val:mixed}, ...].
 *   'end' = Minuten seit Mitternacht (1..1440), OBERE, EXKLUSIVE Grenze des Slots
 *           (Slot deckt [vorheriges end, end) ab). Letzter Slot endet auf 1440 (24:00).
 *   Tages-Index 'day': 0 = Montag ... 6 = Sonntag.
 *
 * FLUECHTIGER Zustand gehoert NIE in den Store (Blocker D) — hier landen nur die
 * Zeitplan-Definitionen (Konfiguration).
 */
final class ScheduleEngine
{
    private Store $s;

    public function __construct(Store $s)
    {
        $this->s = $s;
    }

    /**
     * Liefert die Slots einer Variante/eines Tages (aufsteigend nach 'end').
     * @return array<int,array{end:int,val:mixed}>
     */
    public function getSlots(string $variant, int $day): array
    {
        $day  = $this->clampDay($day);
        $list = $this->s->get('schedule.' . $variant . '.' . $day, []);
        if (!is_array($list)) {
            return [];
        }
        $out = [];
        foreach ($list as $slot) {
            if (is_array($slot) && isset($slot['end'])) {
                $entry = ['end' => (int) $slot['end'], 'val' => $slot['val'] ?? null];
                // Sonnen-Anker (Beschattung) durchreichen; Heizung hat keine -> unveraendert.
                if (isset($slot['anchor']) && $slot['anchor'] !== '' && $slot['anchor'] !== null) {
                    $entry['anchor'] = (string) $slot['anchor'];
                    $entry['offset'] = (int) ($slot['offset'] ?? 0);
                }
                $out[] = $entry;
            }
        }
        return $out;
    }

    /**
     * Setzt die Slots einer Variante/eines Tages — sortiert + validiert + normalisiert:
     *   - ungueltige Eintraege (end<=0 oder end>1440) werden verworfen,
     *   - aufsteigend nach 'end' sortiert, doppelte 'end' zusammengefasst (letzter gewinnt),
     *   - der letzte Slot wird hart auf 1440 (24:00) gezogen (lastSlotFixed).
     *
     * @param array<int,array{end:mixed,val:mixed}> $slots
     */
    public function setSlots(string $variant, int $day, array $slots): void
    {
        $day  = $this->clampDay($day);
        $norm = [];
        foreach ($slots as $slot) {
            if (!is_array($slot) || !isset($slot['end'])) {
                continue;
            }
            $end = (int) $slot['end'];
            if ($end <= 0 || $end > 1440) {
                continue;
            }
            // Doppelte Endzeit: letzter Eintrag gewinnt.
            $entry = ['end' => $end, 'val' => $slot['val'] ?? null];
            if (isset($slot['anchor']) && $slot['anchor'] !== '' && $slot['anchor'] !== null) {
                $entry['anchor'] = (string) $slot['anchor'];
                $entry['offset'] = (int) ($slot['offset'] ?? 0);
            }
            $norm[$end] = $entry;
        }
        if ($norm === []) {
            $this->s->set('schedule.' . $variant . '.' . $day, []);
            return;
        }
        ksort($norm);
        $list = array_values($norm);
        // lastSlotFixed = 24:00
        $list[count($list) - 1]['end'] = 1440;

        $this->s->set('schedule.' . $variant . '.' . $day, $list);
    }

    /**
     * Aktiver Slot-Wert fuer Zeitstempel $ts und Variante — DETERMINISTISCH.
     * @return mixed Slot-Wert oder null, wenn fuer den Tag keine Slots definiert sind.
     */
    public function eval(int $ts, string $variant)
    {
        $day     = (int) date('N', $ts) - 1;         // 1..7 -> 0..6 (Mo..So)
        $minutes = ((int) date('G', $ts)) * 60 + (int) date('i', $ts);

        $slots = $this->getSlots($variant, $day);
        if ($slots === []) {
            return null;
        }
        foreach ($slots as $slot) {
            if ($minutes < $slot['end']) {
                return $slot['val'];
            }
        }
        // Fallback: letzter Slot (deckt bis 24:00).
        return $slots[count($slots) - 1]['val'];
    }

    /**
     * Geo-/Sonnenstands-Auswertung (Beschattung). Liefert einen Ziel-Positions-%
     * (0=offen..100=zu) oder null (= keine Beschattungsanforderung).
     *
     * @param float $az        Azimut der Sonne 0..360
     * @param float $el        Elevation der Sonne (Grad)
     * @param float $bright    Helligkeit (z. B. Lux / kLux) — Einheit legt der Aufrufer fest
     * @param array $geoProfile { azimuthBgn:int, azimuthEnd:int, elevation:int,
     *                            brightnessMin?:float, closePct?:int (Default 100) }
     */
    public function evalGeo(float $az, float $el, float $bright, array $geoProfile): ?int
    {
        $azBgn     = (float) ($geoProfile['azimuthBgn'] ?? 0);
        $azEnd     = (float) ($geoProfile['azimuthEnd'] ?? 360);
        $elMin     = (float) ($geoProfile['elevation'] ?? 0);
        $brightMin = (float) ($geoProfile['brightnessMin'] ?? 0);
        $closePct  = (int) ($geoProfile['closePct'] ?? 100);

        // Azimut-Fenster mit moeglicher 360°-Umschlagbehandlung.
        if ($azBgn <= $azEnd) {
            $inAz = ($az >= $azBgn && $az <= $azEnd);
        } else {
            $inAz = ($az >= $azBgn || $az <= $azEnd);
        }

        if ($inAz && $el >= $elMin && $bright >= $brightMin) {
            return max(0, min(100, $closePct));
        }
        return null;
    }

    /**
     * Deklarative, getierte Regel-Kaskade (Blocker B). Die geordnete $ruleset-Liste
     * (aus der Beschattungs-Kaskade AutomaticOff -> Weather -> Custom -> ManualChange
     * -> Presence -> Temp -> DayNight) wird der Reihe nach ausgewertet; die ERSTE
     * Regel mit target != null gewinnt.
     *
     * SICHERHEITS-TIER-INVARIANTE (testpflichtig): Regeln mit tier=='safety'
     * (Wind/Regen, Custom-Sicherheit) IGNORIEREN manualHold HART — sie ueberfahren
     * eine manuelle Nutzeraenderung (Sturm/Regen -> Sachschadenschutz). manualHold
     * unterdrueckt ausschliesslich Komfort-Regeln (present/temp/day/night).
     *
     * Jede Regel: [ 'tier'=>'safety'|'comfort', 'active'=>bool, 'target'=>int|null ].
     * $ctx: [ 'manualHold'=>bool, ... ] (weitere Felder frei, hier nur manualHold relevant).
     *
     * REIN & DETERMINISTISCH: der Aufrufer berechnet die Bedingungen (active/target),
     * die Engine wendet nur die Tier-Vorrang-Logik an -> direkt testbar.
     */
    public function evalRules(array $ruleset, array $ctx): ?int
    {
        $held = !empty($ctx['manualHold']);

        foreach ($ruleset as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            if (empty($rule['active'])) {
                continue;
            }
            $tier = (string) ($rule['tier'] ?? 'comfort');
            // Komfort-Regeln werden bei aktivem manualHold uebersprungen; Safety NIE.
            if ($held && $tier !== 'safety') {
                continue;
            }
            $target = $rule['target'] ?? null;
            if ($target !== null) {
                return (int) $target;
            }
        }
        return null;
    }

    /**
     * Exportiert eine Variante als Homematic-taugliches Wochenprofil: 7 Tage, auf
     * $rasterMin gerastert, auf $slotLimit Slots begrenzt, letzter Slot 24:00.
     *
     * @return array<int,array<int,array{end:int,val:mixed}>> Index 0..6 (Mo..So)
     */
    public function toHomematicWeek(string $variant, int $rasterMin = 5, int $slotLimit = 13): array
    {
        if ($rasterMin < 1) {
            $rasterMin = 1;
        }
        if ($slotLimit < 1) {
            $slotLimit = 1;
        }

        $week = [];
        for ($day = 0; $day < 7; $day++) {
            $slots = $this->getSlots($variant, $day);
            $week[$day] = $this->rasterAndCap($slots, $rasterMin, $slotLimit);
        }
        return $week;
    }

    /**
     * Rastert+begrenzt eine beliebige Slot-Liste (fuer aus Wochenplan-Ereignissen
     * abgeleitete Tagesprofile, damit sie ins Geraeteraster passen).
     *
     * @param array<int,array{end:int,val:mixed}> $slots
     * @return array<int,array{end:int,val:mixed}>
     */
    public function rasterCap(array $slots, int $rasterMin = 10, int $slotLimit = 13): array
    {
        return $this->rasterAndCap($slots, max(1, $rasterMin), max(1, $slotLimit));
    }

    // ----------------------------------------------------------------------

    /**
     * Rastert Slot-Endzeiten auf ein Vielfaches von $rasterMin (aufrunden),
     * fasst benachbarte gleiche Werte zusammen und begrenzt auf $slotLimit
     * (durch Zusammenfassen der jeweils kuerzesten inneren Grenze).
     *
     * @param array<int,array{end:int,val:mixed}> $slots
     * @return array<int,array{end:int,val:mixed}>
     */
    private function rasterAndCap(array $slots, int $rasterMin, int $slotLimit): array
    {
        if ($slots === []) {
            return [];
        }

        // 1) Rastern (aufrunden), auf 1440 klemmen, doppelte Endzeiten zusammenfassen.
        $rastered = [];
        foreach ($slots as $slot) {
            $end = (int) ceil($slot['end'] / $rasterMin) * $rasterMin;
            if ($end > 1440) {
                $end = 1440;
            }
            $rastered[$end] = ['end' => $end, 'val' => $slot['val']];
        }
        ksort($rastered);
        $list = array_values($rastered);
        $list[count($list) - 1]['end'] = 1440;

        // 2) Benachbarte gleiche Werte zusammenfassen.
        $list = $this->mergeEqual($list);

        // 3) Auf slotLimit begrenzen: wiederholt die innere Grenze mit der
        //    kuerzesten Spanne entfernen (in den nachfolgenden Slot mergen).
        while (count($list) > $slotLimit) {
            $list = $this->mergeShortestSpan($list);
        }

        return $list;
    }

    /**
     * @param array<int,array{end:int,val:mixed}> $list
     * @return array<int,array{end:int,val:mixed}>
     */
    private function mergeEqual(array $list): array
    {
        $out = [];
        foreach ($list as $slot) {
            $n = count($out);
            if ($n > 0 && $this->valEquals($out[$n - 1]['val'], $slot['val'])) {
                // gleiche Temperatur -> vorherigen Slot bis zu diesem end verlaengern
                $out[$n - 1]['end'] = $slot['end'];
            } else {
                $out[] = $slot;
            }
        }
        return $out;
    }

    /**
     * Entfernt genau eine INNERE Grenze (die mit der kuerzesten Spanne) und fasst
     * den betroffenen Slot in seinen Nachfolger zusammen. Deterministisch:
     * bei Gleichstand gewinnt der niedrigste Index.
     *
     * @param array<int,array{end:int,val:mixed}> $list
     * @return array<int,array{end:int,val:mixed}>
     */
    private function mergeShortestSpan(array $list): array
    {
        $n = count($list);
        if ($n <= 1) {
            return $list;
        }
        $prevEnd  = 0;
        $bestIdx  = 0;
        $bestSpan = PHP_INT_MAX;
        // Nur innere Grenzen (Index 0..n-2) sind entfernbar; der letzte (24:00) bleibt.
        for ($i = 0; $i < $n - 1; $i++) {
            $span = $list[$i]['end'] - $prevEnd;
            if ($span < $bestSpan) {
                $bestSpan = $span;
                $bestIdx  = $i;
            }
            $prevEnd = $list[$i]['end'];
        }
        // Slot $bestIdx in $bestIdx+1 mergen: dessen Grenze entfaellt, Nachfolger
        // uebernimmt den frueheren Startbereich (behaelt seinen eigenen Wert/end).
        array_splice($list, $bestIdx, 1);
        return $list;
    }

    private function valEquals($a, $b): bool
    {
        if (is_float($a) || is_float($b) || is_int($a) || is_int($b)) {
            return abs((float) $a - (float) $b) < 1e-9;
        }
        return $a === $b;
    }

    private function clampDay(int $day): int
    {
        if ($day < 0) {
            return 0;
        }
        if ($day > 6) {
            return 6;
        }
        return $day;
    }
}

<?php

declare(strict_types=1);

namespace Hoep\HomeSuite\Engines;

/**
 * Belegung einer Ferienwohnung aus Tageswerten — reine Rechnung, ohne Symcon-Aufrufe.
 *
 * Eingang ist ein Tageswert je Kalendertag des Jahres (Index 0 = 1. Januar): je hoeher,
 * desto sicherer war jemand da (typisch: mittlere Zahl fremder Geraete im Gaestenetz plus
 * Aktivitaet wie Klima-Schaltvorgaenge). Daraus entstehen
 *   - der Tagesstatus: ausser Saison / frei / An- oder Abreise / belegt,
 *   - die belegten Wochen (auch VERMUTETE: zwei Wechseltage in Folge ohne sichtbare
 *     Belegung gelten als Beginn einer Wochenbuchung),
 *   - die noch buchbaren Wochen und alle moeglichen Wochen der Saison,
 *   - Umsatz, Potenzial und Maximum aus den Wochenpreisen.
 *
 * Zwei Rechenweisen:
 *  - 'legacy': die Regeln des frueheren Skript-Analysators, eins zu eins (per Test belegt).
 *    Schwaechen: jeder Belegungsschnipsel zaehlt als ganze Woche, vermutete Buchungen
 *    ueberlappen sich - mit echten Daten ergab das 24 belegte Wochen in einer Saison mit
 *    17 moeglichen und einen Umsatz ueber dem Maximum.
 *  - 'v2' (Standard): Belegungen mit hoechstens `gapMax` Tagen Luecke gelten als EIN
 *    Aufenthalt, erst ab `minStay` Tagen als Buchung (kuerzere Aktivitaet = Reinigung,
 *    Wartung); vermutete Buchungen ueberlappen nie; Wochen = gerundete Aufenthaltsdauer;
 *    Wochen und Umsatz nie ueber dem Maximum der Saison.
 */
final class OccupancyEngine
{
    public const ST_OFF      = 'off-season';
    public const ST_FREE     = 'available';
    public const ST_SERVICE  = 'special';     // An-/Abreise, Wechseltag
    public const ST_OCCUPIED = 'occupied';

    private array $values;
    private int $year;
    private int $days;
    private ?int $seasonFrom;   // Tagesindex
    private ?int $seasonTo;
    private array $prices;      // [{from:int, to:int, price:float}]
    private float $vacantMax;
    private float $serviceMax;
    private float $occupiedMin;
    private string $mode;
    private int $gapMax;
    private int $minStay;
    private ?array $v2 = null;  // Zwischenergebnis der v2-Rechnung

    /**
     * @param array  $values     Tageswert je Tagesindex (fehlend = 0)
     * @param int    $year       Kalenderjahr
     * @param string $seasonFrom "1.6." (deutsch) oder "" fuer ganzjaehrig
     * @param string $seasonTo   "30.9."
     * @param array  $prices     [{start:"1.6.", end:"7.6.", price_per_week:560}, ...]
     * @param array  $thresholds {vacant_max, service_max, occupied_min}
     */
    public function __construct(array $values, int $year, string $seasonFrom = '', string $seasonTo = '',
                                array $prices = [], array $thresholds = [], array $opts = [])
    {
        $this->mode    = (($opts['mode'] ?? 'v2') === 'legacy') ? 'legacy' : 'v2';
        $this->gapMax  = max(0, (int) ($opts['gapMax'] ?? 2));
        $this->minStay = max(1, (int) ($opts['minStay'] ?? 3));
        $this->year   = $year;
        $this->days   = self::isLeap($year) ? 366 : 365;
        $this->values = $values;
        $this->seasonFrom = $seasonFrom !== '' ? $this->dayIndex($seasonFrom) : null;
        $this->seasonTo   = $seasonTo !== '' ? $this->dayIndex($seasonTo) : null;
        $this->prices = [];
        foreach ($prices as $p) {
            $a = $this->dayIndex((string) ($p['start'] ?? ''));
            $b = $this->dayIndex((string) ($p['end'] ?? ''));
            if ($a !== null && $b !== null) {
                $this->prices[] = ['from' => $a, 'to' => $b, 'price' => (float) ($p['price_per_week'] ?? 0)];
            }
        }
        $this->vacantMax   = (float) ($thresholds['vacant_max'] ?? 0.1);
        $this->serviceMax  = (float) ($thresholds['service_max'] ?? 0.3);
        $this->occupiedMin = (float) ($thresholds['occupied_min'] ?? 0.3);
    }

    // ------------------------------------------------------------ oeffentlich

    public function dayCount(): int
    {
        return $this->days;
    }

    /** Status eines Tages (siehe ST_*). In v2 ist jeder Tag einer Buchung "belegt". */
    public function status(int $i): string
    {
        if ($this->mode === 'v2' && $this->inSeason($i)) {
            $v = $this->v2();
            if (isset($v['covered'][$i])) {
                return self::ST_OCCUPIED;
            }
            return $this->isService($i) || $this->isOccupied($i) ? self::ST_SERVICE : self::ST_FREE;
        }
        return $this->rawStatus($i);
    }

    /** Status nur aus dem Tageswert und den Schwellen. */
    public function rawStatus(int $i): string
    {
        if (!$this->inSeason($i)) {
            return self::ST_OFF;
        }
        if (!isset($this->values[$i])) {
            return self::ST_FREE;
        }
        $v = (float) $this->values[$i];
        if ($v <= $this->vacantMax) {
            return self::ST_FREE;
        }
        if ($v <= $this->serviceMax) {
            return self::ST_SERVICE;
        }
        return self::ST_OCCUPIED;
    }

    /** Tagesstatistik der Saison (gleiche Felder wie der fruehere Analysator). */
    public function stats(): array
    {
        if ($this->mode === 'v2') {
            return $this->statsV2();
        }
        $occ = 0; $svc = 0; $free = 0; $off = 0;
        for ($i = 0; $i < $this->days; $i++) {
            if (!$this->inSeason($i)) {
                $off++;
            } elseif ($this->isService($i)) {
                $svc++;
            } elseif ($this->isOccupied($i)) {
                $occ++;
            } else {
                $free++;
            }
        }
        $season = $this->days - $off;
        $total = $occ + $svc;
        return [
            'occupied' => $occ, 'check_in_out' => $svc, 'total_occupied' => $total,
            'available' => $free, 'off_season' => $off, 'season_days' => $season,
            'occupancy_percent' => $season > 0 ? round($total / $season * 100, 1) : 0.0,
        ];
    }

    /** Wochen und Umsatz. */
    public function revenue(): array
    {
        if ($this->mode === 'v2') {
            return $this->revenueV2();
        }
        $occW = $this->occupiedWeeks();
        $avW  = $this->availableWeeks();
        $allW = $this->allWeeks();
        $sum = function (array $weeks): float {
            $s = 0.0;
            foreach ($weeks as $w) {
                $s += $this->weekPrice($w['start'], $w['end']);
            }
            return $s;
        };
        return [
            'current'   => $sum($occW),
            'potential' => $sum($avW),
            'max'       => $sum($allW),
            'occupied_weeks'  => count($occW),
            'available_weeks' => count($avW),
            'possible_weeks'  => count($allW),
            'bookings'  => array_map(function ($w) {
                return $w + ['price' => $this->weekPrice($w['start'], $w['end'])];
            }, $occW),
            'free'      => $avW,
        ];
    }

    /** Tagesindex -> 'Y-m-d' */
    public function date(int $i): string
    {
        return date('Y-m-d', mktime(0, 0, 0, 1, 1 + $i, $this->year));
    }

    // ------------------------------------------------------------ v2

    /**
     * Aufenthalte bilden: aktive Tage (Wechseltag oder belegt) mit hoechstens gapMax
     * Tagen Luecke gehoeren zusammen. Ein Aufenthalt mit mindestens einem belegten Tag und
     * minStay Laenge ist eine Buchung; nur Wechseltage (mind. zwei in Folge) ergeben eine
     * VERMUTETE Buchung ueber den Aufenthalt, mindestens 7 Tage ab dem ersten Wechseltag;
     * alles andere ist Aktivitaet. Vermutete Buchungen werden an echte angestossen, nie
     * ueberlappend.
     */
    private function v2(): array
    {
        if ($this->v2 !== null) {
            return $this->v2;
        }
        $groups = []; $cur = null; $prev = null;
        for ($i = 0; $i < $this->days; $i++) {
            if (!$this->inSeason($i) || !($this->isService($i) || $this->isOccupied($i))) {
                continue;
            }
            if ($cur !== null && $i - $prev - 1 <= $this->gapMax) {
                $cur[1] = $i;
            } else {
                if ($cur !== null) { $groups[] = $cur; }
                $cur = [$i, $i];
            }
            $prev = $i;
        }
        if ($cur !== null) { $groups[] = $cur; }

        $seasonEnd = $this->days - 1;
        while ($seasonEnd > 0 && !$this->inSeason($seasonEnd)) { $seasonEnd--; }

        $real = []; $presumed = []; $activity = [];
        foreach ($groups as [$a, $b]) {
            $occ = 0; $pair = null;
            for ($i = $a; $i <= $b; $i++) {
                if ($this->isOccupied($i)) { $occ++; }
                if ($pair === null && $this->twoService($i)) { $pair = $i; }
            }
            $len = $b - $a + 1;
            if ($occ > 0 && $len >= $this->minStay) {
                $real[] = [$a, $b];
            } elseif ($occ === 0 && $pair !== null) {
                $presumed[] = [$pair, min($seasonEnd, max($b, $pair + 6))];
            } else {
                $activity[] = [$a, $b];
            }
        }
        // vermutete an echte anstossen, nie ueberlappen
        $all = array_map(function ($r) { return [$r[0], $r[1], false]; }, $real);
        usort($all, function ($x, $y) { return $x[0] <=> $y[0]; });
        foreach ($presumed as [$a, $b]) {
            foreach ($all as $r) {
                if ($a <= $r[1] && $b >= $r[0]) {          // Ueberlappung
                    if ($a >= $r[0]) { $a = $r[1] + 1; } else { $b = $r[0] - 1; }
                }
            }
            if ($b - $a + 1 >= $this->minStay) {
                $all[] = [$a, $b, true];
                usort($all, function ($x, $y) { return $x[0] <=> $y[0]; });
            }
        }
        $covered = [];
        $bookings = [];
        foreach ($all as [$a, $b, $p]) {
            for ($i = $a; $i <= $b; $i++) { $covered[$i] = true; }
            $len = $b - $a + 1;
            $n = max(1, (int) round($len / 7));
            $price = 0.0;
            for ($k = 0; $k < $n; $k++) {
                $s = $a + intdiv($k * $len, $n);
                $e = $a + intdiv(($k + 1) * $len, $n) - 1;
                $price += $this->weekPrice($s, $e);
            }
            $bookings[] = ['start' => $a, 'end' => $b, 'days' => $len, 'weeks' => $n, 'presumed' => $p, 'price' => $price];
        }
        return $this->v2 = ['bookings' => $bookings, 'covered' => $covered, 'activity' => $activity];
    }

    private function revenueV2(): array
    {
        $v = $this->v2();
        $allW = $this->allWeeks();
        $max = 0.0;
        foreach ($allW as $w) { $max += $this->weekPrice($w['start'], $w['end']); }
        $cur = 0.0; $weeks = 0;
        foreach ($v['bookings'] as $b) { $cur += $b['price']; $weeks += $b['weeks']; }
        // freie Wochen: Saisontage ausserhalb aller Buchungen, Laeufe ab 7 Tagen
        $free = []; $start = null;
        for ($i = 0; $i < $this->days; $i++) {
            $isFree = $this->inSeason($i) && !isset($v['covered'][$i]);
            if ($isFree && $start === null) { $start = $i; }
            if (!$isFree && $start !== null) { $free = array_merge($free, $this->splitFree($start, $i - 1)); $start = null; }
        }
        if ($start !== null) { $free = array_merge($free, $this->splitFree($start, $this->days - 1)); }
        $pot = 0.0;
        foreach ($free as $w) { $pot += $this->weekPrice($w['start'], $w['end']); }
        return [
            'current'   => min($cur, $max),
            'potential' => min($pot, max(0.0, $max - min($cur, $max))),
            'max'       => $max,
            'occupied_weeks'  => min($weeks, count($allW)),
            'available_weeks' => count($free),
            'possible_weeks'  => count($allW),
            'bookings'  => $v['bookings'],
            'free'      => $free,
            'activity'  => array_map(function ($r) { return ['start' => $r[0], 'end' => $r[1], 'days' => $r[1] - $r[0] + 1]; }, $v['activity']),
        ];
    }

    private function statsV2(): array
    {
        $v = $this->v2();
        $season = 0; $booked = 0; $svc = 0; $act = 0;
        foreach ($v['activity'] as $r) { $act += $r[1] - $r[0] + 1; }
        for ($i = 0; $i < $this->days; $i++) {
            if (!$this->inSeason($i)) { continue; }
            $season++;
            if (isset($v['covered'][$i])) { $booked++; }
            if ($this->isService($i)) { $svc++; }
        }
        return [
            'occupied' => $booked, 'check_in_out' => $svc, 'total_occupied' => $booked,
            'available' => $season - $booked, 'off_season' => $this->days - $season, 'season_days' => $season,
            'activity_days' => $act,
            'occupancy_percent' => $season > 0 ? round($booked / $season * 100, 1) : 0.0,
        ];
    }

    // ------------------------------------------------------------ Regeln

    private function inSeason(int $i): bool
    {
        if ($this->seasonFrom === null || $this->seasonTo === null) {
            return true;
        }
        return $i >= $this->seasonFrom && $i <= $this->seasonTo;
    }

    private function isOccupied(int $i): bool
    {
        return isset($this->values[$i]) && (float) $this->values[$i] > $this->occupiedMin;
    }

    private function isService(int $i): bool
    {
        if (!isset($this->values[$i])) {
            return false;
        }
        $v = (float) $this->values[$i];
        return $v > $this->vacantMax && $v <= $this->serviceMax;
    }

    /** Zwei Wechseltage in Folge (beide in der Saison). */
    private function twoService(int $i): bool
    {
        if ($i >= $this->days - 1) {
            return false;
        }
        return $this->isService($i) && $this->isService($i + 1) && $this->inSeason($i) && $this->inSeason($i + 1);
    }

    /** Liegt der Tag in einer vermuteten Wochenbuchung (7 Tage ab zwei Wechseltagen)? */
    private function inPresumed(int $i): bool
    {
        for ($back = 0; $back <= 6 && $i - $back >= 0; $back++) {
            $s = $i - $back;
            if ($this->twoService($s) && $i >= $s && $i <= $s + 6) {
                return true;
            }
        }
        return false;
    }

    private function occupiedWeeks(): array
    {
        $weeks = []; $in = false; $start = null;
        for ($i = 0; $i < $this->days; $i++) {
            if (!$this->inSeason($i)) {
                continue;
            }
            $occ = $this->isOccupied($i);
            $svc = $this->isService($i);
            $two = $this->twoService($i);
            if ($occ && !$in) {
                $start = $i; $in = true;
            } elseif ($two && !$in) {
                $s = $i; $e = $s + 6; $overlap = false;
                for ($c = $s; $c <= $e && $c < $this->days; $c++) {
                    if ($this->inSeason($c) && $this->isOccupied($c)) { $overlap = true; break; }
                }
                if (!$overlap) {
                    $weeks = array_merge($weeks, $this->splitBooking($s, $e, true));
                }
                $i++;
            } elseif ($two && $in) {
                if ($start <= $i - 1) {
                    $weeks = array_merge($weeks, $this->splitBooking($start, $i - 1, false));
                }
                $in = false;
                $i++;
            } elseif (!$occ && !$svc && $in) {
                if ($start <= $i - 1) {
                    $weeks = array_merge($weeks, $this->splitBooking($start, $i - 1, false));
                }
                $in = false;
            }
        }
        if ($in && $start !== null && $start < $this->days) {
            $e = $this->days - 1;
            while ($e >= 0 && !$this->inSeason($e)) { $e--; }
            if ($start <= $e) {
                $weeks = array_merge($weeks, $this->splitBooking($start, $e, false));
            }
        }
        return $weeks;
    }

    /** 1-8 Tage = 1 Woche, 9-15 = 2, 16-22 = 3 ... */
    private function splitBooking(int $s, int $e, bool $presumed): array
    {
        $days = $e - $s + 1;
        $n = $days <= 8 ? 1 : 1 + (int) ceil(($days - 8) / 7);
        $out = []; $cur = $s;
        for ($w = 0; $w < $n; $w++) {
            if ($w === $n - 1) {
                $out[] = ['start' => $cur, 'end' => $e, 'days' => $e - $cur + 1, 'presumed' => $presumed];
            } else {
                $out[] = ['start' => $cur, 'end' => $cur + 6, 'days' => 7, 'presumed' => $presumed];
                $cur += 7;
            }
        }
        return $out;
    }

    private function availableWeeks(): array
    {
        $weeks = []; $in = false; $start = null;
        for ($i = 0; $i < $this->days; $i++) {
            if (!$this->inSeason($i)) {
                continue;
            }
            $two = $this->twoService($i);
            $free = !$this->isOccupied($i) && !$this->isService($i) && !$two && !$this->inPresumed($i);
            if ($free && !$in) {
                $start = $i; $in = true;
            } elseif (!$free && $in) {
                $weeks = array_merge($weeks, $this->splitFree($start, $i - 1));
                $in = false;
            }
            if ($two) {
                $i++;
            }
        }
        if ($in && $start !== null) {
            $e = $this->days - 1;
            while ($e >= 0 && !$this->inSeason($e)) { $e--; }
            if ($e >= $start) {
                $weeks = array_merge($weeks, $this->splitFree($start, $e));
            }
        }
        return $weeks;
    }

    /** Freie Zeitraeume in buchbare Wochen (mind. 7 Tage; 7-8 Tage = 1 Woche). */
    private function splitFree(int $s, int $e): array
    {
        $out = []; $rest = $e - $s + 1; $cur = $s;
        while ($rest >= 7) {
            if ($rest <= 8) {
                $out[] = ['start' => $cur, 'end' => $e, 'days' => $rest];
                break;
            }
            $out[] = ['start' => $cur, 'end' => $cur + 6, 'days' => 7];
            $cur += 7; $rest -= 7;
        }
        return $out;
    }

    private function allWeeks(): array
    {
        $a = null; $b = null;
        for ($i = 0; $i < $this->days; $i++) {
            if ($this->inSeason($i)) {
                if ($a === null) { $a = $i; }
                $b = $i;
            }
        }
        return $a === null ? [] : $this->splitFree($a, $b);
    }

    /** Preis der Woche nach ihrem mittleren Tag. */
    private function weekPrice(int $s, int $e): float
    {
        $mid = $s + (int) floor(($e - $s) / 2);
        foreach ($this->prices as $p) {
            if ($mid >= $p['from'] && $mid <= $p['to']) {
                return $p['price'];
            }
        }
        return 0.0;
    }

    /** "1.6." / "01.06." / "06-01" -> Tagesindex im Jahr; null wenn unlesbar. */
    public function dayIndex(string $d): ?int
    {
        $d = trim($d);
        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.?$/', $d, $m)) {
            $day = (int) $m[1]; $mon = (int) $m[2];
        } elseif (preg_match('/^(\d{1,2})-(\d{1,2})$/', $d, $m)) {
            $mon = (int) $m[1]; $day = (int) $m[2];
        } else {
            return null;
        }
        if (!checkdate($mon, $day, $this->year)) {
            return null;
        }
        return (int) date('z', mktime(0, 0, 0, $mon, $day, $this->year));
    }

    public static function isLeap(int $y): bool
    {
        return ($y % 4 === 0 && $y % 100 !== 0) || $y % 400 === 0;
    }
}

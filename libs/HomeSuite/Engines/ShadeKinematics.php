<?php

declare(strict_types=1);

namespace Hoep\HomeSuite;

/**
 * Step — ein einzelner Fahrbefehl aus der Kinematik-Berechnung.
 *
 * Reines Wertobjekt (kein IPS). $action ist up|down|stop|slat, $durationMs die
 * geplante Fahrdauer in Millisekunden (0 bei stop), $reason eine kurze Begruendung.
 */
final class Step
{
    public string $action;
    public int $durationMs;
    public string $reason;

    public function __construct(string $action, int $durationMs, string $reason = '')
    {
        $this->action     = $action;
        $this->durationMs = $durationMs;
        $this->reason     = $reason;
    }

    /** @return array{action:string,durationMs:int,reason:string} */
    public function toArray(): array
    {
        return ['action' => $this->action, 'durationMs' => $this->durationMs, 'reason' => $this->reason];
    }
}

/**
 * ShadeKinematics — REINE, testbare Positions-/Lamellen-Kinematik der Beschattung
 * (§3, §4.2). Kein IPS, kein Zustand, deterministisch: gleiche Eingabe -> gleiche
 * Schrittfolge. Wird von IShutter-Treibern OHNE Absolut-Positionsfeedback genutzt,
 * um aus einer bekannten Ist-Position eine Ziel-Position ueber getimte Fahrbefehle
 * zu erreichen.
 *
 * Positions-Konvention (FIX): 0 = offen/oben, 100 = zu/unten.
 *
 * $timings (Sekunden, ausser wo anders vermerkt):
 *   'timeOpening'    float  volle Fahrzeit 100 -> 0   (Richtung "up"/oeffnen)
 *   'timeClosing'    float  volle Fahrzeit 0   -> 100 (Richtung "down"/schliessen)
 *   'timePauseBreak' float  Pause (s) vor Richtungswechsel (optional, informativ)
 *   'runIntoEndstop' bool   an 0/100 in den Endanschlag fahren, kein Stop-Step (Default true)
 *   'applyDimout'    bool   nach vollem Schliessen einen kurzen Wende-/Dimout-Schritt anhaengen
 *   'dimoutUp'       float  Dauer (s) des Dimout-Wendeschritts (nur bei applyDimout & Ziel 100)
 *
 * WICHTIG (Blocker E): Ist die Ist-Position UNBEKANNT (IShutter::POS_UNKNOWN = -1),
 * liefert steps() ein LEERES Array — ohne bekannte Ist-Lage darf keine getimte
 * Absolutfahrt geplant werden; der Aufrufer muss dann relativ move()/referenceRun().
 */
final class ShadeKinematics
{
    private function __construct()
    {
    }

    /**
     * Berechnet die Schrittfolge, um von $fromPct auf $movementTarget (Ziel-%) zu
     * fahren.
     *
     * @param int   $fromPct        aktuelle Ist-Position 0..100 (oder -1 = UNKNOWN)
     * @param int   $movementTarget Ziel-Position 0..100
     * @param array $timings        siehe Klassen-Doc
     * @return Step[]
     */
    public static function steps(int $fromPct, int $movementTarget, array $timings): array
    {
        // Blocker E: ohne bekannte Ist-Position keine Absolut-Kinematik.
        if ($fromPct < 0) {
            return [];
        }

        $from   = self::clamp($fromPct);
        $target = self::clamp($movementTarget);

        $timeOpening = max(0.0, (float) ($timings['timeOpening'] ?? 0.0));
        $timeClosing = max(0.0, (float) ($timings['timeClosing'] ?? 0.0));
        $endstop     = (bool) ($timings['runIntoEndstop'] ?? true);
        $applyDimout = (bool) ($timings['applyDimout'] ?? false);
        $dimoutUp    = max(0.0, (float) ($timings['dimoutUp'] ?? 0.0));

        // Kein Bewegungsbedarf.
        if ($target === $from) {
            return [];
        }

        $delta = $target - $from;
        if ($delta > 0) {
            $dir     = 'down';                 // Richtung zu/unten (schliessen)
            $full    = $timeClosing;
        } else {
            $dir     = 'up';                   // Richtung offen/oben (oeffnen)
            $full    = $timeOpening;
        }

        $fraction   = abs($delta) / 100.0;
        $durationMs = (int) round($fraction * $full * 1000.0);

        $steps = [];
        $steps[] = new Step($dir, $durationMs, 'travel ' . $from . '% -> ' . $target . '%');

        // An den Endpositionen 0/100 in den Endanschlag fahren -> kein Stop noetig.
        $atEndstop = ($target === 0 || $target === 100) && $endstop;
        if (!$atEndstop) {
            $steps[] = new Step('stop', 0, 'halt at ' . $target . '%');
        }

        // Optionaler Dimout-/Wendeschritt nach vollstaendigem Schliessen (Lamelle).
        if ($applyDimout && $target === 100 && $dimoutUp > 0.0) {
            $steps[] = new Step('up', (int) round($dimoutUp * 1000.0), 'dimout tilt');
        }

        return $steps;
    }

    private static function clamp(int $pct): int
    {
        if ($pct < 0) {
            return 0;
        }
        if ($pct > 100) {
            return 100;
        }
        return $pct;
    }
}

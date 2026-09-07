<?php

declare(strict_types=1);

namespace Hoep\HomeSuite\Engines;

/**
 * LightAutomation — REINE Entscheidungslogik der Licht-Automatik (L7–L11).
 *
 * Kernel-frei und deterministisch: bekommt Regeln + aktuelle Eingangswerte
 * (Zeit, Sonnenhoehe, Praesenz, Sensor-Zustaende, Merker) und liefert die
 * auszufuehrenden Aktionen zurueck. Das ANWENDEN (Szene anwenden, Variablen
 * schreiben) macht der Hub — so bleibt diese Engine testbar.
 *
 * Regel-Modell (im Hub-Store 'lightAuto' = ['enabled'=>bool,'rules'=>[...]]):
 *   schedule : {type:'schedule', trigger:{kind:'time'|'sun', time:'HH:MM'|offsetMin,
 *              event:'sunrise'|'sunset', days:[0..6]}, sceneId,
 *              sceneAction:'on'|'off' (Vorgabe 'on'),
 *              endTrigger:{...wie trigger...} (optional; feuert die GEGENrichtung)}
 *   circadian: {type:'circadian', devices:[iid,...], minK, maxK, minLevel, maxLevel}
 *   wake     : {type:'wake', time:'HH:MM', days:[...], audioZone,
 *              audioSource:{kind:'station'|'playlist'|'favorite'|'uri'|'preset', id},
 *              volume, rampMin, offAfterMin}  -- REIN AUDIO, schaltet kein Licht.
 *              offAfterMin>0 setzt nach dem Start den Sleep-Timer der Zone. Licht zum
 *              Wecken laeuft ueber eine eigene 'schedule'-Regel auf dieselbe Zeit.
 *   motion   : {type:'motion', sensor:iid|varId, lux:varId, luxMax, sceneOn|deviceOn, holdSec, off:'scene'|'devices'}
 *   presence : {type:'presence', awayVar:varId, from:'HH:MM', to:'HH:MM', devices:[iid,...], every:minMinutes}
 *
 * ACTIONS (Rueckgabe): [ ['kind'=>'applyScene','sceneId'=>..,'ein'=>bool], ['kind'=>'setDevice','device'=>iid,'on'=>..,'level'=>..,'cct'=>..], ... ]
 */
final class LightAutomation
{
    /** Minuten seit Mitternacht aus 'HH:MM'. -1 bei ungueltig. */
    public static function hhmm(string $s): int
    {
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', trim($s), $m)) {
            return -1;
        }
        return ((int) $m[1]) * 60 + (int) $m[2];
    }

    /**
     * Faellige Zeit-/Sonnen-Trigger im Tick-Fenster [prevMin, nowMin] (Minuten seit Mitternacht).
     * $sunMin: ['sunrise'=>Min,'sunset'=>Min]. $weekday: 0=So..6=Sa (wie date('w')).
     * "Kanten"-Auswertung: feuert genau einmal beim Ueberschreiten der Zielminute.
     *
     * @return array<int,array> Aktionen (applyScene) je faelliger Regel
     */
    public static function dueTriggers(array $rules, int $prevMin, int $nowMin, int $weekday, array $sunMin): array
    {
        $acts = [];
        foreach ($rules as $r) {
            $type = (string) ($r['type'] ?? '');
            if ($type === 'schedule' || $type === 'wake') {
                // Einmal-Wecker kennt keine Wochentage: er weckt beim naechsten
                // Erreichen der Uhrzeit, egal welcher Tag, und schaltet sich danach
                // selbst ab (das erledigt der Hub).
                $einmal = ($type === 'wake') && !empty($r['once']);
                $days = $r['days'] ?? ($r['trigger']['days'] ?? []);
                if (!$einmal && is_array($days) && $days !== []
                    && !in_array($weekday, array_map('intval', $days), true)) {
                    continue;
                }
                if ($type === 'wake') {
                    $target = self::hhmm((string) ($r['time'] ?? ''));
                    if ($target >= 0 && self::crossed($prevMin, $nowMin, $target)) {
                        $acts[] = ['kind' => 'wake', 'rule' => $r];
                    }
                    continue;
                }
                if (($r['sceneId'] ?? '') === '') {
                    continue;
                }
                // Eine Regel kann ZWEI Zeitpunkte haben: den eigentlichen Ausloeser und
                // einen optionalen Endzeitpunkt, der die Gegenrichtung schaltet. Damit
                // genuegt EINE Szene fuer "ab Sonnenuntergang an, um 23:00 wieder aus" -
                // vorher brauchte es dafuer zwei Szenen und zwei Regeln.
                $ein = ((string) ($r['sceneAction'] ?? 'on')) !== 'off';
                $punkte = [[$r['trigger'] ?? null, $ein]];
                if (is_array($r['endTrigger'] ?? null) && $r['endTrigger'] !== []) {
                    $punkte[] = [$r['endTrigger'], !$ein];
                }
                foreach ($punkte as [$tr, $richtung]) {
                    $target = self::triggerMin(is_array($tr) ? $tr : [], $sunMin);
                    if ($target >= 0 && self::crossed($prevMin, $nowMin, $target)) {
                        $acts[] = ['kind' => 'applyScene', 'sceneId' => (string) $r['sceneId'],
                                   'ein' => $richtung];
                    }
                }
            }
        }
        return $acts;
    }

    /**
     * Minute eines Ausloesers auf der Tagesachse. -1, wenn er nicht bestimmbar ist
     * (leerer Ausloeser, oder Sonnenzeit fehlt). Gilt fuer trigger UND endTrigger.
     */
    public static function triggerMin(array $tr, array $sunMin): int
    {
        if (($tr['kind'] ?? 'time') === 'sun') {
            $base = (int) ($sunMin[(string) ($tr['event'] ?? 'sunset')] ?? -1);
            return $base >= 0 ? $base + (int) ($tr['offsetMin'] ?? $tr['time'] ?? 0) : -1;
        }
        return self::hhmm((string) ($tr['time'] ?? ''));
    }

    /** Wurde $target im (halboffenen) Fenster (prev, now] ueberschritten? Mitternachts-Wrap beachtet. */
    public static function crossed(int $prev, int $now, int $target): bool
    {
        if ($prev === $now) {
            return false;
        }
        if ($prev < $now) {
            return $target > $prev && $target <= $now;
        }
        // Wrap ueber Mitternacht
        return $target > $prev || $target <= $now;
    }

    /**
     * Circadian: Farbtemperatur + Helligkeit aus Sonnenhoehe (elevation in Grad).
     * Unter Horizont -> warm/min. Bei elMax (Default 55°) -> kalt/max. Linear dazwischen.
     */
    public static function circadian(array $rule, float $elevationDeg, float $elMax = 55.0): array
    {
        $minK = (int) ($rule['minK'] ?? 2200);
        $maxK = (int) ($rule['maxK'] ?? 5500);
        $minL = (int) ($rule['minLevel'] ?? 15);
        $maxL = (int) ($rule['maxLevel'] ?? 100);
        $t = $elevationDeg <= 0 ? 0.0 : min(1.0, $elevationDeg / max(1.0, $elMax));
        return [
            'cct'   => (int) round($minK + ($maxK - $minK) * $t),
            'level' => (int) round($minL + ($maxL - $minL) * $t),
        ];
    }

    /**
     * Bewegungs-Regel auswerten. Liefert Aktion:
     *  - motionOn && (lux unter Schwelle ODER keine Lux) -> 'on'  (setzt holdUntil = now+holdSec)
     *  - !motionOn && now >= holdUntil                    -> 'off'
     *  - sonst                                            -> null (nichts tun)
     * $lux === null = keine Messung. $holdUntil = bisheriger Merker (Unix).
     *
     * @return array{action:?string, holdUntil:int}
     */
    public static function motion(array $rule, bool $motionOn, ?float $lux, int $holdUntil, int $nowTs): array
    {
        $luxMax = (float) ($rule['luxMax'] ?? 0); // 0 = Helligkeit egal (immer schalten)
        $holdSec = max(5, (int) ($rule['holdSec'] ?? 120));
        $darkEnough = ($luxMax <= 0) || ($lux === null) || ($lux <= $luxMax);
        if ($motionOn && $darkEnough) {
            return ['action' => 'on', 'holdUntil' => $nowTs + $holdSec];
        }
        if (!$motionOn && $holdUntil > 0 && $nowTs >= $holdUntil) {
            return ['action' => 'off', 'holdUntil' => 0];
        }
        return ['action' => null, 'holdUntil' => $holdUntil];
    }

    /**
     * Anwesenheitssimulation: soll aktuell ausgeloest werden? (nur wenn away & im Zeitfenster
     * & seit letztem Schalten >= every Minuten). Waehlt aus $deviceCount ein pseudo-zufaelliges
     * Geraet deterministisch aus $nowTs (kein rand -> resume-fest).
     *
     * @return array{fire:bool, index:int, on:bool}
     */
    public static function presenceSim(array $rule, bool $away, int $nowMin, int $lastFireTs, int $nowTs, int $deviceCount): array
    {
        $none = ['fire' => false, 'index' => -1, 'on' => false];
        if (!$away || $deviceCount <= 0) {
            return $none;
        }
        $from = self::hhmm((string) ($rule['from'] ?? '18:00'));
        $to   = self::hhmm((string) ($rule['to'] ?? '23:30'));
        $inWin = ($from <= $to) ? ($nowMin >= $from && $nowMin <= $to) : ($nowMin >= $from || $nowMin <= $to);
        if (!$inWin) {
            return $none;
        }
        $everySec = max(60, (int) ($rule['every'] ?? 20) * 60);
        if ($lastFireTs > 0 && ($nowTs - $lastFireTs) < $everySec) {
            return $none;
        }
        $idx = (int) (($nowTs / 60) % $deviceCount);
        $on  = ((int) ($nowTs / $everySec) % 2) === 0;
        return ['fire' => true, 'index' => $idx, 'on' => $on];
    }
}

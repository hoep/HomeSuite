<?php

declare(strict_types=1);

namespace Hoep\HomeSuite;

/**
 * SunTimes — Sonnen-Ereigniszeiten fuer einen Tag/Standort (fuer sonnen-verankerte
 * Zeitplan-Grenzen der Beschattung).
 *
 * Nutzt die PHP-Standardfunktion date_sun_info() (ext-date, immer vorhanden) —
 * keine eigene Astronomie. Liefert je Anker die Minute seit LOKALER Mitternacht
 * des Tages von $ts (oder null bei Polartag/-nacht, d. h. das Ereignis tritt an
 * diesem Tag nicht ein). Ost-Laenge positiv.
 *
 * Anker: sunrise, sunset, dawnCivil/duskCivil (buergerliche Daemmerung, -6 deg),
 * dawnNautical/duskNautical (-12 deg), dawnAstro/duskAstro (-18 deg).
 */
final class SunTimes
{
    /** Anker-Schluessel -> date_sun_info-Feld. */
    public const ANCHORS = [
        'sunrise'      => 'sunrise',
        'sunset'       => 'sunset',
        'dawnCivil'    => 'civil_twilight_begin',
        'duskCivil'    => 'civil_twilight_end',
        'dawnNautical' => 'nautical_twilight_begin',
        'duskNautical' => 'nautical_twilight_end',
        'dawnAstro'    => 'astronomical_twilight_begin',
        'duskAstro'    => 'astronomical_twilight_end',
    ];

    /**
     * @return array<string,int|null> Anker-Schluessel -> Minute seit lokaler Mitternacht (0..1440) oder null.
     */
    public static function eventsMinutes(int $ts, float $lat, float $lon): array
    {
        $out = [];
        if (!function_exists('date_sun_info')) {
            foreach (self::ANCHORS as $k => $_) {
                $out[$k] = null;
            }
            return $out;
        }
        $info     = @date_sun_info($ts, $lat, $lon);
        $midnight = strtotime('today', $ts); // lokale Mitternacht des Tages von $ts
        foreach (self::ANCHORS as $k => $field) {
            $v = is_array($info) ? ($info[$field] ?? null) : null;
            // Bool (true=Polartag / false=Polarnacht) -> Ereignis tritt nicht ein.
            $out[$k] = (is_int($v) || is_float($v)) ? (int) round(((int) $v - $midnight) / 60) : null;
        }
        return $out;
    }

    /** Ist $anchor ein bekannter Sonnen-Anker? */
    public static function isAnchor(string $anchor): bool
    {
        return $anchor !== '' && array_key_exists($anchor, self::ANCHORS);
    }
}

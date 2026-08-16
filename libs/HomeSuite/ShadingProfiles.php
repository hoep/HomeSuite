<?php

declare(strict_types=1);

namespace Hoep\HomeSuite;

/**
 * ShadingProfiles — EINE Quelle der Wahrheit fuer die Beschattungs-Profiltypen
 * (IPSShadowing-Parität: Sonne, Wetter, Tagesbeginn, Tagesende, Temperatur).
 *
 * Genutzt vom Hub (ProfileEngine-Schema + Verwaltung, geteilte benannte Profile)
 * UND von ShadingDevice (Manifest-profileTypes). mapToConfig() uebersetzt ein
 * zugewiesenes Profil in die Zonen-Config-Felder, die reconcile auswertet — der
 * Hub PUSHT das Ergebnis in die zugewiesenen Zonen (configureAutomation).
 *
 * schema-Felder tragen {type,min,max,label,options?} -> der generische Profil-
 * Editor (Widget shadeprofiles) rendert daraus automatisch Eingabeelemente.
 */
final class ShadingProfiles
{
    /** Modi fuer Tagesbeginn/-ende (feste Zeit oder Sonnen-Ereignis). */
    public const DAY_MODES = [
        ['value' => 'fixed',       'label' => 'Feste Uhrzeit'],
        ['value' => 'sunrise',     'label' => 'Sonnenaufgang'],
        ['value' => 'sunset',      'label' => 'Sonnenuntergang'],
        ['value' => 'dawnCivil',   'label' => 'Bürgerl. Dämmerung Beginn'],
        ['value' => 'duskCivil',   'label' => 'Bürgerl. Dämmerung Ende'],
        ['value' => 'dawnNautical','label' => 'Naut. Dämmerung Beginn'],
        ['value' => 'duskNautical','label' => 'Naut. Dämmerung Ende'],
        ['value' => 'dawnAstro',   'label' => 'Astron. Dämmerung Beginn'],
        ['value' => 'duskAstro',   'label' => 'Astron. Dämmerung Ende'],
    ];

    /** @return array<int,array> profileTypes fuer ProfileEngine + Manifest. */
    public static function types(): array
    {
        return [
            ['id' => 'sun', 'title' => 'Sonnenprofil', 'editor' => 'fields', 'schema' => [
                'azimuthBgn'    => ['type' => 'int',   'min' => 0,   'max' => 360, 'label' => 'Azimut von (°)'],
                'azimuthEnd'    => ['type' => 'int',   'min' => 0,   'max' => 360, 'label' => 'Azimut bis (°)'],
                'elevation'     => ['type' => 'int',   'min' => -10, 'max' => 90,  'label' => 'Elevations-Schwelle (°)'],
                'brightnessMin' => ['type' => 'int',   'min' => 0,   'max' => 200000, 'label' => 'Helligkeit min (0=aus)'],
                // HYSTERESE: Einschalten bei brightnessMin, Ausschalten erst unter brightnessOff.
                // Ohne diesen Abstand pumpt die Anlage bei durchziehenden Wolken - jede Boee
                // Helligkeit faehrt zu, jede Wolke wieder auf. 0 = keine Hysterese (wie bisher).
                'brightnessOff' => ['type' => 'int',   'min' => 0,   'max' => 200000, 'label' => 'Sonne AUS unter (0=aus)'],
                // closePct (Schliessgrad) ist PRO ROLLO (Baum-Var SunClose, im Besonnung-Widget
                // einstellbar) - bewusst NICHT im geteilten Profil-Schema, sonst doppelt.
            ]],
            ['id' => 'weather', 'title' => 'Wetterschutz', 'editor' => 'fields', 'schema' => [
                'windMaxKmh' => ['type' => 'int',  'min' => 0, 'max' => 200, 'label' => 'Wind max (km/h)'],
                'rainClose'  => ['type' => 'bool', 'label' => 'Bei Regen schützen'],
                'safePos'    => ['type' => 'int',  'min' => 0, 'max' => 100, 'label' => 'Sichere Position (%)'],
            ]],
            ['id' => 'dayBegin', 'title' => 'Tagesbeginn', 'editor' => 'fields', 'schema' => [
                'mode'   => ['type' => 'enum',  'label' => 'Zeitpunkt', 'options' => self::DAY_MODES],
                'time'   => ['type' => 'time',  'label' => 'Uhrzeit (bei fest)'],
                'offset' => ['type' => 'int',   'min' => -180, 'max' => 180, 'label' => 'Offset (min)'],
                'pos'    => ['type' => 'int',   'min' => 0, 'max' => 100, 'label' => 'Position tags (%)'],
            ]],
            ['id' => 'dayEnd', 'title' => 'Tagesende', 'editor' => 'fields', 'schema' => [
                'mode'   => ['type' => 'enum',  'label' => 'Zeitpunkt', 'options' => self::DAY_MODES],
                'time'   => ['type' => 'time',  'label' => 'Uhrzeit (bei fest)'],
                'offset' => ['type' => 'int',   'min' => -180, 'max' => 180, 'label' => 'Offset (min)'],
                'pos'    => ['type' => 'int',   'min' => 0, 'max' => 100, 'label' => 'Position nachts (%)'],
            ]],
            ['id' => 'temp', 'title' => 'Temperatur-Gate', 'editor' => 'fields', 'schema' => [
                'aboveC'      => ['type' => 'float', 'min' => -20, 'max' => 50, 'label' => 'Beschatten ab innen (°C, leer=aus)'],
                'sensorId'    => ['type' => 'objid', 'label' => 'Innen-Temperatur-Variable'],
                'outAboveC'   => ['type' => 'float', 'min' => -20, 'max' => 50, 'label' => 'und ab außen (°C, leer=aus)'],
                'outSensorId' => ['type' => 'objid', 'label' => 'Außen-Temperatur-Variable'],
                'requireSun'  => ['type' => 'bool',  'label' => 'Nur bei Sonne'],
            ]],
        ];
    }

    /** @return array<int,string> alle Typ-IDs. */
    public static function typeIds(): array
    {
        return array_map(static fn($t) => $t['id'], self::types());
    }

    /**
     * Uebersetzt die fields eines zugewiesenen Profils in Zonen-Config-Fragmente,
     * die ShadingDevice.reconcile auswertet. Der Hub merged alle Typen und pusht.
     * @return array<string,mixed>
     */
    public static function mapToConfig(string $type, array $f): array
    {
        switch ($type) {
            case 'sun':
                return ['geoProfile' => [
                    'azimuthBgn'    => (int) ($f['azimuthBgn'] ?? 0),
                    'azimuthEnd'    => (int) ($f['azimuthEnd'] ?? 360),
                    'elevation'     => (int) ($f['elevation'] ?? 0),
                    'brightnessMin' => (int) ($f['brightnessMin'] ?? 0),
                    'brightnessOff' => (int) ($f['brightnessOff'] ?? 0),
                    'closePct'      => (int) ($f['closePct'] ?? 100),
                    'profile'       => true,
                ]];
            case 'weather':
                return [
                    'windStormKmh' => (float) ($f['windMaxKmh'] ?? 45),
                    'safePos'      => (int) ($f['safePos'] ?? 0),
                    'rainClose'    => (bool) ($f['rainClose'] ?? true),
                ];
            case 'temp':
                // Innen- UND Außen-Schwelle (wie IPSShadowing ProfileTemp). Leeres Feld = Schwelle aus
                // (null). ShadingDevice.reconcile blockt, wenn eine gesetzte Schwelle unterschritten wird.
                $has = static fn($k) => isset($f[$k]) && $f[$k] !== '' && $f[$k] !== null;
                $tg = [
                    'aboveC'      => $has('aboveC') ? (float) $f['aboveC'] : null,
                    'sensorId'    => (int) ($f['sensorId'] ?? 0),
                    'outAboveC'   => $has('outAboveC') ? (float) $f['outAboveC'] : null,
                    'outSensorId' => (int) ($f['outSensorId'] ?? 0),
                    'requireSun'  => (bool) ($f['requireSun'] ?? true),
                ];
                return ['tempGate' => $tg];
            case 'dayBegin':
                return ['dayBegin' => ['mode' => (string) ($f['mode'] ?? 'sunrise'), 'time' => (string) ($f['time'] ?? '07:00'), 'offset' => (int) ($f['offset'] ?? 0), 'pos' => (int) ($f['pos'] ?? 0)]];
            case 'dayEnd':
                return ['dayEnd' => ['mode' => (string) ($f['mode'] ?? 'sunset'), 'time' => (string) ($f['time'] ?? '21:00'), 'offset' => (int) ($f['offset'] ?? 0), 'pos' => (int) ($f['pos'] ?? 100)]];
        }
        return [];
    }
}

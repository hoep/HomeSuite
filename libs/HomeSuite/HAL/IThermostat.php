<?php

declare(strict_types=1);

namespace Hoep\HomeSuite\HAL;

/**
 * IThermostat — HAL fuer Heizungs-Aktoren (§3, §4.1).
 *
 * ZWEI SCHEDULE-MODI (kanonisch — an capabilities()['scheduleMode'] erkennbar):
 *
 *  - scheduleMode == 'device'
 *      Das GERAET fuehrt das Wochenprofil selbst (HomeMatic, MAX!, viele Z-Wave).
 *      Das Modul delegiert an readWeekProfile()/writeWeekProfile(); setSetpoint()
 *      ist dann ein temporaeres Override-FENSTER (HM-Manual/Boost, Entscheidung A),
 *      keine Profilueberschreibung.
 *
 *  - scheduleMode == 'controller'
 *      Ein "dummer" Sollwert-Thermostat (KNX/Zigbee/MQTT/Shelly TRV/...). Das
 *      Geraet kann nur einen Sollwert. Die ScheduleEngine IM MODUL faehrt den
 *      Wochenplan und ruft an jeder Slot-Grenze setSetpoint() — kein Geraete-
 *      profil, kein Vendor-Code. readWeekProfile()/writeWeekProfile() sind hier
 *      inert (leeres Profil / false), weil das Geraet gar kein Profil kennt.
 *
 * capabilities() liefert (mindestens):
 *   [ 'scheduleMode'   => 'device'|'controller',
 *     'maxSlots'       => int,     // z.B. 13 (HM)
 *     'rasterMinutes'  => int,     // z.B. 5 oder 10
 *     'deviceProfiles' => int,     // Anzahl geraeteseitiger Praesenzprofile (HM: 3)
 *     'separateSensor' => bool,    // getrennter Wandthermostat/Fuehler
 *     'p1Prefix'       => bool,    // HM P1_-Kanal-Prefix
 *     'hasMode'        => bool,    // auto|manual|boost|frost verfuegbar
 *     'hasHumidity'    => bool ]
 */
interface IThermostat extends IDriver
{
    /** @return array caps-Array (siehe Klassen-Doc); ueberschreibt IDriver::capabilities(). */
    public function capabilities(): array;

    /**
     * Live-Messwerte des Geraets.
     * @return array{actual: ?float, setpoint: ?float, humidity: ?int, valve: ?int}
     */
    public function readLive(): array;

    /**
     * Setzt den Sollwert (in °C). Der Treiber clampt selbst auf seinen zulaessigen
     * Bereich. Semantik je scheduleMode:
     *   'device'     => temporaeres Override-Fenster
     *   'controller' => laufender Sollwert (von der ScheduleEngine getrieben)
     *
     * @return bool true bei erfolgreichem Schreiben, sonst false (nie die()/throw
     *              nach oben — Fehler werden geloggt und als false gemeldet).
     */
    public function setSetpoint(float $c): bool;

    /**
     * Betriebsmodus setzen (nur wenn capabilities()['hasMode']).
     * @param string $mode auto|manual|boost|frost
     */
    public function setMode(string $mode): void;

    // --- NUR bei scheduleMode == 'device' (Geraet fuehrt das Wochenprofil) ---

    /**
     * Liest das Wochenprofil VOM GERAET zurueck (Migrations-Wahrheit, G1).
     * @param int|null $presenceIndex 0..deviceProfiles-1 (2. Achse Praesenz) oder null = aktives
     * @return array 7 Tage x Slots [ dayIndex => [ ['end'=>int-Minuten,'val'=>float], ... ] ]
     */
    public function readWeekProfile(?int $presenceIndex = null): array;

    /**
     * Schreibt ein Wochenprofil ins Geraet (z. B. -> HMXML_setTempProfile).
     * Vor dem Schreiben clampen/rastern; nie die() — bool + Log.
     * @param array    $week          gleiche Struktur wie readWeekProfile()
     * @param int|null $presenceIndex 0..deviceProfiles-1 oder null = aktives
     */
    public function writeWeekProfile(array $week, ?int $presenceIndex = null): bool;

    /**
     * Waehlt am Geraet das Wochenprofil der Praesenz (0..n). Geraete mit nur einem
     * Profil melden true, ohne etwas zu tun - dort ist die Wahl ein Uebertragen.
     */
    public function selectProfile(int $presenceIndex): bool;

    /** Welches Profil fuehrt das Geraet gerade? null = unbekannt/kein Profilgeraet. */
    public function activeProfile(): ?int;
}

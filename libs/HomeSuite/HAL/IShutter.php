<?php

declare(strict_types=1);

namespace Hoep\HomeSuite\HAL;

/**
 * IShutter — HAL fuer Beschattungs-/Rollo-Aktoren (§3, §4.2).
 *
 * POSITIONS-KONVENTION (FIX): 0 = offen/oben, 100 = zu/unten.
 *
 * UNKNOWN-ZUSTAND (Blocker E): Nach Cutover/Neustart oder bei Aktoren ohne
 * Positions-Feedback ist die Position POS_UNKNOWN. In diesem Zustand:
 *   - moveTo() VERWEIGERT (kein Anfahren einer Absolutposition ohne bekannte Ist-Lage),
 *   - nur relatives move(up|down|stop) ist erlaubt,
 *   - Kalibrierung ausschliesslich per operator-kommandiertem, wetter-gegatetem
 *     referenceRun() — NIEMALS automatisch beim Cutover.
 *
 * capabilities() liefert (mindestens):
 *   [ 'positionFeedback' => bool,   // meldet der Aktor seine Ist-Position?
 *     'absolutePosition' => bool,   // kann direkt eine %-Position angefahren werden?
 *     'slat'             => bool,   // Lamellen/Wendung vorhanden
 *     'shadowingType'    => 0|1|2 ] // 0=nur Fahren, 1=Position, 2=Position+Lamelle
 */
interface IShutter extends IDriver
{
    /** Unbekannte Position (kein Feedback / nach Cutover). */
    public const POS_UNKNOWN = -1;

    /** @return array caps-Array (siehe Klassen-Doc). */
    public function capabilities(): array;

    /**
     * Faehrt eine Absolutposition an (0=offen/oben..100=zu/unten).
     * VERWEIGERT (return false + Log), solange readPosition() == POS_UNKNOWN.
     */
    public function moveTo(float $percent): bool;

    /**
     * Relatives Fahren — als EINZIGES bei UNKNOWN erlaubt.
     * @param string $dir up|down|stop
     */
    public function move(string $dir): bool;

    /** Aktuelle Ist-Position in % oder POS_UNKNOWN, wenn kein Feedback vorliegt. */
    public function readPosition(): int;

    /**
     * Referenzfahrt zur Kalibrierung — NUR auf OPERATOR-Kommando, wetter-gegatet
     * (Blocker E). Nie Teil des automatischen Cutover.
     * @param string $dir up|down (Anfahren eines mechanischen Endanschlags)
     */
    public function referenceRun(string $dir): bool;

    /** Lamellen-/Wendungsposition setzen (0..100), sofern capabilities()['slat']. */
    public function setSlat(float $percent): bool;
}

<?php

declare(strict_types=1);

namespace Hoep\HomeSuite\HAL;

/**
 * IClimate — HAL fuer Klimageraete (Kuehlen/Heizen/Entfeuchten).
 *
 * Zwei sehr verschiedene Anbindungen liegen darunter: Toshiba spricht ueber die
 * Hersteller-Cloud und einen MQTT-Kanal, Tado ueber ein fremdes Symcon-Modul.
 * Das Interface haelt beide auf Abstand — kernel-frei und zustandslos wie die
 * uebrigen HALs (Vertrag 3, B1).
 *
 * Aufzaehlungen sind bewusst SPRECHENDE Kleinschreibung ('cool', 'quiet') statt
 * Herstellerbytes. Die Uebersetzung in 0x42 oder in einen Tado-JSON-Block ist
 * Sache des Treibers; nur er kennt sein Geraet.
 *
 * Setter geben true zurueck, wenn der Befehl abgesetzt WURDE - nicht, dass das
 * Geraet ihn schon ausgefuehrt hat. Die Bestaetigung kommt beim naechsten
 * readState(); bei Toshiba dauert das rund fuenf Sekunden.
 */
interface IClimate extends IDriver
{
    /** @return array caps-Array (ClimateCapabilities::toArray()). */
    public function capabilities(): array;

    /** Ein- oder ausschalten. */
    public function setPower(bool $on): bool;

    /** Betriebsart setzen: auto|cool|heat|dry|fan. */
    public function setMode(string $mode): bool;

    /** Solltemperatur in Grad C setzen. */
    public function setTarget(float $celsius): bool;

    /** Luefterstufe setzen: quiet|low|medium|high|auto (je nach capabilities). */
    public function setFan(string $fan): bool;

    /** Schwenken setzen: off|vertical|horizontal|both|fix1..fix5. */
    public function setSwing(string $swing): bool;

    /** Sonderfunktion setzen: off|eco|highpower|silent|comfort|sleep|floor|frost. */
    public function setPreset(string $preset): bool;

    /** Ionisierung/Luftreinigung schalten. */
    public function setIon(bool $on): bool;

    /** Aggregierter Ist-Zustand. */
    public function readState(): ClimateState;
}

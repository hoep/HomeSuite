<?php

declare(strict_types=1);

namespace Hoep\HomeSuite\HAL;

/**
 * IMower — HAL fuer Maehroboter (Husqvarna Automower u. a.).
 *
 * GRUNDSATZ (B1, wie IDriver): kernel-frei und zustandslos. Der Treiber kennt
 * nur das Vendor-Protokoll (dss-App-API bzw. offizielle Connect-API); Kommandos
 * werden ueber den bei bind() uebergebenen $send-Callback rausgeschoben, roher
 * Status kommt ueber readState()/parseMowerEvent() als NORMALISIERTER Zustand
 * zurueck. Poll-Kadenz, Socket, Reconnect und armed-Gate besitzt das Modul.
 *
 * NORMALISIERUNG ist der Kern: alle Treiber (app-api, connect-official,
 * generic-mower) liefern dasselbe readState()-Schema, damit MowerDevice
 * treiberagnostisch bleibt. Aktivitaets-/Status-Codes folgen den bekannten
 * Automower-Enums (activity 0..7, state 0..11, mode).
 */
interface IMower extends IDriver
{
    /**
     * @return array caps-Array, u. a.:
     *   scheduleMode ('device'|'ips'), hasBattery, hasWorkAreas,
     *   canConfirmError, hasHeadlight, hasCuttingHeight, realtime (bool).
     */
    public function capabilities(): array;

    /** Maehen starten. $minutes=0 = Standard/„jetzt", sonst befristeter Override. */
    public function start(int $minutes = 0): bool;

    /**
     * Parken. $mode: 'nextSchedule' | 'furtherNotice' | 'duration'.
     * Bei 'duration' zaehlt $minutes.
     */
    public function park(string $mode = 'nextSchedule', int $minutes = 0): bool;

    /** ECHTES Pause (haelt an, bleibt stehen) — nicht als Start missbrauchen. */
    public function pause(): bool;

    /** Zeitplan wieder aufnehmen (ResumeSchedule). */
    public function resume(): bool;

    /** Bestaetigt einen quittierbaren Fehler (sofern capability canConfirmError). */
    public function confirmError(): bool;

    /** Schnitthoehe setzen (Stufe 1..9), sofern unterstuetzt. */
    public function setCuttingHeight(int $level): bool;

    /** Scheinwerfer-Modus: ALWAYS_ON|ALWAYS_OFF|EVENING_ONLY|EVENING_AND_NIGHT. */
    public function setHeadlight(string $mode): bool;

    /**
     * Wochenplan setzen. $tasks: Liste von
     * ['start'=>Min ab Mitternacht,'duration'=>Min,'monday'=>bool,...,'sunday'=>bool].
     * $workAreaId optional (Mehrflaechen-Maeher).
     */
    public function setSchedule(array $tasks, ?int $workAreaId = null): bool;

    /**
     * NORMALISIERTER Ist-Zustand (best effort, ein Poll-Zyklus). Schema u. a.:
     *   activity(int), state(int), mode(int), battery(?int %),
     *   online(?bool), inChargingStation(?bool), errorCode(?int),
     *   errorConfirmable(?bool), nextStart(?int unix-s),
     *   positions(array{lat,lng,gpsStatus}), statistics(array), lastUpdate(int).
     */
    public function readState(): array;

    /** true=maeht, false=nicht, null=unbekannt. */
    public function isMowing(): ?bool;

    /**
     * Normalisierte Mäh-Timer [{start,duration,days{monday..sunday},missionId}].
     * @return array<int,array>
     */
    public function getTimers(): array;

    /**
     * Zielzustand der Timer setzen (Diff gegen Ist: fehlende anlegen, überzählige löschen).
     * @param array<int,array> $desired
     */
    public function setTimers(array $desired): bool;

    /** Klingennutzungszeit zurücksetzen (nach Messerwechsel). */
    public function resetCuttingBlade(): bool;

    /** Arbeitsbereich aktualisieren (Schnitthöhe/aktiv). */
    public function updateWorkArea(string $workAreaId, ?int $cuttingHeight = null, ?bool $enabled = null): bool;

    /** Sperrzone aktivieren/deaktivieren. */
    public function updateStayOutZone(string $stayOutId, bool $enabled): bool;

    /**
     * PURE: rohes Realtime-Event (nur Treiber mit realtime-capability) zu einem
     * Delta-Zustand parsen — sonst leeres Array. Ohne Seiteneffekt/Kernel.
     */
    public function parseMowerEvent(array $msg): array;
}

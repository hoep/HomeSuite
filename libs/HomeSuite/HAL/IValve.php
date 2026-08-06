<?php

declare(strict_types=1);

namespace Hoep\HomeSuite\HAL;

/**
 * IValve — HAL fuer Bewaesserungs-/Ventil-Aktoren (§3, §4.4).
 *
 * Bewusst schmal gehalten: auf/zu, Ist-Zustand, zeitlich begrenzter Puls
 * (max. Laufzeit hart klemmbar durch die Domaene) und optionaler Durchfluss.
 * Die sequenzielle Ausfuehrung mehrerer Kreise (max-concurrency 1, non-blocking
 * Run-Queue, Blocker J) organisiert das Modul, NICHT der Treiber.
 */
interface IValve extends IDriver
{
    /** @return array caps-Array. */
    public function capabilities(): array;

    /** Ventil oeffnen. */
    public function open(): bool;

    /** Ventil schliessen. */
    public function close(): bool;

    /** true=offen, false=zu, null=unbekannt. */
    public function isOpen(): ?bool;

    /**
     * Zeitlich begrenzter Puls: oeffnet fuer $seconds und schliesst dann.
     * Die maximale Laufzeit wird von der Domaene erzwungen.
     */
    public function pulse(int $seconds): bool;

    /** Aktueller Durchfluss (l/min) oder null, wenn nicht messbar. */
    public function flow(): ?float;
}

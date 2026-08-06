<?php

declare(strict_types=1);

namespace Hoep\HomeSuite\Migration;

/**
 * ActuatorGate — LOKALE Safety-Wahrheit je physischem Aktor (Blocker A/I).
 *
 * Jeder reale Schreibbefehl an einen Aktor laeuft durch genau EIN Gate. Der Modus
 * (SUSPEND/SHADOW/LIVE) ist die einzige Freigabequelle fuer einen Fahrbefehl —
 * NICHT das Ledger (das ist reine Orchestrierungssicht, Blocker I).
 *
 * FAIL-SAFE: Ist der Zustand nicht lesbar/ungueltig, faellt mode() auf SUSPEND
 * (kein Schreiben). Der Zustand liegt LOKAL (hier: als kleine Datei je Aktor unter
 * dem Kernel-Datenverzeichnis; das Modul spiegelt ihn zusaetzlich in sein Attribut).
 *
 * SEMAPHOR-NAMENSRAUM (Blocker A): $semName ist waehrend Schatten-/Parallelbetrieb
 * DERSELBE Name wie beim Altregler (z. B. 'IPSShadowing_Refresh'). Solange auch nur
 * ein Geraet der Domaene nicht 'retired' ist, serialisiert das Gate LIVE-Schreib-
 * befehle ueber genau diesen Alt-Semaphor -> keine Doppelfahrt Alt/Neu. Ein
 * eigener Namensraum erst nach retire der ganzen Domaene.
 *
 * Modi:
 *   SUSPEND (0)  kein realer Schreibbefehl (Migrations-Ruhe / fail-safe)
 *   SHADOW  (1)  Schattenbetrieb: realer Schreibbefehl UNTERDRUECKT (nur Entscheidung protokollieren)
 *   LIVE    (2)  realer Schreibbefehl, serialisiert ueber $semName
 */
final class ActuatorGate
{
    public const SUSPEND = 0;
    public const SHADOW  = 1;
    public const LIVE    = 2;

    /** Non-blocking-Timeout fuer den Alt-Semaphor (ms) — nie den Timer/Hook blockieren (Blocker J). */
    private const SEM_TIMEOUT_MS = 1000;

    private int $actuatorId;
    private string $semName;

    public function __construct(int $actuatorId, string $semName)
    {
        $this->actuatorId = $actuatorId;
        $this->semName    = $semName;
    }

    /** Aktueller Modus; FAIL-SAFE SUSPEND bei unlesbarem/ungueltigem Zustand. */
    public function mode(): int
    {
        try {
            $file = $this->modeFile();
            if (!is_file($file)) {
                return self::SUSPEND; // nie gesetzt -> sicherer Default
            }
            $raw = @file_get_contents($file);
            if ($raw === false) {
                return self::SUSPEND;
            }
            $v = (int) trim($raw);
            if ($v === self::SUSPEND || $v === self::SHADOW || $v === self::LIVE) {
                return $v;
            }
            return self::SUSPEND;
        } catch (\Throwable $e) {
            return self::SUSPEND;
        }
    }

    /** Setzt den Modus (persistiert lokal). */
    public function setMode(int $m): void
    {
        if ($m !== self::SUSPEND && $m !== self::SHADOW && $m !== self::LIVE) {
            throw new \InvalidArgumentException('Ungueltiger Gate-Modus: ' . $m);
        }
        $file = $this->modeFile();
        $dir  = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        if (@file_put_contents($file, (string) $m, LOCK_EX) === false) {
            $this->log('setMode: konnte Zustand nicht schreiben (' . $file . ')');
        }
    }

    /**
     * Fuehrt den realen Schreibbefehl $doWrite aus — aber NUR bei LIVE und
     * serialisiert ueber $semName. Liefert false (+ Log) bei SUSPEND, SHADOW oder
     * Semaphor-Timeout; der verworfene Befehl wird protokolliert.
     */
    public function write(callable $doWrite): bool
    {
        $m = $this->mode();

        if ($m === self::SUSPEND) {
            $this->log('SUSPEND: Schreibbefehl an #' . $this->actuatorId . ' verworfen');
            return false;
        }
        if ($m === self::SHADOW) {
            $this->log('SHADOW: realer Schreibbefehl an #' . $this->actuatorId . ' unterdrueckt');
            return false;
        }

        // LIVE: ueber den (Alt-)Semaphor-Namensraum serialisieren.
        if (function_exists('IPS_SemaphoreEnter')) {
            if (!@\IPS_SemaphoreEnter($this->semName, self::SEM_TIMEOUT_MS)) {
                $this->log('Semaphor-Timeout "' . $this->semName . '" -> Befehl verworfen');
                return false;
            }
            try {
                $doWrite();
            } catch (\Throwable $e) {
                $this->log('write #' . $this->actuatorId . ': ' . $e->getMessage());
                @\IPS_SemaphoreLeave($this->semName);
                return false;
            }
            @\IPS_SemaphoreLeave($this->semName);
            return true;
        }

        // Standalone/Test (kein IPS-Semaphor verfuegbar): direkt ausfuehren.
        try {
            $doWrite();
            return true;
        } catch (\Throwable $e) {
            $this->log('write #' . $this->actuatorId . ': ' . $e->getMessage());
            return false;
        }
    }

    // ----------------------------------------------------------------------

    private function modeFile(): string
    {
        $base = function_exists('IPS_GetKernelDir') ? rtrim((string) \IPS_GetKernelDir(), '/\\') : sys_get_temp_dir();
        return $base . '/homesuite/gates/gate_' . $this->actuatorId . '.mode';
    }

    private function log(string $msg): void
    {
        if (function_exists('IPS_LogMessage')) {
            @\IPS_LogMessage('HS.Gate', $msg);
        } else {
            error_log('HS.Gate: ' . $msg);
        }
    }
}

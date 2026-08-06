<?php

declare(strict_types=1);

namespace Hoep\HomeSuite\HAL;

/**
 * GenericVariableValve — generischer, VENDOR-FREIER Ventil-/Relais-Treiber.
 *
 * Bindet an eine Schalt-Variable (idealerweise mit EnableAction) und optional an
 * eine Durchfluss-Variable -> beliebiges Relais/Ventil ohne Vendor-Code.
 *
 * Konfiguration (cfg / bind):
 *   switchVarId  int   Schalt-Variable (bool; true=offen)
 *   flowVarId    int?  Durchfluss-Variable (l/min)
 *   invert       bool? falls true=zu
 *
 * pulse() ist bewusst NICHT-blockierend umgesetzt: der Treiber oeffnet nur und
 * ueberlaesst das getimte Schliessen der Run-Queue/dem Timer des Moduls
 * (Blocker J — kein blockierendes sleep()/Semaphor im Treiber). Als Fallback
 * plant er, sofern verfuegbar, einen einmaligen Kernel-Timer per IPS_RunScriptTimer
 * NICHT selbst — die maximale Laufzeit erzwingt die Domaene.
 */
final class GenericVariableValve implements IValve
{
    private array $cfg = [];
    /** @var callable */
    private $send;

    public function __construct()
    {
        $this->send = static function ($x): void {
        };
    }

    public function bind(array $config, callable $send): void
    {
        $this->cfg  = $config;
        $this->send = $send;
    }

    public function capabilities(): array
    {
        return [
            'hasFlow' => ((int) ($this->cfg['flowVarId'] ?? 0)) > 0,
            'pulse'   => true,
        ];
    }

    public static function discover(int $timeoutMs = 2000): array
    {
        return [];
    }

    public function poll(): array
    {
        return [];
    }

    public function parseEvent(string $raw): ?AudioState
    {
        return null;
    }

    public function open(): bool
    {
        return $this->writeSwitch(true);
    }

    public function close(): bool
    {
        return $this->writeSwitch(false);
    }

    public function isOpen(): ?bool
    {
        $vid = (int) ($this->cfg['switchVarId'] ?? 0);
        if ($vid <= 0 || !function_exists('GetValue')) {
            return null;
        }
        $val = @\GetValue($vid);
        if (!is_bool($val) && !is_numeric($val)) {
            return null;
        }
        $on = (bool) $val;
        return (bool) ($this->cfg['invert'] ?? false) ? !$on : $on;
    }

    /**
     * Oeffnet das Ventil. Das getimte Schliessen nach $seconds ist NICHT Sache des
     * Treibers (non-blocking, Blocker J) — die Domaenen-Run-Queue schliesst wieder.
     * $seconds wird nur zur Nachvollziehbarkeit protokolliert.
     */
    public function pulse(int $seconds): bool
    {
        if ($seconds <= 0) {
            return false;
        }
        $this->log('pulse: open fuer ' . $seconds . 's (Schliessen uebernimmt die Run-Queue)');
        return $this->open();
    }

    public function flow(): ?float
    {
        $vid = (int) ($this->cfg['flowVarId'] ?? 0);
        if ($vid <= 0 || !function_exists('GetValue')) {
            return null;
        }
        $val = @\GetValue($vid);
        return is_numeric($val) ? (float) $val : null;
    }

    // ----------------------------------------------------------------------

    private function writeSwitch(bool $open): bool
    {
        $vid = (int) ($this->cfg['switchVarId'] ?? 0);
        if ($vid <= 0) {
            $this->log('writeSwitch: keine switchVarId konfiguriert');
            return false;
        }
        $value = (bool) ($this->cfg['invert'] ?? false) ? !$open : $open;
        try {
            if ($this->isActionable($vid) && function_exists('RequestAction')) {
                @\RequestAction($vid, $value);
                return true;
            }
            if (function_exists('SetValue')) {
                @\SetValue($vid, $value);
                return true;
            }
        } catch (\Throwable $e) {
            $this->log('writeSwitch #' . $vid . ': ' . $e->getMessage());
            return false;
        }
        return false;
    }

    private function isActionable(int $vid): bool
    {
        if (!function_exists('IPS_GetVariable')) {
            return false;
        }
        $v = @\IPS_GetVariable($vid);
        if (!is_array($v)) {
            return false;
        }
        return (int) ($v['VariableAction'] ?? 0) > 0
            || (int) ($v['VariableCustomAction'] ?? 0) > 0;
    }

    private function log(string $msg): void
    {
        if (function_exists('IPS_LogMessage')) {
            @\IPS_LogMessage('HS.GenValve', $msg);
        }
    }
}

<?php

declare(strict_types=1);

namespace Hoep\HomeSuite\HAL;

/**
 * GenericVariableValve — generischer, VENDOR-FREIER Ventil-/Bewaesserungs-Treiber.
 *
 * UNIVERSELL (Nutzer-Vorgabe): deckt beliebige Aktoren ab, LinkTap ist nur ein Fall.
 * Drei Modi, je Kreis frei waehlbar — IMMER Variable ODER Skript bindbar:
 *
 *   mode='switch'   : bool-Schalt-Variable (switchVarId; true=offen). Das MODUL timt
 *                     die Dauer (open + Timer -> close). Relais/Homematic/Shelly.
 *   mode='duration' : Dauer-Variable (startVarId, Sekunden) — das GERAET timt selbst
 *                     (z. B. LinkTap StartWateringImmediately). Stop via stopVarId
 *                     (bool) oder startVarId=0. Ist-Zustand aus feedbackVarId.
 *   mode='script'   : Start-/Stop-Skript (onScriptId/offScriptId). Optional Dauer in
 *                     durationVarId schreiben, bevor das Start-Skript laeuft.
 *
 * bind()-config:
 *   mode          string  'switch'|'duration'|'script' (Default 'switch')
 *   switchVarId   int     Schalt-Variable (mode switch)
 *   startVarId    int     Dauer-/Start-Variable in Sekunden (mode duration)
 *   stopVarId     int     Stop-Variable (mode duration/optional)
 *   feedbackVarId int     Ist-Zustand (bool; z. B. WateringActive)
 *   flowVarId     int?    Durchfluss (l/min)
 *   onScriptId/offScriptId int  (mode script)
 *   durationVarId int?    Dauer-Ablage fuer das Start-Skript (mode script)
 *   invert        bool?   Schalt-Logik invertieren
 *   stopValue     mixed?  Wert fuer stopVarId (Default true)
 *
 * pulse() ist NICHT-blockierend (Blocker J): mode 'duration'/'script' uebergeben die
 * Dauer an Geraet/Skript (self-timing); mode 'switch' oeffnet nur — das getimte
 * Schliessen macht der Modul-Timer. Kein sleep()/Semaphor im Treiber.
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

    private function mode(): string
    {
        $m = (string) ($this->cfg['mode'] ?? 'switch');
        return in_array($m, ['switch', 'duration', 'script'], true) ? $m : 'switch';
    }

    public function capabilities(): array
    {
        $m = $this->mode();
        return [
            'mode'       => $m,
            'selfTiming' => ($m === 'duration' || $m === 'script'), // Geraet/Skript timt selbst
            'hasFlow'    => ((int) ($this->cfg['flowVarId'] ?? 0)) > 0,
            'pulse'      => true,
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

    /** Oeffnen. mode switch: bool an. mode script: Start-Skript. mode duration: nur mit Dauer (pulse). */
    public function open(): bool
    {
        switch ($this->mode()) {
            case 'switch':
                return $this->writeSwitch(true);
            case 'script':
                return $this->runScript((int) ($this->cfg['onScriptId'] ?? 0));
            case 'duration':
                $this->log('open() im duration-Modus ohne Dauer — nutze pulse(sekunden)');
                return false;
        }
        return false;
    }

    public function close(): bool
    {
        switch ($this->mode()) {
            case 'switch':
                return $this->writeSwitch(false);
            case 'script':
                return $this->runScript((int) ($this->cfg['offScriptId'] ?? 0));
            case 'duration':
                $stop = (int) ($this->cfg['stopVarId'] ?? 0);
                if ($stop > 0) {
                    return $this->writeVar($stop, $this->cfg['stopValue'] ?? true);
                }
                $start = (int) ($this->cfg['startVarId'] ?? 0);
                return $start > 0 ? $this->writeVar($start, 0) : false;
        }
        return false;
    }

    public function isOpen(): ?bool
    {
        $fb = (int) ($this->cfg['feedbackVarId'] ?? 0);
        if ($fb <= 0 && $this->mode() === 'switch') {
            $fb = (int) ($this->cfg['switchVarId'] ?? 0);
        }
        if ($fb <= 0 || !function_exists('GetValue')) {
            return null;
        }
        $val = @\GetValue($fb);
        if (!is_bool($val) && !is_numeric($val)) {
            return null;
        }
        $on = (bool) $val;
        return (bool) ($this->cfg['invert'] ?? false) ? !$on : $on;
    }

    /**
     * Zeitlich begrenzter Puls ($seconds). mode duration: Sekunden ans Geraet (self-timing).
     * mode script: Dauer optional in durationVarId, dann Start-Skript. mode switch: nur oeffnen
     * (Schliessen macht der Modul-Timer).
     */
    public function pulse(int $seconds): bool
    {
        if ($seconds <= 0) {
            return false;
        }
        switch ($this->mode()) {
            case 'duration':
                $start = (int) ($this->cfg['startVarId'] ?? 0);
                if ($start <= 0) {
                    $this->log('pulse: keine startVarId (duration-Modus)');
                    return false;
                }
                return $this->writeVar($start, $seconds);
            case 'script':
                $dv = (int) ($this->cfg['durationVarId'] ?? 0);
                if ($dv > 0) {
                    $this->writeVar($dv, $seconds);
                }
                return $this->runScript((int) ($this->cfg['onScriptId'] ?? 0));
            case 'switch':
            default:
                $this->log('pulse: open fuer ' . $seconds . 's (Schliessen uebernimmt der Modul-Timer)');
                return $this->writeSwitch(true);
        }
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
        return $this->writeVar($vid, $value);
    }

    /** Schreibt einen Wert per RequestAction (falls actionable), sonst SetValue. */
    private function writeVar(int $vid, $value): bool
    {
        if ($vid <= 0) {
            return false;
        }
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
            $this->log('writeVar #' . $vid . ': ' . $e->getMessage());
            return false;
        }
        return false;
    }

    private function runScript(int $sid): bool
    {
        if ($sid <= 0 || !function_exists('IPS_RunScript') || !@\IPS_ScriptExists($sid)) {
            $this->log('runScript: Skript #' . $sid . ' fehlt');
            return false;
        }
        try {
            @\IPS_RunScript($sid);
            return true;
        } catch (\Throwable $e) {
            $this->log('runScript #' . $sid . ': ' . $e->getMessage());
            return false;
        }
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

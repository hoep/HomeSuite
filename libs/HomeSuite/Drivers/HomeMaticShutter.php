<?php

declare(strict_types=1);

namespace Hoep\HomeSuite\HAL;

/**
 * HomeMaticShutter — HAL-Treiber fuer Homematic-Rollo/Markise-Aktoren (LEVEL-Datenpunkt),
 * bisher via IPSComponentShutter_Homematic aus IPSShadowing. Deckt die Markise (#<ID>) ab.
 *
 * LEVEL ist eine aktionsfaehige Float-Variable 0..1 (Profil ~Intensity.1):
 * LEVEL 1.0 = ganz OFFEN, 0.0 = ganz ZU. Die HomeSuite-Konvention ist umgekehrt
 * skaliert (0=offen/oben .. 100=zu/unten), daher `invert` (Default true):
 *   moveTo(pct): LEVEL = invert ? (1 - pct/100) : (pct/100)
 *   readPosition: pct = round((invert ? 1-LEVEL : LEVEL) * 100)
 *
 * ABSOLUT + FEEDBACK: der Aktor faehrt LEVEL selbst an und meldet die Ist-Lage ->
 * capabilities absolutePosition/positionFeedback = true (keine Zeit-Kinematik noetig).
 * STOP nutzt den HM-STOP-Datenpunkt (HM_WriteValueBoolean), falls verfuegbar.
 *
 * config (bind):
 *   'levelVarId'  int   Objekt-ID der LEVEL-Variable (Ziel & Rueckmeldung), aktionsfaehig
 *   'instanceId'  int   Homematic-Instanz (fuer den STOP-Datenpunkt), optional
 *   'invert'      bool  LEVEL-Konvention (Default true: LEVEL 1 = offen)
 */
final class HomeMaticShutter implements IShutter
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
            'positionFeedback' => true,
            'absolutePosition' => true,
            'slat'             => false,
            'shadowingType'    => 1,
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

    private function levelVid(): int
    {
        return (int) ($this->cfg['levelVarId'] ?? 0);
    }

    private function invert(): bool
    {
        return (bool) ($this->cfg['invert'] ?? true);
    }

    public function readPosition(): int
    {
        $vid = $this->levelVid();
        if ($vid <= 0 || !function_exists('GetValue')) {
            return IShutter::POS_UNKNOWN;
        }
        $lv = @\GetValue($vid);
        if (!is_numeric($lv)) {
            return IShutter::POS_UNKNOWN;
        }
        $lv  = max(0.0, min(1.0, (float) $lv)); // LEVEL 0..1
        $pos = $this->invert() ? (1.0 - $lv) : $lv; // 0=offen .. 1=zu
        return max(0, min(100, (int) round($pos * 100)));
    }

    public function moveTo(float $percent): bool
    {
        $vid = $this->levelVid();
        if ($vid <= 0) {
            return false;
        }
        $p     = max(0.0, min(100.0, $percent)) / 100.0; // 0=offen .. 1=zu
        $level = $this->invert() ? (1.0 - $p) : $p;       // LEVEL 1=offen
        return $this->write($vid, round($level, 3));
    }

    public function move(string $dir): bool
    {
        $dir = strtolower($dir);
        if ($dir === 'up') {
            return $this->moveTo(0.0);   // offen
        }
        if ($dir === 'down') {
            return $this->moveTo(100.0); // zu
        }
        if ($dir === 'stop') {
            $iid = (int) ($this->cfg['instanceId'] ?? 0);
            if ($iid > 0 && function_exists('HM_WriteValueBoolean')) {
                @\HM_WriteValueBoolean($iid, 'STOP', true);
                return true;
            }
            return false;
        }
        return false;
    }

    public function referenceRun(string $dir): bool
    {
        $dir = strtolower($dir);
        return ($dir === 'up' || $dir === 'down') ? $this->move($dir) : false;
    }

    public function setSlat(float $percent): bool
    {
        return false;
    }

    /** Bevorzugt RequestAction (LEVEL ist aktionsfaehig), sonst SetValue. */
    private function write(int $vid, float $level): bool
    {
        try {
            $v = function_exists('IPS_GetVariable') ? @\IPS_GetVariable($vid) : null;
            $actionable = is_array($v)
                && (((int) ($v['VariableAction'] ?? 0) > 0) || ((int) ($v['VariableCustomAction'] ?? 0) > 0));
            if ($actionable && function_exists('RequestAction')) {
                @\RequestAction($vid, $level);
                return true;
            }
            if (function_exists('SetValue')) {
                @\SetValue($vid, $level);
                return true;
            }
        } catch (\Throwable $e) {
            if (function_exists('IPS_LogMessage')) {
                @\IPS_LogMessage('HS.HmShutter', 'write #' . $vid . ': ' . $e->getMessage());
            }
        }
        return false;
    }
}

// Vendor-Selbstregistrierung bei der DriverFactory (§2.2.5).
DriverFactory::register('hm-shutter', HomeMaticShutter::class);

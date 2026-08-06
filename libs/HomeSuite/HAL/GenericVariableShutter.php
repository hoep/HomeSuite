<?php

declare(strict_types=1);

namespace Hoep\HomeSuite\HAL;

/**
 * GenericVariableShutter — generischer, VENDOR-FREIER Rollo-/Beschattungs-Treiber.
 *
 * Bindet an vom Nutzer im LVB zugeordnete Standard-Symcon-Variablen und erfuellt
 * damit dasselbe IShutter-Interface wie Spezial-Treiber -> beliebige Rollo-Aktoren.
 *
 * Konfiguration (cfg / bind) — zwei Betriebsarten:
 *   A) Absolutposition:  positionVarId (idealerweise mit EnableAction), optional feedbackVarId
 *   B) Nur Fahren:        upVarId / downVarId / stopVarId (bool-/Trigger-Variablen)
 *   optional: feedbackVarId (Ist-Position %), slatVarId (Lamelle %)
 *   optional: invert (bool) — falls der Aktor 0=zu/100=offen zaehlt (intern normalisiert)
 *
 * POSITIONS-KONVENTION (FIX): 0 = offen/oben, 100 = zu/unten.
 *
 * UNKNOWN-Zustand (Blocker E): Ohne feedbackVarId UND ohne absolute positionVarId
 * ist die Ist-Position POS_UNKNOWN. Dann verweigert moveTo(); nur move(up|down|stop)
 * ist erlaubt; Kalibrierung ausschliesslich per operator-kommandiertem referenceRun().
 *
 * ZUSTANDSLOS: keine getimte Fahr-Logik im Treiber. Timer-basierte Kinematik
 * (ShadeKinematics::steps) organisiert das Modul, nicht der Treiber.
 */
final class GenericVariableShutter implements IShutter
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
        $hasPos   = ((int) ($this->cfg['positionVarId'] ?? 0)) > 0;
        $hasFb    = ((int) ($this->cfg['feedbackVarId'] ?? 0)) > 0;
        $absolute = $hasPos && (bool) ($this->cfg['absolutePosition'] ?? true);
        $slat     = ((int) ($this->cfg['slatVarId'] ?? 0)) > 0;

        $type = 0;                       // 0=nur Fahren
        if ($absolute || $hasFb) {
            $type = $slat ? 2 : 1;       // 1=Position, 2=Position+Lamelle
        }

        return [
            'positionFeedback' => $hasFb || $absolute,
            'absolutePosition' => $absolute,
            'slat'             => $slat,
            'shadowingType'    => $type,
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

    public function readPosition(): int
    {
        // Feedback hat Vorrang; sonst dient eine absolute Position-Variable als Wahrheit.
        $fb = (int) ($this->cfg['feedbackVarId'] ?? 0);
        if ($fb > 0) {
            $v = $this->readNum($fb);
            if ($v !== null) {
                return $this->normalize((int) round($v));
            }
        }
        $pos = (int) ($this->cfg['positionVarId'] ?? 0);
        if ($pos > 0 && (bool) ($this->cfg['absolutePosition'] ?? true)) {
            $v = $this->readNum($pos);
            if ($v !== null) {
                return $this->normalize((int) round($v));
            }
        }
        return IShutter::POS_UNKNOWN;
    }

    public function moveTo(float $percent): bool
    {
        // Blocker E: kein Anfahren einer Absolutposition ohne bekannte Ist-Lage.
        if ($this->readPosition() === IShutter::POS_UNKNOWN) {
            $this->log('moveTo verweigert: Position UNKNOWN (nur move erlaubt)');
            return false;
        }
        $pos = (int) ($this->cfg['positionVarId'] ?? 0);
        if ($pos <= 0 || !(bool) ($this->cfg['absolutePosition'] ?? true)) {
            $this->log('moveTo: kein absoluter positionVarId gebunden');
            return false;
        }
        $p = $this->clampPct($percent);
        return $this->writeVar($pos, $this->normalize((int) round($p)));
    }

    public function move(string $dir): bool
    {
        $dir = strtolower($dir);

        // Bevorzugt dedizierte up/down/stop-Variablen.
        $map = ['up' => 'upVarId', 'down' => 'downVarId', 'stop' => 'stopVarId'];
        if (isset($map[$dir])) {
            $vid = (int) ($this->cfg[$map[$dir]] ?? 0);
            if ($vid > 0) {
                return $this->writeVar($vid, true);
            }
        }

        // Fallback ueber eine Positionsvariable: up=offen(0), down=zu(100).
        $pos = (int) ($this->cfg['positionVarId'] ?? 0);
        if ($pos > 0) {
            if ($dir === 'up') {
                return $this->writeVar($pos, $this->normalize(0));
            }
            if ($dir === 'down') {
                return $this->writeVar($pos, $this->normalize(100));
            }
        }

        $this->log('move(' . $dir . '): keine passende Variable gebunden');
        return false;
    }

    public function referenceRun(string $dir): bool
    {
        // Nur auf OPERATOR-Kommando (Wetter-Gate liegt beim Modul). Der Treiber
        // faehrt schlicht bis zum mechanischen Endanschlag in Richtung $dir.
        $dir = strtolower($dir);
        if ($dir !== 'up' && $dir !== 'down') {
            return false;
        }
        return $this->move($dir);
    }

    public function setSlat(float $percent): bool
    {
        $vid = (int) ($this->cfg['slatVarId'] ?? 0);
        if ($vid <= 0) {
            return false;
        }
        return $this->writeVar($vid, $this->clampPct($percent));
    }

    // ----------------------------------------------------------------------

    /** Beruecksichtigt eine ggf. invertierte Aktor-Zaehlweise (0=zu/100=offen). */
    private function normalize(int $pct): int
    {
        $pct = max(0, min(100, $pct));
        return (bool) ($this->cfg['invert'] ?? false) ? (100 - $pct) : $pct;
    }

    private function clampPct(float $p): float
    {
        return max(0.0, min(100.0, $p));
    }

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

    private function readNum(int $vid): ?float
    {
        if ($vid <= 0 || !function_exists('GetValue')) {
            return null;
        }
        $val = @\GetValue($vid);
        return is_numeric($val) ? (float) $val : null;
    }

    private function log(string $msg): void
    {
        if (function_exists('IPS_LogMessage')) {
            @\IPS_LogMessage('HS.GenShutter', $msg);
        }
    }
}

<?php

declare(strict_types=1);

namespace Hoep\HomeSuite\HAL;

/**
 * GenericBoundLight — generischer, VENDOR-FREIER Beleuchtungs-Treiber.
 *
 * UNIVERSELL (Nutzer-Vorgabe): bindet reale Symcon-Variablen ODER Skripte je Kanal.
 * Fähigkeiten ergeben sich daraus, welche Kanäle gebunden sind — genau wie die 39
 * bestehenden LVB-light-Kacheln (Schalter varId + optional Helligkeit varId2), nur
 * um Farbe/CCT und Leistung erweitert.
 *
 * bind()-config:
 *   switchVarId   int    bool-Schaltvariable (An/Aus)
 *   invert        bool?  Schaltlogik invertieren
 *   onScriptId    int?   Ein-Skript (Fallback ohne Schaltvariable)
 *   offScriptId   int?   Aus-Skript
 *   levelVarId    int?   Helligkeitsvariable
 *   levelMax      num    Geräte-Vollwert der Helligkeit (Default 100; z. B. 1.0 oder 255)
 *   colorVarId    int?   Farbvariable
 *   colorFormat   string 'int' (0xRRGGBB, Default) | 'hex' ("#RRGGBB")
 *   cctVarId      int?   Farbtemperatur-Variable
 *   cctFormat     string 'kelvin' (Default) | 'mired' | 'percent' (0..100 warm→kalt)
 *   cctMin,cctMax int    Kelvin-Grenzen (Default 2700/6500) für percent/mired-Abbildung
 *   wattVarId     int?   gemessene Leistung (W)
 *   wattRated     num?   Nennleistung (W) für Schätzung, wenn nicht gemessen
 *   circuit       int?   Stromkreis-Nummer (für Summenbildung)
 *
 * Kernel-frei/zustandslos (Vertrag 3, B1): kein Timer, keine Rampe hier — das macht
 * das Modul / die SceneEngine. Schreiben bevorzugt RequestAction (actionable), sonst SetValue.
 */
final class GenericBoundLight implements ILight
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

    // ---- IDriver ----------------------------------------------------------

    public function capabilities(): array
    {
        $caps = new LightCapabilities(
            $this->hasSwitch(),
            (int) ($this->cfg['levelVarId'] ?? 0) > 0,
            (int) ($this->cfg['colorVarId'] ?? 0) > 0,
            (int) ($this->cfg['cctVarId'] ?? 0) > 0,
            (int) ($this->cfg['cctMin'] ?? 2700),
            (int) ($this->cfg['cctMax'] ?? 6500),
            (int) ($this->cfg['wattVarId'] ?? 0) > 0 || (float) ($this->cfg['wattRated'] ?? 0) > 0,
            (int) ($this->cfg['circuit'] ?? 0)
        );
        return $caps->toArray();
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
        return null; // variablengebunden — keine Event-Frames
    }

    // ---- ILight -----------------------------------------------------------

    public function setPower(bool $on): bool
    {
        $sw = (int) ($this->cfg['switchVarId'] ?? 0);
        if ($sw > 0) {
            $val = (bool) ($this->cfg['invert'] ?? false) ? !$on : $on;
            return $this->writeVar($sw, $val);
        }
        if ($this->hasScripts()) {
            return $this->runScript((int) ($this->cfg[$on ? 'onScriptId' : 'offScriptId'] ?? 0));
        }
        // Nur Dimmer, keine Schaltvariable: Ein=100 %, Aus=0 %.
        if ((int) ($this->cfg['levelVarId'] ?? 0) > 0) {
            return $this->writeLevel($on ? 100 : 0);
        }
        return false;
    }

    public function isOn(): ?bool
    {
        $sw = (int) ($this->cfg['switchVarId'] ?? 0);
        if ($sw > 0) {
            $v = $this->readVal($sw);
            if ($v === null) {
                return null;
            }
            $on = (bool) $v;
            return (bool) ($this->cfg['invert'] ?? false) ? !$on : $on;
        }
        $lvl = $this->getLevel();
        return $lvl === null ? null : ($lvl > 0);
    }

    public function setLevel(int $percent): bool
    {
        $percent = max(0, min(100, $percent));
        $hasSwitch = (int) ($this->cfg['switchVarId'] ?? 0) > 0 || $this->hasScripts();
        // Schalter + Dimmer getrennt (wie die 20 LVB-Dimmer: varId + varId2):
        // beim Aufdimmen erst ein, dann Wert; beim Nullen erst Wert, dann aus.
        if ($percent > 0) {
            if ($hasSwitch) {
                $this->setPower(true);
            }
            return $this->writeLevel($percent);
        }
        $ok = $this->writeLevel(0);
        if ($hasSwitch) {
            $this->setPower(false);
        }
        return $ok;
    }

    public function getLevel(): ?int
    {
        $vid = (int) ($this->cfg['levelVarId'] ?? 0);
        if ($vid <= 0) {
            return null;
        }
        $raw = $this->readNum($vid);
        if ($raw === null) {
            return null;
        }
        $max = (float) ($this->cfg['levelMax'] ?? 100);
        if ($max <= 0) {
            $max = 100;
        }
        return (int) round(max(0.0, min(1.0, $raw / $max)) * 100);
    }

    public function setColor(int $rgb): bool
    {
        $vid = (int) ($this->cfg['colorVarId'] ?? 0);
        if ($vid <= 0) {
            return false;
        }
        $rgb &= 0xFFFFFF;
        $fmt = (string) ($this->cfg['colorFormat'] ?? 'int');
        if ($fmt === 'hex') {
            return $this->writeVar($vid, sprintf('#%06X', $rgb));
        }
        return $this->writeVar($vid, $rgb);
    }

    public function getColor(): ?int
    {
        $vid = (int) ($this->cfg['colorVarId'] ?? 0);
        if ($vid <= 0) {
            return null;
        }
        $v = $this->readVal($vid);
        if ($v === null) {
            return null;
        }
        if (is_string($v)) {
            $hex = ltrim($v, '#');
            return ctype_xdigit($hex) ? (hexdec(substr($hex, 0, 6)) & 0xFFFFFF) : null;
        }
        return is_numeric($v) ? ((int) $v & 0xFFFFFF) : null;
    }

    public function setCct(int $kelvin): bool
    {
        $vid = (int) ($this->cfg['cctVarId'] ?? 0);
        if ($vid <= 0) {
            return false;
        }
        $min = (int) ($this->cfg['cctMin'] ?? 2700);
        $max = (int) ($this->cfg['cctMax'] ?? 6500);
        $kelvin = max($min, min($max, $kelvin));
        switch ((string) ($this->cfg['cctFormat'] ?? 'kelvin')) {
            case 'mired':
                return $this->writeVar($vid, (int) round(1000000 / max(1, $kelvin)));
            case 'percent':
                $span = max(1, $max - $min);
                return $this->writeVar($vid, (int) round(($kelvin - $min) / $span * 100));
            case 'kelvin':
            default:
                return $this->writeVar($vid, $kelvin);
        }
    }

    public function getCct(): ?int
    {
        $vid = (int) ($this->cfg['cctVarId'] ?? 0);
        if ($vid <= 0) {
            return null;
        }
        $raw = $this->readNum($vid);
        if ($raw === null) {
            return null;
        }
        $min = (int) ($this->cfg['cctMin'] ?? 2700);
        $max = (int) ($this->cfg['cctMax'] ?? 6500);
        switch ((string) ($this->cfg['cctFormat'] ?? 'kelvin')) {
            case 'mired':
                return (int) round(1000000 / max(1.0, $raw));
            case 'percent':
                return (int) round($min + max(0.0, min(100.0, $raw)) / 100 * ($max - $min));
            case 'kelvin':
            default:
                return (int) round($raw);
        }
    }

    public function power(): ?float
    {
        $vid = (int) ($this->cfg['wattVarId'] ?? 0);
        if ($vid > 0) {
            $v = $this->readNum($vid);
            if ($v !== null) {
                return (float) $v;
            }
        }
        $rated = (float) ($this->cfg['wattRated'] ?? 0);
        if ($rated <= 0) {
            return null;
        }
        $on = $this->isOn();
        if ($on === null) {
            return null;
        }
        if (!$on) {
            return 0.0;
        }
        $lvl = $this->getLevel();
        return $lvl === null ? $rated : $rated * max(0, min(100, $lvl)) / 100;
    }

    public function readState(): LightState
    {
        $on = $this->isOn();
        $lvl = $this->getLevel();
        $col = $this->getColor();
        $cct = $this->getCct();
        $w   = $this->power();
        $reach = $this->reachable();
        return new LightState(
            $on ?? (($lvl ?? 0) > 0),
            $lvl ?? -1,
            $col ?? -1,
            $cct ?? 0,
            $w ?? -1.0,
            $reach
        );
    }

    // ---- intern -----------------------------------------------------------

    private function hasSwitch(): bool
    {
        return (int) ($this->cfg['switchVarId'] ?? 0) > 0 || $this->hasScripts();
    }

    private function hasScripts(): bool
    {
        return (int) ($this->cfg['onScriptId'] ?? 0) > 0 && (int) ($this->cfg['offScriptId'] ?? 0) > 0;
    }

    /** Primärkanal vorhanden/lesbar? */
    private function reachable(): bool
    {
        foreach (['switchVarId', 'levelVarId', 'colorVarId', 'cctVarId'] as $k) {
            $vid = (int) ($this->cfg[$k] ?? 0);
            if ($vid > 0 && function_exists('IPS_VariableExists') && @\IPS_VariableExists($vid)) {
                return true;
            }
        }
        return $this->hasScripts();
    }

    private function writeLevel(int $percent): bool
    {
        $vid = (int) ($this->cfg['levelVarId'] ?? 0);
        if ($vid <= 0) {
            return false;
        }
        $max = (float) ($this->cfg['levelMax'] ?? 100);
        if ($max <= 0) {
            $max = 100;
        }
        $dev = $max * max(0, min(100, $percent)) / 100;
        // Ganzzahlige Geräte-Skala (100/255) ganzzahlig schreiben, 0..1-Skala als float.
        $value = ($max > 1) ? (int) round($dev) : $dev;
        return $this->writeVar($vid, $value);
    }

    /** Schreibt per RequestAction (falls actionable), sonst SetValue. */
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

    private function readVal(int $vid)
    {
        if ($vid <= 0 || !function_exists('GetValue') || !@\IPS_VariableExists($vid)) {
            return null;
        }
        return @\GetValue($vid);
    }

    private function readNum(int $vid): ?float
    {
        $v = $this->readVal($vid);
        return is_numeric($v) ? (float) $v : null;
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
            @\IPS_LogMessage('HS.GenLight', $msg);
        }
    }
}

<?php

declare(strict_types=1);

namespace Hoep\HomeSuite\HAL;

/**
 * GenericVariableThermostat — generischer, VENDOR-FREIER Thermostat-Treiber.
 *
 * scheduleMode == 'controller' (J/E): das gebundene Geraet kennt nur einen
 * Sollwert. Die ScheduleEngine IM MODUL faehrt den Wochenplan und ruft an jeder
 * Slot-Grenze setSetpoint(); dieser Treiber schreibt den Sollwert in eine vom
 * Nutzer im LVB zugeordnete Standard-Symcon-Variable. Damit laeuft die Suite in
 * JEDEM Haus (KNX/Z-Wave/Zigbee2MQTT/Shelly TRV/...), nicht nur im HomeMatic-Haus.
 *
 * Konfiguration (cfg / bind):
 *   setpointVarId  int   Soll-Variable (idealerweise mit EnableAction -> RequestAction)
 *   actualVarId    int?  Ist-Temperatur-Variable
 *   modeVarId      int?  Modus-Variable (auto|manual|...) — sonst kein hasMode
 *   humidityVarId  int?  Feuchte-Variable
 *   valveVarId     int?  Ventilstellungs-Variable (%)
 *   min,max,step   float Klemm-/Rasterparameter (Default 5..30 / 0.5)
 *   maxSlots       int   fuer capabilities() (Default 48)
 *   rasterMinutes  int   fuer capabilities() (Default 15)
 *
 * Da das Geraet kein Wochenprofil kennt, sind readWeekProfile()/writeWeekProfile()
 * hier INERT (leeres Profil / false) — den Zeitplan besitzt die ScheduleEngine.
 *
 * ZUSTANDSLOS: haelt nur die Konfiguration; keine Sockets, kein Poll-Zustand.
 */
final class GenericVariableThermostat implements IThermostat
{
    /** @var array Konfiguration (siehe Klassen-Doc). */
    private array $cfg = [];

    /** @var callable Sende-Callback (bei variablengebundenen Treibern ungenutzt). */
    private $send;

    private float $min = 5.0;
    private float $max = 30.0;
    private float $step = 0.5;

    public function __construct()
    {
        // Default-No-op-Sender, bis bind() gerufen wird.
        $this->send = static function ($x): void {
        };
    }

    public function bind(array $config, callable $send): void
    {
        $this->cfg  = $config;
        $this->send = $send;
        $this->min  = isset($config['min'])  ? (float) $config['min']  : 5.0;
        $this->max  = isset($config['max'])  ? (float) $config['max']  : 30.0;
        $this->step = isset($config['step']) ? (float) $config['step'] : 0.5;
        if ($this->step <= 0) {
            $this->step = 0.5;
        }
        if ($this->max < $this->min) {
            [$this->min, $this->max] = [$this->max, $this->min];
        }
    }

    public function capabilities(): array
    {
        return [
            'scheduleMode'   => 'controller',                 // E/J: Engine treibt den Zeitplan
            'maxSlots'       => (int) ($this->cfg['maxSlots'] ?? 48),
            'rasterMinutes'  => (int) ($this->cfg['rasterMinutes'] ?? 15),
            'deviceProfiles' => 0,                            // kein geraeteseitiges Profil
            'separateSensor' => isset($this->cfg['actualVarId']),
            'p1Prefix'       => false,
            'hasMode'        => isset($this->cfg['modeVarId']),
            'hasHumidity'    => isset($this->cfg['humidityVarId']),
        ];
    }

    public static function discover(int $timeoutMs = 2000): array
    {
        // Variablengebundene Treiber werden manuell im LVB zugeordnet, nicht entdeckt.
        return [];
    }

    public function poll(): array
    {
        // Keine Netz-Frames noetig — Wahrheit liegt in Symcon-Variablen.
        return [];
    }

    public function parseEvent(string $raw): ?AudioState
    {
        // Nicht-Audio-Treiber: kein Event-Parsing.
        return null;
    }

    /** @return array{actual: ?float, setpoint: ?float, humidity: ?int, valve: ?int} */
    public function readLive(): array
    {
        return [
            'actual'   => $this->readFloat($this->cfg['actualVarId']   ?? null),
            'setpoint' => $this->readFloat($this->cfg['setpointVarId'] ?? null),
            'humidity' => $this->readInt($this->cfg['humidityVarId']   ?? null),
            'valve'    => $this->readInt($this->cfg['valveVarId']       ?? null),
        ];
    }

    public function setSetpoint(float $c): bool
    {
        $vid = (int) ($this->cfg['setpointVarId'] ?? 0);
        if ($vid <= 0) {
            $this->log('setSetpoint: keine setpointVarId konfiguriert');
            return false;
        }
        $c = $this->clampRaster($c);
        return $this->writeVar($vid, $c);
    }

    public function setMode(string $mode): void
    {
        $vid = (int) ($this->cfg['modeVarId'] ?? 0);
        if ($vid <= 0) {
            return; // kein Modus gebunden -> hasMode=false, no-op
        }
        $this->writeVar($vid, $mode);
    }

    // --- device-only Methoden: im controller-Modus bewusst inert ---

    public function readWeekProfile(?int $presenceIndex = null): array
    {
        // controller: Geraet fuehrt kein Profil -> nichts zurueckzulesen.
        return [];
    }

    /** controller: das Geraet kennt keine Profile - die Wahl faellt im Modul. */
    public function selectProfile(int $presenceIndex): bool
    {
        return true;
    }

    public function activeProfile(): ?int
    {
        return null;
    }

    public function writeWeekProfile(array $week, ?int $presenceIndex = null): bool
    {
        // controller: kein Geraeteprofil; den Zeitplan faehrt die ScheduleEngine.
        return false;
    }

    // ----------------------------------------------------------------------
    // Interne Helfer (IPS-Aufrufe function_exists-gegated -> testbar ohne Kernel)
    // ----------------------------------------------------------------------

    private function clampRaster(float $c): float
    {
        if ($c < $this->min) {
            $c = $this->min;
        }
        if ($c > $this->max) {
            $c = $this->max;
        }
        // Auf Raster (step) runden, relativ zum Minimum.
        $n = round(($c - $this->min) / $this->step);
        $c = $this->min + $n * $this->step;
        return round($c, 4);
    }

    /**
     * Schreibt einen Wert in eine Symcon-Variable. Bevorzugt RequestAction (wenn
     * die Variable actionable ist), sonst SetValue.
     */
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

    private function readFloat($vid): ?float
    {
        $vid = (int) $vid;
        if ($vid <= 0 || !function_exists('GetValue')) {
            return null;
        }
        $val = @\GetValue($vid);
        return is_numeric($val) ? (float) $val : null;
    }

    private function readInt($vid): ?int
    {
        $vid = (int) $vid;
        if ($vid <= 0 || !function_exists('GetValue')) {
            return null;
        }
        $val = @\GetValue($vid);
        return is_numeric($val) ? (int) round((float) $val) : null;
    }

    private function log(string $msg): void
    {
        if (function_exists('IPS_LogMessage')) {
            @\IPS_LogMessage('HS.GenThermostat', $msg);
        }
    }
}

<?php

declare(strict_types=1);

namespace Hoep\HomeSuite\HAL;

/**
 * HomeMaticThermostat — Vendor-Treiber fuer klassische HomeMatic-Heizthermostate
 * am CCU (Symcon-Modul "HomeMatic CCU Device").
 *
 * BETRIEBSART: scheduleMode == 'device' — das Geraet FUEHRT sein Wochenprogramm
 * selbst (Failsafe: laeuft weiter, auch wenn Symcon aus ist). Das Modul
 *   - schreibt das ganze Wochenprogramm ins Geraet, wenn sich der Plan aendert
 *     (writeWeekProfile -> CCU putParamset MASTER, mit Backup/Verify),
 *   - liest das Geraeteprogramm zurueck (readWeekProfile -> CCU getParamset),
 *   - setzt temporaere Sollwert-Overrides GENERISCH via RequestAction(SET_TEMPERATURE)
 *     (Boost/Manuell), OHNE das Profil zu aendern (Entscheidung A),
 *   - liest Live-Werte aus den Status-Variablen.
 *
 * "Dumme" Geraete ohne eigenes Programm laufen stattdessen ueber den
 * GenericVariableThermostat (scheduleMode 'controller', ScheduleEngine im Modul).
 *
 * Der CCU-Zugriff ist SELBST-ENTHALTEN ({@see CcuXmlRpc}) — kein ext-xmlrpc, keine
 * Legacy-Bibliothek. Host/Serial werden aus der Symcon-IO-Kette aufgeloest.
 *
 * VENDOR-Varianten (per Kanal-Idents erkannt):
 *   - VALVE_STATE               -> HM-CC-RT-DN (1 Profil, 13 Slots, Keys TEMPERATURE_/ENDTIME_)
 *   - ACTUAL_HUMIDITY o. Valve  -> HM-TC-IT-WM-W-EU (3 Praesenzprofile P1_/P2_/P3_, 13 Slots)
 *   - sonst                     -> HM-CC-TC (1 Profil, 24 Slots, Keys TEMPERATUR_/TIMEOUT_)
 */
final class HomeMaticThermostat implements IThermostat
{
    private const DAYS = ['MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY', 'FRIDAY', 'SATURDAY', 'SUNDAY'];

    /** @var array Konfiguration. */
    private array $cfg = [];

    /** @var callable Sende-Callback (ungenutzt). */
    private $send;

    private int $device = 0;
    private float $min = 5.0;
    private float $max = 30.0;
    private float $step = 0.5;

    /** Aufgeloeste Kanal-Variablen (ident => objectId | null). */
    private array $vid = [
        'SET_TEMPERATURE'    => null,
        'SETPOINT'           => null,   // HM-CC-TC nutzt SETPOINT statt SET_TEMPERATURE
        'ACTUAL_TEMPERATURE' => null,
        'ACTUAL_HUMIDITY'    => null,
        'VALVE_STATE'        => null,
        'CONTROL_MODE'       => null,
    ];

    private string $model = 'HM-CC-TC';

    /** CCU-Transport (aufgeloest aus der Symcon-IO-Kette). */
    private string $ccuHost = '';
    private int $ccuPort = 2001;      // BidCos (klassisch); HmIP waere 2010
    private string $baseSerial = '';  // z.B. "ABC1234567" (substr(Address,0,10))
    private string $devChannel = '';  // Kanal-Arg fuer getParamset: "" ausser CC-TC

    public function __construct()
    {
        $this->send = static function ($x): void {
        };
    }

    public function bind(array $config, callable $send): void
    {
        $this->cfg    = $config;
        $this->send   = $send;
        $this->device = (int) ($config['deviceInstanceId'] ?? 0);
        $this->min    = isset($config['min'])  ? (float) $config['min']  : 5.0;
        $this->max    = isset($config['max'])  ? (float) $config['max']  : 30.0;
        $this->step   = isset($config['step']) ? (float) $config['step'] : 0.5;
        if ($this->step <= 0) {
            $this->step = 0.5;
        }
        if ($this->max < $this->min) {
            [$this->min, $this->max] = [$this->max, $this->min];
        }
        $this->resolveChannels();
        $this->resolveCcu();
    }

    public function capabilities(): array
    {
        $deviceProfiles = ($this->model === 'HM-TC-IT-WM-W-EU') ? 3 : 1;
        $maxSlots       = ($this->model === 'HM-CC-TC') ? 24 : 13;

        return [
            'scheduleMode'   => 'device',
            'model'          => $this->model,
            'maxSlots'       => $maxSlots,
            'rasterMinutes'  => 10,               // HM speichert in 10-Min-Schritten
            'deviceProfiles' => $deviceProfiles,
            'separateSensor' => $this->vid['ACTUAL_TEMPERATURE'] !== null,
            'p1Prefix'       => ($this->model === 'HM-TC-IT-WM-W-EU'),
            'hasMode'        => false,            // CONTROL_MODE-Schreiben erst nach Freigabe
            'hasHumidity'    => $this->vid['ACTUAL_HUMIDITY'] !== null,
            'hasValve'       => $this->vid['VALVE_STATE'] !== null,
            'profileApi'     => $this->hasProfileApi(),
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

    /** Sollwert-Variable: SET_TEMPERATURE (RT-DN/TC-IT) oder SETPOINT (CC-TC). */
    private function setpointVid(): int
    {
        return (int) ($this->vid['SET_TEMPERATURE'] ?? $this->vid['SETPOINT'] ?? 0);
    }

    /** @return array{actual: ?float, setpoint: ?float, humidity: ?int, valve: ?int} */
    public function readLive(): array
    {
        return [
            'actual'   => $this->readFloat($this->vid['ACTUAL_TEMPERATURE']),
            'setpoint' => $this->readFloat($this->setpointVid()),
            'humidity' => $this->readInt($this->vid['ACTUAL_HUMIDITY']),
            'valve'    => $this->readInt($this->vid['VALVE_STATE']),
        ];
    }

    public function setSetpoint(float $c): bool
    {
        $vid = $this->setpointVid();
        if ($vid <= 0) {
            $this->log('setSetpoint: Sollwert-Variable nicht aufgeloest (Device #' . $this->device . ')');
            return false;
        }
        $c = $this->clampRaster($c);
        try {
            if (function_exists('RequestAction')) {
                @\RequestAction($vid, $c);   // temporaerer Override -> HM-Modul -> CCU
                return true;
            }
        } catch (\Throwable $e) {
            $this->log('setSetpoint #' . $vid . ': ' . $e->getMessage());
        }
        return false;
    }

    public function setMode(string $mode): void
    {
        $this->log('setMode(' . $mode . ') ignoriert (hasMode=false, Device #' . $this->device . ')');
    }

    // ---------------- scheduleMode 'device': Wochenprofil ----------------

    /**
     * Liest das Geraete-Wochenprofil (Migrations-Wahrheit G1).
     * @return array<int,array<int,array{end:int,val:float}>> dayIndex(0..6) => Slots
     */
    public function readWeekProfile(?int $presenceIndex = null): array
    {
        if (!$this->hasProfileApi()) {
            return [];
        }
        $params = CcuXmlRpc::getParamset($this->ccuHost, $this->ccuPort, $this->profileAddress());
        if (!is_array($params)) {
            $this->log('readWeekProfile: getParamset leer (Host ' . $this->ccuHost . ', Addr ' . $this->profileAddress() . ')');
            return [];
        }
        return $this->parseWeek($params, $presenceIndex);
    }

    /**
     * Schreibt ein Wochenprofil INS GERAET — mit Backup/Verify:
     * bestehendes Paramset sichern -> schreiben -> zuruecklesen -> verifizieren;
     * bei Abweichung Restore des Backups und false.
     *
     * @param array<int,array<int,array{end:int,val:float}>> $week
     */
    public function writeWeekProfile(array $week, ?int $presenceIndex = null): bool
    {
        if (!$this->hasProfileApi()) {
            $this->log('writeWeekProfile: kein CCU-Zugriff -> inert');
            return false;
        }
        $addr = $this->profileAddress();

        // 1) BACKUP (nur die profilrelevanten Keys).
        $before = CcuXmlRpc::getParamset($this->ccuHost, $this->ccuPort, $addr);
        if (!is_array($before)) {
            $this->log('writeWeekProfile: Backup fehlgeschlagen -> Abbruch');
            return false;
        }

        // 2) Ziel-Params bauen und schreiben.
        $target = $this->buildParams($week, $presenceIndex);
        if ($target === []) {
            return false;
        }
        if (!CcuXmlRpc::putParamset($this->ccuHost, $this->ccuPort, $addr, $target)) {
            $this->log('writeWeekProfile: putParamset meldete Fehler');
            return false;
        }

        // 3) VERIFY: zuruecklesen und Soll-Keys vergleichen.
        $after = CcuXmlRpc::getParamset($this->ccuHost, $this->ccuPort, $addr);
        if (is_array($after) && $this->verify($target, $after)) {
            return true;
        }

        // 4) RESTORE bei Abweichung: die zuvor gesicherten Werte zurueckschreiben.
        $this->log('writeWeekProfile: Verify fehlgeschlagen -> Restore des Backups');
        $restore = [];
        foreach (array_keys($target) as $k) {
            if (array_key_exists($k, $before)) {
                $restore[$k] = $this->typedFromKey($k, $before[$k]);
            }
        }
        if ($restore !== []) {
            CcuXmlRpc::putParamset($this->ccuHost, $this->ccuPort, $addr, $restore);
        }
        return false;
    }

    // ----------------------------------------------------------------------
    // Interne Helfer
    // ----------------------------------------------------------------------

    private function resolveChannels(): void
    {
        if ($this->device <= 0 || !function_exists('IPS_GetObjectIDByIdent')) {
            return;
        }
        foreach (array_keys($this->vid) as $ident) {
            $id = @\IPS_GetObjectIDByIdent($ident, $this->device);
            $this->vid[$ident] = (is_int($id) && $id > 0) ? $id : null;
        }
        if ($this->vid['VALVE_STATE'] !== null) {
            $this->model = 'HM-CC-RT-DN';
        } elseif ($this->vid['ACTUAL_HUMIDITY'] !== null) {
            $this->model = 'HM-TC-IT-WM-W-EU';
        } else {
            $this->model = 'HM-CC-TC';
        }
    }

    /** Loest CCU-Host, Basisserial und Profil-Kanal auf. */
    private function resolveCcu(): void
    {
        if (!function_exists('IPS_GetProperty')) {
            return;
        }
        $addr = @\IPS_GetProperty($this->device, 'Address');
        if (is_string($addr) && $addr !== '') {
            $this->baseSerial = substr($addr, 0, 10);
        }
        // Kanal-Arg: "" fuer RT-DN/TC-IT (Geraeteebene), Geraetekanal fuer CC-TC.
        $this->devChannel = ($this->model === 'HM-CC-TC' && is_string($addr) && strlen($addr) > 11)
            ? substr($addr, 11, 1)
            : '';

        $this->ccuHost = (string) ($this->cfg['ccuHost'] ?? '');
        $this->ccuPort = (int) ($this->cfg['ccuPort'] ?? 2001);
        if ($this->ccuHost === '' && function_exists('IPS_GetInstance')) {
            $cur = $this->device;
            for ($i = 0; $i < 8 && $cur > 0; $i++) {
                $host = @\IPS_GetProperty($cur, 'Host');
                if (is_string($host) && $host !== '') {
                    $this->ccuHost = $host;
                    break;
                }
                $inst = @\IPS_GetInstance($cur);
                $cur  = (int) ($inst['ConnectionID'] ?? 0);
            }
        }
    }

    private function hasProfileApi(): bool
    {
        return $this->ccuHost !== '' && $this->baseSerial !== '';
    }

    private function profileAddress(): string
    {
        return $this->baseSerial . ':' . $this->devChannel;
    }

    /** Praesenz-Index (0..2) -> P-Nummer (1..3) fuer TC-IT; sonst 0. */
    private function presenceP(?int $presenceIndex): int
    {
        if ($this->model !== 'HM-TC-IT-WM-W-EU') {
            return 0;
        }
        $p = ($presenceIndex ?? 0) + 1;
        return ($p >= 1 && $p <= 3) ? $p : 1;
    }

    /** Key-Bausteine je Modell: [tempPrefix, endPrefix]. */
    private function keyStyle(?int $presenceIndex): array
    {
        if ($this->model === 'HM-CC-TC') {
            return ['TEMPERATUR_', 'TIMEOUT_'];
        }
        if ($this->model === 'HM-TC-IT-WM-W-EU') {
            $p = 'P' . $this->presenceP($presenceIndex) . '_';
            return [$p . 'TEMPERATURE_', $p . 'ENDTIME_'];
        }
        return ['TEMPERATURE_', 'ENDTIME_']; // RT-DN
    }

    private function maxSlots(): int
    {
        return ($this->model === 'HM-CC-TC') ? 24 : 13;
    }

    /**
     * Roh-Paramset -> HAL-Wochenstruktur. Beruecksichtigt die TC-IT-Firmware-
     * Eigenheit (P1_ kann fehlen -> Fallback auf praefixlose Keys).
     *
     * @return array<int,array<int,array{end:int,val:float}>>
     */
    private function parseWeek(array $params, ?int $presenceIndex): array
    {
        [$tp, $ep] = $this->keyStyle($presenceIndex);
        $out = [];
        foreach (self::DAYS as $di => $day) {
            $slots = [];
            for ($i = 1; $i <= $this->maxSlots(); $i++) {
                $kt = $tp . $day . '_' . $i;
                $ke = $ep . $day . '_' . $i;
                if (!array_key_exists($kt, $params) && $this->model === 'HM-TC-IT-WM-W-EU') {
                    // Firmware-Fallback: praefixloser Key (LAN-Adapter, Profil 1).
                    $kt = 'TEMPERATURE_' . $day . '_' . $i;
                    $ke = 'ENDTIME_' . $day . '_' . $i;
                }
                if (!array_key_exists($kt, $params) || !array_key_exists($ke, $params)) {
                    break;
                }
                $end = (int) round((float) $params[$ke]);
                if ($end > 1440) {
                    $end = 1440;
                }
                $slots[] = ['end' => $end, 'val' => (float) $params[$kt]];
                if ($end >= 1440) {
                    break;
                }
            }
            $out[$di] = $slots;
        }
        return $out;
    }

    /**
     * HAL-Wochenstruktur -> Roh-Params fuer putParamset. Fuellt jeden Tag bis
     * maxSlots mit dem letzten Wert bei Endzeit 1440 auf (HM-Konvention).
     *
     * @param array<int,array<int,array{end:int,val:float}>> $week
     * @return array<string,array{type:string,value:mixed}>
     */
    private function buildParams(array $week, ?int $presenceIndex): array
    {
        [$tp, $ep] = $this->keyStyle($presenceIndex);
        $max = $this->maxSlots();
        $params = [];
        foreach (self::DAYS as $di => $day) {
            $slots = $week[$di] ?? [];
            if ($slots === []) {
                continue; // kein Tagesprofil -> nicht anfassen
            }
            $lastVal = (float) ($slots[count($slots) - 1]['val'] ?? $this->min);
            for ($i = 1; $i <= $max; $i++) {
                if (isset($slots[$i - 1])) {
                    $end = (int) $slots[$i - 1]['end'];
                    $val = $this->clampRaster((float) $slots[$i - 1]['val']);
                } else {
                    $end = 1440;         // Rest des Tages auffuellen
                    $val = $this->clampRaster($lastVal);
                }
                if ($end > 1440) {
                    $end = 1440;
                }
                $params[$tp . $day . '_' . $i] = ['type' => 'double', 'value' => $val];
                $params[$ep . $day . '_' . $i] = ['type' => 'int', 'value' => $end];
                if ($end >= 1440 && !isset($slots[$i])) {
                    // trailing Slots weiter auffuellen (HM erwartet i.d.R. volle Zahl)
                }
            }
        }
        return $params;
    }

    /** Vergleicht geschriebene Soll-Params gegen das Rueckgelesene (Toleranz). */
    private function verify(array $target, array $after): bool
    {
        foreach ($target as $k => $spec) {
            if (!array_key_exists($k, $after)) {
                return false;
            }
            $want = $spec['value'];
            $got  = $after[$k];
            if (($spec['type'] ?? 'string') === 'double') {
                if (abs((float) $want - (float) $got) > 0.05) {
                    return false;
                }
            } elseif ((int) round((float) $want) !== (int) round((float) $got)) {
                return false;
            }
        }
        return true;
    }

    /** Baut aus einem Backup-Rohwert die typisierte put-Spezifikation je Key. */
    private function typedFromKey(string $key, $value): array
    {
        $isEnd = (strpos($key, 'ENDTIME_') !== false) || (strpos($key, 'TIMEOUT_') !== false);
        return $isEnd
            ? ['type' => 'int', 'value' => (int) round((float) $value)]
            : ['type' => 'double', 'value' => (float) $value];
    }

    private function clampRaster(float $c): float
    {
        if ($c < $this->min) {
            $c = $this->min;
        }
        if ($c > $this->max) {
            $c = $this->max;
        }
        $n = round(($c - $this->min) / $this->step);
        return round($this->min + $n * $this->step, 4);
    }

    private function readFloat($vid): ?float
    {
        $vid = (int) $vid;
        if ($vid <= 0 || !function_exists('GetValue')) {
            return null;
        }
        $v = @\GetValue($vid);
        return is_numeric($v) ? (float) $v : null;
    }

    private function readInt($vid): ?int
    {
        $vid = (int) $vid;
        if ($vid <= 0 || !function_exists('GetValue')) {
            return null;
        }
        $v = @\GetValue($vid);
        return is_numeric($v) ? (int) round((float) $v) : null;
    }

    private function log(string $msg): void
    {
        if (function_exists('IPS_LogMessage')) {
            @\IPS_LogMessage('HS.HMThermostat', $msg);
        }
    }
}

// Vendor-Selbstregistrierung bei der DriverFactory (§2.2.5).
DriverFactory::register('hm-HM-CC-RT-DN', HomeMaticThermostat::class);
DriverFactory::register('hm-HM-TC-IT-WM-W-EU', HomeMaticThermostat::class);
DriverFactory::register('hm-HM-CC-TC', HomeMaticThermostat::class);

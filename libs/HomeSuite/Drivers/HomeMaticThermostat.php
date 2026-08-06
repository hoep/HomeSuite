<?php

declare(strict_types=1);

namespace Hoep\HomeSuite\HAL;

/**
 * HomeMaticThermostat — Vendor-Treiber fuer klassische HomeMatic-Heizthermostate
 * am CCU/BidCos (Symcon-Modul "HomeMatic CCU Device").
 *
 * scheduleMode == 'device' (Leitprinzip 7): das GERAET fuehrt das Wochenprofil
 * selbst. Dieser Treiber
 *   - liest Live-Werte aus den Status-Variablen des CCU-Geraets
 *     (SET_TEMPERATURE / ACTUAL_TEMPERATURE / ACTUAL_HUMIDITY / VALVE_STATE),
 *   - schreibt den Sollwert ueber die native, actionable Variable SET_TEMPERATURE
 *     (RequestAction -> Symcon HomeMatic-Modul -> CCU); das ist ein temporaeres
 *     Override-Fenster, KEINE Profilaenderung (Entscheidung A),
 *   - liest/schreibt das Geraete-Wochenprofil ueber die BEWAEHRTE Bibliothek
 *     HMXML_getTempProfile()/HMXML_setTempProfile() (CCU-XML-RPC putParamset auf
 *     <Address>:2/MASTER), sofern diese im Kernel geladen ist. Ist sie es nicht,
 *     bleiben die Profil-Methoden INERT ([] / false) — der Sollwert-/Live-Pfad
 *     funktioniert unabhaengig davon.
 *
 * VENDOR-Varianten (per Kanal-Idents erkannt, nicht geraten):
 *   - VALVE_STATE vorhanden  -> Heizkoerperthermostat (HM-CC-RT-DN): 1 Profil,
 *                               bis 13 Slots/Tag.
 *   - ACTUAL_HUMIDITY, kein VALVE_STATE -> Wandthermostat (HM-TC-IT-WM-W-EU):
 *                               3 Praesenzprofile (P1_/P2_/P3_).
 *   - sonst                   -> HM-CC-TC (aelter): 1 Profil.
 *
 * ZUSTANDSLOS bis auf die aufgeloeste Kanal-Zuordnung (reine Lookups, kein Socket).
 *
 * Konfiguration (bind):
 *   deviceInstanceId int   Instanz-ID des "HomeMatic CCU Device"
 *   min,max,step     float Klemm-/Rasterparameter (Default 5..30 / 0.5)
 */
final class HomeMaticThermostat implements IThermostat
{
    /** @var array Konfiguration. */
    private array $cfg = [];

    /** @var callable Sende-Callback (bei diesem Treiber ungenutzt — Schreiben geht via RequestAction/HMXML). */
    private $send;

    private int $device = 0;
    private float $min = 5.0;
    private float $max = 30.0;
    private float $step = 0.5;

    /** Aufgeloeste Kanal-Variablen (ident => objectId | null). */
    private array $vid = [
        'SET_TEMPERATURE'  => null,
        'ACTUAL_TEMPERATURE' => null,
        'ACTUAL_HUMIDITY'  => null,
        'VALVE_STATE'      => null,
        'CONTROL_MODE'     => null,
    ];

    /** Erkannter Geraetetyp (siehe Klassen-Doc). */
    private string $model = 'HM-CC-TC';

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
    }

    public function capabilities(): array
    {
        $hasValve = $this->vid['VALVE_STATE'] !== null;
        $hasHum   = $this->vid['ACTUAL_HUMIDITY'] !== null;

        // Profilkapazitaet je erkanntem Modell.
        $deviceProfiles = ($this->model === 'HM-TC-IT-WM-W-EU') ? 3 : 1;
        $maxSlots       = ($this->model === 'HM-CC-RT-DN') ? 13 : 24;

        return [
            'scheduleMode'   => 'device',
            'model'          => $this->model,
            'maxSlots'       => $maxSlots,
            'rasterMinutes'  => 10,                 // HM speichert in 10-Min-Schritten
            'deviceProfiles' => $deviceProfiles,
            'separateSensor' => true,
            'p1Prefix'       => ($this->model === 'HM-TC-IT-WM-W-EU'),
            'hasMode'        => false,              // CONTROL_MODE-Schreiben bewusst (noch) nicht
            'hasHumidity'    => $hasHum,
            'hasValve'       => $hasValve,
            'profileApi'     => $this->hasProfileApi(),
        ];
    }

    public static function discover(int $timeoutMs = 2000): array
    {
        // CCU-Geraete werden im LVB manuell zugeordnet (Instanz-Auswahl).
        return [];
    }

    public function poll(): array
    {
        return []; // Wahrheit liegt in den Symcon-Statusvariablen des CCU-Geraets.
    }

    public function parseEvent(string $raw): ?AudioState
    {
        return null;
    }

    /** @return array{actual: ?float, setpoint: ?float, humidity: ?int, valve: ?int} */
    public function readLive(): array
    {
        return [
            'actual'   => $this->readFloat($this->vid['ACTUAL_TEMPERATURE']),
            'setpoint' => $this->readFloat($this->vid['SET_TEMPERATURE']),
            'humidity' => $this->readInt($this->vid['ACTUAL_HUMIDITY']),
            'valve'    => $this->readInt($this->vid['VALVE_STATE']),
        ];
    }

    public function setSetpoint(float $c): bool
    {
        $vid = (int) ($this->vid['SET_TEMPERATURE'] ?? 0);
        if ($vid <= 0) {
            $this->log('setSetpoint: SET_TEMPERATURE nicht aufgeloest (Device #' . $this->device . ')');
            return false;
        }
        $c = $this->clampRaster($c);
        try {
            if (function_exists('RequestAction')) {
                @\RequestAction($vid, $c);   // -> HomeMatic-Modul -> CCU (temporaeres Override)
                return true;
            }
        } catch (\Throwable $e) {
            $this->log('setSetpoint #' . $vid . ': ' . $e->getMessage());
        }
        return false;
    }

    public function setMode(string $mode): void
    {
        // CONTROL_MODE/MANU_MODE-Schreiben ist bei HM aktor-heikel und wird erst
        // nach expliziter Freigabe verdrahtet. hasMode=false -> das Modul ruft
        // setMode() ohnehin nicht.
        $this->log('setMode(' . $mode . ') ignoriert (hasMode=false, Device #' . $this->device . ')');
    }

    // --- scheduleMode 'device': Geraete-Wochenprofil via bewaehrte HMXML-Bibliothek ---

    public function readWeekProfile(?int $presenceIndex = null): array
    {
        if (!$this->hasProfileApi()) {
            return [];
        }
        $wt = $this->presenceToWtProfil($presenceIndex);
        try {
            $raw = @\HMXML_getTempProfile($this->device, false, false, $wt);
            return $this->normalizeProfile($raw, $presenceIndex);
        } catch (\Throwable $e) {
            $this->log('readWeekProfile: ' . $e->getMessage());
            return [];
        }
    }

    public function writeWeekProfile(array $week, ?int $presenceIndex = null): bool
    {
        if (!$this->hasProfileApi()) {
            $this->log('writeWeekProfile: HMXML nicht geladen -> inert');
            return false;
        }
        // BEWUSST noch nicht scharf: das Ueberschreiben eines Geraeteprofils ist
        // destruktiv und wird erst im Migrationsschritt (mit Backup/Verify je Raum)
        // freigegeben. Bis dahin meldet der Treiber ehrlich false.
        $this->log('writeWeekProfile: im Aktor-Safety-Gate (Migrationsschritt) — noch nicht scharf');
        return false;
    }

    // ----------------------------------------------------------------------
    // Interne Helfer
    // ----------------------------------------------------------------------

    /** Loest die Kanal-Variablen am CCU-Geraet auf und erkennt das Modell. */
    private function resolveChannels(): void
    {
        if ($this->device <= 0 || !function_exists('IPS_GetObjectIDByIdent')) {
            return;
        }
        foreach (array_keys($this->vid) as $ident) {
            $id = @\IPS_GetObjectIDByIdent($ident, $this->device);
            $this->vid[$ident] = (is_int($id) && $id > 0) ? $id : null;
        }
        // Modellheuristik aus den vorhandenen Kanaelen.
        if ($this->vid['VALVE_STATE'] !== null) {
            $this->model = 'HM-CC-RT-DN';
        } elseif ($this->vid['ACTUAL_HUMIDITY'] !== null) {
            $this->model = 'HM-TC-IT-WM-W-EU';
        } else {
            $this->model = 'HM-CC-TC';
        }
    }

    /** Ist die bewaehrte HMXML-Profil-Bibliothek im Kernel geladen? */
    private function hasProfileApi(): bool
    {
        return function_exists('HMXML_getTempProfile') && function_exists('HMXML_setTempProfile');
    }

    /** Praesenz-Index (0..2) -> HMXML $WT_Profil (1..3); sonst 0 (aktiv/einfach). */
    private function presenceToWtProfil(?int $presenceIndex): int
    {
        if ($this->model !== 'HM-TC-IT-WM-W-EU' || $presenceIndex === null) {
            return 0;
        }
        $p = $presenceIndex + 1;
        return ($p >= 1 && $p <= 3) ? $p : 1;
    }

    /**
     * Bringt die HMXML-Rueckgabe in die HAL-Form
     * [ dayIndex(0..6) => [ ['end'=>minuten,'val'=>float], ... ] ].
     */
    private function normalizeProfile($raw, ?int $presenceIndex): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $days = ['MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY', 'FRIDAY', 'SATURDAY', 'SUNDAY'];

        // Bei P-Profilen liegt die Tagesebene ggf. unter "P<n>".
        $node = $raw;
        if ($this->model === 'HM-TC-IT-WM-W-EU') {
            $p = 'P' . $this->presenceToWtProfil($presenceIndex);
            if (isset($raw[$p]) && is_array($raw[$p])) {
                $node = $raw[$p];
            }
        }

        $out = [];
        foreach ($days as $di => $dname) {
            $slots = [];
            if (isset($node[$dname]['EndTimes']) && is_array($node[$dname]['EndTimes'])) {
                $ends = $node[$dname]['EndTimes'];
                $vals = $node[$dname]['Values'] ?? [];
                foreach ($ends as $i => $hhmm) {
                    $slots[] = ['end' => $this->hhmmToMin((string) $hhmm), 'val' => (float) ($vals[$i] ?? 0)];
                }
            }
            $out[$di] = $slots;
        }
        return $out;
    }

    private function hhmmToMin(string $hhmm): int
    {
        $p = explode(':', $hhmm);
        if (count($p) !== 2) {
            return 0;
        }
        return ((int) $p[0]) * 60 + (int) $p[1];
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

// Vendor-Selbstregistrierung bei der DriverFactory (§2.2.5): alle klassischen
// HM-Heiztreiber-Ids zeigen auf diese eine, kanal-erkennende Klasse.
DriverFactory::register('hm-HM-CC-RT-DN', HomeMaticThermostat::class);
DriverFactory::register('hm-HM-TC-IT-WM-W-EU', HomeMaticThermostat::class);
DriverFactory::register('hm-HM-CC-TC', HomeMaticThermostat::class);

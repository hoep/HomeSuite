<?php

declare(strict_types=1);

/**
 * PoolController (HSPC) — eigenstaendiges HomeSuite-Domaenenmodul fuer einen
 * ProCon.IP-artigen Pool-Controller (pooldigital.de). Ablösung der PHP-Skript-
 * Klassenfamilie PHPPoolcontroller.
 *
 * Eine Instanz = ein Pool-Controller. Erbt {@see \Hoep\HomeSuite\EntityModule}
 * (Manifest -> Variablen, RPC-Trio, Store, Armed-Gate). Der Geraetezugriff laeuft
 * ueber den selbst-enthaltenen {@see \Hoep\HomeSuite\Drivers\Pool\PoolClient}.
 *
 * STAND P0/P1: reiner LESE-Betrieb. Poll von GetState.csv/GetDos.csv, alle Live-
 * Werte in Statusvariablen. Durchfluss-abhaengige TruePoolTemp-Fusion ist bereits
 * aktiv (Nutzer-Anforderung #2): Inline-Sensor gilt nur bei Durchfluss, sonst der
 * externe In-Pool-Sensor. KEIN Geraete-Schreibzugriff (Relais/Dosierung/Regeln/
 * TReal-Writeback) — der folgt in P2+ ausschliesslich hinter dem Armed-Gate.
 * Alle frueheren Hardcodes (Host/Login/Timeout, Spaltenindizes, Schwellen, Poll)
 * sind Store-Einstellungen.
 *
 * Klassenname == module.json "name" == GUID {878CA345-86D1-84FC-B196-5B3224C067CF} (Prefix HSPC).
 */

require_once __DIR__ . '/../libs/HomeSuite/autoload.php';

use Hoep\HomeSuite\ActionContext;
use Hoep\HomeSuite\Control;
use Hoep\HomeSuite\ControlContract;
use Hoep\HomeSuite\Drivers\Pool\PoolClient;
use Hoep\HomeSuite\Drivers\Pool\TimecSchedule;
use Hoep\HomeSuite\EntityModule;

class PoolController extends EntityModule
{
    private const TIMER_POLL   = 'Poll';
    private const POLL_MS_DEF   = 30000;

    /** Verbindungs-/Struktur-Defaults (frueher hartkodiert, jetzt Store-Einstellungen). */
    private const DEF = [
        'host'           => '',
        'user'           => 'admin',
        'pass'           => '',
        'port'           => 80,
        'timeout'        => 10,
        'connectTimeout' => 5,
        'useHttps'       => false,
        'pollInterval'   => self::POLL_MS_DEF,
        'armed'          => false,
        // Spaltenzuordnung (live 192.168.1.30 verifiziert)
        'poolTempCol'    => 8,
        'outsideCol'     => 9,
        'solarCol'       => 10,
        'returnCol'      => 11,
        'pumpTempCol'    => 12,
        'redoxCol'       => 6,
        'phCol'          => 7,
        'pressureCol'    => 3,
        'flowVolCol'     => 4,
        'flowRateCol'    => 24,
        'pumpRelayIndex' => 0,
        'flowThreshold'  => 0.5,   // cm/s (Anstroemung) -> Durchfluss vorhanden
        'flowSettleSeconds' => 120,
        // Umwaelz-Berechnung (mit TruePoolTemp)
        'poolSize'       => 0.0,   // m3 (0 -> Standardformel)
        'flowRate'       => 0.0,   // m3/h Umwaelzleistung
        // TReal (Option A: Modul steuert, KEIN Geraete-Writeback)
        'trealEnabled'   => false,
        'trealSourceVarId' => 0,
        'minPlausible'   => 0.0,
        'maxPlausible'   => 45.0,
        'maxStaleSeconds' => 1800,
    ];

    private const RELAY_LABELS = [
        'Pumpe', 'Ventil Absorber', 'Pumpe Chlor', 'Pumpe pH minus',
        'Pumpe pH plus', 'Scheinwerfer', 'Photovoltaik', 'Relais 8',
    ];

    // --- Regel-Feldlayouts + Enums (Protokoll-Wahrheit, PROTOCOLS.md:95-98/:170-174) ---
    // Der PoolClient kapselt getRules/setRules generisch; die semantische Feldbelegung je
    // Sektion liegt hier im UI-Editor (nicht im Transport-Client).
    private const TEMPC_DEFAULT   = [0, 0, 0, 0, 0, 0, 255, 0, 0, 0];                    // PROTOCOLS.md:174
    private const ADCC_DEFAULT    = [0, 0, 0, 255, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 10, 10]; // PROTOCOLS.md:174
    private const SWITCHC_DEFAULT = [0, 0, 0, 0, 0, 0];                                  // PROTOCOLS.md:174
    private const RULE_LOGIC      = ['<', '<=', '==', '>', '>='];                        // PROTOCOLS.md:171
    private const SWITCHC_FUNC    = ['NORMAL', 'STEP', 'IMPULSE', 'IMPULSE_RESET'];      // PROTOCOLS.md:98/:173

    /** Instanz-Cache der tabellengetriebenen Konfig-Variablen-Map ($CFGVARS). */
    private ?array $cfgVarsCache = null;

    // ==================================================================
    // Manifest
    // ==================================================================

    protected function entityLabel(): string { return 'Pool'; }

    protected function manifest(): array
    {
        $R = function (string $ident, string $label, int $varType, string $profile = '') {
            return ['ident' => $ident, 'type' => ControlContract::T_REFLECT, 'role' => 'pool:reflect',
                'label' => $label, 'varType' => $varType, 'profile' => $profile, 'actionable' => false];
        };

        // Schreibbare Konfig-Variable (EnableAction). varType bestimmt den Control-Typ:
        // 0=bool -> switch, 2=float / 1=int -> setpoint (keine min/max -> kein Clamping),
        // 3=string -> select mit leerem Optionsset (coerce reicht den String durch;
        // optimistic=false, damit der Dispatch keinen SetValue mit falschem Typ versucht).
        $CFG = function (string $ident, string $label, int $varType, string $profile = '') {
            $type = $varType === 0
                ? ControlContract::T_SWITCH
                : ($varType === 3 ? ControlContract::T_SELECT : ControlContract::T_SETPOINT);
            $d = ['ident' => $ident, 'type' => $type, 'role' => 'pool:cfg', 'label' => $label,
                'varType' => $varType, 'profile' => $profile, 'actionable' => true];
            if ($varType === 3) {
                $d['options']    = [];
                $d['optimistic'] = false;
            }
            return $d;
        };

        $controls = [
            // --- Wassertemperatur ---
            $R('TruePoolTemp', 'Wassertemperatur (echt)', 2, '~Temperature'),
            $R('WaterTempInline', 'Wassertemperatur Inline (S0)', 2, '~Temperature'),
            $R('TrealExternal', 'Wassertemperatur extern', 2, '~Temperature'),
            $R('TempSource', 'Temperatur-Quelle', 3),
            $R('FlowActive', 'Durchfluss', 0, '~Switch'),
            // --- weitere Temperaturen ---
            $R('TempOutside', 'Aussentemperatur', 2, '~Temperature'),
            $R('TempSolar', 'Solarabsorber', 2, '~Temperature'),
            $R('TempReturn', 'Ruecklauf', 2, '~Temperature'),
            $R('TempPumpHousing', 'Pumpe (Gehaeuse)', 2, '~Temperature'),
            // --- Wasserchemie ---
            $R('PH', 'pH-Wert', 2, 'HSPC.pH'),
            $CFG('PHTarget', 'pH Sollwert', 2, 'HSPC.pH'),        // schreibbar (setDosageFull type=1)
            $R('Redox', 'Redox', 2, 'HSPC.Redox'),
            $CFG('RedoxTarget', 'Redox Sollwert', 2, 'HSPC.Redox'), // schreibbar (setDosageFull type=0)
            // --- Hydraulik ---
            $R('Pressure', 'Kesseldruck', 2, 'HSPC.Pressure'),
            $R('FlowVolume', 'Durchfluss (Volumen)', 2, 'HSPC.Flow'),
            $R('FlowRate', 'Anstroemung', 2, 'HSPC.FlowRate'),
            // --- Kanister-Fuellstaende ---
            $R('ClLevel', 'Chlor Fuellstand', 2, 'HSPC.Percent'),
            $R('PHMinusLevel', 'pH-minus Fuellstand', 2, 'HSPC.Percent'),
            $R('PHPlusLevel', 'pH-plus Fuellstand', 2, 'HSPC.Percent'),
            $R('ClConsumption', 'Chlor Verbrauch', 2, 'HSPC.Milliliter'),
            $R('PHMinusConsumption', 'pH-minus Verbrauch', 2, 'HSPC.Milliliter'),
            $R('PHPlusConsumption', 'pH-plus Verbrauch', 2, 'HSPC.Milliliter'),
        ];

        // --- Dosier-Aktivitaet (read-only, aus GetDos) ---
        $controls[] = $R('ClDosing', 'Chlor dosiert gerade', 0, '~Switch');
        $controls[] = $R('PHMinusDosing', 'pH-minus dosiert gerade', 0, '~Switch');
        $controls[] = $R('PHPlusDosing', 'pH-plus dosiert gerade', 0, '~Switch');

        // --- Dosier-Laufzeit (read-only, aus GetDos.csv; Spalten PROTOCOLS.md:60-70) ---
        // Als Float gefuehrt (HSPC.Seconds ist ein Float-Profil), Prefixe wie *Dosing.
        foreach ([['Cl', 'Chlor'], ['PHMinus', 'pH-minus'], ['PHPlus', 'pH-plus']] as [$p, $lbl]) {
            $controls[] = $R($p . 'DosRemain', $lbl . ' Restzeit (manuell)', 2, 'HSPC.Seconds');    // col3
            $controls[] = $R($p . 'DosNextCycle', $lbl . ' naechster Zyklus', 2, 'HSPC.Seconds');   // col6
            $controls[] = $R($p . 'DosDurCur', $lbl . ' aktuelle Dauer', 2, 'HSPC.Seconds');        // col4
            $controls[] = $R($p . 'DosDurTotal', $lbl . ' Gesamt-Dauer', 2, 'HSPC.Seconds');        // col5
            $controls[] = $R($p . 'DosConsumed', $lbl . ' Dosis-Verbrauch', 2, 'HSPC.Milliliter');  // col4*FLOW
        }
        $controls[] = $R('ClPoleReversal', 'Chlor Salz-Umpolung', 2, 'HSPC.Seconds');               // col7, nur Cl
        // pH+ Sollwert (Auto-Schalter DosingPHPAuto siehe $SW-Block) — schreibbar (setDosageFull type=2)
        $controls[] = $CFG('PHPlusTarget', 'pH-plus Sollwert', 2, 'HSPC.pH');

        // --- Automatik-Schalter (schaltbar, gated) ---
        $SW = function (string $ident, string $label) {
            return ['ident' => $ident, 'type' => ControlContract::T_SWITCH, 'role' => 'pool:auto',
                'label' => $label, 'varType' => 0, 'profile' => '~Switch', 'actionable' => true];
        };
        $controls[] = $SW('DosingClAuto', 'Dosierautomatik Redox');   // Geraete-Dosierung Cl/Redox ein/aus
        $controls[] = $SW('DosingPHAuto', 'Dosierautomatik pH');      // Geraete-Dosierung pH-minus ein/aus
        $controls[] = $SW('DosingPHPAuto', 'Dosierautomatik pH-plus'); // Geraete-Dosierung pH+ (PHPCNTRL) ein/aus
        $controls[] = $SW('CircAuto', 'Umwaelzautomatik');            // automatische Filterzeit scharf

        // --- Relais: Ist-Zustand (reflect) + Modus Auto/Manuell Aus/Manuell Ein (schaltbar) ---
        $relayOpts = [
            ['value' => 0, 'caption' => 'Auto'],
            ['value' => 1, 'caption' => 'Manuell Aus'],
            ['value' => 2, 'caption' => 'Manuell Ein'],
        ];
        for ($i = 0; $i < 8; $i++) {
            $label = self::RELAY_LABELS[$i] ?? ('Relais ' . ($i + 1));
            $controls[] = $R('Relay' . $i, $label, 0, '~Switch'); // Ist an/aus (reflect)
            $controls[] = ['ident' => 'Relay' . $i . 'Mode', 'type' => ControlContract::T_SELECT,
                'role' => 'pool:relaymode', 'label' => $label . ' Modus', 'varType' => 1,
                'profile' => 'HSPC.RelayMode', 'actionable' => true, 'options' => $relayOpts];
        }

        // --- Automatik / System / Fehler / Verbindung ---
        $controls[] = $R('AutoCircOptimal', 'Optimale Filterzeit (Min)', 1);
        $controls[] = $R('ProgFilterMin', 'Programmierte Filterzeit Woche (Min)', 1);
        $controls[] = $R('ProgFilterToday', 'Programmierte Filterzeit heute (Min)', 1);
        $controls[] = $R('FilterRuntimeToday', 'Filterzeit heute (Min)', 1);
        $controls[] = $R('CpuTemp', 'CPU-Temperatur', 2, '~Temperature');
        $controls[] = $R('OperatingHours', 'Betriebsstunden', 2);
        $controls[] = $R('Firmware', 'Firmware', 3);
        $controls[] = $R('StatusFlag', 'Statusflag', 1);
        $controls[] = $R('ErrorCount', 'Fehleranzahl', 1);
        $controls[] = $R('ErrorText', 'Fehler/Meldungen', 3);
        $controls[] = $R('LinkOK', 'Verbindung', 0, '~Switch');

        // --- Tabellengetriebene, schreibbare Konfig-Variablen (skalare + Regel-Sektionen) ---
        // Aus der zentralen $CFGVARS-Map generiert. Die Regel-Sektionen (tempc/adcc/switchc)
        // sind vollstaendig enthalten: je Regelindex x Feld eine schreibbare Variable
        // (EnableAction). Dispatch via writeRuleField, Ist-Spiegel via spiegelRules().
        // Bereits vorhandene Idents (Sollwerte/Auto-Schalter) werden uebersprungen.
        $existing = ['RedoxTarget' => 1, 'PHTarget' => 1, 'PHPlusTarget' => 1,
            'DosingClAuto' => 1, 'DosingPHAuto' => 1, 'DosingPHPAuto' => 1];
        $scalarGrps = ['rdx' => 1, 'ph' => 1, 'sensor' => 1, 'network' => 1, 'other' => 1,
            'email' => 1, 'contacts' => 1, 'dtc' => 1, 'cal' => 1,
            'tempc' => 1, 'adcc' => 1, 'switchc' => 1];
        foreach ($this->cfgVars() as $ident => $e) {
            if (!isset($scalarGrps[$e['grp']]) || isset($existing[$ident])) {
                continue;
            }
            if (!empty($e['ro'])) {
                $controls[] = $R($ident, $e['label'], $e['vt'], $e['profile']); // read-only Reflect
                continue;
            }
            $controls[] = $CFG($ident, $e['label'], $e['vt'], $e['profile']);
        }

        return [
            'domain'   => 'pool',
            'title'    => 'Pool',
            'icon'     => 'Drops',
            'controls' => $controls,
            'managementActions' => [
                ['op' => 'configureConnection', 'label' => 'Verbindung konfigurieren'],
                ['op' => 'configureMapping',    'label' => 'Spalten/Schwellen konfigurieren'],
                ['op' => 'configureTReal',      'label' => 'Echte Wassertemperatur (TReal) konfigurieren'],
                ['op' => 'getConfig',           'label' => 'Konfiguration lesen (Diagnose)'],
                ['op' => 'probe',               'label' => 'Verbindung testen (Diagnose)'],
                ['op' => 'poll',                'label' => 'Jetzt abfragen'],
                ['op' => 'readRaw',             'label' => 'Rohdaten lesen (Diagnose)'],
                ['op' => 'readErrors',          'label' => 'Fehlerlog lesen'],
                ['op' => 'writeBudget',         'label' => 'Schreibbremse: Stand abfragen (liest)'],
                ['op' => 'clearErrors',         'label' => 'Fehlerlog loeschen (schreibt)'],
                ['op' => 'setRelayMode',        'label' => 'Relais Auto/Manuell (schreibt)'],
                ['op' => 'doDosage',            'label' => 'Manuelle Dosierung (schreibt)'],
                ['op' => 'resetContainer',      'label' => 'Kanister/Zelle zuruecksetzen (schreibt)'],
                // Konfiguration lesen (Diagnose)
                ['op' => 'getDosageConfig',     'label' => 'Dosier-Konfiguration lesen'],
                ['op' => 'getRules',            'label' => 'Steuerregeln lesen'],
                ['op' => 'getNetwork',          'label' => 'Netzwerk lesen'],
                ['op' => 'getRelayNames',       'label' => 'Relaisnamen lesen'],
                ['op' => 'getDtc',              'label' => 'Alarm-Matrix lesen'],
                ['op' => 'getSensorConfig',     'label' => 'Sensor-Konfig lesen (ADC/BNC/1-Wire/IO)'],
                ['op' => 'getRomCodes',         'label' => '1-Wire ROM-Codes lesen'],
                ['op' => 'computeCirculation',  'label' => 'Umwaelzzeit berechnen (TruePoolTemp)'],
                ['op' => 'scheduleStatus',      'label' => 'Wochenplan-Status'],
                ['op' => 'scheduleFromDevice',  'label' => 'Wochenplan vom Geraet uebernehmen (Probelauf; apply=true schreibt)'],
                ['op' => 'sendSchedule',        'label' => 'Wochenplan an Controller senden (schreibt)'],
                // Konfiguration schreiben (gated)
                ['op' => 'setDosageConfig',     'label' => 'Dosier-Sollwerte setzen (schreibt)'],
                ['op' => 'setRules',            'label' => 'Steuerregeln setzen (schreibt)'],
                ['op' => 'setNetwork',          'label' => 'Netzwerk setzen (schreibt)'],
                ['op' => 'setDeviceTime',       'label' => 'Geraeteuhr stellen (schreibt)'],
                ['op' => 'setRelayName',        'label' => 'Relaisname setzen (schreibt)'],
                ['op' => 'setDtc',              'label' => 'Alarm-Matrix setzen (schreibt)'],
                ['op' => 'setDtcField',         'label' => 'Alarm-Meldestufe eines Codes setzen (RMW, schreibt)'],
                ['op' => 'setSensorConfig',     'label' => 'Sensor-Konfig setzen (schreibt)'],
                // --- Dosierung voll (S3, RMW) ---
                ['op' => 'setDosageFull',       'label' => 'Dosier-Regler VOLL konfigurieren (schreibt, RMW)'],
                // --- Temperatur-/Solar-Regeln TEMPC (S4) ---
                ['op' => 'getTempRule',         'label' => 'Temperaturregel lesen (TEMPC)'],
                ['op' => 'setTempRule',         'label' => 'Temperaturregel setzen (schreibt)'],
                // --- Analog-/Digital-IO-Regeln ADCC/SWITCHC (S5) ---
                ['op' => 'getAdccRule',         'label' => 'Analog-Regel lesen (ADCC)'],
                ['op' => 'setAdccRule',         'label' => 'Analog-Regel setzen (schreibt)'],
                ['op' => 'getSwitchcRule',      'label' => 'Digital-IO-Regel lesen (SWITCHC)'],
                ['op' => 'setSwitchcRule',      'label' => 'Digital-IO-Regel setzen (schreibt)'],
                // --- Sensor-Konfig Einzelkanal-RMW + Rechenhelfer (S6) ---
                ['op' => 'setSensorChannel',    'label' => 'Sensor-Einzelkanal setzen (RMW, schreibt)'],
                ['op' => 'calcImpulseGain',     'label' => 'Impulszaehler-Gain berechnen'],
                ['op' => 'calcGainOffset',      'label' => 'ADC 2-Punkt-Gain/Offset berechnen'],
                // --- Netzwerk RMW (S7) ---
                ['op' => 'setNetworkFields',    'label' => 'Netzwerk setzen (RMW, schreibt)'],
                // --- Alarme/EMAIL/CONTACTS/OTHER (S8) ---
                ['op' => 'getEmail',            'label' => 'E-Mail/Alarm-Konfiguration lesen'],
                ['op' => 'getContacts',         'label' => 'Kontakte lesen'],
                ['op' => 'getOther',            'label' => 'Sonstiges (Zeitzone/Ext-Relais/Durchfluss) lesen'],
                ['op' => 'setEmailAccount',     'label' => 'E-Mail-Konto/Alarm-Mail setzen (schreibt)'],
                ['op' => 'setEmailServer',      'label' => 'SMTP-Server setzen (schreibt)'],
                ['op' => 'setContacts',         'label' => 'Kontakte setzen (schreibt)'],
                ['op' => 'sendTestMail',        'label' => 'Test-Mail senden (schreibt/loest aus)'],
                ['op' => 'setOther',            'label' => 'Sonstiges setzen (schreibt)'],
                // --- Kalibrierung (Messkette! Doppel-Gate: Armed + CalibrationAllowed) ---
                ['op' => 'getCal',              'label' => 'Kalibrierung lesen (Diagnose)'],
                ['op' => 'setHwCal',            'label' => 'ADC-Hardware-Kalibrierung setzen (Messkette! Freigabe)'],
                ['op' => 'setRdxPhCal',         'label' => 'Elektroden-Kalibrierung setzen (Messkette! Freigabe)'],
                ['op' => 'setArmed',            'label' => 'Scharfschalten / Schatten-Modus'],
            ],
            'capabilities' => [
                'armed'     => (bool) $this->cfgVal('armed', false),
                'connected' => $this->cfgVal('host', '') !== '',
            ],
        ];
    }

    // ==================================================================
    // Variablen-Gruppierung (Baum-Struktur)
    // ==================================================================

    /**
     * Das Pool-Modul fuehrt ~500 Statusvariablen. Statt flacher Ablage werden
     * sie in beschriftete Kategorien einsortiert (EntityModule::materializeControlsGrouped).
     */
    protected function usesVariableGroups(): bool
    {
        return true;
    }

    /**
     * Klassifiziert einen Control-Ident in eine Baum-Kategorie (Erst-Treffer
     * gewinnt; Praefix- oder Exakt-Match). Sammelgruppe „Messwerte".
     */
    protected function controlGroup(string $ident, Control $c): string
    {
        static $rules = [
            ['Relais',             ['Relay']],
            ['Regeln Filter',      ['FilterSchedule', 'ProgFilterMin', 'ProgFilterToday', 'AutoCircOptimal', 'CircAuto', 'FilterRuntime']],
            ['Regeln Solar-Temp',  ['TempRule']],
            ['Regeln Analog',      ['AdccR']],
            ['Regeln Digital-IO',  ['SwcR']],
            ['Dosierung Chlor',    ['RdxCfg', 'ClDos', 'ClPole', 'ClConsumption', 'ClDosing', 'ClLevel', 'RedoxTarget', 'DosingClAuto', 'Redox']],
            ['Dosierung pH-minus', ['PHMinus', 'PHTarget', 'DosingPHAuto']],
            ['Dosierung pH-plus',  ['PHPlus', 'DosingPHPAuto']],
            ['Sensorik',           ['CfgOw', 'CfgAdc', 'CfgIo', 'CfgBnc']],
            ['Netzwerk',           ['Net']],
            ['Alarme & Meldung',   ['Mail', 'Smtp', 'Sms', 'Contact', 'ErrorText', 'ErrorCount', 'StatusFlag', 'Dtc']],
            ['Kalibrierung',       ['HwCal', 'ElCal']],
            ['System & Verbindung', ['CpuTemp', 'OperatingHours', 'Firmware', 'LinkOK']],
        ];
        foreach ($rules as [$name, $prefixes]) {
            foreach ($prefixes as $p) {
                if ($ident === $p || strncmp($ident, $p, strlen($p)) === 0) {
                    return $name;
                }
            }
        }
        return 'Messwerte';
    }

    // ==================================================================
    // Lebenszyklus
    // ==================================================================

    // ==================================================================
    // Native Instanz-Properties (Symcon-Konzept: Konfig im Instanz-Formular,
    // NICHT im FabricStore-JSON). Alle frueheren Store-Keys sind jetzt Properties.
    // ==================================================================

    public function Create()
    {
        parent::Create();
        // Verbindung
        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyString('User', 'admin');
        $this->RegisterPropertyString('Password', '');
        $this->RegisterPropertyInteger('Port', 80);
        $this->RegisterPropertyInteger('Timeout', 10);
        $this->RegisterPropertyInteger('ConnectTimeout', 5);
        $this->RegisterPropertyBoolean('UseHTTPS', false);
        $this->RegisterPropertyInteger('PollInterval', self::POLL_MS_DEF);
        // Schreibbremse: Mindestabstand zwischen zwei Schreibvorgaengen und Stundenbudget.
        $this->RegisterPropertyInteger('WriteGapMs', 2000);
        $this->RegisterPropertyInteger('WritesPerHour', 60);
        // Spaltenzuordnung
        $this->RegisterPropertyInteger('PoolTempCol', 8);
        $this->RegisterPropertyInteger('OutsideCol', 9);
        $this->RegisterPropertyInteger('SolarCol', 10);
        $this->RegisterPropertyInteger('ReturnCol', 11);
        $this->RegisterPropertyInteger('PumpTempCol', 12);
        $this->RegisterPropertyInteger('RedoxCol', 6);
        $this->RegisterPropertyInteger('PhCol', 7);
        $this->RegisterPropertyInteger('PressureCol', 3);
        $this->RegisterPropertyInteger('FlowVolCol', 4);
        $this->RegisterPropertyInteger('FlowRateCol', 24);
        $this->RegisterPropertyInteger('PumpRelayIndex', 0);
        $this->RegisterPropertyFloat('FlowThreshold', 0.5);
        $this->RegisterPropertyInteger('FlowSettleSeconds', 120);
        // Umwaelz-Berechnung
        $this->RegisterPropertyFloat('PoolSize', 0.0);
        $this->RegisterPropertyFloat('CircFlowRate', 0.0);
        // TReal (echte Wassertemperatur)
        $this->RegisterPropertyBoolean('TrealEnabled', false);
        $this->RegisterPropertyInteger('TrealSourceVarId', 0);
        $this->RegisterPropertyFloat('MinPlausible', 0.0);
        $this->RegisterPropertyFloat('MaxPlausible', 45.0);
        $this->RegisterPropertyInteger('MaxStaleSeconds', 1800);
        // Umwälz-Wochenplan: welche TIMEC-Regel steuert die Filterpumpe
        $this->RegisterPropertyInteger('FilterRuleIndex', 0);
        // Umwaelz-Wochenplan: Anzahl TIMEC-Regeln (ab FilterRuleIndex), eine je Wochentag-Gruppe.
        $this->RegisterPropertyInteger('FilterRuleCount', 4);
        // Umwaelzautomatik: Startzeit des automatischen Filterfensters (Min ab Mitternacht)
        $this->RegisterPropertyInteger('CircWindowStart', 480);
        // Scharfschalten (reale Schreibzugriffe)
        $this->RegisterPropertyBoolean('Armed', false);
        // Extra-Gate: Kalibrierung schreibt in die Messkette -> nur mit ausdruecklicher Freigabe.
        $this->RegisterPropertyBoolean('CalibrationAllowed', false);
        // Alarm-/DTC-Codes, fuer die je eine schreibbare Meldestufe-Variable (DtcLevel<code>)
        // erzeugt wird. Komma-separierte Liste (z.B. '3,7,12'); leer = keine DTC-Variablen.
        $this->RegisterPropertyString('DtcCodes', '');
    }

    /** Bindungs-Link (Baum-Transparenz) auf den externen In-Pool-Sensor. */
    protected function bindingTargets(): array
    {
        $vid = (int) $this->cfgVal('trealSourceVarId', 0);
        if ($vid > 0 && function_exists('IPS_ObjectExists') && @\IPS_ObjectExists($vid)) {
            return [['ident' => 'bl_TrealSourceVarId', 'name' => 'Wassertemperatur extern', 'targetId' => $vid]];
        }
        return [];
    }

    protected function setupTimers(): void
    {
        $this->RegisterTimer(self::TIMER_POLL, 0, 'HSPC_Poll($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        $this->cfgVarsCache = null;    // CFGVARS-Map neu aufbauen (DtcCodes kann sich geaendert haben)
        $this->ensureProfiles();       // MUSS vor registerControls (parent) laufen
        parent::ApplyChanges();

        $cfg    = $this->cfg();
        $active = trim((string) ($cfg['host'] ?? '')) !== '';
        $ms     = max(5000, (int) ($cfg['pollInterval'] ?? self::POLL_MS_DEF));
        $this->SetTimerInterval(self::TIMER_POLL, $active ? $ms : 0);
        $this->syncReferences();
        $this->ensureRelayLogging();

        // Wochenplan-Baseline: adoptierten Ist-Stand als Referenz merken, damit er
        // nicht faelschlich als "pending" (zu schreiben) gilt.
        $eid = $this->scheduleEventId();
        if ($eid > 0) {
            $rt = $this->readRt();
            if (($rt['schedHash'] ?? '') === '') {
                $rt['schedHash'] = md5(json_encode($this->groupsFromEvent($eid)));
                $this->writeRt($rt);
            }
        }
    }

    // ==================================================================
    // Poll — GetState.csv lesen und alle Statusvariablen spiegeln
    // ==================================================================

    public function Poll(): void
    {
        $client = $this->client();
        if ($client === null) {
            @$this->SetValue('LinkOK', false);
            return;
        }
        $st = $client->getState();
        if (empty($st['ok'])) {
            @$this->SetValue('LinkOK', false);
            $this->SendDebug('HSPC.poll', 'unreachable: ' . (string) ($st['error'] ?? '?'), 0);
            return;
        }
        @$this->SetValue('LinkOK', true);
        $cfg = $this->cfg();

        $col = fn(string $key, int $def) => (int) ($cfg[$key] ?? $def);
        $inline = PoolClient::colVal($st, $col('poolTempCol', 8));

        @$this->SetValue('WaterTempInline', $this->r2($inline));
        @$this->SetValue('TempOutside', $this->r2(PoolClient::colVal($st, $col('outsideCol', 9))));
        @$this->SetValue('TempSolar', $this->r2(PoolClient::colVal($st, $col('solarCol', 10))));
        @$this->SetValue('TempReturn', $this->r2(PoolClient::colVal($st, $col('returnCol', 11))));
        @$this->SetValue('TempPumpHousing', $this->r2(PoolClient::colVal($st, $col('pumpTempCol', 12))));
        @$this->SetValue('PH', $this->r2(PoolClient::colVal($st, $col('phCol', 7))));
        @$this->SetValue('Redox', $this->r2(PoolClient::colVal($st, $col('redoxCol', 6))));
        @$this->SetValue('Pressure', $this->r2(PoolClient::colVal($st, $col('pressureCol', 3))));
        // Durchfluss kann nie negativ sein -> Betrag (Rohwert wird bei Stillstand negativ,
        // sonst liefe das Flussdiagramm rueckwaerts "aus dem Pool").
        $fv = PoolClient::colVal($st, $col('flowVolCol', 4));
        @$this->SetValue('FlowVolume', $this->r2($fv !== null ? abs($fv) : 0.0));

        $flowRate = PoolClient::colVal($st, $col('flowRateCol', 24));
        @$this->SetValue('FlowRate', $this->r2($flowRate));

        // Relais spiegeln: Ist-Zustand (bit0) + Modus (Auto/Manuell Aus/Manuell Ein)
        $rbase = PoolClient::COL_RELAY_BASE;
        for ($i = 0; $i < 8; $i++) {
            $state = PoolClient::colVal($st, $rbase + $i);
            $code  = $state === null ? 0 : (int) round($state);
            @$this->SetValue('Relay' . $i, ($code & 1) === 1);
            @$this->SetValue('Relay' . $i . 'Mode', $this->codeToMode($code));
        }
        $pumpCode = PoolClient::colVal($st, $rbase + $col('pumpRelayIndex', 0));
        $pumpOn = $pumpCode !== null && ((int) round($pumpCode) & 1) === 1;

        // Kanister
        $cb = PoolClient::COL_CANISTER;
        @$this->SetValue('ClLevel', $this->r2(PoolClient::colVal($st, $cb + 0)));
        @$this->SetValue('PHMinusLevel', $this->r2(PoolClient::colVal($st, $cb + 1)));
        @$this->SetValue('PHPlusLevel', $this->r2(PoolClient::colVal($st, $cb + 2)));
        @$this->SetValue('ClConsumption', $this->r2(PoolClient::colVal($st, $cb + 3)));
        @$this->SetValue('PHMinusConsumption', $this->r2(PoolClient::colVal($st, $cb + 4)));
        @$this->SetValue('PHPlusConsumption', $this->r2(PoolClient::colVal($st, $cb + 5)));

        // Dosier-Aktivitaet aus den Dosierpumpen-Relais (Chlor=R2, pH-=R3, pH+=R4)
        $relOn = function (int $idx) use ($st, $rbase): bool {
            $v = PoolClient::colVal($st, $rbase + $idx);
            return $v !== null && ((int) round($v) & 1) === 1;
        };
        @$this->SetValue('ClDosing', $relOn(2));
        @$this->SetValue('PHMinusDosing', $relOn(3));
        @$this->SetValue('PHPlusDosing', $relOn(4));

        // --- Dosier-Laufzeit aus GetDos.csv (Spalten PROTOCOLS.md:60-70), jeder Poll ---
        // Ergaenzt (ersetzt NICHT) die relais-bit-basierten *Dosing-Vars oben.
        $dos = $client->getDos();
        if (!empty($dos['ok'])) {
            $rtDos = $this->readRt();
            $flow  = $rtDos['flow'] ?? [];   // FLOW-Verhaeltnisse aus der 300s-Config-Lesung (s.u.)
            foreach ([0 => 'Cl', 1 => 'PHMinus', 2 => 'PHPlus'] as $row => $p) {
                $d = PoolClient::dosRow($dos, $row);
                @$this->SetValue($p . 'DosRemain', (float) $d['remaining']);
                @$this->SetValue($p . 'DosNextCycle', (float) $d['nextCycle']);
                @$this->SetValue($p . 'DosDurCur', (float) $d['actualDur']);
                @$this->SetValue($p . 'DosDurTotal', (float) $d['totalDur']);
                // Verbrauch ml = actualDur * (FLOW_ML/FLOW_SEC); nur wenn FLOW-Cache vorhanden.
                $fl = $flow[$row] ?? null;
                if (is_array($fl) && (int) ($fl['sec'] ?? 0) > 0) {
                    @$this->SetValue($p . 'DosConsumed',
                        $this->r2($d['actualDur'] * ((float) $fl['ml'] / (int) $fl['sec'])));
                }
            }
            @$this->SetValue('ClPoleReversal', (float) PoolClient::dosRow($dos, 0)['poleReversal']); // nur Cl/Salz
        }

        // System
        @$this->SetValue('Firmware', (string) ($st['firmware'] ?? ''));
        @$this->SetValue('StatusFlag', (int) ($st['statusFlag'] ?? 0));
        @$this->SetValue('CpuTemp', $this->r2(PoolClient::colVal($st, PoolClient::COL_CPUTEMP)));

        // Fehlerlog (gedrosselt: hoechstens alle 300 s, SD-Schonung)
        $rt = $this->readRt();
        if (time() - (int) ($rt['errTs'] ?? 0) > 300) {
            $errs = $this->client()->getErrors();
            if (!empty($errs['ok'])) {
                @$this->SetValue('ErrorCount', (int) ($errs['count'] ?? 0));
                @$this->SetValue('ErrorText', implode("\n", array_slice($errs['lines'] ?? [], -20)));
                $rt['errTs'] = time();
                $this->writeRt($rt);
            }
        }

        // Regel-Sensor-/Eingangsprofile mit Live-Namen nachziehen (gedrosselt: alle 3600 s).
        if (time() - (int) ($rt['sensTs'] ?? 0) > 3600) {
            $this->refreshRuleSensorProfiles($client);
            $rt = $this->readRt();
            $rt['sensTs'] = time();
            $this->writeRt($rt);
        }

        // --- Durchfluss-abhaengige TruePoolTemp-Fusion (Nutzer-Anforderung #2) ---
        $this->fuseWaterTemp($inline, $flowRate, $pumpOn);

        // --- Umwaelzautomatik: optimale Filterzeit berechnen + einstellen (gated) ---
        $this->circAdjust();

        // --- Wochenplan -> Controller zurueckschreiben (nur wenn Umwaelzautomatik AUS) ---
        $this->reconcileSchedule();

        // Empfohlene Umwaelzzeit aus der ECHTEN Wassertemperatur (kein Geraetezugriff).
        $trueTemp = @$this->GetValue('TruePoolTemp');
        if (is_numeric($trueTemp) && (float) $trueTemp > 0) {
            @$this->SetValue('AutoCircOptimal', PoolClient::optimalFilterMinutes(
                (float) $trueTemp, (float) ($cfg['poolSize'] ?? 0), (float) ($cfg['flowRate'] ?? 0)));
        }

        // Filterzeit heute = EIN-Dauer des Pumpenrelais seit Mitternacht (aus Archiv).
        @$this->SetValue('FilterRuntimeToday', $this->pumpOnMinutesToday());

        // Betriebsstunden = laufender Zaehler der Pumpen-EIN-Zeit (persistent).
        $now = time();
        $rt = $this->readRt();
        $lastTick = (int) ($rt['opTick'] ?? $now);
        $rt['opTick'] = $now;
        $this->writeRt($rt);
        if ($pumpOn && $now > $lastTick && ($now - $lastTick) < 3600) {
            $cur = (float) @$this->GetValue('OperatingHours');
            @$this->SetValue('OperatingHours', round($cur + ($now - $lastTick) / 3600.0, 2));
        }

        // Programmierte Filterzeit: getrennt fuer die WOCHE und fuer HEUTE.
        //
        // Frueher gab es nur einen Wert, der ALLE Gruppen aufaddierte. Ein Plan mit einer
        // Gruppe je Wochentag (7 x 630 Min) ergab damit 4410 Min - die Wochensumme, aber
        // beschriftet und gelesen als Tageswert und damit unvergleichbar mit "Filterzeit
        // heute" direkt daneben. Jetzt steht beides nebeneinander: die Woche als Budget,
        // der heutige Tag als Vergleichsgroesse zur tatsaechlichen Laufzeit.
        //
        // Days ist eine Bitmaske: Mo=1, Di=2, Mi=4, Do=8, Fr=16, Sa=32, So=64;
        // date('N') liefert 1 (Montag) bis 7 (Sonntag).
        $eidP = $this->scheduleEventId();
        if ($eidP > 0) {
            $groupsP  = $this->groupsFromEvent($eidP);
            $heuteBit = 1 << ((int) date('N') - 1);
            $sumWoche = 0;
            $sumHeute = 0;
            foreach ($groupsP as $gP) {
                $dauer = 0;
                foreach ($gP['windows'] as $wP) {
                    $dauer += max(0, (int) $wP[1] - (int) $wP[0]);
                }
                $tage = (int) ($gP['days'] ?? 0);
                // Eine Gruppe gilt fuer JEDEN gesetzten Tag - fuer die Wochensumme zaehlt
                // ihre Dauer also so oft, wie Tage gesetzt sind (Mo-Fr = fuenfmal).
                $sumWoche += $dauer * substr_count(decbin($tage & 0x7F), '1');
                if (($tage & $heuteBit) !== 0) {
                    $sumHeute += $dauer;
                }
            }
            @$this->SetValue('ProgFilterMin', $sumWoche);
            @$this->SetValue('ProgFilterToday', $sumHeute);
        }

        // Dosier-Sollwerte (pH/Redox) gedrosselt aus der Konfig lesen (alle 300 s).
        if (time() - (int) ($rt['dosTs'] ?? 0) > 300) {
            $cl = $this->client();
            if ($cl !== null) {
                $rdx = $cl->getDosageConfig(0);
                $phm = $cl->getDosageConfig(1);
                $php = $cl->getDosageConfig(2); // pH+ = PHPCNTRL
                if (!empty($rdx['ok'])) {
                    @$this->SetValue('RedoxTarget', $this->r2((float) ($rdx['config']['target'] ?? 0)));
                    @$this->SetValue('DosingClAuto', (bool) ($rdx['config']['enabled'] ?? false));
                }
                if (!empty($phm['ok'])) {
                    @$this->SetValue('PHTarget', $this->r2((float) ($phm['config']['target'] ?? 0)));
                    @$this->SetValue('DosingPHAuto', (bool) ($phm['config']['enabled'] ?? false));
                }
                if (!empty($php['ok'])) {
                    @$this->SetValue('PHPlusTarget', $this->r2((float) ($php['config']['target'] ?? 0)));
                    @$this->SetValue('DosingPHPAuto', (bool) ($php['config']['enabled'] ?? false));
                }
                // Vollstaendiger Ist-Spiegel aller schreibbaren Dosier-Konfig-Variablen.
                $this->spiegelDosage(0, $rdx);
                $this->spiegelDosage(1, $phm);
                $this->spiegelDosage(2, $php);
                // FLOW-Verhaeltnisse fuer GetDos-Verbrauch cachen (Cl: flowValue/flowTime; pH: flowMl/flowSec).
                // EIN readRt/writeRt in diesem Block (kein zweites), damit opTick nicht ueberschrieben wird.
                $rt = $this->readRt();
                $rt['flow'] = [
                    0 => ['ml' => (float) ($rdx['config']['flowValue'] ?? 0), 'sec' => (int) ($rdx['config']['flowTime'] ?? 0)],
                    1 => ['ml' => (float) ($phm['config']['flowMl'] ?? 0), 'sec' => (int) ($phm['config']['flowSec'] ?? 0)],
                    2 => ['ml' => (float) ($php['config']['flowMl'] ?? 0), 'sec' => (int) ($php['config']['flowSec'] ?? 0)],
                ];
                $rt['dosTs'] = time();
                $this->writeRt($rt);
            }
        }

        // Ist-Spiegel der uebrigen skalaren Konfig-Sektionen (je eigene Drossel).
        $this->spiegelSensorCfg();  // ADC/BNC/1-Wire/IO (300s)
        $this->spiegelNetOther();   // Netzwerk + OTHER (300s)
        $this->spiegelMiscCfg();    // E-Mail/SMTP/Kontakte/DTC/Kalibrierung (900s)
        $this->spiegelRules();      // TEMPC/ADCC/SWITCHC Regel-Felder (300s)
    }

    /**
     * Bildet die autoritative Wassertemperatur. Bei Durchfluss (Anstroemung ueber
     * Schwelle ODER Pumpe an, nach Settle-Zeit) gilt der Inline-Sensor; sonst der
     * externe In-Pool-Sensor. Ohne gueltigen externen Wert Fallback auf Inline.
     * KEIN Geraete-Writeback (Option A).
     */
    private function fuseWaterTemp(?float $inline, ?float $flowRate, bool $pumpOn): void
    {
        $cfg   = $this->cfg();
        $thr   = (float) ($cfg['flowThreshold'] ?? 0.5);
        $settle = max(0, (int) ($cfg['flowSettleSeconds'] ?? 120));
        $now   = time();

        $flowNow = ($flowRate !== null && $flowRate > $thr) || $pumpOn;

        // Flanken-/Settle-Verfolgung im volatilen RtState.
        $rt = $this->readRt();
        if ($flowNow !== (bool) ($rt['flowState'] ?? false)) {
            $rt['flowState']  = $flowNow;
            $rt['flowSince']  = $now;
            $this->writeRt($rt);
        }
        $settled = ($now - (int) ($rt['flowSince'] ?? $now)) >= $settle;
        $flowSettledOn = $flowNow && $settled;

        @$this->SetValue('FlowActive', $flowNow);

        // Externen Sensor lesen + plausibilisieren.
        $ext        = null;
        $extEnabled = (bool) ($cfg['trealEnabled'] ?? false) && (int) ($cfg['trealSourceVarId'] ?? 0) > 0;
        if ($extEnabled) {
            $ext = $this->readExternalTemp((int) $cfg['trealSourceVarId'], $cfg);
            if ($ext !== null) {
                @$this->SetValue('TrealExternal', $this->r2($ext));
            }
        }

        // Quellen-Entscheidung.
        if ($flowSettledOn && $inline !== null) {
            $true = $inline;
            $src  = 'inline';
        } elseif ($extEnabled && $ext !== null) {
            $true = $ext;
            $src  = 'extern';
        } elseif ($inline !== null) {
            $true = $inline;
            $src  = $extEnabled ? 'fallback' : 'inline';
        } else {
            return; // nichts Sinnvolles
        }

        @$this->SetValue('TruePoolTemp', $this->r2($true));
        @$this->SetValue('TempSource', $src);
    }

    /** Externen In-Pool-Temperatursensor lesen und plausibilisieren (null wenn ungueltig). */
    private function readExternalTemp(int $vid, array $cfg): ?float
    {
        if ($vid <= 0 || !function_exists('IPS_VariableExists') || !@\IPS_VariableExists($vid)) {
            return null;
        }
        $val = @\GetValue($vid);
        if (!is_numeric($val)) {
            return null;
        }
        $val = (float) $val;
        $min = (float) ($cfg['minPlausible'] ?? 0.0);
        $max = (float) ($cfg['maxPlausible'] ?? 45.0);
        if ($val < $min || $val > $max) {
            return null;
        }
        $maxStale = max(0, (int) ($cfg['maxStaleSeconds'] ?? 1800));
        if ($maxStale > 0) {
            $v = @\IPS_GetVariable($vid);
            if (is_array($v) && isset($v['VariableUpdated']) && (time() - (int) $v['VariableUpdated']) > $maxStale) {
                return null; // veraltet
            }
        }
        return $val;
    }

    // ==================================================================
    // Umwälz-Wochenplan  <->  Controller (TIMEC-Regel)
    // Der Wochenplan-Ereignis (Ident 'FilterSchedule', Kind der Instanz) ist die
    // editierbare Quelle. Bei Aenderung wird er in eine TIMEC-Regel uebersetzt und
    // (nur bei armed) an den Controller geschrieben. Tag-/Zeitfenster-Format gem.
    // PROTOCOLS §9/§13. Kein Schreiben ohne Armed.
    // ==================================================================

    private function scheduleEventId(): int
    {
        $eid = @$this->GetIDForIdent('FilterSchedule');
        return (is_int($eid) && $eid > 0) ? $eid : 0;
    }

    /** Regel-Indizes, die dem Filter-Wochenplan gehoeren (zusammenhaengendes Budget, 0..15). */
    private function filterRuleIndices(): array
    {
        $base = max(0, min(15, (int) $this->ReadPropertyInteger('FilterRuleIndex')));
        $cnt  = max(1, (int) $this->ReadPropertyInteger('FilterRuleCount'));
        $cnt  = min($cnt, 16 - $base); // nie ueber Index 15 hinaus (TIMEC count=16)
        return range($base, $base + $cnt - 1);
    }

    /**
     * Symcon-Tagesmaske -> Controller-Maske. Beide zaehlen GLEICH (Bit0=Mo..Bit6=So).
     *
     * Hier stand frueher eine Verschiebung um ein Bit ("Controller Bit0=So"), uebernommen aus
     * PROTOCOLS.md. Die Original-Oberflaeche des Geraets belegt das Gegenteil: timectrl.htm
     * beschriftet die Kaestchenreihe mit "Wochentage (Mo-So)" und legt Kaestchen k auf Bit k.
     * Jeder geschriebene Plan war dadurch um einen Wochentag verschoben - unsichtbar, solange
     * alle sieben Tage gleich programmiert sind (127). Die Umrechnung liegt jetzt an EINER
     * Stelle, in TimecSchedule.
     */
    private static function symconToCtrlDays(int $sym): int
    {
        return TimecSchedule::symconToCtrlDays($sym);
    }

    /**
     * Liest das Wochenplan-Ereignis und liefert JE Wochenplan-GRUPPE mit Ein-Fenster
     * einen Satz {days(sym), windows:[[start,end]..max4]}. KEINE Zusammenfassung mehr —
     * jede Wochentag-Gruppe wird zu einer eigenen TIMEC-Regel (S2).
     */
    /** @var array Hinweise aus der letzten Wochenplan-Uebersetzung (Fenster-/Regel-Grenzen). */
    private $planWarn = [];

    /** Tagesmaske als Kuerzel, fuer lesbare Meldungen. */
    private static function daysLabel(int $sym): string
    {
        $n = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];
        $o = [];
        for ($i = 0; $i < 7; $i++) { if ($sym & (1 << $i)) { $o[] = $n[$i]; } }
        return $o ? implode('+', $o) : '(ohne Tag)';
    }

    private function groupsFromEvent(int $eid): array
    {
        $this->planWarn = [];
        $e = @\IPS_GetEvent($eid);
        $groups = is_array($e) ? ($e['ScheduleGroups'] ?? []) : [];
        $out = [];
        foreach ($groups as $g) {
            $pts = $g['Points'] ?? [];
            if (!$pts) {
                continue;
            }
            usort($pts, static function ($a, $b) {
                return (($a['Start']['Hour'] ?? 0) * 60 + ($a['Start']['Minute'] ?? 0))
                     - (($b['Start']['Hour'] ?? 0) * 60 + ($b['Start']['Minute'] ?? 0));
            });
            $open = null;
            $local = [];
            $hasEin = false;
            foreach ($pts as $p) {
                $min = (int) ($p['Start']['Hour'] ?? 0) * 60 + (int) ($p['Start']['Minute'] ?? 0);
                $a = (int) ($p['ActionID'] ?? 0);
                if ($a === 1) {
                    // Angrenzendes "Ein" (neues Ein, waehrend schon offen): das vorherige Fenster
                    // HIER schliessen und ein neues oeffnen -> zwei beruehrende Fenster bleiben
                    // getrennt (Zeit1-4 exakt wie in der Original-UI), statt zu verschmelzen.
                    if ($open !== null && $min > $open) {
                        $local[] = [$open, $min];
                    }
                    $open = $min;
                    $hasEin = true;
                } elseif ($a === 0 && $open !== null) {
                    $local[] = [$open, $min];
                    $open = null;
                }
            }
            if ($open !== null) {
                $local[] = [$open, 1439];
            }
            if ($hasEin && $local) {
                // Frueher wurde hier bei mehr als vier Fenstern abgeschnitten (je Regel passen
                // nur vier). Das uebernimmt jetzt TimecSchedule, und zwar ohne Verlust: mehr
                // Fenster bekommen eine zweite Regel mit derselben Tagesmaske.
                $out[] = ['days' => (int) ($g['Days'] ?? 0), 'windows' => $local];
            }
        }
        // Tage mit gleichem Fensterbild gehoeren in EINE Regel (die Regel traegt die Tagesmaske
        // selbst). Symcon legt im Wochenplan-Editor pro Tag eine eigene Gruppe an; wer Mo-So
        // gleich programmiert, hat sieben identische Gruppen. mergeIdenticalDays macht daraus
        // eine — und sortiert zugleich deterministisch, damit der schedHash stabil bleibt.
        $out = TimecSchedule::mergeIdenticalDays($out);

        // Eine Warnung ueber zu wenige Regeln gibt es hier nicht mehr: wie viele Regeln der Plan
        // wirklich braucht, weiss erst der Transformer beim Schreiben (nach dem Verschmelzen und
        // gegen den Ist-Stand des Geraets). Er meldet es von dort nach $planWarn.
        if ($this->planWarn) {
            $this->LogMessage('Pool-Wochenplan: ' . implode(' | ', $this->planWarn), KL_WARNING);
            @$this->SetValue('ErrorText', implode("\n", $this->planWarn));
        }
        return $out;
    }

    /**
     * RUECKRICHTUNG: schreibt den Symcon-Wochenplan aus den TIMEC-Regeln des Geraets.
     *
     * Gedacht fuer den Fall, dass jemand am Controller selbst (oder mit der Original-Oberflaeche)
     * Zeiten geaendert hat und Symcon nachziehen soll. Standard ist ein PROBELAUF - geschrieben
     * wird nur mit apply=true, weil hier ein bestehender Wochenplan ueberschrieben wird.
     *
     * Bewusste Einschraenkung: die GRUPPENSTRUKTUR des Ereignisses bleibt, wie sie ist. Symcon
     * legt Gruppen an, nicht wir; Gruppen anzulegen oder zu loeschen waere der riskante Teil und
     * ist hier nicht noetig, solange jede Gruppe fuer ihre Tage ein einheitliches Bild bekommt.
     * Passt das Geraetebild nicht zur Gruppierung (eine Gruppe deckt Tage mit UNTERSCHIEDLICHEN
     * Zeiten ab), wird NICHTS geschrieben und der Konflikt gemeldet - halb geschriebene
     * Wochenplaene sind schlimmer als gar keine.
     */
    private function mgmtScheduleFromDevice(array $args): array
    {
        $apply = (bool) ($args['apply'] ?? false);
        $eid   = $this->scheduleEventId();
        if ($eid === 0) {
            return ['ok' => false, 'error' => 'no_schedule_event'];
        }
        $client = $this->client();
        if ($client === null) {
            return ['ok' => false, 'error' => 'not_configured'];
        }
        $cur = $client->getRules('TIMEC');
        if (empty($cur['ok'])) {
            return ['ok' => false, 'error' => 'read_failed'];
        }
        $relay  = (int) $this->cfgVal('pumpRelayIndex', 0);
        $perDay = TimecSchedule::groupsToPerDay(TimecSchedule::rulesToGroups($cur['rules'], $relay));

        $e = @\IPS_GetEvent($eid);
        if (!is_array($e) || (int) ($e['EventType'] ?? -1) !== 2) {
            return ['ok' => false, 'error' => 'not_a_weekly_schedule'];
        }
        // Aktions-IDs des Ereignisses statt fester 0/1 - ein Plan kann eigene Aktionen haben.
        $acts = array_map(static fn($a) => (int) $a['ID'], $e['ScheduleActions'] ?? []);
        if (!in_array(0, $acts, true) || !in_array(1, $acts, true)) {
            return ['ok' => false, 'error' => 'unexpected_actions', 'actions' => $acts];
        }

        $plan = $conflicts = [];
        $seen = 0;
        foreach ($e['ScheduleGroups'] ?? [] as $g) {
            $gid  = (int) $g['ID'];
            $days = (int) $g['Days'];
            $seen |= $days;
            $sig  = null;
            $lbl  = [];
            for ($d = 0; $d < 7; $d++) {
                if (!($days & (1 << $d))) {
                    continue;
                }
                $lbl[] = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'][$d];
                $s = json_encode($perDay[$d]);
                if ($sig === null) {
                    $sig = $s;
                } elseif ($sig !== $s) {
                    $conflicts[] = sprintf('Gruppe %d (%s): das Geraet faehrt diese Tage unterschiedlich - der Wochenplan kann das in einer Gruppe nicht abbilden.',
                        $gid, implode('+', $lbl));
                    $sig = false;
                    break;
                }
            }
            if ($sig === false || $sig === null) {
                continue;
            }
            $first = 0;
            for ($d = 0; $d < 7; $d++) { if ($days & (1 << $d)) { $first = $d; break; } }
            $plan[] = ['group' => $gid, 'days' => implode('+', $lbl),
                       'points' => TimecSchedule::windowsToPoints($perDay[$first]),
                       'had' => count($g['Points'] ?? [])];
        }
        if ($conflicts) {
            return ['ok' => false, 'error' => 'group_structure', 'conflicts' => $conflicts];
        }
        // Tage, fuer die es gar keine Gruppe gibt, faehrt das Geraet zwar - der Wochenplan
        // koennte sie aber nicht zeigen. Das ist meldenswert, kein Abbruchgrund.
        $missing = [];
        for ($d = 0; $d < 7; $d++) {
            if (!($seen & (1 << $d)) && $perDay[$d]) {
                $missing[] = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'][$d];
            }
        }

        if ($apply) {
            foreach ($plan as $p) {
                $n = count($p['points']);
                foreach ($p['points'] as $i => $pt) {
                    @\IPS_SetEventScheduleGroupPoint($eid, $p['group'], $i, $pt['h'], $pt['m'], 0, $pt['a']);
                }
                // Ueberzaehlige Punkte werden mit ungueltiger Zeit (Stunde -1) geloescht.
                for ($i = $n; $i < $p['had']; $i++) {
                    @\IPS_SetEventScheduleGroupPoint($eid, $p['group'], $i, -1, 0, 0, 0);
                }
            }
            // Der Plan stammt jetzt vom Geraet - Stand festhalten, sonst schreibt der naechste
            // Abgleich ihn sofort wieder dorthin zurueck.
            $rt = $this->readRt();
            $rt['schedHash'] = md5(json_encode($this->groupsFromEvent($eid)));
            $this->writeRt($rt);
            $this->LogMessage('Pool-Wochenplan aus den Geraeteregeln uebernommen (' . count($plan) . ' Gruppen).', KL_NOTIFY);
        }

        return ['ok' => true, 'apply' => $apply, 'eventId' => $eid, 'relay' => $relay,
                'groups' => $plan, 'missingDays' => $missing];
    }

    /** Baut die 15-Werte-TIMEC-Regel aus {days,windows}. */
    private function buildFilterRule(array $st): array
    {
        $rule = array_fill(0, 15, 0);
        $rule[1] = (int) $this->cfgVal('pumpRelayIndex', 0);
        $rule[2] = self::symconToCtrlDays((int) ($st['days'] ?? 0));
        $i = 0;
        foreach (($st['windows'] ?? []) as $w) {
            if ($i >= 4) {
                break;
            }
            $rule[3 + $i * 3] = 1;
            $rule[4 + $i * 3] = (int) $w[0];
            $rule[5 + $i * 3] = (int) $w[1];
            $i++;
        }
        $rule[0] = $i > 0 ? 1 : 0; // Regel aktiv, wenn Fenster vorhanden
        return $rule;
    }

    /**
     * Schreibt je Wochentag-Gruppe eine Filter-Regel in die Budget-Slots; ungenutzte Slots
     * werden geleert. Regeln ausserhalb des Budgets bleiben unangetastet (RMW). Gated durch
     * Aufrufer/writeGuarded. Bei fehlgeschlagenem Basis-Read wird ABGEBROCHEN (kein Nullen
     * fremder Regeln mit leerer Basis).
     */
    private function writeFilterRules(array $groups): array
    {
        $client = $this->client();
        if ($client === null) {
            return ['ok' => false, 'error' => 'not_configured'];
        }
        $relay = (int) $this->cfgVal('pumpRelayIndex', 0);
        return $this->writeGuarded('TIMEC', function () use ($client, $groups, $relay) {
            $cur = $client->getRules('TIMEC');
            if (empty($cur['ok'])) {
                return ['ok' => false, 'error' => 'read_before_write_failed'];
            }
            // Der Transformer entscheidet, WELCHE Regeln uns gehoeren (die auf unser Relais)
            // und belegt sonst nur freie Plaetze. Ein festes Index-Budget gab es frueher, es
            // haette hier eine fremde aktive Regel ueberschrieben.
            $warn  = [];
            $rules = TimecSchedule::groupsToRules($groups, $relay, $cur['rules'], $warn);
            foreach ($warn as $m) {
                $this->planWarn[] = $m;
                $this->SendDebug('HSPC.schedule', $m, 0);
            }
            return $client->setRules('TIMEC', $rules); // setRules haengt TIMEC=1 selbst an
        });
    }

    /** Vergleicht Ereignis mit letztem geschriebenen Stand; schreibt bei Aenderung (nur armed). */
    private function reconcileSchedule(): void
    {
        // Bei aktiver Umwaelzautomatik besitzt diese die Filter-Regel -> manueller
        // Wochenplan-Rueckschreib pausiert (kein Schreib-Konflikt).
        if ((bool) @$this->GetValue('CircAuto')) {
            return;
        }
        $eid = $this->scheduleEventId();
        if ($eid === 0) {
            return;
        }
        $groups = $this->groupsFromEvent($eid);
        $hash = md5(json_encode($groups));
        $rt = $this->readRt();
        if ($hash === ($rt['schedHash'] ?? '')) {
            return; // unveraendert
        }
        if (!(bool) $this->cfgVal('armed', false)) {
            $this->SendDebug('HSPC.schedule', 'Zeitplan-Aenderung ausstehend (nicht scharf)', 0);
            return; // Hash NICHT speichern -> wird bei Scharfschaltung geschrieben
        }
        $res = $this->writeFilterRules($groups);
        if (!empty($res['ok'])) {
            $rt['schedHash'] = $hash;
            $this->writeRt($rt);
            $this->SendDebug('HSPC.schedule', 'Zeitplan an Controller geschrieben (' . count($groups) . ' Gruppen)', 0);
        }
    }

    // ==================================================================
    // Filterzeit heute = EIN-Dauer des Pumpenrelais seit Mitternacht (Archiv).
    // Portiert aus der abgeloesten Loesung (Skript #<ID> getLoggedValueDuration).
    // ==================================================================

    /** Archiv-Logging fuer alle Relais-Ist-Variablen sicherstellen (EIN/AUS-Historie). */
    private function ensureRelayLogging(): void
    {
        $aid = $this->archiveId();
        if ($aid === 0 || !function_exists('AC_SetLoggingStatus')) {
            return;
        }
        $changed = false;
        for ($i = 0; $i < 8; $i++) {
            $vid = @$this->GetIDForIdent('Relay' . $i);
            if ($vid && !@\AC_GetLoggingStatus($aid, $vid)) {
                @\AC_SetLoggingStatus($aid, $vid, true);
                $changed = true;
            }
        }
        if ($changed && function_exists('IPS_ApplyChanges')) {
            @\IPS_ApplyChanges($aid);
        }
    }

    private function archiveId(): int
    {
        if (!function_exists('IPS_GetInstanceListByModuleID')) {
            return 0;
        }
        $a = @\IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}');
        return is_array($a) && $a ? (int) $a[0] : 0;
    }

    private function pumpOnMinutesToday(): int
    {
        $aid = $this->archiveId();
        $vid = (int) $this->pumpRelayVarId();
        $last = (int) @$this->GetValue('FilterRuntimeToday');
        if ($aid === 0 || $vid === 0 || !function_exists('AC_GetLoggedValues')) {
            return $last;
        }
        $start = strtotime('today 00:00');
        $data = @\AC_GetLoggedValues($aid, $vid, $start, time(), 0);
        if (!is_array($data) || $data === []) {
            return $last;
        }
        $data = array_reverse($data); // aeltester zuerst
        $sum = 0;
        $on = null;
        foreach ($data as $d) {
            if ((float) $d['Value'] == 1.0) {
                $on = (int) $d['TimeStamp'];
            } elseif ($on !== null) {
                $sum += (int) $d['TimeStamp'] - $on;
                $on = null;
            }
        }
        // Falls die Pumpe aktuell noch laeuft: bis jetzt hinzurechnen.
        $lastRow = end($data);
        if ((float) $lastRow['Value'] == 1.0) {
            $sum += time() - (int) $lastRow['TimeStamp'];
        }
        return (int) round($sum / 60);
    }

    /** Objekt-ID des Pumpenrelais (Relay<PumpRelayIndex>) fuers Archiv. */
    private function pumpRelayVarId(): int
    {
        $idx = (int) $this->cfgVal('pumpRelayIndex', 0);
        $vid = @$this->GetIDForIdent('Relay' . $idx);
        return (is_int($vid) && $vid > 0) ? $vid : 0;
    }

    // ==================================================================
    // Bedien-Hook (P0: keine actionable Controls -> nie aufgerufen)
    // ==================================================================

    protected function applyControl(Control $c, $value, ActionContext $ctx): void
    {
        // Relais-Modus (Auto/Manuell Aus/Manuell Ein) via ENA/MANUAL-Bitmaske.
        if (preg_match('/^Relay(\d+)Mode$/', $c->ident, $m)) {
            $this->applyRelayMode((int) $m[1], (int) $value);
            return;
        }
        if ($c->ident === 'DosingClAuto') {
            $this->applyDosingEnable(0, (bool) $value);
            return;
        }
        if ($c->ident === 'DosingPHAuto') {
            $this->applyDosingEnable(1, (bool) $value);
            return;
        }
        if ($c->ident === 'DosingPHPAuto') {
            $this->applyDosingEnable(2, (bool) $value); // 2 = PHPCNTRL (pH+)
            return;
        }
        if ($c->ident === 'CircAuto') {
            // Reines Modul-Flag; die Automatik laeuft im Poll (circAdjust), nur bei armed.
            $this->SendDebug('HSPC.circ', 'Umwaelzautomatik ' . ((bool) $value ? 'AN' : 'AUS'), 0);
            return;
        }
        // Tabellengetriebener Konfig-Dispatch: ident -> $CFGVARS -> gegatete mgmt-Op.
        $cv = $this->cfgVars();
        if (isset($cv[$c->ident]) && empty($cv[$c->ident]['ro'])) {
            $this->dispatchCfgVar($c->ident, $cv[$c->ident], $value);
            return;
        }
        $this->SendDebug('HSPC.apply', $c->ident . ' unbehandelt', 0);
    }

    /**
     * Dispatch einer schreibbaren Konfig-Variable: baut aus dem $CFGVARS-Eintrag die
     * Argumentstruktur der zustaendigen (gegateten) mgmt-Op und ruft sie auf. Der
     * Schreibpfad bleibt hinter dem Armed-Gate (bzw. Doppel-Gate bei Kalibrierung)
     * der jeweiligen Op.
     *
     * @param mixed $value bereits per Control::coerce() gehaerteter Wert
     */
    private function dispatchCfgVar(string $ident, array $e, $value): void
    {
        $op    = (string) $e['op'];
        $field = (string) $e['field'];
        $grp   = (string) $e['grp'];

        switch ($grp) {
            case 'rdx':
            case 'ph':
                // Dosier-Config: bool-Felder als 0/1, sonst Wert direkt (Setter skaliert intern).
                $v = is_bool($value) ? ((int) $value) : $value;
                $this->mgmt('setDosageFull', ['type' => (int) $e['type'], $field => $v], []);
                return;
            case 'sensor':
                $this->mgmt('setSensorChannel',
                    ['kind' => (string) $e['kind'], 'index' => (int) $e['index'], 'patch' => [$field => $value]], []);
                return;
            case 'network':
                $this->mgmt('setNetworkFields', ['fields' => [$field => $value]], []);
                return;
            case 'other':
                $this->mgmt('setOther', ['other' => [$field => $value]], []);
                return;
            case 'email':
                if ($op === 'setEmailServer') {
                    $this->mgmt('setEmailServer', ['server' => [$field => $value]], []);
                } else {
                    $args = ['fields' => [$field => $value]];
                    if ($e['index'] !== null) {
                        $args['index'] = (int) $e['index'];
                    }
                    $this->mgmt('setEmailAccount', $args, []);
                }
                return;
            case 'contacts':
                $this->mgmt('setContacts', ['contacts' => [(int) $e['index'] => (string) $value]], []);
                return;
            case 'dtc':
                $this->mgmt('setDtcField', ['code' => (int) $e['index'], 'level' => (int) $value], []);
                return;
            case 'cal':
                [$ch, $param] = array_pad(explode('.', $field, 2), 2, '');
                $this->mgmt($op, ['cal' => [$ch => [$param => (int) $value]]], []);
                return;
            case 'tempc':
            case 'adcc':
            case 'switchc':
                $this->writeRuleField(strtoupper($grp), (int) $e['index'], $field, $value);
                return;
            default:
                $this->SendDebug('HSPC.cfg', $ident . ' unbekannte Gruppe ' . $grp, 0);
        }
    }

    /**
     * Per-Feld-RMW-Overlay fuer eine Regel (TEMPC/ADCC/SWITCHC): liest die aktuelle
     * Regel, ueberschreibt genau EINE Feldposition (mit Einheiten-/Enum-Umrechnung)
     * und schreibt zurueck. Fremdregeln bleiben erhalten. Hinter Armed-Gate ('rules').
     * (Wird von den generischen Regel-Konfig-Variablen genutzt; Manifest/Poll dafuer
     * folgen in der Regel-Phase.)
     *
     * @param mixed $value
     */
    private function writeRuleField(string $section, int $ruleIndex, string $field, $value): array
    {
        $maps = [
            'TEMPC'   => ['ena' => 0, 'rel' => 1, 'start' => 2, 'end' => 3, 'state' => 4,
                'sens1' => 5, 'sens2' => 6, 'logic' => 7, 'diff' => 8, 'hyst' => 9],
            'ADCC'    => ['ena' => 0, 'rel' => 1, 'state' => 2, 'drel' => 3, 'start' => 4, 'end' => 5,
                'sens' => 6, 'logic' => 7, 'diff' => 8, 'hyst' => 9, 'cLow' => 10, 'lower' => 11,
                'cHigh' => 12, 'upper' => 13, 'bad' => 14, 'good' => 15],
            'SWITCHC' => ['ena' => 0, 'inp' => 1, 'rel' => 2, 'func' => 3, 'time' => 4, 'state' => 5],
        ];
        $defs = ['TEMPC' => self::TEMPC_DEFAULT, 'ADCC' => self::ADCC_DEFAULT, 'SWITCHC' => self::SWITCHC_DEFAULT];
        if (!isset($maps[$section][$field])) {
            return ['ok' => false, 'error' => 'bad_field'];
        }
        $pos  = $maps[$section][$field];
        $bool = ['ena' => 1, 'state' => 1, 'cLow' => 1, 'cHigh' => 1];
        return $this->gatedWrite('rules', 'cfg:' . $section . ':' . $field,
            function ($cl) use ($section, $ruleIndex, $field, $value, $pos, $bool, $defs) {
                $cur = $cl->getRules($section);
                if (empty($cur['ok'])) {
                    return ['ok' => false, 'error' => 'read_before_write_failed'];
                }
                $rules = $cur['rules'];
                $def   = $defs[$section];
                if ($section === 'TEMPC') {
                    for ($i = 0; $i < 8; $i++) {
                        if (!isset($rules[$i]) || array_sum(array_map('abs', $rules[$i])) === 0) {
                            $rules[$i] = $def;
                        }
                    }
                }
                $rule = $rules[$ruleIndex] ?? $def;
                if (array_sum(array_map('abs', $rule)) === 0) {
                    $rule = $def;
                }
                if (isset($bool[$field])) {
                    $conv = ((bool) $value) ? 1 : 0;
                } elseif ($section === 'TEMPC' && ($field === 'diff' || $field === 'hyst')) {
                    $conv = (int) round(((float) $value) * 100);
                } elseif ($section === 'ADCC' && in_array($field, ['diff', 'hyst', 'lower', 'upper'], true)) {
                    [$offs, $gain] = $this->adccSensorScale($cl, (int) $rule[6]);
                    $conv = (int) round($gain != 0.0 ? ((float) $value - $offs) / $gain : 0.0);
                } else {
                    $conv = (int) round((float) $value);
                }
                $rule[$pos]        = $conv;
                $rules[$ruleIndex] = $rule;
                return $cl->setRules($section, $rules);
            });
    }

    /** Geraete-Dosierung ein/aus (type 0=Cl/Redox, 1=pH-). Liest Konfig, setzt enabled, schreibt (gated). */
    private function applyDosingEnable(int $type, bool $on): void
    {
        if (!$this->writeAllowed('dosingEnable type=' . $type . ' ' . ($on ? 'on' : 'off'))) {
            return;
        }
        $cl = $this->client();
        if ($cl === null) {
            return;
        }
        $cfg = $cl->getDosageConfig($type);
        if (empty($cfg['ok'])) {
            return;
        }
        $c = $cfg['config'];
        $c['enabled'] = $on;
        $this->writeGuarded('dosage', fn() => $cl->setDosageConfig($type, $c));
        $this->SendDebug('HSPC.dosing', 'type ' . $type . ' enabled=' . ($on ? 1 : 0), 0);
    }

    /**
     * Umwaelzautomatik: berechnet die optimale Filterzeit (TruePoolTemp) und STELLT sie
     * am Controller ein (Filter-TIMEC-Regel), sobald CircAuto=an UND armed. Gedrosselt
     * (max 1x/h). Solange aus/nicht scharf: passiert nichts.
     */
    private function circAdjust(): void
    {
        if (!(bool) @$this->GetValue('CircAuto') || !(bool) $this->cfgVal('armed', false)) {
            return;
        }
        $rt = $this->readRt();
        if (time() - (int) ($rt['circTs'] ?? 0) < 3600) {
            return;
        }
        $opt = (int) @$this->GetValue('AutoCircOptimal');
        if ($opt <= 0) {
            return;
        }
        $start = (int) $this->ReadPropertyInteger('CircWindowStart');
        $win = [[$start, min(1439, $start + $opt)]];
        $rem = $opt - ($win[0][1] - $win[0][0]);
        if ($rem > 30) {
            $win[] = [0, min(1439, $rem)];
        }
        $res = $this->writeFilterRules([['days' => 127, 'windows' => $win]]);
        if (!empty($res['ok'])) {
            $rt = $this->readRt();
            $rt['circTs'] = time();
            $this->writeRt($rt);
            $this->SendDebug('HSPC.circ', 'Filterzeit gesetzt: ' . $opt . ' min ab '
                . sprintf('%02d:%02d', intdiv($start, 60), $start % 60), 0);
        }
    }

    /**
     * Setzt den Modus eines Relais. Liest die aktuellen Modi ALLER 16 Relais aus
     * dem letzten State, aendert nur das Ziel und schreibt die vollstaendige
     * Bitmaske (AUTO bleibt fuer die uebrigen erhalten). Nur bei armed=true real;
     * sonst Schatten (nur Log + optimistischer SetValue der Statusvariable).
     */
    private function applyRelayMode(int $index, int $mode): void
    {
        if ($index < 0 || $index > 15) {
            return;
        }
        if (!$this->writeAllowed('setRelayMode Relay' . $index . '=' . $mode)) {
            return; // Schatten-Modus/Winter: nichts real schalten
        }
        $client = $this->client();
        if ($client === null) {
            return;
        }
        // Aktuelle Modi aller Relais aus einem frischen State ableiten.
        $st = $client->getState();
        $modes = array_fill(0, 16, 'A');
        if (!empty($st['ok'])) {
            for ($i = 0; $i < 16; $i++) {
                $code = PoolClient::colVal($st, PoolClient::COL_RELAY_BASE + $i);
                $modes[$i] = self::modeToChar($this->codeToMode($code === null ? 0 : (int) round($code)));
            }
        }
        $modes[$index] = self::modeToChar($mode);
        $res = $this->writeGuarded('relay', fn() => $client->setRelayModes($modes));
        $this->SendDebug('HSPC.relay', 'Relay' . $index . ' -> ' . self::modeToChar($mode) . ' ok=' . json_encode($res['ok'] ?? false), 0);
        // Zeitnah nachfuehren.
        $this->Poll();
    }

    /** GetState-Relais-Code (0..3) -> Modus 0=Auto,1=Manuell Aus,2=Manuell Ein. */
    private function codeToMode(int $code): int
    {
        if (($code & 2) === 0) {
            return 0; // Auto (egal ob gerade an/aus)
        }
        return ($code & 1) === 1 ? 2 : 1; // Manuell Ein / Manuell Aus
    }

    private static function modeToChar(int $mode): string
    {
        return [0 => 'A', 1 => 'O', 2 => 'I'][$mode] ?? 'A';
    }

    // ==================================================================
    // Schreib-Gate (Armed) + Serialisierung (Semaphore je usrcfg-Sektion)
    // ==================================================================

    /** Darf real geschrieben werden? Nur bei armed=true (Winter-Sperre folgt in P5). */
    private function writeAllowed(string $what): bool
    {
        if (!(bool) $this->cfgVal('armed', false)) {
            $this->SendDebug('HSPC.shadow', $what . ' (Schatten-Modus: nicht real ausgefuehrt)', 0);
            return false;
        }
        return true;
    }

    /**
     * Fuehrt eine Schreiboperation serialisiert und GEBREMST aus.
     *
     * Der Controller ist ein kleines Geraet mit einer einzigen CGI-Schnittstelle: kommen
     * mehrere Schreibvorgaenge dicht hintereinander, steigt er aus. Deshalb sind hier drei
     * Dinge uebereinandergelegt:
     *
     *  1. Semaphore je Sektion - zwei Threads schreiben nie dieselbe usrcfg-Sektion gleichzeitig.
     *  2. Semaphore je GERAET - auch verschiedene Sektionen gehen nacheinander zum Controller.
     *  3. Mindestabstand + Stundenbudget - siehe bremse(). Reisst das Budget, wird NICHT
     *     geschrieben und der Aufrufer bekommt einen klaren Fehler statt eines stillen Verlusts.
     *
     * Der Mindestabstand wird ausgesessen (der Aufruf wartet), das Budget nicht: Warten macht
     * eine einzelne Bedienung nur langsamer, ein gerissenes Budget bedeutet dagegen, dass
     * gerade etwas im Kreis schreibt - und das darf nicht auch noch verzoegert durchlaufen.
     */
    private function writeGuarded(string $section, callable $fn, string $was = ''): array
    {
        $sem  = 'HSPC_' . $this->InstanceID . '_' . $section;
        $dev  = 'HSPC_' . $this->InstanceID . '_dev';
        $have = !function_exists('IPS_SemaphoreEnter') || @\IPS_SemaphoreEnter($sem, 5000);
        $hDev = !function_exists('IPS_SemaphoreEnter') || @\IPS_SemaphoreEnter($dev, 15000);
        try {
            $stau = $this->bremse($section);
            if ($stau !== null) {
                return $stau;
            }
            $res = (array) $fn();
            $this->schreibVermerk();
            $this->schreibSpur($section, $was, (bool) ($res['ok'] ?? false));
            return $res;
        } finally {
            if ($hDev && function_exists('IPS_SemaphoreLeave')) {
                @\IPS_SemaphoreLeave($dev);
            }
            if ($have && function_exists('IPS_SemaphoreLeave')) {
                @\IPS_SemaphoreLeave($sem);
            }
        }
    }

    /** Auskunft ueber die Schreibbremse: was ist verbraucht, wann ist wieder frei. */
    private function mgmtWriteBudget(): array
    {
        $limit = max(0, (int) $this->ReadPropertyInteger('WritesPerHour'));
        $gap   = max(0, (int) $this->ReadPropertyInteger('WriteGapMs'));
        $a     = $this->bremsStand();
        $jetzt = (int) round(microtime(true) * 1000);
        $st    = array_values(array_filter(
            array_map('intval', (array) ($a['stamps'] ?? [])),
            static fn($t) => ($jetzt - $t) < 3600000
        ));
        $last  = (int) ($a['last'] ?? 0);
        return [
            'ok'          => true,
            'limit'       => $limit,
            'gapMs'       => $gap,
            'verbraucht'  => count($st),
            'frei'        => $limit > 0 ? max(0, $limit - count($st)) : null,
            'letzterVorSek' => $last > 0 ? (int) round(($jetzt - $last) / 1000) : null,
        ];
    }

    /**
     * Spur jedes REALEN Schreibvorgangs. Beantwortet die Frage "schreibt das Modul staendig
     * zurueck?" mit Belegen statt Vermutungen: hier steht jeder Zugriff mit Zeit, Sektion und
     * Anlass. Der Poll taucht hier nie auf - er liest nur.
     */
    private function schreibSpur(string $section, string $was, bool $ok): void
    {
        $zeile = sprintf(
            "%s  %-10s %-34s %s\n",
            date('d.m.Y H:i:s'),
            $section,
            $was !== '' ? $was : '-',
            $ok ? 'ok' : 'FEHLER'
        );
        $datei = '/tmp/hspc_writes.log';
        @file_put_contents($datei, $zeile, FILE_APPEND);
        $z = @file($datei, FILE_IGNORE_NEW_LINES);
        if (is_array($z) && count($z) > 2000) {
            @file_put_contents($datei, implode("\n", array_slice($z, -2000)) . "\n");
        }
        $this->SendDebug('HSPC.write', trim($zeile), 0);
    }

    /** Ablage der Schreibhistorie. Datei statt Attribut, damit sie ueber Threads hinweg gilt. */
    private function bremsAkte(): string
    {
        return '/tmp/hspc_' . $this->InstanceID . '_writes.json';
    }

    private function bremsStand(): array
    {
        $j = @file_get_contents($this->bremsAkte());
        $a = $j !== false ? json_decode((string) $j, true) : null;
        return is_array($a) ? $a : ['last' => 0, 'stamps' => []];
    }

    /**
     * Wartet den Mindestabstand ab und prueft das Budget. Rueckgabe null = darf schreiben,
     * sonst die fertige Fehlerantwort.
     */
    private function bremse(string $section): ?array
    {
        $gap   = max(0, (int) $this->ReadPropertyInteger('WriteGapMs'));
        $limit = max(0, (int) $this->ReadPropertyInteger('WritesPerHour'));
        $a     = $this->bremsStand();
        $jetzt = (int) round(microtime(true) * 1000);

        // Budget: gleitendes Fenster ueber eine Stunde. 0 = unbegrenzt (bewusst abschaltbar).
        if ($limit > 0) {
            $fenster = array_values(array_filter(
                array_map('intval', (array) ($a['stamps'] ?? [])),
                static fn($t) => ($jetzt - $t) < 3600000
            ));
            if (count($fenster) >= $limit) {
                $aeltest = min($fenster);
                $frei    = (int) ceil((3600000 - ($jetzt - $aeltest)) / 1000);
                $this->LogMessage(sprintf(
                    'Schreibsperre: %d Schreibvorgaenge in der letzten Stunde erreicht das Limit (%d). '
                    . 'Naechster Versuch fruehestens in %d s. Sektion: %s',
                    count($fenster),
                    $limit,
                    $frei,
                    $section
                ), KL_WARNING);
                return [
                    'ok'      => false,
                    'error'   => 'rate_limited',
                    'note'    => sprintf('Schreiblimit erreicht (%d/h). Wieder frei in %d s.', $limit, $frei),
                    'retryIn' => $frei,
                ];
            }
        }

        // Mindestabstand: fehlende Zeit aussitzen, damit zwei Bedienungen nicht kollidieren.
        $last = (int) ($a['last'] ?? 0);
        if ($gap > 0 && $last > 0) {
            $warten = $gap - ($jetzt - $last);
            if ($warten > 0) {
                $warten = min($warten, $gap);   // Uhrspruenge duerfen nicht zu langen Wartezeiten fuehren
                if (function_exists('IPS_Sleep')) {
                    \IPS_Sleep($warten);
                } else {
                    usleep($warten * 1000);
                }
            }
        }
        return null;
    }

    /** Erfolgten Schreibvorgang vermerken (Zeitpunkt + Fenster fuer das Budget). */
    private function schreibVermerk(): void
    {
        $a     = $this->bremsStand();
        $jetzt = (int) round(microtime(true) * 1000);
        $st    = array_values(array_filter(
            array_map('intval', (array) ($a['stamps'] ?? [])),
            static fn($t) => ($jetzt - $t) < 3600000
        ));
        $st[]  = $jetzt;
        if (count($st) > 500) {
            $st = array_slice($st, -500);
        }
        @file_put_contents($this->bremsAkte(), json_encode(['last' => $jetzt, 'stamps' => $st]));
    }

    // ==================================================================
    // Verwaltungs-RPC
    // ==================================================================

    protected function mgmt(string $op, array $args, array $ctx): array
    {
        switch ($op) {
            case 'configureConnection':
                return $this->mgmtConfigureConnection($args, $ctx);
            case 'configureMapping':
                return $this->mgmtConfigureMapping($args, $ctx);
            case 'configureTReal':
                return $this->mgmtConfigureTReal($args, $ctx);
            case 'writeBudget':
                return $this->mgmtWriteBudget();
            case 'getConfig':
                $c = $this->cfg();
                unset($c['pass']); // Passwort nie ausgeben
                $c['passSet'] = ($this->cfgVal('pass', '') !== '');
                return ['ok' => true, 'config' => $c];
            case 'probe':
                $cl = $this->client();
                return $cl === null ? ['ok' => false, 'error' => 'not_configured'] : ['ok' => true] + $cl->ping();
            case 'poll':
                $this->Poll();
                return ['ok' => true, 'linkOk' => (bool) @$this->GetValue('LinkOK')];
            case 'readRaw':
                $cl = $this->client();
                if ($cl === null) {
                    return ['ok' => false, 'error' => 'not_configured'];
                }
                return ['ok' => true, 'state' => $cl->getState(), 'dos' => $cl->getDos()];
            case 'readErrors':
                $cl = $this->client();
                if ($cl === null) {
                    return ['ok' => false, 'error' => 'not_configured'];
                }
                $e = $cl->getErrors();
                if (!empty($e['ok'])) {
                    @$this->SetValue('ErrorCount', (int) ($e['count'] ?? 0));
                    @$this->SetValue('ErrorText', implode("\n", array_slice($e['lines'] ?? [], -20)));
                }
                return $e;
            case 'clearErrors':
                if (!$this->writeAllowed('clearErrors')) {
                    return ['ok' => true, 'shadow' => true];
                }
                $cl = $this->client();
                return $cl === null ? ['ok' => false, 'error' => 'not_configured'] : $cl->clearErrors();
            case 'setRelayMode':
                return $this->mgmtSetRelayMode($args);
            case 'doDosage':
                return $this->mgmtDoDosage($args);
            case 'resetContainer':
                return $this->mgmtResetContainer($args);

            // --- Konfiguration lesen (ungated) ---
            case 'getDosageConfig':
                return $this->withClient(fn($cl) => $cl->getDosageConfig((int) ($args['type'] ?? 0)));
            case 'getRules':
                return $this->withClient(fn($cl) => $cl->getRules(strtoupper((string) ($args['section'] ?? 'TIMEC'))));
            case 'getNetwork':
                return $this->withClient(fn($cl) => $cl->getNetwork());
            case 'getRelayNames':
                return $this->withClient(fn($cl) => $cl->getRelayNames());
            case 'getDtc':
                return $this->withClient(fn($cl) => $cl->getDtc());
            case 'getRomCodes':
                return $this->withClient(fn($cl) => $cl->getRomCodes());
            case 'getSensorConfig':
                return $this->withClient(function ($cl) {
                    return ['ok' => true, 'adc' => $cl->getAdcConfig(), 'bnc' => $cl->getBncConfig(),
                        'onewire' => $cl->getOneWireConfig(), 'io' => $cl->getIoConfig()];
                });
            case 'computeCirculation':
                return $this->mgmtComputeCirculation($args);
            case 'scheduleStatus':
                $eid = $this->scheduleEventId();
                if ($eid === 0) {
                    return ['ok' => false, 'error' => 'no_schedule_event'];
                }
                $groups = $this->groupsFromEvent($eid);
                $rt     = $this->readRt();
                $relay  = (int) $this->cfgVal('pumpRelayIndex', 0);
                // Beide Richtungen zeigen: was der Plan sagt, was das Geraet faehrt, und was
                // geschrieben wuerde. Reines Lesen, deshalb ohne Schreib-Gate.
                $warn = $ours = [];
                $target = $device = null;
                $cl = $this->client();
                if ($cl !== null) {
                    $cur = $cl->getRules('TIMEC');
                    if (!empty($cur['ok'])) {
                        $device = TimecSchedule::rulesToGroups($cur['rules'], $relay);
                        $target = TimecSchedule::groupsToRules($groups, $relay, $cur['rules'], $warn);
                        foreach ($cur['rules'] as $i => $r) {
                            if ((int) ($r[0] ?? 0) === 1 && (int) ($r[1] ?? -1) === $relay) {
                                $ours[] = $i;
                            }
                        }
                    }
                }
                return ['ok' => true, 'eventId' => $eid, 'relay' => $relay,
                    'groups'      => $groups,   // Plan (Symcon), bereits verschmolzen
                    'device'      => $device,   // Geraet -> Plan (Rueckrichtung)
                    'deviceRules' => $ours,     // Regel-Indizes, die uns gehoeren
                    'target'      => $target,   // was geschrieben wuerde
                    'warn'        => $warn,
                    'pending' => (md5(json_encode($groups)) !== ($rt['schedHash'] ?? '')),
                    'armed' => (bool) $this->cfgVal('armed', false)];
            case 'scheduleFromDevice':
                return $this->mgmtScheduleFromDevice($args);
            case 'sendSchedule':
                $eid = $this->scheduleEventId();
                if ($eid === 0) {
                    return ['ok' => false, 'error' => 'no_schedule_event'];
                }
                if (!$this->writeAllowed('sendSchedule')) {
                    return ['ok' => true, 'shadow' => true, 'note' => 'Schatten-Modus: nicht gesendet'];
                }
                $groups = $this->groupsFromEvent($eid);
                $res = $this->writeFilterRules($groups);
                if (!empty($res['ok'])) {
                    $rt = $this->readRt();
                    $rt['schedHash'] = md5(json_encode($groups));
                    $this->writeRt($rt);
                }
                return ['ok' => (bool) ($res['ok'] ?? false), 'groups' => $groups];

            // --- Konfiguration schreiben (gated) ---
            case 'setDosageConfig':
                return $this->gatedWrite('dosage', 'setDosageConfig',
                    fn($cl) => $cl->setDosageConfig((int) ($args['type'] ?? 0), (array) ($args['config'] ?? [])));
            case 'setRules':
                return $this->gatedWrite('rules', 'setRules',
                    fn($cl) => $cl->setRules(strtoupper((string) ($args['section'] ?? '')), (array) ($args['rules'] ?? [])));
            case 'setNetwork':
                return $this->gatedWrite('network', 'setNetwork',
                    fn($cl) => $cl->setNetwork((array) ($args['network'] ?? [])));
            case 'setDeviceTime':
                return $this->gatedWrite('time', 'setDeviceTime',
                    fn($cl) => $cl->setDeviceTime(isset($args['unix']) ? (int) $args['unix'] : null));
            case 'setRelayName':
                return $this->mgmtSetRelayName($args);
            case 'setDtc':
                return $this->gatedWrite('dtc', 'setDtc',
                    fn($cl) => $cl->setDtc((array) ($args['dtc'] ?? []), (int) ($args['count'] ?? 70)));
            case 'setSensorConfig':
                return $this->mgmtSetSensorConfig($args);

            // --- Dosierung voll (S3) ---
            case 'setDosageFull':
                return $this->mgmtSetDosageFull($args);

            // --- TEMPC (S4) ---
            case 'getTempRule':
                return $this->mgmtGetTempRule($args);
            case 'setTempRule':
                return $this->mgmtSetTempRule($args);

            // --- ADCC / SWITCHC (S5) ---
            case 'getAdccRule':
                return $this->mgmtGetAdccRule($args);
            case 'setAdccRule':
                return $this->mgmtSetAdccRule($args);
            case 'getSwitchcRule':
                return $this->mgmtGetSwitchcRule($args);
            case 'setSwitchcRule':
                return $this->mgmtSetSwitchcRule($args);

            // --- Sensor-Einzelkanal-RMW + Rechenhelfer (S6, calc ist ungated) ---
            case 'setSensorChannel':
                return $this->mgmtSetSensorChannel($args);
            case 'calcImpulseGain':
                $r = $this->calcImpulseGain((float) ($args['inputValue'] ?? 0), (float) ($args['diameter'] ?? 0),
                    (int) ($args['sensorType'] ?? 0), (int) ($args['outputUnit'] ?? 0));
                return ['ok' => ($r['gain'] != 0.0), 'gain' => $r['gain'], 'unit' => $r['unit'],
                    'note' => ($r['gain'] == 0.0 ? 'Gain 0 — inputValue/diameter pruefen' : '')];
            case 'calcGainOffset':
                return $this->calcGainOffset((float) ($args['raw1'] ?? 0), (float) ($args['raw2'] ?? 0),
                    (float) ($args['val1'] ?? 0), (float) ($args['val2'] ?? 0));

            // --- Netzwerk RMW (S7) ---
            case 'setNetworkFields':
                return $this->mgmtSetNetworkFields($args);

            // --- Alarme/EMAIL/CONTACTS/OTHER lesen (S8, ungated) ---
            case 'getEmail':
                return $this->withClient(fn($cl) => ['ok' => true, 'account' => $cl->getEmailAccount(), 'server' => $cl->getEmailServer()]);
            case 'getContacts':
                return $this->withClient(fn($cl) => $cl->getContacts());
            case 'getOther':
                return $this->withClient(fn($cl) => $cl->getOther());
            case 'getCal':
                return $this->withClient(fn($cl) => ['ok' => true, 'hwcal' => $cl->getHwCal(), 'rdxphcal' => $cl->getRdxPhCal()]);

            // --- Alarme/EMAIL/CONTACTS/OTHER schreiben (S8, Armed-Gate) ---
            case 'setEmailAccount':
                return $this->mgmtSetEmailAccount($args);
            case 'setEmailServer':
                return $this->mgmtSetEmailServer($args);
            case 'setContacts':
                return $this->mgmtSetContacts($args);
            case 'setDtcField':
                return $this->mgmtSetDtcField($args);
            case 'sendTestMail':
                return $this->gatedWrite('email', 'sendTestMail', fn($cl) => $cl->sendTestMail((int) ($args['index'] ?? 0)));
            case 'setOther':
                return $this->mgmtSetOther($args);

            // --- Kalibrierung (S8, Doppel-Gate: Armed + CalibrationAllowed) ---
            case 'setHwCal':
                return $this->gatedCalWrite('hwcal', 'setHwCal', fn($cl) => $cl->setHwCal((array) ($args['cal'] ?? [])));
            case 'setRdxPhCal':
                return $this->gatedCalWrite('rdxphcal', 'setRdxPhCal', fn($cl) => $cl->setRdxPhCal((array) ($args['cal'] ?? [])));

            case 'setArmed':
                $this->setProps(['Armed' => (bool) ($args['armed'] ?? false)]);
                return ['ok' => true, 'armed' => (bool) $this->cfgVal('armed', false)];
            default:
                return parent::mgmt($op, $args, $ctx);
        }
    }

    /** Properties setzen + uebernehmen (native Symcon-Persistenz). */
    private function setProps(array $map): void
    {
        foreach ($map as $prop => $val) {
            @\IPS_SetProperty($this->InstanceID, $prop, $val);
        }
        @\IPS_ApplyChanges($this->InstanceID);
    }

    private function mgmtConfigureConnection(array $args, array $ctx): array
    {
        $map = [
            'Host'           => trim((string) ($args['host'] ?? $this->cfgVal('host', ''))),
            'User'           => (string) ($args['user'] ?? $this->cfgVal('user', 'admin')),
            'Port'           => (int) ($args['port'] ?? $this->cfgVal('port', 80)),
            'Timeout'        => max(2, (int) ($args['timeout'] ?? $this->cfgVal('timeout', 10))),
            'ConnectTimeout' => max(1, (int) ($args['connectTimeout'] ?? $this->cfgVal('connectTimeout', 5))),
            'UseHTTPS'       => (bool) ($args['useHttps'] ?? $this->cfgVal('useHttps', false)),
            'PollInterval'   => max(5000, (int) ($args['pollInterval'] ?? $this->cfgVal('pollInterval', self::POLL_MS_DEF))),
        ];
        if (isset($args['pass']) && (string) $args['pass'] !== '') {
            $map['Password'] = (string) $args['pass'];
        }
        if (!empty($ctx['dryrun'])) {
            unset($map['Password']);
            return ['ok' => true, 'dryrun' => true, 'props' => $map];
        }
        $this->setProps($map);
        return ['ok' => true, 'probe' => ($this->client() ? $this->client()->ping() : ['ok' => false])];
    }

    private function mgmtConfigureMapping(array $args, array $ctx): array
    {
        $intMap = ['poolTempCol' => 'PoolTempCol', 'outsideCol' => 'OutsideCol', 'solarCol' => 'SolarCol',
            'returnCol' => 'ReturnCol', 'pumpTempCol' => 'PumpTempCol', 'redoxCol' => 'RedoxCol',
            'phCol' => 'PhCol', 'pressureCol' => 'PressureCol', 'flowVolCol' => 'FlowVolCol',
            'flowRateCol' => 'FlowRateCol', 'pumpRelayIndex' => 'PumpRelayIndex', 'flowSettleSeconds' => 'FlowSettleSeconds'];
        $floatMap = ['flowThreshold' => 'FlowThreshold', 'poolSize' => 'PoolSize', 'flowRate' => 'CircFlowRate'];
        $map = [];
        foreach ($intMap as $k => $prop) {
            if (isset($args[$k])) {
                $map[$prop] = (int) $args[$k];
            }
        }
        foreach ($floatMap as $k => $prop) {
            if (isset($args[$k])) {
                $map[$prop] = (float) $args[$k];
            }
        }
        if (!empty($ctx['dryrun'])) {
            return ['ok' => true, 'dryrun' => true, 'props' => $map];
        }
        $this->setProps($map);
        return ['ok' => true, 'props' => $map];
    }

    private function mgmtConfigureTReal(array $args, array $ctx): array
    {
        $vid = (int) ($args['trealSourceVarId'] ?? $this->cfgVal('trealSourceVarId', 0));
        if ($vid > 0 && function_exists('IPS_VariableExists') && !@\IPS_VariableExists($vid)) {
            return ['ok' => false, 'error' => 'variable_not_found', 'vid' => $vid];
        }
        $map = [
            'TrealEnabled'    => (bool) ($args['trealEnabled'] ?? $this->cfgVal('trealEnabled', false)),
            'TrealSourceVarId' => $vid,
            'MinPlausible'    => (float) ($args['minPlausible'] ?? $this->cfgVal('minPlausible', 0.0)),
            'MaxPlausible'    => (float) ($args['maxPlausible'] ?? $this->cfgVal('maxPlausible', 45.0)),
            'MaxStaleSeconds' => max(0, (int) ($args['maxStaleSeconds'] ?? $this->cfgVal('maxStaleSeconds', 1800))),
        ];
        if (!empty($ctx['dryrun'])) {
            return ['ok' => true, 'dryrun' => true, 'props' => $map];
        }
        $this->setProps($map);
        return ['ok' => true, 'props' => $map];
    }

    private function mgmtSetRelayMode(array $args): array
    {
        $index = (int) ($args['index'] ?? -1);
        $mode  = (int) ($args['mode'] ?? 0);
        if ($index < 0 || $index > 15 || $mode < 0 || $mode > 2) {
            return ['ok' => false, 'error' => 'bad_args'];
        }
        if (!$this->writeAllowed('setRelayMode Relay' . $index . '=' . $mode)) {
            return ['ok' => true, 'shadow' => true, 'note' => 'Schatten-Modus: nicht real geschaltet'];
        }
        $client = $this->client();
        if ($client === null) {
            return ['ok' => false, 'error' => 'not_configured'];
        }
        $st = $client->getState();
        $modes = array_fill(0, 16, 'A');
        if (!empty($st['ok'])) {
            for ($i = 0; $i < 16; $i++) {
                $code = PoolClient::colVal($st, PoolClient::COL_RELAY_BASE + $i);
                $modes[$i] = self::modeToChar($this->codeToMode($code === null ? 0 : (int) round($code)));
            }
        }
        $modes[$index] = self::modeToChar($mode);
        $res = $this->writeGuarded('relay', fn() => $client->setRelayModes($modes));
        $this->Poll();
        return ['ok' => (bool) ($res['ok'] ?? false), 'index' => $index, 'mode' => $mode];
    }

    private function mgmtDoDosage(array $args): array
    {
        $type    = (int) ($args['type'] ?? -1);   // 0=Cl/Redox, 1=pH-, 2=pH+
        $seconds = max(0, (int) ($args['seconds'] ?? 0));
        if ($type < 0 || $type > 2) {
            return ['ok' => false, 'error' => 'bad_type'];
        }
        if (!$this->writeAllowed('doDosage type=' . $type . ' s=' . $seconds)) {
            return ['ok' => true, 'shadow' => true, 'note' => 'Schatten-Modus: keine Dosierung ausgeloest'];
        }
        $client = $this->client();
        if ($client === null) {
            return ['ok' => false, 'error' => 'not_configured'];
        }
        $res = $this->writeGuarded('dosage', fn() => $client->manualDosage($type, $seconds));
        return ['ok' => (bool) ($res['ok'] ?? false), 'type' => $type, 'seconds' => $seconds];
    }

    private function mgmtResetContainer(array $args): array
    {
        $type   = (int) ($args['type'] ?? -1);
        $liters = (float) ($args['liters'] ?? 0);
        if ($type < 0 || $type > 2 || $liters <= 0) {
            return ['ok' => false, 'error' => 'bad_args'];
        }
        if (!$this->writeAllowed('resetContainer type=' . $type . ' l=' . $liters)) {
            return ['ok' => true, 'shadow' => true];
        }
        $client = $this->client();
        if ($client === null) {
            return ['ok' => false, 'error' => 'not_configured'];
        }
        $res = $this->writeGuarded('dosage', fn() => $client->resetContainer($type, $liters));
        return ['ok' => (bool) ($res['ok'] ?? false), 'type' => $type, 'liters' => $liters];
    }

    /** Lese-Op mit Client (ungated). */
    private function withClient(callable $fn): array
    {
        $cl = $this->client();
        if ($cl === null) {
            return ['ok' => false, 'error' => 'not_configured'];
        }
        return (array) $fn($cl);
    }

    /** Schreib-Op hinter Armed-Gate + Semaphore je Sektion. */
    private function gatedWrite(string $section, string $what, callable $fn): array
    {
        if (!$this->writeAllowed($what)) {
            return ['ok' => true, 'shadow' => true, 'note' => 'Schatten-Modus: nicht real geschrieben'];
        }
        $cl = $this->client();
        if ($cl === null) {
            return ['ok' => false, 'error' => 'not_configured'];
        }
        $res = $this->writeGuarded($section, fn() => $fn($cl), $what);
        return ['ok' => (bool) ($res['ok'] ?? false)] + (is_array($res) ? $res : []);
    }

    private function mgmtSetRelayName(array $args): array
    {
        $index = (int) ($args['index'] ?? -1);
        $name  = (string) ($args['name'] ?? '');
        if ($index < 0 || $index > 15 || $name === '') {
            return ['ok' => false, 'error' => 'bad_args'];
        }
        return $this->gatedWrite('relnames', 'setRelayName', function ($cl) use ($index, $name) {
            $cur = $cl->getRelayNames();
            $names = $cur['names'] ?? array_fill(0, 16, 'n.a.');
            $names[$index] = $name;
            return $cl->setRelayNames($names);
        });
    }

    private function mgmtSetSensorConfig(array $args): array
    {
        $kind = strtolower((string) ($args['kind'] ?? ''));
        return $this->gatedWrite('sensorcfg', 'setSensorConfig', function ($cl) use ($kind, $args) {
            switch ($kind) {
                case 'adc':     return $cl->setAdcConfig((array) ($args['channels'] ?? []));
                case 'bnc':     return $cl->setBncConfig((array) ($args['channels'] ?? []));
                case 'onewire': return $cl->setOneWireConfig((array) ($args['sensors'] ?? []));
                case 'io':      return $cl->setIoConfig((array) ($args['ios'] ?? []));
                default:        return ['ok' => false, 'error' => 'bad_kind'];
            }
        });
    }

    // ==================================================================
    // S3 — Dosierung voll (RMW read-merge-write, Salz-Round-Trip entschaerft)
    // ==================================================================

    private function mgmtSetDosageFull(array $args): array
    {
        $type = (int) ($args['type'] ?? -1);
        if ($type < 0 || $type > 2) {
            return ['ok' => false, 'error' => 'bad_type'];
        }
        return $this->gatedWrite('dosage', 'setDosageFull', function ($cl) use ($type, $args) {
            $cur = $cl->getDosageConfig($type);
            if (empty($cur['ok'])) {
                return ['ok' => false, 'error' => 'read_before_write_failed'];
            }
            $base = $cur['config'];
            // Salz-Round-Trip (nur Cl, DTYPE=1): Getter liefert FLOW/MAXQUANT roh, Setter dividiert
            // erneut durch 1.25*0.126 -> vor dem Merge in die Anzeige-Einheit zurueckrechnen.
            if ($type === 0 && (int) ($args['cntrlType'] ?? ($base['cntrlType'] ?? 0)) === 1) {
                $base['flowValue']   = (float) ($base['flowValue'] ?? 0) * 1.25 * 0.126;
                $base['maxQuantity'] = (float) ($base['maxQuantity'] ?? 0) * 1.25 * 0.126;
            }
            $allow = $type === 0
                ? ['enabled', 'cntrlType', 'target', 'lowerLimit', 'upperLimit', 'kp', 'maxQuantity', 'delaySec',
                   'refTimeSec', 'minTimeSec', 'maxTimeSec', 'containerL', 'polChange', 'polRelay',
                   'polIntervalS', 'polPauseMs', 'manualSec']
                : ['enabled', 'target', 'lowerLimit', 'upperLimit', 'kp', 'maxQuantity', 'delaySec',
                   'minTimeSec', 'maxTimeSec', 'containerL', 'manualSec',
                   // Erweiterung fuer die schreibbaren pH-Konfig-Variablen (Verdrahtung/Foerdermenge/Beckenparameter)
                   'filterPump', 'dosagePump', 'flowMl', 'flowSec', 'poolParam1', 'poolParam2'];
            $merged = $base;
            foreach ($allow as $k) {
                if (array_key_exists($k, $args)) {
                    $merged[$k] = $args[$k];
                }
            }
            $res = $cl->setDosageConfig($type, $merged);
            if (!empty($res['ok'])) {
                // dosTs zuruecksetzen -> naechster Poll spiegelt die frische Config (300s-Drossel).
                $rt = $this->readRt();
                $rt['dosTs'] = 0;
                $this->writeRt($rt);
            }
            return $res + ['type' => $type];
        });
    }

    // ==================================================================
    // S4 — TEMPC (Temperatur-/Solar-Regeln), RMW ueber getRules/setRules
    // Feldlayout PROTOCOLS.md:171: [0]ena [1]REL [2]start [3]end [4]STATE [5]SENS1
    //   [6]SENS2|255 [7]LOGIC [8]DIFF*100 [9]HYST*100.
    // ==================================================================

    private function mgmtGetTempRule(array $args): array
    {
        $idx = max(0, min(7, (int) ($args['index'] ?? 0)));
        return $this->withClient(function ($cl) use ($idx) {
            $r = $cl->getRules('TEMPC');
            if (empty($r['ok'])) {
                return $r;
            }
            $rule = $r['rules'][$idx] ?? self::TEMPC_DEFAULT;
            if (array_sum(array_map('abs', $rule)) === 0) {
                $rule = self::TEMPC_DEFAULT; // Ganz-Null-Regel -> SENS2=255 (absolut)
            }
            $abs = ((int) $rule[6] === 255);
            @$this->UpdateFormField('tcEna', 'value', (bool) $rule[0]);
            @$this->UpdateFormField('tcRel', 'value', (int) $rule[1]);
            @$this->UpdateFormField('tcStartH', 'value', intdiv((int) $rule[2], 60));
            @$this->UpdateFormField('tcStartM', 'value', (int) $rule[2] % 60);
            @$this->UpdateFormField('tcEndH', 'value', intdiv((int) $rule[3], 60));
            @$this->UpdateFormField('tcEndM', 'value', (int) $rule[3] % 60);
            @$this->UpdateFormField('tcState', 'value', (int) $rule[4]);
            @$this->UpdateFormField('tcSens1', 'value', (int) $rule[5]);
            @$this->UpdateFormField('tcSens2Abs', 'value', $abs);
            @$this->UpdateFormField('tcSens2', 'value', $abs ? 0 : (int) $rule[6]);
            @$this->UpdateFormField('tcLogic', 'value', (int) $rule[7]);
            @$this->UpdateFormField('tcDiff', 'value', (float) $rule[8] / 100);
            @$this->UpdateFormField('tcHyst', 'value', (float) $rule[9] / 100);
            return ['ok' => true, 'index' => $idx, 'rule' => $rule];
        });
    }

    private function mgmtSetTempRule(array $args): array
    {
        $idx   = max(0, min(7, (int) ($args['index'] ?? 0)));
        $start = max(0, min(1439, (int) ($args['startH'] ?? 0) * 60 + (int) ($args['startM'] ?? 0)));
        $end   = max(0, min(1439, (int) ($args['endH'] ?? 0) * 60 + (int) ($args['endM'] ?? 0)));
        $sens2 = !empty($args['sens2Abs']) ? 255 : max(0, min(7, (int) ($args['sens2'] ?? 0)));
        $new = [
            !empty($args['ena']) ? 1 : 0,
            max(0, min(15, (int) ($args['rel'] ?? 1))),
            $start,
            $end,
            !empty($args['state']) ? 1 : 0,
            max(0, min(7, (int) ($args['sens1'] ?? 0))),
            $sens2,
            max(0, min(4, (int) ($args['logic'] ?? 0))),
            (int) round(((float) ($args['diff'] ?? 0)) * 100),
            (int) round(((float) ($args['hyst'] ?? 0)) * 100),
        ];
        return $this->gatedWrite('rules', 'setTempRule', function ($cl) use ($idx, $new) {
            $cur = $cl->getRules('TEMPC');
            if (empty($cur['ok'])) {
                return ['ok' => false, 'error' => 'read_before_write_failed'];
            }
            $rules = $cur['rules'];
            for ($i = 0; $i < 8; $i++) {
                if (!isset($rules[$i]) || array_sum(array_map('abs', $rules[$i])) === 0) {
                    $rules[$i] = self::TEMPC_DEFAULT; // fehlende/Null-Regeln auf korrekten Default
                }
            }
            $rules[$idx] = $new;
            return $cl->setRules('TEMPC', $rules);
        });
    }

    // ==================================================================
    // S5 — ADCC (Analog) + SWITCHC (Digital-IO), RMW ueber getRules/setRules
    // ADCC 16 Werte (PROTOCOLS.md:172): [0]ena [1]REL [2]STATE [3]DREL|255 [4]start
    //   [5]end [6]SENS [7]LOGIC [8]diff_raw [9]hyst_raw [10]CLOW [11]lower_raw
    //   [12]CHIGH [13]upper_raw [14]BAD [15]GOOD. Roh=(Anzeige-offs)/gain.
    // SWITCHC 6 Werte (PROTOCOLS.md:173): [0]ena [1]INP [2]REL [3]FUNC [4]time_sec [5]STATE.
    // ==================================================================

    /** offs/gain der GetState-Spalte des ADCC-Sensors (Fallback 0/1). */
    private function adccSensorScale(PoolClient $cl, int $sens): array
    {
        $st = $cl->getState();
        if (!empty($st['ok']) && isset($st['cols'][$sens])) {
            $g = (float) $st['cols'][$sens]['gain'];
            return [(float) $st['cols'][$sens]['offset'], $g != 0.0 ? $g : 1.0];
        }
        return [0.0, 1.0];
    }

    private function mgmtGetAdccRule(array $args): array
    {
        $idx = (int) ($args['index'] ?? 0);
        if ($idx < 0 || $idx > 7) {
            return ['ok' => false, 'error' => 'bad_index'];
        }
        return $this->withClient(function ($cl) use ($idx) {
            $r = $cl->getRules('ADCC');
            if (empty($r['ok'])) {
                return $r;
            }
            $v = $r['rules'][$idx] ?? self::ADCC_DEFAULT;
            if (array_sum(array_map('abs', $v)) === 0) {
                $v = self::ADCC_DEFAULT;
            }
            [$offs, $gain] = $this->adccSensorScale($cl, (int) $v[6]);
            $disp = fn(int $raw) => $offs + $gain * (float) $raw;
            @$this->UpdateFormField('aEna', 'value', (bool) $v[0]);
            @$this->UpdateFormField('aRel', 'value', (int) $v[1]);
            @$this->UpdateFormField('aState', 'value', (int) $v[2]);
            @$this->UpdateFormField('aDrel', 'value', (int) $v[3]);
            @$this->UpdateFormField('aStart', 'value', (int) $v[4]);
            @$this->UpdateFormField('aEnd', 'value', (int) $v[5]);
            @$this->UpdateFormField('aSens', 'value', (int) $v[6]);
            @$this->UpdateFormField('aLogic', 'value', self::RULE_LOGIC[(int) $v[7]] ?? '<');
            @$this->UpdateFormField('aDiff', 'value', $disp((int) $v[8]));
            @$this->UpdateFormField('aHyst', 'value', $disp((int) $v[9]));
            @$this->UpdateFormField('aCLow', 'value', (bool) $v[10]);
            @$this->UpdateFormField('aLower', 'value', $disp((int) $v[11]));
            @$this->UpdateFormField('aCHigh', 'value', (bool) $v[12]);
            @$this->UpdateFormField('aUpper', 'value', $disp((int) $v[13]));
            @$this->UpdateFormField('aBad', 'value', (int) $v[14]);
            @$this->UpdateFormField('aGood', 'value', (int) $v[15]);
            return ['ok' => true, 'index' => $idx, 'raw' => $v, 'offs' => $offs, 'gain' => $gain];
        });
    }

    private function mgmtSetAdccRule(array $args): array
    {
        $idx = (int) ($args['index'] ?? -1);
        if ($idx < 0 || $idx > 7) {
            return ['ok' => false, 'error' => 'bad_index'];
        }
        return $this->gatedWrite('rules', 'setAdccRule', function ($cl) use ($idx, $args) {
            $cur = $cl->getRules('ADCC');
            if (empty($cur['ok'])) {
                return ['ok' => false, 'error' => 'read_before_write_failed'];
            }
            $rules = $cur['rules'];
            $sens  = max(0, (int) ($args['sens'] ?? 0));
            [$offs, $gain] = $this->adccSensorScale($cl, $sens);
            $raw   = fn(float $disp) => (int) round($gain != 0.0 ? ($disp - $offs) / $gain : 0.0);
            $logic = array_search((string) ($args['logic'] ?? '<'), self::RULE_LOGIC, true);
            $rule = self::ADCC_DEFAULT;
            $rule[0]  = !empty($args['ena']) ? 1 : 0;
            $rule[1]  = max(0, min(15, (int) ($args['rel'] ?? 0)));
            $rule[2]  = !empty($args['state']) ? 1 : 0;
            $rule[3]  = max(0, min(255, (int) ($args['drel'] ?? 255)));  // 255 = Uhrzeit-Modus
            $rule[4]  = max(0, min(1439, (int) ($args['start'] ?? 0)));  // Min
            $rule[5]  = max(0, min(1439, (int) ($args['end'] ?? 0)));    // Min
            $rule[6]  = $sens;
            $rule[7]  = $logic === false ? 0 : (int) $logic;
            $rule[8]  = $raw((float) ($args['diff'] ?? 0));
            $rule[9]  = $raw((float) ($args['hyst'] ?? 0));
            $rule[10] = !empty($args['cLow']) ? 1 : 0;
            $rule[11] = $raw((float) ($args['lower'] ?? 0));
            $rule[12] = !empty($args['cHigh']) ? 1 : 0;
            $rule[13] = $raw((float) ($args['upper'] ?? 0));
            $rule[14] = max(0, min(255, (int) ($args['bad'] ?? 10)));
            $rule[15] = max(0, min(255, (int) ($args['good'] ?? 10)));
            $rules[$idx] = $rule;
            return $cl->setRules('ADCC', $rules);
        });
    }

    private function mgmtGetSwitchcRule(array $args): array
    {
        $idx = (int) ($args['index'] ?? 0);
        if ($idx < 0 || $idx > 7) {
            return ['ok' => false, 'error' => 'bad_index'];
        }
        return $this->withClient(function ($cl) use ($idx) {
            $r = $cl->getRules('SWITCHC');
            if (empty($r['ok'])) {
                return $r;
            }
            $v = $r['rules'][$idx] ?? self::SWITCHC_DEFAULT;
            @$this->UpdateFormField('sEna', 'value', (bool) $v[0]);
            @$this->UpdateFormField('sInp', 'value', (int) $v[1]);
            @$this->UpdateFormField('sRel', 'value', (int) $v[2]);
            @$this->UpdateFormField('sFunc', 'value', self::SWITCHC_FUNC[(int) $v[3]] ?? 'NORMAL');
            @$this->UpdateFormField('sTime', 'value', (int) $v[4]); // Sekunden
            @$this->UpdateFormField('sState', 'value', (int) $v[5]);
            return ['ok' => true, 'index' => $idx, 'raw' => $v];
        });
    }

    private function mgmtSetSwitchcRule(array $args): array
    {
        $idx = (int) ($args['index'] ?? -1);
        if ($idx < 0 || $idx > 7) {
            return ['ok' => false, 'error' => 'bad_index'];
        }
        return $this->gatedWrite('rules', 'setSwitchcRule', function ($cl) use ($idx, $args) {
            $cur = $cl->getRules('SWITCHC');
            if (empty($cur['ok'])) {
                return ['ok' => false, 'error' => 'read_before_write_failed'];
            }
            $rules = $cur['rules'];
            $func  = array_search((string) ($args['func'] ?? 'NORMAL'), self::SWITCHC_FUNC, true);
            $rule = self::SWITCHC_DEFAULT;
            $rule[0] = !empty($args['ena']) ? 1 : 0;
            $rule[1] = max(0, min(15, (int) ($args['inp'] ?? 0)));
            $rule[2] = max(0, min(15, (int) ($args['rel'] ?? 0)));
            $rule[3] = $func === false ? 0 : (int) $func;
            $rule[4] = max(0, (int) ($args['time'] ?? 0)); // Sekunden (PROTOCOLS.md:204)
            $rule[5] = !empty($args['state']) ? 1 : 0;
            $rules[$idx] = $rule;
            return $cl->setRules('SWITCHC', $rules);
        });
    }

    // ==================================================================
    // S6 — Sensor-Konfig Einzelkanal (RMW) + Rechenhelfer (Impuls-Gain, 2-Punkt-Kal)
    // Der bestehende setSensorConfig (Vollsatz) bleibt; dies ist der RMW-Einzelkanal-Pfad
    // fuer den Editor, damit ein Kanal-Schreiben die uebrigen Kanaele NICHT auf Default zieht.
    // ==================================================================

    private function mgmtSetSensorChannel(array $args): array
    {
        $kind = strtolower((string) ($args['kind'] ?? ''));
        if (!in_array($kind, ['adc', 'bnc', 'onewire', 'io'], true)) {
            return ['ok' => false, 'error' => 'bad_kind'];
        }
        $i = (int) ($args['index'] ?? -1);
        if ($i < 0) {
            return ['ok' => false, 'error' => 'bad_index'];
        }
        // Leere Textfelder verwerfen (Ist-Wert behalten); numerische Felder bleiben.
        $patch = array_filter((array) ($args['patch'] ?? []), static fn($v) => $v !== '');
        return $this->gatedWrite('sensorcfg', 'setSensorChannel', function ($cl) use ($kind, $i, $patch) {
            switch ($kind) {
                case 'adc':     $cur = $cl->getAdcConfig();     $rows = $cur['channels'] ?? []; break;
                case 'bnc':     $cur = $cl->getBncConfig();     $rows = $cur['channels'] ?? []; break;
                case 'onewire': $cur = $cl->getOneWireConfig(); $rows = $cur['sensors']  ?? []; break;
                default:        $cur = $cl->getIoConfig();      $rows = $cur['ios']      ?? []; break;
            }
            if (empty($cur['ok'])) {
                return ['ok' => false, 'error' => 'read_before_write_failed'];
            }
            $rows[$i] = array_merge($rows[$i] ?? [], $patch);
            switch ($kind) {
                case 'adc':     return $cl->setAdcConfig($rows);
                case 'bnc':     return $cl->setBncConfig($rows);
                case 'onewire': return $cl->setOneWireConfig($rows);
                default:        return $cl->setIoConfig($rows);
            }
        });
    }

    /** Impulszaehler-Gain fuer IOCFG (PROTOCOLS.md §13/:194). Reine Rechnung, kein Geraetezugriff. */
    private function calcImpulseGain(float $inputValue, float $diameter, int $sensorType, int $outputUnit): array
    {
        $units = ['cm/s', 'm/s', 'l/min', 'l/h', 'm³/s', 'm³/min', 'm³/h'];
        $unit  = $units[$outputUnit] ?? $units[0];
        $gain  = 0.0;
        if ($inputValue > 0 && $diameter > 0) {
            $d    = $diameter / 10.0;             // mm -> cm
            $area = ($d * $d) * M_PI / 4.0;       // cm^2
            if ($sensorType === 0) {              // Impulse pro Liter
                switch ($outputUnit) {
                    case 0: $h = 1000 / 60; break;
                    case 1: $h = 10 / 60; break;
                    case 2: $h = 10 / 60 * ($area / 10000) * 60 * 1000; break;
                    case 3: $h = 10 / 60 * ($area / 10000) * 60 * 60 * 1000; break;
                    case 4: $h = 10 / 60 * ($area / 10000); break;
                    case 5: $h = 10 / 60 * ($area / 10000) * 60; break;
                    case 6: $h = 10 / 60 * ($area / 10000) * 60 * 60; break;
                    default: $h = 0;
                }
                $gain = ($area != 0.0) ? ($h / $area) / $inputValue : 0.0;
            } else {                              // Hz pro m/s
                $aM = ($d * $d) * M_PI / 4.0 / 10000.0; // m^2
                switch ($outputUnit) {
                    case 0: $h = ($inputValue * 60) * $aM / 100; break;
                    case 1: $h = ($inputValue * 60) * $aM; break;
                    case 2: $h = $inputValue / 1000; break;
                    case 3: $h = $inputValue / 60 / 1000; break;
                    case 4: $h = $inputValue * 60; break;
                    case 5: $h = $inputValue; break;
                    case 6: $h = $inputValue / 60; break;
                    default: $h = 0;
                }
                $gain = ($h != 0.0) ? $aM / $h : 0.0;
            }
        }
        return ['gain' => $gain, 'unit' => $unit];
    }

    /** 2-Punkt-Kalibrierung fuer ADC (PROTOCOLS.md §13/:191), Rohwerte ×16. */
    private function calcGainOffset(float $raw1, float $raw2, float $val1, float $val2): array
    {
        if ($raw1 === $raw2) {
            return ['ok' => false, 'error' => 'ADC-Rohwerte muessen unterschiedlich sein'];
        }
        $r1     = $raw1 * 16.0;
        $r2     = $raw2 * 16.0;
        $gain   = ($val1 - $val2) / ($r1 - $r2);
        $offset = $val1 - $gain * $r1;
        return ['ok' => true, 'gain' => $gain, 'offset' => $offset];
    }

    // ==================================================================
    // S7 — Netzwerk (RMW-Overlay; Thermokon/MAC/DLS bleiben erhalten)
    // ==================================================================

    private function mgmtSetNetworkFields(array $args): array
    {
        $f = (array) ($args['fields'] ?? []);
        return $this->gatedWrite('network', 'setNetworkFields', function ($cl) use ($f) {
            $cur = $cl->getNetwork();
            if (empty($cur['ok'])) {
                return ['ok' => false, 'error' => 'read_before_write_failed'];
            }
            $n   = $cur['network']; // enthaelt thermo*, mac, ntpDls unveraendert (RMW-Basis)
            $oct = static function (string $s): ?array {
                $s = trim($s);
                if ($s === '') {
                    return null;
                }
                $p = array_map('intval', explode('.', $s));
                return count($p) === 4 ? $p : null;
            };
            if (array_key_exists('dhcp', $f)) { $n['dhcp']   = ((bool) $f['dhcp']) ? 1 : 0; }
            if (array_key_exists('dls', $f))  { $n['ntpDls'] = ((bool) $f['dls']) ? 1 : 0; }
            if (!empty($f['httpPort']))       { $n['httpPort'] = (int) $f['httpPort']; }
            if (($v = $oct((string) ($f['ip'] ?? ''))) !== null)      { $n['ip'] = $v; }
            if (($v = $oct((string) ($f['mask'] ?? ''))) !== null)    { $n['subnet'] = $v; $n['subnetEna'] = 1; }
            if (($v = $oct((string) ($f['gateway'] ?? ''))) !== null) { $n['gateway'] = $v; $n['gwEna'] = 1; }
            if (($v = $oct((string) ($f['dns'] ?? ''))) !== null)     { $n['dns'] = $v; $n['dnsEna'] = 1; }
            if (($v = $oct((string) ($f['ntp'] ?? ''))) !== null)     { $n['ntp'] = $v; $n['ntpEna'] = 1; }
            // Thermokon-Felder (nur ueber die schreibbaren Variablen; RMW-erhalten wenn nicht gesetzt).
            if (array_key_exists('thermoEna', $f))     { $n['thermoEna']     = ((bool) $f['thermoEna']) ? 1 : 0; }
            if (array_key_exists('thermoPortEna', $f)) { $n['thermoPortEna'] = ((bool) $f['thermoPortEna']) ? 1 : 0; }
            if (array_key_exists('thermoPort1', $f))   { $n['thermoPort1']   = (int) $f['thermoPort1']; }
            if (array_key_exists('thermoPort2', $f))   { $n['thermoPort2']   = (int) $f['thermoPort2']; }
            if (($v = $oct((string) ($f['thermoIp'] ?? ''))) !== null) { $n['thermo'] = $v; }
            return $cl->setNetwork($n);
        });
    }

    // ==================================================================
    // S8 — EMAIL-Konto / Kontakte / OTHER (RMW-Overlay) + Doppel-Gate Kalibrierung
    // ==================================================================

    private function mgmtSetEmailAccount(array $args): array
    {
        $f     = (array) ($args['fields'] ?? []);
        $index = array_key_exists('index', $args) ? (int) $args['index'] : null;
        return $this->gatedWrite('email', 'setEmailAccount', function ($cl) use ($f, $index) {
            $cur = $cl->getEmailAccount();
            if (empty($cur['ok'])) {
                return ['ok' => false, 'error' => 'read_before_write_failed'];
            }
            $a = $cur['account'];
            foreach (['mail', 'html', 'sms', 'debug'] as $b) {
                if (array_key_exists($b, $f)) { $a[$b] = ((bool) $f[$b]) ? 1 : 0; }
            }
            foreach (['from', 'language', 'smsUser', 'smsPass', 'smsFrom', 'smsApi'] as $s) {
                if (array_key_exists($s, $f) && (string) $f[$s] !== '') { $a[$s] = (string) $f[$s]; }
            }
            // Kompatibilitaet Alt-Panel: 'to0' setzt Empfaenger 0 direkt.
            if (isset($f['to0']) && (string) $f['to0'] !== '') {
                $a['to'][0] = ['use' => 1, 'addr' => (string) $f['to0']];
            }
            // Indexbasierte Empfaenger (Variablen-Pfad): toAddr[0..4] / smsToNum[0..1].
            if (array_key_exists('toAddr', $f) && $index !== null && $index >= 0 && $index < 5) {
                $addr = (string) $f['toAddr'];
                $a['to'][$index] = ['use' => $addr !== '' ? 1 : 0, 'addr' => $addr];
            }
            if (array_key_exists('smsToNum', $f) && $index !== null && $index >= 0 && $index < 2) {
                $num = (string) $f['smsToNum'];
                $a['smsTo'][$index] = ['use' => $num !== '' ? 1 : 0, 'num' => $num];
            }
            return $cl->setEmailAccount($a);
        });
    }

    /** SMTP-Server als RMW-Overlay (Einzelfeld-Write darf die anderen nicht leeren). */
    private function mgmtSetEmailServer(array $args): array
    {
        $f = (array) ($args['server'] ?? []);
        return $this->gatedWrite('email', 'setEmailServer', function ($cl) use ($f) {
            $cur = $cl->getEmailServer();
            $s   = ($cur['ok'] ?? false) ? $cur['server'] : [];
            foreach (['smtp', 'user', 'pwd', 'from'] as $k) {
                if (array_key_exists($k, $f)) { $s[$k] = (string) $f[$k]; }
            }
            // B64USR/B64PWD werden im Client neu aus user/pwd berechnet.
            $s['b64usr'] = '';
            $s['b64pwd'] = '';
            return $cl->setEmailServer($s);
        });
    }

    /**
     * Alarm-Meldestufe eines einzelnen DTC-Codes setzen (RMW; alle uebrigen Codes +
     * das action-Feld des Codes bleiben erhalten). level 0..3 -> (msg,email,sms).
     */
    private function mgmtSetDtcField(array $args): array
    {
        $code  = (int) ($args['code'] ?? -1);
        $level = max(0, min(3, (int) ($args['level'] ?? 0)));
        if ($code < 0) {
            return ['ok' => false, 'error' => 'bad_code'];
        }
        return $this->gatedWrite('dtc', 'setDtcField', function ($cl) use ($code, $level) {
            $cur = $cl->getDtc();
            $dtc = ($cur['ok'] ?? false) ? $cur['dtc'] : [];
            $action = (int) ($dtc[$code]['action'] ?? 0);
            $dtc[$code] = [
                'email'  => $level >= 2 ? 1 : 0,
                'msg'    => $level >= 1 ? 1 : 0,
                'sms'    => $level >= 3 ? 1 : 0,
                'action' => $action,
            ];
            $count = 70;
            if (!empty($dtc)) {
                $count = max($count, ((int) max(array_keys($dtc))) + 1);
            }
            $full = [];
            for ($i = 0; $i < $count; $i++) {
                $full[$i] = $dtc[$i] ?? ['email' => 0, 'msg' => 0, 'sms' => 0, 'action' => 0];
            }
            return $cl->setDtc($full, $count);
        });
    }

    private function mgmtSetContacts(array $args): array
    {
        $f = (array) ($args['contacts'] ?? []);
        return $this->gatedWrite('contacts', 'setContacts', function ($cl) use ($f) {
            $cur = $cl->getContacts();
            $c   = ($cur['ok'] ?? false) ? $cur['contacts'] : array_fill(0, 5, 'name@mail.de');
            for ($i = 0; $i < 5; $i++) {
                if (isset($f[$i]) && (string) $f[$i] !== '') {
                    $c[$i] = (string) $f[$i];
                }
            }
            return $cl->setContacts($c);
        });
    }

    private function mgmtSetOther(array $args): array
    {
        $f = (array) ($args['other'] ?? []);
        return $this->gatedWrite('other', 'setOther', function ($cl) use ($f) {
            $cur = $cl->getOther();
            $o   = $cur['other'] ?? []; // 'other' traegt auch bei ok=false Defaults (RMW-Basis)
            foreach (['timezone', 'statusMailMin', 'extRelayMode', 'flowCheck', 'dmx', 'avatar'] as $k) {
                if (array_key_exists($k, $f)) {
                    $o[$k] = $f[$k];
                }
            }
            return $cl->setOther($o);
        });
    }

    /** Wie gatedWrite, zusaetzlich CalibrationAllowed gefordert (Messketten-Schutz). */
    private function gatedCalWrite(string $section, string $what, callable $fn): array
    {
        if (!$this->ReadPropertyBoolean('CalibrationAllowed')) {
            $this->SendDebug('HSPC.cal', $what . ' verweigert: CalibrationAllowed=false', 0);
            return ['ok' => false, 'error' => 'calibration_not_allowed',
                'note' => 'Kalibrierung gesperrt (CalibrationAllowed). Schreibt direkt in die Messkette.'];
        }
        return $this->gatedWrite($section, $what, $fn); // zusaetzlich Armed-Gate + Semaphore
    }

    private function mgmtComputeCirculation(array $args): array
    {
        $cfg  = $this->cfg();
        $temp = @$this->GetValue('TruePoolTemp');
        $temp = is_numeric($temp) ? (float) $temp : 0.0;
        $opt  = PoolClient::optimalFilterMinutes($temp, (float) ($cfg['poolSize'] ?? 0), (float) ($cfg['flowRate'] ?? 0));
        @$this->SetValue('AutoCircOptimal', $opt);
        return ['ok' => true, 'trueTemp' => $temp, 'optimalMinutes' => $opt,
            'poolSize' => (float) ($cfg['poolSize'] ?? 0), 'flowRate' => (float) ($cfg['flowRate'] ?? 0)];
    }

    // ==================================================================
    // $CFGVARS — zentrale, tabellengetriebene Map aller Konfig-Variablen
    // ident => ['op','field','index','type','kind','vt','profile','label','grp','ro'].
    //   op    = zustaendige (gegatete) mgmt-Op
    //   field = Feldname in der Op/Config
    //   index = semantischer Index (Kanal/Regel/Empfaenger/DTC-Code) oder null
    //   type  = Dosier-TYPE (0=Cl,1=pH-,2=pH+) fuer setDosageFull, sonst null
    //   kind  = Sensor-Kind (adc/bnc/onewire/io) fuer setSensorChannel, sonst null
    //   vt    = IPS-Variablentyp (0=bool,1=int,2=float,3=string)
    //   grp   = Sektion (rdx/ph/sensor/network/other/email/contacts/dtc/cal/tempc/adcc/switchc)
    //   ro    = read-only (Reflect, kein EnableAction/Dispatch)
    // Skalare Sektionen (rdx/ph/sensor/network/other/email/contacts/dtc/cal) bekommen in
    // dieser Phase Manifest+Poll; die Regel-Sektionen (tempc/adcc/switchc) sind bereits
    // vollstaendig gefuehrt (Dispatch via writeRuleField), Manifest/Poll folgt separat.
    // ==================================================================

    private function cfgVars(): array
    {
        if ($this->cfgVarsCache !== null) {
            return $this->cfgVarsCache;
        }
        $m = [];
        $add = function (string $ident, string $op, string $field, int $vt, string $profile,
            string $label, string $grp, array $extra = []) use (&$m): void {
            $m[$ident] = array_merge([
                'op' => $op, 'field' => $field, 'index' => null, 'type' => null, 'kind' => null,
                'vt' => $vt, 'profile' => $profile, 'label' => $label, 'grp' => $grp, 'ro' => false,
            ], $extra);
        };

        // --- V1: Dosierung Cl/Redox (RDXCNTRL, type=0) ---
        $rdx = [
            ['DosingClAuto', 'enabled', 0, '~Switch', 'Dosierautomatik Redox'],
            ['RedoxTarget', 'target', 2, 'HSPC.Redox', 'Redox Sollwert (mV)'],
            ['RdxCfgSalt', 'cntrlType', 0, 'HSPC.SaltMode', 'Salzelektrolyse (DTYPE)'],
            ['RdxCfgMin', 'lowerLimit', 2, 'HSPC.Redox', 'Redox Min (mV)'],
            ['RdxCfgMax', 'upperLimit', 2, 'HSPC.Redox', 'Redox Max (mV)'],
            ['RdxCfgKp', 'kp', 2, 'HSPC.KP', 'Proportionalband KP'],
            ['RdxCfgMaxDose', 'maxQuantity', 2, 'HSPC.Milliliter', 'Max. Dosis (ml)'],
            ['RdxCfgDelay', 'delaySec', 2, 'HSPC.Seconds', 'Nachlaufzeit/Delay (s)'],
            ['RdxCfgRefTime', 'refTimeSec', 2, 'HSPC.Seconds', 'Zykluszeit REF_T (s)'],
            ['RdxCfgMinTime', 'minTimeSec', 2, 'HSPC.Seconds', 'Min-Dosierzeit (s)'],
            ['RdxCfgMaxTime', 'maxTimeSec', 2, 'HSPC.Seconds', 'Max-Dosierzeit (s)'],
            ['RdxCfgContainer', 'containerL', 2, 'HSPC.Liter', 'Kanistergroesse (l)'],
            ['RdxCfgPolChange', 'polChange', 0, '~Switch', 'Polwechsel aktiv (Salz)'],
            ['RdxCfgPolRelay', 'polRelay', 1, '', 'Polwechsel-Relais-Index'],
            ['RdxCfgPolInterval', 'polIntervalS', 2, 'HSPC.Seconds', 'Polwechsel-Intervall (s)'],
            ['RdxCfgPolPause', 'polPauseMs', 2, 'HSPC.Millisec', 'Polwechsel-Pause (ms)'],
            ['RdxCfgManual', 'manualSec', 2, 'HSPC.Seconds', 'Manuelle Dosierdauer (s)'],
        ];
        foreach ($rdx as [$id, $f, $vt, $p, $lbl]) {
            $add($id, 'setDosageFull', $f, $vt, $p, $lbl, 'rdx', ['type' => 0]);
        }

        // --- V2: Dosierung pH- (type=1) und pH+ (type=2) ---
        // [ident-suffix, field, vt, profile, label-suffix, ro]
        $phFields = [
            ['Target', 'target', 2, 'HSPC.pH', 'Sollwert', false],           // PHTarget/PHPlusTarget (Sonderfall unten)
            ['Auto', 'enabled', 0, '~Switch', 'Dosierautomatik', false],     // DosingPHAuto/DosingPHPAuto (Sonderfall)
            ['Lower', 'lowerLimit', 2, 'HSPC.pH', 'Grenze unten', false],
            ['Upper', 'upperLimit', 2, 'HSPC.pH', 'Grenze oben', false],
            ['Kp', 'kp', 2, '', 'Regelparameter Kp', false],
            ['MaxQuant', 'maxQuantity', 1, '', 'max. Dosiermenge', false],
            ['Delay', 'delaySec', 2, 'HSPC.Seconds', 'Verzoegerung', false],
            ['MinTime', 'minTimeSec', 2, 'HSPC.Seconds', 'min. Dosierzeit', false],
            ['MaxTime', 'maxTimeSec', 2, 'HSPC.Seconds', 'max. Dosierzeit', false],
            ['Container', 'containerL', 2, 'HSPC.Liter', 'Kanisterinhalt', false],
            ['ManualTime', 'manualSec', 2, 'HSPC.Seconds', 'manuelle Dosierdauer', false],
            ['FilterPump', 'filterPump', 1, '', 'Filterpumpe (Relais-Idx)', false],
            ['DosePump', 'dosagePump', 1, '', 'Dosierpumpe (Relais-Idx)', false],
            ['FlowMl', 'flowMl', 2, 'HSPC.Milliliter', 'Foerdermenge', false],
            ['FlowSec', 'flowSec', 2, 'HSPC.Seconds', 'Foerderzeit', false],
            ['PoolParam1', 'poolParam1', 2, '', 'Beckenparameter 1', false],
            ['PoolParam2', 'poolParam2', 2, '', 'Beckenparameter 2', false],
            ['LastReset', 'lastReset', 3, '', 'letzter Kanister-Reset', true],
        ];
        foreach ([1 => ['PHMinus', 'pH-minus'], 2 => ['PHPlus', 'pH-plus']] as $type => [$pre, $lblpre]) {
            foreach ($phFields as [$suf, $f, $vt, $p, $lblsuf, $ro]) {
                // Sonderfaelle mit festen, bereits vorhandenen Idents:
                if ($suf === 'Target') {
                    $id = ($type === 1) ? 'PHTarget' : 'PHPlusTarget';
                } elseif ($suf === 'Auto') {
                    $id = ($type === 1) ? 'DosingPHAuto' : 'DosingPHPAuto';
                } else {
                    $id = $pre . $suf;
                }
                $add($id, 'setDosageFull', $f, $vt, $p, $lblpre . ' ' . $lblsuf, 'ph',
                    ['type' => $type, 'ro' => $ro]);
            }
        }

        // --- V5: Sensorik (setSensorChannel; kind+index) ---
        $mkSens = function (string $prefix, string $kind, int $count, array $fields) use ($add): void {
            for ($i = 0; $i < $count; $i++) {
                foreach ($fields as [$suf, $f, $vt, $p, $lbl]) {
                    $add($prefix . $i . $suf, 'setSensorChannel', $f, $vt, $p,
                        str_replace('{i}', (string) $i, $lbl), 'sensor', ['kind' => $kind, 'index' => $i]);
                }
            }
        };
        $mkSens('CfgAdc', 'adc', 5, [
            ['Name', 'name', 3, '', 'ADC{i} Name'],
            ['Unit', 'unit', 3, '', 'ADC{i} Einheit'],
            ['Offs', 'offset', 2, '', 'ADC{i} Offset'],
            ['Gain', 'gain', 2, '', 'ADC{i} Gain'],
        ]);
        $mkSens('CfgBnc', 'bnc', 2, [
            ['Name', 'name', 3, '', 'BNC{i} Name'],
            ['Unit', 'unit', 3, '', 'BNC{i} Einheit'],
            ['Offs', 'offset', 2, '', 'BNC{i} Offset'],
            ['Gain', 'gain', 2, '', 'BNC{i} Gain'],
            ['Comp', 'compIdx', 1, 'HSPC.CompIdx', 'BNC{i} Temp-Kompensation'],
        ]);
        $mkSens('CfgOw', 'onewire', 8, [
            ['Code', 'code', 3, '', '1-Wire S{i} ROM-Code'],
            ['Name', 'name', 3, '', '1-Wire S{i} Name'],
            ['Unit', 'unit', 3, '', '1-Wire S{i} Einheit'],
            ['Offs', 'offset', 2, '', '1-Wire S{i} Offset'],
            ['Gain', 'gain', 2, '', '1-Wire S{i} Gain'],
        ]);
        $mkSens('CfgIo', 'io', 4, [
            ['Name', 'name', 3, '', 'Digital-IO {i} Name'],
            ['Unit', 'unit', 3, '', 'Digital-IO {i} Einheit'],
            ['Offs', 'offset', 2, '', 'Digital-IO {i} Offset'],
            ['Gain', 'gain', 2, '', 'Digital-IO {i} Gain'],
            ['Dbnc', 'debounce', 1, 'HSPC.Debounce', 'Digital-IO {i} Entprellung'],
        ]);

        // --- V6: Netzwerk (setNetworkFields) + OTHER (setOther) ---
        $net = [
            ['NetDhcp', 'dhcp', 0, '~Switch', 'Netzwerk: DHCP aktiv'],
            ['NetIP', 'ip', 3, '', 'Netzwerk: IP-Adresse'],
            ['NetMask', 'mask', 3, '', 'Netzwerk: Subnetzmaske'],
            ['NetGateway', 'gateway', 3, '', 'Netzwerk: Gateway'],
            ['NetDns', 'dns', 3, '', 'Netzwerk: DNS-Server'],
            ['NetNtp', 'ntp', 3, '', 'Netzwerk: NTP-Server'],
            ['NetDls', 'dls', 0, '~Switch', 'Netzwerk: Autom. Sommerzeit'],
            ['NetHttpPort', 'httpPort', 1, '', 'Netzwerk: HTTP-Port'],
            ['NetThermoEna', 'thermoEna', 0, '~Switch', 'Thermokon: aktiv'],
            ['NetThermoIp', 'thermoIp', 3, '', 'Thermokon: IP-Adresse'],
            ['NetThermoPortEna', 'thermoPortEna', 0, '~Switch', 'Thermokon: Port aktiv'],
            ['NetThermoPort1', 'thermoPort1', 1, '', 'Thermokon: Port 1'],
            ['NetThermoPort2', 'thermoPort2', 1, '', 'Thermokon: Port 2'],
        ];
        foreach ($net as [$id, $f, $vt, $p, $lbl]) {
            $add($id, 'setNetworkFields', $f, $vt, $p, $lbl, 'network');
        }
        $other = [
            ['OtherTimezone', 'timezone', 1, '', 'Sonstiges: Zeitzone (GMT-Offset)'],
            ['OtherStatusMailMin', 'statusMailMin', 1, 'HSPC.DayMinute', 'Sonstiges: Status-Mail Uhrzeit (Min)'],
            ['OtherExtRelay', 'extRelayMode', 1, 'HSPC.ExtRelay', 'Sonstiges: Ext-Relais-Modus'],
            ['OtherFlowCheck', 'flowCheck', 0, '~Switch', 'Sonstiges: Durchfluss-Check aktiv'],
        ];
        foreach ($other as [$id, $f, $vt, $p, $lbl]) {
            $add($id, 'setOther', $f, $vt, $p, $lbl, 'other');
        }

        // --- V7: E-Mail-Konto / SMTP / Kontakte / DTC / Kalibrierung ---
        $mailAcc = [
            ['MailEnabled', 'mail', 0, '~Switch', 'Alarm-Mail aktiv'],
            ['MailHtml', 'html', 0, '~Switch', 'HTML-Mail'],
            ['MailSms', 'sms', 0, '~Switch', 'SMS-Versand aktiv'],
            ['MailDebug', 'debug', 0, '~Switch', 'Mail-Debug'],
            ['MailFrom', 'from', 3, '', 'Absender-Adresse (FROM)'],
            ['MailLanguage', 'language', 3, '', 'Mail-Sprache (de/en)'],
            ['SmsGwUser', 'smsUser', 3, '', 'SMS-Gateway Benutzer'],
            ['SmsGwPass', 'smsPass', 3, '', 'SMS-Gateway Passwort'],
            ['SmsGwFrom', 'smsFrom', 3, '', 'SMS-Gateway Absender'],
            ['SmsGwApi', 'smsApi', 3, '', 'SMS-Gateway API-URL'],
        ];
        foreach ($mailAcc as [$id, $f, $vt, $p, $lbl]) {
            $add($id, 'setEmailAccount', $f, $vt, $p, $lbl, 'email');
        }
        for ($i = 0; $i < 5; $i++) {
            $add('MailTo' . $i, 'setEmailAccount', 'toAddr', 3, '', 'Empfaenger ' . ($i + 1) . ' (E-Mail)',
                'email', ['index' => $i]);
        }
        for ($i = 0; $i < 2; $i++) {
            $add('SmsTo' . $i, 'setEmailAccount', 'smsToNum', 3, '', 'SMS-Empfaenger ' . ($i + 1) . ' (Nr.)',
                'email', ['index' => $i]);
        }
        $mailSrv = [
            ['SmtpServer', 'smtp', 'SMTP-Server'],
            ['SmtpUser', 'user', 'SMTP-Benutzer'],
            ['SmtpPassword', 'pwd', 'SMTP-Passwort'],
            ['SmtpFrom', 'from', 'SMTP-Absender (FROM)'],
        ];
        foreach ($mailSrv as [$id, $f, $lbl]) {
            $add($id, 'setEmailServer', $f, 3, '', $lbl, 'email');
        }
        for ($i = 0; $i < 5; $i++) {
            $add('Contact' . $i, 'setContacts', 'contact', 3, '', 'Kontakt ' . ($i + 1), 'contacts',
                ['index' => $i]);
        }
        // DTC — je konfiguriertem Code eine Meldestufe-Variable (Property DtcCodes).
        foreach ($this->dtcCodeList() as $code) {
            $add('DtcLevel' . $code, 'setDtcField', 'level', 1, 'HSPC.DtcLevel',
                'Alarm Code ' . $code . ' — Meldestufe', 'dtc', ['index' => $code]);
        }
        // Kalibrierung (Doppel-Gate). field = 'kanal.param'.
        $cal = [
            ['HwCalAdcOffs', 'setHwCal', 'adc.offs', 'HW-Kal ADC Offset (roh)'],
            ['HwCalAdcGain', 'setHwCal', 'adc.gain', 'HW-Kal ADC Gain (roh)'],
            ['HwCalRedoxOffs', 'setHwCal', 'redox.offs', 'HW-Kal Redox Offset (roh)'],
            ['HwCalRedoxGain', 'setHwCal', 'redox.gain', 'HW-Kal Redox Gain (roh)'],
            ['HwCalPhOffs', 'setHwCal', 'ph.offs', 'HW-Kal pH Offset (roh)'],
            ['HwCalPhGain', 'setHwCal', 'ph.gain', 'HW-Kal pH Gain (roh)'],
            ['ElCalRedoxOffs', 'setRdxPhCal', 'redox.offs', 'Elektroden-Kal Redox Offset (roh)'],
            ['ElCalRedoxGain', 'setRdxPhCal', 'redox.gain', 'Elektroden-Kal Redox Gain (roh)'],
            ['ElCalPhOffs', 'setRdxPhCal', 'ph.offs', 'Elektroden-Kal pH Offset (roh)'],
            ['ElCalPhGain', 'setRdxPhCal', 'ph.gain', 'Elektroden-Kal pH Gain (roh)'],
        ];
        foreach ($cal as [$id, $op, $f, $lbl]) {
            $add($id, $op, $f, 1, 'HSPC.CalRaw', $lbl, 'cal');
        }

        // --- V3/V4: Regel-Sektionen TEMPC(8x10) / ADCC(8x16) / SWITCHC(8x6) ---
        // Vollstaendig in der Map gefuehrt; Dispatch via writeRuleField. Manifest/Poll folgt.
        // Labels 1:1 nach ProCon.IP-GUI tempctrl.htm (label-ids/lang.js):
        //   ena=Anwenden, rel=Ausgang, start/end=Zeit(Ein/Aus), state=Schaltzustand,
        //   sens1/sens2=Wenn-Zeile (Vergleichs-Sensoren, sens2 kann 255=Absolut),
        //   logic=Vergleichsoperator, diff=Regelwert, hyst=Hysterese.
        $tempcFields = [
            ['Active', 'ena', 0, '~Switch', 'Anwenden'],
            ['Relay', 'rel', 1, 'HSPC.PoolRelay', 'Ausgang'],
            ['Start', 'start', 1, 'HSPC.DayMinute', 'Zeit Ein'],
            ['End', 'end', 1, 'HSPC.DayMinute', 'Zeit Aus'],
            ['SwitchState', 'state', 0, '~Switch', 'Schaltzustand'],
            ['Sensor1', 'sens1', 1, 'HSPC.TempSensor', 'Sensor'],
            ['Sensor2', 'sens2', 1, 'HSPC.TempSensor2', 'Vergleich (Sensor/Absolut)'],
            ['Logic', 'logic', 1, 'HSPC.RuleLogic', 'Vergleich'],
            ['Diff', 'diff', 2, 'HSPC.TempDelta', 'Regelwert'],
            ['Hyst', 'hyst', 2, 'HSPC.TempDelta', 'Hysterese'],
        ];
        for ($r = 0; $r < 8; $r++) {
            foreach ($tempcFields as [$suf, $f, $vt, $p, $lbl]) {
                $add('TempRule' . $r . '_' . $suf, 'setTempRule', $f, $vt, $p,
                    'TEMPC-Regel ' . $r . ': ' . $lbl, 'tempc', ['index' => $r]);
            }
        }
        // Labels 1:1 nach ProCon.IP-GUI adcctrl.htm (label-ids/lang.js):
        //   ena=Anwenden, rel=Ausgang, state=Schaltzustand, drel="Abhängig von" (255=Uhrzeit),
        //   start/end=Zeit(Von/Bis), sens=Wenn-Sensor, logic=Vergleichsoperator,
        //   diff=Schwellwert (in Sensoreinheit), hyst=Hysterese,
        //   cLow/lower="Unterer Grenzwert", cHigh/upper="Oberer Grenzwert",
        //   bad/good=Filterzeiten "Schlecht/Gut (sec.)".
        $adccFields = [
            ['Ena', 'ena', 0, '~Switch', 'Anwenden'],
            ['Rel', 'rel', 1, 'HSPC.RelayIdx', 'Ausgang'],
            ['State', 'state', 0, '~Switch', 'Schaltzustand'],
            ['Drel', 'drel', 1, 'HSPC.DRel', 'Abhängig von'],
            ['Start', 'start', 1, 'HSPC.MinuteOfDay', 'Zeit Von'],
            ['End', 'end', 1, 'HSPC.MinuteOfDay', 'Zeit Bis'],
            ['Sens', 'sens', 1, 'HSPC.AdccSensor', 'Sensor'],
            ['Logic', 'logic', 1, 'HSPC.RuleLogic', 'Vergleich'],
            ['Diff', 'diff', 2, 'HSPC.AnalogThreshold', 'Schwellwert'],
            ['Hyst', 'hyst', 2, 'HSPC.AnalogThreshold', 'Hysterese'],
            ['CLow', 'cLow', 0, '~Switch', 'Unterer Grenzwert aktiv'],
            ['Lower', 'lower', 2, 'HSPC.AnalogThreshold', 'Unterer Grenzwert'],
            ['CHigh', 'cHigh', 0, '~Switch', 'Oberer Grenzwert aktiv'],
            ['Upper', 'upper', 2, 'HSPC.AnalogThreshold', 'Oberer Grenzwert'],
            ['Bad', 'bad', 1, '', 'Schlecht (sec.)'],
            ['Good', 'good', 1, '', 'Gut (sec.)'],
        ];
        for ($r = 0; $r < 8; $r++) {
            foreach ($adccFields as [$suf, $f, $vt, $p, $lbl]) {
                $add('AdccR' . $r . $suf, 'setAdccRule', $f, $vt, $p,
                    'ADCC-Regel ' . $r . ': ' . $lbl, 'adcc', ['index' => $r]);
            }
        }
        // Labels 1:1 nach ProCon.IP-GUI dioctrl.htm (SWITCHC, label-ids/lang.js):
        //   ena=Anwenden, inp=Eingang (Digital-Input-Name), rel=Ausgang,
        //   func=Funktion (NORMAL/Stromstoß/Impuls/Impuls mit Reset),
        //   time=Schaltdauer (Sek., Anzeige hh:mm:ss), state=Schaltzustand.
        $swcFields = [
            ['Ena', 'ena', 0, '~Switch', 'Anwenden'],
            ['Inp', 'inp', 1, 'HSPC.SwitchInput', 'Eingang'],
            ['Rel', 'rel', 1, 'HSPC.RelayIdx', 'Ausgang'],
            ['Func', 'func', 1, 'HSPC.SwitchFunc', 'Funktion'],
            ['Time', 'time', 1, 'HSPC.SecondsInt', 'Schaltdauer (s)'],
            ['State', 'state', 0, '~Switch', 'Schaltzustand'],
        ];
        for ($r = 0; $r < 8; $r++) {
            foreach ($swcFields as [$suf, $f, $vt, $p, $lbl]) {
                $add('SwcR' . $r . $suf, 'setSwitchcRule', $f, $vt, $p,
                    'SWITCHC-Regel ' . $r . ': ' . $lbl, 'switchc', ['index' => $r]);
            }
        }

        $this->cfgVarsCache = $m;
        return $m;
    }

    /** Liste der DTC-Codes (aus Property DtcCodes), fuer die eine Meldestufe-Variable existiert. */
    private function dtcCodeList(): array
    {
        $raw = '';
        try {
            $raw = (string) $this->ReadPropertyString('DtcCodes');
        } catch (\Throwable $e) {
            return [];
        }
        $out = [];
        foreach (explode(',', $raw) as $tok) {
            $tok = trim($tok);
            if ($tok !== '' && ctype_digit($tok)) {
                $out[(int) $tok] = (int) $tok;
            }
        }
        ksort($out);
        return array_values($out);
    }

    /** SetValue einer Konfig-Variable typgerecht (aus dem Poll-Spiegel). */
    private function setCfgValue(string $ident, array $e, $v): void
    {
        switch ((int) $e['vt']) {
            case 0: @$this->SetValue($ident, (bool) $v); break;
            case 1: @$this->SetValue($ident, (int) round((float) $v)); break;
            case 2: @$this->SetValue($ident, $this->r2((float) $v)); break;
            default: @$this->SetValue($ident, (string) $v); break;
        }
    }

    /** Ist-Spiegel der Dosier-Konfig (rdx/ph) aus getDosageConfig($type). */
    private function spiegelDosage(int $type, array $res): void
    {
        if (empty($res['ok'])) {
            return;
        }
        $cfg = $res['config'] ?? [];
        foreach ($this->cfgVars() as $ident => $e) {
            if (($e['grp'] !== 'rdx' && $e['grp'] !== 'ph') || (int) ($e['type'] ?? -1) !== $type) {
                continue;
            }
            if (!array_key_exists($e['field'], $cfg)) {
                continue;
            }
            $this->setCfgValue($ident, $e, $cfg[$e['field']]);
        }
    }

    /** Ist-Spiegel der Sensor-Konfig (ADC/BNC/1-Wire/IO), gedrosselt 300s. */
    private function spiegelSensorCfg(): void
    {
        $rt = $this->readRt();
        if (time() - (int) ($rt['sensorCfgTs'] ?? 0) < 300) {
            return;
        }
        $cl = $this->client();
        if ($cl === null) {
            return;
        }
        $data = [
            'adc'     => ['channels', $cl->getAdcConfig()],
            'bnc'     => ['channels', $cl->getBncConfig()],
            'onewire' => ['sensors',  $cl->getOneWireConfig()],
            'io'      => ['ios',      $cl->getIoConfig()],
        ];
        foreach ($this->cfgVars() as $ident => $e) {
            if ($e['grp'] !== 'sensor') {
                continue;
            }
            [$key, $res] = $data[$e['kind']] ?? [null, ['ok' => false]];
            if ($key === null || empty($res['ok'])) {
                continue;
            }
            $row = $res[$key][$e['index']] ?? null;
            if (!is_array($row) || !array_key_exists($e['field'], $row)) {
                continue;
            }
            $this->setCfgValue($ident, $e, $row[$e['field']]);
        }
        $rt = $this->readRt();
        $rt['sensorCfgTs'] = time();
        $this->writeRt($rt);
    }

    /** Ist-Spiegel Netzwerk + OTHER, gedrosselt 300s. */
    private function spiegelNetOther(): void
    {
        $rt = $this->readRt();
        if (time() - (int) ($rt['netTs'] ?? 0) < 300) {
            return;
        }
        $cl = $this->client();
        if ($cl === null) {
            return;
        }
        $net = $cl->getNetwork();
        if (!empty($net['ok'])) {
            $n   = $net['network'];
            $ip  = static fn(string $k) => implode('.', array_map('strval', (array) ($n[$k] ?? [])));
            @$this->SetValue('NetDhcp', (bool) ($n['dhcp'] ?? 0));
            @$this->SetValue('NetIP', $ip('ip'));
            @$this->SetValue('NetMask', $ip('subnet'));
            @$this->SetValue('NetGateway', $ip('gateway'));
            @$this->SetValue('NetDns', $ip('dns'));
            @$this->SetValue('NetNtp', $ip('ntp'));
            @$this->SetValue('NetDls', (bool) ($n['ntpDls'] ?? 0));
            @$this->SetValue('NetHttpPort', (int) ($n['httpPort'] ?? 80));
            @$this->SetValue('NetThermoEna', (bool) ($n['thermoEna'] ?? 0));
            @$this->SetValue('NetThermoIp', $ip('thermo'));
            @$this->SetValue('NetThermoPortEna', (bool) ($n['thermoPortEna'] ?? 0));
            @$this->SetValue('NetThermoPort1', (int) ($n['thermoPort1'] ?? 0));
            @$this->SetValue('NetThermoPort2', (int) ($n['thermoPort2'] ?? 0));
        }
        $oth = $cl->getOther();
        if (!empty($oth['ok'])) {
            $o = $oth['other'];
            @$this->SetValue('OtherTimezone', (int) ($o['timezone'] ?? 1));
            @$this->SetValue('OtherStatusMailMin', (int) ($o['statusMailMin'] ?? 0));
            @$this->SetValue('OtherExtRelay', (int) ($o['extRelayMode'] ?? 0));
            @$this->SetValue('OtherFlowCheck', (bool) ($o['flowCheck'] ?? 0));
        }
        $rt = $this->readRt();
        $rt['netTs'] = time();
        $this->writeRt($rt);
    }

    /** Ist-Spiegel E-Mail/SMTP/Kontakte/DTC/Kalibrierung, gedrosselt 900s (SD-schonend). */
    private function spiegelMiscCfg(): void
    {
        $rt = $this->readRt();
        if (time() - (int) ($rt['cfgMiscTs'] ?? 0) < 900) {
            return;
        }
        $cl = $this->client();
        if ($cl === null) {
            return;
        }
        $acc = $cl->getEmailAccount();
        if (!empty($acc['ok'])) {
            $a = $acc['account'];
            @$this->SetValue('MailEnabled', (bool) ($a['mail'] ?? 0));
            @$this->SetValue('MailHtml', (bool) ($a['html'] ?? 0));
            @$this->SetValue('MailSms', (bool) ($a['sms'] ?? 0));
            @$this->SetValue('MailDebug', (bool) ($a['debug'] ?? 0));
            @$this->SetValue('MailFrom', (string) ($a['from'] ?? ''));
            @$this->SetValue('MailLanguage', (string) ($a['language'] ?? 'de'));
            @$this->SetValue('SmsGwUser', (string) ($a['smsUser'] ?? ''));
            @$this->SetValue('SmsGwPass', (string) ($a['smsPass'] ?? ''));
            @$this->SetValue('SmsGwFrom', (string) ($a['smsFrom'] ?? ''));
            @$this->SetValue('SmsGwApi', (string) ($a['smsApi'] ?? ''));
            for ($i = 0; $i < 5; $i++) {
                @$this->SetValue('MailTo' . $i, (string) ($a['to'][$i]['addr'] ?? ''));
            }
            for ($i = 0; $i < 2; $i++) {
                @$this->SetValue('SmsTo' . $i, (string) ($a['smsTo'][$i]['num'] ?? ''));
            }
        }
        $srv = $cl->getEmailServer();
        if (!empty($srv['ok'])) {
            $s = $srv['server'];
            @$this->SetValue('SmtpServer', (string) ($s['smtp'] ?? ''));
            @$this->SetValue('SmtpUser', (string) ($s['user'] ?? ''));
            @$this->SetValue('SmtpPassword', (string) ($s['pwd'] ?? ''));
            @$this->SetValue('SmtpFrom', (string) ($s['from'] ?? ''));
        }
        $con = $cl->getContacts();
        if (!empty($con['ok'])) {
            for ($i = 0; $i < 5; $i++) {
                @$this->SetValue('Contact' . $i, (string) ($con['contacts'][$i] ?? ''));
            }
        }
        $dtcCodes = $this->dtcCodeList();
        if (!empty($dtcCodes)) {
            $dtc = $cl->getDtc();
            if (!empty($dtc['ok'])) {
                $d = $dtc['dtc'];
                foreach ($dtcCodes as $code) {
                    $row   = $d[$code] ?? null;
                    $level = 0;
                    if (is_array($row)) {
                        $level = !empty($row['sms']) ? 3 : (!empty($row['email']) ? 2 : (!empty($row['msg']) ? 1 : 0));
                    }
                    @$this->SetValue('DtcLevel' . $code, $level);
                }
            }
        }
        $hw  = $cl->getHwCal();
        $rp  = $cl->getRdxPhCal();
        $hwc = ($hw['ok'] ?? false) ? $hw['hwcal'] : [];
        $rpc = ($rp['ok'] ?? false) ? $rp['rdxphcal'] : [];
        foreach ($this->cfgVars() as $ident => $e) {
            if ($e['grp'] !== 'cal') {
                continue;
            }
            [$ch, $param] = array_pad(explode('.', $e['field'], 2), 2, '');
            $src = ($e['op'] === 'setHwCal') ? $hwc : $rpc;
            if (isset($src[$ch][$param])) {
                @$this->SetValue($ident, (int) $src[$ch][$param]);
            }
        }
        $rt = $this->readRt();
        $rt['cfgMiscTs'] = time();
        $this->writeRt($rt);
    }

    /**
     * Ist-Spiegel der Regel-Sektionen TEMPC/ADCC/SWITCHC in die per-Regel-Variablen,
     * gedrosselt 300s (SD-Schonung; INI-Lesungen). Liest je Sektion einmal getRules()
     * und spiegelt jedes Regel-Feld aus rules[index][pos] in die zugehoerige CFGVAR.
     * Roh->Anzeige: TEMPC diff/hyst /100; ADCC diff/hyst/lower/upper = offs+gain*raw
     * (offs/gain aus der GetState-Spalte des Regel-Sensors rule[6], je Sensor gecacht);
     * bool-Felder via !=0; alle uebrigen roh. Mapping identisch zu writeRuleField().
     */
    private function spiegelRules(): void
    {
        $rt = $this->readRt();
        if (time() - (int) ($rt['rulesTs'] ?? 0) < 300) {
            return;
        }
        $cl = $this->client();
        if ($cl === null) {
            return;
        }
        $maps = [
            'TEMPC'   => ['ena' => 0, 'rel' => 1, 'start' => 2, 'end' => 3, 'state' => 4,
                'sens1' => 5, 'sens2' => 6, 'logic' => 7, 'diff' => 8, 'hyst' => 9],
            'ADCC'    => ['ena' => 0, 'rel' => 1, 'state' => 2, 'drel' => 3, 'start' => 4, 'end' => 5,
                'sens' => 6, 'logic' => 7, 'diff' => 8, 'hyst' => 9, 'cLow' => 10, 'lower' => 11,
                'cHigh' => 12, 'upper' => 13, 'bad' => 14, 'good' => 15],
            'SWITCHC' => ['ena' => 0, 'inp' => 1, 'rel' => 2, 'func' => 3, 'time' => 4, 'state' => 5],
        ];
        $grpSec = ['tempc' => 'TEMPC', 'adcc' => 'ADCC', 'switchc' => 'SWITCHC'];
        $rules  = [];
        foreach ($grpSec as $sec) {
            $r = $cl->getRules($sec);
            $rules[$sec] = !empty($r['ok']) ? ($r['rules'] ?? []) : null;
        }
        // offs/gain je ADCC-Sensor nur einmal ermitteln (adccSensorScale liest getState).
        $scaleCache = [];
        $scale = function (int $sens) use ($cl, &$scaleCache): array {
            if (!array_key_exists($sens, $scaleCache)) {
                $scaleCache[$sens] = $this->adccSensorScale($cl, $sens);
            }
            return $scaleCache[$sens];
        };
        foreach ($this->cfgVars() as $ident => $e) {
            $grp = (string) $e['grp'];
            if (!isset($grpSec[$grp])) {
                continue;
            }
            $sec = $grpSec[$grp];
            if (!is_array($rules[$sec] ?? null)) {
                continue;
            }
            $idx   = (int) $e['index'];
            $field = (string) $e['field'];
            $pos   = $maps[$sec][$field] ?? null;
            if ($pos === null || !isset($rules[$sec][$idx][$pos])) {
                continue;
            }
            $raw  = $rules[$sec][$idx][$pos];
            $disp = $raw;
            if ($sec === 'TEMPC' && ($field === 'diff' || $field === 'hyst')) {
                $disp = ((float) $raw) / 100.0;
            } elseif ($sec === 'ADCC' && in_array($field, ['diff', 'hyst', 'lower', 'upper'], true)) {
                [$offs, $gain] = $scale((int) ($rules[$sec][$idx][6] ?? 255));
                $disp = $offs + $gain * (float) $raw;
            }
            $this->setCfgValue($ident, $e, $disp);
        }
        $rt = $this->readRt();
        $rt['rulesTs'] = time();
        $this->writeRt($rt);
    }

    // ==================================================================
    // Client / Profile / Helfer
    // ==================================================================

    private function client(): ?PoolClient
    {
        $cfg = $this->cfg();
        if (trim((string) ($cfg['host'] ?? '')) === '') {
            return null;
        }
        return new PoolClient([
            'host'           => (string) $cfg['host'],
            'user'           => (string) ($cfg['user'] ?? 'admin'),
            'pass'           => (string) ($cfg['pass'] ?? ''),
            'port'           => (int) ($cfg['port'] ?? 80),
            'timeout'        => (int) ($cfg['timeout'] ?? 10),
            'connectTimeout' => (int) ($cfg['connectTimeout'] ?? 5),
            'useHttps'       => (bool) ($cfg['useHttps'] ?? false),
        ]);
    }

    private function ensureProfiles(): void
    {
        $this->profileFloat('HSPC.pH', ' ', 2, 0, 14);
        $this->profileFloat('HSPC.Redox', ' mV', 0, 0, 1200);
        $this->profileFloat('HSPC.Pressure', ' mBar', 0, 0, 3000);
        $this->profileFloat('HSPC.Flow', ' m³/h', 1, 0, 100);
        $this->profileFloat('HSPC.FlowRate', ' cm/s', 2, 0, 200);
        $this->profileFloat('HSPC.Percent', ' %', 1, 0, 100);
        $this->profileFloat('HSPC.Milliliter', ' ml', 0, 0, 100000);
        $this->profileFloat('HSPC.Seconds', ' s', 0, 0, 86400); // GetDos-Laufzeitwerte (Float)
        $this->profileRelayMode();

        // --- Neue Profile fuer die tabellengetriebenen Konfig-Variablen ---
        // Skalare Sektionen (V1/V2/V5/V6/V7):
        $this->profileFloat('HSPC.Liter', ' l', 2, 0, 200);        // Kanistergroesse
        $this->profileFloat('HSPC.KP', ' ', 3, 0, 100);            // Proportionalband KP_PARM
        $this->profileFloat('HSPC.Millisec', ' ms', 0, 0, 60000);  // Polwechsel-Pause
        $this->profileBoolAssoc('HSPC.SaltMode', 'Fluessig (NaClO)', 'Salzelektrolyse');
        $this->profileIntSelect('HSPC.CompIdx',
            [0 => 'aus', 7 => 'Temp S0', 8 => 'Temp S1', 9 => 'Temp S2', 10 => 'Temp S3',
             11 => 'Temp S4', 12 => 'Temp S5', 13 => 'Temp S6', 14 => 'Temp S7'], 0, 15, '');
        $this->profileIntPlain('HSPC.Debounce', ' ms', 0, 1000);
        $this->profileIntSelect('HSPC.ExtRelay', [0 => 'OFF', 1 => 'SPI', 3 => 'DMX'], 0, 3, '');
        $this->profileIntSelect('HSPC.DtcLevel',
            [0 => 'Ignorieren', 1 => 'Nur Meldung', 2 => 'Meldung + Mail', 3 => 'Meldung + Mail + SMS'], 0, 3, '');
        $this->profileIntPlain('HSPC.CalRaw', '', -32768, 32767);

        // Regel-Sektionen (V3/V4) — Profile hier bereits anlegen, damit die Regel-Phase
        // nur noch Manifest/Poll ergaenzt (keine doppelte RULE_LOGIC-Definition):
        $relay = [];
        for ($i = 0; $i < 16; $i++) {
            $relay[$i] = self::RELAY_LABELS[$i] ?? ('R' . ($i + 1));
        }
        $this->profileIntSelect('HSPC.RelayIdx', $relay, 0, 15, '');
        $this->profileIntSelect('HSPC.PoolRelay', $relay, 0, 15, '');
        // TEMPC-Sensoren: Rohwert = Sensor-Position 0..7 (OneWire S0..S7). Namen werden
        // zur Laufzeit dynamisch aus getOneWireConfig() nachgezogen (refreshRuleSensorProfiles);
        // hier nur ein sauberer Fallback OHNE Index-Suffixe.
        $this->profileIntSelect('HSPC.TempSensor',
            [0 => 'Pool', 1 => 'Aussen', 2 => 'Solarabsorber', 3 => 'Ruecklauf', 4 => 'Pumpe',
             5 => 'Sensor 6', 6 => 'Sensor 7', 7 => 'Sensor 8'], 0, 7, '');
        // sens2 kennt zusaetzlich 255 = Absolutwert (GUI-Option "(absolute)"): dann gilt der
        // Regelwert als fester Schwellwert statt eines zweiten Sensors.
        $this->profileIntSelect('HSPC.TempSensor2',
            [0 => 'Pool', 1 => 'Aussen', 2 => 'Solarabsorber', 3 => 'Ruecklauf', 4 => 'Pumpe',
             5 => 'Sensor 6', 6 => 'Sensor 7', 7 => 'Sensor 8', 255 => 'Absolut (Regelwert)'], 0, 255, '');
        // Vergleichsoperatoren exakt wie GUI (var Logic = ["<","<=","==",">",">="]), Rohwert 0..4.
        $this->profileIntSelect('HSPC.RuleLogic',
            [0 => '<', 1 => '<=', 2 => '==', 3 => '>', 4 => '>='], 0, 4, '');
        $this->profileFloat('HSPC.TempDelta', ' °C', 2, 0, 50);
        $this->profileIntPlain('HSPC.DayMinute', ' min', 0, 1439);
        $this->profileIntPlain('HSPC.MinuteOfDay', ' min', 0, 1439);
        // ADCC-Sensor: Rohwert = Auswahl-POSITION 0..5, zugeordnet zu den ADC-Kanaelen
        // [1,2,3,4,5,24] (GUI: AnalogIndex/AnalogNames). Namen dynamisch aus getAdcConfig()
        // (Pos 0..4) + getIoConfig() (Pos 5). Fallback ohne Index-Suffixe:
        $this->profileIntSelect('HSPC.AdccSensor',
            [0 => 'Analog 1', 1 => 'Analog 2', 2 => 'Analog 3', 3 => 'Analog 4',
             4 => 'Analog 5', 5 => 'Analog 6'], 0, 5, '');
        // SWITCHC-Funktion exakt wie GUI (var Func = [NORMAL,STEP,IMPULSE,IMPULSE_RESET]),
        // Ordinal 0..3, Beschriftung wie lang.js (STROMSTOß / IMPULS / IMPULS MIT RESET).
        $this->profileIntSelect('HSPC.SwitchFunc',
            [0 => 'NORMAL', 1 => 'Stromstoß', 2 => 'Impuls', 3 => 'Impuls mit Reset'], 0, 3, '');
        // SWITCHC-Eingang: Rohwert = Digital-Input-Position 0..3 (GUI nameArray[k+24]).
        // Namen dynamisch aus getIoConfig(); Fallback ohne Suffixe:
        $this->profileIntSelect('HSPC.SwitchInput',
            [0 => 'Eingang 1', 1 => 'Eingang 2', 2 => 'Eingang 3', 3 => 'Eingang 4'], 0, 3, '');
        $this->profileIntPlain('HSPC.SecondsInt', ' s', 0, 86400);
        // ADCC-Direkt-Relais (GUI DREL): 255 = "Uhrzeit" (Zeitfenster aktiv), sonst Relais-Index.
        $drel = [255 => 'Uhrzeit'];
        for ($i = 0; $i < 16; $i++) {
            $drel[$i] = self::RELAY_LABELS[$i] ?? ('Relais ' . ($i + 1));
        }
        $this->profileIntSelect('HSPC.DRel', $drel, 0, 255, '');
        $this->profileFloat('HSPC.AnalogThreshold', ' ', 2, -100000, 100000);
    }

    /**
     * Zieht die Regel-Sensor-/Eingangsprofile mit den LIVE-Namen des Geraets nach,
     * damit die Auswahllisten (TEMPC/ADCC/SWITCHC) die echten Kanalbezeichnungen
     * zeigen statt der statischen Fallbacks. Additiv (ueberschreibt nur Assoziationen),
     * vollstaendig gekapselt, kein Schreibzugriff aufs Geraet. Rohwert-Semantik exakt
     * wie ProCon-GUI: TEMPC/SWITCHC = Sensor-/Input-Position, ADCC = Auswahl-Position
     * 0..5 -> Kanaele [1,2,3,4,5,24].
     */
    private function refreshRuleSensorProfiles(PoolClient $cl): void
    {
        if (!function_exists('IPS_SetVariableProfileAssociation')) {
            return;
        }
        // --- OneWire S0..S7 -> TempSensor / TempSensor2 (Rohwert = Sensor-Index) ---
        try {
            $ow = $cl->getOneWireConfig();
            if (!empty($ow['ok'])) {
                foreach (($ow['sensors'] ?? []) as $i => $s) {
                    $i  = (int) $i;
                    $nm = trim((string) ($s['name'] ?? ''));
                    if ($i < 0 || $i > 7 || $nm === '' || $nm === 'n.a.') {
                        continue;
                    }
                    @\IPS_SetVariableProfileAssociation('HSPC.TempSensor', $i, $nm, '', -1);
                    @\IPS_SetVariableProfileAssociation('HSPC.TempSensor2', $i, $nm, '', -1);
                }
            }
        } catch (\Throwable $e) {
        }
        // getIoConfig fuer ADCC-Position 5 (nameArray[24] = IO0) UND SwitchInput.
        $io = null;
        try {
            $io = $cl->getIoConfig();
        } catch (\Throwable $e) {
        }
        // --- ADCC-Auswahl 0..5 -> ADC-Kanaele [1,2,3,4,5,24] ---
        //     Pos 0..4 = getAdcConfig()['channels'][0..4], Pos 5 = getIoConfig()['ios'][0].
        try {
            $adc = $cl->getAdcConfig();
            if (!empty($adc['ok'])) {
                for ($p = 0; $p < 5; $p++) {
                    $nm = trim((string) ($adc['channels'][$p]['name'] ?? ''));
                    if ($nm !== '' && $nm !== 'n.a.') {
                        @\IPS_SetVariableProfileAssociation('HSPC.AdccSensor', $p, $nm, '', -1);
                    }
                }
            }
            if (is_array($io) && !empty($io['ok'])) {
                $nm = trim((string) ($io['ios'][0]['name'] ?? ''));
                if ($nm !== '' && $nm !== 'n.a.') {
                    @\IPS_SetVariableProfileAssociation('HSPC.AdccSensor', 5, $nm, '', -1);
                }
            }
        } catch (\Throwable $e) {
        }
        // --- SWITCHC-Eingang 0..3 -> Digital-Inputs (getIoConfig ios[0..3]) ---
        if (is_array($io) && !empty($io['ok'])) {
            foreach (($io['ios'] ?? []) as $i => $c) {
                $i  = (int) $i;
                $nm = trim((string) ($c['name'] ?? ''));
                if ($i < 0 || $i > 3 || $nm === '' || $nm === 'n.a.') {
                    continue;
                }
                @\IPS_SetVariableProfileAssociation('HSPC.SwitchInput', $i, $nm, '', -1);
            }
        }
    }

    /** Boolean-Profil mit zwei Assoziationen (false/true). */
    private function profileBoolAssoc(string $name, string $falseLabel, string $trueLabel): void
    {
        if (!function_exists('IPS_VariableProfileExists')) {
            return;
        }
        if (!@\IPS_VariableProfileExists($name)) {
            @\IPS_CreateVariableProfile($name, 0); // Boolean
        }
        @\IPS_SetVariableProfileAssociation($name, 0, $falseLabel, '', -1);
        @\IPS_SetVariableProfileAssociation($name, 1, $trueLabel, '', -1);
    }

    /** Integer-Profil mit Wertebereich/Suffix, ohne Assoziationen. */
    private function profileIntPlain(string $name, string $suffix, int $min, int $max): void
    {
        if (!function_exists('IPS_VariableProfileExists')) {
            return;
        }
        if (!@\IPS_VariableProfileExists($name)) {
            @\IPS_CreateVariableProfile($name, 1); // Integer
        }
        @\IPS_SetVariableProfileText($name, '', $suffix);
        @\IPS_SetVariableProfileDigits($name, 0);
        @\IPS_SetVariableProfileValues($name, $min, $max, 1);
    }

    /**
     * Integer-Select-Profil (Assoziationen). $assoc = [value => label].
     */
    private function profileIntSelect(string $name, array $assoc, int $min, int $max, string $suffix = ''): void
    {
        if (!function_exists('IPS_VariableProfileExists')) {
            return;
        }
        if (!@\IPS_VariableProfileExists($name)) {
            @\IPS_CreateVariableProfile($name, 1); // Integer
        }
        @\IPS_SetVariableProfileText($name, '', $suffix);
        @\IPS_SetVariableProfileValues($name, $min, $max, 1);
        foreach ($assoc as $value => $label) {
            @\IPS_SetVariableProfileAssociation($name, (int) $value, (string) $label, '', -1);
        }
    }

    private function profileRelayMode(): void
    {
        if (!function_exists('IPS_VariableProfileExists')) {
            return;
        }
        $name = 'HSPC.RelayMode';
        if (!@\IPS_VariableProfileExists($name)) {
            @\IPS_CreateVariableProfile($name, 1); // Integer
        }
        @\IPS_SetVariableProfileValues($name, 0, 2, 0);
        @\IPS_SetVariableProfileAssociation($name, 0, 'Auto', '', -1);
        @\IPS_SetVariableProfileAssociation($name, 1, 'Manuell Aus', '', -1);
        @\IPS_SetVariableProfileAssociation($name, 2, 'Manuell Ein', '', -1);
    }

    private function profileFloat(string $name, string $suffix, int $digits, float $min, float $max): void
    {
        if (!function_exists('IPS_VariableProfileExists')) {
            return;
        }
        if (!@\IPS_VariableProfileExists($name)) {
            @\IPS_CreateVariableProfile($name, 2); // 2 = Float
        }
        @\IPS_SetVariableProfileText($name, '', $suffix);
        @\IPS_SetVariableProfileDigits($name, $digits);
        @\IPS_SetVariableProfileValues($name, $min, $max, 0);
    }

    private function syncReferences(): void
    {
        if (!method_exists($this, 'GetReferenceList')) {
            return;
        }
        foreach ($this->GetReferenceList() as $ref) {
            @$this->UnregisterReference($ref);
        }
        $vid = (int) $this->cfgVal('trealSourceVarId', 0);
        if ($vid > 0 && function_exists('IPS_ObjectExists') && @\IPS_ObjectExists($vid)) {
            @$this->RegisterReference($vid);
        }
    }

    /** Konfiguration aus nativen Instanz-Properties (Symcon-Konzept). */
    private function cfg(): array
    {
        return [
            'host'              => $this->ReadPropertyString('Host'),
            'user'              => $this->ReadPropertyString('User'),
            'pass'              => $this->ReadPropertyString('Password'),
            'port'              => $this->ReadPropertyInteger('Port'),
            'timeout'           => $this->ReadPropertyInteger('Timeout'),
            'connectTimeout'    => $this->ReadPropertyInteger('ConnectTimeout'),
            'useHttps'          => $this->ReadPropertyBoolean('UseHTTPS'),
            'pollInterval'      => $this->ReadPropertyInteger('PollInterval'),
            'armed'             => $this->ReadPropertyBoolean('Armed'),
            'poolTempCol'       => $this->ReadPropertyInteger('PoolTempCol'),
            'outsideCol'        => $this->ReadPropertyInteger('OutsideCol'),
            'solarCol'          => $this->ReadPropertyInteger('SolarCol'),
            'returnCol'         => $this->ReadPropertyInteger('ReturnCol'),
            'pumpTempCol'       => $this->ReadPropertyInteger('PumpTempCol'),
            'redoxCol'          => $this->ReadPropertyInteger('RedoxCol'),
            'phCol'             => $this->ReadPropertyInteger('PhCol'),
            'pressureCol'       => $this->ReadPropertyInteger('PressureCol'),
            'flowVolCol'        => $this->ReadPropertyInteger('FlowVolCol'),
            'flowRateCol'       => $this->ReadPropertyInteger('FlowRateCol'),
            'pumpRelayIndex'    => $this->ReadPropertyInteger('PumpRelayIndex'),
            'flowThreshold'     => $this->ReadPropertyFloat('FlowThreshold'),
            'flowSettleSeconds' => $this->ReadPropertyInteger('FlowSettleSeconds'),
            'poolSize'          => $this->ReadPropertyFloat('PoolSize'),
            'flowRate'          => $this->ReadPropertyFloat('CircFlowRate'),
            'trealEnabled'      => $this->ReadPropertyBoolean('TrealEnabled'),
            'trealSourceVarId'  => $this->ReadPropertyInteger('TrealSourceVarId'),
            'minPlausible'      => $this->ReadPropertyFloat('MinPlausible'),
            'maxPlausible'      => $this->ReadPropertyFloat('MaxPlausible'),
            'maxStaleSeconds'   => $this->ReadPropertyInteger('MaxStaleSeconds'),
            'calibrationAllowed' => $this->ReadPropertyBoolean('CalibrationAllowed'),
        ];
    }

    private function cfgVal(string $key, $def)
    {
        $c = $this->cfg();
        $v = array_key_exists($key, $c) ? $c[$key] : $def;
        return $key === 'armed' ? $this->armedEffective((bool) $v) : $v; // Hub-Master hat Vorrang
    }

    /** Runde auf 2 Nachkommastellen, null bleibt null. */
    private function r2(?float $v): float
    {
        return $v === null ? 0.0 : round($v, 2);
    }

    // ==================================================================
    // Konsolen-Formular (Erstkonfiguration + Diagnose)
    // ==================================================================

    // ==================================================================
    // Oeffentliche Scripting-Prozeduren (-> HSPC_SetRelayMode / _DoDosage …)
    // Control-Setter ueber SetControl; Sollwerte/Dosierung/Wartung ueber Manage.
    // Realer Effekt nur bei Armed=true (Global-Gate).
    // ==================================================================

    public function SetDosingRedoxAuto(bool $On): bool { return $this->setControlValue('DosingClAuto', $On); }
    public function SetDosingPHAuto(bool $On): bool    { return $this->setControlValue('DosingPHAuto', $On); }
    public function SetDosingPHPAuto(bool $On): bool   { return $this->setControlValue('DosingPHPAuto', $On); }
    public function SetCircAuto(bool $On): bool        { return $this->setControlValue('CircAuto', $On); }
    /** Test-Mail an Kontakt-Index (0..4). */
    public function SendTestMail(int $Index = 0): bool { return $this->pcManage('sendTestMail', ['index' => max(0, $Index)]); }

    /** Relais 0..7 -> Modus 0=Auto/1=Manuell Aus/2=Manuell Ein. */
    public function SetRelayMode(int $Index, int $Mode): bool
    {
        if ($Index < 0 || $Index > 7 || $Mode < 0 || $Mode > 2) {
            $this->LogMessage("HSPC.SetRelayMode: Index/Mode ausserhalb (idx={$Index}, mode={$Mode})", KL_ERROR);
            return false;
        }
        return $this->setControlValue('Relay' . $Index . 'Mode', $Mode);
    }

    private function pcManage(string $op, array $args = []): bool
    {
        $r = json_decode($this->Manage(json_encode(['op' => $op, 'args' => $args])), true);
        return is_array($r) && !empty($r['ok']);
    }

    public function SetArmed(bool $Armed): bool
    {
        $r = json_decode($this->Manage(json_encode(['op' => 'setArmed', 'args' => ['armed' => $Armed]])), true);
        return is_array($r) && (isset($r['armed']) ? (bool) $r['armed'] : (!empty($r['ok']) ? $Armed : false));
    }

    /** Manuell dosieren: Type 0=Cl/Redox,1=pH-,2=pH+; Seconds 0=Stop. */
    public function DoDosage(int $Type, int $Seconds): bool     { return $this->pcManage('doDosage', ['type' => $Type, 'seconds' => max(0, $Seconds)]); }
    public function ResetContainer(int $Type, float $Liters): bool { return $this->pcManage('resetContainer', ['type' => $Type, 'liters' => $Liters]); }
    public function SetRelayName(int $Index, string $Name): bool { return $this->pcManage('setRelayName', ['index' => $Index, 'name' => $Name]); }
    public function SendSchedule(): bool                        { return $this->pcManage('sendSchedule'); }
    public function ClearErrors(): bool                         { return $this->pcManage('clearErrors'); }
    /** Geraeteuhr stellen; Unix<=0 = jetzt. */
    public function SetDeviceTime(int $Unix = 0): bool          { return $this->pcManage('setDeviceTime', $Unix > 0 ? ['unix' => $Unix] : []); }
    /** Dosier-Sollwerte/-Grenzen; ConfigJson-Shape wie getDosageConfig(type) liefert. */
    public function SetDosageConfig(int $Type, string $ConfigJson): bool
    {
        $cfg = json_decode($ConfigJson, true);
        if (!is_array($cfg)) { $this->LogMessage('HSPC.SetDosageConfig: ConfigJson ungueltig', KL_ERROR); return false; }
        return $this->pcManage('setDosageConfig', ['type' => $Type, 'config' => $cfg]);
    }

    public function GetTruePoolTemp(): float
    {
        $v = $this->GetControlValue('TruePoolTemp');
        if ($v === null && $this->GetIDForIdent('TruePoolTemp') !== false) { $v = @$this->GetValue('TruePoolTemp'); }
        return (float) $v;
    }

    public function GetConfigurationForm()
    {
        // Native Symcon-Konfiguration: Felder sind an Instanz-Properties gebunden
        // (name == Property) und werden bei "Aenderungen uebernehmen" gespeichert.
        // Buttons unter "actions" sind Laufzeit-Aktionen (RPC), keine Konfig.
        $armed = (bool) $this->cfgVal('armed', false);
        $host  = (string) $this->cfgVal('host', '');

        $form = [
            'elements' => [
                ['type' => 'ExpansionPanel', 'caption' => 'Verbindung', 'expanded' => ($host === ''), 'items' => [
                    ['type' => 'RowLayout', 'items' => [
                        ['type' => 'ValidationTextBox', 'name' => 'Host', 'caption' => 'Host/IP'],
                        ['type' => 'NumberSpinner', 'name' => 'Port', 'caption' => 'Port'],
                        ['type' => 'CheckBox', 'name' => 'UseHTTPS', 'caption' => 'HTTPS'],
                    ]],
                    ['type' => 'RowLayout', 'items' => [
                        ['type' => 'ValidationTextBox', 'name' => 'User', 'caption' => 'Benutzer'],
                        ['type' => 'PasswordTextBox', 'name' => 'Password', 'caption' => 'Passwort'],
                    ]],
                    ['type' => 'RowLayout', 'items' => [
                        ['type' => 'NumberSpinner', 'name' => 'Timeout', 'caption' => 'Timeout (s)'],
                        ['type' => 'NumberSpinner', 'name' => 'ConnectTimeout', 'caption' => 'Connect-Timeout (s)'],
                        ['type' => 'NumberSpinner', 'name' => 'PollInterval', 'caption' => 'Poll-Intervall (ms)', 'minimum' => 5000],
                        ['type' => 'NumberSpinner', 'name' => 'WriteGapMs', 'caption' => 'Mindestabstand zwischen Schreibvorgaengen (ms)', 'minimum' => 0],
                        ['type' => 'NumberSpinner', 'name' => 'WritesPerHour', 'caption' => 'Schreibvorgaenge je Stunde (0 = unbegrenzt)', 'minimum' => 0],
                    ]],
                ]],

                ['type' => 'ExpansionPanel', 'caption' => 'Echte Wassertemperatur (TReal) — externer In-Pool-Sensor', 'items' => [
                    ['type' => 'Label', 'caption' => 'Inline-Sensoren messen nur bei laufender Pumpe korrekt. Bei Stillstand nutzt das '
                        . 'Modul den externen In-Pool-Sensor als echte Wassertemperatur (TruePoolTemp). Kein Geraete-Rueckschreiben.'],
                    ['type' => 'RowLayout', 'items' => [
                        ['type' => 'CheckBox', 'name' => 'TrealEnabled', 'caption' => 'Aktiv'],
                        ['type' => 'SelectVariable', 'name' => 'TrealSourceVarId', 'caption' => 'In-Pool-Sensor (Variable)'],
                    ]],
                    ['type' => 'RowLayout', 'items' => [
                        ['type' => 'NumberSpinner', 'name' => 'MinPlausible', 'caption' => 'Plausibel min (°C)', 'digits' => 1],
                        ['type' => 'NumberSpinner', 'name' => 'MaxPlausible', 'caption' => 'Plausibel max (°C)', 'digits' => 1],
                        ['type' => 'NumberSpinner', 'name' => 'MaxStaleSeconds', 'caption' => 'Max. Alter (s)'],
                    ]],
                ]],

                ['type' => 'ExpansionPanel', 'caption' => 'Spaltenzuordnung & Umwaelz-Berechnung (Standard passt fuer ProCon.IP)', 'items' => [
                    ['type' => 'RowLayout', 'items' => [
                        ['type' => 'NumberSpinner', 'name' => 'PoolTempCol', 'caption' => 'Pool-Temp Spalte'],
                        ['type' => 'NumberSpinner', 'name' => 'OutsideCol', 'caption' => 'Aussen'],
                        ['type' => 'NumberSpinner', 'name' => 'SolarCol', 'caption' => 'Solar'],
                        ['type' => 'NumberSpinner', 'name' => 'ReturnCol', 'caption' => 'Ruecklauf'],
                        ['type' => 'NumberSpinner', 'name' => 'PumpTempCol', 'caption' => 'Pumpe'],
                    ]],
                    ['type' => 'RowLayout', 'items' => [
                        ['type' => 'NumberSpinner', 'name' => 'PhCol', 'caption' => 'pH'],
                        ['type' => 'NumberSpinner', 'name' => 'RedoxCol', 'caption' => 'Redox'],
                        ['type' => 'NumberSpinner', 'name' => 'PressureCol', 'caption' => 'Druck'],
                        ['type' => 'NumberSpinner', 'name' => 'FlowVolCol', 'caption' => 'Durchfluss (Vol.)'],
                        ['type' => 'NumberSpinner', 'name' => 'FlowRateCol', 'caption' => 'Anstroemung'],
                    ]],
                    ['type' => 'RowLayout', 'items' => [
                        ['type' => 'NumberSpinner', 'name' => 'PumpRelayIndex', 'caption' => 'Pumpen-Relais Index'],
                        ['type' => 'NumberSpinner', 'name' => 'FlowThreshold', 'caption' => 'Durchfluss-Schwelle (cm/s)', 'digits' => 2],
                        ['type' => 'NumberSpinner', 'name' => 'FlowSettleSeconds', 'caption' => 'Settle-Zeit (s)'],
                    ]],
                    ['type' => 'RowLayout', 'items' => [
                        ['type' => 'NumberSpinner', 'name' => 'PoolSize', 'caption' => 'Poolvolumen (m³, 0=Standardformel)', 'digits' => 1],
                        ['type' => 'NumberSpinner', 'name' => 'CircFlowRate', 'caption' => 'Umwaelzleistung (m³/h)', 'digits' => 1],
                        ['type' => 'NumberSpinner', 'name' => 'FilterRuleIndex', 'caption' => 'Wochenplan: TIMEC-Regel-Index (0-15)', 'minimum' => 0, 'maximum' => 15],
                    ]],
                ]],

                ['type' => 'CheckBox', 'name' => 'Armed', 'caption' => 'Scharf: reale Schreibzugriffe (Relais/Dosierung) erlauben — sonst nur Schatten-Modus'],
                ['type' => 'CheckBox', 'name' => 'CalibrationAllowed', 'caption' => 'Kalibrierung erlauben (schreibt DIREKT in die ADC/Elektroden-Messkette — nur fuer Fachpersonal)'],
            ],

            'actions' => [
                ['type' => 'Label', 'caption' => 'Status: ' . ($host === '' ? 'nicht konfiguriert' : ('Host ' . $host))
                    . ' · scharf: ' . ($armed ? 'JA (schaltet real)' : 'nein (Schatten-Modus)')],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'Button', 'caption' => 'Verbindung testen', 'onClick' => 'echo HSPC_Manage($id, json_encode(["op"=>"probe"]));'],
                    ['type' => 'Button', 'caption' => 'Jetzt abfragen', 'onClick' => 'echo HSPC_Manage($id, json_encode(["op"=>"poll"]));'],
                    ['type' => 'Button', 'caption' => 'Rohdaten lesen', 'onClick' => 'echo HSPC_Manage($id, json_encode(["op"=>"readRaw"]));'],
                    ['type' => 'Button', 'caption' => 'Geraeteuhr stellen', 'onClick' => 'echo HSPC_Manage($id, json_encode(["op"=>"setDeviceTime"]));'],
                ]],
                ['type' => 'Label', 'caption' => '— Relais Auto/Manuell (nur bei "scharf") —'],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'NumberSpinner', 'name' => 'relIdx', 'caption' => 'Relais-Index (0..15)', 'value' => 0, 'minimum' => 0, 'maximum' => 15],
                    ['type' => 'Select', 'name' => 'relMode', 'caption' => 'Modus', 'value' => 0, 'options' => [
                        ['caption' => 'Auto', 'value' => 0], ['caption' => 'Manuell Aus', 'value' => 1], ['caption' => 'Manuell Ein', 'value' => 2],
                    ]],
                    ['type' => 'Button', 'caption' => 'Relais setzen', 'onClick' =>
                        'echo HSPC_Manage($id, json_encode(["op"=>"setRelayMode","args"=>["index"=>$relIdx,"mode"=>$relMode]]));'],
                ]],
                ['type' => 'Label', 'caption' => '— Manuelle Dosierung (nur bei "scharf"; Sollwerte jetzt ueber Visu-Variablen) —'],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'Select', 'name' => 'dosType', 'caption' => 'Regler', 'value' => 0, 'options' => [
                        ['caption' => 'Chlor/Redox', 'value' => 0], ['caption' => 'pH-minus', 'value' => 1], ['caption' => 'pH-plus', 'value' => 2],
                    ]],
                    ['type' => 'NumberSpinner', 'name' => 'dosSec', 'caption' => 'Dosierdauer (s, 0=Stop)', 'value' => 0, 'minimum' => 0, 'maximum' => 600],
                    ['type' => 'Button', 'caption' => 'Dosieren', 'onClick' =>
                        'echo HSPC_Manage($id, json_encode(["op"=>"doDosage","args"=>["type"=>$dosType,"seconds"=>$dosSec]]));'],
                ]],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'Button', 'caption' => 'Fehlerlog lesen', 'onClick' => 'echo HSPC_Manage($id, json_encode(["op"=>"readErrors"]));'],
                    ['type' => 'Button', 'caption' => 'Fehlerlog loeschen', 'onClick' => 'echo HSPC_Manage($id, json_encode(["op"=>"clearErrors"]));'],
                    ['type' => 'Button', 'caption' => 'Umwaelzzeit berechnen', 'onClick' => 'echo HSPC_Manage($id, json_encode(["op"=>"computeCirculation"]));'],
                    ['type' => 'Button', 'caption' => 'Netzwerk lesen', 'onClick' => 'echo HSPC_Manage($id, json_encode(["op"=>"getNetwork"]));'],
                    ['type' => 'Button', 'caption' => 'Sensor-Konfig lesen', 'onClick' => 'echo HSPC_Manage($id, json_encode(["op"=>"getSensorConfig"]));'],
                ]],
                ['type' => 'Label', 'caption' => '— Umwälz-Wochenplan (editierbar über die Visu; Rückschreiben nur bei "scharf") —'],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'Button', 'caption' => 'Wochenplan-Status', 'onClick' => 'echo HSPC_Manage($id, json_encode(["op"=>"scheduleStatus"]));'],
                    ['type' => 'Button', 'caption' => 'Wochenplan an Controller senden', 'onClick' => 'echo HSPC_Manage($id, json_encode(["op"=>"sendSchedule"]));'],
                    ['type' => 'NumberSpinner', 'name' => 'FilterRuleCount', 'caption' => 'Wochenplan: Anzahl Regeln (je Wochentag-Gruppe eine)', 'minimum' => 1, 'maximum' => 7],
                ]],




                // ==========================================================
                // S6 — Sensor-Konfiguration ADC/BNC/1-Wire/IO (Einzelkanal-RMW) + Rechenhelfer
                // ==========================================================
                ['type' => 'ExpansionPanel', 'caption' => 'Sensor-Diagnose & Rechenhelfer (ADC/BNC/1-Wire/IO)', 'items' => [
                    ['type' => 'Label', 'caption' => 'Die Sensor-Konfiguration erfolgt jetzt ueber die schreibbaren Sensor-Variablen. Hier nur Lesen (Diagnose) und Rechenhelfer (2-Punkt-Kalibrierung, Impulszaehler-Gain).'],
                    ['type' => 'RowLayout', 'items' => [
                        ['type' => 'Button', 'caption' => 'Alle Sektionen lesen', 'onClick' => 'echo HSPC_Manage($id, json_encode(["op"=>"getSensorConfig"]));'],
                        ['type' => 'Button', 'caption' => '1-Wire ROM-Codes lesen', 'onClick' => 'echo HSPC_Manage($id, json_encode(["op"=>"getRomCodes"]));'],
                    ]],
                    ['type' => 'Label', 'caption' => '  ADC 2-Punkt-Kalibrierung (rechnet gain/offset — Ergebnis in die ADC-Gain/Offset-Variablen eintragen):'],
                    ['type' => 'RowLayout', 'items' => [
                        ['type' => 'NumberSpinner', 'name' => 'cRaw1', 'caption' => 'Roh 1', 'digits' => 3],
                        ['type' => 'NumberSpinner', 'name' => 'cVal1', 'caption' => 'Anzeige 1', 'digits' => 3],
                        ['type' => 'NumberSpinner', 'name' => 'cRaw2', 'caption' => 'Roh 2', 'digits' => 3],
                        ['type' => 'NumberSpinner', 'name' => 'cVal2', 'caption' => 'Anzeige 2', 'digits' => 3],
                        ['type' => 'Button', 'caption' => 'Gain/Offset berechnen', 'onClick' =>
                            'echo HSPC_Manage($id, json_encode(["op"=>"calcGainOffset","args"=>["raw1"=>$cRaw1,"raw2"=>$cRaw2,"val1"=>$cVal1,"val2"=>$cVal2]]));'],
                    ]],
                    ['type' => 'Label', 'caption' => '  Impulszaehler-Gain berechnen (Ergebnis in die IO-Gain-Variable eintragen):'],
                    ['type' => 'RowLayout', 'items' => [
                        ['type' => 'Select', 'name' => 'icType', 'caption' => 'Sensortyp', 'value' => 0, 'options' => [
                            ['caption' => 'Impulse/Liter', 'value' => 0], ['caption' => 'Hz pro m/s', 'value' => 1]]],
                        ['type' => 'NumberSpinner', 'name' => 'icInput', 'caption' => 'Kennwert (Imp/l bzw. Hz/(m/s))', 'digits' => 4],
                        ['type' => 'NumberSpinner', 'name' => 'icDia', 'caption' => 'Rohr-Innen-Ø (mm)', 'digits' => 2],
                        ['type' => 'Select', 'name' => 'icUnit', 'caption' => 'Ausgabeeinheit', 'value' => 6, 'options' => [
                            ['caption' => 'cm/s', 'value' => 0], ['caption' => 'm/s', 'value' => 1], ['caption' => 'l/min', 'value' => 2],
                            ['caption' => 'l/h', 'value' => 3], ['caption' => 'm³/s', 'value' => 4], ['caption' => 'm³/min', 'value' => 5], ['caption' => 'm³/h', 'value' => 6]]],
                        ['type' => 'Button', 'caption' => 'Impuls-Gain berechnen', 'onClick' =>
                            'echo HSPC_Manage($id, json_encode(["op"=>"calcImpulseGain","args"=>["sensorType"=>$icType,"inputValue"=>$icInput,"diameter"=>$icDia,"outputUnit"=>$icUnit]]));'],
                    ]],
                ]],


                // ==========================================================
                // S8 — Alarme / E-Mail / Kontakte / OTHER / Kalibrierung
                // ==========================================================
                ['type' => 'ExpansionPanel', 'caption' => 'Alarme / E-Mail / Kontakte / Sonstiges — Diagnose (Lesen; Bearbeitung ueber Visu-Variablen)', 'items' => [
                    ['type' => 'RowLayout', 'items' => [
                        ['type' => 'Button', 'caption' => 'E-Mail/Alarm lesen', 'onClick' => 'echo HSPC_Manage($id, json_encode(["op"=>"getEmail"]));'],
                        ['type' => 'Button', 'caption' => 'Kontakte lesen', 'onClick' => 'echo HSPC_Manage($id, json_encode(["op"=>"getContacts"]));'],
                        ['type' => 'Button', 'caption' => 'Alarm-Matrix lesen', 'onClick' => 'echo HSPC_Manage($id, json_encode(["op"=>"getDtc"]));'],
                        ['type' => 'Button', 'caption' => 'Sonstiges lesen', 'onClick' => 'echo HSPC_Manage($id, json_encode(["op"=>"getOther"]));'],
                    ]],
                    ['type' => 'RowLayout', 'items' => [
                        ['type' => 'NumberSpinner', 'name' => 'tmIdx', 'caption' => 'Test-Mail an Kontakt-Index (0..4)', 'value' => 0, 'minimum' => 0, 'maximum' => 4],
                        ['type' => 'Button', 'caption' => 'Test-Mail senden', 'onClick' => 'echo HSPC_Manage($id, json_encode(["op"=>"sendTestMail","args"=>["index"=>$tmIdx]]));'],
                    ]],
                ]],
                ['type' => 'ExpansionPanel', 'caption' => 'Kalibrierung (Messkette) — Diagnose (Lesen)', 'items' => [
                    ['type' => 'Label', 'caption' => 'Kalibrier-Offset/Gain (ADC bzw. Redox/pH-Elektroden) werden jetzt ueber die schreibbaren Kalibrier-Variablen gesetzt (Doppel-Gate: "scharf" + "Kalibrierung erlauben"). Hier nur Lesen.'],
                    ['type' => 'Button', 'caption' => 'Kalibrierung lesen', 'onClick' => 'echo HSPC_Manage($id, json_encode(["op"=>"getCal"]));'],
                ]],
            ],

            'status' => [],
        ];

        return json_encode($form, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

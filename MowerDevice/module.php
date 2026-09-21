<?php

declare(strict_types=1);

/**
 * MowerDevice (HSMW) — HomeSuite-Domaenenmodul „Maeher" (Portierung von PHPAutomower).
 *
 * Eine Instanz = ein physischer Maehroboter. Erbt {@see \Hoep\HomeSuite\EntityModule}
 * (Manifest -> Variablen, native RequestAction, RPC-Trio, armed-Gate). Gebunden wird die
 * Husqvarna-Cloud ueber den IMower-Treiber `husqvarna-app` (undokumentierte dss-App-API,
 * KEINE Limits, kein App-Key). „Echtzeit" = schneller Poll im Modul (Nutzer-Vorgabe).
 *
 * M0/M1-Stand: Skelett + Manifest, Treiberbindung, read-only Schatten-Poll (armed=false ->
 * nur Reflect/Log, KEIN reales Schalten). Steuern (Start/Park/Pause/Resume/…) ist verdrahtet,
 * aber hinter dem armed-Gate. Profile/Kalender/Karte/Widget folgen M2–M7.
 *
 * Klassenname == module.json "name" == GUID {D1FB2D11-21F3-4B22-8341-E88D512A9B61} (Prefix HSMW).
 */

require_once __DIR__ . '/../libs/HomeSuite/autoload.php';

use Hoep\HomeSuite\ActionContext;
use Hoep\HomeSuite\Control;
use Hoep\HomeSuite\ControlContract;
use Hoep\HomeSuite\ContractException;
use Hoep\HomeSuite\EntityModule;
use Hoep\HomeSuite\HAL\DriverFactory;
use Hoep\HomeSuite\HAL\IDriver;
use Hoep\HomeSuite\HAL\IMower;

class MowerDevice extends EntityModule
{
    private const TIMER_REFRESH = 'Refresh';

    /** Stufen des Automatik-Waehlers (Profil HSMW.AutoMode). */
    private const AUTO_AUTO    = 0;  // Geraet folgt seinem eigenen Wochenplan
    private const AUTO_PAUSE   = 1;  // niemand fuehrt ihn: Autologik aus UND geparkt
    private const AUTO_MANUELL = 2;  // nur Handbetrieb, wir schicken nichts von selbst
    private const AUTO_LOGIK   = 3;  // Regen-Autologik fuehrt (Skripte #<ID>/#<ID>)

    private const DEF_POLL_S     = 20;   // Sekunden (dss ohne Limits -> schneller Poll = „Echtzeit")
    private const FULL_POLL_S    = 900;  // Voll-Poll (Statistik/Timer/Geofence/Messages/WorkAreas) alle 15 Min

    /** Skalar-Reflects: readState-Key => Control-Ident. */
    private const REFLECT_MAP = [
        'activity' => 'Activity', 'state' => 'State', 'mode' => 'Mode', 'battery' => 'Battery',
        'online' => 'Online', 'inChargingStation' => 'InChargingStation', 'errorText' => 'ErrorText',
        'errorCode' => 'ErrorCode', 'nextStart' => 'NextStart', 'runningTime' => 'RunningTime',
        'cuttingTime' => 'CuttingTime', 'chargingTime' => 'ChargingTime', 'searchTime' => 'SearchTime',
        'collisions' => 'Collisions', 'lat' => 'Lat', 'lng' => 'Lng', 'lastUpdate' => 'LastUpdate',
        'chargingCycles' => 'ChargingCycles', 'bladeHours' => 'BladeHours', 'searchHours' => 'SearchHours',
        'bladeUsagePct' => 'BladeUsagePct', 'efficiencyPct' => 'Efficiency',
        'model' => 'Model', 'firmware' => 'Firmware', 'updateRequired' => 'UpdateRequired', 'mission' => 'Mission',
        'missionProgress' => 'MissionProgress',
        'cuttingHeight' => 'CuttingHeight', 'headlight' => 'Headlight',
    ];

    /** JSON-Reflects (Array/Objekt -> String; nicht loggen): readState-Key => Control-Ident. */
    private const JSON_MAP = [
        'geofence' => 'Geofence', 'workAreas' => 'WorkAreasJson', 'stayOutZones' => 'StayOutJson',
        'messages' => 'ErrorHistory', 'timers' => 'TimersJson',
    ];

    /** Archiv-Logging: Ident => Aggregation (0=Standard, 1=Zähler) — Parität zum Legacy-Baum. */
    private const LOG_MAP = [
        'State' => 0, 'Activity' => 0, 'Mode' => 0, 'Battery' => 0,
        'CuttingHeight' => 0, 'ChargingCycles' => 0, 'Collisions' => 0,
        'RunningTime' => 1, 'CuttingTime' => 1, 'SearchTime' => 1, 'ChargingTime' => 1, 'BladeHours' => 1,
    ];

    /** Config-Key => [PropertyName, type-char]. Flache Felder als native Properties. */
    private const PROP_MAP = [
        'driver'       => ['Driver', 's'],
        'username'     => ['Username', 's'],
        'password'     => ['Password', 's'],
        'mowerId'      => ['MowerId', 's'],
        'pollInterval' => ['PollInterval', 'i'],
        'armed'        => ['Armed', 'b'],
    ];

    private ?IDriver $driverInstance = null;
    private bool $driverResolved = false;

    // ==================================================================
    // Lebenszyklus
    // ==================================================================

    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyString('Driver', '');
        $this->RegisterPropertyString('Username', '');
        $this->RegisterPropertyString('Password', '');
        $this->RegisterPropertyString('MowerId', '');
        $this->RegisterPropertyInteger('PollInterval', self::DEF_POLL_S);
        $this->RegisterPropertyBoolean('Armed', false);
        $this->RegisterPropertyInteger('ConfigSchema', 1); // von Anfang an native Properties
        // Kartendarstellung. Die Farbe kam bisher ausschliesslich aus einer Tabelle
        // nach Taetigkeit und war damit fuer ALLE Maeher gleich - gerade unterscheiden
        // will man sie aber. Leer = weiter wie bisher (Taetigkeitsfarbe).
        $this->RegisterPropertyString('KartenFarbe', '');
        $this->RegisterPropertyString('KartenMarker', '');   // leer = wie Pfadfarbe
        $this->RegisterPropertyString('KartenZaun', '');     // leer = Hausblau
        $this->RegisterPropertyInteger('KartenZoom', 18);    // 19 war fest verdrahtet und zu nah
        $this->RegisterAttributeInteger('LastFullPoll', 0); // Cadence: Zeitpunkt des letzten Voll-Polls
    }

    protected function setupTimers(): void
    {
        $this->RegisterTimer(self::TIMER_REFRESH, 0, 'HSMW_Refresh($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        $this->ensureProfiles();     // muss vor der Variablen-Materialisierung existieren
        parent::ApplyChanges();
        $this->driverResolved = false;
        $this->driverInstance = null;

        $active = $this->driver() instanceof IMower;
        $poll   = max(5, (int) $this->cfgVal('pollInterval', self::DEF_POLL_S));
        $this->SetTimerInterval(self::TIMER_REFRESH, $active ? $poll * 1000 : 0);
        $this->updateHealth();
        $this->ensureLogging();      // Archiv-Logging der Statusvariablen (idempotent)
    }

    /**
     * Setzt das Archiv-Logging der materialisierten Statusvariablen mit fester
     * Aggregation (idempotent, Parität zum Legacy-Baum). Ein finales ApplyChanges der
     * Archive-Control, nicht je Variable.
     */
    private function ensureLogging(): void
    {
        if (!function_exists('AC_SetLoggingStatus') || !function_exists('IPS_GetInstanceListByModuleID')) {
            return;
        }
        $aidList = @\IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}'); // Archive Control
        $aid = (is_array($aidList) && $aidList) ? (int) $aidList[0] : 0;
        if ($aid <= 0) {
            return;
        }
        $changed = false;
        foreach (self::LOG_MAP as $ident => $agg) {
            $vid = @$this->GetIDForIdent($ident);
            if (!is_int($vid) || $vid <= 0) {
                continue;
            }
            if (@\AC_GetLoggingStatus($aid, $vid) !== true) {
                @\AC_SetLoggingStatus($aid, $vid, true);
                $changed = true;
            }
            if (function_exists('AC_GetAggregationType') && (int) @\AC_GetAggregationType($aid, $vid) !== $agg) {
                @\AC_SetAggregationType($aid, $vid, $agg); // 0=Standard, 1=Zähler
                $changed = true;
            }
        }
        if ($changed) {
            @\IPS_ApplyChanges($aid); // einmalig, nicht je Variable
        }
    }

    protected function entityLabel(): string
    {
        return 'Mäher';
    }

    /** Farbige Assoc-Profile fuer Aktivitaet/Status (muss vor der Variablen-Anlage existieren). */
    private function ensureProfiles(): void
    {
        // 4. Element = IPS-Icon (Robot/Motion/Move/Battery/Plug/Power/Warning/Alert)
        $this->mkProfile('HSMW.Activity', [
            [0, 'Unbekannt', 0x9AA5AD, 'Information'], [1, '—', 0x9AA5AD, 'Robot'], [2, 'Mäht', 0x2ECC71, 'Motion'], [3, 'Fährt heim', 0x1ABC9C, 'Move'],
            [4, 'Lädt', 0x3498DB, 'Battery'], [5, 'Verlässt Station', 0x1ABC9C, 'Move'], [6, 'In Ladestation', 0x9AA5AD, 'Plug'], [7, 'Steht im Garten', 0xE67E22, 'Warning'],
        ]);
        $this->mkProfile('HSMW.State', [
            [0, 'Unbekannt', 0x9AA5AD, 'Information'], [1, '—', 0x9AA5AD, 'Robot'], [2, 'Pausiert', 0xF1C40F, 'Move'], [3, 'In Betrieb', 0x2ECC71, 'Motion'],
            [4, 'Aktualisiert', 0x3498DB, 'Information'], [5, 'Startet', 0x3498DB, 'Move'], [6, 'Eingeschränkt', 0x9AA5AD, 'Warning'], [7, 'Aus', 0x9AA5AD, 'Power'],
            [8, 'Gestoppt', 0xE67E22, 'Warning'], [9, 'Fehler', 0xE74C3C, 'Alert'], [10, 'Schwerer Fehler', 0xE74C3C, 'Alert'], [11, 'Fehler beim Start', 0xE74C3C, 'Alert'],
        ]);
        $this->mkProfile('HSMW.Headlight', [
            [0, 'Immer an', 0x00B294], [1, 'Immer aus', 0x9AA5AD], [2, 'Nur abends', 0x3498DB], [3, 'Abends & nachts', 0x2C3E50],
        ]);
        // Automatik-Modus (Regen-Autologik): je Mäher unabhängig
        $this->mkProfile('HSMW.AutoMode', [
            [0, 'Auto', 0x3498DB, 'Motion'], [1, 'Pause', 0x9AA5AD, 'Power'], [2, 'Manuell', 0xF2A03D, 'Move'], [3, 'Logik', 0x2ECC71, 'Robot'],
        ]);
    }

    private function mkProfile(string $name, array $assoc): void
    {
        if (!function_exists('IPS_VariableProfileExists')) {
            return;
        }
        if (!@\IPS_VariableProfileExists($name)) {
            @\IPS_CreateVariableProfile($name, 1); // 1 = Integer
        }
        foreach ($assoc as $a) {
            @\IPS_SetVariableProfileAssociation($name, $a[0], $a[1], (string) ($a[3] ?? ''), (int) $a[2]);
        }
    }

    // ==================================================================
    // Manifest (Vertrag 2)
    // ==================================================================

    protected function manifest(): array
    {
        return [
            'domain' => 'mower',
            'title'  => 'Maeher',
            'icon'   => 'Move',

            'controls' => [
                // --- Kommandos (transient, armed-gated) ---
                ['ident' => 'Start', 'type' => ControlContract::T_COMMAND, 'role' => 'mower:start',
                 'label' => 'Maehen', 'varType' => 1, 'actionable' => true],
                ['ident' => 'Park', 'type' => ControlContract::T_COMMAND, 'role' => 'mower:park',
                 'label' => 'Parken', 'varType' => 1, 'actionable' => true],
                ['ident' => 'Pause', 'type' => ControlContract::T_COMMAND, 'role' => 'mower:pause',
                 'label' => 'Pause', 'varType' => 1, 'actionable' => true],
                ['ident' => 'Resume', 'type' => ControlContract::T_COMMAND, 'role' => 'mower:resume',
                 'label' => 'Weiterfahren', 'varType' => 1, 'actionable' => true],
                ['ident' => 'ConfirmError', 'type' => ControlContract::T_COMMAND, 'role' => 'mower:confirmerror',
                 'label' => 'Fehler quittieren', 'varType' => 1, 'actionable' => true],

                // --- Gated schaltbar ---
                ['ident' => 'CuttingHeight', 'type' => ControlContract::T_SETPOINT, 'role' => 'mower:cuttingheight',
                 'label' => 'Schnitthoehe', 'varType' => 1, 'min' => 1, 'max' => 9, 'step' => 1, 'actionable' => true],
                ['ident' => 'Headlight', 'type' => ControlContract::T_SELECT, 'role' => 'mower:headlight',
                 'label' => 'Scheinwerfer', 'varType' => 1, 'actionable' => true, 'profile' => 'HSMW.Headlight',
                 'options' => [
                     ['value' => 0, 'label' => 'Immer an'],
                     ['value' => 1, 'label' => 'Immer aus'],
                     ['value' => 2, 'label' => 'Nur abends'],
                     ['value' => 3, 'label' => 'Abends & nachts'],
                 ]],
                // Automatik-Modus (lokal, NICHT gated) — Regen-Autologik je Mäher
                ['ident' => 'AutoMode', 'type' => ControlContract::T_SELECT, 'role' => 'mower:automode',
                 'label' => 'Automatik', 'varType' => 1, 'actionable' => true, 'profile' => 'HSMW.AutoMode',
                 'options' => [
                     ['value' => 0, 'label' => 'Auto'],
                     ['value' => 1, 'label' => 'Pause'],
                     ['value' => 2, 'label' => 'Manuell'],
                     ['value' => 3, 'label' => 'Logik'],
                 ]],

                // --- Reflect (read-only, nie gated) ---
                ['ident' => 'Activity', 'type' => ControlContract::T_REFLECT, 'role' => 'mower:activity',
                 'label' => 'Aktivitaet', 'varType' => 1, 'profile' => 'HSMW.Activity', 'actionable' => false],
                ['ident' => 'State', 'type' => ControlContract::T_REFLECT, 'role' => 'mower:state',
                 'label' => 'Status', 'varType' => 1, 'profile' => 'HSMW.State', 'actionable' => false],
                ['ident' => 'Mode', 'type' => ControlContract::T_REFLECT, 'role' => 'mower:mode',
                 'label' => 'Modus', 'varType' => 3, 'actionable' => false],
                ['ident' => 'Battery', 'type' => ControlContract::T_REFLECT, 'role' => 'mower:battery',
                 'label' => 'Akku', 'varType' => 1, 'profile' => '~Battery.100', 'actionable' => false],
                ['ident' => 'Online', 'type' => ControlContract::T_REFLECT, 'role' => 'mower:online',
                 'label' => 'Online', 'varType' => 0, 'profile' => '~Switch', 'actionable' => false],
                ['ident' => 'InChargingStation', 'type' => ControlContract::T_REFLECT, 'role' => 'mower:incharging',
                 'label' => 'In Ladestation', 'varType' => 0, 'profile' => '~Switch', 'actionable' => false],
                ['ident' => 'ErrorText', 'type' => ControlContract::T_REFLECT, 'role' => 'mower:errortext',
                 'label' => 'Fehler', 'varType' => 3, 'actionable' => false],
                ['ident' => 'NextStart', 'type' => ControlContract::T_REFLECT, 'role' => 'mower:nextstart',
                 'label' => 'Naechster Start', 'varType' => 1, 'profile' => '~UnixTimestamp', 'actionable' => false],
                ['ident' => 'RunningTime', 'type' => ControlContract::T_REFLECT, 'role' => 'mower:runningtime',
                 'label' => 'Laufzeit', 'varType' => 1, 'unit' => ' s', 'actionable' => false],
                ['ident' => 'Collisions', 'type' => ControlContract::T_REFLECT, 'role' => 'mower:collisions',
                 'label' => 'Kollisionen', 'varType' => 1, 'actionable' => false],
                ['ident' => 'Lat', 'type' => ControlContract::T_REFLECT, 'role' => 'mower:lat',
                 'label' => 'Breitengrad', 'varType' => 2, 'actionable' => false],
                ['ident' => 'Lng', 'type' => ControlContract::T_REFLECT, 'role' => 'mower:lng',
                 'label' => 'Laengengrad', 'varType' => 2, 'actionable' => false],
                ['ident' => 'LastUpdate', 'type' => ControlContract::T_REFLECT, 'role' => 'mower:lastupdate',
                 'label' => 'Aktualisiert', 'varType' => 1, 'profile' => '~UnixTimestamp', 'actionable' => false],
                // --- Zusatzdaten (Wartung / Modell / Mission) ---
                ['ident' => 'ChargingCycles', 'type' => ControlContract::T_REFLECT, 'role' => 'mower:chargingcycles',
                 'label' => 'Ladezyklen', 'varType' => 1, 'actionable' => false],
                ['ident' => 'BladeHours', 'type' => ControlContract::T_REFLECT, 'role' => 'mower:bladehours',
                 'label' => 'Messer-Laufzeit', 'varType' => 1, 'unit' => ' h', 'actionable' => false],
                ['ident' => 'SearchHours', 'type' => ControlContract::T_REFLECT, 'role' => 'mower:searchhours',
                 'label' => 'Suchzeit', 'varType' => 1, 'unit' => ' h', 'actionable' => false],
                ['ident' => 'Model', 'type' => ControlContract::T_REFLECT, 'role' => 'mower:model',
                 'label' => 'Modell', 'varType' => 3, 'actionable' => false],
                ['ident' => 'Firmware', 'type' => ControlContract::T_REFLECT, 'role' => 'mower:firmware',
                 'label' => 'Firmware', 'varType' => 3, 'actionable' => false],
                ['ident' => 'UpdateRequired', 'type' => ControlContract::T_REFLECT, 'role' => 'mower:updaterequired',
                 'label' => 'Update nötig', 'varType' => 0, 'profile' => '~Switch', 'actionable' => false],
                ['ident' => 'Mission', 'type' => ControlContract::T_REFLECT, 'role' => 'mower:mission',
                 'label' => 'Aktuelle Mission', 'varType' => 3, 'actionable' => false],

                // --- Zusatz-Zeiten/Statistik (loggbar bzw. JSON) ---
                ['ident' => 'CuttingTime', 'type' => ControlContract::T_REFLECT, 'role' => 'mower:cuttingtime',
                 'label' => 'Schneidzeit', 'varType' => 1, 'unit' => ' s', 'actionable' => false],
                ['ident' => 'ChargingTime', 'type' => ControlContract::T_REFLECT, 'role' => 'mower:chargingtime',
                 'label' => 'Ladezeit', 'varType' => 1, 'unit' => ' s', 'actionable' => false],
                ['ident' => 'SearchTime', 'type' => ControlContract::T_REFLECT, 'role' => 'mower:searchtime',
                 'label' => 'Suchzeit (s)', 'varType' => 1, 'unit' => ' s', 'actionable' => false],
                ['ident' => 'BladeUsagePct', 'type' => ControlContract::T_REFLECT, 'role' => 'mower:bladeusage',
                 'label' => 'Messer-Verschleiss', 'varType' => 2, 'unit' => ' %', 'actionable' => false],
                ['ident' => 'Efficiency', 'type' => ControlContract::T_REFLECT, 'role' => 'mower:efficiency',
                 'label' => 'Effizienz', 'varType' => 2, 'unit' => ' %', 'actionable' => false],
                // Fortschritt des GERADE bearbeiteten Arbeitsbereichs - dieselbe Zahl, die
                // die Husqvarna-App je Bereich zeigt. Welcher Bereich gemeint ist, steht in
                // 'Mission'; beides kommt aus derselben Auswertung im Treiber.
                ['ident' => 'MissionProgress', 'type' => ControlContract::T_REFLECT, 'role' => 'mower:missionprogress',
                 'label' => 'Fortschritt Bereich', 'varType' => 1, 'unit' => ' %', 'actionable' => false],
                ['ident' => 'ErrorCode', 'type' => ControlContract::T_REFLECT, 'role' => 'mower:errorcode',
                 'label' => 'Fehlercode', 'varType' => 1, 'actionable' => false],
                // --- JSON-Reflects (nicht loggen; nur Voll-Poll) ---
                ['ident' => 'Geofence', 'type' => ControlContract::T_REFLECT, 'role' => 'mower:geofence',
                 'label' => 'Geofence (JSON)', 'varType' => 3, 'actionable' => false],
                ['ident' => 'WorkAreasJson', 'type' => ControlContract::T_REFLECT, 'role' => 'mower:workareas',
                 'label' => 'Arbeitsbereiche (JSON)', 'varType' => 3, 'actionable' => false],
                ['ident' => 'StayOutJson', 'type' => ControlContract::T_REFLECT, 'role' => 'mower:stayoutzones',
                 'label' => 'Sperrzonen (JSON)', 'varType' => 3, 'actionable' => false],
                ['ident' => 'ErrorHistory', 'type' => ControlContract::T_REFLECT, 'role' => 'mower:errorhistory',
                 'label' => 'Fehlerhistorie (JSON)', 'varType' => 3, 'actionable' => false],
                ['ident' => 'TimersJson', 'type' => ControlContract::T_REFLECT, 'role' => 'mower:timers',
                 'label' => 'Mähplan (JSON)', 'varType' => 3, 'actionable' => false],
            ],

            'managementActions' => [
                ['op' => 'configureDriver', 'label' => 'Zugang/Treiber konfigurieren'],
                ['op' => 'getConfig',       'label' => 'Konfiguration lesen (Diagnose)'],
                ['op' => 'validate',        'label' => 'Bindung pruefen (Diagnose)'],
                ['op' => 'driverProbe',     'label' => 'Treiber-Status (Diagnose)'],
                ['op' => 'readNow',         'label' => 'Jetzt lesen (Schatten)'],
                ['op' => 'setArmed',        'label' => 'Scharfschalten / Schatten-Modus'],
                ['op' => 'importLegacy',    'label' => 'Aus PHPAutomower uebernehmen'],
                ['op' => 'migrateConfig',   'label' => 'Config auf Properties migrieren'],
                // --- Mähplan / Karte / Zusatz-Controls ---
                ['op' => 'getTimers',        'label' => 'Mähplan lesen'],
                ['op' => 'setTimers',        'label' => 'Mähplan schreiben (gated)'],
                ['op' => 'getMessages',      'label' => 'Fehlerhistorie lesen'],
                ['op' => 'mapData',          'label' => 'Kartendaten (Positionen/Geofence)'],
                ['op' => 'resetBlade',       'label' => 'Messer-Nutzungszeit zuruecksetzen (gated)'],
                ['op' => 'updateWorkArea',   'label' => 'Arbeitsbereich aktualisieren (gated)'],
                ['op' => 'updateStayOutZone','label' => 'Sperrzone schalten (gated)'],
            ],

            'capabilities' => [
                'scheduleMode' => 'device',
                'driver'       => $this->configuredDriverId(),
                'realtime'     => false,
            ],
        ];
    }

    // ==================================================================
    // Bedien-Hook (Vertrag 1) — Steuern hinter dem armed-Gate
    // ==================================================================

    protected function applyControl(Control $c, $value, ActionContext $ctx): void
    {
        switch ($c->ident) {
            case 'Start':         $this->gated('Start', fn(IMower $d) => $d->start((int) $value)); break;
            case 'Park':          $this->gated('Park', fn(IMower $d) => $d->park('furtherNotice', 0)); break;
            case 'Pause':         $this->gated('Pause', fn(IMower $d) => $d->pause()); break;
            case 'Resume':        $this->gated('Resume', fn(IMower $d) => $d->resume()); break;
            case 'ConfirmError':  $this->gated('ConfirmError', fn(IMower $d) => $d->confirmError()); break;
            case 'CuttingHeight': $this->gated('CuttingHeight', fn(IMower $d) => $d->setCuttingHeight((int) $value)); break;
            case 'Headlight':     $this->gated('Headlight', fn(IMower $d) => $d->setHeadlight($this->headlightModeStr((int) $value))); break;
            case 'AutoMode':
                // Die Stufe waehlt, WER den Maeher fuehrt - und "Pause" soll heissen, dass
                // niemand ihn fuehrt: weder die Autologik noch sein eigener Wochenplan.
                // Deshalb ist Pause nicht nur eine Notiz, sondern schickt ihn heim.
                $alt = (int) $this->GetValue('AutoMode');
                $neu = (int) $value;
                $this->SetValue('AutoMode', $neu);
                if ($neu === self::AUTO_PAUSE) {
                    // Bis auf Weiteres parken - haelt ihn auch ueber den geraeteeigenen Plan hinaus drin.
                    $this->gated('AutoMode=Pause -> Park', fn(IMower $d) => $d->park('furtherNotice', 0));
                } elseif ($alt === self::AUTO_PAUSE && $neu === self::AUTO_AUTO) {
                    // Genau die Umkehrung: das "bis auf Weiteres" aufheben und wieder dem
                    // eigenen Plan folgen. Nur aus Pause heraus - stuende er gerade im
                    // Maehen und wir schickten hier ein Park, wuerden wir ihn abwuergen.
                    $this->gated('AutoMode=Auto -> Plan', fn(IMower $d) => $d->park('nextSchedule', 0));
                }
                break;
            default:
                $this->SendDebug('HSMW.apply', $c->ident . '=' . (is_scalar($value) ? (string) $value : '?'), 0);
                break;
        }
    }

    /** Fuehrt eine Steueraktion aus — nur wenn scharf, sonst Schatten-Log. Danach zeitnah pollen. */
    private function gated(string $what, callable $fn): void
    {
        $d = $this->driver();
        if (!$d instanceof IMower) {
            $this->SendDebug('HSMW.cmd', $what . ': kein Treiber', 0);
            return;
        }
        // Wer den Befehl ausgeloest hat, weiss das Modul nicht: die Regen-Autologik des
        // Maehers liegt in externen Skripten (AutoMode 3 'Logik'), das Modul fuehrt nur aus.
        // Deshalb nennt das Protokoll die BETRIEBSART als Grund - daran erkennt man, ob ein
        // Mensch, der Zeitplan des Geraets oder die Autologik dahinterstand.
        $fmt = function (string $ident): string {
            $vid = @$this->GetIDForIdent($ident);
            return ($vid !== false && $vid > 0) ? (string) @\GetValueFormatted($vid) : '';
        };
        $grund = 'Betriebsart ' . ($fmt('AutoMode') ?: '?');
        $werte = ['akku_pct' => (int) $this->GetControlValue('Battery'),
                  'aktivitaet' => $fmt('Activity')];
        $bereich = (string) @$this->GetControlValue('Mission');
        if ($bereich !== '') { $werte['bereich'] = $bereich; }
        if (!$this->armed()) {
            $this->SendDebug('HSMW.shadow', 'WUERDE ' . $what . ' (nicht scharf)', 0);
            $this->entscheidungMerken($what, $grund, $werte, false);
            return;
        }
        try {
            $ok = (bool) $fn($d);
            $this->SendDebug('HSMW.cmd', $what . ' -> ' . ($ok ? 'ok' : 'FEHLER'), 0);
            $this->entscheidungMerken($what, $ok ? $grund : ($grund . ' - Befehl fehlgeschlagen'), $werte, true);
        } catch (\Throwable $e) {
            $this->SendDebug('HSMW.cmd', $what . ' Exception: ' . $e->getMessage(), 0);
            $this->entscheidungMerken($what, $grund . ' - Fehler: ' . $e->getMessage(), $werte, true);
        }
        // Ist-Zustand nach dem Kommando bald nachziehen.
        $this->SetTimerInterval(self::TIMER_REFRESH, 3000);
    }

    private function headlightModeStr(int $v): string
    {
        return [0 => 'ALWAYS_ON', 1 => 'ALWAYS_OFF', 2 => 'EVENING_ONLY', 3 => 'EVENING_AND_NIGHT'][$v] ?? 'ALWAYS_ON';
    }

    // ==================================================================
    // Poll (read-only, Schatten bis armed)
    // ==================================================================

    /** Timer-Callback (HSMW_Refresh): Ist-Zustand aus der Cloud spiegeln. */
    public function Refresh(): void
    {
        // Nach einem Kommando-Sofort-Poll wieder auf das normale Intervall zuruecksetzen.
        $poll = max(5, (int) $this->cfgVal('pollInterval', self::DEF_POLL_S));
        $this->SetTimerInterval(self::TIMER_REFRESH, $poll * 1000);

        $d = $this->driver();
        if (!$d instanceof IMower) {
            return;
        }

        // Cadence: schwere Endpunkte (Statistik/Timer/Geofence/Messages/WorkAreas) nur
        // alle FULL_POLL_S; Kern-Status (Activity/State/Battery/Position/Einstellungen) schnell.
        // Zeitstempel im Buffer (kein Attribut -> funktioniert auch fuer Bestandsinstanzen;
        // nach jedem Neustart einmal Voll-Poll, das ist gewollt).
        $now  = time();
        $last = (int) @$this->GetBuffer('LastFullPoll');
        $full = ($now - $last) >= self::FULL_POLL_S;

        $st = $d->readState($full);
        if ($st === []) {
            $this->setReflect('Online', false);
            $this->SendDebug('HSMW.poll', 'kein Zustand (offline/Login?)', 0);
            return;
        }
        if ($full) {
            @$this->SetBuffer('LastFullPoll', (string) $now);
        }

        // Skalar-Reflects.
        foreach (self::REFLECT_MAP as $k => $ident) {
            if (array_key_exists($k, $st) && $st[$k] !== null) {
                $this->setReflect($ident, $st[$k]);
            }
        }
        // JSON-Reflects (nur wenn geliefert -> i. d. R. Voll-Poll); Arrays/Objekte -> String.
        foreach (self::JSON_MAP as $k => $ident) {
            if (array_key_exists($k, $st) && $st[$k] !== null && $st[$k] !== []) {
                $this->setReflect($ident, json_encode($st[$k], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
        }
        if (!array_key_exists('online', $st) || $st['online'] === null) {
            $this->setReflect('Online', true); // Antwort erhalten -> online
        }
    }

    // ==================================================================
    // HAL-Treiber
    // ==================================================================

    protected function driver(): ?IDriver
    {
        if ($this->driverResolved) {
            return $this->driverInstance;
        }
        $this->driverResolved = true;
        $this->driverInstance = null;

        $cfg = $this->cfg();
        if ((string) ($cfg['driver'] ?? '') !== 'husqvarna-app') {
            return null;
        }
        if ((string) ($cfg['username'] ?? '') === '' || (string) ($cfg['password'] ?? '') === '' || (string) ($cfg['mowerId'] ?? '') === '') {
            return null;
        }
        try {
            $this->driverInstance = DriverFactory::create('husqvarna-app', [
                'username' => (string) $cfg['username'],
                'password' => (string) $cfg['password'],
                'mowerId'  => (string) $cfg['mowerId'],
            ]);
        } catch (\Throwable $e) {
            $this->SendDebug('HSMW.driver', 'Treiberaufbau fehlgeschlagen: ' . $e->getMessage(), 0);
            $this->driverInstance = null;
        }
        return $this->driverInstance;
    }

    // ==================================================================
    // Verwaltung (mgmt)
    // ==================================================================

    protected function mgmt(string $op, array $args, array $ctx): array
    {
        switch ($op) {
            case 'configureDriver':
                return $this->mgmtConfigureDriver($args, $ctx);
            case 'getConfig':
                $c = $this->cfg();
                unset($c['password']); // Secret nicht ausgeben
                return ['ok' => true, 'config' => $c];
            case 'validate':
                $h = $this->computeHealth();
                return ['ok' => $h['ok'], 'health' => $h['text'], 'issues' => $h['issues']];
            case 'driverProbe':
                return $this->mgmtDriverProbe();
            case 'readNow':
                $this->Refresh();
                return ['ok' => true, 'armed' => $this->armed(), 'state' => $this->snapshotReflects()];
            case 'setArmed':
                @\IPS_SetProperty($this->InstanceID, 'Armed', (bool) ($args['armed'] ?? false));
                @\IPS_ApplyChanges($this->InstanceID);
                return ['ok' => true, 'armed' => $this->armed()];
            case 'importLegacy':
                return $this->mgmtImportLegacy($args);
            case 'migrateConfig':
                return ['ok' => true, 'already' => true, 'note' => 'MowerDevice startet nativ mit Properties'];

            // --- Mähplan / Zusatz-Controls / Karte (WS3/4/2) ---
            case 'getTimers': {
                $d = $this->driver();
                if (!$d instanceof IMower) { return ['ok' => false, 'err' => 'kein Treiber']; }
                // Frisch lesen (Timer + Bereiche) — unabhaengig vom Voll-Poll-Timing.
                $st = $d->readState(true);
                return ['ok' => true, 'timers' => $st['timers'] ?? [], 'workAreas' => $st['workAreas'] ?? []];
            }
            case 'setTimers': {
                $d = $this->driver();
                if (!$d instanceof IMower) { return ['ok' => false, 'err' => 'kein Treiber']; }
                $desired = is_array($args['timers'] ?? null) ? $args['timers'] : [];
                if (!$this->armed()) {
                    $this->SendDebug('HSMW.shadow', 'WUERDE setTimers (' . count($desired) . ', nicht scharf)', 0);
                    return ['ok' => true, 'shadow' => true, 'timers' => $d->getTimers()];
                }
                try { $ok = $d->setTimers($desired); }
                catch (\Throwable $e) { return ['ok' => false, 'err' => $e->getMessage()]; }
                $this->SetTimerInterval(self::TIMER_REFRESH, 3000);
                return ['ok' => $ok, 'timers' => $d->getTimers()];
            }
            case 'getMessages':
                return ['ok' => true, 'messages' => $this->reflectJson('ErrorHistory')];
            case 'mapData':
                return $this->mgmtMapData();
            case 'resetBlade':
                return $this->mgmtGatedResult('resetBlade', fn(IMower $d) => $d->resetCuttingBlade());
            case 'updateWorkArea':
                return $this->mgmtGatedResult('updateWorkArea', fn(IMower $d) => $d->updateWorkArea(
                    (string) ($args['workAreaId'] ?? ''),
                    isset($args['cuttingHeight']) ? (int) $args['cuttingHeight'] : null,
                    isset($args['enabled']) ? (bool) $args['enabled'] : null
                ));
            case 'updateStayOutZone':
                return $this->mgmtGatedResult('updateStayOutZone', fn(IMower $d) => $d->updateStayOutZone(
                    (string) ($args['stayOutId'] ?? ''),
                    (bool) ($args['enabled'] ?? false)
                ));

            default:
                return parent::mgmt($op, $args, $ctx);
        }
    }

    /** JSON-Reflect als Array lesen (leer, wenn nicht gesetzt). */
    private function reflectJson(string $ident): array
    {
        $v = @$this->GetControlValue($ident);
        if (!is_string($v) || $v === '') { return []; }
        $j = json_decode($v, true);
        return is_array($j) ? $j : [];
    }

    /** Positionen/Geofence/Aktivität für die Live-Karte (leichter Poll + gespeicherter Geofence). */
    private function mgmtMapData(): array
    {
        $d = $this->driver();
        if (!$d instanceof IMower) { return ['ok' => false, 'err' => 'kein Treiber']; }
        $st  = $d->readState(false); // leicht: Positionen enthalten
        $geo = $this->reflectJson('Geofence');
        if (!$geo && isset($st['geofence']) && is_array($st['geofence'])) { $geo = $st['geofence']; }
        return [
            'ok'        => true,
            'positions' => $st['positions'] ?? [],
            'geofence'  => $geo ?: null,
            'activity'  => $st['activity'] ?? null,
        ];
    }

    /** Gated Schreibaktion -> mgmt-Result (Schatten wenn nicht scharf). */
    private function mgmtGatedResult(string $what, callable $fn, array $extra = []): array
    {
        $d = $this->driver();
        if (!$d instanceof IMower) { return ['ok' => false, 'err' => 'kein Treiber']; }
        if (!$this->armed()) {
            $this->SendDebug('HSMW.shadow', 'WUERDE ' . $what . ' (nicht scharf)', 0);
            return ['ok' => true, 'shadow' => true] + $extra;
        }
        try { $ok = (bool) $fn($d); }
        catch (\Throwable $e) { return ['ok' => false, 'err' => $e->getMessage()]; }
        $this->SetTimerInterval(self::TIMER_REFRESH, 3000);
        return ['ok' => $ok] + $extra;
    }

    private function mgmtConfigureDriver(array $args, array $ctx): array
    {
        $user = trim((string) ($args['username'] ?? ''));
        $pass = (string) ($args['password'] ?? '');
        $mid  = trim((string) ($args['mowerId'] ?? ''));
        if ($user === '' || $mid === '') {
            throw new ContractException('username und mowerId sind Pflicht');
        }
        $config = [
            'driver'   => 'husqvarna-app',
            'username' => $user,
            'mowerId'  => $mid,
            'pollInterval' => max(5, (int) ($args['pollInterval'] ?? self::DEF_POLL_S)),
        ];
        if ($pass !== '') {
            $config['password'] = $pass; // leeres Passwort NICHT ueberschreiben
        }
        if (!empty($ctx['dryrun'])) {
            $config['password'] = '***';
            return ['ok' => true, 'dryrun' => true, 'config' => $config];
        }
        $this->applyConfigProperties($config, true);
        return ['ok' => true, 'driverActive' => $this->driver() instanceof IMower];
    }

    private function mgmtDriverProbe(): array
    {
        $d = $this->driver();
        if (!$d instanceof IMower) {
            return ['ok' => true, 'driverActive' => false];
        }
        return ['ok' => true, 'driverActive' => true, 'capabilities' => $d->capabilities(),
            'state' => $d->readState()];
    }

    /**
     * Uebernimmt Zugang aus dem produktiven PHPAutomower-Setup: Credentials aus
     * scripts/automower_token.json, Maeher-ID aus einer IPS-Variable (mowerIdVarId).
     * armed bleibt false (Schatten).
     */
    private function mgmtImportLegacy(array $args): array
    {
        $tokenFile = '/var/lib/symcon/scripts/automower_token.json';
        $user = ''; $pass = '';
        if (is_file($tokenFile)) {
            $j = json_decode((string) @file_get_contents($tokenFile), true);
            if (is_array($j)) {
                $user = (string) ($j['username'] ?? '');
                $pass = (string) @base64_decode((string) ($j['password'] ?? ''), true);
            }
        }
        $mid = trim((string) ($args['mowerId'] ?? ''));
        if ($mid === '' && (int) ($args['mowerIdVarId'] ?? 0) > 0) {
            $vid = (int) $args['mowerIdVarId'];
            if (function_exists('IPS_VariableExists') && @\IPS_VariableExists($vid)) {
                $mid = trim((string) @\GetValue($vid));
            }
        }
        if ($user === '' || $pass === '' || $mid === '') {
            throw new ContractException('Konnte Zugang/Maeher-ID nicht ermitteln (Token-Datei bzw. mowerId/mowerIdVarId)');
        }
        $this->applyConfigProperties([
            'driver' => 'husqvarna-app', 'username' => $user, 'password' => $pass,
            'mowerId' => $mid, 'armed' => false, 'pollInterval' => self::DEF_POLL_S,
        ], true);
        return ['ok' => true, 'mowerId' => $mid, 'driverActive' => $this->driver() instanceof IMower];
    }

    private function snapshotReflects(): array
    {
        $out = [];
        foreach (['Activity', 'State', 'Mode', 'Battery', 'Online', 'InChargingStation', 'ErrorText', 'NextStart', 'RunningTime', 'Collisions', 'Lat', 'Lng', 'LastUpdate'] as $id) {
            $out[$id] = $this->GetControlValue($id);
        }
        return $out;
    }

    // ==================================================================
    // Health
    // ==================================================================

    private function computeHealth(): array
    {
        $cfg = $this->cfg();
        if ((string) ($cfg['driver'] ?? '') !== 'husqvarna-app') {
            return ['ok' => false, 'text' => 'inaktiv (kein Treiber)', 'issues' => ['kein Treiber']];
        }
        $issues = [];
        if ((string) ($cfg['username'] ?? '') === '') { $issues[] = 'Benutzername fehlt'; }
        if ((string) ($cfg['password'] ?? '') === '') { $issues[] = 'Passwort fehlt'; }
        if ((string) ($cfg['mowerId'] ?? '') === '') { $issues[] = 'Maeher-ID fehlt'; }
        if ($issues) {
            return ['ok' => false, 'text' => 'FEHLER: ' . implode(', ', $issues), 'issues' => $issues];
        }
        $armed = (bool) ($cfg['armed'] ?? false);
        return ['ok' => true, 'issues' => [], 'text' => 'OK · husqvarna-app' . ($armed ? ' · scharf' : ' · Schatten-Modus')];
    }

    private function updateHealth(): void
    {
        @$this->RegisterVariableString('BindHealth', 'Bindung', '', 95);
        @$this->SetValue('BindHealth', (string) $this->computeHealth()['text']);
    }

    // ==================================================================
    // Oeffentliche Scripting-Prozeduren (HSMW_*)
    // ==================================================================

    public function StartMowing(int $Minutes = 0): bool { return $this->setControlValue('Start', $Minutes); }
    public function Park(): bool                         { return $this->setControlValue('Park', 1); }
    public function Pause(): bool                        { return $this->setControlValue('Pause', 1); }
    public function Resume(): bool                       { return $this->setControlValue('Resume', 1); }
    public function ConfirmError(): bool                 { return $this->setControlValue('ConfirmError', 1); }
    public function SetCuttingHeight(int $Level): bool   { return $this->setControlValue('CuttingHeight', $Level); }
    public function SetHeadlight(int $Mode): bool        { return $this->setControlValue('Headlight', $Mode); }

    public function Refreshed(): bool { $this->Refresh(); return true; }

    public function SetArmed(bool $Armed): bool
    {
        $r = json_decode($this->Manage(json_encode(['op' => 'setArmed', 'args' => ['armed' => $Armed]])), true);
        return is_array($r) && (isset($r['armed']) ? (bool) $r['armed'] : (!empty($r['ok']) ? $Armed : false));
    }

    public function GetBattery(): int  { return (int) $this->GetControlValue('Battery'); }
    public function GetActivity(): string { return (string) $this->GetControlValue('Activity'); }
    public function GetState(): string { return (string) $this->GetControlValue('State'); }
    public function IsOnline(): bool   { return (bool) $this->GetControlValue('Online'); }

    // ==================================================================
    // Konsolen-Formular (Erstkonfiguration; Verwaltung sonst im LVB)
    // ==================================================================

    public function GetConfigurationForm()
    {
        $cfg = $this->cfg();
        $h   = $this->computeHealth();
        return json_encode(['elements' => [
            ['type' => 'Label', 'caption' => 'Husqvarna-Maeher ueber die undokumentierte App-API (keine Limits, kein App-Key). '
                . 'Zugang = Husqvarna-Konto (E-Mail/Passwort). Maeher-ID im Format 240201677-999999999.'],
            ['type' => 'Select', 'name' => 'Driver', 'caption' => 'Treiber', 'value' => (string) ($cfg['driver'] ?? ''),
                'options' => [
                    ['caption' => '— inaktiv (Schatten) —', 'value' => ''],
                    ['caption' => 'husqvarna-app (dss)', 'value' => 'husqvarna-app'],
                ]],
            ['type' => 'ValidationTextBox', 'name' => 'Username', 'caption' => 'Benutzer (E-Mail)', 'value' => (string) ($cfg['username'] ?? '')],
            ['type' => 'PasswordTextBox', 'name' => 'Password', 'caption' => 'Passwort'],
            ['type' => 'ValidationTextBox', 'name' => 'MowerId', 'caption' => 'Maeher-ID', 'value' => (string) ($cfg['mowerId'] ?? '')],
            ['type' => 'NumberSpinner', 'name' => 'PollInterval', 'caption' => 'Poll-Intervall (s)', 'minimum' => 5, 'maximum' => 3600, 'value' => (int) ($cfg['pollInterval'] ?? self::DEF_POLL_S)],
            ['type' => 'CheckBox', 'name' => 'Armed', 'caption' => 'Scharf (schreibt echte Kommandos) — sonst Schatten-Modus'],
            ['type' => 'ExpansionPanel', 'caption' => 'Karte', 'items' => [
                ['type' => 'Label', 'caption' => 'Farben leer lassen = wie bisher: der Pfad nimmt die Farbe der aktuellen Taetigkeit, '
                    . 'der Zaun Hausblau. Eine eigene Farbe ist sinnvoll, wenn mehrere Maeher nebeneinander gezeigt werden.'],
                // Bewusst Textfelder statt SelectColor: SelectColor liefert eine Zahl und
                // kennt kein "nicht gesetzt" - genau das brauchen wir aber, damit "leer"
                // weiter die Taetigkeitsfarbe bedeutet.
                ['type' => 'ValidationTextBox', 'name' => 'KartenFarbe',  'caption' => 'Bewegungspfad (z. B. #00cdab)'],
                ['type' => 'ValidationTextBox', 'name' => 'KartenMarker', 'caption' => 'Positionsnadel (leer = wie Pfad)'],
                ['type' => 'ValidationTextBox', 'name' => 'KartenZaun',   'caption' => 'Geofence-Kreis (leer = Hausblau)'],
                ['type' => 'NumberSpinner', 'name' => 'KartenZoom', 'caption' => 'Zoomstufe (kleiner = mehr Umgebung)', 'minimum' => 1, 'maximum' => 24],
            ]],
            ['type' => 'Label', 'caption' => 'Status: ' . $h['text']],
            ['type' => 'Button', 'caption' => 'Jetzt lesen (Diagnose)', 'onClick' => 'echo HSMW_Manage($id, json_encode(["op"=>"readNow"]));'],
            ['type' => 'Button', 'caption' => 'Aus PHPAutomower uebernehmen (Token + Maeher-ID-Variable)', 'onClick' =>
                'echo HSMW_Manage($id, json_encode(["op"=>"importLegacy","args"=>["mowerId"=>$MowerId]]));'],
        ]]);
    }

    // ==================================================================
    // Config-Helfer (Store-Muster wie die anderen HomeSuite-Geraete)
    // ==================================================================

    protected function isAutomated(Control $c): bool
    {
        return false; // M1: keine Automatik-Hoheit noetig
    }

    private function cfg(): array
    {
        $out = [];
        foreach (self::PROP_MAP as $key => [$p, $t]) {
            $method = 'ReadProperty' . ($t === 'i' ? 'Integer' : ($t === 'f' ? 'Float' : ($t === 'b' ? 'Boolean' : 'String')));
            $out[$key] = $this->castProp($t, @$this->{$method}($p));
        }
        return $out;
    }

    private function cfgVal(string $key, $def)
    {
        $c = $this->cfg();
        $v = array_key_exists($key, $c) ? $c[$key] : $def;
        return $key === 'armed' ? $this->armedEffective((bool) $v) : $v;
    }

    private function armed(): bool
    {
        return $this->armedEffective($this->ReadPropertyBoolean('Armed'));
    }

    private function configuredDriverId(): string
    {
        return (string) ($this->cfg()['driver'] ?? '');
    }

    private function applyConfigProperties(array $c, bool $apply = true): void
    {
        foreach ($c as $k => $v) {
            if (isset(self::PROP_MAP[$k])) {
                [$p, $t] = self::PROP_MAP[$k];
                @\IPS_SetProperty($this->InstanceID, $p, $this->castProp($t, $v));
            }
        }
        if ($apply) {
            @\IPS_ApplyChanges($this->InstanceID);
        }
    }

    private function castProp(string $type, $v)
    {
        switch ($type) {
            case 'i': return (int) $v;
            case 'f': return (float) $v;
            case 'b': return (bool) $v;
            default:  return (string) $v;
        }
    }
}

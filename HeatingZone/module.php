<?php

declare(strict_types=1);

/**
 * HeatingZone (HSHT) — Domaenen-Modul „Heizung" (Phase 1, M1.1).
 *
 * Eine Instanz = ein Heizkreis/Raum. Erbt {@see \Hoep\HomeSuite\EntityModule}:
 * die Basis legt aus diesem manifest() die Status-Variablen an, aktiviert die
 * native RequestAction (Vertrag 1) und liefert das RPC-Trio
 * HSHT_GetManifest / HSHT_GetState / HSHT_Manage.
 *
 * Zwei Thermostat-Klassen (Leitprinzip 7, capabilities().scheduleMode):
 *  - 'device'     : Geraet fuehrt das Wochenprofil selbst (HomeMatic-Adapter, M1.2)
 *  - 'controller' : dummer Sollwert-Thermostat -> ScheduleEngine im Modul faehrt
 *                   den Zeitplan (GenericVariableThermostat, M1.2)
 * Manifest/Editor/Musterseite bleiben in beiden Faellen identisch.
 *
 * M1.2 = generischer Treiber verdrahtet. driver() baut aus der gespeicherten
 * Konfiguration (config.driver == 'generic-thermostat') einen kernel-freien
 * {@see GenericVariableThermostat} ueber die DriverFactory; applyControl() routet
 * Setpoint -> setSetpoint() (und, wenn eine Geraete-Modusvariable gebunden ist,
 * Mode -> setMode()). Ein Refresh-Timer zieht die Reflect-Controls (Ist-Temp/
 * Feuchte/Online) aus readLive() nach. Der Schreibpfad wird ausschliesslich gegen
 * die im LVB zugeordneten Standard-Symcon-Variablen gefahren — noch KEIN
 * HomeMatic-Vendorcode (hm-* liefert driver()==null; der HM-Adapter folgt separat
 * und aktor-vorsichtig).
 */

require_once __DIR__ . '/../libs/HomeSuite/autoload.php';

use Hoep\HomeSuite\ActionContext;
use Hoep\HomeSuite\ContractException;
use Hoep\HomeSuite\Control;
use Hoep\HomeSuite\ControlContract;
use Hoep\HomeSuite\EntityModule;
use Hoep\HomeSuite\HAL\DriverFactory;
use Hoep\HomeSuite\HAL\IDriver;
use Hoep\HomeSuite\HAL\IThermostat;

// Klassenname MUSS = module.json "name" (ohne Leerzeichen) sein.
class HeatingZone extends EntityModule
{
    private const SETPOINT_MIN = 5.0;
    private const SETPOINT_MAX = 30.0;

    /** Tick-Intervall (ms): Reflect + Reconcile (Failsafe-Re-Assert). */
    private const REFRESH_MS = 30000;

    /** Timer-Ident (RegisterTimer/SetTimerInterval). */
    private const TIMER_REFRESH = 'Refresh';

    /** Frostschutz-Sollwert (°C), wenn nicht konfiguriert. */
    private const FROST_DEFAULT = 8.0;

    /** Periodisches Re-Assert des Sollwerts spaetestens alle N Sekunden (Failsafe). */
    private const REASSERT_SECONDS = 300;

    /**
     * Mindestabstand zwischen zwei CCU-Profilzugriffen - ueber ALLE Zonen hinweg.
     *
     * Am 23.08.2026 wurde die Domaene scharf geschaltet: alle 23 Zonen wollten im
     * selben Tick ihr Wochenprogramm schreiben, die CCU brach ein (getParamset
     * leer, putParamset Fehler, danach Timeout des HomeMatic-Sockets). Ein
     * Wochenprofil ist ein grosses Paramset - die Zentrale vertraegt das nur
     * einzeln. Ohne diese Bremse ist Scharfstellen ein Angriff auf die eigene CCU.
     */
    private const PUSH_ABSTAND_SEK = 20;

    /** Nach einem fehlgeschlagenen Schreibversuch so lange Ruhe geben. */
    private const PUSH_RUHE_SEK = 300;

    /** Datei mit dem Zeitstempel des letzten CCU-Profilzugriffs (prozessuebergreifend). */
    private const PUSH_STEMPEL = 'homesuite-ccu-push.stamp';

    /** So selten wird nachgesehen, ob der Profilzeiger des Geraets noch stimmt. */
    private const ZEIGER_PRUEFUNG_SEK = 900;

    /** Presence-Control-Wert (0..2) -> Zeitplan-Variante. */
    private const PRESENCE_VARIANTS = ['Normal', 'Erweitert', 'Abgesenkt'];

    /** Lazy-Cache des HAL-Treibers (pro Instanz-Prozess). */
    private ?IDriver $driverInstance = null;

    /** Wurde driver() in diesem Prozess-Stand schon aufgeloest? */
    private bool $driverResolved = false;

    /**
     * Vertrag 2 — das Manifest dieser Entitaet. Aus ihm legt die Basis die
     * Variablen an; der LVB rendert daraus Bedienung UND Verwaltung generisch.
     *
     * @return array<string,mixed>
     */
    protected function entityLabel(): string { return 'Heizung'; }

    /** Presence-Optionen aus den (ggf. umbenannten) Anzeigenamen. */
    private function presenceOptions(): array
    {
        $out = [];
        foreach ($this->presenceLabels() as $i => $t) {
            $out[] = ['value' => $i, 'label' => $t];
        }
        return $out;
    }

    /** Eigenes Profil erst, wenn wirklich umbenannt wurde. */
    private function presenceProfileSuffix(): string
    {
        return ($this->presenceLabels() === self::PRESENCE_VARIANTS)
            ? '' : (string) $this->InstanceID;
    }

    protected function manifest(): array
    {
        return [
            'domain'  => 'heating',
            'title'   => 'Heizung',
            'icon'    => 'Temperature',

            // ---- typisierte Controls (Vertrag 1) ----
            'controls' => [
                [
                    'ident' => 'Setpoint', 'type' => ControlContract::T_SETPOINT,
                    'role' => 'heating:setpoint', 'label' => 'Solltemperatur',
                    'varType' => 2, 'profile' => '~Temperature',
                    'unit' => '°C', 'min' => self::SETPOINT_MIN, 'max' => self::SETPOINT_MAX,
                    'step' => 0.5, 'dec' => 1, 'actionable' => true,
                ],
                [
                    'ident' => 'ActualTemp', 'type' => ControlContract::T_REFLECT,
                    'role' => 'heating:actual', 'label' => 'Ist-Temperatur',
                    'varType' => 2, 'profile' => '~Temperature', 'unit' => '°C',
                    'dec' => 1, 'actionable' => false,
                ],
                [
                    'ident' => 'Humidity', 'type' => ControlContract::T_REFLECT,
                    'role' => 'heating:humidity', 'label' => 'Luftfeuchte',
                    'varType' => 1, 'profile' => '~Humidity.F', 'unit' => '%',
                    'actionable' => false,
                ],
                [
                    'ident' => 'Mode', 'type' => ControlContract::T_SELECT,
                    'role' => 'heating:mode', 'label' => 'Modus',
                    'varType' => 1, 'actionable' => true,
                    'options' => [
                        ['value' => 0, 'label' => 'Auto'],
                        ['value' => 1, 'label' => 'Manuell'],
                        ['value' => 2, 'label' => 'Boost'],
                        ['value' => 3, 'label' => 'Frostschutz'],
                    ],
                    'deriveProfile' => true,
                ],
                [
                    'ident' => 'Presence', 'type' => ControlContract::T_SELECT,
                    'role' => 'heating:presence', 'label' => 'Praesenz',
                    'varType' => 1, 'actionable' => true,
                    // Beschriftungen frei benennbar (Eigenschaft PresenceLabels);
                    // solange die Vorgabe gilt, teilen sich alle Zonen ein Profil.
                    'options'       => $this->presenceOptions(),
                    'deriveProfile' => true,
                    'profileSuffix' => $this->presenceProfileSuffix(),
                ],
                [
                    'ident' => 'Online', 'type' => ControlContract::T_REFLECT,
                    'role' => 'heating:online', 'label' => 'Online',
                    'varType' => 0, 'actionable' => false,
                ],
            ],

            // ---- Profil-Typ: Wochenprofil (2 Achsen Praesenz x Wochentag) ----
            'profileTypes' => [
                'roomProfile' => [
                    'label'  => 'Wochenprofil',
                    'axes'   => [
                        'presence' => ['Normal', 'Erweitert', 'Abgesenkt'],
                        'weekday'  => ['MO', 'DI', 'MI', 'DO', 'FR', 'SA', 'SO'],
                    ],
                    'slot'   => ['end' => 'HH:MM', 'val' => ['type' => 'float', 'min' => self::SETPOINT_MIN, 'max' => self::SETPOINT_MAX]],
                    'rules'  => ['lastSlotEnd' => '24:00', 'ascending' => true],
                    'editor' => 'weekedit-hm',
                ],
            ],

            // ---- Verwaltungs-Aktionen (Whitelist; Impl folgt in M1.x) ----
            'managementActions' => [
                ['op' => 'createEntity',      'label' => 'Heizkreis anlegen'],
                ['op' => 'renameEntity',      'label' => 'Umbenennen'],
                ['op' => 'deleteEntity',      'label' => 'Loeschen'],
                ['op' => 'configureDriver',   'label' => 'Treiber konfigurieren'],
                ['op' => 'configureAutomation', 'label' => 'Automatik konfigurieren (Frostschutz)'],
                ['op' => 'updateProfile',     'label' => 'Wochenprofil bearbeiten'],
                ['op' => 'getSchedule',       'label' => 'Wochenplan lesen'],
                ['op' => 'duplicateProfile',  'label' => 'Profil duplizieren'],
                ['op' => 'assignProfile',     'label' => 'Profil zuweisen'],
                ['op' => 'setActivePresence', 'label' => 'Praesenz setzen'],
                ['op' => 'importLegacy',      'label' => 'Aus Altsteuerung importieren'],
                ['op' => 'pruefePush',        'label' => 'Fuehrt das Geraet den Plan schon? (nur lesen)'],
                ['op' => 'adoptDevice',       'label' => 'Geraeteprogramm uebernehmen'],
                ['op' => 'syncStatus',        'label' => 'Sync-Status'],
                ['op' => 'loadFromDevice',    'label' => 'Vom Geraet laden'],
                ['op' => 'syncToDevice',      'label' => 'Ans Geraet schreiben'],
                ['op' => 'getConfig',         'label' => 'Konfiguration lesen (Diagnose)'],
                ['op' => 'setArmed',          'label' => 'Scharfschalten / Schatten-Modus'],
                ['op' => 'migrateConfig',     'label' => 'Config auf Properties migrieren (einmalig)'],
            ],

            // ---- Konfig-Felder (Treiberwahl; im LVB gesetzt) ----
            'configFields' => [
                ['key' => 'driver', 'type' => 'select', 'label' => 'Treiber', 'required' => true,
                 'options' => [
                     ['value' => 'hm-HM-TC-IT-WM-W-EU', 'label' => 'HomeMatic HM-TC-IT-WM-W-EU'],
                     ['value' => 'hm-HM-CC-RT-DN',      'label' => 'HomeMatic HM-CC-RT-DN'],
                     ['value' => 'hm-HM-CC-TC',         'label' => 'HomeMatic HM-CC-TC'],
                     ['value' => 'generic-thermostat',  'label' => 'Generisch (Soll/Ist-Variablen)'],
                 ]],
                ['key' => 'targetId',    'type' => 'objid', 'label' => 'Geraet/Sollwert-Variable', 'required' => false],
                ['key' => 'sensorId',    'type' => 'objid', 'label' => 'Ist-Fuehler (optional)',   'required' => false],
            ],

            'capabilities' => [
                'scheduleMode' => $this->scheduleModeOf($this->configuredDriverId()),
                'hasPresence'  => true,
                'hasHumidity'  => true,
                'driver'       => $this->configuredDriverId(),
            ],
        ];
    }

    /** Konfigurierte driverId aus dem Store (ohne den Treiber zu bauen). */
    private function configuredDriverId(): string
    {
        return (string) $this->cfg()['driver'];
    }

    /** Konfiguration aus nativen Instanz-Properties (Symcon-Konzept). schedule.* bleibt im Store. */
    private function cfg(): array
    {
        return [
            'driver'    => $this->ReadPropertyString('Driver'),
            'targetId'  => $this->ReadPropertyInteger('TargetId'),
            'sensorId'  => $this->ReadPropertyInteger('SensorId'),
            'frostTemp' => $this->ReadPropertyFloat('FrostTemp'),
            'armed'     => $this->ReadPropertyBoolean('Armed'),
            'weekWrite' => $this->weekWriteMode(),
        ];
    }

    private function armed(): bool
    {
        return $this->armedEffective($this->ReadPropertyBoolean('Armed')); // Hub-Master hat Vorrang
    }

    /** Baum-Sichtbarkeit: Wochenplan (ScheduleEngine-Store) als read-only JSON spiegeln. */
    protected function refreshMirrors(): void
    {
        $this->mirrorVar('ScheduleJson', 'Wochenplan (JSON, Anzeige)', $this->store()->get('schedule', []));
    }

    /**
     * Schedule-Modus je Treiberklasse (Leitprinzip 7): der generische Sollwert-
     * Treiber laesst die ScheduleEngine im Modul fahren ('controller'), die
     * HomeMatic-Adapter fuehren das Wochenprofil selbst ('device'). Ohne
     * konfigurierten Treiber bleibt 'device' der Default (HM-Haus).
     */
    private function scheduleModeOf(string $driverId): string
    {
        return $driverId === 'generic-thermostat' ? 'controller' : 'device';
    }

    /**
     * Automatik-Hoheit: nur Solltemperatur & Modus oeffnen ein manualHold-Fenster
     * (A3 — Reflect/Presence nicht).
     */
    protected function isAutomated(Control $c): bool
    {
        return in_array($c->ident, ['Setpoint', 'Mode'], true);
    }

    /**
     * Vertrag 1 — realer Umsetzungs-Hook (M1.2). Der optimistische SetValue der
     * Basis hat den Wert bereits in die MODUL-Statusvariable geschrieben; hier
     * wird er an den HAL-Treiber weitergereicht, der ihn in die zugeordnete
     * GERAETE-/Sollwert-Variable schreibt. Wirft nie nach oben (die Basis faengt
     * ohnehin) — Treiber melden Fehler per bool/Log.
     */
    protected function applyControl(Control $c, $value, ActionContext $ctx): void
    {
        // Schatten-Modus: optimistischer SetValue der Basis bleibt, aber real wird
        // NICHTS geschrieben, bis armed=true (Cutover, Kollision mit Altsteuerung vermeiden).
        if (!$this->armed()) {
            $this->SendDebug('HSHT.shadow', $c->ident . '=' . $this->scalar($value) . ' (Schatten-Modus)', 0);
            return;
        }
        $drv = $this->driver();
        if (!$drv instanceof IThermostat) {
            $this->SendDebug(
                'HSHT.applyControl',
                $c->ident . '=' . $this->scalar($value) . ' (kein Thermostat-Treiber verdrahtet)',
                0
            );
            return;
        }

        switch ($c->ident) {
            case 'Setpoint':
                $ok = $drv->setSetpoint((float) $value);
                $this->SendDebug('HSHT.applyControl', 'setSetpoint(' . $this->scalar($value) . ') -> ' . ($ok ? 'ok' : 'FEHLER'), 0);
                break;

            case 'Mode':
                // Nur wenn der Treiber einen Geraete-Modus fuehrt (hasMode). Beim
                // generischen Treiber ohne modeVarId ist setMode() ein No-op; der
                // Modul-Modus bleibt dann rein modulintern (ScheduleEngine).
                $caps = $drv->capabilities();
                if (!empty($caps['hasMode'])) {
                    $drv->setMode($this->modeToDeviceString((int) $value));
                }
                break;

            default:
                // Presence/Reflect: kein direkter Aktorbefehl.
                break;
        }

        // Nach jedem Schreiben die Reflect-Controls frisch nachziehen.
        $this->reflectFromDriver($drv);

        // Praesenzwechsel heisst am Geraet ZUERST: anderes Profil waehlen. Ein
        // TC-IT fuehrt drei Wochenprogramme und entscheidet ueber den Zeiger
        // WEEK_PROGRAM_POINTER, welches gilt - genau so macht es die
        // Altsteuerung. Ohne diesen Schritt schaltet die Praesenz nur eine
        // Modulvariable um, und das Geraet heizt weiter nach dem alten Profil.
        // Geraete mit einem einzigen Profil melden true und bekommen den Plan
        // im anschliessenden reconcile() geschrieben - dort IST die Wahl das
        // Uebertragen.
        if ($c->ident === 'Presence') {
            $this->selectDeviceProfile($drv, (int) $value);
            // Ein Geraet mit nur EINEM Speicherplatz (CC-TC, RT-DN) kennt keinen
            // Zeiger - dort IST die Praesenzwahl das Uebertragen des Plans. Das ist
            // eine ausdrueckliche Ansage des Nutzers, kein Routinedurchlauf: sie
            // darf weder an der Erstberuehrungs-Sperre noch an einer laufenden
            // Ruhezeit haengenbleiben. Der Merker wird beim naechsten Durchlauf
            // ausgewertet und dort geloescht.
            $caps = $drv->capabilities();
            if ((int) ($caps['deviceProfiles'] ?? 1) < 2) {
                $rt = $this->readRt();
                $rt['pushAnsage'] = $this->activeVariant();
                $this->writeRt($rt);
            }
        }

        // Praesenz-/Modus-Wechsel: Zeitplan sofort anwenden (device: Push,
        // controller: Sollwert nachfahren). Bei Setpoint NICHT (dort haelt der
        // manualHold der Basis den Nutzerwert).
        if ($c->ident === 'Presence' || $c->ident === 'Mode') {
            $this->reconcile($drv);
        }
    }

    // ==================================================================
    // HAL-Treiber (M1.2: generischer Variablen-Thermostat)
    // ==================================================================

    /**
     * Baut den HAL-Treiber aus der gespeicherten Konfiguration:
     *  - 'generic-thermostat' bindet die im LVB zugeordneten Standard-Variablen
     *    (Sollwert/Ist), scheduleMode 'controller'.
     *  - 'hm-*' baut den kanal-erkennenden HomeMaticThermostat gegen die
     *    CCU-Geraeteinstanz (targetId = Instanz-ID), scheduleMode 'device'.
     */
    protected function driver(): ?IDriver
    {
        if ($this->driverResolved) {
            return $this->driverInstance;
        }
        $this->driverResolved = true;
        $this->driverInstance = null;

        $cfg      = $this->cfg();
        $driverId = (string) ($cfg['driver'] ?? '');
        $targetId = (int) ($cfg['targetId'] ?? 0);

        if ($driverId === '' || $targetId <= 0) {
            return null; // unkonfiguriert
        }

        try {
            if ($driverId === 'generic-thermostat') {
                $driverCfg = [
                    'setpointVarId' => $targetId,
                    'min'           => self::SETPOINT_MIN,
                    'max'           => self::SETPOINT_MAX,
                    'step'          => 0.5,
                ];
                $sensorId = (int) ($cfg['sensorId'] ?? 0);
                if ($sensorId > 0) {
                    $driverCfg['actualVarId'] = $sensorId;
                }
                $this->driverInstance = DriverFactory::create('generic-thermostat', $driverCfg);
            } elseif (strncmp($driverId, 'hm-', 3) === 0) {
                // hm-*: targetId ist die CCU-Geraeteinstanz; der Treiber loest die
                // Kanaele (SET_/ACTUAL_TEMPERATURE, HUMIDITY, VALVE) selbst auf.
                // sensorId (optional) = separate Sensor-/Wandgeraet-Instanz fuer
                // Ist-Temp/Feuchte (HM-CC-TC "Raumklima").
                $dcfg = [
                    'deviceInstanceId' => $targetId,
                    'min'              => self::SETPOINT_MIN,
                    'max'              => self::SETPOINT_MAX,
                    'step'             => 0.5,
                ];
                $sensorId = (int) ($cfg['sensorId'] ?? 0);
                if ($sensorId > 0) {
                    $dcfg['sensorInstanceId'] = $sensorId;
                }
                $this->driverInstance = DriverFactory::create($driverId, $dcfg);
            }
        } catch (\Throwable $e) {
            $this->SendDebug('HSHT.driver', 'Treiberaufbau fehlgeschlagen: ' . $e->getMessage(), 0);
            $this->driverInstance = null;
        }
        return $this->driverInstance;
    }

    /**
     * Zieht die Reflect-Controls (Ist-Temp/Feuchte/Online) aus readLive() nach.
     * Schreibt ausschliesslich Statusvariablen (Blocker D), NICHT den Store und
     * NICHT den Modul-Sollwert (der bleibt Nutzer-/Engine-Hoheit).
     */
    private function reflectFromDriver(IThermostat $drv): void
    {
        $live = $drv->readLive();

        if (($live['actual'] ?? null) !== null) {
            $this->setReflect('ActualTemp', (float) $live['actual']);
        }
        if (($live['humidity'] ?? null) !== null) {
            $this->setReflect('Humidity', (int) $live['humidity']);
        }
        // Ist-Sollwert des Geraets spiegeln (read-only, auch im Schatten-Modus), damit die
        // Anzeige den tatsaechlichen Sollwert zeigt statt 0. Kein Aktorschreiben.
        if (($live['setpoint'] ?? null) !== null) {
            @$this->SetValue('Setpoint', (float) $live['setpoint']);
        }
        // Online-Heuristik: Sollwert lesbar => zugeordnete Geraetevariable da.
        $this->setReflect('Online', ($live['setpoint'] ?? null) !== null);
    }

    /**
     * Modul-Modus (int) -> Geraete-Modus-String (auto|manual|boost|frost) fuer
     * setMode(). Nur relevant, wenn der Treiber hasMode meldet.
     */
    private function modeToDeviceString(int $mode): string
    {
        switch ($mode) {
            case 1:  return 'manual';
            case 2:  return 'boost';
            case 3:  return 'frost';
            case 0:
            default: return 'auto';
        }
    }

    private function scalar($value): string
    {
        return is_scalar($value) ? (string) $value : (string) json_encode($value);
    }

    // ==================================================================
    // Lebenszyklus: Refresh-Timer scharf/aus je nach Treiber
    // ==================================================================


    // Native Instanz-Properties (Symcon-Konzept). Wochenprofile (schedule.*) bleiben im Store.
    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyString('Driver', '');
        $this->RegisterPropertyInteger('TargetId', 0);
        $this->RegisterPropertyInteger('SensorId', 0);
        $this->RegisterPropertyFloat('FrostTemp', self::FROST_DEFAULT);
        $this->RegisterPropertyBoolean('Armed', false);    // Schatten-Modus bis Cutover
        $this->RegisterPropertyInteger('ConfigSchema', 0); // Migrations-Marker
        $this->RegisterPropertyInteger('QueryInterval', 30); // Abfrage-Intervall in SEKUNDEN (default = REFRESH_MS/1000)
        // Wann darf der Abgleich das WOCHENPROGRAMM ins Geraet schreiben?
        //   0 = nur auf Befehl   1 = bei Aenderung (Vorgabe)   2 = laufend
        // Siehe weekWriteMode() - die Begruendung steht dort.
        $this->RegisterPropertyInteger('WeekWrite', 1);
        // Anzeigenamen der drei Praesenzen. Bewusst GETRENNT von den
        // Wochenplan-Schluesseln (PRESENCE_VARIANTS): die Schluessel benennen
        // die Plaene in ScheduleJson und liessen sich nicht umbenennen, ohne
        // jeden Zeitplan zu migrieren. Leer = Vorgabe.
        $this->RegisterPropertyString('PresenceLabels', '');
    }

    /**
     * Anzeigenamen der Praesenzen (drei Stueck), Vorgabe = Wochenplan-Schluessel.
     * @return string[]
     */
    private function presenceLabels(): array
    {
        // manifest() laeuft auch waehrend parent::Create(), also BEVOR die
        // Eigenschaft registriert ist - dann gilt die Vorgabe.
        try {
            $roh = trim((string) $this->ReadPropertyString('PresenceLabels'));
        } catch (\Throwable $e) {
            return self::PRESENCE_VARIANTS;
        }
        $out = self::PRESENCE_VARIANTS;
        if ($roh === '') {
            return $out;
        }
        foreach (explode(',', $roh) as $i => $t) {
            $t = trim($t);
            if ($i < count($out) && $t !== '') {
                $out[$i] = $t;
            }
        }
        return $out;
    }

    /** Effektives Refresh-Intervall in Millisekunden aus QueryInterval (Boden 2s gegen Hot-Loop). */
    private function refreshIntervalMs(): int
    {
        return max(2, $this->ReadPropertyInteger('QueryInterval')) * 1000;
    }

    /** Bindungs-Links (Baum-Transparenz) auf Sollwert/Ist bzw. HM-Gerät — aus Properties. */
    protected function bindingTargets(): array
    {
        $cfg = $this->cfg();
        $out = [];
        $add = function (string $ident, string $name, int $id) use (&$out): void {
            if ($id > 0 && function_exists('IPS_ObjectExists') && @\IPS_ObjectExists($id)) {
                $out[] = ['ident' => $ident, 'name' => $name, 'targetId' => $id];
            }
        };
        $driver = (string) $cfg['driver'];
        $target = (int) $cfg['targetId'];
        $sensor = (int) $cfg['sensorId'];
        if (strncmp($driver, 'hm-', 3) === 0) {
            $add('bl_Device', 'HM-Gerät', $target);
            $add('bl_Setpoint', 'Sollwert', $this->resolveHmSetpoint($target));
            $add('bl_Sensor', 'Ist-Sensor', $sensor);
        } else {
            $add('bl_Setpoint', 'Sollwert', $target);
            $add('bl_Actual', 'Ist-Temperatur', $sensor);
        }
        return $out;
    }

    /** SET_TEMPERATURE/SETPOINT-Variable einer HM-Instanz (0 wenn nicht auflösbar). */
    private function resolveHmSetpoint(int $instanceId): int
    {
        if ($instanceId <= 0 || !function_exists('IPS_GetObjectIDByIdent')) {
            return 0;
        }
        $sp = @\IPS_GetObjectIDByIdent('SET_TEMPERATURE', $instanceId);
        if (!is_int($sp) || $sp <= 0) {
            $sp = @\IPS_GetObjectIDByIdent('SETPOINT', $instanceId);
        }
        return (is_int($sp) && $sp > 0) ? $sp : 0;
    }

    /** Schreibt Config-Felder in die nativen Properties (Teilmenge erlaubt). */
    private function applyConfigProperties(array $c, bool $apply = true): void
    {
        $S = fn(string $p, $v) => @\IPS_SetProperty($this->InstanceID, $p, $v);
        if (array_key_exists('driver', $c))    $S('Driver', (string) $c['driver']);
        if (array_key_exists('targetId', $c))  $S('TargetId', (int) $c['targetId']);
        if (array_key_exists('sensorId', $c))  $S('SensorId', (int) $c['sensorId']);
        if (array_key_exists('frostTemp', $c)) $S('FrostTemp', (float) $c['frostTemp']);
        if ($apply) {
            @\IPS_ApplyChanges($this->InstanceID);
        }
    }

    /** Einmal-Migration: alte FabricStore-config -> native Properties (per RPC-Op). */
    private function migrateConfig(): array
    {
        if ($this->ReadPropertyInteger('ConfigSchema') >= 1) {
            return ['ok' => true, 'already' => true, 'config' => $this->cfg()];
        }
        $c = $this->store()->get('config', []);
        $c = is_array($c) ? $c : [];
        $this->applyConfigProperties($c, false);
        @\IPS_SetProperty($this->InstanceID, 'ConfigSchema', 1);
        @\IPS_ApplyChanges($this->InstanceID);
        return ['ok' => true, 'migrated' => array_keys($c), 'config' => $this->cfg()];
    }

    protected function setupTimers(): void
    {
        // Timer anlegen (aus). Interval wird in ApplyChanges gesetzt.
        $this->RegisterTimer(self::TIMER_REFRESH, 0, 'HSHT_Refresh($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Konfiguration koennte sich geaendert haben -> Treiber neu aufloesen.
        $this->driverResolved = false;
        $this->driverInstance = null;

        $drv    = $this->driver();
        $active = $drv instanceof IThermostat;
        $this->SetTimerInterval(self::TIMER_REFRESH, $active ? $this->refreshIntervalMs() : 0);

        // Manual-Override-Erkennung: auf Aenderungen der GERAETE-Sollwertvariable
        // lauschen (jemand verstellt am Geraet/HM-Oberflaeche).
        $this->registerSetpointWatch($drv);

        // Aufraeumen: frueher angelegte native Wochenplan-Ereignisse (HeatSchedule_*)
        // entfernen — fuer stufenlose Solltemperaturen ungeeignet (Symcon-Limits).
        $this->cleanupHeatEvents();
    }

    /** Entfernt evtl. vorhandene HeatSchedule_*-Ereignisse (idempotent, je Instanz/leicht). */
    private function cleanupHeatEvents(): void
    {
        if (!function_exists('IPS_DeleteEvent')) {
            return;
        }
        for ($p = 0; $p < count(self::PRESENCE_VARIANTS); $p++) {
            $eid = @$this->GetIDForIdent('HeatSchedule_' . $p);
            if (is_int($eid) && $eid > 0 && @\IPS_EventExists($eid)) {
                @\IPS_DeleteEvent($eid);
            }
        }
    }

    // ==================================================================
    // Oeffentliche Scripting-Prozeduren (-> HSHT_SetSetpoint / _SetMode …)
    //
    // Duenne, typisierte Fassaden ueber SetControl/GetControlValue (Basis). Setzen
    // geht ueber RequestAction -> applyControl (armed-Gate/Reflect/Reconcile bleiben
    // erhalten); realer Effekt nur bei Armed=true + gebundenem Treiber.
    // Zeitplan-Bearbeitung bleibt Manage/LVB — hier nur Leser.
    // ==================================================================

    public function SetSetpoint(float $Celsius): bool { return $this->setControlValue('Setpoint', $Celsius); }
    public function SetMode(int $Mode): bool          { return $this->setControlValue('Mode', $Mode); }
    public function SetPresence(int $Presence): bool  { return $this->setControlValue('Presence', $Presence); }

    /** Modus per Klartext: auto|manual|manuell|boost|frost|frostschutz. */
    public function SetModeName(string $Mode): bool
    {
        $map = ['auto' => 0, 'manual' => 1, 'manuell' => 1, 'boost' => 2, 'frost' => 3, 'frostschutz' => 3];
        $k = strtolower(trim($Mode));
        if (!isset($map[$k])) {
            $this->LogMessage("HSHT.SetModeName: unbekannter Modus '{$Mode}'", KL_ERROR);
            return false;
        }
        return $this->setControlValue('Mode', $map[$k]);
    }

    public function Boost(): bool        { return $this->setControlValue('Mode', 2); }
    public function FrostProtect(): bool { return $this->setControlValue('Mode', 3); }

    public function GetSetpoint(): float   { return (float) $this->GetControlValue('Setpoint'); }
    public function GetActualTemp(): float { return (float) $this->GetControlValue('ActualTemp'); }
    public function GetHumidity(): int     { return (int) $this->GetControlValue('Humidity'); }
    public function GetMode(): int         { return (int) $this->GetControlValue('Mode'); }
    public function GetPresence(): int     { return (int) $this->GetControlValue('Presence'); }
    public function IsOnline(): bool       { return (bool) $this->GetControlValue('Online'); }

    /** Scharf/Schatten (Cutover). Liefert den resultierenden Armed-Zustand. */
    public function SetArmed(bool $Armed): bool
    {
        $r = json_decode($this->Manage(json_encode(['op' => 'setArmed', 'args' => ['armed' => $Armed]])), true);
        return is_array($r) && !empty($r['armed']);
    }

    /** Wochenplan einer Praesenz als JSON lesen (Diagnose); Presence<0 = aktive. */
    public function GetScheduleJson(int $Presence = -1): string
    {
        $args = ($Presence >= 0) ? ['variant' => $Presence] : [];
        return $this->Manage(json_encode(['op' => 'getSchedule', 'args' => $args]));
    }

    /**
     * Konsole: native Bindungs-Ansicht (additiv, ohne Properties -> wirkt sofort
     * auf Bestandsinstanzen, kein Kernel-Neustart). Felder mit aktueller Store-
     * Bindung vorbelegt; „Bindung uebernehmen" ruft configureDriver. Zeitplaene/
     * Praesenz kommen aus dem LiveViewBuilder.
     */
    public function GetConfigurationForm()
    {
        // Native Symcon-Konfiguration: Felder property-gebunden (name == Property),
        // gespeichert bei „Aenderungen uebernehmen". Buttons = Laufzeit-Aktionen (RPC).
        return json_encode([
            'elements' => [
                ['type' => 'Label', 'caption' => 'Heizung — Bindung an das Thermostat. Ziel: generic = Sollwert-Variable, hm-* = HomeMatic-CCU-Instanz. Zeitplaene/Praesenz kommen aus dem LiveViewBuilder.'],
                ['type' => 'Select', 'name' => 'Driver', 'caption' => 'Treiber', 'options' => [
                    ['caption' => '(keiner)', 'value' => ''],
                    ['caption' => 'Generisches Thermostat (Sollwert-Variable)', 'value' => 'generic-thermostat'],
                    ['caption' => 'HomeMatic HM-TC-IT-WM-W-EU (Wandthermostat)', 'value' => 'hm-HM-TC-IT-WM-W-EU'],
                    ['caption' => 'HomeMatic HM-CC-RT-DN (Heizkoerper)', 'value' => 'hm-HM-CC-RT-DN'],
                    ['caption' => 'HomeMatic HM-CC-TC', 'value' => 'hm-HM-CC-TC'],
                ]],
                ['type' => 'NumberSpinner', 'name' => 'QueryInterval', 'caption' => 'Abfrage-Intervall (s)'],
                ['type' => 'SelectObject', 'name' => 'TargetId', 'caption' => 'Ziel (Variable bei generic, CCU-Instanz bei hm-*)'],
                ['type' => 'SelectObject', 'name' => 'SensorId', 'caption' => 'Ist-Sensor (optional: Variable bzw. CCU-Instanz)'],
                ['type' => 'NumberSpinner', 'name' => 'FrostTemp', 'caption' => 'Frostschutz-Solltemperatur (°C)', 'digits' => 1, 'minimum' => 3, 'maximum' => 15],
                ['type' => 'ValidationTextBox', 'name' => 'PresenceLabels', 'caption' => 'Praesenz-Beschriftungen (drei, mit Komma; leer = Normal,Erweitert,Abgesenkt)'],
                ['type' => 'CheckBox', 'name' => 'Armed', 'caption' => 'Scharf — schaltet real (sonst Schatten-Modus; Altsteuerung bleibt Regler)'],
            ],
            'actions' => [
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'Button', 'caption' => 'Ist-Zustand', 'onClick' => 'echo HSHT_Manage($id, json_encode(["op"=>"getConfig"]));'],
                    ['type' => 'Button', 'caption' => 'Wochenplan lesen', 'onClick' => 'echo HSHT_Manage($id, json_encode(["op"=>"getSchedule"]));'],
                    ['type' => 'Button', 'caption' => 'Aus Altsteuerung importieren', 'onClick' => 'echo HSHT_Manage($id, json_encode(["op"=>"importLegacy","args"=>["dryrun"=>true]]));'],
                ]],
                ['type' => 'Label', 'caption' => 'Real geschaltet wird nur bei „scharf" (Armed). Zeitplan-/Praesenz-Verwaltung im LiveViewBuilder.'],
            ],
        ]);
    }

    /**
     * Timer-Callback (prefix: HSHT_Refresh). Reflect + Reconcile (Failsafe).
     * Public erzwungen durch SDK.
     */
    public function Refresh(): void
    {
        $drv = $this->driver();
        if (!$drv instanceof IThermostat) {
            return;
        }
        $this->reflectFromDriver($drv);
        $this->reconcile($drv);
    }

    // ==================================================================
    // Reconciler (Control-Layer / Failsafe) — Block C
    // ==================================================================

    /**
     * Faehrt den Sollwert generisch nach dem aktiven Zeitplan/Modus (controller-
     * Modus). Im device-Modus fuehrt das GERAET den Plan selbst — dann nur
     * sicherstellen, dass das aktuelle Wochenprogramm gepusht ist.
     */
    private function reconcile(IThermostat $drv): void
    {
        if (!$this->armed()) {
            return; // Schatten-Modus: kein reales Schreiben (Altsteuerung bleibt Regler)
        }
        if (!$this->automationEnabled()) {
            return; // globaler Automatik-Schalter (Hub) aus
        }
        $caps = $drv->capabilities();

        if (($caps['scheduleMode'] ?? 'controller') === 'device') {
            // Geraet fuehrt den Plan; Modul sorgt fuer den richtigen Zeiger und
            // den aktuellen Push.
            $this->zeigerAbgleichen($drv);
            $this->pushWeekIfChanged($drv);
            return;
        }

        // --- controller: Modul treibt den Sollwert ---
        $mode = $this->intVal('Mode');           // 0 Auto,1 Manuell,2 Boost,3 Frost
        if ($mode === 1) {
            return; // Manuell: Nutzer besitzt den Sollwert
        }
        if ($this->isManuallyHeld('Setpoint')) {
            return; // manueller Override aktiv (bis naechste Slot-Grenze)
        }

        $desired = $this->desiredSetpoint($mode);
        if ($desired === null) {
            return; // kein Plan fuer diesen Tag/Modus -> nichts erzwingen
        }

        // Re-Assert-Politik: schreiben, wenn der ISTWERT vom Soll abweicht
        // (Drift/konkurrierender Regler/abgelaufener Hold) ODER periodisch (Failsafe).
        // Gegen den Istwert vergleichen, NICHT nur gegen den zuletzt befohlenen.
        $rt      = $this->readRt();
        $lastTs  = (int) ($rt['lastAssertTs'] ?? 0);
        $actual  = $drv->readLive()['setpoint'] ?? null;
        $drift   = ($actual === null) || abs((float) $actual - $desired) > 0.01;
        $stale   = (time() - $lastTs) >= self::REASSERT_SECONDS;

        if (!$drift && !$stale) {
            return;
        }

        if ($drv->setSetpoint($desired)) {
            $this->SetValue('Setpoint', $desired);   // Modul-Sollwert spiegeln
            $rt['lastSet']      = $desired;
            $rt['lastAssertTs'] = time();
            $rt['selfWriteTs']  = time();            // fuer Self-Write-Unterdrueckung
            $rt['selfWriteVal'] = $desired;
            $this->writeRt($rt);
        }
    }

    /** Gewuenschter Sollwert je Modus (Auto/Boost aus Plan, Frost fix). */
    private function desiredSetpoint(int $mode): ?float
    {
        if ($mode === 3) {                            // Frostschutz (haus-weit vom Hub, sonst Instanz)
            $hf = (float) $this->hubProp('HeatFrostTemp', 0);
            return $hf > 0 ? $hf : (float) $this->ReadPropertyFloat('FrostTemp');
        }
        // Auto (0) und Boost (2, vorerst wie Auto) -> aus dem Wochenplan (ScheduleEngine,
        // serialisiert je Praesenz). Native Symcon-Wochenplan-Ereignisse eignen sich fuer
        // stufenlose Solltemperaturen NICHT (nur ~1 Aktion/Lauf, keine zuverlaessige
        // Aktualisierung, ID-Limit) -> Heizung bleibt store-basiert (Sonnen-Anker inklusive).
        $variant = $this->activeVariant();
        $v = $this->scheduleValueAt(time(), $variant);
        return is_numeric($v) ? (float) $v : null;
    }

    /** Aktive Zeitplan-Variante aus dem Presence-Control (0..2). */
    private function activeVariant(): string
    {
        $p = $this->intVal('Presence');
        return self::PRESENCE_VARIANTS[$p] ?? self::PRESENCE_VARIANTS[0];
    }

    /**
     * device-Modus: pusht das Wochenprogramm der aktiven Variante ins Geraet,
     * wenn es sich gegenueber dem zuletzt gepushten Stand geaendert hat.
     */
    /**
     * Wann der Abgleich das Wochenprogramm ins Geraet schreiben darf.
     *
     * Am 23.08.2026 hat das Scharfschalten der Heizdomaene 23 Zonen gleichzeitig
     * an die CCU geschickt, weil fuer KEINE ein Merker existierte - ein
     * unbekannter Merker sah aus wie "der Plan hat sich geaendert". Die CCU ist
     * dabei zusammengebrochen. Takt, Semaphore und Lesen-vor-Schreiben verhindern
     * den Schwarm inzwischen; die eigentliche Frage bleibt aber, warum ein
     * ROUTINEDURCHLAUF ueberhaupt schreibt.
     *
     *   0 nur auf Befehl  Der Abgleich schreibt nie. Geschrieben wird ausschliesslich
     *                     ueber `syncToDevice`. Ein im Haus geaenderter Zeitplan
     *                     erreicht das Geraet dann NICHT von selbst.
     *   1 bei Aenderung   Vorgabe. Geschrieben wird nur, wenn sich der Plan
     *                     gegenueber einem BEKANNTEN Merker geaendert hat. Beim
     *                     ersten Beruehren einer Zone wird nur gelesen und der
     *                     Merker gesetzt - ein unbekannter Merker kann damit
     *                     keinen Schreibvorgang mehr ausloesen.
     *   2 laufend         Verhalten bis 23.08.2026: unbekannter Merker fuehrt zu
     *                     Lesen, Vergleichen und gegebenenfalls Schreiben.
     */
    private function weekWriteMode(): int
    {
        $m = 1;
        try {
            $m = (int) $this->ReadPropertyInteger('WeekWrite');
        } catch (\Throwable $e) {
            $m = 1; // Eigenschaft noch nicht registriert (vor Modul-Neuladen)
        }
        return ($m >= 0 && $m <= 2) ? $m : 1;
    }

    private function pushWeekIfChanged(IThermostat $drv): void
    {
        $caps    = $drv->capabilities();
        $pi      = $this->activeVariantIndex();
        $variant = $this->activeVariant();
        // Dieselben Rasterwerte wie beim Seeden (seedPushHash) - vorher rechnete
        // die eine Stelle mit fest 10/13, die andere mit den Treiberwerten. Bei
        // einem CC-TC (24 Slots) kamen dabei zwei verschiedene Hashes derselben
        // Woche heraus, und die Zone wollte bei JEDEM Tick schreiben.
        $week = $this->schedules()->toHomematicWeek(
            $variant,
            (int) ($caps['rasterMinutes'] ?? 10),
            (int) ($caps['maxSlots'] ?? 13)
        );
        if ($this->weekIsEmpty($week)) {
            return; // kein Plan hinterlegt -> Geraeteprogramm nicht anfassen
        }
        $modus = $this->weekWriteMode();
        if ($modus === 0) {
            return; // nur auf Befehl - der Routinedurchlauf fasst das Geraet nicht an
        }
        $hash = md5($variant . '|' . json_encode($week));
        $rt   = $this->readRt();
        // Der Merker gehoert an den GERAETESPEICHERPLATZ, nicht an die Variante.
        // Ein TC-IT fuehrt drei Profile (P1..P3) - dort hat jede Variante ihren
        // eigenen Platz und einen eigenen Merker, sonst saehe ein Praesenzwechsel
        // wie ein geaenderter Plan aus. Ein CC-TC oder RT-DN hat aber nur EINEN
        // Platz, den sich alle Varianten teilen: ein Merker je Variante behauptet
        // dort "schon aktuell", waehrend im Geraet laengst der Plan einer anderen
        // Praesenz liegt. Gemessen am 29.08.2026 an der Kueche - nach Normal und
        // zurueck auf Abgesenkt lief das Geraet weiter mit dem Normal-Plan.
        $einSlot    = (int) ($caps['deviceProfiles'] ?? 1) < 2;
        $schluessel = $einSlot ? '*' : $variant;
        $ansage     = $einSlot && ((string) ($rt['pushAnsage'] ?? '') === $variant);

        $bekannt = is_array($rt['pushHashes'] ?? null) ? $rt['pushHashes'] : [];
        $vorher  = (string) ($bekannt[$schluessel] ?? '');
        if ($vorher === $hash) {
            if ($ansage) {
                unset($rt['pushAnsage']);
                $this->writeRt($rt);
            }
            return; // schon aktuell - ohne einen einzigen Netzzugriff
        }
        if (!$ansage && (int) ($rt['pushNext'] ?? 0) > time()) {
            return; // nach einem Fehlschlag erst wieder nach einer Ruhezeit
        }
        if (!$this->ccuSlotFrei()) {
            return; // eine andere Zone ist gerade dran
        }

        // ERST NACHSEHEN, DANN SCHREIBEN. Ein unbekannter Hash heisst nicht, dass
        // das Geraet etwas anderes fuehrt - nach einer Migration oder einem
        // Praesenzwechsel ist er nur noch nie gebildet worden. Blind zu schreiben
        // hiesse, 23 Geraeteprogramme anzufassen, um am Ende dasselbe hineinzulegen.
        $imGeraet = $drv->readWeekProfile($pi);
        if ($imGeraet === []) {
            // Nicht lesbar heisst NICHT "leer": die CCU war belegt, die Freigabe
            // haengt, das Geraet antwortet nicht. Wer jetzt schreibt, ueberschreibt
            // ein Programm, das er nicht kennt. Also warten und spaeter nachsehen.
            $rt['pushNext'] = time() + self::PUSH_RUHE_SEK;
            $this->writeRt($rt);
            $this->ccuSlotSchliessen();
            $this->SendDebug('HSHT.push', 'Geraeteprofil nicht lesbar - kein Schreibversuch', 0);
            return;
        }
        if (self::wochenGleich($imGeraet, $week)) {
            $bekannt[$schluessel] = $hash;
            $rt['pushHashes']     = $bekannt;
            unset($rt['pushNext'], $rt['pushAnsage']);
            $this->writeRt($rt);
            $this->ccuSlotSchliessen();
            $this->SendDebug('HSHT.push', 'Geraet fuehrt den Plan bereits (' . $variant . ')', 0);
            return;
        }

        // ERSTBERUEHRUNG IM MODUS "bei Aenderung": es gab noch nie einen Merker,
        // also ist NICHT belegt, dass sich etwas geaendert hat - belegt ist nur,
        // dass beide Seiten auseinanderliegen. Das kann genauso gut heissen, dass
        // im Geraet der bessere Plan steht. Wer hier schreibt, entscheidet das
        // ungefragt fuer 23 Zonen auf einmal; genau das ist am 23.08. passiert.
        // Also: vermerken, sichtbar machen, und die Entscheidung dem Menschen
        // lassen (`syncToDevice` schreibt, `adoptDevice` uebernimmt).
        if ($modus === 1 && $vorher === '' && !$ansage) {
            $ab = is_array($rt['pushDiff'] ?? null) ? $rt['pushDiff'] : [];
            $ab[$variant] = time();
            $rt['pushDiff'] = $ab;
            $rt['pushNext'] = time() + self::PUSH_RUHE_SEK;
            $this->writeRt($rt);
            $this->ccuSlotSchliessen();
            $this->SendDebug('HSHT.push', 'Plan und Geraet gehen auseinander (' . $variant
                . ') - erste Beruehrung, kein Schreibversuch. syncToDevice oder adoptDevice entscheidet.', 0);
            return;
        }

        // Die Variante gehoert in IHR Profil: bis 23.08.2026 wurde ohne Index
        // geschrieben, und das ist bei einem TC-IT immer P1 - der Abgesenkt-Plan
        // waere im Normal-Profil gelandet.
        if ($drv->writeWeekProfile($week, $pi)) {
            $bekannt[$schluessel] = $hash;
            $rt['pushHashes']     = $bekannt;
            $rt['pushHash']       = $hash;
            unset($rt['pushNext'], $rt['pushAnsage']);
            if (is_array($rt['pushDiff'] ?? null)) {
                unset($rt['pushDiff'][$variant]);
            }
            $this->writeRt($rt);
            $this->SendDebug('HSHT.push', 'Wochenprogramm gepusht (' . $variant . ')', 0);
        } else {
            $rt['pushNext'] = time() + self::PUSH_RUHE_SEK;
            $this->writeRt($rt);
            $this->SendDebug('HSHT.push', 'Schreiben fehlgeschlagen (' . $variant . ') - Ruhe bis '
                . date('H:i:s', (int) $rt['pushNext']), 0);
        }
        $this->ccuSlotSchliessen();
    }

    /**
     * Waehlt am Geraet das Profil der Praesenz. Fehler werden gemeldet, nicht
     * geworfen - eine nicht erreichbare CCU darf die Bedienung nicht anhalten.
     */
    private function selectDeviceProfile(IThermostat $drv, int $presence): void
    {
        if (!method_exists($drv, 'selectProfile')) {
            return;
        }
        $ok = $drv->selectProfile($presence);
        $this->SendDebug('HSHT.profil', 'selectProfile(' . $presence . ') -> ' . ($ok ? 'ok' : 'FEHLER'), 0);
    }

    /**
     * Steht der Zeiger des Geraets auf der Praesenz, die das Modul fuehrt?
     *
     * Geprueft wird nur, wenn es etwas zu pruefen gibt (mehrere Geraeteprofile)
     * und hoechstens im Takt der CCU-Bremse - der Zeiger ist eine Kleinigkeit,
     * aber er wird ueber dieselbe Leitung gelesen wie ein ganzes Wochenprofil.
     */
    private function zeigerAbgleichen(IThermostat $drv): void
    {
        $caps = $drv->capabilities();
        if ((int) ($caps['deviceProfiles'] ?? 1) < 2 || !method_exists($drv, 'activeProfile')) {
            return;
        }
        $rt = $this->readRt();
        if ((int) ($rt['zeigerNext'] ?? 0) > time()) {
            return;
        }
        if (!$this->ccuSlotFrei()) {
            return;
        }
        $soll = $this->activeVariantIndex();
        $ist  = $drv->activeProfile();
        if ($ist !== null && $ist !== $soll) {
            $this->selectDeviceProfile($drv, $soll);
        }
        $rt['zeigerNext'] = time() + self::ZEIGER_PRUEFUNG_SEK;
        $this->writeRt($rt);
        $this->ccuSlotSchliessen();
    }

    /**
     * Vorflug fuer das Scharfstellen: fuehrt das Geraet den Plan der aktiven
     * Variante bereits? Liest das Geraeteprofil und vergleicht - ohne einen
     * einzigen Schreibzugriff. Mit seed=true wird bei Gleichheit der Merker
     * gesetzt, damit spaeteres Scharfstellen gar keinen Push mehr ausloest.
     *
     * @return array<string,mixed>
     */
    private function mgmtPruefePush(bool $seed, bool $detail = false): array
    {
        $drv = $this->driver();
        if (!$drv instanceof IThermostat) {
            return ['ok' => false, 'error' => 'kein Treiber'];
        }
        $caps = $drv->capabilities();
        if (($caps['scheduleMode'] ?? '') !== 'device') {
            return ['ok' => false, 'error' => 'nur im device-Modus'];
        }
        $pi      = $this->activeVariantIndex();
        $variant = $this->activeVariant();
        $week    = $this->schedules()->toHomematicWeek(
            $variant,
            (int) ($caps['rasterMinutes'] ?? 10),
            (int) ($caps['maxSlots'] ?? 13)
        );
        $hash    = md5($variant . '|' . json_encode($week));
        $rt      = $this->readRt();
        $bekannt = is_array($rt['pushHashes'] ?? null) ? $rt['pushHashes'] : [];
        // Denselben Schluessel wie pushWeekIfChanged verwenden, sonst meldet die
        // Diagnose bei Einzelprofil-Geraeten einen Merker, den der Abgleich gar
        // nicht liest - und der Seed legte ihn an die falsche Stelle.
        $schluessel = ((int) ($caps['deviceProfiles'] ?? 1) < 2) ? '*' : $variant;
        $leer    = $this->weekIsEmpty($week);
        $dev     = $leer ? [] : $drv->readWeekProfile($pi);
        $gleich  = ($dev !== [] && self::wochenGleich($dev, $week));
        if ($seed && $gleich) {
            $bekannt[$schluessel] = $hash;
            $rt['pushHashes']     = $bekannt;
            unset($rt['pushNext']);
            $this->writeRt($rt);
        }
        $antwort = [
            'ok' => true, 'variante' => $variant, 'praesenz' => $pi, 'planLeer' => $leer,
            'geraetGelesen' => $dev !== [], 'gleich' => $gleich,
            'geseedet' => ($seed && $gleich), 'merkerPasst' => (($bekannt[$schluessel] ?? '') === $hash),
            'schreibmodus' => $this->weekWriteMode(),
            // Worauf zeigt das GERAET gerade? Beim Scharfstellen zieht der Abgleich
            // den Zeiger auf die Praesenz des Moduls - das ist ein echter Eingriff
            // in den Heizbetrieb und gehoert in den Vorflug, nicht in die Ueberraschung.
            'zeigerGeraet' => (method_exists($drv, 'activeProfile') && (int) ($caps['deviceProfiles'] ?? 1) >= 2)
                ? $drv->activeProfile() : null,
            // Wann der Abgleich zuletzt eine Abweichung gesehen und BEWUSST nicht
            // geschrieben hat - sonst bliebe das Auseinanderlaufen unsichtbar.
            'abweichungSeit' => isset($rt['pushDiff'][$variant])
                ? date('d.m.Y H:i:s', (int) $rt['pushDiff'][$variant]) : null,
        ];
        if ($detail) {
            // Beide Seiten in Vergleichsform, Tag fuer Tag: nur so sieht man, WO sie
            // auseinandergehen, statt nur DASS sie es tun.
            $antwort['plan']   = self::wochenText($week);
            $antwort['geraet'] = self::wochenText($dev);
        }
        return $antwort;
    }

    /**
     * Darf DIESE Zone jetzt an die CCU? Der Zeitstempel liegt in einer Datei, weil
     * die Zonen eigenstaendige Instanzen sind und ein Attribut nur die eigene
     * kennt. Die Semaphore macht Lesen und Setzen unteilbar - sonst kaeme bei 23
     * gleichzeitigen Ticks doch wieder ein Schwarm durch.
     */
    private function ccuSlotFrei(): bool
    {
        $datei = \IPS_GetKernelDir() . self::PUSH_STEMPEL;
        if (!@\IPS_SemaphoreEnter('HS.CcuPush', 400)) {
            return false;
        }
        $frei = false;
        $letzt = (int) @file_get_contents($datei);
        if ((time() - $letzt) >= self::PUSH_ABSTAND_SEK) {
            @file_put_contents($datei, (string) time());
            $frei = true;
        }
        @\IPS_SemaphoreLeave('HS.CcuPush');
        return $frei;
    }

    /**
     * Den Zeitstempel NACH getaner Arbeit noch einmal setzen: der Abstand soll ab
     * dem ENDE des letzten Zugriffs zaehlen. Ein Profil zu schreiben und zu
     * verifizieren dauert laenger als der Abstand selbst.
     */
    private function ccuSlotSchliessen(): void
    {
        @file_put_contents(\IPS_GetKernelDir() . self::PUSH_STEMPEL, (string) time());
    }

    /**
     * Zwei Wochenprofile inhaltlich vergleichen (Endzeit und Wert je Slot, auf
     * Zehntelgrad). Ein reiner Hash taugt hier nicht: das Geraet liefert Werte als
     * Fliesskomma und fuellt bis zur Slotzahl auf.
     *
     * @param array<int,array<int,array{end:int,val:mixed}>> $a
     * @param array<int,array<int,array{end:int,val:mixed}>> $b
     */
    private static function wochenGleich(array $a, array $b): bool
    {
        return self::wochenText($a) === self::wochenText($b);
    }

    /** @param array<int,array<int,array{end:int,val:mixed}>> $w */
    private static function wochenText(array $w): array
    {
        // Verglichen wird der TAGESVERLAUF, nicht die Slot-Liste. Das Geraet fuehrt
        // dieselbe Kurve oft in mehr Stufen, als der Plan sie schreibt: zwei
        // benachbarte Slots mit demselben Wert (17:30 auf 18 Grad, 22:30 auf 18
        // Grad) sind derselbe Verlauf wie ein Slot bis 22:30. Ohne dieses
        // Zusammenfassen galten 14 von 23 Zonen als abweichend und haetten ihr
        // Geraeteprogramm neu geschrieben bekommen, um exakt dasselbe hineinzulegen.
        $norm = static function (array $w): array {
            $out = [];
            for ($d = 0; $d < 7; $d++) {
                $tag  = [];
                $letzt = null;
                foreach (($w[$d] ?? []) as $slot) {
                    $end = (int) ($slot['end'] ?? 0);
                    $val = number_format((float) ($slot['val'] ?? 0), 1, '.', '');
                    if ($end <= 0) {
                        continue;
                    }
                    if ($letzt !== null && $letzt === $val && $tag !== []) {
                        array_pop($tag);            // gleicher Wert -> Grenze faellt weg
                    }
                    $tag[]  = $end . ':' . $val;
                    $letzt  = $val;
                    if ($end >= 1440) {
                        break;                      // alles dahinter ist Auffuellung
                    }
                }
                $out[$d] = implode(',', $tag);
            }
            return $out;
        };
        return $norm($w);
    }

    private function weekIsEmpty(array $week): bool
    {
        foreach ($week as $slots) {
            if (is_array($slots) && $slots !== []) {
                return false;
            }
        }
        return true;
    }

    // ==================================================================
    // Manual-Override-Erkennung (Block D)
    // ==================================================================

    /** Registriert (idempotent) das Lauschen auf die GERAETE-Sollwertvariable. */
    private function registerSetpointWatch(?IThermostat $drv): void
    {
        $vid = ($drv instanceof IThermostat) ? $this->deviceSetpointVarId($drv) : 0;
        $rt  = $this->readRt();
        $old = (int) ($rt['watchVid'] ?? 0);

        if ($old > 0 && $old !== $vid) {
            @$this->UnregisterMessage($old, VM_UPDATE);
        }
        // RegisterMessage IMMER (idempotent): nach Reload/Restart sind
        // Registrierungen weg, waehrend rt.watchVid persistiert -> nicht dedupen.
        if ($vid > 0) {
            $this->RegisterMessage($vid, VM_UPDATE);
        }
        $rt['watchVid'] = $vid;
        $this->writeRt($rt);
    }

    /**
     * Native Nachrichtensenke. Faengt zusaetzlich VM_UPDATE der GERAETE-Sollwert-
     * variable ab: aendert sich der Wert, OHNE dass das Modul ihn geschrieben hat
     * -> manueller Override -> manualHold bis zur naechsten Slot-Grenze.
     */
    public function MessageSink($Timestamp, $Sender, $Message, $Data)
    {
        parent::MessageSink($Timestamp, $Sender, $Message, $Data);

        if ($Message !== VM_UPDATE) {
            return;
        }
        $rt = $this->readRt();
        if ((int) ($rt['watchVid'] ?? 0) !== (int) $Sender) {
            return;
        }
        $newVal = isset($Data[0]) && is_numeric($Data[0]) ? (float) $Data[0] : null;
        if ($newVal === null) {
            return;
        }
        // Self-Write-Unterdrueckung: kurz nach eigenem setSetpoint denselben Wert
        // ignorieren (das war das Modul, kein Mensch).
        $selfTs  = (int) ($rt['selfWriteTs'] ?? 0);
        $selfVal = isset($rt['selfWriteVal']) ? (float) $rt['selfWriteVal'] : null;
        if ($selfVal !== null && abs($selfVal - $newVal) < 0.01 && (time() - $selfTs) <= 10) {
            return;
        }
        // Externer Eingriff -> Override bis zur naechsten Slot-Grenze halten.
        $this->manualHold('Setpoint', $this->secondsToNextBoundary($this->activeVariant()));
        $this->SendDebug('HSHT.override', 'Externer Sollwert ' . $newVal . ' erkannt -> Hold bis Slot-Grenze', 0);
    }

    /** Sekunden bis zur naechsten Slot-Grenze der aktiven Variante (Default 1 h). */
    private function secondsToNextSlotBoundary(): int
    {
        $variant = $this->activeVariant();
        $now     = time();
        $day     = (int) date('N', $now) - 1;            // 0..6
        $minNow  = ((int) date('G', $now)) * 60 + (int) date('i', $now);

        $slots = $this->schedules()->getSlots($variant, $day);
        foreach ($slots as $slot) {
            $end = (int) $slot['end'];
            if ($end > $minNow) {
                return max(60, ($end - $minNow) * 60);
            }
        }
        // Keine spaetere Grenze heute -> bis Mitternacht.
        return max(60, (1440 - $minNow) * 60);
    }

    // ==================================================================
    // Laufzeit-Status (volatil) & kleine Helfer
    // ==================================================================

    private function intVal(string $ident): int
    {
        if ($this->GetIDForIdent($ident) === false) {
            return 0;
        }
        $v = @$this->GetValue($ident);
        return is_numeric($v) ? (int) $v : 0;
    }

    /** Objekt-ID der geraeteseitigen Sollwertvariable (fuer den Watch). */
    private function deviceSetpointVarId(IThermostat $drv): int
    {
        // Generisch: config.targetId ist bei generic die Sollwert-Variable,
        // bei hm-* die Instanz -> dort die SET_TEMPERATURE-Variable aufloesen.
        $cfg    = $this->cfg();
        $target = (int) $cfg['targetId'];
        $driver = (string) $cfg['driver'];
        if ($target <= 0) {
            return 0;
        }
        if (strncmp($driver, 'hm-', 3) === 0) {
            $sp = @\IPS_GetObjectIDByIdent('SET_TEMPERATURE', $target);
            if (!is_int($sp) || $sp <= 0) {
                $sp = @\IPS_GetObjectIDByIdent('SETPOINT', $target); // HM-CC-TC
            }
            return (is_int($sp) && $sp > 0) ? $sp : 0;
        }
        return $target; // generic: targetId ist die Sollwert-Variable
    }

    // ==================================================================
    // Verwaltung (M1.2: Treiber konfigurieren)
    // ==================================================================

    protected function mgmt(string $op, array $args, array $ctx): array
    {
        switch ($op) {
            case 'configureDriver':
                return $this->mgmtConfigureDriver($args, $ctx);
            case 'configureAutomation':
                return $this->mgmtConfigureAutomation($args, $ctx);
            case 'updateProfile':
                return $this->mgmtUpdateProfile($args, $ctx);
            case 'getSchedule':
                return $this->mgmtGetSchedule($args);
            case 'setActivePresence':
                return $this->mgmtSetActivePresence($args);
            case 'importLegacy':
                return $this->ImportLegacy($args);
            case 'adoptDevice':          // Alias der generischen Basis-Op
                return $this->opLoadFromDevice();
            case 'pruefePush':           // liest das Geraet und vergleicht - schreibt NICHTS ans Geraet
                return $this->mgmtPruefePush(!empty($args['seed']), !empty($args['detail']));
            case 'getConfig':
                return ['ok' => true, 'config' => $this->cfg()];
            case 'migrateConfig':
                return $this->migrateConfig();
            case 'syncToDevice':
                // Der ausdrueckliche Befehl umgeht Takt und Merker - aber NICHT den
                // Schattenmodus. Eine Zone, die laut Konfiguration nichts schreiben
                // darf, schreibt auch auf Knopfdruck nichts, solange niemand das
                // ausdruecklich erzwingt.
                if (!$this->armed() && empty($args['force'])) {
                    return ['ok' => false, 'error' => 'Zone ist im Schattenmodus - mit force:true erzwingen'];
                }
                return $this->opSyncToDevice();
            case 'setWeekWrite':   // 0 nur auf Befehl | 1 bei Aenderung | 2 laufend
                $m = (int) ($args['mode'] ?? 1);
                if ($m < 0 || $m > 2) {
                    return ['ok' => false, 'error' => 'mode 0..2 erwartet'];
                }
                @\IPS_SetProperty($this->InstanceID, 'WeekWrite', $m);
                @\IPS_ApplyChanges($this->InstanceID);
                return ['ok' => true, 'weekWrite' => $this->weekWriteMode()];
            case 'setArmed':
                @\IPS_SetProperty($this->InstanceID, 'Armed', (bool) ($args['armed'] ?? false));
                @\IPS_ApplyChanges($this->InstanceID);
                return ['ok' => true, 'armed' => $this->armed()];
            default:
                // syncStatus/loadFromDevice/syncToDevice u.a. behandelt die Basis generisch.
                return parent::mgmt($op, $args, $ctx);
        }
    }

    // Zeitplan-Varianten fuer den generischen Geraete-Sync (Basis): HM-Praesenzen.
    protected function scheduleVariants(): array
    {
        return self::PRESENCE_VARIANTS;
    }

    protected function activeVariantIndex(): int
    {
        $p = $this->intVal('Presence');
        return ($p >= 0 && $p < count(self::PRESENCE_VARIANTS)) ? $p : 0;
    }

    /**
     * Migrations-Import (Strangler-Fig, Block F): liest das serialisierte
     * HomeMatic-Wochenprofil der Altsteuerung (Variable HMWochenprofilDaten) und
     * schreibt es in die HeatingZone-Zeitplaene. Idempotent, KEIN Geraeteschreiben,
     * KEIN Legacy-Abschalten (das ist der separate, explizite Cutover).
     *
     * @param array<string,mixed> $spec { legacyVarId:int } oder { data: serialized|array }
     * @return array<string,mixed>
     */
    public function ImportLegacy(array $spec): array
    {
        // Quelle bestimmen: Variable oder direkt uebergebene Daten.
        $data = null;
        if (isset($spec['legacyVarId'])) {
            $vid = (int) $spec['legacyVarId'];
            if ($vid <= 0 || !function_exists('IPS_VariableExists') || !\IPS_VariableExists($vid)) {
                return ['ok' => false, 'error' => 'legacyVarId ist keine Variable'];
            }
            $raw  = @\GetValue($vid);
            $data = is_string($raw) ? @unserialize($raw) : null;
        } elseif (isset($spec['data'])) {
            $data = is_string($spec['data']) ? @unserialize($spec['data']) : $spec['data'];
        }
        if (!is_array($data)) {
            return ['ok' => false, 'error' => 'Legacy-Daten nicht lesbar/serialisiert'];
        }

        // Praesenz-Aliase der Altsteuerung -> HeatingZone-Varianten.
        $aliases = [
            'Normal'    => ['Normal', 'Normaler Betrieb'],
            'Erweitert' => ['Erweitert', 'Erweiterter Betrieb'],
            'Abgesenkt' => ['Abgesenkt', 'Abwesenheitsmodus', 'Abwesend'],
        ];
        $days = ['MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY', 'FRIDAY', 'SATURDAY', 'SUNDAY'];

        $dryrun   = !empty($spec['dryrun']);
        $imported = [];
        $eng      = $this->schedules();

        foreach ($aliases as $variant => $keys) {
            $src = null;
            foreach ($keys as $k) {
                if (isset($data[$k]) && is_array($data[$k])) {
                    $src = $data[$k];
                    break;
                }
            }
            if ($src === null) {
                continue; // diese Praesenz fehlt in der Altquelle
            }
            $dayCount = 0;
            foreach ($days as $di => $dname) {
                if (!isset($src[$dname]['EndTimes']) || !is_array($src[$dname]['EndTimes'])) {
                    continue;
                }
                $ends = $src[$dname]['EndTimes'];
                $vals = $src[$dname]['Values'] ?? [];
                $slots = [];
                foreach ($ends as $i => $hhmm) {
                    $slots[] = ['end' => $this->hhmmToMin((string) $hhmm), 'val' => (float) ($vals[$i] ?? 0)];
                }
                if ($slots === []) {
                    continue;
                }
                if (!$dryrun) {
                    $eng->setSlots($variant, $di, $slots);
                }
                $dayCount++;
            }
            $imported[$variant] = $dayCount;
        }

        if (!$dryrun && !empty($imported)) {
            // Neuer Plan -> Reconciler anstossen (device: Push, controller: Nachfahren).
            $rt = $this->readRt();
            unset($rt['pushHash']);
            $this->writeRt($rt);
            $drv = $this->driver();
            if ($drv instanceof IThermostat) {
                $this->reconcile($drv);
            }
        }

        return ['ok' => true, 'dryrun' => $dryrun, 'imported' => $imported];
    }

    /**
     * Adoptiert das AKTUELLE Geraeteprogramm (device-Modus) in die HeatingZone-
     * Zeitplaene — die Migrations-Wahrheit ist das GERAET (G1), nicht die evtl.
     * veraltete Legacy-Variable. SCHREIBT NICHT ins Geraet; seedet den pushHash,
     * damit der Reconciler das (identische) Programm nicht zurueckschreibt.
     *
     * @return array<string,mixed>
     */
    /** "HH:MM" -> Minuten seit Mitternacht (24:00 -> 1440). */
    private function hhmmToMin(string $hhmm): int
    {
        $p = explode(':', trim($hhmm));
        if (count($p) !== 2) {
            return 0;
        }
        return ((int) $p[0]) * 60 + (int) $p[1];
    }

    /**
     * Schreibt die Slots eines Tages einer Praesenz-Variante (Wochenplan-Editor).
     * args: { variant|presence, day(0..6), slots:[{end:int,val:float}, ...] }.
     * Nach dem Speichern wird der Reconciler angestossen (device: Push; controller:
     * naechster Tick faehrt den Wert).
     */
    private function mgmtUpdateProfile(array $args, array $ctx): array
    {
        $variant = $this->normalizeVariant($args['variant'] ?? ($args['presence'] ?? 'Normal'));
        $day     = (int) ($args['day'] ?? -1);
        if ($day < 0 || $day > 6) {
            throw new ContractException('day muss 0..6 sein');
        }
        $slots = (isset($args['slots']) && is_array($args['slots'])) ? $args['slots'] : [];
        // Slots haerten: end 1..1440, val auf Bereich klemmen.
        $clean = [];
        foreach ($slots as $s) {
            if (!is_array($s) || !isset($s['end'])) {
                continue;
            }
            $end = (int) $s['end'];
            $val = (float) ($s['val'] ?? 0);
            $val = max(self::SETPOINT_MIN, min(self::SETPOINT_MAX, $val));
            $entry = ['end' => $end, 'val' => $val];
            if (isset($s['anchor']) && \Hoep\HomeSuite\SunTimes::isAnchor((string) $s['anchor'])) {
                $entry['anchor'] = (string) $s['anchor'];
                $entry['offset'] = (int) ($s['offset'] ?? 0);
            }
            $clean[] = $entry;
        }

        if (!empty($ctx['dryrun'])) {
            return ['ok' => true, 'dryrun' => true, 'variant' => $variant, 'day' => $day, 'slots' => $clean];
        }

        $this->schedules()->setSlots($variant, $day, $clean);

        // Reconciler anstossen (Push bei device / Nachfahren bei controller).
        $drv = $this->driver();
        if ($drv instanceof IThermostat) {
            // Push-Hash invalidieren, damit device den neuen Plan sicher uebernimmt.
            $rt = $this->readRt();
            unset($rt['pushHash']);
            $this->writeRt($rt);
            $this->reconcile($drv);
        }

        return ['ok' => true, 'variant' => $variant, 'day' => $day, 'slots' => $this->schedules()->getSlots($variant, $day)];
    }

    /**
     * Liefert den kompletten Wochenplan einer Variante (fuer Editor/Verify).
     * args: { variant|presence }.
     */
    private function mgmtGetSchedule(array $args): array
    {
        $variant = $this->normalizeVariant($args['variant'] ?? ($args['presence'] ?? 'Normal'));
        $week = [];
        for ($d = 0; $d < 7; $d++) {
            $week[$d] = $this->schedules()->getSlots($variant, $d);
        }
        return ['ok' => true, 'variant' => $variant, 'week' => $week, 'activeVariant' => $this->activeVariant(),
            'sunEvents' => $this->sunEvents(time()), 'anchors' => array_keys(\Hoep\HomeSuite\SunTimes::ANCHORS)];
    }

    /** Setzt die aktive Praesenz (0..2) ueber die native RequestAction. */
    private function mgmtSetActivePresence(array $args): array
    {
        $p = (int) ($args['presence'] ?? $args['value'] ?? -1);
        if ($p < 0 || $p > 2) {
            throw new ContractException('presence muss 0..2 sein');
        }
        $this->RequestAction('Presence', $p);
        return ['ok' => true, 'presence' => $p, 'variant' => $this->activeVariant()];
    }

    /** Variantennamen auf die erlaubten Presence-Varianten normalisieren. */
    private function normalizeVariant($v): string
    {
        if (is_numeric($v)) {
            return self::PRESENCE_VARIANTS[(int) $v] ?? self::PRESENCE_VARIANTS[0];
        }
        $v = (string) $v;
        return in_array($v, self::PRESENCE_VARIANTS, true) ? $v : self::PRESENCE_VARIANTS[0];
    }

    /**
     * Speichert die Treiber-Konfiguration (LVB-Schreibpfad). Validiert Treiber-Id
     * und dass zugeordnete IDs echte Variablen sind. dryrun gibt die geplante
     * Konfig ohne Schreiben zurueck.
     *
     * @param array<string,mixed> $args {driver, targetId?, sensorId?}
     * @param array<string,mixed> $ctx
     * @return array<string,mixed>
     */
    private function mgmtConfigureDriver(array $args, array $ctx): array
    {
        $driver  = (string) ($args['driver'] ?? '');
        $allowed = ['', 'generic-thermostat', 'hm-HM-TC-IT-WM-W-EU', 'hm-HM-CC-RT-DN', 'hm-HM-CC-TC'];
        if (!in_array($driver, $allowed, true)) {
            throw new ContractException('unbekannter Treiber: ' . $driver);
        }

        $targetId = (int) ($args['targetId'] ?? 0);
        $sensorId = (int) ($args['sensorId'] ?? 0);
        $isHm     = strncmp($driver, 'hm-', 3) === 0;

        // targetId-Semantik je Treiber: generic = Sollwert-VARIABLE, hm-* = CCU-INSTANZ.
        if ($targetId > 0) {
            if ($isHm) {
                if (function_exists('IPS_InstanceExists') && !\IPS_InstanceExists($targetId)) {
                    throw new ContractException('targetId #' . $targetId . ' ist keine Instanz (HM-Geraet erwartet)');
                }
                // Plausibilitaet: hat die Instanz einen Sollwert-Kanal
                // (SET_TEMPERATURE bei RT-DN/TC-IT, SETPOINT bei CC-TC)?
                if (function_exists('IPS_GetObjectIDByIdent')
                    && @\IPS_GetObjectIDByIdent('SET_TEMPERATURE', $targetId) === false
                    && @\IPS_GetObjectIDByIdent('SETPOINT', $targetId) === false) {
                    throw new ContractException('Instanz #' . $targetId . ' hat keinen Sollwert-Kanal (SET_TEMPERATURE/SETPOINT)');
                }
            } elseif (function_exists('IPS_VariableExists') && !\IPS_VariableExists($targetId)) {
                throw new ContractException('targetId #' . $targetId . ' ist keine Variable');
            }
        }
        // sensorId: generic = Ist-VARIABLE; hm-* = Sensor-/Wandgeraet-INSTANZ (optional).
        if ($sensorId > 0) {
            if ($isHm) {
                if (function_exists('IPS_InstanceExists') && !\IPS_InstanceExists($sensorId)
                    && (!function_exists('IPS_VariableExists') || !\IPS_VariableExists($sensorId))) {
                    throw new ContractException('sensorId #' . $sensorId . ' ist keine Instanz/Variable');
                }
            } elseif (function_exists('IPS_VariableExists') && !\IPS_VariableExists($sensorId)) {
                throw new ContractException('sensorId #' . $sensorId . ' ist keine Variable');
            }
        }
        if ($targetId <= 0 && $driver !== '') {
            throw new ContractException($driver . ' braucht ein Ziel (targetId): generic=Variable, hm-*=CCU-Instanz');
        }

        $config = ['driver' => $driver, 'targetId' => $targetId, 'sensorId' => $sensorId];

        if (!empty($ctx['dryrun'])) {
            return ['ok' => true, 'dryrun' => true, 'config' => $config, 'scheduleMode' => $this->scheduleModeOf($driver)];
        }

        // Native Properties schreiben (ApplyChanges zieht Treiber/Links/Timer neu).
        $this->applyConfigProperties($config, true);
        $active = $this->driver() instanceof IThermostat;
        return ['ok' => true, 'config' => $this->cfg(), 'scheduleMode' => $this->scheduleModeOf($driver), 'driverActive' => $active];
    }

    /**
     * Automatik-Parameter setzen. Aktuell: config.frostTemp (Frostschutz-Solltemperatur).
     * Wert plausibel geklemmt (3..15 °C). desiredSetpoint() nutzt frostTemp im Frost-Modus.
     *
     * @param array<string,mixed> $args {frostTemp}
     * @param array<string,mixed> $ctx
     * @return array<string,mixed>
     */
    private function mgmtConfigureAutomation(array $args, array $ctx): array
    {
        if (!array_key_exists('frostTemp', $args) || !is_numeric($args['frostTemp'])) {
            throw new ContractException('frostTemp (Zahl) erwartet');
        }
        $frost = (float) $args['frostTemp'];
        $frost = max(3.0, min(15.0, $frost)); // plausibel klemmen

        if (!empty($ctx['dryrun'])) {
            return ['ok' => true, 'dryrun' => true, 'frostTemp' => $frost];
        }

        @\IPS_SetProperty($this->InstanceID, 'FrostTemp', $frost);
        @\IPS_ApplyChanges($this->InstanceID);

        // Wenn gerade Frost-Modus aktiv ist, sofort neu nachfahren.
        $drv = $this->driver();
        if ($drv instanceof IThermostat) {
            $this->reconcile($drv);
        }

        return ['ok' => true, 'frostTemp' => $frost];
    }
}

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

    /** Volatiler Laufzeit-Status (NICHT im Konfig-Store, Blocker D). */
    private const ATTR_RT = 'RtState';

    /** Frostschutz-Sollwert (°C), wenn nicht konfiguriert. */
    private const FROST_DEFAULT = 8.0;

    /** Periodisches Re-Assert des Sollwerts spaetestens alle N Sekunden (Failsafe). */
    private const REASSERT_SECONDS = 300;

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
                ],
                [
                    'ident' => 'Presence', 'type' => ControlContract::T_SELECT,
                    'role' => 'heating:presence', 'label' => 'Praesenz',
                    'varType' => 1, 'actionable' => true,
                    'options' => [
                        ['value' => 0, 'label' => 'Normal'],
                        ['value' => 1, 'label' => 'Erweitert'],
                        ['value' => 2, 'label' => 'Abgesenkt'],
                    ],
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
                ['op' => 'updateProfile',     'label' => 'Wochenprofil bearbeiten'],
                ['op' => 'getSchedule',       'label' => 'Wochenplan lesen'],
                ['op' => 'duplicateProfile',  'label' => 'Profil duplizieren'],
                ['op' => 'assignProfile',     'label' => 'Profil zuweisen'],
                ['op' => 'setActivePresence', 'label' => 'Praesenz setzen'],
                ['op' => 'importLegacy',      'label' => 'Aus Altsteuerung importieren'],
                ['op' => 'adoptDevice',       'label' => 'Geraeteprogramm uebernehmen'],
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
        $cfg = $this->store()->get('config', []);
        return is_array($cfg) ? (string) ($cfg['driver'] ?? '') : '';
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

        $cfg      = $this->store()->get('config', []);
        $cfg      = is_array($cfg) ? $cfg : [];
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
                $this->driverInstance = DriverFactory::create($driverId, [
                    'deviceInstanceId' => $targetId,
                    'min'              => self::SETPOINT_MIN,
                    'max'              => self::SETPOINT_MAX,
                    'step'             => 0.5,
                ]);
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

    public function Create()
    {
        parent::Create();
        // Volatiler Laufzeit-Status (last-commanded/last-push/hold-Herkunft).
        $this->RegisterAttributeString(self::ATTR_RT, '{}');
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
        $this->SetTimerInterval(self::TIMER_REFRESH, $active ? self::REFRESH_MS : 0);

        // Manual-Override-Erkennung: auf Aenderungen der GERAETE-Sollwertvariable
        // lauschen (jemand verstellt am Geraet/HM-Oberflaeche).
        $this->registerSetpointWatch($drv);
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
        $caps = $drv->capabilities();

        if (($caps['scheduleMode'] ?? 'controller') === 'device') {
            // Geraet fuehrt den Plan; Modul sorgt nur fuer den aktuellen Push.
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
        if ($mode === 3) {                            // Frostschutz
            $v = $this->store()->get('config.frostTemp', self::FROST_DEFAULT);
            return is_numeric($v) ? (float) $v : self::FROST_DEFAULT;
        }
        // Auto (0) und Boost (2, vorerst wie Auto) -> aus dem Wochenplan.
        $variant = $this->activeVariant();
        $v = $this->schedules()->eval(time(), $variant);
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
    private function pushWeekIfChanged(IThermostat $drv): void
    {
        $variant = $this->activeVariant();
        $week    = $this->schedules()->toHomematicWeek($variant, 10, 13);
        if ($this->weekIsEmpty($week)) {
            return; // kein Plan hinterlegt -> Geraeteprogramm nicht anfassen
        }
        $hash = md5($variant . '|' . json_encode($week));
        $rt   = $this->readRt();
        if (($rt['pushHash'] ?? '') === $hash) {
            return; // schon aktuell
        }
        if ($drv->writeWeekProfile($week)) {
            $rt['pushHash'] = $hash;
            $this->writeRt($rt);
            $this->SendDebug('HSHT.push', 'Wochenprogramm gepusht (' . $variant . ')', 0);
        }
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
        $this->manualHold('Setpoint', $this->secondsToNextSlotBoundary());
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

    private function readRt(): array
    {
        try {
            $raw = (string) $this->ReadAttributeString(self::ATTR_RT);
        } catch (\Throwable $e) {
            return [];
        }
        $d = json_decode($raw, true);
        return is_array($d) ? $d : [];
    }

    private function writeRt(array $rt): void
    {
        $j = json_encode($rt);
        $this->WriteAttributeString(self::ATTR_RT, $j === false ? '{}' : $j);
    }

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
        $cfg    = $this->store()->get('config', []);
        $cfg    = is_array($cfg) ? $cfg : [];
        $target = (int) ($cfg['targetId'] ?? 0);
        $driver = (string) ($cfg['driver'] ?? '');
        if ($target <= 0) {
            return 0;
        }
        if (strncmp($driver, 'hm-', 3) === 0) {
            $sp = @\IPS_GetObjectIDByIdent('SET_TEMPERATURE', $target);
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
            case 'updateProfile':
                return $this->mgmtUpdateProfile($args, $ctx);
            case 'getSchedule':
                return $this->mgmtGetSchedule($args);
            case 'setActivePresence':
                return $this->mgmtSetActivePresence($args);
            case 'importLegacy':
                return $this->ImportLegacy($args);
            case 'adoptDevice':
                return $this->mgmtAdoptDevice();
            default:
                return ['ok' => false, 'op' => $op, 'error' => 'not_implemented'];
        }
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
    private function mgmtAdoptDevice(): array
    {
        $drv = $this->driver();
        if (!$drv instanceof IThermostat) {
            return ['ok' => false, 'error' => 'kein Treiber konfiguriert'];
        }
        $caps = $drv->capabilities();
        if (($caps['scheduleMode'] ?? '') !== 'device') {
            return ['ok' => false, 'error' => 'adoptDevice nur im device-Modus sinnvoll'];
        }

        $eng     = $this->schedules();
        $adopted = [];
        foreach (self::PRESENCE_VARIANTS as $pi => $variant) {
            $week = $drv->readWeekProfile($pi);
            $days = 0;
            foreach ($week as $di => $slots) {
                if (is_array($slots) && $slots !== []) {
                    $eng->setSlots($variant, $di, $slots);
                    $days++;
                }
            }
            $adopted[$variant] = $days;
        }

        // pushHash der aktiven Variante seeden -> kein Ruecklschreiben ins Geraet.
        $variant = $this->activeVariant();
        $rt = $this->readRt();
        $rt['pushHash'] = md5($variant . '|' . json_encode($eng->toHomematicWeek($variant, 10, 13)));
        $this->writeRt($rt);

        return ['ok' => true, 'adopted' => $adopted, 'source' => 'device'];
    }

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
            $clean[] = ['end' => $end, 'val' => $val];
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
        return ['ok' => true, 'variant' => $variant, 'week' => $week, 'activeVariant' => $this->activeVariant()];
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
                // Plausibilitaet: hat die Instanz einen SET_TEMPERATURE-Kanal?
                if (function_exists('IPS_GetObjectIDByIdent')
                    && @\IPS_GetObjectIDByIdent('SET_TEMPERATURE', $targetId) === false) {
                    throw new ContractException('Instanz #' . $targetId . ' hat keinen SET_TEMPERATURE-Kanal');
                }
            } elseif (function_exists('IPS_VariableExists') && !\IPS_VariableExists($targetId)) {
                throw new ContractException('targetId #' . $targetId . ' ist keine Variable');
            }
        }
        if ($sensorId > 0 && function_exists('IPS_VariableExists') && !\IPS_VariableExists($sensorId)) {
            throw new ContractException('sensorId #' . $sensorId . ' ist keine Variable');
        }
        if ($targetId <= 0 && $driver !== '') {
            throw new ContractException($driver . ' braucht ein Ziel (targetId): generic=Variable, hm-*=CCU-Instanz');
        }

        $config = ['driver' => $driver, 'targetId' => $targetId, 'sensorId' => $sensorId];

        if (!empty($ctx['dryrun'])) {
            return ['ok' => true, 'dryrun' => true, 'config' => $config, 'scheduleMode' => $this->scheduleModeOf($driver)];
        }

        $this->store()->patch('config', $config);

        // Treiber + Timer neu scharf ziehen und einmal sofort reflektieren.
        $this->driverResolved = false;
        $this->driverInstance = null;
        $active = $this->driver() instanceof IThermostat;
        $this->SetTimerInterval(self::TIMER_REFRESH, $active ? self::REFRESH_MS : 0);
        if ($active) {
            $this->Refresh();
        }

        return ['ok' => true, 'config' => $config, 'scheduleMode' => $this->scheduleModeOf($driver), 'driverActive' => $active];
    }
}

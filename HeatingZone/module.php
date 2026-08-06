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

    /** Refresh-Intervall (ms) fuer das Nachziehen der Reflect-Controls. */
    private const REFRESH_MS = 30000;

    /** Timer-Ident (RegisterTimer/SetTimerInterval). */
    private const TIMER_REFRESH = 'Refresh';

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
                ['op' => 'duplicateProfile',  'label' => 'Profil duplizieren'],
                ['op' => 'assignProfile',     'label' => 'Profil zuweisen'],
                ['op' => 'setActivePresence', 'label' => 'Praesenz setzen'],
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
    }

    // ==================================================================
    // HAL-Treiber (M1.2: generischer Variablen-Thermostat)
    // ==================================================================

    /**
     * Baut den HAL-Treiber aus der gespeicherten Konfiguration. Aktuell ist nur
     * der vendor-freie 'generic-thermostat' verdrahtet: er bindet die im LVB
     * zugeordneten Standard-Symcon-Variablen (Sollwert/Ist/Feuchte). Fuer hm-*
     * liefert driver() bewusst noch null (Adapter folgt separat, aktor-vorsichtig).
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

        if ($driverId !== 'generic-thermostat') {
            return null; // hm-* / unkonfiguriert: in M1.2 (noch) kein Treiber
        }

        $targetId = (int) ($cfg['targetId'] ?? 0);
        if ($targetId <= 0) {
            return null; // ohne Sollwert-Variable kein sinnvoller Treiber
        }
        $sensorId = (int) ($cfg['sensorId'] ?? 0);

        $driverCfg = [
            'setpointVarId' => $targetId,
            'min'           => self::SETPOINT_MIN,
            'max'           => self::SETPOINT_MAX,
            'step'          => 0.5,
        ];
        if ($sensorId > 0) {
            $driverCfg['actualVarId'] = $sensorId;
        }

        try {
            $this->driverInstance = DriverFactory::create('generic-thermostat', $driverCfg);
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

        $active = $this->driver() instanceof IThermostat;
        $this->SetTimerInterval(self::TIMER_REFRESH, $active ? self::REFRESH_MS : 0);
    }

    /**
     * Timer-Callback (prefix: HSHT_Refresh). Zieht die Reflect-Controls nach.
     * Public erzwungen durch SDK; harmlos (nur Lesen + Statusvariablen-Reflect).
     */
    public function Refresh(): void
    {
        $drv = $this->driver();
        if ($drv instanceof IThermostat) {
            $this->reflectFromDriver($drv);
        }
    }

    // ==================================================================
    // Verwaltung (M1.2: Treiber konfigurieren)
    // ==================================================================

    protected function mgmt(string $op, array $args, array $ctx): array
    {
        switch ($op) {
            case 'configureDriver':
                return $this->mgmtConfigureDriver($args, $ctx);
            default:
                return ['ok' => false, 'op' => $op, 'error' => 'not_implemented'];
        }
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

        foreach (['targetId' => $targetId, 'sensorId' => $sensorId] as $key => $vid) {
            if ($vid > 0 && function_exists('IPS_VariableExists') && !\IPS_VariableExists($vid)) {
                throw new ContractException($key . ' #' . $vid . ' ist keine Variable');
            }
        }
        if ($driver === 'generic-thermostat' && $targetId <= 0) {
            throw new ContractException('generischer Treiber braucht eine Sollwert-Variable (targetId)');
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

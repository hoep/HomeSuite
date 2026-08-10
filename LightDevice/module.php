<?php

declare(strict_types=1);

/**
 * LightDevice (HSLT) — Domaenen-Modul "Licht" (Ablösung von IPSLight).
 *
 * Eine Instanz = eine Lampe / ein Lichtkreis. Erbt {@see \Hoep\HomeSuite\EntityModule}
 * (Manifest -> Variablen, native RequestAction, RPC-Trio). Gebunden werden reale
 * Symcon-Variablen ODER Skripte über den generischen ILight-Treiber `generic-light`
 * (Nutzer-Vorgabe: universell, immer Variable ODER Skript). Fähigkeiten (switch/dim/
 * color/cct) ergeben sich daraus, welche Kanäle gebunden sind — genau wie die
 * bestehenden LVB-light-Kacheln (Schalter + optional Helligkeit), nur erweitert.
 *
 * SCHATTEN-MODUS: config.armed=false -> Bedienung/Refresh rechnen und spiegeln nur,
 * es wird NICHTS real geschaltet. Erst armed=true schaltet reale Aktoren (Cutover L12).
 *
 * L1-Stand: Bindung, Manifest/Controls, Health/Validate, Refresh-Spiegel, Test-Ops.
 * Gruppen/Szenen/Automatik (SceneEngine, Trigger, Circadian, Bewegung) folgen L3+.
 *
 * Klassenname == module.json "name" == GUID {B7E1C3A4-5D62-4F08-9A1E-2C7D6B4F0E93} (Prefix HSLT).
 */

require_once __DIR__ . '/../libs/HomeSuite/autoload.php';

use Hoep\HomeSuite\ActionContext;
use Hoep\HomeSuite\Control;
use Hoep\HomeSuite\ControlContract;
use Hoep\HomeSuite\ContractException;
use Hoep\HomeSuite\EntityModule;
use Hoep\HomeSuite\HAL\DriverFactory;
use Hoep\HomeSuite\HAL\IDriver;
use Hoep\HomeSuite\HAL\ILight;

class LightDevice extends EntityModule
{
    private const CCT_MIN = 2700;
    private const CCT_MAX = 6500;

    private const TIMER_REFRESH = 'Refresh';
    private const REFRESH_MS     = 30000;

    /** Lazy-Cache des HAL-Treibers. */
    private ?IDriver $driverInstance = null;
    private bool $driverResolved = false;

    // ==================================================================
    // Manifest (Vertrag 2)
    // ==================================================================

    protected function manifest(): array
    {
        return [
            'domain' => 'light',
            'title'  => 'Licht',
            'icon'   => 'Bulb',

            'controls' => [
                ['ident' => 'Power', 'type' => ControlContract::T_SWITCH, 'role' => 'light:power',
                 'label' => 'Licht', 'varType' => 0, 'profile' => '~Switch', 'actionable' => true],
                ['ident' => 'Brightness', 'type' => ControlContract::T_LEVEL, 'role' => 'light:brightness',
                 'label' => 'Helligkeit', 'varType' => 1, 'unit' => '%',
                 'min' => 0, 'max' => 100, 'step' => 1, 'actionable' => true],
                ['ident' => 'ColorTemp', 'type' => ControlContract::T_SETPOINT, 'role' => 'light:cct',
                 'label' => 'Farbtemperatur', 'varType' => 1, 'unit' => 'K',
                 'min' => self::CCT_MIN, 'max' => self::CCT_MAX, 'step' => 100, 'actionable' => true],
                ['ident' => 'Color', 'type' => ControlContract::T_REFLECT, 'role' => 'light:color',
                 'label' => 'Farbe', 'varType' => 1, 'actionable' => false],
                ['ident' => 'Watt', 'type' => ControlContract::T_REFLECT, 'role' => 'light:watt',
                 'label' => 'Leistung', 'varType' => 2, 'unit' => 'W', 'actionable' => false],
                ['ident' => 'Online', 'type' => ControlContract::T_REFLECT, 'role' => 'light:online',
                 'label' => 'Online', 'varType' => 0, 'profile' => '~Switch', 'actionable' => false],
            ],

            'managementActions' => [
                ['op' => 'createEntity',    'label' => 'Licht anlegen'],
                ['op' => 'renameEntity',    'label' => 'Umbenennen'],
                ['op' => 'deleteEntity',    'label' => 'Loeschen'],
                ['op' => 'configureDriver', 'label' => 'Aktor/Treiber konfigurieren'],
                ['op' => 'getConfig',       'label' => 'Konfiguration lesen (Diagnose)'],
                ['op' => 'validate',        'label' => 'Bindung pruefen (Diagnose)'],
                ['op' => 'driverProbe',     'label' => 'Treiber-Status (Diagnose)'],
                ['op' => 'readState',       'label' => 'Ist-Zustand lesen (Diagnose)'],
                ['op' => 'setPower',        'label' => 'Testschalten An/Aus'],
                ['op' => 'setLevel',        'label' => 'Test-Helligkeit'],
                ['op' => 'setColor',        'label' => 'Test-Farbe'],
                ['op' => 'setCct',          'label' => 'Test-Farbtemperatur'],
                ['op' => 'setArmed',        'label' => 'Scharfschalten / Schatten-Modus'],
                ['op' => 'migrateConfig',   'label' => 'Config auf Properties migrieren (einmalig)'],
            ],

            'capabilities' => [
                'driver'  => $this->configuredDriverId(),
                'light'   => $this->driverCaps(),
            ],
        ];
    }

    /** Bedienung dieser Idents oeffnet ein manualHold-Fenster (Automatik-Hoheit, A3). */
    protected function isAutomated(Control $c): bool
    {
        return in_array($c->ident, ['Power', 'Brightness', 'ColorTemp'], true);
    }

    // ==================================================================
    // Lebenszyklus
    // ==================================================================

    // Native Instanz-Properties (Symcon-Konzept: Konfig im Instanz-Formular,
    // NICHT im FabricStore-JSON). Alle frueheren Store-Keys sind jetzt Properties.
    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyString('Driver', '');
        $this->RegisterPropertyInteger('SwitchVarId', 0);
        $this->RegisterPropertyBoolean('Invert', false);
        $this->RegisterPropertyInteger('OnScriptId', 0);
        $this->RegisterPropertyInteger('OffScriptId', 0);
        $this->RegisterPropertyInteger('LevelVarId', 0);
        $this->RegisterPropertyFloat('LevelMax', 100.0);
        $this->RegisterPropertyInteger('ColorVarId', 0);
        $this->RegisterPropertyString('ColorFormat', 'int');
        $this->RegisterPropertyInteger('CctVarId', 0);
        $this->RegisterPropertyString('CctFormat', 'kelvin');
        $this->RegisterPropertyInteger('CctMin', self::CCT_MIN);
        $this->RegisterPropertyInteger('CctMax', self::CCT_MAX);
        $this->RegisterPropertyInteger('WattVarId', 0);
        $this->RegisterPropertyFloat('WattRated', 0.0);
        $this->RegisterPropertyInteger('Circuit', 0);
        $this->RegisterPropertyBoolean('Armed', false);
        // Migrations-Marker: 0 = alte FabricStore-Config, 1 = auf Properties migriert.
        $this->RegisterPropertyInteger('ConfigSchema', 0);
    }

    /** Bindungs-Links (Baum-Transparenz) auf die gebundenen Quell-Variablen/Skripte — aus Properties. */
    protected function bindingTargets(): array
    {
        $cfg = $this->cfg();
        $out = [];
        $add = function (string $ident, string $name, int $id) use (&$out): void {
            if ($id > 0 && function_exists('IPS_ObjectExists') && @\IPS_ObjectExists($id)) {
                $out[] = ['ident' => $ident, 'name' => $name, 'targetId' => $id];
            }
        };
        $add('bl_SwitchVarId', 'Schalter', (int) $cfg['switchVarId']);
        $add('bl_LevelVarId', 'Helligkeit', (int) $cfg['levelVarId']);
        $add('bl_ColorVarId', 'Farbe', (int) $cfg['colorVarId']);
        $add('bl_CctVarId', 'Farbtemperatur', (int) $cfg['cctVarId']);
        $add('bl_WattVarId', 'Leistung', (int) $cfg['wattVarId']);
        $add('bl_OnScriptId', 'Ein-Skript', (int) $cfg['onScriptId']);
        $add('bl_OffScriptId', 'Aus-Skript', (int) $cfg['offScriptId']);
        return $out;
    }

    protected function setupTimers(): void
    {
        $this->RegisterTimer(self::TIMER_REFRESH, 0, 'HSLT_Refresh($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->driverResolved = false;
        $this->driverInstance = null;

        $active = $this->driver() instanceof ILight;
        $this->SetTimerInterval(self::TIMER_REFRESH, $active ? self::REFRESH_MS : 0);
        $this->syncReferences();
        $this->updateHealth();
    }

    // ==================================================================
    // Bedien-Hook (Vertrag 1) — SCHATTEN-MODUS bis armed
    // ==================================================================

    protected function applyControl(Control $c, $value, ActionContext $ctx): void
    {
        // Optimistischer SetValue der Statusvariable ist bereits durch die Basis erfolgt.
        // Real geschaltet wird nur bei armed=true; sonst nur protokollieren (Schatten-Modus).
        if (!(bool) $this->cfgVal('armed', false)) {
            $this->SendDebug('HSLT.shadow', $c->ident . '=' . (is_scalar($value) ? (string) $value : '?') . ' (Schatten)', 0);
            return;
        }
        $drv = $this->driver();
        if (!($drv instanceof ILight)) {
            return;
        }
        switch ($c->ident) {
            case 'Power':
                $drv->setPower((bool) $value);
                break;
            case 'Brightness':
                $drv->setLevel((int) $value);
                break;
            case 'ColorTemp':
                $drv->setCct((int) $value);
                break;
            default:
                $this->SendDebug('HSLT.apply', $c->ident . ' unbehandelt', 0);
                break;
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
        if ((string) ($cfg['driver'] ?? '') !== 'generic-light') {
            return null; // unkonfiguriert -> Schatten-Modus
        }
        $hasSwitch  = (int) ($cfg['switchVarId'] ?? 0) > 0;
        $hasLevel   = (int) ($cfg['levelVarId'] ?? 0) > 0;
        $hasScripts = (int) ($cfg['onScriptId'] ?? 0) > 0 && (int) ($cfg['offScriptId'] ?? 0) > 0;
        if (!$hasSwitch && !$hasLevel && !$hasScripts) {
            return null;
        }
        try {
            $this->driverInstance = DriverFactory::create('generic-light', [
                'switchVarId' => (int) ($cfg['switchVarId'] ?? 0),
                'invert'      => (bool) ($cfg['invert'] ?? false),
                'onScriptId'  => (int) ($cfg['onScriptId'] ?? 0),
                'offScriptId' => (int) ($cfg['offScriptId'] ?? 0),
                'levelVarId'  => (int) ($cfg['levelVarId'] ?? 0),
                'levelMax'    => (float) ($cfg['levelMax'] ?? 100),
                'colorVarId'  => (int) ($cfg['colorVarId'] ?? 0),
                'colorFormat' => (string) ($cfg['colorFormat'] ?? 'int'),
                'cctVarId'    => (int) ($cfg['cctVarId'] ?? 0),
                'cctFormat'   => (string) ($cfg['cctFormat'] ?? 'kelvin'),
                'cctMin'      => (int) ($cfg['cctMin'] ?? self::CCT_MIN),
                'cctMax'      => (int) ($cfg['cctMax'] ?? self::CCT_MAX),
                'wattVarId'   => (int) ($cfg['wattVarId'] ?? 0),
                'wattRated'   => (float) ($cfg['wattRated'] ?? 0),
                'circuit'     => (int) ($cfg['circuit'] ?? 0),
            ]);
        } catch (\Throwable $e) {
            $this->SendDebug('HSLT.driver', 'Treiberaufbau fehlgeschlagen: ' . $e->getMessage(), 0);
            $this->driverInstance = null;
        }
        return $this->driverInstance;
    }

    /** Fähigkeiten des gebundenen Treibers (leer, wenn inaktiv). */
    private function driverCaps(): array
    {
        $drv = $this->driver();
        return ($drv instanceof ILight) ? $drv->capabilities() : [];
    }

    // ==================================================================
    // Refresh — Ist-Zustand in die Reflect-/Status-Variablen spiegeln
    // ==================================================================

    public function Refresh(): void
    {
        $drv = $this->driver();
        if (!($drv instanceof ILight)) {
            return;
        }
        $st = $drv->readState();
        // Aktionierbare Anzeigen mitführen (Gerät -> Anzeige), Reflects via setReflect.
        @$this->SetValue('Power', $st->on);
        if ($st->level >= 0) {
            @$this->SetValue('Brightness', $st->level);
        }
        if ($st->cct > 0) {
            @$this->SetValue('ColorTemp', $st->cct);
        }
        $this->setReflect('Color', $st->color >= 0 ? $st->color : 0);
        $this->setReflect('Watt', $st->watt >= 0 ? $st->watt : 0.0);
        $this->setReflect('Online', $st->reachable);
    }

    // ==================================================================
    // Verwaltungs-RPC
    // ==================================================================

    protected function mgmt(string $op, array $args, array $ctx): array
    {
        switch ($op) {
            case 'configureDriver':
                return $this->mgmtConfigureDriver($args, $ctx);
            case 'getConfig':
                return ['ok' => true, 'config' => $this->cfg()];
            case 'validate':
                return $this->mgmtValidate();
            case 'driverProbe':
                return $this->mgmtDriverProbe();
            case 'readState':
                $drv = $this->driver();
                return ['ok' => true, 'driverActive' => $drv instanceof ILight,
                    'armed' => (bool) $this->cfgVal('armed', false),
                    'state' => ($drv instanceof ILight) ? $drv->readState()->toArray() : null,
                    'caps'  => $this->driverCaps()];
            case 'setPower':
                return $this->mgmtTest(fn(ILight $d) => $d->setPower((bool) ($args['on'] ?? false)), $args);
            case 'setLevel':
                return $this->mgmtTest(fn(ILight $d) => $d->setLevel((int) ($args['level'] ?? 0)), $args);
            case 'setColor':
                return $this->mgmtTest(fn(ILight $d) => $d->setColor((int) ($args['rgb'] ?? 0)), $args);
            case 'setCct':
                return $this->mgmtTest(fn(ILight $d) => $d->setCct((int) ($args['kelvin'] ?? self::CCT_MIN)), $args);
            case 'setArmed':
                @\IPS_SetProperty($this->InstanceID, 'Armed', (bool) ($args['armed'] ?? false));
                @\IPS_ApplyChanges($this->InstanceID);
                return ['ok' => true, 'armed' => (bool) $this->cfgVal('armed', false)];
            case 'migrateConfig':
                return $this->migrateConfig();
            default:
                return parent::mgmt($op, $args, $ctx);
        }
    }

    /** Test-Schaltung: nur bei armed real, sonst Schatten (kein Aktor-Schreiben). */
    private function mgmtTest(callable $fn, array $args): array
    {
        $drv = $this->driver();
        if (!($drv instanceof ILight)) {
            return ['ok' => false, 'error' => 'driver_inactive'];
        }
        if (!(bool) $this->cfgVal('armed', false)) {
            return ['ok' => true, 'armed' => false, 'shadow' => true, 'note' => 'Schatten-Modus: nicht real geschaltet'];
        }
        $res = (bool) $fn($drv);
        return ['ok' => $res, 'armed' => true, 'state' => $drv->readState()->toArray()];
    }

    /**
     * Aktor-Bindung schreiben (reale Variablen/Skripte). Universell: An/Aus per
     * switchVarId ODER Start/Stop-Skript; Helligkeit/Farbe/CCT optional. armed bleibt
     * unverändert (Default false = Schatten).
     */
    private function mgmtConfigureDriver(array $args, array $ctx): array
    {
        $driver = (string) ($args['driver'] ?? 'generic-light');
        if (!in_array($driver, ['', 'generic-light'], true)) {
            throw new ContractException('unbekannter Treiber: ' . $driver);
        }
        $vexists = function ($id) {
            $id = (int) $id;
            return $id > 0 && function_exists('IPS_VariableExists') && \IPS_VariableExists($id);
        };
        $sexists = function ($id) {
            $id = (int) $id;
            return $id > 0 && function_exists('IPS_ScriptExists') && \IPS_ScriptExists($id);
        };

        $switch  = (int) ($args['switchVarId'] ?? 0);
        $level   = (int) ($args['levelVarId'] ?? 0);
        $onScr   = (int) ($args['onScriptId'] ?? 0);
        $offScr  = (int) ($args['offScriptId'] ?? 0);
        $hasSwitch  = $vexists($switch);
        $hasLevel   = $vexists($level);
        $hasScripts = $sexists($onScr) && $sexists($offScr);
        if (!$hasSwitch && !$hasLevel && !$hasScripts) {
            throw new ContractException('Mindestens eine Bindung nötig: switchVarId, levelVarId oder on/offScriptId');
        }

        $config = [
            'driver'      => $driver,
            'switchVarId' => $hasSwitch ? $switch : 0,
            'invert'      => (bool) ($args['invert'] ?? false),
            'onScriptId'  => $hasScripts ? $onScr : 0,
            'offScriptId' => $hasScripts ? $offScr : 0,
            'levelVarId'  => $hasLevel ? $level : 0,
            'levelMax'    => (float) ($args['levelMax'] ?? 100),
            'colorVarId'  => $vexists($args['colorVarId'] ?? 0) ? (int) $args['colorVarId'] : 0,
            'colorFormat' => in_array((string) ($args['colorFormat'] ?? 'int'), ['int', 'hex'], true) ? (string) ($args['colorFormat'] ?? 'int') : 'int',
            'cctVarId'    => $vexists($args['cctVarId'] ?? 0) ? (int) $args['cctVarId'] : 0,
            'cctFormat'   => in_array((string) ($args['cctFormat'] ?? 'kelvin'), ['kelvin', 'mired', 'percent'], true) ? (string) ($args['cctFormat'] ?? 'kelvin') : 'kelvin',
            'cctMin'      => (int) ($args['cctMin'] ?? self::CCT_MIN),
            'cctMax'      => (int) ($args['cctMax'] ?? self::CCT_MAX),
            'wattVarId'   => $vexists($args['wattVarId'] ?? 0) ? (int) $args['wattVarId'] : 0,
            'wattRated'   => (float) ($args['wattRated'] ?? 0),
            'circuit'     => (int) ($args['circuit'] ?? 0),
        ];

        if (!empty($ctx['dryrun'])) {
            return ['ok' => true, 'dryrun' => true, 'config' => $config];
        }
        // Native Properties schreiben (ApplyChanges triggert syncReferences/Links/Timer neu).
        $this->applyConfigProperties($config, true);
        $active = $this->driver() instanceof ILight;
        return ['ok' => true, 'config' => $this->cfg(), 'driverActive' => $active, 'caps' => $this->driverCaps()];
    }

    private function mgmtDriverProbe(): array
    {
        $drv = $this->driver();
        return ['ok' => true, 'driverActive' => $drv instanceof ILight,
            'driver' => $this->configuredDriverId(), 'caps' => $this->driverCaps()];
    }

    private function mgmtValidate(): array
    {
        $h = $this->computeHealth();
        return ['ok' => $h['ok'], 'health' => $h['text'], 'issues' => $h['issues'], 'config' => $this->cfg()];
    }

    // ==================================================================
    // Health / Referenzen
    // ==================================================================

    private function computeHealth(): array
    {
        $cfg = $this->cfg();
        if ((string) ($cfg['driver'] ?? '') === '') {
            return ['ok' => false, 'text' => 'inaktiv (kein Treiber)', 'issues' => ['kein Treiber']];
        }
        $issues = [];
        $vchk = function ($id, $label) use (&$issues) {
            $id = (int) $id;
            if ($id > 0 && !(function_exists('IPS_VariableExists') && @\IPS_VariableExists($id))) {
                $issues[] = $label . ' #' . $id . ' fehlt';
            }
        };
        $hasSwitch  = (int) ($cfg['switchVarId'] ?? 0) > 0;
        $hasLevel   = (int) ($cfg['levelVarId'] ?? 0) > 0;
        $hasScripts = (int) ($cfg['onScriptId'] ?? 0) > 0 && (int) ($cfg['offScriptId'] ?? 0) > 0;
        if (!$hasSwitch && !$hasLevel && !$hasScripts) {
            $issues[] = 'keine Bindung (switch/level/script)';
        }
        foreach (['switchVarId', 'levelVarId', 'colorVarId', 'cctVarId', 'wattVarId'] as $k) {
            $vchk($cfg[$k] ?? 0, $k);
        }
        if (!($this->driver() instanceof ILight)) {
            $issues[] = 'Treiber inaktiv';
        }
        if ($issues) {
            return ['ok' => false, 'text' => 'FEHLER: ' . implode(', ', $issues), 'issues' => $issues];
        }
        $caps  = $this->driverCaps();
        $bits  = array_values(array_filter([
            !empty($caps['switch']) ? 'schalt' : null,
            !empty($caps['dim']) ? 'dim' : null,
            !empty($caps['color']) ? 'farbe' : null,
            !empty($caps['cct']) ? 'cct' : null,
        ]));
        $armed = (bool) ($cfg['armed'] ?? false);
        return ['ok' => true, 'issues' => [],
            'text' => 'OK · ' . (implode('+', $bits) ?: 'gebunden') . ($armed ? ' · scharf' : ' · Schatten-Modus')];
    }

    private function updateHealth(): void
    {
        @$this->RegisterVariableString('BindHealth', 'Bindung', '', 90);
        $h = $this->computeHealth();
        @$this->SetValue('BindHealth', (string) $h['text']);
    }

    private function syncReferences(): void
    {
        if (!method_exists($this, 'GetReferenceList')) {
            return;
        }
        foreach ($this->GetReferenceList() as $ref) {
            @$this->UnregisterReference($ref);
        }
        $cfg = $this->cfg();
        foreach (['switchVarId', 'levelVarId', 'colorVarId', 'cctVarId', 'wattVarId', 'onScriptId', 'offScriptId'] as $k) {
            $id = (int) ($cfg[$k] ?? 0);
            if ($id > 0 && function_exists('IPS_ObjectExists') && @\IPS_ObjectExists($id)) {
                @$this->RegisterReference($id);
            }
        }
    }

    // ==================================================================
    // Konsolen-Formular (Notfall/Erstkonfiguration; Verwaltung sonst im LVB)
    // ==================================================================

    public function GetConfigurationForm()
    {
        // Native Symcon-Konfiguration: Felder sind an Instanz-Properties gebunden
        // (name == Property) und werden bei "Aenderungen uebernehmen" gespeichert.
        // Buttons unter "actions" sind Laufzeit-Aktionen (RPC), keine Konfig.
        $h = $this->computeHealth();
        $form = [
            'elements' => [
                ['type' => 'Label', 'caption' => 'Licht — Aktor-Bindung (reale Variablen/Skripte). Gruppen/Szenen/Automatik laufen im LiveViewBuilder.'],
                ['type' => 'Select', 'name' => 'Driver', 'caption' => 'Treiber', 'options' => [
                    ['caption' => '— keiner (Schatten-Modus) —', 'value' => ''],
                    ['caption' => 'Generisch (Variable/Skript)', 'value' => 'generic-light'],
                ]],
                ['type' => 'ExpansionPanel', 'caption' => 'An/Aus (Variable ODER Skript)', 'expanded' => true, 'items' => [
                    ['type' => 'SelectVariable', 'name' => 'SwitchVarId', 'caption' => 'Schalt-Variable (bool)'],
                    ['type' => 'RowLayout', 'items' => [
                        ['type' => 'SelectScript', 'name' => 'OnScriptId', 'caption' => 'Ein-Skript'],
                        ['type' => 'SelectScript', 'name' => 'OffScriptId', 'caption' => 'Aus-Skript'],
                        ['type' => 'CheckBox', 'name' => 'Invert', 'caption' => 'Invertieren'],
                    ]],
                ]],
                ['type' => 'ExpansionPanel', 'caption' => 'Helligkeit (optional)', 'items' => [
                    ['type' => 'RowLayout', 'items' => [
                        ['type' => 'SelectVariable', 'name' => 'LevelVarId', 'caption' => 'Helligkeit'],
                        ['type' => 'NumberSpinner', 'name' => 'LevelMax', 'caption' => 'Voll-Wert (100/255/1)', 'digits' => 0, 'minimum' => 1, 'maximum' => 255],
                    ]],
                ]],
                ['type' => 'ExpansionPanel', 'caption' => 'Farbe / Farbtemperatur (optional)', 'items' => [
                    ['type' => 'RowLayout', 'items' => [
                        ['type' => 'SelectVariable', 'name' => 'ColorVarId', 'caption' => 'Farbe (RGB)'],
                        ['type' => 'Select', 'name' => 'ColorFormat', 'caption' => 'Farb-Format', 'options' => [
                            ['caption' => 'Integer 0xRRGGBB', 'value' => 'int'],
                            ['caption' => 'Hex "#RRGGBB"', 'value' => 'hex'],
                        ]],
                    ]],
                    ['type' => 'RowLayout', 'items' => [
                        ['type' => 'SelectVariable', 'name' => 'CctVarId', 'caption' => 'Farbtemperatur'],
                        ['type' => 'Select', 'name' => 'CctFormat', 'caption' => 'CCT-Format', 'options' => [
                            ['caption' => 'Kelvin', 'value' => 'kelvin'],
                            ['caption' => 'Mired', 'value' => 'mired'],
                            ['caption' => 'Prozent 0..100 (warm→kalt)', 'value' => 'percent'],
                        ]],
                    ]],
                    ['type' => 'RowLayout', 'items' => [
                        ['type' => 'NumberSpinner', 'name' => 'CctMin', 'caption' => 'CCT min (K)', 'minimum' => 1000, 'maximum' => 10000],
                        ['type' => 'NumberSpinner', 'name' => 'CctMax', 'caption' => 'CCT max (K)', 'minimum' => 1000, 'maximum' => 10000],
                    ]],
                ]],
                ['type' => 'ExpansionPanel', 'caption' => 'Leistung (optional)', 'items' => [
                    ['type' => 'RowLayout', 'items' => [
                        ['type' => 'SelectVariable', 'name' => 'WattVarId', 'caption' => 'Leistung gemessen (W)'],
                        ['type' => 'NumberSpinner', 'name' => 'WattRated', 'caption' => 'Nennleistung (W)', 'digits' => 0, 'minimum' => 0, 'maximum' => 5000],
                        ['type' => 'NumberSpinner', 'name' => 'Circuit', 'caption' => 'Stromkreis', 'minimum' => 0, 'maximum' => 99],
                    ]],
                ]],
                ['type' => 'CheckBox', 'name' => 'Armed', 'caption' => 'Scharf — schaltet real (sonst Schatten-Modus: nur protokollieren/spiegeln)'],
                ['type' => 'Label', 'caption' => 'Status: ' . $h['text']],
            ],
            'actions' => [
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'Button', 'caption' => 'Test: An', 'onClick' => 'echo HSLT_Manage($id, json_encode(["op"=>"setPower","args"=>["on"=>true]]));'],
                    ['type' => 'Button', 'caption' => 'Test: Aus', 'onClick' => 'echo HSLT_Manage($id, json_encode(["op"=>"setPower","args"=>["on"=>false]]));'],
                    ['type' => 'Button', 'caption' => 'Ist-Zustand', 'onClick' => 'echo HSLT_Manage($id, json_encode(["op"=>"readState"]));'],
                    ['type' => 'Button', 'caption' => 'Bindung pruefen', 'onClick' => 'echo HSLT_Manage($id, json_encode(["op"=>"validate"]));'],
                ]],
                ['type' => 'Label', 'caption' => 'Real geschaltet wird nur bei "scharf" (Armed).'],
            ],
        ];
        return json_encode($form);
    }

    // ==================================================================
    // Helfer
    // ==================================================================

    /** Konfiguration aus nativen Instanz-Properties (Symcon-Konzept). */
    private function cfg(): array
    {
        return [
            'driver'      => $this->ReadPropertyString('Driver'),
            'switchVarId' => $this->ReadPropertyInteger('SwitchVarId'),
            'invert'      => $this->ReadPropertyBoolean('Invert'),
            'onScriptId'  => $this->ReadPropertyInteger('OnScriptId'),
            'offScriptId' => $this->ReadPropertyInteger('OffScriptId'),
            'levelVarId'  => $this->ReadPropertyInteger('LevelVarId'),
            'levelMax'    => $this->ReadPropertyFloat('LevelMax'),
            'colorVarId'  => $this->ReadPropertyInteger('ColorVarId'),
            'colorFormat' => $this->ReadPropertyString('ColorFormat'),
            'cctVarId'    => $this->ReadPropertyInteger('CctVarId'),
            'cctFormat'   => $this->ReadPropertyString('CctFormat'),
            'cctMin'      => $this->ReadPropertyInteger('CctMin'),
            'cctMax'      => $this->ReadPropertyInteger('CctMax'),
            'wattVarId'   => $this->ReadPropertyInteger('WattVarId'),
            'wattRated'   => $this->ReadPropertyFloat('WattRated'),
            'circuit'     => $this->ReadPropertyInteger('Circuit'),
            'armed'       => $this->ReadPropertyBoolean('Armed'),
        ];
    }

    /** Schreibt Config-Felder in die nativen Properties (Teilmenge erlaubt). */
    private function applyConfigProperties(array $c, bool $apply = true): void
    {
        $S = fn(string $p, $v) => @\IPS_SetProperty($this->InstanceID, $p, $v);
        if (array_key_exists('driver', $c))      $S('Driver', (string) $c['driver']);
        if (array_key_exists('switchVarId', $c)) $S('SwitchVarId', (int) $c['switchVarId']);
        if (array_key_exists('invert', $c))      $S('Invert', (bool) $c['invert']);
        if (array_key_exists('onScriptId', $c))  $S('OnScriptId', (int) $c['onScriptId']);
        if (array_key_exists('offScriptId', $c)) $S('OffScriptId', (int) $c['offScriptId']);
        if (array_key_exists('levelVarId', $c))  $S('LevelVarId', (int) $c['levelVarId']);
        if (array_key_exists('levelMax', $c))    $S('LevelMax', (float) $c['levelMax']);
        if (array_key_exists('colorVarId', $c))  $S('ColorVarId', (int) $c['colorVarId']);
        if (array_key_exists('colorFormat', $c)) $S('ColorFormat', (string) $c['colorFormat']);
        if (array_key_exists('cctVarId', $c))    $S('CctVarId', (int) $c['cctVarId']);
        if (array_key_exists('cctFormat', $c))   $S('CctFormat', (string) $c['cctFormat']);
        if (array_key_exists('cctMin', $c))      $S('CctMin', (int) $c['cctMin']);
        if (array_key_exists('cctMax', $c))      $S('CctMax', (int) $c['cctMax']);
        if (array_key_exists('wattVarId', $c))   $S('WattVarId', (int) $c['wattVarId']);
        if (array_key_exists('wattRated', $c))   $S('WattRated', (float) $c['wattRated']);
        if (array_key_exists('circuit', $c))     $S('Circuit', (int) $c['circuit']);
        if ($apply) {
            @\IPS_ApplyChanges($this->InstanceID);
        }
    }

    /** Einmal-Migration: alte FabricStore-config -> native Properties (per RPC ausgeloest). */
    private function migrateConfig(): array
    {
        if ($this->ReadPropertyInteger('ConfigSchema') >= 1) {
            return ['ok' => true, 'already' => true, 'config' => $this->cfg()];
        }
        $c = $this->store()->get('config', []);
        $c = is_array($c) ? $c : [];
        $this->applyConfigProperties($c, false);      // gebuendelt, ApplyChanges gleich unten
        @\IPS_SetProperty($this->InstanceID, 'ConfigSchema', 1);
        @\IPS_ApplyChanges($this->InstanceID);
        return ['ok' => true, 'migrated' => array_keys($c), 'config' => $this->cfg()];
    }

    private function cfgVal(string $key, $def)
    {
        $c = $this->cfg();
        return array_key_exists($key, $c) ? $c[$key] : $def;
    }

    private function configuredDriverId(): string
    {
        return (string) ($this->cfg()['driver'] ?? '');
    }
}

<?php

declare(strict_types=1);

require_once '/var/lib/symcon/modules/HomeSuite/libs/HomeSuite/autoload.php';

use Hoep\HomeSuite\ActionContext;
use Hoep\HomeSuite\Control;
use Hoep\HomeSuite\ControlContract;
use Hoep\HomeSuite\EntityModule;
use Hoep\HomeSuite\HAL\DriverFactory;
use Hoep\HomeSuite\HAL\GardenaAppApi;
use Hoep\HomeSuite\HAL\GardenaDevApi;

/**
 * HomeSuite-Gardena-Geraet (Prefix HSGA) — EIN Objekt je Gardena-Geraet (Feuchtesensor,
 * Bewaesserungscomputer, …). LESEN laeuft ueber den Legacy-Treiber `gardena-app`
 * (smart.gardena.com, Automower-Legacy-Token — grosszuegig limitiert). NUR das VENTIL-SCHALTEN
 * laeuft ueber `gardena-dev` (neue Developer-API, Client ID/Secret; die alte Cloud nimmt keine
 * Kommandos mehr an). Die neue API ist streng limitiert (~1 Aufruf/15 min), daher NIE fuers Lesen.
 * Anlegen der Instanzen ueber den Configurator. Analog MowerDevice: Poll-Timer,
 * Reflect-Statusvariablen, Armed-Gate fuers Schalten (Ventil).
 * Die Controls sind kategorie-adaptiv (Sensor = read-only; watering_computer = zusaetzlich Ventil).
 */
class GardenaDevice extends EntityModule
{
    private const TIMER_REFRESH = 'Refresh';
    private const DEF_POLL_S    = 60;
    // LESEN laeuft ueber die ALTE, grosszuegig limitierte Cloud (Legacy-Token wie der Automower).
    private const TOKEN_FILE    = '/var/lib/symcon/scripts/automower_token.json';
    // SCHALTEN (nur Ventil) laeuft ueber die NEUE Developer-API (Client ID/Secret, root-only).
    private const CRED_FILE     = '/var/lib/symcon/scripts/gardena_dev.json';

    private ?GardenaAppApi $driverInstance = null;
    private bool $driverResolved = false;
    private ?GardenaDevApi $writeInstance = null;
    private bool $writeResolved = false;

    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyString('Driver', 'gardena-app');
        $this->RegisterPropertyString('WriteDriver', 'gardena-dev');
        $this->RegisterPropertyString('DeviceId', '');
        $this->RegisterPropertyString('LocationId', '');
        $this->RegisterPropertyString('Serial', '');
        $this->RegisterPropertyString('Category', '');
        $this->RegisterPropertyInteger('PollInterval', self::DEF_POLL_S);
        $this->RegisterPropertyBoolean('Armed', false);
        $this->RegisterPropertyInteger('ConfigSchema', 1);
        $this->RegisterTimer(self::TIMER_REFRESH, 0, 'HSGA_Refresh($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->driverResolved = false;
        $this->driverInstance = null;
        $this->writeResolved  = false;
        $this->writeInstance  = null;
        $active = $this->driver() instanceof GardenaAppApi;
        $poll   = max(15, (int) $this->ReadPropertyInteger('PollInterval'));
        $this->SetTimerInterval(self::TIMER_REFRESH, $active ? $poll * 1000 : 0);
    }

    // ==================================================================
    // Manifest (kategorie-adaptiv)
    // ==================================================================
    protected function manifest(): array
    {
        $cat = (string) @$this->ReadPropertyString('Category');
        $isWater  = in_array($cat, ['watering_computer', 'water_control', 'smart_irrigation_control'], true);
        $isSensor = in_array($cat, ['sensor2', 'sensor'], true);

        $controls = [
            // --- gemeinsame Reflects ---
            ['ident' => 'Online', 'type' => ControlContract::T_REFLECT, 'role' => 'gardena:online',
             'label' => 'Online', 'varType' => 0, 'profile' => '~Switch', 'actionable' => false],
            ['ident' => 'Battery', 'type' => ControlContract::T_REFLECT, 'role' => 'gardena:battery',
             'label' => 'Batterie', 'varType' => 1, 'profile' => '~Battery.100', 'actionable' => false],
            ['ident' => 'RadioQuality', 'type' => ControlContract::T_REFLECT, 'role' => 'gardena:radio',
             'label' => 'Funkqualitaet', 'varType' => 1, 'profile' => '~Intensity.100', 'actionable' => false],
            ['ident' => 'ErrorText', 'type' => ControlContract::T_REFLECT, 'role' => 'gardena:error',
             'label' => 'Fehler', 'varType' => 3, 'actionable' => false],
            ['ident' => 'LastUpdate', 'type' => ControlContract::T_REFLECT, 'role' => 'gardena:lastupdate',
             'label' => 'Aktualisiert', 'varType' => 1, 'profile' => '~UnixTimestamp', 'actionable' => false],
        ];

        if ($isSensor) {
            $controls[] = ['ident' => 'SoilMoisture', 'type' => ControlContract::T_REFLECT, 'role' => 'gardena:soilmoisture',
                'label' => 'Bodenfeuchte', 'varType' => 1, 'profile' => '~Intensity.100', 'actionable' => false];
            $controls[] = ['ident' => 'SoilTemp', 'type' => ControlContract::T_REFLECT, 'role' => 'gardena:soiltemp',
                'label' => 'Bodentemperatur', 'varType' => 2, 'profile' => '~Temperature', 'actionable' => false];
            $controls[] = ['ident' => 'FrostWarning', 'type' => ControlContract::T_REFLECT, 'role' => 'gardena:frost',
                'label' => 'Frostwarnung', 'varType' => 3, 'actionable' => false];
        }
        if ($isWater) {
            $controls[] = ['ident' => 'AmbientTemp', 'type' => ControlContract::T_REFLECT, 'role' => 'gardena:ambienttemp',
                'label' => 'Umgebungstemperatur', 'varType' => 2, 'profile' => '~Temperature', 'actionable' => false];
            $controls[] = ['ident' => 'ValveState', 'type' => ControlContract::T_REFLECT, 'role' => 'gardena:valvestate',
                'label' => 'Ventil-Status', 'varType' => 3, 'actionable' => false];
            $controls[] = ['ident' => 'ValveDuration', 'type' => ControlContract::T_REFLECT, 'role' => 'gardena:valveduration',
                'label' => 'Restlaufzeit', 'varType' => 1, 'unit' => ' s', 'actionable' => false];
            // Schaltbar (armed-gated)
            $controls[] = ['ident' => 'WaterMinutes', 'type' => ControlContract::T_SETPOINT, 'role' => 'gardena:waterminutes',
                'label' => 'Bewaessern (min)', 'varType' => 1, 'min' => 1, 'max' => 90, 'step' => 1, 'unit' => ' min', 'actionable' => true];
            $controls[] = ['ident' => 'WaterStop', 'type' => ControlContract::T_COMMAND, 'role' => 'gardena:waterstop',
                'label' => 'Bewaesserung stoppen', 'varType' => 1, 'actionable' => true];
        }

        return [
            'domain'   => 'gardena',
            'title'    => 'Gardena',
            'icon'     => 'Sprout',
            'controls' => $controls,
            'managementActions' => [
                ['op' => 'getState',        'label' => 'Zustand lesen (Diagnose)'],
                ['op' => 'listDevices',     'label' => 'Gardena-Geraete auflisten'],
                ['op' => 'importGardena',   'label' => 'Gardena-Geraete importieren'],
                ['op' => 'saveDevCreds',    'label' => 'Developer-API Key/Secret speichern'],
                ['op' => 'testDevRead',     'label' => 'Developer-API testen (lesen)'],
                ['op' => 'backfillSerials', 'label' => 'Seriennummern nachtragen'],
            ],
        ];
    }

    // ==================================================================
    // Bedienung (armed-gated)
    // ==================================================================
    protected function applyControl(Control $c, $value, ActionContext $ctx): void
    {
        switch ($c->ident) {
            case 'WaterMinutes':
                $sec = max(1, (int) round((float) $value)) * 60;
                $this->gated('WaterStart', fn(GardenaDevApi $d) => ($d->valveStart($sec)['ok'] ?? false));
                break;
            case 'WaterStop':
                $this->gated('WaterStop', fn(GardenaDevApi $d) => ($d->valveStop()['ok'] ?? false));
                break;
        }
    }

    /**
     * Fuehrt $fn nur bei vorhandenem Schreib-Treiber (gardena-dev, Developer-API) UND armed=true real
     * aus; sonst nur Log (Schatten). Schalten laeuft NICHT ueber die Legacy-Cloud (die nimmt keine
     * Kommandos mehr an), sondern ueber die neue Husqvarna-Developer-API.
     */
    private function gated(string $what, callable $fn): void
    {
        $d = $this->writeDriver();
        if (!$d instanceof GardenaDevApi) {
            $this->SendDebug('HSGA.cmd', $what . ': kein Schreib-Treiber (gardena_dev.json fehlt/Creds ungueltig)', 0);
            return;
        }
        if (!$this->armed()) { $this->SendDebug('HSGA.shadow', 'WUERDE ' . $what . ' (nicht scharf)', 0); return; }
        try {
            $ok = (bool) $fn($d);
            $this->SendDebug('HSGA.cmd', $what . ' -> ' . ($ok ? 'ok' : 'FEHLER: ' . $d->lastError()), 0);
        } catch (\Throwable $e) {
            $this->SendDebug('HSGA.cmd', $what . ' Exception: ' . $e->getMessage(), 0);
        }
        $this->SetTimerInterval(self::TIMER_REFRESH, 4000); // Zustand bald nachziehen
    }

    // ==================================================================
    // Poll -> Reflect
    // ==================================================================
    public function Refresh(): void
    {
        $poll = max(15, (int) $this->ReadPropertyInteger('PollInterval'));
        $this->SetTimerInterval(self::TIMER_REFRESH, $poll * 1000);

        $d = $this->driver();
        if (!$d instanceof GardenaAppApi) { return; }
        $st = $d->readState();
        if (empty($st['ok'])) { $this->setReflect('Online', false); return; }

        $map = [
            'online' => 'Online', 'battery' => 'Battery', 'radioQuality' => 'RadioQuality', 'error' => 'ErrorText',
            'soilMoisture' => 'SoilMoisture', 'soilTemp' => 'SoilTemp', 'frostWarning' => 'FrostWarning',
            'ambientTemp' => 'AmbientTemp', 'valveState' => 'ValveState', 'valveDuration' => 'ValveDuration',
        ];
        foreach ($map as $k => $ident) {
            if (array_key_exists($k, $st) && $st[$k] !== null && @$this->GetIDForIdent($ident)) {
                $this->setReflect($ident, $st[$k]);
            }
        }
        if (@$this->GetIDForIdent('LastUpdate')) { $this->setReflect('LastUpdate', time()); }
    }

    // ==================================================================
    // HAL-Treiber
    // ==================================================================
    protected function driver(): ?GardenaAppApi
    {
        if ($this->driverResolved) { return $this->driverInstance; }
        $this->driverResolved = true;
        $this->driverInstance = null;

        if ((string) $this->ReadPropertyString('Driver') !== 'gardena-app') { return null; }
        $dev = (string) $this->ReadPropertyString('DeviceId');
        if ($dev === '') { return null; }
        try {
            $drv = DriverFactory::create('gardena-app', [
                'tokenFile'  => self::TOKEN_FILE,
                'deviceId'   => $dev,
                'locationId' => (string) $this->ReadPropertyString('LocationId'),
            ]);
            $this->driverInstance = ($drv instanceof GardenaAppApi) ? $drv : null;
        } catch (\Throwable $e) {
            $this->SendDebug('HSGA.driver', 'Treiberaufbau fehlgeschlagen: ' . $e->getMessage(), 0);
            $this->driverInstance = null;
        }
        return $this->driverInstance;
    }

    /**
     * Schreib-Treiber (gardena-dev, neue Developer-API) — nur wenn die Credential-Datei existiert.
     * Findet das Geraet in der neuen API ueber die Seriennummer (Legacy-IDs != neue Service-IDs).
     */
    protected function writeDriver(): ?GardenaDevApi
    {
        if ($this->writeResolved) { return $this->writeInstance; }
        $this->writeResolved = true;
        $this->writeInstance = null;

        if ((string) $this->ReadPropertyString('WriteDriver') !== 'gardena-dev') { return null; }
        if (!@is_file(self::CRED_FILE)) { return null; }
        try {
            $drv = DriverFactory::create('gardena-dev', [
                'credFile'    => self::CRED_FILE,
                'matchSerial' => (string) $this->ReadPropertyString('Serial'),
            ]);
            $this->writeInstance = ($drv instanceof GardenaDevApi) ? $drv : null;
        } catch (\Throwable $e) {
            $this->SendDebug('HSGA.write', 'Schreib-Treiber fehlgeschlagen: ' . $e->getMessage(), 0);
            $this->writeInstance = null;
        }
        return $this->writeInstance;
    }

    /** Scharf-Zustand: Hub-Master (falls vorhanden) hat Vorrang, sonst per-Instanz-Property. */
    private function armed(): bool
    {
        return $this->armedEffective($this->ReadPropertyBoolean('Armed'));
    }

    // ==================================================================
    // Verwaltung (RPC): Zustand lesen, Cloud-Geraete importieren
    // ==================================================================
    protected function mgmt(string $op, array $args, array $ctx): array
    {
        switch ($op) {
            case 'getState':
                $d = $this->driver();
                return ['ok' => (bool) $d, 'state' => $d ? $d->readState() : null];
            case 'listDevices':
                $d = DriverFactory::create('gardena-app', ['tokenFile' => self::TOKEN_FILE]);
                return ['ok' => true, 'devices' => ($d instanceof GardenaAppApi) ? $d->listDevices() : []];
            case 'importGardena':
                return $this->mgmtImport();
            case 'saveDevCreds':
                return $this->mgmtSaveDevCreds($args);
            case 'testDevRead':
                $w = $this->writeDriver();
                return ['ok' => (bool) $w, 'state' => $w ? $w->readState() : null, 'err' => $w ? $w->lastError() : 'kein Schreib-Treiber'];
            case 'backfillSerials':
                return $this->mgmtBackfillSerials();
        }
        return parent::mgmt($op, $args, $ctx);
    }

    /**
     * Discovery: alle Cloud-Geraete auflisten und je Geraet EINE HSGA-Instanz anlegen
     * (dedup ueber DeviceId). Gateway wird uebersprungen (nichts zu zeigen). armed bleibt false.
     */
    private function mgmtImport(): array
    {
        $drv = DriverFactory::create('gardena-app', ['tokenFile' => self::TOKEN_FILE]);
        if (!$drv instanceof GardenaAppApi) { return ['ok' => false, 'err' => 'kein Treiber']; }
        $devs = $drv->listDevices();
        $guid = '{2DB05F36-BBD0-AC2E-85A8-369B88CD242F}';
        // vorhandene DeviceIds sammeln
        $have = [];
        foreach (@\IPS_GetInstanceListByModuleID($guid) ?: [] as $iid) {
            $have[(string) @\IPS_GetProperty($iid, 'DeviceId')] = $iid;
        }
        $created = [];
        foreach ($devs as $d) {
            if (($d['category'] ?? '') === 'gateway') { continue; }
            if (isset($have[$d['id']])) { continue; }
            $iid = @\IPS_CreateInstance($guid);
            if ($iid === false) { continue; }
            @\IPS_SetName($iid, 'Gardena ' . $d['name']);
            @\IPS_SetProperty($iid, 'Driver', 'gardena-app');
            @\IPS_SetProperty($iid, 'WriteDriver', 'gardena-dev');
            @\IPS_SetProperty($iid, 'DeviceId', $d['id']);
            @\IPS_SetProperty($iid, 'Serial', (string) ($d['serial'] ?? ''));
            @\IPS_SetProperty($iid, 'Category', $d['category']);
            @\IPS_SetProperty($iid, 'PollInterval', self::DEF_POLL_S);
            @\IPS_SetProperty($iid, 'Armed', false);
            @\IPS_ApplyChanges($iid);
            @\HSGA_Refresh($iid);
            $created[] = ['id' => $iid, 'name' => $d['name'], 'category' => $d['category']];
        }
        return ['ok' => true, 'created' => $created, 'total' => count($devs)];
    }

    /** Application-Key/-Secret der neuen Developer-API (root-only) speichern. */
    private function mgmtSaveDevCreds(array $args): array
    {
        $key    = trim((string) ($args['appKey'] ?? ''));
        $secret = trim((string) ($args['appSecret'] ?? ''));
        if ($key === '' || $secret === '') { return ['ok' => false, 'err' => 'appKey/appSecret fehlt']; }
        $ok = @file_put_contents(self::CRED_FILE, json_encode(['appKey' => $key, 'appSecret' => $secret]));
        @chmod(self::CRED_FILE, 0600);
        // Token-Cache verwerfen, damit neu authentifiziert wird
        @unlink('/var/lib/symcon/scripts/gardena_dev_token.json');
        $this->writeResolved = false; $this->writeInstance = null;
        return ['ok' => $ok !== false, 'file' => self::CRED_FILE];
    }

    /** Seriennummern der bereits importierten Instanzen aus der Legacy-Liste nachtragen (fuer Serien-Match). */
    private function mgmtBackfillSerials(): array
    {
        $drv = DriverFactory::create('gardena-app', ['tokenFile' => self::TOKEN_FILE]);
        if (!$drv instanceof GardenaAppApi) { return ['ok' => false, 'err' => 'kein Treiber']; }
        $bySerial = [];
        foreach ($drv->listDevices() as $d) { $bySerial[(string) $d['id']] = (string) ($d['serial'] ?? ''); }
        $guid = '{2DB05F36-BBD0-AC2E-85A8-369B88CD242F}';
        $done = [];
        foreach (@\IPS_GetInstanceListByModuleID($guid) ?: [] as $iid) {
            $devId = (string) @\IPS_GetProperty($iid, 'DeviceId');
            $ser   = $bySerial[$devId] ?? '';
            if ($ser === '') { continue; }
            @\IPS_SetProperty($iid, 'Serial', $ser);
            if ((string) @\IPS_GetProperty($iid, 'WriteDriver') === '') { @\IPS_SetProperty($iid, 'WriteDriver', 'gardena-dev'); }
            @\IPS_ApplyChanges($iid);
            $done[] = ['id' => $iid, 'serial' => $ser];
        }
        return ['ok' => true, 'updated' => $done];
    }
}

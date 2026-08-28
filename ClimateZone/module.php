<?php

declare(strict_types=1);

/**
 * HomeSuite Klima (HSAC) — eine Klimazone, herstellerunabhaengig.
 *
 * Zwei Anbindungen liegen darunter und verhalten sich nach aussen gleich:
 *
 *   toshiba-cloud    Hersteller-Cloud; lesen ueber REST, schalten ueber einen
 *                    Azure-IoT-Kanal (MQTT/TLS) via scripts/toshiba/toshiba_send.py
 *   generic-climate  ueber die Variablen eines vorhandenen Fremdmoduls (Tado)
 *
 * Wie die uebrigen Domaenen kennt das Modul Scharfschalten (Armed), einen
 * Schatten-Modus fuer den Probebetrieb und den Manuell-Vorrang. Ein Befehl
 * erreicht das Geraet NUR bei armed=true; sonst wird er lediglich vermerkt.
 * So laesst sich eine neue Zone gefahrlos einrichten, bevor sie wirklich
 * schaltet - genau wie es Licht und Beschattung vormachen.
 */

require_once __DIR__ . '/../libs/HomeSuite/autoload.php';

use Hoep\HomeSuite\ActionContext;
use Hoep\HomeSuite\Control;
use Hoep\HomeSuite\ControlContract;
use Hoep\HomeSuite\ContractException;
use Hoep\HomeSuite\EntityModule;
use Hoep\HomeSuite\HAL\DriverFactory;
use Hoep\HomeSuite\HAL\IDriver;
use Hoep\HomeSuite\HAL\IClimate;

// Klassenname = module.json "name" ohne Leerzeichen.
class ClimateZone extends EntityModule
{
    private const TIMER_REFRESH = 'Refresh';
    /**
     * Abfragetakt. Zwei Minuten, bewusst traeger als der Minutentakt der
     * Anzeige: ein Raumklima aendert sich nicht in Sekunden, beide Hersteller
     * drosseln aber bei zu vielen Abfragen. Bei Toshiba laeuft daneben noch das
     * Altskript 33691 im Minutentakt - drei Abfragewege auf dieselbe Cloud
     * haben am 28.08.2026 prompt HTTP 429 ausgeloest. Wird das Altskript
     * abgeloest, kann der Takt wieder herunter.
     */
    private const REFRESH_MS    = 120000;

    private ?IDriver $driverInstance = null;
    private bool $driverResolved = false;

    // ==================================================================
    // Manifest
    // ==================================================================

    protected function entityLabel(): string { return 'Klima'; }

    protected function manifest(): array
    {
        return [
            'domain' => 'climate',
            'title'  => 'Klima',
            'icon'   => 'Snowflake',

            'controls' => [
                ['ident' => 'Power', 'type' => ControlContract::T_SWITCH, 'role' => 'climate:power',
                 'label' => 'Ein/Aus', 'varType' => 0, 'profile' => '~Switch', 'actionable' => true],
                ['ident' => 'Mode', 'type' => ControlContract::T_SELECT, 'role' => 'climate:mode',
                 'label' => 'Betriebsart', 'varType' => 1, 'actionable' => true,
                 'profile' => 'HSAC.Mode',
                 'options' => [
                     ['value' => 0, 'label' => 'Auto'],
                     ['value' => 1, 'label' => 'Kühlen'],
                     ['value' => 2, 'label' => 'Heizen'],
                     ['value' => 3, 'label' => 'Trocknen'],
                     ['value' => 4, 'label' => 'Ventilator'],
                 ]],
                ['ident' => 'Target', 'type' => ControlContract::T_SETPOINT, 'role' => 'climate:target',
                 'label' => 'Solltemperatur', 'varType' => 2, 'unit' => '°C',
                 'profile' => '~Temperature', 'min' => 16, 'max' => 30, 'step' => 1,
                 'actionable' => true],
                ['ident' => 'Fan', 'type' => ControlContract::T_SELECT, 'role' => 'climate:fan',
                 'label' => 'Lüfterstufe', 'varType' => 1, 'actionable' => true,
                 'profile' => 'HSAC.Fan',
                 'options' => [
                     ['value' => 0, 'label' => 'Auto'],
                     ['value' => 1, 'label' => 'Leise'],
                     ['value' => 2, 'label' => 'Sehr niedrig'],
                     ['value' => 3, 'label' => 'Niedrig'],
                     ['value' => 4, 'label' => 'Mittel'],
                     ['value' => 5, 'label' => 'Hoch'],
                     ['value' => 6, 'label' => 'Sehr hoch'],
                 ]],
                ['ident' => 'Swing', 'type' => ControlContract::T_SELECT, 'role' => 'climate:swing',
                 'label' => 'Schwenken', 'varType' => 1, 'actionable' => true,
                 'profile' => 'HSAC.Swing',
                 'options' => [
                     ['value' => 0, 'label' => 'Aus'],
                     ['value' => 1, 'label' => 'Vertikal'],
                     ['value' => 2, 'label' => 'Horizontal'],
                     ['value' => 3, 'label' => 'Beides'],
                 ]],
                ['ident' => 'Preset', 'type' => ControlContract::T_SELECT, 'role' => 'climate:preset',
                 'label' => 'Sonderfunktion', 'varType' => 1, 'actionable' => true,
                 'profile' => 'HSAC.Preset',
                 'options' => [
                     ['value' => 0, 'label' => 'Aus'],
                     ['value' => 1, 'label' => 'High Power'],
                     ['value' => 2, 'label' => 'Silent'],
                     ['value' => 3, 'label' => 'ECO'],
                     ['value' => 4, 'label' => 'Frostschutz'],
                     ['value' => 5, 'label' => 'Sleep'],
                     ['value' => 6, 'label' => 'Floor'],
                     ['value' => 7, 'label' => 'Comfort'],
                 ]],
                ['ident' => 'Ion', 'type' => ControlContract::T_SWITCH, 'role' => 'climate:ion',
                 'label' => 'Ionisierung', 'varType' => 0, 'profile' => '~Switch',
                 'actionable' => true],
                ['ident' => 'SwingH', 'type' => ControlContract::T_SELECT, 'role' => 'climate:swingh',
                 'label' => 'Schwenken waagrecht', 'varType' => 1, 'actionable' => true,
                 'profile' => 'HSAC.SwingH',
                 'options' => [['value' => 0, 'label' => 'Aus'], ['value' => 1, 'label' => 'An']]],
                ['ident' => 'Light', 'type' => ControlContract::T_SWITCH, 'role' => 'climate:light',
                 'label' => 'Displaybeleuchtung', 'varType' => 0, 'profile' => '~Switch',
                 'actionable' => true],
                ['ident' => 'Scheduled', 'type' => ControlContract::T_SWITCH, 'role' => 'climate:schedule',
                 'label' => 'Folgt Zeitplan', 'varType' => 0, 'profile' => '~Switch',
                 'actionable' => true],
                ['ident' => 'Humidity', 'type' => ControlContract::T_REFLECT, 'role' => 'climate:humidity',
                 'label' => 'Luftfeuchte', 'varType' => 2, 'unit' => '%',
                 'profile' => '~Humidity.F', 'actionable' => false],
                ['ident' => 'Indoor', 'type' => ControlContract::T_REFLECT, 'role' => 'climate:indoor',
                 'label' => 'Temperatur innen', 'varType' => 2, 'unit' => '°C',
                 'profile' => '~Temperature', 'actionable' => false],
                ['ident' => 'Outdoor', 'type' => ControlContract::T_REFLECT, 'role' => 'climate:outdoor',
                 'label' => 'Temperatur außen', 'varType' => 2, 'unit' => '°C',
                 'profile' => '~Temperature', 'actionable' => false],
                ['ident' => 'Online', 'type' => ControlContract::T_REFLECT, 'role' => 'climate:online',
                 'label' => 'Online', 'varType' => 0, 'profile' => '~Switch', 'actionable' => false],
                ['ident' => 'Bindung', 'type' => ControlContract::T_REFLECT, 'role' => 'climate:health',
                 'label' => 'Bindung', 'varType' => 3, 'actionable' => false],
            ],

            'managementActions' => [
                ['op' => 'createEntity',    'label' => 'Klimazone anlegen'],
                ['op' => 'renameEntity',    'label' => 'Umbenennen'],
                ['op' => 'deleteEntity',    'label' => 'Loeschen'],
                ['op' => 'configureDriver', 'label' => 'Aktor/Treiber konfigurieren'],
                ['op' => 'getConfig',       'label' => 'Konfiguration lesen (Diagnose)'],
                ['op' => 'validate',        'label' => 'Bindung pruefen (Diagnose)'],
                ['op' => 'driverProbe',     'label' => 'Treiber-Status (Diagnose)'],
                ['op' => 'readState',       'label' => 'Ist-Zustand lesen (Diagnose)'],
                ['op' => 'setArmed',        'label' => 'Scharfschalten / Schatten-Modus'],
            ],

            'capabilities' => [
                'driver'  => (string) $this->cfgVal('driver', ''),
                'climate' => $this->driverCaps(),
            ],
        ];
    }

    // ==================================================================
    // Lebenszyklus
    // ==================================================================

    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyString('Driver', '');
        $this->RegisterPropertyBoolean('Armed', false);   // Schatten-Modus bis zum Umschalten
        $this->RegisterPropertyString('AcId', '');
        $this->RegisterPropertyString('DeviceUniqueId', '');
        $this->RegisterPropertyInteger('TokenVid', 0);
        $this->RegisterPropertyString('Bound', '{}');     // Variablenbindung fuer generic-climate
        $this->RegisterTimer(self::TIMER_REFRESH, 0, 'HSAC_RunTimer($_IPS[\'TARGET\'], "refresh");');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->driverInstance = null;
        $this->driverResolved = false;
        $this->profileAnlegen();
        $this->SetTimerInterval(self::TIMER_REFRESH, $this->cfgVal('driver', '') === '' ? 0 : self::REFRESH_MS);
    }

    public function RunTimer(string $was)
    {
        if ($was === 'refresh') {
            $this->refresh();
        }
    }

    // ==================================================================
    // Bedienen
    // ==================================================================

    protected function applyControl(Control $c, $value, ActionContext $ctx): void
    {
        if (!(bool) $this->cfgVal('armed', false)) {
            $this->SendDebug('HSAC.shadow', $c->ident . '=' . (is_scalar($value) ? (string) $value : '?')
                . ' (Schatten-Modus, nicht gesendet)', 0);
            return;
        }
        $drv = $this->driver();
        if (!($drv instanceof IClimate)) {
            return;
        }
        switch ($c->ident) {
            case 'Power':  $drv->setPower((bool) $value); break;
            case 'Target': $drv->setTarget((float) $value); break;
            case 'Ion':    $drv->setIon((bool) $value); break;
            case 'Mode':   $drv->setMode(self::MODI[(int) $value] ?? ''); break;
            case 'Fan':    $drv->setFan(self::LUEFTER[(int) $value] ?? ''); break;
            case 'Swing':  $drv->setSwing(self::SCHWENK[(int) $value] ?? ''); break;
            case 'Preset': $drv->setPreset(self::SONDER[(int) $value] ?? ''); break;
            case 'SwingH':    $drv->setSwingH(((int) $value) === 1 ? 'on' : 'off'); break;
            case 'Light':     $drv->setLight((bool) $value); break;
            case 'Scheduled': $drv->setScheduled((bool) $value); break;
            default:
                $this->SendDebug('HSAC.apply', $c->ident . ' unbehandelt', 0);
                break;
        }
        // Der Zustand folgt dem Befehl mit Verzoegerung (bei Toshiba rund fuenf
        // Sekunden). Ein sofortiges refresh() wuerde den alten Wert lesen und die
        // optimistische Anzeige wieder zurueckdrehen - deshalb erst beim naechsten
        // Timerlauf.
    }

    /** Anzeige-Idents <-> sprechende Treiberwerte. */
    private const MODI    = [0 => 'auto', 1 => 'cool', 2 => 'heat', 3 => 'dry', 4 => 'fan'];
    private const LUEFTER = [0 => 'auto', 1 => 'quiet', 2 => 'verylow', 3 => 'low',
                             4 => 'medium', 5 => 'high', 6 => 'veryhigh'];
    private const SCHWENK = [0 => 'off', 1 => 'vertical', 2 => 'horizontal', 3 => 'both'];
    private const SONDER  = [0 => 'off', 1 => 'highpower', 2 => 'silent', 3 => 'eco',
                             4 => 'frost', 5 => 'sleep', 6 => 'floor', 7 => 'comfort'];

    // ==================================================================
    // Zustand einlesen
    // ==================================================================

    private function refresh(): void
    {
        $drv = $this->driver();
        if (!($drv instanceof IClimate)) {
            $this->anzeige('Online', false);
            return;
        }
        $s = $drv->readState();
        $this->anzeige('Online', $s->reachable);
        if (!$s->reachable) {
            return;
        }
        $this->anzeige('Power', $s->on);
        if ($s->target > -100)  { $this->anzeige('Target', $s->target); }
        if ($s->indoor > -100)  { $this->anzeige('Indoor', $s->indoor); }
        if ($s->outdoor > -100) { $this->anzeige('Outdoor', $s->outdoor); }
        $this->anzeige('Ion', $s->ion);
        if ($s->humidity >= 0)      { $this->anzeige('Humidity', $s->humidity); }
        if ($s->swingH !== '')      { $this->anzeige('SwingH', $s->swingH === 'on' ? 1 : 0); }
        if ($s->light !== null)     { $this->anzeige('Light', $s->light); }
        if ($s->scheduled !== null) { $this->anzeige('Scheduled', $s->scheduled); }
        $k = static fn(array $tab, string $w) => array_search($w, $tab, true);
        if ($s->mode   !== '' && ($i = $k(self::MODI, $s->mode))    !== false) { $this->anzeige('Mode', $i); }
        if ($s->fan    !== '' && ($i = $k(self::LUEFTER, $s->fan))  !== false) { $this->anzeige('Fan', $i); }
        if ($s->swing  !== '' && ($i = $k(self::SCHWENK, $s->swing))!== false) { $this->anzeige('Swing', $i); }
        if ($s->preset !== '' && ($i = $k(self::SONDER, $s->preset))!== false) { $this->anzeige('Preset', $i); }
        $this->anzeige('Bindung', $this->health($s->reachable));
    }

    /**
     * Anzeige nachziehen OHNE den Bedienpfad.
     *
     * setControlValue() der Basis ruft RequestAction und damit applyControl auf.
     * Wer damit den Ist-Zustand spiegelt, sendet ihn postwendend wieder ans
     * Geraet - und bei jedem Timerlauf erneut. Genau diese Schleife hat den
     * Waechter im ersten Anlauf in den Speicheranschlag getrieben.
     */
    private function anzeige(string $ident, $wert): void
    {
        if (@$this->GetIDForIdent($ident) === false) {
            return;
        }
        try {
            $this->SetValue($ident, $wert);
        } catch (\Throwable $e) {
        }
    }

    private function health(bool $erreichbar): string
    {
        $t = (string) $this->cfgVal('driver', '');
        return ($erreichbar ? 'OK' : 'nicht erreichbar')
             . ' · ' . ($t === '' ? 'ohne Treiber' : $t)
             . ' · ' . ((bool) $this->cfgVal('armed', false) ? 'scharf' : 'Schatten-Modus');
    }

    // ==================================================================
    // Treiber
    // ==================================================================

    // protected, nicht private: EntityModule deklariert driver() bereits
    // protected. Eine engere Sichtbarkeit ist in PHP ein Ladefehler - und
    // Symcon ueberspringt ein Modul, dessen Datei nicht laedt, wortlos.
    protected function driver(): ?IDriver
    {
        if ($this->driverResolved) {
            return $this->driverInstance;
        }
        $this->driverResolved = true;
        $id = (string) $this->cfgVal('driver', '');
        if ($id === '' || !DriverFactory::has($id)) {
            return $this->driverInstance = null;
        }
        try {
            $this->driverInstance = DriverFactory::create($id, $this->treiberConfig());
        } catch (\Throwable $e) {
            $this->LogMessage('Treiber ' . $id . ' nicht nutzbar: ' . $e->getMessage(), KL_WARNING);
            $this->driverInstance = null;
        }
        return $this->driverInstance;
    }

    private function treiberConfig(): array
    {
        $gebunden = json_decode((string) $this->ReadPropertyString('Bound'), true);
        return array_merge(is_array($gebunden) ? $gebunden : [], [
            'acId'           => (string) $this->ReadPropertyString('AcId'),
            'deviceUniqueId' => (string) $this->ReadPropertyString('DeviceUniqueId'),
            'tokenVid'       => (int) $this->ReadPropertyInteger('TokenVid'),
        ]);
    }

    private function driverCaps(): array
    {
        $d = $this->driver();
        return ($d instanceof IClimate) ? $d->capabilities() : [];
    }

    private function cfgVal(string $schluessel, $vorgabe)
    {
        switch ($schluessel) {
            case 'driver': return $this->ReadPropertyString('Driver');
            case 'armed':  return $this->ReadPropertyBoolean('Armed');
        }
        return $vorgabe;
    }

    // ==================================================================
    // Verwaltung
    // ==================================================================

    protected function mgmt(string $op, array $args, array $ctx): array
    {
        switch ($op) {
            case 'configureDriver':
                return $this->mgmtConfigureDriver($args, $ctx);

            case 'getConfig':
                return ['ok' => true, 'config' => [
                    'driver'         => $this->ReadPropertyString('Driver'),
                    'armed'          => $this->ReadPropertyBoolean('Armed'),
                    'acId'           => $this->ReadPropertyString('AcId'),
                    'deviceUniqueId' => $this->ReadPropertyString('DeviceUniqueId'),
                    'tokenVid'       => $this->ReadPropertyInteger('TokenVid'),
                    'bound'          => json_decode($this->ReadPropertyString('Bound'), true),
                ]];

            case 'validate':
                $d = $this->driver();
                $probleme = [];
                if ($this->ReadPropertyString('Driver') === '') { $probleme[] = 'kein Treiber gewaehlt'; }
                if (!($d instanceof IClimate))                   { $probleme[] = 'Treiber nicht ladbar'; }
                $erreichbar = ($d instanceof IClimate) && $d->readState()->reachable;
                if ($d instanceof IClimate && !$erreichbar)      { $probleme[] = 'Geraet nicht erreichbar'; }
                return ['ok' => true, 'health' => $this->health($erreichbar),
                        'issues' => $probleme, 'config' => $this->treiberConfig()];

            case 'driverProbe':
                $d = $this->driver();
                return ['ok' => true, 'driverActive' => $d instanceof IClimate,
                        'caps' => $this->driverCaps()];

            case 'readState':
                $d = $this->driver();
                return $d instanceof IClimate
                    ? ['ok' => true, 'state' => $d->readState()->toArray(),
                       'armed' => $this->ReadPropertyBoolean('Armed')]
                    : ['ok' => false, 'error' => 'kein Treiber'];

            case 'setArmed':
                @\IPS_SetProperty($this->InstanceID, 'Armed', (bool) ($args['armed'] ?? false));
                @\IPS_ApplyChanges($this->InstanceID);
                return ['ok' => true, 'armed' => $this->ReadPropertyBoolean('Armed')];

            default:
                return parent::mgmt($op, $args, $ctx);
        }
    }

    /**
     * Treiber und Bindung setzen. Schreibt NUR Properties, schaltet nichts.
     * armed bleibt unberuehrt - eine neu eingerichtete Zone bleibt im
     * Schatten-Modus, bis jemand sie ausdruecklich scharf stellt.
     */
    private function mgmtConfigureDriver(array $args, array $ctx): array
    {
        $treiber = (string) ($args['driver'] ?? '');
        if (!in_array($treiber, ['toshiba-cloud', 'tado-cloud', 'generic-climate'], true)) {
            throw new ContractException('unbekannter Treiber: ' . $treiber);
        }
        if ($treiber === 'toshiba-cloud') {
            foreach (['acId', 'deviceUniqueId'] as $pflicht) {
                if ((string) ($args[$pflicht] ?? '') === '') {
                    throw new ContractException('toshiba-cloud braucht ' . $pflicht);
                }
            }
        }
        if (!empty($ctx['dryrun'])) {
            return ['ok' => true, 'dryrun' => true, 'driver' => $treiber];
        }
        @\IPS_SetProperty($this->InstanceID, 'Driver', $treiber);
        @\IPS_SetProperty($this->InstanceID, 'AcId', (string) ($args['acId'] ?? ''));
        @\IPS_SetProperty($this->InstanceID, 'DeviceUniqueId', (string) ($args['deviceUniqueId'] ?? ''));
        @\IPS_SetProperty($this->InstanceID, 'TokenVid', (int) ($args['tokenVid'] ?? 0));
        if (isset($args['bound']) && is_array($args['bound'])) {
            @\IPS_SetProperty($this->InstanceID, 'Bound', json_encode($args['bound']));
        }
        @\IPS_ApplyChanges($this->InstanceID);
        $d = $this->driver();
        return ['ok' => true, 'driver' => $treiber, 'driverActive' => $d instanceof IClimate,
                'caps' => $this->driverCaps(), 'armed' => $this->ReadPropertyBoolean('Armed')];
    }

    /** Profile mit Beschriftungen - sonst zeigt das Frontend nackte Zahlen. */
    private function profileAnlegen(): void
    {
        $mk = function (string $name, array $paare): void {
            if (!@\IPS_VariableProfileExists($name)) {
                @\IPS_CreateVariableProfile($name, 1);
            }
            foreach ($paare as $wert => $bez) {
                @\IPS_SetVariableProfileAssociation($name, $wert, $bez, '', -1);
            }
        };
        $mk('HSAC.Mode',   [0 => 'Auto', 1 => 'Kühlen', 2 => 'Heizen', 3 => 'Trocknen', 4 => 'Ventilator']);
        $mk('HSAC.Fan',    [0 => 'Auto', 1 => 'Leise', 2 => 'Sehr niedrig', 3 => 'Niedrig',
                            4 => 'Mittel', 5 => 'Hoch', 6 => 'Sehr hoch']);
        $mk('HSAC.Swing',  [0 => 'Aus', 1 => 'Vertikal', 2 => 'Horizontal', 3 => 'Beides']);
        $mk('HSAC.SwingH', [0 => 'Aus', 1 => 'An']);
        $mk('HSAC.Preset', [0 => 'Aus', 1 => 'High Power', 2 => 'Silent', 3 => 'ECO',
                            4 => 'Frostschutz', 5 => 'Sleep', 6 => 'Floor', 7 => 'Comfort']);
    }
}

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
     * Abfragetakt eine Minute.
     *
     * Kurz zwischenzeitlich auf zwei Minuten gesetzt: neben den Modulinstanzen
     * lief bei Toshiba noch das Altskript 33691 im Minutentakt, und drei
     * Abfragewege auf dieselbe Cloud loesten am 28.08.2026 HTTP 429 aus. Seit
     * das Altskript stillgelegt ist, ist dieses Modul der einzige Weg - der
     * Takt kann wieder herunter.
     */
    private const REFRESH_MS    = 60000;

    /** Wochenplan-Takt. Eine Minute reicht: feiner als der Editor Slots setzen kann. */
    private const TIMER_PLAN = 'PlanTick';
    private const PLAN_MS    = 60000;

    /**
     * Wochenplan-Varianten (Achse „Praesenz"). BEWUSST gleich benannt wie bei der
     * Heizung: derselbe Editor (weekedit-hm), dieselbe Denkweise, und ein Haus
     * laesst sich in einem Rutsch auf „Abgesenkt" stellen.
     */
    private const PRESENCE_VARIANTS = ['Normal', 'Erweitert', 'Abgesenkt'];

    /**
     * Betriebsart JE SLOT. Sie steht NEBEN dem Slot-Wert, nicht darin - der Wert
     * bleibt die Zieltemperatur, an der Kurve, Farbskala und Pillen des Editors
     * rechnen. 'inherit' heisst: nimm die Grundbetriebsart der Zone (Eigenschaft
     * DefaultMode). So braucht ein einfacher Plan gar keine Modusangabe.
     */
    private const SLOT_MODES = ['inherit', 'off', 'auto', 'cool', 'heat', 'dry', 'fan'];

    private const TARGET_MIN = 16.0;
    private const TARGET_MAX = 30.0;

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
                     ['value' => 8, 'label' => 'CDU Silent 2'],
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
                ['ident' => 'PowerLevel', 'type' => ControlContract::T_SELECT, 'role' => 'climate:powerlevel',
                 'label' => 'Leistungsstufe', 'varType' => 1, 'actionable' => true,
                 'profile' => 'HSAC.PowerLevel',
                 'options' => [['value' => 50, 'label' => '50 %'],
                               ['value' => 75, 'label' => '75 %'],
                               ['value' => 100, 'label' => '100 %']]],
                ['ident' => 'Running', 'type' => ControlContract::T_REFLECT, 'role' => 'climate:running',
                 'label' => 'Laeuft gerade', 'varType' => 0, 'profile' => '~Switch', 'actionable' => false],
                ['ident' => 'Presence', 'type' => ControlContract::T_REFLECT, 'role' => 'climate:presence',
                 'label' => 'Anwesenheit', 'varType' => 3, 'actionable' => false],
                ['ident' => 'OverrideUntil', 'type' => ControlContract::T_REFLECT, 'role' => 'climate:override',
                 'label' => 'Handbetrieb bis', 'varType' => 1, 'profile' => '~UnixTimestamp',
                 'actionable' => false],
                ['ident' => 'NextChange', 'type' => ControlContract::T_REFLECT, 'role' => 'climate:nextchange',
                 'label' => 'Naechste Aenderung', 'varType' => 1, 'profile' => '~UnixTimestamp',
                 'actionable' => false],
                ['ident' => 'Fireplace', 'type' => ControlContract::T_SELECT, 'role' => 'climate:fireplace',
                 'label' => 'Kaminmodus', 'varType' => 1, 'actionable' => true,
                 'profile' => 'HSAC.Fireplace',
                 'options' => [['value' => 0, 'label' => 'Aus'],
                               ['value' => 1, 'label' => 'Kamin 1'],
                               ['value' => 2, 'label' => 'Kamin 2']]],
                ['ident' => 'SelfClean', 'type' => ControlContract::T_REFLECT, 'role' => 'climate:selfclean',
                 'label' => 'Selbstreinigung', 'varType' => 0, 'profile' => '~Switch', 'actionable' => false],
                ['ident' => 'OpenWindow', 'type' => ControlContract::T_REFLECT, 'role' => 'climate:window',
                 'label' => 'Fenster offen', 'varType' => 0, 'profile' => '~Window', 'actionable' => false],
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

                // ---- Symcon-seitiger Wochenplan ------------------------------
                // 'Scheduled' daneben ist etwas anderes: das meldet der HERSTELLER
                // (tado folgt seinem Cloud-Plan). 'PlanMode' ist unser Plan.
                ['ident' => 'PlanMode', 'type' => ControlContract::T_SELECT, 'role' => 'climate:planmode',
                 'label' => 'Steuerung', 'varType' => 1, 'actionable' => true,
                 'profile' => 'HSAC.PlanMode',
                 'options' => [['value' => 0, 'label' => 'Manuell'],
                               ['value' => 1, 'label' => 'Zeitplan']]],
                ['ident' => 'SchedVariant', 'type' => ControlContract::T_SELECT, 'role' => 'climate:variant',
                 'label' => 'Profil', 'varType' => 1, 'actionable' => true,
                 'profile' => 'HSAC.Variant',
                 'options' => [['value' => 0, 'label' => 'Normal'],
                               ['value' => 1, 'label' => 'Erweitert'],
                               ['value' => 2, 'label' => 'Abgesenkt']]],
                ['ident' => 'PlanNext', 'type' => ControlContract::T_REFLECT, 'role' => 'climate:plannext',
                 'label' => 'Plan: naechster Wechsel', 'varType' => 1,
                 'profile' => '~UnixTimestamp', 'actionable' => false],
                ['ident' => 'PlanOverride', 'type' => ControlContract::T_REFLECT, 'role' => 'climate:planoverride',
                 'label' => 'Handbetrieb bis', 'varType' => 1,
                 'profile' => '~UnixTimestamp', 'actionable' => false],
            ],

            // ---- Profil-Typ: Wochenplan (2 Achsen Praesenz x Wochentag) ----
            // Gleiche Form wie HeatingZone.roomProfile, damit derselbe Editor
            // greift. Der EINZIGE Unterschied ist das Slot-Feld 'mode'.
            'profileTypes' => [
                'climateProfile' => [
                    'label' => 'Wochenplan',
                    'axes'  => [
                        'presence' => self::PRESENCE_VARIANTS,
                        'weekday'  => ['MO', 'DI', 'MI', 'DO', 'FR', 'SA', 'SO'],
                    ],
                    'slot'  => [
                        'end'  => 'HH:MM',
                        'val'  => ['type' => 'float', 'min' => self::TARGET_MIN, 'max' => self::TARGET_MAX],
                        'mode' => ['type' => 'enum', 'values' => self::SLOT_MODES],
                    ],
                    'rules'  => ['lastSlotEnd' => '24:00', 'ascending' => true],
                    'editor' => 'weekedit-hm',
                ],
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
                ['op' => 'getSchedule',       'label' => 'Wochenplan lesen'],
                ['op' => 'updateProfile',     'label' => 'Wochenplan bearbeiten'],
                ['op' => 'setActivePresence', 'label' => 'Profil waehlen'],
                ['op' => 'setPlanMode',       'label' => 'Manuell / Zeitplan umschalten'],
                ['op' => 'setDefaultMode',    'label' => 'Grundbetriebsart setzen'],
                ['op' => 'planPreview',       'label' => 'Was wuerde der Plan jetzt tun? (nur lesen)'],
            ],

            'capabilities' => [
                'driver'       => (string) $this->cfgVal('driver', ''),
                'climate'      => $this->driverCaps(),
                'scheduleMode' => 'server',   // kein Geraet hier kann unseren Plan selbst fahren
                'hasPresence'  => true,
                'slotModes'    => self::SLOT_MODES,
                'defaultMode'  => $this->defaultMode(),
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
        // Grundbetriebsart fuer Slots ohne eigene Angabe ('inherit').
        $this->RegisterPropertyString('DefaultMode', 'auto');
        $this->RegisterTimer(self::TIMER_REFRESH, 0, 'HSAC_RunTimer($_IPS[\'TARGET\'], "refresh");');
        $this->RegisterTimer(self::TIMER_PLAN, 0, 'HSAC_RunTimer($_IPS[\'TARGET\'], "plan");');
    }

    public function ApplyChanges()
    {
        // ZUERST die Variablenprofile: parent::ApplyChanges() legt die Controls an,
        // und ein Auswahl-Control ohne sein Profil wird gar nicht erst erzeugt.
        // Genau daran fehlten bei der ersten verarbeiteten Zone PlanMode und
        // SchedVariant - die uebrigen fanden die Profile dann schon vor.
        $this->profileAnlegen();
        parent::ApplyChanges();
        $this->driverInstance = null;
        $this->driverResolved = false;
        $this->sichtbarkeitPflegen();
        $c = $this->driverCaps();
        $takt = (int) ($c['pollSeconds'] ?? 60);
        $this->SetTimerInterval(self::TIMER_REFRESH,
            $this->cfgVal('driver', '') === '' ? 0 : max(30, $takt) * 1000);
        // Der Plan-Takt laeuft auch im Schatten-Modus: dort rechnet er nur und
        // schreibt PlanNext, damit man VOR dem Scharfschalten sieht, was kaeme.
        $this->SetTimerInterval(self::TIMER_PLAN,
            $this->cfgVal('driver', '') === '' ? 0 : self::PLAN_MS);
    }

    public function RunTimer(string $was)
    {
        if ($was === 'refresh') {
            $this->refresh();
            return;
        }
        if ($was === 'plan') {
            $this->planTick();
        }
    }

    // ==================================================================
    // Bedienen
    // ==================================================================

    protected function applyControl(Control $c, $value, ActionContext $ctx): void
    {
        // Diese beiden steuern NICHT das Geraet, sondern uns. Sie muessen also
        // auch im Schatten-Modus wirken - sonst liesse sich eine Zone gar nicht
        // erst auf Zeitplan vorbereiten.
        if ($c->ident === 'PlanMode' || $c->ident === 'SchedVariant') {
            $this->anzeige($c->ident, (int) $value);
            $this->SetBuffer('PlanOverrideBis', '0');
            $this->anzeige('PlanOverride', 0);
            $this->planTick();
            return;
        }
        // Handbetrieb-Vorrang: greift jemand bei laufendem Zeitplan von Hand ein,
        // gilt seine Entscheidung bis zum naechsten Slotwechsel. Ohne das wuerde
        // der naechste Takt (also spaetestens in einer Minute) alles zurueckdrehen.
        if ($this->planActive() && in_array($c->ident, ['Power', 'Target', 'Mode'], true)) {
            $bis = $this->naechsterWechsel(time());
            if ($bis > 0) {
                $this->SetBuffer('PlanOverrideBis', (string) $bis);
                $this->anzeige('PlanOverride', $bis);
            }
        }
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
            case 'PowerLevel': $drv->setPowerLevel((int) $value); break;
            case 'Fireplace':  $drv->setFireplace(self::KAMIN[(int) $value] ?? ''); break;
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
                             4 => 'frost', 5 => 'sleep', 6 => 'floor', 7 => 'comfort',
                             8 => 'silent2'];
    private const KAMIN   = [0 => 'off', 1 => 'kamin1', 2 => 'kamin2'];

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
        /* RUECKFALL bei Stoerung.
         *
         * Beide Anbieter drosseln, wenn man zu oft fragt - und wer bei einer
         * Drosselung im gleichen Takt weiterfragt, verlaengert sie. Nach einem
         * misslungenen Lesen wird die Pause deshalb verdoppelt, von einer
         * fuenf Minuten bis zu einer Stunde; das erste gelungene Lesen setzt
         * sie zurueck. Am 29.08.2026 hat tado ab 23 Uhr mit HTTP 429
         * dichtgemacht, und das Modul hat sieben Stunden lang unbeirrt
         * weitergefragt.
         */
        $pause = (int) $this->GetBuffer('Pause');
        $bis   = (int) $this->GetBuffer('PauseBis');
        if ($bis > time()) {
            return;
        }
        $s = $drv->readState();
        if (!$s->reachable) {
            $pause = $pause > 0 ? min(3600, $pause * 2) : 300;
            $this->SetBuffer('Pause', (string) $pause);
            $this->SetBuffer('PauseBis', (string) (time() + $pause));
            $this->anzeige('Online', false);
            return;
        }
        if ($pause > 0) {
            $this->SetBuffer('Pause', '0');
            $this->SetBuffer('PauseBis', '0');
        }
        $this->anzeige('Online', true);
        $this->anzeige('Power', $s->on);
        if ($s->target > -100)  { $this->anzeige('Target', $s->target); }
        if ($s->indoor > -100)  { $this->anzeige('Indoor', $s->indoor); }
        if ($s->outdoor > -100) { $this->anzeige('Outdoor', $s->outdoor); }
        $this->anzeige('Ion', $s->ion);
        if ($s->humidity >= 0)      { $this->anzeige('Humidity', $s->humidity); }
        if ($s->swingH !== '')      { $this->anzeige('SwingH', $s->swingH === 'on' ? 1 : 0); }
        if ($s->light !== null)     { $this->anzeige('Light', $s->light); }
        if ($s->scheduled !== null) { $this->anzeige('Scheduled', $s->scheduled); }
        if ($s->powerLevel > 0)     { $this->anzeige('PowerLevel', $s->powerLevel); }
        if ($s->running !== null)   { $this->anzeige('Running', $s->running); }
        if ($s->presence !== '')    { $this->anzeige('Presence', $s->presence === 'home' ? 'Anwesend' : 'Abwesend'); }
        if ($s->selfClean !== null) { $this->anzeige('SelfClean', $s->selfClean); }
        if ($s->fireplace !== '')   { $k = array_search($s->fireplace, self::KAMIN, true);
                                      if ($k !== false) { $this->anzeige('Fireplace', $k); } }
        if ($s->openWindow !== null){ $this->anzeige('OpenWindow', $s->openWindow); }
        $this->anzeige('OverrideUntil', $s->overrideUntil);
        $this->anzeige('NextChange', $s->nextChange);
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

    /**
     * Faehigkeiten - GEPUFFERT.
     *
     * Bei tado kostet capabilities() eine Netzanfrage, und manifest() ruft es
     * bei jedem Zugriff. Mit fuenf Zonen im Minutentakt waren das doppelt so
     * viele Anfragen wie noetig; tado hat am 29.08.2026 ab etwa 23 Uhr mit
     * HTTP 429 dichtgemacht, und die Zonen standen sieben Stunden still.
     *
     * Was ein Geraet kann, aendert sich nicht im Minutentakt - ein Tag Puffer
     * ist reichlich. Bei einem Treiberwechsel wird er in configureDriver
     * verworfen, sonst zeigte die Anzeige die Faehigkeiten des alten Treibers.
     */
    private function driverCaps(bool $frisch = false): array
    {
        if (!$frisch) {
            $p = json_decode((string) $this->GetBuffer('Caps'), true);
            if (is_array($p) && isset($p['t'], $p['c']) && (time() - (int) $p['t']) < 86400) {
                return is_array($p['c']) ? $p['c'] : [];
            }
        }
        $d = $this->driver();
        $c = ($d instanceof IClimate) ? $d->capabilities() : [];
        // Nur echte Antworten ablegen. Ein Treiber, dessen Abfrage nicht
        // durchkam, liefert einen Notbehelf - der darf nicht 24 Stunden lang
        // als Wahrheit gelten und dem Nutzer Stufen anbieten, die sein Geraet
        // nicht kennt (siehe _echt in TadoCloud::capabilities).
        if ($c !== [] && ($c['_echt'] ?? true)) {
            $this->SetBuffer('Caps', json_encode(['t' => time(), 'c' => $c]));
        }
        return $c;
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

            case 'getSchedule':
                return $this->mgmtGetSchedule($args);

            case 'updateProfile':
                return $this->mgmtUpdateProfile($args, $ctx);

            case 'setActivePresence':
                $i = (int) ($args['presence'] ?? $args['variant'] ?? 0);
                if ($i < 0 || $i >= count(self::PRESENCE_VARIANTS)) {
                    return ['ok' => false, 'error' => 'presence 0..' . (count(self::PRESENCE_VARIANTS) - 1)];
                }
                $this->anzeige('SchedVariant', $i);
                $this->SetBuffer('PlanOverrideBis', '0');
                $this->anzeige('PlanOverride', 0);
                $this->planTick();
                return ['ok' => true, 'presence' => $i, 'variant' => self::PRESENCE_VARIANTS[$i]];

            case 'setPlanMode':
                $m = (int) ($args['mode'] ?? 0);
                if ($m !== 0 && $m !== 1) {
                    return ['ok' => false, 'error' => 'mode 0 (Manuell) oder 1 (Zeitplan)'];
                }
                $this->anzeige('PlanMode', $m);
                $this->SetBuffer('PlanOverrideBis', '0');
                $this->anzeige('PlanOverride', 0);
                $this->planTick();
                return ['ok' => true, 'planMode' => $m];

            case 'setDefaultMode':
                $m = (string) ($args['mode'] ?? '');
                if (!in_array($m, ['off', 'auto', 'cool', 'heat', 'dry', 'fan'], true)) {
                    return ['ok' => false, 'error' => 'mode: off|auto|cool|heat|dry|fan'];
                }
                @\IPS_SetProperty($this->InstanceID, 'DefaultMode', $m);
                @\IPS_ApplyChanges($this->InstanceID);
                return ['ok' => true, 'defaultMode' => $this->defaultMode()];

            case 'planPreview':
                $ts   = (int) ($args['ts'] ?? time());
                $soll = $this->planSoll($ts);
                return ['ok' => true, 'ts' => $ts,
                    'variant'     => self::PRESENCE_VARIANTS[$this->activeVariantIndex()] ?? '',
                    'planMode'    => $this->planActive() ? 1 : 0,
                    'armed'       => (bool) $this->cfgVal('armed', false),
                    'overrideBis' => $this->overrideBis(),
                    'defaultMode' => $this->defaultMode(),
                    'soll'        => $soll];

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
        $this->driverInstance = null;
        $this->driverResolved = false;
        $this->SetBuffer('Caps', '');            // Treiberwechsel: Puffer verwerfen
        $this->sichtbarkeitPflegen();
        $d = $this->driver();
        return ['ok' => true, 'driver' => $treiber, 'driverActive' => $d instanceof IClimate,
                'caps' => $this->driverCaps(), 'armed' => $this->ReadPropertyBoolean('Armed')];
    }

    /**
     * Nur zeigen, was das Geraet kann.
     *
     * Das Manifest legt alle Bedienelemente an, damit ein Treiberwechsel keine
     * Variable und keine Archivreihe verliert. Ein Toshiba misst aber keine
     * Luftfeuchte und tado kennt keine Leistungsstufe - stehen diese Felder
     * sichtbar da, zeigen sie 0,0 % oder eine leere Auswahl, und das liest sich
     * wie ein Messwert. Was capabilities() ausschliesst, wird deshalb
     * ausgeblendet: die Variable bleibt samt Historie bestehen, sie taucht nur
     * nicht mehr in der Anzeige auf. Wechselt der Treiber, erscheint sie wieder.
     */
    private function sichtbarkeitPflegen(): void
    {
        $c = $this->driverCaps();
        if ($c === []) {
            return;   // ohne Treiber nichts verstecken
        }
        $leer = static fn($x): bool => !is_array($x) || $x === [];
        $verbergen = [
            'Humidity'      => empty($c['humidity']),
            'Indoor'        => empty($c['indoor']),
            'Outdoor'       => empty($c['outdoor']),
            'Ion'           => empty($c['ion']),
            'Light'         => empty($c['light']),
            'Running'       => empty($c['running']),
            'Presence'      => empty($c['presence']),
            'SelfClean'     => empty($c['selfClean']),
            'PowerLevel'    => $leer($c['powerLevels'] ?? []),
            'SwingH'        => $leer($c['swingsH'] ?? []),
            'Swing'         => $leer($c['swings'] ?? []),
            'Fan'           => $leer($c['fans'] ?? []),
            'Preset'        => $leer($c['presets'] ?? []),
            'Fireplace'     => !is_array($c['fireplaces'] ?? null) || ($c['fireplaces'] ?? []) === [],
            'Scheduled'     => empty($c['schedule']),
            'OverrideUntil' => empty($c['schedule']),
            'NextChange'    => empty($c['schedule']),
            'OpenWindow'    => empty($c['schedule']),   // nur tado meldet Fenster
            // PlanOverride ist nur waehrend eines Handbetriebs interessant.
            'PlanOverride'  => false,
        ];
        foreach ($verbergen as $ident => $weg) {
            $vid = @$this->GetIDForIdent($ident);
            if ($vid === false || $vid <= 0) {
                continue;
            }
            @\IPS_SetHidden($vid, (bool) $weg);
        }
    }

    /** Profile mit Beschriftungen - sonst zeigt das Frontend nackte Zahlen. */
    // ==================================================================
    // Wochenplan (Symcon-seitig)
    // ==================================================================

    private function mgmtGetSchedule(array $args): array
    {
        $variant = (string) ($args['variant'] ?? self::PRESENCE_VARIANTS[0]);
        $week = [];
        for ($d = 0; $d < 7; $d++) {
            $week[$d] = $this->schedules()->getSlots($variant, $d);
        }
        return ['ok' => true, 'variant' => $variant, 'week' => $week,
            'activeVariant' => self::PRESENCE_VARIANTS[$this->activeVariantIndex()] ?? '',
            'variants'      => self::PRESENCE_VARIANTS,
            'slotModes'     => self::SLOT_MODES,
            'defaultMode'   => $this->defaultMode(),
            'planMode'      => $this->planActive() ? 1 : 0,
            'sunEvents'     => null,
            'anchors'       => []];
    }

    /**
     * Einen Wochentag einer Variante schreiben. Gleiche Form wie bei Heizung und
     * Bewaesserung; zusaetzlich wird je Slot die Betriebsart mitgefuehrt.
     */
    private function mgmtUpdateProfile(array $args, array $ctx): array
    {
        $variant = (string) ($args['variant'] ?? self::PRESENCE_VARIANTS[0]);
        if (!in_array($variant, self::PRESENCE_VARIANTS, true)) {
            throw new ContractException('unbekannte Variante: ' . $variant);
        }
        $day = (int) ($args['day'] ?? -1);
        if ($day < 0 || $day > 6) {
            throw new ContractException('day muss 0..6 sein');
        }
        $slots = (isset($args['slots']) && is_array($args['slots'])) ? $args['slots'] : [];
        $clean = [];
        foreach ($slots as $sl) {
            if (!is_array($sl) || !isset($sl['end'])) {
                continue;
            }
            $entry = [
                'end' => max(1, min(1440, (int) $sl['end'])),
                'val' => max(self::TARGET_MIN, min(self::TARGET_MAX, (float) ($sl['val'] ?? self::TARGET_MIN))),
            ];
            $m = (string) ($sl['mode'] ?? '');
            if ($m !== '' && in_array($m, self::SLOT_MODES, true) && $m !== 'inherit') {
                $entry['mode'] = $m;      // 'inherit' wird NICHT gespeichert - das ist die Abwesenheit einer Angabe
            }
            $clean[] = $entry;
        }
        if (!empty($ctx['dryrun'])) {
            return ['ok' => true, 'dryrun' => true, 'variant' => $variant, 'day' => $day, 'slots' => $clean];
        }
        $this->schedules()->setSlots($variant, $day, $clean);
        $this->planTick();
        return ['ok' => true, 'variant' => $variant, 'day' => $day,
                'slots' => $this->schedules()->getSlots($variant, $day)];
    }

    /** Varianten-Achse fuer den generischen Zeitplan-Teil der Basis. */
    protected function scheduleVariants(): array
    {
        return self::PRESENCE_VARIANTS;
    }

    protected function activeVariantIndex(): int
    {
        $vid = @$this->GetIDForIdent('SchedVariant');
        $i   = ($vid !== false && $vid > 0) ? (int) @\GetValue($vid) : 0;
        return ($i >= 0 && $i < count(self::PRESENCE_VARIANTS)) ? $i : 0;
    }

    /** Grundbetriebsart der Zone (fuer Slots mit 'inherit'). */
    private function defaultMode(): string
    {
        // @ ist hier Absicht und kein Schluderigkeit: eine in Create() neu
        // hinzugekommene Eigenschaft existiert fuer BESTEHENDE Instanzen erst
        // nach einem Modul-Reload. Bis dahin gibt ReadPropertyString eine
        // PHP-WARNUNG aus - kein Throwable, sondern Text, der dem JSON der
        // Verwaltungs-Antwort vorangestellt wird und sie unlesbar macht.
        $m = trim((string) @$this->ReadPropertyString('DefaultMode'));
        return in_array($m, ['off', 'auto', 'cool', 'heat', 'dry', 'fan'], true) ? $m : 'auto';
    }

    /** Laeuft fuer diese Zone gerade unser Wochenplan? */
    private function planActive(): bool
    {
        $vid = @$this->GetIDForIdent('PlanMode');
        return ($vid !== false && $vid > 0) && ((int) @\GetValue($vid) === 1);
    }

    /** Steht gerade ein Handbetrieb-Vorrang? Liefert dessen Ende oder 0. */
    private function overrideBis(): int
    {
        $b = (int) $this->GetBuffer('PlanOverrideBis');
        if ($b > 0 && $b <= time()) {
            $this->SetBuffer('PlanOverrideBis', '0');
            $this->anzeige('PlanOverride', 0);
            return 0;
        }
        return $b;
    }

    /**
     * Zeitpunkt des naechsten Slotwechsels ab $ts (absoluter Unix-Zeitstempel).
     * Laeuft der Tag aus, ist es Mitternacht - der naechste Tag hat eigene Slots.
     */
    private function naechsterWechsel(int $ts): int
    {
        $variant = self::PRESENCE_VARIANTS[$this->activeVariantIndex()] ?? self::PRESENCE_VARIANTS[0];
        $day     = (int) date('N', $ts) - 1;
        $minuten = ((int) date('G', $ts)) * 60 + (int) date('i', $ts);
        $tagAnfang = (int) strtotime(date('Y-m-d 00:00:00', $ts));
        foreach ($this->schedules()->getSlots($variant, $day) as $slot) {
            if ($minuten < (int) $slot['end']) {
                return $tagAnfang + ((int) $slot['end']) * 60;
            }
        }
        return $tagAnfang + 86400;
    }

    /**
     * Was soll JETZT gelten? Liefert null, wenn fuer den Tag kein Plan hinterlegt
     * ist - dann fasst der Takt nichts an (ein leerer Plan ist kein Befehl,
     * schon gar kein "alles aus").
     *
     * @return array{mode:string,target:?float,next:int}|null
     */
    private function planSoll(int $ts): ?array
    {
        $variant = self::PRESENCE_VARIANTS[$this->activeVariantIndex()] ?? self::PRESENCE_VARIANTS[0];
        $slot    = $this->schedules()->evalSlot($ts, $variant);
        if (!is_array($slot)) {
            return null;
        }
        $modus = (string) ($slot['mode'] ?? 'inherit');
        if ($modus === 'inherit' || $modus === '') {
            $modus = $this->defaultMode();
        }
        if (!in_array($modus, self::SLOT_MODES, true)) {
            $modus = $this->defaultMode();
        }
        $ziel = null;
        if ($slot['val'] !== null && is_numeric($slot['val'])) {
            $ziel = max(self::TARGET_MIN, min(self::TARGET_MAX, (float) $slot['val']));
        }
        return ['mode' => $modus, 'target' => $ziel, 'next' => $this->naechsterWechsel($ts)];
    }

    /**
     * Ein Takt des Wochenplans.
     *
     * Reihenfolge mit Absicht: erst rechnen und ANZEIGEN, dann erst schalten.
     * So sieht man im Schatten-Modus und bei Handbetrieb-Vorrang trotzdem, was
     * der Plan gerade vorhaette - ohne dass etwas geschaltet wird.
     */
    /**
     * Eine Plan-Entscheidung festhalten - aber nur, wenn sie sich geaendert hat.
     *
     * planTick() laeuft im Minutentakt. Ein Eintrag je Takt haette den Ringpuffer in
     * einer knappen Stunde gefuellt und damit wertlos gemacht: interessant ist der
     * WECHSEL, nicht die Wiederholung. Verglichen wird ueber eine Signatur aus Modus,
     * Sollwert und Scharfzustand; der Vergleichswert liegt im Laufzeit-Status, ueberlebt
     * also einen Reload nicht - dann gibt es einen Eintrag zuviel, und das ist die
     * harmlosere Richtung.
     */
    private function planMerken(array $soll, bool $real): void
    {
        $ziel = ($soll['target'] === null) ? '-' : (string) round((float) $soll['target'], 1);
        $sig  = $soll['mode'] . '|' . $ziel . '|' . ($real ? '1' : '0');
        $rt   = $this->readRt();
        if ((string) ($rt['lastPlanSig'] ?? '') === $sig) {
            return;
        }
        $rt['lastPlanSig'] = $sig;
        $this->writeRt($rt);

        $was = ($soll['mode'] === 'off')
            ? 'ausschalten'
            : ($soll['mode'] . ($ziel === '-' ? '' : ' auf ' . $ziel . ' C'));
        $w = ['modus' => $soll['mode']];
        if ($soll['target'] !== null) { $w['soll_c'] = round((float) $soll['target'], 1); }
        $ist = $this->wertVon('Target', null);
        if (is_numeric($ist)) { $w['ist_soll_c'] = round((float) $ist, 1); }
        $w['geraet_an'] = (bool) $this->wertVon('Power', false);
        $this->entscheidungMerken($was, 'Zeitplan', $w, $real);
    }

    private function planTick(): void
    {
        $jetzt = time();
        $soll  = $this->planSoll($jetzt);
        if ($soll === null) {
            $this->anzeige('PlanNext', 0);
            return;
        }
        $this->anzeige('PlanNext', $soll['next']);

        if (!$this->planActive()) {
            return;                       // Betriebsart „Manuell": Fernbedienung wie bisher
        }
        if ($this->overrideBis() > 0) {
            return;                       // Handbetrieb hat Vorrang bis zum Slotwechsel
        }
        if (!(bool) $this->cfgVal('armed', false)) {
            $this->SendDebug('HSAC.plan', 'Schatten-Modus: ' . $soll['mode']
                . ' ' . ($soll['target'] === null ? '-' : $soll['target']) . ' nicht gesendet', 0);
            $this->planMerken($soll, false);
            return;
        }
        $drv = $this->driver();
        if (!($drv instanceof IClimate)) {
            return;
        }

        // Nur senden, was sich wirklich aendert: jeder Befehl ist bei Toshiba ein
        // MQTT-Paket und bei tado ein Cloud-Aufruf mit Tageskontingent.
        $istAn    = (bool) $this->wertVon('Power', false);
        $istModus = self::MODI[(int) $this->wertVon('Mode', 0)] ?? 'auto';
        $istZiel  = (float) $this->wertVon('Target', 0.0);

        $this->planMerken($soll, true);
        if ($soll['mode'] === 'off') {
            if ($istAn) {
                $drv->setPower(false);
                $this->anzeige('Power', false);
            }
            return;
        }
        if (!$istAn) {
            $drv->setPower(true);
            $this->anzeige('Power', true);
        }
        if ($istModus !== $soll['mode']) {
            $drv->setMode($soll['mode']);
            $i = array_search($soll['mode'], self::MODI, true);
            if ($i !== false) {
                $this->anzeige('Mode', $i);
            }
        }
        // Trocknen und Ventilator kennen keine Zieltemperatur.
        if ($soll['target'] !== null && !in_array($soll['mode'], ['dry', 'fan'], true)
            && abs($istZiel - $soll['target']) > 0.05) {
            $drv->setTarget($soll['target']);
            $this->anzeige('Target', $soll['target']);
        }
    }

    /** Aktueller Wert einer eigenen Statusvariablen (ohne Bedienpfad). */
    private function wertVon(string $ident, $vorgabe)
    {
        $vid = @$this->GetIDForIdent($ident);
        if ($vid === false || $vid <= 0) {
            return $vorgabe;
        }
        $w = @\GetValue($vid);
        return $w === null ? $vorgabe : $w;
    }

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
        $mk('HSAC.PowerLevel', [50 => '50 %', 75 => '75 %', 100 => '100 %']);
        $mk('HSAC.Fireplace', [0 => 'Aus', 1 => 'Kamin 1', 2 => 'Kamin 2']);
        $mk('HSAC.PlanMode', [0 => 'Manuell', 1 => 'Zeitplan']);
        $mk('HSAC.Variant',  [0 => 'Normal', 1 => 'Erweitert', 2 => 'Abgesenkt']);
        $mk('HSAC.Preset', [0 => 'Aus', 1 => 'High Power', 2 => 'Silent', 3 => 'ECO',
                            4 => 'Heizen 8 °C', 5 => 'Sleep', 6 => 'Floor', 7 => 'Comfort',
                            8 => 'CDU Silent 2']);
    }
}

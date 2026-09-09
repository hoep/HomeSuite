<?php

declare(strict_types=1);

/**
 * ShadingDevice (HSSH) — Domaenen-Modul „Beschattung" (Phase 2, M0/M1-Geruest).
 *
 * Eine Instanz = ein Rollo / eine Markise / ein Beschattungs-Element. Erbt
 * {@see \Hoep\HomeSuite\EntityModule} genau wie HeatingZone: die Basis legt aus
 * diesem manifest() die Status-Variablen an, aktiviert die native RequestAction
 * (Vertrag 1) und liefert das RPC-Trio HSSH_GetManifest / HSSH_GetState / HSSH_Manage.
 *
 * Leitentscheidungen (mit dem Nutzer abgestimmt, siehe Migrationsplan):
 *  - scheduleMode ist FEST 'controller': Rollos/IPSShadowing fuehren KEIN
 *    HomeSuite-Wochenprogramm; die ScheduleEngine im Modul faehrt den
 *    Positions-Zeitplan (val = Position 0..100 statt Temperatur). Der device-
 *    Sync-Pfad (syncStatus/loadFromDevice/syncToDevice) ist IThermostat-getypt
 *    und fuer einen IShutter inert -> die zugehoerigen Managementaktionen werden
 *    hier BEWUSST weggelassen (keine toten Buttons).
 *  - Treiber = GenericVariableShutter, gebunden an die BEREITS fahrbare
 *    IPSShadowing-Position-Variable (positionVarId=feedbackVarId, absolutePosition):
 *    echtes 0..100-Feedback, kein Roh-Telegramm-Treiber im ersten Wurf (M1/M2).
 *  - Zeitplan-Achse (statt Praesenz bei der Heizung): umschaltbare Plaene
 *    Anwesend/Abwesend/Urlaub (scheduleVariants/activeVariantIndex ueber 'Plan').
 *  - Manuell-Hold laeuft bis zur naechsten Zeitplan-Slot-Grenze (wie Heizung).
 *  - Sonnenautomatik + reconcile (evalRules-Kaskade Safety>Manuell>Sonne>Zeitplan)
 *    folgen in M3/M4; hier ist applyControl noch treiber-los (Schatten-Modus:
 *    kein Geraeteschreiben, solange driver()==null).
 *
 * Der Klassenname MUSS == module.json "name" == GUID-Register-Eintrag sein
 * ({A9645ED8-CB55-43B8-869B-BFF6ACFC8DC1}); der Hub mappt diese GUID bereits auf
 * die Domaene 'shading' (Hub::GUID_HSSH).
 */

require_once __DIR__ . '/../libs/HomeSuite/autoload.php';

use Hoep\HomeSuite\ActionContext;
use Hoep\HomeSuite\ContractException;
use Hoep\HomeSuite\Control;
use Hoep\HomeSuite\ControlContract;
use Hoep\HomeSuite\EntityModule;
use Hoep\HomeSuite\HAL\DriverFactory;
use Hoep\HomeSuite\HAL\IDriver;
use Hoep\HomeSuite\HAL\IShutter;
use Hoep\HomeSuite\ShadeKinematics;
use Hoep\HomeSuite\SunTimes;

// Klassenname MUSS = module.json "name" (ohne Leerzeichen) sein.
class ShadingDevice extends EntityModule
{
    /** Positions-Grenzen (%) — IShutter-Konvention 0=offen/oben .. 100=zu/unten. */
    private const POS_MIN = 0;
    private const POS_MAX = 100;
    private const LOG_MAX = 80;               // Ringpuffer-Groesse des Entscheidungs-/Befehls-Logs je Rollo

    /**
     * Zeitplan-Achsen (umschaltbare Plaene). Zwei Achsen (Nutzerwunsch „beides
     * kombiniert"): Praesenz x Saison. Die ScheduleEngine traegt EINE Varianten-
     * Liste -> wir bilden das KREUZPRODUKT als zusammengesetzte Varianten-Schluessel
     * (kein Engine-Umbau). Trennzeichen zwischen den Achsen: VARIANT_SEP.
     */
    private const PLAN_VARIANTS   = ['Anwesend', 'Abwesend', 'Urlaub'];
    private const SEASON_VARIANTS = ['Sommer', 'Winter'];
    private const VARIANT_SEP     = ' · ';

    /** Lazy-Cache des HAL-Treibers (pro Instanz-Prozess). */
    private ?IDriver $driverInstance = null;

    /** Wurde driver() in diesem Prozess-Stand schon aufgeloest? */
    private bool $driverResolved = false;

    /** Timer + Reconcile-Parameter. */
    private const TIMER_REFRESH  = 'Refresh';
    private const TIMER_MOVE      = 'MoveStop'; // Ein-Schuss: stoppt/settlet eine zeitbasierte Fahrt
    /** Nach so vielen Sekunden ueber der Fahrdauer gilt der Fahrt-Merker als tot. */
    private const MOVE_TOT_S      = 60;
    private const REFRESH_MS      = 30000;   // Reflect + Reconcile
    private const POS_TOLERANCE   = 3;        // % Drift, bevor gefahren wird
    private const SAFE_POS        = 0;        // Sturm-/Regen-sichere Position (offen/eingefahren)
    private const SUN_DWELL       = 300;      // Min-Dwell (s) gegen Sonnen-Flattern
    private const CAL_TIMEOUT     = 900;      // Kalibrier-Lock: Not-Aus nach 15 Min ohne Abschluss

    /** Umgebungs-Sensoren (Standort-Defaults; per config.env ueberschreibbar). */
    /**
     * Ab welcher Sonnenhoehe der waagrechte Strahlungssensor als Zeuge taugt.
     * sin(12 Grad) = 0,2079. Darunter wird der zuletzt gueltige Klarheitsindex
     * weitergetragen (siehe clearIndex), hoechstens CLEAR_HOLD_S lang.
     */
    private const CLEAR_EL_MIN_SIN = 0.2079;
    private const CLEAR_HOLD_S     = 5400;   // 90 Minuten

    private const SUN_AZ_ID      = 15291;    // Azimut (Location #<ID>)
    private const SUN_EL_ID      = 45609;    // Elevation
    private const WIND_ID        = 58381;    // Wind (km/h)
    private const RAIN_ID        = 19991;    // Regen
    private const BRIGHT_ID      = 53778;    // Helligkeit
    private const WIND_STORM_KMH = 45.0;     // Sturm-Schwelle (km/h)
    private const BUS_GAP_MS     = 4000;     // Mindestabstand zweier Funktelegramme (haus-weit, Hub-Vorgabe)

    /**
     * Vertrag 2 — das Manifest dieser Entitaet. Aus ihm legt die Basis die
     * Variablen an; der LVB rendert daraus Bedienung UND Verwaltung generisch.
     *
     * @return array<string,mixed>
     */
    protected function entityLabel(): string { return 'Beschattung'; }

    protected function manifest(): array
    {
        return [
            'domain' => 'shading',
            'title'  => 'Beschattung',
            'icon'   => 'Window',

            // ---- typisierte Controls (Vertrag 1) ----
            'controls' => [
                [
                    'ident' => 'Position', 'type' => ControlContract::T_LEVEL,
                    'role' => 'shading:position', 'label' => 'Position',
                    'varType' => 1, 'profile' => '~Intensity.100', 'unit' => '%',
                    'min' => self::POS_MIN, 'max' => self::POS_MAX, 'step' => 5,
                    'actionable' => true,
                ],
                [
                    'ident' => 'Movement', 'type' => ControlContract::T_COMMAND,
                    'role' => 'shading:move', 'label' => 'Fahrt',
                    'varType' => 1, 'actionable' => true,
                    'options' => [
                        ['value' => 1, 'label' => 'Auf'],
                        ['value' => 2, 'label' => 'Ab'],
                        ['value' => 0, 'label' => 'Stop'],
                    ],
                ],
                [
                    'ident' => 'ActualPosition', 'type' => ControlContract::T_REFLECT,
                    'role' => 'shading:actual', 'label' => 'Ist-Position',
                    'varType' => 1, 'profile' => '~Intensity.100', 'unit' => '%',
                    'actionable' => false,
                ],
                [
                    // Warum greift die Automatik gerade nicht? Der Grund entsteht ohnehin in
                    // reconcile() - bisher verschwand er dort im Nichts, und im Dashboard war
                    // nur zu sehen, DASS nichts passiert. Leer = kein Hindernis.
                    'ident' => 'BlockReason', 'type' => ControlContract::T_REFLECT,
                    'role' => 'shading:block', 'label' => 'Automatik blockiert durch',
                    'varType' => 3, 'actionable' => false,
                ],
                [
                    'ident' => 'Mode', 'type' => ControlContract::T_SELECT,
                    'role' => 'shading:mode', 'label' => 'Modus',
                    'varType' => 1, 'actionable' => true, 'profile' => 'HSSH.Mode',
                    'options' => [
                        ['value' => 0, 'label' => 'Auto'],
                        ['value' => 1, 'label' => 'Manuell'],
                        ['value' => 2, 'label' => 'Sonne'],
                    ],
                ],
                [
                    'ident' => 'Plan', 'type' => ControlContract::T_SELECT,
                    'role' => 'shading:plan', 'label' => 'Plan',
                    'varType' => 1, 'actionable' => true, 'profile' => 'HSSH.Plan',
                    'options' => [
                        ['value' => 0, 'label' => 'Anwesend'],
                        ['value' => 1, 'label' => 'Abwesend'],
                        ['value' => 2, 'label' => 'Urlaub'],
                    ],
                ],
                [
                    'ident' => 'Season', 'type' => ControlContract::T_SELECT,
                    'role' => 'shading:season', 'label' => 'Saison',
                    'varType' => 1, 'actionable' => true, 'profile' => 'HSSH.Season',
                    'options' => [
                        ['value' => 0, 'label' => 'Sommer'],
                        ['value' => 1, 'label' => 'Winter'],
                    ],
                ],
                // Sonnenprofil je Zone als echte, editierbare Baum-Variablen (Quelle der
                // Wahrheit; evalGeo liest sie, Nordausrichtung dreht sie). Ersetzt das
                // frueher im FabricStore versteckte geoProfile.
                [
                    'ident' => 'SunAzBgn', 'type' => ControlContract::T_LEVEL,
                    'role' => 'shading:sunAzBgn', 'label' => 'Sonne Azimut von',
                    'varType' => 1, 'unit' => '°', 'min' => 0, 'max' => 360, 'step' => 5, 'actionable' => true,
                ],
                [
                    'ident' => 'SunAzEnd', 'type' => ControlContract::T_LEVEL,
                    'role' => 'shading:sunAzEnd', 'label' => 'Sonne Azimut bis',
                    'varType' => 1, 'unit' => '°', 'min' => 0, 'max' => 360, 'step' => 5, 'actionable' => true,
                ],
                [
                    'ident' => 'SunElev', 'type' => ControlContract::T_LEVEL,
                    'role' => 'shading:sunElev', 'label' => 'Sonne Elevation-Schwelle',
                    'varType' => 1, 'unit' => '°', 'min' => -10, 'max' => 90, 'step' => 1, 'actionable' => true,
                ],
                // Per-Rollo Sonnen-Schliessgrad: wie weit dieses Rollo bei Sonne im
                // Fenster schliesst (0..100 %). Ueberschreibt den geteilten Profilwert
                // -> West 100 %, andere 75/50 % moeglich (wie IPSShadowing shadowingPos).
                [
                    'ident' => 'SunClose', 'type' => ControlContract::T_LEVEL,
                    'role' => 'shading:sunClose', 'label' => 'Sonne Schließgrad',
                    'varType' => 1, 'unit' => '%', 'min' => 0, 'max' => 100, 'step' => 5, 'actionable' => true,
                    'profile' => '~Intensity.100',
                ],
                [
                    'ident' => 'Online', 'type' => ControlContract::T_REFLECT,
                    'role' => 'shading:online', 'label' => 'Online',
                    'varType' => 0, 'actionable' => false,
                ],

                // ---- Entscheidungsprotokoll ------------------------------------
                // Warum faehrt das Rollo - oder warum nicht? Bisher war das nur im
                // Trockenlauf sichtbar (mgmt-Op reconcileProbe) und damit weder im
                // Baum noch im Hub noch in einem Diagramm. Jede Zwischengroesse, die
                // in die Entscheidung eingeht, bekommt hier ihre eigene Variable -
                // sonst bleibt eine knappe Entscheidung (37 % Klarheit gegen die
                // 35er-Schwelle) von aussen unerklaerbar.
                [
                    'ident' => 'AutoZiel', 'type' => ControlContract::T_REFLECT,
                    'role' => 'shading:autoTarget', 'label' => 'Automatik-Ziel',
                    'varType' => 1, 'profile' => '~Intensity.100', 'unit' => '%',
                    'actionable' => false,
                ],
                [
                    'ident' => 'AutoGrund', 'type' => ControlContract::T_REFLECT,
                    'role' => 'shading:autoReason', 'label' => 'Entscheidung wegen',
                    'varType' => 3, 'actionable' => false,
                ],
                [
                    'ident' => 'SonneRoh', 'type' => ControlContract::T_REFLECT,
                    'role' => 'shading:sunRaw', 'label' => 'Sonnenregel roh',
                    'varType' => 1, 'unit' => '%', 'actionable' => false,
                ],
                [
                    'ident' => 'SonneZiel', 'type' => ControlContract::T_REFLECT,
                    'role' => 'shading:sunTarget', 'label' => 'Sonnenregel entprellt',
                    'varType' => 1, 'unit' => '%', 'actionable' => false,
                ],
                [
                    'ident' => 'Klarheit', 'type' => ControlContract::T_REFLECT,
                    'role' => 'shading:clearIdx', 'label' => 'Klarheitsindex',
                    'varType' => 1, 'unit' => '%', 'actionable' => false,
                ],
                [
                    'ident' => 'KlarheitSchwelle', 'type' => ControlContract::T_REFLECT,
                    'role' => 'shading:clearThr', 'label' => 'Klarheit Schwelle',
                    'varType' => 1, 'unit' => '%', 'actionable' => false,
                ],
                [
                    'ident' => 'ZeitplanZiel', 'type' => ControlContract::T_REFLECT,
                    'role' => 'shading:schedTarget', 'label' => 'Zeitplan-Ziel',
                    'varType' => 1, 'unit' => '%', 'actionable' => false,
                ],
                [
                    'ident' => 'Variante', 'type' => ControlContract::T_REFLECT,
                    'role' => 'shading:variant', 'label' => 'Zeitplan-Variante',
                    'varType' => 3, 'actionable' => false,
                ],
                [
                    'ident' => 'Sturm', 'type' => ControlContract::T_REFLECT,
                    'role' => 'shading:storm', 'label' => 'Sturm aktiv',
                    'varType' => 0, 'actionable' => false,
                ],
                [
                    'ident' => 'ManuellVorrang', 'type' => ControlContract::T_REFLECT,
                    'role' => 'shading:held', 'label' => 'Manuell hat Vorrang',
                    'varType' => 0, 'actionable' => false,
                ],
                [
                    'ident' => 'TuerOffen', 'type' => ControlContract::T_REFLECT,
                    'role' => 'shading:doorOpen', 'label' => 'Tür offen',
                    'varType' => 0, 'actionable' => false,
                ],
                [
                    'ident' => 'TuerSperrt', 'type' => ControlContract::T_REFLECT,
                    'role' => 'shading:doorBlock', 'label' => 'Schließen durch Tür gesperrt',
                    'varType' => 0, 'actionable' => false,
                ],
                [
                    'ident' => 'WuerdeFahren', 'type' => ControlContract::T_REFLECT,
                    'role' => 'shading:wouldMove', 'label' => 'Fahrbefehl steht an',
                    'varType' => 0, 'actionable' => false,
                ],

                // ---- Naechster Fahrbefehl laut Zeitsteuerung -------------------
                [
                    'ident' => 'NaechsteFahrt', 'type' => ControlContract::T_REFLECT,
                    'role' => 'shading:nextRun', 'label' => 'Nächste Fahrt',
                    'varType' => 1, 'profile' => '~UnixTimestamp', 'actionable' => false,
                ],
                [
                    'ident' => 'NaechstesZiel', 'type' => ControlContract::T_REFLECT,
                    'role' => 'shading:nextTarget', 'label' => 'Nächstes Ziel',
                    'varType' => 1, 'profile' => '~Intensity.100', 'unit' => '%',
                    'actionable' => false,
                ],
                [
                    'ident' => 'NaechsteRichtung', 'type' => ControlContract::T_REFLECT,
                    'role' => 'shading:nextDir', 'label' => 'Nächste Richtung',
                    'varType' => 3, 'actionable' => false,
                ],
            ],

            // ---- Profil-Typ: Positions-Wochenplan (2 Achsen Plan x Wochentag) ----
            'profileTypes' => [
                'roomProfile' => [
                    'label' => 'Positions-Wochenplan',
                    'axes'  => [
                        'plan'    => self::PLAN_VARIANTS,
                        'season'  => self::SEASON_VARIANTS,
                        'weekday' => ['MO', 'DI', 'MI', 'DO', 'FR', 'SA', 'SO'],
                    ],
                    'slot'   => ['end' => 'HH:MM', 'val' => ['type' => 'int', 'min' => self::POS_MIN, 'max' => self::POS_MAX]],
                    'rules'  => ['lastSlotEnd' => '24:00', 'ascending' => true],
                    'editor' => 'weekedit-hm',
                ],
            ],

            // ---- Verwaltungs-Aktionen (Whitelist) ----
            // Bewusst OHNE syncStatus/loadFromDevice/syncToDevice/adoptDevice:
            // scheduleMode ist fest 'controller', die device-Sync-Ops sind
            // IThermostat-getypt und fuer einen IShutter tot.
            'managementActions' => [
                ['op' => 'createEntity',     'label' => 'Beschattung anlegen'],
                ['op' => 'renameEntity',     'label' => 'Umbenennen'],
                ['op' => 'deleteEntity',     'label' => 'Loeschen'],
                ['op' => 'configureDriver',  'label' => 'Treiber konfigurieren'],
                ['op' => 'updateProfile',    'label' => 'Positions-Wochenplan bearbeiten'],
                ['op' => 'getSchedule',      'label' => 'Wochenplan lesen'],
                ['op' => 'duplicateProfile', 'label' => 'Plan duplizieren'],
                ['op' => 'assignProfile',    'label' => 'Plan zuweisen'],
                ['op' => 'setActivePlan',    'label' => 'Plan setzen'],
                ['op' => 'configureAutomation', 'label' => 'Sonne/Sicherheit konfigurieren'],
                ['op' => 'setArmed',           'label' => 'Scharfschalten / Schatten-Modus'],
                ['op' => 'migrateConfig',      'label' => 'Config auf Properties migrieren (einmalig)'],
                ['op' => 'rotateGeo',          'label' => 'Sonnenprofil-Azimut drehen (Nordausrichtung)'],
                ['op' => 'driverProbe',        'label' => 'Treiber-Status (Diagnose)'],
                ['op' => 'reconcileProbe',     'label' => 'Regel-Entscheidung (Trockenlauf)'],
                ['op' => 'getConfig',          'label' => 'Konfiguration lesen (Diagnose)'],
                ['op' => 'command',            'label' => 'Bedienen (Position/Fahrt/Modus)'],
                ['op' => 'referenceRun',       'label' => 'Referenzfahrt (kalibrieren)'],
                ['op' => 'calMove',            'label' => 'Kalibrierfahrt: rohes Fahren (auf/ab)'],
                ['op' => 'calStop',            'label' => 'Kalibrierfahrt: Stopp'],
                ['op' => 'calSetTime',         'label' => 'Kalibrierfahrt: gemessene Fahrzeit uebernehmen'],
                ['op' => 'calAbort',           'label' => 'Kalibrierfahrt: abbrechen (Automatik wieder frei)'],
                ['op' => 'validate',           'label' => 'Bindung pruefen (Diagnose)'],
                ['op' => 'getLog',             'label' => 'Entscheidungs-/Befehls-Log lesen'],
                ['op' => 'clearLog',           'label' => 'Entscheidungs-/Befehls-Log LEEREN'],
            ],

            // ---- Konfig-Felder (Treiberwahl; im LVB gesetzt) ----
            // generic-shutter wird an die IPSShadowing-Position-Variable gebunden;
            // automaticId = deren Automatic-Bool (fuer Cutover/Rollback, M8).
            'configFields' => [
                ['key' => 'driver', 'type' => 'select', 'label' => 'Treiber', 'required' => true,
                 'options' => [
                     ['value' => 'generic-shutter', 'label' => 'Generisch (Positions-Variable)'],
                 ]],
                ['key' => 'positionId',  'type' => 'objid', 'label' => 'Positions-Variable (generisch/Homematic)', 'required' => false],
            ],

            'capabilities' => [
                'scheduleMode' => 'controller',
                'hasPlan'      => true,
                'driver'       => $this->configuredDriverId(),
            ],
        ];
    }

    /** Konfigurierte driverId (aus nativen Properties). */
    private function configuredDriverId(): string
    {
        return (string) $this->cfg()['driver'];
    }

    // ==================================================================
    // Native Instanz-Properties (Symcon-Konzept). Flache Bindungs-/Sicherheits-
    // felder sind Properties; komplexe/variable Strukturen (schedules, geoProfile,
    // env, tempGate, dayBegin/dayEnd, doorIds, RtState) bleiben im FabricStore.
    // ==================================================================
    public function Create()
    {
        // Globalstrahlung als Sonnen-Gate. Die Geometrie allein sagt nur, WO die Sonne steht -
        // nicht, ob sie scheint. Ohne diese Bindung meldet ein Fenster auch bei geschlossener
        // Wolkendecke "Sonne", sobald der Sonnenstand ins Azimutfenster faellt.
        // 0 = nicht gebunden bzw. Schwelle aus -> Verhalten unveraendert wie bisher.
        $this->RegisterPropertyInteger('SunRadId', 0);
        $this->RegisterPropertyFloat('SunRadMin', 0.0);
        $this->RegisterPropertyFloat('SunRadOff', 0.0);   // Hysterese: Ausschalt-Schwelle (0 = keine)
        parent::Create();
        $this->RegisterAttributeString('DecisionLog', '[]'); // Ringpuffer: Automatik-Entscheidungen + manuelle Befehle (nur echte Fahrten)
        $this->RegisterPropertyString('Driver', '');
        $this->RegisterPropertyBoolean('Invert', false);
        // Geraeteart: 'shutter' = Rollo (Default) | 'awning' = Markise. Rein semantisch —
        // steuert Beschriftung/Darstellung im Frontend (Ein/Aus + Ausfahrgrad statt Auf/Zu).
        $this->RegisterPropertyString('DeviceKind', 'shutter');
        $this->RegisterPropertyInteger('PositionId', 0);
        // ALTLAST: nur noch REGISTRIERT, nirgends benutzt - bestehende Instanzen fuehren
        // sie in ihrer Konfiguration; ohne Registrierung meldet die Konsole einen Ladefehler.
        $this->RegisterPropertyInteger('AutomaticId', 0);
        $this->RegisterPropertyInteger('SocketId', 0);
        $this->RegisterPropertyInteger('Channel', 0);
        $this->RegisterPropertyInteger('Repeat', 2);
        $this->RegisterPropertyInteger('StopRepeat', 4);
        $this->RegisterPropertyInteger('GapMs', 50);
        $this->RegisterPropertyInteger('TimeOpening', 0);
        $this->RegisterPropertyInteger('TimeClosing', 0);
        $this->RegisterPropertyInteger('InstanceId', 0);
        $this->RegisterPropertyInteger('LevelVarId', 0);
        $this->RegisterPropertyFloat('WindStormKmh', 50.0);
        $this->RegisterPropertyInteger('SafePos', 0);
        $this->RegisterPropertyBoolean('RainClose', false);
        $this->RegisterPropertyString('SunSource', 'location');
        $this->RegisterPropertyInteger('LocationId', 0);
        $this->RegisterPropertyFloat('Lat', 0.0);
        $this->RegisterPropertyFloat('Lon', 0.0);
        $this->RegisterPropertyBoolean('Armed', false);   // Schatten-Modus bis Cutover
        // --- Automatik-Bindungen als ECHTE Properties (frueher nur Store + Extra-Button).
        //     Grund: Formularfelder, die keine Property sind, speichert das normale
        //     „Uebernehmen" NICHT - der Nutzer stellte etwas ein, sah Erfolg und danach
        //     wieder leere Felder. Jetzt traegt jedes Feld seinen echten Property-Namen. ---
        $this->RegisterPropertyString('Doors', '[]');   // Aussperr-Schutz: [{"varId":123}, …]
        $this->RegisterPropertyInteger('EnvSunAzId', 0); // 0 = Hub-/Standardwert benutzen
        $this->RegisterPropertyInteger('EnvSunElId', 0);
        $this->RegisterPropertyInteger('EnvWindId', 0);
        $this->RegisterPropertyInteger('EnvRainId', 0);
        $this->RegisterPropertyInteger('EnvBrightId', 0);
        $this->RegisterPropertyInteger('ConfigSchema', 0); // Migrations-Marker
        $this->RegisterPropertyInteger('QueryInterval', 30); // Abfrage-Intervall in SEKUNDEN (Default = REFRESH_MS/1000)
    }

    /**
     * Konfiguration: flache Felder aus nativen Properties, KOMPLEXE Strukturen
     * (geoProfile/env/tempGate/dayBegin/dayEnd/doorIds/…) weiterhin aus dem Store.
     */
    private function cfg(): array
    {
        $store = $this->store()->get('config', []);
        $store = is_array($store) ? $store : [];
        $props = [
            'driver'       => $this->ReadPropertyString('Driver'),
            'invert'       => $this->ReadPropertyBoolean('Invert'),
            'deviceKind'   => $this->ReadPropertyString('DeviceKind'),
            'positionId'   => $this->ReadPropertyInteger('PositionId'),
            'socketId'     => $this->ReadPropertyInteger('SocketId'),
            'channel'      => $this->ReadPropertyInteger('Channel'),
            'repeat'       => $this->ReadPropertyInteger('Repeat'),
            'stopRepeat'   => $this->ReadPropertyInteger('StopRepeat'),
            'gapMs'        => $this->ReadPropertyInteger('GapMs'),
            'timeOpening'  => $this->ReadPropertyInteger('TimeOpening'),
            'timeClosing'  => $this->ReadPropertyInteger('TimeClosing'),
            'instanceId'   => $this->ReadPropertyInteger('InstanceId'),
            'levelVarId'   => $this->ReadPropertyInteger('LevelVarId'),
            'windStormKmh' => $this->ReadPropertyFloat('WindStormKmh'),
            'safePos'      => $this->ReadPropertyInteger('SafePos'),
            'rainClose'    => $this->ReadPropertyBoolean('RainClose'),
            'sunSource'    => $this->ReadPropertyString('SunSource'),
            'locationId'   => $this->ReadPropertyInteger('LocationId'),
            'lat'          => $this->ReadPropertyFloat('Lat'),
            'lon'          => $this->ReadPropertyFloat('Lon'),
            'armed'        => $this->ReadPropertyBoolean('Armed'),
        ];
        $merged = array_merge($store, $props); // Properties gewinnen fuer flache Keys; komplexe kommen aus dem Store

        // --- Globale (haus-weite) Defaults vom Hub (zentrales Config-Formular). Hub gewinnt,
        //     wenn gesetzt; sonst Instanz/Store-Fallback. So teilen sich alle Rollos EINEN
        //     Sensorsatz/Standort/Sturmschwelle statt Duplikat je Instanz. ---
        $hw = (float) $this->hubProp('ShadeWindStormKmh', 0);
        if ($hw > 0) { $merged['windStormKmh'] = $hw; }
        $hs = (int) $this->hubProp('ShadeSafePos', 0);
        if ($hs > 0) { $merged['safePos'] = $hs; }
        // Bus-Abstand: AUSSCHLIESSLICH haus-weit. Er beschreibt die gemeinsame Luftschnittstelle,
        // nicht das einzelne Rollo - je Rollo einstellbar waere er sinnlos, weil das langsamste
        // Geraet ohnehin den Takt aller anderen bestimmt.
        $merged['busGapMs'] = max(0, (int) $this->hubProp('ShadeBusGapMs', self::BUS_GAP_MS));
        // REGEN IST OPT-IN JE GERAET (Vorgabe): hier hat frueher der Hub-Schalter
        // ShadeRainClose bedingungslos gewonnen -> Regen haette ALLE 17 Rollos gefahren,
        // obwohl in IPSShadowing nur die Markise ein Wetterprofil hatte. Der Hub-Override
        // ist deshalb entfernt: massgeblich ist allein die Instanz-Property RainClose
        // (oben in $props, gewinnt ueber array_merge). Wind/Sturm bleibt bewusst haus-weit,
        // weil er die Mechanik schuetzt und nicht dem Komfort dient.
        // Sensor-Rangfolge: EIGENE Instanz-Property > Hub (haus-weit) > Store (Altbestand) >
        // Konstante. Frueher gewann der Hub bedingungslos - deshalb liess sich z. B. die
        // Helligkeits-/Strahlungsquelle je Rollo einstellen, sprang aber sofort wieder auf
        // den Hub-Wert zurueck ("wird nicht uebernommen"). 0 = nichts eigenes gesetzt.
        $env = is_array($merged['env'] ?? null) ? $merged['env'] : [];
        $sensors = [
            'sunAzId'  => ['ShadeSunAzId',  'EnvSunAzId'],
            'sunElId'  => ['ShadeSunElId',  'EnvSunElId'],
            'windId'   => ['ShadeWindId',   'EnvWindId'],
            'rainId'   => ['ShadeRainId',   'EnvRainId'],
            'brightId' => ['ShadeBrightId', 'EnvBrightId'],
        ];
        foreach ($sensors as $k => [$hubProp, $ownProp]) {
            $g = (int) $this->hubProp($hubProp, 0);
            if ($g > 0) { $env[$k] = $g; }
            $own = (int) $this->ReadPropertyInteger($ownProp);
            if ($own > 0) { $env[$k] = $own; } // eigene Wahl schlaegt den Hub-Standard
        }
        $merged['env'] = $env;

        // AUSSEN-Temperaturfuehler ist EINER fuers ganze Haus -> Hub-Vorgabe gewinnt, wenn
        // gesetzt. Der INNEN-Fuehler bleibt je Rollo (jeder Raum hat einen eigenen).
        $hto = (int) $this->hubProp('ShadeTempOutId', 0);
        if ($hto > 0 && is_array($merged['tempGate'] ?? null)) {
            $merged['tempGate']['outSensorId'] = $hto;
        }

        // Aussperr-Schutz: ab Schema 2 ist die Property die Wahrheit - auch LEER, sonst
        // liesse sich der letzte Kontakt nie wieder loeschen (Store-Rueckfall).
        if ((int) $this->ReadPropertyInteger('ConfigSchema') >= 2) {
            $merged['doorIds'] = $this->doorsFromProperty();
        }
        return $merged;
    }

    /** Tuerkontakt-Liste (Property „Doors") -> flache Variablen-ID-Liste. */
    private function doorsFromProperty(): array
    {
        $rows = json_decode((string) $this->ReadPropertyString('Doors'), true);
        if (!is_array($rows)) { return []; }
        $ids = [];
        foreach ($rows as $r) {
            $id = (int) (is_array($r) ? ($r['varId'] ?? 0) : $r);
            if ($id > 0 && !in_array($id, $ids, true)) { $ids[] = $id; }
        }
        return $ids;
    }

    /** Anzeigetext bei nicht abgesetztem Funkbefehl - eine Stelle, damit Setzen und Loeschen zusammenpassen. */
    private const BLOCK_FUNK = 'Funkbefehl nicht abgesetzt';

    private function armed(): bool
    {
        return $this->armedEffective($this->ReadPropertyBoolean('Armed')); // Hub-Master hat Vorrang
    }

    /** Baum-Sichtbarkeit: Positions-Wochenplan + Automatik-Config (env/tempGate/Tag) als JSON spiegeln. */
    protected function refreshMirrors(): void
    {
        $this->mirrorVar('ScheduleJson', 'Positions-Wochenplan (JSON, Anzeige)', $this->store()->get('schedule', []));
        $auto = [];
        foreach (['env', 'tempGate', 'dayBegin', 'dayEnd', 'doorIds', 'geoProfile'] as $k) {
            $v = $this->cfgVal($k, null);
            if ($v !== null) { $auto[$k] = $v; }
        }
        $this->mirrorVar('AutomationJson', 'Automatik/Sensoren (JSON, Anzeige)', $auto);
    }

    /** Map flache Config-Keys -> [PropertyName, Typ]. */
    private const PROP_MAP = [
        'driver'=>['Driver','s'], 'invert'=>['Invert','b'], 'deviceKind'=>['DeviceKind','s'], 'positionId'=>['PositionId','i'],
        'socketId'=>['SocketId','i'], 'channel'=>['Channel','i'],
        'repeat'=>['Repeat','i'], 'stopRepeat'=>['StopRepeat','i'], 'gapMs'=>['GapMs','i'],
        'timeOpening'=>['TimeOpening','i'], 'timeClosing'=>['TimeClosing','i'], 'instanceId'=>['InstanceId','i'],
        'levelVarId'=>['LevelVarId','i'], 'windStormKmh'=>['WindStormKmh','f'], 'safePos'=>['SafePos','i'],
        'rainClose'=>['RainClose','b'], 'sunSource'=>['SunSource','s'], 'locationId'=>['LocationId','i'],
        'lat'=>['Lat','f'], 'lon'=>['Lon','f'], 'armed'=>['Armed','b'],
    ];

    private function castProp(string $type, $v)
    {
        switch ($type) { case 'i': return (int)$v; case 'f': return (float)$v; case 'b': return (bool)$v; default: return (string)$v; }
    }

    /**
     * Schreibt gemischte Config: flache Keys -> Properties (IPS_SetProperty),
     * komplexe Keys -> Store-Patch. $apply=true triggert IPS_ApplyChanges.
     */
    private function applyConfigProperties(array $c, bool $apply = true): void
    {
        $storePatch = [];
        foreach ($c as $k => $v) {
            if (isset(self::PROP_MAP[$k])) {
                [$p, $t] = self::PROP_MAP[$k];
                @\IPS_SetProperty($this->InstanceID, $p, $this->castProp($t, $v));
            } else {
                $storePatch[$k] = $v; // geoProfile/env/tempGate/dayBegin/dayEnd/doorIds …
            }
        }
        if ($storePatch !== []) {
            $this->store()->patch('config', $storePatch);
        }
        if ($apply) {
            @\IPS_ApplyChanges($this->InstanceID);
        }
    }

    /**
     * Einmal-Migration Schema 1 -> 2: Tuerkontakte und Sensor-Bindungen aus dem Store in
     * echte Properties heben. Bewusst OHNE IPS_ApplyChanges (kein Rekursions-Risiko aus
     * ApplyChanges heraus) - bis zum naechsten Uebernehmen gilt weiter der Store, danach
     * die Property. Beide Wege liefern dieselben Werte, der Uebergang ist also unsichtbar.
     */
    private function migrateAutomationProps(): void
    {
        if ((int) $this->ReadPropertyInteger('ConfigSchema') >= 2) {
            return;
        }
        $store = $this->store()->get('config', []);
        $store = is_array($store) ? $store : [];

        $doors = array_values(array_filter(array_map('intval', (array) ($store['doorIds'] ?? []))));
        if ($doors !== []) {
            @\IPS_SetProperty($this->InstanceID, 'Doors', json_encode(array_map(
                static function ($id) { return ['varId' => (int) $id]; }, $doors)));
        }
        $env = is_array($store['env'] ?? null) ? $store['env'] : [];
        foreach (['sunAzId'=>'EnvSunAzId','sunElId'=>'EnvSunElId','windId'=>'EnvWindId',
                  'rainId'=>'EnvRainId','brightId'=>'EnvBrightId'] as $k => $p) {
            $v = (int) ($env[$k] ?? 0);
            if ($v > 0) { @\IPS_SetProperty($this->InstanceID, $p, $v); }
        }
        @\IPS_SetProperty($this->InstanceID, 'ConfigSchema', 2);
    }

    /** Einmal-Migration: flache FabricStore-config -> native Properties (per RPC-Op). */
    private function migrateConfig(): array
    {
        if ($this->ReadPropertyInteger('ConfigSchema') >= 1) {
            return ['ok' => true, 'already' => true, 'config' => $this->cfg()];
        }
        $c = $this->store()->get('config', []);
        $c = is_array($c) ? $c : [];
        $flat = [];
        foreach ($c as $k => $v) {
            if (isset(self::PROP_MAP[$k])) {
                [$p, $t] = self::PROP_MAP[$k];
                @\IPS_SetProperty($this->InstanceID, $p, $this->castProp($t, $v));
                $flat[] = $k;
            }
        }
        @\IPS_SetProperty($this->InstanceID, 'ConfigSchema', 1);
        @\IPS_ApplyChanges($this->InstanceID);
        return ['ok' => true, 'migrated' => $flat, 'config' => $this->cfg()];
    }

    /** Bindungs-Links (Baum-Transparenz) treiberabhaengig + Sensoren/Tueren. */
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
        if ($driver === 'generic-shutter') { $add('bl_Position', 'Position', (int) $cfg['positionId']); }
        elseif ($driver === 'somfy-rts')   { $add('bl_Socket', 'Somfy-Socket', (int) $cfg['socketId']); }
        elseif ($driver === 'hm-shutter')  { $add('bl_Device', 'HM-Gerät', (int) $cfg['instanceId']); $add('bl_Level', 'Level', (int) $cfg['levelVarId']); }
        $env = is_array($cfg['env'] ?? null) ? $cfg['env'] : [];
        $envLabels = ['sunAzId'=>'Sonnen-Azimut','sunElId'=>'Sonnen-Elevation','windId'=>'Wind','rainId'=>'Regen','brightId'=>'Helligkeit'];
        foreach ($envLabels as $k => $lab) { $add('bl_env_' . $k, $lab, (int) ($env[$k] ?? 0)); }
        $tg = is_array($cfg['tempGate'] ?? null) ? $cfg['tempGate'] : [];
        $add('bl_tempSensor', 'Temp-Gate-Sensor innen', (int) ($tg['sensorId'] ?? 0));
        $add('bl_tempSensorOut', 'Temp-Gate-Sensor außen', (int) ($tg['outSensorId'] ?? 0));
        $i = 0;
        foreach ((array) ($cfg['doorIds'] ?? []) as $d) { $add('bl_Door' . $i, 'Tür-Kontakt', (int) $d); $i++; }
        return $out;
    }

    /**
     * Zeitplan-Achse: umschaltbare Plaene (statt Praesenz bei der Heizung). Die
     * ScheduleEngine speichert je Variante einen eigenen Wochenplan.
     *
     * @return string[]
     */
    protected function scheduleVariants(): array
    {
        $out = [];
        foreach (self::PLAN_VARIANTS as $plan) {
            foreach (self::SEASON_VARIANTS as $season) {
                $out[] = $plan . self::VARIANT_SEP . $season;
            }
        }
        return $out; // 6 Varianten: Anwesend·Sommer, Anwesend·Winter, Abwesend·Sommer, …
    }

    /**
     * Aktiver Kreuzprodukt-Index aus 'Plan' (0..2) und 'Season' (0..1):
     * index = plan * |SEASON| + season. Passt auf die Reihenfolge in
     * scheduleVariants().
     */
    protected function activeVariantIndex(): int
    {
        $p = $this->intVal('Plan');
        $s = $this->intVal('Season');
        $p = ($p >= 0 && $p < count(self::PLAN_VARIANTS)) ? $p : 0;
        $s = ($s >= 0 && $s < count(self::SEASON_VARIANTS)) ? $s : 0;
        return $p * count(self::SEASON_VARIANTS) + $s;
    }

    /**
     * Automatik-Hoheit: nur Position & Modus oeffnen ein manualHold-Fenster
     * (Reflect/Plan nicht). Der Hold laeuft bis zur naechsten Slot-Grenze (M3).
     */
    protected function isAutomated(Control $c): bool
    {
        return in_array($c->ident, ['Position', 'Mode'], true);
    }

    /**
     * MANUELL-VORRANG: einen Nutzer-Eingriff in den dauerhaften Modus MANUELL
     * uebersetzen (Vorgabe: "manuell hat immer hoechste Prio und stellt das Rollo
     * auf manuell, bis es wieder auf Automatik gestellt wird").
     *
     * Wirkung ueber den bestehenden Mode-Control (0 Auto, 1 Manuell, 2 Sonne):
     * computeDecision setzt sunOn/schedOn nur fuer Mode 0/2 -> bei Mode 1 liefert
     * evalRules kein Komfort-Ziel mehr. Das Safety-Tier (Sturm/Regen) ist davon
     * unberuehrt und faehrt weiterhin. Ist der Modus bereits 1, passiert nichts
     * (kein unnoetiges Schreiben/Event).
     *
     * Zusaetzlich wird der kurze manualHold gesetzt: er deckt den Moment ab, bevor
     * der Modus wirkt, und traegt die Handbedienung auch dann, wenn der Nutzer
     * bewusst im Automatikmodus bleiben will (Movement ist T_COMMAND und bekommt
     * in RequestAction von Haus aus KEINEN Hold).
     */
    private function enterManualMode(): void
    {
        $vid = @$this->GetIDForIdent('Mode');
        if ($vid && (int) @GetValue($vid) !== 1) {
            $this->SetValue('Mode', 1);
            $this->SendDebug('HSSH.manual', 'Handbedienung -> Modus MANUELL (bleibt bis der Nutzer auf Automatik zurueckstellt)', 0);
        }
        $this->manualHold('Position', $this->secondsToNextBoundary($this->activeVariant()));
    }

    /**
     * Vertrag 1 — realer Umsetzungs-Hook. Die Basis hat den Wert optimistisch
     * bereits in die MODUL-Statusvariable geschrieben; hier wird er an den HAL-
     * Treiber weitergereicht (Position -> moveTo, Fahrt -> move).
     *
     * M0/M1-Geruest: solange kein Treiber konfiguriert ist (driver()==null),
     * passiert NICHTS am Geraet (Schatten-Modus). Die Bindung an den
     * GenericVariableShutter + reconcile/Sonnenautomatik folgen in M2/M3/M4.
     */
    protected function applyControl(Control $c, $value, ActionContext $ctx): void
    {
        // Manuellen Befehl protokollieren (auch im Schatten-Modus als Intent; nur bei echter Aenderung).
        if ($c->ident === 'Position' || $c->ident === 'Movement') {
            $frm = -1;
            try { $mdrv = $this->driver(); if ($mdrv instanceof IShutter) { $frm = (int) $mdrv->readPosition(); } } catch (\Throwable $e) {}
            if ($c->ident === 'Position') {
                $mto = (int) max(self::POS_MIN, min(self::POS_MAX, (int) round((float) $value)));
                if ($frm < 0 || $mto !== $frm) { $this->logDecision($frm, $mto, 'Manuell', $this->armed(), 'manuell'); }
            } else {
                $mv = (int) $value; $mto = $mv === 1 ? self::POS_MIN : ($mv === 2 ? self::POS_MAX : $frm);
                $this->logDecision($frm, $mto, $mv === 0 ? 'Manuell (Stopp)' : 'Manuell', $this->armed(), 'manuell');
            }
        }
        // MANUELL HAT IMMER VORRANG (Vorgabe): jeder Nutzer-Eingriff schaltet das Rollo
        // dauerhaft in den Modus MANUELL (Mode=1) - nicht nur ein Zeitfenster. Sonne und
        // Zeitplan sind damit aus (computeDecision: sunOn/schedOn), Sturm/Sicherheit bleibt
        // (eigener Tier). Zurueck in die Automatik NUR durch den Nutzer (Modus-Icon der
        // Kachel bzw. Mode-Control). Das ist die IPSShadowing-Semantik (ManualChange), aber
        // sichtbar und ohne deren stille Resets. Der kurze manualHold bleibt zusaetzlich als
        // Schutz fuer den Moment, in dem der Modus noch nicht gegriffen hat.
        // Reconcile/Automatikfahrten laufen NICHT hier durch (die rufen driveTo direkt),
        // koennen den Modus also nicht versehentlich setzen.
        if ($c->ident === 'Position' || $c->ident === 'Movement') {
            $this->enterManualMode();
        }
        // Gegenrichtung: schaltet der Nutzer bewusst zurueck auf Automatik (0) oder
        // Sonne (2), muss die manuelle Sperre WEG - sonst bliebe die Automatik trotz
        // Auto-Modus bis zur naechsten Zeitplan-Grenze blockiert (genau der Zustand,
        // der nach einer Kalibrierung sichtbar war).
        if ($c->ident === 'Mode' && (int) $value !== 1) {
            $this->releaseAutoHold();
        }
        // Schatten-Modus: KEIN reales Fahren/Bus-Telegramm bis armed=true (sonst
        // Kollision mit IPSShadowing auf demselben Socket 45711). Optimistischer
        // SetValue der Basis bleibt (Anzeige folgt), real passiert nichts.
        if (!$this->armed()) {
            $this->SendDebug('HSSH.shadow', $c->ident . '=' . (is_scalar($value) ? (string) $value : '?') . ' (Schatten-Modus)', 0);
            return;
        }
        $drv = $this->driver();
        if (!$drv instanceof IShutter) {
            // Kein Treiber gebunden: es faehrt nichts. Dann darf auch der Sollwert nicht
            // stehenbleiben - die Basisklasse hat ihn vor diesem Aufruf schon geschrieben.
            $this->mirrorBack($c->ident);
            return;
        }
        // TUER-GUARD: gilt auch fuer den ausdruecklichen Befehl.
        //
        // Ein konfigurierter Tuerkontakt ist eine Zusage an die Mechanik - ein Rollo faehrt nicht
        // in eine offene Terrassentuer, egal ob Zeitplan, Kachel oder Skript den Befehl gibt.
        // Auffahren und der Sturm-Rueckzug bleiben immer erlaubt, geblockt wird nur das Zufahren.
        //
        // Was frueher falsch war, ist NICHT der Guard, sondern sein Nachlauf: die Basisklasse
        // schreibt den Sollwert, bevor applyControl ueberhaupt entscheiden kann. Brach der Guard
        // danach ab, meldete die Buchfuehrung "zu", waehrend das Rollo offen stand - so stand
        // Esszimmer Mitte am 17.08.2026 auf Position 100 bei Ist 0, und die Automatik hielt sich
        // fuer fertig. Deshalb: blocken UND den Sollwert auf die belegte Lage zuruecknehmen.
        $curForGuard = $drv->readPosition();
        if ($curForGuard === IShutter::POS_UNKNOWN) { $curForGuard = $this->estPos(); }
        switch ($c->ident) {
            case 'Position':
                $p = (int) max(self::POS_MIN, min(self::POS_MAX, (int) round((float) $value)));
                if ($this->closeBlockedByDoor($curForGuard, $p)) {
                    $this->setBlock('Tür offen');
                    $this->SendDebug('HSSH.guard', 'Tuer offen -> Zufahren auf ' . $p . '% blockiert (Befehl)', 0);
                    $this->mirrorBack('Position');   // Sollwert zurueck: es ist nichts gefahren
                    break;
                }
                // ueber den Executor: zeitbasiert bei Somfy, mit Ramp + Positions-Rueckmeldung
                $this->fahrtQuittieren($this->driveTo($drv, $p), 'Position');
                break;
            case 'Movement':
                // Auf/Zu ueber den Executor (Endanschlag, selbstkalibrierend + Rueckmeldung); Stopp beendet die laufende Fahrt.
                $mv = (int) $value;
                if ($mv === 1)      { $this->fahrtQuittieren($this->driveTo($drv, self::POS_MIN), 'Movement'); }   // auf
                elseif ($mv === 2)  {
                    if ($this->closeBlockedByDoor($curForGuard, self::POS_MAX)) {
                        $this->setBlock('Tür offen');
                        $this->SendDebug('HSSH.guard', 'Tuer offen -> Zufahren (Taste Zu) blockiert', 0);
                        $this->mirrorBack('Position');
                        break;
                    }
                    $this->fahrtQuittieren($this->driveTo($drv, self::POS_MAX), 'Movement');
                }   // zu
                else                { $rtm = $this->readRt(); if (!empty($rtm['moving'])) { $this->finishMove(true); } else { $drv->move('stop'); } }
                break;
        }
    }

    /**
     * Ergebnis einer Fahrt auswerten - und bei Misserfolg die BUCHFUEHRUNG ZURUECKNEHMEN.
     *
     * Das ist der Kern der Selbstheilung. Ein Funkprotokoll ohne Rueckmeldung kann einen
     * verlorenen Befehl nur dann nachholen, wenn der Sollwert die Wirklichkeit nicht
     * ueberschreibt: bleibt "Ziel = Ist" stehen, obwohl nichts gefahren ist, sieht jeder
     * weitere Abgleich "Ziel erreicht" und ruehrt das Rollo nie wieder an. Genau so blieben
     * am 09.09.2026 sechzehn Rollos offen, waehrend die Anlage sie fuer geschlossen hielt.
     *
     * Wird der Sollwert dagegen zurueckgenommen, steht beim naechsten Automatiklauf wieder
     * Ziel != Ist - und das Rollo faehrt von selbst nach. Kein Sonderweg, kein
     * "Erzwingen"-Knopf, keine Handarbeit.
     *
     * Die Stoerung wird ausserdem sichtbar gemacht: BlockReason ist reine Anzeige und
     * blockiert nichts, der naechste Versuch bleibt also erlaubt. Ein stiller Fehlschlag
     * waere die schlechteste aller Moeglichkeiten - er sieht aus wie Erfolg.
     */
    private function fahrtQuittieren(bool $ok, string $ident): void
    {
        $this->applyOk = $ok;
        if ($ok) {
            if ((string) @$this->GetControlValue('BlockReason') === self::BLOCK_FUNK) {
                $this->setBlock('');   // frueherer Fehlschlag ist ueberholt
            }
            return;
        }
        $this->setBlock(self::BLOCK_FUNK);
        $this->SendDebug('HSSH.send', $ident . ': Telegramm nicht abgesetzt -> Sollwert zurueckgenommen', 0);
        $this->LogMessage('HSSH: Fahrbefehl nicht abgesetzt (' . $ident . ') - Sollwert zurueckgenommen, '
                        . 'die Automatik holt die Fahrt nach', KL_WARNING);
        $this->mirrorBack('Position');
    }

    /**
     * HAL-Treiber (M1): GenericVariableShutter, gebunden an die IPSShadowing-
     * Position-Variable. Diese ist ZIEL und FEEDBACK zugleich (absolutePosition) —
     * readPosition liefert echte 0..100, moveTo faehrt ueber die getestete
     * IPSShadowing-Dead-Reckoning-Kette. KEIN Roh-Telegramm-Treiber.
     */
    protected function driver(): ?IDriver
    {
        if ($this->driverResolved) {
            return $this->driverInstance;
        }
        $this->driverResolved = true;
        $this->driverInstance = null;

        $cfg        = $this->cfg();
        $driverId   = (string) ($cfg['driver'] ?? '');
        $positionId = (int) ($cfg['positionId'] ?? 0);

        if ($driverId === '') {
            return null; // unkonfiguriert -> applyControl bleibt im Schatten-Modus
        }
        try {
            if ($driverId === 'generic-shutter') {
                if ($positionId <= 0) {
                    return null; // generic-shutter braucht eine Positions-Variable
                }
                $dcfg = [
                    'positionVarId'    => $positionId,
                    'feedbackVarId'    => $positionId,
                    'absolutePosition' => true,
                    'invert'           => (bool) ($cfg['invert'] ?? false),
                ];
                $this->driverInstance = DriverFactory::create('generic-shutter', $dcfg);
            } elseif ($driverId === 'somfy-rts') {
                // Roh-Aktor: Somfy RTS via Client-Socket zum TCP-Gateway. Der Treiber
                // ist zustandslos (B1); der Sende-Callback schiebt den Frame ueber
                // CSCK_SendText auf den Socket. KEINE Absolutposition/Feedback ->
                // Absolutfahrten macht das Modul zeitbasiert (ShadeKinematics).
                $socketId = (int) ($cfg['socketId'] ?? 0);
                $channel  = (int) ($cfg['channel'] ?? 0);
                if ($socketId <= 0 || $channel < 1 || $channel > 16) {
                    return null; // unvollstaendig konfiguriert -> Schatten-Modus
                }
                // Der Callback MELDET jetzt, statt zu schweigen.
                //
                // Vorher stand hier ein @ vor dem Aufruf und die Rueckgabe wurde verworfen. Ein
                // Telegramm, das den Socket nie erreicht, war damit nicht von einem gesendeten zu
                // unterscheiden - und das Modul buchte die Fahrt trotzdem als erledigt. Genau
                // diese Blindheit macht die Fehlersuche vom 17./18.08.2026 unmoeglich.
                $send = static function ($frame) use ($socketId): array {
                    if (!function_exists('CSCK_SendText')) {
                        return ['ok' => false, 'status' => 0, 'err' => 'CSCK_SendText nicht vorhanden'];
                    }
                    $status = 0;
                    try {
                        $status = (int) (@\IPS_GetInstance($socketId)['InstanceStatus'] ?? 0);
                    } catch (\Throwable $e) {
                        // Instanz weg oder kein Socket - der Status bleibt 0 und faellt unten auf
                    }
                    try {
                        $r = \CSCK_SendText($socketId, (string) $frame);
                        return ['ok' => ($r !== false), 'status' => $status, 'err' => ''];
                    } catch (\Throwable $e) {
                        return ['ok' => false, 'status' => $status, 'err' => $e->getMessage()];
                    }
                };
                $this->driverInstance = DriverFactory::create('somfy-rts', [
                    'channel'    => $channel,
                    'repeat'     => (int) ($cfg['repeat'] ?? 3),
                    'stopRepeat' => (int) ($cfg['stopRepeat'] ?? 4),
                    'gapMs'      => (int) ($cfg['gapMs'] ?? 50),
                    'busGapMs'   => (int) ($cfg['busGapMs'] ?? self::BUS_GAP_MS),
                    'invert'     => (bool) ($cfg['invert'] ?? false),
                ], $send);
            } elseif ($driverId === 'hm-shutter') {
                // Homematic-Rollo/Markise: LEVEL-Datenpunkt (absolut + Feedback).
                $levelVarId = (int) ($cfg['levelVarId'] ?? 0);
                if ($levelVarId <= 0) {
                    return null;
                }
                $this->driverInstance = DriverFactory::create('hm-shutter', [
                    'levelVarId' => $levelVarId,
                    'instanceId' => (int) ($cfg['instanceId'] ?? 0),
                    'invert'     => (bool) ($cfg['invert'] ?? true),
                ]);
            }
        } catch (\Throwable $e) {
            $this->SendDebug('HSSH.driver', 'Treiberaufbau fehlgeschlagen: ' . $e->getMessage(), 0);
            $this->driverInstance = null;
        }
        return $this->driverInstance;
    }

    /**
     * Verwaltungs-Hook der Domaene (Manage() hat bereits die Whitelist geprueft).
     * scheduleMode ist fest 'controller' -> KEINE device-Sync-Ops.
     */
    protected function mgmt(string $op, array $args, array $ctx): array
    {
        switch ($op) {
            case 'getLog':
                $entries = json_decode((string) $this->ReadAttributeString('DecisionLog'), true);
                return ['ok' => true, 'room' => \IPS_GetName($this->InstanceID), 'entries' => is_array($entries) ? $entries : []];
            case 'clearLog':
                // Das Log ist reine Nachvollziehbarkeit, keine Betriebsgrundlage - Leeren
                // aendert am Verhalten der Zone nichts. Zahl der verworfenen Eintraege
                // zurueckgeben, damit der Aufrufer weiss, dass wirklich etwas passiert ist.
                $vorher = json_decode((string) $this->ReadAttributeString('DecisionLog'), true);
                $this->WriteAttributeString('DecisionLog', '[]');
                return ['ok' => true, 'cleared' => is_array($vorher) ? count($vorher) : 0];
            case 'configureDriver':
                return $this->mgmtConfigureDriver($args, $ctx);
            case 'configureAutomation':
                return $this->mgmtConfigureAutomation($args);
            case 'setArmed':
                return $this->mgmtSetArmed($args);
            case 'updateProfile':
                return $this->mgmtUpdateProfile($args, $ctx);
            case 'getSchedule':
                return $this->mgmtGetSchedule($args);
            case 'setActivePlan':
                return $this->mgmtSetActivePlan($args);
            case 'command':
                return $this->mgmtCommand($args);
            case 'driverProbe':
                return $this->mgmtDriverProbe();
            case 'reconcileProbe':
                return $this->mgmtReconcileProbe();
            case 'getConfig':
                return ['ok' => true, 'config' => $this->cfg()];
            case 'migrateConfig':
                return $this->migrateConfig();
            case 'rotateGeo':
                return $this->mgmtRotateGeo($args);
            case 'referenceRun':
                return $this->mgmtReferenceRun($args);
            case 'calMove':
                return $this->mgmtCalMove($args);
            case 'calStop':
                return $this->mgmtCalStop();
            case 'calSetTime':
                return $this->mgmtCalSetTime($args);
            case 'calAbort':
                return $this->mgmtCalAbort();
            case 'validate':
                return $this->mgmtValidate();
            default:
                // updateProfile/getSchedule/setActivePlan/importLegacy folgen in M6/M7.
                return parent::mgmt($op, $args, $ctx);
        }
    }

    /**
     * Bindet den generischen Shutter-Treiber an eine (IPSShadowing-)Positions-
     * Variable. Schreibt NUR den Store, kein Geraet. automaticId = das
     * IPSShadowing-Automatic-Bool (fuer Cutover/Rollback, M8).
     */
    private function mgmtConfigureDriver(array $args, array $ctx): array
    {
        $driver = (string) ($args['driver'] ?? '');
        if (!in_array($driver, ['', 'generic-shutter', 'somfy-rts', 'hm-shutter'], true)) {
            throw new ContractException('unbekannter Treiber: ' . $driver);
        }
        $config = ['driver' => $driver, 'invert' => (bool) ($args['invert'] ?? false)];

        if ($driver === 'generic-shutter') {
            $positionId  = (int) ($args['positionId'] ?? 0);
            if ($positionId > 0 && function_exists('IPS_VariableExists') && !\IPS_VariableExists($positionId)) {
                throw new ContractException('positionId #' . $positionId . ' ist keine Variable');
            }
            if ($positionId <= 0) {
                throw new ContractException('generic-shutter braucht eine Positions-Variable (positionId)');
            }
            $config['positionId']  = $positionId;
        } elseif ($driver === 'somfy-rts') {
            // Roh-Aktor Somfy RTS: Client-Socket-Instanz + Kanal 1..16 + Fahrzeiten
            // (kein Feedback -> Position wird zeitbasiert geschaetzt).
            $socketId = (int) ($args['socketId'] ?? 0);
            $channel  = (int) ($args['channel'] ?? 0);
            if ($socketId <= 0 || !function_exists('IPS_InstanceExists') || !\IPS_InstanceExists($socketId)) {
                throw new ContractException('somfy-rts braucht die Client-Socket-Instanz (socketId)');
            }
            if ($channel < 1 || $channel > 16) {
                throw new ContractException('somfy-rts Kanal muss 1..16 sein');
            }
            $config['socketId']    = $socketId;
            $config['channel']     = $channel;
            $config['repeat']      = max(1, (int) ($args['repeat'] ?? 2));
            $config['timeOpening'] = max(0, (int) ($args['timeOpening'] ?? 0));
            $config['timeClosing'] = max(0, (int) ($args['timeClosing'] ?? 0));
        } elseif ($driver === 'hm-shutter') {
            // Homematic-Rollo/Markise: LEVEL-Variable (aktionsfaehig) aus der Instanz aufloesen.
            $instanceId = (int) ($args['instanceId'] ?? 0);
            if ($instanceId <= 0 || !function_exists('IPS_InstanceExists') || !\IPS_InstanceExists($instanceId)) {
                throw new ContractException('hm-shutter braucht die Homematic-Instanz (instanceId)');
            }
            $levelVarId = (int) ($args['levelVarId'] ?? 0);
            if ($levelVarId <= 0) {
                $levelVarId = (int) (@\IPS_GetObjectIDByIdent('LEVEL', $instanceId) ?: 0);
            }
            if ($levelVarId <= 0 || !\IPS_VariableExists($levelVarId)) {
                throw new ContractException('LEVEL-Variable der Instanz #' . $instanceId . ' nicht gefunden');
            }
            $config['instanceId'] = $instanceId;
            $config['levelVarId'] = $levelVarId;
            $config['invert']     = (bool) ($args['invert'] ?? true);
        }

        if (!empty($ctx['dryrun'])) {
            return ['ok' => true, 'dryrun' => true, 'config' => $config, 'scheduleMode' => 'controller'];
        }

        $this->applyConfigProperties($config, true); // Properties + ApplyChanges (Treiber/Links/Refs/Timer neu)
        $active = $this->driver() instanceof IShutter;

        return ['ok' => true, 'config' => $this->cfg(), 'scheduleMode' => 'controller', 'driverActive' => $active];
    }

    /**
     * Loesch-Schutz: registriert die real gebundenen Objekte als Instanz-Referenzen,
     * damit Symcon beim Loeschen warnt ("wird von Beschattung X verwendet"). Bei
     * jedem Rebind alte Referenzen entfernen und neu setzen. RegisterReference
     * schuetzt Variablen/Instanzen (Positions-Var, Client-Socket, Sensoren) — NICHT
     * die IPSLibrary-Klassendatei; der Somfy-Treiber lebt jetzt in HomeSuite.
     */
    private function syncReferences(): void
    {
        if (!method_exists($this, 'GetReferenceList')) {
            return;
        }
        foreach ($this->GetReferenceList() as $ref) {
            @$this->UnregisterReference($ref);
        }
        $cfg = $this->cfg();
        $driver = (string) ($cfg['driver'] ?? '');
        $ids = [];
        // Nur die vom AKTIVEN Treiber real genutzten Bindungen referenzieren:
        //  - generic-shutter: Positions-Variable
        //  - somfy-rts: Client-Socket (NICHT die alte IPSShadowing-Position-Variable,
        //    sonst bliebe IPSShadowing faelschlich unloeschbar)
        // env/doors immer (Safety/Sonne).
        $keys = [];
        if ($driver === 'generic-shutter') {
            $keys[] = 'positionId';
        }
        if ($driver === 'somfy-rts') {
            $keys[] = 'socketId';
        }
        if ($driver === 'hm-shutter') {
            $keys[] = 'levelVarId';
            $keys[] = 'instanceId';
        }
        foreach ($keys as $k) {
            $id = (int) ($cfg[$k] ?? 0);
            if ($id > 0) {
                $ids[$id] = true;
            }
        }
        $env = $cfg['env'] ?? [];
        if (is_array($env)) {
            foreach ($env as $id) {
                if ((int) $id > 0) {
                    $ids[(int) $id] = true;
                }
            }
        }
        foreach (($cfg['doorIds'] ?? []) as $id) {
            if ((int) $id > 0) {
                $ids[(int) $id] = true;
            }
        }
        foreach (array_keys($ids) as $id) {
            if (function_exists('IPS_ObjectExists') && @\IPS_ObjectExists($id)) {
                @$this->RegisterReference($id);
            }
        }
    }

    /**
     * Read-only-Diagnose (KEIN Geraeteschreiben): aktuelle Ist-Position + Treiber-
     * Faehigkeiten. Dient dem HAL-Bindungstest (M1) und dem Trockenlauf (M7).
     */
    private function mgmtDriverProbe(): array
    {
        $vars = $this->scheduleVariants();
        $idx  = $this->activeVariantIndex();
        $base = [
            'ok'            => true,
            'variants'      => $vars,
            'activeIndex'   => $idx,
            'activeVariant' => $vars[$idx] ?? null,
        ];
        $drv = $this->driver();
        if (!$drv instanceof IShutter) {
            return $base + ['driverActive' => false, 'position' => null, 'capabilities' => null];
        }
        $pos = $drv->readPosition();
        return $base + [
            'driverActive' => true,
            'position'     => $pos,
            'known'        => $pos !== IShutter::POS_UNKNOWN,
            'capabilities' => $drv->capabilities(),
        ];
    }

    /**
     * Sonnenautomatik + Sicherheit konfigurieren (Store-only, kein Geraet).
     * geoProfile = Raum-Sonnenprofil {azimuthBgn,azimuthEnd,elevation,brightnessMin?,closePct?};
     * env = Sensor-Objekt-IDs {sunAzId,sunElId,windId,rainId,brightId}; windStormKmh; safePos.
     */
    private function mgmtConfigureAutomation(array $args): array
    {
        $patch = [];
        if (array_key_exists('geoProfile', $args)) {
            $gp = $args['geoProfile'];
            if ($gp !== null && !is_array($gp)) {
                throw new ContractException('geoProfile muss ein Objekt/null sein');
            }
            $patch['geoProfile'] = $gp;
        }
        if (array_key_exists('env', $args)) {
            if (!is_array($args['env'])) {
                throw new ContractException('env muss ein Objekt sein');
            }
            $patch['env'] = array_map('intval', $args['env']);
        }
        if (array_key_exists('windStormKmh', $args)) {
            $patch['windStormKmh'] = (float) $args['windStormKmh'];
        }
        if (array_key_exists('safePos', $args)) {
            $patch['safePos'] = max(0, min(100, (int) $args['safePos']));
        }
        // Wetter-/Temp-/Tag-Profil-Felder (vom Hub gepusht):
        if (array_key_exists('rainClose', $args)) { $patch['rainClose'] = (bool) $args['rainClose']; }
        if (array_key_exists('tempGate', $args)) {
            // MISCHEN statt ersetzen: die Temperatur-FUEHLER (sensorId/outSensorId) gehoeren
            // zum Rollo - jeder Raum hat seinen eigenen -, die SCHWELLEN kommen aus dem
            // geteilten Profil. Ein Profil-Push traegt keine Sensor-IDs; wuerde er den
            // tempGate komplett ersetzen, stuenden die Fuehler danach auf 0 und das Gate
            // waere still wirkungslos (genau so verlor Buero seinen Innenfuehler).
            if (!is_array($args['tempGate'])) {
                $patch['tempGate'] = null;                       // ausdrueckliche Abwahl
            } else {
                // Ueberlagern: was der Aufrufer NICHT nennt, bleibt stehen. So kann das
                // Profil nur Schwellen schicken und das Rollo-Widget nur Fuehler, ohne
                // sich gegenseitig zu ueberschreiben.
                $old = $this->cfgVal('tempGate', null);
                $old = is_array($old) ? $old : [];
                $patch['tempGate'] = array_merge($old, $args['tempGate']);
            }
        }
        if (array_key_exists('dayBegin', $args))  { $patch['dayBegin']  = is_array($args['dayBegin']) ? $args['dayBegin'] : null; }
        if (array_key_exists('dayEnd', $args))    { $patch['dayEnd']    = is_array($args['dayEnd']) ? $args['dayEnd'] : null; }
        if (array_key_exists('doorIds', $args)) {
            if (!is_array($args['doorIds'])) {
                throw new ContractException('doorIds muss eine Liste sein');
            }
            $patch['doorIds'] = array_values(array_map('intval', $args['doorIds']));
        }
        // Sonnenzeit-Quelle: Location-Instanz ODER eigene Koordinaten.
        if (array_key_exists('sunSource', $args)) {
            $patch['sunSource'] = ((string) $args['sunSource'] === 'coords') ? 'coords' : 'location';
        }
        if (array_key_exists('locationId', $args)) { $patch['locationId'] = (int) $args['locationId']; }
        if (array_key_exists('lat', $args))        { $patch['lat'] = (float) $args['lat']; }
        if (array_key_exists('lon', $args))        { $patch['lon'] = (float) $args['lon']; }
        if ($patch !== []) {
            // Flache Felder -> Properties, komplexe (geoProfile/env/tempGate/dayBegin/dayEnd/doorIds) -> Store.
            $this->applyConfigProperties($patch, true); // ApplyChanges re-registriert Watches
            // Sonnenprofil-Edit -> Baum-Variablen (Wahrheit) nachziehen.
            if (isset($patch['geoProfile']) && is_array($patch['geoProfile'])) { $this->seedGeoVars($patch['geoProfile']); }
            // Abwahl (geoProfile = null): die Baum-Variablen sind die Wahrheit fuer
            // geoProfile() - sie MUESSEN mit genullt werden. Sonst bliebe das alte
            // Sonnenfenster stehen und das Rollo verschattete weiter nach einem
            // Profil, das gar nicht mehr zugewiesen ist.
            if (array_key_exists('geoProfile', $patch) && $patch['geoProfile'] === null) { $this->clearGeoVars(); }
        }
        return ['ok' => true, 'config' => $patch];
    }

    /**
     * Positions-Wochenplan einer Variante/eines Tages schreiben (val 0..100).
     * variant = Kreuzprodukt-Name „Plan · Season" oder Index in scheduleVariants().
     */
    private function mgmtUpdateProfile(array $args, array $ctx): array
    {
        $variant = $this->normalizeVariant($args['variant'] ?? '');
        $day     = (int) ($args['day'] ?? -1);
        if ($day < 0 || $day > 6) {
            throw new ContractException('day muss 0..6 sein');
        }
        $slots = (isset($args['slots']) && is_array($args['slots'])) ? $args['slots'] : [];
        $clean = [];
        foreach ($slots as $s) {
            if (!is_array($s)) {
                continue;
            }
            $hasEnd    = isset($s['end']);
            $hasAnchor = isset($s['anchor']) && SunTimes::isAnchor((string) $s['anchor']);
            if (!$hasEnd && !$hasAnchor) {
                continue;
            }
            $val   = max(self::POS_MIN, min(self::POS_MAX, (int) round((float) ($s['val'] ?? 0))));
            $entry = ['val' => $val];
            if ($hasAnchor) {
                $entry['anchor'] = (string) $s['anchor'];
                $entry['offset'] = (int) ($s['offset'] ?? 0);
            }
            // Nominale Endzeit fuer Speicherung/Sortierung (verankerte werden zur Laufzeit re-aufgeloest).
            $entry['end'] = $hasEnd ? (int) $s['end'] : $this->resolveEnd($entry, $this->sunEvents(time()));
            $clean[] = $entry;
        }
        if (!empty($ctx['dryrun'])) {
            return ['ok' => true, 'dryrun' => true, 'variant' => $variant, 'day' => $day, 'slots' => $clean];
        }
        $this->schedules()->setSlots($variant, $day, $clean);

        $drv = $this->driver();
        if ($drv instanceof IShutter) {
            $this->reconcile($drv); // controller: neuen Plan nachfahren (Schatten-Modus bis armed)
        }
        return ['ok' => true, 'variant' => $variant, 'day' => $day, 'slots' => $this->schedules()->getSlots($variant, $day)];
    }

    /** Kompletter Wochenplan einer Variante (fuer Editor/Verify). */
    private function mgmtGetSchedule(array $args): array
    {
        $variant = $this->normalizeVariant($args['variant'] ?? '');
        $week = [];
        for ($d = 0; $d < 7; $d++) {
            $week[$d] = $this->schedules()->getSlots($variant, $d);
        }
        return ['ok' => true, 'variant' => $variant, 'week' => $week, 'activeVariant' => $this->activeVariant(),
            'variants' => $this->scheduleVariants(), 'sunEvents' => $this->sunEvents(time()), 'anchors' => array_keys(SunTimes::ANCHORS)];
    }

    /** Setzt Plan (0..2) und/oder Season (0..1) ueber die native RequestAction. */
    private function mgmtSetActivePlan(array $args): array
    {
        $res = ['ok' => true];
        if (array_key_exists('plan', $args)) {
            $p = (int) $args['plan'];
            if ($p < 0 || $p >= count(self::PLAN_VARIANTS)) {
                throw new ContractException('plan muss 0..' . (count(self::PLAN_VARIANTS) - 1) . ' sein');
            }
            $this->RequestAction('Plan', $p);
            $res['plan'] = $p;
        }
        if (array_key_exists('season', $args)) {
            $s = (int) $args['season'];
            if ($s < 0 || $s >= count(self::SEASON_VARIANTS)) {
                throw new ContractException('season muss 0..' . (count(self::SEASON_VARIANTS) - 1) . ' sein');
            }
            $this->RequestAction('Season', $s);
            $res['season'] = $s;
        }
        $res['variant'] = $this->activeVariant();
        return $res;
    }

    /** Variantennamen (Kreuzprodukt) normalisieren: Name aus scheduleVariants() oder Index. */
    private function normalizeVariant($v): string
    {
        $vars = $this->scheduleVariants();
        if (is_numeric($v)) {
            return $vars[(int) $v] ?? $vars[0];
        }
        $v = (string) $v;
        return in_array($v, $vars, true) ? $v : $vars[0];
    }

    /**
     * Generischer Bedien-Op (Visu -> Entitaet): setzt einen aktionablen Control
     * ueber die native RequestAction. Nur Whitelist-Idents.
     */
    private function mgmtCommand(array $args): array
    {
        $ident = (string) ($args['ident'] ?? '');
        if (!in_array($ident, ['Position', 'Movement', 'Mode', 'Plan', 'Season'], true)) {
            throw new ContractException('command: ident nicht erlaubt: ' . $ident);
        }
        $this->RequestAction($ident, $args['value'] ?? 0);
        return ['ok' => true, 'ident' => $ident, 'value' => $args['value'] ?? 0];
    }


    /**
     * Scharfschalten (armed=true -> reconcile faehrt wirklich) bzw. zurueck in den
     * Schatten-Modus (armed=false -> nur berechnen/loggen). M8-Cutover je Geraet.
     */
    private function mgmtSetArmed(array $args): array
    {
        $armed = (bool) ($args['armed'] ?? false);
        @\IPS_SetProperty($this->InstanceID, 'Armed', $armed);
        @\IPS_ApplyChanges($this->InstanceID);
        return ['ok' => true, 'armed' => $this->armed()];
    }

    /** Sonnenprofil dieser Zone aus den BAUM-VARIABLEN (Quelle der Wahrheit); Fallback Store. */
    private function geoProfile(): ?array
    {
        if (@$this->GetIDForIdent('SunAzBgn')) {
            $bgn = (int) @$this->GetValue('SunAzBgn');
            $end = (int) @$this->GetValue('SunAzEnd');
            $el  = (int) @$this->GetValue('SunElev');
            if ($bgn !== 0 || $end !== 0 || $el !== 0) {
                $st = $this->cfgVal('geoProfile', null); $st = is_array($st) ? $st : [];
                // Schliessgrad pro Rollo aus SunClose (Wahrheit); 0/fehlend -> Store bzw. 100.
                $cp = @$this->GetIDForIdent('SunClose') ? (int) @$this->GetValue('SunClose') : 0;
                if ($cp <= 0) { $cp = (int) ($st['closePct'] ?? 100); }
                // brightnessOff MUSS mit: computeDecision setzt damit die Hysterese
                // (laufende Beschattung haelt bis zur niedrigeren AUS-Schwelle). Fehlte der
                // Wert hier, war er dort immer 0 -> die Hysterese griff nie, und die
                // Beschattung fiel schon an der EIN-Schwelle wieder heraus (19.08.2026:
                // Strahlung 18:08 unter 300, Aufblenden 18:13 bei 281 W/m2 statt bei 180).
                return ['azimuthBgn' => $bgn, 'azimuthEnd' => $end, 'elevation' => $el,
                        'closePct' => $cp, 'brightnessMin' => (int) ($st['brightnessMin'] ?? 0),
                        'brightnessOff' => (int) ($st['brightnessOff'] ?? 0),
                        // Klarheits-Schwellen wie brightnessOff aus dem Store durchreichen -
                        // sie haben keine eigene Baum-Variable, wuerden hier sonst verschwinden.
                        'clearMin' => (int) ($st['clearMin'] ?? 0),
                        'clearOff' => (int) ($st['clearOff'] ?? 0)];
            }
        }
        $st = $this->cfgVal('geoProfile', null);
        return is_array($st) ? $st : null;
    }

    /** Setzt das Sonnenprofil in die Baum-Variablen (Seed aus Store bzw. Editor-Write). */
    private function seedGeoVars(array $gp): void
    {
        if (!@$this->GetIDForIdent('SunAzBgn')) { return; }
        @$this->SetValue('SunAzBgn', (int) ($gp['azimuthBgn'] ?? 0));
        @$this->SetValue('SunAzEnd', (int) ($gp['azimuthEnd'] ?? 0));
        @$this->SetValue('SunElev', (int) ($gp['elevation'] ?? 0));
        // Schliessgrad nur seeden, wenn noch nicht sinnvoll gesetzt (>0), damit ein
        // per-Rollo-Override nicht bei jeder Profilzuweisung ueberschrieben wird.
        if (@$this->GetIDForIdent('SunClose') && (int) @$this->GetValue('SunClose') <= 0) {
            @$this->SetValue('SunClose', (int) ($gp['closePct'] ?? 100));
        }
    }

    /**
     * Sonnenprofil der Zone loeschen: Baum-Variablen auf 0. geoProfile() liefert danach
     * null (alle drei Winkel 0 UND kein Store-Profil), computeDecision laesst die
     * Sonnenregel damit komplett weg - das Rollo folgt nur noch Zeitplan und Sicherheit.
     * SunClose bleibt stehen: das ist der Schliessgrad-Wunsch des Rollos, kein Profilwert.
     */
    private function clearGeoVars(): void
    {
        foreach (['SunAzBgn', 'SunAzEnd', 'SunElev'] as $i) {
            if (@$this->GetIDForIdent($i)) { @$this->SetValue($i, 0); }
        }
    }

    /** Dreht das Zonen-Sonnenprofil (Baum-Variablen SunAzBgn/End) um deltaDeg (Nordausrichtung). */
    private function mgmtRotateGeo(array $args): array
    {
        $delta = (float) ($args['deltaDeg'] ?? 0);
        if (!@$this->GetIDForIdent('SunAzBgn')) { return ['ok' => true, 'skipped' => 'no controls']; }
        $rot = function ($v) use ($delta) { $n = fmod(((float) $v + $delta), 360.0); if ($n < 0) { $n += 360.0; } return (int) round($n); };
        @$this->SetValue('SunAzBgn', $rot((int) @$this->GetValue('SunAzBgn')));
        @$this->SetValue('SunAzEnd', $rot((int) @$this->GetValue('SunAzEnd')));
        return ['ok' => true, 'azimuthBgn' => (int) @$this->GetValue('SunAzBgn'), 'azimuthEnd' => (int) @$this->GetValue('SunAzEnd')];
    }

    /**
     * TROCKENLAUF (read-only, kein Geraeteschreiben): liefert die Regel-Entscheidung
     * inkl. Zwischengroessen, ohne zu fahren und ohne den Debounce-State zu
     * veraendern. Kern des M7-Vergleichs gegen IPSShadowing.
     */
    private function mgmtReconcileProbe(): array
    {
        $drv = $this->driver();
        if (!$drv instanceof IShutter) {
            return ['ok' => true, 'driverActive' => false];
        }
        $d      = $this->computeDecision($drv, false);
        $armed  = (bool) $this->cfgVal('armed', false);
        $target = $d['target'];
        $drift  = ($d['cur'] === IShutter::POS_UNKNOWN) || ($target !== null && abs($d['cur'] - $target) > self::POS_TOLERANCE);
        // Ist-Position fuer die Anzeige: bei rueckmeldungslosen Treibern (Somfy) auf die
        // gespiegelte ActualPosition (IPSShadowing-Position) zurueckfallen statt UNKNOWN.
        $curIst = $d['cur'];
        if ($curIst === IShutter::POS_UNKNOWN) { $ap = @$this->GetValue('ActualPosition'); if (is_numeric($ap)) { $curIst = (int) $ap; } }
        return [
            'ok'           => true,
            'driverActive' => true,
            'armed'        => $armed,
            'current'      => $curIst,
            'target'       => $target,
            'wouldMove'    => ($armed && $target !== null && $drift && !$d['blockedByDoor']),
            'doorOpen'     => $d['doorOpen'],
            'blockedByDoor' => $d['blockedByDoor'],
            'doorIds'      => array_values(array_map('intval', (array) $this->cfgVal('doorIds', []))),
            'mode'         => $d['mode'],
            'variant'      => $d['variant'],
            'held'         => $d['held'],
            'storm'        => $d['storm'],
            'rawSun'       => $d['rawSun'],
            'sunTarget'    => $d['sunTarget'],
            'schedTarget'  => $d['schedTarget'],
            'clearIdx'     => $d['clearIdx'] ?? null,
            'clearThr'     => $d['clearThr'] ?? null,
            'inputs'       => $d['inp'],
            'sunEvents'    => $this->sunEvents(time()),
            'geoProfile'   => $this->geoProfile(),
        ];
    }

    // ==================================================================
    // Lebenszyklus: Refresh-Timer + Watches
    // ==================================================================

    protected function setupTimers(): void
    {
        $this->RegisterTimer(self::TIMER_REFRESH, 0, 'HSSH_Refresh($_IPS[\'TARGET\']);');
        $this->RegisterTimer(self::TIMER_MOVE, 0, 'HSSH_MoveDone($_IPS[\'TARGET\']);');
    }

    // Effektives Refresh-Intervall in ms aus der Property QueryInterval (Sekunden).
    // Untergrenze 2s, damit ein versehentlicher 0-Wert keine Endlosschleife ausloest.
    private function refreshMs(): int
    {
        return max(2, $this->ReadPropertyInteger('QueryInterval')) * 1000;
    }

    /**
     * Fester Startversatz dieser Instanz innerhalb eines Abfragezyklus (0 .. Intervall).
     * Aus der Instanz-ID abgeleitet, damit derselbe Rollo immer denselben Platz bekommt und
     * ein Sweep ueber alle Instanzen sie trotzdem gleichmaessig verteilt.
     */
    private function phaseOffsetMs(): int
    {
        $slots = 16;                                   // so viele Rollos teilen sich den Funkbus
        return (int) (($this->InstanceID % $slots) * ($this->refreshMs() / $slots));
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->driverResolved = false;
        $this->driverInstance = null;
        $this->migrateAutomationProps(); // Store -> Properties (Tuerkontakte/Sensoren), einmalig

        $drv    = $this->driver();
        $active = $drv instanceof IShutter;
        // PHASENVERSATZ, NICHT GLEICHTAKT.
        //
        // SetTimerInterval zaehlt ab JETZT. Laeuft ApplyChanges ueber mehrere Instanzen -
        // ein Konfigurationslauf, ein Modul-Reload, eine Migration -, starten danach alle
        // Rollo-Timer in derselben Sekunde, und beim ersten Tick faehrt die ganze Fassade
        // gleichzeitig los. Am 17.08.2026 gingen so 15 Fahrbefehle in 12 Sekunden ueber
        // EINEN Funksender; gefahren ist genau eines. Dieselbe Menge lief morgens ueber die
        // Minute verteilt fehlerfrei - der Unterschied war allein der Gleichtakt.
        //
        // Der erste Tick bekommt deshalb einen festen, aus der Instanz-ID abgeleiteten
        // Aufschlag; Refresh() setzt danach das normale Intervall. Der Versatz ist stabil
        // (gleiche Instanz -> gleicher Platz im Reigen) und braucht keinen Zufall.
        $this->SetTimerInterval(self::TIMER_REFRESH, $active ? ($this->refreshMs() + $this->phaseOffsetMs()) : 0);
        $this->registerWatches($drv);
        $this->syncReferences();
        $this->updateHealth();

        // Uebergang Store->Baum: Sonnenprofil-Variablen aus dem (bereits korrigierten)
        // Store einmalig seeden, solange sie leer sind. Danach sind die Variablen die Wahrheit.
        if (@$this->GetIDForIdent('SunAzBgn')) {
            $b = (int) @$this->GetValue('SunAzBgn'); $e = (int) @$this->GetValue('SunAzEnd'); $l = (int) @$this->GetValue('SunElev');
            if ($b === 0 && $e === 0 && $l === 0) {
                $st = $this->cfgVal('geoProfile', null);
                if (is_array($st)) { $this->seedGeoVars($st); }
            }
        }
    }

    /**
     * Konsole: native Bindungs-Ansicht (additiv, ohne Properties -> wirkt sofort
     * auf Bestandsinstanzen, kein Kernel-Neustart). Die Felder sind mit der
     * aktuellen Store-Bindung vorbelegt; „Bindung uebernehmen" ruft configureDriver.
     * Sicherheits-Schwellen und Sonnenprofil kommen aus den geteilten Profilen.
     */
    // ==================================================================
    // Oeffentliche Scripting-Prozeduren (-> HSSH_SetPosition / _Move …)
    // Duenne Fassaden ueber SetControl/GetControlValue; realer Effekt nur bei Armed=true.
    // ==================================================================

    public function SetPosition(int $Percent): bool { return $this->setControlValue('Position', $Percent); }

    /** Richtung: up|auf|1 / down|ab|zu|2 / stop|0. */
    public function Move(string $Direction): bool
    {
        $map = ['up' => 1, 'auf' => 1, '1' => 1, 'down' => 2, 'ab' => 2, 'zu' => 2, '2' => 2, 'stop' => 0, '0' => 0];
        $k = strtolower(trim($Direction));
        if (!isset($map[$k])) { $this->LogMessage("HSSH.Move: unbekannte Richtung '{$Direction}'", KL_ERROR); return false; }
        return $this->setControlValue('Movement', $map[$k]);
    }
    public function MoveUp(): bool   { return $this->setControlValue('Movement', 1); }
    public function MoveDown(): bool { return $this->setControlValue('Movement', 2); }
    public function MoveStop(): bool { return $this->setControlValue('Movement', 0); }

    public function SetMode(int $Mode): bool             { return $this->setControlValue('Mode', $Mode); }
    public function SetPlan(int $Plan): bool             { return $this->setControlValue('Plan', $Plan); }
    public function SetSeason(int $Season): bool         { return $this->setControlValue('Season', $Season); }
    public function SetSunAzimuthBegin(int $Deg): bool   { return $this->setControlValue('SunAzBgn', $Deg); }
    public function SetSunAzimuthEnd(int $Deg): bool     { return $this->setControlValue('SunAzEnd', $Deg); }
    public function SetSunElevation(int $Deg): bool      { return $this->setControlValue('SunElev', $Deg); }

    /** Scharf/Schatten (Cutover). Achtung: schaltet reale Rollo-Telegramme frei. */
    public function SetArmed(bool $Armed): bool
    {
        $r = json_decode($this->Manage(json_encode(['op' => 'setArmed', 'args' => ['armed' => $Armed]])), true);
        return is_array($r) && (isset($r['armed']) ? (bool) $r['armed'] : (!empty($r['ok']) ? $Armed : false));
    }

    public function GetPosition(): int       { return (int) $this->GetControlValue('Position'); }
    public function GetActualPosition(): int { return (int) $this->GetControlValue('ActualPosition'); }
    public function GetMode(): int           { return (int) $this->GetControlValue('Mode'); }
    public function IsOnline(): bool         { return (bool) $this->GetControlValue('Online'); }

    public function GetConfigurationForm()
    {
        $cfg = $this->cfg();
        // Aussperr-Schutz: markenuebergreifend erkannte Kontakte als Auswahl-Optionen.
        $contacts = \Hoep\HomeSuite\Engines\Contacts::detect(true);
        $copts = [['caption' => '— Kontakt waehlen —', 'value' => 0]];
        foreach ($contacts as $c) {
            $copts[] = ['caption' => $c['instance'] . ' · ' . $c['var'] . '  (#' . $c['id'] . ')', 'value' => (int) $c['id']];
        }
        $doorVals = array_map(static function ($id) {
            return ['varId' => (int) $id];
        }, array_values(array_map('intval', (array) ($cfg['doorIds'] ?? []))));
        $active   = $this->driver() instanceof IShutter;
        $rt       = $this->readRt();
        $estKnown = !empty($rt['posKnown']);
        $armed    = (bool) ($cfg['armed'] ?? false);
        // Umgebungs-Sensoren (aus config.env, sonst Standort-Defaults).
        $env       = is_array($cfg['env'] ?? null) ? $cfg['env'] : [];
        $envSunAz  = (int) ($env['sunAzId']  ?? self::SUN_AZ_ID);
        $envSunEl  = (int) ($env['sunElId']  ?? self::SUN_EL_ID);
        $envWind   = (int) ($env['windId']   ?? self::WIND_ID);
        $envRain   = (int) ($env['rainId']   ?? self::RAIN_ID);
        $envBright = (int) ($env['brightId'] ?? self::BRIGHT_ID);
        // Sonnenzeit-Quelle: Location-Instanz ODER eigene Koordinaten.
        $sunSource  = ((string) ($cfg['sunSource'] ?? 'location') === 'coords') ? 'coords' : 'location';
        $locationId = (int) ($cfg['locationId'] ?? 0);
        $lat        = (float) ($cfg['lat'] ?? 0.0);
        $lon        = (float) ($cfg['lon'] ?? 0.0);
        $status   = 'Treiber: ' . ($active ? 'aktiv' : 'inaktiv')
            . ' · scharf: ' . ($armed ? 'JA (faehrt real)' : 'nein (Schatten-Modus)')
            . ' · Position: ' . ($estKnown ? ((int) ($rt['estPos'] ?? 0) . '% (geschaetzt)') : 'unbekannt (Referenzfahrt noetig)');
        $drvSel  = (string) ($cfg['driver'] ?? '');
        $geoNow  = $this->geoProfile();
        $geoNow  = is_array($geoNow) ? $geoNow : [];
        $brightNow = ($envBright > 0 && @\IPS_VariableExists($envBright)) ? @GetValue($envBright) : null;
        $brightNow = is_numeric($brightNow) ? (float) $brightNow : null;
        $eff    = static function (int $own, int $effective) {
            return $own > 0 ? '' : ' — aktuell #' . $effective . ' (Haus-Vorgabe)';
        };
        // WICHTIG: jedes Feld traegt seinen ECHTEN Property-Namen. Frueher hiessen sie
        // cfgDriver/cfgDoors/cfgEnv… und wurden nur ueber Extra-Buttons gespeichert -
        // das normale "Uebernehmen" verwarf sie kommentarlos, das Formular kam leer
        // zurueck. Jetzt speichert "Uebernehmen" alles, die Extra-Buttons entfallen.
        $el = [
            ['type' => 'Label', 'caption' => 'Beschattung — Geraete-Bindung. Sicherheits-Schwellen, Sonnen- und '
                . 'Wetterprofile kommen aus den geteilten Profilen (LiveViewBuilder), nicht hier.'],
            ['type' => 'NumberSpinner', 'name' => 'QueryInterval', 'caption' => 'Abfrage-Intervall (s)'],
            ['type' => 'Select', 'name' => 'Driver', 'caption' => 'Treiber', 'options' => [
                ['caption' => '(keiner / Schatten-Modus)', 'value' => ''],
                ['caption' => 'Absolutposition 0..100 (generisch)', 'value' => 'generic-shutter'],
                ['caption' => 'Somfy RTS (Bus-Rollo: auf/ab/stop + Fahrzeiten)', 'value' => 'somfy-rts'],
                ['caption' => 'Homematic-Rollo/Markise (LEVEL, absolut)', 'value' => 'hm-shutter'],
            ]],
        ];
        // Nur die Felder des GEWAEHLTEN Treibers zeigen. Vorher standen alle drei Bloecke
        // untereinander - ein Somfy-Rollo bemaengelte dann eine fehlende Absolutpositions-
        // Variable, die es bauartbedingt gar nicht haben kann.
        if ($drvSel === 'generic-shutter') {
            $el[] = ['type' => 'Label', 'caption' => '— Absolutposition (generisch) —'];
            $el[] = ['type' => 'SelectVariable', 'name' => 'PositionId',
                'caption' => 'Positions-Variable (0..100, mit Rueckmeldung)'];
        } elseif ($drvSel === 'somfy-rts') {
            $el[] = ['type' => 'Label', 'caption' => '— Somfy RTS (Bus-Rollo ohne Positions-Rueckmeldung) —'];
            $el[] = ['type' => 'SelectInstance', 'name' => 'SocketId', 'caption' => 'Client-Socket (RTS-Gateway)'];
            $el[] = ['type' => 'NumberSpinner', 'name' => 'Channel', 'caption' => 'RTS-Kanal (1..16)',
                'minimum' => 0, 'maximum' => 16];
            $el[] = ['type' => 'RowLayout', 'items' => [
                ['type' => 'NumberSpinner', 'name' => 'TimeClosing', 'caption' => 'Fahrzeit ZU (0->100) in s',
                    'minimum' => 0, 'maximum' => 300],
                ['type' => 'NumberSpinner', 'name' => 'TimeOpening', 'caption' => 'Fahrzeit AUF (100->0) in s',
                    'minimum' => 0, 'maximum' => 300],
                ['type' => 'NumberSpinner', 'name' => 'Repeat', 'caption' => 'Sende-Wiederholungen',
                    'minimum' => 1, 'maximum' => 8],
            ]];
            $el[] = ['type' => 'Label', 'caption' => 'Ohne Rueckmeldung wird die Position aus den Fahrzeiten '
                . 'geschaetzt — sie ist erst nach einer Referenzfahrt bekannt. Eine Positions-Variable gibt es '
                . 'bei RTS nicht; sie wird deshalb hier auch nicht verlangt.'];
        } elseif ($drvSel === 'hm-shutter') {
            $el[] = ['type' => 'Label', 'caption' => '— Homematic-Rollo/Markise (LEVEL-Datenpunkt, absolut) —'];
            $el[] = ['type' => 'SelectInstance', 'name' => 'InstanceId', 'caption' => 'Homematic-Instanz (LEVEL/STOP)'];
            $el[] = ['type' => 'SelectVariable', 'name' => 'LevelVarId',
                'caption' => 'LEVEL-Variable (optional — sonst aus der Instanz)'];
        } else {
            $el[] = ['type' => 'Label', 'caption' => 'Kein Treiber gewaehlt: die Automatik rechnet und '
                . 'protokolliert, faehrt aber nichts. Treiber waehlen und "Uebernehmen" — danach erscheinen '
                . 'die passenden Felder.'];
        }
        if ($drvSel !== '') {
            $el[] = ['type' => 'Select', 'name' => 'DeviceKind', 'caption' => 'Art des Beschattungselements',
                'options' => [['caption' => 'Rollo / Jalousie', 'value' => 'shutter'],
                              ['caption' => 'Markise', 'value' => 'awning']]];
            $el[] = ['type' => 'CheckBox', 'name' => 'Invert',
                'caption' => 'Richtung invertieren (auf/ab bzw. LEVEL 1=offen / 0=zu..100=offen)'];
        }
        $el[] = ['type' => 'Label', 'caption' => $status];
        $el[] = ['type' => 'RowLayout', 'items' => [
            ['type' => 'Button', 'caption' => 'Referenzfahrt: voll AUF (setzt 0%)', 'onClick' =>
                'echo HSSH_Manage($id, json_encode(["op"=>"referenceRun","args"=>["dir"=>"up"]]));'],
            ['type' => 'Button', 'caption' => 'Referenzfahrt: voll ZU (setzt 100%)', 'onClick' =>
                'echo HSSH_Manage($id, json_encode(["op"=>"referenceRun","args"=>["dir"=>"down"]]));'],
            ['type' => 'Button', 'caption' => 'Bindung pruefen', 'onClick' =>
                'echo HSSH_Manage($id, json_encode(["op"=>"validate"]));'],
        ]];
        $el[] = ['type' => 'Label', 'caption' => '— Aussperr-Schutz (Tuer-/Fensterkontakte) —'];
        $el[] = ['type' => 'Label', 'caption' => 'Bei OFFENEM Kontakt wird das ZUFAHREN blockiert (Auffahren + '
            . 'Sturm-Rueckzug bleiben erlaubt). Kontakte werden markenuebergreifend erkannt (Homematic/HmIP, '
            . 'Z-Wave, Zigbee, Shelly …). Erkannt: ' . count($contacts) . '.'];
        $el[] = ['type' => 'List', 'name' => 'Doors', 'caption' => 'Kontakte', 'rowCount' => 4,
            'add' => true, 'delete' => true,
            'columns' => [['caption' => 'Kontakt', 'name' => 'varId', 'width' => 'auto', 'add' => 0,
                'edit' => ['type' => 'Select', 'options' => $copts]]]];
        $el[] = ['type' => 'Label', 'caption' => '— Umgebungs-Sensoren (Sonne/Wind/Regen/Helligkeit fuer die Automatik) —'];
        $el[] = ['type' => 'Label', 'caption' => 'Leer/0 = die haus-weite Vorgabe aus dem Hub benutzen. Eine eigene '
            . 'Auswahl gilt nur fuer dieses Rollo und schlaegt die Hub-Vorgabe.'];
        $el[] = ['type' => 'RowLayout', 'items' => [
            ['type' => 'SelectVariable', 'name' => 'EnvSunAzId',
                'caption' => 'Sonnen-Azimut (Grad)' . $eff((int) $this->ReadPropertyInteger('EnvSunAzId'), $envSunAz)],
            ['type' => 'SelectVariable', 'name' => 'EnvSunElId',
                'caption' => 'Sonnen-Elevation (Grad)' . $eff((int) $this->ReadPropertyInteger('EnvSunElId'), $envSunEl)],
        ]];
        $el[] = ['type' => 'RowLayout', 'items' => [
            ['type' => 'SelectVariable', 'name' => 'EnvWindId',
                'caption' => 'Wind (km/h)' . $eff((int) $this->ReadPropertyInteger('EnvWindId'), $envWind)],
            ['type' => 'SelectVariable', 'name' => 'EnvRainId',
                'caption' => 'Regen' . $eff((int) $this->ReadPropertyInteger('EnvRainId'), $envRain)],
            ['type' => 'SelectVariable', 'name' => 'EnvBrightId',
                'caption' => 'Helligkeit / Globalstrahlung' . $eff((int) $this->ReadPropertyInteger('EnvBrightId'), $envBright)],
        ]];
        // Frueher standen hier NOCHMAL drei Strahlungsfelder (SunRadId/Min/Off). Das war eine
        // zweite, konkurrierende Sonnen-Schranke neben der im Sonnenprofil - niemand konnte
        // wissen, welche gilt. Es bleibt EINE: die Quelle steht oben (Helligkeit/Globalstrahlung),
        // die Schwellen stehen im Sonnenprofil. Die alten Properties sind auf 0 (= aus) und
        // werden nicht mehr angeboten.
        $el[] = ['type' => 'Label', 'caption' => 'Sonnen-Schranke: die Schwellen stehen im SONNENPROFIL '
            . '(Helligkeit min = einschalten, Sonne aus unter = Hysterese zum Zurueckfahren) und gelten gegen '
            . 'die Variable hier oben — in DEREN Einheit. Aktuell gemessen: '
            . ($brightNow === null ? 'kein Messwert' : sprintf('%.0f', $brightNow))
            . ', Schwellen aus dem zugewiesenen Sonnenprofil: '
            . (int) ($geoNow['brightnessMin'] ?? 0) . ' ein / ' . (int) ($geoNow['brightnessOff'] ?? 0) . ' aus.'
            . ((int) ($geoNow['clearMin'] ?? 0) > 0
                ? (' Zusaetzlich Klarheit (Anteil am Klarhimmel-Wert): '
                   . (int) $geoNow['clearMin'] . '% ein / ' . (int) ($geoNow['clearOff'] ?? 0) . '% aus, aktuell '
                   . (($kcNow = $this->clearIndex($brightNow, $this->envNum('sunElId', self::SUN_EL_ID))) === null
                        ? 'nicht bestimmbar (Sonne zu tief)'
                        : sprintf('%.0f%%', $kcNow * 100)) . '.')
                : ' Klarheits-Schranke aus.')];
        $el[] = ['type' => 'Label', 'caption' => '— Sonnenzeit-Quelle (fuer Sonnen-Anker im Zeitplan) —'];
        $el[] = ['type' => 'Select', 'name' => 'SunSource', 'caption' => 'Quelle', 'options' => [
            ['caption' => 'Location-Instanz (Symcon-Standort)', 'value' => 'location'],
            ['caption' => 'Eigene Koordinaten', 'value' => 'coords'],
        ]];
        $el[] = ['type' => 'SelectInstance', 'name' => 'LocationId', 'caption' => 'Location-Instanz'];
        $el[] = ['type' => 'RowLayout', 'items' => [
            ['type' => 'NumberSpinner', 'name' => 'Lat', 'caption' => 'Breite (lat)', 'digits' => 5,
                'minimum' => -90, 'maximum' => 90],
            ['type' => 'NumberSpinner', 'name' => 'Lon', 'caption' => 'Laenge (lon)', 'digits' => 5,
                'minimum' => -180, 'maximum' => 180],
        ]];
        return json_encode(['elements' => array_merge($el, [
            ['type' => 'Label', 'caption' => '— Scharfschalten —'],
            ['type' => 'Label', 'caption' => 'Aktueller Zustand: ' . ($armed ? 'SCHARF (Automatik faehrt real)' : 'Schatten-Modus (Automatik rechnet/protokolliert nur)')],
            ['type' => 'RowLayout', 'items' => [
                ['type' => 'Button', 'caption' => 'Scharfschalten (faehrt real)', 'onClick' =>
                    'echo HSSH_Manage($id, json_encode(["op"=>"setArmed","args"=>["armed"=>true]]));'],
                ['type' => 'Button', 'caption' => 'Schatten-Modus (nur rechnen)', 'onClick' =>
                    'echo HSSH_Manage($id, json_encode(["op"=>"setArmed","args"=>["armed"=>false]]));'],
            ]],
            ['type' => 'Label', 'caption' => 'Achtung: Referenzfahrt faehrt das Rollo REAL in den Endanschlag (zum Kalibrieren). '
                . 'Somfy: kein Positions-Feedback -> die Position wird aus den Fahrzeiten '
                . 'geschaetzt. Erst nach einer Referenzfahrt (voll auf/zu) ist sie bekannt. Real gefahren wird nur bei '
                . '"scharf"; bis dahin werden Fahrten nur protokolliert (Schatten-Modus).'],
        ])]);
    }

    /** Timer-Callback (prefix HSSH_Refresh): Reflect + Reconcile. Public per SDK. */
    public function Refresh(): void
    {
        $drv = $this->driver();
        if (!$drv instanceof IShutter) {
            return;
        }
        // Nach dem ersten (versetzten) Tick auf das normale Intervall zurueck; der einmal
        // gewonnene Phasenversatz bleibt dabei erhalten, weil ab hier alles gleich lang wartet.
        $this->SetTimerInterval(self::TIMER_REFRESH, $this->refreshMs());
        // Waehrend der Kalibrierung nicht spiegeln: die Ist-Lage ist per Definition
        // unbekannt (roher Motorlauf ohne Bookkeeping) - reconcile() entscheidet
        // selbst, ob Sturm den Lock bricht.
        if (!$this->calLocked()) {
            $this->reflectFromDriver($drv);
        }
        $this->reconcile($drv);
    }

    /** Ist-Position/Online aus dem Treiber spiegeln (nur Statusvariablen). */
    private function reflectFromDriver(IShutter $drv): void
    {
        $pos = $drv->readPosition();
        if ($pos === IShutter::POS_UNKNOWN) {
            $pos = $this->estPos(); // travel-only: geschaetzte Lage spiegeln (oder UNKNOWN)
        }
        if ($pos === IShutter::POS_UNKNOWN) {
            // Feedback-los (Somfy) und noch nicht kalibriert: echte Position der
            // IPSShadowing-Positionsvariable read-only spiegeln (Schatten-Anzeige).
            $legacyPos = (int) $this->cfgVal('positionId', 0);
            if ($legacyPos > 0 && function_exists('IPS_VariableExists') && @\IPS_VariableExists($legacyPos)) {
                $lp = @GetValue($legacyPos);
                if (is_numeric($lp)) { $pos = (int) round((float) $lp); }
            }
        }
        if ($pos !== IShutter::POS_UNKNOWN) {
            $this->setReflect('ActualPosition', $pos);
        }
        $this->setReflect('Online', $pos !== IShutter::POS_UNKNOWN);
    }

    // ==================================================================
    // Zeitbasierter Fahr-Executor (travel-only Treiber ohne Feedback, z. B. Somfy)
    // ==================================================================

    /**
     * Faehrt eine Zielposition an. Absolut-Treiber -> moveTo(). Travel-only
     * (Somfy RTS) -> ShadeKinematics: move(dir) jetzt, Stop per Ein-Schuss-Timer
     * nach der berechneten Fahrdauer. Die geschaetzte Position (estPos) dient als
     * Ausgangslage; ist sie unbekannt, wird NICHT absolut gefahren (Referenzfahrt
     * noetig).
     */
    private function driveTo(IShutter $drv, int $target): bool
    {
        $caps = $drv->capabilities();
        if (!empty($caps['absolutePosition'])) {
            if ($drv->moveTo((float) $target)) {
                $this->SetValue('Position', $target);
                $rt = $this->readRt();
                $rt['lastSet']      = $target;
                $rt['lastAssertTs'] = time();
                $this->writeRt($rt);
                return true;
            }
            return false;
        }

        // travel-only: laufende Fahrt zuerst beenden (Position aus verstrichener Zeit).
        $rt = $this->readRt();
        if (!empty($rt['moving'])) {
            $this->finishMove(true);
        }
        $from = $this->estPos();
        $endstop = ($target === self::POS_MIN || $target === self::POS_MAX);
        if ($from === IShutter::POS_UNKNOWN) {
            if (!$endstop) {
                $this->SendDebug('HSSH.move', 'estPos unbekannt -> Mittelstellung erst nach voller Auf-/Zufahrt (Kalibrierung)', 0);
                return false;
            }
            // Endanschlag ohne bekannte Position: vom Gegen-Endanschlag voll durchfahren (selbstkalibrierend).
            $from = ($target === self::POS_MIN) ? self::POS_MAX : self::POS_MIN;
        }
        // "Nichts zu tun" und "geht nicht" sind ZWEI verschiedene Antworten und duerfen
        // nicht beide false heissen: der Aufrufer nimmt bei false den Sollwert zurueck, und
        // das waere bei einem bereits erreichten Ziel schlicht falsch.
        $tAuf = (float) $this->cfgVal('timeOpening', 0);
        $tZu  = (float) $this->cfgVal('timeClosing', 0);
        if ($tAuf <= 0 || $tZu <= 0) {
            $this->SendDebug('HSSH.move', 'keine Fahrzeiten konfiguriert -> keine Fahrt moeglich', 0);
            return false;
        }
        $steps = ShadeKinematics::steps($from, $target, [
            'timeOpening'    => $tAuf,
            'timeClosing'    => $tZu,
            'runIntoEndstop' => true,
        ]);
        if (empty($steps)) {
            return true; // kein Bewegungsbedarf - das Ziel steht bereits
        }
        $first = $steps[0];
        if ($first->action === 'stop' || $first->durationMs <= 0) {
            return true;
        }
        if (!$drv->move($first->action)) {
            return false;
        }
        $rt = $this->readRt();
        $rt['moving']      = true;
        $rt['moveTarget']  = $target;
        $rt['moveDir']     = $first->action;
        $rt['moveFrom']    = $from;
        $rt['moveStartTs'] = time();
        $rt['moveDurMs']   = $first->durationMs;
        $this->writeRt($rt);
        if ($from >= 0) { $this->pushPosition($from); } // Startlage sofort spiegeln
        // Periodischer Tick (~1 s, oder kuerzer bei sehr kurzer Fahrt) -> laufende Rueckmeldung + Abschluss.
        $this->SetTimerInterval(self::TIMER_MOVE, (int) min(1000, max(200, $first->durationMs)));
        return true;
    }

    /**
     * Beendet eine laufende zeitbasierte Fahrt: Stop senden (ausser sauber in den
     * Endanschlag 0/100 gelaufen) und estPos setzen. $interrupted=true bei
     * vorzeitigem Abbruch -> Position aus verstrichener Zeit interpolieren.
     */
    private function finishMove(bool $interrupted): void
    {
        $this->SetTimerInterval(self::TIMER_MOVE, 0);
        $rt = $this->readRt();
        if (empty($rt['moving'])) {
            return;
        }
        $target = (int) ($rt['moveTarget'] ?? 0);
        $from   = (int) ($rt['moveFrom'] ?? 0);
        $newPos = $target;
        if ($interrupted) {
            $elapsedMs = max(0, (time() - (int) ($rt['moveStartTs'] ?? time())) * 1000);
            $durMs     = max(1, (int) ($rt['moveDurMs'] ?? 1));
            $frac      = min(1.0, $elapsedMs / $durMs);
            $newPos    = (int) round($from + ($target - $from) * $frac);
        }
        $drv     = $this->driver();
        $endstop = ($target === 0 || $target === 100);
        // Bei sauberem Erreichen eines Endanschlags kein Stop noetig (Motor stoppt
        // am Anschlag selbst -> selbstkalibrierend). Sonst Stop senden.
        if ($drv instanceof IShutter && !($endstop && !$interrupted)) {
            $drv->move('stop');
        }
        $rt = $this->readRt();
        $rt['moving'] = false;
        $this->writeRt($rt);
        $this->setEstPos($newPos, true);
        $this->SetValue('Position', $newPos);
        $this->pushPosition($newPos);            // Endlage sofort an Kachel + Spiegel zurueckmelden
    }

    /**
     * Timer-Callback (prefix HSSH_MoveDone): periodischer Fahr-Tick (~1 s). Interpoliert die Position
     * aus verstrichener Fahrzeit und meldet sie laufend zurueck (Ramp); am Ende regulaerer Abschluss.
     */
    public function MoveDone(): void
    {
        $rt = $this->readRt();
        if (empty($rt['moving'])) { $this->SetTimerInterval(self::TIMER_MOVE, 0); return; }
        $start = (int) ($rt['moveStartTs'] ?? 0);
        $durMs = max(1, (int) ($rt['moveDurMs'] ?? 1));
        $elapsedMs = max(0, (time() - $start) * 1000);
        if ($elapsedMs >= $durMs) { $this->finishMove(false); return; }   // fertig -> Endlage + Stop
        $from   = (int) ($rt['moveFrom'] ?? 0);
        $target = (int) ($rt['moveTarget'] ?? 0);
        if ($from < 0) { return; } // unkalibriert (Referenzfahrt): kein Zwischen-Ramp, erst am Endanschlag
        $frac   = min(1.0, $elapsedMs / $durMs);
        $interp = (int) round($from + ($target - $from) * $frac);
        $this->setEstPos($interp, true);
        $this->pushPosition($interp);
    }

    // ==================================================================
    // Kalibrier-Lock: ab dem ersten calMove bis calSetTime (Speichern) ODER
    // calAbort darf die Automatik das Rollo NICHT anfassen. Der Lock lebt im
    // RtState (also im Modul, nicht im Widget) und ueberlebt einen Neustart;
    // ein Not-Aus nach CAL_TIMEOUT verhindert ein dauerhaft gesperrtes Rollo,
    // falls der Nutzer nie abschliesst (Browser zu, Tab weg).
    // ==================================================================

    /** Laeuft gerade eine Kalibrierung? Abgelaufene Locks werden dabei selbst aufgeraeumt. */
    private function calLocked(): bool
    {
        $rt = $this->readRt();
        if (empty($rt['calLock'])) { return false; }
        $until = (int) ($rt['calUntil'] ?? 0);
        if ($until > 0 && time() > $until) {
            $this->calUnlock('Timeout');   // Not-Aus: Motor stoppen + Lock loesen
            return false;
        }
        return true;
    }

    /** Lock setzen/verlaengern. $phase: 'moving' (Motor laeuft) | 'stopped' (Messung steht, wartet auf Uebernehmen). */
    private function calLock(string $phase): void
    {
        $rt = $this->readRt();
        $rt['calLock']  = true;
        $rt['calPhase'] = $phase;
        $rt['calUntil'] = time() + self::CAL_TIMEOUT;
        $this->writeRt($rt);
        $this->SendDebug('HSSH.cal', 'Kalibrier-Lock ' . $phase . ' (bis ' . date('H:i:s', $rt['calUntil']) . ')', 0);
    }

    /** Lock aufheben. Bei $stopMotor stoppt zusaetzlich eine evtl. laufende Messfahrt. */
    private function calUnlock(string $why, bool $stopMotor = true): void
    {
        $rt = $this->readRt();
        $was = !empty($rt['calLock']) ? (string) ($rt['calPhase'] ?? '') : '';
        unset($rt['calLock'], $rt['calPhase'], $rt['calUntil']);
        $this->writeRt($rt);
        if ($stopMotor && $was === 'moving') {
            $drv = $this->driver();
            if ($drv instanceof IShutter) { $drv->move('stop'); }
        }
        if ($was !== '') { $this->SendDebug('HSSH.cal', 'Kalibrier-Lock frei (' . $why . ')', 0); }
    }

    /** Geschaetzte Ist-Position (nur wenn kalibriert), sonst POS_UNKNOWN. */
    private function estPos(): int
    {
        $rt = $this->readRt();
        return !empty($rt['posKnown']) ? (int) ($rt['estPos'] ?? 0) : IShutter::POS_UNKNOWN;
    }

    /** Geschaetzte Position setzen (known=true nach Fahrt/Referenzfahrt). */
    private function setEstPos(int $pos, bool $known): void
    {
        $rt = $this->readRt();
        $rt['estPos']   = max(self::POS_MIN, min(self::POS_MAX, $pos));
        $rt['posKnown'] = $known;
        $this->writeRt($rt);
    }

    /**
     * Geschaetzte Ist-Position live zurueckmelden (Somfy hat KEIN Feedback, wie IPSShadowing selbst
     * rechnen): ActualPosition (daran haengt die Kachel) + IPSShadowing-Spiegelvariable (positionId).
     */
    private function pushPosition(int $pos): void
    {
        $pos = max(self::POS_MIN, min(self::POS_MAX, $pos));
        $this->setReflect('ActualPosition', $pos);
        $this->setReflect('Online', true);
        $lp = (int) $this->cfgVal('positionId', 0);
        if ($lp > 0 && function_exists('IPS_VariableExists') && @\IPS_VariableExists($lp)) {
            $cur = @GetValue($lp);
            if (!is_numeric($cur) || (int) round((float) $cur) !== $pos) {
                // SELBST-SCHREIBVORGANG MARKIEREN, BEVOR geschrieben wird: die gespiegelte
                // Positions-Variable ist zugleich die von MessageSink UEBERWACHTE Variable.
                // Ohne diese Markierung deutet das Modul jeden eigenen Ramp-Zwischenwert als
                // externen Eingriff und holdet sich selbst -> nach EINER Automatikfahrt war
                // die Komfort-Automatik bis zur naechsten Slot-Grenze tot. Der Marker muss
                // je Schreibvorgang mitlaufen (nicht nur einmal je Fahrt), weil eine Fahrt
                // 17-33 s dauert und aus dem 10-s-Fenster faellt.
                $rt = $this->readRt();
                $rt['selfWriteTs']  = time();
                $rt['selfWriteVal'] = $pos;
                $this->writeRt($rt);
                @SetValue($lp, $pos);
            }
        }
    }

    /**
     * Trockenlauf-Vorschau des geplanten Fahrbefehls (Schatten-Modus/Log). Zeigt
     * bei Somfy Richtung + rohes Telegramm (Hex) + geplante Fahrdauer, damit vor
     * dem Scharfschalten geprueft werden kann, was real gesendet WUERDE.
     */
    private function drivePreview(IShutter $drv, int $target, int $cur): string
    {
        $caps = $drv->capabilities();
        if (!empty($caps['absolutePosition'])) {
            return 'moveTo ' . $target . '%';
        }
        $from = $this->estPos();
        $ref  = ($from === IShutter::POS_UNKNOWN) ? $cur : $from;
        $dir  = ($target > $ref) ? 'down' : 'up';
        $hex  = method_exists($drv, 'frameHex') ? $drv->frameHex($dir) : '';
        if ($from === IShutter::POS_UNKNOWN) {
            return 'travel ' . strtoupper($dir) . ($hex !== '' ? ' [' . $hex . ']' : '') . ' (estPos unbekannt -> Referenzfahrt noetig)';
        }
        $steps = ShadeKinematics::steps($from, $target, [
            'timeOpening' => (float) $this->cfgVal('timeOpening', 0),
            'timeClosing' => (float) $this->cfgVal('timeClosing', 0),
        ]);
        $dur = (!empty($steps)) ? $steps[0]->durationMs : 0;
        return 'travel ' . strtoupper($dir) . ($hex !== '' ? ' [' . $hex . ']' : '') . ' ' . $from . '%->' . $target . '% (' . $dur . 'ms)';
    }

    /**
     * Referenzfahrt (Kalibrierung): voll in einen Endanschlag fahren und estPos
     * exakt auf 0 (up) bzw. 100 (down) setzen. NUR auf Operator-Kommando, hart
     * safety-gegatet (kein Fahren bei Sturm/Regen). Dies IST ein realer Fahrbefehl
     * — bewusst nicht an armed gebunden, weil man vor dem Scharfschalten kalibriert.
     */
    private function mgmtReferenceRun(array $args): array
    {
        $dir = strtolower((string) ($args['dir'] ?? ''));
        if (!in_array($dir, ['up', 'down'], true)) {
            throw new ContractException('dir muss up|down sein');
        }
        $drv = $this->driver();
        if (!$drv instanceof IShutter) {
            throw new ContractException('kein Treiber gebunden');
        }
        if ($this->stormActive($this->readInputs())) {
            throw new ContractException('Sturm/Regen aktiv -> Referenzfahrt gesperrt (Safety)');
        }
        $drv->referenceRun($dir); // realer Fahrbefehl in den Endanschlag
        $endstop = ($dir === 'up') ? self::POS_MIN : self::POS_MAX;
        $full    = ($dir === 'up') ? (float) $this->cfgVal('timeOpening', 0) : (float) $this->cfgVal('timeClosing', 0);
        $durMs   = max(1000, (int) round($full * 1000));
        // Abschluss ueber den MoveStop-Timer: setzt estPos exakt auf den Endanschlag.
        $rt = $this->readRt();
        $rt['moving']      = true;
        $rt['moveTarget']  = $endstop;
        $rt['moveFrom']    = ($endstop === self::POS_MIN) ? self::POS_MAX : self::POS_MIN;
        $rt['moveDir']     = $dir;
        $rt['moveStartTs'] = time();
        $rt['moveDurMs']   = $durMs;
        $this->writeRt($rt);
        $this->SetTimerInterval(self::TIMER_MOVE, $durMs);
        return ['ok' => true, 'dir' => $dir, 'endstop' => $endstop, 'settleMs' => $durMs];
    }

    // ------------------------------------------------------------------
    // Kalibrierfahrt (fuers LVB-Kalibrier-Widget): ROHES Fahren/Stoppen ueber den
    // Treiber (NICHT driveTo mit Auto-Stop) — nur so misst das Widget die echte
    // Fahrzeit. calSetTime uebernimmt die gemessene Zeit in TimeOpening/TimeClosing.
    // ------------------------------------------------------------------
    private function mgmtCalMove(array $args): array
    {
        $dir = strtolower((string) ($args['dir'] ?? ''));
        if (!in_array($dir, ['up', 'down'], true)) {
            throw new ContractException('dir muss up|down sein');
        }
        $drv = $this->driver();
        if (!$drv instanceof IShutter) {
            throw new ContractException('kein Treiber gebunden');
        }
        if ($this->stormActive($this->readInputs())) {
            throw new ContractException('Sturm/Regen aktiv -> Kalibrierfahrt gesperrt (Safety)');
        }
        // rohes Motor-AN in die Richtung; laeuft bis calStop (bzw. bis zum Endanschlag des Motors).
        $ok = (bool) $drv->move($dir);
        // laufende zeitbasierte Fahrt-Bookkeeping beenden, damit estPos nicht dazwischenfunkt
        $rt = $this->readRt(); if (!empty($rt['moving'])) { $rt['moving'] = false; $this->writeRt($rt); $this->SetTimerInterval(self::TIMER_MOVE, 0); }
        // AUTOMATIK SPERREN bis Uebernehmen/Abbrechen (Vorgabe) ...
        $this->calLock('moving');
        // ... und die Positionsschaetzung als UNBEKANNT markieren: der Motor laeuft roh,
        // ohne Bookkeeping - jede weiter gefuehrte Zahl waere gelogen und wuerde die
        // naechste Automatikfahrt von falscher Ausgangslage rechnen lassen.
        $this->setEstPos(0, false);
        return ['ok' => $ok, 'dir' => $dir, 'locked' => true];
    }

    private function mgmtCalStop(): array
    {
        $drv = $this->driver();
        if (!$drv instanceof IShutter) {
            throw new ContractException('kein Treiber gebunden');
        }
        $ok = (bool) $drv->move('stop');
        // Lock BLEIBT: der Nutzer entscheidet erst jetzt (Uebernehmen oder Verwerfen).
        // Timeout laeuft neu, damit ein langes Ueberlegen den Lock nicht platzen laesst.
        $this->calLock('stopped');
        return ['ok' => $ok, 'locked' => true];
    }

    /**
     * Kalibrierung abbrechen (Widget "Verwerfen" / Not-Aus): Motor stoppen, Lock
     * loesen, nichts uebernehmen. Ohne diese Op bliebe die Automatik nach einem
     * abgebrochenen Messvorgang bis zum Timeout gesperrt.
     */
    private function mgmtCalAbort(): array
    {
        $this->calUnlock('Abbruch durch Nutzer');   // stoppt den Motor, falls er noch laeuft
        $this->releaseAutoHold();                   // wie beim Speichern: Automatik wieder frei
        return ['ok' => true, 'locked' => false];
    }

    /**
     * Manuelle Sperre komplett aufheben -> die Automatik uebernimmt beim naechsten
     * Reconcile wieder. Gegenstueck zu enterManualMode(). Wird bewusst NUR durch
     * eine ausdrueckliche Nutzer-Handlung ausgeloest: Kalibrierung speichern/abbrechen
     * oder Rueckschalten auf Automatik.
     */
    private function releaseAutoHold(): void
    {
        $this->clearManualHold('Position');
        $this->clearManualHold('Mode');
        $this->SendDebug('HSSH.manual', 'Manuelle Sperre aufgehoben -> Automatik uebernimmt wieder', 0);
    }

    private function mgmtCalSetTime(array $args): array
    {
        $dir = strtolower((string) ($args['dir'] ?? ''));
        if (!in_array($dir, ['up', 'down'], true)) {
            throw new ContractException('dir muss up|down sein');
        }
        $sec = (int) round((float) ($args['seconds'] ?? 0));
        if ($sec < 1 || $sec > 300) {
            throw new ContractException('seconds muss 1..300 sein');
        }
        $key = ($dir === 'up') ? 'timeOpening' : 'timeClosing'; // up=Auf=Fahrzeit AUF, down=Zu=Fahrzeit ZU
        $this->applyConfigProperties([$key => $sec], true);
        // Die Messfahrt endete per Definition in der Endlage der gemessenen Richtung
        // -> die Lage ist jetzt EXAKT bekannt (up=0=offen, down=100=zu). Damit ist das
        // Rollo referenziert und kann anschliessend Zwischenpositionen anfahren.
        $this->setEstPos(($dir === 'up') ? self::POS_MIN : self::POS_MAX, true);
        $this->calUnlock('Fahrzeit uebernommen', false);   // Automatik wieder frei
        // SPEICHERN gibt die Automatik VOLLSTAENDIG frei: der manualHold, den die
        // Messfahrt ausgeloest hat, waere sonst bis zur naechsten Zeitplan-Grenze
        // aktiv und die Automatik trotz geloestem Lock weiter blockiert.
        $this->releaseAutoHold();
        return ['ok' => true, 'dir' => $dir, 'seconds' => $sec, 'locked' => false,
            'timeOpening' => $this->ReadPropertyInteger('TimeOpening'),
            'timeClosing' => $this->ReadPropertyInteger('TimeClosing')];
    }

    /** Diagnose: prueft die Bindung dieser Instanz (op=validate + Konsolen-Button). */
    private function mgmtValidate(): array
    {
        $h = $this->computeHealth();
        return ['ok' => $h['ok'], 'health' => $h['text'], 'issues' => $h['issues'], 'config' => $this->cfg()];
    }

    /**
     * Bindungs-Gesundheit: existieren die gebundenen Objekte, ist der Treiber aktiv,
     * ist die Position bekannt. Liefert ['ok','text','issues'] fuer Health-Variable,
     * Konsole und den Hub-Aggregat-Scan.
     */
    private function computeHealth(): array
    {
        $cfg = $this->cfg();
        $drv = (string) ($cfg['driver'] ?? '');
        if ($drv === '') {
            return ['ok' => false, 'text' => 'inaktiv (kein Treiber)', 'issues' => ['kein Treiber']];
        }
        $issues = [];
        foreach (['positionId', 'socketId'] as $k) {
            $id = (int) ($cfg[$k] ?? 0);
            if ($id > 0 && function_exists('IPS_ObjectExists') && !@\IPS_ObjectExists($id)) {
                $issues[] = $k . ' #' . $id . ' fehlt';
            }
        }
        if ($drv === 'somfy-rts') {
            $sid = (int) ($cfg['socketId'] ?? 0);
            if ($sid <= 0 || !function_exists('IPS_InstanceExists') || !@\IPS_InstanceExists($sid)) {
                $issues[] = 'Client-Socket fehlt';
            }
            if (((int) ($cfg['timeOpening'] ?? 0)) <= 0 || ((int) ($cfg['timeClosing'] ?? 0)) <= 0) {
                $issues[] = 'Fahrzeiten fehlen';
            }
        }
        if ($drv === 'hm-shutter') {
            $lv = (int) ($cfg['levelVarId'] ?? 0);
            if ($lv <= 0 || !function_exists('IPS_VariableExists') || !@\IPS_VariableExists($lv)) {
                $issues[] = 'LEVEL-Variable fehlt';
            }
        }
        $drv = $this->driver();
        if (!($drv instanceof IShutter)) {
            $issues[] = 'Treiber inaktiv';
        }
        if ($issues) {
            return ['ok' => false, 'text' => 'FEHLER: ' . implode(', ', $issues), 'issues' => $issues];
        }
        // Absolut-Treiber (HM) melden echtes Feedback; travel-only nutzt estPos.
        $pos = $drv->readPosition();
        if ($pos === IShutter::POS_UNKNOWN) {
            $pos = $this->estPos();
        }
        $known = $pos !== IShutter::POS_UNKNOWN;
        return ['ok' => true, 'issues' => [],
            'text' => 'OK' . ($known ? ' · ' . $pos . '%' : ' · Position unbekannt (Referenzfahrt noetig)')];
    }

    /** Sichtbare Bindungs-Health-Variable aktualisieren (nicht still ausfallen). */
    private function updateHealth(): void
    {
        @$this->RegisterVariableString('BindHealth', 'Bindung', '', 90);
        $h = $this->computeHealth();
        @$this->SetValue('BindHealth', (string) $h['text']);
    }

    // ==================================================================
    // Reconciler (controller-Modus) — evalRules-Kaskade Safety>Sonne>Zeitplan
    // ==================================================================

    /**
     * Treibt die Position nach der getierten Regel-Kaskade. SCHATTEN-MODUS bis
     * config.armed==true: es wird berechnet und geloggt, aber NICHT gefahren
     * (M7-Trockenlauf). manualHold (externer/manueller Eingriff) unterdrueckt nur
     * Komfort-Regeln; Safety (Wind/Regen) ueberfaehrt ihn hart (ScheduleEngine).
     */
    /**
     * Sperrgrund festhalten. Leerer Text heisst: nichts haelt die Automatik auf.
     * Wird bei JEDEM Abgleich gesetzt, damit ein weggefallener Grund auch wieder
     * verschwindet - ein stehengebliebener Hinweis waere schlimmer als keiner.
     */
    private function setBlock(string $text): void
    {
        $cur = (string) @$this->GetControlValue('BlockReason');
        if ($cur !== $text) { @$this->setReflect('BlockReason', $text); }
    }

    private function reconcile(IShutter $drv): void
    {
        $d = $this->computeDecision($drv, true);
        // Protokoll zuerst: es soll auch dann stimmen, wenn gleich darunter ein
        // Abbruchpfad greift (Kalibrierung, laufende Fahrt, Automatik aus).
        $this->dokumentiere($d, is_numeric($d['cur'] ?? null) ? (int) $d['cur'] : null);
        $target = $d['target'];
        // Kalibrierung laeuft -> die Automatik fasst das Rollo NICHT an. Ausnahme: STURM
        // steht ueber der Kalibrierung (Sachschadenschutz) und bricht sie hart ab.
        if ($this->calLocked()) {
            if (empty($d['storm'])) { $this->setBlock('Kalibrierung läuft'); return; }
            $this->calUnlock('Sturm bricht Kalibrierung ab');
        }
        // LAUFENDE FAHRT NIEMALS ZERHACKEN: driveTo() beendet als erstes jede laufende
        // Fahrt (finishMove -> STOP). Da MessageSink bei JEDER Wind-/Regen-Aktualisierung
        // ein Reconcile ausloest (Windsensor liefert alle 2 s!), wurde eine 13-Sekunden-
        // Fahrt in ~7 Stop/Start-Zyklen zerlegt: das Rollo kam physisch kaum vom Fleck,
        // waehrend die zeitbasierte Schaetzung bis zum Ziel hochlief (0->12->24->...->75).
        // Waehrend einer Fahrt wird deshalb nichts nachgeregelt - nur Sturm darf abbrechen.
        $rtMove = $this->readRt();
        if (!empty($rtMove['moving']) && empty($d['storm'])) {
            /* Der Merker muss sich selbst heilen koennen.
             *
             * Zurueckgesetzt wird er sonst NUR vom MoveStop-Timer. Bleibt der
             * einmal aus, kehrt jeder Abgleich hier sofort um - und weil nur ein
             * Abgleich den Merker wieder loesen wuerde, ist die Instanz dauerhaft
             * verklemmt. Genau so stand die Beschattung Balkon (#<ID>) vom
             * 29.08.2026 13:50 bis zum 30.08. 21:21 still: MoveStop war dort NIE
             * gelaufen, das Entscheidungsprotokoll brach mitten am Tag ab, und
             * "Fahrbefehl steht an" stand 31 Stunden auf wahr, ohne dass je
             * gefahren wurde. Die uebrigen sechzehn Rollos liefen normal weiter -
             * es faellt also nicht auf, solange man nicht danebensteht.
             *
             * Eine Fahrt kann nicht laenger dauern als ihre eigene berechnete
             * Dauer plus Reserve. Danach gilt der Merker als tot und wird
             * abgeschlossen, statt ewig zu blockieren. */
            $startTs = (int) ($rtMove['moveStartTs'] ?? 0);
            $durS    = (int) ceil(((int) ($rtMove['moveDurMs'] ?? 0)) / 1000);
            $frist   = $startTs + $durS + self::MOVE_TOT_S;
            if ($startTs > 0 && time() > $frist) {
                $this->SendDebug('HSSH.move', 'Fahrt-Merker seit ' . (time() - $startTs)
                    . ' s gesetzt (Dauer war ' . $durS . ' s) - als tot behandelt und abgeschlossen', 0);
                $this->finishMove(false);          // Position auf das Ziel, Merker frei
            } else {
                $this->setBlock('');   // faehrt gerade - kein Hindernis, nur beschaeftigt
                return;
            }
        }
        // Globaler Automatik-Schalter (Hub) aus -> keine Komfort-Automatik; Sturm/Safety bleibt.
        if (!$this->automationEnabled() && empty($d['storm'])) {
            $this->setBlock('Automatik am Hub aus');
            return;
        }
        $rt = $this->readRt();
        if ($target === null) {
            // Kein Ziel heisst meist: von Hand uebersteuert. Das ist der haeufigste Grund,
            // warum die Automatik "nichts tut", und genau der, den man sehen will.
            $this->setBlock($this->isManuallyHeld('Position') ? 'Von Hand übersteuert' : '');
            $this->writeRt($rt); // Debounce-State ggf. schon in computeDecision persistiert
            return; // nichts erzwingen (keine aktive Regel / Hold ohne Safety)
        }
        $cur    = $d['cur'];
        $armed  = (bool) ($this->cfgVal('armed', false));
        $drift  = ($cur === IShutter::POS_UNKNOWN) || abs($cur - $target) > self::POS_TOLERANCE;

        if (!$drift) {
            $this->setBlock('');           // steht schon richtig - kein Hindernis
            $rt['lastTarget'] = $target;
            unset($rt['blockedTs'], $rt['blockedTarget']); // Blockade vorbei -> naechste wird wieder gemeldet
            $this->writeRt($rt);
            return;
        }
        // Tuer-Guard: Zufahren gegen offene Tuer blocken (Auffahren/Sturm bleibt erlaubt).
        if ($d['blockedByDoor']) {
            $this->setBlock('Tür offen');
            $this->SendDebug('HSSH.guard', 'Tuer offen -> Zufahren auf ' . $target . '% blockiert', 0);
            // EINMAL protokollieren, nicht bei jedem Tick. Die Automatik versucht es im
            // Abfragetakt weiter (richtig - sobald die Tuer zugeht, soll sie fahren), aber ein
            // Eintrag je Minute ist keine Information mehr: am 17.08.2026 stammten 21 der 60
            // sichtbaren Logzeilen von einer einzigen offenen Terrassentuer und haben alles
            // andere aus dem Fenster gedraengt. Neu geloggt wird erst wieder, wenn sich das
            // Ziel aendert oder die Blockade zwischendurch weg war.
            $schonGemeldet = !empty($rt['blockedTs']) && (int) ($rt['blockedTarget'] ?? -1) === (int) $target;
            if ($armed && !$schonGemeldet) {
                $this->logDecision((int) $cur, (int) $target, 'Tür blockiert', true, 'auto');
            }
            $rt['blockedTs']     = time();
            $rt['blockedTarget'] = (int) $target;
            $this->writeRt($rt);
            return;
        }
        if ($armed) {
            $this->setBlock('');           // es wird gefahren - Entwarnung
            // Self-Write VOR dem Schreiben markieren: auch bei SYNCHRONER VM_UPDATE-
            // Zustellung darf der eigene moveTo nicht als externer Eingriff (-> Hold)
            // missdeutet werden.
            $rt['selfWriteTs']  = time();
            $rt['selfWriteVal'] = $target;
            unset($rt['blockedTs'], $rt['blockedTarget']); // es faehrt -> naechste Blockade wieder melden
            $this->writeRt($rt);
            // Absolut-Treiber: moveTo. Travel-only (Somfy): zeitbasiert ueber driveTo.
            $this->driveTo($drv, (int) $target);
            $this->logDecision((int) $cur, (int) $target, $this->reasonOf($d), true, 'auto');
        } else {
            $this->setBlock('Schatten-Modus (nicht scharf)');
            // Schatten-Modus: nur protokollieren, kein Geraeteschreiben. Fuer den
            // Trockenlauf wird der geplante Fahrbefehl inkl. Telegramm-Vorschau geloggt.
            $this->SendDebug('HSSH.shadow', 'Ziel ' . $target . '% (ist ' . $cur . '%, '
                . ($d['storm'] ? 'STURM' : ($d['sunTarget'] !== null ? 'Sonne' : 'Zeitplan')) . ') - nicht scharf | '
                . $this->drivePreview($drv, (int) $target, (int) $cur), 0);
            // KEIN Log im Schatten-Modus: es faehrt nichts -> waeren nur hypothetische
            // Wiederholungen (Rauschen). Geloggt werden nur echte Fahrten (armed) + Manuell.
            $rt['shadowTarget'] = $target;
            $rt['shadowTs']     = time();
            $this->writeRt($rt);
        }
    }


    /**
     * Naechster Fahrbefehl laut Zeitsteuerung.
     *
     * Nicht die naechste Slot-GRENZE (die gibt es schon als secondsToNextBoundary),
     * sondern die naechste Grenze, an der sich der Zielwert wirklich AENDERT -
     * nur dort entsteht ein Fahrbefehl. Zwei gleiche Slots hintereinander sind
     * kein Ereignis.
     *
     * Sonnen-verankerte Grenzen ("eine Stunde nach Sonnenaufgang") werden je Tag
     * aufgeloest, deshalb wird tageweise vorgegangen und nicht mit einer festen
     * Minutenliste gerechnet.
     *
     * @return array{ts:int,ziel:?int,richtung:string}  ts=0 -> keine Aenderung in Sicht
     */
    private function naechsteFahrt(): array
    {
        $leer = ['ts' => 0, 'ziel' => null, 'richtung' => ''];
        $variant = $this->activeVariant();
        $now = time();
        $vorher = $this->scheduleValueAt($now, $variant);
        $vorher = is_numeric($vorher) ? (int) round((float) $vorher) : null;

        for ($d = 0; $d <= 7; $d++) {
            $tag = strtotime('+' . $d . ' day', $now);
            $mid = strtotime('today', $tag);
            $idx = (int) date('N', $tag) - 1;
            $slots = $this->schedules()->getSlots($variant, $idx);
            if ($slots === []) {
                continue;
            }
            $sun = $this->sunEvents($tag);
            $res = [];
            foreach ($slots as $s) {
                $res[] = ['end' => $this->resolveEnd($s, $sun),
                          'val' => is_numeric($s['val'] ?? null) ? (int) round((float) $s['val']) : null];
            }
            usort($res, static fn($a, $b) => $a['end'] - $b['end']);

            foreach ($res as $i => $s) {
                // Der Wechsel passiert am ENDE dieses Slots: dort uebernimmt der naechste.
                $grenze = $mid + $s['end'] * 60;
                if ($grenze <= $now) {
                    $vorher = $s['val'];       // liegt hinter uns, gilt aber als Ausgangswert
                    continue;
                }
                $next = $res[$i + 1]['val'] ?? null;
                if ($next === null || $next === $vorher) {
                    $vorher = ($next !== null) ? $next : $vorher;
                    continue;
                }
                return ['ts' => $grenze, 'ziel' => $next,
                        // 0 = offen, 100 = zu: ein groesserer Wert heisst schliessen.
                        'richtung' => ($vorher === null || $next > $vorher) ? 'Ab' : 'Auf'];
            }
        }
        return $leer;
    }

    /**
     * Entscheidungsprotokoll in Variablen schreiben.
     *
     * Absichtlich JEDE Zwischengroesse und nicht nur das Ergebnis: die Frage
     * "warum ist das Rollo jetzt zu?" laesst sich sonst nicht beantworten, ohne
     * den Trockenlauf von Hand aufzurufen. Die Werte sind reine Anzeige und
     * greifen nirgends in die Regelung ein.
     *
     * -1 steht durchgaengig fuer "keine Anforderung" (die Skala 0..100 ist belegt).
     */
    private function dokumentiere(array $d, ?int $cur): void
    {
        $z = static fn($v) => ($v === null) ? -1 : (int) $v;
        $ziel = $d['target'] ?? null;

        $this->setIfExists('AutoZiel',         $z($ziel));
        $this->setIfExists('AutoGrund',        ($ziel === null) ? 'keine Anforderung' : $this->reasonOf($d));
        $this->setIfExists('SonneRoh',         $z($d['rawSun'] ?? null));
        $this->setIfExists('SonneZiel',        $z($d['sunTarget'] ?? null));
        $this->setIfExists('Klarheit',         $z($d['clearIdx'] ?? null));
        $this->setIfExists('KlarheitSchwelle', $z($d['clearThr'] ?? null));
        $this->setIfExists('ZeitplanZiel',     $z($d['schedTarget'] ?? null));
        $this->setIfExists('Variante',         (string) ($d['variant'] ?? ''));
        $this->setIfExists('Sturm',            !empty($d['storm']));
        $this->setIfExists('ManuellVorrang',   !empty($d['held']));
        $this->setIfExists('TuerOffen',        !empty($d['doorOpen']));
        $this->setIfExists('TuerSperrt',       !empty($d['blockedByDoor']));
        $this->setIfExists('WuerdeFahren',     ($ziel !== null && $cur !== null && (int) $ziel !== (int) $cur));

        $n = $this->naechsteFahrt();
        $this->setIfExists('NaechsteFahrt',    (int) $n['ts']);
        $this->setIfExists('NaechstesZiel',    $z($n['ziel']));
        $this->setIfExists('NaechsteRichtung', (string) $n['richtung']);
    }

    /** Setzt eine Variable nur, wenn es sie gibt - haelt aeltere Instanzen ohne die neuen Idents heil. */
    private function setIfExists(string $ident, $value): void
    {
        $vid = @$this->GetIDForIdent($ident);
        if (is_int($vid) && $vid > 0) {
            @$this->SetValue($ident, $value);
        }
    }

    /** Grund der Automatik-Entscheidung (Vorrang Sturm > Sonne > Zeitplan). */
    private function reasonOf(array $d): string
    {
        if (!empty($d['storm'])) { return 'Sturm'; }
        if (($d['sunTarget'] ?? null) !== null) { return 'Sonne'; }
        if (($d['schedTarget'] ?? null) !== null) { return 'Zeitplan'; }
        return 'Automatik';
    }

    /**
     * Einen Eintrag in den Ringpuffer des Entscheidungs-/Befehls-Logs schreiben.
     * Nur echte Fahrten/Befehle. src: 'auto'|'manuell'; armed: true=scharf gefahren, false=Schatten (nur berechnet).
     */
    private function logDecision(int $from, ?int $to, string $why, bool $armed, string $src): void
    {
        try {
            $log = json_decode((string) $this->ReadAttributeString('DecisionLog'), true);
            if (!is_array($log)) { $log = []; }
            $log[] = ['t' => time(), 'from' => $from, 'to' => $to, 'why' => $why, 'armed' => $armed ? 1 : 0, 'src' => $src];
            if (count($log) > self::LOG_MAX) { $log = array_slice($log, -self::LOG_MAX); }
            $this->WriteAttributeString('DecisionLog', json_encode($log, JSON_UNESCAPED_UNICODE));
        } catch (\Throwable $e) { /* Log darf den Betrieb nie stoeren */ }
    }

    /**
     * Reine Entscheidungslogik (ohne Fahren): baut das evalRules-Ruleset und
     * liefert Ziel + Zwischengroessen. $persist=false => Sonnen-Debounce wird NICHT
     * in den RtState geschrieben (fuer die read-only reconcileProbe/Trockenlauf).
     */
    private function computeDecision(IShutter $drv, bool $persist): array
    {
        $mode = $this->intVal('Mode');          // 0 Auto, 1 Manuell, 2 Sonne
        $cur  = $drv->readPosition();
        if ($cur === IShutter::POS_UNKNOWN) {
            $cur = $this->estPos();             // travel-only (Somfy): geschaetzte Ist-Lage
        }
        $inp  = $this->readInputs();

        // Sonne: Sonnenstandsvergleich gegen das Raum-Sonnenprofil (evalGeo) + Min-Dwell.
        $geo = $this->geoProfile(); // Baum-Variablen = Wahrheit
        // HYSTERESE: solange die Beschattung LAEUFT, gilt die niedrigere Ausschalt-Schwelle.
        // Sonst schaltet dieselbe Schwelle ein und aus, und bei durchziehenden Wolken pumpt
        // die Anlage - jede Aufhellung faehrt zu, jede Wolke wieder auf.
        $rtH = $this->readRt();
        if (is_array($geo) && !empty($rtH['sunOn'])) {
            $off = (float) ($geo['brightnessOff'] ?? 0);
            if ($off > 0) { $geo['brightnessMin'] = $off; }
        }
        // Die Helligkeitsschwelle wird mit der WIRKSAMEN Helligkeit geprueft, nicht mit
        // dem Rohwert - siehe wirksameHelligkeit(): unterhalb von zwoelf Grad Sonnenhoehe
        // ist der waagrechte Sensor kein gueltiger Zeuge fuer eine senkrechte Fassade.
        $hell = $this->wirksameHelligkeit($inp['bright'] ?? null, $inp['el']);
        $rawSun = (is_array($geo) && $inp['el'] !== null)
            ? $this->schedules()->evalGeo((float) ($inp['az'] ?? 0), (float) $inp['el'], (float) ($hell ?? 0), $geo)
            : null;
        // Strahlungs-Gate: die Geometrie sagt, WO die Sonne steht - die Strahlung, OB sie
        // scheint. Bei geschlossener Decke faellt die Sonnenregel damit weg, obwohl der
        // Sonnenstand im Fenster liegt. Greift nur, wenn Variable UND Schwelle gesetzt sind.
        $radMin = (float) $this->ReadPropertyFloat('SunRadMin');
        $radOff = (float) $this->ReadPropertyFloat('SunRadOff');
        // Gleiche Logik wie im Profil: laeuft die Beschattung, zaehlt die Ausschalt-Schwelle.
        if ($radMin > 0 && $radOff > 0 && !empty($rtH['sunOn'])) { $radMin = $radOff; }
        if ($rawSun !== null && $radMin > 0) {
            $rad = $inp['rad'] ?? null;
            if ($rad !== null && $rad < $radMin) {
                $this->SendDebug('HSSH.sun', sprintf('Sonne verworfen: %.0f W/m2 < %.0f W/m2', $rad, $radMin), 0);
                $rawSun = null;
            }
        }
        // Klarheits-Gate: eine feste W/m²-Schwelle bedeutet je nach Sonnenstand etwas
        // anderes - 271 W/m² sind im August um 13 Uhr eine dichte Wolkendecke (ein Drittel
        // des Moeglichen) und im Oktober um 9 Uhr strahlender Himmel. Deshalb zusaetzlich
        // der Anteil am Klarhimmel-Wert. Auch hier Hysterese: laeuft die Beschattung,
        // zaehlt die niedrigere AUS-Schwelle.
        if ($rawSun !== null && is_array($geo)) {
            $cMin = (float) ($geo['clearMin'] ?? 0) / 100.0;
            $cOff = (float) ($geo['clearOff'] ?? 0) / 100.0;
            if ($cMin > 0) {
                if (!empty($rtH['sunOn']) && $cOff > 0) { $cMin = $cOff; }
                $kc = $this->clearIndex($inp['bright'] ?? null, $inp['el'] ?? null);
                if ($kc !== null && $kc < $cMin) {
                    $this->SendDebug('HSSH.sun', sprintf('Sonne verworfen: Klarheit %.0f%% < %.0f%%', $kc * 100, $cMin * 100), 0);
                    $rawSun = null;
                }
            }
        }
        $sunTarget = $this->debounceSun($rawSun, $persist);
        // Temp-Gate: Sonnen-Beschattung nur, wenn Temperatur ueber Schwelle (IPSShadowing shadowingByTemp).
        // Zwei Schwellen wie in IPSShadowing ProfileTemp: Innen (sensorId>=aboveC) UND Aussen
        // (outSensorId>=outAboveC). Eine Schwelle mit fehlendem Sensor/Wert (null) gilt als erfuellt
        // ('ignore'), sodass z. B. das Schlafzimmer nur nach Aussentemperatur schattet.
        $tg = $this->cfgVal('tempGate', null);
        if (is_array($tg) && $sunTarget !== null) {
            $block = false;
            $inId = (int) ($tg['sensorId'] ?? 0);
            if ($inId > 0 && isset($tg['aboveC']) && $tg['aboveC'] !== null) {
                $tv = $this->tempOf($inId);
                if ($tv !== null && $tv < (float) $tg['aboveC']) { $block = true; }
            }
            $outId = (int) ($tg['outSensorId'] ?? 0);
            if (!$block && $outId > 0 && isset($tg['outAboveC']) && $tg['outAboveC'] !== null) {
                $ov = $this->tempOf($outId);
                if ($ov !== null && $ov < (float) $tg['outAboveC']) { $block = true; }
            }
            if ($block) { $sunTarget = null; }
        }

        // Zeitplan (sonnen-verankerte Grenzen werden fuer den Tag aufgeloest).
        $schedV      = $this->scheduleValueAt(time(), $this->activeVariant()); // Basis loest Sonnen-Anker auf
        $schedTarget = is_numeric($schedV) ? (int) round((float) $schedV) : null;
        if ($schedTarget === null) { $schedTarget = $this->dayNightTarget(time()); } // Fallback: Tag/Nacht-Profil

        // Safety: Wind/Regen -> sichere Position.
        $storm = $this->stormActive($inp);
        $safe  = (int) $this->cfgVal('safePos', self::SAFE_POS);

        $sunOn   = ($mode === 0 || $mode === 2);
        $schedOn = ($mode === 0);
        $held    = $this->isManuallyHeld('Position');

        $rules = [
            ['tier' => 'safety',  'active' => $storm,                              'target' => $safe],
            ['tier' => 'comfort', 'active' => $sunOn && $sunTarget !== null,       'target' => $sunTarget],
            ['tier' => 'comfort', 'active' => $schedOn && $schedTarget !== null,   'target' => $schedTarget],
        ];
        $target = $this->schedules()->evalRules($rules, ['manualHold' => $held]);

        $doorOpen = $this->anyDoorOpen();
        $blocked  = $this->closeBlockedByDoor((int) $cur, $target !== null ? (int) $target : null);

        return [
            'mode' => $mode, 'cur' => $cur, 'inp' => $inp, 'variant' => $this->activeVariant(),
            'rawSun' => $rawSun, 'sunTarget' => $sunTarget, 'schedTarget' => $schedTarget,
            'storm' => $storm, 'safe' => $safe, 'held' => $held, 'target' => $target,
            'doorOpen' => $doorOpen, 'blockedByDoor' => $blocked,
            // Klarheit mitgeben: sonst steht im Trockenlauf nur "Sonne ja/nein", und die
            // knappen Faelle (37 % gegen die 35er-Schwelle) sind von aussen nicht erklaerbar.
            'clearIdx' => (($kc = $this->clearIndex($inp['bright'] ?? null, $inp['el'] ?? null)) === null)
                            ? null : round($kc * 100),
            'clearThr' => (is_array($geo) && (int) ($geo['clearMin'] ?? 0) > 0)
                            ? (!empty($rtH['sunOn']) && (int) ($geo['clearOff'] ?? 0) > 0
                                ? (int) $geo['clearOff'] : (int) $geo['clearMin'])
                            : null,
        ];
    }

    /**
     * Min-Dwell-Entprellung der Sonnen-Regel (Blocker: evalGeo ist reiner
     * Schwellvergleich -> flappt bei Wolken). Ein Zustandswechsel wird erst nach
     * SUN_DWELL Sekunden anhaltender Bedingung uebernommen. Liefert die effektive
     * Sonnen-Zielposition oder null.
     */
    private function debounceSun(?int $raw, bool $persist): ?int
    {
        $rt   = $this->readRt();
        $now  = time();
        $act  = $raw !== null;
        $state = (bool) ($rt['sunOn'] ?? false);
        $cand  = (bool) ($rt['sunCand'] ?? $state);
        $candTs = (int) ($rt['sunCandTs'] ?? $now);

        if ($act === $state) {
            $cand = $act;
            $candTs = $now;
        } else {
            if ($act !== $cand) { $cand = $act; $candTs = $now; }
            if ($now - $candTs >= self::SUN_DWELL) { $state = $act; }
        }
        $lastTarget = $raw !== null ? $raw : (int) ($rt['sunLastTarget'] ?? self::SAFE_POS);

        if ($persist) {
            $rt['sunOn']     = $state;
            $rt['sunCand']   = $cand;
            $rt['sunCandTs'] = $candTs;
            if ($raw !== null) { $rt['sunLastTarget'] = $raw; }
            $this->writeRt($rt);
        }
        return $state ? $lastTarget : null;
    }

    /**
     * Klarheitsindex: gemessene Globalstrahlung geteilt durch die, die bei diesem
     * Sonnenstand unter klarem Himmel moeglich waere (Haurwitz-Modell). Klarer Himmel
     * liegt bei rund 0,75-0,95, bedeckt bei 0,15-0,35. Anders als eine feste W/m²-Schwelle
     * bedeutet der Wert um 9 Uhr dasselbe wie um 13 Uhr und im Dezember dasselbe wie im Juni.
     *
     * Bei sehr tiefer Sonne liefert das Modell null statt eines Werts: unter etwa 3 Grad
     * traegt es nicht mehr, und ein durch die Division aufgeblasener Index wuerde dort
     * Beschattung rechtfertigen, wo kaum Energie ankommt.
     */
    /**
     * Die fuer die Fassade WIRKSAME Helligkeit.
     *
     * Dasselbe Problem wie beim Klarheitsindex, nur an der absoluten Schwelle: der
     * waagrechte Strahlungssensor bricht bei flacher Sonne zusammen (gemessen am
     * 04.09.2026 bei wolkenlosem Himmel von 250 auf 19 W/m2 zwischen 18:05 und 19:00,
     * waehrend die Geometrie nur eine Halbierung hergibt). Fuer eine senkrechte
     * Westwand ist das der Zeitpunkt der GROESSTEN Last - der Strahl trifft sie dann
     * nahezu im rechten Winkel.
     *
     * Deshalb gilt oberhalb von zwoelf Grad der Messwert, darunter der zuletzt
     * belastbare. Beendet wird die Beschattung dann nicht mehr von einem blinden
     * Sensor, sondern von der Geometrie: der Elevationsschwelle des Sonnenprofils.
     * Die 90 Minuten sind nur die Reissleine, falls die Sonne nie wieder hochkommt.
     */
    private function wirksameHelligkeit(?float $bright, ?float $elDeg): ?float
    {
        if ($bright === null || $elDeg === null) { return $bright; }
        if (sin(deg2rad($elDeg)) > self::CLEAR_EL_MIN_SIN) {
            $this->SetBuffer('brightLast', json_encode(['v' => $bright, 't' => time()]));
            return $bright;
        }
        $letzt = json_decode((string) $this->GetBuffer('brightLast'), true);
        if (is_array($letzt) && isset($letzt['v'], $letzt['t'])
            && (time() - (int) $letzt['t']) <= self::CLEAR_HOLD_S) {
            return (float) $letzt['v'];
        }
        return $bright;
    }

    private function clearIndex(?float $ghi, ?float $elDeg): ?float
    {
        if ($ghi === null || $elDeg === null) { return null; }
        $s = sin(deg2rad($elDeg));

        // Unterhalb von CLEAR_EL_MIN ist der WAAGRECHTE Sensor kein gueltiger Zeuge mehr.
        // Gemessen am 04.09.2026 bei wolkenlosem Himmel (die Kurve von 16:14 bis 18:05 ist
        // glatt, ohne jede Zacke):
        //
        //     18:05   Elevation 10,0 Grad   Modell 137 W/m2   gemessen 250   Index 182 %
        //     19:00   Elevation  5,9 Grad   Modell  64 W/m2   gemessen  19   Index  30 %
        //
        // Der gemessene Wert faellt auf ein Dreizehntel, das Modell nur auf die Haelfte -
        // der Himmel hat sich nicht geaendert, der Sensor sieht die Sonne bei streifendem
        // Einfall nicht mehr (Cosinus-Fehler bzw. Abschattung durch Gelaende und Dach).
        //
        // Fuer eine SENKRECHTE Fassade ist das der schlimmste denkbare Zeitpunkt zu
        // verstummen: waehrend der waagrechte Sensor gegen null geht, trifft der Strahl
        // die Westwand nahezu senkrecht. Deshalb wird der letzte belastbare Index
        // WEITERGETRAGEN, statt in ein "keine Sonne" zu kippen, das nur ein Messartefakt
        // ist. Wolken entstehen nicht aus dem Nichts in der letzten halben Stunde; und
        // faellt die Strahlung wirklich weg, greift weiterhin die absolute W/m2-Schranke.
        if ($s > self::CLEAR_EL_MIN_SIN) {
            $clear = 1098.0 * $s * exp(-0.057 / $s);           // Haurwitz-Klarhimmel (W/m²)
            if ($clear < 50.0) { return null; }
            $kc = max(0.0, $ghi) / $clear;
            $this->SetBuffer('clearLast', json_encode(['kc' => $kc, 't' => time()]));
            return $kc;
        }

        $letzt = json_decode((string) $this->GetBuffer('clearLast'), true);
        if (is_array($letzt) && isset($letzt['kc'], $letzt['t'])
            && (time() - (int) $letzt['t']) <= self::CLEAR_HOLD_S) {
            return (float) $letzt['kc'];
        }
        // Nichts Belastbares in Reichweite: wie bisher null - das Gate wird uebersprungen,
        // nicht etwa negativ entschieden.
        return null;
    }

    /** Sturm-/Regen-Lage aus den Umgebungssensoren (Regen nur wenn Wetterprofil rainClose). */
    private function stormActive(array $inp): bool
    {
        // Die Safety-Stufe faehrt auf safePos, und safePos ist die EINGEFAHRENE
        // Lage. Bei einer Markise ist das der Sinn der Sache: Wind und Nass machen
        // sie kaputt, also weg damit. Bei einem Rollo bedeutet dieselbe Lage OFFEN -
        // die "Sicherung" reisst das Rollo auf, statt es zu schuetzen. Ein Rollo
        // laeuft in seinen Fuehrungsschienen und braucht keinen Sturmschutz.
        //
        // Am 20.08. hat Regen ein Rollo vierzehnmal aufgefahren, bei 0 km/h Wind.
        // Mit Wind waere dasselbe passiert, nur haus-weit.
        //
        // Deshalb: Sturm gilt ausschliesslich fuer Markisen.
        if ((string) $this->cfgVal('deviceKind', 'shutter') !== 'awning') {
            return false;
        }
        $windMax = (float) $this->cfgVal('windStormKmh', self::WIND_STORM_KMH);
        $wind    = $inp['wind'];
        if (($wind !== null) && $wind >= $windMax) {
            return true;
        }
        // Vorgabe false: eine Instanz ohne ausdrueckliche Einstellung darf sich
        // nicht selbst scharf schalten. Vorher stand hier true.
        return ($inp['rain'] === true) && (bool) $this->cfgVal('rainClose', false);
    }

    /** Aktuelle Temperatur fuer das Temp-Gate (tg.sensorId; null = kein Sensor -> kein Gate). */
    private function tempNow(array $tg): ?float
    {
        return $this->tempOf((int) ($tg['sensorId'] ?? 0));
    }

    /** Aktuelle Temperatur einer Sensor-Variable (oder null, wenn ungueltig/leer). */
    private function tempOf(int $id): ?float
    {
        if ($id <= 0 || !function_exists('IPS_VariableExists') || !@\IPS_VariableExists($id)) { return null; }
        $v = @GetValue($id);
        return is_numeric($v) ? (float) $v : null;
    }

    /** Tag/Nacht-Grenze eines Tagesprofils in Minuten aufloesen (fixed ODER Sonnen-Modus). */
    private function resolveDayMode(array $d, array $sun): ?int
    {
        $mode = (string) ($d['mode'] ?? 'sunrise'); $off = (int) ($d['offset'] ?? 0);
        if ($mode === 'fixed') { $p = explode(':', (string) ($d['time'] ?? '07:00')); return max(0, min(1440, ((int) $p[0]) * 60 + (int) ($p[1] ?? 0) + $off)); }
        if (isset($sun[$mode]) && $sun[$mode] !== null) { return max(0, min(1440, (int) $sun[$mode] + $off)); }
        return null;
    }

    /** Fallback-Tag/Nacht-Position aus dayBegin/dayEnd-Profilen, wenn kein Slot-Plan existiert. */
    private function dayNightTarget(int $ts): ?int
    {
        $db = $this->cfgVal('dayBegin', null); $de = $this->cfgVal('dayEnd', null);
        if (!is_array($db) || !is_array($de)) { return null; }
        $sun = $this->sunEvents($ts);
        $bgn = $this->resolveDayMode($db, $sun); $end = $this->resolveDayMode($de, $sun);
        if ($bgn === null || $end === null) { return null; }
        $minNow = ((int) date('G', $ts)) * 60 + (int) date('i', $ts);
        return ($minNow >= $bgn && $minNow < $end) ? (int) ($db['pos'] ?? 0) : (int) ($de['pos'] ?? 100);
    }

    /** Umgebungswerte lesen (null, wenn Sensor fehlt). */
    private function readInputs(): array
    {
        return [
            'az'     => $this->envNum('sunAzId', self::SUN_AZ_ID),
            'el'     => $this->envNum('sunElId', self::SUN_EL_ID),
            'bright' => $this->envNum('brightId', self::BRIGHT_ID),
            'rad'    => $this->radNow(),
            'wind'   => $this->envNum('windId', self::WIND_ID),
            'rain'   => $this->envBool('rainId', self::RAIN_ID),
        ];
    }

    private function envId(string $key, int $def): int
    {
        $env = $this->cfgVal('env', []);
        $env = is_array($env) ? $env : [];
        return (int) ($env[$key] ?? $def);
    }

    /** Gemessene Globalstrahlung (W/m2) der gebundenen Variablen, sonst null. */
    private function radNow(): ?float
    {
        $id = (int) $this->ReadPropertyInteger('SunRadId');
        if ($id <= 0 || !@\IPS_VariableExists($id)) { return null; }
        $v = @GetValue($id);
        return is_numeric($v) ? (float) $v : null;
    }

    /**
     * Eingefrorene Messwerte gelten als nicht vorhanden.
     *
     * Geprueft wurde bisher nur, ob die Variable EXISTIERT. Ein Fuehler, der vor
     * Wochen verstummt ist, lieferte damit weiter seinen letzten Wert - und der
     * ist genau dann falsch, wenn es darauf ankommt (Wind, Helligkeit, Regen).
     * Nachweislich vorgekommen an #<ID> und #<ID>. null bedeutet fuer die
     * aufrufende Regel "keine Angabe", nicht "Wert 0".
     */
    private const ENV_MAXALTER = 600;   // Sekunden

    private function envNum(string $key, int $def): ?float
    {
        $id = $this->envId($key, $def);
        if ($id <= 0 || !function_exists('IPS_VariableExists') || !@\IPS_VariableExists($id)) {
            return null;
        }
        if (!$this->envFrisch($id)) {
            return null;
        }
        $v = @GetValue($id);
        return is_numeric($v) ? (float) $v : null;
    }

    /** Wurde die Variable in den letzten ENV_MAXALTER Sekunden geschrieben? */
    private function envFrisch(int $id): bool
    {
        $v = @\IPS_GetVariable($id);
        if (!is_array($v) || !isset($v['VariableUpdated'])) { return true; }   // im Zweifel gelten lassen
        $u = (int) $v['VariableUpdated'];
        return $u > 0 && (time() - $u) <= self::ENV_MAXALTER;
    }

    private function envBool(string $key, int $def): ?bool
    {
        $id = $this->envId($key, $def);
        if ($id <= 0 || !function_exists('IPS_VariableExists') || !@\IPS_VariableExists($id)) {
            return null;
        }
        if (!$this->envFrisch($id)) {
            return null;
        }
        $v = @GetValue($id);
        if (is_bool($v)) { return $v; }
        return is_numeric($v) ? ((float) $v > 0) : null;
    }

    /** Ist eine der ueberwachten Tueren (config.doorIds) offen? (truthy = offen) */
    private function anyDoorOpen(): bool
    {
        $ids = $this->cfgVal('doorIds', []);
        if (!is_array($ids)) {
            return false;
        }
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0 && function_exists('IPS_VariableExists') && @\IPS_VariableExists($id)) {
                $v = @GetValue($id);
                if ((is_bool($v) && $v) || (is_numeric($v) && (float) $v > 0)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Richtungsabhaengiger Tuer-Guard: blockt NUR das Zufahren (Ziel geschlossener
     * als Ist) bei offener Tuer. Auffahren/Rueckzug (Ziel offener) und der Sturm-
     * Rueckzug (safePos=offen) bleiben IMMER erlaubt.
     */
    private function closeBlockedByDoor(int $cur, ?int $target): bool
    {
        if ($target === null || $cur === IShutter::POS_UNKNOWN) {
            return false;
        }
        return $target > $cur && $this->anyDoorOpen();
    }

    /**
     * Setzt den Positions-Spiegel auf die zuletzt bekannte echte Lage zurueck.
     *
     * Symcons Basisklasse schreibt den Sollwert einer Control-Variable BEVOR applyControl()
     * ueberhaupt entscheiden kann, ob gefahren wird. Bricht applyControl danach ab, bleibt eine
     * Zahl stehen, die nie ein Motor gesehen hat - und der naechste Automatikabgleich haelt sich
     * fuer fertig ("Ziel erreicht") und faehrt nie wieder. Genau dieser stille Selbstbetrug hat
     * am 17.08.2026 die ganze Fassade eine Nacht lang offen stehen lassen.
     *
     * Keine Rueckmeldung heisst nicht: irgendetwas behaupten. Wenn nichts gefahren wurde, zaehlt
     * die letzte belegte Lage.
     *
     * ACHTUNG, Grenze der Ehrlichkeit: bei Somfy RTS quittiert der Empfaenger nichts. Ein
     * GESENDETES, aber verlorenes Telegramm ist von einem angekommenen nicht zu unterscheiden -
     * dagegen hilft kein Spiegel, sondern nur der Bus-Abstand (ShadeBusGapMs).
     */
    private function mirrorBack(string $ident): void
    {
        if ($ident !== 'Position') {
            return;
        }
        $real = $this->estPos();
        if ($real === IShutter::POS_UNKNOWN) {
            return; // nichts Belegtes da - dann lieber gar nichts behaupten
        }
        $vid = @$this->GetIDForIdent('Position');
        if ($vid && (int) @GetValue($vid) !== (int) $real) {
            $rt = $this->readRt();                 // eigenen Schreibvorgang markieren, sonst
            $rt['selfWriteTs']  = time();          // deutet der Watcher ihn als Handeingriff
            $rt['selfWriteVal'] = (int) $real;
            $this->writeRt($rt);
            $this->SetValue('Position', (int) $real);
            $this->SendDebug('HSSH.mirror', 'Sollwert auf belegte Lage ' . $real . '% zurueckgesetzt (nicht gefahren)', 0);
        }
    }

    /** Aktive Kreuzprodukt-Variante (Plan · Season). */
    private function activeVariant(): string
    {
        $vars = $this->scheduleVariants();
        $idx  = $this->activeVariantIndex();
        return $vars[$idx] ?? ($vars[0] ?? 'Anwesend' . self::VARIANT_SEP . 'Sommer');
    }

    /** Config-Wert (aus dem Store) mit Default. */
    private function cfgVal(string $key, $def)
    {
        $cfg = $this->cfg();
        $v = $cfg[$key] ?? $def;
        return $key === 'armed' ? $this->armedEffective((bool) $v) : $v; // Hub-Master hat Vorrang
    }


    // ==================================================================
    // Manual-Override / externe Eingriffe / Sofort-Safety
    // ==================================================================

    /** Lauscht (idempotent) auf Positions-Variable + Wind + Regen. */
    private function registerWatches(?IShutter $drv): void
    {
        $posVid  = (int) $this->cfgVal('positionId', 0);
        $windVid = $this->envId('windId', self::WIND_ID);
        $rainVid = $this->envId('rainId', self::RAIN_ID);
        $want    = array_values(array_unique(array_filter([$posVid, $windVid, $rainVid], static fn($v) => (int) $v > 0)));

        $rt  = $this->readRt();
        $old = is_array($rt['watchAll'] ?? null) ? $rt['watchAll'] : [];
        foreach ($old as $v) {
            if (!in_array((int) $v, $want, true)) {
                @$this->UnregisterMessage((int) $v, VM_UPDATE);
            }
        }
        foreach ($want as $v) {
            $this->RegisterMessage((int) $v, VM_UPDATE); // idempotent, ueberlebt Reload nicht
        }
        $rt['watchVid'] = $posVid;
        $rt['watchAll'] = $want;
        $this->writeRt($rt);
    }

    /**
     * Native Nachrichtensenke: (a) externe Aenderung der Positions-Variable ->
     * manualHold bis Slot-Grenze; (b) Wind/Regen-Flanke -> SOFORT ein Safety-
     * reconcile (nicht auf den 30s-Tick warten).
     */
    public function MessageSink($Timestamp, $Sender, $Message, $Data)
    {
        parent::MessageSink($Timestamp, $Sender, $Message, $Data);
        if ($Message !== VM_UPDATE) {
            return;
        }

        // Wind und Regen werden im 5-Sekunden-Takt GESCHRIEBEN, aendern sich dabei
        // aber fast nie. Eine Sturm-Flanke setzt zwingend einen geaenderten Wert
        // voraus, also ist jedes readInputs()/stormActive() auf eine unveraenderte
        // Aktualisierung reine Last: allein die Regenvariable kam so auf 19.089
        // Handler-Laeufe in viereinhalb Tagen (je ~128 ms) - zusammen mit Wind
        // 48 Minuten blockierte Nachrichtenzeit ueber alle Beschattungsinstanzen.
        // $Data[1] ist das Changed-Flag von VM_UPDATE. Fehlt es, wird nicht
        // gefiltert. Die Positions-Variable bleibt bewusst aussen vor: dort ist
        // auch ein wertgleicher Fremdschreibvorgang ein Eingriff. Alles Uebrige
        // deckt weiterhin der 30-s-Tick ab.
        if (isset($Data[1]) && !$Data[1]) {
            $wVid = $this->envId('windId', self::WIND_ID);
            $rVid = $this->envId('rainId', self::RAIN_ID);
            if (($wVid > 0 && (int) $Sender === $wVid) || ($rVid > 0 && (int) $Sender === $rVid)) {
                return;
            }
        }
        $rt      = $this->readRt();
        $posVid  = (int) ($rt['watchVid'] ?? 0);
        $windVid = $this->envId('windId', self::WIND_ID);
        $rainVid = $this->envId('rainId', self::RAIN_ID);

        if ($posVid > 0 && (int) $Sender === $posVid) {
            $newVal = isset($Data[0]) && is_numeric($Data[0]) ? (float) $Data[0] : null;
            if ($newVal === null) {
                return;
            }
            // Waehrend einer EIGENEN zeitbasierten Fahrt bzw. einer Kalibrierung stammt
            // jede Aenderung dieser Variable vom Modul selbst (Ramp-Rueckmeldung) - nie
            // als Fremdeingriff werten, sonst holdet sich das Modul selbst.
            if (!empty($rt['moving']) || $this->calLocked()) {
                return;
            }
            $selfTs  = (int) ($rt['selfWriteTs'] ?? 0);
            $selfVal = isset($rt['selfWriteVal']) ? (float) $rt['selfWriteVal'] : null;
            if ($selfVal !== null && abs($selfVal - $newVal) < 1.0 && (time() - $selfTs) <= 10) {
                return; // Self-Write (das war das Modul)
            }
            // Echter Fremdeingriff (Legacy-Automatik, Skript, Visu): Handbedienung -> MANUELL.
            $this->enterManualMode();
            $this->SendDebug('HSSH.override', 'Externe Position ' . $newVal . '% -> Hold bis Slot-Grenze', 0);
            return;
        }
        if (($windVid > 0 && (int) $Sender === $windVid) || ($rainVid > 0 && (int) $Sender === $rainVid)) {
            // NUR bei echter Sturm-FLANKE sofort reagieren. Die Wetterstation
            // aktualisiert alle 2 s; ein Reconcile je Update ist reine Last (und war
            // in Kombination mit dem Fahrt-Abbruch der Grund fuer zerhackte Fahrten).
            // Der normale 30-s-Tick deckt alles Uebrige ab.
            $storm = $this->stormActive($this->readInputs());
            $rtS   = $this->readRt();
            $was   = !empty($rtS['stormWas']);
            if ($storm === $was) {
                return;
            }
            $rtS['stormWas'] = $storm;
            $this->writeRt($rtS);
            $this->SendDebug('HSSH.safety', 'Sturm-Flanke: ' . ($storm ? 'AKTIV' : 'vorbei') . ' -> Sofort-Reconcile', 0);
            $drv = $this->driver();
            if ($drv instanceof IShutter) {
                $this->reconcile($drv); // Sofort-Safety (Schatten-Modus bis armed)
            }
        }
    }


    /** Integer-Wert einer Status-Variable per Ident (0, wenn nicht vorhanden). */
    private function intVal(string $ident): int
    {
        if ($this->GetIDForIdent($ident) === false) {
            return 0;
        }
        $v = @$this->GetValue($ident);
        return is_numeric($v) ? (int) $v : 0;
    }
}

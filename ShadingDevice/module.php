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
    private const REFRESH_MS      = 30000;   // Reflect + Reconcile
    private const POS_TOLERANCE   = 3;        // % Drift, bevor gefahren wird
    private const SAFE_POS        = 0;        // Sturm-/Regen-sichere Position (offen/eingefahren)
    private const SUN_DWELL       = 300;      // Min-Dwell (s) gegen Sonnen-Flattern

    /** Umgebungs-Sensoren (Standort-Defaults; per config.env ueberschreibbar). */
    private const SUN_AZ_ID      = 15291;    // Azimut (Location #<ID>)
    private const SUN_EL_ID      = 45609;    // Elevation
    private const WIND_ID        = 58381;    // Wind (km/h)
    private const RAIN_ID        = 19991;    // Regen
    private const BRIGHT_ID      = 53778;    // Helligkeit
    private const WIND_STORM_KMH = 45.0;     // Sturm-Schwelle (km/h)

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
                    'ident' => 'Mode', 'type' => ControlContract::T_SELECT,
                    'role' => 'shading:mode', 'label' => 'Modus',
                    'varType' => 1, 'actionable' => true,
                    'options' => [
                        ['value' => 0, 'label' => 'Auto'],
                        ['value' => 1, 'label' => 'Manuell'],
                        ['value' => 2, 'label' => 'Sonne'],
                    ],
                ],
                [
                    'ident' => 'Plan', 'type' => ControlContract::T_SELECT,
                    'role' => 'shading:plan', 'label' => 'Plan',
                    'varType' => 1, 'actionable' => true,
                    'options' => [
                        ['value' => 0, 'label' => 'Anwesend'],
                        ['value' => 1, 'label' => 'Abwesend'],
                        ['value' => 2, 'label' => 'Urlaub'],
                    ],
                ],
                [
                    'ident' => 'Season', 'type' => ControlContract::T_SELECT,
                    'role' => 'shading:season', 'label' => 'Saison',
                    'varType' => 1, 'actionable' => true,
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
                [
                    'ident' => 'Online', 'type' => ControlContract::T_REFLECT,
                    'role' => 'shading:online', 'label' => 'Online',
                    'varType' => 0, 'actionable' => false,
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
                ['op' => 'importLegacy',       'label' => 'Aus IPSShadowing importieren'],
                ['op' => 'configureAutomation', 'label' => 'Sonne/Sicherheit konfigurieren'],
                ['op' => 'setArmed',           'label' => 'Scharfschalten / Schatten-Modus'],
                ['op' => 'migrateConfig',      'label' => 'Config auf Properties migrieren (einmalig)'],
                ['op' => 'rotateGeo',          'label' => 'Sonnenprofil-Azimut drehen (Nordausrichtung)'],
                ['op' => 'driverProbe',        'label' => 'Treiber-Status (Diagnose)'],
                ['op' => 'reconcileProbe',     'label' => 'Regel-Entscheidung (Trockenlauf)'],
                ['op' => 'getConfig',          'label' => 'Konfiguration lesen (Diagnose)'],
                ['op' => 'command',            'label' => 'Bedienen (Position/Fahrt/Modus)'],
                ['op' => 'referenceRun',       'label' => 'Referenzfahrt (kalibrieren)'],
                ['op' => 'validate',           'label' => 'Bindung pruefen (Diagnose)'],
            ],

            // ---- Konfig-Felder (Treiberwahl; im LVB gesetzt) ----
            // generic-shutter wird an die IPSShadowing-Position-Variable gebunden;
            // automaticId = deren Automatic-Bool (fuer Cutover/Rollback, M8).
            'configFields' => [
                ['key' => 'driver', 'type' => 'select', 'label' => 'Treiber', 'required' => true,
                 'options' => [
                     ['value' => 'generic-shutter', 'label' => 'Generisch (Positions-Variable)'],
                 ]],
                ['key' => 'positionId',  'type' => 'objid', 'label' => 'Positions-Variable (IPSShadowing)', 'required' => false],
                ['key' => 'automaticId', 'type' => 'objid', 'label' => 'Automatik-Bool (Cutover/Rollback)', 'required' => false],
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
        parent::Create();
        $this->RegisterPropertyString('Driver', '');
        $this->RegisterPropertyBoolean('Invert', false);
        $this->RegisterPropertyInteger('PositionId', 0);
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
        $this->RegisterPropertyInteger('ConfigSchema', 0); // Migrations-Marker
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
            'positionId'   => $this->ReadPropertyInteger('PositionId'),
            'automaticId'  => $this->ReadPropertyInteger('AutomaticId'),
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
        $merged['rainClose'] = $this->hubPropBool('ShadeRainClose', (bool) ($merged['rainClose'] ?? true));
        $env = is_array($merged['env'] ?? null) ? $merged['env'] : [];
        foreach (['sunAzId'=>'ShadeSunAzId','sunElId'=>'ShadeSunElId','windId'=>'ShadeWindId','rainId'=>'ShadeRainId','brightId'=>'ShadeBrightId'] as $k => $hp) {
            $g = (int) $this->hubProp($hp, 0);
            if ($g > 0) { $env[$k] = $g; } // Hub-Sensor gewinnt
        }
        $merged['env'] = $env;
        return $merged;
    }

    private function armed(): bool
    {
        return $this->ReadPropertyBoolean('Armed');
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
        'driver'=>['Driver','s'], 'invert'=>['Invert','b'], 'positionId'=>['PositionId','i'],
        'automaticId'=>['AutomaticId','i'], 'socketId'=>['SocketId','i'], 'channel'=>['Channel','i'],
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
        $add('bl_Automatic', 'IPSShadowing-Automatik', (int) $cfg['automaticId']);
        $env = is_array($cfg['env'] ?? null) ? $cfg['env'] : [];
        $envLabels = ['sunAzId'=>'Sonnen-Azimut','sunElId'=>'Sonnen-Elevation','windId'=>'Wind','rainId'=>'Regen','brightId'=>'Helligkeit'];
        foreach ($envLabels as $k => $lab) { $add('bl_env_' . $k, $lab, (int) ($env[$k] ?? 0)); }
        $tg = is_array($cfg['tempGate'] ?? null) ? $cfg['tempGate'] : [];
        $add('bl_tempSensor', 'Temp-Gate-Sensor', (int) ($tg['sensorId'] ?? 0));
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
        // Schatten-Modus: KEIN reales Fahren/Bus-Telegramm bis armed=true (sonst
        // Kollision mit IPSShadowing auf demselben Socket 45711). Optimistischer
        // SetValue der Basis bleibt (Anzeige folgt), real passiert nichts.
        if (!$this->armed()) {
            $this->SendDebug('HSSH.shadow', $c->ident . '=' . (is_scalar($value) ? (string) $value : '?') . ' (Schatten-Modus)', 0);
            return;
        }
        $drv = $this->driver();
        if (!$drv instanceof IShutter) {
            return; // kein (Shutter-)Treiber gebunden -> Schatten-Modus
        }
        switch ($c->ident) {
            case 'Position':
                $p = (int) max(self::POS_MIN, min(self::POS_MAX, (int) round((float) $value)));
                if ($this->closeBlockedByDoor($drv->readPosition(), $p)) {
                    $this->SendDebug('HSSH.guard', 'Tuer offen -> manuelles Zufahren auf ' . $p . '% blockiert', 0);
                    break;
                }
                $drv->moveTo((float) $p);
                break;
            case 'Movement':
                $drv->move((int) $value === 1 ? 'up' : ((int) $value === 2 ? 'down' : 'stop'));
                break;
        }
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
                $send = static function ($frame) use ($socketId): void {
                    if (function_exists('CSCK_SendText')) {
                        @\CSCK_SendText($socketId, (string) $frame);
                    }
                };
                $this->driverInstance = DriverFactory::create('somfy-rts', [
                    'channel'    => $channel,
                    'repeat'     => (int) ($cfg['repeat'] ?? 3),
                    'stopRepeat' => (int) ($cfg['stopRepeat'] ?? 4),
                    'gapMs'      => (int) ($cfg['gapMs'] ?? 50),
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
            case 'importLegacy':
                return $this->mgmtImportLegacy($args, $ctx);
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
            $automaticId = (int) ($args['automaticId'] ?? 0);
            if ($positionId > 0 && function_exists('IPS_VariableExists') && !\IPS_VariableExists($positionId)) {
                throw new ContractException('positionId #' . $positionId . ' ist keine Variable');
            }
            if ($automaticId > 0 && function_exists('IPS_VariableExists') && !\IPS_VariableExists($automaticId)) {
                throw new ContractException('automaticId #' . $automaticId . ' ist keine Variable');
            }
            if ($positionId <= 0) {
                throw new ContractException('generic-shutter braucht eine Positions-Variable (positionId)');
            }
            $config['positionId']  = $positionId;
            $config['automaticId'] = $automaticId;
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
        // automaticId immer (Cutover/Rollback-Helfer), env/doors immer (Safety/Sonne).
        $keys = ['automaticId'];
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
        if (array_key_exists('tempGate', $args))  { $patch['tempGate']  = is_array($args['tempGate']) ? $args['tempGate'] : null; }
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
     * Adoption EINES IPSShadowing-Geraetes (nicht-destruktiv, wie HeatingZone.ImportLegacy):
     * bindet den Treiber an dessen Position-Variable, uebernimmt Automatic-VID und
     * (best effort) das Sonnenprofil als 'zu verifizieren'. Kein Geraeteschreiben,
     * armed bleibt false (Schatten). dryrun liefert nur die geplante Zuordnung.
     * args: { deviceId } = IPSShadowing-Geraetecontainer (ident Position/Automatic/ProfileSun).
     */
    private function mgmtImportLegacy(array $args, array $ctx): array
    {
        $dev = (int) ($args['deviceId'] ?? 0);
        if ($dev <= 0 || !@\IPS_ObjectExists($dev)) {
            throw new ContractException('deviceId (IPSShadowing-Geraetecontainer) fehlt/ungueltig');
        }
        // Klartextname bevorzugt aus args (IPSShadowing-Container heisst intern
        // 'DeviceN'; der Klartext liegt in der NAMES-Map des Migrationsskripts).
        $name    = (string) ($args['name'] ?? '');
        if ($name === '') { $name = (string) @\IPS_GetName($dev); }
        $posVid  = (int) (@\IPS_GetObjectIDByIdent('Position', $dev) ?: 0);
        $autoVid = (int) (@\IPS_GetObjectIDByIdent('Automatic', $dev) ?: 0);
        if ($posVid <= 0) {
            throw new ContractException('Geraet #' . $dev . ' hat keine Position-Variable');
        }
        // Sonnenprofil aus IPSShadowing ProfileSun (Selektor -> Profil-Kategorie).
        $geo   = null;
        $psSel = @\IPS_GetObjectIDByIdent('ProfileSun', $dev);
        if ($psSel) {
            $pid = (int) @GetValue($psSel);
            if ($pid > 0 && @\IPS_ObjectExists($pid)) {
                $rd  = function ($id) use ($pid) { $v = @\IPS_GetObjectIDByIdent($id, $pid); return $v ? (int) GetValue($v) : null; };
                $bgn = $rd('AzimuthBgn'); $end = $rd('AzimuthEnd'); $el = $rd('Elevation');
                if ($bgn !== null || $end !== null || $el !== null) {
                    $geo = ['azimuthBgn' => (int) $bgn, 'azimuthEnd' => (int) $end, 'elevation' => (int) $el, 'closePct' => 100, 'unverified' => true];
                }
            }
        }
        $config  = ['driver' => 'generic-shutter', 'positionId' => $posVid, 'automaticId' => $autoVid, 'armed' => false];
        $summary = ['deviceId' => $dev, 'name' => $name, 'positionId' => $posVid, 'automaticId' => $autoVid, 'geoProfile' => $geo];

        if (!empty($ctx['dryrun'])) {
            return ['ok' => true, 'dryrun' => true] + $summary;
        }
        $applyCfg = $config;
        if ($geo !== null) { $applyCfg['geoProfile'] = $geo; }
        $this->applyConfigProperties($applyCfg, true); // flach->Properties, geoProfile->Store, ApplyChanges
        if ($name !== '') {
            @\IPS_SetName($this->InstanceID, 'Beschattung ' . $name);
        }
        return ['ok' => true, 'adopted' => true, 'driverActive' => $this->driver() instanceof IShutter] + $summary;
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
                return ['azimuthBgn' => $bgn, 'azimuthEnd' => $end, 'elevation' => $el,
                        'closePct' => (int) ($st['closePct'] ?? 100), 'brightnessMin' => (int) ($st['brightnessMin'] ?? 0)];
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
        return [
            'ok'           => true,
            'driverActive' => true,
            'armed'        => $armed,
            'current'      => $d['cur'],
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

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->driverResolved = false;
        $this->driverInstance = null;

        $drv    = $this->driver();
        $active = $drv instanceof IShutter;
        $this->SetTimerInterval(self::TIMER_REFRESH, $active ? self::REFRESH_MS : 0);
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

    public function SetPosition(int $Percent): bool { return $this->SetControl('Position', $Percent); }

    /** Richtung: up|auf|1 / down|ab|zu|2 / stop|0. */
    public function Move(string $Direction): bool
    {
        $map = ['up' => 1, 'auf' => 1, '1' => 1, 'down' => 2, 'ab' => 2, 'zu' => 2, '2' => 2, 'stop' => 0, '0' => 0];
        $k = strtolower(trim($Direction));
        if (!isset($map[$k])) { $this->LogMessage("HSSH.Move: unbekannte Richtung '{$Direction}'", KL_ERROR); return false; }
        return $this->SetControl('Movement', $map[$k]);
    }
    public function MoveUp(): bool   { return $this->SetControl('Movement', 1); }
    public function MoveDown(): bool { return $this->SetControl('Movement', 2); }
    public function MoveStop(): bool { return $this->SetControl('Movement', 0); }

    public function SetMode(int $Mode): bool             { return $this->SetControl('Mode', $Mode); }
    public function SetPlan(int $Plan): bool             { return $this->SetControl('Plan', $Plan); }
    public function SetSeason(int $Season): bool         { return $this->SetControl('Season', $Season); }
    public function SetSunAzimuthBegin(int $Deg): bool   { return $this->SetControl('SunAzBgn', $Deg); }
    public function SetSunAzimuthEnd(int $Deg): bool     { return $this->SetControl('SunAzEnd', $Deg); }
    public function SetSunElevation(int $Deg): bool      { return $this->SetControl('SunElev', $Deg); }

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
        return json_encode(['elements' => [
            ['type' => 'Label', 'caption' => 'Beschattung — Geraete-Bindung. Sicherheits-Schwellen, Sonnen- und '
                . 'Wetterprofile kommen aus den geteilten Profilen (LiveViewBuilder), nicht hier.'],
            ['type' => 'Select', 'name' => 'cfgDriver', 'caption' => 'Treiber',
                'value' => (string) ($cfg['driver'] ?? 'generic-shutter'), 'options' => [
                    ['caption' => '(keiner / Schatten-Modus)', 'value' => ''],
                    ['caption' => 'Absolutposition 0..100 (generisch / Homematic-LEVEL)', 'value' => 'generic-shutter'],
                    ['caption' => 'Somfy RTS (Bus-Rollo: auf/ab/stop + Fahrzeiten)', 'value' => 'somfy-rts'],
                    ['caption' => 'Homematic-Rollo/Markise (LEVEL, absolut)', 'value' => 'hm-shutter'],
                ]],
            ['type' => 'Label', 'caption' => '— Absolutposition (generisch / Homematic-LEVEL) —'],
            ['type' => 'SelectVariable', 'name' => 'cfgPositionId', 'caption' => 'Positions-Variable (Ziel & Rueckmeldung)',
                'value' => (int) ($cfg['positionId'] ?? 0)],
            ['type' => 'SelectVariable', 'name' => 'cfgAutomaticId', 'caption' => 'IPSShadowing-Automatik-Variable (optional, fuer Cutover/Rollback)',
                'value' => (int) ($cfg['automaticId'] ?? 0)],
            ['type' => 'Label', 'caption' => '— Somfy RTS (Bus-Rollo ohne Positions-Rueckmeldung) —'],
            ['type' => 'SelectInstance', 'name' => 'cfgSocketId', 'caption' => 'Client-Socket (RTS-Gateway)',
                'value' => (int) ($cfg['socketId'] ?? 45711)],
            ['type' => 'NumberSpinner', 'name' => 'cfgChannel', 'caption' => 'RTS-Kanal (1..16)',
                'value' => (int) ($cfg['channel'] ?? 0), 'minimum' => 0, 'maximum' => 16],
            ['type' => 'RowLayout', 'items' => [
                ['type' => 'NumberSpinner', 'name' => 'cfgTimeClosing', 'caption' => 'Fahrzeit ZU (0->100) in s',
                    'value' => (int) ($cfg['timeClosing'] ?? 0), 'minimum' => 0, 'maximum' => 300],
                ['type' => 'NumberSpinner', 'name' => 'cfgTimeOpening', 'caption' => 'Fahrzeit AUF (100->0) in s',
                    'value' => (int) ($cfg['timeOpening'] ?? 0), 'minimum' => 0, 'maximum' => 300],
                ['type' => 'NumberSpinner', 'name' => 'cfgRepeat', 'caption' => 'Sende-Wiederholungen',
                    'value' => (int) ($cfg['repeat'] ?? 3), 'minimum' => 1, 'maximum' => 8],
            ]],
            ['type' => 'Label', 'caption' => '— Homematic-Rollo/Markise (LEVEL-Datenpunkt, absolut) —'],
            ['type' => 'SelectInstance', 'name' => 'cfgHmInstance', 'caption' => 'Homematic-Instanz (LEVEL/STOP)',
                'value' => (int) ($cfg['instanceId'] ?? 0)],
            ['type' => 'CheckBox', 'name' => 'cfgInvert', 'caption' => 'Richtung invertieren (auf/ab bzw. LEVEL 1=offen / 0=zu..100=offen)',
                'value' => (bool) ($cfg['invert'] ?? false)],
            ['type' => 'Button', 'caption' => 'Bindung uebernehmen', 'onClick' =>
                'echo HSSH_Manage($id, json_encode(["op"=>"configureDriver","args"=>['
                . '"driver"=>$cfgDriver,"invert"=>$cfgInvert,'
                . '"positionId"=>$cfgPositionId,"automaticId"=>$cfgAutomaticId,'
                . '"socketId"=>$cfgSocketId,"channel"=>$cfgChannel,"repeat"=>$cfgRepeat,'
                . '"timeOpening"=>$cfgTimeOpening,"timeClosing"=>$cfgTimeClosing,'
                . '"instanceId"=>$cfgHmInstance]]));'],
            ['type' => 'Label', 'caption' => $status],
            ['type' => 'RowLayout', 'items' => [
                ['type' => 'Button', 'caption' => 'Referenzfahrt: voll AUF (setzt 0%)', 'onClick' =>
                    'echo HSSH_Manage($id, json_encode(["op"=>"referenceRun","args"=>["dir"=>"up"]]));'],
                ['type' => 'Button', 'caption' => 'Referenzfahrt: voll ZU (setzt 100%)', 'onClick' =>
                    'echo HSSH_Manage($id, json_encode(["op"=>"referenceRun","args"=>["dir"=>"down"]]));'],
                ['type' => 'Button', 'caption' => 'Bindung pruefen', 'onClick' =>
                    'echo HSSH_Manage($id, json_encode(["op"=>"validate"]));'],
            ]],
            ['type' => 'Label', 'caption' => '— Aussperr-Schutz (Tuer-/Fensterkontakte) —'],
            ['type' => 'Label', 'caption' => 'Bei OFFENEM Kontakt wird das ZUFAHREN blockiert (Auffahren + Sturm-Rueckzug bleiben erlaubt). '
                . 'Kontakte werden markenuebergreifend erkannt (Homematic/HmIP, Z-Wave, Zigbee, Shelly …).'],
            ['type' => 'List', 'name' => 'cfgDoors', 'caption' => 'Kontakte', 'rowCount' => 4, 'add' => true, 'delete' => true,
                'columns' => [['caption' => 'Kontakt', 'name' => 'varId', 'width' => 'auto', 'add' => 0,
                    'edit' => ['type' => 'Select', 'options' => $copts]]],
                'values' => $doorVals],
            ['type' => 'Button', 'caption' => 'Aussperr-Schutz uebernehmen', 'onClick' =>
                'echo HSSH_Manage($id, json_encode(["op"=>"configureAutomation","args"=>["doorIds"=>'
                . 'array_values(array_filter(array_map(function($r){return (int)$r["varId"];}, json_decode($cfgDoors,true)?:[])))]]));'],
            ['type' => 'Label', 'caption' => '— Umgebungs-Sensoren (Sonne/Wind/Regen/Helligkeit fuer die Automatik) —'],
            ['type' => 'RowLayout', 'items' => [
                ['type' => 'SelectVariable', 'name' => 'cfgEnvSunAz', 'caption' => 'Sonnen-Azimut (Grad)',
                    'value' => $envSunAz],
                ['type' => 'SelectVariable', 'name' => 'cfgEnvSunEl', 'caption' => 'Sonnen-Elevation (Grad)',
                    'value' => $envSunEl],
            ]],
            ['type' => 'RowLayout', 'items' => [
                ['type' => 'SelectVariable', 'name' => 'cfgEnvWind', 'caption' => 'Wind (km/h)',
                    'value' => $envWind],
                ['type' => 'SelectVariable', 'name' => 'cfgEnvRain', 'caption' => 'Regen',
                    'value' => $envRain],
                ['type' => 'SelectVariable', 'name' => 'cfgEnvBright', 'caption' => 'Helligkeit',
                    'value' => $envBright],
            ]],
            ['type' => 'Button', 'caption' => 'Umgebungs-Sensoren uebernehmen', 'onClick' =>
                'echo HSSH_Manage($id, json_encode(["op"=>"configureAutomation","args"=>["env"=>['
                . '"sunAzId"=>$cfgEnvSunAz,"sunElId"=>$cfgEnvSunEl,"windId"=>$cfgEnvWind,'
                . '"rainId"=>$cfgEnvRain,"brightId"=>$cfgEnvBright]]]));'],
            ['type' => 'Label', 'caption' => '— Sonnenzeit-Quelle (fuer Sonnen-Anker im Zeitplan) —'],
            ['type' => 'Select', 'name' => 'cfgSunSource', 'caption' => 'Quelle',
                'value' => $sunSource, 'options' => [
                    ['caption' => 'Location-Instanz (Symcon-Standort)', 'value' => 'location'],
                    ['caption' => 'Eigene Koordinaten', 'value' => 'coords'],
                ]],
            ['type' => 'SelectInstance', 'name' => 'cfgLocationId', 'caption' => 'Location-Instanz',
                'value' => $locationId],
            ['type' => 'RowLayout', 'items' => [
                ['type' => 'NumberSpinner', 'name' => 'cfgLat', 'caption' => 'Breite (lat)',
                    'value' => $lat, 'digits' => 5, 'minimum' => -90, 'maximum' => 90],
                ['type' => 'NumberSpinner', 'name' => 'cfgLon', 'caption' => 'Laenge (lon)',
                    'value' => $lon, 'digits' => 5, 'minimum' => -180, 'maximum' => 180],
            ]],
            ['type' => 'Button', 'caption' => 'Sonnenzeit-Quelle uebernehmen', 'onClick' =>
                'echo HSSH_Manage($id, json_encode(["op"=>"configureAutomation","args"=>['
                . '"sunSource"=>$cfgSunSource,"locationId"=>$cfgLocationId,"lat"=>$cfgLat,"lon"=>$cfgLon]]));'],
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
        ]]);
    }

    /** Timer-Callback (prefix HSSH_Refresh): Reflect + Reconcile. Public per SDK. */
    public function Refresh(): void
    {
        $drv = $this->driver();
        if (!$drv instanceof IShutter) {
            return;
        }
        $this->reflectFromDriver($drv);
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
        if ($from === IShutter::POS_UNKNOWN) {
            $this->SendDebug('HSSH.move', 'estPos unbekannt -> keine Absolutfahrt (Referenzfahrt noetig)', 0);
            return false;
        }
        $steps = ShadeKinematics::steps($from, $target, [
            'timeOpening'    => (float) $this->cfgVal('timeOpening', 0),
            'timeClosing'    => (float) $this->cfgVal('timeClosing', 0),
            'runIntoEndstop' => true,
        ]);
        if (empty($steps)) {
            return false; // kein Bewegungsbedarf oder keine Fahrzeiten konfiguriert
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
        $this->SetTimerInterval(self::TIMER_MOVE, $first->durationMs);
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
    }

    /** Timer-Callback (prefix HSSH_MoveDone): beendet die zeitbasierte Fahrt reglaer. */
    public function MoveDone(): void
    {
        $this->finishMove(false);
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
        foreach (['positionId', 'socketId', 'automaticId'] as $k) {
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
    private function reconcile(IShutter $drv): void
    {
        $d = $this->computeDecision($drv, true);
        $target = $d['target'];
        // Globaler Automatik-Schalter (Hub) aus -> keine Komfort-Automatik; Sturm/Safety bleibt.
        if (!$this->automationEnabled() && empty($d['storm'])) {
            return;
        }
        $rt = $this->readRt();
        if ($target === null) {
            $this->writeRt($rt); // Debounce-State ggf. schon in computeDecision persistiert
            return; // nichts erzwingen (keine aktive Regel / Hold ohne Safety)
        }
        $cur    = $d['cur'];
        $armed  = (bool) ($this->cfgVal('armed', false));
        $drift  = ($cur === IShutter::POS_UNKNOWN) || abs($cur - $target) > self::POS_TOLERANCE;

        if (!$drift) {
            $rt['lastTarget'] = $target;
            $this->writeRt($rt);
            return;
        }
        // Tuer-Guard: Zufahren gegen offene Tuer blocken (Auffahren/Sturm bleibt erlaubt).
        if ($d['blockedByDoor']) {
            $this->SendDebug('HSSH.guard', 'Tuer offen -> Zufahren auf ' . $target . '% blockiert', 0);
            $rt['blockedTs'] = time();
            $this->writeRt($rt);
            return;
        }
        if ($armed) {
            // Self-Write VOR dem Schreiben markieren: auch bei SYNCHRONER VM_UPDATE-
            // Zustellung darf der eigene moveTo nicht als externer Eingriff (-> Hold)
            // missdeutet werden.
            $rt['selfWriteTs']  = time();
            $rt['selfWriteVal'] = $target;
            $this->writeRt($rt);
            // Absolut-Treiber: moveTo. Travel-only (Somfy): zeitbasiert ueber driveTo.
            $this->driveTo($drv, (int) $target);
        } else {
            // Schatten-Modus: nur protokollieren, kein Geraeteschreiben. Fuer den
            // Trockenlauf wird der geplante Fahrbefehl inkl. Telegramm-Vorschau geloggt.
            $this->SendDebug('HSSH.shadow', 'Ziel ' . $target . '% (ist ' . $cur . '%, '
                . ($d['storm'] ? 'STURM' : ($d['sunTarget'] !== null ? 'Sonne' : 'Zeitplan')) . ') - nicht scharf | '
                . $this->drivePreview($drv, (int) $target, (int) $cur), 0);
            $rt['shadowTarget'] = $target;
            $rt['shadowTs']     = time();
            $this->writeRt($rt);
        }
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
        $rawSun = (is_array($geo) && $inp['el'] !== null)
            ? $this->schedules()->evalGeo((float) ($inp['az'] ?? 0), (float) $inp['el'], (float) ($inp['bright'] ?? 0), $geo)
            : null;
        $sunTarget = $this->debounceSun($rawSun, $persist);
        // Temp-Gate: Sonnen-Beschattung nur, wenn Temperatur ueber Schwelle (IPSShadowing shadowingByTemp).
        $tg = $this->cfgVal('tempGate', null);
        if (is_array($tg) && $sunTarget !== null) {
            $tv = $this->tempNow($tg);
            if ($tv !== null && $tv < (float) ($tg['aboveC'] ?? 24)) { $sunTarget = null; }
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

    /** Sturm-/Regen-Lage aus den Umgebungssensoren (Regen nur wenn Wetterprofil rainClose). */
    private function stormActive(array $inp): bool
    {
        $windMax = (float) $this->cfgVal('windStormKmh', self::WIND_STORM_KMH);
        $wind    = $inp['wind'];
        $rainOn  = (bool) $this->cfgVal('rainClose', true);
        return (($wind !== null) && $wind >= $windMax) || ($rainOn && $inp['rain'] === true);
    }

    /** Aktuelle Temperatur fuer das Temp-Gate (tg.sensorId; null = kein Sensor -> kein Gate). */
    private function tempNow(array $tg): ?float
    {
        $id = (int) ($tg['sensorId'] ?? 0);
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

    private function envNum(string $key, int $def): ?float
    {
        $id = $this->envId($key, $def);
        if ($id <= 0 || !function_exists('IPS_VariableExists') || !@\IPS_VariableExists($id)) {
            return null;
        }
        $v = @GetValue($id);
        return is_numeric($v) ? (float) $v : null;
    }

    private function envBool(string $key, int $def): ?bool
    {
        $id = $this->envId($key, $def);
        if ($id <= 0 || !function_exists('IPS_VariableExists') || !@\IPS_VariableExists($id)) {
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
        return $cfg[$key] ?? $def;
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
        $rt      = $this->readRt();
        $posVid  = (int) ($rt['watchVid'] ?? 0);
        $windVid = $this->envId('windId', self::WIND_ID);
        $rainVid = $this->envId('rainId', self::RAIN_ID);

        if ($posVid > 0 && (int) $Sender === $posVid) {
            $newVal = isset($Data[0]) && is_numeric($Data[0]) ? (float) $Data[0] : null;
            if ($newVal === null) {
                return;
            }
            $selfTs  = (int) ($rt['selfWriteTs'] ?? 0);
            $selfVal = isset($rt['selfWriteVal']) ? (float) $rt['selfWriteVal'] : null;
            if ($selfVal !== null && abs($selfVal - $newVal) < 1.0 && (time() - $selfTs) <= 10) {
                return; // Self-Write (das war das Modul)
            }
            $this->manualHold('Position', $this->secondsToNextBoundary($this->activeVariant()));
            $this->SendDebug('HSSH.override', 'Externe Position ' . $newVal . '% -> Hold bis Slot-Grenze', 0);
            return;
        }
        if (($windVid > 0 && (int) $Sender === $windVid) || ($rainVid > 0 && (int) $Sender === $rainVid)) {
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

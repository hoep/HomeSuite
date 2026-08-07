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
                ['op' => 'driverProbe',        'label' => 'Treiber-Status (Diagnose)'],
                ['op' => 'reconcileProbe',     'label' => 'Regel-Entscheidung (Trockenlauf)'],
                ['op' => 'getConfig',          'label' => 'Konfiguration lesen (Diagnose)'],
                ['op' => 'command',            'label' => 'Bedienen (Position/Fahrt/Modus)'],
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

    /** Konfigurierte driverId aus dem Store (ohne den Treiber zu bauen). */
    private function configuredDriverId(): string
    {
        $cfg = $this->store()->get('config', []);
        return is_array($cfg) ? (string) ($cfg['driver'] ?? '') : '';
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

        $cfg        = $this->store()->get('config', []);
        $cfg        = is_array($cfg) ? $cfg : [];
        $driverId   = (string) ($cfg['driver'] ?? '');
        $positionId = (int) ($cfg['positionId'] ?? 0);

        if ($driverId === '' || $positionId <= 0) {
            return null; // unkonfiguriert -> applyControl bleibt im Schatten-Modus
        }
        try {
            if ($driverId === 'generic-shutter') {
                $dcfg = [
                    'positionVarId'    => $positionId,
                    'feedbackVarId'    => $positionId,
                    'absolutePosition' => true,
                    'invert'           => (bool) ($cfg['invert'] ?? false),
                ];
                $this->driverInstance = DriverFactory::create('generic-shutter', $dcfg);
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
                $cfg = $this->store()->get('config', []);
                return ['ok' => true, 'config' => is_array($cfg) ? $cfg : []];
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
        if (!in_array($driver, ['', 'generic-shutter'], true)) {
            throw new ContractException('unbekannter Treiber: ' . $driver);
        }
        $positionId  = (int) ($args['positionId'] ?? 0);
        $automaticId = (int) ($args['automaticId'] ?? 0);
        $invert      = (bool) ($args['invert'] ?? false);

        if ($positionId > 0 && function_exists('IPS_VariableExists') && !\IPS_VariableExists($positionId)) {
            throw new ContractException('positionId #' . $positionId . ' ist keine Variable');
        }
        if ($automaticId > 0 && function_exists('IPS_VariableExists') && !\IPS_VariableExists($automaticId)) {
            throw new ContractException('automaticId #' . $automaticId . ' ist keine Variable');
        }
        if ($positionId <= 0 && $driver !== '') {
            throw new ContractException($driver . ' braucht eine Positions-Variable (positionId)');
        }

        $config = ['driver' => $driver, 'positionId' => $positionId, 'automaticId' => $automaticId, 'invert' => $invert];

        if (!empty($ctx['dryrun'])) {
            return ['ok' => true, 'dryrun' => true, 'config' => $config, 'scheduleMode' => 'controller'];
        }

        $this->store()->patch('config', $config);
        $this->driverResolved = false;
        $this->driverInstance = null;
        $active = $this->driver() instanceof IShutter;

        return ['ok' => true, 'config' => $config, 'scheduleMode' => 'controller', 'driverActive' => $active];
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
            $this->store()->patch('config', $patch);
            $this->registerWatches($this->driver()); // env koennte Wind/Regen-IDs geaendert haben
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
        $this->store()->patch('config', $config);
        if ($geo !== null) {
            $this->store()->patch('config', ['geoProfile' => $geo]);
        }
        $this->driverResolved = false;
        $this->driverInstance = null;
        $this->registerWatches($this->driver());
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
        $this->store()->patch('config', ['armed' => $armed]);
        return ['ok' => true, 'armed' => $armed];
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
            'mode'         => $d['mode'],
            'variant'      => $d['variant'],
            'held'         => $d['held'],
            'storm'        => $d['storm'],
            'rawSun'       => $d['rawSun'],
            'sunTarget'    => $d['sunTarget'],
            'schedTarget'  => $d['schedTarget'],
            'inputs'       => $d['inp'],
            'sunEvents'    => $this->sunEvents(time()),
            'geoProfile'   => $this->cfgVal('geoProfile', null),
        ];
    }

    // ==================================================================
    // Lebenszyklus: Refresh-Timer + Watches
    // ==================================================================

    protected function setupTimers(): void
    {
        $this->RegisterTimer(self::TIMER_REFRESH, 0, 'HSSH_Refresh($_IPS[\'TARGET\']);');
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
    }

    /**
     * Konsole: native Bindungs-Ansicht (additiv, ohne Properties -> wirkt sofort
     * auf Bestandsinstanzen, kein Kernel-Neustart). Die Felder sind mit der
     * aktuellen Store-Bindung vorbelegt; „Bindung uebernehmen" ruft configureDriver.
     * Sicherheits-Schwellen und Sonnenprofil kommen aus den geteilten Profilen.
     */
    public function GetConfigurationForm()
    {
        $cfg = $this->store()->get('config', []);
        $cfg = is_array($cfg) ? $cfg : [];
        return json_encode(['elements' => [
            ['type' => 'Label', 'caption' => 'Beschattung — Bindung an die (IPSShadowing-)Positions-Variable. '
                . 'Sicherheits-Schwellen und Sonnenprofil kommen aus den geteilten Profilen (LiveViewBuilder), nicht hier.'],
            ['type' => 'Select', 'name' => 'cfgDriver', 'caption' => 'Treiber',
                'value' => (string) ($cfg['driver'] ?? 'generic-shutter'), 'options' => [
                    ['caption' => '(keiner / Schatten-Modus)', 'value' => ''],
                    ['caption' => 'Generischer Rollladen (Position 0..100)', 'value' => 'generic-shutter'],
                ]],
            ['type' => 'SelectVariable', 'name' => 'cfgPositionId', 'caption' => 'Positions-Variable (Ziel & Rueckmeldung)',
                'value' => (int) ($cfg['positionId'] ?? 0)],
            ['type' => 'SelectVariable', 'name' => 'cfgAutomaticId', 'caption' => 'IPSShadowing-Automatik-Variable (optional, fuer Cutover/Rollback)',
                'value' => (int) ($cfg['automaticId'] ?? 0)],
            ['type' => 'CheckBox', 'name' => 'cfgInvert', 'caption' => 'Position invertieren (0=zu ... 100=offen)',
                'value' => (bool) ($cfg['invert'] ?? false)],
            ['type' => 'Button', 'caption' => 'Bindung uebernehmen', 'onClick' =>
                'echo HSSH_Manage($id, json_encode(["op"=>"configureDriver","args"=>['
                . '"driver"=>$cfgDriver,"positionId"=>$cfgPositionId,"automaticId"=>$cfgAutomaticId,"invert"=>$cfgInvert]]));'],
            ['type' => 'Label', 'caption' => 'Verwaltung/Automatik laufen im LiveViewBuilder; hier nur die Geraete-Bindung.'],
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
        if ($pos !== IShutter::POS_UNKNOWN) {
            $this->setReflect('ActualPosition', $pos);
        }
        $this->setReflect('Online', $pos !== IShutter::POS_UNKNOWN);
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
            if ($drv->moveTo((float) $target)) {
                $this->SetValue('Position', $target);
                $rt['lastSet']      = $target;
                $rt['lastAssertTs'] = time();
                $this->writeRt($rt);
            }
        } else {
            // Schatten-Modus: nur protokollieren, kein Geraeteschreiben.
            $this->SendDebug('HSSH.shadow', 'Ziel ' . $target . '% (ist ' . $cur . '%, '
                . ($d['storm'] ? 'STURM' : ($d['sunTarget'] !== null ? 'Sonne' : 'Zeitplan')) . ') - nicht scharf', 0);
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
        $inp  = $this->readInputs();

        // Sonne: Sonnenstandsvergleich gegen das Raum-Sonnenprofil (evalGeo) + Min-Dwell.
        $geo = $this->cfgVal('geoProfile', null);
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
        $cfg = $this->store()->get('config', []);
        $cfg = is_array($cfg) ? $cfg : [];
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

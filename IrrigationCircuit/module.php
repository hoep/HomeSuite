<?php

declare(strict_types=1);

/**
 * IrrigationCircuit (HSIR) — Domaenen-Modul "Bewaesserung" (Portierung von IPSWatering).
 *
 * Eine Instanz = ein Bewaesserungskreis. Erbt {@see \Hoep\HomeSuite\EntityModule}
 * (Manifest -> Variablen, native RequestAction, RPC-Trio). Gebunden wird der ROH-Aktor
 * (nie eine IPSWatering-Variable) ueber den generischen IValve-Treiber `generic-valve`
 * mit drei Modi (Nutzer-Vorgabe: universell, immer Variable ODER Skript):
 *   - duration : Sekunden an eine aktionsfaehige Variable (z. B. LinkTap StartWateringImmediately),
 *   - switch   : bool on/off (Modul timt die Dauer),
 *   - script   : Start/Stop-Skript.
 *
 * M0/M1-Stand: Gruppengeruest, Bindung, Health/Validate, zeitbasierter Einzel-Executor,
 * Schatten-Modus (config.armed=false -> nur rechnen/loggen, KEIN reales Schalten).
 * Zeitplan-Engine (mehrere Fenster/Tag), Gates (Regen/Temp-%/Evaporation) und Forecast
 * folgen in M2/M3 (siehe IrrigationPlan.md).
 *
 * Klassenname == module.json "name" == GUID {D264A82B-DE31-45CC-8AF2-8F4C5D076508} (Prefix HSIR).
 */

require_once __DIR__ . '/../libs/HomeSuite/autoload.php';

use Hoep\HomeSuite\ActionContext;
use Hoep\HomeSuite\Control;
use Hoep\HomeSuite\ControlContract;
use Hoep\HomeSuite\ContractException;
use Hoep\HomeSuite\EntityModule;
use Hoep\HomeSuite\HAL\DriverFactory;
use Hoep\HomeSuite\HAL\IDriver;
use Hoep\HomeSuite\HAL\IValve;

class IrrigationCircuit extends EntityModule
{
    private const DUR_MIN = 0;
    private const DUR_MAX = 240;          // Minuten (Hard-Clamp-Obergrenze im Formular)
    private const DEF_DURATION = 15;      // Minuten Basisdauer
    private const DEF_MAXRUNTIME = 120;   // Minuten Sicherheits-Clamp

    // Temperatur-Ueberschreib-Regeln (Defaults, im LVB/Formular aenderbar):
    private const DEF_BLOCK_BELOW_C = 10; // < 10 C -> keine Bewaesserung
    private const DEF_COLD_BELOW_C  = 20; // < 20 C -> coldPct
    private const DEF_COLD_PCT      = 80; // -20 %
    private const DEF_HOT_ABOVE_C   = 28; // > 28 C -> hotPct
    private const DEF_HOT_PCT       = 120; // +20 %

    private const TIMER_REFRESH = 'Refresh';   // zyklisch: Reflect + Watchdog
    private const TIMER_RUNSTOP = 'RunStop';    // Ein-Schuss: getimtes Schliessen (switch-Modus)
    private const REFRESH_MS    = 30000;

    /** Lazy-Cache des HAL-Treibers. */
    private ?IDriver $driverInstance = null;
    private bool $driverResolved = false;

    // ==================================================================
    // Manifest (Vertrag 2)
    // ==================================================================

    protected function manifest(): array
    {
        return [
            'domain' => 'irrigation',
            'title'  => 'Bewaesserung',
            'icon'   => 'Drops',

            'controls' => [
                ['ident' => 'Active', 'type' => ControlContract::T_SWITCH, 'role' => 'irrigation:active',
                 'label' => 'Bewaesserung', 'varType' => 0, 'profile' => '~Switch', 'actionable' => true],
                ['ident' => 'Automatic', 'type' => ControlContract::T_SWITCH, 'role' => 'irrigation:automatic',
                 'label' => 'Automatik', 'varType' => 0, 'profile' => '~Switch', 'actionable' => true],
                ['ident' => 'Run', 'type' => ControlContract::T_COMMAND, 'role' => 'irrigation:run',
                 'label' => 'Jetzt bewaessern', 'varType' => 1, 'actionable' => true],
                ['ident' => 'Stop', 'type' => ControlContract::T_COMMAND, 'role' => 'irrigation:stop',
                 'label' => 'Stoppen', 'varType' => 1, 'actionable' => true],
                ['ident' => 'Duration', 'type' => ControlContract::T_SETPOINT, 'role' => 'irrigation:duration',
                 'label' => 'Dauer', 'varType' => 1, 'unit' => 'min',
                 'min' => self::DUR_MIN, 'max' => self::DUR_MAX, 'step' => 1, 'actionable' => true],
                ['ident' => 'SeasonalAdjust', 'type' => ControlContract::T_LEVEL, 'role' => 'irrigation:adjust',
                 'label' => 'Saison-Faktor', 'varType' => 1, 'unit' => '%',
                 'min' => 0, 'max' => 200, 'step' => 5, 'actionable' => true],
                ['ident' => 'Program', 'type' => ControlContract::T_SELECT, 'role' => 'irrigation:program',
                 'label' => 'Programm', 'varType' => 1, 'actionable' => true,
                 'options' => [
                     ['value' => 0, 'label' => 'Manuell'],
                     ['value' => 1, 'label' => 'Taeglich'],
                     ['value' => 2, 'label' => 'Jeden 2. Tag'],
                     ['value' => 3, 'label' => 'Jeden 3. Tag'],
                     ['value' => 4, 'label' => 'Jeden 4. Tag'],
                     ['value' => 5, 'label' => 'Mo/Mi/Fr'],
                     ['value' => 6, 'label' => 'Mo/Do'],
                 ]],
                ['ident' => 'Running', 'type' => ControlContract::T_REFLECT, 'role' => 'irrigation:running',
                 'label' => 'Laeuft', 'varType' => 0, 'profile' => '~Switch', 'actionable' => false],
                ['ident' => 'RainBlocked', 'type' => ControlContract::T_REFLECT, 'role' => 'irrigation:rainblocked',
                 'label' => 'Regen-Sperre', 'varType' => 0, 'profile' => '~Switch', 'actionable' => false],
                ['ident' => 'Rain', 'type' => ControlContract::T_REFLECT, 'role' => 'irrigation:rain',
                 'label' => 'Regen', 'varType' => 2, 'unit' => 'mm', 'actionable' => false],
                ['ident' => 'LastRun', 'type' => ControlContract::T_REFLECT, 'role' => 'irrigation:lastrun',
                 'label' => 'Status', 'varType' => 3, 'actionable' => false],
                ['ident' => 'Online', 'type' => ControlContract::T_REFLECT, 'role' => 'irrigation:online',
                 'label' => 'Online', 'varType' => 0, 'profile' => '~Switch', 'actionable' => false],
            ],

            // Positions-/Bewaesserungs-Wochenplan: MEHRERE Fenster pro Tag (Start+Dauer).
            'profileTypes' => [
                'wateringProgram' => [
                    'label' => 'Bewaesserungs-Wochenplan',
                    'axes'  => ['weekday' => ['MO', 'DI', 'MI', 'DO', 'FR', 'SA', 'SO']],
                    // Konvention: end = Startminute, val = Dauer (Min); mehrere Eintraege/Tag = mehrere Fenster.
                    'slot'  => ['end' => 'HH:MM (Start)', 'val' => ['type' => 'int', 'min' => 1, 'max' => self::DUR_MAX]],
                    'rules' => ['multiPerDay' => true],
                    'editor' => 'slots',
                ],
            ],

            'managementActions' => [
                ['op' => 'createEntity',   'label' => 'Bewaesserung anlegen'],
                ['op' => 'renameEntity',   'label' => 'Umbenennen'],
                ['op' => 'deleteEntity',   'label' => 'Loeschen'],
                ['op' => 'configureDriver', 'label' => 'Aktor/Treiber konfigurieren'],
                ['op' => 'getConfig',      'label' => 'Konfiguration lesen (Diagnose)'],
                ['op' => 'validate',       'label' => 'Bindung pruefen (Diagnose)'],
                ['op' => 'driverProbe',    'label' => 'Treiber-Status (Diagnose)'],
                ['op' => 'runNow',         'label' => 'Jetzt bewaessern'],
                ['op' => 'stopNow',        'label' => 'Stoppen'],
                ['op' => 'setArmed',       'label' => 'Scharfschalten / Schatten-Modus'],
                ['op' => 'configureAutomation', 'label' => 'Regeln (Regen/Temperatur/Evaporation)'],
                ['op' => 'computeProbe',   'label' => 'Dauer/Gate-Berechnung (Trockenlauf)'],
                ['op' => 'updateProfile',  'label' => 'Wochenplan bearbeiten'],
                ['op' => 'getSchedule',    'label' => 'Wochenplan lesen'],
                ['op' => 'importLegacy',   'label' => 'Aus IPSWatering importieren'],
            ],

            'capabilities' => [
                'scheduleMode' => 'controller',
                'multiPerDay'  => true,
                'driver'       => $this->configuredDriverId(),
            ],
        ];
    }

    /** Bedienung dieser Idents oeffnet ein manualHold-Fenster (Automatik-Hoheit, A3). */
    protected function isAutomated(Control $c): bool
    {
        return in_array($c->ident, ['Active', 'Duration', 'Program'], true);
    }

    // ==================================================================
    // Lebenszyklus
    // ==================================================================

    protected function setupTimers(): void
    {
        $this->RegisterTimer(self::TIMER_REFRESH, 0, 'HSIR_Refresh($_IPS[\'TARGET\']);');
        $this->RegisterTimer(self::TIMER_RUNSTOP, 0, 'HSIR_RunStop($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->driverResolved = false;
        $this->driverInstance = null;

        // Einmalige Sinn-Defaults (sonst starten Duration/SeasonalAdjust bei 0).
        if (!(bool) $this->cfgVal('seeded', false)) {
            @$this->SetValue('Duration', self::DEF_DURATION);
            @$this->SetValue('SeasonalAdjust', 100);
            $this->store()->patch('config', ['seeded' => true]);
        }

        $active = $this->driver() instanceof IValve;
        $this->SetTimerInterval(self::TIMER_REFRESH, $active ? self::REFRESH_MS : 0);
        $this->syncReferences();
        $this->updateHealth();
    }

    // ==================================================================
    // Bedien-Hook (Vertrag 1)
    // ==================================================================

    protected function applyControl(Control $c, $value, ActionContext $ctx): void
    {
        switch ($c->ident) {
            case 'Run':
                $min = (int) $value;             // Minuten; <=0 => konfigurierte Dauer
                $this->startRun($min > 0 ? $min * 60 : $this->effectiveSeconds(), true); // explizit -> Gates aus
                break;
            case 'Stop':
                $this->stopRun();
                break;
            case 'Active':
                if ((bool) $value) {
                    $this->startRun($this->effectiveSeconds(), true);
                } else {
                    $this->stopRun();
                }
                break;
            // Duration/SeasonalAdjust/Program/Automatic: Wert steckt bereits in der
            // Statusvariable (optimistic SetValue der Basis) -> keine Aktor-Aktion.
            default:
                $this->SendDebug('HSIR.apply', $c->ident . '=' . (is_scalar($value) ? (string) $value : '?'), 0);
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

        $cfg      = $this->cfg();
        $driverId = (string) ($cfg['driver'] ?? '');
        if ($driverId !== 'generic-valve') {
            return null; // unkonfiguriert -> Schatten-Modus
        }
        $mode = (string) ($cfg['mode'] ?? 'switch');
        // Mindest-Bindung je Modus vorhanden?
        $ok = ($mode === 'switch'   && (int) ($cfg['switchVarId'] ?? 0) > 0)
           || ($mode === 'duration' && (int) ($cfg['startVarId'] ?? 0) > 0)
           || ($mode === 'script'   && (int) ($cfg['onScriptId'] ?? 0) > 0);
        if (!$ok) {
            return null;
        }
        try {
            $this->driverInstance = DriverFactory::create('generic-valve', [
                'mode'          => $mode,
                'switchVarId'   => (int) ($cfg['switchVarId'] ?? 0),
                'startVarId'    => (int) ($cfg['startVarId'] ?? 0),
                'stopVarId'     => (int) ($cfg['stopVarId'] ?? 0),
                'feedbackVarId' => (int) ($cfg['feedbackVarId'] ?? 0),
                'flowVarId'     => (int) ($cfg['flowVarId'] ?? 0),
                'onScriptId'    => (int) ($cfg['onScriptId'] ?? 0),
                'offScriptId'   => (int) ($cfg['offScriptId'] ?? 0),
                'durationVarId' => (int) ($cfg['durationVarId'] ?? 0),
                'invert'        => (bool) ($cfg['invert'] ?? false),
            ]);
        } catch (\Throwable $e) {
            $this->SendDebug('HSIR.driver', 'Treiberaufbau fehlgeschlagen: ' . $e->getMessage(), 0);
            $this->driverInstance = null;
        }
        return $this->driverInstance;
    }

    // ==================================================================
    // Executor (zeitbasiert, Einzel-Lauf) — Schatten-Modus bis armed
    // ==================================================================

    /**
     * Effektive Dauer in Sekunden: Basisdauer x Saison-Faktor x Temperatur-Faktor
     * x Evaporations-Faktor, hart auf maxRuntime geklemmt.
     */
    private function effectiveSeconds(): int
    {
        $baseMin = (float) $this->valOf('Duration', self::DEF_DURATION);
        $adj     = (float) $this->valOf('SeasonalAdjust', 100);
        $maxMin  = (int) $this->cfgVal('maxRuntimeMin', self::DEF_MAXRUNTIME);
        $min     = $baseMin * ($adj / 100.0) * $this->tempFactor() * $this->evapFactor();
        $min     = max(0.0, min((float) $maxMin, $min));
        return (int) round($min * 60.0);
    }

    /** Temperatur-Regel-Konfig (Defaults = Nutzer-Vorgabe: >28 +20%, <20 -20%, <10 keine). */
    private function tempCfg(): array
    {
        $c = $this->cfg();
        $t = (isset($c['temp']) && is_array($c['temp'])) ? $c['temp'] : [];
        return [
            'enabled'     => (bool) ($t['enabled'] ?? true),
            'tempVarId'   => (int) ($t['tempVarId'] ?? 0),
            'blockBelowC' => (float) ($t['blockBelowC'] ?? self::DEF_BLOCK_BELOW_C),
            'coldBelowC'  => (float) ($t['coldBelowC'] ?? self::DEF_COLD_BELOW_C),
            'coldPct'     => (float) ($t['coldPct'] ?? self::DEF_COLD_PCT),
            'hotAboveC'   => (float) ($t['hotAboveC'] ?? self::DEF_HOT_ABOVE_C),
            'hotPct'      => (float) ($t['hotPct'] ?? self::DEF_HOT_PCT),
        ];
    }

    /** Aktuelle Aussentemperatur (bound tempVarId), sonst null. */
    private function tempNow(): ?float
    {
        $id = (int) $this->tempCfg()['tempVarId'];
        if ($id <= 0 || !function_exists('IPS_VariableExists') || !@\IPS_VariableExists($id)) {
            return null;
        }
        $v = @\GetValue($id);
        return is_numeric($v) ? (float) $v : null;
    }

    /**
     * Temperatur-Faktor je Regeln: > hotAboveC -> hotPct%, < coldBelowC -> coldPct%,
     * sonst 100 %. Keine Temperatur/deaktiviert -> 1.0 (keine Skalierung).
     */
    private function tempFactor(): float
    {
        // Generische Schwellen-Regel der Basis (wiederverwendbar in allen Domaenen).
        return $this->ruleTempFactor($this->tempCfg(), $this->tempNow());
    }

    /** Evaporations-Faktor = ET0_aktuell / ET0_ref (geklemmt). Ohne Konfig/Quelle -> 1.0. (Forecast/ET0-Quelle -> M3b) */
    private function evapFactor(): float
    {
        $c = $this->cfg();
        $e = (isset($c['evap']) && is_array($c['evap'])) ? $c['evap'] : [];
        if (empty($e['enabled'])) {
            return 1.0;
        }
        $vid = (int) ($e['et0VarId'] ?? 0);
        $ref = (float) ($e['et0RefMmPerDay'] ?? 4.0);
        if ($vid <= 0 || $ref <= 0 || !@\IPS_VariableExists($vid)) {
            return 1.0;
        }
        $et0 = @\GetValue($vid);
        if (!is_numeric($et0)) {
            return 1.0;
        }
        return max(0.3, min(2.0, ((float) $et0) / $ref));
    }

    /** Aktuelle Regenmenge (sensorId, mm) oder null. */
    private function rainNow(): ?float
    {
        $id = (int) $this->cfgVal('sensorId', 0);
        if ($id <= 0 || !function_exists('IPS_VariableExists') || !@\IPS_VariableExists($id)) {
            return null;
        }
        $v = @\GetValue($id);
        return is_numeric($v) ? (float) $v : null;
    }

    /**
     * Start-Gate: liefert einen Sperrgrund (String) oder null (frei). Kaelte-Sperre
     * (< blockBelowC) und Regen-Sensor-Schwelle. (Forecast/Feuchte -> M3b)
     */
    private function gateBlock(): ?string
    {
        $t = $this->tempCfg();
        if ($this->ruleBlockBelow($t, $this->tempNow())) {
            return 'Kaelte (< ' . rtrim(rtrim(number_format((float) $t['blockBelowC'], 1, ',', ''), '0'), ',') . ' C)';
        }
        $c = $this->cfg();
        $g = (isset($c['rain']) && is_array($c['rain'])) ? $c['rain'] : [];
        if (($g['enabled'] ?? true)) {
            $thr  = (float) ($g['thresholdMm'] ?? 2.0);
            $rain = $this->rainNow();
            if ($rain !== null && $rain >= $thr) {
                return 'Regen ' . $rain . ' mm (>= ' . $thr . ')';
            }
        }
        return null;
    }

    /** Startet einen Bewaesserungslauf ueber $seconds. armed=false -> nur Log (Schatten).
     *  $force=true umgeht die Gates (explizite Bedienung); geplante Laeufe pruefen die Gates. */
    private function startRun(int $seconds, bool $force = false): void
    {
        if ($seconds <= 0) {
            return;
        }
        if (!$force) {
            $reason = $this->gateBlock();
            if ($reason !== null) {
                $this->setReflect('RainBlocked', true);
                $this->setReflect('LastRun', 'Gesperrt: ' . $reason);
                $this->SendDebug('HSIR.gate', 'Start gesperrt: ' . $reason, 0);
                return;
            }
        }
        $this->setReflect('RainBlocked', false);
        $drv = $this->driver();
        if (!$drv instanceof IValve) {
            $this->SendDebug('HSIR.run', 'kein Treiber gebunden', 0);
            return;
        }
        if (!(bool) $this->cfgVal('armed', false)) {
            $this->SendDebug('HSIR.shadow', 'WUERDE bewaessern: ' . $seconds . 's (nicht scharf)', 0);
            $this->setReflect('LastRun', 'Schatten: ' . round($seconds / 60) . ' min geplant');
            return;
        }
        $caps = $drv->capabilities();
        $selfTiming = !empty($caps['selfTiming']);
        $ok = $selfTiming ? $drv->pulse($seconds) : $drv->open();
        if (!$ok) {
            $this->SendDebug('HSIR.run', 'Start fehlgeschlagen', 0);
            return;
        }
        $rt = $this->readRt();
        $rt['running']    = true;
        $rt['runStartTs'] = time();
        $rt['runDurMs']   = $seconds * 1000;
        $rt['selfTiming'] = $selfTiming;
        $this->writeRt($rt);
        $this->setReflect('Running', true);
        $this->setReflect('LastRun', date('d.m. H:i') . ' — ' . round($seconds / 60) . ' min');
        // switch-Modus: Modul schliesst nach der Dauer; duration/script: Geraet timt selbst
        // -> nur Watchdog (Dauer + 30 s Puffer).
        $ms = $selfTiming ? ($seconds * 1000 + 30000) : ($seconds * 1000);
        $this->SetTimerInterval(self::TIMER_RUNSTOP, max(1000, $ms));
    }

    /** Beendet den Lauf: bei switch-Modus schliessen; Status zuruecksetzen. */
    private function stopRun(): void
    {
        $this->SetTimerInterval(self::TIMER_RUNSTOP, 0);
        $rt = $this->readRt();
        $drv = $this->driver();
        if ($drv instanceof IValve && (bool) $this->cfgVal('armed', false)) {
            // Bei duration/script ist der Stop ein aktiver Abbruch; bei switch schliessen.
            $drv->close();
        }
        $rt['running'] = false;
        $this->writeRt($rt);
        $this->setReflect('Running', false);
    }

    /** Timer-Callback (HSIR_RunStop): getimtes Schliessen / Watchdog-Ende. */
    public function RunStop(): void
    {
        $this->stopRun();
    }

    /** Timer-Callback (HSIR_Refresh): Ist-Zustand spiegeln + Watchdog. */
    public function Refresh(): void
    {
        $drv = $this->driver();
        if (!$drv instanceof IValve) {
            return;
        }
        $open = $drv->isOpen();
        if ($open !== null) {
            $this->setReflect('Running', (bool) $open);
            $this->setReflect('Online', true);
        }
        // Watchdog: laeuft laenger als Dauer + Puffer -> hart stoppen.
        $rt = $this->readRt();
        if (!empty($rt['running'])) {
            $elapsed = (time() - (int) ($rt['runStartTs'] ?? time())) * 1000;
            if ($elapsed > ((int) ($rt['runDurMs'] ?? 0) + 60000)) {
                $this->SendDebug('HSIR.watchdog', 'Lauf ueberzogen -> Stop', 0);
                $this->stopRun();
            }
        }
        $this->runSchedule();
    }

    /**
     * Automatik-Zeitplan: startet faellige Fenster des heutigen Tages, sofern Automatik an,
     * das Programm heute bewaessert und kein Lauf aktiv ist. Mehrere Fenster/Tag; je Fenster
     * genau ein Start pro Tag. Gates greifen (nicht force).
     */
    private function runSchedule(): void
    {
        if (!$this->automationEnabled()) {
            return; // globaler Automatik-Schalter (Hub) aus
        }
        if (!(bool) $this->valOf('Automatic', 0)) {
            return;
        }
        $rt = $this->readRt();
        if (!empty($rt['running'])) {
            return; // max. ein Lauf gleichzeitig
        }
        $now  = time();
        $prog = (int) $this->valOf('Program', 0);
        if (!$this->programDue($prog, $now)) {
            return;
        }
        $wd    = (int) date('N', $now) - 1;                 // 0=Mo .. 6=So
        $slots = array_values($this->schedGet('Standard', $wd));
        if (empty($slots)) {
            return;
        }
        $today  = date('Y-m-d', $now);
        $minNow = ((int) date('G', $now)) * 60 + (int) date('i', $now);
        $ran    = (($rt['ranDate'] ?? '') === $today && is_array($rt['ranSlots'] ?? null)) ? $rt['ranSlots'] : [];
        foreach ($slots as $i => $s) {
            $start = (int) ($s['end'] ?? 0);   // Konvention: end = Startminute
            $dur   = (int) ($s['val'] ?? 0);   // val = Dauer (Min)
            if ($dur <= 0 || $minNow < $start || $minNow > $start + 60) {
                continue; // nicht faellig oder Fenster (60 min) verpasst
            }
            if (in_array($i, $ran, true)) {
                continue; // heute schon gelaufen
            }
            $this->startRun($dur * 60, false); // geplant -> Gates aktiv
            $ran[] = $i;
            $rt = $this->readRt();
            $rt['ranDate']     = $today;
            $rt['ranSlots']    = $ran;
            $rt['lastRunDate'] = $today; // Anker fuer "jeden n-ten Tag"
            $this->writeRt($rt);
            break; // ein Fenster pro Refresh-Tick
        }
    }

    /** Bewaessert das Programm heute? (0 Manuell / 1 taegl. / 2-4 jeden n-ten Tag / 5 Mo-Mi-Fr / 6 Mo-Do) */
    private function programDue(int $prog, int $ts): bool
    {
        if ($prog === 0) {
            return false;
        }
        if ($prog === 1) {
            return true;
        }
        if ($prog >= 2 && $prog <= 4) {
            $last = (string) ($this->readRt()['lastRunDate'] ?? '');
            if ($last === '') {
                return true;
            }
            $days = (int) floor((strtotime(date('Y-m-d', $ts)) - strtotime($last)) / 86400);
            return $days >= $prog;
        }
        $wd = (int) date('N', $ts); // 1=Mo .. 7=So
        if ($prog === 5) {
            return in_array($wd, [1, 3, 5], true);
        }
        if ($prog === 6) {
            return in_array($wd, [1, 4], true);
        }
        return false;
    }

    // ==================================================================
    // Verwaltung (mgmt)
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
            case 'runNow':
                $min = (int) ($args['minutes'] ?? 0);
                $sec = $min > 0 ? $min * 60 : $this->effectiveSeconds();
                $this->startRun($sec, (bool) ($args['force'] ?? false));
                return ['ok' => true, 'armed' => (bool) $this->cfgVal('armed', false), 'seconds' => $sec];
            case 'stopNow':
                $this->stopRun();
                return ['ok' => true];
            case 'importLegacy':
                return $this->mgmtImportLegacy($args);
            case 'configureAutomation':
                return $this->mgmtConfigureAutomation($args);
            case 'computeProbe':
                return $this->mgmtComputeProbe();
            case 'setArmed':
                $this->store()->patch('config', ['armed' => (bool) ($args['armed'] ?? false)]);
                $this->updateHealth();
                return ['ok' => true, 'armed' => (bool) $this->cfgVal('armed', false)];
            case 'updateProfile':
                return $this->mgmtUpdateProfile($args, $ctx);
            case 'getSchedule':
                return $this->mgmtGetSchedule($args);
            default:
                return parent::mgmt($op, $args, $ctx);
        }
    }

    /**
     * Aktor-Bindung schreiben (ROH-Aktor, nie IPSWatering-Variable). Drei Modi:
     * duration (Sekunden-Variable), switch (bool), script (Start/Stop). Immer Variable
     * ODER Skript. + RegisterReference-Loeschschutz.
     */
    private function mgmtConfigureDriver(array $args, array $ctx): array
    {
        $mode = (string) ($args['mode'] ?? 'switch');
        if (!in_array($mode, ['switch', 'duration', 'script'], true)) {
            throw new ContractException('mode muss switch|duration|script sein');
        }
        $driver = (string) ($args['driver'] ?? 'generic-valve');
        if (!in_array($driver, ['', 'generic-valve'], true)) {
            throw new ContractException('unbekannter Treiber: ' . $driver);
        }
        $config = ['driver' => $driver, 'mode' => $mode, 'invert' => (bool) ($args['invert'] ?? false)];

        $vexists = function ($id) {
            $id = (int) $id;
            return $id > 0 && function_exists('IPS_VariableExists') && \IPS_VariableExists($id);
        };
        $sexists = function ($id) {
            $id = (int) $id;
            return $id > 0 && function_exists('IPS_ScriptExists') && \IPS_ScriptExists($id);
        };

        if ($mode === 'switch') {
            if (!$vexists($args['switchVarId'] ?? 0)) {
                throw new ContractException('switch-Modus braucht eine Schalt-Variable (switchVarId)');
            }
            $config['switchVarId'] = (int) $args['switchVarId'];
        } elseif ($mode === 'duration') {
            if (!$vexists($args['startVarId'] ?? 0)) {
                throw new ContractException('duration-Modus braucht die Sekunden-Variable (startVarId)');
            }
            $config['startVarId'] = (int) $args['startVarId'];
            $config['stopVarId']  = (int) ($args['stopVarId'] ?? 0);
        } else { // script
            if (!$sexists($args['onScriptId'] ?? 0)) {
                throw new ContractException('script-Modus braucht ein Start-Skript (onScriptId)');
            }
            $config['onScriptId']    = (int) $args['onScriptId'];
            $config['offScriptId']   = (int) ($args['offScriptId'] ?? 0);
            $config['durationVarId'] = (int) ($args['durationVarId'] ?? 0);
        }
        // gemeinsame optionale Bindungen
        $config['feedbackVarId'] = (int) ($args['feedbackVarId'] ?? 0);
        $config['flowVarId']     = (int) ($args['flowVarId'] ?? 0);
        $config['sensorId']      = (int) ($args['sensorId'] ?? 0);   // Regensensor (mm)
        $config['maxRuntimeMin'] = max(1, (int) ($args['maxRuntimeMin'] ?? self::DEF_MAXRUNTIME));

        if (!empty($ctx['dryrun'])) {
            return ['ok' => true, 'dryrun' => true, 'config' => $config];
        }
        $this->store()->patch('config', $config);
        $this->driverResolved = false;
        $this->driverInstance = null;
        $this->syncReferences();
        $this->updateHealth();
        return ['ok' => true, 'config' => $config, 'driverActive' => $this->driver() instanceof IValve];
    }

    /**
     * Aus einer LinkTap-Instanz (bzw. beliebigem Aktor-Container) die aktionsfaehigen
     * Variablen aufloesen und als duration-Bindung uebernehmen. KEINE IPSWatering-Variable.
     * armed bleibt false (Schatten). Fuer den Bulk-Import der Kreise.
     */
    private function mgmtImportLegacy(array $args): array
    {
        $lt = (int) ($args['linkTapId'] ?? 0);
        if ($lt <= 0 || !function_exists('IPS_InstanceExists') || !@\IPS_InstanceExists($lt)) {
            throw new ContractException('linkTapId (Aktor-Instanz) fehlt/ungueltig');
        }
        $start = (int) (@\IPS_GetObjectIDByIdent('StartWateringImmediately', $lt) ?: 0);
        $stop  = (int) (@\IPS_GetObjectIDByIdent('StopWatering', $lt) ?: 0);
        $fb    = (int) (@\IPS_GetObjectIDByIdent('WateringActive', $lt) ?: 0);
        if ($start <= 0) {
            throw new ContractException('StartWateringImmediately auf #' . $lt . ' nicht gefunden');
        }
        $config = [
            'driver' => 'generic-valve', 'mode' => 'duration',
            'startVarId' => $start, 'stopVarId' => $stop, 'feedbackVarId' => $fb,
            'sensorId' => (int) ($args['sensorId'] ?? 0),
            'maxRuntimeMin' => self::DEF_MAXRUNTIME, 'armed' => false,
        ];
        $this->store()->patch('config', $config);
        $this->driverResolved = false;
        $this->driverInstance = null;
        $this->syncReferences();
        $this->updateHealth();
        return ['ok' => true, 'linkTapId' => $lt, 'startVarId' => $start, 'stopVarId' => $stop,
            'feedbackVarId' => $fb, 'driverActive' => $this->driver() instanceof IValve];
    }

    private function mgmtDriverProbe(): array
    {
        $drv = $this->driver();
        if (!$drv instanceof IValve) {
            return ['ok' => true, 'driverActive' => false];
        }
        return ['ok' => true, 'driverActive' => true, 'capabilities' => $drv->capabilities(),
            'isOpen' => $drv->isOpen(), 'flow' => $drv->flow()];
    }

    /**
     * Regeln setzen: Temperatur-Ueberschreibung (>hot +x%, <cold -x%, <block keine),
     * Regen-Schranke, Evaporation. Store-only (kein Geraet). + Referenzen aktualisieren.
     */
    private function mgmtConfigureAutomation(array $args): array
    {
        $patch = [];
        if (isset($args['temp']) && is_array($args['temp'])) {
            $t = $args['temp'];
            $patch['temp'] = [
                'enabled'     => (bool) ($t['enabled'] ?? true),
                'tempVarId'   => (int) ($t['tempVarId'] ?? 0),
                'blockBelowC' => (float) ($t['blockBelowC'] ?? self::DEF_BLOCK_BELOW_C),
                'coldBelowC'  => (float) ($t['coldBelowC'] ?? self::DEF_COLD_BELOW_C),
                'coldPct'     => (float) ($t['coldPct'] ?? self::DEF_COLD_PCT),
                'hotAboveC'   => (float) ($t['hotAboveC'] ?? self::DEF_HOT_ABOVE_C),
                'hotPct'      => (float) ($t['hotPct'] ?? self::DEF_HOT_PCT),
            ];
        }
        if (isset($args['rain']) && is_array($args['rain'])) {
            $patch['rain'] = [
                'enabled'     => (bool) ($args['rain']['enabled'] ?? true),
                'thresholdMm' => (float) ($args['rain']['thresholdMm'] ?? 2.0),
            ];
        }
        if (isset($args['evap']) && is_array($args['evap'])) {
            $patch['evap'] = [
                'enabled'         => (bool) ($args['evap']['enabled'] ?? false),
                'et0VarId'        => (int) ($args['evap']['et0VarId'] ?? 0),
                'et0RefMmPerDay'  => (float) ($args['evap']['et0RefMmPerDay'] ?? 4.0),
            ];
        }
        if ($patch !== []) {
            $this->store()->patch('config', $patch);
            $this->syncReferences();
        }
        return ['ok' => true, 'config' => $this->cfg()];
    }

    /** Trockenlauf: zeigt die berechnete Dauer + Gate-Entscheidung (kein Schalten). */
    private function mgmtComputeProbe(): array
    {
        $t = $this->tempCfg();
        return [
            'ok'             => true,
            'baseMin'        => (float) $this->valOf('Duration', self::DEF_DURATION),
            'seasonalAdjust' => (float) $this->valOf('SeasonalAdjust', 100),
            'tempNow'        => $this->tempNow(),
            'tempFactor'     => $this->tempFactor(),
            'evapFactor'     => $this->evapFactor(),
            'rainNow'        => $this->rainNow(),
            'effectiveSec'   => $this->effectiveSeconds(),
            'effectiveMin'   => round($this->effectiveSeconds() / 60, 1),
            'gate'           => ['blocked' => $this->gateBlock() !== null, 'reason' => $this->gateBlock()],
            'rules'          => $t,
        ];
    }

    private function mgmtUpdateProfile(array $args, array $ctx): array
    {
        $variant = (string) ($args['variant'] ?? 'Standard');
        $day     = (int) ($args['day'] ?? -1);
        if ($day < 0 || $day > 6) {
            throw new ContractException('day muss 0..6 sein');
        }
        $slots = (isset($args['slots']) && is_array($args['slots'])) ? $args['slots'] : [];
        $clean = [];
        foreach ($slots as $s) {
            if (!is_array($s) || !isset($s['end'])) {
                continue; // end = Startminute, val = Dauer(Min)
            }
            $clean[] = ['end' => max(0, min(1440, (int) $s['end'])),
                        'val' => max(1, min(self::DUR_MAX, (int) round((float) ($s['val'] ?? 0))))];
        }
        if (!empty($ctx['dryrun'])) {
            return ['ok' => true, 'dryrun' => true, 'variant' => $variant, 'day' => $day, 'slots' => $clean];
        }
        $this->schedSet($variant, $day, $clean);
        return ['ok' => true, 'variant' => $variant, 'day' => $day, 'slots' => $this->schedGet($variant, $day)];
    }

    private function mgmtGetSchedule(array $args): array
    {
        $variant = (string) ($args['variant'] ?? 'Standard');
        $week = [];
        for ($d = 0; $d < 7; $d++) {
            $week[$d] = $this->schedGet($variant, $d);
        }
        return ['ok' => true, 'variant' => $variant, 'week' => $week];
    }

    // ==================================================================
    // Loeschschutz + Health
    // ==================================================================

    /** RegisterReference nur auf die real genutzten ROH-Bindungen (nie IPSWatering-Vars). */
    private function syncReferences(): void
    {
        if (!method_exists($this, 'GetReferenceList')) {
            return;
        }
        foreach ($this->GetReferenceList() as $ref) {
            @$this->UnregisterReference($ref);
        }
        $cfg  = $this->cfg();
        $mode = (string) ($cfg['mode'] ?? 'switch');
        $ids  = [];
        $add  = function ($id) use (&$ids) {
            $id = (int) $id;
            if ($id > 0) {
                $ids[$id] = true;
            }
        };
        if ($mode === 'switch') {
            $add($cfg['switchVarId'] ?? 0);
        }
        if ($mode === 'duration') {
            $add($cfg['startVarId'] ?? 0);
            $add($cfg['stopVarId'] ?? 0);
        }
        if ($mode === 'script') {
            $add($cfg['onScriptId'] ?? 0);
            $add($cfg['offScriptId'] ?? 0);
            $add($cfg['durationVarId'] ?? 0);
        }
        $add($cfg['feedbackVarId'] ?? 0);
        $add($cfg['flowVarId'] ?? 0);
        $add($cfg['sensorId'] ?? 0);
        if (isset($cfg['temp']) && is_array($cfg['temp'])) {
            $add($cfg['temp']['tempVarId'] ?? 0);
        }
        if (isset($cfg['evap']) && is_array($cfg['evap'])) {
            $add($cfg['evap']['et0VarId'] ?? 0);
        }
        foreach (array_keys($ids) as $id) {
            if (function_exists('IPS_ObjectExists') && @\IPS_ObjectExists($id)) {
                @$this->RegisterReference($id);
            }
        }
    }

    private function mgmtValidate(): array
    {
        $h = $this->computeHealth();
        return ['ok' => $h['ok'], 'health' => $h['text'], 'issues' => $h['issues'], 'config' => $this->cfg()];
    }

    private function computeHealth(): array
    {
        $cfg  = $this->cfg();
        $mode = (string) ($cfg['mode'] ?? '');
        $drv  = (string) ($cfg['driver'] ?? '');
        if ($drv === '' || $mode === '') {
            return ['ok' => false, 'text' => 'inaktiv (kein Treiber)', 'issues' => ['kein Treiber']];
        }
        $issues = [];
        $vchk = function ($id, $label) use (&$issues) {
            $id = (int) $id;
            if ($id > 0 && !(function_exists('IPS_VariableExists') && @\IPS_VariableExists($id))) {
                $issues[] = $label . ' #' . $id . ' fehlt';
            }
        };
        if ($mode === 'switch' && (int) ($cfg['switchVarId'] ?? 0) <= 0) {
            $issues[] = 'Schalt-Variable fehlt';
        }
        if ($mode === 'duration' && (int) ($cfg['startVarId'] ?? 0) <= 0) {
            $issues[] = 'Sekunden-Variable fehlt';
        }
        if ($mode === 'script') {
            $sid = (int) ($cfg['onScriptId'] ?? 0);
            if ($sid <= 0 || !(function_exists('IPS_ScriptExists') && @\IPS_ScriptExists($sid))) {
                $issues[] = 'Start-Skript fehlt';
            }
        }
        $vchk($cfg['switchVarId'] ?? 0, 'switchVarId');
        $vchk($cfg['startVarId'] ?? 0, 'startVarId');
        $vchk($cfg['stopVarId'] ?? 0, 'stopVarId');
        $vchk($cfg['feedbackVarId'] ?? 0, 'feedbackVarId');
        $vchk($cfg['sensorId'] ?? 0, 'sensorId');
        if (!($this->driver() instanceof IValve)) {
            $issues[] = 'Treiber inaktiv';
        }
        if ($issues) {
            return ['ok' => false, 'text' => 'FEHLER: ' . implode(', ', $issues), 'issues' => $issues];
        }
        $armed = (bool) ($cfg['armed'] ?? false);
        return ['ok' => true, 'issues' => [],
            'text' => 'OK · ' . $mode . ($armed ? ' · scharf' : ' · Schatten-Modus')];
    }

    private function updateHealth(): void
    {
        @$this->RegisterVariableString('BindHealth', 'Bindung', '', 90);
        $h = $this->computeHealth();
        @$this->SetValue('BindHealth', (string) $h['text']);
    }

    // ==================================================================
    // Konsolen-Formular (Notfall/Erstkonfiguration; Verwaltung sonst im LVB)
    // ==================================================================

    public function GetConfigurationForm()
    {
        $cfg   = $this->cfg();
        $mode  = (string) ($cfg['mode'] ?? 'switch');
        $armed = (bool) ($cfg['armed'] ?? false);
        $h     = $this->computeHealth();
        return json_encode(['elements' => [
            ['type' => 'Label', 'caption' => 'Bewaesserung — Aktor-Bindung (ROH-Aktor, nie IPSWatering-Variable). '
                . 'Zeitplaene/Automatik laufen im LiveViewBuilder.'],
            ['type' => 'Select', 'name' => 'cfgMode', 'caption' => 'Aktor-Modus',
                'value' => $mode, 'options' => [
                    ['caption' => 'Dauer-Variable (Sekunden, Geraet timt selbst — z. B. LinkTap)', 'value' => 'duration'],
                    ['caption' => 'Schalt-Variable (bool on/off, Modul timt)', 'value' => 'switch'],
                    ['caption' => 'Skript (Start/Stop)', 'value' => 'script'],
                ]],
            ['type' => 'Label', 'caption' => '— Dauer-Variable —'],
            ['type' => 'SelectVariable', 'name' => 'cfgStartVarId', 'caption' => 'Start (Sekunden, aktionsfaehig)',
                'value' => (int) ($cfg['startVarId'] ?? 0)],
            ['type' => 'SelectVariable', 'name' => 'cfgStopVarId', 'caption' => 'Stop (optional)',
                'value' => (int) ($cfg['stopVarId'] ?? 0)],
            ['type' => 'Label', 'caption' => '— Schalt-Variable —'],
            ['type' => 'SelectVariable', 'name' => 'cfgSwitchVarId', 'caption' => 'Schalt-Variable (bool)',
                'value' => (int) ($cfg['switchVarId'] ?? 0)],
            ['type' => 'Label', 'caption' => '— Skript —'],
            ['type' => 'RowLayout', 'items' => [
                ['type' => 'SelectScript', 'name' => 'cfgOnScriptId', 'caption' => 'Start-Skript',
                    'value' => (int) ($cfg['onScriptId'] ?? 0)],
                ['type' => 'SelectScript', 'name' => 'cfgOffScriptId', 'caption' => 'Stop-Skript',
                    'value' => (int) ($cfg['offScriptId'] ?? 0)],
            ]],
            ['type' => 'Label', 'caption' => '— Gemeinsam —'],
            ['type' => 'SelectVariable', 'name' => 'cfgFeedbackVarId', 'caption' => 'Ist-Zustand (optional, bool)',
                'value' => (int) ($cfg['feedbackVarId'] ?? 0)],
            ['type' => 'SelectVariable', 'name' => 'cfgFlowVarId', 'caption' => 'Durchfluss (optional, l/min)',
                'value' => (int) ($cfg['flowVarId'] ?? 0)],
            ['type' => 'SelectVariable', 'name' => 'cfgSensorId', 'caption' => 'Regensensor (optional, mm)',
                'value' => (int) ($cfg['sensorId'] ?? 0)],
            ['type' => 'RowLayout', 'items' => [
                ['type' => 'NumberSpinner', 'name' => 'cfgMaxRuntimeMin', 'caption' => 'Max. Laufzeit (min)',
                    'value' => (int) ($cfg['maxRuntimeMin'] ?? self::DEF_MAXRUNTIME), 'minimum' => 1, 'maximum' => 600],
                ['type' => 'CheckBox', 'name' => 'cfgInvert', 'caption' => 'Invertieren',
                    'value' => (bool) ($cfg['invert'] ?? false)],
            ]],
            ['type' => 'Button', 'caption' => 'Bindung uebernehmen', 'onClick' =>
                'echo HSIR_Manage($id, json_encode(["op"=>"configureDriver","args"=>['
                . '"driver"=>"generic-valve","mode"=>$cfgMode,"invert"=>$cfgInvert,'
                . '"startVarId"=>$cfgStartVarId,"stopVarId"=>$cfgStopVarId,"switchVarId"=>$cfgSwitchVarId,'
                . '"onScriptId"=>$cfgOnScriptId,"offScriptId"=>$cfgOffScriptId,'
                . '"feedbackVarId"=>$cfgFeedbackVarId,"flowVarId"=>$cfgFlowVarId,"sensorId"=>$cfgSensorId,'
                . '"maxRuntimeMin"=>$cfgMaxRuntimeMin]]));'],
            ['type' => 'Label', 'caption' => 'Status: ' . $h['text'] . ' · scharf: ' . ($armed ? 'JA (schaltet real)' : 'nein (Schatten-Modus)')],
            ['type' => 'RowLayout', 'items' => [
                ['type' => 'Button', 'caption' => 'Jetzt bewaessern (Testlauf)', 'onClick' =>
                    'echo HSIR_Manage($id, json_encode(["op"=>"runNow","args"=>[]]));'],
                ['type' => 'Button', 'caption' => 'Stoppen', 'onClick' =>
                    'echo HSIR_Manage($id, json_encode(["op"=>"stopNow","args"=>[]]));'],
                ['type' => 'Button', 'caption' => 'Bindung pruefen', 'onClick' =>
                    'echo HSIR_Manage($id, json_encode(["op"=>"validate"]));'],
            ]],
            ['type' => 'Label', 'caption' => 'Achtung: real geschaltet wird nur bei "scharf" (armed). Vorher werden Laeufe nur protokolliert.'],
        ]]);
    }

    // ==================================================================
    // Helfer
    // ==================================================================

    private function cfg(): array
    {
        $c = $this->store()->get('config', []);
        return is_array($c) ? $c : [];
    }

    /**
     * Eigene Zeitplan-Ablage (Konvention end=Startminute, val=Dauer(Min); mehrere/Tag).
     * BEWUSST NICHT ueber ScheduleEngine (die erzwingt value-until-end + letzter Slot=1440).
     */
    private function schedGet(string $variant, int $day): array
    {
        $sc = $this->store()->get('irriSchedule', []);
        $sc = is_array($sc) ? $sc : [];
        $d  = $sc[$variant][$day] ?? [];
        return is_array($d) ? $d : [];
    }

    private function schedSet(string $variant, int $day, array $slots): void
    {
        $sc = $this->store()->get('irriSchedule', []);
        $sc = is_array($sc) ? $sc : [];
        if (!isset($sc[$variant]) || !is_array($sc[$variant])) {
            $sc[$variant] = [];
        }
        $sc[$variant][(string) $day] = array_values($slots);
        $this->store()->set('irriSchedule', $sc);
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

    /** Aktueller Wert einer Statusvariable per Ident (numerisch), sonst Default. */
    private function valOf(string $ident, $def)
    {
        try {
            $id = @$this->GetIDForIdent($ident);
            if (is_int($id) && $id > 0) {
                $v = @GetValue($id);
                if (is_bool($v) || is_numeric($v)) {
                    return $v;
                }
            }
        } catch (\Throwable $e) {
        }
        return $def;
    }
}

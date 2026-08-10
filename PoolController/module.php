<?php

declare(strict_types=1);

/**
 * PoolController (HSPC) — eigenstaendiges HomeSuite-Domaenenmodul fuer einen
 * ProCon.IP-artigen Pool-Controller (pooldigital.de). Ablösung der PHP-Skript-
 * Klassenfamilie PHPPoolcontroller.
 *
 * Eine Instanz = ein Pool-Controller. Erbt {@see \Hoep\HomeSuite\EntityModule}
 * (Manifest -> Variablen, RPC-Trio, Store, Armed-Gate). Der Geraetezugriff laeuft
 * ueber den selbst-enthaltenen {@see \Hoep\HomeSuite\Drivers\Pool\PoolClient}.
 *
 * STAND P0/P1: reiner LESE-Betrieb. Poll von GetState.csv/GetDos.csv, alle Live-
 * Werte in Statusvariablen. Durchfluss-abhaengige TruePoolTemp-Fusion ist bereits
 * aktiv (Nutzer-Anforderung #2): Inline-Sensor gilt nur bei Durchfluss, sonst der
 * externe In-Pool-Sensor. KEIN Geraete-Schreibzugriff (Relais/Dosierung/Regeln/
 * TReal-Writeback) — der folgt in P2+ ausschliesslich hinter dem Armed-Gate.
 * Alle frueheren Hardcodes (Host/Login/Timeout, Spaltenindizes, Schwellen, Poll)
 * sind Store-Einstellungen.
 *
 * Klassenname == module.json "name" == GUID {878CA345-86D1-84FC-B196-5B3224C067CF} (Prefix HSPC).
 */

require_once __DIR__ . '/../libs/HomeSuite/autoload.php';

use Hoep\HomeSuite\ActionContext;
use Hoep\HomeSuite\Control;
use Hoep\HomeSuite\ControlContract;
use Hoep\HomeSuite\Drivers\Pool\PoolClient;
use Hoep\HomeSuite\EntityModule;

class PoolController extends EntityModule
{
    private const TIMER_POLL   = 'Poll';
    private const POLL_MS_DEF   = 30000;

    /** Verbindungs-/Struktur-Defaults (frueher hartkodiert, jetzt Store-Einstellungen). */
    private const DEF = [
        'host'           => '',
        'user'           => 'admin',
        'pass'           => '',
        'port'           => 80,
        'timeout'        => 10,
        'connectTimeout' => 5,
        'useHttps'       => false,
        'pollInterval'   => self::POLL_MS_DEF,
        'armed'          => false,
        // Spaltenzuordnung (live 192.168.1.30 verifiziert)
        'poolTempCol'    => 8,
        'outsideCol'     => 9,
        'solarCol'       => 10,
        'returnCol'      => 11,
        'pumpTempCol'    => 12,
        'redoxCol'       => 6,
        'phCol'          => 7,
        'pressureCol'    => 3,
        'flowVolCol'     => 4,
        'flowRateCol'    => 24,
        'pumpRelayIndex' => 0,
        'flowThreshold'  => 0.5,   // cm/s (Anstroemung) -> Durchfluss vorhanden
        'flowSettleSeconds' => 120,
        // Umwaelz-Berechnung (mit TruePoolTemp)
        'poolSize'       => 0.0,   // m3 (0 -> Standardformel)
        'flowRate'       => 0.0,   // m3/h Umwaelzleistung
        // TReal (Option A: Modul steuert, KEIN Geraete-Writeback)
        'trealEnabled'   => false,
        'trealSourceVarId' => 0,
        'minPlausible'   => 0.0,
        'maxPlausible'   => 45.0,
        'maxStaleSeconds' => 1800,
    ];

    private const RELAY_LABELS = [
        'Pumpe', 'Ventil Absorber', 'Pumpe Chlor', 'Pumpe pH minus',
        'Pumpe pH plus', 'Scheinwerfer', 'Photovoltaik', 'Relais 8',
    ];

    // ==================================================================
    // Manifest
    // ==================================================================

    protected function manifest(): array
    {
        $R = function (string $ident, string $label, int $varType, string $profile = '') {
            return ['ident' => $ident, 'type' => ControlContract::T_REFLECT, 'role' => 'pool:reflect',
                'label' => $label, 'varType' => $varType, 'profile' => $profile, 'actionable' => false];
        };

        $controls = [
            // --- Wassertemperatur ---
            $R('TruePoolTemp', 'Wassertemperatur (echt)', 2, '~Temperature'),
            $R('WaterTempInline', 'Wassertemperatur Inline (S0)', 2, '~Temperature'),
            $R('TrealExternal', 'Wassertemperatur extern', 2, '~Temperature'),
            $R('TempSource', 'Temperatur-Quelle', 3),
            $R('FlowActive', 'Durchfluss', 0, '~Switch'),
            // --- weitere Temperaturen ---
            $R('TempOutside', 'Aussentemperatur', 2, '~Temperature'),
            $R('TempSolar', 'Solarabsorber', 2, '~Temperature'),
            $R('TempReturn', 'Ruecklauf', 2, '~Temperature'),
            $R('TempPumpHousing', 'Pumpe (Gehaeuse)', 2, '~Temperature'),
            // --- Wasserchemie ---
            $R('PH', 'pH-Wert', 2, 'HSPC.pH'),
            $R('PHTarget', 'pH Sollwert', 2, 'HSPC.pH'),
            $R('Redox', 'Redox', 2, 'HSPC.Redox'),
            $R('RedoxTarget', 'Redox Sollwert', 2, 'HSPC.Redox'),
            // --- Hydraulik ---
            $R('Pressure', 'Kesseldruck', 2, 'HSPC.Pressure'),
            $R('FlowVolume', 'Durchfluss (Volumen)', 2, 'HSPC.Flow'),
            $R('FlowRate', 'Anstroemung', 2, 'HSPC.FlowRate'),
            // --- Kanister-Fuellstaende ---
            $R('ClLevel', 'Chlor Fuellstand', 2, 'HSPC.Percent'),
            $R('PHMinusLevel', 'pH-minus Fuellstand', 2, 'HSPC.Percent'),
            $R('PHPlusLevel', 'pH-plus Fuellstand', 2, 'HSPC.Percent'),
            $R('ClConsumption', 'Chlor Verbrauch', 2, 'HSPC.Milliliter'),
            $R('PHMinusConsumption', 'pH-minus Verbrauch', 2, 'HSPC.Milliliter'),
            $R('PHPlusConsumption', 'pH-plus Verbrauch', 2, 'HSPC.Milliliter'),
        ];

        // --- Dosier-Aktivitaet (read-only, aus GetDos) ---
        $controls[] = $R('ClDosing', 'Chlor dosiert gerade', 0, '~Switch');
        $controls[] = $R('PHMinusDosing', 'pH-minus dosiert gerade', 0, '~Switch');
        $controls[] = $R('PHPlusDosing', 'pH-plus dosiert gerade', 0, '~Switch');

        // --- Relais: Ist-Zustand (reflect) + Modus Auto/Manuell Aus/Manuell Ein (schaltbar) ---
        $relayOpts = [
            ['value' => 0, 'caption' => 'Auto'],
            ['value' => 1, 'caption' => 'Manuell Aus'],
            ['value' => 2, 'caption' => 'Manuell Ein'],
        ];
        for ($i = 0; $i < 8; $i++) {
            $label = self::RELAY_LABELS[$i] ?? ('Relais ' . ($i + 1));
            $controls[] = $R('Relay' . $i, $label, 0, '~Switch'); // Ist an/aus (reflect)
            $controls[] = ['ident' => 'Relay' . $i . 'Mode', 'type' => ControlContract::T_SELECT,
                'role' => 'pool:relaymode', 'label' => $label . ' Modus', 'varType' => 1,
                'profile' => 'HSPC.RelayMode', 'actionable' => true, 'options' => $relayOpts];
        }

        // --- Automatik / System / Fehler / Verbindung ---
        $controls[] = $R('AutoCircOptimal', 'Empf. Umwaelzzeit (Min)', 1);
        $controls[] = $R('Firmware', 'Firmware', 3);
        $controls[] = $R('StatusFlag', 'Statusflag', 1);
        $controls[] = $R('ErrorCount', 'Fehleranzahl', 1);
        $controls[] = $R('ErrorText', 'Fehler/Meldungen', 3);
        $controls[] = $R('LinkOK', 'Verbindung', 0, '~Switch');

        return [
            'domain'   => 'pool',
            'title'    => 'Pool',
            'icon'     => 'Drops',
            'controls' => $controls,
            'managementActions' => [
                ['op' => 'configureConnection', 'label' => 'Verbindung konfigurieren'],
                ['op' => 'configureMapping',    'label' => 'Spalten/Schwellen konfigurieren'],
                ['op' => 'configureTReal',      'label' => 'Echte Wassertemperatur (TReal) konfigurieren'],
                ['op' => 'getConfig',           'label' => 'Konfiguration lesen (Diagnose)'],
                ['op' => 'probe',               'label' => 'Verbindung testen (Diagnose)'],
                ['op' => 'poll',                'label' => 'Jetzt abfragen'],
                ['op' => 'readRaw',             'label' => 'Rohdaten lesen (Diagnose)'],
                ['op' => 'readErrors',          'label' => 'Fehlerlog lesen'],
                ['op' => 'clearErrors',         'label' => 'Fehlerlog loeschen (schreibt)'],
                ['op' => 'setRelayMode',        'label' => 'Relais Auto/Manuell (schreibt)'],
                ['op' => 'doDosage',            'label' => 'Manuelle Dosierung (schreibt)'],
                ['op' => 'resetContainer',      'label' => 'Kanister/Zelle zuruecksetzen (schreibt)'],
                // Konfiguration lesen (Diagnose)
                ['op' => 'getDosageConfig',     'label' => 'Dosier-Konfiguration lesen'],
                ['op' => 'getRules',            'label' => 'Steuerregeln lesen'],
                ['op' => 'getNetwork',          'label' => 'Netzwerk lesen'],
                ['op' => 'getRelayNames',       'label' => 'Relaisnamen lesen'],
                ['op' => 'getDtc',              'label' => 'Alarm-Matrix lesen'],
                ['op' => 'getSensorConfig',     'label' => 'Sensor-Konfig lesen (ADC/BNC/1-Wire/IO)'],
                ['op' => 'getRomCodes',         'label' => '1-Wire ROM-Codes lesen'],
                ['op' => 'computeCirculation',  'label' => 'Umwaelzzeit berechnen (TruePoolTemp)'],
                // Konfiguration schreiben (gated)
                ['op' => 'setDosageConfig',     'label' => 'Dosier-Sollwerte setzen (schreibt)'],
                ['op' => 'setRules',            'label' => 'Steuerregeln setzen (schreibt)'],
                ['op' => 'setNetwork',          'label' => 'Netzwerk setzen (schreibt)'],
                ['op' => 'setDeviceTime',       'label' => 'Geraeteuhr stellen (schreibt)'],
                ['op' => 'setRelayName',        'label' => 'Relaisname setzen (schreibt)'],
                ['op' => 'setDtc',              'label' => 'Alarm-Matrix setzen (schreibt)'],
                ['op' => 'setSensorConfig',     'label' => 'Sensor-Konfig setzen (schreibt)'],
                ['op' => 'setArmed',            'label' => 'Scharfschalten / Schatten-Modus'],
            ],
            'capabilities' => [
                'armed'     => (bool) $this->cfgVal('armed', false),
                'connected' => $this->cfgVal('host', '') !== '',
            ],
        ];
    }

    // ==================================================================
    // Lebenszyklus
    // ==================================================================

    protected function setupTimers(): void
    {
        $this->RegisterTimer(self::TIMER_POLL, 0, 'HSPC_Poll($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        $this->ensureProfiles();       // MUSS vor registerControls (parent) laufen
        parent::ApplyChanges();

        $cfg    = $this->cfg();
        $active = trim((string) ($cfg['host'] ?? '')) !== '';
        $ms     = max(5000, (int) ($cfg['pollInterval'] ?? self::POLL_MS_DEF));
        $this->SetTimerInterval(self::TIMER_POLL, $active ? $ms : 0);
        $this->syncReferences();
    }

    // ==================================================================
    // Poll — GetState.csv lesen und alle Statusvariablen spiegeln
    // ==================================================================

    public function Poll(): void
    {
        $client = $this->client();
        if ($client === null) {
            @$this->SetValue('LinkOK', false);
            return;
        }
        $st = $client->getState();
        if (empty($st['ok'])) {
            @$this->SetValue('LinkOK', false);
            $this->SendDebug('HSPC.poll', 'unreachable: ' . (string) ($st['error'] ?? '?'), 0);
            return;
        }
        @$this->SetValue('LinkOK', true);
        $cfg = $this->cfg();

        $col = fn(string $key, int $def) => (int) ($cfg[$key] ?? $def);
        $inline = PoolClient::colVal($st, $col('poolTempCol', 8));

        @$this->SetValue('WaterTempInline', $this->r2($inline));
        @$this->SetValue('TempOutside', $this->r2(PoolClient::colVal($st, $col('outsideCol', 9))));
        @$this->SetValue('TempSolar', $this->r2(PoolClient::colVal($st, $col('solarCol', 10))));
        @$this->SetValue('TempReturn', $this->r2(PoolClient::colVal($st, $col('returnCol', 11))));
        @$this->SetValue('TempPumpHousing', $this->r2(PoolClient::colVal($st, $col('pumpTempCol', 12))));
        @$this->SetValue('PH', $this->r2(PoolClient::colVal($st, $col('phCol', 7))));
        @$this->SetValue('Redox', $this->r2(PoolClient::colVal($st, $col('redoxCol', 6))));
        @$this->SetValue('Pressure', $this->r2(PoolClient::colVal($st, $col('pressureCol', 3))));
        @$this->SetValue('FlowVolume', $this->r2(PoolClient::colVal($st, $col('flowVolCol', 4))));

        $flowRate = PoolClient::colVal($st, $col('flowRateCol', 24));
        @$this->SetValue('FlowRate', $this->r2($flowRate));

        // Relais spiegeln: Ist-Zustand (bit0) + Modus (Auto/Manuell Aus/Manuell Ein)
        $rbase = PoolClient::COL_RELAY_BASE;
        for ($i = 0; $i < 8; $i++) {
            $state = PoolClient::colVal($st, $rbase + $i);
            $code  = $state === null ? 0 : (int) round($state);
            @$this->SetValue('Relay' . $i, ($code & 1) === 1);
            @$this->SetValue('Relay' . $i . 'Mode', $this->codeToMode($code));
        }
        $pumpCode = PoolClient::colVal($st, $rbase + $col('pumpRelayIndex', 0));
        $pumpOn = $pumpCode !== null && ((int) round($pumpCode) & 1) === 1;

        // Kanister
        $cb = PoolClient::COL_CANISTER;
        @$this->SetValue('ClLevel', $this->r2(PoolClient::colVal($st, $cb + 0)));
        @$this->SetValue('PHMinusLevel', $this->r2(PoolClient::colVal($st, $cb + 1)));
        @$this->SetValue('PHPlusLevel', $this->r2(PoolClient::colVal($st, $cb + 2)));
        @$this->SetValue('ClConsumption', $this->r2(PoolClient::colVal($st, $cb + 3)));
        @$this->SetValue('PHMinusConsumption', $this->r2(PoolClient::colVal($st, $cb + 4)));
        @$this->SetValue('PHPlusConsumption', $this->r2(PoolClient::colVal($st, $cb + 5)));

        // Dosier-Aktivitaet aus den Dosierpumpen-Relais (Chlor=R2, pH-=R3, pH+=R4)
        $relOn = function (int $idx) use ($st, $rbase): bool {
            $v = PoolClient::colVal($st, $rbase + $idx);
            return $v !== null && ((int) round($v) & 1) === 1;
        };
        @$this->SetValue('ClDosing', $relOn(2));
        @$this->SetValue('PHMinusDosing', $relOn(3));
        @$this->SetValue('PHPlusDosing', $relOn(4));

        // System
        @$this->SetValue('Firmware', (string) ($st['firmware'] ?? ''));
        @$this->SetValue('StatusFlag', (int) ($st['statusFlag'] ?? 0));

        // Fehlerlog (gedrosselt: hoechstens alle 300 s, SD-Schonung)
        $rt = $this->readRt();
        if (time() - (int) ($rt['errTs'] ?? 0) > 300) {
            $errs = $this->client()->getErrors();
            if (!empty($errs['ok'])) {
                @$this->SetValue('ErrorCount', (int) ($errs['count'] ?? 0));
                @$this->SetValue('ErrorText', implode("\n", array_slice($errs['lines'] ?? [], -20)));
                $rt['errTs'] = time();
                $this->writeRt($rt);
            }
        }

        // --- Durchfluss-abhaengige TruePoolTemp-Fusion (Nutzer-Anforderung #2) ---
        $this->fuseWaterTemp($inline, $flowRate, $pumpOn);

        // Empfohlene Umwaelzzeit aus der ECHTEN Wassertemperatur (kein Geraetezugriff).
        $trueTemp = @$this->GetValue('TruePoolTemp');
        if (is_numeric($trueTemp) && (float) $trueTemp > 0) {
            @$this->SetValue('AutoCircOptimal', PoolClient::optimalFilterMinutes(
                (float) $trueTemp, (float) ($cfg['poolSize'] ?? 0), (float) ($cfg['flowRate'] ?? 0)));
        }

        // Dosier-Sollwerte (pH/Redox) gedrosselt aus der Konfig lesen (alle 300 s).
        if (time() - (int) ($rt['dosTs'] ?? 0) > 300) {
            $cl = $this->client();
            if ($cl !== null) {
                $rdx = $cl->getDosageConfig(0);
                $phm = $cl->getDosageConfig(1);
                if (!empty($rdx['ok'])) {
                    @$this->SetValue('RedoxTarget', $this->r2((float) ($rdx['config']['target'] ?? 0)));
                }
                if (!empty($phm['ok'])) {
                    @$this->SetValue('PHTarget', $this->r2((float) ($phm['config']['target'] ?? 0)));
                }
                $rt = $this->readRt();
                $rt['dosTs'] = time();
                $this->writeRt($rt);
            }
        }
    }

    /**
     * Bildet die autoritative Wassertemperatur. Bei Durchfluss (Anstroemung ueber
     * Schwelle ODER Pumpe an, nach Settle-Zeit) gilt der Inline-Sensor; sonst der
     * externe In-Pool-Sensor. Ohne gueltigen externen Wert Fallback auf Inline.
     * KEIN Geraete-Writeback (Option A).
     */
    private function fuseWaterTemp(?float $inline, ?float $flowRate, bool $pumpOn): void
    {
        $cfg   = $this->cfg();
        $thr   = (float) ($cfg['flowThreshold'] ?? 0.5);
        $settle = max(0, (int) ($cfg['flowSettleSeconds'] ?? 120));
        $now   = time();

        $flowNow = ($flowRate !== null && $flowRate > $thr) || $pumpOn;

        // Flanken-/Settle-Verfolgung im volatilen RtState.
        $rt = $this->readRt();
        if ($flowNow !== (bool) ($rt['flowState'] ?? false)) {
            $rt['flowState']  = $flowNow;
            $rt['flowSince']  = $now;
            $this->writeRt($rt);
        }
        $settled = ($now - (int) ($rt['flowSince'] ?? $now)) >= $settle;
        $flowSettledOn = $flowNow && $settled;

        @$this->SetValue('FlowActive', $flowNow);

        // Externen Sensor lesen + plausibilisieren.
        $ext        = null;
        $extEnabled = (bool) ($cfg['trealEnabled'] ?? false) && (int) ($cfg['trealSourceVarId'] ?? 0) > 0;
        if ($extEnabled) {
            $ext = $this->readExternalTemp((int) $cfg['trealSourceVarId'], $cfg);
            if ($ext !== null) {
                @$this->SetValue('TrealExternal', $this->r2($ext));
            }
        }

        // Quellen-Entscheidung.
        if ($flowSettledOn && $inline !== null) {
            $true = $inline;
            $src  = 'inline';
        } elseif ($extEnabled && $ext !== null) {
            $true = $ext;
            $src  = 'extern';
        } elseif ($inline !== null) {
            $true = $inline;
            $src  = $extEnabled ? 'fallback' : 'inline';
        } else {
            return; // nichts Sinnvolles
        }

        @$this->SetValue('TruePoolTemp', $this->r2($true));
        @$this->SetValue('TempSource', $src);
    }

    /** Externen In-Pool-Temperatursensor lesen und plausibilisieren (null wenn ungueltig). */
    private function readExternalTemp(int $vid, array $cfg): ?float
    {
        if ($vid <= 0 || !function_exists('IPS_VariableExists') || !@\IPS_VariableExists($vid)) {
            return null;
        }
        $val = @\GetValue($vid);
        if (!is_numeric($val)) {
            return null;
        }
        $val = (float) $val;
        $min = (float) ($cfg['minPlausible'] ?? 0.0);
        $max = (float) ($cfg['maxPlausible'] ?? 45.0);
        if ($val < $min || $val > $max) {
            return null;
        }
        $maxStale = max(0, (int) ($cfg['maxStaleSeconds'] ?? 1800));
        if ($maxStale > 0) {
            $v = @\IPS_GetVariable($vid);
            if (is_array($v) && isset($v['VariableUpdated']) && (time() - (int) $v['VariableUpdated']) > $maxStale) {
                return null; // veraltet
            }
        }
        return $val;
    }

    // ==================================================================
    // Bedien-Hook (P0: keine actionable Controls -> nie aufgerufen)
    // ==================================================================

    protected function applyControl(Control $c, $value, ActionContext $ctx): void
    {
        // Relais-Modus (Auto/Manuell Aus/Manuell Ein) via ENA/MANUAL-Bitmaske.
        if (preg_match('/^Relay(\d+)Mode$/', $c->ident, $m)) {
            $this->applyRelayMode((int) $m[1], (int) $value);
            return;
        }
        $this->SendDebug('HSPC.apply', $c->ident . ' unbehandelt', 0);
    }

    /**
     * Setzt den Modus eines Relais. Liest die aktuellen Modi ALLER 16 Relais aus
     * dem letzten State, aendert nur das Ziel und schreibt die vollstaendige
     * Bitmaske (AUTO bleibt fuer die uebrigen erhalten). Nur bei armed=true real;
     * sonst Schatten (nur Log + optimistischer SetValue der Statusvariable).
     */
    private function applyRelayMode(int $index, int $mode): void
    {
        if ($index < 0 || $index > 15) {
            return;
        }
        if (!$this->writeAllowed('setRelayMode Relay' . $index . '=' . $mode)) {
            return; // Schatten-Modus/Winter: nichts real schalten
        }
        $client = $this->client();
        if ($client === null) {
            return;
        }
        // Aktuelle Modi aller Relais aus einem frischen State ableiten.
        $st = $client->getState();
        $modes = array_fill(0, 16, 'A');
        if (!empty($st['ok'])) {
            for ($i = 0; $i < 16; $i++) {
                $code = PoolClient::colVal($st, PoolClient::COL_RELAY_BASE + $i);
                $modes[$i] = self::modeToChar($this->codeToMode($code === null ? 0 : (int) round($code)));
            }
        }
        $modes[$index] = self::modeToChar($mode);
        $res = $this->writeGuarded('relay', fn() => $client->setRelayModes($modes));
        $this->SendDebug('HSPC.relay', 'Relay' . $index . ' -> ' . self::modeToChar($mode) . ' ok=' . json_encode($res['ok'] ?? false), 0);
        // Zeitnah nachfuehren.
        $this->Poll();
    }

    /** GetState-Relais-Code (0..3) -> Modus 0=Auto,1=Manuell Aus,2=Manuell Ein. */
    private function codeToMode(int $code): int
    {
        if (($code & 2) === 0) {
            return 0; // Auto (egal ob gerade an/aus)
        }
        return ($code & 1) === 1 ? 2 : 1; // Manuell Ein / Manuell Aus
    }

    private static function modeToChar(int $mode): string
    {
        return [0 => 'A', 1 => 'O', 2 => 'I'][$mode] ?? 'A';
    }

    // ==================================================================
    // Schreib-Gate (Armed) + Serialisierung (Semaphore je usrcfg-Sektion)
    // ==================================================================

    /** Darf real geschrieben werden? Nur bei armed=true (Winter-Sperre folgt in P5). */
    private function writeAllowed(string $what): bool
    {
        if (!(bool) $this->cfgVal('armed', false)) {
            $this->SendDebug('HSPC.shadow', $what . ' (Schatten-Modus: nicht real ausgefuehrt)', 0);
            return false;
        }
        return true;
    }

    /**
     * Fuehrt eine Schreiboperation serialisiert aus (Semaphore je Sektion), damit
     * Poll-Timer und Bedienung nicht gleichzeitig auf usrcfg.cgi schreiben.
     */
    private function writeGuarded(string $section, callable $fn): array
    {
        $sem = 'HSPC_' . $this->InstanceID . '_' . $section;
        $have = !function_exists('IPS_SemaphoreEnter') || @\IPS_SemaphoreEnter($sem, 5000);
        try {
            return (array) $fn();
        } finally {
            if ($have && function_exists('IPS_SemaphoreLeave')) {
                @\IPS_SemaphoreLeave($sem);
            }
        }
    }

    // ==================================================================
    // Verwaltungs-RPC
    // ==================================================================

    protected function mgmt(string $op, array $args, array $ctx): array
    {
        switch ($op) {
            case 'configureConnection':
                return $this->mgmtConfigureConnection($args, $ctx);
            case 'configureMapping':
                return $this->mgmtConfigureMapping($args, $ctx);
            case 'configureTReal':
                return $this->mgmtConfigureTReal($args, $ctx);
            case 'getConfig':
                $c = $this->cfg();
                unset($c['pass']); // Passwort nie ausgeben
                $c['passSet'] = ($this->cfgVal('pass', '') !== '');
                return ['ok' => true, 'config' => $c];
            case 'probe':
                $cl = $this->client();
                return $cl === null ? ['ok' => false, 'error' => 'not_configured'] : ['ok' => true] + $cl->ping();
            case 'poll':
                $this->Poll();
                return ['ok' => true, 'linkOk' => (bool) @$this->GetValue('LinkOK')];
            case 'readRaw':
                $cl = $this->client();
                if ($cl === null) {
                    return ['ok' => false, 'error' => 'not_configured'];
                }
                return ['ok' => true, 'state' => $cl->getState(), 'dos' => $cl->getDos()];
            case 'readErrors':
                $cl = $this->client();
                return $cl === null ? ['ok' => false, 'error' => 'not_configured'] : $cl->getErrors();
            case 'clearErrors':
                if (!$this->writeAllowed('clearErrors')) {
                    return ['ok' => true, 'shadow' => true];
                }
                $cl = $this->client();
                return $cl === null ? ['ok' => false, 'error' => 'not_configured'] : $cl->clearErrors();
            case 'setRelayMode':
                return $this->mgmtSetRelayMode($args);
            case 'doDosage':
                return $this->mgmtDoDosage($args);
            case 'resetContainer':
                return $this->mgmtResetContainer($args);

            // --- Konfiguration lesen (ungated) ---
            case 'getDosageConfig':
                return $this->withClient(fn($cl) => $cl->getDosageConfig((int) ($args['type'] ?? 0)));
            case 'getRules':
                return $this->withClient(fn($cl) => $cl->getRules(strtoupper((string) ($args['section'] ?? 'TIMEC'))));
            case 'getNetwork':
                return $this->withClient(fn($cl) => $cl->getNetwork());
            case 'getRelayNames':
                return $this->withClient(fn($cl) => $cl->getRelayNames());
            case 'getDtc':
                return $this->withClient(fn($cl) => $cl->getDtc());
            case 'getRomCodes':
                return $this->withClient(fn($cl) => $cl->getRomCodes());
            case 'getSensorConfig':
                return $this->withClient(function ($cl) {
                    return ['ok' => true, 'adc' => $cl->getAdcConfig(), 'bnc' => $cl->getBncConfig(),
                        'onewire' => $cl->getOneWireConfig(), 'io' => $cl->getIoConfig()];
                });
            case 'computeCirculation':
                return $this->mgmtComputeCirculation($args);

            // --- Konfiguration schreiben (gated) ---
            case 'setDosageConfig':
                return $this->gatedWrite('dosage', 'setDosageConfig',
                    fn($cl) => $cl->setDosageConfig((int) ($args['type'] ?? 0), (array) ($args['config'] ?? [])));
            case 'setRules':
                return $this->gatedWrite('rules', 'setRules',
                    fn($cl) => $cl->setRules(strtoupper((string) ($args['section'] ?? '')), (array) ($args['rules'] ?? [])));
            case 'setNetwork':
                return $this->gatedWrite('network', 'setNetwork',
                    fn($cl) => $cl->setNetwork((array) ($args['network'] ?? [])));
            case 'setDeviceTime':
                return $this->gatedWrite('time', 'setDeviceTime',
                    fn($cl) => $cl->setDeviceTime(isset($args['unix']) ? (int) $args['unix'] : null));
            case 'setRelayName':
                return $this->mgmtSetRelayName($args);
            case 'setDtc':
                return $this->gatedWrite('dtc', 'setDtc',
                    fn($cl) => $cl->setDtc((array) ($args['dtc'] ?? []), (int) ($args['count'] ?? 70)));
            case 'setSensorConfig':
                return $this->mgmtSetSensorConfig($args);

            case 'setArmed':
                $this->store()->patch('config', ['armed' => (bool) ($args['armed'] ?? false)]);
                return ['ok' => true, 'armed' => (bool) $this->cfgVal('armed', false)];
            default:
                return parent::mgmt($op, $args, $ctx);
        }
    }

    private function mgmtConfigureConnection(array $args, array $ctx): array
    {
        $patch = [
            'host'           => trim((string) ($args['host'] ?? $this->cfgVal('host', ''))),
            'user'           => (string) ($args['user'] ?? $this->cfgVal('user', 'admin')),
            'port'           => (int) ($args['port'] ?? $this->cfgVal('port', 80)),
            'timeout'        => max(2, (int) ($args['timeout'] ?? $this->cfgVal('timeout', 10))),
            'connectTimeout' => max(1, (int) ($args['connectTimeout'] ?? $this->cfgVal('connectTimeout', 5))),
            'useHttps'       => (bool) ($args['useHttps'] ?? $this->cfgVal('useHttps', false)),
            'pollInterval'   => max(5000, (int) ($args['pollInterval'] ?? $this->cfgVal('pollInterval', self::POLL_MS_DEF))),
        ];
        // Passwort nur setzen, wenn ein nicht-leerer Wert kommt (leeres Feld = unveraendert).
        if (isset($args['pass']) && (string) $args['pass'] !== '') {
            $patch['pass'] = (string) $args['pass'];
        }
        if (!empty($ctx['dryrun'])) {
            unset($patch['pass']);
            return ['ok' => true, 'dryrun' => true, 'config' => $patch];
        }
        $this->store()->patch('config', $patch);
        $this->ApplyChanges();
        return ['ok' => true, 'probe' => ($this->client() ? $this->client()->ping() : ['ok' => false])];
    }

    private function mgmtConfigureMapping(array $args, array $ctx): array
    {
        $keys = ['poolTempCol', 'outsideCol', 'solarCol', 'returnCol', 'pumpTempCol', 'redoxCol',
            'phCol', 'pressureCol', 'flowVolCol', 'flowRateCol', 'pumpRelayIndex', 'flowSettleSeconds'];
        $patch = [];
        foreach ($keys as $k) {
            if (isset($args[$k])) {
                $patch[$k] = (int) $args[$k];
            }
        }
        foreach (['flowThreshold', 'poolSize', 'flowRate'] as $fk) {
            if (isset($args[$fk])) {
                $patch[$fk] = (float) $args[$fk];
            }
        }
        if (!empty($ctx['dryrun'])) {
            return ['ok' => true, 'dryrun' => true, 'config' => $patch];
        }
        $this->store()->patch('config', $patch);
        return ['ok' => true, 'config' => $patch];
    }

    private function mgmtConfigureTReal(array $args, array $ctx): array
    {
        $vid = (int) ($args['trealSourceVarId'] ?? 0);
        if ($vid > 0 && function_exists('IPS_VariableExists') && !@\IPS_VariableExists($vid)) {
            return ['ok' => false, 'error' => 'variable_not_found', 'vid' => $vid];
        }
        $patch = [
            'trealEnabled'    => (bool) ($args['trealEnabled'] ?? $this->cfgVal('trealEnabled', false)),
            'trealSourceVarId' => $vid,
            'minPlausible'    => (float) ($args['minPlausible'] ?? $this->cfgVal('minPlausible', 0.0)),
            'maxPlausible'    => (float) ($args['maxPlausible'] ?? $this->cfgVal('maxPlausible', 45.0)),
            'maxStaleSeconds' => max(0, (int) ($args['maxStaleSeconds'] ?? $this->cfgVal('maxStaleSeconds', 1800))),
        ];
        if (!empty($ctx['dryrun'])) {
            return ['ok' => true, 'dryrun' => true, 'config' => $patch];
        }
        $this->store()->patch('config', $patch);
        $this->syncReferences();
        return ['ok' => true, 'config' => $patch];
    }

    private function mgmtSetRelayMode(array $args): array
    {
        $index = (int) ($args['index'] ?? -1);
        $mode  = (int) ($args['mode'] ?? 0);
        if ($index < 0 || $index > 15 || $mode < 0 || $mode > 2) {
            return ['ok' => false, 'error' => 'bad_args'];
        }
        if (!$this->writeAllowed('setRelayMode Relay' . $index . '=' . $mode)) {
            return ['ok' => true, 'shadow' => true, 'note' => 'Schatten-Modus: nicht real geschaltet'];
        }
        $client = $this->client();
        if ($client === null) {
            return ['ok' => false, 'error' => 'not_configured'];
        }
        $st = $client->getState();
        $modes = array_fill(0, 16, 'A');
        if (!empty($st['ok'])) {
            for ($i = 0; $i < 16; $i++) {
                $code = PoolClient::colVal($st, PoolClient::COL_RELAY_BASE + $i);
                $modes[$i] = self::modeToChar($this->codeToMode($code === null ? 0 : (int) round($code)));
            }
        }
        $modes[$index] = self::modeToChar($mode);
        $res = $this->writeGuarded('relay', fn() => $client->setRelayModes($modes));
        $this->Poll();
        return ['ok' => (bool) ($res['ok'] ?? false), 'index' => $index, 'mode' => $mode];
    }

    private function mgmtDoDosage(array $args): array
    {
        $type    = (int) ($args['type'] ?? -1);   // 0=Cl/Redox, 1=pH-, 2=pH+
        $seconds = max(0, (int) ($args['seconds'] ?? 0));
        if ($type < 0 || $type > 2) {
            return ['ok' => false, 'error' => 'bad_type'];
        }
        if (!$this->writeAllowed('doDosage type=' . $type . ' s=' . $seconds)) {
            return ['ok' => true, 'shadow' => true, 'note' => 'Schatten-Modus: keine Dosierung ausgeloest'];
        }
        $client = $this->client();
        if ($client === null) {
            return ['ok' => false, 'error' => 'not_configured'];
        }
        $res = $this->writeGuarded('dosage', fn() => $client->manualDosage($type, $seconds));
        return ['ok' => (bool) ($res['ok'] ?? false), 'type' => $type, 'seconds' => $seconds];
    }

    private function mgmtResetContainer(array $args): array
    {
        $type   = (int) ($args['type'] ?? -1);
        $liters = (float) ($args['liters'] ?? 0);
        if ($type < 0 || $type > 2 || $liters <= 0) {
            return ['ok' => false, 'error' => 'bad_args'];
        }
        if (!$this->writeAllowed('resetContainer type=' . $type . ' l=' . $liters)) {
            return ['ok' => true, 'shadow' => true];
        }
        $client = $this->client();
        if ($client === null) {
            return ['ok' => false, 'error' => 'not_configured'];
        }
        $res = $this->writeGuarded('dosage', fn() => $client->resetContainer($type, $liters));
        return ['ok' => (bool) ($res['ok'] ?? false), 'type' => $type, 'liters' => $liters];
    }

    /** Lese-Op mit Client (ungated). */
    private function withClient(callable $fn): array
    {
        $cl = $this->client();
        if ($cl === null) {
            return ['ok' => false, 'error' => 'not_configured'];
        }
        return (array) $fn($cl);
    }

    /** Schreib-Op hinter Armed-Gate + Semaphore je Sektion. */
    private function gatedWrite(string $section, string $what, callable $fn): array
    {
        if (!$this->writeAllowed($what)) {
            return ['ok' => true, 'shadow' => true, 'note' => 'Schatten-Modus: nicht real geschrieben'];
        }
        $cl = $this->client();
        if ($cl === null) {
            return ['ok' => false, 'error' => 'not_configured'];
        }
        $res = $this->writeGuarded($section, fn() => $fn($cl));
        return ['ok' => (bool) ($res['ok'] ?? false)] + (is_array($res) ? $res : []);
    }

    private function mgmtSetRelayName(array $args): array
    {
        $index = (int) ($args['index'] ?? -1);
        $name  = (string) ($args['name'] ?? '');
        if ($index < 0 || $index > 15 || $name === '') {
            return ['ok' => false, 'error' => 'bad_args'];
        }
        return $this->gatedWrite('relnames', 'setRelayName', function ($cl) use ($index, $name) {
            $cur = $cl->getRelayNames();
            $names = $cur['names'] ?? array_fill(0, 16, 'n.a.');
            $names[$index] = $name;
            return $cl->setRelayNames($names);
        });
    }

    private function mgmtSetSensorConfig(array $args): array
    {
        $kind = strtolower((string) ($args['kind'] ?? ''));
        return $this->gatedWrite('sensorcfg', 'setSensorConfig', function ($cl) use ($kind, $args) {
            switch ($kind) {
                case 'adc':     return $cl->setAdcConfig((array) ($args['channels'] ?? []));
                case 'bnc':     return $cl->setBncConfig((array) ($args['channels'] ?? []));
                case 'onewire': return $cl->setOneWireConfig((array) ($args['sensors'] ?? []));
                case 'io':      return $cl->setIoConfig((array) ($args['ios'] ?? []));
                default:        return ['ok' => false, 'error' => 'bad_kind'];
            }
        });
    }

    private function mgmtComputeCirculation(array $args): array
    {
        $cfg  = $this->cfg();
        $temp = @$this->GetValue('TruePoolTemp');
        $temp = is_numeric($temp) ? (float) $temp : 0.0;
        $opt  = PoolClient::optimalFilterMinutes($temp, (float) ($cfg['poolSize'] ?? 0), (float) ($cfg['flowRate'] ?? 0));
        @$this->SetValue('AutoCircOptimal', $opt);
        return ['ok' => true, 'trueTemp' => $temp, 'optimalMinutes' => $opt,
            'poolSize' => (float) ($cfg['poolSize'] ?? 0), 'flowRate' => (float) ($cfg['flowRate'] ?? 0)];
    }

    // ==================================================================
    // Client / Profile / Helfer
    // ==================================================================

    private function client(): ?PoolClient
    {
        $cfg = $this->cfg();
        if (trim((string) ($cfg['host'] ?? '')) === '') {
            return null;
        }
        return new PoolClient([
            'host'           => (string) $cfg['host'],
            'user'           => (string) ($cfg['user'] ?? 'admin'),
            'pass'           => (string) ($cfg['pass'] ?? ''),
            'port'           => (int) ($cfg['port'] ?? 80),
            'timeout'        => (int) ($cfg['timeout'] ?? 10),
            'connectTimeout' => (int) ($cfg['connectTimeout'] ?? 5),
            'useHttps'       => (bool) ($cfg['useHttps'] ?? false),
        ]);
    }

    private function ensureProfiles(): void
    {
        $this->profileFloat('HSPC.pH', ' ', 2, 0, 14);
        $this->profileFloat('HSPC.Redox', ' mV', 0, 0, 1200);
        $this->profileFloat('HSPC.Pressure', ' mBar', 0, 0, 3000);
        $this->profileFloat('HSPC.Flow', ' m³/h', 1, 0, 100);
        $this->profileFloat('HSPC.FlowRate', ' cm/s', 2, 0, 200);
        $this->profileFloat('HSPC.Percent', ' %', 1, 0, 100);
        $this->profileFloat('HSPC.Milliliter', ' ml', 0, 0, 100000);
        $this->profileRelayMode();
    }

    private function profileRelayMode(): void
    {
        if (!function_exists('IPS_VariableProfileExists')) {
            return;
        }
        $name = 'HSPC.RelayMode';
        if (!@\IPS_VariableProfileExists($name)) {
            @\IPS_CreateVariableProfile($name, 1); // Integer
        }
        @\IPS_SetVariableProfileValues($name, 0, 2, 0);
        @\IPS_SetVariableProfileAssociation($name, 0, 'Auto', '', -1);
        @\IPS_SetVariableProfileAssociation($name, 1, 'Manuell Aus', '', -1);
        @\IPS_SetVariableProfileAssociation($name, 2, 'Manuell Ein', '', -1);
    }

    private function profileFloat(string $name, string $suffix, int $digits, float $min, float $max): void
    {
        if (!function_exists('IPS_VariableProfileExists')) {
            return;
        }
        if (!@\IPS_VariableProfileExists($name)) {
            @\IPS_CreateVariableProfile($name, 2); // 2 = Float
        }
        @\IPS_SetVariableProfileText($name, '', $suffix);
        @\IPS_SetVariableProfileDigits($name, $digits);
        @\IPS_SetVariableProfileValues($name, $min, $max, 0);
    }

    private function syncReferences(): void
    {
        if (!method_exists($this, 'GetReferenceList')) {
            return;
        }
        foreach ($this->GetReferenceList() as $ref) {
            @$this->UnregisterReference($ref);
        }
        $vid = (int) $this->cfgVal('trealSourceVarId', 0);
        if ($vid > 0 && function_exists('IPS_ObjectExists') && @\IPS_ObjectExists($vid)) {
            @$this->RegisterReference($vid);
        }
    }

    private function cfg(): array
    {
        $c = $this->store()->get('config', []);
        $c = is_array($c) ? $c : [];
        return $c + self::DEF;
    }

    private function cfgVal(string $key, $def)
    {
        $c = $this->cfg();
        return array_key_exists($key, $c) ? $c[$key] : $def;
    }

    /** Runde auf 2 Nachkommastellen, null bleibt null. */
    private function r2(?float $v): float
    {
        return $v === null ? 0.0 : round($v, 2);
    }

    // ==================================================================
    // Konsolen-Formular (Erstkonfiguration + Diagnose)
    // ==================================================================

    public function GetConfigurationForm()
    {
        $cfg   = $this->cfg();
        $armed = (bool) ($cfg['armed'] ?? false);
        $host  = (string) ($cfg['host'] ?? '');

        return json_encode(['elements' => [
            ['type' => 'Label', 'caption' => 'Pool-Controller (ProCon.IP). Verwaltung/Bedienung spaeter im LiveViewBuilder; '
                . 'hier Erstkonfiguration + Diagnose. Schreibbetrieb (Relais/Dosierung) erst ab spaeteren Phasen und nur bei "scharf".'],

            ['type' => 'ExpansionPanel', 'caption' => 'Verbindung', 'expanded' => ($host === ''), 'items' => [
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'ValidationTextBox', 'name' => 'cfgHost', 'caption' => 'Host/IP', 'value' => $host],
                    ['type' => 'NumberSpinner', 'name' => 'cfgPort', 'caption' => 'Port', 'value' => (int) ($cfg['port'] ?? 80)],
                    ['type' => 'CheckBox', 'name' => 'cfgHttps', 'caption' => 'HTTPS', 'value' => (bool) ($cfg['useHttps'] ?? false)],
                ]],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'ValidationTextBox', 'name' => 'cfgUser', 'caption' => 'Benutzer', 'value' => (string) ($cfg['user'] ?? 'admin')],
                    ['type' => 'PasswordTextBox', 'name' => 'cfgPass', 'caption' => 'Passwort (leer = unveraendert)', 'value' => ''],
                ]],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'NumberSpinner', 'name' => 'cfgTimeout', 'caption' => 'Timeout (s)', 'value' => (int) ($cfg['timeout'] ?? 10)],
                    ['type' => 'NumberSpinner', 'name' => 'cfgConnTimeout', 'caption' => 'Connect-Timeout (s)', 'value' => (int) ($cfg['connectTimeout'] ?? 5)],
                    ['type' => 'NumberSpinner', 'name' => 'cfgPoll', 'caption' => 'Poll-Intervall (ms)', 'value' => (int) ($cfg['pollInterval'] ?? self::POLL_MS_DEF), 'minimum' => 5000],
                ]],
                ['type' => 'Button', 'caption' => 'Verbindung speichern & testen', 'onClick' =>
                    'echo HSPC_Manage($id, json_encode(["op"=>"configureConnection","args"=>['
                    . '"host"=>$cfgHost,"port"=>$cfgPort,"useHttps"=>$cfgHttps,"user"=>$cfgUser,"pass"=>$cfgPass,'
                    . '"timeout"=>$cfgTimeout,"connectTimeout"=>$cfgConnTimeout,"pollInterval"=>$cfgPoll]]));'],
            ]],

            ['type' => 'ExpansionPanel', 'caption' => 'Echte Wassertemperatur (TReal) — externer In-Pool-Sensor', 'items' => [
                ['type' => 'Label', 'caption' => 'Die Inline-Sensoren messen nur bei laufender Pumpe korrekt. Bei stehender Pumpe '
                    . 'nutzt das Modul den externen In-Pool-Sensor als echte Wassertemperatur (TruePoolTemp). Kein Geraete-Rueckschreiben.'],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'CheckBox', 'name' => 'cfgTrealEnabled', 'caption' => 'Aktiv', 'value' => (bool) ($cfg['trealEnabled'] ?? false)],
                    ['type' => 'SelectVariable', 'name' => 'cfgTrealVar', 'caption' => 'In-Pool-Sensor (Variable)', 'value' => (int) ($cfg['trealSourceVarId'] ?? 0)],
                ]],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'NumberSpinner', 'name' => 'cfgTrealMin', 'caption' => 'Plausibel min (°C)', 'value' => (float) ($cfg['minPlausible'] ?? 0), 'digits' => 1],
                    ['type' => 'NumberSpinner', 'name' => 'cfgTrealMax', 'caption' => 'Plausibel max (°C)', 'value' => (float) ($cfg['maxPlausible'] ?? 45), 'digits' => 1],
                    ['type' => 'NumberSpinner', 'name' => 'cfgTrealStale', 'caption' => 'Max. Alter (s)', 'value' => (int) ($cfg['maxStaleSeconds'] ?? 1800)],
                ]],
                ['type' => 'Button', 'caption' => 'TReal speichern', 'onClick' =>
                    'echo HSPC_Manage($id, json_encode(["op"=>"configureTReal","args"=>['
                    . '"trealEnabled"=>$cfgTrealEnabled,"trealSourceVarId"=>$cfgTrealVar,'
                    . '"minPlausible"=>$cfgTrealMin,"maxPlausible"=>$cfgTrealMax,"maxStaleSeconds"=>$cfgTrealStale]]));'],
            ]],

            ['type' => 'ExpansionPanel', 'caption' => 'Spaltenzuordnung & Durchfluss (Standard passt fuer ProCon.IP)', 'items' => [
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'NumberSpinner', 'name' => 'mapPool', 'caption' => 'Pool-Temp Spalte', 'value' => (int) ($cfg['poolTempCol'] ?? 8)],
                    ['type' => 'NumberSpinner', 'name' => 'mapPh', 'caption' => 'pH Spalte', 'value' => (int) ($cfg['phCol'] ?? 7)],
                    ['type' => 'NumberSpinner', 'name' => 'mapRedox', 'caption' => 'Redox Spalte', 'value' => (int) ($cfg['redoxCol'] ?? 6)],
                    ['type' => 'NumberSpinner', 'name' => 'mapFlowRate', 'caption' => 'Anstroemung Spalte', 'value' => (int) ($cfg['flowRateCol'] ?? 24)],
                ]],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'NumberSpinner', 'name' => 'mapPumpRelay', 'caption' => 'Pumpen-Relais Index', 'value' => (int) ($cfg['pumpRelayIndex'] ?? 0)],
                    ['type' => 'NumberSpinner', 'name' => 'mapFlowThr', 'caption' => 'Durchfluss-Schwelle (cm/s)', 'value' => (float) ($cfg['flowThreshold'] ?? 0.5), 'digits' => 2],
                    ['type' => 'NumberSpinner', 'name' => 'mapSettle', 'caption' => 'Settle-Zeit (s)', 'value' => (int) ($cfg['flowSettleSeconds'] ?? 120)],
                ]],
                ['type' => 'Label', 'caption' => '— Umwaelz-Berechnung (mit echter Wassertemperatur) —'],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'NumberSpinner', 'name' => 'mapPoolSize', 'caption' => 'Poolvolumen (m³, 0=Standardformel)', 'value' => (float) ($cfg['poolSize'] ?? 0), 'digits' => 1],
                    ['type' => 'NumberSpinner', 'name' => 'mapFlowRate2', 'caption' => 'Umwaelzleistung (m³/h)', 'value' => (float) ($cfg['flowRate'] ?? 0), 'digits' => 1],
                ]],
                ['type' => 'Button', 'caption' => 'Zuordnung speichern', 'onClick' =>
                    'echo HSPC_Manage($id, json_encode(["op"=>"configureMapping","args"=>['
                    . '"poolTempCol"=>$mapPool,"phCol"=>$mapPh,"redoxCol"=>$mapRedox,"flowRateCol"=>$mapFlowRate,'
                    . '"pumpRelayIndex"=>$mapPumpRelay,"flowThreshold"=>$mapFlowThr,"flowSettleSeconds"=>$mapSettle,'
                    . '"poolSize"=>$mapPoolSize,"flowRate"=>$mapFlowRate2]]));'],
            ]],

            ['type' => 'ExpansionPanel', 'caption' => 'Steuerung & Test (schreibt nur bei "scharf")', 'items' => [
                ['type' => 'Label', 'caption' => 'Scharfschalten aktiviert reale Schreibzugriffe (Relais/Dosierung). Im Schatten-Modus wird nur protokolliert.'],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'Button', 'caption' => ($armed ? 'Scharf: AN — jetzt entschaerfen' : 'Scharf schalten'), 'onClick' =>
                        'echo HSPC_Manage($id, json_encode(["op"=>"setArmed","args"=>["armed"=>' . ($armed ? 'false' : 'true') . ']]));'],
                ]],
                ['type' => 'Label', 'caption' => '— Relais Auto/Manuell —'],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'NumberSpinner', 'name' => 'relIdx', 'caption' => 'Relais-Index (0..15)', 'value' => 0, 'minimum' => 0, 'maximum' => 15],
                    ['type' => 'Select', 'name' => 'relMode', 'caption' => 'Modus', 'value' => 0, 'options' => [
                        ['caption' => 'Auto', 'value' => 0],
                        ['caption' => 'Manuell Aus', 'value' => 1],
                        ['caption' => 'Manuell Ein', 'value' => 2],
                    ]],
                    ['type' => 'Button', 'caption' => 'Relais setzen', 'onClick' =>
                        'echo HSPC_Manage($id, json_encode(["op"=>"setRelayMode","args"=>["index"=>$relIdx,"mode"=>$relMode]]));'],
                ]],
                ['type' => 'Label', 'caption' => '— Manuelle Dosierung (Sekunden) —'],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'Select', 'name' => 'dosType', 'caption' => 'Mittel', 'value' => 0, 'options' => [
                        ['caption' => 'Chlor/Redox', 'value' => 0],
                        ['caption' => 'pH-minus', 'value' => 1],
                        ['caption' => 'pH-plus', 'value' => 2],
                    ]],
                    ['type' => 'NumberSpinner', 'name' => 'dosSec', 'caption' => 'Dauer (s, 0=Stop)', 'value' => 0, 'minimum' => 0, 'maximum' => 600],
                    ['type' => 'Button', 'caption' => 'Dosieren', 'onClick' =>
                        'echo HSPC_Manage($id, json_encode(["op"=>"doDosage","args"=>["type"=>$dosType,"seconds"=>$dosSec]]));'],
                ]],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'Button', 'caption' => 'Fehlerlog lesen', 'onClick' =>
                        'echo HSPC_Manage($id, json_encode(["op"=>"readErrors"]));'],
                    ['type' => 'Button', 'caption' => 'Fehlerlog loeschen', 'onClick' =>
                        'echo HSPC_Manage($id, json_encode(["op"=>"clearErrors"]));'],
                ]],
                ['type' => 'Label', 'caption' => '— Sollwerte (Dosierung) —'],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'Select', 'name' => 'dcType', 'caption' => 'Regler', 'value' => 1, 'options' => [
                        ['caption' => 'Chlor/Redox (mV)', 'value' => 0],
                        ['caption' => 'pH-minus', 'value' => 1],
                        ['caption' => 'pH-plus', 'value' => 2],
                    ]],
                    ['type' => 'NumberSpinner', 'name' => 'dcTarget', 'caption' => 'Sollwert', 'value' => 7.2, 'digits' => 2],
                    ['type' => 'NumberSpinner', 'name' => 'dcLow', 'caption' => 'Min', 'value' => 6.6, 'digits' => 2],
                    ['type' => 'NumberSpinner', 'name' => 'dcHigh', 'caption' => 'Max', 'value' => 7.6, 'digits' => 2],
                    ['type' => 'Button', 'caption' => 'Sollwerte setzen', 'onClick' =>
                        'echo HSPC_Manage($id, json_encode(["op"=>"setDosageConfig","args"=>["type"=>$dcType,'
                        . '"config"=>["enabled"=>true,"target"=>$dcTarget,"lowerLimit"=>$dcLow,"upperLimit"=>$dcHigh]]]));'],
                ]],
                ['type' => 'Label', 'caption' => 'Hinweis: setzt Sollwert/Min/Max des gewaehlten Reglers. Weitere Dosier-Parameter zuvor mit "Dosier-Konfiguration lesen" pruefen.'],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'Button', 'caption' => 'Geraeteuhr stellen (jetzt)', 'onClick' =>
                        'echo HSPC_Manage($id, json_encode(["op"=>"setDeviceTime"]));'],
                    ['type' => 'Button', 'caption' => 'Umwaelzzeit berechnen', 'onClick' =>
                        'echo HSPC_Manage($id, json_encode(["op"=>"computeCirculation"]));'],
                    ['type' => 'Button', 'caption' => 'Netzwerk lesen', 'onClick' =>
                        'echo HSPC_Manage($id, json_encode(["op"=>"getNetwork"]));'],
                    ['type' => 'Button', 'caption' => 'Sensor-Konfig lesen', 'onClick' =>
                        'echo HSPC_Manage($id, json_encode(["op"=>"getSensorConfig"]));'],
                ]],
            ]],

            ['type' => 'Label', 'caption' => 'Status: ' . ($host === '' ? 'nicht konfiguriert' : ('Host ' . $host))
                . ' · scharf: ' . ($armed ? 'JA' : 'nein (Schatten-Modus)')],
            ['type' => 'RowLayout', 'items' => [
                ['type' => 'Button', 'caption' => 'Verbindung testen', 'onClick' =>
                    'echo HSPC_Manage($id, json_encode(["op"=>"probe"]));'],
                ['type' => 'Button', 'caption' => 'Jetzt abfragen', 'onClick' =>
                    'echo HSPC_Manage($id, json_encode(["op"=>"poll"]));'],
                ['type' => 'Button', 'caption' => 'Konfiguration lesen', 'onClick' =>
                    'echo HSPC_Manage($id, json_encode(["op"=>"getConfig"]));'],
                ['type' => 'Button', 'caption' => 'Rohdaten lesen', 'onClick' =>
                    'echo HSPC_Manage($id, json_encode(["op"=>"readRaw"]));'],
            ]],
        ]]);
    }
}

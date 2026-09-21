<?php

declare(strict_types=1);

/**
 * EnergyManager — die ENTSCHEIDENDE Ebene der Energie-Domaene.
 *
 * Gemessen und gerechnet wird an dieser Anlage laengst: "Energy Distribution" liefert
 * Ueberschuss, Einspeisung, Erzeugung und Verbrauch live, drei "Energierechner" fuehren
 * Verbrauch und Kosten je Tag/Woche/Monat/Jahr, "PowerPrice" haelt die stuendliche
 * Preisvorhersage. Was fehlte, war nicht eine zweite Messebene, sondern die Frage: WANN
 * soll etwas laufen?
 *
 * Genau das tut dieses Modul. Es misst nichts und rechnet nichts nach - es liest die
 * vorhandenen Werte und entscheidet daraus, ob eine verschiebbare Last jetzt laufen soll.
 *
 * VERSCHIEBBAR ist, was HomeSuite auch schalten kann: Pool (Filterpumpe), Maeher,
 * Bewaesserungskreise. Haushaltsgeraete wie Trockner oder Spueler sind an dieser Anlage
 * SENSOREN, keine Aktoren - sie melden ihren Zustand, lassen sich aber nicht fernstarten.
 * Fuer sie gibt es deshalb bewusst nur eine EMPFEHLUNG ("jetzt guenstig"), keinen Befehl:
 * lieber ehrlich raten als Steuerung vortaeuschen.
 *
 * Wie alle Domaenen kennt das Modul Scharf und Schatten. Im Schatten rechnet es durch und
 * protokolliert, was es TAETE - so laesst sich der Cutover an Belegen entscheiden statt am
 * Bauchgefuehl. Jede Entscheidung samt Grund landet ueber entscheidungMerken() im
 * Entscheidungs-Log und damit im domaenenuebergreifenden Protokoll.
 */

require_once __DIR__ . '/../libs/HomeSuite/autoload.php';

use Hoep\HomeSuite\ActionContext;
use Hoep\HomeSuite\Control;
use Hoep\HomeSuite\ControlContract;
use Hoep\HomeSuite\EntityModule;

class EnergyManager extends EntityModule
{
    private const TIMER_TICK = 'Tick';

    /** Vorgaben, bewusst zurueckhaltend: lieber nichts verschieben als falsch verschieben. */
    private const DEF_INTERVAL_S   = 300;   // 5 Minuten - Preise gelten stundenweise
    private const DEF_SURPLUS_W    = 800;   // ab so viel Ueberschuss lohnt eine Last
    private const DEF_PRICE_CT     = 0.0;   // 0 = Preisregel aus (erst einschalten, wenn geprueft)
    private const DEF_MINRUN_MIN   = 30;    // kuerzere Laeufe kosten mehr, als sie bringen

    protected function entityLabel(): string { return 'Energie'; }

    protected function manifest(): array
    {
        return [
            'domain' => 'energy',
            'title'  => 'Energie',
            'icon'   => 'EnergyProduction',

            'controls' => [
                ['ident' => 'Automatic', 'type' => ControlContract::T_SWITCH, 'role' => 'energy:automatic',
                 'label' => 'Lastverschiebung', 'varType' => 0, 'profile' => '~Switch', 'actionable' => true],
                // --- Anzeige: woraus entschieden wird ---
                ['ident' => 'Surplus', 'type' => ControlContract::T_REFLECT, 'role' => 'energy:surplus',
                 'label' => 'Ueberschuss', 'varType' => 2, 'unit' => ' W', 'actionable' => false],
                ['ident' => 'Price', 'type' => ControlContract::T_REFLECT, 'role' => 'energy:price',
                 'label' => 'Strompreis', 'varType' => 2, 'unit' => ' ct/kWh', 'actionable' => false],
                ['ident' => 'PriceRank', 'type' => ControlContract::T_REFLECT, 'role' => 'energy:pricerank',
                 'label' => 'Guenstigste Stunde von', 'varType' => 1, 'actionable' => false],
                ['ident' => 'Cheap', 'type' => ControlContract::T_REFLECT, 'role' => 'energy:cheap',
                 'label' => 'Jetzt guenstig', 'varType' => 0, 'profile' => '~Switch', 'actionable' => false],
                ['ident' => 'CheapWindow', 'type' => ControlContract::T_REFLECT, 'role' => 'energy:cheapwindow',
                 'label' => 'Guenstigstes Fenster', 'varType' => 3, 'actionable' => false],
                // --- Anzeige: was daraus folgt ---
                ['ident' => 'ActiveLoads', 'type' => ControlContract::T_REFLECT, 'role' => 'energy:activeloads',
                 'label' => 'Verschobene Lasten', 'varType' => 1, 'actionable' => false],
                ['ident' => 'LoadsJson', 'type' => ControlContract::T_REFLECT, 'role' => 'energy:loads',
                 'label' => 'Lasten (JSON)', 'varType' => 3, 'actionable' => false],
                ['ident' => 'LastRun', 'type' => ControlContract::T_REFLECT, 'role' => 'energy:lastrun',
                 'label' => 'Status', 'varType' => 3, 'actionable' => false],
            ],

            'managementActions' => [
                ['op' => 'getConfig',    'label' => 'Konfiguration lesen (Diagnose)'],
                ['op' => 'configureSources', 'label' => 'Quellen binden (Ueberschuss/Preis)'],
                ['op' => 'setRules',     'label' => 'Schwellen setzen (Ueberschuss/Preis/Mindestlauf)'],
                ['op' => 'listLoads',    'label' => 'Verschiebbare Lasten lesen'],
                ['op' => 'setLoads',     'label' => 'Verschiebbare Lasten setzen'],
                ['op' => 'computeProbe', 'label' => 'Was wuerde jetzt geschehen? (Trockenlauf)'],
                ['op' => 'setArmed',     'label' => 'Scharfschalten / Schatten-Modus'],
            ],

            'capabilities' => [
                'armed'   => (bool) $this->cfgVal('armed', false),
                'sources' => $this->quellen(),
            ],
        ];
    }

    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyInteger('Interval', self::DEF_INTERVAL_S);
        $this->RegisterPropertyInteger('SurplusVarId', 0);
        $this->RegisterPropertyInteger('PriceVarId', 0);
        $this->RegisterPropertyInteger('MarketVarId', 0);
        $this->RegisterPropertyBoolean('Armed', false);
    }

    protected function setupTimers(): void
    {
        $this->RegisterTimer(self::TIMER_TICK, 0, 'HSEN_Tick($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $iv = max(60, (int) $this->ReadPropertyInteger('Interval'));
        $this->SetTimerInterval(self::TIMER_TICK, $iv * 1000);
        $this->quellenHorchen();
        $this->syncReferences();
    }

    /**
     * Auf die gebundenen Quellen horchen.
     *
     * Der Ueberschuss aendert sich staendig; auf jede Aenderung sofort zu entscheiden waere
     * Flattern. Deshalb entscheidet der TIMER, und die Anmeldung dient nur der Anzeige -
     * dieselbe Trennung wie in den Geraetemodulen: spiegeln sofort, handeln getaktet.
     */
    private function quellenHorchen(): void
    {
        foreach ((array) @$this->GetMessageList() as $sid => $msgs) {
            if ((int) $sid > 0 && in_array(10603 /* VM_UPDATE */, (array) $msgs, true)) {
                @$this->UnregisterMessage((int) $sid, 10603);
            }
        }
        foreach (['SurplusVarId', 'PriceVarId'] as $prop) {
            $vid = (int) $this->ReadPropertyInteger($prop);
            if ($vid > 0 && @\IPS_VariableExists($vid)) { @$this->RegisterMessage($vid, 10603); }
        }
    }

    public function MessageSink($Timestamp, $Sender, $Message, $Data)
    {
        parent::MessageSink($Timestamp, $Sender, $Message, $Data);
        if ((int) $Message !== 10603) { return; }
        // Nur spiegeln. Entschieden wird im Takt - sonst schaltete jede Wolke eine Last.
        $this->spiegeln();
    }

    protected function applyControl(Control $c, $value, ActionContext $ctx): void
    {
        if ($c->ident === 'Automatic' && !(bool) $value) {
            $this->entscheidungMerken('Lastverschiebung aus', 'Handbedienung', [], true, null, 'Energie');
        }
    }

    protected function driver(): ?\Hoep\HomeSuite\HAL\IDriver { return null; }

    // ==================================================================
    // Konfiguration — dieselben Helfer wie in den uebrigen Domaenen.
    // Sie stehen NICHT in EntityModule, sondern werden je Modul gefuehrt; wer sie von der
    // Basisklasse erwartet, faellt zur Laufzeit hin.
    // ==================================================================

    /** Store-Konfig, ueberlagert von den nativen Properties (diese gewinnen). */
    private function cfg(): array
    {
        $store = $this->store()->get('config', []);
        $store = is_array($store) ? $store : [];
        return array_merge($store, [
            'armed'        => (bool) $this->ReadPropertyBoolean('Armed'),
            'interval'     => (int) $this->ReadPropertyInteger('Interval'),
            'surplusVarId' => (int) $this->ReadPropertyInteger('SurplusVarId'),
            'priceVarId'   => (int) $this->ReadPropertyInteger('PriceVarId'),
            'marketVarId'  => (int) $this->ReadPropertyInteger('MarketVarId'),
        ]);
    }

    private function cfgVal(string $key, $def)
    {
        $c = $this->cfg();
        $v = array_key_exists($key, $c) ? $c[$key] : $def;
        return $key === 'armed' ? $this->armedEffective((bool) $v) : $v;  // Hub-Master hat Vorrang
    }

    private function armed(): bool
    {
        return $this->armedEffective($this->ReadPropertyBoolean('Armed'));
    }

    /**
     * Loeschschutz: die gebundenen Quellen und die Ziel-Instanzen der Lasten als Referenzen
     * anmelden. Ohne das laesst sich eine Variable loeschen, an der die Lastverschiebung
     * haengt - und das faellt erst auf, wenn nichts mehr verschoben wird.
     */
    private function syncReferences(): void
    {
        if (!method_exists($this, 'GetReferenceList')) { return; }
        foreach ($this->GetReferenceList() as $ref) { @$this->UnregisterReference($ref); }
        foreach ($this->quellen() as $vid) {
            if ((int) $vid > 0 && @\IPS_ObjectExists((int) $vid)) { @$this->RegisterReference((int) $vid); }
        }
        foreach ($this->lasten() as $l) {
            if ((int) $l['instanz'] > 0 && @\IPS_ObjectExists((int) $l['instanz'])) {
                @$this->RegisterReference((int) $l['instanz']);
            }
        }
    }

    // ==================================================================
    // Quellen lesen — NICHTS wird hier neu gerechnet
    // ==================================================================

    /** Die gebundenen Quellen als Uebersicht (Diagnose/Manifest). */
    private function quellen(): array
    {
        return [
            'surplusVarId' => (int) $this->ReadPropertyInteger('SurplusVarId'),
            'priceVarId'   => (int) $this->ReadPropertyInteger('PriceVarId'),
            'marketVarId'  => (int) $this->ReadPropertyInteger('MarketVarId'),
        ];
    }

    private function zahlVon(int $vid): ?float
    {
        if ($vid <= 0 || !@\IPS_VariableExists($vid)) { return null; }
        $v = @\GetValue($vid);
        return is_numeric($v) ? (float) $v : null;
    }

    /** Aktueller Ueberschuss in Watt (positiv = mehr Erzeugung als Verbrauch). */
    private function ueberschuss(): ?float { return $this->zahlVon((int) $this->ReadPropertyInteger('SurplusVarId')); }

    /** Aktueller Strompreis, so wie ihn die gebundene Quelle fuehrt. */
    private function preis(): ?float { return $this->zahlVon((int) $this->ReadPropertyInteger('PriceVarId')); }

    /**
     * Preisvorhersage als Liste [{start,end,price}], nach Zeit sortiert.
     *
     * Bewusst tolerant: die Quelle liefert JSON, und ein Feldname kann sich aendern. Was
     * nicht lesbar ist, gilt als "keine Vorhersage" - dann entscheidet nur der Ueberschuss.
     * Eine unlesbare Quelle darf nie zu einer Entscheidung fuehren, die niemand erklaeren
     * kann.
     */
    private function vorhersage(): array
    {
        $vid = (int) $this->ReadPropertyInteger('MarketVarId');
        if ($vid <= 0 || !@\IPS_VariableExists($vid)) { return []; }
        $d = json_decode((string) @\GetValue($vid), true);
        if (!is_array($d)) { return []; }
        $out = [];
        foreach ($d as $e) {
            if (!is_array($e)) { continue; }
            $s = (int) ($e['start'] ?? 0);
            $p = $e['price'] ?? null;
            if ($s <= 0 || !is_numeric($p)) { continue; }
            $out[] = ['start' => $s, 'end' => (int) ($e['end'] ?? ($s + 3600)), 'price' => (float) $p];
        }
        usort($out, static fn($a, $b) => $a['start'] <=> $b['start']);
        return $out;
    }

    /**
     * Wo steht die laufende Stunde im Preisvergleich der naechsten 24 Stunden?
     *
     * Der absolute Preis sagt allein wenig - 43 ct sind viel oder wenig, je nach Tag. Der
     * RANG beantwortet die Frage, die zaehlt: gibt es heute noch guenstigere Stunden?
     *
     * @return array{rang:?int, anzahl:int, jetzt:?float, beste:?array}
     */
    private function preisRang(): array
    {
        $fc = $this->vorhersage();
        $now = time();
        $kommend = array_values(array_filter($fc, static fn($e) => $e['end'] > $now && $e['start'] < $now + 86400));
        if ($kommend === []) { return ['rang' => null, 'anzahl' => 0, 'jetzt' => null, 'beste' => null]; }
        $jetzt = null;
        foreach ($kommend as $e) { if ($e['start'] <= $now && $e['end'] > $now) { $jetzt = $e['price']; break; } }
        $preise = array_column($kommend, 'price');
        sort($preise);
        $rang = null;
        if ($jetzt !== null) {
            foreach ($preise as $i => $p) { if (abs($p - $jetzt) < 0.0001) { $rang = $i + 1; break; } }
        }
        $beste = $kommend[0];
        foreach ($kommend as $e) { if ($e['price'] < $beste['price']) { $beste = $e; } }
        return ['rang' => $rang, 'anzahl' => count($kommend), 'jetzt' => $jetzt, 'beste' => $beste];
    }

    // ==================================================================
    // Regeln und Lasten
    // ==================================================================

    private function regeln(): array
    {
        $c = $this->cfg();
        $r = (isset($c['rules']) && is_array($c['rules'])) ? $c['rules'] : [];
        return [
            'surplusW'   => (float) ($r['surplusW'] ?? self::DEF_SURPLUS_W),
            'priceCt'    => (float) ($r['priceCt'] ?? self::DEF_PRICE_CT),
            'minRunMin'  => max(1, (int) ($r['minRunMin'] ?? self::DEF_MINRUN_MIN)),
        ];
    }

    /**
     * Verschiebbare Lasten aus dem Store.
     *
     * Je Eintrag: id, name, instanz (HomeSuite-Instanz, die geschaltet wird), ident
     * (Bedien-Variable dort), watt (Schaetzung, fuer die Reihenfolge), prio (klein = wichtig),
     * fromHour/toHour (erlaubtes Fenster), enabled.
     */
    private function lasten(): array
    {
        $c = $this->cfg();
        $l = (isset($c['loads']) && is_array($c['loads'])) ? $c['loads'] : [];
        $out = [];
        foreach ($l as $e) {
            if (!is_array($e)) { continue; }
            $out[] = [
                'id'       => (string) ($e['id'] ?? uniqid('l', false)),
                'name'     => (string) ($e['name'] ?? ''),
                'instanz'  => (int) ($e['instanz'] ?? 0),
                'ident'    => (string) ($e['ident'] ?? ''),
                'watt'     => (int) ($e['watt'] ?? 0),
                'prio'     => (int) ($e['prio'] ?? 50),
                'fromHour' => max(0, min(23, (int) ($e['fromHour'] ?? 0))),
                'toHour'   => max(0, min(24, (int) ($e['toHour'] ?? 24))),
                'enabled'  => ($e['enabled'] ?? true) !== false,
            ];
        }
        usort($out, static fn($a, $b) => $a['prio'] <=> $b['prio']);
        return $out;
    }

    /** Laeuft die Last gerade? Gelesen wird die Bedien-Variable der Ziel-Instanz. */
    private function laeuft(array $last): ?bool
    {
        $vid = $this->lastVarId($last);
        if ($vid <= 0) { return null; }
        $v = @\GetValue($vid);
        return is_bool($v) ? $v : (bool) $v;
    }

    /** Bedien-Variable einer Last (ueber die Kinder gesucht, nicht ueber GetObjectIDByIdent). */
    private function lastVarId(array $last): int
    {
        $iid = (int) $last['instanz'];
        $id  = (string) $last['ident'];
        if ($iid <= 0 || $id === '' || !@\IPS_InstanceExists($iid)) { return 0; }
        foreach (@\IPS_GetChildrenIDs($iid) ?: [] as $c) {
            $o = @\IPS_GetObject($c);
            if (($o['ObjectType'] ?? -1) === 2 && ($o['ObjectIdent'] ?? '') === $id) { return (int) $c; }
        }
        return 0;
    }

    // ==================================================================
    // Der Entscheidungskern
    // ==================================================================

    /** Nur Anzeige auffrischen - ohne zu entscheiden. */
    private function spiegeln(): void
    {
        $u = $this->ueberschuss();
        if ($u !== null) { $this->setReflect('Surplus', round($u, 0)); }
        $p = $this->preis();
        if ($p !== null) { $this->setReflect('Price', round($p, 2)); }
        $pr = $this->preisRang();
        if ($pr['rang'] !== null) { $this->setReflect('PriceRank', (int) $pr['rang']); }
        if (is_array($pr['beste'])) {
            $this->setReflect('CheapWindow', date('H:i', $pr['beste']['start']) . '–'
                . date('H:i', $pr['beste']['end']) . '  ' . round($pr['beste']['price'], 1));
        }
    }

    /**
     * Einmal entscheiden. Wird vom Timer gerufen.
     *
     * Reihenfolge mit Absicht: erst spiegeln, dann entscheiden. So zeigt die Kachel die
     * Lage auch dann, wenn die Automatik aus ist oder nichts zu verschieben waere.
     */
    public function Tick(): void
    {
        $this->spiegeln();

        $auto = (bool) @$this->GetValue('Automatic');
        if (!$auto || !$this->automationEnabled()) {
            $this->setReflect('LastRun', $auto ? 'Automatik im Hub aus' : 'Lastverschiebung aus');
            return;
        }

        $e = $this->bewerten();
        $this->setReflect('Cheap', (bool) $e['guenstig']);
        $this->setReflect('ActiveLoads', (int) $e['laufend']);
        $this->setReflect('LastRun', $e['text']);

        foreach ($e['schalten'] as $s) {
            $this->lastSchalten($s['last'], (bool) $s['ein'], (string) $s['grund'], $e);
        }
    }

    /**
     * Die eigentliche Bewertung - OHNE Nebenwirkung, damit computeProbe dasselbe rechnen
     * kann, was der Takt tut. Ein Trockenlauf, der anders rechnet als der Betrieb, ist
     * wertlos.
     */
    private function bewerten(): array
    {
        $rg  = $this->regeln();
        $u   = $this->ueberschuss();
        $p   = $this->preis();
        $pr  = $this->preisRang();
        $std = (int) date('G');

        // "Guenstig" ist zweierlei, und beides zaehlt: genug eigener Strom ODER ein
        // niedriger Marktpreis. Das erste spart Einspeisung, das zweite spart Geld.
        $ueberschussOk = ($u !== null && $u >= $rg['surplusW']);
        $preisOk       = ($rg['priceCt'] > 0 && $p !== null && $p <= $rg['priceCt']);
        $guenstig      = $ueberschussOk || $preisOk;

        $grund = [];
        if ($ueberschussOk) { $grund[] = 'Überschuss ' . round((float) $u) . ' W'; }
        if ($preisOk)       { $grund[] = 'Preis ' . round((float) $p, 1) . ' unter Schwelle'; }
        if ($grund === []) {
            $grund[] = ($u === null) ? 'kein Überschusswert' : ('Überschuss ' . round((float) $u) . ' W zu gering');
        }
        if ($pr['rang'] !== null) { $grund[] = 'Preisrang ' . $pr['rang'] . '/' . $pr['anzahl']; }
        $grundText = implode(', ', $grund);

        $schalten = []; $laufend = 0; $rest = ($u ?? 0.0);
        foreach ($this->lasten() as $l) {
            if (!$l['enabled']) { continue; }
            $an = $this->laeuft($l);
            if ($an === true) { $laufend++; }
            $imFenster = ($std >= $l['fromHour'] && $std < $l['toHour']);
            if (!$imFenster) {
                // Ausserhalb des Fensters wird NICHT abgeschaltet: das Fenster sagt, wann
                // verschoben werden darf, nicht wann etwas verboten ist. Sonst wuerde die
                // Energieregel eine laufende Bewaesserung mitten im Lauf abwuergen.
                continue;
            }
            if ($guenstig && $an === false && ($rest >= $l['watt'] || $preisOk)) {
                $schalten[] = ['last' => $l, 'ein' => true, 'grund' => $grundText];
                $rest -= $l['watt'];
            } elseif (!$guenstig && $an === true) {
                $schalten[] = ['last' => $l, 'ein' => false, 'grund' => $grundText];
            }
        }

        return ['guenstig' => $guenstig, 'laufend' => $laufend, 'schalten' => $schalten,
                'text' => ($guenstig ? 'günstig: ' : 'nicht günstig: ') . $grundText,
                'ueberschuss' => $u, 'preis' => $p, 'rang' => $pr['rang'], 'regeln' => $rg];
    }

    /** Eine Last schalten - oder im Schatten nur vermerken, was geschehen waere. */
    private function lastSchalten(array $l, bool $ein, string $grund, array $e): void
    {
        $vid = $this->lastVarId($l);
        $name = $l['name'] !== '' ? $l['name'] : ((string) @\IPS_GetName((int) $l['instanz']));
        $werte = ['last' => $name, 'watt' => $l['watt']];
        if ($e['ueberschuss'] !== null) { $werte['ueberschuss_w'] = round((float) $e['ueberschuss']); }
        if ($e['preis'] !== null)       { $werte['preis'] = round((float) $e['preis'], 2); }
        if ($e['rang'] !== null)        { $werte['preisrang'] = $e['rang']; }

        if ($vid <= 0) {
            $this->entscheidungMerken($name . ($ein ? ' ein' : ' aus'), 'Ziel nicht gefunden', $werte, true, false, 'Energie');
            return;
        }
        if (!$this->armed()) {
            $this->entscheidungMerken($name . ($ein ? ' ein' : ' aus'), $grund, $werte, false, null, 'Energie');
            return;
        }
        $v = @\IPS_GetVariable($vid);
        $ok = true;
        if ((int) ($v['VariableAction'] ?? 0) > 0 || (int) ($v['VariableCustomAction'] ?? 0) > 0) {
            @\RequestAction($vid, $ein);
        } else {
            $ok = (bool) @\SetValue($vid, $ein);
        }
        $this->entscheidungMerken($name . ($ein ? ' ein' : ' aus'), $grund, $werte, true, $ok, 'Energie');
    }

    // ==================================================================
    // Verwaltung
    // ==================================================================

    protected function mgmt(string $op, array $args, array $ctx): array
    {
        switch ($op) {
            case 'configureSources': {
                foreach (['SurplusVarId' => 'surplusVarId', 'PriceVarId' => 'priceVarId',
                          'MarketVarId' => 'marketVarId'] as $prop => $key) {
                    if (array_key_exists($key, $args)) {
                        @\IPS_SetProperty($this->InstanceID, $prop, (int) $args[$key]);
                    }
                }
                @\IPS_ApplyChanges($this->InstanceID);
                return ['ok' => true, 'sources' => $this->quellen()];
            }
            case 'setRules': {
                $r = $this->regeln();
                foreach (['surplusW', 'priceCt'] as $k) {
                    if (array_key_exists($k, $args)) { $r[$k] = (float) $args[$k]; }
                }
                if (array_key_exists('minRunMin', $args)) { $r['minRunMin'] = max(1, (int) $args['minRunMin']); }
                $this->store()->patch('config', ['rules' => $r]);
                return ['ok' => true, 'rules' => $this->regeln()];
            }
            case 'listLoads':
                return ['ok' => true, 'loads' => $this->lasten()];
            case 'setLoads': {
                $l = is_array($args['loads'] ?? null) ? $args['loads'] : [];
                $this->store()->patch('config', ['loads' => array_values($l)]);
                $this->refreshMirrors();
                return ['ok' => true, 'loads' => $this->lasten()];
            }
            case 'computeProbe': {
                $e = $this->bewerten();
                // Die Schaltliste als Klartext - im Trockenlauf will man lesen, WAS
                // geschehen wuerde, nicht ein Array von Instanz-IDs entziffern.
                $wuerde = [];
                foreach ($e['schalten'] as $s) {
                    $wuerde[] = ($s['last']['name'] !== '' ? $s['last']['name']
                                : (string) @\IPS_GetName((int) $s['last']['instanz']))
                              . ($s['ein'] ? ' EIN' : ' AUS');
                }
                return ['ok' => true, 'armed' => $this->armed(), 'guenstig' => $e['guenstig'],
                        'ueberschuss_w' => $e['ueberschuss'], 'preis' => $e['preis'],
                        'preisrang' => $e['rang'], 'regeln' => $e['regeln'],
                        'laufend' => $e['laufend'], 'wuerde' => $wuerde, 'begruendung' => $e['text'],
                        'lasten' => count($this->lasten()), 'quellen' => $this->quellen()];
            }
            default:
                return parent::mgmt($op, $args, $ctx);
        }
    }

    /** Lasten als lesbare Spiegelvariable im Baum. */
    protected function refreshMirrors(): void
    {
        $this->mirrorVar('LoadsJson', 'Lasten (JSON, Anzeige)', $this->lasten());
    }

    // ==================================================================
    // Scripting-API
    // ==================================================================

    public function Refresh(): void { $this->Tick(); }
    public function GetSurplus(): float { return (float) ($this->ueberschuss() ?? 0.0); }
    public function IsCheap(): bool { return (bool) $this->bewerten()['guenstig']; }
}

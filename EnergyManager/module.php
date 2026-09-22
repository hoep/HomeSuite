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
    private const DEF_SURPLUS_W    = 0.0;   // 0 = Ueberschussregel aus - siehe unten, es gibt hier keinen
    private const DEF_PRICE_CT     = 0.0;   // 0 = feste Preisschwelle aus
    private const DEF_RANKTOP      = 0;     // 0 = Rangregel aus; sonst "unter den N guenstigsten Stunden"
    private const DEF_MAXGRID_W    = 0.0;   // 0 = kein Deckel auf den Zukauf
    private const DEF_MINRUN_MIN   = 30;    // kuerzere Laeufe kosten mehr, als sie bringen
    private const DEF_MINSAVE_CT   = 0.0;   // 0 = Ersparnisregel aus; sonst Mindestertrag je Lauf in ct
    private const DEF_FIXPRICE_CT  = 0.0;   // >0 = Festtarif in ct/kWh; dann gibt es nichts zu verschieben

    /*
     * WARUM hier in Kilowattstunden und Cent gerechnet wird und nicht in Watt.
     *
     * Bezahlt wird ARBEIT, nicht Leistung. Ob 36 oder 52 ct/kWh gelten, sagt fuer sich
     * genommen nichts darueber, ob sich das Verschieben einer Last lohnt - das entscheidet
     * erst, WIE VIELE Kilowattstunden diese Last in dieser Stunde zieht:
     *
     *     Ersparnis [ct] = Leistung [kW] x Laufdauer [h] x Preisunterschied [ct/kWh]
     *
     * Eine 2-kW-Last, zwei Stunden lang, bei 15 ct Unterschied bringt 60 ct. Eine 50-W-Last
     * bringt unter denselben Bedingungen 1,5 ct - das ist die Schaltung nicht wert. Genau
     * diesen Unterschied kann eine Regel auf Watt-Schwellen nicht sehen, und deshalb traegt
     * jede Last hier ihre erwartete Laufdauer, und jede Entscheidung nennt ihren Ertrag.
     *
     * Die Leistungswerte bleiben als ANZEIGE erhalten - sie zeigen die Lage. Entschieden
     * wird nach Geld.
     *
     * UND WORAN SICH ALLES ENTSCHEIDET: am Tarif.
     *
     * Bei einem FESTTARIF kostet jede Kilowattstunde rund um die Uhr gleich viel. Dann gibt
     * es keinen Preisunterschied, keine Ersparnis und folglich nichts zu verschieben - egal
     * wie gut die Marktpreise aussehen, an dieser Rechnung aendern sie nichts. Ist
     * fixedPriceCt gesetzt, bleiben alle Verschieberegeln deshalb WIRKUNGSLOS, und das
     * Modul tut das, was dann noch Wert hat: es rechnet die Lage und die Kosten mit.
     *
     * Die Marktpreisquelle darf trotzdem gebunden bleiben - als Beobachtung. Sie beantwortet
     * die Frage, ob sich ein variabler Tarif ueberhaupt lohnen WUERDE, und an dem Tag, an
     * dem einer abgeschlossen wird, genuegt es, fixedPriceCt auf 0 zu setzen.
     */

    /*
     * WARUM die Ueberschussregel ab Werk AUS ist.
     *
     * Gemessen ueber 14 Tage (50 000 Punkte): die Bilanz aus Erzeugung minus Verbrauch lag
     * bei einem Mittel von -1070 W, im Hoechstfall bei +314 W - in 99 % der Zeit NEGATIV.
     * Diese Anlage verbraucht fast immer mehr, als sie erzeugt; die PV deckt einen Teil,
     * eingespeist wird praktisch nie. Eine Regel "starte ab 800 W Ueberschuss" haette hier
     * niemals ausgeloest.
     *
     * Der Hebel ist deshalb der PREIS: die Spanne der naechsten 24 Stunden betrug zuletzt
     * 36,8 bis 55,7 ct/kWh - ein Drittel Unterschied. Wer eine Last in die guenstigen
     * Stunden legt, spart real, auch ohne eine einzige Wattstunde Ueberschuss.
     */

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
                ['ident' => 'Production', 'type' => ControlContract::T_REFLECT, 'role' => 'energy:production',
                 'label' => 'Erzeugung', 'varType' => 2, 'profile' => '~Watt', 'unit' => ' W', 'actionable' => false],
                ['ident' => 'Consumption', 'type' => ControlContract::T_REFLECT, 'role' => 'energy:consumption',
                 'label' => 'Verbrauch', 'varType' => 2, 'profile' => '~Watt', 'unit' => ' W', 'actionable' => false],
                ['ident' => 'Grid', 'type' => ControlContract::T_REFLECT, 'role' => 'energy:grid',
                 'label' => 'Zukauf', 'varType' => 2, 'profile' => '~Watt', 'unit' => ' W', 'actionable' => false],
                ['ident' => 'ProductionKwh', 'type' => ControlContract::T_REFLECT, 'role' => 'energy:productionkwh',
                 'label' => 'Erzeugung heute', 'varType' => 2, 'profile' => '~Electricity', 'unit' => ' kWh', 'actionable' => false],
                ['ident' => 'GridKwh', 'type' => ControlContract::T_REFLECT, 'role' => 'energy:gridkwh',
                 'label' => 'Zukauf heute', 'varType' => 2, 'profile' => '~Electricity', 'unit' => ' kWh', 'actionable' => false],
                ['ident' => 'GridCostToday', 'type' => ControlContract::T_REFLECT, 'role' => 'energy:gridcost',
                 'label' => 'Zukauf heute (Kosten)', 'varType' => 2, 'unit' => ' EUR', 'actionable' => false],
                ['ident' => 'SavedToday', 'type' => ControlContract::T_REFLECT, 'role' => 'energy:savedtoday',
                 'label' => 'Verschiebung brachte heute', 'varType' => 2, 'unit' => ' ct', 'actionable' => false],
                ['ident' => 'SelfRate', 'type' => ControlContract::T_REFLECT, 'role' => 'energy:selfrate',
                 'label' => 'Eigendeckung', 'varType' => 2, 'profile' => '~Intensity.100', 'unit' => ' %', 'actionable' => false],
                ['ident' => 'Surplus', 'type' => ControlContract::T_REFLECT, 'role' => 'energy:surplus',
                 'label' => 'Bilanz', 'varType' => 2, 'profile' => '~Watt', 'unit' => ' W', 'actionable' => false],
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
                ['op' => 'configureSources', 'label' => 'Quellen binden (Bilanz/Preis)'],
                ['op' => 'setBalance',   'label' => 'Erzeuger / Verbraucher / Zukauf festlegen'],
                ['op' => 'getBalance',   'label' => 'Energiebilanz lesen'],
                ['op' => 'setRules',     'label' => 'Schwellen setzen (Preis/Rang/Mindestertrag)'],
                ['op' => 'listLoads',    'label' => 'Verschiebbare Lasten lesen'],
                ['op' => 'setLoads',     'label' => 'Verschiebbare Lasten setzen'],
                ['op' => 'computeProbe', 'label' => 'Was wuerde jetzt geschehen? (Trockenlauf)'],
                ['op' => 'getSim',       'label' => 'Simulation: Konfiguration lesen'],
                ['op' => 'setSim',       'label' => 'Simulation: Zaehler und Tarife festlegen'],
                ['op' => 'simTarife',    'label' => 'Simulation: Tarife vergleichen'],
                ['op' => 'simVerschiebung', 'label' => 'Simulation: Was braechte Lastverschiebung?'],
                ['op' => 'setArmed',     'label' => 'Scharfschalten / Schatten-Modus'],
            ],

            'capabilities' => [
                'armed'   => (bool) $this->cfgVal('armed', false),
                'sources' => $this->quellen(),
                'balance' => $this->bilanz(),
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
        $ids = $this->bilanzVarIds();
        foreach (['SurplusVarId', 'PriceVarId'] as $prop) { $ids[] = (int) $this->ReadPropertyInteger($prop); }
        foreach (array_unique($ids) as $vid) {
            $vid = (int) $vid;
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
        foreach ($this->bilanzVarIds() as $vid) {
            if ($vid > 0 && @\IPS_ObjectExists($vid)) { @$this->RegisterReference($vid); }
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

    /**
     * Die konfigurierte Zuordnung: wer erzeugt, wer verbraucht, wo wird zugekauft.
     *
     * Bewusst LISTEN und nicht je eine Variable: hier speisen zwei Wechselrichter (PV1, PV2)
     * in zwei Ebenen ein, und ein Haus kann morgen einen dritten bekommen. Was gezaehlt
     * wird, gehoert in die Konfiguration, nicht in den Code.
     */
    private function bilanzCfg(): array
    {
        $c = $this->cfg();
        $b = (isset($c['balance']) && is_array($c['balance'])) ? $c['balance'] : [];
        $liste = static function ($roh): array {
            $out = [];
            foreach ((array) $roh as $e) {
                if (is_array($e)) { $vid = (int) ($e['vid'] ?? 0); $name = (string) ($e['name'] ?? ''); }
                else              { $vid = (int) $e;                $name = ''; }
                if ($vid <= 0) { continue; }
                if ($name === '') { $name = (string) @\IPS_GetName($vid); }
                $out[] = ['vid' => $vid, 'name' => $name];
            }
            return $out;
        };
        return [
            'erzeuger'    => $liste($b['erzeuger'] ?? []),
            'verbraucher' => $liste($b['verbraucher'] ?? []),
            'zukaufVid'   => (int) ($b['zukaufVid'] ?? 0),
            // Tageszaehler in kWh - das ist die Groesse, die auf der Rechnung steht.
            'erzeugungKwhVid' => (int) ($b['erzeugungKwhVid'] ?? 0),
            'zukaufKwhVid'    => (int) ($b['zukaufKwhVid'] ?? 0),
        ];
    }

    /** Alle Variablen der Bilanz - fuer den Loeschschutz und die Anmeldung. */
    private function bilanzVarIds(): array
    {
        $b = $this->bilanzCfg();
        $ids = array_merge(array_column($b['erzeuger'], 'vid'), array_column($b['verbraucher'], 'vid'));
        foreach (['zukaufVid', 'erzeugungKwhVid', 'zukaufKwhVid'] as $k) {
            if ($b[$k] > 0) { $ids[] = $b[$k]; }
        }
        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * Die Lage in Watt: Erzeugung, Verbrauch, Zukauf, Bilanz, Eigendeckung.
     *
     * Zwei Wege, und der erste gewinnt:
     *  1. aus den konfigurierten Listen - dann sind alle vier Zahlen belegt und erklaerbar;
     *  2. ersatzweise aus der gebundenen Bilanz-Variablen (frueher "Ueberschuss") - dann
     *     kennt das Modul nur die Differenz, nicht ihre Bestandteile.
     *
     * Ist der Zukauf eigens gemessen, hat die MESSUNG Vorrang vor der Rechnung: ein Zaehler
     * weiss mehr als eine Differenz zweier Schaetzungen.
     */
    private function bilanz(): array
    {
        $b = $this->bilanzCfg();
        $summe = function (array $liste): ?float {
            $s = null;
            foreach ($liste as $e) {
                $v = $this->zahlVon((int) $e['vid']);
                if ($v === null) { continue; }
                $s = ($s ?? 0.0) + $v;
            }
            return $s;
        };
        $prod = $summe($b['erzeuger']);
        $verb = $summe($b['verbraucher']);
        $quelle = 'listen';

        $bil = ($prod !== null && $verb !== null) ? ($prod - $verb) : null;
        if ($bil === null) {
            $bil = $this->zahlVon((int) $this->ReadPropertyInteger('SurplusVarId'));
            $quelle = ($bil === null) ? '-' : 'bilanzvariable';
        }

        $zukauf = $this->zahlVon($b['zukaufVid']);
        if ($zukauf === null && $bil !== null) { $zukauf = max(0.0, -$bil); }

        $deckung = ($verb !== null && $verb > 0 && $prod !== null)
            ? min(100.0, 100.0 * $prod / $verb) : null;

        return ['produktion' => $prod, 'verbrauch' => $verb, 'zukauf' => $zukauf,
                'bilanz' => $bil, 'deckung' => $deckung, 'quelle' => $quelle,
                'erzeugungKwh' => $this->zahlVon($b['erzeugungKwhVid']),
                'zukaufKwh'    => $this->zahlVon($b['zukaufKwhVid']),
                'erzeuger' => $b['erzeuger'], 'verbraucher' => $b['verbraucher'],
                'zukaufVid' => $b['zukaufVid']];
    }

    private function zahlVon(int $vid): ?float
    {
        if ($vid <= 0 || !@\IPS_VariableExists($vid)) { return null; }
        $v = @\GetValue($vid);
        return is_numeric($v) ? (float) $v : null;
    }

    /** Bilanz in Watt (positiv = mehr Erzeugung als Verbrauch). Hier praktisch immer negativ. */
    private function ueberschuss(): ?float { return $this->bilanz()['bilanz']; }

    /**
     * Der Preis, der fuer DIESEN Haushalt gilt, in ct/kWh.
     *
     * Ein gesetzter Festtarif gewinnt gegen jede Marktquelle: bezahlt wird, was im Vertrag
     * steht, nicht was die Boerse meldet.
     */
    private function preis(): ?float
    {
        $fest = (float) $this->regeln()['fixedPriceCt'];
        if ($fest > 0) { return $fest; }
        return $this->zahlVon((int) $this->ReadPropertyInteger('PriceVarId'));
    }

    /** Der Marktpreis - reine Beobachtung, auch wenn ein Festtarif gilt. */
    private function marktpreis(): ?float { return $this->zahlVon((int) $this->ReadPropertyInteger('PriceVarId')); }

    /** Gilt ein Festtarif? Dann ist Verschieben sinnlos. */
    private function festtarif(): bool { return (float) $this->regeln()['fixedPriceCt'] > 0; }

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
            'rankTop'    => max(0, (int) ($r['rankTop'] ?? self::DEF_RANKTOP)),
            'maxGridW'   => (float) ($r['maxGridW'] ?? self::DEF_MAXGRID_W),
            'minRunMin'  => max(1, (int) ($r['minRunMin'] ?? self::DEF_MINRUN_MIN)),
            'minSaveCt'  => (float) ($r['minSaveCt'] ?? self::DEF_MINSAVE_CT),
            'fixedPriceCt' => (float) ($r['fixedPriceCt'] ?? self::DEF_FIXPRICE_CT),
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
                // Erwartete Laufdauer in Minuten. Ohne sie laesst sich kein Ertrag rechnen;
                // fehlt sie, gilt der Mindestlauf als vorsichtige Untergrenze.
                'runMin'   => max(1, (int) ($e['runMin'] ?? 0)),
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
        $b = $this->bilanz();
        if ($b['produktion'] !== null) { $this->setReflect('Production',  round($b['produktion'], 0)); }
        if ($b['verbrauch']  !== null) { $this->setReflect('Consumption', round($b['verbrauch'], 0)); }
        if ($b['zukauf']     !== null) { $this->setReflect('Grid',        round($b['zukauf'], 0)); }
        if ($b['deckung']    !== null) { $this->setReflect('SelfRate',    round($b['deckung'], 1)); }
        if ($b['bilanz']     !== null) { $this->setReflect('Surplus',     round($b['bilanz'], 0)); }
        if ($b['erzeugungKwh'] !== null) { $this->setReflect('ProductionKwh', round($b['erzeugungKwh'], 2)); }
        if ($b['zukaufKwh']    !== null) { $this->setReflect('GridKwh',       round($b['zukaufKwh'], 2)); }
        /*
         * Was der heutige Zukauf kostet - die Zahl, die am Monatsende zaehlt.
         *
         * NUR mit gesetztem Festtarif. Den Marktpreis hier einzusetzen waere die
         * gefaehrlichste Art von Fehler: eine praezise aussehende Zahl, die niemandes
         * Rechnung beschreibt. Ohne hinterlegten Tarif bleibt die Anzeige leer.
         */
        $fix = (float) $this->regeln()['fixedPriceCt'];
        $this->setReflect('GridCostToday',
            ($b['zukaufKwh'] !== null && $fix > 0) ? round($b['zukaufKwh'] * $fix / 100.0, 2) : 0.0);
        $p = $this->preis();
        if ($p !== null) { $this->setReflect('Price', round($p, 2)); }
        $pr = $this->preisRang();
        if ($pr['rang'] !== null) { $this->setReflect('PriceRank', (int) $pr['rang']); }
        if (is_array($pr['beste'])) {
            // Der Tag MUSS dazu, sobald das Fenster nicht heute liegt: die Vorhersage reicht
            // 24 Stunden, und "14:00-15:00" sah um 17:20 Uhr aus wie heute Nachmittag -
            // gemeint war der naechste Tag. Eine Uhrzeit ohne Tag ist hier eine Falle.
            $b = $pr['beste'];
            $heute = date('Y-m-d') === date('Y-m-d', $b['start']);
            $this->setReflect('CheapWindow',
                ($heute ? '' : date('D ', $b['start']))
                . date('H:i', $b['start']) . '–' . date('H:i', $b['end'])
                . '  ' . round($b['price'], 1));
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
        $b   = $this->bilanz();
        $u   = $b['bilanz'];
        $p   = $this->preis();
        $pr  = $this->preisRang();
        $std = (int) date('G');

        /*
         * Drei Wege, guenstig zu sein - jeder einzeln abschaltbar (Schwelle 0 = aus):
         *
         *  - PREISSCHWELLE: der Preis liegt unter einem festen Wert in ct/kWh. Klar, aber
         *    starr: an einem teuren Tag loest sie nie aus, an einem billigen dauernd.
         *  - PREISRANG: die laufende Stunde gehoert zu den N guenstigsten der naechsten 24.
         *    Das ist die verlaessliche Regel, weil sie relativ misst - sie findet jeden Tag
         *    seine billigen Stunden, egal auf welchem Niveau er liegt.
         *  - UEBERSCHUSS: die Bilanz ist positiv genug. Hier ab Werk aus; siehe die
         *    Begruendung bei den Vorgaben oben.
         *
         * Ist keine Regel eingeschaltet, geschieht NICHTS. Ein Energiemanager, der ohne
         * gesetzte Schwelle munter schaltet, waere nicht vorsichtig, sondern unberechenbar.
         */
        $fest = $this->festtarif();

        // Bei Festtarif kosten alle Stunden gleich viel. Preisschwelle und Preisrang sind
        // dann keine Regeln mehr, sondern Selbstbetrug - sie werden gar nicht erst geprueft.
        $preisOk = (!$fest && $rg['priceCt'] > 0 && $p !== null && $p <= $rg['priceCt']);
        $rangOk  = (!$fest && $rg['rankTop'] > 0 && $pr['rang'] !== null && $pr['rang'] <= $rg['rankTop']);
        $ueberschussOk = ($rg['surplusW'] > 0 && $u !== null && $u >= $rg['surplusW']);
        $regelAn = ((!$fest && ($rg['priceCt'] > 0 || $rg['rankTop'] > 0)) || $rg['surplusW'] > 0);
        $guenstig = $regelAn && ($preisOk || $rangOk || $ueberschussOk);

        $grund = [];
        if ($fest && $rg['surplusW'] <= 0) {
            $grund[] = 'Festtarif ' . round($rg['fixedPriceCt'], 1) . ' ct/kWh - Verschieben bringt nichts';
        }
        if ($preisOk)       { $grund[] = 'Preis ' . round((float) $p, 1) . ' ct unter Schwelle ' . round($rg['priceCt'], 1); }
        if ($rangOk)        { $grund[] = 'Preisrang ' . $pr['rang'] . '/' . $pr['anzahl'] . ' (Top ' . $rg['rankTop'] . ')'; }
        if ($ueberschussOk) { $grund[] = 'Überschuss ' . round((float) $u) . ' W'; }
        if ($grund === []) {
            if ($fest && $rg['surplusW'] <= 0) {
                // Begruendung steht schon oben.
            } elseif (!$regelAn) {
                $grund[] = 'keine Regel eingeschaltet';
            } else {
                if ($rg['priceCt'] > 0) {
                    $grund[] = ($p === null) ? 'kein Preiswert'
                        : ('Preis ' . round((float) $p, 1) . ' ct über Schwelle ' . round($rg['priceCt'], 1));
                }
                if ($rg['rankTop'] > 0) {
                    $grund[] = ($pr['rang'] === null) ? 'keine Preisvorhersage'
                        : ('Preisrang ' . $pr['rang'] . '/' . $pr['anzahl'] . ' schlechter als Top ' . $rg['rankTop']);
                }
                if ($rg['surplusW'] > 0) {
                    $grund[] = ($u === null) ? 'keine Bilanz'
                        : ('Bilanz ' . round((float) $u) . ' W unter ' . round($rg['surplusW']) . ' W');
                }
            }
        }
        $grundText = implode(', ', $grund);

        /*
         * Der Zukauf-Deckel begrenzt die SUMME, nicht den einzelnen Verbraucher: was schon
         * aus dem Netz kommt, plus was in diesem Durchgang dazukaeme, muss darunter bleiben.
         * Dadurch startet in einer billigen Stunde nicht alles gleichzeitig - die wichtigste
         * Last zuerst (prio), der Rest wartet auf den naechsten Takt.
         */
        $zukauf = $b['zukauf'];
        $dazu = 0.0;

        $schalten = []; $laufend = 0;
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
            if ($guenstig && $an === false) {
                if ($rg['maxGridW'] > 0 && $zukauf !== null
                    && ($zukauf + $dazu + $l['watt']) > $rg['maxGridW']) {
                    continue;   // Deckel erreicht - diese Last wartet
                }
                // Lohnt sich dieser Lauf ueberhaupt? Gerechnet wird in Arbeit und Geld.
                $ertrag = $this->ertrag($l, $p, $pr);
                if ($rg['minSaveCt'] > 0 && $ertrag !== null && $ertrag < $rg['minSaveCt']) {
                    continue;   // zu wenige Kilowattstunden, als dass es sich lohnte
                }
                $g = $grundText;
                if ($ertrag !== null) { $g .= ', Ertrag ' . round($ertrag, 1) . ' ct'; }
                $schalten[] = ['last' => $l, 'ein' => true, 'grund' => $g, 'ertrag' => $ertrag];
                $dazu += (float) $l['watt'];
            } elseif (!$guenstig && $an === true) {
                $schalten[] = ['last' => $l, 'ein' => false, 'grund' => $grundText];
            }
        }

        return ['guenstig' => $guenstig, 'laufend' => $laufend, 'schalten' => $schalten,
                'festtarif' => $fest,
                'text' => ($guenstig ? 'günstig: ' : 'nicht günstig: ') . $grundText,
                'ueberschuss' => $u, 'bilanz' => $b, 'preis' => $p, 'rang' => $pr['rang'],
                'regeln' => $rg];
    }

    /**
     * Was bringt es, diese Last JETZT laufen zu lassen statt zum Mittelwert des Tages?
     *
     *     Ertrag [ct] = Leistung [kW] x Laufdauer [h] x (Vergleichspreis - Jetztpreis)
     *
     * Vergleichsmass ist der MEDIAN der naechsten 24 Stunden, nicht der teuerste Wert: der
     * Median beschreibt, was ein Lauf zu beliebiger Zeit im Mittel kosten wuerde, und genau
     * dagegen wird verschoben. Gegen den Hoechstpreis zu rechnen wuerde jede Verschiebung
     * schoenrechnen.
     *
     * Ohne Preisvorhersage gibt es kein Vergleichsmass - dann null statt einer erfundenen
     * Zahl.
     */
    private function ertrag(array $l, ?float $jetzt, array $pr): ?float
    {
        if ($jetzt === null || $this->festtarif()) { return null; }
        $fc = $this->vorhersage();
        $now = time();
        $preise = [];
        foreach ($fc as $e) {
            if ($e['end'] > $now && $e['start'] < $now + 86400) { $preise[] = $e['price']; }
        }
        if (count($preise) < 4) { return null; }
        sort($preise);
        $median = $preise[intdiv(count($preise), 2)];
        $dauer = max(1, (int) ($l['runMin'] > 0 ? $l['runMin'] : $this->regeln()['minRunMin']));
        $kwh = ((float) $l['watt'] / 1000.0) * ($dauer / 60.0);
        return $kwh * ($median - $jetzt);
    }

    /** Eine Last schalten - oder im Schatten nur vermerken, was geschehen waere. */
    private function lastSchalten(array $l, bool $ein, string $grund, array $e): void
    {
        $vid = $this->lastVarId($l);
        $name = $l['name'] !== '' ? $l['name'] : ((string) @\IPS_GetName((int) $l['instanz']));
        $werte = ['last' => $name, 'watt' => $l['watt']];
        $dauer = (int) ($l['runMin'] > 0 ? $l['runMin'] : $this->regeln()['minRunMin']);
        $werte['kwh'] = round(((float) $l['watt'] / 1000.0) * ($dauer / 60.0), 3);
        if (($e['bilanz']['zukauf'] ?? null) !== null) { $werte['zukauf_w'] = round((float) $e['bilanz']['zukauf']); }
        if ($e['ueberschuss'] !== null) { $werte['bilanz_w'] = round((float) $e['ueberschuss']); }
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
            case 'setBalance': {
                $b = $this->bilanzCfg();
                foreach (['erzeuger', 'verbraucher'] as $k) {
                    if (!array_key_exists($k, $args)) { continue; }
                    $neu = [];
                    foreach ((array) $args[$k] as $e) {
                        $vid = is_array($e) ? (int) ($e['vid'] ?? 0) : (int) $e;
                        if ($vid <= 0 || !@\IPS_VariableExists($vid)) { continue; }
                        $neu[] = ['vid' => $vid,
                                  'name' => (string) (is_array($e) ? ($e['name'] ?? '') : '')];
                    }
                    $b[$k] = $neu;
                }
                foreach (['zukaufVid', 'erzeugungKwhVid', 'zukaufKwhVid'] as $k) {
                    if (array_key_exists($k, $args)) { $b[$k] = (int) $args[$k]; }
                }
                $this->store()->patch('config', ['balance' => $b]);
                @\IPS_ApplyChanges($this->InstanceID);   // Anmeldung und Loeschschutz nachziehen
                return ['ok' => true, 'balance' => $this->bilanz()];
            }
            case 'getBalance':
                return ['ok' => true, 'balance' => $this->bilanz(), 'rules' => $this->regeln()];
            case 'setRules': {
                $r = $this->regeln();
                foreach (['surplusW', 'priceCt', 'maxGridW', 'minSaveCt', 'fixedPriceCt'] as $k) {
                    if (array_key_exists($k, $args)) { $r[$k] = (float) $args[$k]; }
                }
                if (array_key_exists('rankTop', $args))   { $r['rankTop']   = max(0, (int) $args['rankTop']); }
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
            case 'getSim':
                return ['ok' => true, 'sim' => $this->simCfg()];
            case 'setSim': {
                $cur = $this->simCfg();
                foreach (['zaehler', 'tarife', 'zonen'] as $k) {
                    if (isset($args[$k]) && is_array($args[$k])) { $cur[$k] = array_values($args[$k]); }
                }
                if (isset($args['tage']))     { $cur['tage'] = max(7, (int) $args['tage']); }
                if (isset($args['istTarif'])) { $cur['istTarif'] = (string) $args['istTarif']; }
                $this->store()->patch('config', ['sim' => $cur]);
                @\IPS_ApplyChanges($this->InstanceID);
                return ['ok' => true, 'sim' => $this->simCfg()];
            }
            case 'simTarife':
                return $this->mgmtSimTarife($args);
            case 'simVerschiebung':
                return $this->mgmtSimVerschiebung($args);

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
                $b = $e['bilanz'];
                return ['ok' => true, 'armed' => $this->armed(), 'guenstig' => $e['guenstig'],
                        'erzeugung_w' => $b['produktion'], 'verbrauch_w' => $b['verbrauch'],
                        'zukauf_w' => $b['zukauf'], 'eigendeckung_pct' => $b['deckung'],
                        'erzeugung_kwh_heute' => $b['erzeugungKwh'], 'zukauf_kwh_heute' => $b['zukaufKwh'],
                        'festtarif' => $e['festtarif'], 'marktpreis' => $this->marktpreis(),
                        'bilanzquelle' => $b['quelle'],
                        'ueberschuss_w' => $e['ueberschuss'], 'preis' => $e['preis'],
                        'preisrang' => $e['rang'], 'regeln' => $e['regeln'],
                        'laufend' => $e['laufend'], 'wuerde' => $wuerde, 'begruendung' => $e['text'],
                        'lasten' => count($this->lasten()), 'quellen' => $this->quellen()];
            }
            default:
                return parent::mgmt($op, $args, $ctx);
        }
    }

    // ==================================================================
    // Simulation
    //
    // Sie beantwortet zwei Fragen, und beide gehoeren zusammen:
    //   1. Welcher Tarif waere fuer DIESEN Haushalt der guenstigste?
    //   2. Was braechte es, Verbrauch zeitlich zu verschieben?
    //
    // Gerechnet wird auf den ECHTEN Stundenwerten der Netzzaehler, nicht auf einem
    // Musterhaushalt. Der Unterschied ist erheblich: Vergleichsportale rechnen mit 3.100
    // kWh, hier sind es ueber 13.000 auf zwei Zaehlpunkten - und der Grundpreis faellt
    // zweimal an.
    //
    // Die Rechnung selbst steht in EnergySim und kennt kein Symcon. Hier wird nur gelesen
    // und uebergeben.
    // ==================================================================

    /** Vorgabetarife: gemessen aus den Preisblaettern, Stand 22.09.2026, brutto. */
    private const SIM_TARIFE = [
        ['id' => 'loyal',    'name' => 'Energie AG Ökostrom Loyal',   'ct' => 14.90, 'grundEur' => 4.62],
        ['id' => 'feelgood', 'name' => 'Energie AG Feel Good',        'ct' => 12.00, 'grundEur' => 5.28],
        ['id' => 'komfort',  'name' => 'Energie AG Ökostrom Komfort', 'ct' => 19.46, 'grundEur' => 4.62],
        ['id' => 'direkt',   'name' => 'Energie AG Ökostrom Direkt',  'ct' => 19.56, 'grundEur' => 7.26],
        ['id' => 'voltino',  'name' => 'Voltino Fix 26 (Neukunde)',   'ct' => 14.11, 'grundEur' => 4.34],
        ['id' => 'voltino2', 'name' => 'Voltino Fix 26 (danach)',     'ct' => 19.08, 'grundEur' => 5.88],
        ['id' => 'smart',    'name' => 'Energie AG Ökostrom Smart',   'grundEur' => 5.18,
         'zonen' => ['Sun' => 5.00, 'Day' => 17.04, 'Night' => 13.22, 'Weekend' => 13.05]],
    ];

    private function simCfg(): array
    {
        $c = $this->cfg();
        $s = (isset($c['sim']) && is_array($c['sim'])) ? $c['sim'] : [];
        $z = [];
        foreach ((array) ($s['zaehler'] ?? []) as $e) {
            $vid = (int) ($e['vid'] ?? 0);
            if ($vid > 0) {
                $z[] = ['vid' => $vid, 'name' => (string) ($e['name'] ?? \IPS_GetName($vid))];
            }
        }
        return [
            'zaehler'   => $z,
            'tarife'    => (isset($s['tarife']) && is_array($s['tarife']) && $s['tarife'] !== [])
                           ? $s['tarife'] : self::SIM_TARIFE,
            'zonen'     => (isset($s['zonen']) && is_array($s['zonen']) && $s['zonen'] !== [])
                           ? $s['zonen'] : \Hoep\HomeSuite\Engines\EnergySim::ZONEN_SMART,
            'tage'      => max(7, (int) ($s['tage'] ?? 365)),
            'istTarif'  => (string) ($s['istTarif'] ?? ''),
        ];
    }

    /** Die Archiv-Instanz, ohne sie fest zu verdrahten. */
    private function archivId(): int
    {
        $l = @\IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}');
        return is_array($l) && $l ? (int) $l[0] : 0;
    }

    /**
     * Stundenreihe eines Leistungszaehlers als Kilowattstunden.
     *
     * Gelesen werden STUNDENMITTEL in Watt; ein Stundenmittel mal eine Stunde ergibt
     * Wattstunden. Negative Werte (Einspeisung) zaehlen als null - dieser Zaehler misst
     * den Bezug, und ein Anbieter verrechnet nichts Negatives.
     *
     * @return array<int,array{ts:int,kwh:float}>
     */
    private function stundenreihe(int $vid, int $tage): array
    {
        $ac = $this->archivId();
        if ($ac <= 0 || $vid <= 0 || !@\AC_GetLoggingStatus($ac, $vid)) {
            return [];
        }
        $a = @\AC_GetAggregatedValues($ac, $vid, 0, time() - $tage * 86400, time(), 0);
        if (!is_array($a)) {
            return [];
        }
        $out = [];
        foreach ($a as $h) {
            $w = (float) ($h['Avg'] ?? 0);
            $out[] = ['ts' => (int) ($h['TimeStamp'] ?? 0), 'kwh' => $w > 0 ? $w / 1000.0 : 0.0];
        }
        return $out;
    }

    /**
     * Tarifvergleich je Zaehlpunkt und in Summe.
     *
     * Der Vergleich umfasst NUR die Energie. Das Netzentgelt ist im Netzgebiet fuer alle
     * Anbieter gleich; es mitzurechnen wuerde die Unterschiede kleiner erscheinen lassen,
     * als sie sind.
     */
    private function mgmtSimTarife(array $args): array
    {
        $cfg  = $this->simCfg();
        $tage = max(7, (int) ($args['tage'] ?? $cfg['tage']));
        if ($cfg['zaehler'] === []) {
            return ['ok' => false, 'error' => 'keine Zaehler konfiguriert (setSim)'];
        }
        $E = \Hoep\HomeSuite\Engines\EnergySim::class;
        $gesamtZonen = []; $proZaehler = []; $faktor = 1.0;
        foreach ($cfg['zaehler'] as $z) {
            $reihe = $this->stundenreihe((int) $z['vid'], $tage);
            if ($reihe === []) {
                $proZaehler[] = ['zaehler' => $z['name'], 'fehler' => 'keine Archivdaten'];
                continue;
            }
            $auf = $E::zonen($reihe, $cfg['zonen']);
            // Deckt die Reihe weniger als ein Jahr, wird hochgerechnet - und gesagt, dass.
            $faktor = $auf['stunden'] > 0 ? (8760.0 / $auf['stunden']) : 1.0;
            $proZaehler[] = [
                'zaehler'      => $z['name'],
                'kwh'          => round($auf['gesamt'], 0),
                'kwh_jahr'     => round($auf['gesamt'] * $faktor, 0),
                'stunden'      => $auf['stunden'],
                'zonen'        => array_map(static fn($v) => round($v, 0), $auf['zonen']),
                'tarife'       => $E::vergleich($auf['zonen'], $cfg['tarife'], $faktor, $cfg['istTarif']),
            ];
            foreach ($auf['zonen'] as $n => $v) {
                $gesamtZonen[$n] = ($gesamtZonen[$n] ?? 0.0) + $v;
            }
        }
        return ['ok' => true,
                'zeitraum_tage'  => $tage,
                'hochgerechnet'  => round($faktor, 3),
                'je_zaehlpunkt'  => $proZaehler,
                'gesamt' => [
                    'kwh_jahr' => round(array_sum($gesamtZonen) * $faktor, 0),
                    'zonen'    => array_map(static fn($v) => round($v * $faktor, 0), $gesamtZonen),
                    'tarife'   => $E::vergleich($gesamtZonen, $this->simTarifeDoppelt($cfg), $faktor, $cfg['istTarif']),
                ],
                'hinweis' => 'Nur Energiekosten. Netzentgelt und Abgaben sind anbieterunabhaengig.'];
    }

    /**
     * Fuer die Gesamtsicht faellt der Grundpreis je ZAEHLPUNKT an, nicht je Haushalt.
     *
     * Das zu uebersehen ist der haeufigste Fehler beim Vergleich mit zwei Vertraegen - und
     * er verzerrt gerade bei einem kleinen zweiten Zaehler erheblich.
     */
    private function simTarifeDoppelt(array $cfg): array
    {
        $n = max(1, count($cfg['zaehler']));
        $out = [];
        foreach ($cfg['tarife'] as $t) {
            $t['grundEur'] = ((float) ($t['grundEur'] ?? 0)) * $n;
            $out[] = $t;
        }
        return $out;
    }

    /** Was braechte es, Verbrauch aus einer Zone in eine andere zu verschieben? */
    private function mgmtSimVerschiebung(array $args): array
    {
        $cfg  = $this->simCfg();
        $tage = max(7, (int) ($args['tage'] ?? $cfg['tage']));
        $kwh  = (float) ($args['kwh'] ?? 500);
        $von  = (string) ($args['von'] ?? 'Day');
        $nach = (string) ($args['nach'] ?? 'Sun');
        $E = \Hoep\HomeSuite\Engines\EnergySim::class;
        $zonen = []; $faktor = 1.0;
        foreach ($cfg['zaehler'] as $z) {
            $reihe = $this->stundenreihe((int) $z['vid'], $tage);
            if ($reihe === []) { continue; }
            $auf = $E::zonen($reihe, $cfg['zonen']);
            $faktor = $auf['stunden'] > 0 ? (8760.0 / $auf['stunden']) : 1.0;
            foreach ($auf['zonen'] as $n => $v) { $zonen[$n] = ($zonen[$n] ?? 0.0) + $v; }
        }
        if ($zonen === []) { return ['ok' => false, 'error' => 'keine Archivdaten']; }
        $out = [];
        foreach ($this->simTarifeDoppelt($cfg) as $t) {
            $out[] = $E::verschiebung($zonen, $t, $von, $nach, $kwh, $faktor);
        }
        usort($out, static fn($a, $b) => $b['ersparnis'] <=> $a['ersparnis']);
        return ['ok' => true, 'von' => $von, 'nach' => $nach, 'kwh' => $kwh,
                'ergebnis' => $out,
                'hinweis' => 'Bei einem Festtarif ist die Ersparnis null - dann ist Verschieben wirkungslos.'];
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
    /** Aktueller Netzbezug in Watt - die Zahl, die diese Anlage wirklich beschreibt. */
    public function GetGrid(): float { return (float) ($this->bilanz()['zukauf'] ?? 0.0); }
    /** Anteil des Verbrauchs, den die eigene Erzeugung gerade deckt (Prozent). */
    public function GetSelfRate(): float { return (float) ($this->bilanz()['deckung'] ?? 0.0); }
    public function IsCheap(): bool { return (bool) $this->bewerten()['guenstig']; }
}

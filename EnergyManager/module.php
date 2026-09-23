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
                // --- Simulation: was die Seite "Strompreis" anzeigt ---
                ['ident' => 'SimCostToday', 'type' => ControlContract::T_REFLECT, 'role' => 'energy:simtoday',
                 'label' => 'Jahreskosten heute', 'varType' => 2, 'unit' => ' EUR', 'actionable' => false],
                ['ident' => 'SimCostBest', 'type' => ControlContract::T_REFLECT, 'role' => 'energy:simbest',
                 'label' => 'Bester Tarif', 'varType' => 2, 'unit' => ' EUR', 'actionable' => false],
                ['ident' => 'SimBestName', 'type' => ControlContract::T_REFLECT, 'role' => 'energy:simbestname',
                 'label' => 'Bester Tarif (Name)', 'varType' => 3, 'actionable' => false],
                ['ident' => 'SimSaving', 'type' => ControlContract::T_REFLECT, 'role' => 'energy:simsaving',
                 'label' => 'Möglich pro Jahr', 'varType' => 2, 'unit' => ' EUR', 'actionable' => false],
                ['ident' => 'SimKwh', 'type' => ControlContract::T_REFLECT, 'role' => 'energy:simkwh',
                 'label' => 'Jahresbezug', 'varType' => 2, 'profile' => '~Electricity', 'unit' => ' kWh', 'actionable' => false],
                ['ident' => 'SimTable', 'type' => ControlContract::T_REFLECT, 'role' => 'energy:simtable',
                 'label' => 'Tarifvergleich (Tabelle)', 'varType' => 3, 'actionable' => false],
                ['ident' => 'SimZones', 'type' => ControlContract::T_REFLECT, 'role' => 'energy:simzones',
                 'label' => 'Zeitzonen (Tabelle)', 'varType' => 3, 'actionable' => false],
                // Die Kennzahlen der Wertkarten brauchen BEIDE Enden der Skala, nicht nur
                // das eigene: eine Zahl ohne Bereich sagt nicht, ob sie gut ist.
                ['ident' => 'SimCostWorst', 'type' => ControlContract::T_REFLECT, 'role' => 'energy:simworst',
                 'label' => 'Teuerster Tarif', 'varType' => 2, 'unit' => ' EUR', 'actionable' => false],
                ['ident' => 'SimPriceBest', 'type' => ControlContract::T_REFLECT, 'role' => 'energy:simpricebest',
                 'label' => 'Bester Preis', 'varType' => 2, 'unit' => ' ct', 'actionable' => false],
                ['ident' => 'SimPriceWorst', 'type' => ControlContract::T_REFLECT, 'role' => 'energy:simpriceworst',
                 'label' => 'Teuerster Preis', 'varType' => 2, 'unit' => ' ct', 'actionable' => false],
                ['ident' => 'SimKwhPrev', 'type' => ControlContract::T_REFLECT, 'role' => 'energy:simkwhprev',
                 'label' => 'Jahresbezug davor', 'varType' => 2, 'unit' => ' kWh', 'actionable' => false],
                ['ident' => 'SimDayPct', 'type' => ControlContract::T_REFLECT, 'role' => 'energy:simdaypct',
                 'label' => 'Anteil Tageszone', 'varType' => 2, 'unit' => ' %', 'actionable' => false],
                ['ident' => 'SimSavingPct', 'type' => ControlContract::T_REFLECT, 'role' => 'energy:simsavingpct',
                 'label' => 'Ersparnis als Anteil', 'varType' => 2, 'unit' => ' %', 'actionable' => false],
                ['ident' => 'SimRankText', 'type' => ControlContract::T_REFLECT, 'role' => 'energy:simrank',
                 'label' => 'Rang des eigenen Tarifs', 'varType' => 3, 'actionable' => false],
                ['ident' => 'SimSavingText', 'type' => ControlContract::T_REFLECT, 'role' => 'energy:simsavingtext',
                 'label' => 'Ersparnis (Text)', 'varType' => 3, 'actionable' => false],
                ['ident' => 'SimSavingPctText', 'type' => ControlContract::T_REFLECT, 'role' => 'energy:simsavingpcttext',
                 'label' => 'Ersparnis als Anteil (Text)', 'varType' => 3, 'actionable' => false],
                // Jahresbeginn bis heute - der Vergleich, den die Energie-Seite schon zieht.
                // Der Grosswert der Karte bleibt der rollierende Jahresbezug: er ist die
                // Grundlage des Tarifvergleichs. YTD ist die Frage danach, nicht dieselbe.
                ['ident' => 'SimKwhYtd', 'type' => ControlContract::T_REFLECT, 'role' => 'energy:simkwhytd',
                 'label' => 'Bezug seit Jahresbeginn', 'varType' => 2, 'unit' => ' kWh', 'actionable' => false],
                ['ident' => 'SimKwhYtdPrev', 'type' => ControlContract::T_REFLECT, 'role' => 'energy:simkwhytdprev',
                 'label' => 'Bezug Vorjahr bis heute', 'varType' => 2, 'unit' => ' kWh', 'actionable' => false],
                ['ident' => 'SimYtdText', 'type' => ControlContract::T_REFLECT, 'role' => 'energy:simytdtext',
                 'label' => 'YTD-Vergleich (Text)', 'varType' => 3, 'actionable' => false],
                ['ident' => 'SimYtdDelta', 'type' => ControlContract::T_REFLECT, 'role' => 'energy:simytddelta',
                 'label' => 'YTD-Abweichung (Text)', 'varType' => 3, 'actionable' => false],
                ['ident' => 'SimYtdState', 'type' => ControlContract::T_REFLECT, 'role' => 'energy:simytdstate',
                 'label' => 'YTD-Abweichung (Zustand)', 'varType' => 3, 'actionable' => false],
                // --- Simulator: der Schieberegler und sein Ergebnis ---
                // T_SETPOINT, nicht T_SWITCH: ein Schalter zwingt den Wert auf boolesch,
                // aus 1500 kWh wurde dabei stillschweigend eine 1.
                ['ident' => 'ShiftKwh', 'type' => ControlContract::T_SETPOINT, 'role' => 'energy:shiftkwh',
                 'label' => 'Verschieben', 'varType' => 1, 'profile' => 'HSEN.ShiftKwh',
                 'min' => 0, 'max' => 3000, 'step' => 50, 'unit' => ' kWh', 'actionable' => true],
                ['ident' => 'ShiftSaving', 'type' => ControlContract::T_REFLECT, 'role' => 'energy:shiftsaving',
                 'label' => 'Ersparnis dadurch', 'varType' => 2, 'unit' => ' EUR', 'actionable' => false],
                ['ident' => 'ShiftNote', 'type' => ControlContract::T_REFLECT, 'role' => 'energy:shiftnote',
                 'label' => 'Was das bedeutet', 'varType' => 3, 'actionable' => false],
                ['ident' => 'ShiftTable', 'type' => ControlContract::T_REFLECT, 'role' => 'energy:shifttable',
                 'label' => 'Verschiebung je Tarif (Tabelle)', 'varType' => 3, 'actionable' => false],
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
                ['op' => 'refreshSim',   'label' => 'Simulation: Anzeigen auffrischen'],
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
        // Der Schieberegler braucht ein eigenes Profil: 0 bis 3.000 kWh in 50er-Schritten.
        // Feiner waere Schein-Genauigkeit - die Groesse ist eine Abschaetzung, kein Messwert.
        if (!@\IPS_VariableProfileExists('HSEN.ShiftKwh')) {
            @\IPS_CreateVariableProfile('HSEN.ShiftKwh', 1);
            @\IPS_SetVariableProfileText('HSEN.ShiftKwh', '', ' kWh');
            @\IPS_SetVariableProfileValues('HSEN.ShiftKwh', 0, 3000, 50);
            @\IPS_SetVariableProfileIcon('HSEN.ShiftKwh', 'Energy');
        }
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
        if ($c->ident === 'ShiftKwh') {
            $this->SetValue('ShiftKwh', max(0, min(3000, (int) $value)));
            $this->RefreshShift();
            return;
        }
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
                if (isset($args['entgelte']) && is_array($args['entgelte'])) {
                    // Wer einen Wert von der Rechnung eintraegt, hebt damit auch den
                    // Schaetzungs-Vermerk auf - sonst bliebe eine genaue Zahl als unsicher
                    // markiert.
                    $cur['entgelte'] = array_merge($cur['entgelte'] ?? [], $args['entgelte']);
                    if (!array_key_exists('geschaetzt', $args['entgelte'])) {
                        $cur['entgelte']['geschaetzt'] = false;
                    }
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
            case 'refreshSim':
                $this->RefreshSim();
                return ['ok' => true];

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

    /**
     * Vorgabetarife, brutto, Stand 22.09.2026.
     *
     * Quelle ist jeweils das Preisblatt des Anbieters oder eine Tarifuebersicht; `stand`
     * traegt das Datum, damit der Vergleich altert statt stillschweigend falsch zu werden.
     * Nur bundesweit oder in Oberoesterreich erhaeltliche Tarife - Wien Energie etwa
     * beliefert Wien, Niederoesterreich und das Burgenland und faellt deshalb heraus.
     *
     * Neukundenjahre stehen als EIGENER Eintrag daneben, nicht als Fussnote: ein Bonus
     * verschiebt die Rangfolge erheblich und laeuft nach zwoelf Monaten aus. Voltino zeigt
     * es: im ersten Jahr Platz zwei, danach Platz zehn.
     */
    private const SIM_TARIFE = [
        // --- Energie AG ---
        ['id' => 'feelgood', 'name' => 'Energie AG Feel Good (Jahr 1)', 'ct' => 12.00, 'rabattPct' => 5.0, 'grundEur' => 5.28,
         'bindung' => '12 Monate Festpreis', 'stand' => '2026-09-22',
         // Der Grundpreis steht als LISTENpreis. Auf ihn gibt es 1,50 EUR/Monat
         // Kombi-Bonus - aber nur in Verbindung mit einem zweiten Produkt. Ob der
         // Haushalt ihn bekommt, ist eine Vertragsfrage, keine Rechenfrage; ihn
         // ungefragt einzurechnen wuerde die Ersparnis um 36 EUR/Jahr schoenrechnen
         // (zwei Zaehlpunkte mal zwoelf Monate).
         'hinweis' => '1,50 EUR/Monat Kombi-Bonus moeglich, nicht eingerechnet',
         'quelle' => 'energieag.at/privat/strom/standard-tarife/festpreis'],
        // Der Festpreis gilt NUR zwoelf Monate. Danach laeuft der Vertrag ohne
        // Kuendigung automatisch in Oekostrom Loyal - also genau in den Tarif, der
        // heute schon gilt. Ein befristeter Tarif ohne seinen Folgetarif in der
        // Liste verspricht eine Ersparnis, die es im zweiten Jahr nicht mehr gibt;
        // die anderen befristeten Angebote stehen aus demselben Grund doppelt drin.
        ['id' => 'feelgood2', 'name' => 'Energie AG Feel Good (ab Jahr 2)', 'ct' => 14.90, 'rabattPct' => 5.0, 'gratisTage' => 30, 'grundEur' => 4.62,
         'hinweis' => 'automatischer Wechsel in Ökostrom Loyal', 'stand' => '2026-09-22',
         'quelle' => 'energieag.at/privat/strom/standard-tarife/festpreis'],
        ['id' => 'loyal',    'name' => 'Energie AG Ökostrom Loyal',   'ct' => 14.90, 'rabattPct' => 5.0, 'gratisTage' => 30, 'grundEur' => 4.62,
         'hinweis' => 'Preiserhöhung vertraglich ausgeschlossen', 'stand' => '2026-09-22', 'quelle' => 'Preisblatt Ökostrom Loyal'],
        ['id' => 'smart',    'name' => 'Energie AG Ökostrom Smart',   'rabattPct' => 5.0, 'grundEur' => 5.18,
         'zonen' => ['Sun' => 5.00, 'Day' => 17.04, 'Night' => 13.22, 'Weekend' => 13.05],
         'bindung' => '12 Monate, Smart Meter', 'stand' => '2026-09-22', 'quelle' => 'Preisblatt Ökostrom Smart'],
        // Drei Zonen mit EIGENEN Grenzen - deshalb 'zonenDef' am Tarif selbst.
        // SommerSonne Apr-Sep 10-16, WinterSonne Okt-Maerz 10-16, sonst Basis.
        // Beispiel aus einer Anlage mit PV: dort fielen nur rund 9 % des
        // Netzbezugs in die Sommersonne - mittags deckt die PV den Bedarf, sodass
        // gerade in der billigsten Stunde fast nichts aus dem Netz kommt. Rund 76 %
        // lagen in der Basiszone zu 16,98 ct, also UEBER dem Festpreis von Loyal.
        ['id' => 'smartloyal', 'name' => 'Energie AG Ökostrom Smart Loyal', 'rabattPct' => 5.0, 'grundEur' => 5.28,
         'zonen' => ['SommerSonne' => 6.60, 'WinterSonne' => 13.20, 'Basis' => 16.98],
         'zonenDef' => [
             ['name' => 'SommerSonne', 'monate' => [4, 5, 6, 7, 8, 9], 'stunden' => [10, 16], 'tage' => 'alle'],
             ['name' => 'WinterSonne', 'monate' => [10, 11, 12, 1, 2, 3], 'stunden' => [10, 16], 'tage' => 'alle'],
             ['name' => 'Basis'],
         ],
         'bindung' => 'Smart Meter, Bindung optional',
         'hinweis' => '5 % Kombi-Bonus auf den Arbeitspreis nicht eingerechnet',
         'stand' => '2026-09-22', 'quelle' => 'Preisblatt Ökostrom Smart Loyal'],
        // Boersenpreis plus Aufschlag. Das Produkt rechnet VIERTELstuendlich ab, die
        // hinterlegte EPEX-Reihe ist stuendlich - fuer einen Haushalt ohne grosse
        // schaltbare Lasten ist das eine brauchbare Naeherung, aber eben eine.
        ['id' => 'eagspot', 'name' => 'Energie AG Ökostrom Spot', 'typ' => 'spot', 'aufschlagCt' => 3.00,
         'rabattPct' => 5.0, 'grundEur' => 5.40, 'bindung' => 'Smart Meter, viertelstuendlich',
         'hinweis' => 'stuendlich gerechnet, Produkt ist viertelstuendlich',
         'stand' => '2026-09-22', 'quelle' => 'energieag.at/privat/strom/standard-tarife'],
        ['id' => 'komfort',  'name' => 'Energie AG Ökostrom Komfort', 'ct' => 19.46, 'rabattPct' => 5.0, 'grundEur' => 4.62,
         'stand' => '2026-09-22', 'quelle' => 'tarife.at'],
        ['id' => 'direkt',   'name' => 'Energie AG Ökostrom Direkt',  'ct' => 19.56, 'rabattPct' => 5.0, 'grundEur' => 7.26,
         'bindung' => '12 Monate', 'stand' => '2026-09-22', 'quelle' => 'Preisblatt Ökostrom Direkt'],
        // --- Voltino (Wels Strom) ---
        ['id' => 'voltino1', 'name' => 'Voltino Fix 26 (Neukundenjahr)', 'ct' => 14.11, 'grundEur' => 4.34,
         'hinweis' => 'nur Jahr 1', 'stand' => '2026-09-22', 'quelle' => 'Preisblatt VOLTINO Fix 26'],
        ['id' => 'voltino2', 'name' => 'Voltino Fix 26 (ab Jahr 2)',     'ct' => 19.08, 'grundEur' => 5.88,
         'hinweis' => 'Arbeitspreis ändert sich quartalsweise', 'stand' => '2026-09-22', 'quelle' => 'Preisblatt VOLTINO Fix 26'],
        // --- Verbund ---
        ['id' => 'verbund1', 'name' => 'Verbund V-Strom Österreich (Jahr 1)', 'ct' => 11.40, 'grundEur' => 4.79,
         'bindung' => '12 Monate', 'hinweis' => '3,6 ct Rabatt im ersten Jahr', 'stand' => '2026-09-22', 'quelle' => 'stromliste.at'],
        ['id' => 'verbund2', 'name' => 'Verbund V-Strom Österreich',        'ct' => 15.00, 'grundEur' => 4.79,
         'bindung' => '12 Monate', 'stand' => '2026-09-22', 'quelle' => 'stromliste.at'],
        ['id' => 'verbund3', 'name' => 'Verbund V-Strom Classic',           'ct' => 19.67, 'grundEur' => 5.64,
         'stand' => '2026-09-22', 'quelle' => 'stromliste.at'],
        ['id' => 'verbundspot', 'name' => 'Verbund V-Strom SPOT', 'typ' => 'spot', 'aufschlagCt' => 1.68, 'grundEur' => 4.79,
         'stand' => '2026-09-22', 'quelle' => 'stromliste.at'],
        // --- oekostrom AG ---
        ['id' => 'oekofix',  'name' => 'oekostrom oeko Fix',  'ct' => 15.48, 'grundEur' => 6.00,
         'stand' => '2026-09-22', 'quelle' => 'stromliste.at'],
        ['id' => 'oekofair', 'name' => 'oekostrom oeko Fair', 'ct' => 18.48, 'grundEur' => 4.80,
         'bindung' => '12 Monate', 'hinweis' => '4 Freimonate im ersten Jahr', 'stand' => '2026-09-22', 'quelle' => 'stromliste.at'],
        ['id' => 'oekoflow', 'name' => 'oekostrom oeko Flow',  'ct' => 20.54, 'grundEur' => 3.00,
         'stand' => '2026-09-22', 'quelle' => 'stromliste.at'],
        ['id' => 'oekospot', 'name' => 'oekostrom oeko Spot+', 'typ' => 'spot', 'aufschlagCt' => 1.80, 'grundEur' => 2.16,
         'stand' => '2026-09-22', 'quelle' => 'stromliste.at'],
        // --- Pullstrom ---
        ['id' => 'pull1', 'name' => 'Pullstrom Classic S (Jahr 1)', 'ct' => 12.78, 'grundEur' => 4.90,
         'hinweis' => '95 Gratistage, danach 17,28 ct', 'stand' => '2026-09-22', 'quelle' => 'stromliste.at'],
        ['id' => 'pull2', 'name' => 'Pullstrom Classic S',          'ct' => 17.28, 'grundEur' => 4.90,
         'stand' => '2026-09-22', 'quelle' => 'stromliste.at'],
        ['id' => 'pullora', 'name' => 'Pullstrom Ora', 'typ' => 'spot', 'aufschlagCt' => 1.60, 'grundEur' => 2.22,
         'stand' => '2026-09-22', 'quelle' => 'stromliste.at'],
        // --- Boersentarife ---
        ['id' => 'awattarh', 'name' => 'aWATTar HOURLY', 'typ' => 'spot', 'aufschlagCt' => 1.80, 'grundEur' => 5.75,
         'stand' => '2026-09-22', 'quelle' => 'stromliste.at'],
        ['id' => 'awattarm', 'name' => 'aWATTar Monthly', 'ct' => 20.12, 'grundEur' => 5.75,
         'stand' => '2026-09-22', 'quelle' => 'stromliste.at'],
        ['id' => 'smartc', 'name' => 'smartENERGY smartCONTROL', 'typ' => 'spot', 'aufschlagCt' => 1.44, 'grundEur' => 2.99,
         'stand' => '2026-09-22', 'quelle' => 'stromliste.at'],
        // --- MONTANA ---
        ['id' => 'montana1', 'name' => 'MONTANA Strom RELAX', 'ct' => 25.14, 'grundEur' => 5.04,
         'stand' => '2026-09-22', 'quelle' => 'stromliste.at'],
    ];

    /** Wo die Boersenpreisreihe liegt (ct/kWh netto je Stunde). */
    private const SPOT_DATEI = '/var/lib/symcon/scripts/data/spotpreise-at.json';

    /**
     * Entgelte neben dem Energiepreis - NETTO, je Zaehlpunkt wo es fix ist.
     *
     * Sie aendern die Rangfolge der Anbieter NICHT, weil sie fuer alle gleich sind. Sie
     * beantworten aber die andere Frage: was steht am Ende auf der Rechnung. Genau deshalb
     * stehen sie getrennt und werden nicht in den Anbietervergleich gemischt.
     *
     * Die Werte sind belegte Schaetzungen fuer das Netzgebiet Oberoesterreich 2026, KEINE
     * abgelesenen Zahlen: Netz OOE veroeffentlicht die Aufschluesselung nicht frei. Die
     * genauen Betraege stehen auf der Netzrechnung; bis dahin ist die Gesamtsumme eine
     * Groessenordnung und als solche gekennzeichnet.
     *
     * Elektrizitaetsabgabe und Erneuerbaren-Foerderpauschale sind dagegen bundesweit
     * festgelegt und genau: 0,10 ct/kWh (Haushalte, befristet bis Ende 2026) und
     * 19,02 EUR je Zaehlpunkt und Jahr.
     */
    private const ENTGELTE = [
        'netzArbeitCt'     => 6.50,   // Netznutzung + Netzverlust, geschaetzt
        'netzFixEur'       => 3.50,   // Messentgelt + Pauschale je Monat, geschaetzt
        'elAbgabeCt'       => 0.10,   // bundesweit, Haushalte 2026
        'oekoBeitragCt'    => 0.30,   // Erneuerbaren-Foerderbeitrag, geschaetzt
        'oekoPauschaleEur' => 19.02,  // je Zaehlpunkt und Jahr, bundesweit
        'ustProzent'       => 20.0,
        'geschaetzt'       => true,
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
            'spotDatei' => (string) ($s['spotDatei'] ?? self::SPOT_DATEI),
            'warnTage'  => max(7, (int) ($s['warnTage'] ?? 90)),
            'entgelte'  => array_merge(self::ENTGELTE,
                             (isset($s['entgelte']) && is_array($s['entgelte'])) ? $s['entgelte'] : []),
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
    private function stundenreihe(int $vid, int $tage, int $versatzTage = 0): array
    {
        $ac = $this->archivId();
        if ($ac <= 0 || $vid <= 0 || !@\AC_GetLoggingStatus($ac, $vid)) {
            return [];
        }
        // $versatzTage schiebt das Fenster nach hinten: 365/365 liefert das Jahr VOR
        // dem laufenden, ohne dass der Rest der Rechnung etwas davon wissen muss.
        $bis = time() - $versatzTage * 86400;
        return $this->reiheKwh($vid, $bis - $tage * 86400, $bis)['reihe'];
    }

    /**
     * Die Stundenreihe einer Bezugsvariablen in kWh, samt Urteil ueber ihre Brauchbarkeit.
     *
     * ZAEHLERVARIABLEN sind die Wahrheit - bezahlt wird nach Arbeit, nicht nach Leistung.
     * Fuer sie liefert das Archiv im stuendlichen `Avg` bereits den VERBRAUCH der Stunde
     * in kWh. Gegengeprueft am 22.09.2026 ueber 365 Tage: Summe der Stundenwerte
     * 10.776 kWh gegen einen Zaehlerhub von 10.762 kWh - 0,13 % Abweichung.
     *
     * Eine LEISTUNGSVARIABLE (W) bleibt als Rueckfall moeglich, taugt aber nur als
     * Naeherung: dieselbe Anlage lieferte integriert 13.339 kWh, waehrend die Zaehler
     * 13.018 kWh auswiesen - 2,5 % zu hoch, im laufenden Jahr sogar 6,8 %.
     *
     * ZWEI FALLEN, beide am 22.09.2026 gemessen:
     *
     * 1. Der ERSTE Eintrag einer Zaehlerreihe ist der Zaehlerstand selbst, keine
     *    Differenz. Am Beginn der Aufzeichnung (19.12.2024) steht eine Stunde mit
     *    120.809,64 kWh. Wer sie mitzaehlt, bekommt fuer das Vorjahr 157.712 kWh.
     *    Erkannt wird sie ohne Zauberzahl: eine einzelne Stunde kann unmoeglich mehr
     *    verbraucht haben als das ganze Fenster, also gilt der Zaehlerhub als Obergrenze.
     *
     * 2. Reicht die Aufzeichnung nicht bis zum Beginn des Fensters, ist die Summe kein
     *    Jahresverbrauch, sondern ein Rest. Deshalb das Urteil `vollstaendig`: es gibt
     *    genau dann true, wenn zum Fensterbeginn schon ein Wert im Archiv steht.
     */
    private function reiheKwh(int $vid, int $von, int $bis): array
    {
        $leer = ['reihe' => [], 'summe' => 0.0, 'hub' => null, 'vollstaendig' => false];
        $ac = $this->archivId();
        if ($ac <= 0 || $vid <= 0 || $bis <= $von || !@\AC_GetLoggingStatus($ac, $vid)) { return $leer; }

        $zaehler = ((int) @\AC_GetAggregationType($ac, $vid) === 1);
        $stand = static function (int $t) use ($ac, $vid): ?float {
            $x = @\AC_GetLoggedValues($ac, $vid, 0, $t, 1);
            return (is_array($x) && count($x)) ? (float) $x[0]['Value'] : null;
        };
        $anfang = $stand($von);
        $ende   = $stand($bis);
        $hub = ($zaehler && $anfang !== null && $ende !== null) ? ($ende - $anfang) : null;

        $a = @\AC_GetAggregatedValues($ac, $vid, 0, $von, $bis, 0);
        if (!is_array($a)) { return $leer; }

        // Lange Fenster bekommen eine Obergrenze je Stunde: eine einzelne Stunde, die
        // mehr als ein Prozent des ganzen Fensters traegt, ist kein Verbrauch, sondern
        // ein Sprung im Zaehlwerk. Am Zaehlpunkt DG standen so 22 Stunden mit bis zu
        // 189,6 kWh - in einer Wohnung nicht moeglich; sie blaehten das Jahr von 2.255
        // auf 3.995 kWh. Auf kurze Fenster ist die Regel nicht anwendbar (ein Prozent
        // eines Tages waere weniger als eine normale Stunde), dort bleibt sie aus.
        $grenze = ($hub !== null && $hub > 0 && ($bis - $von) >= 30 * 86400) ? ($hub * 0.01) : null;

        $reihe = []; $summe = 0.0;
        foreach ($a as $h) {
            $v = (float) ($h['Avg'] ?? 0);
            if ($v <= 0) { $kwh = 0.0; }
            elseif (!$zaehler) { $kwh = $v / 1000.0; }
            elseif ($hub !== null && $hub > 0 && $v > $hub) { continue; }   // Zaehlerstand, kein Verbrauch
            elseif ($grenze !== null && $v > $grenze) { continue; }         // Sprung im Zaehlwerk
            else { $kwh = $v; }
            $reihe[] = ['ts' => (int) ($h['TimeStamp'] ?? 0), 'kwh' => $kwh];
            $summe += $kwh;
        }

        // Und dann gilt der ZAEHLERHUB. Die Stundenreihe liefert nur noch die Form -
        // wann der Strom bezogen wurde -, die Hoehe kommt vom Zaehler. Was die Filter
        // nicht erwischt haben, faengt der Faktor ab. Er greift nur im plausiblen
        // Bereich: weicht die Summe um mehr als die Haelfte ab, stimmt etwas
        // Grundsaetzliches nicht, und stillschweigend zurechtzuruecken waere falsch.
        if ($zaehler && $hub !== null && $hub > 0 && $summe > 0) {
            $f = $hub / $summe;
            if ($f > 0.5 && $f < 2.0) {
                foreach ($reihe as $k => $e) { $reihe[$k]['kwh'] = $e['kwh'] * $f; }
                $summe = $hub;
            }
        }

        return ['reihe' => $reihe, 'summe' => $summe, 'hub' => $hub,
                'vollstaendig' => $zaehler ? ($anfang !== null) : (count($reihe) >= (int) (($bis - $von) / 3600 * 0.66))];
    }

    /**
     * Netzbezug in einem frei gewaehlten Fenster, in kWh - ueber alle Zaehler.
     *
     * Liefert null, sobald ein Zaehler das Fenster nicht zu zwei Dritteln traegt.
     */
    private function bezugFenster(int $von, int $bis): ?float
    {
        $summe = 0.0; $hatte = false;
        foreach ($this->simCfg()['zaehler'] as $z) {
            $r = $this->reiheKwh((int) $z['vid'], $von, $bis);
            if (!$r['vollstaendig']) { return null; }
            $summe += $r['summe'];
            $hatte = true;
        }
        return $hatte ? $summe : null;
    }

    /**
     * Der Netzbezug des Jahres VOR dem laufenden, in kWh.
     *
     * Dient allein dem Vergleich auf der Wertkarte. Liefert null, sobald das Archiv
     * das aeltere Fenster nicht mehr vollstaendig traegt - eine halb gefuellte
     * Vorperiode ergaebe einen Rueckgang, den es nie gab.
     */
    private function bezugVorperiode(int $tage): ?float
    {
        $bis = time() - $tage * 86400;
        return $this->bezugFenster($bis - $tage * 86400, $bis);
    }

    /**
     * Die Boersenpreisreihe, Unixzeit der Stunde => ct/kWh netto.
     *
     * Kommt von aWATTar (EPEX Spot AT), liegt als Datei daneben statt im Store: 8.771
     * Stundenpreise sind kein Konfigurationswert, und der Store wird bei jedem Schreiben
     * ganz neu geschrieben.
     */
    private function spotreihe(string $datei): array
    {
        if ($datei === '' || !is_readable($datei)) { return []; }
        $j = json_decode((string) @file_get_contents($datei), true);
        $p = (is_array($j) && isset($j['preise']) && is_array($j['preise'])) ? $j['preise'] : [];
        $out = [];
        foreach ($p as $ts => $ct) { $out[(int) $ts] = (float) $ct; }
        return $out;
    }

    /** Kopfdaten der Preisreihe - fuer die Frage, wie frisch sie ist. */
    private function spotStand(string $datei): array
    {
        if ($datei === '' || !is_readable($datei)) { return ['vorhanden' => false]; }
        $j = json_decode((string) @file_get_contents($datei), true);
        if (!is_array($j)) { return ['vorhanden' => false]; }
        return ['vorhanden' => true, 'quelle' => $j['quelle'] ?? '?',
                'stunden' => (int) ($j['stunden'] ?? 0),
                'abgerufen' => (string) ($j['abgerufen'] ?? ''),
                'alter_tage' => isset($j['abgerufen']) ? (int) floor((time() - strtotime((string) $j['abgerufen'])) / 86400) : null];
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
        $spot = $this->spotreihe((string) $cfg['spotDatei']);
        $gesamtZonen = []; $proZaehler = []; $faktor = 1.0; $alleStunden = [];
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
                'tarife'       => $E::vergleichAlle($reihe, $auf['zonen'], $cfg['tarife'], $spot, $faktor, $cfg['istTarif']),
            ];
            foreach ($auf['zonen'] as $n => $v) {
                $gesamtZonen[$n] = ($gesamtZonen[$n] ?? 0.0) + $v;
            }
            // Fuer die Boersenrechnung werden die Stunden beider Zaehler zusammengelegt.
            foreach ($reihe as $h) {
                $ts = (int) $h['ts'];
                $alleStunden[$ts] = ['ts' => $ts, 'kwh' => (($alleStunden[$ts]['kwh'] ?? 0.0) + (float) $h['kwh'])];
            }
        }
        // Entgelte einmal fuer den ganzen Haushalt, dann je Tarif die Gesamtsumme.
        $kwhJahr = array_sum($gesamtZonen) * $faktor;
        $entg = $this->entgelte($kwhJahr, max(1, count($cfg['zaehler'])), $cfg['entgelte']);
        $gesamtTarife = $E::vergleichAlle(array_values($alleStunden), $gesamtZonen,
                            $this->simTarifeDoppelt($cfg), $spot, $faktor, $cfg['istTarif']);
        $gesamt = [];
        foreach ($gesamtTarife as $t) {
            if ($t['gesamt'] === null) { continue; }
            $gesamt[] = ['name' => $t['name'], 'id' => $t['id'],
                         'energie' => round($t['gesamt'], 2),
                         'entgelte' => $entg['summe_brutto'],
                         'gesamt'  => round($t['gesamt'] + $entg['summe_brutto'], 2),
                         'je_kwh_ct' => $kwhJahr > 0 ? round(100 * ($t['gesamt'] + $entg['summe_brutto']) / $kwhJahr, 2) : null];
        }
        return ['ok' => true,
                'zeitraum_tage'  => $tage,
                'hochgerechnet'  => round($faktor, 3),
                'je_zaehlpunkt'  => $proZaehler,
                'gesamt' => [
                    'kwh_jahr' => round(array_sum($gesamtZonen) * $faktor, 0),
                    'zonen'    => array_map(static fn($v) => round($v * $faktor, 0), $gesamtZonen),
                    'tarife'   => $gesamtTarife,
                ],
                'entgelte'      => $entg,
                'gesamtkosten'  => $gesamt,
                'boersenpreise' => $this->spotStand((string) $cfg['spotDatei']),
                'alter'   => $E::alter($cfg['tarife'], time(), (int) $cfg['warnTage']),
                'hinweis' => 'Nur Energiekosten. Netzentgelt und Abgaben sind anbieterunabhaengig.'];
    }

    /**
     * Was neben dem Energiepreis anfaellt - fuer den ganzen Haushalt, brutto.
     *
     * @param float $kwh Jahresbezug ueber alle Zaehlpunkte
     * @param int   $n   Anzahl der Zaehlpunkte (Messentgelt und Pauschale fallen je Punkt an)
     */
    private function entgelte(float $kwh, int $n, array $e): array
    {
        $ust = 1.0 + ((float) $e['ustProzent']) / 100.0;
        $netzArbeit = $kwh * ((float) $e['netzArbeitCt']) / 100.0;
        $netzFix    = ((float) $e['netzFixEur']) * 12.0 * $n;
        $elAbgabe   = $kwh * ((float) $e['elAbgabeCt']) / 100.0;
        $oekoBeitr  = $kwh * ((float) $e['oekoBeitragCt']) / 100.0;
        $oekoPausch = ((float) $e['oekoPauschaleEur']) * $n;
        $netto = $netzArbeit + $netzFix + $elAbgabe + $oekoBeitr + $oekoPausch;
        return [
            'netz_arbeit'        => round($netzArbeit * $ust, 2),
            'netz_fix'           => round($netzFix * $ust, 2),
            'elektrizitaets_abgabe' => round($elAbgabe * $ust, 2),
            'oeko_beitrag'       => round($oekoBeitr * $ust, 2),
            'oeko_pauschale'     => round($oekoPausch * $ust, 2),
            'summe_brutto'       => round($netto * $ust, 2),
            'je_kwh_ct'          => $kwh > 0 ? round(100 * $netto * $ust / $kwh, 2) : 0.0,
            'geschaetzt'         => !empty($e['geschaetzt']),
        ];
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

    /**
     * Die Simulation in Anzeigevariablen spiegeln.
     *
     * Die LVB-Seite liest Tabellen ueber ?api=tabledata aus einer Variablen - deshalb
     * landen Tarifvergleich und Zonen hier als Zeilen-Array, nicht als verschachteltes
     * JSON. Erste Zeile ist die Kopfzeile, so erwartet es das Tabellen-Widget.
     *
     * Bewusst NICHT im Takt: die Tarifrechnung liest 8.760 Stundenwerte je Zaehlpunkt aus
     * dem Archiv. Das dauert zwar nur Bruchteile einer Sekunde, aendert sich aber hoechstens
     * taeglich - alle fuenf Minuten waere reine Verschwendung.
     */
    public function RefreshSim(): void
    {
        $x = $this->mgmtSimTarife([]);
        if (empty($x['ok'])) {
            $this->setReflect('SimTable', json_encode([['Hinweis'], [(string) ($x['error'] ?? 'Simulation nicht möglich')]]));
            return;
        }
        $eur = static fn($v) => number_format((float) $v, 0, ',', '.') . ' €';

        // --- Tarifvergleich ---
        //
        // Spaltenwahl mit Absicht: die Spalte "Status" traegt guenstiger/heute/teurer und
        // wird vom Tabellen-Widget zu einem farbigen Chip, "Anteil" mit Prozentzeichen zu
        // einem Balken. So entsteht die Grafik aus den Daten selbst, ohne ein weiteres
        // Widget. Die Entgelte stehen NICHT je Zeile: sie sind fuer alle Anbieter gleich,
        // eine Spalte mit 22 gleichen Werten traegt nichts bei.
        $zeilen = [['Tarif', 'Status', 'Anteil', 'Energie', 'Gesamt', 'ct/kWh', 'ggü. heute']];
        $ist = null; $bester = null; $teuerster = 0.0;
        foreach ($x['gesamtkosten'] as $t) { $teuerster = max($teuerster, (float) $t['gesamt']); }
        foreach ($x['gesamtkosten'] as $t) {
            if ($bester === null) { $bester = $t; }
            $roh = null;
            foreach ($x['gesamt']['tarife'] as $g) { if ($g['id'] === $t['id']) { $roh = $g; break; } }
            $diff = $roh['differenz'] ?? null;
            $heute = ($diff !== null && abs((float) $diff) < 0.01);
            if ($heute) { $ist = $t; }
            $zeilen[] = [
                $t['name'],
                $diff === null ? '' : ($heute ? 'heute' : ((float) $diff < 0 ? 'günstiger' : 'teurer')),
                $teuerster > 0 ? number_format(100 * (float) $t['gesamt'] / $teuerster, 0, ',', '.') . ' %' : '',
                $eur($t['energie']),
                $eur($t['gesamt']),
                number_format((float) $t['je_kwh_ct'], 2, ',', '.'),
                $diff === null ? '' : ($heute ? '—'
                    : (($diff > 0 ? '+' : '−') . number_format(abs((float) $diff), 0, ',', '.') . ' €')),
            ];
        }
        $this->setReflect('SimTable', json_encode($zeilen, JSON_UNESCAPED_UNICODE));

        // --- Zeitzonen ---
        $zz = [['Zone', 'Anteil', 'kWh/Jahr']];   // "Anteil" mit % wird zum Balken
        $summe = array_sum($x['gesamt']['zonen']);
        foreach ($x['gesamt']['zonen'] as $n => $v) {
            $zz[] = [$n, $summe > 0 ? number_format(100 * $v / $summe, 1, ',', '.') . ' %' : '—',
                     number_format((float) $v, 0, ',', '.')];
        }
        $this->setReflect('SimZones', json_encode($zz, JSON_UNESCAPED_UNICODE));

        // --- Kennzahlen ---
        $this->setReflect('SimKwh', round((float) $x['gesamt']['kwh_jahr'], 0));
        if ($bester !== null) {
            $this->setReflect('SimCostBest', round((float) $bester['gesamt'], 0));
            $this->setReflect('SimBestName', (string) $bester['name']);
        }
        if ($ist !== null) {
            $istK = (float) $ist['gesamt'];
            $bstK = (float) ($bester['gesamt'] ?? 0);
            $this->setReflect('SimCostToday', round($istK, 0));
            $this->setReflect('SimSaving', round($istK - $bstK, 0));
            $this->setReflect('SimSavingPct', $istK > 0 ? round(100 * ($istK - $bstK) / $istK, 1) : 0.0);
            $this->setReflect('SimSavingText',
                '−' . number_format($istK - $bstK, 0, ',', '.') . ' €/a');
            // Als Text, nicht als Zahl: die Plakette zeigt den Rohwert, und "13,80"
            // ohne Prozentzeichen liest sich wie ein Geldbetrag.
            $this->setReflect('SimSavingPctText', $istK > 0
                ? (number_format(100 * ($istK - $bstK) / $istK, 1, ',', '.') . ' %') : '');
            // Rang: der eigene Tarif in der nach Kosten sortierten Liste.
            $rang = 0; $i = 0;
            foreach ($x['gesamtkosten'] as $t) {
                $i++;
                if ($t['id'] === $ist['id']) { $rang = $i; break; }
            }
            $this->setReflect('SimRankText', $rang > 0
                ? ('Platz ' . $rang . ' von ' . count($x['gesamtkosten'])) : '');
        }

        // --- Beide Enden der Skala ---
        //
        // Die Wertkarten zeichnen eine Leiste von guenstigstem bis teuerstem Tarif und
        // setzen den eigenen Stand als Punkt darauf. Dafuer braucht es das obere Ende
        // als eigene Variable - aus der Tabelle laesst es sich nicht binden.
        $letzter = end($x['gesamtkosten']) ?: null;
        if ($letzter) {
            $this->setReflect('SimCostWorst', round((float) $letzter['gesamt'], 0));
            $this->setReflect('SimPriceWorst', round((float) $letzter['je_kwh_ct'], 2));
        }
        if ($bester !== null) {
            $this->setReflect('SimPriceBest', round((float) $bester['je_kwh_ct'], 2));
        }

        // --- Anteil der teuren Tageszone ---
        $tagKwh = (float) ($x['gesamt']['zonen']['Day'] ?? 0);
        $this->setReflect('SimDayPct', $summe > 0 ? round(100 * $tagKwh / $summe, 1) : 0.0);

        // --- Vergleich mit dem Jahr davor ---
        //
        // Nur setzen, wenn das Archiv die Vorperiode wirklich traegt. Eine 0 waere hier
        // keine Angabe, sondern eine Behauptung - und die Karte zeigte "-100 %".
        $vor = $this->bezugVorperiode((int) $this->simCfg()['tage']);
        if ($vor !== null && $vor > 0) {
            $this->setReflect('SimKwhPrev', round($vor, 0));
        }

        // --- Seit Jahresbeginn, taggenau gegen das Vorjahr ---
        //
        // Dieselbe Rechnung, die die Energie-Seite serverseitig zieht (?api=cmp&stage=year):
        // vom 1. Jaenner bis JETZT, dagegen derselbe Abschnitt des Vorjahres. Der rollierende
        // Jahresbezug daneben ist eine ANDERE Frage und darf abweichen - im September
        // ueberlappen sich die beiden Fenster nur zu drei Vierteln.
        $jetzt   = time();
        $anfang  = mktime(0, 0, 0, 1, 1, (int) date('Y', $jetzt));
        $ytd     = $this->bezugFenster($anfang, $jetzt);
        $ytdVor  = $this->bezugFenster(strtotime('-1 year', $anfang), strtotime('-1 year', $jetzt));
        if ($ytd !== null) { $this->setReflect('SimKwhYtd', round($ytd, 0)); }
        if ($ytdVor !== null) { $this->setReflect('SimKwhYtdPrev', round($ytdVor, 0)); }
        if ($ytd !== null && $ytdVor !== null && $ytdVor > 0) {
            $p = 100 * ($ytd - $ytdVor) / $ytdVor;
            $this->setReflect('SimYtdText',
                'seit 1. Jänner ' . number_format($ytd, 0, ',', '.')
                . ' kWh gegen ' . number_format($ytdVor, 0, ',', '.') . ' kWh im Vorjahr');
            $this->setReflect('SimYtdDelta',
                ($p >= 0 ? '+' : '−') . number_format(abs($p), 1, ',', '.') . ' % YTD');
            // Mehr Verbrauch ist nicht gut: die Farbe dreht, nicht das Vorzeichen.
            // Neutralband bei zwei Prozent: Wetter und Anwesenheit allein bewegen den
            // Jahresverbrauch um mehr als ein Prozent - das rot zu faerben, meldet
            // Alarm, wo nichts geschehen ist.
            $this->setReflect('SimYtdState', abs($p) < 2.0 ? 'muted' : ($p > 0 ? 'crit' : 'ok'));
        }

        $this->RefreshShift();
    }

    /**
     * Was der Schieberegler gerade bedeutet.
     *
     * Zeigt BEIDES: was die Verschiebung beim besten Zeitzonentarif braechte und dass sie
     * bei einem Festtarif exakt nichts bringt. Nur den guenstigen Fall zu zeigen waere ein
     * Versprechen, das der eigene Tarif nicht haelt.
     */
    public function RefreshShift(): void
    {
        $kwh = (int) @$this->GetValue('ShiftKwh');
        $x = $this->mgmtSimVerschiebung(['kwh' => $kwh, 'von' => 'Day', 'nach' => 'Sun']);
        if (empty($x['ok'])) { return; }

        $zeilen = [['Tarif', 'Status', 'Anteil', 'Ersparnis', 'je kWh']];
        $beste = 0.0;
        foreach ($x['ergebnis'] as $e) { $beste = max($beste, (float) $e['ersparnis']); }
        foreach ($x['ergebnis'] as $e) {
            $wirkt = ((float) $e['ersparnis'] > 0);
            $zeilen[] = [$e['tarif'],
                $wirkt ? 'günstiger' : 'ruht',
                ($wirkt && $beste > 0) ? number_format(100 * (float) $e['ersparnis'] / $beste, 0, ',', '.') . ' %' : '0 %',
                number_format((float) $e['ersparnis'], 0, ',', '.') . ' €',
                $wirkt ? number_format((float) $e['je_kwh_ct'], 2, ',', '.') . ' ct' : '—'];
        }
        $this->setReflect('ShiftTable', json_encode($zeilen, JSON_UNESCAPED_UNICODE));
        $this->setReflect('ShiftSaving', round($beste, 0));

        // Das Sonnenfenster umfasst Apr-Aug, taeglich 12-16 Uhr: 153 Tage x 4 h = 612 Stunden.
        $stunden = 612;
        $kw = $kwh > 0 ? $kwh / $stunden : 0.0;
        $this->setReflect('ShiftNote', $kwh <= 0
            ? 'Nichts verschoben.'
            : sprintf('%s kWh im Sonnenfenster (%d Stunden im Jahr) bedeuten %s kW Dauerlast in jeder einzelnen Stunde.%s',
                number_format($kwh, 0, ',', '.'), $stunden,
                number_format($kw, 1, ',', '.'),
                $kw > 4.0 ? ' Das ist mit Haushaltsgeräten nicht zu schaffen.' : ''));
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

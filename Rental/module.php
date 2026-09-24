<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/HomeSuite/autoload.php';

use Hoep\HomeSuite\Engines\OccupancyEngine;

/**
 * Rental (HSRT) — Belegung und Umsatz einer Ferienwohnung ohne Buchungskalender.
 *
 * Eine Instanz je vermietetem Standort, eingehaengt unter dessen HSSP-Instanz. Die Wohnung
 * verraet ihre Belegung selbst: fremde Geraete im Gaestenetz (aus der Anwesenheit HSPR des
 * Standorts) und weitere Aktivitaet wie Schaltvorgaenge der Klimaanlagen. Aus dem
 * Tagesmittel dieser Signale entsteht je Tag ein Wert, daraus der Tagesstatus
 * (frei / An- oder Abreise / belegt), daraus die belegten und freien Wochen und mit den
 * Wochenpreisen der Saison der Umsatz.
 *
 * Die Geraetezahl wird hier mitgeschrieben und archiviert, damit das Tagesmittel aus dem
 * Archiv kommt. Eine aeltere Zeitreihe (fruehere Zaehlvariable) kann als Vorgeschichte
 * angegeben werden; fuer Tage ohne eigene Werte wird sie gelesen.
 *
 * Die Rechnung steckt in Engines/OccupancyEngine und ist ohne Symcon testbar.
 */
class Rental extends IPSModule
{
    private const ARCHIVE_GUID = '{43192F0B-135B-4CE7-A0A7-1475603F3060}';

    private const ST_OFF = 0;
    private const ST_FREE = 1;
    private const ST_SERVICE = 2;
    private const ST_OCCUPIED = 3;

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger('PresenceId', 0);        // HSPR des Standorts (Gaeste-Geraete)
        $this->RegisterPropertyInteger('LegacyGuestVid', 0);    // aeltere archivierte Geraetezahl (Vorgeschichte)
        $this->RegisterPropertyString('Counters', '[]');        // [{Vid}] archivierte Aktivitaetszaehler
        $this->RegisterPropertyString('SeasonStart', '1.6.');
        $this->RegisterPropertyString('SeasonEnd', '30.9.');
        $this->RegisterPropertyString('Prices', '[]');          // [{Von, Bis, Preis}]
        $this->RegisterPropertyFloat('VacantMax', 0.1);
        $this->RegisterPropertyFloat('ServiceMax', 1.4);
        $this->RegisterPropertyFloat('OccupiedMin', 1.5);
        $this->RegisterPropertyFloat('CommissionPct', 30.0);
        $this->RegisterPropertyFloat('TaxPct', 21.0);
        $this->RegisterPropertyString('Overrides', '[]');       // [{Datum "d.m.Y", Wert}]
        $this->RegisterPropertyInteger('IntervalMinutes', 60);
        $this->RegisterPropertyString('Mode', 'v2');           // v2 | legacy (Regeln des alten Skripts)
        $this->RegisterPropertyInteger('GapMax', 2);           // v2: Luecke in Tagen, die ein Aufenthalt ueberbrueckt
        $this->RegisterPropertyInteger('MinStay', 3);          // v2: Mindestdauer einer Buchung in Tagen

        $this->ensureProfiles();
        $this->RegisterVariableInteger('GuestDevices', 'Gäste-Geräte (jetzt)', '', 10);
        $this->RegisterVariableInteger('TodayStatus', 'Heute', 'HSRT.Status', 20);
        $this->RegisterVariableFloat('TodayValue', 'Tageswert heute', '', 25);
        $this->RegisterVariableFloat('OccupancyPct', 'Auslastung Saison', 'HSRT.Percent', 30);
        $this->RegisterVariableInteger('WeeksBooked', 'Belegte Wochen', '', 40);
        $this->RegisterVariableInteger('WeeksFree', 'Freie Wochen', '', 41);
        $this->RegisterVariableInteger('WeeksPossible', 'Mögliche Wochen', '', 42);
        $this->RegisterVariableFloat('RevenueGross', 'Umsatz brutto (vor Provision)', 'HSRT.Euro', 50);
        $this->RegisterVariableFloat('RevenueAfterCommission', 'Umsatz nach Provision', 'HSRT.Euro', 51);
        $this->RegisterVariableFloat('RevenueNet', 'Umsatz netto', 'HSRT.Euro', 52);
        $this->RegisterVariableFloat('RevenuePotential', 'Potenzial (freie Wochen)', 'HSRT.Euro', 53);
        $this->RegisterVariableFloat('RevenueMax', 'Maximum (volle Saison)', 'HSRT.Euro', 54);
        $this->RegisterVariableString('Register', 'Register (JSON)', '', 90);

        $this->RegisterTimer('Sample', 0, 'HSRT_Sample($_IPS[\'TARGET\']);');
        $this->RegisterTimer('Update', 0, 'HSRT_Update($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->ensureProfiles();
        // Anzeige-Tabellen (Zeilenformat wie das Tabellen-Widget). Hier statt in Create(),
        // damit bestehende Instanzen sie ohne Neuanlage bekommen.
        $this->RegisterVariableString('Summary', 'Kurzfassung', '', 15);
        $this->RegisterVariableString('CalendarTable', 'Kalender (Tabelle)', '', 91);
        $this->RegisterVariableString('CalendarPrevTable', 'Kalender Vorjahr (Tabelle)', '', 92);
        $this->RegisterVariableString('BookingsTable', 'Buchungen (Tabelle)', '', 93);
        $this->RegisterVariableString('FreeTable', 'Noch buchbar (Tabelle)', '', 94);
        // Saison aus der Visualisierung einstellbar: schaltbare Spiegel der Einstellungen.
        $this->RegisterVariableString('SeasonFrom', 'Saison von', '', 80);
        $this->RegisterVariableString('SeasonTo', 'Saison bis', '', 81);
        $this->EnableAction('SeasonFrom');
        $this->EnableAction('SeasonTo');
        $this->SetValue('SeasonFrom', $this->ReadPropertyString('SeasonStart'));
        $this->SetValue('SeasonTo', $this->ReadPropertyString('SeasonEnd'));
        $this->ensureLogging();
        $this->SetTimerInterval('Sample', 60 * 1000);
        $min = max(0, $this->ReadPropertyInteger('IntervalMinutes'));
        $this->SetTimerInterval('Update', $min > 0 ? max(5, $min) * 60 * 1000 : 0);
        $this->SetStatus($this->ReadPropertyInteger('PresenceId') > 0 || $this->ReadPropertyInteger('LegacyGuestVid') > 0 ? 102 : 104);
    }

    /**
     * Saison von/bis aus der Visualisierung: "1.6.", "01.06", "1.6.2026" - alles wird zu "T.M.".
     * Ungueltiges wird verworfen, die Anzeige springt auf den gueltigen Wert zurueck. Gueltiges
     * wird Einstellung (bleibt nach Neustart) und sofort neu gerechnet.
     */
    public function RequestAction($Ident, $Value)
    {
        if ($Ident !== 'SeasonFrom' && $Ident !== 'SeasonTo') {
            throw new Exception('Unbekannte Aktion ' . $Ident);
        }
        $prop = $Ident === 'SeasonFrom' ? 'SeasonStart' : 'SeasonEnd';
        $norm = '';
        if (preg_match('/^\s*(\d{1,2})\s*[.\/-]\s*(\d{1,2})\s*[.\/-]?\s*(\d{2,4})?\s*$/', (string) $Value, $m)
            && checkdate((int) $m[2], (int) $m[1], 2024)) {
            $norm = (int) $m[1] . '.' . (int) $m[2] . '.';
        }
        if ($norm === '') {
            $this->SetValue($Ident, $this->ReadPropertyString($prop));   // Eingabe verworfen
            $this->LogMessage('Saison: "' . $Value . '" ist kein Datum (T.M.)', KL_WARNING);
            return;
        }
        $this->SetValue($Ident, $norm);
        if ($norm !== $this->ReadPropertyString($prop)) {
            IPS_SetProperty($this->InstanceID, $prop, $norm);
            IPS_ApplyChanges($this->InstanceID);
            $this->Update();
        }
    }

    /** Minuetlich: aktuelle Gaeste-Geraetezahl aus der Anwesenheit uebernehmen (wird archiviert). */
    public function Sample(): void
    {
        $pid = $this->ReadPropertyInteger('PresenceId');
        if ($pid <= 0 || !@IPS_InstanceExists($pid)) {
            return;
        }
        $gv = @IPS_GetObjectIDByIdent('Guests', $pid);
        if (!$gv) {
            return;
        }
        $n = max(0, (int) GetValue($gv));
        $own = $this->GetIDForIdent('GuestDevices');
        if (GetValue($own) !== $n) {
            SetValue($own, $n);
        }
    }

    /** Tageswerte des laufenden Jahres aus dem Archiv, Rechnung, Variablen schreiben. */
    public function Update(): bool
    {
        $this->Sample();
        $year = (int) date('Y');
        $values = $this->dayValues($year);
        $eng = $this->engine($values, $year, (int) date('z'));
        $st = $eng->stats();
        $rv = $eng->revenue();

        $today = (int) date('z');
        $map = [OccupancyEngine::ST_OFF => self::ST_OFF, OccupancyEngine::ST_FREE => self::ST_FREE,
                OccupancyEngine::ST_SERVICE => self::ST_SERVICE, OccupancyEngine::ST_OCCUPIED => self::ST_OCCUPIED];
        $comm = 1.0 - max(0.0, min(100.0, $this->ReadPropertyFloat('CommissionPct'))) / 100.0;
        $tax  = 1.0 - max(0.0, min(100.0, $this->ReadPropertyFloat('TaxPct'))) / 100.0;

        $this->put('TodayStatus', $map[$eng->status($today)]);
        $this->put('TodayValue', round((float) ($values[$today] ?? 0), 1));
        $this->put('OccupancyPct', (float) $st['occupancy_percent']);
        $this->put('WeeksBooked', (int) $rv['occupied_weeks']);
        $this->put('WeeksFree', (int) $rv['available_weeks']);
        $this->put('WeeksPossible', (int) $rv['possible_weeks']);
        $this->put('RevenueGross', round($rv['current'], 2));
        $this->put('RevenueAfterCommission', round($rv['current'] * $comm, 2));
        $this->put('RevenueNet', round($rv['current'] * $comm * $tax, 2));
        $this->put('RevenuePotential', round($rv['potential'], 2));
        $this->put('RevenueMax', round($rv['max'], 2));

        $days = [];
        for ($i = 0; $i < $eng->dayCount(); $i++) {
            $days[] = ['d' => $eng->date($i), 'v' => round((float) ($values[$i] ?? 0), 1), 's' => $map[$eng->status($i)]];
        }
        $fmt = function (array $w) use ($eng) {
            return ['von' => $eng->date($w['start']), 'bis' => $eng->date($w['end']), 'tage' => $w['days']]
                + (isset($w['price']) ? ['preis' => $w['price'], 'vermutet' => (bool) $w['presumed'],
                                          'wochen' => (int) ($w['weeks'] ?? max(1, (int) round($w['days'] / 7)))] : []);
        };
        $this->writeTables($eng, $values, $rv, $today, $map);
        $this->put('Register', json_encode([
            'jahr'     => $year,
            'saison'   => [$this->ReadPropertyString('SeasonStart'), $this->ReadPropertyString('SeasonEnd')],
            'rechenweise' => $this->ReadPropertyString('Mode'),
            'stats'    => $st,
            'umsatz'   => ['brutto' => $rv['current'], 'nachProvision' => round($rv['current'] * $comm, 2),
                           'netto' => round($rv['current'] * $comm * $tax, 2), 'potenzial' => $rv['potential'], 'maximum' => $rv['max']],
            'buchungen' => array_map($fmt, $rv['bookings']),
            'frei'      => array_map($fmt, $rv['free']),
            'tage'      => $days,
            'stand'     => time(),
        ], JSON_UNESCAPED_UNICODE));
        return true;
    }

    /** Rechenkern mit den Einstellungen der Instanz. $fromDay: freie Wochen erst ab diesem Tag. */
    private function engine(array $values, int $year, int $fromDay): OccupancyEngine
    {
        return new OccupancyEngine($values, $year, $this->ReadPropertyString('SeasonStart'),
            $this->ReadPropertyString('SeasonEnd'), $this->prices(), [
                'vacant_max'   => $this->ReadPropertyFloat('VacantMax'),
                'service_max'  => $this->ReadPropertyFloat('ServiceMax'),
                'occupied_min' => $this->ReadPropertyFloat('OccupiedMin'),
            ], [
                'mode'    => $this->ReadPropertyString('Mode'),
                'gapMax'  => $this->ReadPropertyInteger('GapMax'),
                'minStay' => $this->ReadPropertyInteger('MinStay'),
                'fromDay' => $fromDay,
            ]);
    }

    /**
     * Tabellen fuer die Anzeige. Kalender: je Saisonmonat eine Zeile, Spalte 2 die Tage als
     * Zustaende mit Semikolon (ok = belegt, warn = An-/Abreise, fehler = frei, leer = ausser
     * Saison), "*" vorn markiert heute, ":" leitet den Tooltip ein.
     */
    private function writeTables(OccupancyEngine $eng, array $values, array $rv, int $today, array $map): void
    {
        $year = (int) date('Y');
        $this->put('CalendarTable', json_encode($this->calendarRows($eng, $values, $year, $today, $map), JSON_UNESCAPED_UNICODE));
        $prevVals = $this->dayValues($year - 1);
        $prev = $this->engine($prevVals, $year - 1, 0);
        $this->put('CalendarPrevTable', json_encode($this->calendarRows($prev, $prevVals, $year - 1, -1, $map), JSON_UNESCAPED_UNICODE));

        $dm = function (int $i) use ($eng) { return date('j.n.', mktime(0, 0, 0, 1, 1 + $i, $year = (int) substr($eng->date(0), 0, 4))); };
        $eur = function (float $x) { return number_format($x, 0, ',', '.') . ' €'; };
        $b = [['Zeitraum', 'Tage', 'Wo.', 'Preis', 'Art']];
        foreach ($rv['bookings'] as $w) {
            $b[] = [$dm($w['start']) . ' – ' . $dm($w['end']), $w['days'], (int) ($w['weeks'] ?? max(1, (int) round($w['days'] / 7))),
                    $eur((float) $w['price']), $w['presumed'] ? 'vermutet' : 'erkannt'];
        }
        $this->put('BookingsTable', json_encode($b, JSON_UNESCAPED_UNICODE));
        $f = [['Zeitraum', 'Tage']];
        foreach ($rv['free'] as $w) {
            $f[] = [$dm($w['start']) . ' – ' . $dm($w['end']), $w['days']];
        }
        $this->put('FreeTable', json_encode($f, JSON_UNESCAPED_UNICODE));

        // Kurzfassung fuer den Seitenkopf
        $st = $map[$eng->status($today)];
        $txt = [self::ST_OFF => 'außer Saison', self::ST_FREE => 'frei', self::ST_SERVICE => 'An-/Abreise', self::ST_OCCUPIED => 'belegt'][$st];
        $last = null; $cur = null;
        foreach ($rv['bookings'] as $w) {
            if ($w['end'] < $today) { $last = $w; }
            if ($w['start'] <= $today && $w['end'] >= $today) { $cur = $w; }
        }
        if ($cur !== null) {
            $txt .= ' · seit ' . $dm($cur['start']);
        } elseif ($last !== null) {
            $txt .= ' · letzte Abreise ' . $dm($last['end']);
        }
        $this->put('Summary', $txt);
    }

    private function calendarRows(OccupancyEngine $eng, array $values, int $year, int $today, array $map): array
    {
        $tok = [self::ST_OFF => '', self::ST_FREE => 'fehler', self::ST_SERVICE => 'warn', self::ST_OCCUPIED => 'ok'];
        $name = [self::ST_OFF => 'außer Saison', self::ST_FREE => 'frei', self::ST_SERVICE => 'An-/Abreise', self::ST_OCCUPIED => 'belegt'];
        $monate = ['', 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
        $rows = [['Monat', 'Tage', 'Zustände', 'Auslastung']];
        for ($m = 1; $m <= 12; $m++) {
            $n = (int) date('t', mktime(0, 0, 0, $m, 1, $year));
            $cells = []; $inSeason = 0; $busy = 0;
            for ($d = 1; $d <= $n; $d++) {
                $i = (int) date('z', mktime(0, 0, 0, $m, $d, $year));
                $s = $map[$eng->status($i)];
                if ($s !== self::ST_OFF) { $inSeason++; }
                if ($s === self::ST_SERVICE || $s === self::ST_OCCUPIED) { $busy++; }
                $cells[] = ($i === $today ? '*' : '') . $tok[$s] . ':' . $this->tooltip($eng, $i, $year, $d . '.' . $m . '. ' . $name[$s]);
            }
            if ($inSeason === 0) {
                continue;   // nur Monate mit Saisontagen
            }
            $rows[] = [$monate[$m], $n . ' Tage', implode(';', $cells), round($busy / $inSeason * 100) . ' %'];
        }
        return $rows;
    }

    /**
     * Tooltip einer Kalenderzelle: Status, zugehoerige Buchung, Grund der Einstufung und die
     * einzelnen Messbeitraege (WLAN-Gaeste, Klima-Schaltvorgaenge, Korrektur). Zeilen mit
     * Zeilenumbruch; ohne Semikolon, das trennt im Kalender die Zellen.
     */
    private function tooltip(OccupancyEngine $eng, int $i, int $year, string $kopf): string
    {
        $r = $eng->reason($i);
        $z = [$kopf];
        if ($r['buchung'] !== null) {
            [$a, $b, $p] = $r['buchung'];
            $dm = function (int $k) use ($year) { return date('j.n.', mktime(0, 0, 0, 1, 1 + $k, $year)); };
            $z[] = 'Buchung ' . $dm($a) . ' – ' . $dm($b) . ($p ? ' (vermutet)' : ' (erkannt)');
        }
        if ($r['grund'] !== 'außer Saison') {
            $z[] = 'Grund: ' . $r['grund'];
            foreach ($this->detail[$year][$i] ?? [] as $zeile) { $z[] = '· ' . $zeile; }
            if (empty($this->detail[$year][$i])) { $z[] = '· keine Messwerte an diesem Tag'; }
        }
        return str_replace(';', ',', implode("\n", $z));
    }

    /** Status heute als Zahl: 0 ausser Saison, 1 frei, 2 An-/Abreise, 3 belegt. */
    public function GetTodayStatus(): int
    {
        return (int) $this->GetValue('TodayStatus');
    }

    public function GetRegister(): string
    {
        return (string) $this->GetValue('Register');
    }

    public function GetConfigurationForm()
    {
        $spalte = function (string $cap, string $name, string $w, $add, string $type = 'ValidationTextBox') {
            return ['caption' => $cap, 'name' => $name, 'width' => $w, 'add' => $add, 'edit' => ['type' => $type]];
        };
        return json_encode([
            'elements' => [
                ['type' => 'Label', 'caption' => 'Belegung und Umsatz einer Ferienwohnung aus Gaestenetz und Aktivitaet. Die Instanz unter den Standort haengen.'],
                ['type' => 'SelectInstance', 'name' => 'PresenceId', 'caption' => 'Anwesenheit des Standorts (HSPR)'],
                ['type' => 'SelectVariable', 'name' => 'LegacyGuestVid', 'caption' => 'Vorgeschichte: aeltere archivierte Geraetezahl (optional)'],
                ['type' => 'List', 'name' => 'Counters', 'caption' => 'Aktivitaetszaehler (archiviert, Tagesmittel wird addiert)',
                 'add' => true, 'delete' => true, 'rowCount' => 3,
                 'columns' => [$spalte('Variable', 'Vid', 'auto', 0, 'SelectVariable')]],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'ValidationTextBox', 'name' => 'SeasonStart', 'caption' => 'Saison von (T.M.)'],
                    ['type' => 'ValidationTextBox', 'name' => 'SeasonEnd', 'caption' => 'bis'],
                ]],
                ['type' => 'List', 'name' => 'Prices', 'caption' => 'Wochenpreise', 'add' => true, 'delete' => true, 'rowCount' => 8,
                 'columns' => [$spalte('von (T.M.)', 'Von', '120px', ''), $spalte('bis (T.M.)', 'Bis', '120px', ''),
                               $spalte('Preis je Woche', 'Preis', 'auto', 0, 'NumberSpinner')]],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'NumberSpinner', 'name' => 'VacantMax', 'caption' => 'frei bis', 'digits' => 1],
                    ['type' => 'NumberSpinner', 'name' => 'ServiceMax', 'caption' => 'An-/Abreise bis', 'digits' => 1],
                    ['type' => 'NumberSpinner', 'name' => 'OccupiedMin', 'caption' => 'belegt ab (ueber)', 'digits' => 1],
                ]],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'NumberSpinner', 'name' => 'CommissionPct', 'caption' => 'Provision', 'suffix' => '%', 'digits' => 1],
                    ['type' => 'NumberSpinner', 'name' => 'TaxPct', 'caption' => 'Steuer', 'suffix' => '%', 'digits' => 1],
                ]],
                ['type' => 'List', 'name' => 'Overrides', 'caption' => 'Tageswerte korrigieren', 'add' => true, 'delete' => true, 'rowCount' => 4,
                 'columns' => [$spalte('Datum (T.M.JJJJ)', 'Datum', '160px', ''), $spalte('Wert', 'Wert', 'auto', 0, 'NumberSpinner')]],
                ['type' => 'Select', 'name' => 'Mode', 'caption' => 'Rechenweise', 'options' => [
                    ['caption' => 'Aufenthalte (empfohlen)', 'value' => 'v2'],
                    ['caption' => 'wie das fruehere Skript', 'value' => 'legacy'],
                ]],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'NumberSpinner', 'name' => 'GapMax', 'caption' => 'Luecke ueberbruecken bis', 'suffix' => 'Tage'],
                    ['type' => 'NumberSpinner', 'name' => 'MinStay', 'caption' => 'Buchung ab', 'suffix' => 'Tage'],
                ]],
                ['type' => 'NumberSpinner', 'name' => 'IntervalMinutes', 'caption' => 'Neu rechnen alle', 'suffix' => 'min'],
            ],
            'actions' => [['type' => 'Button', 'caption' => 'Jetzt rechnen', 'onClick' => 'HSRT_Update($id);']],
        ]);
    }

    // ---------------------------------------------------------------- intern

    /** Tageswert je Tagesindex: Mittel der Gaeste-Geraete + Mittel der Aktivitaetszaehler. */
    /** Je Tag die einzelnen Beitraege zum Tageswert, fuer den Tooltip: [Jahr][Tag] => [Zeilen]. */
    private array $detail = [];

    private function dayValues(int $year): array
    {
        $det = [];
        $zahl = function ($x) { return number_format((float) $x, 1, ',', ''); };
        $arch = $this->archiveId();
        $from = mktime(0, 0, 0, 1, 1, $year);
        $to = min(time(), mktime(23, 59, 59, 12, 31, $year));
        $guest = [];
        if ($arch > 0) {
            $legacy = $this->ReadPropertyInteger('LegacyGuestVid');
            if ($legacy > 0 && @IPS_VariableExists($legacy)) {
                foreach ($this->dailyAgg($arch, $legacy, $from, $to) as $i => [$v, $mx]) {
                    $guest[$i] = $v;
                    $det[$i]['gast'] = 'WLAN-Gäste (Vorgeschichte): Ø ' . $zahl($v) . ' · max ' . (int) round($mx) . ' Geräte';
                }
            }
            // eigene Werte haben Vorrang, wo es sie gibt
            foreach ($this->dailyAgg($arch, $this->GetIDForIdent('GuestDevices'), $from, $to) as $i => [$v, $mx]) {
                $guest[$i] = $v;
                $det[$i]['gast'] = 'WLAN-Gäste: Ø ' . $zahl($v) . ' · max ' . (int) round($mx) . ' Geräte';
            }
        }
        $values = $guest;
        foreach ($this->counters() as $vid) {
            // Name mit Ordner davor ("Toshiba Klimaanlagen · Zustandsaenderungen") - der Zaehler
            // allein heisst oft nur "Zustandsaenderungen". Ein Zaehler-Tageswert ist eine Summe,
            // ein Maximum dazu waere sinnlos.
            $name = @IPS_ObjectExists($vid) ? IPS_GetName($vid) : ('#' . $vid);
            $ord = @IPS_ObjectExists($vid) ? (int) IPS_GetParent($vid) : 0;
            if ($ord > 0) { $name = IPS_GetName($ord) . ' · ' . $name; }
            foreach ($arch > 0 ? $this->dailyAgg($arch, $vid, $from, $to) : [] as $i => [$v, $mx]) {
                $values[$i] = round(($values[$i] ?? 0) + $v, 1);
                if ($v > 0) {
                    $det[$i]['z' . $vid] = $name . ': ' . (abs($v - round($v)) < 0.05 ? (int) round($v) : $zahl($v));
                }
            }
        }
        foreach (json_decode($this->ReadPropertyString('Overrides'), true) ?: [] as $o) {
            $ts = $this->parseDate((string) ($o['Datum'] ?? ''));
            if ($ts !== null && (int) date('Y', $ts) === $year) {
                $i = (int) date('z', $ts);
                $values[$i] = (float) ($o['Wert'] ?? 0);
                $det[$i] = ['hand' => 'von Hand korrigiert auf ' . $zahl($values[$i]) . ' (Messwerte ersetzt)'];
            }
        }
        $this->detail[$year] = array_map('array_values', $det);
        return $values;
    }

    /** Tagesmittel und -maximum aus dem Archiv, indiziert nach Tag des Jahres: [Tag => [avg, max]]. */
    private function dailyAgg(int $arch, int $vid, int $from, int $to): array
    {
        if (!@AC_GetLoggingStatus($arch, $vid)) {
            return [];
        }
        $rows = @AC_GetAggregatedValues($arch, $vid, 1, $from, $to, 0);
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $r) {
            $out[(int) date('z', (int) $r['TimeStamp'])] = [(float) $r['Avg'], (float) $r['Max']];
        }
        return $out;
    }

    /** Tagesmittel aus dem Archiv, indiziert nach Tag des Jahres. */
    private function dailyAvg(int $arch, int $vid, int $from, int $to): array
    {
        if (!@AC_GetLoggingStatus($arch, $vid)) {
            return [];
        }
        $rows = @AC_GetAggregatedValues($arch, $vid, 1, $from, $to, 0);
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $r) {
            $out[(int) date('z', (int) $r['TimeStamp'])] = (float) $r['Avg'];
        }
        return $out;
    }

    private function counters(): array
    {
        $out = [];
        foreach (json_decode($this->ReadPropertyString('Counters'), true) ?: [] as $r) {
            $v = (int) ($r['Vid'] ?? 0);
            if ($v > 0 && @IPS_VariableExists($v)) {
                $out[] = $v;
            }
        }
        return $out;
    }

    private function prices(): array
    {
        $out = [];
        foreach (json_decode($this->ReadPropertyString('Prices'), true) ?: [] as $r) {
            $out[] = ['start' => (string) ($r['Von'] ?? ''), 'end' => (string) ($r['Bis'] ?? ''), 'price_per_week' => (float) ($r['Preis'] ?? 0)];
        }
        return $out;
    }

    private function parseDate(string $s): ?int
    {
        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', trim($s), $m) && checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            return mktime(12, 0, 0, (int) $m[2], (int) $m[1], (int) $m[3]);
        }
        return null;
    }

    private function archiveId(): int
    {
        $l = @IPS_GetInstanceListByModuleID(self::ARCHIVE_GUID);
        return is_array($l) && $l ? (int) $l[0] : 0;
    }

    /** Die Geraetezahl archivieren - NUR einschalten, nie abschalten (Abschalten loescht die Historie). */
    private function ensureLogging(): void
    {
        $arch = $this->archiveId();
        $vid = @$this->GetIDForIdent('GuestDevices');
        if ($arch <= 0 || !$vid || @AC_GetLoggingStatus($arch, $vid)) {
            return;
        }
        @AC_SetLoggingStatus($arch, $vid, true);
        @IPS_ApplyChanges($arch);
    }

    private function ensureProfiles(): void
    {
        if (!IPS_VariableProfileExists('HSRT.Status')) {
            IPS_CreateVariableProfile('HSRT.Status', 1);
        }
        IPS_SetVariableProfileValues('HSRT.Status', 0, 3, 1);
        IPS_SetVariableProfileAssociation('HSRT.Status', self::ST_OFF, 'Außer Saison', '', 0x7F8C8D);
        IPS_SetVariableProfileAssociation('HSRT.Status', self::ST_FREE, 'Frei', '', 0xDC5244);
        IPS_SetVariableProfileAssociation('HSRT.Status', self::ST_SERVICE, 'An-/Abreise', '', 0xFFB45C);
        IPS_SetVariableProfileAssociation('HSRT.Status', self::ST_OCCUPIED, 'Belegt', '', 0x00CDAB);
        if (!IPS_VariableProfileExists('HSRT.Euro')) {
            IPS_CreateVariableProfile('HSRT.Euro', 2);
            IPS_SetVariableProfileDigits('HSRT.Euro', 0);
            IPS_SetVariableProfileText('HSRT.Euro', '', ' €');
        }
        if (!IPS_VariableProfileExists('HSRT.Percent')) {
            IPS_CreateVariableProfile('HSRT.Percent', 2);
            IPS_SetVariableProfileDigits('HSRT.Percent', 1);
            IPS_SetVariableProfileText('HSRT.Percent', '', ' %');
        }
    }

    private function put(string $ident, $value): void
    {
        $vid = @$this->GetIDForIdent($ident);
        if ($vid && GetValue($vid) !== $value) {
            SetValue($vid, $value);
        }
    }
}

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
        $this->ensureLogging();
        $this->SetTimerInterval('Sample', 60 * 1000);
        $min = max(0, $this->ReadPropertyInteger('IntervalMinutes'));
        $this->SetTimerInterval('Update', $min > 0 ? max(5, $min) * 60 * 1000 : 0);
        $this->SetStatus($this->ReadPropertyInteger('PresenceId') > 0 || $this->ReadPropertyInteger('LegacyGuestVid') > 0 ? 102 : 104);
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
        $eng = new OccupancyEngine($values, $year, $this->ReadPropertyString('SeasonStart'),
            $this->ReadPropertyString('SeasonEnd'), $this->prices(), [
                'vacant_max'   => $this->ReadPropertyFloat('VacantMax'),
                'service_max'  => $this->ReadPropertyFloat('ServiceMax'),
                'occupied_min' => $this->ReadPropertyFloat('OccupiedMin'),
            ]);
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
                + (isset($w['price']) ? ['preis' => $w['price'], 'vermutet' => (bool) $w['presumed']] : []);
        };
        $this->put('Register', json_encode([
            'jahr'     => $year,
            'saison'   => [$this->ReadPropertyString('SeasonStart'), $this->ReadPropertyString('SeasonEnd')],
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
                ['type' => 'NumberSpinner', 'name' => 'IntervalMinutes', 'caption' => 'Neu rechnen alle', 'suffix' => 'min'],
            ],
            'actions' => [['type' => 'Button', 'caption' => 'Jetzt rechnen', 'onClick' => 'HSRT_Update($id);']],
        ]);
    }

    // ---------------------------------------------------------------- intern

    /** Tageswert je Tagesindex: Mittel der Gaeste-Geraete + Mittel der Aktivitaetszaehler. */
    private function dayValues(int $year): array
    {
        $arch = $this->archiveId();
        $from = mktime(0, 0, 0, 1, 1, $year);
        $to = min(time(), mktime(23, 59, 59, 12, 31, $year));
        $guest = [];
        if ($arch > 0) {
            $legacy = $this->ReadPropertyInteger('LegacyGuestVid');
            if ($legacy > 0 && @IPS_VariableExists($legacy)) {
                foreach ($this->dailyAvg($arch, $legacy, $from, $to) as $i => $v) {
                    $guest[$i] = $v;
                }
            }
            // eigene Werte haben Vorrang, wo es sie gibt
            foreach ($this->dailyAvg($arch, $this->GetIDForIdent('GuestDevices'), $from, $to) as $i => $v) {
                $guest[$i] = $v;
            }
        }
        $values = $guest;
        foreach ($this->counters() as $vid) {
            foreach ($arch > 0 ? $this->dailyAvg($arch, $vid, $from, $to) : [] as $i => $v) {
                $values[$i] = round(($values[$i] ?? 0) + $v, 1);
            }
        }
        foreach (json_decode($this->ReadPropertyString('Overrides'), true) ?: [] as $o) {
            $ts = $this->parseDate((string) ($o['Datum'] ?? ''));
            if ($ts !== null && (int) date('Y', $ts) === $year) {
                $values[(int) date('z', $ts)] = (float) ($o['Wert'] ?? 0);
            }
        }
        return $values;
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

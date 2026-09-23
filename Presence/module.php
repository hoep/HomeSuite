<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/HomeSuite/autoload.php';

use Hoep\HomeSuite\Engines\PresenceEngine;

/**
 * Presence (HSPR) — Anwesenheit und Belegung EINES Standorts.
 *
 * Eine Instanz je Standort, eingehaengt unter dessen HSSP-Instanz (Kind = Haus). Sie
 * beantwortet zwei Fragen:
 *   1. Welche Bewohner sind da?      (Personen mit ihren Geraeten)
 *   2. Sind Gaeste da?               (fremde Telefone im Hauptnetz, Geraete im Gaeste-WLAN)
 * und leitet daraus die Belegung ab: leer, Bewohner, Gaeste, beides, unbekannt.
 *
 * Quelle ist die Host-Liste des Routers: je Geraet eine boolesche Online-Variable, deren
 * Ident die MAC ohne Trenner ist (so legt sie das FritzBox-Projekt an). Jede Person darf
 * zusaetzlich eine fertige Anwesenheitsvariable aus einer anderen Quelle mitbringen
 * (z. B. einen WLAN-Controller) — beides wird ODER-verknuepft.
 *
 * Die Entscheidung selbst steckt in Engines/PresenceEngine (ohne Symcon testbar); dieses
 * Modul liest nur die Variablen ein, fuehrt den Vorzustand und schreibt das Ergebnis.
 */
class Presence extends IPSModule
{
    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger('HostsCategory', 0);   // Ordner mit den Host-Variablen
        $this->RegisterPropertyInteger('GuestCountVid', 0);   // Anzahl Geraete im Gaeste-WLAN (optional)
        $this->RegisterPropertyString('Persons', '[]');       // [{Name, Names, Macs, ExtVid}]
        $this->RegisterPropertyInteger('HoldMinutes', 20);    // Funkpausen ueberbruecken
        $this->RegisterPropertyInteger('StaleMinutes', 30);   // Router gilt danach als stumm
        $this->RegisterPropertyInteger('IntervalSeconds', 60);
        $this->RegisterPropertyBoolean('DetectGuests', true);
        $this->RegisterPropertyString('GuestPattern', PresenceEngine::DEFAULT_GUEST_PATTERN);
        $this->RegisterPropertyString('IgnoreNames', '');     // Komma-Liste: nie als Gast zaehlen
        $this->RegisterPropertyString('ResidentNames', '');   // wer HIER wohnt (Komma); leer = Ferienhaus

        $this->RegisterAttributeString('State', '{}');

        $this->ensureProfile();
        $this->RegisterVariableInteger('Occupancy', 'Belegung', 'HSPR.Belegung', 10);
        $this->RegisterVariableBoolean('Anyone', 'Jemand da', '~Presence', 20);
        $this->RegisterVariableString('Residents', 'Anwesend', '', 30);
        $this->RegisterVariableInteger('Guests', 'Gäste-Geräte', '', 40);
        $this->RegisterVariableString('GuestList', 'Gäste-Geräte (Namen)', '', 50);
        $this->RegisterVariableBoolean('Fresh', 'Router meldet', '', 60);
        $this->RegisterVariableString('Register', 'Register (JSON)', '', 90);

        $this->RegisterTimer('Update', 0, 'HSPR_Update($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->ensureProfile();
        $this->ensurePersonVariables();
        $sec = max(0, $this->ReadPropertyInteger('IntervalSeconds'));
        $this->SetTimerInterval('Update', $sec > 0 ? max(15, $sec) * 1000 : 0);
        if ($this->ReadPropertyInteger('HostsCategory') > 0 || $this->personsHaveExt()) {
            $this->SetStatus(102);
        } else {
            $this->SetStatus(104);
        }
    }

    /** Ein Durchlauf: Hosts lesen, bewerten, Variablen schreiben. */
    public function Update(): bool
    {
        $persons = $this->persons();
        $hosts = $this->readHosts($this->ReadPropertyInteger('HostsCategory'));

        // GuestCountVid: ein ORDNER = Host-Variablen des Gaestenetzes (jedes Geraet einzeln,
        // Haustechnik wird ausgefiltert); eine VARIABLE = nur deren Anzahl (Altform).
        $guestWlan = 0;
        $guestHosts = [];
        $gv = $this->ReadPropertyInteger('GuestCountVid');
        if ($gv > 0 && @IPS_CategoryExists($gv)) {
            $guestHosts = $this->readHosts($gv);
        } elseif ($gv > 0 && @IPS_VariableExists($gv)) {
            $guestWlan = max(0, (int) GetValue($gv));
        }

        $engPersons = [];
        foreach ($persons as $p) {
            $ext = null;
            if ($p['ExtVid'] > 0 && @IPS_VariableExists($p['ExtVid'])) {
                $ext = (bool) GetValue($p['ExtVid']);
            }
            $engPersons[] = ['name' => $p['Name'], 'names' => $p['Names'], 'macs' => $p['Macs'], 'ext' => $ext];
        }

        $state = json_decode($this->ReadAttributeString('State'), true);
        $res = PresenceEngine::evaluate($hosts, $engPersons, is_array($state) ? $state : [], [
            'now'          => time(),
            'holdSec'      => max(0, $this->ReadPropertyInteger('HoldMinutes')) * 60,
            'staleSec'     => max(1, $this->ReadPropertyInteger('StaleMinutes')) * 60,
            'guestPattern' => $this->ReadPropertyBoolean('DetectGuests')
                ? $this->ReadPropertyString('GuestPattern') : '/(?!)/',
            'ignore'       => $this->csv($this->ReadPropertyString('IgnoreNames')),
            'guestWlan'    => $this->ReadPropertyBoolean('DetectGuests') ? $guestWlan : 0,
            'guestHosts'   => $this->ReadPropertyBoolean('DetectGuests') ? $guestHosts : [],
            'residents'    => $this->csv($this->ReadPropertyString('ResidentNames')),
        ]);
        $this->WriteAttributeString('State', json_encode($res['state']));

        $this->setIfChanged('Occupancy', (int) $res['occupancy']);
        $this->setIfChanged('Anyone', in_array($res['occupancy'], [1, 2, 3, 5], true));
        $this->setIfChanged('Residents', implode(', ', $res['present']));
        $this->setIfChanged('Guests', (int) $res['guests']);
        $this->setIfChanged('GuestList', implode(', ', $res['guestNames']));
        $this->setIfChanged('Fresh', (bool) $res['fresh']);
        foreach ($res['persons'] as $name => $here) {
            $vid = @$this->GetIDForIdent($this->personIdent($name));
            if ($vid) {
                $this->setVid((int) $vid, (bool) $here);
            }
        }

        $seen = $res['state']['seen'] ?? [];
        $reg = [
            'occupancy' => $res['occupancy'],
            'fresh'     => $res['fresh'],
            'hosts'     => count($hosts),
            'online'    => count(array_filter(array_column($hosts, 'online'))),
            'persons'   => array_map(function ($n) use ($res, $seen) {
                return ['name' => $n, 'present' => (bool) $res['persons'][$n], 'lastSeen' => (int) ($seen[$n] ?? 0)];
            }, array_keys($res['persons'])),
            'guests'    => $res['guestNames'],
            'guestWlan' => $guestWlan,
            'guestNetOnline' => count(array_filter(array_column($guestHosts, 'online'))),
            'updated'   => time(),
        ];
        $this->setIfChanged('Register', json_encode($reg, JSON_UNESCAPED_UNICODE));
        return true;
    }

    /** Oeffentliche Abfrage fuer Skripte: ist diese Person gerade da? */
    public function IsPresent(string $Person): bool
    {
        $vid = @$this->GetIDForIdent($this->personIdent($Person));
        return $vid ? (bool) GetValue((int) $vid) : false;
    }

    /** Belegung als Zahl (0 leer, 1 Bewohner, 2 Gaeste, 3 Bewohner/Familie und Gaeste, 4 unbekannt, 5 Familie). */
    public function GetOccupancy(): int
    {
        return (int) $this->GetValue('Occupancy');
    }

    /**
     * Kandidaten fuer die Personen-Zuordnung: alle Hosts, deren Name nach Telefon/Uhr
     * aussieht, mit Online-Stand. Hilft beim Einrichten, ohne den Router-Baum zu durchsuchen.
     */
    public function ListCandidates(): string
    {
        $out = [];
        foreach ($this->readHosts($this->ReadPropertyInteger('HostsCategory')) as $h) {
            $n = PresenceEngine::hostName($h['name']);
            if (@preg_match($this->ReadPropertyString('GuestPattern'), $n) === 1) {
                $out[] = ['name' => $n, 'mac' => $h['mac'], 'online' => $h['online'], 'updated' => $h['updated']];
            }
        }
        usort($out, function ($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });
        return json_encode($out, JSON_UNESCAPED_UNICODE);
    }

    public function GetConfigurationForm()
    {
        return json_encode([
            'elements' => [
                ['type' => 'Label', 'caption' =>
                    'Anwesenheit eines Standorts. Die Instanz unter den Standort (HSSP, Ebene Haus) haengen.'],
                ['type' => 'SelectCategory', 'name' => 'HostsCategory',
                 'caption' => 'Ordner mit den Host-Variablen des Routers (Ident = MAC)'],
                ['type' => 'ValidationTextBox', 'name' => 'ResidentNames',
                 'caption' => 'Bewohner dieses Standorts (Namen aus der Liste, Komma; leer = Ferienhaus: alle sind Familie)'],
                ['type' => 'SelectObject', 'name' => 'GuestCountVid',
                 'caption' => 'Gaestenetz: Ordner mit dessen Host-Variablen (oder Anzahl-Variable)'],
                ['type' => 'List', 'name' => 'Persons', 'caption' => 'Bewohner',
                 'add' => true, 'delete' => true, 'rowCount' => 6,
                 'columns' => [
                     ['caption' => 'Name', 'name' => 'Name', 'width' => '120px', 'add' => '',
                      'edit' => ['type' => 'ValidationTextBox']],
                     ['caption' => 'Geraetenamen (Komma, * erlaubt)', 'name' => 'Names', 'width' => 'auto', 'add' => '',
                      'edit' => ['type' => 'ValidationTextBox']],
                     ['caption' => 'MAC-Adressen (Komma)', 'name' => 'Macs', 'width' => '220px', 'add' => '',
                      'edit' => ['type' => 'ValidationTextBox']],
                     ['caption' => 'Weitere Quelle', 'name' => 'ExtVid', 'width' => '160px', 'add' => 0,
                      'edit' => ['type' => 'SelectVariable']],
                 ]],
                ['type' => 'NumberSpinner', 'name' => 'HoldMinutes', 'caption' => 'Haltezeit', 'suffix' => 'min'],
                ['type' => 'NumberSpinner', 'name' => 'StaleMinutes', 'caption' => 'Router gilt als stumm nach', 'suffix' => 'min'],
                ['type' => 'NumberSpinner', 'name' => 'IntervalSeconds', 'caption' => 'Takt', 'suffix' => 's'],
                ['type' => 'CheckBox', 'name' => 'DetectGuests', 'caption' => 'Gaeste erkennen'],
                ['type' => 'ValidationTextBox', 'name' => 'GuestPattern',
                 'caption' => 'Gaeste auch im Hauptnetz: Muster (Regex, leer = nur Gaestenetz)'],
                ['type' => 'ValidationTextBox', 'name' => 'IgnoreNames', 'caption' => 'Nie als Gast zaehlen (Komma)'],
            ],
            'actions' => [
                ['type' => 'Button', 'caption' => 'Jetzt auswerten', 'onClick' => 'HSPR_Update($id);'],
            ],
        ]);
    }

    // ---------------------------------------------------------------- intern

    private function ensureProfile(): void
    {
        if (!IPS_VariableProfileExists('HSPR.Belegung')) {
            IPS_CreateVariableProfile('HSPR.Belegung', 1);
        }
        IPS_SetVariableProfileValues('HSPR.Belegung', 0, 5, 1);
        IPS_SetVariableProfileAssociation('HSPR.Belegung', 0, 'Leer', '', 0x7F8C8D);
        IPS_SetVariableProfileAssociation('HSPR.Belegung', 1, 'Bewohner', '', 0x00CDAB);
        IPS_SetVariableProfileAssociation('HSPR.Belegung', 2, 'Gäste', '', 0x5AA9FF);
        IPS_SetVariableProfileAssociation('HSPR.Belegung', 3, 'Bewohner und Gäste', '', 0x9B7BFF);
        IPS_SetVariableProfileAssociation('HSPR.Belegung', 4, 'Unbekannt', '', 0xFFB45C);
        IPS_SetVariableProfileAssociation('HSPR.Belegung', 5, 'Familie', '', 0x7FD4C1);
    }

    /** Je Person eine Variable "Name da"; verwaiste bleiben stehen (Archiv), werden aber nicht mehr geschrieben. */
    private function ensurePersonVariables(): void
    {
        $pos = 100;
        foreach ($this->persons() as $p) {
            $this->RegisterVariableBoolean($this->personIdent($p['Name']), $p['Name'] . ' da', '~Presence', $pos++);
        }
    }

    private function personIdent(string $name): string
    {
        $slug = preg_replace('/[^A-Za-z0-9]/', '', iconv('UTF-8', 'ASCII//TRANSLIT', $name) ?: $name);
        return 'P_' . ($slug !== '' ? $slug : substr(md5($name), 0, 8));
    }

    /** @return array<int,array{Name:string,Names:array,Macs:array,ExtVid:int}> */
    private function persons(): array
    {
        $rows = json_decode($this->ReadPropertyString('Persons'), true);
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $r) {
            $n = trim((string) ($r['Name'] ?? ''));
            if ($n === '') {
                continue;
            }
            $out[] = [
                'Name'   => $n,
                'Names'  => $this->csv((string) ($r['Names'] ?? '')),
                'Macs'   => $this->csv((string) ($r['Macs'] ?? '')),
                'ExtVid' => (int) ($r['ExtVid'] ?? 0),
            ];
        }
        return $out;
    }

    private function personsHaveExt(): bool
    {
        foreach ($this->persons() as $p) {
            if ($p['ExtVid'] > 0) {
                return true;
            }
        }
        return false;
    }

    /** Host-Variablen: boolesch, Ident = 12 Hexzeichen (MAC). */
    private function readHosts(int $cat): array
    {
        if ($cat <= 0 || !@IPS_ObjectExists($cat)) {
            return [];
        }
        $out = [];
        foreach (IPS_GetChildrenIDs($cat) as $cid) {
            $obj = IPS_GetObject($cid);
            if ($obj['ObjectType'] !== 2 || !preg_match('/^[0-9A-Fa-f]{12}$/', (string) $obj['ObjectIdent'])) {
                continue;
            }
            $v = IPS_GetVariable($cid);
            if ($v['VariableType'] !== 0) {
                continue;
            }
            $out[] = [
                'name'    => (string) $obj['ObjectName'],
                'mac'     => (string) $obj['ObjectIdent'],
                'online'  => (bool) GetValue($cid),
                'updated' => (int) $v['VariableUpdated'],
            ];
        }
        return $out;
    }

    private function csv(string $s): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $s)), 'strlen'));
    }

    private function setIfChanged(string $ident, $value): void
    {
        $vid = @$this->GetIDForIdent($ident);
        if ($vid) {
            $this->setVid((int) $vid, $value);
        }
    }

    private function setVid(int $vid, $value): void
    {
        if (GetValue($vid) !== $value) {
            SetValue($vid, $value);
        }
    }
}

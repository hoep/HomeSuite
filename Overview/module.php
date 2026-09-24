<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/HomeSuite/autoload.php';

/**
 * Overview (HSOV) — die Lage aller Standorte auf einen Blick.
 *
 * Sammelt aus den HomeSuite-Modulen drei Dinge fuer die Startseite:
 *   1. Standorte: Belegung (Anwesenheit HSPR) und, wo vermietet, den Status (Rental HSRT).
 *   2. Hinweise: nur was abweicht und jemanden braucht - jeder mit seiner Loesung als
 *      Aktion (Rollo zurueck auf Automatik, Bewaesserung stoppen, Klima aus ...).
 *   3. Fahrplan: was vorhin geschah und was als Naechstes geplant ist (Rollofahrten,
 *      Bewaesserungsfenster, Planwechsel, Wochenplan-Ereignisse der Module).
 *
 * Das Modul schaltet selbst nichts von sich aus. Es fuehrt nur Aktionen aus, die jemand
 * auf einem Hinweis antippt, und zwar ueber die oeffentlichen Befehle der Fachmodule.
 *
 * Ausgabe: GetState() liefert alles als JSON (fuer ?api=mod&op=state), die Variable
 * "Lage (JSON)" dasselbe fuer Live-Updates, dazu Anzahl und Schwere der Hinweise fuer ein
 * Symbol in der Kopfleiste.
 */
class Overview extends IPSModule
{
    private const GUID_HSSP = '{5598F752-886D-475F-91CE-5813A3C581E5}';
    private const LIB_HS    = '{0F66F23F-ED50-4CD5-AB44-5FC961C7733A}';

    private const SEV_INFO = 1;
    private const SEV_WARN = 2;
    private const SEV_CRIT = 3;

    /** @var array<int,int> Cache Objekt -> Standort (HSSP Kind Haus) */
    private $siteCache = [];

    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyInteger('IntervalSeconds', 60);
        $this->RegisterPropertyFloat('RunawayFactor', 1.5);   // Bewaesserung: so viel laenger als geplant = Hinweis
        $this->RegisterPropertyBoolean('ShowHealth', true);
        $this->RegisterPropertyBoolean('ShowBattery', true);
        $this->RegisterPropertyInteger('PastHours', 3);
        $this->RegisterPropertyInteger('AheadHours', 30);   // ab Mitternacht: heute + morgen frueh
        // Alarmanlage: Variablen-IDs (Komma), die "scharf" anzeigen. Ist keine davon an,
        // tragen die Sicherheits-Eintraege im Fahrplan das Kennzeichen "unscharf".
        $this->RegisterPropertyString('AlarmArmedVars', '');

        $this->RegisterAttributeString('Dismissed', '{}');     // {hintId: bis-Zeitstempel}

        $this->ensureProfile();
        $this->RegisterVariableInteger('HintCount', 'Hinweise', '', 10);
        $this->RegisterVariableInteger('HintWorst', 'Dringlichkeit', 'HSOV.Severity', 20);
        $this->RegisterVariableString('State', 'Lage (JSON)', '', 90);

        $this->RegisterTimer('Update', 0, 'HSOV_Update($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->ensureProfile();
        // Tagesprotokoll der tatsaechlich ausgefuehrten Aktionen. Als Variable, nicht als
        // Attribut: nachtraeglich eingefuehrte Attribute fehlen laufenden Instanzen bis zum
        // naechsten Neustart.
        $this->RegisterVariableString('EventLog', 'Protokoll heute (JSON)', '', 95);
        @IPS_SetHidden((int) $this->GetIDForIdent('EventLog'), true);
        $this->watch();
        $this->SetTimerInterval('Update', max(15, $this->ReadPropertyInteger('IntervalSeconds')) * 1000);
        $this->SetStatus(102);
    }

    // ================================================================= oeffentlich

    public function Update(): bool
    {
        $now = time();
        if ((int) date('i') % 10 === 0) { $this->watch(); }
        $byMod = $this->instancesByModule();
        $hints = [];
        foreach ([$this->hintsShading($byMod), $this->hintsIrrigation($byMod), $this->hintsClimate($byMod),
                  $this->hintsPresence($byMod), $this->hintsHealth($byMod), $this->hintsBattery($byMod),
                  $this->hintsWarnings($byMod)] as $part) {
            $hints = array_merge($hints, $part);
        }
        // weggeklickte Hinweise ausblenden; Eintraege fuer verschwundene Hinweise aufraeumen
        $dis = json_decode($this->ReadAttributeString('Dismissed'), true) ?: [];
        $ids = array_column($hints, 'id');
        $dis = array_filter($dis, function ($until, $id) use ($now, $ids) { return $until > $now && in_array($id, $ids, true); }, ARRAY_FILTER_USE_BOTH);
        $this->WriteAttributeString('Dismissed', json_encode($dis));
        $hints = array_values(array_filter($hints, function ($h) use ($dis) { return !isset($dis[$h['id']]); }));
        usort($hints, function ($a, $b) { return [$b['sev'], $b['since']] <=> [$a['sev'], $a['since']]; });

        $sites = $this->sites($byMod, $hints);
        $timeline = $this->timeline($byMod, $now);

        $worst = 0;
        foreach ($hints as $h) { $worst = max($worst, $h['sev']); }
        $state = ['ok' => true, 'ts' => $now, 'sites' => $sites, 'hints' => $hints, 'timeline' => $timeline,
                  'counts' => ['hints' => count($hints), 'worst' => $worst, 'crit' => count(array_filter($hints, function ($h) { return $h['sev'] === self::SEV_CRIT; }))]];
        $this->put('HintCount', count($hints));
        $this->put('HintWorst', $worst);
        $this->put('State', json_encode($state, JSON_UNESCAPED_UNICODE));
        return true;
    }

    /** Welche Variablen beobachtet werden: [ident => [Bereich, Art]] je Modul. */
    private const WATCH = [
        'ShadingDevice'     => ['Position' => ['Beschattung', 'pos']],
        'LightDevice'       => ['Power' => ['Licht', 'onoff']],
        'AudioZone'         => ['PlayState' => ['Musik', 'play']],
        'AudioZoneBridged'  => ['PlayState' => ['Musik', 'play']],
        'IrrigationCircuit' => ['Running' => ['Bewässerung', 'run']],
        'ClimateZone'       => ['Power' => ['Klima', 'onoff']],
        'Presence'          => ['Residents' => ['Anwesenheit', 'people']],
        // Wetter je Standort (fremde Bibliothek SymconWeatherStation): der Standort ergibt
        // sich wie ueberall aus dem Platz im Baum.
        'BlitzortungListener' => ['Active' => ['Wetter', 'storm']],
        'WeatherStation'      => ['PrecipType' => ['Wetter', 'precip']],
    ];

    private const NIEDERSCHLAG = [1 => 'Regen', 2 => 'Schneeregen', 3 => 'Schnee'];

    /** Variablen der Fachmodule fuer das Tagesprotokoll anmelden (idempotent). */
    private function watch(): void
    {
        $want = [];
        foreach ($this->instancesByModule() as $mod => $ids) {
            if (!isset(self::WATCH[$mod])) { continue; }
            foreach ($ids as $iid) {
                foreach (self::WATCH[$mod] as $ident => $_) {
                    $v = @IPS_GetObjectIDByIdent($ident, $iid);
                    if ($v) { $want[(int) $v] = true; }
                }
            }
        }
        $have = [];
        foreach ($this->GetMessageList() as $sender => $msgs) {
            if (in_array(VM_UPDATE, (array) $msgs, true)) { $have[(int) $sender] = true; }
        }
        foreach (array_diff_key($have, $want) as $v => $_) { $this->UnregisterMessage($v, VM_UPDATE); }
        foreach (array_diff_key($want, $have) as $v => $_) { $this->RegisterMessage($v, VM_UPDATE); }
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message !== VM_UPDATE || empty($Data[1])) { return; }   // nur echte Aenderungen
        $vid = (int) $SenderID;
        $iid = (int) @IPS_GetParent($vid);
        if ($iid <= 0 || !@IPS_InstanceExists($iid)) { return; }
        $mod = IPS_GetInstance($iid)['ModuleInfo']['ModuleName'];
        $ident = (string) IPS_GetObject($vid)['ObjectIdent'];
        if (!isset(self::WATCH[$mod][$ident])) { return; }
        [$area, $art] = self::WATCH[$mod][$ident];
        $new = $Data[0]; $old = $Data[2] ?? null;
        $entries = [];
        $room = $this->roomName($iid);
        switch ($art) {
            case 'pos':
                $p = (int) $new;
                $entries[] = [$area, $p <= 0 ? 'Rollos auf' : ($p >= 100 ? 'Rollos zu' : "Rollos auf $p %"), $room];
                break;
            case 'onoff':
                $entries[] = [$area, $area . ($new ? ' an' : ' aus'), $room];
                break;
            case 'play':
                $entries[] = [$area, $new ? 'Musik startet' : 'Musik stoppt', $room];
                break;
            case 'run':
                $entries[] = [$area, $this->nice($iid) . ($new ? ' bewässert' : ': Bewässerung fertig'), ''];
                break;
            case 'storm':
                if ($new) {
                    $km = @GetValue((int) @IPS_GetObjectIDByIdent('Nearest', $iid));
                    $ri = (string) @GetValue((int) @IPS_GetObjectIDByIdent('BearingText', $iid));
                    $ri = trim((string) preg_replace('/^\d+°\s*/u', '', $ri));
                    $entries[] = [$area, 'Gewitter in der Nähe', trim(($km ? $km . ' km' : '') . ($ri !== '' && $ri !== '–' ? ' ' . $ri : ''))];
                } else {
                    $entries[] = [$area, 'Gewitter vorbei', ''];
                }
                break;
            case 'precip':
                $n = (int) $new; $o = (int) $old;
                if ($n > 0 && $o === 0) {
                    $entries[] = [$area, (self::NIEDERSCHLAG[$n] ?? 'Niederschlag') . ' beginnt', ''];
                } elseif ($n === 0 && $o > 0) {
                    $mm = @GetValue((int) @IPS_GetObjectIDByIdent('RainDay', $iid));
                    $entries[] = [$area, (self::NIEDERSCHLAG[$o] ?? 'Niederschlag') . ' hört auf', is_numeric($mm) && $mm > 0 ? number_format((float) $mm, 1, ',', '') . ' mm heute' : ''];
                }
                break;
            case 'people':
                $a = array_filter(array_map('trim', explode(',', (string) $new)));
                $b = array_filter(array_map('trim', explode(',', (string) $old)));
                foreach (array_diff($a, $b) as $n) { $entries[] = [$area, "$n angekommen", '']; }
                foreach (array_diff($b, $a) as $n) { $entries[] = [$area, "$n gegangen", '']; }
                break;
        }
        if (!$entries) { return; }
        $site = $this->siteOf($iid);
        // $TimeStamp von Symcon ist keine Unix-Zeit (im Test 1 h 40 min daneben) - die
        // Meldung kommt sofort, also gilt die aktuelle Uhrzeit.
        $t = time();
        if (!IPS_SemaphoreEnter('HSOV_Log_' . $this->InstanceID, 2000)) { return; }
        try {
            $lv = $this->GetIDForIdent('EventLog');
            $log = json_decode((string) GetValue($lv), true) ?: [];
            $day0 = mktime(0, 0, 0);
            $log = array_values(array_filter($log, function ($e) use ($day0) { return (int) ($e[0] ?? 0) >= $day0; }));
            foreach ($entries as [$ar, $title, $name]) {
                // Regen mit kurzer Pause ist EIN Regen: endete er vor weniger als 20 Minuten
                // am selben Standort, faellt das Ende weg statt eines neuen Beginns.
                if (substr($title, -8) === ' beginnt') {
                    for ($i = count($log) - 1; $i >= 0; $i--) {
                        $e = $log[$i];
                        if ((int) $e[1] !== $site || $e[2] !== $ar) { continue; }
                        if (substr((string) $e[3], -9) === ' hört auf' && $t - (int) $e[0] < 1200) {
                            array_splice($log, $i, 1);
                            continue 2;
                        }
                        break;
                    }
                }
                $log[] = [$t, $site, $ar, $title, $name];
            }
            if (count($log) > 1500) { $log = array_slice($log, -1500); }
            SetValue($lv, json_encode($log, JSON_UNESCAPED_UNICODE));
        } finally {
            IPS_SemaphoreLeave('HSOV_Log_' . $this->InstanceID);
        }
    }

    public function GetState(): string
    {
        $s = (string) $this->GetValue('State');
        return $s !== '' ? $s : json_encode(['ok' => false, 'err' => 'noch nicht berechnet']);
    }

    /**
     * Verwaltung/Aktionen (ueber ?api=mod&op=manage, mit Token):
     *   {"op":"act","args":{"id":"<hintId>","action":"<key>"}}
     *   {"op":"refresh"}
     */
    public function Manage(string $Json): string
    {
        $req = json_decode($Json, true) ?: [];
        $op = (string) ($req['op'] ?? '');
        $args = (array) ($req['args'] ?? []);
        if ($op === 'refresh' || $op === 'state') {
            $this->Update();
            return $this->GetState();
        }
        if ($op === 'act') {
            $res = $this->act((string) ($args['id'] ?? ''), (string) ($args['action'] ?? ''));
            $this->Update();
            return json_encode($res, JSON_UNESCAPED_UNICODE);
        }
        return json_encode(['ok' => false, 'err' => 'op']);
    }

    // ================================================================= Aktionen

    private function act(string $id, string $action): array
    {
        if ($id === '' || $action === '') {
            return ['ok' => false, 'err' => 'id/action fehlt'];
        }
        if ($action === 'dismiss' || $action === 'today') {
            $dis = json_decode($this->ReadAttributeString('Dismissed'), true) ?: [];
            $dis[$id] = $action === 'today' ? mktime(0, 0, 0, (int) date('n'), (int) date('j') + 1) : time() + 30 * 86400;
            $this->WriteAttributeString('Dismissed', json_encode($dis));
            return ['ok' => true, 'done' => $action];
        }
        // Fachaktionen: Hinweis-ID traegt Art und Instanz ("sh-12345-hand")
        if (!preg_match('/^([a-z]+)-(\d+)/', $id, $m)) {
            return ['ok' => false, 'err' => 'unbekannter Hinweis'];
        }
        $kind = $m[1]; $iid = (int) $m[2];
        if (!@IPS_InstanceExists($iid)) {
            return ['ok' => false, 'err' => 'Instanz fehlt'];
        }
        try {
            if ($kind === 'sh' && $action === 'auto' && function_exists('HSSH_SetMode')) {
                return ['ok' => (bool) HSSH_SetMode($iid, 0)];
            }
            if ($kind === 'ir' && $action === 'stop' && function_exists('HSIR_Stop')) {
                return ['ok' => (bool) HSIR_Stop($iid)];
            }
            if ($kind === 'ac' && $action === 'off' && function_exists('HSAC_SetControl')) {
                return ['ok' => (bool) HSAC_SetControl($iid, 'Power', 'false')];
            }
        } catch (\Throwable $e) {
            return ['ok' => false, 'err' => $e->getMessage()];
        }
        return ['ok' => false, 'err' => 'Aktion nicht verfuegbar'];
    }

    // ================================================================= Hinweise

    private function hintsShading(array $by): array
    {
        $out = []; $sturm = [];
        foreach ($by['ShadingDevice'] ?? [] as $iid) {
            $st = $this->state('HSSH', $iid);
            if (!$st) { continue; }
            $site = $this->siteOf($iid);
            if ((int) ($st['Mode'] ?? 0) === 1) {
                $out[] = $this->hint("sh-$iid-hand", $site, 'Beschattung', self::SEV_WARN,
                    $this->shadeLabel($iid) . ' steht auf Hand', 'Automatik übergangen',
                    $this->changedAt($iid, 'Mode'),
                    [['key' => 'auto', 'label' => 'Zurück auf Automatik', 'primary' => true], ['key' => 'today', 'label' => 'Heute so lassen']]);
            } elseif (!empty($st['ManuellVorrang'])) {
                $out[] = $this->hint("sh-$iid-vorrang", $site, 'Beschattung', self::SEV_INFO,
                    $this->shadeLabel($iid) . ' von Hand verstellt', 'Automatik wartet bis zur nächsten Planfahrt',
                    $this->changedAt($iid, 'Position'), [['key' => 'today', 'label' => 'Ausblenden']]);
            }
            if (!empty($st['Sturm'])) {
                $sturm[$site][] = $this->roomName($iid);
            }
        }
        foreach ($sturm as $site => $names) {
            $out[] = $this->hint("shs-$site-sturm", (int) $site, 'Beschattung', self::SEV_INFO,
                'Sturmschutz aktiv', count($names) . ' Rollos gesperrt', time(), []);
        }
        return $out;
    }

    private function hintsIrrigation(array $by): array
    {
        $out = [];
        $fac = max(1.0, $this->ReadPropertyFloat('RunawayFactor'));
        foreach ($by['IrrigationCircuit'] ?? [] as $iid) {
            $st = $this->state('HSIR', $iid);
            if (!$st || empty($st['Running'])) { continue; }
            $since = $this->changedAt($iid, 'Running');
            $min = (int) floor((time() - $since) / 60);
            $dur = function_exists('HSIR_GetEffectiveMinutes') ? (int) @HSIR_GetEffectiveMinutes($iid) : (int) ($st['Duration'] ?? 0);
            if ($dur <= 0) { $dur = (int) ($st['Duration'] ?? 0); }
            if ($dur > 0 && $min > max($dur * $fac, $dur + 10)) {
                $out[] = $this->hint("ir-$iid-lang", $this->siteOf($iid), 'Bewässerung', self::SEV_CRIT,
                    'Bewässerung ' . $this->nice($iid) . " läuft seit $min Minuten", "geplant waren $dur Minuten", $since,
                    [['key' => 'stop', 'label' => 'Jetzt stoppen', 'primary' => true], ['key' => 'dismiss', 'label' => 'Laufen lassen']]);
            }
        }
        return $out;
    }

    private function hintsClimate(array $by): array
    {
        $out = [];
        foreach ($by['ClimateZone'] ?? [] as $iid) {
            $st = $this->state('HSAC', $iid);
            if (!$st || empty($st['OpenWindow']) || (empty($st['Power']) && empty($st['Running']))) { continue; }
            $room = $this->roomName($iid);
            $out[] = $this->hint("ac-$iid-fenster", $this->siteOf($iid), 'Klima', self::SEV_WARN,
                "Fenster offen, Klima $room läuft", 'kühlt oder heizt ins Freie', $this->changedAt($iid, 'OpenWindow'),
                [['key' => 'off', 'label' => 'Klima aus', 'primary' => true], ['key' => 'dismiss', 'label' => 'Ignorieren']]);
        }
        return $out;
    }

    private function hintsPresence(array $by): array
    {
        $out = [];
        foreach ($by['Presence'] ?? [] as $iid) {
            $v = @IPS_GetObjectIDByIdent('Fresh', $iid);
            if (!$v || GetValue($v)) { continue; }
            $site = $this->siteOf($iid);
            $out[] = $this->hint("pr-$iid-stumm", $site, 'Netz', self::SEV_WARN,
                'Router ' . $this->siteName($site) . ' meldet nicht', 'Anwesenheit dort unbekannt', (int) IPS_GetVariable($v)['VariableChanged'],
                [['key' => 'dismiss', 'label' => 'Ignorieren']]);
        }
        return $out;
    }

    private function hintsHealth(array $by): array
    {
        if (!$this->ReadPropertyBoolean('ShowHealth')) { return []; }
        $out = [];
        foreach ($by['DeviceHealth'] ?? [] as $dh) {
            $reg = $this->jsonVar($dh, 'Register');
            $grp = [];
            foreach ((array) ($reg['devices'] ?? []) as $d) {
                if (!in_array($d['state'] ?? '', ['unreach', 'fault'], true)) { continue; }
                $grp[$this->siteOf((int) ($d['id'] ?? 0))][] = $d;
            }
            foreach ($grp as $site => $list) {
                $names = array_map(function ($d) { return (string) ($d['name'] ?? '?'); }, $list);
                $out[] = $this->hint('dh-' . $dh . '-' . $site . '-' . substr(md5(implode('|', array_column($list, 'id'))), 0, 6),
                    (int) $site, 'Geräte', self::SEV_WARN,
                    count($list) === 1 ? '1 Gerät nicht erreichbar' : count($list) . ' Geräte nicht erreichbar',
                    $this->kurzliste($names), (int) max(array_map(function ($d) { return (int) ($d['lastSeen'] ?? 0); }, $list)),
                    [['key' => 'today', 'label' => 'Später']]);
            }
        }
        return $out;
    }

    private const WARNSTUFE = [1 => 'gelb', 2 => 'orange', 3 => 'rot'];

    /** Warnungen einer WeatherWarnings-Instanz (heute und kuenftig). */
    private function warnings(int $wid): array
    {
        $v = @IPS_GetObjectIDByIdent('Warnings', $wid);
        $l = $v ? json_decode((string) GetValue($v), true) : null;
        return is_array($l) ? $l : [];
    }

    private function uhrzeit(int $t): string
    {
        return date('Y-m-d', $t) === date('Y-m-d') ? date('H:i', $t) : (['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'][(int) date('w', $t)] . ' ' . date('H:i', $t));
    }

    /** Laufende Unwetterwarnungen ab orange brauchen Aufmerksamkeit; gelb steht nur im Fahrplan. */
    private function hintsWarnings(array $by): array
    {
        $out = [];
        $now = time();
        foreach ($by['WeatherWarnings'] ?? [] as $wid) {
            foreach ($this->warnings($wid) as $w) {
                $st = (int) ($w['stufe'] ?? 0);
                if ($st < 2 || (int) $w['von'] > $now || (int) $w['bis'] <= $now) { continue; }
                $text = trim((string) ($w['text'] ?? ''));
                $out[] = $this->hint('wx-' . $wid . '-' . ($w['id'] ?? ''), $this->siteOf($wid), 'Wetter', $st >= 3 ? 3 : 2,
                    'Unwetterwarnung ' . $w['art'] . ' (' . self::WARNSTUFE[$st] . ')',
                    'bis ' . $this->uhrzeit((int) $w['bis']) . ($text !== '' ? ' · ' . (mb_strlen($text) > 110 ? mb_substr($text, 0, 108) . '…' : $text) : ''),
                    (int) $w['von'], []);
            }
        }
        return $out;
    }

    private function hintsBattery(array $by): array
    {
        if (!$this->ReadPropertyBoolean('ShowBattery')) { return []; }
        $out = [];
        foreach ($by['BatteryManager'] ?? [] as $bm) {
            $reg = $this->jsonVar($bm, 'Register');
            $grp = [];
            foreach ((array) ($reg['devices'] ?? []) as $d) {
                if (($d['state'] ?? '') !== 'empty' && empty($d['low'])) { continue; }
                $site = !empty($d['varId']) ? $this->siteOf((int) $d['varId']) : 0;
                $grp[$site][] = $d;
            }
            foreach ($grp as $site => $list) {
                $names = array_map(function ($d) { return (string) ($d['name'] ?? '?'); }, $list);
                $out[] = $this->hint('bm-' . $bm . '-' . $site . '-' . substr(md5(implode('|', $names)), 0, 6), (int) $site, 'Batterien', self::SEV_WARN,
                    count($list) === 1 ? 'Batterie leer: ' . $names[0] : count($list) . ' Batterien leer', $this->kurzliste($names),
                    (int) max(array_map(function ($d) { return (int) ($d['ts'] ?? 0); }, $list)), [['key' => 'today', 'label' => 'Später']]);
            }
        }
        return $out;
    }

    // ================================================================= Standorte

    private function sites(array $by, array $hints): array
    {
        $out = [];
        foreach ($this->siteList() as $sid) {
            $row = ['id' => $sid, 'name' => IPS_GetName($sid), 'abbr' => $this->siteAbbr($sid), 'occupancy' => -1, 'occLabel' => '', 'residents' => '',
                    'guests' => 0, 'fresh' => null, 'rental' => null, 'hints' => 0, 'worst' => 0];
            foreach (IPS_GetChildrenIDs($sid) as $c) {
                if (!IPS_InstanceExists($c)) { continue; }
                $mn = IPS_GetInstance($c)['ModuleInfo']['ModuleName'];
                if ($mn === 'Presence') {
                    $ov = @IPS_GetObjectIDByIdent('Occupancy', $c);
                    $row['occupancy'] = $ov ? (int) GetValue($ov) : -1;
                    $row['occLabel'] = $ov ? GetValueFormatted($ov) : '';
                    $rv = @IPS_GetObjectIDByIdent('Residents', $c);
                    $row['residents'] = $rv ? (string) GetValue($rv) : '';
                    $gv = @IPS_GetObjectIDByIdent('Guests', $c);
                    $row['guests'] = $gv ? (int) GetValue($gv) : 0;
                    $fv = @IPS_GetObjectIDByIdent('Fresh', $c);
                    $row['fresh'] = $fv ? (bool) GetValue($fv) : null;
                    $row['presenceId'] = $c;
                } elseif ($mn === 'Rental') {
                    $tv = @IPS_GetObjectIDByIdent('TodayStatus', $c);
                    $sv = @IPS_GetObjectIDByIdent('Summary', $c);
                    $row['rental'] = ['id' => $c, 'status' => $tv ? (int) GetValue($tv) : 0,
                                      'label' => $tv ? GetValueFormatted($tv) : '', 'summary' => $sv ? (string) GetValue($sv) : ''];
                }
            }
            foreach ($hints as $h) {
                if ($h['site'] === $sid) { $row['hints']++; $row['worst'] = max($row['worst'], $h['sev']); }
            }
            $out[] = $row;
        }
        return $out;
    }

    /** Standorte = HSSP mit Kind Haus, in Baumreihenfolge. */
    private function siteList(): array
    {
        $l = [];
        foreach (@IPS_GetInstanceListByModuleID(self::GUID_HSSP) ?: [] as $i) {
            $cfg = json_decode((string) @IPS_GetConfiguration($i), true);
            if (is_array($cfg) && ($cfg['Kind'] ?? '') === 'Haus') {
                $l[] = $i;
            }
        }
        usort($l, function ($a, $b) {
            return [IPS_GetObject($a)['ObjectPosition'], IPS_GetName($a)] <=> [IPS_GetObject($b)['ObjectPosition'], IPS_GetName($b)];
        });
        return $l;
    }

    // ================================================================= Fahrplan

    /**
     * Tagesfahrplan: der ganze heutige Tag (0:00) bis morgen frueh (AheadHours ab
     * Mitternacht), vergangene Punkte eingeschlossen. Quellen:
     *   - Wochenplaene der Fachmodule (Beschattung, Heizung, Bewaesserung) - jeder Wechsel
     *   - Klima: naechster Planwechsel
     *   - Licht-Automatik: aktive Zeit-/Sonnenregeln
     *   - Symcon-Wochenplaene aller uebrigen Objekte (Warmwasser, Maehplan, Pool, Wecker ...)
     *   - Symcon-Ereignisse mit fester Tageszeit (taeglich/woechentlich "um HH:MM")
     * Gleiche Aktionen zur selben Minute am selben Standort werden zusammengefasst.
     */
    private function timeline(array $by, int $now): array
    {
        $day0 = mktime(0, 0, 0);
        $to = $day0 + max(24, $this->ReadPropertyInteger('AheadHours')) * 3600;
        $grp = [];   // key -> [t, site, area, title, names[], detail, kind]
        $add = function (int $t, int $site, string $area, string $title, string $name, string $detail = '', string $kind = '', string $badge = '') use (&$grp, $day0, $to) {
            if ($t < $day0 || $t > $to) { return; }
            $k = $site . '|' . intdiv($t, 60) . '|' . $area . '|' . $title . '|' . $kind . '|' . $detail . '|' . $badge;
            if (!isset($grp[$k])) { $grp[$k] = ['t' => $t, 'site' => $site, 'area' => $area, 'title' => $title, 'names' => [], 'detail' => $detail, 'kind' => $kind, 'badge' => $badge]; }
            if ($name !== '') { $grp[$k]['names'][] = $name; }
        };

        // NUR SCHARFE DOMAENEN. Ein Plan im Schatten-Modus schaltet nichts - ihn in den
        // Fahrplan zu schreiben hiesse, Aktionen zu behaupten, die nie stattfinden
        // (23.09.2026: "22:00 Klima Planwechsel" in einer Klima-Domaene, die aus war).
        // Beschattung: jeder Wechsel des aktiven Wochenplans
        foreach ($this->armedList($by, 'Shading', 'ShadingDevice', 'HSSH') as $iid) {
            foreach ($this->weekChanges($this->manageJson('HSSH', $iid, 'getSchedule'), $day0, $to) as [$t, $val]) {
                $title = $val <= 0 ? 'Rollos auf' : ($val >= 100 ? 'Rollos zu' : "Rollos auf $val %");
                $add($t, $this->siteOf($iid), 'Beschattung', $title, $this->roomName($iid));
            }
        }
        // Heizung: Plan der aktuell gewaehlten Anwesenheitsvariante
        foreach ($this->armedList($by, 'Heating', 'HeatingZone', 'HSHT') as $iid) {
            $st = $this->state('HSHT', $iid);
            $pres = isset($st['Presence']) ? (int) $st['Presence'] : -1;
            $plan = function_exists('HSHT_GetScheduleJson') ? json_decode((string) @HSHT_GetScheduleJson($iid, $pres), true) : null;
            foreach ($this->weekChanges(is_array($plan) ? $plan : [], $day0, $to) as [$t, $val]) {
                $add($t, $this->siteOf($iid), 'Heizung', 'Heizung auf ' . $this->num($val) . ' °C', $this->roomName($iid));
            }
        }
        // Bewaesserung: Fenster des Wochenplans, mit Sperrgrund
        foreach ($this->armedList($by, 'Irrigation', 'IrrigationCircuit', 'HSIR') as $iid) {
            $st = $this->state('HSIR', $iid);
            $dur = function_exists('HSIR_GetEffectiveMinutes') ? (int) @HSIR_GetEffectiveMinutes($iid) : 0;
            $skip = !empty($st['RainBlocked']);
            $grund = $skip ? $this->umlaute(trim((string) preg_replace('/^Gesperrt:\s*/i', '', (string) ($st['LastRun'] ?? '')))) : '';
            foreach ($this->weekChanges($this->manageJson('HSIR', $iid, 'getSchedule'), $day0, $to) as [$t, $val]) {
                if ($val <= 0) { continue; }
                $past = $t < $now;
                $add($t, $this->siteOf($iid), 'Bewässerung', $this->nice($iid) . ($past ? ' bewässert' : ' bewässern'), '',
                    $skip && !$past ? ('entfällt' . ($grund !== '' ? ' · ' . $grund : '')) : ($dur > 0 ? "$dur min" : ''), $skip && !$past ? 'skip' : '');
            }
        }
        // Klima: naechster Planwechsel - nur scharf UND mit aktivem Zeitplan
        foreach ($this->armedList($by, 'Climate', 'ClimateZone', 'HSAC') as $iid) {
            $st = $this->state('HSAC', $iid);
            $t = (int) ($st['NextChange'] ?? 0);
            if ($t >= $now && ((int) ($st['PlanMode'] ?? 0) !== 0 || !empty($st['Scheduled']))) { $add($t, $this->siteOf($iid), 'Klima', 'Klima Planwechsel', $this->roomName($iid)); }
        }
        // Licht-Automatik (Hub): aktive Zeit- und Sonnenregeln
        foreach ($by['HomeSuite Hub'] ?? [] as $hub) {
            $la = function_exists('HSH_Manage') ? json_decode((string) @HSH_Manage($hub, json_encode(['op' => 'lightAutoGet'])), true) : null;
            if (!is_array($la) || empty($la['automationEnabled']) || !$this->armed($by, 'Light')) { continue; }
            $sun = (array) ($la['sun'] ?? []);
            foreach ((array) ($la['rules'] ?? []) as $r) {
                if (empty($r['enabled']) || ($r['type'] ?? '') !== 'schedule') { continue; }
                $tr = (array) ($r['trigger'] ?? []);
                foreach ([0, 1] as $plus) {
                    $d0 = $day0 + $plus * 86400;
                    $days = (array) ($tr['days'] ?? []);
                    if ($days && !in_array((int) date('N', $d0), array_map('intval', $days), true) && !in_array((int) date('N', $d0) - 1, array_map('intval', $days), true)) { continue; }
                    $min = null;
                    if (($tr['kind'] ?? '') === 'time' && preg_match('/^(\d{1,2}):(\d{2})/', (string) ($tr['time'] ?? ''), $m)) {
                        $min = (int) $m[1] * 60 + (int) $m[2];
                    } elseif (($tr['kind'] ?? '') === 'sun' && isset($sun[$tr['event'] ?? ''])) {
                        $min = (int) $sun[$tr['event']] + (int) ($tr['offsetMin'] ?? 0);
                    }
                    if ($min !== null) { $add($d0 + $min * 60, $this->siteOf($hub), 'Licht', 'Licht: ' . (string) ($r['name'] ?? 'Regel'), ''); }
                }
            }
        }
        // Symcon-Ereignisse: Wochenplaene (alle Schaltpunkte) und feste Tageszeiten
        $covered = ['IrrigationCircuit', 'ClimateZone', 'ShadingDevice', 'HeatingZone'];
        foreach (IPS_GetEventList() as $eid) {
            $e = IPS_GetEvent($eid);
            if (!$e['EventActive']) { continue; }
            $p = IPS_GetParent($eid);
            if (@IPS_InstanceExists($p) && in_array(IPS_GetInstance($p)['ModuleInfo']['ModuleName'], $covered, true)) { continue; }
            $site = $this->siteOf($p);
            if ($this->isHomeSuite($p)) {
                $gate = ['AudioZone' => 'Audio', 'AudioZoneBridged' => 'Audio', 'LightDevice' => 'Light', 'PoolController' => 'Pool', 'MowerDevice' => 'Mower'][IPS_GetInstance($p)['ModuleInfo']['ModuleName']] ?? '';
                if ($gate !== '' && !$this->armed($by, $gate)) { continue; }
            }
            $area = $this->isHomeSuite($p) ? $this->domainLabel($p) : $this->areaGuess($p, (string) IPS_GetName($eid));
            $label = $this->objLabel($p);
            // Pool: der Filterplan gilt nur, wenn die Pumpe auf Auto steht. "Manuell Aus"
            // (Winterbetrieb) oder "Manuell Ein" uebersteuert ihn am Controller - dann
            // schaltet er nichts und gehoert nicht in den Fahrplan.
            if (@IPS_InstanceExists($p) && IPS_GetInstance($p)['ModuleInfo']['ModuleName'] === 'PoolController'
                && IPS_GetObject($eid)['ObjectIdent'] === 'FilterSchedule') {
                $mode = $this->identDeep($p, 'Relay' . (int) @IPS_GetProperty($p, 'PumpRelayIndex') . 'Mode');
                if ($mode && (int) GetValue($mode) !== 0) { continue; }
                $label = 'Pumpe';
            }
            if ((int) $e['EventType'] === 2) {
                foreach ([0, 1] as $plus) {
                    $d0 = $day0 + $plus * 86400;
                    // Der Punkt um 0:00 ist meist nur der Tagesanfang des Plans - kein Schalten,
                    // wenn er dieselbe Aktion traegt wie der letzte Punkt des Vortags.
                    $prev = $this->schedulePoints($e, $d0 - 86400);
                    $prevAct = $prev ? end($prev)[1] : null;
                    foreach ($this->schedulePoints($e, $d0) as [$t, $act]) {
                        if ($t === $d0 && $act === $prevAct) { continue; }
                        $add($t, $site, $area, $label . ($act !== '' ? ': ' . $act : ''), '');
                    }
                }
            } elseif ((int) $e['EventType'] === 1 && (int) $e['CyclicTimeType'] === 0) {
                // feste Tageszeit: "taeglich um HH:MM" (DateType 2) oder "woechentlich" (3) an Tagen
                $dt = (int) $e['CyclicDateType'];
                if ($dt !== 2 && $dt !== 3) { continue; }
                $tf = (array) ($e['CyclicTimeFrom'] ?? []);
                foreach ([0, 1] as $plus) {
                    $d0 = $day0 + $plus * 86400;
                    if ($dt === 3 && (((int) $e['CyclicDateDay']) & (1 << ((int) date('N', $d0) - 1))) === 0) { continue; }
                    $t = $d0 + (int) ($tf['Hour'] ?? 0) * 3600 + (int) ($tf['Minute'] ?? 0) * 60 + (int) ($tf['Second'] ?? 0);
                    $name = trim((string) IPS_GetName($eid));
                    $label = preg_match('/^(alle|t(ae|ä)glich|w(oe|ö)chentlich|unnamed)/i', $name) || $name === '' ? '' : $name;
                    $title = $this->objLabel($p) . ($label !== '' ? ': ' . $label : '');
                    // Technik nur am Skript-/Ereignisnamen erkennen - Ordnernamen wie "Zaehler"
                    // oder "Statistik" wuerden sonst echte Schaltaktionen verstecken.
                    $add($t, $site, $this->isTechnik($this->nice($p) . ' ' . $label) ? 'Technik' : $area, $title, '');
                }
            }
        }

        // Amtliche Wetterwarnungen (Modul WeatherWarnings) zu ihrem Beginn, Plakette in der
        // Warnfarbe. Laeuft eine schon seit gestern, steht sie am Tagesanfang.
        foreach ($by['WeatherWarnings'] ?? [] as $wid) {
            foreach ($this->warnings($wid) as $w) {
                $add(max((int) $w['von'], $day0), $this->siteOf($wid), 'Wetter', 'Warnung ' . $w['art'], '',
                    'bis ' . $this->uhrzeit((int) $w['bis']), '', self::WARNSTUFE[(int) $w['stufe']] ?? '');
            }
        }

        // Aufziehendes Gewitter: die Stationsauswertung schaetzt, wann es da ist. Das ist eine
        // Schaetzung, kein Schaltpunkt - darum steht es so im Detail.
        foreach ($by['WeatherStation'] ?? [] as $wid) {
            $ap = @IPS_GetObjectIDByIdent('StormApproaching', $wid);
            $eta = @IPS_GetObjectIDByIdent('StormEta', $wid);
            if (!$ap || !$eta || !GetValue($ap) || (int) GetValue($eta) <= 0) { continue; }
            $dist = @IPS_GetObjectIDByIdent('StormDist', $wid);
            $add(time() + (int) GetValue($eta) * 60, $this->siteOf($wid), 'Wetter', 'Gewitter kommt', '',
                'geschätzt' . ($dist && GetValue($dist) > 0 ? ' · jetzt ' . (int) GetValue($dist) . ' km entfernt' : ''), '');
        }

        // TV-Aufnahmen der Receiver (Tabelle: Sender, Beginn "TT.MM. HH:MM", Ende, Titel ...)
        foreach ($by['EnigmaReceiver'] ?? [] as $rid) {
            $rows = $this->jsonVar($rid, 'TimerListe');
            foreach (array_slice($rows, 1) as $r) {
                if (!is_array($r) || !preg_match('/(\d{1,2})\.(\d{1,2})\.\s+(\d{1,2}):(\d{2})/', (string) ($r[1] ?? ''), $m)) { continue; }
                $t = mktime((int) $m[3], (int) $m[4], 0, (int) $m[2], (int) $m[1]);
                $sender = preg_match('/alt="([^"]*)"/', (string) ($r[0] ?? ''), $sm) ? $sm[1] : strip_tags((string) ($r[0] ?? ''));
                $add($t, $this->siteOf($rid), 'TV', 'Aufnahme: ' . (string) ($r[3] ?? ''), '',
                    trim($sender . ' · bis ' . (string) ($r[2] ?? '')), '');
            }
        }
        // Was TATSAECHLICH geschah (Tagesprotokoll)
        foreach (json_decode((string) $this->GetValue('EventLog'), true) ?: [] as $e) {
            [$t, $site, $area, $title, $name] = $e + [0, 0, '', '', ''];
            $add((int) $t, (int) $site, (string) $area, (string) $title, (string) $name, '', 'done');
        }

        // Geplantes, das schon als erledigt im Protokoll steht, nicht doppelt zeigen
        // (Plan 19:10 "Rollos zu" + Protokoll 19:10 "Rollos zu").
        $done = [];
        foreach ($grp as $g) {
            if ($g['kind'] === 'done') { $done[] = [$g['site'], $g['area'], $g['t']]; }
        }
        $alarm = $this->alarmArmed();
        $ev = [];
        foreach ($grp as $k => $g) {
            if ($g['kind'] === '' && $g['t'] <= time()) {
                foreach ($done as [$ds, $da, $dt]) {
                    if ($ds === $g['site'] && $da === $g['area'] && abs($dt - $g['t']) <= 1200) { continue 2; }
                }
            }
            $n = count($g['names']);
            $title = $g['title'];
            $detail = $g['detail'];
            if ($n > 1) {
                $title .= ' · ' . $n . ($g['area'] === 'Heizung' || $g['area'] === 'Licht' || $g['area'] === 'Musik' || $g['area'] === 'Klima' ? ' Räume' : ($g['area'] === 'Beschattung' ? ' Rollos' : ''));
                $detail = trim($this->kurzliste($g['names']) . ($detail !== '' ? ' · ' . $detail : ''));
            } elseif ($n === 1) {
                $detail = trim($g['names'][0] . ($detail !== '' ? ' · ' . $detail : ''));
            }
            $badge = (string) ($g['badge'] ?? '');
            if ($badge === '') { $badge = ($g['area'] === 'Sicherheit' && $g['kind'] === '' && $alarm === false) ? 'unscharf' : ''; }
            $ev[] = $this->event(substr(md5($k), 0, 12), $g['t'], $g['site'], $g['area'], $title, $detail, $g['kind'], $badge);
        }
        usort($ev, function ($a, $b) { return [$a['t'], $a['area']] <=> [$b['t'], $b['area']]; });
        return $ev;
    }

    /**
     * Interne Buchhaltung (Zaehler nullen, Staende rechnen, Listen leeren, Mitternachts-
     * Timer) schaltet nichts im Haus - sie steht als "Technik" im Fahrplan und ist dort
     * standardmaessig ausgeblendet.
     */
    private function isTechnik(string $t): bool
    {
        return (bool) preg_match('/berechn|rechnen|setzen|reset|zur(ue|ü)ck|null|leeren|import|timer|status|feiertag|neuer tag|config|abgleich|aufr(ae|ä)um|speicher|sicher|archiv|log|statistik|z(ae|ä)hler/i', $t);
    }

    /** Objekt per Ident unter $root suchen - auch in Unterordnern (HSPC gruppiert seine Variablen). */
    private function identDeep(int $root, string $ident): int
    {
        $id = @IPS_GetObjectIDByIdent($ident, $root);
        if ($id) { return (int) $id; }
        foreach (IPS_GetChildrenIDs($root) as $c) {
            if (IPS_GetObject($c)['ObjectType'] === 0 && ($id = $this->identDeep($c, $ident))) { return $id; }
        }
        return 0;
    }

    /** Bereich eines fremden Zeitplans aus Objekt- und Ereignisnamen erraten. */
    private function areaGuess(int $p, string $eventName): string
    {
        $t = IPS_GetName($p) . ' ' . $eventName . ' ' . (@IPS_InstanceExists($p) ? IPS_GetInstance($p)['ModuleInfo']['ModuleName'] : '');
        foreach (['/m(ae|ä)h|mower|automower/i' => 'Mäher', '/warmwasser|viessmann|boiler/i' => 'Warmwasser',
                  '/pool|filter|umw(ae|ä)lz/i' => 'Pool', '/alarm|w(ae|ä)chter|scharf/i' => 'Sicherheit',
                  '/licht|lampe|beleucht/i' => 'Licht', '/rollo|jalousie|markise|beschatt/i' => 'Beschattung',
                  '/heiz|thermost/i' => 'Heizung', '/musik|sonos|radio|wecker/i' => 'Musik'] as $rx => $area) {
            if (preg_match($rx, $t)) { return $area; }
        }
        return 'Zeitplan';
    }

    /** Anzeigename; bei Skripten mit dem Ordner davor ("Beleuchtung: Aus"). */
    private function objLabel(int $id): string
    {
        $n = $this->nice($id);
        if (@IPS_ObjectExists($id) && IPS_GetObject($id)['ObjectType'] === 3) {
            $parent = (int) IPS_GetParent($id);
            if ($parent > 0) { return $this->nice($parent) . ': ' . $n; }
        }
        return $n;
    }

    /**
     * Scharf-Stufe einer Domaene im Hub: Arm<Domain>Mode 0 = alle Schatten, 1 = je Geraet,
     * 2 = alle scharf (Vorrang); ohne Mode-Variable der alte Schalter Arm<Domain>.
     * Rueckgabe 0, 1 oder 2.
     */
    private function armMode(array $by, string $domain): int
    {
        foreach ($by['HomeSuite Hub'] ?? [] as $hub) {
            $m = @IPS_GetObjectIDByIdent('Arm' . $domain . 'Mode', $hub);
            if ($m) { return max(0, min(2, (int) GetValue($m))); }
            $v = @IPS_GetObjectIDByIdent('Arm' . $domain, $hub);
            if ($v) { return GetValue($v) ? 2 : 0; }
        }
        return 2;
    }

    /** Domaene ueberhaupt aktiv (nicht "alle Schatten")? */
    private function armed(array $by, string $domain): bool
    {
        return $this->armMode($by, $domain) > 0;
    }

    /** Geraete einer Domaene, die wirklich schalten (Stufe 2 alle, Stufe 1 nur die scharfen). */
    private function armedList(array $by, string $domain, string $module, string $pfx): array
    {
        $mode = $this->armMode($by, $domain);
        $ids = $by[$module] ?? [];
        if ($mode === 0) { return []; }
        if ($mode === 2) { return $ids; }
        return array_values(array_filter($ids, function ($iid) use ($pfx) {
            $c = $this->manageJson($pfx, $iid, 'getConfig');
            return !empty($c['config']['armed']);
        }));
    }

    /** JSON-Antwort einer Verwaltungsoperation (PREFIX_Manage). */
    private function manageJson(string $pfx, int $iid, string $op): array
    {
        $fn = $pfx . '_Manage';
        if (!function_exists($fn)) { return []; }
        try {
            $j = json_decode((string) @$fn($iid, json_encode(['op' => $op])), true);
            return is_array($j) ? $j : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Wechsel eines Wochenplans (week[Mo..So] = Slots {end: Minute, val}) im Zeitraum:
     * liefert [[Zeitstempel, neuer Wert], ...]. Der Wert vor 0:00 ist der letzte des Vortags.
     */
    private function weekChanges(array $plan, int $from, int $to): array
    {
        $week = $plan['week'] ?? null;
        if (!is_array($week) || count($week) < 7) { return []; }
        // Sonnengebundene Grenzen ("anchor":"sunset","offset":10): das gespeicherte "end"
        // ist nur die Ersatzzeit. Gezaehlt wird Sonnenereignis + Versatz (23.09.2026: Plan
        // zeigte 20:29, gefahren wurde um 19:10 = Sonnenuntergang 19:00 + 10 min).
        $sun = (array) ($plan['sunEvents'] ?? []);
        $endOf = function (array $slot) use ($sun) {
            $a = (string) ($slot['anchor'] ?? '');
            if ($a !== '' && isset($sun[$a])) {
                return max(0, min(1440, (int) $sun[$a] + (int) ($slot['offset'] ?? 0)));
            }
            return (int) ($slot['end'] ?? 0);
        };
        $out = [];
        for ($d0 = $from; $d0 <= $to; $d0 += 86400) {
            $wd = (int) date('N', $d0) - 1;
            $prevDay = (array) $week[($wd + 6) % 7];
            $last = $prevDay ? end($prevDay) : null;
            $prevVal = is_array($last) ? (float) ($last['val'] ?? 0) : null;
            $start = 0;
            foreach ((array) $week[$wd] as $slot) {
                $val = (float) ($slot['val'] ?? 0);
                if ($prevVal === null || $val != $prevVal) {
                    $t = $d0 + $start * 60;
                    if ($t >= $from && $t <= $to) { $out[] = [$t, $val]; }
                }
                $prevVal = $val;
                $start = $endOf((array) $slot);
            }
        }
        return $out;
    }

    /** Alle Schaltpunkte eines Symcon-Wochenplans an einem Tag: [[Zeitstempel, Aktionsname]]. */
    private function schedulePoints(array $e, int $d0): array
    {
        $names = [];
        foreach ((array) $e['ScheduleActions'] as $a) { $names[(int) $a['ID']] = (string) $a['Name']; }
        $bit = 1 << ((int) date('N', $d0) - 1);
        $out = [];
        foreach ((array) $e['ScheduleGroups'] as $g) {
            if (((int) $g['Days'] & $bit) === 0) { continue; }
            foreach ((array) $g['Points'] as $pt) {
                $t = $d0 + (int) $pt['Start']['Hour'] * 3600 + (int) $pt['Start']['Minute'] * 60 + (int) ($pt['Start']['Second'] ?? 0);
                $out[] = [$t, $names[(int) $pt['ActionID']] ?? ''];
            }
        }
        usort($out, function ($a, $b) { return $a[0] <=> $b[0]; });
        return $out;
    }

    private function num(float $v): string
    {
        return rtrim(rtrim(number_format($v, 1, ',', ''), '0'), ',');
    }

    /** Name der Aktion, die ein Wochenplan zum Zeitpunkt $t ausloest. */
    private function scheduleAction(array $e, int $t): string
    {
        $names = [];
        foreach ((array) $e['ScheduleActions'] as $a) { $names[(int) $a['ID']] = (string) $a['Name']; }
        $bit = 1 << ((int) date('N', $t) - 1);
        foreach ((array) $e['ScheduleGroups'] as $g) {
            if (((int) $g['Days'] & $bit) === 0) { continue; }
            foreach ((array) $g['Points'] as $pt) {
                if ((int) $pt['Start']['Hour'] === (int) date('G', $t) && (int) $pt['Start']['Minute'] === (int) date('i', $t)) {
                    return $names[(int) $pt['ActionID']] ?? '';
                }
            }
        }
        return (string) IPS_GetName($e['EventID']);
    }

    // ================================================================= Helfer

    private function hint(string $id, int $site, string $area, int $sev, string $title, string $detail, int $since, array $actions): array
    {
        return ['id' => $id, 'site' => $site, 'siteName' => $this->siteName($site), 'siteAbbr' => $this->siteAbbr($site), 'area' => $area, 'sev' => $sev,
                'title' => $title, 'detail' => $detail, 'since' => $since, 'actions' => $actions];
    }

    private function event(string $id, int $t, int $site, string $area, string $title, string $detail, string $kind = '', string $badge = ''): array
    {
        return ['id' => $id, 't' => $t, 'site' => $site, 'siteName' => $this->siteName($site), 'siteAbbr' => $this->siteAbbr($site), 'area' => $area,
                'title' => $title, 'detail' => $detail, 'kind' => $kind, 'badge' => $badge, 'past' => $t < time()];
    }

    /** Alarmanlage scharf? null = nicht konfiguriert, sonst true sobald eine Anzeige-Variable an ist. */
    private function alarmArmed(): ?bool
    {
        $ids = array_filter(array_map('intval', preg_split('/[\s,;]+/', (string) $this->ReadPropertyString('AlarmArmedVars'))));
        $any = false;
        foreach ($ids as $v) {
            if (!@IPS_VariableExists($v)) { continue; }
            $any = true;
            if (GetValue($v)) { return true; }
        }
        return $any ? false : null;
    }

    private function instancesByModule(): array
    {
        $by = [];
        foreach (IPS_GetInstanceList() as $i) {
            $by[IPS_GetInstance($i)['ModuleInfo']['ModuleName']][] = $i;
        }
        return $by;
    }

    /** Zustand eines HomeSuite-Moduls als Feld (PREFIX_GetState). */
    private function state(string $pfx, int $iid): array
    {
        $fn = $pfx . '_GetState';
        if (!function_exists($fn)) { return []; }
        try {
            $j = json_decode((string) @$fn($iid), true);
            return is_array($j) ? $j : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function jsonVar(int $iid, string $ident): array
    {
        $v = @IPS_GetObjectIDByIdent($ident, $iid);
        $j = $v ? json_decode((string) GetValue($v), true) : null;
        return is_array($j) ? $j : [];
    }

    private function changedAt(int $iid, string $ident): int
    {
        $v = @IPS_GetObjectIDByIdent($ident, $iid);
        return $v ? (int) IPS_GetVariable($v)['VariableChanged'] : time();
    }

    /** Naechster Standort ueber dem Objekt; 0 = keiner. */
    private function siteOf(int $id): int
    {
        if ($id <= 0) { return 0; }
        if (isset($this->siteCache[$id])) { return $this->siteCache[$id]; }
        $x = $id; $res = 0;
        for ($n = 0; $x > 0 && $n < 16; $n++) {
            if (@IPS_InstanceExists($x) && (string) IPS_GetInstance($x)['ModuleInfo']['ModuleID'] === self::GUID_HSSP) {
                $cfg = json_decode((string) @IPS_GetConfiguration($x), true);
                if (is_array($cfg) && ($cfg['Kind'] ?? '') === 'Haus') { $res = $x; break; }
            }
            $x = (int) @IPS_GetParent($x);
        }
        if ($res === 0) {
            // Technik ohne Standort (Pool, Warmwasser, Maeher ...) steht am Hauptstandort:
            // dem ersten Standort in Baumreihenfolge.
            $l = $this->siteList();
            $res = $l ? $l[0] : 0;
        }
        return $this->siteCache[$id] = $res;
    }

    private function siteName(int $site): string
    {
        return $site > 0 && @IPS_ObjectExists($site) ? IPS_GetName($site) : 'Anlage';
    }

    /** Kuerzel des Standorts (Eigenschaft Abbr), sonst der Name. */
    private function siteAbbr(int $site): string
    {
        if ($site <= 0 || !@IPS_InstanceExists($site)) { return 'Anlage'; }
        $a = trim((string) @IPS_GetProperty($site, 'Abbr'));
        return $a !== '' ? $a : IPS_GetName($site);
    }

    /** Raum = naechster HSSP ueber der Instanz (Kind Raum), sonst Instanzname. */
    private function roomName(int $iid): string
    {
        $p = (int) @IPS_GetParent($iid);
        if ($p > 0 && @IPS_InstanceExists($p) && (string) IPS_GetInstance($p)['ModuleInfo']['ModuleID'] === self::GUID_HSSP) {
            return IPS_GetName($p);
        }
        return $this->nice($iid);
    }

    /** Anzeigename ohne Zusatz in Klammern ("Buero (Beschattung)" -> "Buero"). */
    private function nice(int $id): string
    {
        return trim((string) preg_replace('/\s*\([^)]*\)\s*$/', '', (string) @IPS_GetName($id)));
    }

    private function isHomeSuite(int $iid): bool
    {
        if ($iid <= 0 || !@IPS_InstanceExists($iid)) { return false; }
        $m = @IPS_GetModule((string) IPS_GetInstance($iid)['ModuleInfo']['ModuleID']);
        return is_array($m) && ($m['LibraryID'] ?? '') === self::LIB_HS;
    }

    private function domainLabel(int $iid): string
    {
        $map = ['AudioZone' => 'Musik', 'AudioZoneBridged' => 'Musik', 'HeatingZone' => 'Heizung', 'LightDevice' => 'Licht',
                'ShadingDevice' => 'Beschattung', 'IrrigationCircuit' => 'Bewässerung', 'ClimateZone' => 'Klima', 'PoolController' => 'Pool'];
        return $map[IPS_GetInstance($iid)['ModuleInfo']['ModuleName']] ?? 'Plan';
    }

    /** "Rollo Esszimmer" - eine Markise heisst Markise. */
    private function shadeLabel(int $iid): string
    {
        $r = $this->roomName($iid);
        return stripos($r, 'markise') !== false ? $r : 'Rollo ' . $r;
    }

    /** Fachmodule schreiben manche Texte ohne Umlaute. */
    private function umlaute(string $s): string
    {
        return str_replace(['Kaelte', 'Waerme', 'Naesse', 'Hitze'], ['Kälte', 'Wärme', 'Nässe', 'Hitze'], $s);
    }

    private function kurzliste(array $names): string
    {
        $names = array_values(array_unique($names));
        $n = count($names);
        return $n <= 3 ? implode(', ', $names) : implode(', ', array_slice($names, 0, 3)) . ' und ' . ($n - 3) . ' weitere';
    }

    private function ensureProfile(): void
    {
        if (!IPS_VariableProfileExists('HSOV.Severity')) {
            IPS_CreateVariableProfile('HSOV.Severity', 1);
        }
        IPS_SetVariableProfileValues('HSOV.Severity', 0, 3, 1);
        IPS_SetVariableProfileAssociation('HSOV.Severity', 0, 'ruhig', '', 0x00CDAB);
        IPS_SetVariableProfileAssociation('HSOV.Severity', 1, 'Hinweis', '', 0x5AA9FF);
        IPS_SetVariableProfileAssociation('HSOV.Severity', 2, 'Warnung', '', 0xF2B441);
        IPS_SetVariableProfileAssociation('HSOV.Severity', 3, 'dringend', '', 0xF2685A);
    }

    private function put(string $ident, $value): void
    {
        $vid = @$this->GetIDForIdent($ident);
        if ($vid && GetValue($vid) !== $value) {
            SetValue($vid, $value);
        }
    }
}

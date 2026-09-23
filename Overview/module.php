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
        $this->RegisterPropertyInteger('AheadHours', 20);

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
        $this->SetTimerInterval('Update', max(15, $this->ReadPropertyInteger('IntervalSeconds')) * 1000);
        $this->SetStatus(102);
    }

    // ================================================================= oeffentlich

    public function Update(): bool
    {
        $now = time();
        $byMod = $this->instancesByModule();
        $hints = [];
        foreach ([$this->hintsShading($byMod), $this->hintsIrrigation($byMod), $this->hintsClimate($byMod),
                  $this->hintsPresence($byMod), $this->hintsHealth($byMod), $this->hintsBattery($byMod)] as $part) {
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
            $row = ['id' => $sid, 'name' => IPS_GetName($sid), 'occupancy' => -1, 'occLabel' => '', 'residents' => '',
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

    private function timeline(array $by, int $now): array
    {
        $from = $now - max(0, $this->ReadPropertyInteger('PastHours')) * 3600;
        $to = $now + max(1, $this->ReadPropertyInteger('AheadHours')) * 3600;
        $ev = [];

        // Rollos: naechste Fahrt je Geraet, zusammengefasst je Standort, Minute und Richtung
        $grp = [];
        foreach ($by['ShadingDevice'] ?? [] as $iid) {
            $st = $this->state('HSSH', $iid);
            $t = (int) ($st['NaechsteFahrt'] ?? 0);
            if ($t < $now || $t > $to) { continue; }
            $dir = (string) ($st['NaechsteRichtung'] ?? '');
            $ziel = (int) ($st['NaechstesZiel'] ?? 0);
            $txt = $dir === 'Auf' ? 'Rollos auf' : ($ziel >= 100 ? 'Rollos zu' : "Rollos auf $ziel %");
            $key = $this->siteOf($iid) . '|' . intdiv($t, 60) . '|' . $txt;
            $grp[$key]['t'] = $t; $grp[$key]['txt'] = $txt; $grp[$key]['site'] = $this->siteOf($iid);
            $grp[$key]['names'][] = $this->roomName($iid);
        }
        foreach ($grp as $key => $g) {
            $ev[] = $this->event('sh-' . md5($key), $g['t'], $g['site'], 'Beschattung', $g['txt'],
                count($g['names']) === 1 ? $g['names'][0] : count($g['names']) . ' Rollos · ' . $this->kurzliste($g['names']));
        }

        // Bewaesserung: Fenster des Wochenplans heute und morgen frueh
        foreach ($by['IrrigationCircuit'] ?? [] as $iid) {
            $st = $this->state('HSIR', $iid);
            $plan = function_exists('HSIR_Manage') ? json_decode((string) @HSIR_Manage($iid, json_encode(['op' => 'getSchedule'])), true) : null;
            $week = is_array($plan) ? ($plan['week'] ?? null) : null;
            if (!is_array($week) || count($week) < 7) { continue; }
            $dur = function_exists('HSIR_GetEffectiveMinutes') ? (int) @HSIR_GetEffectiveMinutes($iid) : 0;
            foreach ([0, 1] as $plus) {
                $day0 = mktime(0, 0, 0, (int) date('n'), (int) date('j') + $plus);
                $wd = (int) date('N', $day0) - 1;
                $prevEnd = 0;
                foreach ((array) $week[$wd] as $slot) {
                    $end = (int) ($slot['end'] ?? 0);
                    if ((int) ($slot['val'] ?? 0) > 0) {
                        $t = $day0 + $prevEnd * 60;
                        if ($t >= $from && $t <= $to) {
                            $skip = !empty($st['RainBlocked']);
                            $grund = $skip ? $this->umlaute(trim((string) preg_replace('/^Gesperrt:\s*/i', '', (string) ($st['LastRun'] ?? '')))) : '';
                            $ev[] = $this->event("ir-$iid-$t", $t, $this->siteOf($iid), 'Bewässerung', $this->nice($iid) . ' bewässern',
                                $skip ? ('entfällt' . ($grund !== '' ? ' · ' . $grund : '')) : (($dur > 0 ? $dur : ($end - $prevEnd)) . ' min'),
                                $skip ? 'skip' : '');
                        }
                    }
                    $prevEnd = $end;
                }
            }
        }

        // Klima: naechster Planwechsel, je Standort und Minute zusammengefasst
        $kg = [];
        foreach ($by['ClimateZone'] ?? [] as $iid) {
            $st = $this->state('HSAC', $iid);
            $t = (int) ($st['NextChange'] ?? 0);
            if ($t >= $now && $t <= $to) {
                $k = $this->siteOf($iid) . '|' . intdiv($t, 60);
                $kg[$k]['t'] = $t; $kg[$k]['site'] = $this->siteOf($iid); $kg[$k]['rooms'][] = $this->roomName($iid);
            }
        }
        foreach ($kg as $k => $g) {
            $n = count($g['rooms']);
            $ev[] = $this->event('ac-' . md5($k), $g['t'], $g['site'], 'Klima', $n === 1 ? 'Klima ' . $g['rooms'][0] : "Klima · $n Räume",
                'Planwechsel' . ($n > 1 ? ' · ' . $this->kurzliste($g['rooms']) : ''));
        }

        // Wochenplan-Ereignisse der HomeSuite-Module (Audio-Zeitplan u. a.)
        foreach (IPS_GetEventList() as $eid) {
            $e = IPS_GetEvent($eid);
            if (!$e['EventActive'] || (int) $e['EventType'] !== 2) { continue; }
            $p = IPS_GetParent($eid);
            if (!$this->isHomeSuite($p)) { continue; }
            // Bewaesserung, Klima und Beschattung stehen schon mit eigenem Text im Fahrplan
            if (in_array(IPS_GetInstance($p)['ModuleInfo']['ModuleName'], ['IrrigationCircuit', 'ClimateZone', 'ShadingDevice'], true)) { continue; }
            $t = (int) $e['NextRun'];
            if ($t < $now || $t > $to) { continue; }
            $ev[] = $this->event("ev-$eid-$t", $t, $this->siteOf($p), $this->domainLabel($p), $this->nice($p), $this->scheduleAction($e, $t));
        }

        usort($ev, function ($a, $b) { return $a['t'] <=> $b['t']; });
        return $ev;
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
        return ['id' => $id, 'site' => $site, 'siteName' => $this->siteName($site), 'area' => $area, 'sev' => $sev,
                'title' => $title, 'detail' => $detail, 'since' => $since, 'actions' => $actions];
    }

    private function event(string $id, int $t, int $site, string $area, string $title, string $detail, string $kind = ''): array
    {
        return ['id' => $id, 't' => $t, 'site' => $site, 'siteName' => $this->siteName($site), 'area' => $area,
                'title' => $title, 'detail' => $detail, 'kind' => $kind, 'past' => $t < time()];
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
        return $this->siteCache[$id] = $res;
    }

    private function siteName(int $site): string
    {
        return $site > 0 && @IPS_ObjectExists($site) ? IPS_GetName($site) : 'Anlage';
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

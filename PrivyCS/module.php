<?php

declare(strict_types=1);

/**
 * Privycs - Ueberwachung der VPN-Plattform (Standorte, Knoten, Alarme).
 *
 * Loest die Einzelskripte privycs.inc.php / #30666 ab. Gelesen wird ausschliesslich ueber
 * GET mit einem Schluessel der Rolle "api" (nur lesen) - eine Haussteuerung braucht keine
 * Schreibrechte auf der VPN-Plattform. Der Schluessel liegt in einer Datei (Rechte 600) und
 * erscheint nirgends im Formular oder im Baum.
 *
 * Was geholt wird:
 *  - Standorte: Bandbreite, Handshake-Alter und Laufzeit je Site-to-Site-Gegenstelle
 *    (EIN Aufruf fuer alle, Zuordnung ueber den oeffentlichen Schluessel, nie ueber den Namen).
 *  - Knoten (Agents): online, Clients, Mbit/s, zuletzt gesehen.
 *  - Gesamtzahlen der Plattform.
 *  - Erweiterungen, sobald die Rolle sie freigibt: Alarme, ausstehende Updates, Health-Score,
 *    Systemwerte je Knoten. Jede Schnittstelle wird einzeln probiert; 403 heisst "noch nicht
 *    freigegeben" und ist kein Fehler - die Variable "Freigaben" zeigt, was schon geht.
 *
 * Fuer die Startseite ("Braucht Sie") legt das Modul eine Hinweisliste als JSON ab; das
 * Overview-Modul liest sie. Die Standort-Variablen tragen Idents PV_<sha1(pubkey)[0..12]> -
 * dieselben wie im Vorgaengerskript, deshalb konnten sie samt IDs uebernommen werden.
 */
class PrivyCS extends IPSModule
{
    private const ST_GETRENNT = 0;
    private const ST_TRAEGE = 1;
    private const ST_VERBUNDEN = 2;

    /** Diese Werte werden archiviert (Verlaeufe auf der IT-Seite). Eingeschaltet beim Anlegen der Variablen. */
    private const ARCHIV = ['PV_Rx', 'PV_Tx', 'PV_Ms', 'PV_Status', 'AG_State', 'AG_Clients', 'AG_Mbps', 'AG_Health',
                            'AG_Latency', 'AG_Loss', 'AG_Cpu', 'AG_Mem', 'AG_Disk'];

    /** Erweiterungen: Schluessel => Pfad. Werden nur abgefragt, wenn nicht als gesperrt bekannt (Neuversuch stuendlich). */
    private const EXTRA = [
        // Alarme selbst: alerts/history?hours=1 in Update() - siehe dort.
        'alertst' => 'alerts/statistics',
        'updates' => 'dashboard/updates',
        'health'  => 'sites/health/ranking/all?time_range=1h',
        'links'   => 'site-to-site/links',
    ];

    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyString('BaseUrl', 'https://app.privycs.com/api/v1');
        $this->RegisterPropertyString('KeyFile', '/var/lib/symcon/scripts/privycs.login');
        $this->RegisterPropertyString('SiteAgent', 'agent-wireguard-site2site');
        $this->RegisterPropertyInteger('IntervalSeconds', 60);
        $this->RegisterPropertyInteger('HandshakeWarn', 180);
        $this->RegisterPropertyInteger('HandshakeDead', 600);
        $this->RegisterPropertyInteger('AgentStaleSeconds', 300);
        $this->RegisterPropertyInteger('HintAfterMinutes', 5);
        $this->RegisterPropertyString('SiteMap', '[]');   // [{Peer:"BellaVista@Site2Site", Site:<HSSP-ID>}] optional
        $this->RegisterTimer('Update', 0, 'HSVP_Update($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->profile();
        $this->RegisterVariableString('Summary', 'Kurzfassung', '', 1);
        $this->RegisterVariableInteger('SitesConnected', 'Standorte verbunden', '', 10);
        $this->RegisterVariableInteger('SitesTotal', 'Standorte gesamt', '', 11);
        $this->RegisterVariableInteger('AgentsOnline', 'Knoten online', '', 12);
        $this->RegisterVariableInteger('AgentsTotal', 'Knoten gesamt', '', 13);
        $this->RegisterVariableInteger('ClientsConnected', 'Clients verbunden', '', 14);
        $this->RegisterVariableInteger('PeersConnected', 'Gegenstellen verbunden', '', 15);
        $this->RegisterVariableInteger('PeersTotal', 'Gegenstellen gesamt', '', 16);
        $this->RegisterVariableInteger('AlertsActive', 'Alarme aktiv', '', 20);
        $this->RegisterVariableInteger('UpdatesPending', 'Systeme mit Updates', '', 21);
        $this->RegisterVariableInteger('UpdatesPackages', 'Updatepakete offen', '', 22);
        $this->RegisterVariableFloat('MbpsTotal', 'Durchsatz gesamt', 'PV.Mbps', 17);
        $this->RegisterVariableString('SitesTable', 'Standorte (Tabelle)', '', 95);
        $this->RegisterVariableString('AgentsTable', 'Knoten (Tabelle)', '', 96);
        $this->RegisterVariableString('Hints', 'Hinweise (JSON)', '', 90);
        $this->RegisterVariableString('Sites', 'Standorte (JSON)', '', 91);
        $this->RegisterVariableString('Agents', 'Knoten (JSON)', '', 92);
        $this->RegisterVariableString('Grants', 'Freigaben', '', 93);
        $this->RegisterVariableString('LastError', 'Letzter Fehler', '', 94);
        $this->ensureLogging();
        foreach (['Hints', 'Sites', 'Agents', 'Grants', 'LastError', 'SitesTable', 'AgentsTable'] as $i) { @IPS_SetHidden((int) $this->GetIDForIdent($i), true); }
        $this->SetTimerInterval('Update', max(30, $this->ReadPropertyInteger('IntervalSeconds')) * 1000);
        $this->SetStatus(is_readable($this->ReadPropertyString('KeyFile')) ? 102 : 201);
    }

    // ================================================================= oeffentlich

    public function Update(): bool
    {
        $now = time();
        $err = [];
        $grants = json_decode((string) $this->GetValue('Grants'), true) ?: [];

        // ---- Standorte (Site-to-Site-Gegenstellen) ----
        $sites = [];
        $r = $this->get('agents/' . rawurlencode($this->ReadPropertyString('SiteAgent')) . '/peers/bandwidth');
        $grants['bandwidth'] = ['code' => $r['code'], 't' => $now];
        if ($r['ok']) {
            foreach ((array) ($r['json']['data'] ?? []) as $p) {
                $s = $this->site($p);
                if ($s !== null) { $sites[] = $s; }
            }
        } else {
            $err[] = 'Standorte: ' . $r['error'];
        }

        // ---- Knoten ----
        $agents = [];
        $r = $this->get('agents/status');
        $grants['agents'] = ['code' => $r['code'], 't' => $now];
        if ($r['ok']) {
            foreach ((array) ($r['json']['agents'] ?? []) as $a) {
                $agents[] = $this->agent($a, $now);
            }
        } else {
            $err[] = 'Knoten: ' . $r['error'];
        }
        $r = $this->get('agents/summary');
        $grants['summary'] = ['code' => $r['code'], 't' => $now];
        if ($r['ok']) {
            $j = $r['json'];
            $this->put('ClientsConnected', (int) ($j['connected_clients'] ?? 0));
            $this->put('PeersConnected', (int) ($j['connected_peers'] ?? 0));
            $this->put('PeersTotal', (int) ($j['total_peers'] ?? 0));
        }

        // ---- Erweiterungen (nur mit Freigabe) ----
        $extra = [];
        foreach (self::EXTRA as $key => $path) {
            $g = $grants[$key] ?? null;
            if ($g && in_array((int) $g['code'], [401, 403, 404], true) && $now - (int) $g['t'] < 3600) { continue; }
            $r = $this->get($path);
            $grants[$key] = ['code' => $r['code'], 't' => $now];
            if ($r['ok']) { $extra[$key] = $r['json']; }
        }
        // Systemwerte je Knoten (nur wenn freigegeben; ein Aufruf je Knoten)
        $g = $grants['system'] ?? null;
        if (!($g && in_array((int) $g['code'], [401, 403, 404], true) && $now - (int) $g['t'] < 3600)) {
            foreach ($agents as &$a) {
                $r = $this->get('agents/' . rawurlencode($a['id']) . '/system/history?limit=1');
                $grants['system'] = ['code' => $r['code'], 't' => $now];
                if (!$r['ok']) { break; }
                $a['system'] = $this->systemRow($r['json']);
            }
            unset($a);
        }
        // Alarme NUR der letzten Stunde (alerts/history?hours=1, ~30 KB) statt alerts/active -
        // die aktive Liste schleppt alle nie aufgeloesten Alarme seit 2025 mit (4,6 MB).
        $r = $this->get('alerts/history?hours=1');
        $grants['alerts'] = ['code' => $r['code'], 't' => $now];
        if ($r['ok']) { $extra['alerts'] = $r['json']; }
        $this->mergeExtras($sites, $agents, $extra);

        // ---- Schreiben ----
        $this->writeSites($sites);
        $this->writeAgents($agents);
        $conn = count(array_filter($sites, function ($s) { return $s['state'] === self::ST_VERBUNDEN; }));
        $this->put('SitesConnected', $conn);
        $this->put('SitesTotal', count($sites));
        $on = count(array_filter($agents, function ($a) { return $a['online']; }));
        $this->put('AgentsOnline', $on);
        $this->put('AgentsTotal', count($agents));
        $alerts = $this->alertList($extra);
        $this->put('AlertsActive', count($alerts));
        $upd = $this->updateList($extra);
        $this->put('UpdatesPending', count($upd));
        $this->put('UpdatesPackages', (int) array_sum(array_map(function ($u) { return (int) ($u['pakete'] ?? 0); }, $upd)));
        $this->put('MbpsTotal', round(array_sum(array_column($agents, 'mbps')), 2));
        $this->writeTables($sites, $agents);

        $hints = $this->hints($sites, $agents, $alerts, $upd, $err, $now);
        $this->put('Hints', json_encode($hints, JSON_UNESCAPED_UNICODE));
        $this->put('Sites', json_encode($sites, JSON_UNESCAPED_UNICODE));
        $this->put('Agents', json_encode($agents, JSON_UNESCAPED_UNICODE));
        $this->put('Grants', json_encode($grants));
        $this->put('LastError', implode(' · ', $err));
        $this->put('Summary', count($sites) ? ($conn . '/' . count($sites) . ' Standorte · ' . $on . '/' . count($agents) . ' Knoten'
            . (count($alerts) ? ' · ' . count($alerts) . ' Alarme' : '')) : ($err ? 'keine Daten' : '—'));
        $this->SetStatus($err && !$sites ? 202 : 102);
        return !$err;
    }

    /** Welche Schnittstellen sind freigegeben? Fuer das Formular und zum Nachsehen. */
    public function GetGrants(): string
    {
        return (string) $this->GetValue('Grants');
    }

    // ================================================================= Abruf

    private function key(): ?string
    {
        $f = $this->ReadPropertyString('KeyFile');
        if (!is_readable($f)) { return null; }
        $k = trim((string) @file_get_contents($f));
        return $k === '' ? null : $k;
    }

    /** GET gegen die Plattform. Liefert ['ok','code','json','error']; der Schluessel verlaesst nie diese Methode. */
    private function get(string $path): array
    {
        $k = $this->key();
        if ($k === null) { return ['ok' => false, 'code' => 0, 'json' => [], 'error' => 'Schlüsseldatei fehlt']; }
        $ch = curl_init(rtrim($this->ReadPropertyString('BaseUrl'), '/') . '/' . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $k, 'Accept: application/json']]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $j = json_decode((string) $raw, true);
        if ($code === 200 && is_array($j)) { return ['ok' => true, 'code' => 200, 'json' => $j, 'error' => '']; }
        $msg = [0 => 'nicht erreichbar', 401 => 'Schlüssel ungültig', 403 => 'nicht freigegeben', 503 => 'Dienst vorübergehend weg'][$code] ?? ('HTTP ' . $code);
        return ['ok' => false, 'code' => $code, 'json' => [], 'error' => $msg];
    }

    // ================================================================= Aufbereitung

    /**
     * Eine Gegenstelle. Zustand allein aus dem Handshake-Alter - Rate 0 heisst nicht "tot",
     * ein ruhender Tunnel ist gesund. Handshake 0 bei Rate 0 in beide Richtungen heisst dagegen
     * "noch nie" (die Schnittstelle meldet dann 0 statt eines Alters) und zaehlt als getrennt;
     * frueher stand dort faelschlich "Verbunden".
     */
    private function site(array $p): ?array
    {
        $pub = (string) ($p['peer_public_key'] ?? '');
        if ($pub === '') { return null; }
        $hs = (int) ($p['last_handshake_seconds_ago'] ?? -1);
        $rx = (int) round((float) ($p['rx_rate_bytes_per_sec'] ?? 0));
        $tx = (int) round((float) ($p['tx_rate_bytes_per_sec'] ?? 0));
        $nie = ($hs < 0) || ($hs === 0 && $rx === 0 && $tx === 0);
        $st = $nie ? self::ST_GETRENNT
            : ($hs <= $this->ReadPropertyInteger('HandshakeWarn') ? self::ST_VERBUNDEN
            : ($hs <= $this->ReadPropertyInteger('HandshakeDead') ? self::ST_TRAEGE : self::ST_GETRENNT));
        $name = (string) ($p['peer_name'] ?? '?');
        return ['key' => 'PV_' . substr(sha1($pub), 0, 12), 'name' => $name, 'short' => preg_replace('/@.*$/', '', $name),
                'state' => $st, 'never' => $nie, 'hs' => $nie ? null : $hs, 'rx' => $rx, 'tx' => $tx,
                'ms' => isset($p['latency_ms']) ? round((float) $p['latency_ms'], 1) : null,
                'resolved' => (bool) ($p['peer_name_resolved'] ?? true)];
    }

    private function agent(array $a, int $now): array
    {
        $seen = strtotime((string) ($a['last_seen'] ?? '')) ?: 0;
        $online = (($a['status'] ?? '') === 'online') && ($now - $seen) <= $this->ReadPropertyInteger('AgentStaleSeconds');
        return ['id' => (string) ($a['agent_id'] ?? '?'), 'online' => $online, 'seen' => $seen,
                'clients' => (int) ($a['connected_clients'] ?? 0), 'endpoints' => (int) ($a['vpn_endpoints'] ?? 0),
                'mbps' => round((float) ($a['bandwidth_mbps'] ?? 0), 2), 'traffic' => (string) ($a['traffic_formatted'] ?? '')];
    }

    /** Letzte Zeile aus system/history - Feldnamen laut Dokumentation, tolerant gelesen. */
    private function systemRow(array $j): ?array
    {
        $rows = $j['data'] ?? $j['history'] ?? (isset($j[0]) ? $j : []);
        $x = is_array($rows) && $rows ? end($rows) : null;
        if (!is_array($x)) { return null; }
        $f = function ($k) use ($x) { return isset($x[$k]) && is_numeric($x[$k]) ? round((float) $x[$k], 1) : null; };
        return ['cpu' => $f('cpu_usage_percent'), 'mem' => $f('memory_used_percent'), 'disk' => $f('disk_usage_percent'),
                'load' => $f('system_load_avg_5m'), 'uptime' => $f('system_uptime_seconds')];
    }

    /** Erweiterungen in Standorte und Knoten einsortieren (Health-Score, Latenz). */
    private function mergeExtras(array &$sites, array &$agents, array $extra): void
    {
        if (isset($extra['health'])) {
            $by = [];
            foreach ((array) ($extra['health']['agents'] ?? []) as $h) { $by[(string) ($h['agent_id'] ?? '')] = $h; }
            foreach ($agents as &$a) {
                $h = $by[$a['id']] ?? null;
                if (!$h) { continue; }
                // Health-Score: KLEINER ist besser (Rang 1 = niedrigster Wert); gewichtet aus
                // Latenz, CPU, Speicher, Verbindungen und Paketverlust.
                $a['health'] = round((float) ($h['health_score'] ?? 0), 1);
                $a['healthState'] = (string) ($h['status'] ?? '');
                $a['rank'] = (int) ($h['rank'] ?? 0);
                $a['latency'] = round((float) ($h['latency_ms'] ?? 0), 1);
                $a['loss'] = round((float) ($h['packet_loss_percent'] ?? 0), 2);
            }
            unset($a);
        }
        // site-to-site/links sind Verbindungen ZWISCHEN den Knoten (FSN-US, FSN-NBG-ARM), nicht
        // die Standorte des Hauses - sie haengen deshalb am Knoten der Seite A.
        if (isset($extra['links'])) {
            foreach ((array) ($extra['links']['links'] ?? []) as $l) {
                if (!is_array($l)) { continue; }
                foreach ($agents as &$a) {
                    if ($a['id'] !== ($l['site_a_agent_id'] ?? '')) { continue; }
                    $a['links'][] = ['name' => (string) ($l['link_name'] ?? ''), 'to' => (string) ($l['site_b_hostname'] ?? ''),
                                     'ok' => !empty($l['peer_connected']) && ($l['status'] ?? '') === 'connected',
                                     'ms' => (int) ($l['latency_ms'] ?? 0), 'err' => (string) ($l['error_message'] ?? '')];
                }
                unset($a);
            }
        }
    }

    private function alertList(array $extra): array
    {
        $out = [];
        foreach ((array) ($extra['alerts']['alerts'] ?? []) as $a) {
            if (!is_array($a) || !empty($a['resolved'])) { continue; }
            // Nur Warnungen und Schlimmeres - "success" (Peer verbunden) ist eine Meldung, kein Alarm.
            if (!in_array(strtolower((string) ($a['severity'] ?? '')), ['warning', 'error', 'critical', 'crit', 'high'], true)) { continue; }
            if ((strtotime((string) ($a['created_at'] ?? '')) ?: 0) < time() - 3600) { continue; }
            $out[] = ['id' => (string) ($a['alert_id'] ?? $a['id'] ?? md5(json_encode($a))), 'sev' => strtolower((string) ($a['severity'] ?? 'warning')),
                      'title' => (string) ($a['title'] ?? $a['alert_type'] ?? 'Alarm'), 'msg' => (string) ($a['message'] ?? ''),
                      't' => strtotime((string) ($a['created_at'] ?? '')) ?: time()];
        }
        return $out;
    }

    private function updateList(array $extra): array
    {
        $out = [];
        foreach ((array) ($extra['updates']['systems'] ?? []) as $u) {
            if (!is_array($u)) { continue; }
            $id = (string) ($u['agent_name'] ?? $u['agent_id'] ?? '?');
            $was = [];
            $pak = (int) ($u['system_updates'] ?? 0);
            if ($pak > 0) { $was[] = $pak . ' Systempakete'; }
            if (!empty($u['agent_update_available'])) { $was[] = 'Agent'; }
            if (!empty($u['crowdsec_update'])) { $was[] = 'CrowdSec'; }
            if (!empty($u['suricata_update'])) { $was[] = 'Suricata'; }
            if (!empty($u['ruleset_stale'])) { $was[] = 'Regelsatz veraltet'; }
            if ($was) { $out[] = ['agent' => $id, 'was' => $was, 'pakete' => $pak]; }
        }
        return $out;
    }

    // ================================================================= Hinweise

    /**
     * Hinweise fuer "Braucht Sie". Ein Standort oder Knoten meldet sich erst, wenn der Zustand
     * laenger als HintAfterMinutes anhaelt - ein verpasster Handshake ist noch kein Befund.
     * Seit wann, merkt sich das Modul in der Standortliste des letzten Laufs.
     */
    private function hints(array &$sites, array $agents, array $alerts, array $upd, array $err, int $now): array
    {
        $prev = [];
        foreach ((array) json_decode((string) $this->GetValue('Sites'), true) as $s) { $prev[$s['key']] = $s; }
        $after = $this->ReadPropertyInteger('HintAfterMinutes') * 60;
        $out = [];
        foreach ($sites as &$s) {
            $p = $prev[$s['key']] ?? null;
            $s['since'] = ($p && (int) $p['state'] === $s['state'] && isset($p['since'])) ? (int) $p['since'] : $now;
            if ($s['state'] === self::ST_VERBUNDEN || $now - $s['since'] < $after) { continue; }
            $out[] = ['id' => 'vpn-' . $s['key'], 'standort' => $s['short'], 'sev' => $s['state'] === self::ST_GETRENNT ? 3 : 2,
                      'title' => 'VPN ' . $s['short'] . ($s['state'] === self::ST_GETRENNT ? ' getrennt' : ' träge'),
                      'detail' => $s['never'] ? 'noch nie verbunden' : ('letzter Handshake vor ' . $this->dauer((int) $s['hs'])),
                      'since' => $s['since']];
        }
        unset($s);
        foreach ($agents as $a) {
            if ($a['online'] || $now - $a['seen'] < $after) { continue; }
            $out[] = ['id' => 'vpnag-' . $a['id'], 'standort' => '', 'sev' => 3, 'title' => 'Privycs-Knoten ' . $a['id'] . ' offline',
                      'detail' => $a['seen'] ? 'zuletzt gesehen vor ' . $this->dauer($now - $a['seen']) : 'nie gesehen', 'since' => $a['seen'] ?: $now];
        }
        // Systemwerte der Knoten (sofern freigegeben): volle Platte oder voller Speicher
        foreach ($agents as $a) {
            $sy = $a['system'] ?? null;
            if (!is_array($sy)) { continue; }
            if ($sy['disk'] !== null && $sy['disk'] >= 90) {
                $out[] = ['id' => 'vpndisk-' . $a['id'], 'standort' => '', 'sev' => $sy['disk'] >= 97 ? 3 : 2,
                          'title' => 'Privycs-Knoten ' . $a['id'] . ': Platte ' . round($sy['disk']) . ' % voll', 'detail' => '', 'since' => $now];
            }
            if ($sy['mem'] !== null && $sy['mem'] >= 95) {
                $out[] = ['id' => 'vpnmem-' . $a['id'], 'standort' => '', 'sev' => 2,
                          'title' => 'Privycs-Knoten ' . $a['id'] . ': Arbeitsspeicher ' . round($sy['mem']) . ' %', 'detail' => '', 'since' => $now];
            }
        }
        foreach ($alerts as $a) {
            $sev = in_array($a['sev'], ['critical', 'crit', 'error'], true) ? 3 : 2;
            $out[] = ['id' => 'vpnal-' . $a['id'], 'standort' => '', 'sev' => $sev, 'title' => 'Privycs: ' . $a['title'],
                      'detail' => mb_substr($a['msg'], 0, 140), 'since' => $a['t']];
        }
        if ($upd) {
            // Ein Hinweis fuer alle Systeme - sechs Zeilen "Updates offen" sagen nicht mehr als eine.
            $out[] = ['id' => 'vpnup', 'standort' => '', 'sev' => 1, 'title' => 'Privycs: Updates offen auf ' . count($upd) . ' System' . (count($upd) > 1 ? 'en' : ''),
                      'detail' => implode(' · ', array_map(function ($u) { return $u['agent'] . ' (' . implode(', ', $u['was']) . ')'; }, $upd)), 'since' => $now];
        }
        foreach ($agents as $a) {
            foreach ($a['links'] ?? [] as $l) {
                if ($l['ok']) { continue; }
                $out[] = ['id' => 'vpnlink-' . $l['name'], 'standort' => '', 'sev' => 3, 'title' => 'Knotenverbindung ' . $l['name'] . ' getrennt',
                          'detail' => $a['id'] . ' → ' . $l['to'] . ($l['err'] !== '' ? ' · ' . $l['err'] : ''), 'since' => $now];
            }
            if (isset($a['healthState']) && $a['healthState'] !== '' && !in_array($a['healthState'], ['optimal', 'good'], true)) {
                $out[] = ['id' => 'vpnhealth-' . $a['id'], 'standort' => '', 'sev' => 2, 'title' => 'Privycs-Knoten ' . $a['id'] . ': ' . $a['healthState'],
                          'detail' => 'Latenz ' . $a['latency'] . ' ms · Verlust ' . $a['loss'] . ' %', 'since' => $now];
            }
        }
        if ($err && !$sites) {
            $out[] = ['id' => 'vpn-api', 'standort' => '', 'sev' => 2, 'title' => 'Privycs nicht abrufbar', 'detail' => implode(' · ', $err), 'since' => $now];
        }
        return $out;
    }

    // ================================================================= Baum

    /** Je Standort eine Kategorie mit Empfang/Senden/Handshake/Laufzeit/Verbindung (Idents wie im Vorgaengerskript). */
    private function writeSites(array $sites): void
    {
        foreach ($sites as $s) {
            $kat = @IPS_GetObjectIDByIdent($s['key'], $this->InstanceID);
            if (!$kat) {
                $kat = IPS_CreateCategory();
                if (!$kat) { continue; }
                IPS_SetParent($kat, $this->InstanceID); IPS_SetIdent($kat, $s['key']);
            }
            if (IPS_GetName($kat) !== $s['name']) { IPS_SetName($kat, $s['name']); }
            $this->setChild($kat, 'PV_Rx', 'Empfang', 1, 'PV.Byterate', $s['rx']);
            $this->setChild($kat, 'PV_Tx', 'Senden', 1, 'PV.Byterate', $s['tx']);
            $this->setChild($kat, 'PV_Hs', 'Handshake', 1, 'PV.Sekunden', $s['hs'] ?? 0);
            if ($s['ms'] !== null) { $this->setChild($kat, 'PV_Ms', 'Laufzeit', 2, 'PV.Latenz', (float) $s['ms']); }
            $this->setChild($kat, 'PV_Status', 'Verbindung', 1, 'PV.Zustand', $s['state']);
        }
    }

    private function writeAgents(array $agents): void
    {
        // Eigene Kennung 'Knoten': 'Agents' ist schon die JSON-Variable - mit derselben Kennung
        // landeten die Knoten am 25.09.2026 als Kinder der VARIABLEN statt in einer Kategorie.
        $root = @IPS_GetObjectIDByIdent('Knoten', $this->InstanceID);
        if (!$root) {
            $root = IPS_CreateCategory();
            if (!$root) { return; }
            IPS_SetParent($root, $this->InstanceID); IPS_SetIdent($root, 'Knoten'); IPS_SetName($root, 'Knoten'); IPS_SetPosition($root, 50);
        }
        // Altbestand unter der Variablen einmalig umhaengen (IDs bleiben)
        $alt = @IPS_GetObjectIDByIdent('Agents', $this->InstanceID);
        if ($alt && IPS_GetObject($alt)['ObjectType'] === 2) {
            foreach (IPS_GetChildrenIDs($alt) as $c) { if (IPS_GetObject($c)['ObjectType'] === 0) { IPS_SetParent($c, $root); } }
        }
        foreach ($agents as $a) {
            $ident = 'AG_' . substr(sha1($a['id']), 0, 12);
            $kat = @IPS_GetObjectIDByIdent($ident, $root);
            if (!$kat) {
                $kat = IPS_CreateCategory();
                if (!$kat) { continue; }
                IPS_SetParent($kat, $root); IPS_SetIdent($kat, $ident);
            }
            if (IPS_GetName($kat) !== $a['id']) { IPS_SetName($kat, $a['id']); }
            $this->setChild($kat, 'AG_Online', 'Online', 0, '~Switch', $a['online']);
            // Zustand 0/2 fuer den Fluss im Netz-Modus (Ring rot/gruen, Bahnen an/aus)
            $this->setChild($kat, 'AG_State', 'Zustand', 1, 'PV.Zustand', $a['online'] ? self::ST_VERBUNDEN : self::ST_GETRENNT);
            $this->setChild($kat, 'AG_Clients', 'Clients', 1, '', $a['clients']);
            $this->setChild($kat, 'AG_Mbps', 'Bandbreite', 2, 'PV.Mbps', $a['mbps']);
            $this->setChild($kat, 'AG_Seen', 'Zuletzt gesehen', 1, '~UnixTimestamp', $a['seen']);
            if (isset($a['health'])) {
                $this->setChild($kat, 'AG_Health', 'Health-Score (kleiner = besser)', 2, 'PV.Score', $a['health']);
                $this->setChild($kat, 'AG_HealthState', 'Zustand', 3, '', $a['healthState']);
                $this->setChild($kat, 'AG_Latency', 'Latenz zum Gateway', 2, 'PV.Latenz', $a['latency']);
                $this->setChild($kat, 'AG_Loss', 'Paketverlust', 2, 'PV.Prozent', $a['loss']);
            }
            $sys = $a['system'] ?? null;
            if (is_array($sys)) {
                if ($sys['cpu'] !== null)  { $this->setChild($kat, 'AG_Cpu', 'CPU', 2, 'PV.Prozent', $sys['cpu']); }
                if ($sys['mem'] !== null)  { $this->setChild($kat, 'AG_Mem', 'Arbeitsspeicher', 2, 'PV.Prozent', $sys['mem']); }
                if ($sys['disk'] !== null) { $this->setChild($kat, 'AG_Disk', 'Platte', 2, 'PV.Prozent', $sys['disk']); }
            }
        }
    }

    private function setChild(int $parent, string $ident, string $name, int $type, string $profile, $value): void
    {
        $id = @IPS_GetObjectIDByIdent($ident, $parent);
        if (!$id) {
            $id = IPS_CreateVariable($type);
            if (!$id) { return; }
            IPS_SetParent($id, $parent); IPS_SetIdent($id, $ident); IPS_SetName($id, $name);
            if (in_array($ident, self::ARCHIV, true)) {
                // Symcon vergibt geloeschte IDs neu - unter der neuen ID kann noch das Archiv eines
                // Vorgaengers liegen (25.09.2026: 8 Werte mit Eintraegen vom 24.08./03.09., die
                // Zeitleiste zeigte privycs-nbg-arm deshalb "getrennt seit 3.9."). Eine frisch
                // angelegte Variable hat keine Geschichte: vorher leeren.
                $ac = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}')[0] ?? 0;
                if ($ac) { @AC_DeleteVariableData($ac, $id, 0, time()); }
                $this->logOn($id);
            }
        }
        if ($profile !== '' && IPS_VariableProfileExists($profile) && IPS_GetVariable($id)['VariableCustomProfile'] !== $profile
            && IPS_GetVariableProfile($profile)['ProfileType'] === IPS_GetVariable($id)['VariableType']) {
            IPS_SetVariableCustomProfile($id, $profile);
        }
        if (GetValue($id) !== $value) { SetValue($id, $value); }
    }

    /** Archivierung einschalten (nur einschalten, nie aus - ausschalten LOESCHT die Daten). */
    private function logOn(int $id): void
    {
        $ac = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}')[0] ?? 0;
        if ($ac && !AC_GetLoggingStatus($ac, $id)) { AC_SetLoggingStatus($ac, $id, true); IPS_ApplyChanges($ac); }
    }

    /** Bestehende Variablen nachziehen (einmal je ApplyChanges). */
    private function ensureLogging(): void
    {
        $walk = function (int $p) use (&$walk) {
            foreach (IPS_GetChildrenIDs($p) as $c) {
                $o = IPS_GetObject($c);
                if ($o['ObjectType'] === 2 && in_array($o['ObjectIdent'], self::ARCHIV, true)) { $this->logOn($c); }
                elseif ($o['ObjectType'] === 0) { $walk($c); }
            }
        };
        $walk($this->InstanceID);
    }

    /** Tabellen fuer das Tabellen-Widget: Zeile 0 = Kopf. */
    private function writeTables(array $sites, array $agents): void
    {
        $rate = function (int $b): string {
            if ($b >= 1048576) { return number_format($b / 1048576, 1, ',', '.') . ' MB/s'; }
            if ($b >= 1024) { return number_format($b / 1024, 1, ',', '.') . ' kB/s'; }
            return $b . ' B/s';
        };
        $st = [self::ST_GETRENNT => 'getrennt', self::ST_TRAEGE => 'träge', self::ST_VERBUNDEN => 'verbunden'];
        $rows = [['Standort', 'Zustand', 'Handshake', '↓', '↑', 'Latenz']];
        foreach ($sites as $s) {
            $rows[] = [$s['short'], $st[$s['state']], $s['never'] ? 'nie' : $this->dauer((int) $s['hs']), $rate($s['rx']), $rate($s['tx']),
                       $s['ms'] !== null ? number_format((float) $s['ms'], 1, ',', '') . ' ms' : '—'];
        }
        $this->put('SitesTable', json_encode($rows, JSON_UNESCAPED_UNICODE));
        $rows = [['Knoten', 'Zustand', 'Health', 'Clients', 'Mbit/s', 'Latenz', 'CPU', 'RAM', 'Platte']];
        foreach ($agents as $a) {
            $sy = $a['system'] ?? [];
            $rows[] = [$a['id'], $a['online'] ? ($a['healthState'] ?? 'online') : 'offline', isset($a['health']) ? number_format($a['health'], 1, ',', '') : '—',
                       (string) $a['clients'], number_format($a['mbps'], 2, ',', ''), isset($a['latency']) ? number_format($a['latency'], 1, ',', '') . ' ms' : '—',
                       isset($sy['cpu']) ? round($sy['cpu']) . ' %' : '—', isset($sy['mem']) ? round($sy['mem']) . ' %' : '—', isset($sy['disk']) ? round($sy['disk']) . ' %' : '—'];
        }
        $this->put('AgentsTable', json_encode($rows, JSON_UNESCAPED_UNICODE));
    }

    private function put(string $ident, $value): void
    {
        $id = @$this->GetIDForIdent($ident);
        if ($id && GetValue($id) !== $value) { SetValue($id, $value); }
    }

    private function dauer(int $s): string
    {
        if ($s < 120) { return $s . ' s'; }
        if ($s < 7200) { return round($s / 60) . ' min'; }
        if ($s < 172800) { return round($s / 3600) . ' h'; }
        return round($s / 86400) . ' Tagen';
    }

    private function profile(): void
    {
        if (!IPS_VariableProfileExists('PV.Byterate')) {
            IPS_CreateVariableProfile('PV.Byterate', 1); IPS_SetVariableProfileText('PV.Byterate', '', ' B/s');
        }
        if (!IPS_VariableProfileExists('PV.Sekunden')) {
            IPS_CreateVariableProfile('PV.Sekunden', 1); IPS_SetVariableProfileText('PV.Sekunden', '', ' s');
        }
        if (!IPS_VariableProfileExists('PV.Latenz')) {
            IPS_CreateVariableProfile('PV.Latenz', 2); IPS_SetVariableProfileText('PV.Latenz', '', ' ms'); IPS_SetVariableProfileDigits('PV.Latenz', 1);
        }
        if (!IPS_VariableProfileExists('PV.Mbps')) {
            IPS_CreateVariableProfile('PV.Mbps', 2); IPS_SetVariableProfileText('PV.Mbps', '', ' Mbit/s'); IPS_SetVariableProfileDigits('PV.Mbps', 2);
        }
        if (!IPS_VariableProfileExists('PV.Score')) {
            IPS_CreateVariableProfile('PV.Score', 2); IPS_SetVariableProfileDigits('PV.Score', 1);
        }
        if (!IPS_VariableProfileExists('PV.Prozent')) {
            IPS_CreateVariableProfile('PV.Prozent', 2); IPS_SetVariableProfileText('PV.Prozent', '', ' %');
            IPS_SetVariableProfileDigits('PV.Prozent', 1); IPS_SetVariableProfileValues('PV.Prozent', 0, 100, 0);
        }
        if (!IPS_VariableProfileExists('PV.Zustand')) {
            IPS_CreateVariableProfile('PV.Zustand', 1);
            IPS_SetVariableProfileAssociation('PV.Zustand', self::ST_GETRENNT, 'Getrennt', '', 0xE04B4B);
            IPS_SetVariableProfileAssociation('PV.Zustand', self::ST_TRAEGE, 'Träge', '', 0xE0A030);
            IPS_SetVariableProfileAssociation('PV.Zustand', self::ST_VERBUNDEN, 'Verbunden', '', 0x00CDAB);
        }
    }

    public function GetConfigurationForm()
    {
        $g = json_decode((string) @$this->GetValue('Grants'), true) ?: [];
        $rows = [];
        foreach ($g as $k => $v) {
            $rows[] = ['Schnittstelle' => $k, 'Stand' => ((int) $v['code'] === 200 ? 'freigegeben' : ((int) $v['code'] === 403 ? 'nicht freigegeben' : 'HTTP ' . $v['code']))];
        }
        return json_encode([
            'elements' => [
                ['type' => 'Label', 'caption' => 'Liest die Privycs-Plattform nur lesend (Schlüssel der Rolle api). Der Schlüssel liegt in einer Datei und erscheint nie im Formular.'],
                ['type' => 'ValidationTextBox', 'name' => 'BaseUrl', 'caption' => 'API-Adresse'],
                ['type' => 'ValidationTextBox', 'name' => 'KeyFile', 'caption' => 'Schlüsseldatei'],
                ['type' => 'ValidationTextBox', 'name' => 'SiteAgent', 'caption' => 'Site-to-Site-Knoten'],
                ['type' => 'NumberSpinner', 'name' => 'IntervalSeconds', 'caption' => 'Abruf alle', 'suffix' => 's', 'minimum' => 30],
                ['type' => 'NumberSpinner', 'name' => 'HandshakeWarn', 'caption' => 'Träge ab Handshake-Alter', 'suffix' => 's'],
                ['type' => 'NumberSpinner', 'name' => 'HandshakeDead', 'caption' => 'Getrennt ab', 'suffix' => 's'],
                ['type' => 'NumberSpinner', 'name' => 'AgentStaleSeconds', 'caption' => 'Knoten offline, wenn nicht gesehen seit', 'suffix' => 's'],
                ['type' => 'NumberSpinner', 'name' => 'HintAfterMinutes', 'caption' => 'Hinweis erst nach', 'suffix' => 'min'],
            ],
            'actions' => [
                ['type' => 'Button', 'caption' => 'Jetzt abrufen', 'onClick' => 'HSVP_Update($id);'],
                ['type' => 'List', 'name' => 'GrantList', 'caption' => 'Freigaben der Schnittstellen', 'rowCount' => 9,
                 'columns' => [['caption' => 'Schnittstelle', 'name' => 'Schnittstelle', 'width' => '200px'], ['caption' => 'Stand', 'name' => 'Stand', 'width' => 'auto']],
                 'values' => $rows],
            ],
            'status' => [
                ['code' => 102, 'icon' => 'active', 'caption' => 'Daten aktuell'],
                ['code' => 201, 'icon' => 'error', 'caption' => 'Schlüsseldatei fehlt oder ist nicht lesbar'],
                ['code' => 202, 'icon' => 'error', 'caption' => 'Abruf fehlgeschlagen'],
            ],
        ]);
    }
}

<?php

declare(strict_types=1);

/**
 * DeviceHealth — Gesundheit der Geraete, markenuebergreifend.
 *
 * Beantwortet drei Fragen, die der BatteryManager bewusst NICHT beantwortet:
 *   1. Ist das Geraet erreichbar?          (CCU: UNREACH / STICKY_UNREACH, sonst: Stille)
 *   2. Steht eine Konfiguration an?        (CCU: CONFIG_PENDING)
 *   3. Wie gut ist die Funkstrecke?        (CCU: rssiInfo, bester Empfang je Geraet)
 *
 * Der BatteryManager bleibt fuer Batterien zustaendig; hier wird LOWBAT nur als Hinweis
 * mitgefuehrt, nie als Zustand gewertet. Zwei Module, zwei Fragen — keine Doppelbewertung.
 *
 * Ersetzt die IPSLibrary-Komponente IPSHomematic (HTML-Tabellen fuer Servicemeldungen und
 * RSSI). Statt HTML entstehen JSON-Register und JSON-Tabellen, die das LiveViewBuilder-
 * table-Widget direkt anzeigt.
 *
 * Der Scan laeuft NICHT im Kernel-Thread: HSDH_Update() stoesst ein Worker-Skript an
 * (gleiches Muster wie BatteryManager und RainRadar), der eigentliche Durchlauf mit
 * CCU-Abfragen und Instanz-Scan passiert im Skript-Thread.
 */
class DeviceHealth extends IPSModule
{
    // Zustaende, aufsteigend nach Dringlichkeit — die Reihenfolge ist die Bewertung.
    private const ST_OK      = 0;
    private const ST_UNKNOWN = 1;  // nichts bekannt (keine Variable, keine Aussage)
    private const ST_WEAK    = 2;  // Funk schwach, laeuft aber
    private const ST_CONFIG  = 3;  // Konfiguration steht zur Uebertragung an
    private const ST_SILENT  = 4;  // meldet sich nicht mehr
    private const ST_FAULT   = 5;  // Instanz meldet Fehler
    private const ST_UNREACH = 6;  // CCU sagt: nicht erreichbar

    /** Meldungstyp -> Severity-Chip des LVB-Widgets (gleiche Farben und Filter). */
    private const SEVERITY = [
        'ERROR' => 'ERROR', 'FAULT_REPORTING' => 'ERROR', 'SABOTAGE' => 'ERROR',
        'UNREACH' => 'WARNING', 'STICKY_UNREACH' => 'WARNING', 'LOWBAT' => 'WARNING',
        'LOW_BAT' => 'WARNING', 'DUTYCYCLE' => 'WARNING', 'DUTY_CYCLE' => 'WARNING',
        'CONFIG_PENDING' => 'NOTIFY', 'UPDATE_PENDING' => 'NOTIFY',
    ];

    /** 65536 ist in rssiInfo der Platzhalter fuer "kein Wert", nicht etwa ein Messwert. */
    private const RSSI_LEER = 65536;

    /** true nur waehrend HSDH_Scan() — dann wandert der Fortschritt live in die Variable. */
    private $emitProgress = false;

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger('Interval', 15);          // Scan-Takt in MINUTEN (0 = aus)
        $this->RegisterPropertyString('CcuIp', '');              // leer = CCU nicht fragen
        $this->RegisterPropertyString('CcuPorts', '2001,2010');  // BidCos-RF, HmIP
        $this->RegisterPropertyInteger('WeakRssiDbm', -80);      // schlechter als dieser Wert => schwacher Funk
        //   HomeMatic-Praxis: ueber -70 dBm gut, -70 bis -80 brauchbar, darunter kritisch.
        $this->RegisterPropertyInteger('SilentDays', 7);         // so lange still => meldet sich nicht (0 = aus)
        // Nur diese Systeme werden auf Stille beurteilt. Somfy/Harmony/IRTrans werden nur
        // BEFEHLT - sie schweigen im Normalbetrieb, und "still" waere dort eine Falschmeldung.
        $this->RegisterPropertyString('SilentSystems', 'HomeMatic,Z-Wave,Zigbee,Shelly');
        $this->RegisterPropertyBoolean('ScanInstances', true);   // generischer Instanz-Scan (Z-Wave, Zigbee, ...)
        $this->RegisterPropertyString('IgnoreModules', '');      // Komma-Liste Modulnamen, die uebersprungen werden
        $this->RegisterPropertyString('ExcludeInstances', '[]'); // Liste [{InstanceID:int}]
        $this->RegisterPropertyBoolean('CreateLinks', true);     // Links im Baum pflegen
        $this->RegisterPropertyInteger('RootCategory', 0);       // 0 = unter der Instanz

        $this->maybeStatusProfile();

        $this->RegisterVariableInteger('Total', 'Geräte beobachtet', '', 10);
        $this->RegisterVariableInteger('Ok', 'In Ordnung', '', 20);
        $this->RegisterVariableInteger('Unreachable', 'Nicht erreichbar', '', 30);
        $this->RegisterVariableInteger('Faulted', 'Instanz im Fehler', '', 40);
        $this->RegisterVariableInteger('Silent', 'Meldet sich nicht', '', 50);
        $this->RegisterVariableInteger('ConfigPending', 'Konfiguration steht an', '', 60);
        $this->RegisterVariableInteger('WeakSignal', 'Schwacher Funk', '', 70);
        $this->RegisterVariableInteger('Attention', 'Handlungsbedarf', '', 80);
        $this->RegisterVariableInteger('OkPercent', 'OK-Anteil', '~Intensity.100', 85);
        $this->RegisterVariableInteger('Status', 'Gesamtstatus', 'HSDH.Status', 90);
        $this->RegisterVariableString('Register', 'Register (JSON)', '', 100);
        $this->RegisterVariableString('Table', 'Tabelle (JSON)', '', 110);
        $this->RegisterVariableString('RadioTable', 'Funkstrecken (JSON)', '', 120);
        // Die CCU-Meldungen in genau der Form, die das LVB-Widget msglog erwartet.
        // Seit 19.09.2026 ist DIESE Variable die einzige Quelle: vorher fragte das Widget
        // bei JEDEM Poll selbst die CCU (zwei XML-RPC-Aufrufe, ungepuffert).
        $this->RegisterVariableString('CcuMessages', 'CCU-Meldungen (JSON)', '', 125);
        $this->RegisterVariableInteger('LastRun', 'Letzter Scan', '~UnixTimestamp', 130);
        $this->RegisterVariableInteger('Progress', 'Scan-Fortschritt', '', 140);

        $this->RegisterTimer('Scan', 0, 'HSDH_Update($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->maybeStatusProfile();
        $this->ensureWorker();
        $min = max(0, $this->ReadPropertyInteger('Interval'));
        $this->SetTimerInterval('Scan', $min * 60 * 1000);
    }

    /** HSDH.Status — dieselbe Farbsprache wie BATT.Status, damit beide Kacheln gleich sprechen. */
    private function maybeStatusProfile(): void
    {
        if (!IPS_VariableProfileExists('HSDH.Status')) {
            IPS_CreateVariableProfile('HSDH.Status', 1);
        }
        IPS_SetVariableProfileValues('HSDH.Status', 0, 6, 1);
        IPS_SetVariableProfileAssociation('HSDH.Status', self::ST_OK,      'In Ordnung',            '', 0x2ECC71);
        IPS_SetVariableProfileAssociation('HSDH.Status', self::ST_UNKNOWN, 'Unbekannt',             '', 0x95A5A6);
        IPS_SetVariableProfileAssociation('HSDH.Status', self::ST_WEAK,    'Schwacher Funk',        '', 0xF1C40F);
        IPS_SetVariableProfileAssociation('HSDH.Status', self::ST_CONFIG,  'Konfiguration steht an', '', 0xF39C12);
        IPS_SetVariableProfileAssociation('HSDH.Status', self::ST_SILENT,  'Meldet sich nicht',     '', 0xF2685A);
        IPS_SetVariableProfileAssociation('HSDH.Status', self::ST_FAULT,   'Instanz im Fehler',     '', 0xE74C3C);
        IPS_SetVariableProfileAssociation('HSDH.Status', self::ST_UNREACH, 'Nicht erreichbar',      '', 0xC0392B);
    }

    /**
     * Worker-Skript — der Scan fragt die CCU und laeuft ueber alle Instanzen. Beides gehoert
     * nicht in den Kernel-Thread: eine haengende CCU wuerde ihn blockieren.
     */
    private function ensureWorker(): void
    {
        $wid = @IPS_GetObjectIDByIdent('Worker', $this->InstanceID);
        if ($wid === false) {
            $wid = IPS_CreateScript(0);
            IPS_SetParent($wid, $this->InstanceID);
            IPS_SetIdent($wid, 'Worker');
            IPS_SetName($wid, 'DeviceHealth Worker (async)');
            @IPS_SetHidden($wid, true);
        }
        $iid = (int) $this->InstanceID;
        $code = "<?php\n"
            . "// AUTOGENERIERT von DeviceHealth (ensureWorker). Laeuft im Skript-Thread\n"
            . "// (nicht im Kernel) -> CCU-Abfragen und Instanz-Scan sind hier sicher.\n"
            . "// NICHT haendisch aendern.\n"
            . "HSDH_Scan({$iid});\n";
        IPS_SetScriptContent($wid, $code);
    }

    /** HSDH_Update — Timer/Knopf: stoesst den Worker an und kehrt sofort zurueck. */
    public function Update(): void
    {
        $wid = @IPS_GetObjectIDByIdent('Worker', $this->InstanceID);
        if ($wid === false) { $this->ensureWorker(); $wid = @IPS_GetObjectIDByIdent('Worker', $this->InstanceID); }
        if ($wid !== false) { @$this->SetValue('Progress', 1); IPS_RunScript($wid); }
    }

    /** HSDH_Scan — der eigentliche Durchlauf. Nur aus dem Worker-Thread aufrufen. */
    public function Scan(): void
    {
        $this->emitProgress = true;
        try {
            $res = $this->computeDevices();
            $this->setProg(90);
            $this->writeOutputs($res, true);
        } catch (\Throwable $e) {
            IPS_LogMessage('DeviceHealth', 'Scan-Fehler: ' . $e->getMessage());
        } finally {
            $this->emitProgress = false;
            @$this->SetValue('Progress', 0);
        }
    }

    /** HSDH_Preview — read-only: rechnet durch und gibt das Register zurueck, ohne etwas zu schreiben. */
    public function Preview(): string
    {
        try {
            return $this->buildRegister($this->computeDevices());
        } catch (\Throwable $e) {
            return json_encode(['error' => $e->getMessage()]);
        }
    }

    /** HSDH_CcuMeldungen — Diagnose: was die CCU gerade an Servicemeldungen fuehrt, roh. */
    public function CcuMeldungen(): string
    {
        $ccu = $this->ccuStand();
        return json_encode(['ip' => $this->ReadPropertyString('CcuIp'), 'erreicht' => $ccu['ok'],
            'messages' => $ccu['liste'], 'count' => count($ccu['liste'])],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function setProg($p): void
    {
        if ($this->emitProgress) { @$this->SetValue('Progress', max(1, min(99, (int) $p))); }
    }

    // ==================================================================================
    //  Kern
    // ==================================================================================

    /**
     * Sammelt alle beobachteten Geraete und bewertet sie.
     *
     * Zwei Quellen, die einander ergaenzen: die CCU weiss ueber HomeMatic Bescheid (und nur
     * sie — in IP-Symcon steht UNREACH nicht), der Instanz-Scan deckt alles andere ab.
     * Zusammengefuehrt wird ueber die Seriennummer im Feld "Address" der HomeMatic-Instanzen.
     *
     * @return array{ts:int,counts:array,devices:list<array>,funk:list<array>}
     */
    private function computeDevices(): array
    {
        $now = time();
        $ccu = $this->ccuStand();
        $this->setProg(25);
        $rssi = $this->ccuRssi();
        $this->setProg(45);

        $stillSek = max(0, $this->ReadPropertyInteger('SilentDays')) * 86400;
        $schwach  = $this->ReadPropertyInteger('WeakRssiDbm');
        $ignore   = array_filter(array_map('trim', explode(',', $this->ReadPropertyString('IgnoreModules'))));
        $ausListe = [];
        foreach ((array) json_decode($this->ReadPropertyString('ExcludeInstances'), true) as $e) {
            if (isset($e['InstanceID'])) { $ausListe[(int) $e['InstanceID']] = true; }
        }

        $geraete = [];
        $gesehen = [];   // Seriennummer => Index in $geraete

        if ($this->ReadPropertyBoolean('ScanInstances')) {
            $alle = IPS_GetInstanceList();
            $n = max(1, count($alle));
            $i = 0;
            foreach ($alle as $iid) {
                if ((++$i % 40) === 0) { $this->setProg(45 + (int) (35 * $i / $n)); }
                if (isset($ausListe[$iid])) { continue; }
                $inst = IPS_GetInstance($iid);
                if ((int) $inst['ConnectionID'] === 0) { continue; }   // ohne Datenkette kein Feldgeraet
                $modul = (string) ($inst['ModuleInfo']['ModuleName'] ?? '');
                if ($modul === '' || in_array($modul, $ignore, true)) { continue; }

                $kinder = IPS_GetChildrenIDs($iid);
                if (!$kinder) { continue; }                             // Kanallose Huelle: nichts zu bewerten

                $letzte = 0;
                $hatVar = false;
                foreach ($kinder as $k) {
                    if (!IPS_VariableExists($k)) { continue; }
                    $hatVar = true;
                    $v = IPS_GetVariable($k);
                    $letzte = max($letzte, (int) $v['VariableUpdated']);
                }
                if (!$hatVar) { continue; }

                $serie = $this->serieVon($iid);
                $status = (int) $inst['InstanceStatus'];

                $eintrag = [
                    'id'     => $iid,
                    'name'   => IPS_GetName($iid),
                    'ort'    => $this->ortVon($iid),
                    'system' => $this->systemVon($modul),
                    'modul'  => $modul,
                    'serie'  => $serie,
                    'letzte' => $letzte,
                    'status' => $status,
                    'rssi'   => null,
                    'hinweis' => [],
                    'zustand' => self::ST_OK,
                ];

                // Instanzstatus: 102 = aktiv. Alles ab 200 ist ein Fehler, 104 = inaktiv (gewollt).
                if ($status >= 200) {
                    $eintrag['zustand'] = self::ST_FAULT;
                    $eintrag['hinweis'][] = 'Instanzstatus ' . $status;
                } elseif ($status === 104) {
                    continue;                                           // bewusst deaktiviert: kein Befund
                }

                // Stille wird hier NICHT bewertet - erst nach dem Zusammenfuehren der Kanaele.
                // Ein HomeMatic-Geraet hat Kanaele, die jahrelang schweigen (ein nie benutzter
                // Taster), waehrend ein anderer im Minutentakt sendet. Wer je Kanal urteilt,
                // erklaert genau die Geraete fuer tot, die gerade eben noch gesprochen haben.

                if ($serie !== '' && isset($gesehen[$serie])) {
                    // HomeMatic meldet ein physisches Geraet als mehrere Kanalinstanzen.
                    $alt = &$geraete[$gesehen[$serie]];
                    $alt['letzte'] = max($alt['letzte'], $letzte);
                    if ($eintrag['zustand'] > $alt['zustand']) {
                        $alt['zustand'] = $eintrag['zustand'];
                        $alt['hinweis'] = array_values(array_unique(array_merge($alt['hinweis'], $eintrag['hinweis'])));
                    }
                    $alt['kanaele'] = ($alt['kanaele'] ?? 1) + 1;
                    unset($alt);
                    continue;
                }
                $eintrag['kanaele'] = 1;
                $geraete[] = $eintrag;
                if ($serie !== '') { $gesehen[$serie] = count($geraete) - 1; }
            }
        }
        $this->setProg(80);

        // CCU-Befunde einarbeiten. Eine Seriennummer, die IP-Symcon nicht kennt, kommt als
        // eigener Eintrag dazu - sonst verschwaende man genau die Meldung, die zaehlt.
        foreach ($ccu['meldungen'] as $serie => $typen) {
            $idx = $gesehen[$serie] ?? null;
            if ($idx === null) {
                $geraete[] = ['id' => 0, 'name' => $serie, 'ort' => '', 'system' => 'HomeMatic',
                    'modul' => '', 'serie' => $serie, 'letzte' => 0, 'status' => 0, 'rssi' => null,
                    'hinweis' => ['nur der CCU bekannt'], 'zustand' => self::ST_OK, 'kanaele' => 0];
                $idx = count($geraete) - 1;
                $gesehen[$serie] = $idx;
            }
            foreach ($typen as $typ) {
                if ($typ === 'UNREACH' || $typ === 'STICKY_UNREACH') {
                    $geraete[$idx]['zustand'] = max($geraete[$idx]['zustand'], self::ST_UNREACH);
                    $geraete[$idx]['hinweis'][] = ($typ === 'STICKY_UNREACH') ? 'war nicht erreichbar' : 'nicht erreichbar';
                } elseif ($typ === 'CONFIG_PENDING') {
                    $geraete[$idx]['zustand'] = max($geraete[$idx]['zustand'], self::ST_CONFIG);
                    $geraete[$idx]['hinweis'][] = 'Konfiguration steht zur Übertragung an';
                } elseif ($typ === 'LOWBAT' || $typ === 'LOW_BAT') {
                    // Batterien gehoeren dem BatteryManager - hier nur als Hinweis, nie als Zustand.
                    $geraete[$idx]['hinweis'][] = 'Batterie schwach (siehe Batterien)';
                } else {
                    $geraete[$idx]['hinweis'][] = $typ;
                }
            }
        }

        // Stille — JETZT, auf dem zusammengefuehrten Stand, und nur fuer Systeme, die von
        // sich aus melden. Ein Somfy-Rollo oder ein Harmony-Geraet wird nur BEFEHLT; es
        // schweigt im Normalbetrieb und ist trotzdem kerngesund.
        $melder = array_filter(array_map('trim', explode(',', $this->ReadPropertyString('SilentSystems'))));
        foreach ($geraete as $k => $g) {
            if ($stillSek <= 0 || $g['letzte'] <= 0) { continue; }
            if ($melder && !in_array($g['system'], $melder, true)) { continue; }
            if (($now - $g['letzte']) <= $stillSek) { continue; }
            if ($g['zustand'] >= self::ST_SILENT) { continue; }
            $geraete[$k]['zustand'] = self::ST_SILENT;
            $geraete[$k]['hinweis'][] = 'still seit ' . $this->alterText($g['letzte'], $now);
        }

        // Funkqualitaet: schwaechste Strecke je Geraet.
        foreach ($geraete as $k => $g) {
            if ($g['serie'] === '' || !isset($rssi[$g['serie']])) { continue; }
            $geraete[$k]['rssi'] = $rssi[$g['serie']];
            if ($rssi[$g['serie']] < $schwach && $geraete[$k]['zustand'] < self::ST_WEAK) {
                $geraete[$k]['zustand'] = self::ST_WEAK;
                $geraete[$k]['hinweis'][] = $rssi[$g['serie']] . ' dBm';
            }
        }

        // Ein Geraet ohne jede Aussage ist nicht "in Ordnung", es ist unbekannt.
        foreach ($geraete as $k => $g) {
            if ($g['zustand'] === self::ST_OK && $g['letzte'] === 0 && $g['rssi'] === null) {
                $geraete[$k]['zustand'] = self::ST_UNKNOWN;
            }
            $geraete[$k]['hinweis'] = array_values(array_unique($g['hinweis']));
        }

        usort($geraete, function ($a, $b) {
            if ($a['zustand'] !== $b['zustand']) { return $b['zustand'] <=> $a['zustand']; }
            return strcasecmp($a['name'], $b['name']);
        });

        $z = ['total' => count($geraete), 'ok' => 0, 'unknown' => 0, 'weak' => 0,
              'config' => 0, 'silent' => 0, 'fault' => 0, 'unreach' => 0];
        foreach ($geraete as $g) { $z[$this->zustandKey($g['zustand'])]++; }
        $z['attention'] = $z['unreach'] + $z['fault'] + $z['silent'] + $z['config'] + $z['weak'];

        return ['ts' => $now, 'counts' => $z, 'devices' => $geraete,
                'funk' => $this->funkListe($rssi, $geraete),
                'ccuMsgs' => $ccu['liste'] ?? [], 'ccuOk' => $ccu['ok']];
    }

    // ==================================================================================
    //  CCU
    // ==================================================================================

    /** @return list<int> */
    private function ccuPorts(): array
    {
        $out = [];
        foreach (explode(',', $this->ReadPropertyString('CcuPorts')) as $p) {
            $p = (int) trim($p);
            if ($p > 0 && $p < 65536) { $out[] = $p; }
        }
        return $out ?: [2001, 2010];
    }

    /**
     * Servicemeldungen der CCU, nach Seriennummer gebuendelt.
     *
     * @return array{meldungen:array<string,list<string>>,ok:bool}
     */
    private function ccuStand(): array
    {
        $ip = trim($this->ReadPropertyString('CcuIp'));
        if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
            return ['meldungen' => [], 'ok' => false];
        }
        $namen = $this->regaNamen($ip);
        $ifaces = [2001 => 'BidCos-RF', 2010 => 'HmIP-RF'];

        $meld = []; $liste = []; $gesehen = []; $ok = false;
        foreach ($this->ccuPorts() as $port) {
            $xml = $this->xmlRpc($ip, $port, 'getServiceMessages');
            if ($xml === null) { continue; }
            $ok = true;
            foreach ($this->parseServiceMessages($xml) as $m) {
                if ($this->istAus($m['val'])) { continue; }
                $adr = $m['addr'];
                $serie = explode(':', $adr)[0];
                $typ = strtoupper($m['type']);
                if ($serie === '' || $typ === '') { continue; }

                if (!isset($meld[$serie]) || !in_array($typ, $meld[$serie], true)) {
                    $meld[$serie][] = $typ;
                }
                // Dieselbe Meldung kann auf beiden Schnittstellen auftauchen - einmal reicht.
                $schl = $adr . '|' . $typ;
                if (isset($gesehen[$schl])) { continue; }
                $gesehen[$schl] = true;

                $name = $namen[$serie] ?? $serie;
                $liste[] = ['sev' => self::SEVERITY[$typ] ?? 'NOTIFY', 'type' => $typ, 'addr' => $adr,
                    'iface' => $ifaces[$port] ?? (string) $port, 'name' => $name,
                    'm' => $name . '  ·  ' . $typ, 't' => ''];
            }
        }
        return ['meldungen' => $meld, 'liste' => $liste, 'ok' => $ok];
    }

    /**
     * Geraetenamen aus der CCU (ReGaHss, Port 8181) — Seriennummer => Name.
     *
     * Braucht man fuer Geraete, die IP-Symcon gar nicht kennt: ohne diese Liste steht in
     * der Meldung nur die nackte Seriennummer, und die sagt niemandem etwas. Die Liste
     * aendert sich selten, deshalb 10 Minuten in einer Datei gepuffert.
     *
     * @return array<string,string>
     */
    private function regaNamen(string $ip): array
    {
        $cf = IPS_GetKernelDir() . 'hsdh-hm-namen-' . md5($ip) . '.json';
        if (is_file($cf) && (time() - filemtime($cf)) < 600) {
            $c = json_decode((string) @file_get_contents($cf), true);
            if (is_array($c) && $c) { return $c; }
        }
        $r = $this->rega($ip, 'string s;object d;foreach(s,dom.GetObject(ID_DEVICES).EnumUsedIDs())'
            . '{d=dom.GetObject(s);WriteLine(d.Address()#"\t"#d.Name());}');
        $map = [];
        foreach (preg_split('/\r?\n/', (string) $r) as $ln) {
            if (strpos($ln, "\t") === false) { continue; }
            [$a, $n] = explode("\t", $ln, 2);
            $a = trim($a); $n = trim($n);
            if ($a !== '') { $map[$a] = $this->ccuUtf8($n); }
        }
        if ($map) { @file_put_contents($cf, json_encode($map, JSON_UNESCAPED_UNICODE)); }
        return $map;
    }

    /** TCL an ReGaHss. Haengt seine Ausgabe vor ein <xml>-Anhaengsel, das hier abgeschnitten wird. */
    private function rega(string $ip, string $script, int $timeout = 8): ?string
    {
        $ch = curl_init("http://$ip:8181/tclrega.exe");
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $script,
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 4]);
        $r = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200 || !is_string($r)) { return null; }
        $p = strpos($r, '<xml>');
        return ($p !== false) ? substr($r, 0, $p) : $r;
    }

    /** Die CCU liefert ISO-8859-1; doppelt kodierte Umlaute bleiben sonst als Kaestchen stehen. */
    private function ccuUtf8($s): string
    {
        $s = (string) $s;
        return (preg_match('//u', $s) === 1) ? $s : (string) @iconv('ISO-8859-1', 'UTF-8//IGNORE', $s);
    }

    /**
     * HSDH_Bestaetigen — eine Servicemeldung gezielt quittieren.
     *
     * Ueber das ReGaHss-Alarmobjekt `AL-<Adresse>.<Typ>`, nicht ueber ein CCU-Programm:
     * so verschwindet genau DIESE Meldung und nicht pauschal alle. CONFIG_PENDING laesst
     * sich damit uebrigens NICHT wegdruecken - das loest sich erst, wenn die wartende
     * Konfiguration das (Batterie-)Geraet tatsaechlich erreicht.
     */
    public function Bestaetigen(string $Adresse, string $Typ): bool
    {
        $ip = trim($this->ReadPropertyString('CcuIp'));
        if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) { return false; }
        $adr = preg_replace('/[^A-Za-z0-9:_-]/', '', $Adresse);
        $typ = preg_replace('/[^A-Z_]/', '', strtoupper($Typ));
        if ($adr === '' || $typ === '') { return false; }
        $r = $this->rega($ip, 'var o=dom.GetObject("AL-' . $adr . '.' . $typ . '");'
            . 'if(o){o.AlReceipt();WriteLine("ok");}else{WriteLine("no");}');
        $ok = ($r !== null && strpos($r, 'ok') !== false);
        if ($ok) { $this->Update(); }   // Anzeige sofort nachziehen, nicht erst in 15 Minuten
        return $ok;
    }

    /**
     * Empfangsstaerken: je Geraet, wie gut die Anlage es im BESTEN Fall hoert (dBm).
     *
     * `rssiInfo` liefert eine Matrix `A -> B -> [X, Y]`, und die Lesart ist der ganze Trick:
     * X ist, was **A von B** empfangen hat, Y, was **B von A** empfangen hat. Der Wert gehoert
     * also jeweils dem SENDER, nicht dem Zeilen-Schluessel. Wer das vertauscht, bekommt hier
     * statt 98 Geraeten nur die vier Gateways, die ueberhaupt messen.
     *
     * Gewertet wird der BESTE Wert je Geraet: dass ein entferntes Gateway ein Geraet schwach
     * hoert, ist normal - kritisch ist erst, wenn es AUCH das naechste kaum noch hoert.
     * 65536 heisst "kein Wert", positive Werte sind keine dBm.
     *
     * @return array<string,int>
     */
    private function ccuRssi(): array
    {
        $ip = trim($this->ReadPropertyString('CcuIp'));
        if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) { return []; }
        $xml = $this->xmlRpc($ip, 2001, 'rssiInfo', 10);
        if ($xml === null) { return []; }

        $prev = libxml_use_internal_errors(true);
        $sx = simplexml_load_string($xml);
        libxml_use_internal_errors($prev);
        if (!$sx) { return []; }

        $out = [];
        // <struct><member><name>SENDER</name><value><struct><member><name>PEER</name>
        //   <value><array><data><value><i4>..</i4></value> x2
        foreach (($sx->xpath('//params/param/value/struct/member') ?: []) as $sender) {
            $sName = trim((string) $sender->name);
            foreach (($sender->xpath('./value/struct/member') ?: []) as $peer) {
                $pName = trim((string) $peer->name);
                $werte = [];
                foreach (($peer->xpath('./value/array/data/value') ?: []) as $v) {
                    $werte[] = (int) trim((string) ($v->i4 ?? $v->int ?? $v));
                }
                // Wert gehoert dem Sender: [0] hat A von B gehoert -> B, [1] hat B von A gehoert -> A.
                foreach ([[$pName, $werte[0] ?? self::RSSI_LEER], [$sName, $werte[1] ?? self::RSSI_LEER]] as [$wer, $w]) {
                    if ($wer === '' || $wer === 'BidCoS-RF') { continue; }
                    if ($w === self::RSSI_LEER || $w >= 0 || $w < -130) { continue; }
                    $out[$wer] = isset($out[$wer]) ? max($out[$wer], $w) : $w;
                }
            }
        }
        return $out;
    }

    /** @return list<array> Funkstrecken-Zeilen, schwaechste zuerst. */
    private function funkListe(array $rssi, array $geraete): array
    {
        $namen = [];
        foreach ($geraete as $g) { if ($g['serie'] !== '') { $namen[$g['serie']] = $g['name']; } }
        $rows = [];
        foreach ($rssi as $serie => $dbm) {
            $rows[] = ['serie' => $serie, 'name' => $namen[$serie] ?? $serie, 'dbm' => $dbm];
        }
        usort($rows, fn($a, $b) => $a['dbm'] <=> $b['dbm']);
        return $rows;
    }

    private function istAus($val): bool
    {
        $s = strtolower(trim((string) $val));
        return $s === '' || $s === '0' || $s === 'false';
    }

    private function xmlRpc(string $ip, int $port, string $method, int $timeout = 6): ?string
    {
        $body = '<?xml version="1.0"?><methodCall><methodName>' . $method . '</methodName><params></params></methodCall>';
        $ch = curl_init("http://$ip:$port/");
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_HTTPHEADER => ['Content-Type: text/xml']]);
        $r = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($code === 200 && is_string($r)) ? $r : null;
    }

    /** @return list<array{addr:string,type:string,val:string}> */
    private function parseServiceMessages(string $xml): array
    {
        $out = [];
        $prev = libxml_use_internal_errors(true);
        $sx = simplexml_load_string($xml);
        libxml_use_internal_errors($prev);
        if (!$sx) { return $out; }
        foreach (($sx->xpath('//params/param/value/array/data/value') ?: []) as $it) {
            $vals = $it->xpath('./array/data/value');
            if (!$vals || count($vals) < 2) { continue; }
            // Getypte Werte stehen im Kindknoten (<value><boolean>1</boolean></value>),
            // der direkte Text ist dann leer.
            $lese = function ($v): string {
                $t = trim((string) $v);
                if ($t !== '') { return $t; }
                foreach ($v->children() as $c) { return trim((string) $c); }
                return '';
            };
            $out[] = [
                'addr' => $lese($vals[0]),
                'type' => $lese($vals[1]),
                'val'  => isset($vals[2]) ? $lese($vals[2]) : '1',
            ];
        }
        return $out;
    }

    // ==================================================================================
    //  Zuordnung
    // ==================================================================================

    /** Seriennummer einer Instanz: HomeMatic fuehrt sie in "Address" als SERIE:KANAL. */
    private function serieVon(int $iid): string
    {
        $cfg = @json_decode(IPS_GetConfiguration($iid), true);
        if (!is_array($cfg)) { return ''; }
        foreach (['Address', 'address', 'HMAddress'] as $k) {
            if (!isset($cfg[$k]) || !is_string($cfg[$k]) || $cfg[$k] === '') { continue; }
            $s = explode(':', $cfg[$k])[0];
            if (preg_match('/^[A-Z0-9]{6,}$/i', $s)) { return $s; }
        }
        return '';
    }

    /** Ort = der naechste Elternknoten oberhalb der Instanz, der kein Technikordner ist. */
    private function ortVon(int $iid): string
    {
        $p = IPS_GetParent($iid);
        $tiefe = 0;
        while ($p > 0 && $tiefe++ < 6) {
            $n = IPS_GetName($p);
            if (!in_array($n, ['Hardware', 'Homematic', 'HomeMatic', 'Z-Wave', 'ZWave', 'Geräte', 'Devices'], true)) {
                return $n;
            }
            $p = IPS_GetParent($p);
        }
        return '';
    }

    private function systemVon(string $modul): string
    {
        $m = strtolower($modul);
        foreach ([['homematic', 'HomeMatic'], ['hm', 'HomeMatic'], ['z-wave', 'Z-Wave'], ['zwave', 'Z-Wave'],
                  ['zigbee', 'Zigbee'], ['shelly', 'Shelly'], ['mqtt', 'MQTT'], ['knx', 'KNX'],
                  ['enocean', 'EnOcean'], ['philips', 'Hue'], ['hue', 'Hue']] as [$nadel, $name]) {
            if (strpos($m, $nadel) !== false) { return $name; }
        }
        return $modul;
    }

    private function zustandKey(int $s): string
    {
        return [self::ST_OK => 'ok', self::ST_UNKNOWN => 'unknown', self::ST_WEAK => 'weak',
                self::ST_CONFIG => 'config', self::ST_SILENT => 'silent', self::ST_FAULT => 'fault',
                self::ST_UNREACH => 'unreach'][$s] ?? 'unknown';
    }

    private function zustandText(int $s): string
    {
        return [self::ST_OK => 'In Ordnung', self::ST_UNKNOWN => 'Unbekannt', self::ST_WEAK => 'Schwacher Funk',
                self::ST_CONFIG => 'Konfiguration steht an', self::ST_SILENT => 'Meldet sich nicht',
                self::ST_FAULT => 'Instanz im Fehler', self::ST_UNREACH => 'Nicht erreichbar'][$s] ?? 'Unbekannt';
    }

    // ==================================================================================
    //  Ausgabe
    // ==================================================================================

    private function writeOutputs(array $res, bool $withLinks): void
    {
        $c = $res['counts'];
        $this->SetValue('Total', $c['total']);
        $this->SetValue('Ok', $c['ok']);
        $this->SetValue('Unreachable', $c['unreach']);
        $this->SetValue('Faulted', $c['fault']);
        $this->SetValue('Silent', $c['silent']);
        $this->SetValue('ConfigPending', $c['config']);
        $this->SetValue('WeakSignal', $c['weak']);
        $this->SetValue('Attention', $c['attention']);
        $this->SetValue('OkPercent', $c['total'] > 0 ? (int) round($c['ok'] / $c['total'] * 100) : 0);
        $this->SetValue('Status', $c['unreach'] > 0 ? self::ST_UNREACH
            : ($c['fault'] > 0 ? self::ST_FAULT
            : ($c['silent'] > 0 ? self::ST_SILENT
            : ($c['config'] > 0 ? self::ST_CONFIG
            : ($c['weak'] > 0 ? self::ST_WEAK : self::ST_OK)))));
        $this->SetValue('Register', $this->buildRegister($res));
        $this->SetValue('Table', $this->buildTable($res));
        $this->SetValue('RadioTable', $this->buildRadioTable($res));
        $this->SetValue('CcuMessages', json_encode(
            ['ts' => $res['ts'], 'messages' => $res['ccuMsgs'] ?? [], 'count' => count($res['ccuMsgs'] ?? [])],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->SetValue('LastRun', $res['ts']);
        if ($withLinks && $this->ReadPropertyBoolean('CreateLinks')) {
            $this->syncLinks($res['devices']);
        }
    }

    private function buildRegister(array $res): string
    {
        $out = ['ts' => $res['ts'], 'ver' => 1, 'ccuOk' => $res['ccuOk'] ?? false,
                'counts' => $res['counts'], 'devices' => []];
        foreach ($res['devices'] as $d) {
            $out['devices'][] = [
                'id' => $d['id'], 'name' => $d['name'], 'room' => $d['ort'], 'system' => $d['system'],
                'serial' => $d['serie'], 'state' => $this->zustandKey($d['zustand']),
                'rssi' => $d['rssi'], 'lastSeen' => $d['letzte'],
                'note' => implode(' · ', $d['hinweis']),
            ];
        }
        return json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** JSON-Tabelle fuers LVB table-Widget: Zeile 0 = Spaltenkopf. Nur Auffaelliges zuerst. */
    private function buildTable(array $res): string
    {
        $rows = [['Gerät', 'Ort', 'System', 'Zustand', 'Funk', 'Zuletzt gehört', 'Befund']];
        foreach ($res['devices'] as $d) {
            // Die Tabelle zeigt, was zu TUN ist. "In Ordnung" braucht keine Zeile - und
            // "Unbekannt" auch nicht: ein Harmony-Geraet ohne eine einzige Variable ist kein
            // Befund, sondern eine Wissensluecke. Es steht im Register und im Zaehler.
            if ($d['zustand'] === self::ST_OK || $d['zustand'] === self::ST_UNKNOWN) { continue; }
            $rows[] = [
                $d['name'], $d['ort'], $d['system'], $this->zustandText($d['zustand']),
                $d['rssi'] === null ? '' : $d['rssi'] . ' dBm',
                $this->alterText((int) $d['letzte'], (int) $res['ts']),
                implode(' · ', $d['hinweis']),
            ];
        }
        if (count($rows) === 1) { $rows[] = ['Alles in Ordnung', '', '', '', '', '', '']; }
        return json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function buildRadioTable(array $res): string
    {
        $rows = [['Gerät', 'Empfangsstärke', 'Bewertung']];
        $schwach = $this->ReadPropertyInteger('WeakRssiDbm');
        foreach ($res['funk'] as $f) {
            $rows[] = [$f['name'], $f['dbm'] . ' dBm',
                $f['dbm'] < $schwach ? 'schwach' : ($f['dbm'] < ($schwach + 15) ? 'ausreichend' : 'gut')];
        }
        if (count($rows) === 1) { $rows[] = ['Keine Funkdaten', '', '']; }
        return json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Wie alt die letzte Lebensaeusserung ist - in Worten. Ein Datum beantwortet die Frage
     * nicht, um die es geht: "vor 2 h" und "vor 4 Jahren" unterscheiden sich, "23.08. 13:33"
     * und "12.04. 09:02" sehen gleich aus.
     */
    private function alterText(int $ts, int $now): string
    {
        if ($ts <= 0) { return 'nie'; }
        $s = max(0, $now - $ts);
        if ($s < 3600)   { return 'vor ' . max(1, (int) round($s / 60)) . ' min'; }
        if ($s < 86400)  { return 'vor ' . (int) round($s / 3600) . ' h'; }
        if ($s < 86400 * 60)  { return 'vor ' . (int) round($s / 86400) . ' Tagen'; }
        if ($s < 86400 * 365) { return 'vor ' . (int) round($s / (86400 * 30)) . ' Monaten'; }
        return 'vor ' . number_format($s / (86400 * 365), 1, ',', '') . ' Jahren';
    }

    /** Links der auffaelligen Geraete, nach Zustand gruppiert (idempotent). */
    private function syncLinks(array $devices): void
    {
        $root = $this->ReadPropertyInteger('RootCategory');
        if ($root <= 0 || !IPS_ObjectExists($root)) { $root = (int) $this->InstanceID; }

        $gruppen = [self::ST_UNREACH => 'Nicht erreichbar', self::ST_FAULT => 'Instanz im Fehler',
                    self::ST_SILENT => 'Meldet sich nicht', self::ST_CONFIG => 'Konfiguration steht an',
                    self::ST_WEAK => 'Schwacher Funk'];

        $soll = [];   // catId => [instanzId => Name]
        foreach ($devices as $d) {
            if (!isset($gruppen[$d['zustand']]) || (int) $d['id'] <= 0) { continue; }
            $soll[$d['zustand']][(int) $d['id']] = $d['name'] . ($d['ort'] !== '' ? ' (' . $d['ort'] . ')' : '');
        }

        foreach ($gruppen as $st => $titel) {
            $ident = 'grp_' . $this->zustandKey($st);
            $eintraege = $soll[$st] ?? [];
            $cid = @IPS_GetObjectIDByIdent($ident, $root);
            if ($eintraege === []) {
                if ($cid !== false) { $this->kategorieLeeren((int) $cid); @IPS_DeleteCategory((int) $cid); }
                continue;
            }
            if ($cid === false) {
                $cid = IPS_CreateCategory();
                IPS_SetParent($cid, $root);
                IPS_SetIdent($cid, $ident);
            }
            IPS_SetName((int) $cid, $titel);

            $vorhanden = [];
            foreach (IPS_GetChildrenIDs((int) $cid) as $k) {
                if (IPS_GetObject($k)['ObjectType'] !== 6) { continue; }
                $ziel = (int) IPS_GetLink($k)['TargetID'];
                if (!isset($eintraege[$ziel])) { IPS_DeleteLink($k); continue; }
                $vorhanden[$ziel] = $k;
                IPS_SetName($k, $eintraege[$ziel]);
            }
            foreach ($eintraege as $ziel => $name) {
                if (isset($vorhanden[$ziel]) || !IPS_ObjectExists($ziel)) { continue; }
                $lid = IPS_CreateLink();
                IPS_SetParent($lid, (int) $cid);
                IPS_SetLinkTargetID($lid, $ziel);
                IPS_SetName($lid, $name);
            }
        }
    }

    private function kategorieLeeren(int $cid): void
    {
        foreach (IPS_GetChildrenIDs($cid) as $k) {
            if (IPS_GetObject($k)['ObjectType'] === 6) { @IPS_DeleteLink($k); }
        }
    }

    public function GetConfigurationForm()
    {
        $note = 'Noch kein Scan.';
        $reg = @json_decode((string) $this->GetValue('Register'), true);
        if (is_array($reg) && isset($reg['counts'])) {
            $c = $reg['counts'];
            $note = sprintf('Letzter Scan: %s · beobachtet %d · in Ordnung %d · nicht erreichbar %d · '
                . 'im Fehler %d · still %d · Konfiguration %d · schwacher Funk %d · CCU %s',
                $this->GetValue('LastRun') ? date('d.m.y H:i', $this->GetValue('LastRun')) : '?',
                $c['total'] ?? 0, $c['ok'] ?? 0, $c['unreach'] ?? 0, $c['fault'] ?? 0,
                $c['silent'] ?? 0, $c['config'] ?? 0, $c['weak'] ?? 0,
                ($reg['ccuOk'] ?? false) ? 'erreicht' : 'nicht erreicht');
        }
        return json_encode([
            'elements' => [
                ['type' => 'Label', 'caption' =>
                    'Gerätegesundheit — Erreichbarkeit, Konfigurationsstau und Funkqualität, markenübergreifend. '
                    . 'Batterien bleiben Sache des Battery Managers; LOWBAT steht hier nur als Hinweis.'],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'NumberSpinner', 'name' => 'Interval', 'caption' => 'Scan-Intervall (Minuten, 0 = aus)'],
                    ['type' => 'NumberSpinner', 'name' => 'SilentDays', 'caption' => 'Still seit (Tage) => meldet sich nicht'],
                    ['type' => 'ValidationTextBox', 'name' => 'SilentSystems', 'caption' => 'Stille nur bei diesen Systemen'],
                    ['type' => 'NumberSpinner', 'name' => 'WeakRssiDbm', 'caption' => 'Schwacher Funk unter (dBm)'],
                ]],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'ValidationTextBox', 'name' => 'CcuIp', 'caption' => 'HomeMatic-CCU (IP, leer = nicht fragen)'],
                    ['type' => 'ValidationTextBox', 'name' => 'CcuPorts', 'caption' => 'CCU-Ports (2001 BidCos, 2010 HmIP)'],
                ]],
                ['type' => 'Label', 'caption' =>
                    'UNREACH und CONFIG_PENDING führt allein die CCU — in IP-Symcon stehen sie nicht. '
                    . 'Ohne CCU-Adresse bleibt nur der generische Teil: Instanzstatus und Stille.'],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'CheckBox', 'name' => 'ScanInstances', 'caption' => 'Instanzen scannen (Z-Wave, Zigbee, …)'],
                    ['type' => 'CheckBox', 'name' => 'CreateLinks', 'caption' => 'Links im Baum pflegen'],
                ]],
                ['type' => 'SelectCategory', 'name' => 'RootCategory', 'caption' => 'Ziel-Ordner für Links (0 = unter der Instanz)'],
                ['type' => 'ValidationTextBox', 'name' => 'IgnoreModules', 'caption' => 'Module überspringen (Namen, Komma-getrennt)'],
                ['type' => 'List', 'name' => 'ExcludeInstances', 'caption' => 'Ausgeschlossene Instanzen',
                 'add' => true, 'delete' => true, 'rowCount' => 6,
                 'columns' => [
                     ['caption' => 'Instanz', 'name' => 'InstanceID', 'width' => 'auto', 'add' => 0,
                      'edit' => ['type' => 'SelectInstance']],
                 ]],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'Button', 'caption' => 'Jetzt scannen', 'onClick' => 'HSDH_Update($id);'],
                    ['type' => 'Button', 'caption' => 'CCU-Meldungen anzeigen', 'onClick' => 'echo HSDH_CcuMeldungen($id);'],
                ]],
                ['type' => 'Label', 'caption' => $note],
            ],
        ]);
    }
}

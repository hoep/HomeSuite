<?php

declare(strict_types=1);

/**
 * BatteryManager — sammelt alle Geraetebatterien in IP-Symcon, dedupliziert (HomeMatic doppelt!),
 * klassifiziert (OK / Bald tauschen / Leer) und legt gruppierte Links + Statistik + JSON-Register/Tabelle an.
 *
 * Der schwere All-Variablen-Scan laeuft NICHT im Kernel-Thread, sondern in einem Worker-Skript
 * (wie RainRadar). BM_Update() stoesst nur asynchron an. BM_Preview() rechnet read-only (kein Baum-Schreiben).
 */
class BatteryManager extends IPSModule
{
    private const STATE_OK = 0;
    private const STATE_WARN = 1;   // bald tauschen
    private const STATE_EMPTY = 2;  // leer / tauschen
    private const STATE_UNKNOWN = 3;

    /** true nur waehrend BM_Scan() -> Fortschritt wird live in die Progress-Variable geschrieben. */
    private $emitProgress = false;

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger('Interval', 30);        // Scan-Takt in MINUTEN (0 = aus)
        $this->RegisterPropertyInteger('EmptyThreshold', 15);  // <  % => Leer
        $this->RegisterPropertyInteger('WarnThreshold', 30);   // <  % => Bald (>= WarnThreshold => OK)
        $this->RegisterPropertyInteger('StaleDays', 21);       // %-Wert 0 und aelter als N Tage => Unbekannt statt Leer
        $this->RegisterPropertyInteger('MaxAgeDays', 60);      // Prozent/Enum/Spannung: aelter als N Tage nicht aktualisiert => raus (tot/Geist). 0 = aus. Bool (LOWBAT) ausgenommen.
        $this->RegisterPropertyBoolean('IncludeHidden', true); // versteckte Variablen mit einbeziehen
        $this->RegisterPropertyBoolean('CreateLinks', true);   // Links im Baum anlegen/pflegen
        $this->RegisterPropertyString('ExcludeIdents', '');    // Komma-Liste: Namens-/Ident-Teilstrings ueberspringen
        $this->RegisterPropertyString('ExcludeVars', '[]');    // Liste [{VarID:int}] — aus dem Baum gewaehlte Variablen ausschliessen (ganzes Geraet)
        $this->RegisterPropertyInteger('RootCategory', 0);     // optionaler Ziel-Ordner (0 = unter der Instanz)

        // Statistik-Variablen
        $this->maybeStatusProfile();
        $this->RegisterVariableInteger('Total', 'Batterien gesamt', '', 10);
        $this->RegisterVariableInteger('Ok', 'OK', '', 20);
        $this->RegisterVariableInteger('Warn', 'Bald tauschen', '', 30);
        $this->RegisterVariableInteger('Empty', 'Leer / tauschen', '', 40);
        $this->RegisterVariableInteger('Unknown', 'Unbekannt', '', 50);
        $this->RegisterVariableInteger('Weak', 'Handlungsbedarf (Bald+Leer)', '', 60);
        if (!IPS_VariableProfileExists('~Intensity.100')) { /* Standardprofil vorhanden; sonst 0-100 ok */ }
        $this->RegisterVariableInteger('OkPercent', 'OK-Anteil', '~Intensity.100', 65);
        $this->RegisterVariableInteger('Status', 'Gesamtstatus', 'BATT.Status', 70);
        $this->RegisterVariableString('Register', 'Register (JSON)', '', 80);
        $this->RegisterVariableString('Table', 'Tabelle (JSON)', '', 90);
        $this->RegisterVariableInteger('LastRun', 'Letzter Scan', '~UnixTimestamp', 100);
        $this->RegisterVariableInteger('Progress', 'Scan-Fortschritt', '', 105); // 0 = idle, 1..99 = laeuft

        $this->RegisterTimer('Scan', 0, 'BM_Update($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->maybeStatusProfile();
        $this->ensureWorker();
        $min = max(0, $this->ReadPropertyInteger('Interval'));
        $this->SetTimerInterval('Scan', $min * 60 * 1000);
    }

    /** BATT.Status-Profil (0 OK gruen, 1 Bald gelb, 2 Leer rot, 3 Unbekannt grau). */
    private function maybeStatusProfile(): void
    {
        if (!IPS_VariableProfileExists('BATT.Status')) {
            IPS_CreateVariableProfile('BATT.Status', 1);
        }
        IPS_SetVariableProfileValues('BATT.Status', 0, 3, 1);
        IPS_SetVariableProfileAssociation('BATT.Status', 0, 'OK', '', 0x2ECC71);
        IPS_SetVariableProfileAssociation('BATT.Status', 1, 'Bald tauschen', '', 0xF39C12);
        IPS_SetVariableProfileAssociation('BATT.Status', 2, 'Leer', '', 0xE74C3C);
        IPS_SetVariableProfileAssociation('BATT.Status', 3, 'Unbekannt', '', 0x95A5A6);
    }

    /**
     * Worker-Skript (Skript-Thread) — fuehrt den schweren Scan aus. RUN via IPS_RunScript (async).
     * Der Body ruft nur die Prefix-Funktion; die laeuft dann im Skript-Thread, nicht im Kernel.
     */
    private function ensureWorker(): void
    {
        $wid = @IPS_GetObjectIDByIdent('Worker', $this->InstanceID);
        if ($wid === false) {
            $wid = IPS_CreateScript(0);
            IPS_SetParent($wid, $this->InstanceID);
            IPS_SetIdent($wid, 'Worker');
            IPS_SetName($wid, 'BatteryManager Worker (async)');
            @IPS_SetHidden($wid, true);
        }
        $iid = (int) $this->InstanceID;
        $code = "<?php\n"
            . "// AUTOGENERIERT von BatteryManager (ensureWorker). Laeuft im Skript-Thread\n"
            . "// (nicht im Kernel) -> der All-Variablen-Scan ist hier sicher. NICHT haendisch aendern.\n"
            . "BM_Scan({$iid});\n";
        IPS_SetScriptContent($wid, $code);
    }

    /** BM_Update — Timer/Button: stoesst den Worker asynchron an und kehrt sofort zurueck. */
    public function Update(): void
    {
        $wid = @IPS_GetObjectIDByIdent('Worker', $this->InstanceID);
        if ($wid === false) { $this->ensureWorker(); $wid = @IPS_GetObjectIDByIdent('Worker', $this->InstanceID); }
        if ($wid !== false) { @$this->SetValue('Progress', 1); IPS_RunScript($wid); }
    }

    /** BM_Scan — schwerer Scan (nur aus dem Worker-Thread). Schreibt Statistik, Register/Tabelle und Links. */
    public function Scan(): void
    {
        $this->emitProgress = true;
        try {
            $res = $this->computeDevices();
            $this->setProg(90);
            $this->writeOutputs($res, true);
        } catch (\Throwable $e) {
            IPS_LogMessage('BatteryManager', 'Scan-Fehler: ' . $e->getMessage());
        } finally {
            $this->emitProgress = false;
            @$this->SetValue('Progress', 0);   // 0 = idle -> Fortschrittsanzeige im Frontend verschwindet
        }
    }

    private function setProg($p): void
    {
        if ($this->emitProgress) { @$this->SetValue('Progress', max(1, min(99, (int) $p))); }
    }

    /** BM_Preview — read-only: rechnet den Scan durch und gibt das Register-JSON zurueck OHNE Baum/Var-Schreiben. */
    public function Preview(): string
    {
        try {
            $res = $this->computeDevices();
            return $this->buildRegister($res);
        } catch (\Throwable $e) {
            return json_encode(['error' => $e->getMessage()]);
        }
    }

    // ==================================================================================
    //  Kern: Erkennung + Dedup + Klassifizierung
    // ==================================================================================

    private function computeDevices(): array
    {
        $emptyThr = $this->ReadPropertyInteger('EmptyThreshold');
        $warnThr  = $this->ReadPropertyInteger('WarnThreshold');
        $staleSec = max(0, $this->ReadPropertyInteger('StaleDays')) * 86400;
        $maxAge   = max(0, $this->ReadPropertyInteger('MaxAgeDays'));
        $inclHid  = $this->ReadPropertyBoolean('IncludeHidden');
        $exList   = array_filter(array_map('trim', explode(',', $this->ReadPropertyString('ExcludeIdents'))));
        $exVars   = []; foreach ((json_decode($this->ReadPropertyString('ExcludeVars'), true) ?: []) as $r) { $v = is_array($r) ? (int) ($r['VarID'] ?? 0) : (int) $r; if ($v > 0) $exVars[$v] = true; }
        $exKeys   = []; // Geraete-Keys, die wegen einer ausgeschlossenen Variable ganz entfallen
        $self     = (int) $this->InstanceID;
        $now      = time();

        $groups = []; // deviceKey => [ 'cands'=>[...], ... ]
        $all = IPS_GetVariableList();
        $N = max(1, count($all));
        $pstep = max(1, intdiv($N, 15));
        $pi = 0;
        foreach ($all as $vid) {
            if ($this->emitProgress && (++$pi % $pstep === 0)) { $this->setProg(5 + $pi / $N * 65); }
            $obj = @IPS_GetObject($vid); if (!$obj) continue;
            if (!$inclHid && !empty($obj['ObjectIsHidden'])) { /* trotzdem pruefen: viele Batterie-Vars sind hidden */ }
            $var = @IPS_GetVariable($vid); if (!$var) continue;
            $name  = (string) $obj['ObjectName'];
            $ident = (string) $obj['ObjectIdent'];
            $prof  = $var['VariableCustomProfile'] !== '' ? $var['VariableCustomProfile'] : $var['VariableProfile'];
            $type  = (int) $var['VariableType'];

            // Instanz-Kontext des Traegers ermitteln
            $inst = $this->ownerInstance($vid);
            $mod = ''; $addr = '';
            if ($inst > 0) {
                $ii = @IPS_GetInstance($inst);
                $mod = $ii['ModuleInfo']['ModuleName'] ?? '';
                if ($inst === $self) continue; // eigene Variablen nie
                $cfg = @json_decode((string) @IPS_GetConfiguration($inst), true);
                if (is_array($cfg) && isset($cfg['Address'])) $addr = (string) $cfg['Address'];
            }

            $cand = $this->detect($vid, $name, $ident, $prof, $type, $mod);
            if ($cand === null) continue;
            $loc0 = (string) @IPS_GetLocation($vid);
            if (stripos($loc0, '_deprecated') !== false || stripos($loc0, 'Papierkorb') !== false) continue;

            // Ausschluss-Liste (Nutzer)
            $skip = false;
            foreach ($exList as $ex) { if ($ex !== '' && (stripos($ident, $ex) !== false || stripos($name, $ex) !== false)) { $skip = true; break; } }
            if ($skip) continue;

            $cand['vid'] = $vid; $cand['name'] = $name; $cand['ident'] = $ident;
            $cand['prof'] = $prof; $cand['inst'] = $inst; $cand['mod'] = $mod;
            $cand['updated'] = (int) ($var['VariableUpdated'] ?? 0);

            // Aktualisierungs-Zeit (nicht Aenderung!): Prozent/Enum/Spannung melden regelmaessig -> nie
            // aktualisiert oder aelter als MaxAgeDays => tot/Geist -> raus. Bool/LOWBAT wird von HomeMatic
            // NICHT periodisch geschrieben (nur bei Aenderung) -> hier KEIN Alters-Limit (Phantom-Check unten je Geraet).
            if ($cand['class'] !== 'bool' && $maxAge > 0) {
                if ($cand['updated'] <= 0 || ($now - $cand['updated']) > $maxAge * 86400) continue;
            }

            // Device-Key: HomeMatic -> Serial (vor ':'), sonst Instanz, sonst Objekt-Parent
            if ($this->isHM($mod) && $addr !== '') { $key = 'HM:' . explode(':', $addr)[0]; }
            elseif ($inst > 0) { $key = 'I:' . $inst; }
            else { $key = 'O:' . (int) $obj['ParentID'] . ':' . $vid; }

            // Nutzer-Ausschluss aus dem Baum: eine gewaehlte Variable entfernt das GANZE Geraet.
            if (isset($exVars[$vid])) { $exKeys[$key] = true; continue; }

            $groups[$key][] = $cand;
        }

        $this->setProg(72);
        // Pro Geraet einen Repraesentanten + Zustand
        $devices = [];
        foreach ($groups as $key => $cands) {
            if (isset($exKeys[$key])) continue; // Geraet per Ausschluss-Liste entfernt
            $rep = $this->pickRepresentative($cands);
            // Phantom-Netzaktor ueberspringen: einziger Traeger ist ein NIE geschriebenes LOWBAT (updated=0)
            // OHNE echte Batterie-Messung (kein %, keine Enum, keine je aktualisierte Spannung) — z. B. Schalt-Steckdose.
            if ($rep['class'] === 'bool' && $rep['updated'] <= 0) {
                $real = false;
                foreach ($cands as $cc) { if ($cc['class'] === 'pct' || $cc['class'] === 'enum' || ($cc['class'] === 'volt' && $cc['updated'] > 0)) { $real = true; break; } }
                if (!$real) continue;
            }
            $st  = $this->classify($rep, $emptyThr, $warnThr, $staleSec, $now);
            [$device, $room, $path] = $this->labelFor($rep['vid']);
            $devices[] = [
                'name'   => $device,
                'room'   => $room,
                'path'   => $path,
                'system' => $this->systemOf($rep['mod'], $key),
                'state'  => $st['state'],
                'type'   => $rep['class'],
                'value'  => $st['value'],
                'unit'   => $st['unit'],
                'text'   => $st['text'],
                'varId'  => $rep['vid'],
                'low'    => $st['low'],
                'ts'     => $rep['updated'],
                'key'    => $key,
            ];
        }

        // Sortierung: schlechtester zuerst (Leer, Bald, Unbekannt, OK), dann Wert aufsteigend
        usort($devices, function ($a, $b) {
            $ord = [self::STATE_EMPTY => 0, self::STATE_WARN => 1, self::STATE_UNKNOWN => 2, self::STATE_OK => 3];
            $sa = $ord[$a['state']] ?? 9; $sb = $ord[$b['state']] ?? 9;
            if ($sa !== $sb) return $sa <=> $sb;
            $va = is_numeric($a['value']) ? (float) $a['value'] : 999;
            $vb = is_numeric($b['value']) ? (float) $b['value'] : 999;
            if ($va !== $vb) return $va <=> $vb;
            return strcasecmp($a['name'], $b['name']);
        });

        $counts = ['total' => count($devices), 'ok' => 0, 'warn' => 0, 'empty' => 0, 'unknown' => 0];
        foreach ($devices as $d) {
            if ($d['state'] === self::STATE_OK) $counts['ok']++;
            elseif ($d['state'] === self::STATE_WARN) $counts['warn']++;
            elseif ($d['state'] === self::STATE_EMPTY) $counts['empty']++;
            else $counts['unknown']++;
        }
        $counts['weak'] = $counts['warn'] + $counts['empty'];

        $this->setProg(85);
        return ['devices' => $devices, 'counts' => $counts, 'ts' => $now];
    }

    /** Ist die Variable eine Geraetebatterie? Gibt ['class'=>pct|bool|volt|enum] oder null. */
    private function detect(int $vid, string $name, string $ident, string $prof, int $type, string $mod): ?array
    {
        // --- harte Ausschluesse (kein Wechselakku) ---
        if (stripos($mod, 'NUT Client') !== false) return null;                       // USV
        if (preg_match('/^NUTC\./i', $prof) || preg_match('/^DP_battery_/i', $ident)) return null;
        if (stripos($mod, 'BMWConnectedDrive') !== false) return null;                // Auto-Traktionsakku
        if (preg_match('/cd_vehicle_|hvSoc|stateOfCharge|electricalSystem_battery/i', $ident)) return null;
        if ($prof === '~HTMLBox' || $type === 3) return null;                          // Report/String, keine Messung
        if (stripos($prof, 'BatterieInteger') !== false) return null;                 // Kapazitaet (mAh), kein Ladestand
        if (preg_match('/Automower\.Battery/i', $prof)) return null;                   // Maeher = Ladeakku, kein Wechselakku
        if (stripos($mod, 'Mower') !== false || stripos($mod, 'Automower') !== false || stripos($name, 'automower') !== false) return null;
        if (stripos($mod, 'Tempest') !== false || preg_match('/^Tempest_/i', $prof)) return null; // Solar/Hub (Tempest_battery_status, Tempest_volt, ...)

        $hay = strtolower($name . '|' . $ident . '|' . $prof);
        $looksBattery = (bool) preg_match('/batter|batterie|lowbat|low_bat|\bakku\b|battery/i', $hay);

        // --- Klassen ---
        // Prozent
        if ($prof === '~Battery.100' || preg_match('/Battery\.100/i', $prof)
            || (in_array($ident, ['BatteryVariable', 'batteryLevel', 'Battery', 'Z2M_Battery', 'batteryState', 'Battery_Status'], true) && $type !== 0)
            || (preg_match('/Gardena\.Battery|batteryLevel/i', $prof) && $type !== 0)) {
            if ($type === 0) { return ['class' => 'bool']; }
            return ['class' => 'pct'];
        }
        // Bool-LOWBAT
        if ($prof === '~Battery' || in_array($ident, ['LOWBAT', 'BatteryLowVariable', 'LOWBAT_REPORTING'], true)) {
            return ['class' => 'bool'];
        }
        // Spannung — NUR wenn Ident/Name explizit Batterie meint (~Volt allein = Netz-/Zaehlerspannung!)
        if ($ident === 'BATTERY_STATE' || stripos($ident, 'BatteryVolt') !== false
            || (($prof === '~Volt' || stripos($prof, 'spannung') !== false) && $looksBattery)) {
            return ['class' => 'volt'];
        }
        // Enum-/Status-Profile mit Assoziationen
        if ($looksBattery && $type !== 3 && $prof !== '' && IPS_VariableProfileExists($prof)) {
            $p = @IPS_GetVariableProfile($prof);
            if (is_array($p) && !empty($p['Associations'])) return ['class' => 'enum'];
        }
        // Fallback ueber Namen/Ident
        if ($looksBattery) {
            if ($type === 0) return ['class' => 'bool'];
            if ($type === 2) return ['class' => 'volt'];
            return ['class' => 'pct'];
        }
        return null;
    }

    /** Repraesentant je Geraet: Prozent > Bool(LOWBAT vor LOWBAT_REPORTING) > Enum > Spannung; stabile Tie-Breaks. */
    private function pickRepresentative(array $cands): array
    {
        $rank = ['pct' => 1, 'bool' => 2, 'enum' => 3, 'volt' => 4];
        usort($cands, function ($a, $b) use ($rank) {
            $ra = $rank[$a['class']] ?? 9; $rb = $rank[$b['class']] ?? 9;
            if ($ra !== $rb) return $ra <=> $rb;
            // LOWBAT vor LOWBAT_REPORTING
            $la = ($a['ident'] === 'LOWBAT_REPORTING') ? 1 : 0;
            $lb = ($b['ident'] === 'LOWBAT_REPORTING') ? 1 : 0;
            if ($la !== $lb) return $la <=> $lb;
            if ($a['updated'] !== $b['updated']) return $b['updated'] <=> $a['updated']; // neuester
            return $a['vid'] <=> $b['vid'];
        });
        return $cands[0];
    }

    /** Zustand aus dem Repraesentanten ableiten. */
    private function classify(array $rep, int $emptyThr, int $warnThr, int $staleSec, int $now): array
    {
        $vid = $rep['vid'];
        $raw = @GetValue($vid);
        $low = false;

        if ($rep['class'] === 'bool') {
            $low = (bool) $raw;
            return ['state' => $low ? self::STATE_EMPTY : self::STATE_OK, 'value' => $low ? 1 : 0,
                    'unit' => '', 'text' => $low ? 'schwach' : 'ok', 'low' => $low];
        }
        if ($rep['class'] === 'pct') {
            $v = is_numeric($raw) ? (float) $raw : null;
            if ($v === null) return ['state' => self::STATE_UNKNOWN, 'value' => null, 'unit' => '%', 'text' => '?', 'low' => false];
            // 0% + lange nicht aktualisiert -> vermutlich "nie gemeldet" => unbekannt
            if ($v <= 0 && $staleSec > 0 && $rep['updated'] > 0 && ($now - $rep['updated']) > $staleSec) {
                return ['state' => self::STATE_UNKNOWN, 'value' => 0, 'unit' => '%', 'text' => 'keine Meldung', 'low' => false];
            }
            $st = ($v >= $warnThr) ? self::STATE_OK : (($v >= $emptyThr) ? self::STATE_WARN : self::STATE_EMPTY);
            return ['state' => $st, 'value' => round($v, 0), 'unit' => '%', 'text' => round($v, 0) . '%',
                    'low' => $st === self::STATE_EMPTY];
        }
        if ($rep['class'] === 'enum') {
            $txt = @GetValueFormatted($vid);
            $st = self::STATE_UNKNOWN;
            if (preg_match('/leer|low|schwach|empty|kritisch|critical/i', (string) $txt)) $st = self::STATE_EMPTY;
            elseif (preg_match('/mittel|medium|warn/i', (string) $txt)) $st = self::STATE_WARN;
            elseif (preg_match('/ok|voll|full|gut|normal|hoch|high/i', (string) $txt)) $st = self::STATE_OK;
            return ['state' => $st, 'value' => is_numeric($raw) ? $raw : null, 'unit' => '', 'text' => (string) $txt,
                    'low' => $st === self::STATE_EMPTY];
        }
        // volt: Info-only (kein generisches Schwellwert-Mapping)
        $v = is_numeric($raw) ? round((float) $raw, 2) : null;
        return ['state' => self::STATE_UNKNOWN, 'value' => $v, 'unit' => 'V',
                'text' => $v !== null ? ($v . ' V') : '?', 'low' => false];
    }

    // ==================================================================================
    //  Ausgaben: Statistik-Variablen, Register/Tabelle, Links
    // ==================================================================================

    private function writeOutputs(array $res, bool $withLinks): void
    {
        $c = $res['counts'];
        $this->SetValue('Total', $c['total']);
        $this->SetValue('Ok', $c['ok']);
        $this->SetValue('Warn', $c['warn']);
        $this->SetValue('Empty', $c['empty']);
        $this->SetValue('Unknown', $c['unknown']);
        $this->SetValue('Weak', $c['weak']);
        $this->SetValue('OkPercent', $c['total'] > 0 ? (int) round($c['ok'] / $c['total'] * 100) : 0);
        $this->SetValue('Status', $c['empty'] > 0 ? self::STATE_EMPTY : ($c['warn'] > 0 ? self::STATE_WARN : self::STATE_OK));
        $this->SetValue('Register', $this->buildRegister($res));
        $this->SetValue('Table', $this->buildTable($res));
        $this->SetValue('LastRun', $res['ts']);
        if ($withLinks && $this->ReadPropertyBoolean('CreateLinks')) {
            $this->syncLinks($res['devices']);
        }
    }

    private function stateKey(int $s): string
    {
        return [self::STATE_OK => 'ok', self::STATE_WARN => 'warn', self::STATE_EMPTY => 'empty', self::STATE_UNKNOWN => 'unknown'][$s] ?? 'unknown';
    }

    private function buildRegister(array $res): string
    {
        $out = ['ts' => $res['ts'], 'ver' => 1, 'counts' => $res['counts'], 'devices' => []];
        foreach ($res['devices'] as $d) {
            $out['devices'][] = [
                'name' => $d['name'], 'room' => $d['room'], 'system' => $d['system'],
                'state' => $this->stateKey($d['state']), 'type' => $d['type'],
                'value' => $d['value'], 'unit' => $d['unit'], 'text' => $d['text'],
                'varId' => $d['varId'], 'low' => $d['low'], 'ts' => $d['ts'],
            ];
        }
        return json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** JSON-Tabelle fuers LVB table-Widget: Zeile 0 = Spaltenkopf, dann Datenzeilen. */
    private function buildTable(array $res): string
    {
        $rows = [['Gerät', 'Ort', 'System', 'Status', 'Wert', 'Aktualisiert']];
        $lbl = ['ok' => 'OK', 'warn' => 'Bald', 'empty' => 'Leer', 'unknown' => '?'];
        foreach ($res['devices'] as $d) {
            $rows[] = [
                $d['name'], $d['room'], $d['system'], $lbl[$this->stateKey($d['state'])],
                $d['text'], $d['ts'] ? date('d.m. H:i', $d['ts']) : '',
            ];
        }
        return json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** Links nach Status gruppiert anlegen/verschieben/entfernen (idempotent). Namen = klare Geraetezuordnung. */
    private function syncLinks(array $devices): void
    {
        $root = $this->ReadPropertyInteger('RootCategory');
        if ($root <= 0 || !IPS_ObjectExists($root)) { $root = $this->ensureCategory($this->InstanceID, 'BatRoot', 'Batterien'); }

        $cat = [
            self::STATE_EMPTY => $this->ensureCategory($root, 'catEmpty', 'Leer / tauschen'),
            self::STATE_WARN  => $this->ensureCategory($root, 'catWarn', 'Bald tauschen'),
            self::STATE_OK    => $this->ensureCategory($root, 'catOk', 'OK'),
            self::STATE_UNKNOWN => $this->ensureCategory($root, 'catUnknown', 'Unbekannt'),
        ];

        // Bestehende Links je Ziel einsammeln
        $existing = []; // targetID => linkID
        foreach ($cat as $cid) {
            foreach (@IPS_GetChildrenIDs($cid) ?: [] as $child) {
                if (IPS_GetObject($child)['ObjectType'] === 6) { // Link
                    $t = @IPS_GetLink($child)['TargetID'];
                    if ($t) $existing[$t] = $child;
                }
            }
        }

        $keep = [];
        foreach ($devices as $d) {
            $target = $d['varId']; $group = $cat[$d['state']] ?? $cat[self::STATE_UNKNOWN];
            $lname = $this->linkName($d);
            if (isset($existing[$target])) {
                $lid = $existing[$target];
                if (IPS_GetObject($lid)['ParentID'] !== $group) @IPS_SetParent($lid, $group); // in richtige Gruppe verschieben
                if (IPS_GetName($lid) !== $lname) @IPS_SetName($lid, $lname);
            } else {
                $lid = @IPS_CreateLink();
                @IPS_SetParent($lid, $group);
                @IPS_SetLinkTargetID($lid, $target);
                @IPS_SetName($lid, $lname);
            }
            $keep[$target] = true;
        }

        // Verwaiste Links entfernen (Batterie verschwunden/ausgeschlossen)
        foreach ($existing as $target => $lid) {
            if (empty($keep[$target])) { @IPS_DeleteLink($lid); }
        }
    }

    private function ensureCategory(int $parent, string $ident, string $name): int
    {
        $cid = @IPS_GetObjectIDByIdent($ident, $parent);
        if ($cid === false) {
            $cid = IPS_CreateCategory();
            IPS_SetParent($cid, $parent);
            IPS_SetIdent($cid, $ident);
        }
        if (IPS_GetName($cid) !== $name) IPS_SetName($cid, $name);
        return $cid;
    }

    private function linkName(array $d): string
    {
        $base = $d['room'] !== '' ? ($d['room'] . ' · ' . $d['name']) : $d['name'];
        $val = $d['text'] !== '' ? (' — ' . $d['text']) : '';
        return mb_substr($base . $val, 0, 90);
    }

    // ==================================================================================
    //  Baum-/Namens-Helfer
    // ==================================================================================

    private function ownerInstance(int $vid): int
    {
        $p = $vid;
        for ($i = 0; $i < 30 && $p > 0; $i++) {
            $par = @IPS_GetObject($p)['ParentID'] ?? 0;
            if ($par <= 0) return 0;
            if (IPS_InstanceExists($par)) return $par;
            $p = $par;
        }
        return 0;
    }

    /**
     * [Geraetename, Raum/Ort, voller Pfad] aus der Objekt-Location. Strukturelle/generische Container
     * (Hardware, Homematic, Device Information, Maintenance ...) werden uebersprungen, damit der Bezeichner
     * das echte Geraet trifft (z. B. „Feuchtesensor Böschung" statt „Device Information").
     */
    private function labelFor(int $vid): array
    {
        $skip = ['Hardware', 'Homematic', 'HomeMatic', 'Geräte', 'Devices', 'Zentrale', 'Zentralen', 'Schnittstellen',
                 'Konfigurator', 'Device Information', 'Maintenance', 'Wartung', 'MAINTENANCE', 'Kanal 0', 'Channel 0',
                 'GARDENA smart Garden'];
        $parts = array_values(array_filter(explode("\\", (string) @IPS_GetLocation($vid)), fn($s) => $s !== ''));
        array_pop($parts); // Variablenname weg (LOWBAT / Battery Level ...)
        $meaningful = array_values(array_filter($parts, fn($s) => !in_array($s, $skip, true)));
        $device = count($meaningful) ? $meaningful[count($meaningful) - 1] : ('#' . $vid);
        $room = count($meaningful) >= 2 ? $meaningful[count($meaningful) - 2] : '';
        return [$device, $room, implode(' / ', $parts)];
    }

    private function isHM(string $mod): bool
    {
        return stripos($mod, 'HomeMatic') !== false || stripos($mod, 'Homematic') !== false;
    }

    private function systemOf(string $mod, string $key): string
    {
        if ($this->isHM($mod)) return 'HM';
        $m = strtolower($mod);
        if (strpos($m, 'z-wave') !== false || strpos($m, 'zwave') !== false) return 'ZWave';
        if (strpos($m, 'zigbee') !== false) return 'Zigbee';
        if (strpos($m, 'shelly') !== false) return 'Shelly';
        if (strpos($m, 'gardena') !== false) return 'Gardena';
        if (strpos($m, 'linktap') !== false) return 'LinkTap';
        if (strpos($m, 'mqtt') !== false) return 'MQTT';
        if (strpos($m, 'withings') !== false) return 'Withings';
        if (strpos($m, 'tempest') !== false) return 'Tempest';
        return $mod !== '' ? $mod : 'Other';
    }

    public function GetConfigurationForm()
    {
        $note = 'Noch kein Scan.';
        $reg = @json_decode((string) $this->GetValue('Register'), true);
        if (is_array($reg) && isset($reg['counts'])) {
            $c = $reg['counts'];
            $note = sprintf('Letzter Scan: %s · gesamt %d · OK %d · Bald %d · Leer %d · Unbekannt %d',
                $this->GetValue('LastRun') ? date('d.m.y H:i', $this->GetValue('LastRun')) : '?',
                $c['total'] ?? 0, $c['ok'] ?? 0, $c['warn'] ?? 0, $c['empty'] ?? 0, $c['unknown'] ?? 0);
        }
        return json_encode([
            'elements' => [
                ['type' => 'Label', 'caption' => 'Battery Manager — sammelt alle Geraetebatterien, dedupliziert (HomeMatic wird pro physischem Geraet nur einmal gezaehlt) und legt gruppierte Links + Statistik + JSON-Register/Tabelle an.'],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'NumberSpinner', 'name' => 'Interval', 'caption' => 'Scan-Intervall (Minuten, 0 = aus)'],
                    ['type' => 'NumberSpinner', 'name' => 'WarnThreshold', 'caption' => 'Bald tauschen unter (%)'],
                    ['type' => 'NumberSpinner', 'name' => 'EmptyThreshold', 'caption' => 'Leer unter (%)'],
                    ['type' => 'NumberSpinner', 'name' => 'StaleDays', 'caption' => '0%-Karenz (Tage)'],
                    ['type' => 'NumberSpinner', 'name' => 'MaxAgeDays', 'caption' => 'Max. Alter %/Spannung (Tage, 0=aus)'],
                ]],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'CheckBox', 'name' => 'IncludeHidden', 'caption' => 'Versteckte Variablen einbeziehen'],
                    ['type' => 'CheckBox', 'name' => 'CreateLinks', 'caption' => 'Links im Baum pflegen'],
                ]],
                ['type' => 'SelectCategory', 'name' => 'RootCategory', 'caption' => 'Ziel-Ordner fuer Links (0 = unter der Instanz)'],
                ['type' => 'ValidationTextBox', 'name' => 'ExcludeIdents', 'caption' => 'Ausschluss (Teilstrings, Komma-getrennt)'],
                ['type' => 'List', 'name' => 'ExcludeVars', 'caption' => 'Ausgeschlossene Variablen (aus dem Baum wählen — entfernt das ganze Gerät)',
                 'add' => true, 'delete' => true, 'rowCount' => 6,
                 'columns' => [
                     ['caption' => 'Variable', 'name' => 'VarID', 'width' => 'auto', 'add' => 0,
                      'edit' => ['type' => 'SelectVariable']],
                 ]],
                ['type' => 'Button', 'caption' => 'Jetzt scannen', 'onClick' => 'BM_Update($id);'],
                ['type' => 'Label', 'caption' => $note],
            ],
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Hoep\HomeSuite\Drivers\Pool;

/**
 * PoolClient — selbst-enthaltener HTTP-Client fuer ProCon.IP-artige Pool-Controller
 * (pooldigital.de, FW 1.6/1.7). P0/P1: reiner LESE-Pfad (GetState.csv, GetDos.csv).
 *
 * Bewusst OHNE Abhaengigkeit von den PHPPoolcontroller-Altklassen (Cleanroom): die
 * Portierung des Schreib-/Regel-/Dosier-Pfads folgt in spaeteren Phasen als eigene
 * Methoden dieser Klasse, immer hinter dem Armed-Gate des Moduls.
 *
 * Robustes Parsing: Werte werden GENERISCH ueber die vom Geraet mitgelieferten
 * Offset-/Gain-Zeilen berechnet (Wert = Offset + Gain * Rohwert) statt fester
 * Skalierungen im Code — so bricht ein FW-Update nicht still. Spaltenindizes sind
 * konfigurierbar (Defaults aus dem Live-Geraet 192.168.1.30 verifiziert).
 *
 * Auth: HTTP Basic pro Request. Nur HTTP (Geraet kann kein HTTPS) — UseHTTPS ist
 * als Option vorhanden, Default aus.
 */
final class PoolClient
{
    private string $host;
    private string $user;
    private string $pass;
    private int $port;
    private int $timeout;
    private int $connectTimeout;
    private bool $useHttps;

    /** Bekannte Spaltenzuordnung der Wertezeile (0-indexiert), live verifiziert. */
    public const COL_TIME       = 0;
    public const COL_PRESSURE   = 3;   // ADC2 Kesseldruck (mBar)
    public const COL_FLOWVOL    = 4;   // ADC3 Durchfluss (m3/h) - nur bei Durchfluss sinnvoll
    public const COL_CPUTEMP    = 5;
    public const COL_REDOX      = 6;   // BNC0 (mV)
    public const COL_PH         = 7;   // BNC1 (pH)
    public const COL_TEMP_BASE  = 8;   // S0..S7 -> 8..15 (S0=Pool)
    public const COL_RELAY_BASE = 16;  // Relais 1..8 -> 16..23
    public const COL_DI_BASE    = 24;  // Digital-In 1..4 -> 24..27 (DI0=Anstroemung cm/s)
    public const COL_CANISTER   = 36;  // 36..38 Fuellstand %, 39..41 Verbrauch ml

    public function __construct(array $cfg)
    {
        $this->host           = trim((string) ($cfg['host'] ?? ''));
        $this->user           = (string) ($cfg['user'] ?? 'admin');
        $this->pass           = (string) ($cfg['pass'] ?? '');
        $this->port           = (int) ($cfg['port'] ?? 80);
        $this->timeout        = max(2, (int) ($cfg['timeout'] ?? 10));
        $this->connectTimeout = max(1, (int) ($cfg['connectTimeout'] ?? 5));
        $this->useHttps       = (bool) ($cfg['useHttps'] ?? false);
    }

    public function isConfigured(): bool
    {
        return $this->host !== '';
    }

    private function url(string $path): string
    {
        $scheme = $this->useHttps ? 'https' : 'http';
        $p      = ltrim($path, '/');
        return sprintf('%s://%s:%d/%s', $scheme, $this->host, $this->port, $p);
    }

    /**
     * Roh-GET mit Basic-Auth und Cache-Buster. Gibt Body oder null zurueck.
     * Wirft NIE — Fehler werden als null signalisiert (Aufrufer entscheidet).
     */
    public function raw(string $path): ?string
    {
        if (!$this->isConfigured() || !function_exists('curl_init')) {
            return null;
        }
        $sep = (strpos($path, '?') === false) ? '?' : '&';
        $url = $this->url($path) . $sep . 'a=' . (string) mt_rand(1, 999999);

        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
            CURLOPT_USERPWD        => $this->user . ':' . $this->pass,
            CURLOPT_HTTPHEADER     => ['Cache-Control: no-cache'],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $code !== 200) {
            return null;
        }
        return (string) $body;
    }

    /** Verbindungstest (leichtgewichtig). */
    public function ping(): array
    {
        $body = $this->raw('GetState.csv');
        if ($body === null) {
            return ['ok' => false, 'error' => 'unreachable'];
        }
        $st = $this->parseState($body);
        return ['ok' => (bool) ($st['ok'] ?? false), 'firmware' => $st['firmware'] ?? '', 'error' => $st['error'] ?? null];
    }

    // ==================================================================
    // GetState.csv
    // ==================================================================

    public function getState(): array
    {
        $body = $this->raw('GetState.csv');
        if ($body === null) {
            return ['ok' => false, 'error' => 'unreachable'];
        }
        return $this->parseState($body);
    }

    /**
     * Parst die 6-Zeilen-CSV. Zeilen: [0]=sys, [1]=Namen, [2]=Einheiten,
     * [3]=Offset, [4]=Gain, [5]=Werte. Wert = Offset + Gain * Rohwert.
     *
     * @return array{ok:bool,error?:string,firmware?:string,uptime?:int,statusFlag?:int,
     *   cols?:array<int,array{name:string,unit:string,offset:float,gain:float,raw:float,val:float}>}
     */
    public function parseState(string $csv): array
    {
        $lines = preg_split('/\r?\n/', trim($csv)) ?: [];
        if (count($lines) < 6) {
            return ['ok' => false, 'error' => 'csv_short'];
        }
        $sys    = str_getcsv($lines[0]);
        $names  = str_getcsv($lines[1]);
        $units  = str_getcsv($lines[2]);
        $offset = str_getcsv($lines[3]);
        $gain   = str_getcsv($lines[4]);
        $values = str_getcsv($lines[5]);

        $n    = count($values);
        $cols = [];
        for ($i = 0; $i < $n; $i++) {
            $raw = (float) ($values[$i] ?? 0);
            $off = (float) ($offset[$i] ?? 0);
            $gn  = isset($gain[$i]) && $gain[$i] !== '' ? (float) $gain[$i] : 1.0;
            $cols[$i] = [
                'name'   => (string) ($names[$i] ?? ''),
                'unit'   => (string) ($units[$i] ?? ''),
                'offset' => $off,
                'gain'   => $gn,
                'raw'    => $raw,
                'val'    => $off + $gn * $raw,
            ];
        }

        return [
            'ok'         => true,
            'firmware'   => (string) ($sys[1] ?? ''),
            'uptime'     => (int) ($sys[2] ?? 0),
            'statusFlag' => (int) ($sys[4] ?? 0),
            'featureBits' => (int) ($sys[5] ?? 0),
            'sys'        => $sys,
            'cols'       => $cols,
        ];
    }

    /** Physikalischer Wert einer Spalte (Offset+Gain), oder null wenn nicht vorhanden. */
    public static function colVal(array $state, int $index): ?float
    {
        return isset($state['cols'][$index]) ? (float) $state['cols'][$index]['val'] : null;
    }

    /** Name einer Spalte aus der Namenszeile (leer wenn nicht vorhanden). */
    public static function colName(array $state, int $index): string
    {
        return isset($state['cols'][$index]) ? (string) $state['cols'][$index]['name'] : '';
    }

    // ==================================================================
    // GetDos.csv (Dosier-Livezustand, je Regler eine Zeile: Cl=0, pH-=1, pH+=2)
    // ==================================================================

    public function getDos(): array
    {
        $body = $this->raw('GetDos.csv');
        if ($body === null) {
            return ['ok' => false, 'error' => 'unreachable'];
        }
        $lines = preg_split('/\r?\n/', trim($body)) ?: [];
        $rows  = [];
        foreach ($lines as $ln) {
            if (trim($ln) === '') {
                continue;
            }
            $rows[] = array_map(static fn($c) => is_numeric($c) ? (float) $c : $c, str_getcsv($ln));
        }
        return ['ok' => $rows !== [], 'rows' => $rows];
    }

    // ==================================================================
    // SCHREIB-Pfad (P2+). Serialisierung/Armed-Gate liegen im Modul; hier nur
    // der reine, faithful portierte Transport. Alle POST-Werte werden konsequent
    // urlencodet (Fix des Alt-Bugs bei Namen/ROM-Codes mit Sonderzeichen).
    // ==================================================================

    /** Roh-POST (application/x-www-form-urlencoded). Body wird UNVERAENDERT gesendet. */
    public function postRaw(string $path, string $body): array
    {
        if (!$this->isConfigured() || !function_exists('curl_init')) {
            return ['ok' => false, 'error' => 'not_configured'];
        }
        $ch = curl_init($this->url($path));
        if ($ch === false) {
            return ['ok' => false, 'error' => 'curl_init'];
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
            CURLOPT_USERPWD        => $this->user . ':' . $this->pass,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        return ['ok' => ($resp !== false && $code === 200), 'code' => $code, 'error' => $err ?: null];
    }

    /**
     * usrcfg.cgi-Sektion schreiben. $fields werden urlencodet und mit dem Sektions-
     * Marker (z.B. RDXCNTRL=1) abgeschlossen. Nur der Marker bleibt unencodiert.
     */
    public function postSection(string $sectionMarker, array $fields): array
    {
        $parts = [];
        foreach ($fields as $k => $v) {
            $parts[] = rawurlencode((string) $k) . '=' . rawurlencode((string) $v);
        }
        $parts[] = $sectionMarker . '=1';
        return $this->postRaw('usrcfg.cgi', implode('&', $parts));
    }

    /** Command.htm-Aktion (GET) mit Cache-Buster. */
    public function command(string $cmd): array
    {
        $rand = (string) mt_rand(1, 999999999);
        $body = $this->raw('Command.htm?' . $cmd . ',' . $rand);
        return ['ok' => $body !== null, 'response' => $body];
    }

    /**
     * Relais-Modi setzen (Auto/Manuell-Aus/Manuell-Ein). $modes: 16 Zeichen
     * 'A'|'O'|'I' (Index 0..15). Protokoll aus der Firmware-GUI verifiziert:
     *   'O' (Manuell Aus): ena-Bit gesetzt; 'I' (Manuell Ein): ena- UND state-Bit;
     *   'A' (Auto): kein Bit. POST /usrcfg.cgi "ENA=<ena>,<state>&MANUAL=1".
     */
    public function setRelayModes(array $modes): array
    {
        $ena = 0;
        $state = 0;
        for ($i = 0; $i < 16; $i++) {
            $m = strtoupper((string) ($modes[$i] ?? 'A'));
            if ($m === 'O') {
                $ena |= (1 << $i);
            } elseif ($m === 'I') {
                $ena |= (1 << $i);
                $state |= (1 << $i);
            }
        }
        return $this->postRaw('usrcfg.cgi', 'ENA=' . $ena . ',' . $state . '&MANUAL=1');
    }

    /** Manuelle Dosierung (type 0=Cl/Redox, 1=pH-, 2=pH+; Sekunden, 0=Stop). */
    public function manualDosage(int $type, int $seconds): array
    {
        return $this->command('MAN_DOSAGE=' . $type . ',' . $seconds);
    }

    /**
     * Kanister/Zelle zuruecksetzen. $type 0=Cl(RDXCNTRL),1=pH-(PHCNTRL),2=pH+(PHPCNTRL).
     * CANQUANT in ml (= Liter*1000), LASTRST = aktueller Zeitstempel "dd.Mon.YYYY HH:MM".
     */
    public function resetContainer(int $type, float $liters): array
    {
        $marker = [0 => 'RDXCNTRL', 1 => 'PHCNTRL', 2 => 'PHPCNTRL'][$type] ?? 'RDXCNTRL';
        $months = ['Jan', 'Feb', 'Mär', 'Apr', 'Mai', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dez'];
        $n      = (int) date('n');
        $stamp  = date('d') . '.' . ($months[$n - 1] ?? 'Jan') . '.' . date('Y') . ' ' . date('H:i');
        return $this->postSection($marker, [
            'CANQUANT' => (int) round($liters * 1000.0),
            'LASTRST'  => $stamp,
        ]);
    }

    /**
     * Fehler-/Ereignislog lesen (/log/error.log). Zaehlt NUR echte Fehlereintraege
     * (Zeilen `E<code>=<ts>,<cause>`), nicht den HTML-Wrapper. "Rescue Control" =>
     * keine Fehler. (Alt-Bug behoben: es wurden HTML-Zeilen mitgezaehlt.)
     */
    public function getErrors(): array
    {
        $body = $this->raw('log/error.log');
        if ($body === null) {
            return ['ok' => false, 'error' => 'unreachable', 'lines' => [], 'count' => 0];
        }
        if (preg_match('/Rescue Control/i', $body)) {
            return ['ok' => true, 'lines' => [], 'count' => 0];
        }
        $lines = [];
        foreach (preg_split('/\r?\n/', $body) ?: [] as $ln) {
            $ln = trim(strip_tags($ln));
            if ($ln !== '' && preg_match('/^E\d+\s*=/', $ln)) {
                $lines[] = $ln;
            }
        }
        return ['ok' => true, 'lines' => $lines, 'count' => count($lines)];
    }

    /** Fehlerlog loeschen (Command.htm?CLRCODES). Firmware-korrekt OHNE Doppel-?. */
    public function clearErrors(): array
    {
        return $this->command('CLRCODES=1');
    }

    // ==================================================================
    // INI-Helfer (Read-Modify-Write-Basis)
    // ==================================================================

    /** INI/CSV lesen, in Zeilen splitten (CRLF/LF). */
    private function lines(string $path): ?array
    {
        $body = $this->raw($path);
        if ($body === null) {
            return null;
        }
        return preg_split('/\r?\n/', rtrim($body, "#\r\n\t ")) ?: [];
    }

    /** Wert nach dem ersten '=' einer Zeile (leer wenn keins). */
    private static function afterEq(string $line): string
    {
        $p = strpos($line, '=');
        return $p === false ? '' : substr($line, $p + 1);
    }

    // ==================================================================
    // Steuerregeln (TIMEC / TEMPC / ADCC / SWITCHC) — generisch
    // ==================================================================

    private const RULE_SPEC = [
        'TIMEC'   => ['file' => 'usr/timec.ini',   'prefix' => 'RULE',   'count' => 16, 'len' => 15],
        'TEMPC'   => ['file' => 'usr/tempc.ini',   'prefix' => 'RULE',   'count' => 8,  'len' => 10],
        'ADCC'    => ['file' => 'usr/adcc.ini',    'prefix' => 'ANALOG', 'count' => 8,  'len' => 16],
        'SWITCHC' => ['file' => 'usr/switchc.ini', 'prefix' => 'RULE',   'count' => 8,  'len' => 6],
    ];

    /** Regeln einer Sektion als Integer-Matrix [ruleIndex => [werte...]] lesen. */
    public function getRules(string $section): array
    {
        $s = self::RULE_SPEC[$section] ?? null;
        if ($s === null) {
            return ['ok' => false, 'error' => 'unknown_section'];
        }
        $lines = $this->lines($s['file']);
        if ($lines === null) {
            return ['ok' => false, 'error' => 'unreachable'];
        }
        $rules = [];
        foreach ($lines as $ln) {
            $ln = trim($ln);
            if ($ln === '' || $ln[0] === '[') {
                continue;
            }
            if (!preg_match('/^(?:RULE|ANALOG)?(\d+)=(.*)$/i', $ln, $m)) {
                continue;
            }
            $idx = (int) $m[1];
            if ($idx < 0 || $idx >= $s['count']) {
                continue;
            }
            $vals = array_map('intval', explode(',', $m[2]));
            $vals = array_pad(array_slice($vals, 0, $s['len']), $s['len'], 0);
            $rules[$idx] = $vals;
        }
        for ($i = 0; $i < $s['count']; $i++) {
            if (!isset($rules[$i])) {
                $rules[$i] = array_fill(0, $s['len'], 0);
            }
        }
        ksort($rules);
        return ['ok' => true, 'rules' => $rules];
    }

    /** Vollstaendigen Regelsatz einer Sektion schreiben (Marker als letztes Feld). */
    public function setRules(string $section, array $rules): array
    {
        $s = self::RULE_SPEC[$section] ?? null;
        if ($s === null) {
            return ['ok' => false, 'error' => 'unknown_section'];
        }
        $parts = [];
        for ($i = 0; $i < $s['count']; $i++) {
            $rule = $rules[$i] ?? array_fill(0, $s['len'], 0);
            $rule = array_pad(array_slice(array_map('intval', $rule), 0, $s['len']), $s['len'], 0);
            $parts[] = $s['prefix'] . $i . '=' . implode(',', $rule);
        }
        return $this->postRaw('usrcfg.cgi', implode('&', $parts) . '&' . $section . '=1');
    }

    // ==================================================================
    // Dosierung — Konfiguration lesen/schreiben (RDXCNTRL/PHCNTRL/PHPCNTRL)
    // ==================================================================

    private const DOS_FILE = [0 => 'usr/rdxcntrl.ini', 1 => 'usr/phcntrl.ini', 2 => 'usr/phpcntrl.ini'];
    private const DOS_MARK = [0 => 'RDXCNTRL', 1 => 'PHCNTRL', 2 => 'PHPCNTRL'];

    /** Dosier-Konfiguration lesen. type 0=Cl/Redox, 1=pH-, 2=pH+. */
    public function getDosageConfig(int $type): array
    {
        $file = self::DOS_FILE[$type] ?? null;
        if ($file === null) {
            return ['ok' => false, 'error' => 'bad_type'];
        }
        $lines = $this->lines($file);
        if ($lines === null) {
            return ['ok' => false, 'error' => 'unreachable'];
        }
        $v = static function (array $lines, int $i): string {
            return isset($lines[$i]) ? self::afterEq((string) $lines[$i]) : '';
        };
        $pair = static function (string $csv, int $i): string {
            $a = explode(',', $csv);
            return $a[$i] ?? '';
        };
        if ($type === 0) { // Cl/Redox
            $flow = explode(',', $v($lines, 5));
            $pol  = explode(',', $v($lines, 18));
            return ['ok' => true, 'type' => 0, 'raw' => $lines, 'config' => [
                'enabled'      => (int) $v($lines, 1) === 1,
                'cntrlType'    => (int) $v($lines, 2),   // 0=Fluessig,1=Salz
                'filterPump'   => (int) $v($lines, 3),
                'dosagePump'   => (int) $v($lines, 4),
                'flowValue'    => (float) ($flow[0] ?? 0),
                'flowTime'     => (int) ($flow[1] ?? 3600),
                'kp'           => (float) $v($lines, 6),
                'maxQuantity'  => (int) $v($lines, 7),
                'delaySec'     => (int) $v($lines, 8),
                'target'       => (float) $pair($v($lines, 9), 1),
                'lowerLimit'   => (float) $pair($v($lines, 10), 1),
                'upperLimit'   => (float) $pair($v($lines, 11), 1),
                'refTimeSec'   => (int) $v($lines, 12),
                'minTimeSec'   => (int) $v($lines, 13),
                'maxTimeSec'   => (int) $v($lines, 14),
                'containerL'   => (float) $v($lines, 16) / 1000.0,
                'lastReset'    => $v($lines, 17),
                'polChange'    => (int) ($pol[0] ?? 0),
                'polRelay'     => (int) ($pol[1] ?? 0),
                'polIntervalS' => (int) ($pol[2] ?? 0),
                'polPauseMs'   => (int) ($pol[3] ?? 0),
                'manualSec'    => (int) $v($lines, 19),
            ]];
        }
        // pH- / pH+ (um eine Zeile versetzt, kein cntrlType/refT)
        $flow = explode(',', $v($lines, 4));
        $user = explode(',', $v($lines, 5));
        return ['ok' => true, 'type' => $type, 'raw' => $lines, 'config' => [
            'enabled'     => (int) $v($lines, 1) === 1,
            'filterPump'  => (int) $v($lines, 2),
            'dosagePump'  => (int) $v($lines, 3),
            'flowMl'      => (float) ($flow[0] ?? 1500),
            'flowSec'     => (int) ($flow[1] ?? 3600),
            'poolParam1'  => (float) ($user[0] ?? 200),
            'poolParam2'  => (float) ($user[1] ?? 0.10),
            'kp'          => (float) $v($lines, 6),
            'maxQuantity' => (int) $v($lines, 7),
            'delaySec'    => (int) $v($lines, 8),
            'target'      => (float) $pair($v($lines, 9), 1),
            'lowerLimit'  => (float) $pair($v($lines, 10), 1),
            'upperLimit'  => (float) $pair($v($lines, 11), 1),
            'minTimeSec'  => (int) $v($lines, 12),
            'maxTimeSec'  => (int) $v($lines, 13),
            'containerL'  => (float) $v($lines, 15) / 1000.0,
            'lastReset'   => $v($lines, 16),
            'manualSec'   => (int) $v($lines, 17),
        ]];
    }

    private function lastRstStamp(): string
    {
        $months = ['Jan', 'Feb', 'Mär', 'Apr', 'Mai', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dez'];
        $n = (int) date('n');
        return date('d') . '.' . ($months[$n - 1] ?? 'Jan') . '.' . date('Y') . ' ' . date('H:i');
    }

    /** Dosier-Konfiguration schreiben (faithful, PROTOCOLS §6). */
    public function setDosageConfig(int $type, array $c): array
    {
        $marker = self::DOS_MARK[$type] ?? null;
        if ($marker === null) {
            return ['ok' => false, 'error' => 'bad_type'];
        }
        $stamp = ($c['lastReset'] ?? '') !== '' ? (string) $c['lastReset'] : $this->lastRstStamp();
        if ($type === 0) { // Cl/Redox — TARGET/MIN/MAX *16
            $target = (float) ($c['target'] ?? 750);
            $lower  = (float) ($c['lowerLimit'] ?? 400);
            $upper  = (float) ($c['upperLimit'] ?? 900);
            $kp     = (float) ($c['kp'] ?? 0.25);
            $refT   = (int) ($c['refTimeSec'] ?? 0);
            $flowV  = (float) ($c['flowValue'] ?? 0);
            $maxQ   = (int) ($c['maxQuantity'] ?? 0);
            if ((int) ($c['cntrlType'] ?? 0) === 1) { // Salz: Ruecktransformation
                $flowV = $flowV / 1.25 / 0.126;
                $maxQ  = (int) round($maxQ / 1.25 / 0.126);
            }
            $body = 'TYPE=' . (int) (($c['enabled'] ?? false) ? 1 : 0)
                . '&DTYPE=' . (int) ($c['cntrlType'] ?? 0)
                . '&FILTER=' . (int) ($c['filterPump'] ?? 0)
                . '&RDXPUMP=' . (int) ($c['dosagePump'] ?? 0)
                . '&FLOW=' . $flowV . ',' . (int) ($c['flowTime'] ?? 3600)
                . '&KP_PARM=' . $kp
                . '&MAXQUANT=' . $maxQ
                . '&DELAY=' . (int) ($c['delaySec'] ?? 0)
                . '&TARGET=' . (int) round($target * 16) . ',' . $target
                . '&MIN_VAL=' . (int) round($lower * 16) . ',' . $lower
                . '&MAX_VAL=' . (int) round($upper * 16) . ',' . $upper
                . '&REF_T=' . $refT
                . '&MIN_T=' . (int) ($c['minTimeSec'] ?? 0)
                . '&MAX_T=' . (int) ($c['maxTimeSec'] ?? 0)
                . '&KP=' . (int) round($kp * $refT)
                . '&CANQUANT=' . (int) round((float) ($c['containerL'] ?? 0) * 1000)
                . '&LASTRST=' . rawurlencode($stamp)
                . '&POLARITY=' . (int) ($c['polChange'] ?? 0) . ',' . (int) ($c['polRelay'] ?? 0) . ','
                    . (int) ($c['polIntervalS'] ?? 0) . ',' . (int) ($c['polPauseMs'] ?? 0) . ',150'
                . '&MDOSTIME=' . (int) ($c['manualSec'] ?? 0)
                . '&RDXCNTRL=1';
            return $this->postRaw('usrcfg.cgi', $body);
        }
        // pH- / pH+ — TARGET/MIN/MAX *128
        $target = (float) ($c['target'] ?? ($type === 1 ? 7.00 : 6.90));
        $lower  = (float) ($c['lowerLimit'] ?? 6.60);
        $upper  = (float) ($c['upperLimit'] ?? 7.60);
        $kp     = (float) ($c['kp'] ?? 0.10);
        $p1     = (float) ($c['poolParam1'] ?? 200);
        $p2     = (float) ($c['poolParam2'] ?? 0.10);
        $ml     = (float) ($c['flowMl'] ?? 1500);
        $sec    = (int) ($c['flowSec'] ?? 3600);
        $kpCalc = ($p2 > 0 && $ml > 0) ? (int) round($kp * ($p1 / ($p2 * 128.0)) * (($sec * 1000.0) / $ml)) : 0;
        $body = 'TYPE=' . (int) (($c['enabled'] ?? false) ? 1 : 0)
            . '&FILTER=' . (int) ($c['filterPump'] ?? 0)
            . '&PHPUMP=' . (int) ($c['dosagePump'] ?? 0)
            . '&FLOW=' . $ml . ',' . $sec
            . '&USER=' . $p1 . ',' . $p2
            . '&KP_PARM=' . $kp
            . '&MAXQUANT=' . (int) ($c['maxQuantity'] ?? 500)
            . '&DELAY=' . (int) ($c['delaySec'] ?? 0)
            . '&TARGET=' . (int) round($target * 128) . ',' . $target
            . '&MIN_VAL=' . (int) round($lower * 128) . ',' . $lower
            . '&MAX_VAL=' . (int) round($upper * 128) . ',' . $upper
            . '&MIN_T=' . (int) ($c['minTimeSec'] ?? 0)
            . '&MAX_T=' . (int) ($c['maxTimeSec'] ?? 0)
            . '&KP=' . $kpCalc
            . '&CANQUANT=' . (int) round((float) ($c['containerL'] ?? 0) * 1000)
            . '&LASTRST=' . rawurlencode($stamp)
            . '&MDOSTIME=' . (int) ($c['manualSec'] ?? 0)
            . '&' . $marker . '=1';
        return $this->postRaw('usrcfg.cgi', $body);
    }

    // ==================================================================
    // Netzwerk (NUR aus Firmware baubar — PHP-Altklasse kaputt)
    // ==================================================================

    public function getNetwork(): array
    {
        $lines = $this->lines('GetNetw.csv');
        if ($lines === null) {
            return ['ok' => false, 'error' => 'unreachable'];
        }
        $row = static fn(int $i) => isset($lines[$i]) ? explode(',', trim((string) $lines[$i])) : [];
        return ['ok' => true, 'raw' => $lines, 'network' => [
            'dhcp' => (int) ($row(0)[0] ?? 0), 'ip' => array_slice($row(0), 1, 4), 'httpPort' => (int) ($row(0)[5] ?? 80),
            'subnetEna' => (int) ($row(1)[0] ?? 0), 'subnet' => array_slice($row(1), 1, 4),
            'dnsEna' => (int) ($row(2)[0] ?? 0), 'dns' => array_slice($row(2), 1, 4),
            'gwEna' => (int) ($row(3)[0] ?? 0), 'gateway' => array_slice($row(3), 1, 4),
            'macEna' => (int) ($row(4)[0] ?? 0), 'mac' => array_map('hexdec', array_slice($row(4), 1, 6)), // hex->dez (PROTOCOLS.md:101), RMW-konsistent zu setNetwork intval
            'ntpEna' => (int) ($row(5)[0] ?? 0), 'ntp' => array_slice($row(5), 1, 4),
            // Zeile 6 = THERMOKON_ENA,ip0..3,PORTENA,port1,port2 (PROTOCOLS.md:74)
            'thermoEna'     => (int) ($row(6)[0] ?? 0),
            'thermo'        => array_slice($row(6), 1, 4),
            'thermoPortEna' => (int) ($row(6)[5] ?? 0),
            'thermoPort1'   => (int) ($row(6)[6] ?? 0),
            'thermoPort2'   => (int) ($row(6)[7] ?? 0),
            // Zeile 7 = NTP_DLS (Sommerzeit 0/1)
            'ntpDls'        => (int) ($row(7)[0] ?? 0),
        ]];
    }

    /** Netzwerk schreiben (NETWORKC). $n wie getNetwork()['network']; MAC in Dezimal-Oktetten. */
    public function setNetwork(array $n): array
    {
        $csv = static fn(array $a) => implode(',', array_map('intval', $a));
        $body = 'IP=' . (int) ($n['dhcp'] ?? 0) . ',' . $csv($n['ip'] ?? [0, 0, 0, 0]) . ',' . (int) ($n['httpPort'] ?? 80)
            . '&SUBN=' . (int) ($n['subnetEna'] ?? 0) . ',' . $csv($n['subnet'] ?? [255, 255, 255, 0])
            . '&DNS_ENA=' . (int) ($n['dnsEna'] ?? 0) . ',' . $csv($n['dns'] ?? [0, 0, 0, 0])
            . '&GTWY_ENA=' . (int) ($n['gwEna'] ?? 0) . ',' . $csv($n['gateway'] ?? [0, 0, 0, 0])
            . '&MAC=' . (int) ($n['macEna'] ?? 0) . ',' . $csv($n['mac'] ?? [0, 0, 0, 0, 0, 0])
            . '&NTP_ENA=' . (int) ($n['ntpEna'] ?? 0) . ',' . $csv($n['ntp'] ?? [0, 0, 0, 0])
            . '&THERMO=' . (int) ($n['thermoEna'] ?? 0) . ',' . $csv($n['thermo'] ?? [0, 0, 0, 0]) . ','
                . (int) ($n['thermoPortEna'] ?? 0) . ',' . (int) ($n['thermoPort1'] ?? 0) . ',' . (int) ($n['thermoPort2'] ?? 0)
            . '&NTP_DLS=' . (int) ($n['ntpDls'] ?? 0)
            . '&NETWORKC=1';
        return $this->postRaw('usrcfg.cgi', $body);
    }

    /** Geraeteuhr stellen (NTP-Epoche = Unix + 2208988800). */
    public function setDeviceTime(?int $unixTs = null): array
    {
        $ts = $unixTs ?? time();
        return $this->command('TIME=' . ($ts + 2208988800));
    }

    // ==================================================================
    // Relaisnamen (relcfg): REL0..7 + EXT0..7
    // ==================================================================

    public function getRelayNames(): array
    {
        $lines = $this->lines('usr/relcfg.ini');
        if ($lines === null) {
            return ['ok' => false, 'error' => 'unreachable'];
        }
        $names = array_fill(0, 16, 'n.a.');
        foreach ($lines as $ln) {
            if (preg_match('/^REL(\d+)=(.*)$/', trim($ln), $m)) {
                $names[(int) $m[1]] = $m[2];
            } elseif (preg_match('/^EXT(\d+)=(.*)$/', trim($ln), $m)) {
                $names[(int) $m[1] + 8] = $m[2];
            }
        }
        return ['ok' => true, 'names' => $names];
    }

    /** Alle 16 Relaisnamen schreiben (RELCFG). */
    public function setRelayNames(array $names): array
    {
        $parts = [];
        for ($i = 0; $i < 8; $i++) {
            $parts[] = 'REL' . $i . '=' . rawurlencode((string) ($names[$i] ?? 'n.a.'));
        }
        for ($i = 0; $i < 8; $i++) {
            $parts[] = 'EXT' . $i . '=' . rawurlencode((string) ($names[$i + 8] ?? 'n.a.'));
        }
        return $this->postRaw('usrcfg.cgi', implode('&', $parts) . '&RELCFG=1');
    }

    // ==================================================================
    // Alarm/DTC (DTCCFG): je Code email,msg,sms,action
    // ==================================================================

    public function getDtc(): array
    {
        $lines = $this->lines('usr/dtccfg.ini');
        if ($lines === null) {
            $lines = $this->lines('usr/DTCCFG.ini'); // Namensvariante
        }
        if ($lines === null) {
            return ['ok' => false, 'error' => 'unreachable'];
        }
        $dtc = [];
        foreach ($lines as $ln) {
            if (preg_match('/^DTC(\d+)=(.*)$/', trim($ln), $m)) {
                $a = explode(',', $m[2]);
                $dtc[(int) $m[1]] = ['email' => (int) ($a[0] ?? 0), 'msg' => (int) ($a[1] ?? 0),
                    'sms' => (int) ($a[2] ?? 0), 'action' => (int) ($a[3] ?? 0)];
            }
        }
        ksort($dtc);
        return ['ok' => true, 'dtc' => $dtc];
    }

    /** DTC-Matrix schreiben. $dtc[i]=[email,msg,sms,action], count typ. 70. */
    public function setDtc(array $dtc, int $count = 70): array
    {
        $parts = [];
        for ($i = 0; $i < $count; $i++) {
            $d = $dtc[$i] ?? ['email' => 0, 'msg' => 0, 'sms' => 0, 'action' => 0];
            $parts[] = 'DTC' . $i . '=' . (int) $d['email'] . ',' . (int) $d['msg'] . ',' . (int) $d['sms'] . ',' . (int) $d['action'];
        }
        return $this->postRaw('usrcfg.cgi', implode('&', $parts) . '&DTCCFG=1');
    }

    // ==================================================================
    // Konfig-Editoren: ADC / BNC / 1-Wire / IO  (lesen + schreiben)
    // ==================================================================

    public function getAdcConfig(): array
    {
        $lines = $this->lines('usr/adccfg.ini');
        if ($lines === null) {
            return ['ok' => false, 'error' => 'unreachable'];
        }
        $ch = [];
        foreach ($lines as $ln) {
            if (preg_match('/^ADC(\d+)(NAME|UNIT|OFFS|GAIN)=(.*)$/', trim($ln), $m)) {
                $i = (int) $m[1];
                $ch[$i] = $ch[$i] ?? ['name' => 'n.a.', 'unit' => 'n.a.', 'offset' => 0.0, 'gain' => 1.0];
                $key = ['NAME' => 'name', 'UNIT' => 'unit', 'OFFS' => 'offset', 'GAIN' => 'gain'][$m[2]];
                $ch[$i][$key] = in_array($m[2], ['OFFS', 'GAIN'], true) ? (float) $m[3] : $m[3];
            }
        }
        ksort($ch);
        return ['ok' => true, 'channels' => $ch];
    }

    public function setAdcConfig(array $channels): array
    {
        $parts = [];
        for ($i = 0; $i < 5; $i++) {
            $c = $channels[$i] ?? [];
            $parts[] = 'ADC' . $i . 'NAME=' . rawurlencode((string) ($c['name'] ?? 'n.a.'));
            $parts[] = 'ADC' . $i . 'UNIT=' . rawurlencode((string) ($c['unit'] ?? 'n.a.'));
            $parts[] = 'ADC' . $i . 'OFFS=' . (float) ($c['offset'] ?? 0.0);
            $parts[] = 'ADC' . $i . 'GAIN=' . (float) ($c['gain'] ?? 1.0);
        }
        return $this->postRaw('usrcfg.cgi', implode('&', $parts) . '&ADCCFG=1');
    }

    public function getBncConfig(): array
    {
        $lines = $this->lines('usr/bnccfg.ini');
        if ($lines === null) {
            return ['ok' => false, 'error' => 'unreachable'];
        }
        $ch = [];
        foreach ($lines as $ln) {
            if (preg_match('/^BNC(\d+)=(.*)$/', trim($ln), $m)) {
                $a = explode(',', $m[2]);
                $ch[(int) $m[1]] = ['name' => $a[0] ?? '', 'unit' => $a[1] ?? '', 'offset' => (float) ($a[2] ?? 0),
                    'gain' => (float) ($a[3] ?? 1), 'compIdx' => (int) ($a[4] ?? 0)];
            }
        }
        ksort($ch);
        return ['ok' => true, 'channels' => $ch];
    }

    public function setBncConfig(array $channels): array
    {
        $parts = [];
        for ($i = 0; $i < 2; $i++) {
            $c = $channels[$i] ?? [];
            $parts[] = 'BNC' . $i . '=' . rawurlencode((string) ($c['name'] ?? ''))
                . ',' . rawurlencode((string) ($c['unit'] ?? ''))
                . ',' . (float) ($c['offset'] ?? 0) . ',' . (float) ($c['gain'] ?? 1) . ',' . (int) ($c['compIdx'] ?? 0);
        }
        return $this->postRaw('usrcfg.cgi', implode('&', $parts) . '&BNCCFG=1');
    }

    public function getRomCodes(): array
    {
        $lines = $this->lines('GetRCode.csv');
        if ($lines === null) {
            return ['ok' => false, 'error' => 'unreachable'];
        }
        $codes = [];
        foreach ($lines as $ln) {
            $b = explode(',', trim($ln));
            if (count($b) === 8) {
                $codes[] = implode(' ', array_map('trim', $b));
            }
        }
        return ['ok' => true, 'codes' => $codes];
    }

    public function getOneWireConfig(): array
    {
        $lines = $this->lines('usr/tempcfg.ini');
        if ($lines === null) {
            return ['ok' => false, 'error' => 'unreachable'];
        }
        $s = [];
        foreach ($lines as $ln) {
            if (preg_match('/^S(\d+)(CODE|NAME|UNIT|OFFS|GAIN)=(.*)$/', trim($ln), $m)) {
                $i = (int) $m[1];
                $s[$i] = $s[$i] ?? ['code' => '00 00 00 00 00 00 00 00', 'name' => 'n.a.', 'unit' => 'C', 'offset' => 0.0, 'gain' => 1.0];
                $key = ['CODE' => 'code', 'NAME' => 'name', 'UNIT' => 'unit', 'OFFS' => 'offset', 'GAIN' => 'gain'][$m[2]];
                $s[$i][$key] = in_array($m[2], ['OFFS', 'GAIN'], true) ? (float) $m[3] : $m[3];
            }
        }
        ksort($s);
        return ['ok' => true, 'sensors' => $s];
    }

    public function setOneWireConfig(array $sensors): array
    {
        $parts = [];
        for ($i = 0; $i < 8; $i++) {
            $c = $sensors[$i] ?? [];
            $parts[] = 'S' . $i . 'CODE=' . rawurlencode((string) ($c['code'] ?? '00 00 00 00 00 00 00 00'));
            $parts[] = 'S' . $i . 'NAME=' . rawurlencode((string) ($c['name'] ?? 'n.a.'));
            $parts[] = 'S' . $i . 'UNIT=' . rawurlencode((string) ($c['unit'] ?? 'C'));
            $parts[] = 'S' . $i . 'OFFS=' . (float) ($c['offset'] ?? 0.0);
            $parts[] = 'S' . $i . 'GAIN=' . (float) ($c['gain'] ?? 1.0);
        }
        return $this->postRaw('usrcfg.cgi', implode('&', $parts) . '&SCFG=1');
    }

    public function getIoConfig(): array
    {
        $lines = $this->lines('usr/iocfg.ini');
        if ($lines === null) {
            return ['ok' => false, 'error' => 'unreachable'];
        }
        $io = [];
        foreach ($lines as $ln) {
            if (preg_match('/^IO(\d+)(NAME|UNIT|OFFS|GAIN|DBNCT)=(.*)$/', trim($ln), $m)) {
                $i = (int) $m[1];
                $io[$i] = $io[$i] ?? ['name' => 'n.a.', 'unit' => '--', 'offset' => 0.0, 'gain' => 1.0, 'debounce' => 40];
                $key = ['NAME' => 'name', 'UNIT' => 'unit', 'OFFS' => 'offset', 'GAIN' => 'gain', 'DBNCT' => 'debounce'][$m[2]];
                $io[$i][$key] = in_array($m[2], ['OFFS', 'GAIN'], true) ? (float) $m[3] : (in_array($m[2], ['DBNCT'], true) ? (int) $m[3] : $m[3]);
            }
        }
        ksort($io);
        return ['ok' => true, 'ios' => $io];
    }

    public function setIoConfig(array $ios): array
    {
        $parts = [];
        for ($i = 0; $i < 4; $i++) {
            $c = $ios[$i] ?? [];
            $parts[] = 'IO' . $i . 'NAME=' . rawurlencode((string) ($c['name'] ?? 'n.a.'));
            $parts[] = 'IO' . $i . 'UNIT=' . rawurlencode((string) ($c['unit'] ?? '--'));
            $parts[] = 'IO' . $i . 'OFFS=' . (float) ($c['offset'] ?? 0.0);
            $parts[] = 'IO' . $i . 'GAIN=' . (float) ($c['gain'] ?? 1.0);
            $parts[] = 'IO' . $i . 'DBNCT=' . (int) ($c['debounce'] ?? 40);
        }
        return $this->postRaw('usrcfg.cgi', implode('&', $parts) . '&IOCFG=1');
    }

    /**
     * Optimale Umwaelz-/Filterzeit in Minuten (auf 5 aufgerundet), PROTOCOLS §11.
     * Nutzt die ECHTE Wassertemperatur (TruePoolTemp) — reine Rechnung, kein Geraetezugriff.
     */
    public static function optimalFilterMinutes(float $tempPool, float $poolSize = 0, float $flow = 0): int
    {
        if ($poolSize > 0 && $flow > 0) {
            $circTime = ($poolSize / $flow) * 60.0;
            $circulations = $tempPool <= 20 ? 2.0 : min(4.0, 2.0 + (($tempPool - 20) / 10.0) * 2.0);
            $min = (int) ($circTime * $circulations);
        } else {
            $t = $tempPool;
            $hours = (0.0033 * $t * $t + 0.0333 * $t + 2e-14) * 2.66667;
            $min = (int) ($hours * 60);
        }
        return (int) (ceil($min / 5.0) * 5);
    }

    // ==================================================================
    // GetDos-Semantik (reine Struktur-Abbildung, Spalten PROTOCOLS.md:60-70)
    // ==================================================================

    /**
     * Semantische GetDos-Zeile. $dos = Rueckgabe von getDos(); $row 0=Cl,1=pH-,2=pH+.
     * Leere/fehlende Zeile -> alle 0. Werte sind rohe Sekunden (kein offs/gain).
     */
    public static function dosRow(array $dos, int $row): array
    {
        $r = $dos['rows'][$row] ?? [];
        $n = static fn(int $i): float => isset($r[$i]) && is_numeric($r[$i]) ? (float) $r[$i] : 0.0;
        return [
            'pumpState'    => (int) $n(0),   // PROTOCOLS.md:63
            'pumpOnTime'   => (int) $n(1),   // :64
            'relaisState'  => (int) $n(2),   // :65
            'remaining'    => (int) $n(3),   // :66  Restzeit manuelle Dosierung (s)
            'actualDur'    => (int) $n(4),   // :67  aktuelle Dauer (s)
            'totalDur'     => (int) $n(5),   // :68  Gesamt-Dauer (s)
            'nextCycle'    => (int) $n(6),   // :69  naechster Zyklus (s)
            'poleReversal' => (int) $n(7),   // :70  Salz-Umpolung (s, nur Cl)
        ];
    }

    // ==================================================================
    // EMAIL — Konto (email.htm) + SMTP-Server (emailcfg.htm), key-basiert
    // (KEIN Ganz-Sektions-RMW: zwei getrennte EMAIL=1-POSTs, PROTOCOLS.md:104)
    // ==================================================================

    /** email.ini als key=value-Map lesen (Helfer). */
    private function emailKv(): ?array
    {
        $lines = $this->lines('usr/email.ini');
        if ($lines === null) {
            return null;
        }
        $kv = [];
        foreach ($lines as $ln) {
            $p = strpos($ln, '=');
            if ($p !== false) {
                $kv[substr($ln, 0, $p)] = substr($ln, $p + 1);
            }
        }
        return $kv;
    }

    /** EMAIL-Konto lesen (/usr/email.ini; TO{i}=use,addr / SMS_TO{i}=use,num). */
    public function getEmailAccount(): array
    {
        $kv = $this->emailKv();
        if ($kv === null) {
            return ['ok' => false, 'error' => 'unreachable'];
        }
        $to = [];
        for ($i = 0; $i < 5; $i++) {
            $a = explode(',', $kv['TO' . $i] ?? '0,');
            $to[$i] = ['use' => (int) ($a[0] ?? 0), 'addr' => trim($a[1] ?? '')];
        }
        $sms = [];
        for ($i = 0; $i < 2; $i++) {
            $a = explode(',', $kv['SMS_TO' . $i] ?? '0,');
            $sms[$i] = ['use' => (int) ($a[0] ?? 0), 'num' => trim($a[1] ?? '')];
        }
        return ['ok' => true, 'raw' => $kv, 'account' => [
            'from' => $kv['FROM'] ?? '', 'to' => $to,
            'language' => $kv['LANGUAGE'] ?? 'de', 'mail' => (int) ($kv['MAIL'] ?? 0),
            'html' => (int) ($kv['HTML'] ?? 0), 'sms' => (int) ($kv['SMS'] ?? 0),
            'debug' => (int) ($kv['DEBUG'] ?? 0),
            'smsUser' => $kv['SMS_USER'] ?? '', 'smsPass' => $kv['SMS_PASS'] ?? '',
            'smsFrom' => $kv['SMS_FROM'] ?? '', 'smsTo' => $sms, 'smsApi' => $kv['SMS_API'] ?? '',
        ]];
    }

    /**
     * EMAIL-Konto schreiben. $a = Shape wie getEmailAccount()['account'].
     * Feldreihenfolge exakt email.htm (PROTOCOLS.md:104). TO{i}/SMS_TO{i} sind Kommalisten
     * -> nur Teilwerte werden rawurlencodet, Komma bleibt Struktur (kein postSection).
     */
    public function setEmailAccount(array $a): array
    {
        $norm  = static fn(string $n): string => preg_replace('/[ ()\/]/', '', str_replace('+', '00', $n));
        $parts = [];
        $parts[] = 'FROM=' . rawurlencode((string) ($a['from'] ?? ''));
        for ($i = 0; $i < 5; $i++) {
            $t    = $a['to'][$i] ?? ['use' => 0, 'addr' => 'name@mail.de'];
            $addr = (string) ($t['addr'] ?? '');
            if ($addr === '') {
                $addr = 'name@mail.de';
            }
            $parts[] = 'TO' . $i . '=' . (int) ($t['use'] ?? 0) . ',' . rawurlencode($addr);
        }
        $parts[] = 'LANGUAGE=' . rawurlencode((string) ($a['language'] ?? 'de'));
        $parts[] = 'MAIL='  . (int) ($a['mail'] ?? 0);
        $parts[] = 'HTML='  . (int) ($a['html'] ?? 0);
        $parts[] = 'SMS='   . (int) ($a['sms'] ?? 0);
        $parts[] = 'DEBUG=' . (int) ($a['debug'] ?? 0);
        $parts[] = 'SMS_USER=' . rawurlencode((string) ($a['smsUser'] ?? ''));
        $parts[] = 'SMS_PASS=' . rawurlencode((string) ($a['smsPass'] ?? ''));
        $parts[] = 'SMS_FROM=' . rawurlencode((string) ($a['smsFrom'] ?? ''));
        for ($i = 0; $i < 2; $i++) {
            $s       = $a['smsTo'][$i] ?? ['use' => 0, 'num' => ''];
            $parts[] = 'SMS_TO' . $i . '=' . (int) ($s['use'] ?? 0) . ',' . rawurlencode($norm((string) ($s['num'] ?? '')));
        }
        $parts[] = 'SMS_API=' . rawurlencode((string) ($a['smsApi'] ?? ''));
        return $this->postRaw('usrcfg.cgi', implode('&', $parts) . '&EMAIL=1');
    }

    /** SMTP-Serverkonto lesen (aus /usr/email.ini). */
    public function getEmailServer(): array
    {
        $kv = $this->emailKv();
        if ($kv === null) {
            return ['ok' => false, 'error' => 'unreachable'];
        }
        return ['ok' => true, 'server' => [
            'smtp' => $kv['SMTP'] ?? '', 'user' => $kv['USER'] ?? '', 'pwd' => $kv['PWD'] ?? '',
            'b64usr' => $kv['B64USR'] ?? '', 'b64pwd' => $kv['B64PWD'] ?? '', 'from' => $kv['FROM'] ?? '',
        ]];
    }

    /**
     * SMTP-Server schreiben. Feldreihenfolge SMTP,USER,PWD,B64USR,B64PWD,FROM (PROTOCOLS.md:104).
     * B64USR/B64PWD werden aus user/pwd berechnet, wenn nicht vorgegeben.
     */
    public function setEmailServer(array $s): array
    {
        $user = (string) ($s['user'] ?? '');
        $pwd  = (string) ($s['pwd'] ?? '');
        $b64u = (string) ($s['b64usr'] ?? '') !== '' ? (string) $s['b64usr'] : base64_encode($user);
        $b64p = (string) ($s['b64pwd'] ?? '') !== '' ? (string) $s['b64pwd'] : base64_encode($pwd);
        return $this->postSection('EMAIL', [
            'SMTP' => (string) ($s['smtp'] ?? ''), 'USER' => $user, 'PWD' => $pwd,
            'B64USR' => $b64u, 'B64PWD' => $b64p, 'FROM' => (string) ($s['from'] ?? ''),
        ]);
    }

    // ==================================================================
    // CONTACTS (emailcfg.htm StoreContacts) — TO_0..4 (PROTOCOLS.md:105)
    // ==================================================================

    public function getContacts(): array
    {
        $lines = $this->lines('usr/email.ini');
        if ($lines === null) {
            return ['ok' => false, 'error' => 'unreachable'];
        }
        $c = array_fill(0, 5, 'name@mail.de');
        foreach ($lines as $ln) {
            if (preg_match('/^TO_(\d+)=(.*)$/', trim($ln), $m) && (int) $m[1] < 5) {
                $c[(int) $m[1]] = $m[2] !== '' ? $m[2] : 'name@mail.de';
            }
        }
        return ['ok' => true, 'contacts' => $c];
    }

    public function setContacts(array $contacts): array
    {
        $parts = [];
        for ($i = 0; $i < 5; $i++) {
            $v = (string) ($contacts[$i] ?? '');
            if ($v === '') {
                $v = 'name@mail.de';
            }
            $parts[] = 'TO_' . $i . '=' . rawurlencode($v);
        }
        return $this->postRaw('usrcfg.cgi', implode('&', $parts) . '&CONTACTS=1');
    }

    /** Test-Mail an Kontakt-Index i (0..4) senden (PROTOCOLS.md:153, command() haengt rand an). */
    public function sendTestMail(int $contactIndex = 0): array
    {
        return $this->command('TESTMAIL=' . max(0, $contactIndex) . ',0');
    }

    // ==================================================================
    // OTHER (othercfg.htm) — VALUE1..8 + CHECK1..8 (PROTOCOLS.md:102)
    // ==================================================================

    /**
     * OTHER lesen (tolerant aus /usr/othr.ini, key=value je Zeile; sonst Defaults).
     * VALUE1=Zeitzone, VALUE2=Status-Mail-Minute; CHECK5=Ext-Relais-Select (0/1/3), CHECK7=Durchfluss.
     */
    public function getOther(): array
    {
        $lines = $this->lines('usr/othr.ini') ?? [];
        $kv    = [];
        foreach ($lines as $ln) {
            $p = strpos($ln, '=');
            if ($p !== false) {
                $kv[substr($ln, 0, $p)] = substr($ln, $p + 1);
            }
        }
        $val = static fn(int $i, $d = 0) => isset($kv['VALUE' . $i]) ? $kv['VALUE' . $i] : $d;
        $chk = static fn(int $i, $d = 0) => isset($kv['CHECK' . $i]) ? (int) $kv['CHECK' . $i] : $d;
        return ['ok' => $lines !== [], 'raw' => $kv, 'other' => [
            'timezone'      => (int) $val(1, 1),
            'statusMailMin' => (int) $val(2, 0),
            'value3' => (int) $val(3, 0), 'value4' => (int) $val(4, 17), 'value5' => (int) $val(5, 0),
            'value6' => (int) $val(6, 0), 'value7' => (int) $val(7, 0), 'value8' => (int) $val(8, 0),
            'extControl' => $chk(1), 'sdcard' => $chk(2), 'dmx' => $chk(3), 'avatar' => $chk(4),
            'extRelayMode' => $chk(5), 'highBusload' => $chk(6), 'flowCheck' => $chk(7), 'check8' => $chk(8),
        ]];
    }

    /**
     * OTHER schreiben (voller Sektions-Write: alle 8 VALUE + 8 CHECK, dann OTHER=1).
     * VALUE2 als MINUTEN erwartet; extRelayMode (CHECK5) nur 0/1/3.
     */
    public function setOther(array $o): array
    {
        $v = [
            1 => (int) ($o['timezone'] ?? 1),     2 => (int) ($o['statusMailMin'] ?? 0),
            3 => (int) ($o['value3'] ?? 0),       4 => (int) ($o['value4'] ?? 17),
            5 => (int) ($o['value5'] ?? 0),       6 => (int) ($o['value6'] ?? 0),
            7 => (int) ($o['value7'] ?? 0),       8 => (int) ($o['value8'] ?? 0),
        ];
        $ext = (int) ($o['extRelayMode'] ?? 0);
        if (!in_array($ext, [0, 1, 3], true)) {
            $ext = 0;
        }
        $c = [
            1 => (int) (bool) ($o['extControl'] ?? 0), 2 => (int) (bool) ($o['sdcard'] ?? 0),
            3 => (int) (bool) ($o['dmx'] ?? 0),        4 => (int) (bool) ($o['avatar'] ?? 0),
            5 => $ext,                                 6 => (int) (bool) ($o['highBusload'] ?? 0),
            7 => (int) (bool) ($o['flowCheck'] ?? 0),  8 => (int) (bool) ($o['check8'] ?? 0),
        ];
        $parts = [];
        for ($i = 1; $i <= 8; $i++) {
            $parts[] = 'VALUE' . $i . '=' . $v[$i];
        }
        for ($i = 1; $i <= 8; $i++) {
            $parts[] = 'CHECK' . $i . '=' . $c[$i];
        }
        return $this->postRaw('usrcfg.cgi', implode('&', $parts) . '&OTHER=1');
    }

    // ==================================================================
    // Kalibrierung — HWCAL (ADC) + RDXPHCAL (Elektroden). NUR ueber Modul-Doppel-Gate!
    // offs/gain sind Geraete-ROHINTEGERS (GUI hat *16384 schon angewandt) -> nicht skalieren.
    // ==================================================================

    /** Cal-Zeitstempel "d.Mon.Y␣␣H:i" mit DOPPEL-Leerzeichen (adccal/rdxphkal, NICHT lastRstStamp). */
    private function calStamp(): string
    {
        $months = ['Jan', 'Feb', 'Mär', 'Apr', 'Mai', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dez'];
        $n      = (int) date('n');
        return date('d') . '.' . ($months[$n - 1] ?? 'Jan') . '.' . date('Y') . '  ' . date('H:i');
    }

    /** HWCAL lesen (/usr/hwcal.ini). KALIBRIERUNG — nur zur Anzeige/RMW-Basis. */
    public function getHwCal(): array
    {
        $lines = $this->lines('usr/hwcal.ini');
        if ($lines === null) {
            return ['ok' => false, 'error' => 'unreachable'];
        }
        $out = [];
        foreach ($lines as $ln) {
            if (preg_match('/^(ADC|REDOX|PH)=(.*)$/', trim($ln), $m)) {
                $a = explode(',', $m[2]);
                $out[strtolower($m[1])] = ['offs' => (int) ($a[0] ?? 0), 'gain' => (int) ($a[1] ?? 0),
                    'lastupdate' => trim($a[2] ?? '')];
            }
        }
        return ['ok' => true, 'hwcal' => $out];
    }

    /**
     * HWCAL schreiben — KALIBRIERUNG (ADC-Messkette). Feldreihenfolge PROTOCOLS.md:110.
     * RMW: fehlende Kanaele werden aus dem aktuellen Stand uebernommen. $cal[adc|redox|ph]=['offs','gain'].
     */
    public function setHwCal(array $cal): array
    {
        $cur = $this->getHwCal();
        $c   = ($cur['ok'] ?? false) ? $cur['hwcal'] : [];
        $ch  = static function (array $c, array $cal, string $k): array {
            $o = $cal[$k] ?? [];
            $b = $c[$k] ?? ['offs' => 0, 'gain' => 0];
            return ['offs' => (int) ($o['offs'] ?? $b['offs']), 'gain' => (int) ($o['gain'] ?? $b['gain'])];
        };
        $adc = $ch($c, $cal, 'adc');
        $rdx = $ch($c, $cal, 'redox');
        $ph  = $ch($c, $cal, 'ph');
        $ts  = $this->calStamp();
        $body = 'ADC='    . $adc['offs'] . ',' . $adc['gain'] . ',' . rawurlencode($ts)
              . '&REDOX=' . $rdx['offs'] . ',' . $rdx['gain'] . ',' . rawurlencode($ts)
              . '&PH='    . $ph['offs']  . ',' . $ph['gain']  . ',' . rawurlencode($ts)
              . '&HWCAL=1';
        return $this->postRaw('usrcfg.cgi', $body);
    }

    /** RDXPHCAL lesen (/usr/rdxphcal.ini). KALIBRIERUNG — Anzeige/RMW-Basis. */
    public function getRdxPhCal(): array
    {
        $lines = $this->lines('usr/rdxphcal.ini');
        if ($lines === null) {
            return ['ok' => false, 'error' => 'unreachable'];
        }
        $out = [];
        foreach ($lines as $ln) {
            if (preg_match('/^(REDOX|PH)=(.*)$/', trim($ln), $m)) {
                $a = explode(',', $m[2]);
                $out[strtolower($m[1])] = ['offs' => (int) ($a[0] ?? 0), 'gain' => (int) ($a[1] ?? 0),
                    'lastupdate' => trim($a[2] ?? ''), 'condition' => trim($a[3] ?? ''),
                    'newData' => trim($a[4] ?? '')];
            }
        }
        return ['ok' => true, 'rdxphcal' => $out];
    }

    /**
     * RDXPHCAL schreiben — KALIBRIERUNG (Elektroden). Feldreihenfolge PROTOCOLS.md:111.
     * RMW zwingend: nicht geaenderter Kanal + condition/newData werden 1:1 aus der aktuellen INI
     * uebernommen. $cal[redox|ph]=['offs','gain'] (nur diese aendern).
     */
    public function setRdxPhCal(array $cal): array
    {
        $cur  = $this->getRdxPhCal();
        $c    = ($cur['ok'] ?? false) ? $cur['rdxphcal'] : [];
        $ts   = $this->calStamp();
        $line = static function (array $c, array $cal, string $k, string $ts): string {
            $b    = $c[$k] ?? ['offs' => 0, 'gain' => 1, 'condition' => '', 'newData' => ''];
            $o    = $cal[$k] ?? [];
            $offs = (int) ($o['offs'] ?? $b['offs']);
            $gain = (int) ($o['gain'] ?? $b['gain']);
            $lu   = isset($cal[$k]) ? $ts : (string) $b['lastupdate']; // nur geaenderter Kanal bekommt neuen TS
            return $offs . ',' . $gain . ',' . rawurlencode($lu) . ','
                 . rawurlencode((string) $b['condition']) . ',' . rawurlencode((string) $b['newData']);
        };
        $body = 'REDOX=' . $line($c, $cal, 'redox', $ts) . '&PH=' . $line($c, $cal, 'ph', $ts) . '&RDXPHCAL=1';
        return $this->postRaw('usrcfg.cgi', $body);
    }
}

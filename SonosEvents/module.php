<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/HomeSuite/autoload.php';

/**
 * HomeSuite Sonos-Ereignisse (HSSE) — UPnP-Eventing statt Abfragetakt.
 *
 * Sonos kann Zustandsaenderungen von sich aus melden (GENA): wir melden uns beim Player
 * fuer einen Dienst an und geben eine Rueckruf-Adresse an; der Player schickt daraufhin
 * HTTP-NOTIFY, sobald sich etwas aendert. Genau das, was HEOS ueber seine
 * Steuerverbindung ohnehin tut (register_for_change_events) - Sonos braucht dafuer einen
 * Empfaenger, deshalb dieses Modul mit einem eigenen Server-Socket.
 *
 * Ablauf:
 *   1. ApplyChanges sammelt alle AudioZone-Instanzen mit Treiber sonos-upnp,
 *      loest IP und RINCON auf und meldet sich je Player fuer AVTransport
 *      (Wiedergabe/Titel) und RenderingControl (Lautstaerke/Stumm) an.
 *   2. Der Player antwortet mit einer SID; die merken wir uns samt Zone.
 *   3. NOTIFY kommt am Server-Socket an -> Zone anhand der SID finden -> LastChange
 *      auswerten -> die Zone ihre Variablen setzen lassen.
 *   4. Ein Timer erneuert die Anmeldungen, bevor sie ablaufen.
 *
 * Der Abfragetakt der Zonen bleibt als Sicherheitsnetz bestehen - nur deutlich langsamer.
 * Eventing ersetzt kein Nachfassen: geht ein NOTIFY verloren oder faellt ein Player kurz
 * aus, muss der Zustand irgendwann wieder von selbst zusammenfinden.
 */
class HomeSuiteSonosEvents extends IPSModule
{
    private const TIMER_RENEW = 'Renew';
    private const ABO_SEK     = 600;   // Laufzeit einer Anmeldung
    private const DIENSTE     = [
        'AVTransport'     => '/MediaRenderer/AVTransport/Event',
        'RenderingControl'=> '/MediaRenderer/RenderingControl/Event',
    ];

    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyBoolean('Active', true);
        $this->RegisterPropertyString('CallbackHost', '');   // leer = selbst ermitteln
        $this->RegisterPropertyInteger('CallbackPort', 49152);
        $this->RegisterAttributeString('Subs', '{}');        // SID -> {zone,host,dienst,ts}
        // Zaehler, um "kommt nichts an" von "kommt an, wird nicht verstanden" zu trennen.
        $this->RegisterAttributeString('Stat', '{}');
        $this->RegisterTimer(self::TIMER_RENEW, 0, 'HSSE_Renew($_IPS[\'TARGET\']);');
        $this->ConnectParent('{C8792760-65CF-4C53-B5C7-A30FCC84FEFE}');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        if (!$this->ReadPropertyBoolean('Active')) {
            $this->abmeldenAlle();
            $this->SetTimerInterval(self::TIMER_RENEW, 0);
            $this->SetStatus(104);
            return;
        }
        $this->SetStatus(102);
        // Erneuern deutlich vor Ablauf - ein verpasstes Fenster kostet sonst Ereignisse.
        $this->SetTimerInterval(self::TIMER_RENEW, (int) (self::ABO_SEK * 0.7) * 1000);
        $this->Renew();
    }

    /** Rueckruf-Adresse, die der Player anspricht. */
    private function callback(): string
    {
        $host = trim($this->ReadPropertyString('CallbackHost'));
        if ($host === '') {
            // Die Adresse, unter der uns die Player sehen: die des eigenen Netzes.
            $host = (string) (gethostbyname(gethostname()) ?: '');
            if ($host === '' || strncmp($host, '127.', 4) === 0) {
                $host = trim((string) @shell_exec("ip -4 route get 192.168.1.1 2>/dev/null | grep -oP 'src \\K[0-9.]+'"));
            }
        }
        return $host;
    }

    /** Alle Sonos-Zonen: instanz => [host, rincon]. */
    private function zonen(): array
    {
        $out = [];
        foreach (IPS_GetInstanceList() as $id) {
            $inst = IPS_GetInstance($id);
            if (($inst['ModuleInfo']['ModuleName'] ?? '') !== 'AudioZone') {
                continue;
            }
            if ((string) @IPS_GetProperty($id, 'Driver') !== 'sonos-upnp') {
                continue;
            }
            $r = @HSAU_Manage($id, json_encode(['op' => 'getConfig', 'args' => new stdClass()]));
            $c = json_decode((string) $r, true)['config'] ?? [];
            $h = (string) ($c['host'] ?? '');
            if ($h !== '') {
                $out[$id] = ['host' => $h, 'rincon' => (string) ($c['rincon'] ?? '')];
            }
        }
        return $out;
    }

    /** SUBSCRIBE/RESUBSCRIBE gegen einen Player. */
    private function abo(string $host, string $pfad, string $sid = ''): array
    {
        $cbHost = $this->callback();
        $port   = (int) $this->ReadPropertyInteger('CallbackPort');
        if ($cbHost === '' || $port <= 0) {
            return ['ok' => false, 'error' => 'Rueckruf-Adresse unbekannt'];
        }
        $kopf = "SUBSCRIBE {$pfad} HTTP/1.1\r\nHOST: {$host}:1400\r\n";
        $kopf .= ($sid !== '')
            ? "SID: {$sid}\r\nTIMEOUT: Second-" . self::ABO_SEK . "\r\n\r\n"
            : "CALLBACK: <http://{$cbHost}:{$port}/hsse>\r\nNT: upnp:event\r\nTIMEOUT: Second-" . self::ABO_SEK . "\r\n\r\n";
        $s = @stream_socket_client("tcp://{$host}:1400", $en, $es, 5);
        if (!$s) {
            return ['ok' => false, 'error' => $es ?: 'kein Socket'];
        }
        @fwrite($s, $kopf);
        stream_set_timeout($s, 5);
        $ant = (string) @stream_get_contents($s, 4096);
        @fclose($s);
        $ok = (bool) preg_match('~^HTTP/1\.\d 200~', $ant);
        preg_match('~(?im)^SID:\s*(\S+)~', $ant, $m);
        return ['ok' => $ok, 'sid' => $m[1] ?? $sid, 'roh' => substr($ant, 0, 80)];
    }

    private function abmeldenAlle(): void
    {
        $subs = json_decode($this->ReadAttributeString('Subs'), true) ?: [];
        foreach ($subs as $sid => $s) {
            $sock = @stream_socket_client('tcp://' . $s['host'] . ':1400', $en, $es, 3);
            if ($sock) {
                @fwrite($sock, "UNSUBSCRIBE {$s['pfad']} HTTP/1.1\r\nHOST: {$s['host']}:1400\r\nSID: {$sid}\r\n\r\n");
                @fclose($sock);
            }
        }
        $this->WriteAttributeString('Subs', '{}');
    }

    /** Anmeldungen anlegen bzw. verlaengern. */
    public function Renew(): void
    {
        $alt  = json_decode($this->ReadAttributeString('Subs'), true) ?: [];
        $neu  = [];
        $zahl = 0;
        foreach ($this->zonen() as $zone => $z) {
            foreach (self::DIENSTE as $name => $pfad) {
                // Bestehende Anmeldung verlaengern, sonst neu anlegen.
                $sidAlt = '';
                foreach ($alt as $sid => $s) {
                    if ((int) $s['zone'] === (int) $zone && $s['dienst'] === $name) {
                        $sidAlt = (string) $sid;
                        break;
                    }
                }
                $r = $this->abo($z['host'], $pfad, $sidAlt);
                if (!$r['ok'] && $sidAlt !== '') {
                    $r = $this->abo($z['host'], $pfad, '');   // abgelaufen -> neu anmelden
                }
                if ($r['ok'] && ($r['sid'] ?? '') !== '') {
                    $neu[$r['sid']] = ['zone' => (int) $zone, 'host' => $z['host'],
                                       'dienst' => $name, 'pfad' => $pfad, 'ts' => time()];
                    $zahl++;
                }
            }
        }
        $this->WriteAttributeString('Subs', json_encode($neu));
        $this->SendDebug('HSSE.renew', $zahl . ' Anmeldungen aktiv', 0);
    }

    /** Wieviele Anmeldungen stehen? (Diagnose) */
    public function Status(): string
    {
        $subs = json_decode($this->ReadAttributeString('Subs'), true) ?: [];
        $z = [];
        foreach ($subs as $sid => $s) {
            $z[] = ['zone' => $s['zone'], 'name' => @IPS_GetName((int) $s['zone']),
                    'dienst' => $s['dienst'], 'host' => $s['host'],
                    'alter' => time() - (int) $s['ts']];
        }
        $st = json_decode($this->ReadAttributeString('Stat'), true) ?: [];
        return json_encode(['ok' => true, 'callback' => $this->callback() . ':' . $this->ReadPropertyInteger('CallbackPort'),
                            'anmeldungen' => count($subs), 'empfangen' => $st, 'liste' => $z]);
    }

    // ===== Daten vom Server-Socket: rohes HTTP der Player =====
    public function ReceiveData($JSONString)
    {
        $d = json_decode($JSONString);
        if (!is_object($d)) {
            return '';
        }
        $key = (string) ($d->ClientIP ?? '') . ':' . (int) ($d->ClientPort ?? 0);
        $st = json_decode($this->ReadAttributeString('Stat'), true) ?: [];
        $st['rx'] = (int) ($st['rx'] ?? 0) + 1;
        $st['letzte'] = time();
        $st['von'] = (string) ($d->ClientIP ?? '');
        $this->WriteAttributeString('Stat', json_encode($st));
        $buf = $this->GetBuffer('rx_' . $key) . utf8_decode((string) ($d->Buffer ?? ''));

        // HTTP RICHTIG ABGRENZEN.
        //
        // Erster Versuch wartete schlicht auf </e:propertyset>. Damit wurden nur 50 von 187
        // Paketen je vollstaendig - der Rest blieb liegen: Sonos schickt teils mit
        // Content-Length, teils in Stuecken (chunked), und mehrere Meldungen koennen in
        // EINEM Paket stecken. Deshalb hier die Kopfzeilen auswerten und den Rumpf zaehlen.
        while (true) {
            $ke = strpos($buf, "\r\n\r\n");
            if ($ke === false) {
                break;                                   // Kopf noch nicht komplett
            }
            $kopf = substr($buf, 0, $ke);
            $ab   = $ke + 4;
            $len  = null;
            if (preg_match('~(?im)^Content-Length:\s*(\d+)~', $kopf, $m)) {
                $len = (int) $m[1];
            }
            $chunked = (bool) preg_match('~(?im)^Transfer-Encoding:\s*chunked~', $kopf);

            if ($len !== null) {
                if (strlen($buf) - $ab < $len) {
                    break;                               // Rumpf noch unvollstaendig
                }
                $rumpf = substr($buf, $ab, $len);
                $rest  = substr($buf, $ab + $len);
            } elseif ($chunked) {
                $ende = strpos($buf, "0\r\n\r\n", $ab);
                if ($ende === false) {
                    break;
                }
                $roh   = substr($buf, $ab, $ende - $ab);
                $rumpf = preg_replace('~(?m)^[0-9a-fA-F]+\r?\n~', '', $roh);   // Stueckgroessen weg
                $rest  = substr($buf, $ende + 5);
            } else {
                $e2 = strpos($buf, '</e:propertyset>', $ab);
                if ($e2 === false) {
                    break;
                }
                $rumpf = substr($buf, $ab, $e2 - $ab + 16);
                $rest  = substr($buf, $e2 + 16);
            }
            $this->verarbeite($kopf . "\r\n\r\n" . $rumpf);
            $st = json_decode($this->ReadAttributeString('Stat'), true) ?: [];
            $st['voll'] = (int) ($st['voll'] ?? 0) + 1;
            $this->WriteAttributeString('Stat', json_encode($st));
            $buf = ltrim($rest, "\r\n");
            if ($buf === '') {
                break;
            }
        }
        if (strlen($buf) > 400000) {
            $buf = '';                                   // Reissleine gegen Muell
        }
        $this->SetBuffer('rx_' . $key, $buf);
        return '';
    }

    /** frueherer Rumpf-Abschluss - bleibt als Dokumentation der Abgrenzung */
    private function unbenutzt(): void
    {
        $key = ''; $buf = '';
        unset($key, $buf);
    }

    /** NOTIFY auswerten und die Zone setzen lassen. */
    private function verarbeite(string $roh): void
    {
        if (!preg_match('~(?im)^SID:\s*(\S+)~', $roh, $m)) {
            return;
        }
        $sid  = $m[1];
        $subs = json_decode($this->ReadAttributeString('Subs'), true) ?: [];
        if (!isset($subs[$sid])) {
            $st = json_decode($this->ReadAttributeString('Stat'), true) ?: [];
            $st['fremd'] = (int) ($st['fremd'] ?? 0) + 1;
            $this->WriteAttributeString('Stat', json_encode($st));
            return;                       // fremde oder veraltete Anmeldung
        }
        $zone = (int) $subs[$sid]['zone'];
        // LastChange ist doppelt verpackt: XML im XML, HTML-kodiert.
        if (!preg_match('~<LastChange>(.*?)</LastChange>~s', $roh, $lc)) {
            return;
        }
        $inner = html_entity_decode($lc[1], ENT_QUOTES | ENT_XML1 | ENT_HTML5);
        $feld = static function (string $x, string $tag) {
            return preg_match('~<' . $tag . '\b[^>]*val="([^"]*)"~', $x, $mm)
                ? html_entity_decode($mm[1], ENT_QUOTES | ENT_XML1 | ENT_HTML5) : null;
        };
        $ev = array_filter([
            'transport' => $feld($inner, 'TransportState'),
            'volume'    => $feld($inner, 'Volume'),
            'mute'      => $feld($inner, 'Mute'),
            'meta'      => $feld($inner, 'CurrentTrackMetaData'),
            'uri'       => $feld($inner, 'CurrentTrackURI'),
        ], static fn($v) => $v !== null);
        if ($ev === []) {
            return;
        }
        $this->SendDebug('HSSE.notify', 'Zone ' . $zone . ': ' . implode(',', array_keys($ev)), 0);
        if (function_exists('HSAU_ApplyEvent')) {
            @HSAU_ApplyEvent($zone, json_encode($ev));
        }
    }
}

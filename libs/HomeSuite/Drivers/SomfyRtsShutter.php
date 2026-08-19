<?php

declare(strict_types=1);

namespace Hoep\HomeSuite\HAL;

/**
 * SomfyRtsShutter — HomeSuite-HAL-Treiber fuer die Somfy-RTS-Rollos, die bisher
 * die (irrefuehrend benannte) IPSLibrary-Klasse IPSComponentShutter_Dummy ueber
 * einen Client-Socket zum RTS-TCP-Gateway (Somfy) angesteuert hat.
 *
 * PROTOKOLL: pro Kanal (1..16) ein FESTES 13-Byte-Telegramm je Richtung
 * (up/down/stop). Der Rolling-Code liegt im Gateway, nicht im Frame — deshalb
 * sind die Telegramme statisch. Die Tabelle ist BYTE-GENAU aus der Altklasse
 * uebernommen (Extraktion + Diff-Verifikation); NICHT haendisch abtippen/aendern.
 *
 * ZUSTANDSLOS/KERNEL-FREI (B1): der Treiber haelt keinen Socket. Er baut nur den
 * Frame und schiebt ihn ueber den bei bind() uebergebenen $send-Callback raus;
 * das Modul verdrahtet $send = fn($frame) => CSCK_SendText($socketId, $frame).
 *
 * KEIN FEEDBACK, KEINE ABSOLUTPOSITION (Somfy RTS): readPosition() == POS_UNKNOWN,
 * moveTo() verweigert. Absolutfahrten macht das Modul zeitbasiert ueber
 * ShadeKinematics (timeOpening/timeClosing) + move()/stop(); Kalibrierung per
 * referenceRun() in einen Endanschlag.
 *
 * RELIABILITAET: die Telegramme kommen ueber das Gateway NICHT immer an — jedes
 * Kommando wird daher MEHRFACH gesendet (Nutzer-Erfahrung: erst wiederholtes
 * Senden lief verlaesslich). STOP ist am kritischsten (ein verpasster Stop laesst
 * das Rollo weiterfahren -> die zeitbasierte Positionsschaetzung stimmt dann
 * nicht mehr), deshalb bekommt STOP zusaetzliche Wiederholungen.
 *
 * config (bind):
 *   'channel'    int    1..16  RTS-Kanal (= Device-Index der Altkonfiguration)
 *   'repeat'     int    Sendewiederholungen je Kommando (Default 5)
 *   'stopRepeat' int    Wiederholungen fuer STOP (Default 7, mind. repeat)
 *   'gapMs'      int    Pause zwischen den Wiederholungen in ms (Default 60)
 *   'busGapMs'   int    Mindestabstand zum Telegramm EINER ANDEREN Zone (Default 4000,
 *                      haus-weit ueber den Hub gesetzt - siehe move())
 *   'invert'     bool   up/down vertauschen (Default false)
 */
final class SomfyRtsShutter implements IShutter
{
    /**
     * BYTE-GENAUE Telegramm-Tabelle je Kanal 1..16 (up/down/stop), aus
     * IPSComponentShutter_Dummy.class.php uebernommen. Nicht editieren.
     * @var array<int,array{up:string,down:string,stop:string}>
     */
    private const FRAMES = [
         1 => ['up' => "\x7F\xF2\xFA\x7A\x65\xFA\x00\x00\x00\xFF\xFE\x06\x41", 'down' => "\x7F\xF2\xFA\x7A\x65\xFA\x00\x00\x00\xFF\xFD\x06\x40", 'stop' => "\x7F\xF2\xFA\x7A\x65\xFA\x00\x00\x00\xFF\xFC\x06\x3F"],
         2 => ['up' => "\x7F\xF2\xFA\x7A\x65\xFA\x00\x00\x00\xFE\xFE\x06\x40", 'down' => "\x7F\xF2\xFA\x7A\x65\xFA\x00\x00\x00\xFE\xFD\x06\x3F", 'stop' => "\x7F\xF2\xFA\x7A\x65\xFA\x00\x00\x00\xFE\xFC\x06\x3E"],
         3 => ['up' => "\x7F\xF2\xFA\x7A\x65\xFA\x00\x00\x00\xFD\xFE\x06\x3F", 'down' => "\x7F\xF2\xFA\x7A\x65\xFA\x00\x00\x00\xFD\xFD\x06\x3E", 'stop' => "\x7F\xF2\xFA\x7A\x65\xFA\x00\x00\x00\xFD\xFC\x06\x3D"],
         4 => ['up' => "\x7F\xF2\xFA\x7A\x65\xFA\x00\x00\x00\xFC\xFE\x06\x3E", 'down' => "\x7F\xF2\xFA\x7A\x65\xFA\x00\x00\x00\xFC\xFD\x06\x3D", 'stop' => "\x7F\xF2\xFA\x7A\x65\xFA\x00\x00\x00\xFC\xFC\x06\x3C"],
         5 => ['up' => "\x7F\xF2\xFA\x7A\x65\xFA\x00\x00\x00\xFB\xFE\x06\x3D", 'down' => "\x7F\xF2\xFA\x7A\x65\xFA\x00\x00\x00\xFB\xFD\x06\x3C", 'stop' => "\x7F\xF2\xFA\x7A\x65\xFA\x00\x00\x00\xFB\xFC\x06\x3B"],
         6 => ['up' => "\x7F\xF2\xFA\x7A\x65\xFA\x00\x00\x00\xFA\xFE\x06\x3C", 'down' => "\x7F\xF2\xFA\x7A\x65\xFA\x00\x00\x00\xFA\xFD\x06\x3B", 'stop' => "\x7F\xF2\xFA\x7A\x65\xFA\x00\x00\x00\xFA\xFC\x06\x3A"],
         7 => ['up' => "\x7F\xF2\xFA\x7A\x65\xFA\x00\x00\x00\xF9\xFE\x06\x3B", 'down' => "\x7F\xF2\xFA\x7A\x65\xFA\x00\x00\x00\xF9\xFD\x06\x3A", 'stop' => "\x7F\xF2\xFA\x7A\x65\xFA\x00\x00\x00\xF9\xFC\x06\x39"],
         8 => ['up' => "\x7F\xF2\xFA\x7A\x65\xFA\x00\x00\x00\xF8\xFE\x06\x3A", 'down' => "\x7F\xF2\xFA\x7A\x65\xFA\x00\x00\x00\xF8\xFD\x06\x39", 'stop' => "\x7F\xF2\xFA\x7A\x65\xFA\x00\x00\x00\xF8\xFC\x06\x38"],
         9 => ['up' => "\x7F\xF2\xFA\x7A\x65\xFA\x00\x00\x00\xF7\xFE\x06\x39", 'down' => "\x7F\xF2\xFA\x7A\x65\xFA\x00\x00\x00\xF7\xFD\x06\x38", 'stop' => "\x7F\xF2\xFA\x7A\x65\xFA\x00\x00\x00\xF7\xFC\x06\x37"],
        10 => ['up' => "\x7F\xF2\xFA\x7A\x65\xFA\x00\x00\x00\xF6\xFE\x06\x38", 'down' => "\x7F\xF2\xFA\x7A\x65\xFA\x00\x00\x00\xF6\xFD\x06\x37", 'stop' => "\x7F\xF2\xFA\x7A\x65\xFA\x00\x00\x00\xF6\xFC\x06\x36"],
        11 => ['up' => "\x7F\xF2\xFA\x7A\x65\xFA\x00\x00\x00\xF5\xFE\x06\x37", 'down' => "\x7F\xF2\xFA\x7A\x65\xFA\x00\x00\x00\xF5\xFD\x06\x36", 'stop' => "\x7F\xF2\xFA\x7A\x65\xFA\x00\x00\x00\xF5\xFC\x06\x35"],
        12 => ['up' => "\x7F\xF2\xFA\x7A\x65\xFA\x00\x00\x00\xF4\xFE\x06\x36", 'down' => "\x7F\xF2\xFA\x7A\x65\xFA\x00\x00\x00\xF4\xFD\x06\x35", 'stop' => "\x7F\xF2\xFA\x7A\x65\xFA\x00\x00\x00\xF4\xFC\x06\x34"],
        13 => ['up' => "\x7F\xF2\xFA\x7A\x65\xFA\x00\x00\x00\xF3\xFE\x06\x35", 'down' => "\x7F\xF2\xFA\x7A\x65\xFA\x00\x00\x00\xF3\xFD\x06\x34", 'stop' => "\x7F\xF2\xFA\x7A\x65\xFA\x00\x00\x00\xF3\xFC\x06\x33"],
        14 => ['up' => "\x7F\xF2\xFA\x7A\x65\xFA\x00\x00\x00\xF2\xFE\x06\x34", 'down' => "\x7F\xF2\xFA\x7A\x65\xFA\x00\x00\x00\xF2\xFD\x06\x33", 'stop' => "\x7F\xF2\xFA\x7A\x65\xFA\x00\x00\x00\xF2\xFC\x06\x32"],
        15 => ['up' => "\x7F\xF2\xFA\x7A\x65\xFA\x00\x00\x00\xF1\xFE\x06\x33", 'down' => "\x7F\xF2\xFA\x7A\x65\xFA\x00\x00\x00\xF1\xFD\x06\x32", 'stop' => "\x7F\xF2\xFA\x7A\x65\xFA\x00\x00\x00\xF1\xFC\x06\x31"],
        16 => ['up' => "\x7F\xF2\xFA\x7A\x65\xFA\x00\x00\x00\xF0\xFE\x06\x32", 'down' => "\x7F\xF2\xFA\x7A\x65\xFA\x00\x00\x00\xF0\xFD\x06\x31", 'stop' => "\x7F\xF2\xFA\x7A\x65\xFA\x00\x00\x00\xF0\xFC\x06\x30"],
    ];

    private array $cfg = [];
    /** @var callable */
    private $send;

    public function __construct()
    {
        // Vorgabe-Callback: meldet ehrlich "nicht gesendet", statt stillschweigend nichts zu tun.
        $this->send = static function ($x): array {
            return ['ok' => false, 'status' => 0, 'err' => 'kein Sende-Callback gebunden'];
        };
    }

    public function bind(array $config, callable $send): void
    {
        $this->cfg  = $config;
        $this->send = $send;
    }

    public function capabilities(): array
    {
        return [
            'positionFeedback' => false,
            'absolutePosition' => false,
            'slat'             => false,
            'shadowingType'    => 0,   // nur Fahren (up/down/stop)
            'channel'          => $this->channel(),
        ];
    }

    public static function discover(int $timeoutMs = 2000): array
    {
        return [];
    }

    public function poll(): array
    {
        return [];
    }

    public function parseEvent(string $raw): ?AudioState
    {
        return null;
    }

    /** Somfy RTS meldet nichts zurueck -> Ist-Lage ist immer UNKNOWN (Modul schaetzt zeitbasiert). */
    public function readPosition(): int
    {
        return IShutter::POS_UNKNOWN;
    }

    /** Absolutfahrt ist am Aktor nicht moeglich; das Modul faehrt zeitbasiert ueber move(). */
    public function moveTo(float $percent): bool
    {
        $this->log('moveTo verweigert: Somfy RTS ohne Absolutposition (Modul faehrt zeitbasiert)');
        return false;
    }

    /**
     * Relatives Fahren — das einzige, was der Aktor kann.
     * @param string $dir up|down|stop
     */
    public function move(string $dir): bool
    {
        $dir = strtolower($dir);
        if ((bool) ($this->cfg['invert'] ?? false)) {
            if ($dir === 'up') {
                $dir = 'down';
            } elseif ($dir === 'down') {
                $dir = 'up';
            }
        }
        $frame = $this->frame($dir);
        if ($frame === '') {
            $this->log('move(' . $dir . '): kein Frame (Kanal ' . $this->channel() . ' / Richtung ungueltig)');
            return false;
        }
        // Reliabilitaet: Kommando MEHRFACH senden (Telegramme gehen sonst verloren).
        // STOP kritischer -> mehr Wiederholungen. Kleine Pause zwischen den Sends.
        // Voreinstellung von 3 auf 5 angehoben (STOP 4 -> 7): RTS quittiert nichts, ein
        // verlorenes Telegramm faellt nur dadurch auf, dass das Rollo stehen bleibt - und
        // beim Betrieb ueber ein TCP-Gateway gehen einzelne Sendungen erfahrungsgemaess
        // verloren. Wiederholungen sind unschaedlich, weil AUF/AB/STOP idempotent sind:
        // ein zweites "AB" an ein bereits fahrendes Rollo aendert nichts. Bei 60 ms Abstand
        // kostet ein Kommando so rund 0,3 s. Pro Rollo ueber 'repeat'/'stopRepeat'/'gapMs'
        // weiter frei einstellbar.
        $repeat = max(1, (int) ($this->cfg['repeat'] ?? 5));
        if ($dir === 'stop') {
            $repeat = max($repeat, (int) ($this->cfg['stopRepeat'] ?? 7));
        }
        $gapMs = max(0, (int) ($this->cfg['gapMs'] ?? 60));

        // ------------------------------------------------------------------
        // BUS-SERIALISIERUNG ueber ALLE Rollos
        // ------------------------------------------------------------------
        // Alle Zonen teilen sich EIN RTS-Gateway und EINE Luftschnittstelle. Faehrt die
        // Automatik mehrere Rollos gleichzeitig an (Sonnenregel trifft eine ganze Fassade,
        // oder ein Sammelbefehl), schickten bisher alle Zonen ihre je 5 Telegramme
        // durcheinander. Auf 433 MHz gibt es keine Kollisionsvermeidung: was sich
        // ueberlagert, ist weg - und weil RTS nichts quittiert, faellt es nur dadurch auf,
        // dass ein Rollo stehen bleibt, waehrend die Buchfuehrung des Moduls es als
        // gefahren fuehrt. Genau dieses Bild ("Kachel zeigt Fahrt, Rollo steht") war der
        // Anlass.
        //
        // Darum: ein globales Schloss ueber alle Instanzen, plus ein Mindestabstand zum
        // letzten Telegramm IRGENDEINER Zone (busGapMs, Vorgabe 500 ms). Die Zeit des
        // letzten Sendens liegt in einer Datei, weil jeder Aufruf in einem eigenen
        // PHP-Prozess laeuft und statische Variablen das nicht ueberleben.
        // Vorgabe 4000 ms statt frueher 500 ms: eine halbe Sekunde reichte nicht. Am 17.08.2026
        // gingen 15 Fahrbefehle im Abstand von rund 0,8 s raus, physisch gefahren ist genau
        // EINER; dieselbe Menge morgens ueber die Minute verteilt lief fehlerfrei. Der Wert
        // kommt haus-weit vom Hub (ShadeBusGapMs), nicht je Rollo.
        $busGap = max(0, (int) ($this->cfg['busGapMs'] ?? 4000));
        $sem    = 'HSSH_RTS_BUS';
        $stamp  = sys_get_temp_dir() . '/hssh_rts_last';

        // PROTOKOLL (Fehlersuche, 18.08.2026)
        // ------------------------------------------------------------------
        // Anlass: am 17.08. wurden 15 Fahrbefehle erzeugt, physisch fuhr genau EINES. Am
        // 18.08. hoerte der Zeitstempel des letzten Telegramms um 06:00:28 auf, waehrend bis
        // 06:00:52 weiter Fahrbefehle entstanden - ein Dutzend Aufrufe hat die Sendeschleife
        // nie erreicht. Aus Aufzeichnungen laesst sich nicht unterscheiden, ob der Thread im
        // Warten stirbt, das Schloss verfaellt oder der Socket das Senden verweigert.
        //
        // Deshalb wird JEDER Schritt einzeln vermerkt, und zwar SCHON VOR dem Warten. Nur so
        // hinterlaesst ein Aufruf, der unterwegs abgeraeumt wird, ueberhaupt eine Spur: fehlt
        // zu einem "an" die Zeile "ab", ist der Thread zwischen beiden verschwunden.
        $lauf = substr(bin2hex(random_bytes(3)), 0, 6);   // verbindet die Zeilen eines Aufrufs
        $this->spur($lauf, 'an', sprintf('Ch%02d %-4s repeat=%d gap=%dms busGap=%dms',
            $this->channel(), $dir, $repeat, $gapMs, $busGap));

        $t0     = microtime(true);
        $have   = function_exists('IPS_SemaphoreEnter') ? @\IPS_SemaphoreEnter($sem, 10000) : true;
        $tSchloss = microtime(true) - $t0;
        $this->spur($lauf, 'schloss', sprintf('%s nach %.2fs',
            $have ? 'erhalten' : 'ABGELAUFEN - sendet trotzdem', $tSchloss));

        $gewartet = 0.0;
        $erg      = [];
        try {
            if ($busGap > 0) {
                $last = (float) @file_get_contents($stamp);
                $waitMs = (int) round(($last + $busGap / 1000 - microtime(true)) * 1000);
                if ($waitMs > 0 && function_exists('IPS_Sleep')) {
                    $gewartet = min(5000, $waitMs) / 1000.0;
                    @\IPS_Sleep(min(5000, $waitMs));
                }
            }
            for ($i = 0; $i < $repeat; $i++) {
                // Rueckgabe NICHT mehr verwerfen: der Sende-Callback meldet jetzt, ob das
                // Telegramm den Socket erreicht hat und in welchem Zustand der Socket war.
                $r = ($this->send)($frame);
                $erg[] = is_array($r) ? $r : ['ok' => null];
                if ($gapMs > 0 && $i < $repeat - 1 && function_exists('IPS_Sleep')) {
                    @\IPS_Sleep($gapMs);
                }
            }
            @file_put_contents($stamp, (string) microtime(true));
        } finally {
            if ($have && function_exists('IPS_SemaphoreLeave')) {
                @\IPS_SemaphoreLeave($sem);
            }
        }

        $gut = 0; $status = '?'; $fehler = '';
        foreach ($erg as $e) {
            if (!empty($e['ok'])) { $gut++; }
            if (isset($e['status'])) { $status = (string) $e['status']; }
            if (!empty($e['err']) && $fehler === '') { $fehler = (string) $e['err']; }
        }
        $this->spur($lauf, 'ab', sprintf('Ch%02d %-4s gesendet %d/%d  Socket-Status %s  gewartet %.2fs  gesamt %.2fs%s',
            $this->channel(), $dir, $gut, $repeat, $status, $gewartet, microtime(true) - $t0,
            $fehler !== '' ? ('  FEHLER: ' . $fehler) : ''));

        return true;
    }

    /** Referenzfahrt = voll in einen Endanschlag; nur up|down. */
    public function referenceRun(string $dir): bool
    {
        $dir = strtolower($dir);
        if ($dir !== 'up' && $dir !== 'down') {
            return false;
        }
        return $this->move($dir);
    }

    /** Somfy-RTS-Rollo hier ohne separate Lamelle. */
    public function setSlat(float $percent): bool
    {
        return false;
    }

    // ----------------------------------------------------------------------

    /**
     * Rohes Telegramm fuer Kanal+Richtung (oder '' bei ungueltig). PUBLIC fuers
     * Dry-Run/Diagnose des Moduls (Frame im Schatten-Modus loggen statt senden).
     * @param string $dir up|down|stop
     */
    public function frame(string $dir): string
    {
        $ch = $this->channel();
        return self::FRAMES[$ch][$dir] ?? '';
    }

    /** Telegramm als Hex-String fuer Anzeige/Log. */
    public function frameHex(string $dir): string
    {
        $f = $this->frame($dir);
        return $f === '' ? '' : strtoupper(implode(' ', str_split(bin2hex($f), 2)));
    }

    private function channel(): int
    {
        return max(0, min(16, (int) ($this->cfg['channel'] ?? 0)));
    }

    private function log(string $msg): void
    {
        if (function_exists('IPS_LogMessage')) {
            @\IPS_LogMessage('HS.SomfyRts', $msg);
        }
    }

    /**
     * Eine Zeile Sendeprotokoll — in die Symcon-Meldungen UND in eine Datei.
     *
     * Zwei Wege, weil beide je eine Schwaeche haben: die Meldungsliste ist im Fenster sofort
     * sichtbar, aber fluechtig und begrenzt; die Datei ueberlebt einen Neustart und laesst sich
     * auswerten, ist aber im Frontend unsichtbar. Fuer eine Fehlersuche, deren Anlass nur alle
     * paar Tage auftritt, braucht es beides.
     *
     * Die Datei wird bei 4000 Zeilen auf die letzten 2000 gekuerzt - ein Protokoll, das die
     * Platte fuellt, waere ein neuer Fehler statt der Loesung eines alten.
     */
    private function spur(string $lauf, string $schritt, string $text): void
    {
        $zeile = sprintf("%s.%03d %s %-8s %s\n", date('d.m. H:i:s'),
            (int) ((microtime(true) - floor(microtime(true))) * 1000), $lauf, $schritt, $text);
        $datei = sys_get_temp_dir() . '/hssh_rts.log';
        @file_put_contents($datei, $zeile, FILE_APPEND | LOCK_EX);
        if (@filesize($datei) > 600000) {
            $z = @file($datei);
            if (is_array($z) && count($z) > 4000) {
                @file_put_contents($datei, implode('', array_slice($z, -2000)), LOCK_EX);
            }
        }
        $this->log($lauf . ' ' . $schritt . ' ' . $text);
    }
}

// Vendor-Selbstregistrierung bei der DriverFactory (§2.2.5).
DriverFactory::register('somfy-rts', SomfyRtsShutter::class);

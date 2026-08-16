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
        $this->send = static function ($x): void {
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
        for ($i = 0; $i < $repeat; $i++) {
            ($this->send)($frame);
            if ($gapMs > 0 && $i < $repeat - 1 && function_exists('IPS_Sleep')) {
                @\IPS_Sleep($gapMs);
            }
        }
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
}

// Vendor-Selbstregistrierung bei der DriverFactory (§2.2.5).
DriverFactory::register('somfy-rts', SomfyRtsShutter::class);

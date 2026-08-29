<?php

declare(strict_types=1);

namespace Hoep\HomeSuite\HAL;


/**
 * ToshibaCloud — Klimageraete ueber die Toshiba-Hersteller-Cloud.
 *
 * LESEN geht ueber die REST-Schnittstelle (GetCurrentACState); SCHREIBEN geht
 * NICHT ueber REST - dort gibt es schlicht keinen Endpunkt dafuer. Befehle
 * laufen ueber einen Azure-IoT-Hub per MQTT/TLS. Diesen Teil uebernimmt das
 * Hilfsskript scripts/toshiba/toshiba_send.py, damit die MQTT-Handarbeit nicht
 * in PHP nachgebaut werden muss.
 *
 * Zustandsformat (am 28.08.2026 an den echten Geraeten geprueft): 19 Bytes als
 * Hexpaare. Beim SENDEN bedeutet 0xFF "unveraendert" - man schickt also eine
 * Kette aus lauter FF und setzt nur das Byte, das sich aendern soll. Das wurde
 * mit Solltemperatur und Merit nachgewiesen; die Nachbarfelder blieben beide
 * Male unberuehrt.
 *
 *   Byte 0  Status      30 ein, 31 aus
 *   Byte 1  Modus       41 auto, 42 kuehlen, 43 heizen, 44 trocknen, 45 luefter
 *   Byte 2  Soll        Hexwert = Grad Celsius (14 = 20 Grad)
 *   Byte 3  Luefter     31 leise .. 36 sehr hoch, 41 auto
 *   Byte 4  Schwenken   31 aus, 41 vertikal, 42 horizontal, 43 beides, 50-54 fix
 *   Byte 5  Leistung    32 = 50%, 48 = 75%, 64 = 100%
 *   Byte 6  Merit       zwei Halbbytes (merit_b hoch, merit_a niedrig)
 *   Byte 7  Ionisierung 10 aus, 18 ein
 *   Byte 8  Innentemperatur   (nur lesend)
 *   Byte 9  Aussentemperatur  (nur lesend)
 *
 * Konfiguration: acId (fuer das Lesen), deviceUniqueId (Ziel der Befehle),
 * tokenVid (Symcon-Variable mit dem Zugriffstoken - NICHT aus settings.json
 * lesen, die Datei wird nur periodisch geschrieben und der Token darin ist
 * regelmaessig veraltet).
 */
final class ToshibaCloud implements IClimate
{
    private const BASIS  = 'https://mobileapi.toshibahomeaccontrols.com';
    private const SENDER = '/var/lib/symcon/scripts/toshiba/toshiba_send.py';

    /** Sprechender Wert -> Hexbyte. */
    private const MODI    = ['auto' => '41', 'cool' => '42', 'heat' => '43',
                             'dry' => '44', 'fan' => '45'];
    private const FANS    = ['quiet' => '31', 'verylow' => '32', 'low' => '33',
                             'medium' => '34', 'high' => '35', 'veryhigh' => '36',
                             'auto' => '41'];
    private const SWINGS  = ['off' => '31', 'vertical' => '41', 'horizontal' => '42',
                             'both' => '43', 'fix1' => '50', 'fix2' => '51',
                             'fix3' => '52', 'fix4' => '53', 'fix5' => '54'];
    /** Leistungsstufe: Byte 5. Die Cloud fuehrt sie als Hexpaar, nicht als Prozentwert. */
    private const STUFEN  = [50 => '32', 75 => '4b', 100 => '64'];
    /**
     * Merit A - das NIEDRIGE Halbbyte von Byte 6. 0x04 heisst in der
     * Herstellerlogik "Heizen auf 8 Grad", also Frostschutz.
     */
    private const PRESETS = [ 'off' => 0, 'highpower' => 1, 'silent' => 2,
                              'eco' => 3, 'frost' => 4, 'sleep' => 5,
                              'floor' => 6, 'comfort' => 7, 'silent2' => 10];

    /** Merit B - das HOHE Halbbyte von Byte 6: der Kaminmodus. */
    private const KAMIN   = ['off' => 0, 'kamin1' => 2, 'kamin2' => 3];

    private array $cfg = [];

    public function bind(array $config, callable $send): void
    {
        $this->cfg = $config;
    }

    public function capabilities(): array
    {
        return (new ClimateCapabilities(
            power: true, target: true, targetMin: 17.0, targetMax: 30.0, targetStep: 1.0,
            modes: array_keys(self::MODI), fans: array_keys(self::FANS),
            swings: array_keys(self::SWINGS), presets: array_keys(self::PRESETS),
            ion: true, indoor: true, outdoor: true,
            powerLevels: array_keys(self::STUFEN), running: false, presence: false,
            selfClean: true, fireplaces: array_keys(self::KAMIN)
        ))->toArray();
    }

    /** Keine Netzsuche: beide Wege brauchen Zugangsdaten bzw. eine Bindung. */
    public static function discover(int $timeoutMs = 2000): array
    {
        return [];
    }

    public function poll(): array
    {
        return $this->readState()->toArray();
    }

    public function parseEvent(string $raw): ?AudioState
    {
        return null;   // Toshiba liefert keine Ereignisse auf diesem Weg.
    }

    // ------------------------------------------------------------------ lesen

    public function readState(): ClimateState
    {
        $hex = $this->zustandHex();
        if ($hex === null) {
            return new ClimateState(reachable: false);
        }
        $b = static fn(int $i): string => substr($hex, $i * 2, 2);
        $wert = static fn(array $tab, string $h): string
            => (string) (array_search(strtolower($h), $tab, true) ?: '');

        $b6      = hexdec($b(6));
        $merit   = $b6 & 0x0F;          // niedriges Halbbyte = Merit A
        $kaminNr = ($b6 >> 4) & 0x0F;   // hohes Halbbyte  = Merit B (Kamin)
        return new ClimateState(
            on:       strtolower($b(0)) === '30',
            mode:     $wert(self::MODI, $b(1)),
            target:   (float) hexdec($b(2)),
            indoor:   (float) hexdec($b(8)),
            outdoor:  (float) hexdec($b(9)),
            fan:      $wert(self::FANS, $b(3)),
            swing:    $wert(self::SWINGS, $b(4)),
            preset:   (string) (array_search($merit, self::PRESETS, true) ?: 'off'),
            ion:      strtolower($b(7)) === '18',
            humidity: -1.0,        // misst das Geraet nicht
            powerLevel: (int) (array_search(strtolower($b(5)), self::STUFEN, true) ?: -1),
            // Selbstreinigung steht in Byte 14, nicht 15: Merit A und B teilen
            // sich Byte 6 als Halbbytes, wodurch sich alles Nachfolgende um
            // eine Stelle verschiebt. 0x18 an, 0x10 aus, 0xff nicht vorhanden.
            // Die Bytes 10 bis 13 und 15 bis 18 sind unbenutzt - auch in der
            // Referenzbibliothek; hier wird nichts hineingedeutet.
            selfClean: strtolower($b(14)) === 'ff' ? null : (strtolower($b(14)) === '18'),
            fireplace: (string) (array_search($kaminNr, self::KAMIN, true) ?: 'off'),
            swingH:   '',
            light:    null,
            scheduled: null,
            reachable: true
        );
    }

    /** Roher Zustands-Hexstring oder null. */
    private function zustandHex(bool $erneut = false): ?string
    {
        $acId  = (string) ($this->cfg['acId'] ?? '');
        $token = $this->token();
        if ($acId === '' || $token === '') {
            return null;
        }
        $url = self::BASIS . '/api/AC/GetCurrentACState?' . http_build_query(['ACId' => $acId]);
        $ch  = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            // Ohne User-Agent antwortet Toshibas Schutzschicht mit 403.
            CURLOPT_USERAGENT      => 'curl/8.5.0',
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json',
                                       'Authorization: Bearer ' . $token],
        ]);
        $antwort = curl_exec($ch);
        $code    = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code === 401 || $code === 403) {
            // Token abgelehnt: einmal neu anmelden, dann genau einen weiteren
            // Versuch. Klappt auch der nicht, ist es kein Tokenproblem.
            if ($this->anmelden() && !$erneut) {
                return $this->zustandHex(true);
            }
        }
        if ($code !== 200 || !is_string($antwort)) {
            $this->log('Zustand nicht lesbar (HTTP ' . $code . ')');
            return null;
        }
        $d = json_decode($antwort, true);
        $hex = $d['ResObj']['ACStateData'] ?? null;
        return (is_string($hex) && strlen($hex) >= 20) ? $hex : null;
    }

    private function token(): string
    {
        $vid = (int) ($this->cfg['tokenVid'] ?? 0);
        if ($vid > 0 && function_exists('GetValue')) {
            return (string) @\GetValue($vid);
        }
        return '';
    }

    /**
     * Neu anmelden und den Token ablegen.
     *
     * Bis zum 28.08.2026 hat das Altskript 33691 diesen Token im Minutentakt
     * erneuert - genauer: es meldete sich bei JEDEM Lauf neu an, was Toshiba
     * prompt mit HTTP 429 quittierte. Seit das Skript stillgelegt ist, ist
     * dieser Treiber der einzige Weg, und er muss sich selbst darum kuemmern.
     *
     * Angemeldet wird NUR, wenn die Cloud den Token ablehnt - nicht auf Vorrat.
     * Der Toshiba-Token haelt lange; ein Anmeldesturm ist hier das groessere
     * Risiko als ein abgelaufener Token.
     */
    private function anmelden(): bool
    {
        /* SPERRE gegen den Anmeldesturm.
         *
         * Ohne sie versucht JEDER fehlgeschlagene Lesevorgang eine Anmeldung -
         * bei zwei Instanzen im Minutentakt sind das 120 Anmeldungen je Stunde.
         * Toshiba drosselt daraufhin auch die Anmeldung, das Lesen scheitert
         * weiter, und die Sache schaukelt sich auf. Genau das ist am 28.08.2026
         * passiert: sieben fehlgeschlagene Anmeldungen in Folge.
         *
         * Als Gedaechtnis dient der Zeitstempel der Token-Variablen selbst -
         * keine zusaetzliche Variable noetig. Wurde der Token in den letzten
         * zehn Minuten geschrieben und es klemmt trotzdem, ist es kein
         * Tokenproblem, sondern die Drosselung; dann hilft nur warten.
         */
        $vid = (int) ($this->cfg['tokenVid'] ?? 0);
        if ($vid > 0 && function_exists('IPS_GetVariable')) {
            $v = @\IPS_GetVariable($vid);
            $alter = is_array($v) ? (time() - (int) ($v['VariableChanged'] ?? 0)) : 99999;
            if ($alter < 600) {
                $this->log('Anmeldung uebersprungen - Token ist erst ' . $alter . ' s alt');
                return false;
            }
        }

        /* Zweite Sperre, die auch bei MISSLUNGENER Anmeldung greift.
         *
         * Die Sperre oben haengt am Zeitstempel des Tokens - der aendert sich nur
         * bei Erfolg. Scheitert die Anmeldung, merkt sich also niemand etwas, und
         * beide Instanzen versuchen es beim naechsten Takt erneut. Genau so ist am
         * 29.08.2026 eine Sperre bei Toshiba entstanden, die auch achtzehn Minuten
         * spaeter noch "Too many requests" lieferte - die angegebenen 60 Sekunden
         * sind nicht woertlich zu nehmen. Der Versuch wird deshalb in einer Datei
         * vermerkt, gemeinsam fuer alle Instanzen, und zwar VOR dem Aufruf.
         */
        $marke = '/var/lib/symcon/scripts/data/toshiba-anmeldung.zeit';
        $letzt = (int) @file_get_contents($marke);
        if ($letzt > 0 && (time() - $letzt) < 900) {
            $this->log('Anmeldung uebersprungen - letzter Versuch vor ' . (time() - $letzt) . ' s');
            return false;
        }
        @file_put_contents($marke, (string) time());
        $u = (string) $this->varWert('userVid');
        $p = (string) $this->varWert('passVid');
        if ($u === '' || $p === '') {
            $this->log('keine Zugangsdaten hinterlegt (userVid/passVid)');
            return false;
        }
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => self::BASIS . '/api/Consumer/Login',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode(['Username' => $u, 'Password' => $p]),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_USERAGENT      => 'curl/8.5.0',
            CURLOPT_TIMEOUT        => 25,
        ]);
        $antwort = curl_exec($ch);
        $code    = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $d = json_decode((string) $antwort, true);
        $neu = $d['ResObj']['access_token'] ?? '';
        if ($code !== 200 || $neu === '') {
            $this->log('Anmeldung fehlgeschlagen (HTTP ' . $code . ')');
            return false;
        }
        $vid = (int) ($this->cfg['tokenVid'] ?? 0);
        if ($vid > 0) {
            @\SetValue($vid, (string) $neu);
        }
        $cid = (int) ($this->cfg['consumerVid'] ?? 0);
        if ($cid > 0 && isset($d['ResObj']['consumerId'])) {
            @\SetValue($cid, (string) $d['ResObj']['consumerId']);
        }
        $this->log('Token erneuert');
        return true;
    }

    private function varWert(string $schluessel)
    {
        $vid = (int) ($this->cfg[$schluessel] ?? 0);
        return ($vid > 0 && @\IPS_VariableExists($vid)) ? @\GetValue($vid) : '';
    }

    // --------------------------------------------------------------- schreiben

    public function setPower(bool $on): bool    { return $this->byte(0, $on ? '30' : '31'); }
    public function setIon(bool $on): bool      { return $this->byte(7, $on ? '18' : '10'); }

    // Die Toshiba-Cloud kennt weder waagrechtes Schwenken noch Displaylicht,
    // und einen Zeitplan fuehrt sie ueberhaupt nicht - dort gibt es nur den
    // Handbetrieb. False heisst hier "kann das Geraet nicht", nicht "misslungen".
    public function setSwingH(string $swing): bool   { return false; }

    public function setPowerLevel(int $prozent): bool
    {
        $h = self::STUFEN[$prozent] ?? null;
        return $h === null ? false : $this->byte(5, $h);
    }

    /**
     * Kaminmodus - das HOHE Halbbyte von Byte 6. Wie bei der Sonderfunktion
     * muss das Nachbar-Halbbyte erhalten bleiben, sonst schaltet man beim
     * Kamin ungewollt ECO ab.
     */
    public function setFireplace(string $modus): bool
    {
        $b = self::KAMIN[strtolower($modus)] ?? null;
        if ($b === null) {
            return false;
        }
        $hex = $this->zustandHex();
        if ($hex === null) {
            $this->log('Kaminmodus nicht gesetzt: Zustand nicht lesbar (Halbbyte unbekannt)');
            return false;
        }
        $niedrig = hexdec(substr($hex, 12, 2)) & 0x0F;
        return $this->byte(6, sprintf('%02x', ($b << 4) | $niedrig));
    }
    public function setLight(bool $on): bool         { return false; }
    public function setScheduled(bool $folgen): bool { return false; }

    public function setMode(string $mode): bool
    {
        $h = self::MODI[strtolower($mode)] ?? null;
        return $h === null ? false : $this->byte(1, $h);
    }

    public function setTarget(float $celsius): bool
    {
        $g = (int) round($celsius);
        if ($g < 5 || $g > 40) {
            return false;
        }
        return $this->byte(2, sprintf('%02x', $g));
    }

    public function setFan(string $fan): bool
    {
        $h = self::FANS[strtolower($fan)] ?? null;
        return $h === null ? false : $this->byte(3, $h);
    }

    public function setSwing(string $swing): bool
    {
        $h = self::SWINGS[strtolower($swing)] ?? null;
        return $h === null ? false : $this->byte(4, $h);
    }

    /**
     * Sonderfunktion. Byte 6 traegt ZWEI Werte in zwei Halbbytes; das obere
     * (merit_b) wird aus dem aktuellen Zustand uebernommen, damit es beim
     * Schreiben nicht verlorengeht. Ist der Zustand nicht lesbar, wird der
     * Befehl NICHT gesendet - lieber nichts tun als das Nachbarfeld raten.
     */
    public function setPreset(string $preset): bool
    {
        $a = self::PRESETS[strtolower($preset)] ?? null;
        if ($a === null) {
            return false;
        }
        $hex = $this->zustandHex();
        if ($hex === null) {
            $this->log('Sonderfunktion nicht gesetzt: Zustand nicht lesbar (Halbbyte unbekannt)');
            return false;
        }
        $hoch = hexdec(substr($hex, 12, 2)) & 0xF0;
        return $this->byte(6, sprintf('%02x', $hoch | $a));
    }

    /** Genau ein Byte setzen, alle uebrigen auf FF = unveraendert. */
    private function byte(int $index, string $hex): bool
    {
        $ziel = (string) ($this->cfg['deviceUniqueId'] ?? '');
        if ($ziel === '' || !is_file(self::SENDER)) {
            $this->log('Senden nicht moeglich: Ziel oder Bruecke fehlt');
            return false;
        }
        $kette = array_fill(0, 19, 'ff');
        $kette[$index] = strtolower($hex);
        $befehl = escapeshellcmd('/usr/bin/python3') . ' ' . escapeshellarg(self::SENDER)
                . ' ' . escapeshellarg($ziel) . ' ' . escapeshellarg(implode('', $kette));
        $aus = [];
        $rc  = 0;
        @exec($befehl . ' 2>&1', $aus, $rc);
        if ($rc !== 0) {
            $this->log('Befehl fehlgeschlagen (rc ' . $rc . '): ' . implode(' ', $aus));
            return false;
        }
        return true;
    }

    private function log(string $msg): void
    {
        if (function_exists('IPS_LogMessage')) {
            @\IPS_LogMessage('HS.Toshiba', $msg);
        }
    }
}

DriverFactory::register('toshiba-cloud', ToshibaCloud::class);

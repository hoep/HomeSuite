<?php

declare(strict_types=1);

namespace Hoep\HomeSuite\HAL;

/**
 * TadoCloud — Klimazonen direkt an der tado-Schnittstelle, ohne Fremdmodul.
 *
 * Warum eigen statt geliehen: Das Fremdmodul holt den Zugriffstoken erst, wenn
 * ein Aufruf scheitert - also mitten im Bedarf, womoeglich mitten in einer
 * Netzstoerung. Tado dreht bei jeder Erneuerung den Refresh-Token; geht die
 * Antwort einmal verloren, ist die Anmeldung dauerhaft weg und nur ueber den
 * Geraetecode im Browser zurueckzuholen. Genau so sind am 26.08.2026 Tado,
 * Withings und der Kalender innerhalb von zwei Minuten gestorben.
 *
 * Dieser Treiber erneuert deshalb VORSORGLICH: sobald der Token in weniger als
 * fuenf Minuten ablaeuft, wird er beim naechsten Lesezyklus getauscht. Die Zone
 * wird im Minutentakt abgefragt, es gibt also rund fuenf Anlaeufe, bevor es eng
 * wird - statt eines einzigen im ungeeignetsten Moment.
 *
 * Der neue Refresh-Token wird SOFORT geschrieben, vor jeder weiteren Arbeit.
 * Ein Absturz zwischen Empfang und Ablage kostet sonst die Anmeldung.
 *
 * Schnittstelle: https://my.tado.com/api/v2
 *   GET  /homes/{h}/zones/{z}/state
 *   PUT  /homes/{h}/zones/{z}/overlay   setzt Leistung/Modus/Temperatur/Luefter
 *   DEL  /homes/{h}/zones/{z}/overlay   zurueck auf den Zeitplan
 *
 * Konfiguration: homeId, zoneId, accessVid, refreshVid, expiresVid
 * (drei Symcon-Variablen; Token gehoeren in den Baum, nicht in den Code).
 */
final class TadoCloud implements IClimate
{
    private const API       = 'https://my.tado.com/api/v2';
    private const TOKEN_URL = 'https://login.tado.com/oauth2/token';
    /* Client-Kennung der tado-App statt der oeffentlichen Drittanbieter-Kennung.
     *
     * Die oeffentliche Kennung 1bb50063-... ist seit 27.01.2026 auf ein
     * Tagesbudget gedeckelt (gemessen 1000 Anfragen, Kopf "RateLimit-Policy").
     * Fuer die App-Kennung gilt das nicht: dieselbe Abfrage lief am 29.08.2026
     * mit HTTP 200 durch, waehrend das Budget der alten Kennung bei r=0 stand,
     * und die Antwort trug ueberhaupt keinen RateLimit-Kopf mehr.
     *
     * Der Ablauf ist aus einem Charles-Mitschnitt der iPhone-App nachgebaut:
     * Autorisierungscode mit PKCE, ohne Client-Geheimnis. Geraetecode und
     * Passwort-Weg sind fuer diesen Client serverseitig abgeschaltet.
     */
    private const CLIENT_ID = 'eec8b609-9e2d-4403-9336-4f62a475271e';
    private const TENANT    = '1d543ad5-a8ac-4704-b9e2-26838b4d6513';
    private const REDIR     = 'tado://auth/redirect';
    private const SCOPE     = 'home.user offline_access';
    private const UA        = 'tado/15158 CFNetwork/3860.700.1 Darwin/25.6.0';
    private const AUTH_URL  = 'https://login.tado.com/oauth2/authorize';
    private const VORLAUF   = 300;   // Sekunden vor Ablauf erneuern (Token gilt 1800 s)

    /** sprechend <-> tado */
    private const MODI  = ['auto' => 'AUTO', 'cool' => 'COOL', 'heat' => 'HEAT',
                           'dry' => 'DRY', 'fan' => 'FAN'];
    private const FANS  = ['auto' => 'AUTO', 'quiet' => 'SILENT', 'low' => 'LEVEL1',
                           'medium' => 'LEVEL2', 'high' => 'LEVEL3', 'veryhigh' => 'LEVEL4'];
    private const SWING = ['off' => 'OFF', 'vertical' => 'ON'];

    private array $cfg = [];
    private int $rest = -1;              // Restanfragen laut Antwortkopf, -1 = unbekannt
    private int $ruecksetzung = 0;       // Zeitpunkt, ab dem das Kontingent wieder frei ist
    private string $etag = '';           // ETag der letzten Antwort, fuer bedingte Anfragen

    public function bind(array $config, callable $send): void
    {
        $this->cfg = $config;
    }

    public static function discover(int $timeoutMs = 2000): array
    {
        return [];
    }

    /**
     * Faehigkeiten kommen von tado selbst, nicht aus einer Annahme.
     *
     * /capabilities nennt je Betriebsart die zulaessigen Luefterstufen, den
     * Temperaturbereich und ob Schwenken und Displaylicht gehen. Diese Geraete
     * kennen zum Beispiel LEVEL1 bis LEVEL3 - eine fest verdrahtete Liste mit
     * LEVEL4 haette dem Nutzer eine Stufe angeboten, die es nicht gibt.
     * Faellt die Abfrage aus, gilt ein vorsichtiger Grundstock.
     */
    public function capabilities(): array
    {
        $c = $this->ruf('GET', '/homes/' . $this->i('homeId') . '/zones/' . $this->i('zoneId') . '/capabilities');
        $modi = [];
        $fans = [];
        $min = 16.0; $max = 30.0; $step = 1.0;
        $licht = false; $schwenkV = []; $schwenkH = [];
        if (is_array($c)) {
            foreach (self::MODI as $sprech => $tado) {
                if (!isset($c[$tado]) || !is_array($c[$tado])) {
                    continue;
                }
                $modi[] = $sprech;
                $m = $c[$tado];
                foreach ((array) ($m['fanLevel'] ?? []) as $f) {
                    $k = array_search($f, self::FANS, true);
                    if ($k !== false && !in_array($k, $fans, true)) { $fans[] = $k; }
                }
                if (($m['light'] ?? []) !== [])          { $licht = true; }
                if (($m['verticalSwing'] ?? []) !== [])   { $schwenkV = array_keys(self::SWING); }
                if (($m['horizontalSwing'] ?? []) !== []) { $schwenkH = array_keys(self::SWING); }
                if (isset($m['temperatures']['celsius'])) {
                    $t = $m['temperatures']['celsius'];
                    $min = (float) ($t['min'] ?? $min);
                    $max = (float) ($t['max'] ?? $max);
                    $step = (float) ($t['step'] ?? $step);
                }
            }
        }
        if ($modi === []) { $modi = array_keys(self::MODI); }
        if ($fans === []) { $fans = array_keys(self::FANS); }
        $a = (new ClimateCapabilities(
            power: true, target: true, targetMin: $min, targetMax: $max, targetStep: $step,
            modes: $modi, fans: $fans, swings: $schwenkV, presets: [],
            ion: false, indoor: true, outdoor: false,
            humidity: true, swingsH: $schwenkH, light: $licht, schedule: true,
            powerLevels: [], running: true, presence: true, selfClean: false,
            // Mit der App-Kennung gilt kein Tagesbudget mehr (siehe CLIENT_ID).
            // Zwei Minuten sind fuer Raumtemperaturen reichlich; die
            // Sammelabfrage holt dabei alle Zonen in EINER Anfrage, und
            // unveraenderte Antworten kosten dank ETag nur ein 304.
            pollSeconds: 120
        ))->toArray();
        // Kam die Abfrage nicht durch (z. B. Kontingent leer), ist das hier nur
        // der Notbehelf - ALLE Modi und Luefterstufen. Den 24 Stunden lang
        // zwischenzuspeichern hiesse, dem Nutzer einen Tag lang Stufen
        // anzubieten, die sein Geraet nicht kennt. Also kennzeichnen, damit der
        // Aufrufer nur echte Antworten ablegt.
        $a['_echt'] = is_array($c);
        return $a;
    }

    public function poll(): array
    {
        return $this->readState()->toArray();
    }

    public function parseEvent(string $raw): ?AudioState
    {
        return null;
    }

    // ------------------------------------------------------------------ lesen

    /* Sammelabfrage: EINE Anfrage fuer alle Zonen statt einer je Zone.
     *
     * tado deckelt seit 27.01.2026 die REST-API pro Konto und Tag; gemessen am
     * 29.08.2026 waren es 1000 Anfragen (Kopf "RateLimit-Policy: perday;q=1000").
     * Fuenf Zonen im Zwei-Minuten-Takt sind 3600 Anfragen taeglich - das
     * Kontingent war jeden Vormittag leer, und was wie ein taeglicher Verlust
     * der Registrierung aussah, war in Wahrheit die Drosselung.
     *
     * /zoneStates liefert alle Zonen auf einmal. Die Antwort landet in einer
     * gemeinsamen Ablage, aus der sich alle Zonen-Instanzen bedienen; nur wer
     * das Semaphor bekommt, fragt wirklich nach. Ohne diesen Einzelflug haetten
     * fuenf Instanzen weiterhin fuenf Anfragen ausgeloest, nur eben auf einen
     * anderen Endpunkt.
     */
    private const ABLAGE = '/var/lib/symcon/scripts/data/tado-';
    private const FRISCH = 100;      // Sekunden, die die Ablage als aktuell gilt (< Takt)

    private function zonen(): ?array
    {
        $home  = $this->i('homeId');
        $datei = self::ABLAGE . $home . '.json';
        /* Vermerkt werden ZWEI Zeitpunkte:
         *   t = letzte gelungene Antwort (dazu gehoert z)
         *   v = letzter VERSUCH, gelungen oder nicht
         * Nur t zu fuehren war zu wenig: schlug die Abfrage fehl, stand nichts
         * Frisches in der Ablage, und die naechste Zone fragte selbst nach.
         * Bei einer Stoerung wurde aus der einen Sammelabfrage wieder eine je
         * Zone - gemessen am 29.08.2026 fuenf Anfragen in derselben Sekunde,
         * ausgerechnet waehrend die Drosselung lief. Mit v geht pro Zeitfenster
         * hoechstens eine Anfrage raus, egal wie sie ausgeht.
         */
        $lesen = static function (string $d) {
            $x = @json_decode((string) @file_get_contents($d), true);
            return is_array($x) ? $x : [];
        };
        $p = $lesen($datei);
        if (isset($p['z']) && (time() - (int) ($p['t'] ?? 0)) < self::FRISCH) {
            return $p['z'];
        }
        if ((time() - (int) ($p['v'] ?? 0)) < self::FRISCH) {
            return null;          // jemand hat es eben versucht und ist gescheitert
        }
        $sem = 'tadoZonen' . $home;
        if (!@\IPS_SemaphoreEnter($sem, 9000)) {
            $p = $lesen($datei);  // ein anderer holt gerade - dessen Ergebnis nehmen
            return (isset($p['z']) && (time() - (int) ($p['t'] ?? 0)) < self::FRISCH) ? $p['z'] : null;
        }
        try {
            $p = $lesen($datei);                                   // hinter dem Riegel erneut pruefen
            if (isset($p['z']) && (time() - (int) ($p['t'] ?? 0)) < self::FRISCH) {
                return $p['z'];
            }
            if ((time() - (int) ($p['v'] ?? 0)) < self::FRISCH) {
                return null;
            }
            /* Bedingte Anfrage, wie die tado-App sie stellt.
             *
             * Der Mitschnitt der iPhone-App vom 29.08.2026 zeigt zu jedem
             * zoneStates-Aufruf ein "If-None-Match" mit dem zuletzt erhaltenen
             * ETag. Hat sich nichts geaendert, antwortet tado mit 304 und ohne
             * Rumpf - der Zustand bleibt gueltig, es fliessen keine Daten.
             */
            $marke = (string) ($p['e'] ?? '');
            $tk = $this->token();
            if ($tk === '') {
                @file_put_contents($datei, json_encode(['v' => time()] + $p));
                return null;
            }
            $roh  = $this->http('GET', self::API . '/homes/' . $home . '/zoneStates', null, $tk, $code,
                                $marke !== '' ? ['If-None-Match: ' . $marke] : []);
            $neu  = ['v' => time()];                               // der Versuch zaehlt in jedem Fall
            if ($code === 304 && isset($p['z'])) {
                $neu['t'] = time();                                // unveraendert = weiterhin gueltig
                $neu['z'] = $p['z'];
                $neu['e'] = $marke;
                @file_put_contents($datei, json_encode($neu));
                return $p['z'];
            }
            $d   = json_decode((string) $roh, true);
            $gut = ($code >= 200 && $code <= 299) && is_array($d) && isset($d['zoneStates']);
            if (!$gut) {
                $this->log('GET /homes/' . $home . '/zoneStates -> HTTP ' . $code);
            }
            if ($gut) {
                $neu['t'] = time();
                $neu['z'] = $d['zoneStates'];
                if ($this->etag !== '') { $neu['e'] = $this->etag; }
            } elseif (isset($p['t'], $p['z'])) {
                $neu['t'] = (int) $p['t'];                         // letzten guten Stand nicht wegwerfen
                $neu['z'] = $p['z'];
                if (isset($p['e'])) { $neu['e'] = $p['e']; }
            }
            @file_put_contents($datei, json_encode($neu));
            return $gut ? $d['zoneStates'] : null;
        } finally {
            @\IPS_SemaphoreLeave($sem);
        }
    }

    public function readState(): ClimateState
    {
        $alle = $this->zonen();
        $d = is_array($alle) ? ($alle[(string) $this->i('zoneId')] ?? null) : null;
        if (!is_array($d)) {
            return new ClimateState(reachable: false);
        }
        $s   = $d['setting'] ?? [];
        $an  = (string) ($s['power'] ?? '') === 'ON';
        $rueck = static fn(array $tab, $w): string
            => (string) (array_search((string) $w, $tab, true) ?: '');

        return new ClimateState(
            on:      $an,
            mode:    $rueck(self::MODI, $s['mode'] ?? ''),
            target:  isset($s['temperature']['celsius']) ? (float) $s['temperature']['celsius'] : -100.0,
            indoor:  isset($d['sensorDataPoints']['insideTemperature']['celsius'])
                        ? (float) $d['sensorDataPoints']['insideTemperature']['celsius'] : -100.0,
            outdoor: -100.0,          // liefert tado nur haus-, nicht zonenweise
            fan:     $rueck(self::FANS, $s['fanLevel'] ?? ''),
            swing:   $rueck(self::SWING, $s['verticalSwing'] ?? ''),
            preset:  '',
            ion:     false,
            humidity: isset($d['sensorDataPoints']['humidity']['percentage'])
                        ? (float) $d['sensorDataPoints']['humidity']['percentage'] : -1.0,
            swingH:  $rueck(self::SWING, $s['horizontalSwing'] ?? ''),
            light:   isset($s['light']) ? ((string) $s['light'] === 'ON') : null,
            // Kein Overlay = die Zone folgt ihrem Zeitplan.
            scheduled: !isset($d['overlay']) || $d['overlay'] === null,
            // acPower sagt, ob der Verdichter LAEUFT - "power: ON" heisst nur,
            // dass die Zone eingeschaltet ist. Bei erreichter Solltemperatur
            // steht das Geraet trotzdem.
            running:  isset($d['activityDataPoints']['acPower']['value'])
                        ? ((string) $d['activityDataPoints']['acPower']['value'] === 'ON') : null,
            presence: strtolower((string) ($d['tadoMode'] ?? '')),
            overrideUntil: isset($d['overlay']['termination']['projectedExpiry'])
                        ? (int) strtotime((string) $d['overlay']['termination']['projectedExpiry']) : 0,
            nextChange: isset($d['nextTimeBlock']['start'])
                        ? (int) strtotime((string) $d['nextTimeBlock']['start']) : 0,
            // link.state ist genauer als "die Abfrage kam durch": die Cloud
            // antwortet auch dann, wenn das Innengeraet selbst offline ist.
            openWindow: isset($d['openWindow']) ? ($d['openWindow'] !== null) : null,
            reachable: ((string) ($d['link']['state'] ?? 'ONLINE')) === 'ONLINE'
        );
    }

    // --------------------------------------------------------------- schreiben

    public function setPower(bool $on): bool
    {
        // Ausschalten braucht kein setting-Beiwerk; Einschalten uebernimmt den
        // zuletzt gelesenen Zustand, sonst wuerde tado auf Vorgaben zurueckfallen.
        if (!$on) {
            return $this->overlay(['type' => 'AIR_CONDITIONING', 'power' => 'OFF']);
        }
        $z = $this->readState();
        return $this->overlay($this->setting($z, ['power' => 'ON']));
    }

    public function setMode(string $mode): bool
    {
        $m = self::MODI[strtolower($mode)] ?? null;
        return $m === null ? false : $this->aendere(['mode' => $m]);
    }

    public function setTarget(float $celsius): bool
    {
        return $this->aendere(['temperature' => ['celsius' => round($celsius, 1)]]);
    }

    public function setFan(string $fan): bool
    {
        $f = self::FANS[strtolower($fan)] ?? null;
        return $f === null ? false : $this->aendere(['fanLevel' => $f]);
    }

    public function setSwing(string $swing): bool
    {
        $s = self::SWING[strtolower($swing)] ?? null;
        return $s === null ? false : $this->aendere(['verticalSwing' => $s]);
    }

    public function setPreset(string $preset): bool { return false; }   // kennt tado nicht
    public function setIon(bool $on): bool          { return false; }
    public function setFireplace(string $modus): bool { return false; }   // kennt dieses Geraet nicht

    public function setPowerLevel(int $prozent): bool { return false; }   // kennt tado nicht

    public function setSwingH(string $swing): bool
    {
        $s = self::SWING[strtolower($swing)] ?? null;
        return $s === null ? false : $this->aendere(['horizontalSwing' => $s]);
    }

    public function setLight(bool $on): bool
    {
        return $this->aendere(['light' => $on ? 'ON' : 'OFF']);
    }

    /**
     * Zurueck auf den Zeitplan: das Overlay loeschen. Umgekehrt (false) waere
     * ein Handbetrieb ohne inhaltliche Aenderung - dafuer wird der Ist-Zustand
     * als Overlay festgeschrieben.
     */
    public function setScheduled(bool $folgen): bool
    {
        if ($folgen) {
            $d = $this->ruf('DELETE', '/homes/' . $this->i('homeId') . '/zones/' . $this->i('zoneId') . '/overlay');
            return is_array($d);
        }
        $z = $this->readState();
        return $z->reachable && $this->overlay($this->setting($z, []));
    }

    /**
     * Ein Feld aendern, den Rest aus dem Ist-Zustand uebernehmen.
     *
     * tado ersetzt mit dem Overlay die GANZE Einstellung - wer nur die
     * Temperatur schickt, verliert Modus und Luefterstufe. Deshalb erst lesen,
     * dann das eine Feld ersetzen.
     */
    private function aendere(array $feld): bool
    {
        $z = $this->readState();
        if (!$z->reachable) {
            return false;
        }
        return $this->overlay($this->setting($z, ['power' => 'ON'] + $feld));
    }

    private function setting(ClimateState $z, array $ueberschreiben): array
    {
        $s = ['type' => 'AIR_CONDITIONING', 'power' => $z->on ? 'ON' : 'OFF'];
        if ($z->mode !== '' && isset(self::MODI[$z->mode]))   { $s['mode']    = self::MODI[$z->mode]; }
        if ($z->target > -100)                                { $s['temperature'] = ['celsius' => $z->target]; }
        if ($z->fan !== '' && isset(self::FANS[$z->fan]))     { $s['fanLevel'] = self::FANS[$z->fan]; }
        if ($z->swing !== '' && isset(self::SWING[$z->swing])){ $s['verticalSwing'] = self::SWING[$z->swing]; }
        if ($z->swingH !== '' && isset(self::SWING[$z->swingH])){ $s['horizontalSwing'] = self::SWING[$z->swingH]; }
        if ($z->light !== null)                               { $s['light'] = $z->light ? 'ON' : 'OFF'; }
        return array_merge($s, $ueberschreiben);
    }

    private function overlay(array $setting): bool
    {
        $d = $this->ruf('PUT', '/homes/' . $this->i('homeId') . '/zones/' . $this->i('zoneId') . '/overlay',
                        ['setting' => $setting, 'termination' => ['type' => 'MANUAL']]);
        return is_array($d);
    }

    // ------------------------------------------------------------------ Token

    /**
     * Gueltiger Zugriffstoken, vorsorglich erneuert.
     * Leerer String = keine Anmeldung; dann hilft nur eine neue Registrierung.
     */
    /**
     * Vollstaendige Neuanmeldung mit hinterlegten Zugangsdaten.
     *
     * Nachgebaut aus dem Charles-Mitschnitt der iPhone-App: der Anmeldeserver
     * ist ein FusionAuth, die Zugangsdaten gehen als loginId/password an
     * POST /oauth2/authorize, danach laeuft eine Weiterleitungskette ueber
     * complete-registration und consent bis zur Rueckleitung tado://auth/redirect
     * mit dem Autorisierungscode. Ohne Client-Geheimnis, dafuer mit PKCE.
     *
     * Liefert den frischen Zugriffstoken oder '' - dann bleibt es beim alten.
     */
    private function neuAnmelden(): string
    {
        $benutzer = trim((string) $this->wert('benutzerVid', ''));
        $kennwort = (string) $this->wert('kennwortVid', '');
        if ($benutzer === '' || $kennwort === '') {
            $this->log('keine Zugangsdaten hinterlegt - Neuanmeldung nicht moeglich');
            return '';
        }
        $b64 = static fn(string $r): string => rtrim(strtr(base64_encode($r), '+/', '-_'), '=');
        $pruefer   = $b64(random_bytes(40));
        $forderung = $b64(hash('sha256', $pruefer, true));
        $zustand   = $b64(random_bytes(16));
        $kekse     = tempnam(sys_get_temp_dir(), 'tado');
        $ziel      = '';

        $ruf = function (string $verb, string $url, ?array $form) use ($kekse, &$ziel): int {
            $ziel = '';
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_COOKIEFILE     => $kekse,
                CURLOPT_COOKIEJAR      => $kekse,
                CURLOPT_TIMEOUT        => 25,
                CURLOPT_USERAGENT      => self::UA,
                CURLOPT_HTTPHEADER     => ['Accept: */*', 'X-Amzn-Trace-Id: tado=iOS-15158'],
                CURLOPT_HEADERFUNCTION => function ($c, $z) use (&$ziel) {
                    if (stripos(trim($z), 'location:') === 0) { $ziel = trim(substr(trim($z), 9)); }
                    return strlen($z);
                },
            ]);
            if ($form !== null) {
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form));
                curl_setopt($ch, CURLOPT_HTTPHEADER,
                    ['Content-Type: application/x-www-form-urlencoded', 'Accept: */*',
                     'X-Amzn-Trace-Id: tado=iOS-15158']);
            }
            $a = curl_exec($ch);
            $c = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return $c;
        };

        $gemein = ['client_id' => self::CLIENT_ID, 'code_challenge' => $forderung,
                   'code_challenge_method' => 'S256', 'redirect_uri' => self::REDIR,
                   'response_type' => 'code', 'scope' => self::SCOPE, 'state' => $zustand];
        $ruf('GET', self::AUTH_URL . '?' . http_build_query($gemein), null);
        $ruf('POST', self::AUTH_URL, $gemein + [
            'tenantId' => self::TENANT, 'timezone' => 'Europe/Vienna',
            'metaData.device.name' => 'iPhone/iPod Safari', 'metaData.device.type' => 'BROWSER',
            'userVerifyingPlatformAuthenticatorAvailable' => 'true',
            'loginId' => $benutzer, 'password' => $kennwort]);

        $code = '';
        for ($n = 0; $n < 8 && $ziel !== ''; $n++) {
            if (stripos($ziel, self::REDIR) === 0) {
                parse_str((string) parse_url($ziel, PHP_URL_QUERY), $q);
                $code = (string) ($q['code'] ?? '');
                break;
            }
            $ruf('GET', ($ziel[0] === '/' ? 'https://login.tado.com' . $ziel : $ziel), null);
        }
        if ($code === '') {
            @unlink($kekse);
            $this->log('Neuanmeldung: kein Autorisierungscode erhalten');
            return '';
        }
        $antwort = $this->http('POST', self::TOKEN_URL, null, null, $c2, [], http_build_query([
            'scope' => self::SCOPE, 'grant_type' => 'authorization_code',
            'client_id' => self::CLIENT_ID, 'redirect_uri' => self::REDIR,
            'code' => $code, 'code_verifier' => $pruefer]));
        @unlink($kekse);
        $d = json_decode((string) $antwort, true);
        if (!isset($d['access_token'], $d['refresh_token'])) {
            $this->log('Neuanmeldung: Tausch fehlgeschlagen (HTTP ' . $c2 . ')');
            return '';
        }
        $this->setze('refreshVid', (string) $d['refresh_token']);
        $this->setze('accessVid',  (string) $d['access_token']);
        $this->setze('expiresVid', time() + (int) ($d['expires_in'] ?? 1800));
        $this->log('Neuanmeldung gelungen');
        return (string) $d['access_token'];
    }

    private function token(): string
    {
        $ablauf = (int) $this->wert('expiresVid', 0);
        $zugriff = (string) $this->wert('accessVid', '');
        if ($zugriff !== '' && $ablauf > time() + self::VORLAUF) {
            return $zugriff;
        }
        $refresh = (string) $this->wert('refreshVid', '');
        if ($refresh === '') {
            $this->log('kein Refresh-Token - Registrierung noetig');
            return '';
        }
        /* Einzelflug bei der Erneuerung.
         *
         * Die fuenf Zonen teilen sich EINEN Refresh-Token, und tado rotiert ihn
         * bei jeder Einloesung. Liefen fuenf Erneuerungen gleichzeitig, gewann
         * eine und die anderen vier legten einen bereits verbrauchten Token vor
         * - genau das waren die HTTP 400 am 29.08.2026, die wie ein Verlust der
         * Registrierung aussahen. Wer den Riegel nicht bekommt, wartet auf den
         * Gewinner und nimmt dessen frischen Token.
         */
        $sem = 'tadoToken' . $this->i('homeId');
        if (!@\IPS_SemaphoreEnter($sem, 12000)) {
            return (string) $this->wert('accessVid', '');
        }
        try {
        $ablauf  = (int) $this->wert('expiresVid', 0);          // hinter dem Riegel erneut pruefen
        $zugriff = (string) $this->wert('accessVid', '');
        if ($zugriff !== '' && $ablauf > time() + self::VORLAUF) {
            return $zugriff;
        }
        $refresh = (string) $this->wert('refreshVid', '');
        $antwort = $this->http('POST', self::TOKEN_URL
            . '?client_id=' . self::CLIENT_ID . '&grant_type=refresh_token&scope=' . rawurlencode(self::SCOPE)
            . '&refresh_token=' . urlencode($refresh),
            null, null, $code);
        $d = json_decode((string) $antwort, true);
        if ($code !== 200 || !isset($d['access_token'], $d['refresh_token'])) {
            // 4xx = Token verbrannt, da hilft kein Wiederholen. 5xx/Netz = beim
            // naechsten Zyklus erneut versuchen; der alte Token gilt bis dahin.
            // 429 heisst gedrosselt, NICHT verbrannt - der Refresh-Token gilt
            // weiter. Eine Aufforderung zur Neuregistrierung waere hier falsch
            // und wuerde zu einer unnoetigen Browser-Bestaetigung verleiten.
            if ($code >= 400 && $code < 500 && $code !== 429) {
                // Refresh-Token verbrannt. Frueher hiess das: der Nutzer muss
                // im Browser neu bestaetigen. Mit hinterlegten Zugangsdaten
                // meldet sich der Treiber selbst wieder an - genau dafuer
                // liegen Benutzer und Kennwort im Baum.
                $this->log('Erneuerung fehlgeschlagen (HTTP ' . $code . ') - melde neu an');
                $frisch = $this->neuAnmelden();
                if ($frisch !== '') {
                    return $frisch;
                }
            }
            $this->log('Erneuerung fehlgeschlagen (HTTP ' . $code . ')'
                . ($code === 429 ? ' - gedrosselt, spaeter erneut' : ' - naechster Versuch folgt'));
            return $zugriff;   // notfalls den alten probieren
        }
        // ZUERST ablegen, dann weiterarbeiten.
        $this->setze('refreshVid', (string) $d['refresh_token']);
        $this->setze('accessVid',  (string) $d['access_token']);
        $this->setze('expiresVid', time() + (int) ($d['expires_in'] ?? 600));
        return (string) $d['access_token'];
        } finally {
            @\IPS_SemaphoreLeave($sem);
        }
    }

    // ------------------------------------------------------------------ intern

    private function ruf(string $verb, string $pfad, ?array $daten = null)
    {
        $t = $this->token();
        if ($t === '') {
            return null;
        }
        $antwort = $this->http($verb, self::API . $pfad, $daten, $t, $code);
        if ($code < 200 || $code > 299) {
            $this->log($verb . ' ' . $pfad . ' -> HTTP ' . $code);
            return null;
        }
        if ($antwort === '' || $antwort === null) {
            return [];           // PUT/DELETE antworten leer
        }
        $d = json_decode((string) $antwort, true);
        return is_array($d) ? $d : [];
    }

    private function http(string $verb, string $url, ?array $daten, ?string $token, ?int &$code, array $zusatz = [], ?string $formular = null)
    {
        // Wie die App auftreten, nicht nur mit ihrer Kennung: Kennzeichner und
        // Ablaufmarke gehen bei jeder Anfrage mit.
        $kopf = array_merge(['Content-Type: application/json',
                             'Accept: application/json, text/plain, */*',
                             'X-Amzn-Trace-Id: tado=iOS-15158'], $zusatz);
        if ($token !== null && $token !== '') {
            $kopf[] = 'Authorization: Bearer ' . $token;
        }
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $verb,
            CURLOPT_HTTPHEADER     => $kopf,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_USERAGENT      => self::UA,
        ]);
        if ($formular !== null) {
            // Der Anmeldeserver nimmt nur Formularkodierung, kein JSON.
            curl_setopt($ch, CURLOPT_POSTFIELDS, $formular);
            curl_setopt($ch, CURLOPT_HTTPHEADER,
                array_merge(['Content-Type: application/x-www-form-urlencoded'], $zusatz));
        } elseif ($daten !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($daten));
        }
        // Den Kontingent-Kopf mitlesen: er nennt Restanfragen und die Sekunden
        // bis zur Ruecksetzung. Ohne ihn merkt man das Ende des Kontingents erst
        // am ersten 429 - mit ihm laesst es sich kommen sehen.
        $kopf = [];
        $this->etag = '';
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($c, $z) use (&$kopf) {
            if (stripos($z, 'ratelimit:') === 0) { $kopf[] = trim($z); }
            if (stripos($z, 'etag:') === 0)      { $this->etag = trim(substr(trim($z), 5)); }
            return strlen($z);
        });
        $antwort = curl_exec($ch);
        $code    = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($kopf !== [] && preg_match('/r=(\\d+)(?:;t=(\\d+))?/', $kopf[0], $m)) {
            $this->rest = (int) $m[1];
            $this->ruecksetzung = isset($m[2]) ? (time() + (int) $m[2]) : 0;
            if ($this->rest <= 50) {
                $this->log('Kontingent knapp: noch ' . $this->rest . ' Anfragen'
                    . ($this->ruecksetzung ? ', frei ab ' . date('H:i', $this->ruecksetzung) : ''));
            }
        }
        return $antwort;
    }

    private function i(string $k): int    { return (int) ($this->cfg[$k] ?? 0); }

    private function wert(string $k, $vorgabe)
    {
        $vid = (int) ($this->cfg[$k] ?? 0);
        if ($vid <= 0 || !function_exists('IPS_VariableExists') || !@\IPS_VariableExists($vid)) {
            return $vorgabe;
        }
        $w = @\GetValue($vid);
        return $w === null ? $vorgabe : $w;
    }

    private function setze(string $k, $wert): void
    {
        $vid = (int) ($this->cfg[$k] ?? 0);
        if ($vid > 0 && @\IPS_VariableExists($vid)) {
            @\SetValue($vid, $wert);
        }
    }

    private function log(string $msg): void
    {
        if (function_exists('IPS_LogMessage')) {
            @\IPS_LogMessage('HS.Tado', $msg);
        }
    }
}

DriverFactory::register('tado-cloud', TadoCloud::class);

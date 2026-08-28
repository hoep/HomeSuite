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
    private const CLIENT_ID = '1bb50063-6b0c-4d11-bd99-387f4a91cc46';
    private const VORLAUF   = 300;   // Sekunden vor Ablauf erneuern

    /** sprechend <-> tado */
    private const MODI  = ['auto' => 'AUTO', 'cool' => 'COOL', 'heat' => 'HEAT',
                           'dry' => 'DRY', 'fan' => 'FAN'];
    private const FANS  = ['auto' => 'AUTO', 'quiet' => 'SILENT', 'low' => 'LEVEL1',
                           'medium' => 'LEVEL2', 'high' => 'LEVEL3', 'veryhigh' => 'LEVEL4'];
    private const SWING = ['off' => 'OFF', 'vertical' => 'ON'];

    private array $cfg = [];

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
        return (new ClimateCapabilities(
            power: true, target: true, targetMin: $min, targetMax: $max, targetStep: $step,
            modes: $modi, fans: $fans, swings: $schwenkV, presets: [],
            ion: false, indoor: true, outdoor: false,
            humidity: true, swingsH: $schwenkH, light: $licht, schedule: true,
            powerLevels: [], running: true, presence: true, selfClean: false
        ))->toArray();
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

    public function readState(): ClimateState
    {
        $d = $this->ruf('GET', '/homes/' . $this->i('homeId') . '/zones/' . $this->i('zoneId') . '/state');
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
        $antwort = $this->http('POST', self::TOKEN_URL
            . '?client_id=' . self::CLIENT_ID . '&grant_type=refresh_token&refresh_token=' . urlencode($refresh),
            null, null, $code);
        $d = json_decode((string) $antwort, true);
        if ($code !== 200 || !isset($d['access_token'], $d['refresh_token'])) {
            // 4xx = Token verbrannt, da hilft kein Wiederholen. 5xx/Netz = beim
            // naechsten Zyklus erneut versuchen; der alte Token gilt bis dahin.
            $this->log('Erneuerung fehlgeschlagen (HTTP ' . $code . ')'
                . (($code >= 400 && $code < 500) ? ' - neu registrieren' : ' - naechster Versuch folgt'));
            return $zugriff;   // notfalls den alten probieren
        }
        // ZUERST ablegen, dann weiterarbeiten.
        $this->setze('refreshVid', (string) $d['refresh_token']);
        $this->setze('accessVid',  (string) $d['access_token']);
        $this->setze('expiresVid', time() + (int) ($d['expires_in'] ?? 600));
        return (string) $d['access_token'];
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

    private function http(string $verb, string $url, ?array $daten, ?string $token, ?int &$code)
    {
        $kopf = ['Content-Type: application/json'];
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
        ]);
        if ($daten !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($daten));
        }
        $antwort = curl_exec($ch);
        $code    = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
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

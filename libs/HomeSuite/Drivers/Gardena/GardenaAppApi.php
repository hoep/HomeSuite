<?php

declare(strict_types=1);

namespace Hoep\HomeSuite\HAL;

/**
 * Gardena „smart system" ueber die ALTE Cloud https://smart.gardena.com/v1/ — authentifiziert mit
 * DEMSELBEN Husqvarna-Group-SSO-Token wie der Automower (scripts/automower_token.json). Husqvarna
 * besitzt Gardena; das Legacy-Bearer-Token (JWT, iss=husqvarna) wird von smart.gardena.com akzeptiert.
 * Der user_id-Parameter ist der `sub`-Claim des JWT. Live verifiziert (2026-08): Standorte, Geraete,
 * Abilities/Zustaende (Feuchtesensoren, Bewaesserungscomputer). NUR Lesen ist scharf; Ventil-Kommandos
 * sind implementiert, werden aber vom Modul erst bei armed=true und nach Doku-Verifikation gefeuert.
 *
 * Ein Treiber-Objekt = EIN Gardena-Geraet (deviceId). readState() liefert einen flachen Zustand.
 */
final class GardenaAppApi implements IDriver
{
    private string $base = 'https://smart.gardena.com/v1/';

    private ?string $tokenFile = null;
    private string $deviceId   = '';
    private string $locationId = '';

    private ?string $token    = null;
    private string $provider  = 'husqvarna';
    private string $userId    = '';
    private string $username  = '';
    private string $password  = '';
    private int $tokenAblauf  = 0;      // JWT-exp
    private bool $loginVersucht = false;

    /** @var callable|null */
    private $send = null;
    private string $lastError = '';
    /** @var array<int,array>|null Geraete-Cache je Prozess (kurzer TTL ueber den Aufruf). */
    private ?array $devicesCache = null;

    public function bind(array $config, callable $send): void
    {
        $this->deviceId   = (string) ($config['deviceId'] ?? '');
        $this->locationId = (string) ($config['locationId'] ?? '');
        if (!empty($config['tokenFile'])) {
            $this->tokenFile = (string) $config['tokenFile'];
        }
        $this->send = $send;
        $this->loadToken();
    }

    /** Token aus der (root-only) Datei laden und user_id aus dem JWT (sub) ziehen. Kein Netz. */
    private function loadToken(): void
    {
        if (!$this->tokenFile || !@is_file($this->tokenFile)) {
            $this->lastError = 'no_token_file';
            return;
        }
        $j = @json_decode((string) @file_get_contents($this->tokenFile), true);
        if (!is_array($j) || empty($j['token'])) {
            $this->lastError = 'no_token';
            return;
        }
        $this->token    = (string) $j['token'];
        $this->provider = (string) ($j['provider'] ?? 'husqvarna');
        $this->username = (string) ($j['username'] ?? '');
        $this->password = (string) ($j['password'] ?? '');
        // JWT-Payload (Teil 2, base64url) -> sub und exp
        $parts = explode('.', $this->token);
        if (count($parts) >= 2) {
            $pl = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/')), true);
            $this->userId      = (string) ($pl['sub'] ?? $pl['user_id'] ?? '');
            $this->tokenAblauf = (int) ($pl['exp'] ?? 0);
        }
        if ($this->tokenAblauf === 0) {
            $this->tokenAblauf = (int) ($j['expiration'] ?? 0);
        }
    }

    /** Ist der Token noch brauchbar? Fuenf Minuten Sicherheitsabstand. */
    private function tokenGueltig(): bool
    {
        return $this->token !== null && $this->tokenAblauf > (time() + 300);
    }

    /**
     * Token bei Bedarf erneuern.
     *
     * Der Husqvarna-Legacy-Token laeuft nach ZEHN TAGEN ab, und bis hierher hat
     * ihn niemand erneuert: loadToken() las die Datei und fertig. Am 23.08.2026
     * 11:35 lief er aus, danach scheiterte JEDER Gardena-Abruf still - die
     * Geraete standen auf Online=false, die Messwerte froren ein, und weil die
     * Online-Variable weiter geschrieben wurde, hat es auch die Cloud-Wache
     * nicht gemeldet. Sieben Tage lang.
     *
     * Die Zugangsdaten liegen in derselben Datei, eine Neuanmeldung ist also
     * moeglich. Sie wird HOECHSTENS EINMAL je Prozess versucht - sonst haemmert
     * ein Poll-Timer bei anhaltendem Fehler im Minutentakt gegen die IAM.
     */
    private function tokenSichern(): bool
    {
        if ($this->tokenGueltig()) { return true; }
        if ($this->loginVersucht) { return false; }
        $this->loginVersucht = true;
        if ($this->username === '' || $this->password === '') {
            $this->lastError = 'no_credentials';
            return false;
        }
        return $this->anmelden();
    }

    /** Anmeldung an der Husqvarna-IAM; Ergebnis zurueck in die Tokendatei. */
    private function anmelden(): bool
    {
        $felder = ['data' => ['type' => 'token',
            'attributes' => ['username' => $this->username, 'password' => $this->password]]];
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => 'https://iam-api.dss.husqvarnagroup.net/api/v3/token',
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($felder),
            CURLOPT_HTTPHEADER     => ['Accept: application/json', 'Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $roh  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $res = ($roh === false || $roh === '') ? null : json_decode((string) $roh, true);
        $neu = $res['data']['id'] ?? null;
        if ($code !== 201 && $code !== 200) { $this->lastError = 'login_http_' . $code; return false; }
        if (empty($neu)) { $this->lastError = 'login_no_token'; return false; }

        $this->token    = (string) $neu;
        $this->provider = (string) ($res['data']['attributes']['provider'] ?? 'husqvarna');
        $parts = explode('.', $this->token);
        if (count($parts) >= 2) {
            $pl = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/')), true);
            $this->userId      = (string) ($pl['sub'] ?? $pl['user_id'] ?? '');
            $this->tokenAblauf = (int) ($pl['exp'] ?? 0);
        }

        // Zurueckschreiben, damit alle Instanzen und der naechste Lauf davon haben.
        if ($this->tokenFile) {
            $j = @json_decode((string) @file_get_contents($this->tokenFile), true);
            if (!is_array($j)) { $j = []; }
            $j['token']      = $this->token;
            $j['provider']   = $this->provider;
            $j['expiration'] = $this->tokenAblauf;
            $j['erneuert']   = date('c');
            @file_put_contents($this->tokenFile . '.tmp', json_encode($j, JSON_PRETTY_PRINT));
            @chmod($this->tokenFile . '.tmp', 0600);
            @rename($this->tokenFile . '.tmp', $this->tokenFile);
        }
        return true;
    }

    private function headers(?array $body): array
    {
        $h = ['Accept: application/json'];
        if ($body !== null) {
            $h[] = 'Content-Type: application/json';
        }
        if (!empty($this->token)) {
            $h[] = 'Authorization: Bearer ' . $this->token;
            $h[] = 'Authorization-Provider: ' . $this->provider;
        }
        return $h;
    }

    /**
     * HTTP gegen smart.gardena.com/v1. $query wird um user_id (Pflicht) ergaenzt.
     * @return array{code:int,body:mixed}
     */
    private function req(string $method, string $path, array $query = [], ?array $body = null): array
    {
        if (!$this->tokenSichern()) {
            return ['code' => 0, 'body' => null];
        }
        if (!$this->token || $this->userId === '') {
            return ['code' => 0, 'body' => null];
        }
        $query['user_id'] = $this->userId;
        $url = $this->base . ltrim($path, '/') . '?' . http_build_query($query);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->headers($body));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
        } elseif ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        }
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        // 401 heisst: der Token gilt nicht mehr, obwohl sein Ablauf noch in der
        // Zukunft liegt (zurueckgezogen, Passwort geaendert, Sitzung beendet).
        // Genau einmal neu anmelden und den Aufruf wiederholen.
        if ($code === 401 && !$this->loginVersucht) {
            $this->loginVersucht = true;
            if ($this->anmelden()) {
                return $this->req($method, $path, $query, $body);
            }
        }
        if ($code >= 400 || $code === 0) {
            $this->lastError = 'http_' . $code;
        }
        return ['code' => $code, 'body' => is_string($raw) ? json_decode($raw, true) : null];
    }

    /** Standort-ID ermitteln (konfiguriert oder erste des Kontos), gecacht. */
    private function loc(): string
    {
        if ($this->locationId !== '') {
            return $this->locationId;
        }
        $r = $this->req('GET', 'locations');
        $locs = $r['body']['locations'] ?? [];
        if (is_array($locs) && isset($locs[0]['id'])) {
            $this->locationId = (string) $locs[0]['id'];
        }
        return $this->locationId;
    }

    /** Rohe Geraeteliste des Standorts (gecacht je Objekt). */
    private function devicesRaw(): array
    {
        if ($this->devicesCache !== null) {
            return $this->devicesCache;
        }
        $loc = $this->loc();
        if ($loc === '') {
            return $this->devicesCache = [];
        }
        $r = $this->req('GET', 'devices', ['locationId' => $loc]);
        $this->devicesCache = is_array($r['body']['devices'] ?? null) ? $r['body']['devices'] : [];
        return $this->devicesCache;
    }

    /** @return array<int,array{id:string,name:string,category:string,serial:string,model:string,online:bool}> */
    public function listDevices(): array
    {
        $out = [];
        foreach ($this->devicesRaw() as $d) {
            $info = $this->abilProps($d, 'device_info');
            $out[] = [
                'id'       => (string) ($d['id'] ?? ''),
                'name'     => (string) ($d['name'] ?? ''),
                'category' => (string) ($d['category'] ?? ($info['category'] ?? '')),
                'serial'   => (string) ($info['serial_number'] ?? ''),
                'model'    => (string) ($info['model_number'] ?? ''),
                'online'   => (string) ($info['connection_status'] ?? '') === 'online',
            ];
        }
        return $out;
    }

    /** Properties einer Ability als name=>value-Map. */
    private function abilProps(array $device, string $abilityName): array
    {
        foreach ($device['abilities'] ?? [] as $a) {
            if (($a['name'] ?? '') === $abilityName) {
                $m = [];
                foreach ($a['properties'] ?? [] as $p) {
                    if (isset($p['name'])) {
                        $m[$p['name']] = $p['value'] ?? null;
                    }
                }
                return $m;
            }
        }
        return [];
    }

    private function findDevice(string $id): ?array
    {
        foreach ($this->devicesRaw() as $d) {
            if ((string) ($d['id'] ?? '') === $id) {
                return $d;
            }
        }
        return null;
    }

    /**
     * Flacher Zustand eines Geraets (default das gebundene). Gemeinsame Felder + kategoriespezifisch.
     * @return array<string,mixed>
     */
    public function readState(?string $deviceId = null): array
    {
        $id = $deviceId ?? $this->deviceId;
        $d  = $id !== '' ? $this->findDevice($id) : null;
        if ($d === null) {
            return ['ok' => false, 'error' => $this->lastError ?: 'device_not_found'];
        }
        $info = $this->abilProps($d, 'device_info');
        $cat  = (string) ($d['category'] ?? ($info['category'] ?? ''));
        $s = [
            'ok'           => true,
            'id'           => $id,
            'name'         => (string) ($d['name'] ?? ''),
            'category'     => $cat,
            'serial'       => (string) ($info['serial_number'] ?? ''),
            'model'        => (string) ($info['model_number'] ?? ''),
            'online'       => ((string) ($info['connection_status'] ?? '') === 'online')
                              || (bool) ($this->abilProps($d, 'connectivity')['established'] ?? false),
            'battery'      => $this->numOrNull($this->abilProps($d, 'battery')['level'] ?? null),
            'radioQuality' => $this->numOrNull($this->abilProps($d, 'radio')['quality'] ?? null),
            'error'        => (string) ($this->abilProps($d, 'error')['error'] ?? ''),
        ];
        if ($cat === 'sensor2' || $cat === 'sensor') {
            $s['soilTemp']     = $this->numOrNull($this->abilProps($d, 'soil_temperature')['temperature'] ?? null);
            $s['soilMoisture'] = $this->numOrNull($this->abilProps($d, 'humidity')['humidity'] ?? null);
            $s['frostWarning'] = (string) ($this->abilProps($d, 'soil_temperature')['frost_warning'] ?? '');
        } elseif ($cat === 'watering_computer' || $cat === 'water_control' || $cat === 'smart_irrigation_control') {
            $s['ambientTemp'] = $this->numOrNull($this->abilProps($d, 'ambient_temperature')['temperature'] ?? null);
            $w = $this->abilProps($d, 'watering');
            $t = null;
            foreach ($w as $k => $v) { if (strpos($k, 'watering_timer') === 0 && is_array($v)) { $t = $v; break; } }
            $s['valveState']    = (string) ($t['state'] ?? 'unknown');   // idle | manual | scheduled …
            $s['valveDuration'] = (int) ($t['duration'] ?? 0);
            $s['valveId']       = (int) ($t['valve_id'] ?? 1);
            $s['wateringAbilityId'] = $this->abilityId($d, 'watering');
        }
        return $s;
    }

    private function abilityId(array $device, string $name): string
    {
        foreach ($device['abilities'] ?? [] as $a) {
            if (($a['name'] ?? '') === $name) { return (string) ($a['id'] ?? ''); }
        }
        return '';
    }

    private function numOrNull($v)
    {
        return is_numeric($v) ? (0 + $v) : null;
    }

    // ------------------------------------------------------------------
    // Schalten (nur watering_computer). NICHT ungeprueft feuern — das Modul
    // ruft dies erst bei armed=true auf. Kommando-Name gegen Gardena-Doku
    // verifizieren, bevor es scharf geschaltet wird.
    // ------------------------------------------------------------------
    public function valveStart(int $seconds): array
    {
        $st = $this->readState();
        $aid = (string) ($st['wateringAbilityId'] ?? '');
        if ($aid === '') { return ['ok' => false, 'error' => 'no_watering_ability']; }
        $r = $this->req('POST', 'devices/' . $this->deviceId . '/abilities/' . $aid . '/command',
            ['locationId' => $this->loc()],
            ['name' => 'manual_watering', 'parameters' => ['manual_watering_timer' => max(1, $seconds)]]);
        return ['ok' => $r['code'] >= 200 && $r['code'] < 300, 'code' => $r['code'], 'body' => $r['body']];
    }

    public function valveStop(): array
    {
        $st = $this->readState();
        $aid = (string) ($st['wateringAbilityId'] ?? '');
        if ($aid === '') { return ['ok' => false, 'error' => 'no_watering_ability']; }
        $r = $this->req('POST', 'devices/' . $this->deviceId . '/abilities/' . $aid . '/command',
            ['locationId' => $this->loc()],
            ['name' => 'cancel_override']);
        return ['ok' => $r['code'] >= 200 && $r['code'] < 300, 'code' => $r['code'], 'body' => $r['body']];
    }

    public function lastError(): string
    {
        return $this->lastError;
    }

    // ---- IDriver-Pflicht ----
    public function capabilities()
    {
        return ['read' => true, 'category' => $this->deviceId !== '' ? ($this->readState()['category'] ?? '') : ''];
    }

    public static function discover(int $timeoutMs = 2000): array
    {
        return []; // Discovery laeuft ueber die Modul-Import-Op (listDevices), nicht ueber die Factory.
    }

    public function poll(): array
    {
        return $this->readState();
    }

    public function parseEvent(string $raw): ?AudioState
    {
        return null; // Audio-spezifische IDriver-Methode; hier ungenutzt.
    }
}

DriverFactory::register('gardena-app', GardenaAppApi::class);

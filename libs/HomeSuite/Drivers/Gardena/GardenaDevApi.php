<?php

declare(strict_types=1);

namespace Hoep\HomeSuite\HAL;

/**
 * Gardena „smart system" ueber die NEUE Husqvarna-Developer-Plattform
 * (https://api.smart.gardena.dev/v1/). Auth: OAuth2 client_credentials gegen den gemeinsamen
 * Auth-Server api.authentication.husqvarnagroup.dev mit Application-Key/-Secret (aus
 * scripts/gardena_dev.json). Anders als der Legacy-Treiber gardena-app kann diese API auch
 * SCHREIBEN (VALVE_CONTROL). Der access_token wird nach scripts/gardena_dev_token.json gecacht
 * (Gueltigkeit i.d.R. ~24h) und bei Ablauf automatisch erneuert.
 *
 * Datenmodell der neuen API: eine Location enthaelt „services" (COMMON, VALVE, VALVE_SET,
 * SENSOR, SOIL_SENSOR …), jeweils mit eigener serviceId. Kommandos gehen per
 * PUT /v1/command/{valveServiceId}. readState() flacht die relevanten Services zu einem
 * Zustand ab, kompatibel zum Legacy-Treiber (gleiche Feldnamen).
 *
 * Ein Treiber-Objekt = EIN Gardena-Geraet. „deviceId" ist hier die COMMON-serviceId des Geraets
 * (bzw. die zugehoerige VALVE-serviceId fuers Schalten wird aus der Location aufgeloest).
 */
final class GardenaDevApi implements IDriver
{
    private string $authUrl = 'https://api.authentication.husqvarnagroup.dev/v1/oauth2/token';
    private string $base    = 'https://api.smart.gardena.dev/v1/';

    private string $credFile  = '/var/lib/symcon/scripts/gardena_dev.json';
    private string $tokenFile = '/var/lib/symcon/scripts/gardena_dev_token.json';

    private string $deviceId    = '';   // COMMON-serviceId (Geraet) in der NEUEN API
    private string $locationId  = '';
    private string $matchSerial = '';   // Fallback: Geraet ueber Seriennummer finden (Legacy-IDs != neue IDs)

    private string $appKey    = '';
    private string $appSecret = '';
    private string $token     = '';
    private int    $tokenExp  = 0;

    /** @var callable|null */
    private $send = null;
    private string $lastError = '';
    /** @var array|null Location-Detail-Cache (services inkl. included) je Objekt. */
    private ?array $locCache = null;

    public function bind(array $config, callable $send): void
    {
        $this->deviceId    = (string) ($config['deviceId'] ?? '');
        $this->locationId  = (string) ($config['locationId'] ?? '');
        $this->matchSerial = (string) ($config['matchSerial'] ?? '');
        if (!empty($config['credFile'])) {
            $this->credFile = (string) $config['credFile'];
        }
        if (!empty($config['tokenFile'])) {
            $this->tokenFile = (string) $config['tokenFile'];
        }
        $this->send = $send;
        $this->loadCreds();
    }

    /** Application-Key/-Secret aus der (root-only) Datei laden. Kein Netz. */
    private function loadCreds(): void
    {
        if (!@is_file($this->credFile)) {
            $this->lastError = 'no_cred_file';
            return;
        }
        $j = @json_decode((string) @file_get_contents($this->credFile), true);
        if (!is_array($j) || empty($j['appKey']) || empty($j['appSecret'])) {
            $this->lastError = 'no_creds';
            return;
        }
        $this->appKey    = (string) $j['appKey'];
        $this->appSecret = (string) $j['appSecret'];
    }

    /** Gueltigen access_token liefern (Cache-Datei -> sonst neu holen). */
    private function accessToken(): string
    {
        if ($this->token !== '' && $this->tokenExp > time() + 30) {
            return $this->token;
        }
        // Cache-Datei
        $c = @json_decode((string) @file_get_contents($this->tokenFile), true);
        if (is_array($c) && !empty($c['access_token']) && (int) ($c['expires_at'] ?? 0) > time() + 30) {
            $this->token   = (string) $c['access_token'];
            $this->tokenExp = (int) $c['expires_at'];
            return $this->token;
        }
        if ($this->appKey === '' || $this->appSecret === '') {
            $this->loadCreds();
            if ($this->appKey === '') {
                return '';
            }
        }
        // client_credentials-Grant
        $post = http_build_query([
            'grant_type'    => 'client_credentials',
            'client_id'     => $this->appKey,
            'client_secret' => $this->appSecret,
        ]);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->authUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $j = is_string($raw) ? json_decode($raw, true) : null;
        if ($code !== 200 || empty($j['access_token'])) {
            $this->lastError = 'auth_http_' . $code;
            return '';
        }
        $this->token    = (string) $j['access_token'];
        $exp            = (int) ($j['expires_in'] ?? 86400);
        $this->tokenExp = time() + max(60, $exp - 60);
        @file_put_contents($this->tokenFile, json_encode([
            'access_token' => $this->token,
            'expires_at'   => $this->tokenExp,
        ]));
        @chmod($this->tokenFile, 0600);
        return $this->token;
    }

    /**
     * HTTP gegen api.smart.gardena.dev. Header: Bearer + X-Api-Key (+ JSON:API-Content-Type bei Body).
     * @return array{code:int,body:mixed}
     */
    private function req(string $method, string $path, ?array $body = null): array
    {
        $tok = $this->accessToken();
        if ($tok === '') {
            return ['code' => 0, 'body' => null];
        }
        $h = [
            'Authorization: Bearer ' . $tok,
            'X-Api-Key: ' . $this->appKey,
            'Accept: application/vnd.api+json',
        ];
        if ($body !== null) {
            $h[] = 'Content-Type: application/vnd.api+json';
        }
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->base . ltrim($path, '/'));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $h);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        if ($method === 'PUT' || $method === 'POST') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
            }
        }
        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code >= 400 || $code === 0) {
            $this->lastError = 'http_' . $code;
        }
        return ['code' => $code, 'body' => is_string($raw) ? json_decode($raw, true) : null];
    }

    private function loc(): string
    {
        if ($this->locationId !== '') {
            return $this->locationId;
        }
        $r = $this->req('GET', 'locations');
        foreach (($r['body']['data'] ?? []) as $l) {
            if (($l['type'] ?? '') === 'LOCATION') {
                $this->locationId = (string) ($l['id'] ?? '');
                break;
            }
        }
        return $this->locationId;
    }

    /**
     * Location-Detail (alle Services als included), gruppiert je Geraet als LISTE von Services.
     * WICHTIG: In GARDENAs JSON:API teilen sich mehrere Services eines Geraets DIESELBE id
     * (= Geraete-id, nur `type` unterscheidet; VALVE_SET hat Suffix „:wc"). Daher NICHT nach id
     * mappen (Kollision), sondern je Geraet eine Liste [{type, attr, sid}, …] fuehren.
     */
    private function services(): array
    {
        if ($this->locCache !== null) {
            return $this->locCache;
        }
        $loc = $this->loc();
        if ($loc === '') {
            return $this->locCache = ['byDevice' => []];
        }
        $r = $this->req('GET', 'locations/' . $loc);
        $byDevice = [];
        foreach (($r['body']['included'] ?? []) as $s) {
            $type = (string) ($s['type'] ?? '');
            if ($type === '' || $type === 'DEVICE') {
                continue; // DEVICE-Resourcen enumerieren nur, tragen keine Attribute
            }
            $dev = (string) ($s['relationships']['device']['data']['id'] ?? '');
            if ($dev === '') {
                continue;
            }
            $attr = [];
            foreach (($s['attributes'] ?? []) as $k => $v) {
                // neue API kapselt jeden Wert als {value:…,timestamp:…}
                $attr[$k] = is_array($v) && array_key_exists('value', $v) ? $v['value'] : $v;
            }
            $byDevice[$dev][] = ['type' => $type, 'attr' => $attr, 'sid' => (string) ($s['id'] ?? '')];
        }
        return $this->locCache = ['byDevice' => $byDevice];
    }

    /** @return array<int,array{id:string,name:string,category:string,serial:string,model:string,online:bool}> */
    public function listDevices(): array
    {
        $sv = $this->services();
        $out = [];
        foreach ($sv['byDevice'] as $devId => $services) {
            $common = null;
            foreach ($services as $s) {
                if ($s['type'] === 'COMMON') { $common = $s['attr']; break; }
            }
            if ($common === null) {
                continue;
            }
            $devId = (string) $devId;
            $cat = $this->categoryOf($services);
            $out[] = [
                'id'       => (string) $devId,
                'name'     => (string) ($common['name'] ?? ''),
                'category' => $cat,
                'serial'   => (string) ($common['serial'] ?? ''),
                'model'    => (string) ($common['modelType'] ?? ''),
                'online'   => (string) ($common['rfLinkState'] ?? '') === 'ONLINE',
            ];
        }
        return $out;
    }

    /** Legacy-kompatible Kategorie aus den vorhandenen Service-Typen ableiten. */
    private function categoryOf(array $services): string
    {
        $types = array_map(fn($s) => $s['type'], $services);
        if (in_array('VALVE', $types, true) || in_array('VALVE_SET', $types, true)) {
            return 'watering_computer';
        }
        if (in_array('SENSOR', $types, true) || in_array('SOIL_SENSOR', $types, true)) {
            return 'sensor2';
        }
        return 'gateway';
    }

    /**
     * Flacher Zustand eines Geraets (default das gebundene). Feldnamen kompatibel zum Legacy-Treiber,
     * plus valveServiceId fuers Schalten.
     */
    /** Geraet-ID (COMMON-serviceId) aufloesen: direkt, sonst ueber Seriennummer. */
    private function resolveDeviceId(?string $wanted): string
    {
        $sv = $this->services();
        $id = (string) ($wanted ?? $this->deviceId);
        if ($id !== '' && isset($sv['byDevice'][$id])) {
            return $id;
        }
        if ($this->matchSerial !== '') {
            foreach ($sv['byDevice'] as $devId => $services) {
                foreach ($services as $s) {
                    if ($s['type'] === 'COMMON' && (string) ($s['attr']['serial'] ?? '') === $this->matchSerial) {
                        return (string) $devId;
                    }
                }
            }
        }
        return $id;
    }

    public function readState(?string $deviceId = null): array
    {
        $sv = $this->services();
        $id = $this->resolveDeviceId($deviceId);
        $services = $sv['byDevice'][$id] ?? null;
        if ($services === null) {
            return ['ok' => false, 'error' => $this->lastError ?: 'device_not_found'];
        }
        $common = []; $valve = null; $valveId = ''; $sensor = []; $soil = [];
        foreach ($services as $s) {
            switch ($s['type']) {
                case 'COMMON':      $common = $s['attr']; break;
                case 'VALVE':       $valve = $s['attr']; $valveId = (string) $s['sid']; break;
                case 'SENSOR':      $sensor = $s['attr']; break;
                case 'SOIL_SENSOR': $soil = $s['attr']; break;
            }
        }
        $cat = $this->categoryOf($services);
        $s = [
            'ok'           => true,
            'id'           => (string) $id,
            'name'         => (string) ($common['name'] ?? ''),
            'category'     => $cat,
            'serial'       => (string) ($common['serial'] ?? ''),
            'model'        => (string) ($common['modelType'] ?? ''),
            'online'       => (string) ($common['rfLinkState'] ?? '') === 'ONLINE',
            'battery'      => $this->numOrNull($common['batteryLevel'] ?? null),
            'radioQuality' => $this->numOrNull($common['rfLinkLevel'] ?? null),
            'error'        => '',
        ];
        if ($cat === 'sensor2') {
            $s['soilTemp']     = $this->numOrNull($soil['soilTemperature'] ?? ($sensor['soilTemperature'] ?? null));
            $s['soilMoisture'] = $this->numOrNull($soil['soilHumidity'] ?? ($sensor['soilHumidity'] ?? null));
            $s['frostWarning'] = '';
        } elseif ($cat === 'watering_computer') {
            $s['ambientTemp']       = $this->numOrNull($sensor['ambientTemperature'] ?? null);
            $st                     = (string) ($valve['activity'] ?? 'unknown'); // CLOSED | MANUAL_WATERING | SCHEDULED_WATERING …
            $s['valveState']        = $st === 'CLOSED' ? 'idle' : strtolower($st);
            $s['valveDuration']     = (int) ($valve['duration'] ?? 0);
            $s['valveId']           = 1;
            $s['valveServiceId']    = $valveId;
            $s['wateringAbilityId'] = $valveId; // Legacy-Feldname, damit das Modul unveraendert bleibt
        }
        return $s;
    }

    private function numOrNull($v)
    {
        return is_numeric($v) ? (0 + $v) : null;
    }

    // ------------------------------------------------------------------
    // Schalten (neue API, JSON:API). VALVE_CONTROL an die VALVE-serviceId.
    // ------------------------------------------------------------------
    public function valveStart(int $seconds): array
    {
        $sid = (string) ($this->readState()['valveServiceId'] ?? '');
        if ($sid === '') {
            return ['ok' => false, 'error' => 'no_valve_service'];
        }
        $r = $this->req('PUT', 'command/' . $sid, [
            'data' => [
                'id'         => 'hsga-start',
                'type'       => 'VALVE_CONTROL',
                'attributes' => [
                    'command' => 'START_SECONDS_TO_OVERRIDE',
                    'seconds' => max(1, $seconds),
                ],
            ],
        ]);
        return ['ok' => $r['code'] >= 200 && $r['code'] < 300, 'code' => $r['code'], 'body' => $r['body']];
    }

    public function valveStop(): array
    {
        $sid = (string) ($this->readState()['valveServiceId'] ?? '');
        if ($sid === '') {
            return ['ok' => false, 'error' => 'no_valve_service'];
        }
        $r = $this->req('PUT', 'command/' . $sid, [
            'data' => [
                'id'         => 'hsga-stop',
                'type'       => 'VALVE_CONTROL',
                'attributes' => ['command' => 'STOP_UNTIL_NEXT_TASK'],
            ],
        ]);
        return ['ok' => $r['code'] >= 200 && $r['code'] < 300, 'code' => $r['code'], 'body' => $r['body']];
    }

    public function lastError(): string
    {
        return $this->lastError;
    }

    // ---- IDriver-Pflicht ----
    public function capabilities()
    {
        return ['read' => true, 'write' => true, 'category' => $this->deviceId !== '' ? ($this->readState()['category'] ?? '') : ''];
    }

    public static function discover(int $timeoutMs = 2000): array
    {
        return []; // Discovery laeuft ueber die Modul-Import-Op (listDevices).
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

DriverFactory::register('gardena-dev', GardenaDevApi::class);

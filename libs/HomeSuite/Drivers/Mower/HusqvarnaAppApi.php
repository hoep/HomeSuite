<?php

declare(strict_types=1);

namespace Hoep\HomeSuite\HAL;

/**
 * HusqvarnaAppApi — SELF-CONTAINED IMower-Treiber ueber die undokumentierte
 * Husqvarna „dss"-App-API (iam-api/amc-api.dss.husqvarnagroup.net).
 *
 * Diese Fassung hat KEINE Abhaengigkeit mehr zu \PHPAutomower oder
 * automower.class.maps.php. Transport/Auth (Baustein 1), Lesen/Normalisieren
 * (Baustein 2) und Steuern/Zeitplan (Baustein 3) sind hier vollstaendig
 * eingebettet.
 *
 * GRUNDSATZ (B1): kernel-frei, zustandslos ueber Requests hinweg. Der einzige
 * persistente Zustand ist der auf Platte abgelegte dss-Token (eigene Datei,
 * beschreibbar vom symcon-Nutzer — NICHT die root-only scripts/automower_token.json).
 * Token-Reuse verhindert einen Login-Sturm; erst bei Ablauf wird mit den bei
 * bind() uebergebenen Credentials neu eingeloggt.
 *
 * capabilities()['realtime']=false — die dss-App-API kann keinen Push; „Echtzeit"
 * macht das Modul per schnellem Poll (readState(false) = leichter Poll,
 * readState(true) = voller Poll inkl. schwerer Endpunkte).
 *
 * readState() liefert das normalisierte 40-Key-Schema (siehe unten). Alle
 * Schreibpfade (start/park/pause/resume/confirmError/setCuttingHeight/
 * setHeadlight/setSchedule/setTimers/updateWorkArea/updateStayOutZone/
 * resetCuttingBlade) gehen ueber die eingebettete Steuer-/Transport-Schicht;
 * das armed-Gate besitzt das Modul (dieser Treiber fuehrt aus, was er gerufen wird).
 */
final class HusqvarnaAppApi implements IMower
{
    /* ================= dss-Basis-URLs ================= */
    private string $urlIam   = 'https://iam-api.dss.husqvarnagroup.net/api/v3/';
    private string $urlTrack = 'https://amc-api.dss.husqvarnagroup.net/app/v1/';

    /* ================= Sitzung / Zugangsdaten ================= */
    private string $username = '';
    private string $password = '';
    private string $mowerId  = '';

    private ?string $token       = null;
    private ?string $provider    = null;
    private int $tokenExpiration = 0;
    private int $tokenBuffer     = 300;
    private bool $isLoggedIn     = false;
    private ?string $lastError   = null;
    private ?string $tokenFile   = null;

    /** @var callable */
    private $send;

    /* ================= Enum-Maps (deutsch) ================= */

    /** Activity-String -> Code 0..7 */
    private const ACT_CODES = [
        'UNKNOWN' => 0, 'NOT_APPLICABLE' => 1, 'MOWING' => 2, 'GOING_HOME' => 3,
        'CHARGING' => 4, 'LEAVING' => 5, 'PARKED_IN_CS' => 6, 'STOPPED_IN_GARDEN' => 7,
    ];
    private const ACT_TEXT = [
        'UNKNOWN' => 'Unbekannt', 'NOT_APPLICABLE' => 'Nicht zutreffend',
        'MOWING' => 'Mäht', 'GOING_HOME' => 'Auf dem Weg zur Ladestation',
        'CHARGING' => 'Lädt', 'LEAVING' => 'Verlässt die Ladestation',
        'PARKED_IN_CS' => 'In Ladestation geparkt', 'STOPPED_IN_GARDEN' => 'Im Garten gestoppt',
    ];
    /** State-String -> Code 0..11 */
    private const STATE_CODES = [
        'UNKNOWN' => 0, 'NOT_APPLICABLE' => 1, 'PAUSED' => 2, 'IN_OPERATION' => 3,
        'WAIT_UPDATING' => 4, 'WAIT_POWER_UP' => 5, 'RESTRICTED' => 6, 'OFF' => 7,
        'STOPPED' => 8, 'ERROR' => 9, 'FATAL_ERROR' => 10, 'ERROR_AT_POWER_UP' => 11,
    ];
    private const STATE_TEXT = [
        'UNKNOWN' => 'Unbekannt', 'NOT_APPLICABLE' => 'Nicht zutreffend',
        'PAUSED' => 'Pausiert', 'IN_OPERATION' => 'In Betrieb',
        'WAIT_UPDATING' => 'Wartet auf Aktualisierung', 'WAIT_POWER_UP' => 'Wartet auf Einschalten',
        'RESTRICTED' => 'Eingeschränkt', 'OFF' => 'Aus', 'STOPPED' => 'Gestoppt',
        'ERROR' => 'Fehler', 'FATAL_ERROR' => 'Schwerwiegender Fehler',
        'ERROR_AT_POWER_UP' => 'Fehler beim Einschalten',
    ];
    private const MODE_TEXT = [
        'HOME' => 'Bis auf Weiteres geparkt', 'MAIN_AREA' => 'Mähen nach Plan',
        'SECONDARY_AREA' => 'Mähen mit Arbeitsbereich-Vorgabe', 'DEMO' => 'Demo-Modus',
        'POI' => 'Punkt-Anfahrt', 'UNKNOWN' => 'Status unbekannt',
    ];
    /** Headlight-String -> Code 0..3 */
    private const HEADLIGHT_CODES = [
        'ALWAYS_ON' => 0, 'ALWAYS_OFF' => 1, 'EVENING_ONLY' => 2, 'EVENING_AND_NIGHT' => 3,
    ];
    /** Code 0..3 -> Headlight-String (fuer setHeadlight-Toleranz). */
    private const HEADLIGHT_NAMES = [
        0 => 'ALWAYS_ON', 1 => 'ALWAYS_OFF', 2 => 'EVENING_ONLY', 3 => 'EVENING_AND_NIGHT',
    ];

    /* ================= IDriver / IMower — Bind & Meta ================= */

    public function bind(array $config, callable $send): void
    {
        $this->username = (string) ($config['username'] ?? '');
        $this->password = (string) ($config['password'] ?? '');
        $this->mowerId  = (string) ($config['mowerId'] ?? '');
        if (!empty($config['tokenFile'])) {
            $this->tokenFile = (string) $config['tokenFile'];
        }
        $this->send = $send;
        // Vorhandenen Token (falls gueltig) laden — kein Login, kein Netz.
        $this->loadToken();
    }

    public function capabilities(): array
    {
        return [
            'scheduleMode'     => 'device',
            'hasBattery'       => true,
            'hasWorkAreas'     => true,
            'canConfirmError'  => true,
            'hasHeadlight'     => true,
            'hasCuttingHeight' => true,
            'realtime'         => false, // dss kann keinen Push -> Modul pollt schnell
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
        return null; // kein Realtime auf der App-API
    }

    public function parseMowerEvent(array $msg): array
    {
        return []; // kein Realtime
    }

    /** Letzter Fehlercode (nie ein Secret). */
    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /* ================= Lesen ================= */

    /**
     * NORMALISIERTER Ist-Zustand (40-Key-Schema).
     *
     * @param bool $full true = voller Poll (statistics/messages/missions/timers/
     *                   geofence/stayOutZones); false = leichter Poll
     *                   (status/settings/robot/workAreas). Nicht geholte Felder = null/[].
     *
     * `missions` haengt bewusst am LEICHTEN Poll, obwohl es ein eigener Aufruf ist: dort
     * stehen bei den systematisch maehenden Modellen (AE/EPOS) die Flaechen SAMT Fortschritt,
     * und der bewegt sich waehrend des Maehens laufend (gemessen 20.09.2026: "Unten" 4 % ->
     * 7 % in elf Minuten). Im 15-Minuten-Takt waere die Anzeige die meiste Zeit veraltet.
     * Ohne die Liste laesst sich ausserdem die aktive missionId nicht in einen Bereichsnamen
     * aufloesen - genau deshalb stand in `mission` vorher immer der Modus.
     *
     * Warum missions und nicht workAreas: an dieser Anlage antwortet /workAreas mit 404, die
     * Flaechen kommen aus /missions (siehe Fallback in normalizeState). Kostenpunkt ist EIN
     * zusaetzlicher Aufruf je Poll (leicht: 3 -> 4); die dss-App-API kennt keine Rate-Limits.
     * @return array leeres Array => nicht verfuegbar (Modul setzt Online=false).
     */
    public function readState(bool $full = true): array
    {
        if ($this->mowerId === '') {
            return [];
        }
        if (!$this->ensureLogin()) {
            return [];
        }

        // ---- Roh-Endpunkte holen (mit Cadence) --------------------------
        $id    = rawurlencode($this->mowerId);
        $raw   = [];
        $keys  = $full
            ? ['status', 'settings', 'statistics', 'messages', 'missions',
               'timers', 'geofence', 'workAreas', 'stayOutZones', 'robot']
            : ['status', 'settings', 'robot', 'missions'];   // missions: siehe unten
        $paths = [
            'status'       => 'mowers/' . $id . '/status',
            'settings'     => 'mowers/' . $id . '/settings',
            'statistics'   => 'mowers/' . $id . '/statistics',
            'messages'     => 'mowers/' . $id . '/messages',
            'missions'     => 'mowers/' . $id . '/missions',
            'timers'       => 'mowers/' . $id . '/timers',
            'geofence'     => 'mowers/' . $id . '/geofence',
            'workAreas'    => 'mowers/' . $id . '/workAreas',
            'stayOutZones' => 'mowers/' . $id . '/stayOutZones',
            'robot'        => 'mowers/' . $id,
        ];
        foreach ($keys as $k) {
            $r = $this->get($paths[$k]);
            $raw[$k] = ($r === false) ? null : $r;
        }

        // Wenn nicht einmal der Status kam -> nicht verfuegbar.
        if ($raw['status'] === null && ($raw['robot'] ?? null) === null) {
            return [];
        }

        return $this->normalizeState($raw);
    }

    public function isMowing(): ?bool
    {
        $st = $this->readState(false);
        if (empty($st) || !array_key_exists('activity', $st) || $st['activity'] === null) {
            return null;
        }
        return ((int) $st['activity'] === self::ACT_CODES['MOWING']);
    }

    /* ================= Steuern ================= */

    public function start(int $minutes = 0): bool
    {
        $cmd = 'start';
        if ($minutes >= 720)      { $cmd = 'start12h'; }
        elseif ($minutes >= 360)  { $cmd = 'start6h'; }
        elseif ($minutes >= 180)  { $cmd = 'start3h'; }
        return ($this->control($cmd) !== false);
    }

    public function park(string $mode = 'nextSchedule', int $minutes = 0): bool
    {
        switch ($mode) {
            case 'nextSchedule': return ($this->control('parkuntilnextschedule') !== false);
            case 'furtherNotice': return ($this->control('park') !== false);
            case 'duration':
                $cmd = 'park';
                if ($minutes >= 720)     { $cmd = 'park12h'; }
                elseif ($minutes >= 360) { $cmd = 'park6h'; }
                elseif ($minutes >= 180) { $cmd = 'park3h'; }
                return ($this->control($cmd) !== false);
            default: return ($this->control('park') !== false);
        }
    }

    /** ECHTES Pause (eigener control/pause-Endpunkt, NICHT start). */
    public function pause(): bool
    {
        return ($this->control('pause') !== false);
    }

    /** Fortsetzen: die dss-API kennt kein Resume => regulaerer Start (Periode 0). */
    public function resume(): bool
    {
        return ($this->control('start') !== false);
    }

    public function confirmError(): bool
    {
        // PUT mowers/<id>/errors/confirm (ohne Body).
        return ($this->put('mowers/' . rawurlencode($this->mowerId) . '/errors/confirm') !== false);
    }

    public function setCuttingHeight(int $level): bool
    {
        $level = max(1, min(9, $level));
        return $this->updateSettings(['cuttingHeight' => $level]);
    }

    public function setHeadlight(string $mode): bool
    {
        // Toleranz: numerischer Code oder String.
        if (is_numeric($mode) && isset(self::HEADLIGHT_NAMES[(int) $mode])) {
            $mode = self::HEADLIGHT_NAMES[(int) $mode];
        }
        $mode = strtoupper((string) $mode);
        if (!isset(self::HEADLIGHT_CODES[$mode])) {
            return false;
        }
        return $this->updateSettings(['headlight' => ['mode' => $mode]]);
    }

    /**
     * Wochenplan setzen (Kalender-Weg). $tasks: Liste von
     * ['start'=>Min,'duration'=>Min,'monday'=>bool,...,'sunday'=>bool].
     */
    public function setSchedule(array $tasks, ?int $workAreaId = null): bool
    {
        if ($workAreaId !== null) {
            return $this->updateWorkAreaCalendar((string) $workAreaId, $tasks);
        }
        return $this->updateCalendar($tasks);
    }

    /* ================= Settings / Wartung (public) ================= */

    /** PUT mowers/<id>/settings {settings:<data>}. Leerer 2xx-Body = Erfolg. */
    public function updateSettings(array $settings): bool
    {
        $res = $this->put('mowers/' . rawurlencode($this->mowerId) . '/settings', ['settings' => $settings]);
        if ($res === false) {
            return false;
        }
        if ($res === null) {
            return true; // leerer 2xx-Body
        }
        if (is_object($res) && isset($res->status)) {
            return ($res->status === 'OK');
        }
        return true;
    }

    /** PUT mowers/<id>/statistics/resetCuttingBladeUsageTime (ohne Body) — wie Alt-Code ($put=true). */
    public function resetCuttingBlade(): bool
    {
        $res = $this->put('mowers/' . rawurlencode($this->mowerId) . '/statistics/resetCuttingBladeUsageTime');
        return ($res !== false);
    }

    /**
     * Arbeitsbereich aktualisieren (Schnitthoehe/aktiv).
     * PUT mowers/<id>/workAreas/<waId> {data:{type:workArea,id,attributes:{...}}}.
     */
    public function updateWorkArea(string $workAreaId, ?int $cuttingHeight = null, ?bool $enabled = null): bool
    {
        $attributes = [];
        if ($cuttingHeight !== null) { $attributes['cuttingHeight'] = $cuttingHeight; }
        if ($enabled !== null)       { $attributes['enable']        = $enabled; }
        $data = ['data' => ['type' => 'workArea', 'id' => $workAreaId, 'attributes' => $attributes]];
        $res  = $this->put('mowers/' . rawurlencode($this->mowerId) . '/workAreas/' . rawurlencode($workAreaId), $data);
        return ($res !== false);
    }

    /**
     * Sperrzone aktivieren/deaktivieren.
     * PUT mowers/<id>/stayOutZones/<zoneId> {data:{type:stayOutZone,id,attributes:{enable:bool}}}.
     */
    public function updateStayOutZone(string $stayOutId, bool $enabled): bool
    {
        $data = ['data' => ['type' => 'stayOutZone', 'id' => $stayOutId, 'attributes' => ['enable' => $enabled]]];
        $res  = $this->put('mowers/' . rawurlencode($this->mowerId) . '/stayOutZones/' . rawurlencode($stayOutId), $data);
        return ($res !== false);
    }

    /* ================= Kalender (public) ================= */

    /** PUT mowers/<id>/calendar {data:{type:calendar,attributes:{tasks}}}. */
    public function updateCalendar(array $tasks): bool
    {
        $data = ['data' => ['type' => 'calendar', 'attributes' => ['tasks' => $tasks]]];
        $res  = $this->put('mowers/' . rawurlencode($this->mowerId) . '/calendar', $data);
        return ($res !== false);
    }

    /** PUT mowers/<id>/workAreas/<waId>/calendar {data:{type:calendar,attributes:{tasks}}}. */
    public function updateWorkAreaCalendar(string $workAreaId, array $tasks): bool
    {
        $data = ['data' => ['type' => 'calendar', 'attributes' => ['tasks' => $tasks]]];
        $res  = $this->put(
            'mowers/' . rawurlencode($this->mowerId) . '/workAreas/' . rawurlencode($workAreaId) . '/calendar',
            $data
        );
        return ($res !== false);
    }

    /* ================= Timer-CRUD (public) ================= */

    /**
     * Rohe Timer-Objekte (inkl. id) des Maehers.
     * @return array<int,\stdClass>
     */
    public function getTimersRaw(): array
    {
        $res = $this->get('mowers/' . rawurlencode($this->mowerId) . '/timers');
        if (is_object($res) && isset($res->timers) && is_array($res->timers)) {
            return $res->timers;
        }
        if (is_array($res)) {
            return $res;
        }
        return [];
    }

    /**
     * Normalisierte Timer (aufgefaechert je Tag NICHT — days-Objekt bleibt),
     * ohne id, im readState-Timer-Format [{start,duration,days{...},missionId}].
     * @return array<int,array>
     */
    public function getTimers(): array
    {
        return $this->normalizeTimers($this->getTimersRaw());
    }

    /**
     * Einen Timer anlegen/aktualisieren.
     * PUT mowers/<id>/timers {timer:{...}} (+ id bei Update).
     */
    public function setTimer(array $timer, ?string $timerId = null): bool
    {
        if ($timerId !== null) {
            $timer['id'] = $timerId;
        }
        $res = $this->put('mowers/' . rawurlencode($this->mowerId) . '/timers', ['timer' => $timer]);
        return ($res !== false);
    }

    /** Timer loeschen. DELETE mowers/<id>/timers/<timerId>. */
    public function deleteTimer(string $timerId): bool
    {
        $res = $this->delete('mowers/' . rawurlencode($this->mowerId) . '/timers/' . rawurlencode($timerId));
        return ($res !== false);
    }

    /**
     * Zielzustand der Timer setzen (Diff gegen Ist). Erzeugt fehlende, loescht
     * ueberzaehlige. Wird vom Modul NUR im scharfen Zustand gerufen.
     *
     * @param array<int,array> $desired jeder Eintrag:
     *        ['start'=>Min,'duration'=>Min,'days'=>['monday'=>bool,...],'missionId'=>?]
     * @return bool true, wenn alle noetigen Schreibvorgaenge gelangen (oder nichts zu tun)
     */
    public function setTimers(array $desired): bool
    {
        $current = $this->getTimersRaw();

        $curBySig = [];
        foreach ($current as $t) {
            $curBySig[$this->timerSig((array) $this->timerToArr($t))] = $t;
        }
        $wantSig = [];
        foreach ($desired as $t) {
            $wantSig[$this->timerSig($this->normalizeTimerEntry($t))] = $this->normalizeTimerEntry($t);
        }

        $ok = true;

        // Loeschen: im Ist, aber nicht im Soll.
        foreach ($curBySig as $sig => $t) {
            if (!isset($wantSig[$sig])) {
                $tid = null;
                if (is_object($t) && isset($t->id)) { $tid = (string) $t->id; }
                elseif (is_array($t) && isset($t['id'])) { $tid = (string) $t['id']; }
                if ($tid !== null && !$this->deleteTimer($tid)) {
                    $ok = false;
                }
            }
        }

        // Anlegen: im Soll, aber nicht im Ist.
        foreach ($wantSig as $sig => $t) {
            if (!isset($curBySig[$sig])) {
                $payload = [
                    'start'    => (int) $t['start'],
                    'duration' => (int) $t['duration'],
                    'days'     => $t['days'],
                ];
                if ($t['missionId'] !== null) {
                    $payload['missionId'] = $t['missionId'];
                }
                if (!$this->setTimer($payload)) {
                    $ok = false;
                }
            }
        }

        return $ok;
    }

    /* ================= Steuer-Kommando-Switch (privat) ================= */

    /**
     * control/*-Kommando gegen den gebundenen Maeher (POST, Body {"period":N}).
     * BUGFIX: pause = eigener control/pause-Endpunkt, NICHT start.
     *
     * @return object|array|null|false Transport-Antwort; false bei unbekanntem Kommando/Fehler
     */
    private function control(string $command)
    {
        $base = 'mowers/' . rawurlencode($this->mowerId) . '/control/';
        switch ($command) {
            case 'park':                  return $this->post($base . 'park',                  ['period' => 0]);
            case 'pause':                 return $this->post($base . 'pause',                 ['period' => 0]);
            case 'start':                 return $this->post($base . 'start',                 ['period' => 0]);
            case 'start3h':               return $this->post($base . 'start/override/period', ['period' => 180]);
            case 'start6h':               return $this->post($base . 'start/override/period', ['period' => 360]);
            case 'start12h':              return $this->post($base . 'start/override/period', ['period' => 720]);
            case 'parkuntilnextschedule': return $this->post($base . 'park/duration/timer',   ['period' => 0]);
            case 'park3h':                return $this->post($base . 'park/duration/timer',   ['period' => 180]);
            case 'park6h':                return $this->post($base . 'park/duration/timer',   ['period' => 360]);
            case 'park12h':               return $this->post($base . 'park/duration/timer',   ['period' => 720]);
            default:                      return false;
        }
    }

    /* ================= Transport: generische Verben (privat) ================= */

    /** @return object|array|false */
    private function get(string $path)
    {
        return $this->track('GET', $path, null);
    }

    /** @return object|array|null|false */
    private function post(string $path, ?array $body = null)
    {
        return $this->track('POST', $path, $body);
    }

    /** @return object|array|null|false */
    private function put(string $path, ?array $body = null)
    {
        return $this->track('PUT', $path, $body);
    }

    /** @return object|array|null|false */
    private function delete(string $path)
    {
        return $this->track('DELETE', $path, null);
    }

    /**
     * Zentraler Track-API-Aufruf mit einmaligem 401-Relogin.
     *
     * @return object|array|null|false  dekodierte Antwort, null bei leerem 2xx-Body,
     *                                   false bei Auth-/HTTP-/Transport-Fehler.
     */
    private function track(string $method, string $path, ?array $body, int $retry = 0)
    {
        if (!$this->ensureLogin($retry > 0)) {
            return false;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->urlTrack . $path);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->headers($body));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        switch ($method) {
            case 'POST':   curl_setopt($ch, CURLOPT_POST, true); break;
            case 'PUT':    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT'); break;
            case 'DELETE': curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE'); break;
            case 'GET':    /* default */ break;
        }
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
        $json = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $res = ($json === false || $json === '') ? null : json_decode($json);

        // Token abgelaufen/ungueltig -> einmal forcierter Relogin.
        if ($code === 401 || ($res && is_object($res) && isset($res->code) && $res->code === 'invalid.token')) {
            if ($retry < 1) {
                $this->isLoggedIn = false;
                usleep(500000);
                return $this->track($method, $path, $body, $retry + 1);
            }
            $this->lastError = 'token_invalid';
            return false;
        }

        if ($code < 200 || $code >= 300) {
            $this->lastError = 'http_' . $code;
            return false;
        }

        // Erfolg: leerer Body => null; sonst dekodierte Antwort (Fallback Rohtext).
        if ($res === null) {
            return null;
        }
        return $res;
    }

    /** Request-Header (Bearer + Provider, sofern Token vorliegt). */
    private function headers(?array $body = null): array
    {
        if (!empty($this->token)) {
            $h = [
                'Content-type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $this->token,
                'Authorization-Provider: ' . (string) $this->provider,
            ];
        } else {
            $h = ['Content-type: application/json', 'Accept: application/json'];
        }
        if ($body !== null) {
            $h[] = 'Content-Length: ' . strlen((string) json_encode($body));
        }
        return $h;
    }

    /* ================= Auth / Token (privat) ================= */

    /**
     * Sichert einen gueltigen Token. Reused vorhandenen Token; loggt nur bei
     * Ablauf/force mit den bind()-Credentials neu ein.
     */
    private function ensureLogin(bool $force = false): bool
    {
        if (!$force && $this->isLoggedIn && $this->tokenValid()) {
            return true;
        }
        if (!$force && $this->loadToken() && $this->tokenValid()) {
            return true;
        }
        if ($this->username === '' || $this->password === '') {
            $this->lastError = 'no_credentials';
            return false;
        }
        $ok = $this->login();
        if ($ok) {
            usleep(500000); // Nachlauf, damit die dss-API den Token verarbeitet
        }
        return $ok;
    }

    /** IAM-Login; setzt Token/Provider und persistiert sie. */
    private function login(): bool
    {
        $fields = [
            'data' => [
                'type'       => 'token',
                'attributes' => ['username' => $this->username, 'password' => $this->password],
            ],
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->urlIam . 'token');
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->headers($fields));
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($fields));
        $json = curl_exec($ch);
        curl_close($ch);

        $res = ($json === false || $json === '') ? null : json_decode($json);
        if (!is_object($res) || isset($res->errors)) {
            $this->lastError = 'login_failed';
            return false;
        }
        $token    = $res->data->id ?? null;
        $provider = $res->data->attributes->provider ?? null;
        if (empty($token) || empty($provider)) {
            $this->lastError = 'login_no_token';
            return false;
        }

        $this->token       = (string) $token;
        $this->provider    = (string) $provider;
        $exp               = $this->parseTokenExpiration($this->token);
        $this->tokenExpiration = $exp ?: (time() + 7 * 24 * 60 * 60);
        $this->isLoggedIn  = true;
        $this->lastError   = null;
        $this->saveToken();
        return true;
    }

    /** JWT-exp auslesen. */
    private function parseTokenExpiration(?string $token): ?int
    {
        if (!is_string($token) || $token === '') {
            return null;
        }
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        $payload = base64_decode(str_replace(['-', '_'], ['+', '/'], $parts[1]));
        if ($payload === false || $payload === '') {
            return null;
        }
        $data = json_decode($payload, true);
        if (!is_array($data) || !isset($data['exp'])) {
            return null;
        }
        return (int) $data['exp'];
    }

    private function tokenValid(): bool
    {
        if (empty($this->token)) {
            return false;
        }
        return $this->tokenExpiration > (time() + $this->tokenBuffer);
    }

    /** Absoluter, vom symcon-Nutzer beschreibbarer Token-Pfad. */
    private function tokenPath(): string
    {
        if (!empty($this->tokenFile)) {
            return $this->tokenFile;
        }
        if (function_exists('IPS_GetKernelDir')) {
            $dir = rtrim((string) \IPS_GetKernelDir(), '/\\');
            if ($dir !== '') {
                $this->tokenFile = $dir . DIRECTORY_SEPARATOR . 'automower_hsmw_token.json';
                return $this->tokenFile;
            }
        }
        $this->tokenFile = __DIR__ . DIRECTORY_SEPARATOR . 'automower_hsmw_token.json';
        return $this->tokenFile;
    }

    private function saveToken(): void
    {
        $data = [
            'token'      => $this->token,
            'provider'   => $this->provider,
            'expiration' => $this->tokenExpiration,
            'username'   => $this->username,
            'password'   => $this->password !== '' ? base64_encode($this->password) : null,
        ];
        $file = $this->tokenPath();
        @file_put_contents($file, json_encode($data));
        @chmod($file, 0600);
    }

    private function loadToken(): bool
    {
        $file = $this->tokenPath();
        if (!is_file($file)) {
            return false;
        }
        $data = json_decode((string) @file_get_contents($file), true);
        if (!is_array($data) || !isset($data['token'], $data['provider'], $data['expiration'])) {
            return false;
        }
        if ($this->username === '' && isset($data['username'])) {
            $this->username = (string) $data['username'];
        }
        if ($this->password === '' && !empty($data['password'])) {
            $this->password = (string) base64_decode((string) $data['password']);
        }
        if ((int) $data['expiration'] > (time() + $this->tokenBuffer)) {
            $this->token           = (string) $data['token'];
            $this->provider        = (string) $data['provider'];
            $this->tokenExpiration = (int) $data['expiration'];
            $this->isLoggedIn      = true;
            return true;
        }
        return false;
    }

    /* ================= Normalisierung (privat) ================= */

    /** deutscher Fehlertext zu einem Code. */
    private function errorText(int $code): string
    {
        $map = self::errorCodes();
        return $map[$code] ?? ('Unbekannter Fehler (Code: ' . $code . ')');
    }

    /** Klingenverschleiss in % (Referenz 36 h, gedeckelt). */
    private function bladeUsagePct($sec): float
    {
        $t = (float) $sec;
        if ($t <= 0) {
            return 0.0;
        }
        return round(min(100, ($t / (36 * 3600)) * 100), 1);
    }

    /** Effizienz % = Schnittzeit / Laufzeit * 100. */
    private function efficiencyPct($cut, $run): float
    {
        $run = (float) $run;
        if ($run <= 0) {
            return 0.0;
        }
        return round(((float) $cut / $run) * 100, 1);
    }

    /** Dotted-Path-Zugriff. */
    private function g($node, string $path)
    {
        $cur = $node;
        foreach (explode('.', $path) as $k) {
            if (is_object($cur) && isset($cur->$k)) {
                $cur = $cur->$k;
            } elseif (is_array($cur) && array_key_exists($k, $cur)) {
                $cur = $cur[$k];
            } else {
                return null;
            }
        }
        return $cur;
    }

    /** erste nicht-null Fundstelle aus mehreren Pfaden. */
    private function first($node, array $paths, $default = null)
    {
        foreach ($paths as $p) {
            $v = $this->g($node, $p);
            if ($v !== null) {
                return $v;
            }
        }
        return $default;
    }

    /** Status-„Wurzel" aus status-Endpoint ODER robot-data. */
    private function statusRoot($rawStatus, $rawRobot)
    {
        $s = $this->first($rawStatus, ['status', 'data.attributes.mower']);
        if ($s === null) {
            $s = $rawStatus;
        }
        if (($s === null || (is_object($s) && !isset($s->mowerStatus) && !isset($s->batteryPercent)))
            && $rawRobot !== null) {
            $r = $this->first($rawRobot, ['status', 'data.attributes.mower']);
            if ($r !== null) {
                $s = $r;
            }
        }
        return $s;
    }

    /**
     * Baut das normalisierte 40-Key-Schema aus den Roh-Endpunkten.
     * @param array $raw ['status'=>..,'settings'=>..,...]
     */
    private function normalizeState(array $raw): array
    {
        $rawStatus    = $raw['status']       ?? null;
        $rawSettings  = $raw['settings']     ?? null;
        $rawStats     = $raw['statistics']   ?? null;
        $rawMessages  = $raw['messages']     ?? null;
        $rawMissions  = $raw['missions']     ?? null;
        $rawTimers    = $raw['timers']       ?? null;
        $rawGeofence  = $raw['geofence']     ?? null;
        $rawWorkAreas = $raw['workAreas']    ?? null;
        $rawStayOut   = $raw['stayOutZones'] ?? null;
        $rawRobot     = $raw['robot']        ?? null;

        $sr = $this->statusRoot($rawStatus, $rawRobot);

        // ---- Activity / State / Mode -------------------------------------
        $activityStr = $sr !== null ? $this->first($sr, ['mowerStatus.activity', 'activity']) : null;
        $stateStr    = $sr !== null ? $this->first($sr, ['mowerStatus.state', 'state']) : null;
        $modeStr     = $sr !== null ? $this->first($sr, ['mowerStatus.mode', 'mode']) : null;

        $activity     = ($activityStr !== null && isset(self::ACT_CODES[$activityStr])) ? self::ACT_CODES[$activityStr] : null;
        $activityText = ($activityStr !== null) ? (self::ACT_TEXT[$activityStr] ?? (string) $activityStr) : null;
        $state        = ($stateStr !== null && isset(self::STATE_CODES[$stateStr])) ? self::STATE_CODES[$stateStr] : null;
        $stateText    = ($stateStr !== null) ? (self::STATE_TEXT[$stateStr] ?? (string) $stateStr) : null;
        $mode         = ($modeStr !== null) ? (self::MODE_TEXT[$modeStr] ?? (string) $modeStr) : null;

        // ---- Batterie / Verbindung / Ladung ------------------------------
        $batteryRaw = $sr !== null ? $this->first($sr, ['batteryPercent', 'battery.batteryPercent']) : null;
        $battery    = ($batteryRaw !== null) ? (int) $batteryRaw : null;

        $connectedRaw = $sr !== null ? $this->first($sr, ['connected', 'metadata.connected']) : null;
        if ($connectedRaw === null && $rawRobot !== null) {
            $connectedRaw = $this->first($rawRobot, ['status.connected', 'connected']);
        }
        $online = ($connectedRaw === null) ? null : (bool) $connectedRaw;
        // Wahrheit ableiten: Kernfelder erhalten => online (dss connected oft false).
        if ($online === null || $online === false) {
            if ($activity !== null || $battery !== null) {
                $online = true;
            }
        }

        $inCsRaw = $sr !== null ? $this->g($sr, 'inChargingStation') : null;
        if ($inCsRaw !== null) {
            $inChargingStation = (bool) $inCsRaw;
        } elseif ($activityStr === 'PARKED_IN_CS' || $activityStr === 'CHARGING') {
            $inChargingStation = true;
        } elseif ($activityStr !== null) {
            $inChargingStation = false;
        } else {
            $inChargingStation = null;
        }

        // ---- Fehler ------------------------------------------------------
        $errRaw = $sr !== null ? $this->first($sr, ['lastErrorCode', 'errorCode']) : null;
        if ($errRaw === null && $rawRobot !== null) {
            $errRaw = $this->first($rawRobot, ['status.lastErrorCode', 'status.errorCode']);
        }
        $errorCode = ($errRaw !== null) ? (int) $errRaw : null;
        $errorText = ($errorCode !== null) ? $this->errorText($errorCode) : null;

        // ---- naechster Start ---------------------------------------------
        $nsRaw = $sr !== null ? $this->first($sr, ['nextStartTimestamp']) : null;
        if ($nsRaw === null && $rawRobot !== null) {
            $nsRaw = $this->g($rawRobot, 'status.nextStartTimestamp');
        }
        // Derselbe Ortszeit-als-UTC-Versatz wie bei den Meldungen weiter unten: der
        // naechste Start stand dadurch zwei Stunden zu spaet. Gemessen 20.09.2026 an
        // Lefty - Rohwert 1789929156 ergibt lokal formatiert 20:32:36, die App nennt
        // 18:32. Dass storedTimestamp aus DERSELBEN Antwort ein korrekter UTC-Wert ist
        // (gegengeprueft: lokal 18:14:34 bei echter Serverzeit 18:14:37), macht es
        // heimtueckisch - die Felder folgen zwei verschiedenen Regeln.
        // date('Z', ...) statt einer festen Zahl, damit Winter- und Sommerzeit stimmen.
        $nextStart     = ($nsRaw !== null) ? (int) $nsRaw : null;
        if ($nextStart !== null && $nextStart > 0) { $nextStart -= (int) date('Z', $nextStart); }
        $nextStartText = ($nextStart !== null && $nextStart > 0)
            ? date('Y-m-d H:i:s', $nextStart)
            : (($nextStart !== null) ? 'Nicht geplant' : null);

        // ---- Statistik ---------------------------------------------------
        $stt = $rawStats;
        if ($stt !== null && !(is_object($stt) && isset($stt->totalRunningTime))) {
            $wrap = $this->first($stt, ['statistics', 'data.attributes']);
            if ($wrap !== null) {
                $stt = $wrap;
            }
        }
        $runningTime    = ($v = $this->g($stt, 'totalRunningTime'))       !== null ? (int) $v : null;
        $cuttingTime    = ($v = $this->g($stt, 'totalCuttingTime'))       !== null ? (int) $v : null;
        $chargingTime   = ($v = $this->g($stt, 'totalChargingTime'))      !== null ? (int) $v : null;
        $searchTime     = ($v = $this->g($stt, 'totalSearchingTime'))     !== null ? (int) $v : null;
        $collisions     = ($v = $this->g($stt, 'numberOfCollisions'))     !== null ? (int) $v : null;
        $chargingCycles = ($v = $this->g($stt, 'numberOfChargingCycles')) !== null ? (int) $v : null;
        $bladeUsageSec  = $this->g($stt, 'cuttingBladeUsageTime');

        $bladeHours    = ($bladeUsageSec !== null) ? (int) floor((int) $bladeUsageSec / 3600) : null;
        $searchHours   = ($searchTime !== null) ? (int) floor($searchTime / 3600) : null;
        $bladeUsagePct = ($bladeUsageSec !== null) ? $this->bladeUsagePct($bladeUsageSec) : null;
        $efficiencyPct = ($runningTime !== null && $cuttingTime !== null)
            ? $this->efficiencyPct($cuttingTime, $runningTime) : null;

        // ---- Einstellungen -----------------------------------------------
        $setRoot = $this->first($rawSettings, ['settings', 'data.attributes']);
        if ($setRoot === null) {
            $setRoot = $rawSettings;
        }
        $chRaw = $this->g($setRoot, 'cuttingHeight');
        $cuttingHeight = ($chRaw !== null) ? (int) $chRaw : null;

        $hlStr = $this->first($setRoot, ['headlight.mode', 'headlightMode']);
        $headlight = ($hlStr !== null && isset(self::HEADLIGHT_CODES[$hlStr])) ? self::HEADLIGHT_CODES[$hlStr]
            : (($hlStr !== null && is_numeric($hlStr)) ? (int) $hlStr : null);

        // ---- Modell / Firmware / Update ----------------------------------
        $model    = $rawRobot !== null ? $this->first($rawRobot, ['model', 'data.attributes.system.model']) : null;
        $firmware = $rawRobot !== null ? $this->first($rawRobot, [
            'swPackageVersionString', 'firmwareVersion', 'data.attributes.system.firmwareVersion',
        ]) : null;
        $updRaw = $rawRobot !== null ? $this->first($rawRobot, ['status.swUpdateRequired', 'swUpdateRequired']) : null;
        if ($updRaw === null && $sr !== null) {
            $updRaw = $this->g($sr, 'swUpdateRequired');
        }
        $updateRequired = ($updRaw === null) ? null : (bool) $updRaw;

        // ---- Positionen --------------------------------------------------
        $positions = [];
        $locs = ($sr !== null) ? $this->g($sr, 'lastLocations') : null;
        if ($locs === null && $rawRobot !== null) {
            $locs = $this->g($rawRobot, 'status.lastLocations');
        }
        if ($locs === null && $rawStatus !== null) {
            $locs = $this->first($rawStatus, ['status.lastLocations', 'lastLocations']);
        }
        if (is_array($locs)) {
            foreach ($locs as $loc) {
                $positions[] = [
                    'lat'       => ($v = $this->g($loc, 'latitude'))  !== null ? (float) $v : null,
                    'lng'       => ($v = $this->g($loc, 'longitude')) !== null ? (float) $v : null,
                    'gpsStatus' => $this->first($loc, ['gpsStatus', 'status']),
                ];
            }
        }
        $lat = isset($positions[0]) ? $positions[0]['lat'] : null;
        $lng = isset($positions[0]) ? $positions[0]['lng'] : null;

        // ---- Geofence ----------------------------------------------------
        $geofence = null;
        $gfLat = $this->first($rawGeofence, [
            'centralPoint.location.latitude', 'centralPoint.latitude',
            'data.attributes.centralPoint.location.latitude', 'location.latitude', 'latitude',
        ]);
        $gfLng = $this->first($rawGeofence, [
            'centralPoint.location.longitude', 'centralPoint.longitude',
            'data.attributes.centralPoint.location.longitude', 'location.longitude', 'longitude',
        ]);
        $gfRad = $this->first($rawGeofence, [
            'centralPoint.sensitivity.radius', 'radiusInMeters', 'radius', 'data.attributes.radiusInMeters',
        ]);
        if ($gfLat === null) {
            $gfLat = $this->first($setRoot, ['geofence.centralPoint.latitude', 'geofence.centralPoint.location.latitude']);
            $gfLng = $this->first($setRoot, ['geofence.centralPoint.longitude', 'geofence.centralPoint.location.longitude']);
            $gfRad = $this->first($setRoot, ['geofence.radiusInMeters']);
        }
        if ($gfLat === null && $rawRobot !== null) {
            $gfLat = $this->first($rawRobot, ['centralPoint.location.latitude']);
            $gfLng = $this->first($rawRobot, ['centralPoint.location.longitude']);
            $gfRad = $this->first($rawRobot, ['centralPoint.sensitivity.radius']);
        }
        if ($gfLat !== null || $gfLng !== null || $gfRad !== null) {
            $geofence = [
                'lat'    => ($gfLat !== null) ? (float) $gfLat : null,
                'lng'    => ($gfLng !== null) ? (float) $gfLng : null,
                'radius' => ($gfRad !== null) ? (float) $gfRad : null,
            ];
        }

        // ---- Nachrichten -------------------------------------------------
        $messages = [];
        $msgList = $this->first($rawMessages, ['messages', 'data.attributes.messages']);
        if (is_array($msgList)) {
            foreach ($msgList as $m) {
                $mc = ($v = $this->g($m, 'code')) !== null ? (int) $v : null;
                // Husqvarna liefert die Zeitstempel der Meldungen als ORTSZEIT,
                // kodiert als waere es UTC. Wer sie unbesehen als Unix-Zeit liest,
                // rechnet den Versatz ein zweites Mal hinein - die Fehlerhistorie
                // lag dadurch im Sommer zwei Stunden in der ZUKUNFT.
                // Nachgewiesen am 02.09.2026 an zwei Ereignissen von Righty: der
                // Zustand kippte laut Archiv um 19:15:12 und 19:20:13 auf "Fehler",
                // die Historie meldete 21:14:52 und 21:19:59 - auf die Sekunde
                // genau zwei Stunden spaeter. date('Z') statt einer festen Zahl,
                // damit Winter- und Sommerzeit gleichermassen stimmen.
                $mt = ($v = $this->g($m, 'time')) !== null ? (int) $v : null;
                if ($mt !== null && $mt > 0) { $mt -= (int) date('Z', $mt); }
                $messages[] = [
                    'time' => $mt,
                    'code' => $mc,
                    'text' => ($mc !== null) ? $this->errorText($mc) : null,
                ];
            }
        }

        // ---- Arbeitsbereiche ---------------------------------------------
        $workAreas = [];
        $waList = $this->first($rawWorkAreas, ['workAreas', 'data', 'data.attributes.workAreas']);
        if ($waList === null && is_array($rawWorkAreas)) {
            $waList = $rawWorkAreas;
        }
        if (is_array($waList)) {
            foreach ($waList as $wa) {
                $enabled = $this->first($wa, ['enabled', 'attributes.enabled', 'enable']);
                $workAreas[] = [
                    'id'            => $this->first($wa, ['workAreaId', 'id', 'attributes.workAreaId']),
                    'name'          => $this->first($wa, ['name', 'attributes.name']),
                    'cuttingHeight' => ($v = $this->first($wa, ['cuttingHeight', 'attributes.cuttingHeight'])) !== null ? (int) $v : null,
                    'enabled'       => ($enabled === null) ? null : (bool) $enabled,
                    // Die offizielle Automower-Connect-API fuehrt den Fortschritt auch hier.
                    // Er fehlte, weshalb er bisher nur ueber den missions-Fallback ankam.
                    'progress'      => ($p = $this->first($wa, ['progress', 'attributes.progress'])) !== null ? (int) $p : null,
                ];
            }
        }
        // Fallback: EPOS-/systematische Maeher (z. B. Modell AE) liefern KEINE workAreas
        // (Endpunkt 404), sondern fuehren die Flaechen als „missions". Als Bereiche mappen,
        // damit der Maehplan-Editor missionId -> Name aufloesen kann.
        if (empty($workAreas)) {
            $misAsWa = $this->first($rawMissions, ['missions', 'data.attributes.missions']);
            if (is_array($misAsWa)) {
                foreach ($misAsWa as $mi) {
                    $en = $this->first($mi, ['enabled', 'schedulable']);
                    $workAreas[] = [
                        'id'            => (string) $this->first($mi, ['missionId', 'areaId', 'id']),
                        'name'          => $this->first($mi, ['name']),
                        'cuttingHeight' => ($v = $this->g($mi, 'cuttingHeight')) !== null ? (int) $v : null,
                        'enabled'       => ($en === null) ? null : (bool) $en,
                        'progress'      => ($v = $this->g($mi, 'progress')) !== null ? (int) $v : null,
                    ];
                }
            }
        }

        // ---- Ausschlusszonen ---------------------------------------------
        $stayOutZones = [];
        $sozList = $this->first($rawStayOut, ['stayOutZones.zones', 'zones', 'data', 'data.attributes.zones']);
        if ($sozList === null && is_array($rawStayOut)) {
            $sozList = $rawStayOut;
        }
        if (is_array($sozList)) {
            foreach ($sozList as $z) {
                $enabled = $this->first($z, ['enabled', 'attributes.enabled', 'enable']);
                $stayOutZones[] = [
                    'id'      => $this->first($z, ['id', 'attributes.id']),
                    'name'    => $this->first($z, ['name', 'attributes.name']),
                    'enabled' => ($enabled === null) ? null : (bool) $enabled,
                ];
            }
        }

        // ---- Timer -------------------------------------------------------
        $timers = $this->normalizeTimers($this->timerList($rawTimers));

        // ---- aktive Mission ----------------------------------------------
        // Die dss-App-API nennt den gerade bearbeiteten Arbeitsbereich `missionId` -
        // NICHT `workAreaId`, wie es die offizielle Automower-Connect-API tut. Ohne
        // diesen Schluessel blieb `activeWaId` immer null und `mission` fiel auf den
        // Modus zurueck: in der Anzeige stand jahrelang "Maehen nach Plan" statt
        // "Hausbereich". Gemessen 20.09.2026 an Lefty: status.missionId = 11675, und
        // genau diese Id fuehrt workAreas als "Hausbereich".
        $mission = null;
        $activeWaId = $sr !== null ? $this->first($sr, ['workAreaId', 'mowerStatus.workAreaId', 'missionId']) : null;
        if ($activeWaId === null && $rawRobot !== null) {
            $activeWaId = $this->first($rawRobot, ['status.workAreaId', 'status.missionId']);
        }
        if ($activeWaId !== null) {
            foreach ($workAreas as $wa) {
                if ((string) $wa['id'] === (string) $activeWaId && $wa['name'] !== null) {
                    $mission = $wa['name'];
                    break;
                }
            }
            if ($mission === null) {
                $misList = $this->first($rawMissions, ['missions', 'data.attributes.missions']);
                if (is_array($misList)) {
                    foreach ($misList as $mi) {
                        if ((string) $this->first($mi, ['missionId', 'areaId', 'id']) === (string) $activeWaId) {
                            $mission = $this->first($mi, ['name']);
                            break;
                        }
                    }
                }
            }
        }
        // Fortschritt GENAU des aktiven Bereichs. Er steht in workAreas, die Zuordnung
        // aber nur ueber die Id von oben - deshalb hier und nicht beim Einlesen.
        $missionProgress = null;
        if ($activeWaId !== null) {
            foreach ($workAreas as $wa) {
                if ((string) $wa['id'] === (string) $activeWaId && ($wa['progress'] ?? null) !== null) {
                    $missionProgress = (int) $wa['progress'];
                    break;
                }
            }
        }
        if ($mission === null && $mode !== null) {
            $mission = $mode;
        }

        // ---- letzter Aktualisierungszeitpunkt ----------------------------
        $luRaw = $sr !== null ? $this->first($sr, ['statusTimestamp', 'metadata.statusTimestamp']) : null;
        $lastUpdate = ($luRaw !== null && $luRaw > 0) ? (int) $luRaw : time();

        // ---- normalisiertes Schema (Keys exakt) --------------------------
        return [
            'activity'          => $activity,
            'activityText'      => $activityText,
            'state'             => $state,
            'stateText'         => $stateText,
            'mode'              => $mode,
            'battery'           => $battery,
            'online'            => $online,
            'inChargingStation' => $inChargingStation,
            'errorCode'         => $errorCode,
            'errorText'         => $errorText,
            'nextStart'         => $nextStart,
            'nextStartText'     => $nextStartText,
            'runningTime'       => $runningTime,
            'cuttingTime'       => $cuttingTime,
            'chargingTime'      => $chargingTime,
            'searchTime'        => $searchTime,
            'collisions'        => $collisions,
            'chargingCycles'    => $chargingCycles,
            'bladeHours'        => $bladeHours,
            'searchHours'       => $searchHours,
            'bladeUsagePct'     => $bladeUsagePct,
            'efficiencyPct'     => $efficiencyPct,
            'cuttingHeight'     => $cuttingHeight,
            'headlight'         => $headlight,
            'model'             => ($model !== null) ? (string) $model : null,
            'firmware'          => ($firmware !== null) ? (string) $firmware : null,
            'updateRequired'    => $updateRequired,
            'mission'           => ($mission !== null) ? (string) $mission : null,
            'missionAreaId'     => ($activeWaId !== null) ? (string) $activeWaId : null,
            'missionProgress'   => $missionProgress,
            'lat'               => $lat,
            'lng'               => $lng,
            'positions'         => $positions,
            'geofence'          => $geofence,
            'messages'          => $messages,
            'workAreas'         => $workAreas,
            'stayOutZones'      => $stayOutZones,
            'timers'            => $timers,
            'lastUpdate'        => $lastUpdate,
        ];
    }

    /** Extrahiert die rohe Timer-Liste aus verschiedenen Antwortformen. */
    private function timerList($rawTimers): array
    {
        $tmList = $this->first($rawTimers, ['timers', 'data.attributes.timers']);
        if ($tmList === null && is_array($rawTimers)) {
            $tmList = $rawTimers;
        }
        return is_array($tmList) ? $tmList : [];
    }

    /**
     * Normalisiert eine rohe Timer-Liste in [{start,duration,days{...},missionId}].
     * @param array $list
     * @return array<int,array>
     */
    private function normalizeTimers(array $list): array
    {
        $out = [];
        foreach ($list as $t) {
            $out[] = $this->timerToArr($t);
        }
        return $out;
    }

    /** Ein roher Timer -> normalisiertes Array [{start,duration,days{...},missionId}]. */
    private function timerToArr($t): array
    {
        $dsrc = $this->g($t, 'days');
        if ($dsrc === null) {
            $dsrc = $t;
        }
        $days = [
            'monday'    => (bool) $this->first($dsrc, ['monday'], false),
            'tuesday'   => (bool) $this->first($dsrc, ['tuesday'], false),
            'wednesday' => (bool) $this->first($dsrc, ['wednesday'], false),
            'thursday'  => (bool) $this->first($dsrc, ['thursday'], false),
            'friday'    => (bool) $this->first($dsrc, ['friday'], false),
            'saturday'  => (bool) $this->first($dsrc, ['saturday'], false),
            'sunday'    => (bool) $this->first($dsrc, ['sunday'], false),
        ];
        $mid = $this->first($t, ['missionId', 'workAreaId']);
        return [
            'start'     => ($v = $this->g($t, 'start'))    !== null ? (int) $v : null,
            'duration'  => ($v = $this->g($t, 'duration')) !== null ? (int) $v : null,
            'days'      => $days,
            'missionId' => ($mid !== null) ? (string) $mid : null,
        ];
    }

    /** Vereinheitlicht einen (Soll-)Timer-Eintrag auf das interne Format. */
    private function normalizeTimerEntry(array $t): array
    {
        // Tage koennen als Objekt/Array unter 'days' ODER flach als Flags kommen.
        $dsrc = $t['days'] ?? $t;
        $days = [
            'monday'    => (bool) $this->first($dsrc, ['monday'], false),
            'tuesday'   => (bool) $this->first($dsrc, ['tuesday'], false),
            'wednesday' => (bool) $this->first($dsrc, ['wednesday'], false),
            'thursday'  => (bool) $this->first($dsrc, ['thursday'], false),
            'friday'    => (bool) $this->first($dsrc, ['friday'], false),
            'saturday'  => (bool) $this->first($dsrc, ['saturday'], false),
            'sunday'    => (bool) $this->first($dsrc, ['sunday'], false),
        ];
        $mid = $t['missionId'] ?? ($t['workAreaId'] ?? null);
        return [
            'start'     => isset($t['start']) ? (int) $t['start'] : 0,
            'duration'  => isset($t['duration']) ? (int) $t['duration'] : 0,
            'days'      => $days,
            'missionId' => ($mid !== null) ? (string) $mid : null,
        ];
    }

    /** Eindeutige Signatur eines Timers (fuer Diff). */
    private function timerSig(array $t): string
    {
        $days = $t['days'] ?? [];
        $d = '';
        foreach (['monday','tuesday','wednesday','thursday','friday','saturday','sunday'] as $k) {
            $d .= (!empty($days[$k]) ? '1' : '0');
        }
        return ((int) ($t['start'] ?? 0)) . ':' . ((int) ($t['duration'] ?? 0)) . ':' . $d . ':' . (string) ($t['missionId'] ?? '');
    }

    /* ================= Fehlercode-Tabelle ================= */

    /** @return array<int,string> Fehlercode -> deutscher Text. */
    private static function errorCodes(): array
    {
        return [
            0 => 'Keine Meldung', 1 => 'Außerhalb des Arbeitsbereichs', 2 => 'Kein Schleifensignal',
            3 => 'Falsches Schleifensignal', 4 => 'Schleifensensorproblem, vorne',
            5 => 'Schleifensensorproblem, hinten', 6 => 'Schleifensensorproblem, links',
            7 => 'Schleifensensorproblem, rechts', 8 => 'Falscher PIN-Code', 9 => 'Eingeklemmt',
            10 => 'Auf den Kopf gestellt', 11 => 'Schwache Batterie', 12 => 'Leerer Akku',
            13 => 'Kein Antrieb', 14 => 'Mäher angehoben', 15 => 'Angehoben',
            16 => 'Festgefahren in der Ladestation', 17 => 'Ladestation blockiert',
            18 => 'Problem mit dem Auffahrsensor, hinten', 19 => 'Problem mit dem Auffahrsensor, vorne',
            20 => 'Radmotor blockiert, rechts', 21 => 'Radmotor blockiert, links',
            22 => 'Problem mit dem Radantrieb, rechts', 23 => 'Problem mit dem Radantrieb, links',
            24 => 'Schneidsystem blockiert', 25 => 'Schneidesystem blockiert',
            26 => 'Ungültige Untergerätekombination', 27 => 'Einstellungen wiederhergestellt',
            28 => 'Problem mit der Speicherschaltung', 29 => 'Steigung zu steil',
            30 => 'Problem mit dem Ladesystem', 31 => 'Problem mit der STOP-Taste',
            32 => 'Problem mit dem Neigungssensor', 33 => 'Mäher gekippt',
            34 => 'Mähen gestoppt - Hang zu steil', 35 => 'Radmotor überlastet, rechts',
            36 => 'Radmotor überlastet, links', 37 => 'Ladestrom zu hoch', 38 => 'Elektronikproblem',
            39 => 'Problem mit dem Schneidmotor', 40 => 'Eingeschränkter Schnitthöhenbereich',
            41 => 'Unerwartete Schnitthöheneinstellung', 42 => 'Begrenzter Schnitthöhenbereich',
            43 => 'Problem mit der Schnitthöhe, Antrieb', 44 => 'Schnitthöhenproblem, Strom',
            45 => 'Schnitthöhenproblem, dir', 46 => 'Schnitthöhe blockiert',
            47 => 'Problem mit der Schnitthöhe', 48 => 'Keine Reaktion vom Ladegerät',
            49 => 'Ultraschallproblem', 50 => 'Führung 1 nicht gefunden', 51 => 'Führung 2 nicht gefunden',
            52 => 'Führung 3 nicht gefunden', 53 => 'GPS-Navigationsproblem', 54 => 'Schwaches GPS-Signal',
            55 => 'Schwierigkeiten, nach Hause zu finden', 56 => 'Leitfaden-Kalibrierung durchgeführt',
            57 => 'Leitfaden-Kalibrierung fehlgeschlagen', 58 => 'Vorübergehendes Batterieproblem',
            59 => 'Vorübergehendes Batterieproblem', 60 => 'Vorübergehendes Batterieproblem',
            61 => 'Vorübergehendes Batterieproblem', 62 => 'Vorübergehendes Batterieproblem',
            63 => 'Vorübergehendes Batterieproblem', 64 => 'Vorübergehendes Batterieproblem',
            65 => 'Vorübergehendes Batterieproblem', 66 => 'Batterieproblem', 67 => 'Batterieproblem',
            68 => 'Vorübergehendes Batterieproblem', 69 => 'Alarm! Mäher ausgeschaltet',
            70 => 'Alarm! Mäher gestoppt', 71 => 'Alarm! Mäher angehoben', 72 => 'Alarm! Mäher gekippt',
            73 => 'Alarm! Mäher in Bewegung', 74 => 'Alarm! Außerhalb des Geofence',
            75 => 'Verbindung geändert', 76 => 'Verbindung NICHT geändert',
            77 => 'Com-Board nicht verfügbar',
            78 => 'Verrutscht - Mäher ist verrutscht, Situation nicht mit Bewegungsmuster gelöst',
            79 => 'Ungültige Batteriekombination - Ungültige Kombination von verschiedenen Batterietypen.',
            80 => 'Ungleichgewicht des Mähsystems - Warnung', 81 => 'Sicherheitsfunktion defekt',
            82 => 'Radmotor blockiert, hinten rechts', 83 => 'Radmotor blockiert, hinten links',
            84 => 'Radantriebsproblem, hinten rechts', 85 => 'Radantriebsstörung, hinten links',
            86 => 'Radmotor überlastet, hinten rechts', 87 => 'Radmotor überlastet, hinten links',
            88 => 'Winkelsensorproblem', 89 => 'Ungültige Systemkonfiguration',
            90 => 'Kein Strom in der Ladestation', 91 => 'Problem mit dem Schaltkabel',
            92 => 'Arbeitsbereich nicht gültig', 93 => 'Keine genaue Position von den Satelliten',
            94 => 'Kommunikationsproblem mit der Referenzstation', 95 => 'Klappsensor aktiviert',
            96 => 'Rechter Bürstenmotor überlastet', 97 => 'Linker Bürstenmotor überlastet',
            98 => 'Ultraschall-Sensor 1 defekt', 99 => 'Ultraschall-Sensor 2 defekt',
            100 => 'Ultraschallsensor 3 defekt', 101 => 'Ultraschallsensor 4 defekt',
            102 => 'Schneidantriebsmotor 1 defekt', 103 => 'Schneideantriebsmotor 2 defekt',
            104 => 'Schneidantriebsmotor 3 defekt', 105 => 'Hebesensor defekt',
            106 => 'Kollisionssensor defekt', 107 => 'Andocksensor defekt',
            108 => 'Sensor für klappbares Schneidwerk defekt', 109 => 'Schleifensensor defekt',
            110 => 'Kollisionssensor defekt', 111 => 'Keine bestätigte Position',
            112 => 'Hauptunwucht des Schneidsystems', 113 => 'Komplexer Arbeitsbereich',
            114 => 'Zu hoher Entladestrom', 115 => 'Zu hoher interner Strom',
            116 => 'Hohe Ladeverlustleistung', 117 => 'Hohe interne Verlustleistung',
            118 => 'Problem mit dem Ladesystem', 119 => 'Problem mit dem Zonengenerator',
            120 => 'Interner Spannungsfehler', 121 => 'Hohe interne Temperatur', 122 => 'CAN-Fehler',
            123 => 'Ziel nicht erreichbar', 124 => 'Ziel blockiert',
            125 => 'Batterie muss ausgetauscht werden',
            126 => 'Batterie hat bald das Ende ihrer Lebensdauer erreicht', 127 => 'Batterieproblem',
            128 => 'Mehrere Referenzstationen erkannt', 129 => 'Hilfsschneidmittel blockiert',
            130 => 'Unwucht der Hilfstrennscheibe erkannt', 131 => 'Angehobener Gelenkarm',
            132 => 'EPOS-Zubehör fehlt', 133 => 'Bluetooth-Verbindung mit CS fehlgeschlagen',
            134 => 'Ungültige SW-Konfiguration', 135 => 'Radarproblem', 136 => 'Arbeitsbereich manipuliert',
            137 => 'Hohe Temperatur im Schneidemotor, rechts', 138 => 'Hohe Temperatur im Schneidemotor, Mitte',
            139 => 'Hohe Temperatur im Schneidemotor, links', 141 => 'Problem mit dem Radbürstenmotor',
            143 => 'Problem mit der Stromversorgung des Zubehörs', 144 => 'Problem mit dem Begrenzungsdraht',
            701 => 'Konnektivitätsproblem', 702 => 'Konnektivitätseinstellungen wiederhergestellt',
            703 => 'Konnektivitätsproblem', 704 => 'Konnektivitätsproblem', 705 => 'Konnektivitätsproblem',
            706 => 'Schlechte Signalqualität', 707 => 'SIM-Karte erfordert PIN', 708 => 'SIM-Karte gesperrt',
            709 => 'SIM-Karte nicht gefunden', 710 => 'SIM-Karte gesperrt', 711 => 'SIM-Karte gesperrt',
            712 => 'SIM-Karte gesperrt', 713 => 'Geofence-Problem', 714 => 'Geofence-Problem',
            715 => 'Konnektivitätsproblem', 716 => 'Konnektivitätsproblem',
            717 => 'SMS konnte nicht gesendet werden',
            724 => 'Kommunikationsplatine SW muss aktualisiert werden',
        ];
    }
}

// Self-Registrierung bei der Factory (wie die nativen Codecs).
DriverFactory::register('husqvarna-app', HusqvarnaAppApi::class);

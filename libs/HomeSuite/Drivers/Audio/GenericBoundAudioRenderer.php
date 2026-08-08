<?php

declare(strict_types=1);

namespace Hoep\HomeSuite\HAL;

/**
 * GenericBoundAudioRenderer — generischer, VENDOR-FREIER Audio-Treiber.
 *
 * Baugleich zum GenericVariableValve (kernel-frei, zustandslos): jede Audio-Funktion
 * bindet an eine VARIABLE ODER ein SKRIPT. Schreiben ueber RequestAction (falls die
 * Variable eine Aktion hat), sonst SetValue; Lesen ueber GetValue. Kein Socket, kein
 * sleep, keine Session (Grundsatz B1) — Push gibt es hier nicht (poll()/parseEvent()
 * sind leer; der Zustand kommt ueber readState(), daher IAudioStateReadable).
 *
 * Damit ist Sonos im Uebergang ueber die vorhandenen IPSSonos-Raum-Dummy-Variablen
 * bindbar und spaeter ohne Code-Aenderung auf einen nativen Treiber (sonos-upnp/heos)
 * umstellbar. "Power" ist bewusst KEINE Codec-Methode, sondern eine gebundene
 * Variable/Skript (Play/Stop ODER echte Steckdose).
 *
 * bind()-config (Auszug):
 *   bind.transport {varId, map:{play,pause,stop,next,prev}} ODER scriptPlay/scriptPause/...
 *   bind.volume    {varId}
 *   bind.mute      {varId, invert?}
 *   bind.power     {varId} ODER {scriptOn,scriptOff}   (Play/Stop oder Steckdose)
 *   bind.repeat    {varId, map:{off,one,all}}
 *   bind.shuffle   {varId}
 *   bind.position  {varId}                              (POSITIONP %, 0..100)
 *   bind.input     {varId}                              (optionaler Eingangswahl-Wert)
 *   bind.source    {favorite:{varId}, radio:{varId}, playlist:{varId}}
 *   reflect        {title,artist,album,albumArtist,coverUri,positionTime,duration,
 *                   playState,volume,mute,repeat,shuffle,rincon,ip,online,source}  (je varId)
 *   group          {mode:'script'|'bool', scriptId?, masterVarId?, slaveVarId?,
 *                   rinconVarId?, masterRinconVarId?, masterNameVarId?}
 *   caps           {seek?,announce?,grouping?,queue?,tone?,sleepTimer?}  (Overrides)
 */
final class GenericBoundAudioRenderer implements IAudioRenderer, IAudioStateReadable
{
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

    // ---- IDriver -------------------------------------------------------------

    public function capabilities(): AudioCapabilities
    {
        $bind = (array) ($this->cfg['bind'] ?? []);
        $caps = (array) ($this->cfg['caps'] ?? []);
        $sources = [];
        foreach ((array) ($bind['source'] ?? []) as $k => $v) {
            if (is_array($v) && (int) ($v['varId'] ?? 0) > 0) {
                // 'radio' entspricht der Quellen-Art 'station'
                $sources[] = ($k === 'radio') ? 'station' : (string) $k;
            }
        }
        return new AudioCapabilities(
            true,
            (bool) ($caps['seek'] ?? isset($bind['position'])),
            (bool) ($caps['announce'] ?? false),
            (bool) ($caps['grouping'] ?? isset($this->cfg['group'])),
            $sources,
            (bool) ($caps['queue'] ?? false),
            (bool) ($caps['tone'] ?? false),
            (bool) ($caps['sleepTimer'] ?? false)
        );
    }

    public static function discover(int $timeoutMs = 2000): array
    {
        return [];
    }

    public function poll(): array
    {
        return []; // variablengebunden — kein Frame; Zustand via readState()
    }

    public function parseEvent(string $raw): ?AudioState
    {
        return null; // kein Push-Strom
    }

    // ---- Transport -----------------------------------------------------------

    public function play(): void
    {
        $this->transport('play', 'scriptPlay');
    }

    public function pause(): void
    {
        $this->transport('pause', 'scriptPause');
    }

    public function stop(): void
    {
        $this->transport('stop', 'scriptStop');
    }

    public function next(): void
    {
        $this->transport('next', 'scriptNext');
    }

    public function previous(): void
    {
        $this->transport('prev', 'scriptPrev');
    }

    /** Transport-Kommando: Variable mit gemapptem Code ODER Skript. */
    private function transport(string $cmd, string $scriptKey): void
    {
        $t = (array) ($this->bindOf('transport'));
        $sid = (int) ($t[$scriptKey] ?? 0);
        if ($sid > 0) {
            $this->runScript($sid);
            return;
        }
        $vid = (int) ($t['varId'] ?? 0);
        if ($vid <= 0) {
            $this->log('transport: keine varId/Skript fuer ' . $cmd);
            return;
        }
        $map = (array) ($t['map'] ?? ['play' => 1, 'pause' => 2, 'stop' => 3, 'next' => 4, 'prev' => 5]);
        if (!array_key_exists($cmd, $map)) {
            $this->log('transport: kein Mapping fuer ' . $cmd);
            return;
        }
        $this->writeVar($vid, $map[$cmd]);
    }

    public function seek(int $sec): void
    {
        $p = (array) $this->bindOf('position');
        $vid = (int) ($p['varId'] ?? 0);
        if ($vid <= 0) {
            $this->log('seek: keine position-varId');
            return;
        }
        // POSITIONP ist Prozent — aus Sekunden ueber die reflektierte Dauer umrechnen.
        $dur = $this->readStateDurationSec();
        $pct = ($dur > 0) ? (int) round(max(0, min($sec, $dur)) / $dur * 100) : 0;
        $this->writeVar($vid, $pct);
    }

    // ---- Lautstaerke ---------------------------------------------------------

    public function setVolume(int $pct): void
    {
        $pct = max(0, min(100, $pct));
        $vid = (int) (((array) $this->bindOf('volume'))['varId'] ?? 0);
        if ($vid <= 0) {
            $this->log('setVolume: keine volume-varId');
            return;
        }
        $this->writeVar($vid, $pct);
    }

    public function setMute(bool $on): void
    {
        $m = (array) $this->bindOf('mute');
        $vid = (int) ($m['varId'] ?? 0);
        if ($vid <= 0) {
            $this->log('setMute: keine mute-varId');
            return;
        }
        $val = (bool) ($m['invert'] ?? false) ? !$on : $on;
        $this->writeVar($vid, $val);
    }

    // ---- Quelle / Inhalt -----------------------------------------------------

    public function selectInput(string $inputId): void
    {
        $vid = (int) (((array) $this->bindOf('input'))['varId'] ?? 0);
        if ($vid <= 0) {
            $this->log('selectInput: keine input-varId');
            return;
        }
        $this->writeVar($vid, is_numeric($inputId) ? (int) $inputId : $inputId);
    }

    public function playSource(AudioSourceRef $ref): void
    {
        // 'station' bindet auf die 'radio'-Variable (IPSSonos RADIOSTATION).
        $key = ($ref->kind === AudioSourceRef::KIND_STATION) ? 'radio' : $ref->kind;
        $src = (array) ($this->bindOf('source'));
        $vid = (int) (((array) ($src[$key] ?? []))['varId'] ?? 0);
        if ($vid <= 0) {
            $this->log('playSource: keine Quelle-varId fuer ' . $ref->kind);
            return;
        }
        $this->writeVar($vid, is_numeric($ref->id) ? (int) $ref->id : $ref->id);
    }

    public function playAnnouncement(string $uri, int $volume = 0): void
    {
        // Generisch nicht unterstuetzt (CAP_ANNOUNCE=false); nativer Treiber implementiert das.
        $this->log('playAnnouncement im generic-audio-Treiber nicht unterstuetzt');
    }

    public function setPlayMode(int $repeat, bool $shuffle): void
    {
        $r = (array) $this->bindOf('repeat');
        $rid = (int) ($r['varId'] ?? 0);
        if ($rid > 0) {
            $map = (array) ($r['map'] ?? [AudioState::REPEAT_OFF => 0, AudioState::REPEAT_ONE => 1, AudioState::REPEAT_ALL => 2]);
            $this->writeVar($rid, $map[$repeat] ?? $repeat);
        }
        $sid = (int) (((array) $this->bindOf('shuffle'))['varId'] ?? 0);
        if ($sid > 0) {
            $this->writeVar($sid, $shuffle);
        }
    }

    public function listFavorites(): array
    {
        return []; // Quellen liefert die LVB-Seite aus den Profil-Associations (?api=assoc)
    }

    public function listPlaylists(): array
    {
        return [];
    }

    public function browse(string $containerId, int $offset, int $limit): AudioBrowseResult
    {
        return new AudioBrowseResult([], 0, $offset);
    }

    // ---- Gruppierung (deklarativ, B2) ---------------------------------------

    public function setGroupMembers(string $coordinatorUid, array $memberUids): void
    {
        $g = (array) ($this->cfg['group'] ?? []);
        if ($g === []) {
            $this->log('setGroupMembers: keine group-Bindung');
            return;
        }
        $self = $this->readVarStr((int) ($g['rinconVarId'] ?? 0));
        $isCoordinator = ($self !== '' && $self === $coordinatorUid);

        if (($g['mode'] ?? 'script') === 'bool') {
            if ((int) ($g['masterVarId'] ?? 0) > 0) {
                $this->writeVar((int) $g['masterVarId'], $isCoordinator);
            }
            if ((int) ($g['slaveVarId'] ?? 0) > 0) {
                $this->writeVar((int) $g['slaveVarId'], !$isCoordinator && in_array($self, $memberUids, true));
            }
            return;
        }

        // mode 'script' (IPSSonos ChangeMasterSlave): Master-RINCON/-Name setzen, dann Skript.
        if (!$isCoordinator && (int) ($g['masterRinconVarId'] ?? 0) > 0) {
            $this->writeVar((int) $g['masterRinconVarId'], $coordinatorUid);
        }
        $sid = (int) ($g['scriptId'] ?? 0);
        if ($sid > 0) {
            $this->runScript($sid);
        }
    }

    public function setGroupVolume(int $pct): void
    {
        // Adressiert den Koordinator; generisch fehlt eine dedizierte Gruppen-Var ->
        // auf die eigene Volume-Variable schreiben (Modul faechert bei Bedarf auf).
        $vid = (int) (((array) ($this->cfg['group'] ?? []))['volumeVarId'] ?? 0);
        if ($vid > 0) {
            $this->writeVar($vid, max(0, min(100, $pct)));
            return;
        }
        $this->setVolume($pct);
    }

    // ---- IAudioStateReadable -------------------------------------------------

    public function readState(): AudioState
    {
        $r = (array) ($this->cfg['reflect'] ?? []);
        $repeatRaw = $this->readVar((int) ($r['repeat'] ?? 0));
        return new AudioState(
            $this->readBool($r['playState'] ?? 0),
            $this->readVarStr((int) ($r['title'] ?? 0)),
            $this->readVarStr((int) ($r['artist'] ?? 0)),
            $this->readVarStr((int) ($r['album'] ?? 0)),
            $this->readVarStr((int) ($r['albumArtist'] ?? 0)),
            $this->readVarStr((int) ($r['coverUri'] ?? 0)),
            $this->timeToSec($this->readVarStr((int) ($r['positionTime'] ?? 0))),
            $this->timeToSec($this->readVarStr((int) ($r['duration'] ?? 0))),
            (int) $this->readNum($r['volume'] ?? 0),
            $this->readBool($r['mute'] ?? 0),
            is_bool($repeatRaw) ? ($repeatRaw ? AudioState::REPEAT_ALL : AudioState::REPEAT_OFF) : (int) $repeatRaw,
            $this->readBool($r['shuffle'] ?? 0),
            (bool) ($this->cfg['caps']['seek'] ?? isset($this->cfg['bind']['position'])),
            (int) ($r['online'] ?? 0) > 0 ? $this->readBool($r['online']) : true,
            $this->readVarStr((int) ($r['source'] ?? 0))
        );
    }

    public function readGroup(): array
    {
        $g = (array) ($this->cfg['group'] ?? []);
        $self = $this->readVarStr((int) ($g['rinconVarId'] ?? 0));
        $master = $this->readVarStr((int) ($g['masterRinconVarId'] ?? 0));
        $role = 'standalone';
        if ($master !== '' && $self !== '') {
            $role = ($master === $self) ? 'coordinator' : 'member';
        }
        return [
            'role'           => $role,
            'coordinatorUid' => ($master !== '' ? $master : $self),
            'memberUids'     => ($self !== '' ? [$self] : []),
        ];
    }

    // ---- Helfer (analog GenericVariableValve) --------------------------------

    private function bindOf(string $field)
    {
        $bind = (array) ($this->cfg['bind'] ?? []);
        return $bind[$field] ?? [];
    }

    private function readStateDurationSec(): int
    {
        $r = (array) ($this->cfg['reflect'] ?? []);
        return $this->timeToSec($this->readVarStr((int) ($r['duration'] ?? 0)));
    }

    /** Schreibt einen Wert per RequestAction (falls actionable), sonst SetValue. */
    private function writeVar(int $vid, $value): bool
    {
        if ($vid <= 0) {
            return false;
        }
        try {
            if ($this->isActionable($vid) && function_exists('RequestAction')) {
                @\RequestAction($vid, $value);
                return true;
            }
            if (function_exists('SetValue')) {
                @\SetValue($vid, $value);
                return true;
            }
        } catch (\Throwable $e) {
            $this->log('writeVar #' . $vid . ': ' . $e->getMessage());
        }
        return false;
    }

    private function runScript(int $sid): bool
    {
        if ($sid <= 0 || !function_exists('IPS_RunScript') || !@\IPS_ScriptExists($sid)) {
            $this->log('runScript: Skript #' . $sid . ' fehlt');
            return false;
        }
        try {
            @\IPS_RunScript($sid);
            return true;
        } catch (\Throwable $e) {
            $this->log('runScript #' . $sid . ': ' . $e->getMessage());
            return false;
        }
    }

    private function isActionable(int $vid): bool
    {
        if (!function_exists('IPS_GetVariable')) {
            return false;
        }
        $v = @\IPS_GetVariable($vid);
        if (!is_array($v)) {
            return false;
        }
        return (int) ($v['VariableAction'] ?? 0) > 0 || (int) ($v['VariableCustomAction'] ?? 0) > 0;
    }

    private function readVar(int $vid)
    {
        if ($vid <= 0 || !function_exists('GetValue')) {
            return null;
        }
        return @\GetValue($vid);
    }

    private function readVarStr(int $vid): string
    {
        $v = $this->readVar($vid);
        return ($v === null) ? '' : (string) $v;
    }

    private function readNum($vid): float
    {
        $v = $this->readVar((int) $vid);
        return is_numeric($v) ? (float) $v : 0.0;
    }

    private function readBool($vid): bool
    {
        $v = $this->readVar((int) $vid);
        if (is_bool($v)) {
            return $v;
        }
        return is_numeric($v) ? ((float) $v != 0.0) : false;
    }

    /** "mm:ss" oder "hh:mm:ss" -> Sekunden. Leerstring -> 0. */
    private function timeToSec(string $t): int
    {
        $t = trim($t);
        if ($t === '') {
            return 0;
        }
        $p = array_map('intval', explode(':', $t));
        $n = count($p);
        if ($n === 3) {
            return $p[0] * 3600 + $p[1] * 60 + $p[2];
        }
        if ($n === 2) {
            return $p[0] * 60 + $p[1];
        }
        return (int) $p[0];
    }

    private function log(string $msg): void
    {
        if (function_exists('IPS_LogMessage')) {
            @\IPS_LogMessage('HS.GenAudio', $msg);
        }
    }
}

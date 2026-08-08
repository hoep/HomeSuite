<?php

declare(strict_types=1);

namespace Hoep\HomeSuite\HAL;

/**
 * Heos — Denon/Marantz HEOS-Treiber (CLI-Protokoll, Push). Implementiert IAudioRenderer.
 *
 * HEOS ist ein PUSH-Protokoll: ein persistenter TCP-Socket (Port 1255) liefert
 * Event-/Antwort-Frames. Diesen Socket haelt die Bridge (Splitter HSBH), NICHT der
 * Treiber (Grundsatz B1: zustandslos). Der Treiber baut nur Kommando-Frames (per $send
 * rausgeschoben) und uebersetzt eingehende JSON-Frames via parseEvent() in AudioState.
 *
 * Damit ist HEOS ohne Aenderung an AudioZone/Manifest integrierbar (gleiche Controls,
 * anderer Treiber). Command-Frames sind HEOS-CLI-Zeilen "heos://...\r\n".
 *
 * bind()-config:
 *   pid  string|int  HEOS Player-ID (aus players/get_players)
 *
 * HINWEIS: Die Bridge-Instanz (HSBH) + das Kind AudioZoneBridged (HSAUX) werden erst
 * angelegt, wenn HEOS-Hardware vorhanden ist. Dieser Codec ist eigenstaendig ladbar
 * und getestet (parseEvent).
 */
final class Heos implements IAudioRenderer
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

    private function pid(): string
    {
        return (string) ($this->cfg['pid'] ?? '');
    }

    private function cmd(string $path): void
    {
        ($this->send)('heos://' . $path . "\r\n");
    }

    // ---- IDriver -------------------------------------------------------------

    public function capabilities(): AudioCapabilities
    {
        return new AudioCapabilities(true, false, false, true, ['preset', 'station', 'playlist'], true, false, false);
    }

    public static function discover(int $timeoutMs = 2000): array
    {
        return []; // Discovery macht die Bridge ueber SSDP/players_get_players
    }

    /** Snapshot-Requests, die die Bridge sendet (HEOS liefert die Antworten als Frames). */
    public function poll(): array
    {
        $p = $this->pid();
        if ($p === '') {
            return [];
        }
        return [
            'heos://player/get_play_state?pid=' . $p . "\r\n",
            'heos://player/get_now_playing_media?pid=' . $p . "\r\n",
            'heos://player/get_volume?pid=' . $p . "\r\n",
            'heos://player/get_mute?pid=' . $p . "\r\n",
        ];
    }

    /**
     * PURE: HEOS-JSON-Frame -> AudioState-Delta (nicht gesetzte Felder bleiben Default),
     * oder null wenn irrelevant/andere pid.
     */
    public function parseEvent(string $raw): ?AudioState
    {
        $j = json_decode(trim($raw), true);
        if (!is_array($j) || !isset($j['heos']['command'])) {
            return null;
        }
        $cmd = (string) $j['heos']['command'];
        $pl  = (isset($j['payload']) && is_array($j['payload'])) ? $j['payload'] : [];
        // Message-String "pid=X&..." parsen (Events tragen die Nutzdaten dort).
        parse_str(str_replace('&', '&', (string) ($j['heos']['message'] ?? '')), $msg);

        // Fremde pid ignorieren, wenn eine gebunden ist.
        $mine = $this->pid();
        if ($mine !== '' && isset($msg['pid']) && (string) $msg['pid'] !== $mine) {
            return null;
        }

        $st = new AudioState();
        switch ($cmd) {
            case 'player/get_now_playing_media':
                $st->title       = (string) ($pl['song'] ?? '');
                $st->artist      = (string) ($pl['artist'] ?? '');
                $st->album       = (string) ($pl['album'] ?? '');
                $st->coverUri    = (string) ($pl['image_url'] ?? '');
                $st->source      = (string) ($pl['type'] ?? '');
                return $st;
            case 'player/get_play_state':
            case 'event/player_state_changed':
                $state = (string) ($msg['state'] ?? ($pl['state'] ?? ''));
                $st->playState = ($state === 'play');
                return $st;
            case 'player/get_volume':
            case 'event/player_volume_changed':
                if (isset($msg['level'])) {
                    $st->volume = (int) $msg['level'];
                }
                if (isset($msg['mute'])) {
                    $st->mute = ((string) $msg['mute'] === 'on');
                }
                return $st;
            case 'player/get_mute':
                $st->mute = ((string) ($msg['state'] ?? '') === 'on');
                return $st;
            case 'event/player_now_playing_progress':
                $st->positionSec = (int) round(((int) ($msg['cur_pos'] ?? 0)) / 1000);
                $st->durationSec = (int) round(((int) ($msg['duration'] ?? 0)) / 1000);
                return $st;
        }
        return null;
    }

    // ---- Transport -----------------------------------------------------------

    public function play(): void
    {
        $this->cmd('player/set_play_state?pid=' . $this->pid() . '&state=play');
    }

    public function pause(): void
    {
        $this->cmd('player/set_play_state?pid=' . $this->pid() . '&state=pause');
    }

    public function stop(): void
    {
        $this->cmd('player/set_play_state?pid=' . $this->pid() . '&state=stop');
    }

    public function next(): void
    {
        $this->cmd('player/play_next?pid=' . $this->pid());
    }

    public function previous(): void
    {
        $this->cmd('player/play_previous?pid=' . $this->pid());
    }

    public function seek(int $sec): void
    {
        // HEOS-CLI kennt kein direktes Seek in v1 -> No-Op (capabilities.seek=false).
    }

    // ---- Lautstaerke ---------------------------------------------------------

    public function setVolume(int $pct): void
    {
        $this->cmd('player/set_volume?pid=' . $this->pid() . '&level=' . max(0, min(100, $pct)));
    }

    public function setMute(bool $on): void
    {
        $this->cmd('player/set_mute?pid=' . $this->pid() . '&state=' . ($on ? 'on' : 'off'));
    }

    // ---- Quelle / Inhalt -----------------------------------------------------

    public function selectInput(string $inputId): void
    {
        $this->cmd('browse/play_input?pid=' . $this->pid() . '&input=' . rawurlencode($inputId));
    }

    public function playSource(AudioSourceRef $ref): void
    {
        if ($ref->kind === AudioSourceRef::KIND_PRESET) {
            $this->cmd('browse/play_preset?pid=' . $this->pid() . '&preset=' . rawurlencode($ref->id));
        } elseif ($ref->kind === AudioSourceRef::KIND_STATION && $ref->uri !== '') {
            $this->cmd('browse/play_stream?pid=' . $this->pid() . '&url=' . rawurlencode($ref->uri));
        }
    }

    public function playAnnouncement(string $uri, int $volume = 0): void
    {
        if ($uri !== '') {
            $this->cmd('browse/play_stream?pid=' . $this->pid() . '&url=' . rawurlencode($uri));
        }
    }

    public function setPlayMode(int $repeat, bool $shuffle): void
    {
        $rep = $repeat === AudioState::REPEAT_ALL ? 'on_all'
            : ($repeat === AudioState::REPEAT_ONE ? 'on_one' : 'off');
        $this->cmd('player/set_play_mode?pid=' . $this->pid() . '&repeat=' . $rep . '&shuffle=' . ($shuffle ? 'on' : 'off'));
    }

    public function listFavorites(): array
    {
        return []; // ueber die Bridge (browse/get_music_sources) — Ausbaustufe
    }

    public function listPlaylists(): array
    {
        return [];
    }

    public function browse(string $containerId, int $offset, int $limit): AudioBrowseResult
    {
        return new AudioBrowseResult([], 0, $offset);
    }

    // ---- Gruppierung ---------------------------------------------------------

    public function setGroupMembers(string $coordinatorUid, array $memberUids): void
    {
        // HEOS: set_group?pid=leader,member1,member2  (Leader zuerst). Leere Liste -> aufloesen.
        $pids = array_values(array_unique(array_merge([$coordinatorUid], $memberUids)));
        $this->cmd('group/set_group?pid=' . implode(',', array_map('rawurlencode', $pids)));
    }

    public function setGroupVolume(int $pct): void
    {
        // Gruppen-Volume adressiert die Gruppen-ID (== Leader-pid).
        $this->cmd('group/set_volume?gid=' . $this->pid() . '&level=' . max(0, min(100, $pct)));
    }
}

// Selbst-Registrierung bei der DriverFactory.
DriverFactory::register('heos', Heos::class);

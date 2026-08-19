<?php

declare(strict_types=1);

namespace Hoep\HomeSuite\Media;

use Hoep\HomeSuite\Contracts\IMediaProvider;
use Hoep\HomeSuite\Contracts\IMediaWritable;
use Hoep\HomeSuite\Engines\MediaProviders;
use Hoep\HomeSuite\HAL\ContentRef;

/**
 * PlexProvider — self-hosted Plex Media Server (renderer-unabhaengig, direkte Stream-URLs).
 *
 * Auth ueber X-Plex-Token. Stream-/Cover-URLs tragen den Token als Query -> jeder Renderer
 * kann ohne Header spielen. JSON via Accept-Header.
 *
 * config: url (z. B. http://10.10.10.x:32400), token (X-Plex-Token)
 */
final class PlexProvider implements IMediaProvider, IMediaWritable
{
    private string $url;
    private string $tok;

    public function __construct(array $cfg)
    {
        $this->url = rtrim(trim((string) ($cfg['url'] ?? '')), '/');
        $this->tok = (string) ($cfg['token'] ?? '');
    }

    public function id(): string
    {
        return 'plex';
    }

    public function label(): string
    {
        return 'Plex';
    }

    public function isConfigured(): bool
    {
        return $this->url !== '' && $this->tok !== '';
    }

    /** Musik-Bibliotheken + Playlists als Wurzeln. */
    public function roots(): array
    {
        $out = [];
        $j = $this->get('/library/sections');
        foreach ((array) ($j['MediaContainer']['Directory'] ?? []) as $d) {
            if (($d['type'] ?? '') !== 'artist') {
                continue; // Musik-Sektion
            }
            $out[] = new ContentRef('plex', 'container', 'sec:' . $d['key'], '',
                (string) ($d['title'] ?? ''), '', '', '', '', 0, true);
        }
        $out[] = new ContentRef('plex', 'container', 'playlists', '', 'Playlists', '', '', '', '', 0, true);
        return $out;
    }

    public function browse(string $containerId, int $offset = 0, int $limit = 100): array
    {
        if ($containerId === 'playlists') {
            $j = $this->get('/playlists?playlistType=audio');
            return $this->mapMeta($j['MediaContainer']['Metadata'] ?? [], true);
        }
        if (strncmp($containerId, 'sec:', 4) === 0) {
            $j = $this->get('/library/sections/' . rawurlencode(substr($containerId, 4)) . '/all?type=9'); // 9=album
            // Alben/Hoerbuecher nach Interpret/Autor, dann Titel (statt nur Titel).
            return ContentRef::sortByArtistTitle($this->mapMeta($j['MediaContainer']['Metadata'] ?? [], true));
        }
        if (strncmp($containerId, 'key:', 4) === 0) {
            $j = $this->get(substr($containerId, 4)); // Container-Kinder (Album/Playlist-Items)
            return $this->mapMeta($j['MediaContainer']['Metadata'] ?? [], false);
        }
        return [];
    }

    /** @param array $meta @param bool $asContainer Alben/Playlists=Container, sonst Tracks */
    private function mapMeta(array $meta, bool $asContainer): array
    {
        $out = [];
        foreach ($meta as $m) {
            $isTrack = (($m['type'] ?? '') === 'track');
            if ($isTrack) {
                $part = $m['Media'][0]['Part'][0]['key'] ?? '';
                $out[] = new ContentRef('plex', 'url', 'track:' . ($m['ratingKey'] ?? ''),
                    $part !== '' ? $this->tokUrl($part) : '',
                    (string) ($m['title'] ?? ''), (string) ($m['grandparentTitle'] ?? ''),
                    (string) ($m['parentTitle'] ?? ''), $this->thumb($m['thumb'] ?? ''), 'audio/mpeg',
                    (int) round(((int) ($m['duration'] ?? 0)) / 1000), false);
            } else {
                // Album/Playlist -> Container, Kinder via key
                $childKey = (string) ($m['key'] ?? '');
                $out[] = new ContentRef('plex', 'container', 'key:' . $childKey, '',
                    (string) ($m['title'] ?? ''), (string) ($m['parentTitle'] ?? ($m['grandparentTitle'] ?? '')),
                    '', $this->thumb($m['thumb'] ?? ''), '', 0, true);
            }
        }
        return $out;
    }

    public function search(string $query, int $limit = 50): array
    {
        $j = $this->get('/search?query=' . rawurlencode($query));
        return $this->mapMeta($j['MediaContainer']['Metadata'] ?? [], false);
    }

    public function resolve(ContentRef $ref): ContentRef
    {
        if ($ref->kind === 'url' && $ref->uri !== '') {
            return $ref;
        }
        if (strncmp($ref->id, 'key:', 4) === 0) {
            $kids = $this->browse($ref->id, 0, 1);
            if ($kids) {
                return $this->resolve($kids[0]);
            }
        }
        return $ref;
    }

    private function tokUrl(string $path): string
    {
        $sep = (strpos($path, '?') !== false) ? '&' : '?';
        return $this->url . $path . $sep . 'X-Plex-Token=' . rawurlencode($this->tok);
    }

    private function thumb(string $path): string
    {
        return $path === '' ? '' : $this->tokUrl($path);
    }

    private function get(string $path): array
    {
        $url = $this->url . $path . (strpos($path, '?') !== false ? '&' : '?') . 'X-Plex-Token=' . rawurlencode($this->tok);
        $headers = ['Accept: application/json'];
        $r = '';
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8,
                CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_HTTPHEADER => $headers, CURLOPT_FOLLOWLOCATION => true]);
            $r = (string) curl_exec($ch);
            curl_close($ch);
        } else {
            $ctx = stream_context_create(['http' => ['timeout' => 8, 'header' => implode("\r\n", $headers), 'ignore_errors' => true]]);
            $r = (string) @file_get_contents($url, false, $ctx);
        }
        $j = json_decode($r, true);
        return is_array($j) ? $j : [];
    }

    // ==================================================================
    // Schreiben (IMediaWritable): Playlists im Plex-Server anlegen
    // ==================================================================

    /** @var string|null Maschinenkennung des Servers (fuer die server://-Verweise) */
    private ?string $mid = null;

    /**
     * Plex referenziert Inhalte beim Anlegen ueber server://<Maschinenkennung>/... — ohne
     * die geht es nicht, und sie steht nur in der Wurzelantwort des Servers.
     */
    private function machineId(): string
    {
        if ($this->mid !== null) {
            return $this->mid;
        }
        $j = $this->get('/');
        $this->mid = (string) ($j['MediaContainer']['machineIdentifier'] ?? '');
        return $this->mid;
    }

    /** ratingKeys der eigenen Titel aus den Verweisen ziehen (fremde ignorieren). */
    private function keysOf(array $refs): array
    {
        $keys = [];
        foreach ($refs as $r) {
            if (!$r instanceof ContentRef || $r->provider !== 'plex') {
                continue;
            }
            if (strncmp($r->id, 'track:', 6) === 0) {
                $k = substr($r->id, 6);
                if ($k !== '') {
                    $keys[] = $k;
                }
            }
        }
        return array_values(array_unique($keys));
    }

    /** Aufruf mit Methode (POST/PUT/DELETE) — get() kann nur lesen. */
    private function send(string $method, string $path): array
    {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'code' => 0];
        }
        $url = $this->url . $path . (strpos($path, '?') !== false ? '&' : '?')
             . 'X-Plex-Token=' . rawurlencode($this->tok);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $r = (string) curl_exec($ch);
        $c = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $j = json_decode($r, true);
        return ['ok' => ($c >= 200 && $c < 300), 'code' => $c, 'json' => is_array($j) ? $j : []];
    }

    public function createPlaylist(string $name, array $refs): ?ContentRef
    {
        $name = trim($name);
        $keys = $this->keysOf($refs);
        $mid  = $this->machineId();
        if ($name === '' || $keys === [] || $mid === '') {
            return null;   // Plex legt keine leere Playlist an
        }
        $uri = 'server://' . $mid . '/com.plexapp.plugins.library/library/metadata/' . implode(',', $keys);
        $r = $this->send('POST', '/playlists?type=audio&smart=0&title=' . rawurlencode($name)
                                . '&uri=' . rawurlencode($uri));
        if (empty($r['ok'])) {
            return null;
        }
        $m = ($r['json']['MediaContainer']['Metadata'][0] ?? []);
        $key = (string) ($m['key'] ?? '');
        if ($key === '') {
            return null;
        }
        return new ContentRef('plex', 'container', 'key:' . $key, '',
            (string) ($m['title'] ?? $name), '', '', $this->thumb((string) ($m['composite'] ?? '')), '', 0, true);
    }

    public function addToPlaylist(string $playlistId, array $refs): int
    {
        $keys = $this->keysOf($refs);
        $mid  = $this->machineId();
        // Die Oberflaeche haelt Playlists als 'key:/playlists/<id>/items' - daraus die Nummer.
        if (preg_match('~(\d+)~', $playlistId, $mm)) {
            $playlistId = $mm[1];
        }
        if ($keys === [] || $mid === '' || $playlistId === '') {
            return 0;
        }
        $uri = 'server://' . $mid . '/com.plexapp.plugins.library/library/metadata/' . implode(',', $keys);
        $r = $this->send('PUT', '/playlists/' . rawurlencode($playlistId) . '/items?uri=' . rawurlencode($uri));
        return empty($r['ok']) ? 0 : count($keys);
    }

    public function playlists(): array
    {
        $j = $this->get('/playlists?playlistType=audio');
        $out = [];
        foreach ((array) ($j['MediaContainer']['Metadata'] ?? []) as $m) {
            if (!empty($m['smart'])) {
                continue;   // regelbasiert - nimmt keine Handzugaben an
            }
            $n = (int) ($m['leafCount'] ?? 0);
            $out[] = new ContentRef('plex', 'container', 'key:' . (string) ($m['key'] ?? ''), '',
                (string) ($m['title'] ?? ''), $n > 0 ? ($n . ' Titel') : '', '',
                $this->thumb((string) ($m['composite'] ?? '')), '', 0, true);
        }
        return $out;
    }

    public function deletePlaylist(string $playlistId): bool
    {
        if (preg_match('~(\d+)~', $playlistId, $mm)) {
            $playlistId = $mm[1];
        }
        if ($playlistId === '') {
            return false;
        }
        return !empty($this->send('DELETE', '/playlists/' . rawurlencode($playlistId))['ok']);
    }
}

MediaProviders::register('plex', PlexProvider::class);

<?php

declare(strict_types=1);

namespace Hoep\HomeSuite\Media;

use Hoep\HomeSuite\Contracts\IMediaProvider;
use Hoep\HomeSuite\Engines\MediaProviders;
use Hoep\HomeSuite\HAL\ContentRef;

/**
 * SpotifyProvider — Spotify Web-API (Browsen/Metadaten renderer-unabhaengig).
 *
 * Liefert ContentRef mit kind='spotify' und uri='spotify:...' (Playlist/Album/Track) +
 * Cover. Die WIEDERGABE ist DRM-gebunden: der Renderer-Treiber uebersetzt die spotify:-URI
 * (Sonos x-sonos-spotify, HEOS play). Auth per Refresh-Token (OAuth Authorization Code),
 * eingerichtet ueber den Hub-OAuth-Hook.
 *
 * config: clientId, clientSecret, refreshToken
 */
final class SpotifyProvider implements IMediaProvider
{
    private const AUTH = 'https://accounts.spotify.com/api/token';
    private const API  = 'https://api.spotify.com/v1';

    private string $cid;
    private string $secret;
    private string $refresh;
    private string $access = '';

    public function __construct(array $cfg)
    {
        $this->cid     = (string) ($cfg['clientId'] ?? '');
        $this->secret  = (string) ($cfg['clientSecret'] ?? '');
        $this->refresh = (string) ($cfg['refreshToken'] ?? '');
    }

    public function id(): string
    {
        return 'spotify';
    }

    public function label(): string
    {
        return 'Spotify';
    }

    public function isConfigured(): bool
    {
        return $this->cid !== '' && $this->secret !== '' && $this->refresh !== '';
    }

    /** Access-Token per Refresh-Token holen (gecacht je Instanz). */
    private function access(): string
    {
        if ($this->access !== '') {
            return $this->access;
        }
        if (!$this->isConfigured()) {
            return '';
        }
        $r = $this->post(self::AUTH, ['grant_type' => 'refresh_token', 'refresh_token' => $this->refresh],
            'Basic ' . base64_encode($this->cid . ':' . $this->secret));
        $j = json_decode($r, true);
        $this->access = (string) ($j['access_token'] ?? '');
        return $this->access;
    }

    public function roots(): array
    {
        return [
            new ContentRef('spotify', 'container', 'playlists', '', 'Playlists', '', '', '', '', 0, true),
            new ContentRef('spotify', 'container', 'albums', '', 'Alben (gespeichert)', '', '', '', '', 0, true),
        ];
    }

    public function browse(string $containerId, int $offset = 0, int $limit = 50): array
    {
        $limit = max(1, min($limit, 50)); // Spotify: /me/playlists, /me/albums, tracks -> hartes Maximum 50 (limit>50 => HTTP 400 => leer)
        if ($containerId === 'playlists') {
            $j = $this->api('/me/playlists?limit=' . $limit . '&offset=' . $offset);
            $out = [];
            foreach ((array) ($j['items'] ?? []) as $p) {
                // isContainer=false: Playlist direkt abspielbar (Tippen spielt die ganze Playlist via URI).
                // Spotify liefert /playlists/{id}/tracks fuer gefolgte/fremde Playlists 403 -> Drill-in nutzlos,
                // und fuer die Wiedergabe uebersetzt der Renderer ohnehin die spotify:playlist:-URI.
                $out[] = new ContentRef('spotify', 'spotify', 'pl:' . $p['id'], 'spotify:playlist:' . $p['id'],
                    (string) ($p['name'] ?? ''), (string) ($p['owner']['display_name'] ?? ''), '',
                    (string) ($p['images'][0]['url'] ?? ''), '', 0, false);
            }
            return $out;
        }
        if ($containerId === 'albums') {
            $j = $this->api('/me/albums?limit=' . $limit . '&offset=' . $offset);
            $out = [];
            foreach ((array) ($j['items'] ?? []) as $it) {
                $a = $it['album'] ?? [];
                $out[] = new ContentRef('spotify', 'spotify', 'al:' . $a['id'], 'spotify:album:' . $a['id'],
                    (string) ($a['name'] ?? ''), (string) ($a['artists'][0]['name'] ?? ''), '',
                    (string) ($a['images'][0]['url'] ?? ''), '', 0, true);
            }
            return $out;
        }
        if (strncmp($containerId, 'pl:', 3) === 0) {
            $j = $this->api('/playlists/' . rawurlencode(substr($containerId, 3)) . '/tracks?limit=' . $limit . '&offset=' . $offset);
            return $this->mapTracks(array_map(fn($x) => $x['track'] ?? [], (array) ($j['items'] ?? [])));
        }
        if (strncmp($containerId, 'al:', 3) === 0) {
            $j = $this->api('/albums/' . rawurlencode(substr($containerId, 3)) . '/tracks?limit=' . $limit . '&offset=' . $offset);
            return $this->mapTracks((array) ($j['items'] ?? []));
        }
        return [];
    }

    private function mapTracks(array $tracks): array
    {
        $out = [];
        foreach ($tracks as $t) {
            if (empty($t['id'])) {
                continue;
            }
            $out[] = new ContentRef('spotify', 'spotify', 'tr:' . $t['id'], 'spotify:track:' . $t['id'],
                (string) ($t['name'] ?? ''), (string) ($t['artists'][0]['name'] ?? ''),
                (string) ($t['album']['name'] ?? ''), (string) ($t['album']['images'][0]['url'] ?? ''),
                '', (int) round(((int) ($t['duration_ms'] ?? 0)) / 1000), false);
        }
        return $out;
    }

    public function search(string $query, int $limit = 30): array
    {
        $limit = max(1, min($limit, 50)); // Spotify /search: max 50
        $j = $this->api('/search?type=playlist,album,track&limit=' . $limit . '&q=' . rawurlencode($query));
        $out = [];
        foreach ((array) ($j['playlists']['items'] ?? []) as $p) {
            $out[] = new ContentRef('spotify', 'spotify', 'pl:' . $p['id'], 'spotify:playlist:' . $p['id'],
                (string) ($p['name'] ?? ''), 'Playlist', '', (string) ($p['images'][0]['url'] ?? ''), '', 0, false);
        }
        foreach ((array) ($j['tracks']['items'] ?? []) as $t) {
            $out[] = new ContentRef('spotify', 'spotify', 'tr:' . $t['id'], 'spotify:track:' . $t['id'],
                (string) ($t['name'] ?? ''), (string) ($t['artists'][0]['name'] ?? ''),
                (string) ($t['album']['name'] ?? ''), (string) ($t['album']['images'][0]['url'] ?? ''), '', 0, false);
        }
        return $out;
    }

    public function resolve(ContentRef $ref): ContentRef
    {
        return $ref; // spotify:-URI ist bereits die abspielbare Referenz (Renderer uebersetzt)
    }

    private function api(string $path): array
    {
        $tok = $this->access();
        if ($tok === '') {
            return [];
        }
        $r = $this->httpGet(self::API . $path, ['Authorization: Bearer ' . $tok, 'Accept: application/json']);
        $j = json_decode($r, true);
        return is_array($j) ? $j : [];
    }

    private function httpGet(string $url, array $headers): string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8,
                CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_HTTPHEADER => $headers]);
            $r = curl_exec($ch);
            curl_close($ch);
            return $r === false ? '' : (string) $r;
        }
        $ctx = stream_context_create(['http' => ['timeout' => 8, 'header' => implode("\r\n", $headers), 'ignore_errors' => true]]);
        return (string) @file_get_contents($url, false, $ctx);
    }

    private function post(string $url, array $form, string $auth): string
    {
        $body = http_build_query($form);
        $headers = ['Content-Type: application/x-www-form-urlencoded', 'Authorization: ' . $auth];
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers]);
            $r = curl_exec($ch);
            curl_close($ch);
            return $r === false ? '' : (string) $r;
        }
        $ctx = stream_context_create(['http' => ['method' => 'POST', 'timeout' => 8,
            'header' => implode("\r\n", $headers), 'content' => $body, 'ignore_errors' => true]]);
        return (string) @file_get_contents($url, false, $ctx);
    }
}

MediaProviders::register('spotify', SpotifyProvider::class);

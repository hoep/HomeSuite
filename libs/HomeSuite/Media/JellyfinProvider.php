<?php

declare(strict_types=1);

namespace Hoep\HomeSuite\Media;

use Hoep\HomeSuite\Contracts\IMediaProvider;
use Hoep\HomeSuite\Engines\MediaProviders;
use Hoep\HomeSuite\HAL\ContentRef;

/**
 * JellyfinProvider — self-hosted Medienserver (renderer-unabhaengig, direkte Stream-URLs).
 *
 * Auth ueber API-Key (Dashboard -> API-Schluessel) + UserId. Stream-/Cover-URLs tragen
 * den api_key als Query -> jeder Renderer (Sonos/HEOS/UPnP) kann ohne Header spielen.
 *
 * config: url, apiKey, userId
 */
final class JellyfinProvider implements IMediaProvider
{
    private string $url;
    private string $key;
    private string $uid;

    public function __construct(array $cfg)
    {
        $this->url = rtrim(trim((string) ($cfg['url'] ?? '')), '/');
        $this->key = (string) ($cfg['apiKey'] ?? '');
        $this->uid = (string) ($cfg['userId'] ?? '');
    }

    public function id(): string
    {
        return 'jellyfin';
    }

    public function label(): string
    {
        return 'Jellyfin';
    }

    public function isConfigured(): bool
    {
        return $this->url !== '' && $this->key !== '' && $this->uid !== '';
    }

    /** Musik-/Hoerbuch-Bibliotheken (Views). */
    public function roots(): array
    {
        $j = $this->get('/Users/' . rawurlencode($this->uid) . '/Views');
        $out = [];
        foreach ((array) ($j['Items'] ?? []) as $it) {
            $ct = (string) ($it['CollectionType'] ?? '');
            if (!in_array($ct, ['music', 'audiobooks', 'books', 'podcasts', ''], true)) {
                continue;
            }
            $out[] = new ContentRef('jellyfin', 'container', 'lib:' . $it['Id'], '',
                (string) ($it['Name'] ?? ''), '', '', $this->cover((string) $it['Id']), '', 0, true);
        }
        return $out;
    }

    public function browse(string $containerId, int $offset = 0, int $limit = 100): array
    {
        $parent = null;
        $types = 'MusicAlbum,Playlist,MusicArtist,AudioBook,Folder';
        if (strncmp($containerId, 'lib:', 4) === 0) {
            $parent = substr($containerId, 4);
        } elseif (strncmp($containerId, 'item:', 5) === 0) {
            $parent = substr($containerId, 5);
            $types = 'Audio,MusicAlbum,Playlist,Folder';
        }
        $q = '/Users/' . rawurlencode($this->uid) . '/Items?Recursive=false&SortBy=SortName&Limit=' . $limit
            . '&StartIndex=' . $offset . '&IncludeItemTypes=' . rawurlencode($types)
            . ($parent !== null ? ('&ParentId=' . rawurlencode($parent)) : '');
        $j = $this->get($q);
        $out = [];
        foreach ((array) ($j['Items'] ?? []) as $it) {
            $isAudio = (($it['Type'] ?? '') === 'Audio');
            $ref = new ContentRef('jellyfin', $isAudio ? 'url' : 'container',
                ($isAudio ? 'audio:' : 'item:') . $it['Id'],
                $isAudio ? $this->streamUrl((string) $it['Id']) : '',
                (string) ($it['Name'] ?? ''),
                (string) ($it['AlbumArtist'] ?? ($it['Artists'][0] ?? '')),
                (string) ($it['Album'] ?? ''),
                $this->cover((string) $it['Id']),
                $isAudio ? 'audio/mpeg' : '',
                (int) round(((int) ($it['RunTimeTicks'] ?? 0)) / 10000000),
                !$isAudio);
            $out[] = $ref;
        }
        return $out;
    }

    public function search(string $query, int $limit = 50): array
    {
        $j = $this->get('/Users/' . rawurlencode($this->uid) . '/Items?Recursive=true&SearchTerm=' . rawurlencode($query)
            . '&IncludeItemTypes=MusicAlbum,Playlist,Audio,AudioBook&Limit=' . $limit);
        $out = [];
        foreach ((array) ($j['Items'] ?? []) as $it) {
            $isAudio = (($it['Type'] ?? '') === 'Audio');
            $out[] = new ContentRef('jellyfin', $isAudio ? 'url' : 'container',
                ($isAudio ? 'audio:' : 'item:') . $it['Id'],
                $isAudio ? $this->streamUrl((string) $it['Id']) : '',
                (string) ($it['Name'] ?? ''), (string) ($it['AlbumArtist'] ?? ''), (string) ($it['Album'] ?? ''),
                $this->cover((string) $it['Id']), $isAudio ? 'audio/mpeg' : '', 0, !$isAudio);
        }
        return $out;
    }

    public function resolve(ContentRef $ref): ContentRef
    {
        if ($ref->kind === 'url' && $ref->uri !== '') {
            return $ref;
        }
        if (strncmp($ref->id, 'audio:', 6) === 0) {
            $ref->uri = $this->streamUrl(substr($ref->id, 6));
            $ref->kind = 'url';
        } elseif (strncmp($ref->id, 'item:', 5) === 0) {
            $kids = $this->browse($ref->id, 0, 1);
            if ($kids) {
                return $this->resolve($kids[0]);
            }
        }
        return $ref;
    }

    private function streamUrl(string $itemId): string
    {
        // Direkte Wiedergabe (static) mit api_key als Query -> renderer-unabhaengig.
        return $this->url . '/Audio/' . rawurlencode($itemId) . '/stream?static=true&api_key=' . rawurlencode($this->key);
    }

    private function cover(string $itemId): string
    {
        return $this->url . '/Items/' . rawurlencode($itemId) . '/Images/Primary?api_key=' . rawurlencode($this->key);
    }

    private function get(string $path): array
    {
        $url = $this->url . $path;
        $headers = ['Accept: application/json', 'X-Emby-Token: ' . $this->key,
            'Authorization: MediaBrowser Token="' . $this->key . '"'];
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
}

MediaProviders::register('jellyfin', JellyfinProvider::class);

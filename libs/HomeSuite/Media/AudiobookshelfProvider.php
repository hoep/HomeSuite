<?php

declare(strict_types=1);

namespace Hoep\HomeSuite\Media;

use Hoep\HomeSuite\Contracts\IMediaProvider;
use Hoep\HomeSuite\Contracts\IMediaWritable;
use Hoep\HomeSuite\Engines\MediaProviders;
use Hoep\HomeSuite\HAL\ContentRef;

/**
 * AudiobookshelfProvider — self-hosted Hoerbuch-/Podcast-Bibliothek (renderer-unabhaengig).
 *
 * Liefert DIREKTE HTTP-Stream-URLs (mit ?token=), damit JEDER Renderer (Sonos/HEOS/UPnP)
 * abspielen kann — kein DRM, kein Sonos-Lock-in. Token wird per Login (Benutzer/Passwort)
 * geholt (ABS-User-Token, langlebig) und je Instanz gecacht.
 *
 * config: url (Basis inkl. evtl. Subpfad), username, password [, token]
 */
final class AudiobookshelfProvider implements IMediaProvider, IMediaWritable
{
    private string $url;
    private string $user;
    private string $pass;
    private string $tok;

    public function __construct(array $cfg)
    {
        $this->url  = rtrim(trim((string) ($cfg['url'] ?? '')), '/');
        $this->user = (string) ($cfg['username'] ?? '');
        $this->pass = (string) ($cfg['password'] ?? '');
        $this->tok  = (string) ($cfg['token'] ?? '');
    }

    public function id(): string
    {
        return 'audiobookshelf';
    }

    public function label(): string
    {
        return 'Audiobookshelf';
    }

    public function isConfigured(): bool
    {
        return $this->url !== '' && ($this->tok !== '' || ($this->user !== '' && $this->pass !== ''));
    }

    /** ABS-User-Token (aus config oder per Login). '' bei Fehlschlag. */
    public function token(): string
    {
        if ($this->tok !== '') {
            return $this->tok;
        }
        if ($this->user === '') {
            return '';
        }
        $r = $this->http('/login', 'POST', ['username' => $this->user, 'password' => $this->pass], false);
        $j = json_decode($r, true);
        $this->tok = (string) ($j['user']['token'] ?? '');
        return $this->tok;
    }

    /** Buch-Bibliotheken als Wurzel-Container. */
    public function roots(): array
    {
        $j = json_decode($this->http('/api/libraries'), true);
        $out = [];
        foreach ((array) ($j['libraries'] ?? []) as $lib) {
            if (($lib['mediaType'] ?? 'book') !== 'book') {
                continue;
            }
            $out[] = new ContentRef('audiobookshelf', 'container', 'lib:' . $lib['id'],
                '', (string) ($lib['name'] ?? 'Bibliothek'), '', '', '', '', 0, true);
            // Serien sind bei Hoerbuechern das, was bei Musik die Playlist ist: die einzige
            // gefuellte Listenart dieses Servers (Playlists und Sammlungen sind hier leer).
            $out[] = new ContentRef('audiobookshelf', 'container', 'ser:' . $lib['id'],
                '', (string) ($lib['name'] ?? 'Bibliothek') . ' - Serien', '', '', '', '', 0, true);
        }
        return $out;
    }

    public function browse(string $containerId, int $offset = 0, int $limit = 100): array
    {
        if (strncmp($containerId, 'lib:', 4) === 0) {
            return $this->libraryItems(substr($containerId, 4), $offset, $limit);
        }
        if (strncmp($containerId, 'item:', 5) === 0) {
            return $this->itemTracks(substr($containerId, 5));
        }
        if (strncmp($containerId, 'serb:', 5) === 0) {
            [$libId, $serId] = array_pad(explode(':', substr($containerId, 5), 2), 2, '');
            return $this->seriesBooks($libId, $serId);
        }
        if (strncmp($containerId, 'ser:', 4) === 0) {
            return $this->seriesList(substr($containerId, 4), $offset, $limit);
        }
        // Default: erste Buch-Bibliothek
        $roots = $this->roots();
        return $roots ? $this->libraryItems(substr($roots[0]->id, 4), $offset, $limit) : [];
    }

    /** Hoerbuecher einer Bibliothek (als Container). */
    private function libraryItems(string $libId, int $offset, int $limit): array
    {
        $page = (int) floor($offset / max(1, $limit));
        // Serverseitig nach Autor sortieren (paginierungs-stabil ueber Seiten), Titel als Feinsortierung folgt clientseitig.
        $j = json_decode($this->http('/api/libraries/' . rawurlencode($libId) . '/items?limit=' . $limit . '&page=' . $page . '&sort=media.metadata.authorName'), true);
        $out = [];
        foreach ((array) ($j['results'] ?? []) as $it) {
            $md = (array) (($it['media']['metadata'] ?? []));
            $out[] = new ContentRef('audiobookshelf', 'container', 'item:' . $it['id'],
                '', (string) ($md['title'] ?? ''), (string) ($md['authorName'] ?? ''), '',
                $this->coverUrl((string) $it['id']), '', (int) round((float) (($it['media']['duration'] ?? 0))), true);
        }
        return ContentRef::sortByArtistTitle($out); // Autor -> Titel
    }

    /** Serien einer Bibliothek als Container. */
    private function seriesList(string $libId, int $offset, int $limit): array
    {
        $j = json_decode($this->http('/api/libraries/' . rawurlencode($libId)
            . '/series?limit=' . max(1, $limit) . '&page=' . (int) floor($offset / max(1, $limit))), true);
        $out = [];
        foreach ((array) ($j['results'] ?? []) as $ser) {
            $id = (string) ($ser['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $n = count((array) ($ser['books'] ?? []));
            $out[] = new ContentRef('audiobookshelf', 'container', 'serb:' . $libId . ':' . $id, '',
                (string) ($ser['name'] ?? 'Serie'), $n > 0 ? ($n . ' Baende') : '', '', '', '', 0, true);
        }
        return $out;
    }

    /**
     * Baende einer Serie, in Folgenreihenfolge.
     *
     * Der Server filtert ueber die base64-kodierte Serien-Kennung und liefert bereits nach
     * Folge sortiert; die Sortierung hier ist nur die Absicherung, falls das je kippt.
     */
    private function seriesBooks(string $libId, string $serId): array
    {
        if ($libId === '' || $serId === '') {
            return [];
        }
        $j = json_decode($this->http('/api/libraries/' . rawurlencode($libId)
            . '/items?limit=200&filter=series.' . rawurlencode(base64_encode($serId))), true);
        $roh = [];
        foreach ((array) ($j['results'] ?? []) as $it) {
            $md  = (array) ($it['media']['metadata'] ?? []);
            $ser = (array) ($md['series'] ?? []);
            $roh[] = [
                'folge' => (float) ($ser['sequence'] ?? 0),
                'ref'   => new ContentRef('audiobookshelf', 'container', 'item:' . $it['id'], '',
                    (string) ($md['title'] ?? ''), (string) ($md['authorName'] ?? ''), '',
                    $this->coverUrl((string) $it['id']), '',
                    (int) round((float) ($it['media']['duration'] ?? 0)), true),
            ];
        }
        usort($roh, static fn($a, $b) => $a['folge'] <=> $b['folge']);
        return array_column($roh, 'ref');
    }

    /** Audiospuren eines Hoerbuchs als abspielbare (URL-)Items. */
    private function itemTracks(string $itemId): array
    {
        $j = json_decode($this->http('/api/items/' . rawurlencode($itemId) . '?expanded=1'), true);
        $md = (array) (($j['media']['metadata'] ?? []));
        $book = (string) ($md['title'] ?? '');
        $author = (string) ($md['authorName'] ?? '');
        $cover = $this->coverUrl($itemId);
        $tracks = (array) ($j['media']['tracks'] ?? $j['media']['audioFiles'] ?? []);
        // Reihenfolge absichern: bei einem Buch mit 393 Dateien ist eine vertauschte
        // Reihenfolge kein Schoenheitsfehler, sondern eine unbrauchbare Wiedergabe.
        usort($tracks, static function ($a, $b) {
            $ia = (float) ($a['index'] ?? ($a['metadata']['index'] ?? 0));
            $ib = (float) ($b['index'] ?? ($b['metadata']['index'] ?? 0));
            return $ia <=> $ib;
        });
        $tok = $this->token();
        $out = [];
        foreach ($tracks as $i => $t) {
            $ino = (string) ($t['ino'] ?? ($t['metadata']['ino'] ?? ''));
            if ($ino === '') {
                continue;
            }
            $title = (string) ($t['title'] ?? ($t['metadata']['filename'] ?? ('Track ' . ($i + 1))));
            $uri = $this->url . '/api/items/' . rawurlencode($itemId) . '/file/' . rawurlencode($ino) . '?token=' . rawurlencode($tok);
            $out[] = new ContentRef('audiobookshelf', 'url', 'file:' . $itemId . ':' . $ino, $uri,
                $title, $author, $book, $cover, (string) ($t['mimeType'] ?? 'audio/mpeg'),
                (int) round((float) ($t['duration'] ?? 0)), false);
        }
        return $out;
    }

    public function search(string $query, int $limit = 50): array
    {
        $out = [];
        foreach ($this->roots() as $lib) {
            $libId = substr($lib->id, 4);
            $j = json_decode($this->http('/api/libraries/' . rawurlencode($libId) . '/search?q=' . rawurlencode($query) . '&limit=' . $limit), true);
            foreach ((array) ($j['book'] ?? []) as $hit) {
                $it = $hit['libraryItem'] ?? $hit;
                $md = (array) (($it['media']['metadata'] ?? []));
                $out[] = new ContentRef('audiobookshelf', 'container', 'item:' . ($it['id'] ?? ''),
                    '', (string) ($md['title'] ?? ''), (string) ($md['authorName'] ?? ''), '',
                    $this->coverUrl((string) ($it['id'] ?? '')), '', 0, true);
            }
        }
        return ContentRef::sortByArtistTitle($out); // Autor -> Titel
    }

    public function resolve(ContentRef $ref): ContentRef
    {
        if ($ref->kind === 'url' && $ref->uri !== '') {
            return $ref;
        }
        // item:-Container -> ersten Track aufloesen (Wiedergabe ab Kapitel 1)
        if (strncmp($ref->id, 'item:', 5) === 0) {
            $tracks = $this->itemTracks(substr($ref->id, 5));
            if ($tracks) {
                return $tracks[0];
            }
        }
        return $ref;
    }

    private function coverUrl(string $itemId): string
    {
        return $this->url . '/api/items/' . rawurlencode($itemId) . '/cover?token=' . rawurlencode($this->token());
    }

    // ---- HTTP ----------------------------------------------------------------

    private function http(string $path, string $method = 'GET', ?array $body = null, bool $auth = true)
    {
        $url = $this->url . $path;
        $headers = ['Accept: application/json'];
        if ($auth) {
            $headers[] = 'Authorization: Bearer ' . $this->token();
        }
        $payload = null;
        if ($body !== null) {
            $payload = json_encode($body);
            $headers[] = 'Content-Type: application/json';
        }
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            $opt = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_HTTPHEADER => $headers, CURLOPT_FOLLOWLOCATION => true];
            if ($method === 'POST') {
                $opt[CURLOPT_POST] = true;
                $opt[CURLOPT_POSTFIELDS] = (string) $payload;
            } elseif ($method !== 'GET') {
                // Ohne das wuerde jedes DELETE/PATCH still zu einem GET - der Aufruf saehe
                // erfolgreich aus und haette nichts getan.
                $opt[CURLOPT_CUSTOMREQUEST] = $method;
                if ($payload !== null) {
                    $opt[CURLOPT_POSTFIELDS] = (string) $payload;
                }
            }
            curl_setopt_array($ch, $opt);
            $r = curl_exec($ch);
            curl_close($ch);
            return $r === false ? '' : (string) $r;
        }
        $ctx = stream_context_create(['http' => ['method' => $method, 'timeout' => 8,
            'header' => implode("\r\n", $headers), 'content' => (string) $payload, 'ignore_errors' => true]]);
        $r = @file_get_contents($url, false, $ctx);
        return $r === false ? '' : (string) $r;
    }

    // ==================================================================
    // Schreiben (IMediaWritable): Playlists im Audiobookshelf anlegen
    // ==================================================================

    /**
     * Eine ABS-Playlist enthaelt BUECHER (libraryItems), keine einzelnen Dateien - deshalb
     * zaehlen hier die 'item:'-Verweise. Eine einzelne Kapiteldatei laesst sich dort nicht
     * ablegen; wer das braucht, meint eine Warteschlange, keine Playlist.
     */
    private function itemsOf(array $refs): array
    {
        $out = [];
        foreach ($refs as $r) {
            if (!$r instanceof ContentRef || $r->provider !== 'audiobookshelf') {
                continue;
            }
            $id = '';
            if (strncmp($r->id, 'item:', 5) === 0) {
                $id = substr($r->id, 5);
            } elseif (strncmp($r->id, 'file:', 5) === 0) {
                // Datei -> zugehoeriges Buch, damit die Auswahl nicht stillschweigend leer bleibt
                $t = explode(':', substr($r->id, 5));
                $id = $t[0] ?? '';
            }
            if ($id !== '') {
                $out[$id] = ['libraryItemId' => $id];
            }
        }
        return array_values($out);
    }

    /** Bibliothek, in der die Liste angelegt wird: die des ersten Eintrags, sonst die erste. */
    private function libIdOf(array $items): string
    {
        if ($items !== []) {
            $j = json_decode((string) $this->http('/api/items/' . rawurlencode($items[0]['libraryItemId'])), true);
            $lib = (string) ($j['libraryId'] ?? '');
            if ($lib !== '') {
                return $lib;
            }
        }
        $roots = $this->roots();
        foreach ($roots as $r) {
            if (strncmp($r->id, 'lib:', 4) === 0) {
                return substr($r->id, 4);
            }
        }
        return '';
    }

    public function createPlaylist(string $name, array $refs): ?ContentRef
    {
        $name  = trim($name);
        $items = $this->itemsOf($refs);
        if ($name === '' || $items === []) {
            return null;
        }
        $lib = $this->libIdOf($items);
        if ($lib === '') {
            return null;
        }
        $r = json_decode((string) $this->http('/api/playlists', 'POST',
            ['libraryId' => $lib, 'name' => $name, 'items' => $items]), true);
        $id = (string) ($r['id'] ?? '');
        if ($id === '') {
            return null;
        }
        return new ContentRef('audiobookshelf', 'container', 'pl:' . $id, '',
            (string) ($r['name'] ?? $name), count($items) . ' Titel', '', '', '', 0, true);
    }

    public function addToPlaylist(string $playlistId, array $refs): int
    {
        if (strncmp($playlistId, 'pl:', 3) === 0) {
            $playlistId = substr($playlistId, 3);
        }
        $items = $this->itemsOf($refs);
        if ($playlistId === '' || $items === []) {
            return 0;
        }
        $r = json_decode((string) $this->http('/api/playlists/' . rawurlencode($playlistId) . '/batch/add',
            'POST', ['items' => $items]), true);
        return isset($r['id']) ? count($items) : 0;
    }

    public function playlists(): array
    {
        $out = [];
        foreach ($this->roots() as $r) {
            if (strncmp($r->id, 'lib:', 4) !== 0) {
                continue;
            }
            $j = json_decode((string) $this->http('/api/libraries/' . rawurlencode(substr($r->id, 4)) . '/playlists?limit=100'), true);
            foreach ((array) ($j['results'] ?? []) as $pl) {
                $n = count((array) ($pl['items'] ?? []));
                $out[] = new ContentRef('audiobookshelf', 'container', 'pl:' . (string) ($pl['id'] ?? ''), '',
                    (string) ($pl['name'] ?? ''), $n > 0 ? ($n . ' Titel') : '', '', '', '', 0, true);
            }
        }
        return $out;
    }

    public function deletePlaylist(string $playlistId): bool
    {
        if (strncmp($playlistId, 'pl:', 3) === 0) {
            $playlistId = substr($playlistId, 3);
        }
        if ($playlistId === '') {
            return false;
        }
        $this->http('/api/playlists/' . rawurlencode($playlistId), 'DELETE');
        return true;
    }
}

MediaProviders::register('audiobookshelf', AudiobookshelfProvider::class);

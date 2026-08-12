<?php

declare(strict_types=1);

namespace Hoep\HomeSuite\Media;

use Hoep\HomeSuite\Contracts\IMediaProvider;
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
final class AudiobookshelfProvider implements IMediaProvider
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

    /** Audiospuren eines Hoerbuchs als abspielbare (URL-)Items. */
    private function itemTracks(string $itemId): array
    {
        $j = json_decode($this->http('/api/items/' . rawurlencode($itemId) . '?expanded=1'), true);
        $md = (array) (($j['media']['metadata'] ?? []));
        $book = (string) ($md['title'] ?? '');
        $author = (string) ($md['authorName'] ?? '');
        $cover = $this->coverUrl($itemId);
        $tracks = (array) ($j['media']['tracks'] ?? $j['media']['audioFiles'] ?? []);
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
}

MediaProviders::register('audiobookshelf', AudiobookshelfProvider::class);

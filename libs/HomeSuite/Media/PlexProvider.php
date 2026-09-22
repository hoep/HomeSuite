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

    // ==================================================================
    // Vollzugriff auf die Plex-Bibliothek
    //
    // Bis hierher deckt der Provider ab, was die Audio-Domaene braucht: Musik suchen,
    // abspielen, Playlists pflegen. Alles Folgende loest die alte Klassenfamilie
    // PHPPlex/PlexAPI/PlexSeries/PlexMovies/PlexMusic/PlexLiveTV ab, die im Skriptordner
    // lag und produktiv von nichts ausser zwei Entwicklerbeispielen benutzt wurde.
    //
    // Bewusste Entscheidung zur Rueckgabeform: die Bibliotheks- und Live-TV-Methoden
    // liefern NORMALISIERTE ARRAYS, keine ContentRef. ContentRef beschreibt etwas
    // Abspielbares mit Strom-URL - fuer eine Serienstaffel oder eine Aufnahmeregel waere
    // das ein falsches Versprechen. Wer einen Titel abspielen will, nimmt weiter
    // browse()/resolve().
    // ==================================================================

    /** Plex-Typnummern, wie sie in ?type= erwartet werden. */
    public const TYP_FILM    = 1;
    public const TYP_SERIE   = 2;
    public const TYP_STAFFEL = 3;
    public const TYP_FOLGE   = 4;
    public const TYP_ARTIST  = 8;
    public const TYP_ALBUM   = 9;
    public const TYP_TITEL   = 10;

    /** Lesen mit Parametern - get() kann nur feste Pfade. */
    private function getP(string $path, array $params = []): array
    {
        if ($params !== []) {
            $path .= (strpos($path, '?') !== false ? '&' : '?') . http_build_query($params);
        }
        return $this->get($path);
    }

    /** Die Metadatenliste aus einer Antwort, egal wie tief Plex sie diesmal einpackt. */
    private function liste(array $j): array
    {
        $c = $j['MediaContainer'] ?? [];
        foreach (['Metadata', 'Directory', 'Video', 'Track', 'Hub'] as $k) {
            if (isset($c[$k]) && is_array($c[$k])) {
                return $c[$k];
            }
        }
        return [];
    }

    /**
     * Ein Plex-Eintrag in eine flache, sprechende Form.
     *
     * Plex benennt dieselbe Sache je nach Typ anders: bei einer Folge steht die Serie in
     * grandparentTitle, bei einem Titel der Interpret. Das hier einmal aufzuloesen erspart
     * es jedem Aufrufer.
     */
    private function eintrag(array $m): array
    {
        $typ = (string) ($m['type'] ?? '');
        $e = [
            'id'      => (string) ($m['ratingKey'] ?? ''),
            'typ'     => $typ,
            'titel'   => (string) ($m['title'] ?? ''),
            'sortier' => (string) ($m['titleSort'] ?? ($m['title'] ?? '')),
            'jahr'    => (int) ($m['year'] ?? 0),
            'dauer_s' => (int) round(((int) ($m['duration'] ?? 0)) / 1000),
            'bild'    => $this->thumb((string) ($m['thumb'] ?? '')),
            'inhalt'  => (string) ($m['summary'] ?? ''),
            'gesehen' => ((int) ($m['viewCount'] ?? 0)) > 0,
            'zuletzt' => (int) ($m['lastViewedAt'] ?? 0),
            'hinzu'   => (int) ($m['addedAt'] ?? 0),
            'schluessel' => (string) ($m['key'] ?? ''),
        ];
        if (isset($m['parentTitle']))      { $e['ueber']  = (string) $m['parentTitle']; }
        if (isset($m['grandparentTitle'])) { $e['darueber'] = (string) $m['grandparentTitle']; }
        if (isset($m['index']))            { $e['nummer'] = (int) $m['index']; }
        if (isset($m['parentIndex']))      { $e['staffel'] = (int) $m['parentIndex']; }
        if (isset($m['leafCount']))        { $e['anzahl'] = (int) $m['leafCount']; }
        if (isset($m['viewedLeafCount']))  { $e['gesehen_anzahl'] = (int) $m['viewedLeafCount']; }
        if (isset($m['rating']))           { $e['bewertung'] = (float) $m['rating']; }
        if (isset($m['originallyAvailableAt'])) { $e['erstausstrahlung'] = (string) $m['originallyAvailableAt']; }
        $part = $m['Media'][0]['Part'][0]['key'] ?? '';
        if ($part !== '') { $e['datei_url'] = $this->tokUrl((string) $part); }
        foreach (['Genre' => 'genres', 'Director' => 'regie', 'Role' => 'besetzung'] as $q => $z) {
            if (isset($m[$q]) && is_array($m[$q])) {
                $e[$z] = array_values(array_filter(array_map(
                    static fn($x) => (string) ($x['tag'] ?? ''), $m[$q])));
            }
        }
        return $e;
    }

    /** @return array<int,array> */
    private function eintraege(array $j): array
    {
        return array_map([$this, 'eintrag'], $this->liste($j));
    }

    // ---------- Server und Bibliotheken ----------

    /** Kenndaten des Servers: Name, Fassung, Maschinenkennung. */
    public function serverInfo(): array
    {
        $c = $this->get('/')['MediaContainer'] ?? [];
        return [
            'name'      => (string) ($c['friendlyName'] ?? ''),
            'version'   => (string) ($c['version'] ?? ''),
            'plattform' => (string) ($c['platform'] ?? ''),
            'kennung'   => (string) ($c['machineIdentifier'] ?? ''),
            'transcoder_video' => !empty($c['transcoderVideo']),
            'transcoder_audio' => !empty($c['transcoderAudio']),
        ];
    }

    /** Erreichbarkeit pruefen, ohne eine Ausnahme zu werfen. */
    public function testConnection(): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'grund' => 'Adresse oder Token fehlt'];
        }
        $i = $this->serverInfo();
        return ($i['kennung'] !== '')
            ? ['ok' => true] + $i
            : ['ok' => false, 'grund' => 'keine Antwort vom Server'];
    }

    /**
     * Alle Bibliotheken, wahlweise nach Art gefiltert.
     *
     * @param string $art '' = alle, sonst movie|show|artist|photo
     */
    public function libraries(string $art = ''): array
    {
        $out = [];
        foreach ($this->liste($this->get('/library/sections')) as $d) {
            $t = (string) ($d['type'] ?? '');
            if ($art !== '' && $t !== $art) {
                continue;
            }
            $out[] = [
                'id'    => (string) ($d['key'] ?? ''),
                'titel' => (string) ($d['title'] ?? ''),
                'art'   => $t,
                'agent' => (string) ($d['agent'] ?? ''),
                'pfade' => array_values(array_map(
                    static fn($l) => (string) ($l['path'] ?? ''), (array) ($d['Location'] ?? []))),
            ];
        }
        return $out;
    }

    /**
     * Die ID der ersten Bibliothek einer Art.
     *
     * Ohne Angabe raet der Aufrufer sonst - und bei zwei Filmbibliotheken raet er falsch.
     * Deshalb gibt libraries() die vollstaendige Liste; dies hier ist nur die Abkuerzung
     * fuer die haeufige Anlage mit genau einer Bibliothek je Art.
     */
    public function findLibraryId(string $art): string
    {
        $l = $this->libraries($art);
        return $l ? (string) $l[0]['id'] : '';
    }

    // ---------- Allgemeiner Bibliothekszugriff ----------

    /** Eintraege einer Bibliothek, mit freien Zusatzparametern. */
    public function libraryItems(string $sectionId, array $params = [], int $offset = 0, int $limit = 0): array
    {
        if ($limit > 0) {
            $params['X-Plex-Container-Start'] = $offset;
            $params['X-Plex-Container-Size']  = $limit;
        }
        return $this->eintraege($this->getP('/library/sections/' . rawurlencode($sectionId) . '/all', $params));
    }

    /** Einzelner Eintrag mit allen Feldern. */
    public function itemDetails(string $ratingKey): array
    {
        $l = $this->eintraege($this->get('/library/metadata/' . rawurlencode($ratingKey)));
        return $l ? $l[0] : [];
    }

    /** Die Kinder eines Eintrags: Staffeln einer Serie, Folgen einer Staffel, Titel eines Albums. */
    public function children(string $ratingKey): array
    {
        return $this->eintraege($this->get('/library/metadata/' . rawurlencode($ratingKey) . '/children'));
    }

    /** Suche innerhalb einer Bibliothek (die globale Suche steckt in search()). */
    public function librarySearch(string $sectionId, string $query, int $typ = 0): array
    {
        $p = ['query' => $query];
        if ($typ > 0) { $p['type'] = $typ; }
        return $this->eintraege($this->getP('/library/sections/' . rawurlencode($sectionId) . '/search', $p));
    }

    /** Zuletzt hinzugekommen. */
    public function recentlyAdded(string $sectionId, int $limit = 20): array
    {
        return $this->eintraege($this->getP('/library/sections/' . rawurlencode($sectionId) . '/recentlyAdded',
            ['X-Plex-Container-Start' => 0, 'X-Plex-Container-Size' => $limit]));
    }

    /** Noch nicht Gesehenes. */
    public function unwatched(string $sectionId, int $typ = 0): array
    {
        $p = ['unwatched' => 1];
        if ($typ > 0) { $p['type'] = $typ; }
        return $this->libraryItems($sectionId, $p);
    }

    /** Alle Genres einer Bibliothek. */
    public function genres(string $sectionId): array
    {
        $out = [];
        foreach ($this->liste($this->get('/library/sections/' . rawurlencode($sectionId) . '/genre')) as $g) {
            $out[] = ['id' => (string) ($g['key'] ?? ''), 'titel' => (string) ($g['title'] ?? '')];
        }
        return $out;
    }

    // ---------- Gesehen-Status ----------

    /**
     * Als gesehen markieren.
     *
     * Plex nennt das scrobble, und es ist ein GET mit Parametern - nicht wie man erwarten
     * wuerde ein PUT. Antwort ist leer; der Erfolg zeigt sich nur am HTTP-Code.
     */
    public function markWatched(string $ratingKey): bool
    {
        return $this->scrobble('/:/scrobble', $ratingKey);
    }

    public function markUnwatched(string $ratingKey): bool
    {
        return $this->scrobble('/:/unscrobble', $ratingKey);
    }

    private function scrobble(string $pfad, string $ratingKey): bool
    {
        if ($ratingKey === '') {
            return false;
        }
        $r = $this->send('GET', $pfad . '?identifier=com.plexapp.plugins.library&key=' . rawurlencode($ratingKey));
        return !empty($r['ok']);
    }

    // ---------- Filme ----------

    public function movies(string $sectionId = '', int $offset = 0, int $limit = 0): array
    {
        $sec = $sectionId !== '' ? $sectionId : $this->findLibraryId('movie');
        return $sec === '' ? [] : $this->libraryItems($sec, ['type' => self::TYP_FILM], $offset, $limit);
    }

    public function searchMovies(string $query, string $sectionId = ''): array
    {
        $sec = $sectionId !== '' ? $sectionId : $this->findLibraryId('movie');
        return $sec === '' ? [] : $this->librarySearch($sec, $query, self::TYP_FILM);
    }

    public function moviesByYear(int $jahr, string $sectionId = ''): array
    {
        $sec = $sectionId !== '' ? $sectionId : $this->findLibraryId('movie');
        return $sec === '' ? [] : $this->libraryItems($sec, ['type' => self::TYP_FILM, 'year' => $jahr]);
    }

    public function moviesByGenre(string $genre, string $sectionId = ''): array
    {
        $sec = $sectionId !== '' ? $sectionId : $this->findLibraryId('movie');
        return $sec === '' ? [] : $this->libraryItems($sec, ['type' => self::TYP_FILM, 'genre' => $genre]);
    }

    // ---------- Serien ----------

    public function shows(string $sectionId = '', int $offset = 0, int $limit = 0): array
    {
        $sec = $sectionId !== '' ? $sectionId : $this->findLibraryId('show');
        return $sec === '' ? [] : $this->libraryItems($sec, ['type' => self::TYP_SERIE], $offset, $limit);
    }

    public function searchShows(string $query, string $sectionId = ''): array
    {
        $sec = $sectionId !== '' ? $sectionId : $this->findLibraryId('show');
        return $sec === '' ? [] : $this->librarySearch($sec, $query, self::TYP_SERIE);
    }

    /** Staffeln einer Serie. */
    public function seasons(string $showId): array
    {
        return $this->children($showId);
    }

    /** Folgen einer Staffel. */
    public function episodes(string $seasonId): array
    {
        return $this->children($seasonId);
    }

    /**
     * Alle Folgen einer Serie ueber alle Staffeln.
     *
     * Plex bietet dafuer keinen eigenen Endpunkt - also Staffel fuer Staffel. Bei langen
     * Serien sind das entsprechend viele Abrufe; wer nur eine Staffel braucht, nimmt
     * episodes().
     */
    public function allEpisodes(string $showId): array
    {
        $out = [];
        foreach ($this->seasons($showId) as $st) {
            if (($st['typ'] ?? '') !== 'season') {
                continue;
            }
            foreach ($this->episodes((string) $st['id']) as $f) {
                $out[] = $f;
            }
        }
        return $out;
    }

    // ---------- Musik (ergaenzend zur Abspielseite) ----------

    public function artists(string $sectionId = '', int $offset = 0, int $limit = 0): array
    {
        $sec = $sectionId !== '' ? $sectionId : $this->findLibraryId('artist');
        return $sec === '' ? [] : $this->libraryItems($sec, ['type' => self::TYP_ARTIST], $offset, $limit);
    }

    public function albumsOf(string $artistId): array
    {
        return $this->children($artistId);
    }

    public function tracksOf(string $albumId): array
    {
        return $this->children($albumId);
    }

    /** Meistgespieltes - Plex sortiert dafuer nach 'plays'. */
    public function mostPlayed(string $sectionId = '', int $limit = 25): array
    {
        $sec = $sectionId !== '' ? $sectionId : $this->findLibraryId('artist');
        return $sec === '' ? [] : $this->libraryItems($sec, ['sort' => 'plays:desc'], 0, $limit);
    }

    // ---------- Live-TV und Aufnahmen ----------

    /**
     * Gibt es ueberhaupt einen Tuner?
     *
     * Die alte Klasse hat dafuer neun verschiedene Endpunkte durchprobiert, weil Plex sie
     * ueber die Jahre umbenannt hat. Geblieben ist /livetv/dvrs - die uebrigen Pfade
     * antworten auf aktuellen Fassungen nicht mehr und werden hier nicht mitgeschleppt.
     */
    public function liveTvAvailable(): bool
    {
        return $this->dvrs() !== [];
    }

    /** Die Aufnahmegeraete (DVR) des Servers. */
    public function dvrs(): array
    {
        $out = [];
        foreach ($this->liste($this->get('/livetv/dvrs')) as $d) {
            $out[] = [
                'id'       => (string) ($d['key'] ?? ''),
                'uuid'     => (string) ($d['uuid'] ?? ''),
                'sprache'  => (string) ($d['lineupTitle'] ?? ''),
                'letzter_abgleich' => (int) ($d['epgIdentifier'] ?? 0) ?: (int) ($d['lastEpgRefreshedAt'] ?? 0),
                'tuner'    => array_values(array_map(static fn($t) => [
                    'id'    => (string) ($t['key'] ?? ''),
                    'uuid'  => (string) ($t['uuid'] ?? ''),
                    'titel' => (string) ($t['title'] ?? ''),
                    'status'=> (string) ($t['status'] ?? ''),
                ], (array) ($d['Device'] ?? []))),
            ];
        }
        return $out;
    }

    /** Alle Tuner ueber alle Aufnahmegeraete hinweg. */
    public function tuners(): array
    {
        $out = [];
        foreach ($this->dvrs() as $d) {
            foreach ($d['tuner'] as $t) {
                $t['dvr'] = $d['id'];
                $out[] = $t;
            }
        }
        return $out;
    }

    /**
     * Die Senderliste.
     *
     * Sie haengt am Aufnahmegeraet, nicht am Server: ohne DVR gibt es keine Sender.
     */
    public function channels(): array
    {
        $out = [];
        foreach ($this->dvrs() as $d) {
            $j = $this->get('/livetv/dvrs/' . rawurlencode($d['id']) . '/channels');
            foreach ($this->liste($j) as $c) {
                $out[] = [
                    'id'      => (string) ($c['id'] ?? ($c['key'] ?? '')),
                    'nummer'  => (string) ($c['vcn'] ?? ($c['channelIdentifier'] ?? '')),
                    'titel'   => (string) ($c['title'] ?? ''),
                    'bild'    => $this->thumb((string) ($c['thumb'] ?? '')),
                    'dvr'     => $d['id'],
                    'aktiv'   => !isset($c['enabled']) || (bool) $c['enabled'],
                ];
            }
        }
        return $out;
    }

    /**
     * Programmzeitschrift in einem Zeitfenster.
     *
     * @param int $von Unix-Zeit, 0 = jetzt
     * @param int $bis Unix-Zeit, 0 = in vier Stunden
     */
    public function guide(int $von = 0, int $bis = 0, string $channelId = ''): array
    {
        $von = $von > 0 ? $von : time();
        $bis = $bis > 0 ? $bis : ($von + 4 * 3600);
        $p = ['beginsAt>' => $von, 'endsAt<' => $bis];
        $pfad = $channelId !== ''
            ? '/livetv/channels/' . rawurlencode($channelId) . '/guide'
            : '/livetv/guide';
        $out = [];
        foreach ($this->liste($this->getP($pfad, $p)) as $g) {
            $e = $this->eintrag($g);
            $e['beginn'] = (int) ($g['Media'][0]['beginsAt'] ?? ($g['beginsAt'] ?? 0));
            $e['ende']   = (int) ($g['Media'][0]['endsAt'] ?? ($g['endsAt'] ?? 0));
            $e['sender'] = (string) ($g['Media'][0]['channelTitle'] ?? '');
            $out[] = $e;
        }
        return $out;
    }

    /** Einzelne Sendung aus der Programmzeitschrift. */
    public function programDetails(string $programId): array
    {
        $l = $this->eintraege($this->get('/livetv/guide/' . rawurlencode($programId)));
        return $l ? $l[0] : [];
    }

    /** Aufnahmeregeln (Serienaufnahmen). */
    public function recordingRules(string $dvrId = ''): array
    {
        $pfad = $dvrId !== ''
            ? '/livetv/dvrs/' . rawurlencode($dvrId) . '/rules'
            : '/livetv/dvrs/rules';
        return $this->eintraege($this->get($pfad));
    }

    /** Geplante, noch nicht erfolgte Aufnahmen. */
    public function scheduledRecordings(string $dvrId = ''): array
    {
        $pfad = $dvrId !== ''
            ? '/livetv/dvrs/' . rawurlencode($dvrId) . '/schedules'
            : '/livetv/dvrs/schedules';
        return $this->eintraege($this->get($pfad));
    }

    /** Bereits erfolgte Aufnahmen. */
    public function recordings(string $dvrId = ''): array
    {
        $pfad = $dvrId !== ''
            ? '/livetv/dvrs/' . rawurlencode($dvrId) . '/recordings'
            : '/livetv/dvrs/recordings';
        return $this->eintraege($this->get($pfad));
    }

    /**
     * Eine Aufnahmeregel anlegen.
     *
     * @param string $programId Sendung aus der Programmzeitschrift
     * @param array  $opt       'typ' => 'single'|'series', 'dvr' => Geraet
     */
    public function createRecordingRule(string $programId, array $opt = []): bool
    {
        if ($programId === '') {
            return false;
        }
        $dvr  = (string) ($opt['dvr'] ?? '');
        $pfad = $dvr !== ''
            ? '/livetv/dvrs/' . rawurlencode($dvr) . '/rules'
            : '/livetv/dvrs/rules';
        $p = [
            'targetLibrarySectionID' => (string) ($opt['section'] ?? ''),
            'mediaGrabOperationType' => ((($opt['typ'] ?? 'single') === 'series') ? 'series' : 'single'),
            'key'                    => $programId,
        ];
        return !empty($this->send('POST', $pfad . '?' . http_build_query(array_filter($p)))['ok']);
    }

    public function deleteRecordingRule(string $ruleId, string $dvrId = ''): bool
    {
        $pfad = $dvrId !== ''
            ? '/livetv/dvrs/' . rawurlencode($dvrId) . '/rules/' . rawurlencode($ruleId)
            : '/livetv/dvrs/rules/' . rawurlencode($ruleId);
        return !empty($this->send('DELETE', $pfad)['ok']);
    }

    public function deleteScheduledRecording(string $scheduleId, string $dvrId = ''): bool
    {
        $pfad = $dvrId !== ''
            ? '/livetv/dvrs/' . rawurlencode($dvrId) . '/schedules/' . rawurlencode($scheduleId)
            : '/livetv/dvrs/schedules/' . rawurlencode($scheduleId);
        return !empty($this->send('DELETE', $pfad)['ok']);
    }

    /** Eine fertige Aufnahme loeschen - das ist eine Loeschung in der Bibliothek. */
    public function deleteRecording(string $ratingKey): bool
    {
        if ($ratingKey === '') {
            return false;
        }
        return !empty($this->send('DELETE', '/library/metadata/' . rawurlencode($ratingKey))['ok']);
    }
}

MediaProviders::register('plex', PlexProvider::class);

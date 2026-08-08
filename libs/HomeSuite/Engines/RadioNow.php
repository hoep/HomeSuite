<?php

declare(strict_types=1);

namespace Hoep\HomeSuite\Engines;

/**
 * RadioNow — "Was laeuft" fuer Radio, IPSSonos-FREI und Vendor-unabhaengig.
 *
 * Kette (alles ohne IPSSonos):
 *   1) Direkter HQ-Stream je Sender (statt TuneIn -> keine TuneIn-Werbung).
 *   2) Aktueller Titel aus dem Stream ueber ICY-Metadaten (StreamTitle).
 *   3) Song-COVER (nicht Senderlogo) via iTunes-/Deezer-Suche (Artist+Titel), ohne Key.
 *
 * "Naechster Titel" gibt es bei Live-Radio NICHT (kein Dienst kennt ihn) — nur bei
 * Playlists ueber die Warteschlange (SonosUpnp::readNext). Wortsender (Oe1) liefern
 * Programmtext statt Song -> dann kein Cover.
 *
 * Rein zustandslos/statisch: nur HTTP-GET (curl), kein Kernel/IPS. Das Cachen/Timen
 * uebernimmt der Aufrufer (Hook/Modul).
 */
final class RadioNow
{
    /**
     * Kuratierte Senderliste (die 10 angelegten Stationen). Best-Quality-Direktstream
     * (ICY-faehig) + Erkennungsmuster (Sonos-Sendername/URI -> Sender). ORF-Sender sind
     * markiert (koennen alternativ die ORF-Audio-API nutzen).
     * @var array<string,array{title:string,stream:string,orf:?string,match:string[]}>
     */
    public const STATIONS = [
        'oe3'      => ['title' => 'Hitradio Ö3',            'stream' => 'https://orf-live.ors-shoutcast.at/oe3-q2a', 'orf' => 'oe3', 'match' => ['oe3', 'hitradio ö3', 's8007']],
        'fm4'      => ['title' => 'FM4',                    'stream' => 'https://orf-live.ors-shoutcast.at/fm4-q2a', 'orf' => 'fm4', 'match' => ['fm4', 'fm 4']],
        'oe1'      => ['title' => 'Österreich 1',           'stream' => 'https://orf-live.ors-shoutcast.at/oe1-q2a', 'orf' => 'oe1', 'match' => ['oe1', 'österreich 1', 'ö1']],
        'ooe'      => ['title' => 'Radio der Region',   'stream' => 'https://orf-live.ors-shoutcast.at/ooe-q2a', 'orf' => 'ooe', 'match' => ['oberösterreich', 'ooe', 'radio oö']],
        'kronehit' => ['title' => 'Kronehit',               'stream' => 'http://onair-ha1.krone.at/kronehit1058.mp3', 'orf' => null, 'match' => ['kronehit', 'krone']],
        'antenne'  => ['title' => 'Antenne Bayern',         'stream' => 'https://stream.antenne.de/antenne/stream/mp3', 'orf' => null, 'match' => ['antenne bayern 103', 'antenne bayern (', 'antenne bayern pop']],
        'antenne_chillout' => ['title' => 'Antenne Bayern Chillout', 'stream' => 'https://stream.antenne.de/chillout/stream/mp3', 'orf' => null, 'match' => ['chillout']],
        'antenne_love'     => ['title' => 'Antenne Bayern Lovesongs', 'stream' => 'https://stream.antenne.de/lovesongs/stream/mp3', 'orf' => null, 'match' => ['lovesong']],
        'antenne_top40'    => ['title' => 'Antenne Bayern Top 40',    'stream' => 'https://stream.antenne.de/top-40/stream/mp3', 'orf' => null, 'match' => ['antenne bayern top']],
        'liferadio'        => ['title' => 'Life Radio',              'stream' => 'https://stream.liferadio.tirol/MUONLY/mp3-192/link', 'orf' => null, 'match' => ['life radio']],
    ];

    /** Sender-Key aus einem Sonos-Sendernamen/URI erkennen (fuer die Now-Anzeige). */
    public static function detect(string $stationNameOrUri): ?string
    {
        $s = mb_strtolower(trim($stationNameOrUri));
        if ($s === '') {
            return null;
        }
        // Spezifische zuerst (Sub-Kanaele vor dem Hauptsender).
        foreach (['antenne_top40', 'antenne_chillout', 'antenne_love', 'antenne', 'oe3', 'fm4', 'oe1', 'ooe', 'kronehit', 'liferadio'] as $key) {
            foreach (self::STATIONS[$key]['match'] as $m) {
                if (strpos($s, $m) !== false) {
                    return $key;
                }
            }
        }
        return null;
    }

    /**
     * Kompletter Now-Datensatz fuer einen Sender-Key: {station,title,artist,song,cover,isTalk}.
     * @return array<string,mixed>
     */
    public static function now(string $stationKey, bool $withCover = true): array
    {
        $st = self::STATIONS[$stationKey] ?? null;
        if ($st === null) {
            return ['ok' => false, 'error' => 'unknown station'];
        }
        $raw = self::icyTitle($st['stream']);
        [$artist, $title] = self::splitArtistTitle($raw, $st['title']);
        $out = ['ok' => true, 'station' => $stationKey, 'stationTitle' => $st['title'],
            'raw' => $raw, 'artist' => $artist, 'title' => $title, 'cover' => '', 'isTalk' => false];
        // Ohne "Artist - Title"-Trennung ist es i. d. R. ein Sender-Claim/Wortprogramm,
        // ebenso bei Nachrichten-Stichworten -> kein Song, kein Cover.
        if ($artist === '' || $title === '' || self::looksNonSong($title)) {
            $out['isTalk'] = true;
            return $out;
        }
        if ($withCover) {
            $out['cover'] = self::cover($artist, $title);
        }
        return $out;
    }

    /** Direkter HQ-Stream eines Senders (fuer Werbe-freie Wiedergabe statt TuneIn). */
    public static function streamUrl(string $stationKey): string
    {
        return (string) (self::STATIONS[$stationKey]['stream'] ?? '');
    }

    // ---- ICY -----------------------------------------------------------------

    /**
     * Liest den aktuellen StreamTitle ueber ICY-Metadaten (rohes Socket-Lesen, da
     * Shoutcast mit "ICY 200 OK" statt HTTP antwortet). '' wenn keine/leer.
     */
    public static function icyTitle(string $url, int $timeoutSec = 6): string
    {
        $p = parse_url($url);
        if ($p === false || empty($p['host'])) {
            return '';
        }
        $https = (($p['scheme'] ?? 'http') === 'https');
        $host  = $p['host'];
        $port  = (int) ($p['port'] ?? ($https ? 443 : 80));
        $path  = ($p['path'] ?? '/') . (isset($p['query']) ? ('?' . $p['query']) : '');
        $remote = ($https ? 'tls://' : '') . $host . ':' . $port;

        $errno = 0; $err = '';
        $fp = @stream_socket_client($remote, $errno, $err, 4, STREAM_CLIENT_CONNECT);
        if (!$fp) {
            return '';
        }
        stream_set_timeout($fp, $timeoutSec);
        $req = "GET " . $path . " HTTP/1.0\r\nHost: " . $host . "\r\n"
            . "User-Agent: HomeSuite/1.0\r\nIcy-MetaData: 1\r\nConnection: close\r\n\r\n";
        @fwrite($fp, $req);

        // Header bis Leerzeile lesen (ICY- oder HTTP-Statuszeile), icy-metaint finden.
        $header = ''; $deadline = time() + $timeoutSec;
        while (strpos($header, "\r\n\r\n") === false && !feof($fp) && time() < $deadline) {
            $header .= (string) fread($fp, 1);
        }
        if (!preg_match('/icy-metaint:\s*(\d+)/i', $header, $m)) {
            @fclose($fp);
            return '';
        }
        $metaint = (int) $m[1];

        // Audio ueberspringen, dann Metadatenblock lesen (bis zu 3 Fenster).
        $meta = '';
        for ($round = 0; $round < 3 && time() < $deadline; $round++) {
            $this_read = self::readN($fp, $metaint, $deadline);
            if (strlen($this_read) < $metaint) {
                break;
            }
            $lenByte = self::readN($fp, 1, $deadline);
            if ($lenByte === '') {
                break;
            }
            $len = ord($lenByte) * 16;
            if ($len > 0) {
                $meta = self::readN($fp, $len, $deadline);
                break;
            }
        }
        @fclose($fp);
        if ($meta !== '' && preg_match("/StreamTitle='(.*?)';/s", $meta, $mm)) {
            return self::normalize($mm[1]);
        }
        return '';
    }

    private static function readN($fp, int $n, int $deadline): string
    {
        $buf = '';
        while (strlen($buf) < $n && !feof($fp) && time() < $deadline) {
            $chunk = fread($fp, $n - strlen($buf));
            if ($chunk === '' || $chunk === false) {
                $info = stream_get_meta_data($fp);
                if (!empty($info['timed_out'])) {
                    break;
                }
                continue;
            }
            $buf .= $chunk;
        }
        return $buf;
    }

    private static function normalize(string $s): string
    {
        $s = trim($s);
        if ($s !== '' && !mb_check_encoding($s, 'UTF-8')) {
            $s = mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
        }
        return trim($s);
    }

    /** "Artist - Title | Senderzusatz" -> [artist, title]. */
    public static function splitArtistTitle(string $raw, string $stationTitle): array
    {
        $s = trim($raw);
        if ($s === '') {
            return ['', ''];
        }
        // Sender-Suffix nach " | " abschneiden (z. B. "... | FM4 Festivalradio").
        $s = preg_split('/\s+\|\s+/', $s)[0];
        if (strpos($s, ' - ') !== false) {
            [$a, $t] = array_map('trim', explode(' - ', $s, 2));
            return [$a, $t];
        }
        return ['', $s];
    }

    /** Heuristik: Nachrichten/Werbung im Titel -> kein Song-Cover. */
    private static function looksNonSong(string $title): bool
    {
        $s = mb_strtolower($title);
        if ($s === '') {
            return true;
        }
        foreach (['nachrichten', 'news', 'wetter', 'verkehr', 'werbung', 'advert', 'jingle'] as $kw) {
            if (strpos($s, $kw) !== false) {
                return true;
            }
        }
        return false;
    }

    /** Primaeren Artist extrahieren (feat./&/x/,-Zusaetze weg) fuer die Cover-Suche. */
    private static function primaryArtist(string $artist): string
    {
        $a = preg_replace('/\s*\(.*?\)\s*/', ' ', $artist);          // Klammern weg
        $a = preg_split('/\s+(feat\.?|ft\.?|vs\.?|x|&|,|\/)\s+/i', $a)[0];
        return trim($a);
    }

    // ---- Cover-Lookup (iTunes -> Deezer), ohne Key --------------------------

    /** Song-Cover-URL (600px) fuer Artist+Titel; '' wenn nichts gefunden. */
    public static function cover(string $artist, string $title): string
    {
        $title = trim($title);
        if ($title === '') {
            return '';
        }
        $prim = self::primaryArtist($artist);
        // Mehrere Such-Terme, vom praezisen zum lockeren.
        $terms = array_values(array_unique(array_filter([
            trim($prim . ' ' . $title),
            trim($artist . ' ' . $title),
            trim($title . ' ' . $prim),
        ])));
        foreach ($terms as $term) {
            $j = self::getJson('https://itunes.apple.com/search?media=music&entity=song&limit=1&term=' . rawurlencode($term));
            if (is_array($j) && !empty($j['results'][0]['artworkUrl100'])) {
                return str_replace('100x100bb', '600x600bb', (string) $j['results'][0]['artworkUrl100']);
            }
        }
        foreach ($terms as $term) {
            $d = self::getJson('https://api.deezer.com/search?limit=1&q=' . rawurlencode($term));
            if (is_array($d) && !empty($d['data'][0]['album']['cover_big'])) {
                return (string) $d['data'][0]['album']['cover_big'];
            }
        }
        return '';
    }

    private static function getJson(string $url, int $timeoutSec = 6)
    {
        $s = self::httpGet($url, $timeoutSec);
        if ($s === '') {
            return null;
        }
        $j = json_decode($s, true);
        return is_array($j) ? $j : null;
    }

    private static function httpGet(string $url, int $timeoutSec = 6): string
    {
        // Portabel: curl falls vorhanden, sonst file_get_contents (allow_url_fopen).
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $timeoutSec,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_HTTPHEADER     => ['User-Agent: HomeSuite/1.0'],
                CURLOPT_FOLLOWLOCATION => true,
            ]);
            $r = curl_exec($ch);
            curl_close($ch);
            if ($r !== false) {
                return (string) $r;
            }
        }
        $ctx = stream_context_create(['http' => [
            'method' => 'GET', 'timeout' => $timeoutSec,
            'header' => "User-Agent: HomeSuite/1.0\r\n", 'ignore_errors' => true,
        ], 'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
        $r = @file_get_contents($url, false, $ctx);
        return $r === false ? '' : (string) $r;
    }
}

<?php

declare(strict_types=1);

namespace Hoep\HomeSuite\HAL;

/**
 * SonosUpnp — nativer Sonos-Treiber (cleanroom UPnP/SOAP), implementiert IAudioRenderer
 * + IAudioStateReadable. ZUSTANDSLOS: kein persistenter Socket, keine Session; jede
 * Aktion ist ein synchroner HTTP-POST an den Player (Port 1400). Kein IPSLibrary-require
 * (macht IPSSonos loeschbar). Kommandowissen neu implementiert nach der offenen
 * UPnP-AVTransport/RenderingControl-Spezifikation.
 *
 * bind()-config:
 *   host    string  Player-IP (z. B. 192.168.1.40)   [Pflicht]
 *   rincon  string  Player-UUID (RINCON_...)          [fuer Gruppierung]
 *   port    int     Default 1400
 *   timeout int     HTTP-Timeout ms (Default 2000)
 *
 * Hinweis Polling-Kosten: readState() macht mehrere SOAP-Roundtrips. Das Modul sollte
 * das Refresh-Intervall im nativen Modus grosszuegig waehlen (Sonos hat kein Push).
 */
final class SonosUpnp implements IAudioRenderer, IAudioStateReadable, IAudioQueue
{
    private const AVT = 'urn:schemas-upnp-org:service:AVTransport:1';
    private const RC  = 'urn:schemas-upnp-org:service:RenderingControl:1';

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

    private function host(): string
    {
        return (string) ($this->cfg['host'] ?? '');
    }

    private function port(): int
    {
        return (int) ($this->cfg['port'] ?? 1400);
    }

    // ---- IDriver -------------------------------------------------------------

    public function capabilities(): AudioCapabilities
    {
        return new AudioCapabilities(true, true, true, true, ['favorite', 'station', 'playlist'], true, false, false);
    }

    public static function discover(int $timeoutMs = 2000): array
    {
        // SSDP M-SEARCH (best effort, ohne Kernel). Bei Bedarf ausbauen.
        $out = [];
        $msg = "M-SEARCH * HTTP/1.1\r\nHOST: 239.255.255.250:1900\r\nMAN: \"ssdp:discover\"\r\n"
            . "MX: 1\r\nST: urn:schemas-upnp-org:device:ZonePlayer:1\r\n\r\n";
        $sock = @fsockopen('udp://239.255.255.250', 1900, $e, $s, max(1, $timeoutMs / 1000));
        if (!$sock) {
            return $out;
        }
        @stream_set_timeout($sock, 0, $timeoutMs * 1000);
        @fwrite($sock, $msg);
        $seen = [];
        $until = microtime(true) + $timeoutMs / 1000;
        while (microtime(true) < $until) {
            $buf = @fread($sock, 2048);
            if ($buf === '' || $buf === false) {
                break;
            }
            if (preg_match('~LOCATION:\s*http://([\d.]+):1400~i', $buf, $m)) {
                $ip = $m[1];
                if (!isset($seen[$ip])) {
                    $seen[$ip] = true;
                    $out[] = ['title' => 'Sonos ' . $ip, 'config' => ['host' => $ip, 'model' => 'Sonos']];
                }
            }
        }
        @fclose($sock);
        return $out;
    }

    public function poll(): array
    {
        return []; // synchron ueber readState(); kein Frame-Strom
    }

    public function parseEvent(string $raw): ?AudioState
    {
        return null; // kein UPnP-Event-Abo in v1 (readState pollt)
    }

    // ---- Transport -----------------------------------------------------------

    public function play(): void
    {
        $this->soap(self::AVT, 'Play', '<InstanceID>0</InstanceID><Speed>1</Speed>');
    }

    public function pause(): void
    {
        $this->soap(self::AVT, 'Pause', '<InstanceID>0</InstanceID>');
    }

    public function stop(): void
    {
        $this->soap(self::AVT, 'Stop', '<InstanceID>0</InstanceID>');
    }

    public function next(): void
    {
        $this->soap(self::AVT, 'Next', '<InstanceID>0</InstanceID>');
    }

    public function previous(): void
    {
        $this->soap(self::AVT, 'Previous', '<InstanceID>0</InstanceID>');
    }

    public function seek(int $sec): void
    {
        $this->soap(self::AVT, 'Seek',
            '<InstanceID>0</InstanceID><Unit>REL_TIME</Unit><Target>' . $this->secToHms($sec) . '</Target>');
    }

    // ---- Lautstaerke ---------------------------------------------------------

    public function setVolume(int $pct): void
    {
        $pct = max(0, min(100, $pct));
        $this->soap(self::RC, 'SetVolume',
            '<InstanceID>0</InstanceID><Channel>Master</Channel><DesiredVolume>' . $pct . '</DesiredVolume>');
    }

    public function setMute(bool $on): void
    {
        $this->soap(self::RC, 'SetMute',
            '<InstanceID>0</InstanceID><Channel>Master</Channel><DesiredMute>' . ($on ? 1 : 0) . '</DesiredMute>');
    }

    // ---- Quelle / Inhalt -----------------------------------------------------

    public function selectInput(string $inputId): void
    {
        // Line-In etc. per x-rincon-stream:<uid> (Sonos-spezifisch).
        $uid = (string) ($this->cfg['rincon'] ?? '');
        if ($inputId === 'line-in' && $uid !== '') {
            $this->setUri('x-rincon-stream:' . $uid, '');
            $this->play();
        }
    }

    public function playSource(AudioSourceRef $ref): void
    {
        if ($ref->uri === '') {
            return;
        }
        // Uebersetzung nach Inhalts-Art (aus ContentRef): url/dlna = direkter Track,
        // spotify = Dienst-URI (x-sonos-spotify), station = Radio (mp3radio), sonst Queue.
        $ck = (string) ($ref->metadata['contentKind'] ?? '');
        if ($ck === 'spotify' || strncmp($ref->uri, 'spotify:', 8) === 0) {
            $this->playSpotify($ref->uri);
            return;
        }
        if ($ref->kind === AudioSourceRef::KIND_STATION || $ck === 'station') {
            $uri = (strpos($ref->uri, 'x-rincon') === 0) ? $ref->uri : ('x-rincon-mp3radio://' . $ref->uri);
            $this->setUri($uri, $ref->metadata['didl'] ?? $this->radioDidl($ref->title !== '' ? $ref->title : 'Radio'));
            $this->play();
            return;
        }
        // url/dlna/library/container: als Track in die (geleerte) Queue -> abspielen.
        // Verhalten unveraendert - nur ueber die drei benannten Schritte, damit dieselben
        // Bausteine auch fuer ganze Sammlungen taugen.
        $this->clearQueue();
        $this->addToQueue($ref);
        $this->startQueue(0);
    }

    // ==================================================================
    // Warteschlange (IAudioQueue)
    // ==================================================================

    public function clearQueue(): void
    {
        $this->soap(self::AVT, 'RemoveAllTracksFromQueue', '<InstanceID>0</InstanceID>');
    }

    /**
     * Einen Titel einreihen. Liefert NewQueueLength aus der Antwort, sonst 0 - der
     * Aufrufer erfaehrt damit, ob der Player die Einreihung wirklich angenommen hat.
     */
    public function addToQueue(AudioSourceRef $ref, bool $asNext = false): int
    {
        if ($ref->uri === '') {
            return 0;
        }
        $didl = $ref->metadata['didl'] ?? $this->trackDidl(
            $ref->title,
            (string) ($ref->metadata['cover'] ?? ''),
            (string) ($ref->metadata['artist'] ?? ''),
            (string) ($ref->metadata['album'] ?? ''),
            (string) ($ref->metadata['mime'] ?? ''),
            (int) ($ref->metadata['durationSec'] ?? 0),
            $ref->uri
        );
        $resp = $this->soap(self::AVT, 'AddURIToQueue',
            '<InstanceID>0</InstanceID><EnqueuedURI>' . $this->esc($ref->uri) . '</EnqueuedURI>'
            . '<EnqueuedURIMetaData>' . $this->esc($didl) . '</EnqueuedURIMetaData>'
            . '<DesiredFirstTrackNumberEnqueued>0</DesiredFirstTrackNumberEnqueued>'
            . '<EnqueueAsNext>' . ($asNext ? '1' : '0') . '</EnqueueAsNext>');
        return preg_match('~<NewQueueLength>(\d+)</NewQueueLength>~', $resp, $m) ? (int) $m[1] : 0;
    }

    /**
     * Einen Titel aus der Warteschlange werfen. Sonos zaehlt ab 1, unsere Listen ab 0.
     * UpdateID 0 heisst „ohne Versionspruefung" - eine echte ID muesste sonst aus einem
     * vorherigen Browse stammen und waere zwischen Anzeige und Klick laengst veraltet.
     * Die Antwort traegt die neue UpdateID; fehlt sie, hat der Player nicht angenommen.
     */
    public function removeFromQueue(int $index): bool
    {
        if ($index < 0) {
            return false;
        }
        $resp = $this->soap(self::AVT, 'RemoveTrackRangeFromQueue',
            '<InstanceID>0</InstanceID><UpdateID>0</UpdateID>'
            . '<StartingIndex>' . ($index + 1) . '</StartingIndex>'
            . '<NumberOfTracks>1</NumberOfTracks>');
        return $resp !== '' && stripos($resp, 'NewUpdateID') !== false;
    }

    public function startQueue(int $index = 0): void
    {
        $uid = (string) ($this->cfg['rincon'] ?? '');
        if ($uid !== '') {
            $this->setUri('x-rincon-queue:' . $uid . '#0', '');
        }
        if ($index > 0) {
            // Sonos zaehlt Titel ab 1, unsere Listen ab 0.
            $this->soap(self::AVT, 'Seek',
                '<InstanceID>0</InstanceID><Unit>TRACK_NR</Unit><Target>' . ($index + 1) . '</Target>');
        }
        $this->play();
    }

    /**
     * Eine ganze Sammlung abspielen oder anhaengen.
     *
     * $mode 'replace' leert vorher, 'append' haengt an die laufende Warteschlange an und
     * laesst die Wiedergabe in Ruhe. Rueckgabe: wie viele Titel der Player angenommen hat -
     * die Oberflaeche meldet diese Zahl, nicht die gewuenschte.
     */
    public function playSourceList(array $refs, string $mode = 'replace'): int
    {
        $refs = array_values(array_filter($refs, static fn($r) => $r instanceof AudioSourceRef && $r->uri !== ''));
        if ($refs === []) {
            return 0;
        }
        $anhaengen = ($mode === 'append');
        if (!$anhaengen) {
            $this->clearQueue();
        }
        $n = 0;
        foreach ($refs as $r) {
            if ($this->addToQueue($r) > 0 || $n === 0) {
                $n++;
            }
        }
        if (!$anhaengen) {
            $this->startQueue(0);
        }
        return $n;
    }

    /** Spotify-URI (spotify:track|album|playlist:ID) auf Sonos abspielen (x-sonos-spotify). */
    private function playSpotify(string $spUri): void
    {
        if (!preg_match('~spotify:(track|album|playlist|artist):([A-Za-z0-9]+)~', $spUri, $m)) {
            return;
        }
        $type = $m[1];
        $enc  = rawurlencode('spotify:' . $type . ':' . $m[2]);
        $sid  = (int) ($this->cfg['spotifySid'] ?? 9);   // Household-spezifisch (Default 9)
        $sn   = (int) ($this->cfg['spotifySn'] ?? 1);
        if ($type === 'track') {
            $uri = 'x-sonos-spotify:' . $enc . '?sid=' . $sid . '&flags=8224&sn=' . $sn;
            $this->soap(self::AVT, 'RemoveAllTracksFromQueue', '<InstanceID>0</InstanceID>');
            $this->soap(self::AVT, 'AddURIToQueue',
                '<InstanceID>0</InstanceID><EnqueuedURI>' . $this->esc($uri) . '</EnqueuedURI>'
                . '<EnqueuedURIMetaData></EnqueuedURIMetaData>'
                . '<DesiredFirstTrackNumberEnqueued>0</DesiredFirstTrackNumberEnqueued><EnqueueAsNext>0</EnqueueAsNext>');
        } else {
            // Container (Playlist/Album): x-rincon-cpcontainer + Queue
            $cont = 'x-rincon-cpcontainer:1006206c' . $enc . '?sid=' . $sid . '&flags=8300&sn=' . $sn;
            $this->soap(self::AVT, 'RemoveAllTracksFromQueue', '<InstanceID>0</InstanceID>');
            $this->soap(self::AVT, 'AddURIToQueue',
                '<InstanceID>0</InstanceID><EnqueuedURI>' . $this->esc($cont) . '</EnqueuedURI>'
                . '<EnqueuedURIMetaData></EnqueuedURIMetaData>'
                . '<DesiredFirstTrackNumberEnqueued>0</DesiredFirstTrackNumberEnqueued><EnqueueAsNext>0</EnqueueAsNext>');
        }
        $uid = (string) ($this->cfg['rincon'] ?? '');
        if ($uid !== '') {
            $this->setUri('x-rincon-queue:' . $uid . '#0', '');
        }
        $this->play();
    }

    /**
     * DIDL fuer einen einzelnen Musik-Track (direkte URL).
     *
     * Sobald mehr als ein Titel in der Warteschlange steht, ist sie die Anzeige-Wahrheit:
     * ohne Interpret, Album und Dauer stuenden dort vierzehnmal nur nackte Titel. Die
     * Zusatzangaben sind daher kein Schmuck, sondern das, was die Liste lesbar macht.
     */
    private function trackDidl(string $title, string $cover, string $artist = '', string $album = '', string $mime = '', int $sec = 0, string $uri = ''): string
    {
        return '<DIDL-Lite xmlns:dc="http://purl.org/dc/elements/1.1/" '
            . 'xmlns:upnp="urn:schemas-upnp-org:metadata-1-0/upnp/" '
            . 'xmlns="urn:schemas-upnp-org:metadata-1-0/DIDL-Lite/"><item id="0" parentID="-1" restricted="true">'
            . '<dc:title>' . $this->esc($title !== '' ? $title : 'Titel') . '</dc:title>'
            . ($artist !== '' ? '<dc:creator>' . $this->esc($artist) . '</dc:creator>' : '')
            . ($artist !== '' ? '<upnp:artist>' . $this->esc($artist) . '</upnp:artist>' : '')
            . ($album !== '' ? '<upnp:album>' . $this->esc($album) . '</upnp:album>' : '')
            . '<upnp:class>object.item.audioItem.musicTrack</upnp:class>'
            . ($cover !== '' ? '<upnp:albumArtURI>' . $this->esc($cover) . '</upnp:albumArtURI>' : '')
            // <res> mit dem vom Anbieter GEMELDETEN Dateityp (nicht geraten) und der Adresse.
            // Ohne dieses Element verwirft Sonos die Beschreibung und zeigt bis zum Vorladen
            // den nackten Dateinamen; die Dauer wird als Attribut mitgegeben.
            . ($uri !== '' ? '<res protocolInfo="http-get:*:' . $this->esc($mime !== '' ? $mime : 'audio/mpeg') . ':*"'
                . ($sec > 0 ? sprintf(' duration="%d:%02d:%02d"', intdiv($sec, 3600), intdiv($sec % 3600, 60), $sec % 60) : '')
                . '>' . $this->esc($uri) . '</res>' : '')
            . '</item></DIDL-Lite>';
    }

    public function playAnnouncement(string $uri, int $volume = 0): void
    {
        // Clip/Announcement (unterbrechende Durchsage) — Ausbaustufe (benoetigt DIDL + Restore).
        if ($uri !== '') {
            $this->setUri($uri, '');
            if ($volume > 0) {
                $this->setVolume($volume);
            }
            $this->play();
        }
    }

    public function setPlayMode(int $repeat, bool $shuffle): void
    {
        $mode = 'NORMAL';
        if ($shuffle && $repeat !== AudioState::REPEAT_OFF) {
            $mode = 'SHUFFLE';                 // Sonos: SHUFFLE = shuffle + repeat all
        } elseif ($shuffle) {
            $mode = 'SHUFFLE_NOREPEAT';
        } elseif ($repeat === AudioState::REPEAT_ALL) {
            $mode = 'REPEAT_ALL';
        } elseif ($repeat === AudioState::REPEAT_ONE) {
            $mode = 'REPEAT_ONE';
        }
        $this->soap(self::AVT, 'SetPlayMode', '<InstanceID>0</InstanceID><NewPlayMode>' . $mode . '</NewPlayMode>');
    }

    public function listFavorites(): array
    {
        return $this->browseList('FV:2', 0, 100, AudioSourceRef::KIND_FAVORITE);
    }

    public function listPlaylists(): array
    {
        return $this->browseList('SQ:', 0, 100, AudioSourceRef::KIND_PLAYLIST);
    }

    public function browse(string $containerId, int $offset, int $limit): AudioBrowseResult
    {
        $items = $this->browseList($containerId, $offset, $limit);
        return new AudioBrowseResult($items, count($items), $offset);
    }

    // ---- Gruppierung ---------------------------------------------------------

    public function setGroupMembers(string $coordinatorUid, array $memberUids): void
    {
        $self = (string) ($this->cfg['rincon'] ?? '');
        if ($self === '') {
            return;
        }
        if ($coordinatorUid === $self) {
            // Ich soll Koordinator sein. Zwei Faelle, die frueher beide als "nichts tun"
            // behandelt wurden - und deshalb liess sich eine Gruppe nie aufloesen:
            //
            //  a) Ich bin bereits eigenstaendig -> wirklich nichts zu tun; die Mitglieder
            //     treten von sich aus bei (der Aufrufer spricht sie einzeln an).
            //  b) Ich haenge noch in der Gruppe eines anderen -> "ich bin jetzt Koordinator"
            //     heisst dann: austreten und die eigene Warteschlange uebernehmen. Genau das
            //     meint das Trennen (mgmtUngroup ruft setGroupMembers(self, [self])).
            if (($this->readGroup()['role'] ?? '') === 'member') {
                $this->setUri('x-rincon-queue:' . $self . '#0', '');
            }
            return;
        }
        if (in_array($self, $memberUids, true)) {
            // Diesem Koordinator beitreten.
            $this->setUri('x-rincon:' . $coordinatorUid, '');
        } else {
            // Aus der Gruppe loesen -> eigener Koordinator (eigene Queue).
            $this->setUri('x-rincon-queue:' . $self . '#0', '');
        }
    }

    public function setGroupVolume(int $pct): void
    {
        // Ohne GroupRenderingControl setzen wir die eigene Lautstaerke (Koordinator).
        $this->setVolume($pct);
    }

    // ---- IAudioStateReadable -------------------------------------------------

    public function readState(): AudioState
    {
        $ti = $this->soap(self::AVT, 'GetTransportInfo', '<InstanceID>0</InstanceID>');
        $pi = $this->soap(self::AVT, 'GetPositionInfo', '<InstanceID>0</InstanceID>');
        $gv = $this->soap(self::RC, 'GetVolume', '<InstanceID>0</InstanceID><Channel>Master</Channel>');
        $gm = $this->soap(self::RC, 'GetMute', '<InstanceID>0</InstanceID><Channel>Master</Channel>');

        $state = strtoupper($this->tag($ti, 'CurrentTransportState'));
        $meta  = $this->unescapeXml($this->tag($pi, 'TrackMetaData'));

        return new AudioState(
            $state === 'PLAYING',
            $this->tag($meta, 'dc:title'),
            $this->tag($meta, 'dc:creator'),
            $this->tag($meta, 'upnp:album'),
            '',
            $this->tag($meta, 'upnp:albumArtURI'),
            $this->hmsToSec($this->tag($pi, 'RelTime')),
            $this->hmsToSec($this->tag($pi, 'TrackDuration')),
            (int) $this->tag($gv, 'CurrentVolume'),
            $this->tag($gm, 'CurrentMute') === '1',
            AudioState::REPEAT_OFF,
            false,
            true,
            // ERREICHBARKEIT statt "ist konfiguriert": vorher stand hier host()!=='' - damit
            // meldete JEDER konfigurierte Player "online", auch ein stromlos abgeschalteter.
            // Fuer die Ein/Aus-Anzeige ist aber genau das die einzige ehrliche Quelle: hat der
            // Player auf die Abfrage geantwortet? Ein ausgeschalteter Sonos antwortet nicht.
            $ti !== '',
            $this->sourceType($this->tag($pi, 'TrackURI'))
        );
    }

    /**
     * "Was kommt als Naechstes": naechster Titel der Warteschlange (Q:0). Bei Radio/
     * Livestream ODER am Ende der Queue -> [] (kein naechster Titel). PURE-ish (SOAP-GET).
     * @return array{title:string,artist:string,album:string,coverUri:string}|array{}
     */
    public function readNext(): array
    {
        $pi  = $this->soap(self::AVT, 'GetPositionInfo', '<InstanceID>0</InstanceID>');
        $uri = $this->tag($pi, 'TrackURI');
        // Radio/Stream/Line-In/Gruppe haben keine Queue-Nachfolge.
        if ($uri === '' || preg_match('~^(x-sonosapi-stream|x-rincon-mp3radio|x-rincon-stream|x-rincon:)~', $uri)) {
            return [];
        }
        $track = (int) $this->tag($pi, 'Track');       // 1-basiert = aktueller
        if ($track <= 0) {
            return [];
        }
        // naechster Eintrag: 0-basierter Index == aktueller 1-basierter Track.
        $resp = $this->soap('urn:schemas-upnp-org:service:ContentDirectory:1', 'Browse',
            '<ObjectID>Q:0</ObjectID><BrowseFlag>BrowseDirectChildren</BrowseFlag><Filter>*</Filter>'
            . '<StartingIndex>' . $track . '</StartingIndex><RequestedCount>1</RequestedCount><SortCriteria></SortCriteria>',
            '/MediaServer/ContentDirectory/Control');
        $didl = $this->unescapeXml($this->tag($resp, 'Result'));
        if ($didl === '' || stripos($didl, '<item') === false) {
            return [];
        }
        return [
            'title'    => $this->tag($didl, 'dc:title'),
            'artist'   => $this->tag($didl, 'dc:creator'),
            'album'    => $this->tag($didl, 'upnp:album'),
            'coverUri' => $this->tag($didl, 'upnp:albumArtURI'),
        ];
    }

    /**
     * Radio-Info direkt vom Player: aktueller Sendername + laufender Titel (streamContent)
     * + CurrentURI. @return array{isRadio:bool,station:string,uri:string,streamContent:string}
     */
    public function radioInfo(): array
    {
        $mi  = $this->soap(self::AVT, 'GetMediaInfo', '<InstanceID>0</InstanceID>');
        $pi  = $this->soap(self::AVT, 'GetPositionInfo', '<InstanceID>0</InstanceID>');
        $uri = $this->tag($pi, 'TrackURI');
        if ($uri === '') {
            $uri = $this->tag($mi, 'CurrentURI');
        }
        $isRadio = (bool) preg_match('~(x-sonosapi-stream|x-rincon-mp3radio|mp3radio|sonos.*stream)~i', $uri)
            || stripos($this->sourceType($uri), 'radio') !== false;
        $curMeta = $this->unescapeXml($this->tag($mi, 'CurrentURIMetaData'));
        $station = $this->tag($curMeta, 'dc:title');
        $trkMeta = $this->unescapeXml($this->tag($pi, 'TrackMetaData'));
        $stream  = $this->tag($trkMeta, 'r:streamContent');
        if ($stream === '') {
            $stream = $this->tag($trkMeta, 'streamContent');
        }
        return ['isRadio' => $isRadio, 'station' => $station, 'uri' => $uri, 'streamContent' => $stream];
    }

    public function readGroup(): array
    {
        $self = (string) ($this->cfg['rincon'] ?? '');
        $pi = $this->soap(self::AVT, 'GetPositionInfo', '<InstanceID>0</InstanceID>');
        $uri = $this->tag($pi, 'TrackURI');
        if (preg_match('~^x-rincon:(RINCON_[0-9A-F]+)~i', $uri, $m)) {
            return ['role' => 'member', 'coordinatorUid' => $m[1], 'memberUids' => [$self]];
        }
        return ['role' => 'standalone', 'coordinatorUid' => $self, 'memberUids' => ($self !== '' ? [$self] : [])];
    }

    // ---- SOAP/HTTP + Parsing -------------------------------------------------

    /** Minimales DIDL fuer einen Radiosender (audioBroadcast), damit Sonos ihn als Sender fuehrt. */
    private function radioDidl(string $title): string
    {
        return '<DIDL-Lite xmlns:dc="http://purl.org/dc/elements/1.1/" '
            . 'xmlns:upnp="urn:schemas-upnp-org:metadata-1-0/upnp/" '
            . 'xmlns="urn:schemas-upnp-org:metadata-1-0/DIDL-Lite/">'
            . '<item id="R:0/0/0" parentID="R:0/0" restricted="true"><dc:title>' . $this->esc($title) . '</dc:title>'
            . '<upnp:class>object.item.audioItem.audioBroadcast</upnp:class>'
            . '<desc id="cdudn" nameSpace="urn:schemas-rinconnetworks-com:metadata-1-0/">SA_RINCON65031_</desc></item></DIDL-Lite>';
    }

    private function setUri(string $uri, string $didl): void
    {
        $this->soap(self::AVT, 'SetAVTransportURI',
            '<InstanceID>0</InstanceID><CurrentURI>' . $this->esc($uri) . '</CurrentURI>'
            . '<CurrentURIMetaData>' . $this->esc($didl) . '</CurrentURIMetaData>');
    }

    /**
     * ContentDirectory-Browse -> AudioSourceRef[] (title/uri).
     *
     * $kind sagt, als WAS die Treffer zurueckkommen. Vorher stand hier fest
     * KIND_FAVORITE - damit meldete listPlaylists() seine Ergebnisse als Favoriten,
     * und ein Aufrufer konnte die beiden Listen nicht auseinanderhalten.
     */
    private function browseList(string $container, int $offset = 0, int $limit = 100,
                               string $kind = AudioSourceRef::KIND_FAVORITE): array
    {
        $svc = 'urn:schemas-upnp-org:service:ContentDirectory:1';
        $resp = $this->soap($svc, 'Browse',
            '<ObjectID>' . $this->esc($container) . '</ObjectID><BrowseFlag>BrowseDirectChildren</BrowseFlag>'
            . '<Filter>*</Filter><StartingIndex>' . $offset . '</StartingIndex><RequestedCount>' . $limit . '</RequestedCount>'
            . '<SortCriteria></SortCriteria>',
            '/MediaServer/ContentDirectory/Control');
        $didl = $this->unescapeXml($this->tag($resp, 'Result'));
        $out = [];
        if (preg_match_all('~<item[^>]*id="([^"]*)"[^>]*>(.*?)</item>~s', $didl, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $it) {
                $out[] = new AudioSourceRef($kind, $it[1],
                    $this->tag($it[2], 'dc:title'), $this->tag($it[2], 'res'));
            }
        }
        return $out;
    }

    /**
     * WARTESCHLANGE des Players lesen (Sonos-Container 'Q:0').
     *
     * browseList() liefert nur Titel und Stream-Adresse - fuer die Anzeige braucht es
     * Interpret, Album, Dauer und Coverbild, deshalb hier eine eigene DIDL-Auswertung.
     * Zusaetzlich wird die laufende Spur ermittelt (AVTransport CurrentTrack, 1-basiert),
     * damit die Anzeige "laeuft gerade" von "als Naechstes" trennen kann.
     *
     * Bewusst KEIN Zwischenspeicher: die Warteschlange aendert sich beim Abspielen staendig,
     * und ein veralteter Stand waere schlimmer als eine Abfrage mehr.
     *
     * @return array{items:array<int,array<string,mixed>>,current:int,total:int}
     */
    public function queueList(int $offset = 0, int $limit = 60): array
    {
        $svc  = 'urn:schemas-upnp-org:service:ContentDirectory:1';
        $resp = $this->soap($svc, 'Browse',
            '<ObjectID>Q:0</ObjectID><BrowseFlag>BrowseDirectChildren</BrowseFlag>'
            . '<Filter>*</Filter><StartingIndex>' . max(0, $offset) . '</StartingIndex>'
            . '<RequestedCount>' . max(1, $limit) . '</RequestedCount><SortCriteria></SortCriteria>',
            '/MediaServer/ContentDirectory/Control');
        $didl  = $this->unescapeXml($this->tag($resp, 'Result'));
        $total = (int) $this->tag($resp, 'TotalMatches');
        $items = [];
        if (preg_match_all('~<item[^>]*id="([^"]*)"[^>]*>(.*?)</item>~s', $didl, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $i => $it) {
                $x   = $it[2];
                $res = '';
                // res traegt die Dauer als Attribut: <res duration="0:04:12" ...>
                $dur = '';
                if (preg_match('~<res\b([^>]*)>(.*?)</res>~s', $x, $rm)) {
                    $res = $this->unescapeXml($rm[2]);
                    if (preg_match('~duration="([^"]+)"~', $rm[1], $dm)) { $dur = $dm[1]; }
                }
                $items[] = [
                    'idx'      => $offset + $i,             // 0-basiert, wie die Anzeige zaehlt
                    'id'       => $it[1],
                    'title'    => $this->unescapeXml($this->tag($x, 'dc:title')),
                    'artist'   => $this->unescapeXml($this->tag($x, 'dc:creator')),
                    'album'    => $this->unescapeXml($this->tag($x, 'upnp:album')),
                    'cover'    => $this->unescapeXml($this->tag($x, 'upnp:albumArtURI')),
                    'duration' => $this->trimDur($dur),
                    'uri'      => $res,
                ];
            }
        }
        // Laufende Spur: 1-basiert im AVTransport, hier auf 0-basiert gebracht.
        $pos = $this->soap(self::AVT, 'GetPositionInfo', '<InstanceID>0</InstanceID>');
        $cur = max(0, ((int) $this->tag($pos, 'Track')) - 1);
        return ['items' => $items, 'current' => $cur, 'total' => $total ?: count($items)];
    }

    /** "0:04:12" -> "4:12" (fuehrende Nullstunde weg, Minuten ohne fuehrende Null). */
    private function trimDur(string $d): string
    {
        $p = explode(':', trim($d));
        if (count($p) !== 3) { return trim($d); }
        $h = (int) $p[0]; $m = (int) $p[1]; $s = (int) $p[2];
        return $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%d:%02d', $m, $s);
    }

    /** Spur der Warteschlange direkt anspringen (1-basiert im AVTransport). */
    public function playQueueIndex(int $idx): void
    {
        $this->soap(self::AVT, 'Seek',
            '<InstanceID>0</InstanceID><Unit>TRACK_NR</Unit><Target>' . (max(0, $idx) + 1) . '</Target>');
        $this->play();
    }

    /**
     * Wer gehoert zum Haushalt, und unter welcher Adresse?
     *
     * Jeder erreichbare Player kennt die GANZE Anlage - man muss also nicht jede Box
     * einzeln fragen, was praktisch ist, weil selten alle eingeschaltet sind. Die
     * Antwort ist doppelt XML-kodiert (ein XML-Dokument als Textinhalt eines anderen),
     * deshalb wird zweimal dekodiert.
     *
     * Die UUID (RINCON_...) ist die dauerhafte Kennung einer Box, die IP nicht: sie
     * kommt per DHCP und wandert. Genau dafuer ist diese Abfrage da.
     *
     * @return array<string,array{ip:string,name:string,invisible:bool}> UUID => Angaben
     */
    public function zoneGroupTopology(): array
    {
        $xml = $this->soap(
            'urn:schemas-upnp-org:service:ZoneGroupTopology:1',
            'GetZoneGroupState',
            '',
            '/ZoneGroupTopology/Control'
        );
        if ($xml === '') {
            return [];
        }
        $xml = html_entity_decode($xml, ENT_QUOTES | ENT_XML1);
        $xml = html_entity_decode($xml, ENT_QUOTES | ENT_XML1);
        $out = [];
        if (!preg_match_all('~<ZoneGroupMember\b([^>]*)>~i', $xml, $mm)) {
            return [];
        }
        foreach ($mm[1] as $attr) {
            if (!preg_match('~UUID="([^"]+)"~i', $attr, $u)) {
                continue;
            }
            $ip = '';
            if (preg_match('~Location="https?://([0-9.]+):~i', $attr, $l)) {
                $ip = $l[1];
            }
            preg_match('~ZoneName="([^"]*)"~i', $attr, $n);
            $out[$u[1]] = [
                'ip'        => $ip,
                'name'      => $n[1] ?? '',
                // Unsichtbare Mitglieder sind z. B. der zweite Lautsprecher eines
                // Stereopaars oder ein Sub - eigene UUID, aber keine eigene Zone.
                'invisible' => (bool) preg_match('~Invisible="1"~i', $attr),
            ];
        }
        return $out;
    }

    /**
     * Synchroner SOAP-POST an den Player. Liefert den Response-Body (oder '').
     * Kein Kernel/IPS — reines stream-context-HTTP (portabel, kein persistenter Socket).
     */
    private function soap(string $service, string $action, string $argsXml, ?string $path = null): string
    {
        $host = $this->host();
        if ($host === '') {
            return '';
        }
        $ctrl = $path ?? ('/MediaRenderer/' . $this->serviceCtrl($service) . '/Control');
        $body = '<?xml version="1.0"?>'
            . '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/" '
            . 's:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/"><s:Body>'
            . '<u:' . $action . ' xmlns:u="' . $service . '">' . $argsXml . '</u:' . $action . '>'
            . '</s:Body></s:Envelope>';
        $to = (int) ($this->cfg['timeout'] ?? 2000);
        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: text/xml; charset=\"utf-8\"\r\n"
                . 'SOAPACTION: "' . $service . '#' . $action . "\"\r\n",
            'content'       => $body,
            'timeout'       => max(0.5, $to / 1000),
            'ignore_errors' => true,
        ]]);
        $url = 'http://' . $host . ':' . $this->port() . $ctrl;
        $resp = @file_get_contents($url, false, $ctx);
        return $resp === false ? '' : (string) $resp;
    }

    private function serviceCtrl(string $service): string
    {
        if (strpos($service, 'AVTransport') !== false) {
            return 'AVTransport';
        }
        if (strpos($service, 'RenderingControl') !== false) {
            return 'RenderingControl';
        }
        return 'AVTransport';
    }

    /** Ersten Tag-Inhalt (ohne Namespace-Beachtung ausser explizit) extrahieren. */
    private function tag(string $xml, string $tag): string
    {
        if ($xml === '') {
            return '';
        }
        $t = preg_quote($tag, '~');
        // (?=[\s/>]) verhindert, dass ein Name als PRAEFIX eines laengeren trifft:
        // ohne das faengt <upnp:album ...> beim <upnp:albumArtURI> an, und weil dessen
        // Ende-Marke nicht </upnp:album> lautet, laeuft der Ausdruck bis zur echten
        // Ende-Marke durch und liefert die halbe Datenzeile als "Album".
        if (preg_match('~<' . $t . '(?=[\s/>])[^>]*>(.*?)</' . $t . '\s*>~s', $xml, $m)) {
            return html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_XML1);
        }
        return '';
    }

    private function unescapeXml(string $s): string
    {
        return html_entity_decode($s, ENT_QUOTES | ENT_XML1);
    }

    private function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1);
    }

    private function secToHms(int $sec): string
    {
        $sec = max(0, $sec);
        return sprintf('%d:%02d:%02d', intdiv($sec, 3600), intdiv($sec % 3600, 60), $sec % 60);
    }

    private function hmsToSec(string $t): int
    {
        $t = trim($t);
        if ($t === '' || stripos($t, 'NOT_IMPLEMENTED') !== false) {
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
        return (int) ($p[0] ?? 0);
    }

    private function sourceType(string $uri): string
    {
        if ($uri === '') {
            return '';
        }
        if (strpos($uri, 'x-sonosapi-stream') === 0 || strpos($uri, 'x-rincon-mp3radio') === 0) {
            return 'radio';
        }
        if (strpos($uri, 'x-rincon-stream') === 0) {
            return 'line-in';
        }
        if (strpos($uri, 'x-rincon:') === 0) {
            return 'group';
        }
        if (strpos($uri, 'x-sonos-spotify') !== false || strpos($uri, 'spotify') !== false) {
            return 'spotify';
        }
        return 'library';
    }
}

// Selbst-Registrierung bei der DriverFactory (Vendor-Treiber-Konvention).
DriverFactory::register('sonos-upnp', SonosUpnp::class);

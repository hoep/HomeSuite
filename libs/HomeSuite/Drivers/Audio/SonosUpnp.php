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
final class SonosUpnp implements IAudioRenderer, IAudioStateReadable
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
        $this->soap(self::AVT, 'RemoveAllTracksFromQueue', '<InstanceID>0</InstanceID>');
        $didl = $ref->metadata['didl'] ?? $this->trackDidl($ref->title, (string) ($ref->metadata['cover'] ?? ''));
        $this->soap(self::AVT, 'AddURIToQueue',
            '<InstanceID>0</InstanceID><EnqueuedURI>' . $this->esc($ref->uri) . '</EnqueuedURI>'
            . '<EnqueuedURIMetaData>' . $this->esc($didl) . '</EnqueuedURIMetaData>'
            . '<DesiredFirstTrackNumberEnqueued>0</DesiredFirstTrackNumberEnqueued><EnqueueAsNext>0</EnqueueAsNext>');
        $uid = (string) ($this->cfg['rincon'] ?? '');
        if ($uid !== '') {
            $this->setUri('x-rincon-queue:' . $uid . '#0', '');
        }
        $this->play();
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

    /** Minimales DIDL fuer einen einzelnen Musik-Track (direkte URL). */
    private function trackDidl(string $title, string $cover): string
    {
        return '<DIDL-Lite xmlns:dc="http://purl.org/dc/elements/1.1/" '
            . 'xmlns:upnp="urn:schemas-upnp-org:metadata-1-0/upnp/" '
            . 'xmlns="urn:schemas-upnp-org:metadata-1-0/DIDL-Lite/"><item id="0" parentID="-1" restricted="true">'
            . '<dc:title>' . $this->esc($title !== '' ? $title : 'Titel') . '</dc:title>'
            . '<upnp:class>object.item.audioItem.musicTrack</upnp:class>'
            . ($cover !== '' ? '<upnp:albumArtURI>' . $this->esc($cover) . '</upnp:albumArtURI>' : '')
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
        return $this->browseList('FV:2');       // Sonos Favorites-Container
    }

    public function listPlaylists(): array
    {
        return $this->browseList('SQ:');        // Sonos Playlists-Container
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
            // Ich bin Koordinator: Mitglieder joinen selbst (der Controller ruft sie einzeln).
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

    /** ContentDirectory-Browse -> AudioSourceRef[] (title/uri). */
    private function browseList(string $container, int $offset = 0, int $limit = 100): array
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
                $out[] = new AudioSourceRef(AudioSourceRef::KIND_FAVORITE, $it[1],
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
        if (preg_match('~<' . $t . '[^>]*>(.*?)</' . $t . '>~s', $xml, $m)) {
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

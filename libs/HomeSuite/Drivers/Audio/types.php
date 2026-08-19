<?php

declare(strict_types=1);

namespace Hoep\HomeSuite\HAL;

/**
 * Audio-HAL Value Objects (Vertrag 3, §2.3) — reine Datenhalter, KEIN Kernel-Zugriff.
 *
 * Von IDriver/IAudioRenderer referenziert (IDriver::parseEvent(): ?AudioState,
 * IAudioRenderer::capabilities(): AudioCapabilities, playSource(AudioSourceRef),
 * browse(): AudioBrowseResult). Liegen bewusst im Namespace Hoep\HomeSuite\HAL,
 * damit die Interface-Signaturen ohne Namespace-Import tragen.
 *
 * Grundsatz: unveraenderlich konstruiert (Konstruktor-Promotion), zusaetzlich ein
 * toArray() fuer den Manifest-/Reflect-Snapshot und ein fromArray() zum Rekonstruieren.
 * KEINE Kernel-Aufrufe (IPS_..., GetValue) hier — das Lesen von Variablen macht der Treiber.
 */

/**
 * AudioState — Now-Playing- + Wiedergabe-Zustand eines EINZELNEN Renderers.
 *
 * parseEvent()/readState() liefern diesen (ggf. als Delta: nicht bekannte Felder
 * bleiben auf ihrem Default). Gruppen-/Rollen-Infos gehoeren NICHT hierher, die
 * liefert readGroup()/setGroupMembers (B2, deklarativ).
 */
final class AudioState
{
    public const REPEAT_OFF = 0;
    public const REPEAT_ONE = 1;
    public const REPEAT_ALL = 2;

    public function __construct(
        public bool $playState = false,      // true = spielt gerade
        public string $title = '',
        public string $artist = '',
        public string $album = '',
        public string $albumArtist = '',
        public string $coverUri = '',        // BARE URL (kein HTMLBox-Snippet)
        public int $positionSec = 0,
        public int $durationSec = 0,
        public int $volume = 0,              // normalisiert 0..100
        public bool $mute = false,
        public int $repeat = self::REPEAT_OFF,
        public bool $shuffle = false,
        public bool $seekable = false,
        public bool $online = true,
        public string $source = ''           // Quellentyp (spotify/radio/line-in/...)
    ) {
    }

    public function toArray(): array
    {
        return [
            'playState'   => $this->playState,
            'title'       => $this->title,
            'artist'      => $this->artist,
            'album'       => $this->album,
            'albumArtist' => $this->albumArtist,
            'coverUri'    => $this->coverUri,
            'positionSec' => $this->positionSec,
            'durationSec' => $this->durationSec,
            'volume'      => $this->volume,
            'mute'        => $this->mute,
            'repeat'      => $this->repeat,
            'shuffle'     => $this->shuffle,
            'seekable'    => $this->seekable,
            'online'      => $this->online,
            'source'      => $this->source,
        ];
    }

    public static function fromArray(array $a): self
    {
        return new self(
            (bool) ($a['playState'] ?? false),
            (string) ($a['title'] ?? ''),
            (string) ($a['artist'] ?? ''),
            (string) ($a['album'] ?? ''),
            (string) ($a['albumArtist'] ?? ''),
            (string) ($a['coverUri'] ?? ''),
            (int) ($a['positionSec'] ?? 0),
            (int) ($a['durationSec'] ?? 0),
            (int) ($a['volume'] ?? 0),
            (bool) ($a['mute'] ?? false),
            (int) ($a['repeat'] ?? self::REPEAT_OFF),
            (bool) ($a['shuffle'] ?? false),
            (bool) ($a['seekable'] ?? false),
            (bool) ($a['online'] ?? true),
            (string) ($a['source'] ?? '')
        );
    }
}

/**
 * AudioCapabilities — real verfuegbare Teilmenge eines Treibers (IDriver::capabilities()).
 * transport ist praktisch immer true; seek/announce/grouping/queue/tone/sleepTimer
 * variieren je Vendor. sources listet unterstuetzte Quellen-Arten.
 */
final class AudioCapabilities
{
    /**
     * @param string[] $sources unterstuetzte Quellen-Arten (favorite|radio|playlist|preset|uri|line-in)
     */
    public function __construct(
        public bool $transport = true,
        public bool $seek = false,
        public bool $announce = false,
        public bool $grouping = false,
        public array $sources = [],
        public bool $queue = false,
        public bool $tone = false,
        public bool $sleepTimer = false
    ) {
    }

    public function toArray(): array
    {
        return [
            'transport'  => $this->transport,
            'seek'       => $this->seek,
            'announce'   => $this->announce,
            'grouping'   => $this->grouping,
            'sources'    => array_values($this->sources),
            'queue'      => $this->queue,
            'tone'       => $this->tone,
            'sleepTimer' => $this->sleepTimer,
        ];
    }

    public static function fromArray(array $a): self
    {
        return new self(
            (bool) ($a['transport'] ?? true),
            (bool) ($a['seek'] ?? false),
            (bool) ($a['announce'] ?? false),
            (bool) ($a['grouping'] ?? false),
            (array) ($a['sources'] ?? []),
            (bool) ($a['queue'] ?? false),
            (bool) ($a['tone'] ?? false),
            (bool) ($a['sleepTimer'] ?? false)
        );
    }
}

/**
 * AudioSourceRef — Referenz auf eine abspielbare Quelle (playSource()).
 * kind = favorite|playlist|station|uri|preset. id ist vendor-/kontextabhaengig
 * (Assoc-Index, Container-URI, Preset-Nummer, ...).
 */
final class AudioSourceRef
{
    public const KIND_FAVORITE = 'favorite';
    public const KIND_PLAYLIST = 'playlist';
    public const KIND_STATION  = 'station';
    public const KIND_URI      = 'uri';
    public const KIND_PRESET   = 'preset';

    public function __construct(
        public string $kind,
        public string $id,
        public string $title = '',
        public string $uri = '',
        public array $metadata = []
    ) {
    }

    public function toArray(): array
    {
        return [
            'kind'     => $this->kind,
            'id'       => $this->id,
            'title'    => $this->title,
            'uri'      => $this->uri,
            'metadata' => $this->metadata,
        ];
    }

    public static function fromArray(array $a): self
    {
        return new self(
            (string) ($a['kind'] ?? self::KIND_URI),
            (string) ($a['id'] ?? ''),
            (string) ($a['title'] ?? ''),
            (string) ($a['uri'] ?? ''),
            (array) ($a['metadata'] ?? [])
        );
    }
}

/**
 * ContentRef — RENDERER-UNABHAENGIGE Referenz auf Medien-Inhalt (Provider-Ebene).
 * Trennt Quelle (Spotify/Plex/Jellyfin/Audiobookshelf/Radio/lokal) vom Renderer
 * (Sonos/HEOS/...). Wo moeglich ist `uri` eine echte Stream-URL (universell spielbar);
 * bei DRM-Diensten (Spotify/Audible) traegt `uri` die Dienst-URI (z. B. spotify:...),
 * die der Renderer-Treiber in sein Schema uebersetzt.
 *
 * container=true -> abspielbare/aufklappbare Sammlung (Playlist/Album/Hoerbuch).
 */
final class ContentRef
{
    public function __construct(
        public string $provider,           // spotify|plex|jellyfin|audiobookshelf|radio|local|sonos
        public string $kind,               // url|dlna|spotify|audible|plex|library|station|preset|container
        public string $id = '',            // provider-interne Id (Container/Item)
        public string $uri = '',           // abspielbare URL/URI (leer -> erst resolve())
        public string $title = '',
        public string $artist = '',
        public string $album = '',
        public string $cover = '',
        public string $mime = '',
        public int $durationSec = 0,
        public bool $isContainer = false
    ) {
    }

    public function toArray(): array
    {
        return [
            'provider' => $this->provider, 'kind' => $this->kind, 'id' => $this->id, 'uri' => $this->uri,
            'title' => $this->title, 'artist' => $this->artist, 'album' => $this->album, 'cover' => $this->cover,
            'mime' => $this->mime, 'durationSec' => $this->durationSec, 'isContainer' => $this->isContainer,
        ];
    }

    public static function fromArray(array $a): self
    {
        return new self(
            (string) ($a['provider'] ?? ''), (string) ($a['kind'] ?? 'url'), (string) ($a['id'] ?? ''),
            (string) ($a['uri'] ?? ''), (string) ($a['title'] ?? ''), (string) ($a['artist'] ?? ''),
            (string) ($a['album'] ?? ''), (string) ($a['cover'] ?? ''), (string) ($a['mime'] ?? ''),
            (int) ($a['durationSec'] ?? 0), (bool) ($a['isContainer'] ?? false)
        );
    }

    /**
     * Sortiert eine Liste von ContentRefs nach Interpret/Autor, dann Titel (case-insensitive,
     * natuerliche Reihenfolge). Fuer Alben- und Hoerbuch-Listen — NICHT fuer Tracks (Reihenfolge!)
     * oder Playlists (die bleiben in Zuletzt-/API-Reihenfolge). Leerer Interpret sortiert ans Ende.
     *
     * @param ContentRef[] $refs
     * @return ContentRef[]
     */
    public static function sortByArtistTitle(array $refs): array
    {
        $key = static function (string $s): string {
            $s = trim($s);
            return $s === '' ? "\u{10FFFF}" : mb_strtolower($s, 'UTF-8');
        };
        usort($refs, static function (ContentRef $a, ContentRef $b) use ($key): int {
            return [$key($a->artist), $key($a->title)] <=> [$key($b->artist), $key($b->title)];
        });
        return $refs;
    }

    /**
     * ContentRef -> AudioSourceRef (fuer IAudioRenderer::playSource). Die eigentliche
     * Uebersetzung macht der Renderer anhand metadata.contentKind (url|dlna|spotify|
     * station|container|...). kind bleibt hier bewusst grob (STATION nur fuer Radio).
     */
    public function toSourceRef(): AudioSourceRef
    {
        $kind = ($this->kind === 'station') ? AudioSourceRef::KIND_STATION
            : (($this->kind === 'preset') ? AudioSourceRef::KIND_PRESET : AudioSourceRef::KIND_URI);
        return new AudioSourceRef($kind, $this->id !== '' ? $this->id : $this->uri, $this->title, $this->uri, [
            'provider'     => $this->provider,
            'contentKind'  => $this->kind,
            'cover'        => $this->cover,
            'isContainer'  => $this->isContainer,
            // Interpret, Album, Dateityp und Dauer wurden hier bisher weggeworfen. Solange
            // nur EIN Titel lief, fiel das nicht auf; sobald eine ganze Sammlung in der
            // Warteschlange steht, ist sie die Anzeige und stuende ohne diese Angaben als
            // Liste von Dateinamen da.
            'artist'       => $this->artist,
            'album'        => $this->album,
            'mime'         => $this->mime,
            'durationSec'  => $this->durationSec,
        ]);
    }
}

/**
 * AudioBrowseResult — Seite eines Browse-Vorgangs (browse()).
 * @property AudioSourceRef[] $items
 */
final class AudioBrowseResult
{
    /**
     * @param AudioSourceRef[] $items
     */
    public function __construct(
        public array $items = [],
        public int $total = 0,
        public int $offset = 0
    ) {
    }

    public function toArray(): array
    {
        return [
            'items'  => array_map(static fn (AudioSourceRef $i): array => $i->toArray(), $this->items),
            'total'  => $this->total,
            'offset' => $this->offset,
        ];
    }

    public static function fromArray(array $a): self
    {
        $items = array_map(
            static fn ($i): AudioSourceRef => $i instanceof AudioSourceRef ? $i : AudioSourceRef::fromArray((array) $i),
            (array) ($a['items'] ?? [])
        );
        return new self($items, (int) ($a['total'] ?? 0), (int) ($a['offset'] ?? 0));
    }
}

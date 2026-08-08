<?php

declare(strict_types=1);

namespace Hoep\HomeSuite\Contracts;

use Hoep\HomeSuite\HAL\ContentRef;

/**
 * IMediaProvider — QUELLEN-Abstraktion (renderer-unabhaengig). Ein Provider kapselt
 * einen Inhalte-Dienst (Spotify, Plex, Jellyfin, Audiobookshelf, Radio, lokal) und
 * liefert ContentRef-Objekte. Die WIEDERGABE macht ein Renderer (IAudioRenderer), der
 * den ContentRef in sein Schema uebersetzt.
 *
 * Zustandslos gegenueber dem Kernel: nur HTTP/API-Aufrufe. Konfiguration (URL/Token/
 * OAuth) kommt aus dem Hub-Store (im Symcon-Frontend gepflegt).
 */
interface IMediaProvider
{
    /** Stabile Id: spotify|plex|jellyfin|audiobookshelf|radio|local */
    public function id(): string;

    /** Anzeigename fuer die Oberflaeche. */
    public function label(): string;

    /** Ist der Provider vollstaendig konfiguriert (URL/Token/OAuth vorhanden)? */
    public function isConfigured(): bool;

    /**
     * Wurzel-Container des Providers (z. B. "Playlists", "Hoerbuecher", "Alben").
     * @return ContentRef[]
     */
    public function roots(): array;

    /**
     * Kinder eines Containers (Container + Items).
     * @return ContentRef[]
     */
    public function browse(string $containerId, int $offset = 0, int $limit = 100): array;

    /**
     * Freitextsuche.
     * @return ContentRef[]
     */
    public function search(string $query, int $limit = 50): array;

    /**
     * Fuellt/aktualisiert die abspielbare `uri` eines ContentRef (falls noetig, z. B.
     * signierte Stream-URL). Gibt den (ggf. angereicherten) ContentRef zurueck.
     */
    public function resolve(ContentRef $ref): ContentRef;
}

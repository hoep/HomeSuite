<?php

declare(strict_types=1);

namespace Hoep\HomeSuite\Contracts;

use Hoep\HomeSuite\HAL\ContentRef;

/**
 * IMediaWritable — SCHREIBENDER Zusatzvertrag fuer Medienquellen.
 *
 * Bewusst getrennt von IMediaProvider, der ausdruecklich lesend bleibt. Wer Playlists
 * anlegen kann, erfuellt zusaetzlich diesen Vertrag; die Oberflaeche fragt per instanceof
 * und blendet den Knopf sonst aus, statt ihn ins Leere laufen zu lassen. Dasselbe Muster
 * wie IAudioQueue bei den Playern.
 *
 * Die Liste entsteht IM ANBIETER, nicht bei uns. Sie kommt danach ueber den ganz normalen
 * browse()-Weg zurueck und ist damit sofort ueberall sichtbar - in Plex, in der Sonos-App,
 * in unserer Bibliothek. Es gibt kein zweites Speicherformat, das auseinanderlaufen kann.
 *
 * Grenze, die aus der Sache folgt: eine Playlist gehoert IMMER einem Anbieter. Titel aus
 * verschiedenen Quellen lassen sich hier nicht mischen.
 */
interface IMediaWritable
{
    /**
     * Neue Playlist anlegen und die uebergebenen Eintraege hineinlegen.
     *
     * @param ContentRef[] $refs Eintraege dieses Anbieters (fremde werden ignoriert)
     * @return ContentRef|null Container-Verweis auf die neue Liste, null bei Fehlschlag
     */
    public function createPlaylist(string $name, array $refs): ?ContentRef;

    /**
     * Eintraege an eine bestehende Playlist anhaengen.
     *
     * @param ContentRef[] $refs
     * @return int Anzahl der tatsaechlich uebernommenen Eintraege
     */
    public function addToPlaylist(string $playlistId, array $refs): int;

    /**
     * Die BESCHREIBBAREN Playlists dieses Anbieters.
     *
     * Bewusst nicht alle: Plex erzeugt selbst "intelligente" Listen (Zuletzt gespielt,
     * All Music …), die sich aus einer Regel speisen - an die laesst sich nichts anhaengen.
     * Wer sie als Ziel anboete, erzeugte einen Fehlschlag, der wie ein Fehler aussaehe.
     *
     * @return ContentRef[]
     */
    public function playlists(): array;

    /** Playlist loeschen. */
    public function deletePlaylist(string $playlistId): bool;
}

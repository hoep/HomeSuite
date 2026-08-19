<?php

declare(strict_types=1);

namespace Hoep\HomeSuite\Engines;

use Hoep\HomeSuite\Contracts\IMediaProvider;
use Hoep\HomeSuite\HAL\ContentRef;

/**
 * MediaProviders — Registry + Factory der Inhalte-Provider (renderer-unabhaengig).
 *
 * Provider registrieren sich beim Laden (self-register). Aus der im Symcon-Frontend
 * (Hub-Store) gepflegten Quellen-Konfig werden die AKTIVIERTEN Provider instanziiert.
 * So bleibt die Quelle vom Renderer (Sonos/HEOS) getrennt.
 *
 * sources-Config (Hub-Store 'sources'):
 *   { spotify:{enabled,clientId,clientSecret,refreshToken},
 *     plex:{enabled,url,token},
 *     jellyfin:{enabled,url,apiKey,userId},
 *     audiobookshelf:{enabled,url,token},
 *     radio:{enabled}, local:{enabled} }
 */
final class MediaProviders
{
    /** @var array<string,string> id => Klassenname (implements IMediaProvider) */
    private static array $registry = [];

    private function __construct()
    {
    }

    public static function register(string $id, string $className): void
    {
        self::$registry[$id] = $className;
    }

    /** @return array<int,string> */
    public static function ids(): array
    {
        return array_keys(self::$registry);
    }

    /**
     * Schema der konfigurierbaren Provider (fuer das Symcon-Formular): id => Felder.
     * @return array<string,array{label:string,fields:array}>
     */
    public static function schema(): array
    {
        return [
            'spotify'        => ['label' => 'Spotify', 'fields' => ['clientId', 'clientSecret', 'refreshToken']],
            'plex'           => ['label' => 'Plex', 'fields' => ['url', 'token']],
            'jellyfin'       => ['label' => 'Jellyfin', 'fields' => ['url', 'apiKey', 'userId']],
            'audiobookshelf' => ['label' => 'Audiobookshelf', 'fields' => ['url', 'username', 'password']],
            'radio'          => ['label' => 'Radio (RadioNow)', 'fields' => []],
            'local'          => ['label' => 'Lokal / DLNA', 'fields' => []],
        ];
    }

    /**
     * Aktivierte, konfigurierte Provider aus der Quellen-Konfig bauen.
     * @param array<string,mixed> $sources
     * @return IMediaProvider[]
     */
    public static function build(array $sources): array
    {
        $out = [];
        foreach (self::$registry as $id => $class) {
            $cfg = (isset($sources[$id]) && is_array($sources[$id])) ? $sources[$id] : [];
            if (empty($cfg['enabled'])) {
                continue;
            }
            if (!class_exists($class)) {
                continue;
            }
            $p = new $class($cfg);
            if ($p instanceof IMediaProvider) {
                $out[$id] = $p;
            }
        }
        return $out;
    }

    /** Einen einzelnen Provider bauen (auch wenn nicht enabled) — fuer Diagnose/Test. */
    public static function make(string $id, array $cfg): ?IMediaProvider
    {
        $class = self::$registry[$id] ?? null;
        if ($class === null || !class_exists($class)) {
            return null;
        }
        $p = new $class($cfg);
        return $p instanceof IMediaProvider ? $p : null;
    }

    /**
     * Einen Verweis in die geordnete Liste seiner abspielbaren Titel aufloesen.
     *
     * Das ist die Naht, die bisher fehlte: browse() liefert die Titel laengst, resolve()
     * kann aber per Vertrag nur EINEN Verweis zurueckgeben - und die Provider gaben darum
     * den ersten zurueck und warfen den Rest weg. Genau drei Faelle:
     *
     *  1. Kein Container -> ein einzelner Titel, wie bisher ueber resolve().
     *  2. Container MIT eigener Adresse -> er ist selbst am Stueck spielbar. Das trifft
     *     Spotify-Alben und -Playlists, deren Kennung schon die Sammlung bezeichnet; der
     *     Sonos-Treiber reiht sie als Container ein. Regel am Datum, nicht am Providernamen.
     *  3. Container ohne Adresse -> browse(), Kinder in gelieferter Reihenfolge. Die
     *     Reihenfolge der Liste IST die Abspielreihenfolge; ein zusaetzliches Feld waere
     *     eine zweite Wahrheit daneben.
     *
     * Verschachtelte Container werden NICHT verfolgt, sondern gezaehlt und gemeldet. Ein in
     * CD-Ordner unterteiltes Album liefert damit null Titel und eine ehrliche Auskunft statt
     * einer halben Wiedergabe.
     *
     * @return array{tracks: ContentRef[], truncated: bool, skipped: int, total: int}
     */
    public static function expand(IMediaProvider $p, ContentRef $ref, int $max = 200): array
    {
        $leer = ['tracks' => [], 'truncated' => false, 'skipped' => 0, 'total' => 0];
        $max  = max(1, $max);

        if (!$ref->isContainer) {
            $t = $p->resolve($ref);
            return $t->uri !== '' ? ['tracks' => [$t], 'truncated' => false, 'skipped' => 0, 'total' => 1] : $leer;
        }
        if ($ref->uri !== '') {
            return ['tracks' => [$ref], 'truncated' => false, 'skipped' => 0, 'total' => 1];
        }

        // Seitenweise holen, bis der Deckel erreicht ist oder nichts mehr kommt. Der
        // Seitenschnitt ist noetig, weil eine grosse Playlist sonst in EINER Antwort
        // haengt - und die kann der Symcon-Hook nicht ausliefern.
        $seite   = 100;
        $tracks  = [];
        $skipped = 0;
        $offset  = 0;
        $mehr    = true;
        while ($mehr && count($tracks) < $max) {
            $kinder = $p->browse($ref->id, $offset, $seite);
            $anz    = count($kinder);
            $mehr   = ($anz >= $seite);
            $offset += $anz;
            foreach ($kinder as $k) {
                if (!$k instanceof ContentRef) {
                    continue;
                }
                if ($k->isContainer) {
                    $skipped++;
                    continue;
                }
                $t = ($k->uri === '') ? $p->resolve($k) : $k;
                if ($t->uri !== '') {
                    $tracks[] = $t;
                }
                if (count($tracks) >= $max) {
                    break;
                }
            }
            if ($anz === 0) {
                break;
            }
        }
        return [
            'tracks'    => $tracks,
            'truncated' => ($mehr || count($tracks) >= $max),
            'skipped'   => $skipped,
            'total'     => count($tracks),
        ];
    }
}

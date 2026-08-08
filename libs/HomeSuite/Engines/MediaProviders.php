<?php

declare(strict_types=1);

namespace Hoep\HomeSuite\Engines;

use Hoep\HomeSuite\Contracts\IMediaProvider;

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
}

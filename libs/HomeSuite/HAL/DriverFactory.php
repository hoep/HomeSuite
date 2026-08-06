<?php

declare(strict_types=1);

namespace Hoep\HomeSuite\HAL;

/**
 * DriverFactory — waehlt/instanziiert einen HAL-Treiber aus einer driverId
 * (aus driverCatalog, §2.2.5) und bindet Konfiguration + Sende-Callback.
 *
 * Die generischen Variablen-Treiber (kein Vendor-Code) sind fest registriert.
 * Vendor-Treiber (hm-*, sonos, heos, musiccast, denon, gardena, ...) leben in
 * libs/HomeSuite/Drivers/ und registrieren sich ueber DriverFactory::register()
 * — so bleibt die Factory frei von harten Vendor-Abhaengigkeiten und trotzdem
 * zentral aufloesbar.
 */
final class DriverFactory
{
    /**
     * Registry driverId => vollqualifizierter Klassenname (implements IDriver).
     * @var array<string,string>
     */
    private static array $registry = [
        // Generische, vendor-freie Treiber (jedes Haus):
        'generic-thermostat' => GenericVariableThermostat::class,
        'generic-shutter'    => GenericVariableShutter::class,
        'generic-valve'      => GenericVariableValve::class,
    ];

    private function __construct()
    {
    }

    /**
     * Registriert (oder ueberschreibt) einen Treiber. Vendor-Treiber rufen dies
     * beim Laden auf, damit create() sie kennt.
     */
    public static function register(string $driverId, string $className): void
    {
        self::$registry[$driverId] = $className;
    }

    /** Ist eine driverId bekannt? */
    public static function has(string $driverId): bool
    {
        return isset(self::$registry[$driverId]);
    }

    /** @return array<int,string> Liste aller registrierten driverIds. */
    public static function ids(): array
    {
        return array_keys(self::$registry);
    }

    /**
     * Erzeugt einen Treiber, bindet Konfiguration und Sende-Callback und liefert
     * die gebundene Instanz.
     *
     * @param string        $driverId Id aus dem driverCatalog
     * @param array         $config   Treiber-Konfiguration
     * @param callable|null $send     Sende-Callback (Audio: HTTP-POST/Socket-Write);
     *                                bei Nicht-Audio/variablengebundenen Treibern optional
     * @throws \InvalidArgumentException wenn driverId unbekannt oder Klasse ungeeignet
     */
    public static function create(string $driverId, array $config, ?callable $send = null): IDriver
    {
        if (!isset(self::$registry[$driverId])) {
            throw new \InvalidArgumentException('Unbekannter Treiber: ' . $driverId);
        }
        $class = self::$registry[$driverId];
        if (!class_exists($class)) {
            throw new \InvalidArgumentException('Treiber-Klasse nicht geladen: ' . $class);
        }
        $driver = new $class();
        if (!$driver instanceof IDriver) {
            throw new \InvalidArgumentException($class . ' implementiert IDriver nicht');
        }
        // No-op-Sender fuer variablengebundene Treiber, die keine Frames senden.
        $sender = $send ?? static function ($x): void {
        };
        $driver->bind($config, $sender);
        return $driver;
    }
}

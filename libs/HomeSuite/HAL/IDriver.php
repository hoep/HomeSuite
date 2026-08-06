<?php

declare(strict_types=1);

namespace Hoep\HomeSuite\HAL;

/**
 * IDriver — gemeinsame Basis ALLER HomeSuite-Treiber (Vertrag 3, §2.3).
 *
 * GRUNDSATZ (B1): Treiber sind KERNEL-FREI und ZUSTANDSLOS. Sie kennen nur das
 * Vendor-Protokoll, halten selbst KEINEN Socket, KEINE Session und KEINEN
 * Zustand ueber einzelne Requests hinweg. Kommandos werden ueber den bei bind()
 * uebergebenen Callback $send als Frame/Request rausgeschoben; rohe Event-Frames
 * werden von parseEvent() zu einem (Delta-)Zustand geparst. Socket, Verbindungs-
 * zustand, Reconnect und Queue besitzt die aufrufende Instanz bzw. Bridge.
 *
 * Damit sind Sonos (SOAP-per-call, $send = HTTP-POST), HEOS/Cast (persistenter
 * Socket in Bridge/Instanz) UND Unit-Tests (Frame rein -> Zustand raus) sauber
 * tragbar — ohne den Treiber an den Kernel zu binden.
 *
 * HINWEIS ZUR RUECKGABE VON capabilities():
 * Der Audio-Zweig liefert ein AudioCapabilities-Objekt, die Nicht-Audio-HAL
 * (IThermostat/IShutter/IValve) liefert ein caps-Array. PHP-Interface-Kovarianz
 * erlaubt einer erbenden Schnittstelle nur das VERENGEN einer Rueckgabe, nicht
 * den Wechsel auf einen unvereinbaren Typ. Deshalb deklariert IDriver bewusst
 * KEINEN Rueckgabetyp fuer capabilities(); die Fachschnittstellen setzen ihren
 * jeweils passenden (AudioCapabilities bzw. array) selbst. So bleiben alle
 * Sub-Interfaces gleichzeitig ladbar (kein "Declaration must be compatible").
 */
interface IDriver
{
    /**
     * Bindet Konfiguration und Sende-Callback in den Treiber.
     *
     * @param array    $config Treiber-spezifische Konfiguration (host/udn/varIds/...)
     * @param callable $send   fn(mixed $frameOrRequest): void — schiebt einen
     *                         Frame/Request nach aussen (HTTP-POST, Socket-Write, ...).
     */
    public function bind(array $config, callable $send): void;

    /**
     * Real verfuegbare Faehigkeiten des Treibers.
     *
     * Audio: AudioCapabilities. Nicht-Audio: assoziatives caps-Array
     * (siehe die jeweilige Fachschnittstelle). Bewusst OHNE Rueckgabetyp,
     * damit die erbenden Interfaces ihren eigenen setzen koennen.
     */
    public function capabilities();

    /**
     * Netzwerk-Discovery (kurz getimeoutet, best effort). Liefert Kandidaten
     * im Format [ ['title'=>..., 'config'=>['host'=>..,'udn'=>..,'model'=>..]], ... ].
     * Nicht-Audio-Treiber liefern hier i. d. R. ein leeres Array.
     */
    public static function discover(int $timeoutMs = 2000): array;

    /**
     * Liefert die Request-Frame(s), die fuer einen Snapshot noetig sind
     * (per $send auszuschieben). Push-/variablengebundene Treiber liefern [].
     */
    public function poll(): array;

    /**
     * PURE: rohen Event-Frame in einen (Delta-)Zustand uebersetzen — oder null,
     * wenn der Frame nicht relevant ist. Ohne Seiteneffekt, ohne Kernel-Zugriff.
     * Nicht-Audio-Treiber liefern hier null.
     */
    public function parseEvent(string $raw): ?AudioState;
}

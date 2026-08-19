<?php

declare(strict_types=1);

namespace Hoep\HomeSuite\HAL;

/**
 * IAudioRenderer — Audio-HAL, ZUSTANDSLOSER Protokoll-Codec (Vertrag 3, §2.3, B1).
 *
 * Der Renderer-Treiber wandelt Kommandos in Vendor-Frames (per bind()-Callback
 * $send rausgeschoben) und rohe Event-Frames in AudioState-Deltas (parseEvent()).
 * Er haelt KEINEN Socket, KEINE Session, KEINEN Zustand: das besitzt die
 * AudioZone-Instanz (parentless: eigener Socket) bzw. die Bridge (Splitter).
 *
 * Aus dem Treiber ENTFERNT (B1): connect/disconnect/getConnectionState/subscribe/
 * supportsPush/nowPlaying. Verbindungszustand liefert die Instanz/Bridge ueber eine
 * Connection-reflect-Variable; Push ist implizit (Bridge liefert Frames -> parseEvent).
 *
 * Die Value Objects (AudioState, AudioCapabilities, AudioSourceRef,
 * AudioBrowseResult) liegen im selben Namespace (Hoep\HomeSuite\HAL,
 * Datei Drivers/Audio/types.php).
 *
 * LAUTSTAERKE ist normalisiert 0..100. setVolume() adressiert IMMER den einzelnen
 * Renderer (B5); setGroupVolume() adressiert den Gruppen-Koordinator.
 *
 * GRUPPIERUNG (B2): eine EINZIGE autoritative, deklarative Op setGroupMembers().
 * GroupJoin/Leave/Set sind reine AudioZone-Controls; der Controller uebersetzt sie
 * in genau einen setGroupMembers(coordinatorUid, memberUids)-Aufruf.
 */
interface IAudioRenderer extends IDriver
{
    /** @return AudioCapabilities real verfuegbare Teilmenge; ueberschreibt IDriver::capabilities(). */
    public function capabilities(): AudioCapabilities;

    // --- Transport (erzeugen Frame(s) via $send) ---
    public function play(): void;
    public function pause(): void;
    public function stop(): void;
    public function next(): void;
    public function previous(): void;

    /** Springt an Sekunde $sec (nur sinnvoll, wenn AudioState->seekable, CAP_SEEK). */
    public function seek(int $sec): void;

    // --- Lautstaerke (normalisiert 0..100) ---
    /** Adressiert IMMER den einzelnen Renderer (B5). */
    public function setVolume(int $pct): void;
    public function setMute(bool $on): void;

    // --- Quelle / Inhalt ---
    public function selectInput(string $inputId): void;

    /** @param AudioSourceRef $ref favorite|playlist|station|uri|preset */
    public function playSource(AudioSourceRef $ref): void;

    /** Durchsage/Ansage (CAP_ANNOUNCE); $volume=0 laesst die Lautstaerke unveraendert. */
    public function playAnnouncement(string $uri, int $volume = 0): void;

    /** @param int $repeat REPEAT_OFF|ONE|ALL */
    public function setPlayMode(int $repeat, bool $shuffle): void;

    public function listFavorites(): array;
    public function listPlaylists(): array;
    public function browse(string $containerId, int $offset, int $limit): AudioBrowseResult;

    // --- Gruppierung: EINE autoritative, deklarative Op (B2) ---
    /**
     * Setzt die vollstaendige Gruppen-Mitgliedschaft deklarativ.
     * unjoin == setGroupMembers(selfUid, [selfUid]).
     */
    public function setGroupMembers(string $coordinatorUid, array $memberUids): void;

    /** Gruppen-Lautstaerke — adressiert den Koordinator (B5). */
    public function setGroupVolume(int $pct): void;
}

/**
 * IAudioRendererExtended — v1.1-Erweiterungen (spaeter, mit passenden Methoden — B3).
 * Die zugehoerigen CAP_QUEUE/CAP_TONE/CAP_SLEEPTIMER-Flags sind im v1-Core GESTRICHEN
 * und leben erst hier.
 */
/**
 * Warteschlange als EIGENER, schmaler Vertrag.
 *
 * Bewusst getrennt von IAudioRendererExtended: dort haengen Queue, Klangregelung und
 * Einschlafzeit zusammen in einem Interface, man kann also die Warteschlange nicht
 * anbieten, ohne den Rest mitzuschleppen. Hier soll die Frage "kann dieser Player eine
 * Warteschlange?" ehrlich mit instanceof beantwortbar sein - davon haengt ab, ob die
 * Oberflaeche die Tasten "Alles abspielen"/"Anhaengen" ueberhaupt zeigt.
 */
interface IAudioQueue extends IAudioRenderer
{
    /** Warteschlange leeren. */
    public function clearQueue(): void;

    /** Einen Titel einreihen; liefert die neue Laenge der Warteschlange (0 = unbekannt). */
    public function addToQueue(AudioSourceRef $ref, bool $asNext = false): int;

    /** Auf die Warteschlange umschalten und ab $index abspielen. */
    public function startQueue(int $index = 0): void;
}

interface IAudioRendererExtended extends IAudioRenderer
{
    public function addToQueue(AudioSourceRef $ref): void;   // CAP_QUEUE
    public function clearQueue(): void;                       // CAP_QUEUE
    public function setTone(array $bands): void;              // CAP_TONE
    public function setSleepTimer(int $minutes): void;        // CAP_SLEEPTIMER
}

/**
 * IAudioStateReadable — Erweiterung fuer VARIABLENGEBUNDENE Treiber ohne Push-Frames
 * (z. B. der GenericBoundAudioRenderer im IPSSonos-Uebergang). Solche Treiber liefern
 * ihren Zustand nicht ueber parseEvent() (kein Frame-Strom), sondern lesen ihn beim
 * Refresh() der Instanz direkt aus den gebundenen Reflect-Variablen.
 *
 * Push-Treiber (HEOS/UPnP-Event) implementieren dies NICHT — sie nutzen weiterhin
 * parseEvent(): ?AudioState (IDriver::parseEvent). Das Modul entscheidet je Treiber:
 * readState() aufrufen ODER Frames an parseEvent() geben.
 */
interface IAudioStateReadable extends IAudioRenderer
{
    /** Liest den aktuellen Zustand aus den gebundenen Reflect-Variablen (GetValue). */
    public function readState(): AudioState;

    /**
     * Aktuelle Gruppen-Sicht dieses Renderers.
     * @return array{role:string,coordinatorUid:string,memberUids:string[]}
     *   role = standalone|coordinator|member
     */
    public function readGroup(): array;
}

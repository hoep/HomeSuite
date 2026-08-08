<?php

declare(strict_types=1);

/**
 * HomeSuite — zentraler Klassen-Loader (Store-Muster, KEIN PSR-4).
 *
 * Das Verzeichnis libs/ von IP-Symcon kennt kein PSR-4-Autoloading. HomeSuite
 * laedt daher alle Klassen der gemeinsamen Basis (Namespace-Root
 * Hoep\HomeSuite\) ueber explizite require_once — genau in Abhaengigkeits-
 * reihenfolge, damit Interfaces/Basistypen vor ihren Nutzern definiert sind.
 *
 * Jedes Modul (Hub, HeatingZone, ...) bindet zu Beginn seiner .php genau dieses
 * autoload.php ein:
 *
 *     require_once __DIR__ . '/../../libs/HomeSuite/autoload.php';
 *
 * Der Root ist vendor-eindeutig (Hoep\), damit zwei Libraries mit gleichem
 * Basis-Root im selben Kernel-Prozess nicht kollidieren.
 *
 * HINWEIS (Milestone M0.0 / Skelett):
 * Die untenstehende Liste nennt VOLLSTAENDIG alle Klassendateien, welche die
 * nachgelagerten Bau-Agenten anlegen. Solange die Implementierungsphase laeuft,
 * werden nur bereits vorhandene Dateien geladen (file_exists-Guard), damit das
 * Skelett nicht mit einem fatalen Fehler bricht. Sobald alle Dateien existieren,
 * ist der Guard ein reines No-Op — die Reihenfolge bleibt verbindlich.
 */

$__hs_base = __DIR__;

/**
 * Verbindliche, abhaengigkeitssortierte Ladeliste (relativ zu libs/HomeSuite/).
 * Reihenfolge: Basistypen/Constants -> Value Objects -> HAL-Interfaces ->
 * konkrete HAL-Treiber -> Engines -> Provision -> Migration -> EntityModule.
 */
$__hs_files = [

    // --- Contracts: Basistypen & Vertrag 1 (Control-Contract) ---
    'Contracts/ControlContract.php',   // Type/Role-Konstanten, isValidType/isValidRole, ContractException
    'Contracts/ActionContext.php',     // Provenienz eines Bedienvorgangs (source/ts/meta)
    'Contracts/Control.php',           // typisiertes Control (coerce/optimistic/toArray)
    'Contracts/Store.php',             // JSON-Store auf Attribut "FabricStore" (nur Konfig/Profile)
    'Contracts/Manifest.php',          // Manifest-JSON v1.0 (Struktur + state-Snapshot)

    // --- Value Objects: Audio-HAL (reine Datenhalter, vor den Interfaces/Treibern) ---
    'Drivers/Audio/types.php',         // AudioState, AudioCapabilities, AudioSourceRef, AudioBrowseResult, ContentRef
    'Contracts/IMediaProvider.php',    // Quellen-Abstraktion (renderer-unabhaengig)

    // --- HAL: Interfaces (kernel-frei, zustandslos) ---
    'HAL/IDriver.php',                 // Basis: bind/capabilities/discover/poll/parseEvent
    'HAL/IThermostat.php',             // Heizungs-HAL
    'HAL/IShutter.php',                // Beschattungs-HAL
    'HAL/IValve.php',                  // Bewaesserungs-HAL
    'HAL/IAudioRenderer.php',          // Audio-HAL (Codec, Vertrag 3)

    // --- HAL: selbst-enthaltener CCU-Transport (kein ext-xmlrpc/Legacy) ---
    'HAL/CcuXmlRpc.php',               // XML-RPC-Client fuer BidCos/HmIP (Wochenprofil-Paramset)

    // --- HAL: DriverFactory + generische Variablen-Treiber (jedes Haus) ---
    'HAL/DriverFactory.php',           // waehlt/instanziiert Treiber aus driverCatalog
    'HAL/GenericVariableThermostat.php',
    'HAL/GenericVariableShutter.php',
    'HAL/GenericVariableValve.php',

    // --- Audio-Treiber (generisch variablen-/skriptgebunden + native Vendor-Codecs) ---
    'Drivers/Audio/GenericBoundAudioRenderer.php',
    'Drivers/Audio/SonosUpnp.php',     // nativer Sonos-Codec (self-registriert 'sonos-upnp')
    'Drivers/Audio/Heos.php',          // Denon/Marantz HEOS-Codec (self-registriert 'heos')

    // --- Vendor-Treiber (self-registrieren bei der DriverFactory) ---
    'Drivers/HomeMaticThermostat.php',
    'Drivers/SomfyRtsShutter.php',
    'Drivers/HomeMaticShutter.php',

    // --- Engines ---
    'Engines/ProfileEngine.php',       // Anlegen/Bearbeiten/Zuweisen von Profilen (getrennt)
    'Engines/ScheduleEngine.php',      // Slot-/Geo-/Rule-Auswertung, Homematic-Wochenexport
    'Engines/SunTimes.php',            // Sonnen-Ereigniszeiten (date_sun_info) fuer verankerte Grenzen
    'Engines/RadioNow.php',            // Radio "was laeuft" (ICY-Titel + Song-Cover, IPSSonos-frei)
    'Engines/MediaProviders.php',      // Registry/Factory der Inhalte-Provider (renderer-unabhaengig)
    'Media/AudiobookshelfProvider.php', // self-hosted Hoerbuecher (direkte Stream-URLs)
    'Media/JellyfinProvider.php',      // self-hosted Medienserver (direkte Stream-URLs)
    'Media/PlexProvider.php',          // Plex Media Server (direkte Stream-URLs)
    'ShadingProfiles.php',             // Beschattungs-Profiltypen (Schema) + Profil->Config-Mapper
    'Engines/ShadeKinematics.php',     // Positions-/Lamellen-Kinematik der Beschattung

    // --- Provision ---
    'Provision/Provisioner.php',       // idempotentes Anlegen von Objekten/Profilen/Instanzen

    // --- Migration (Strangler-Fig, Aktor-Safety) ---
    'Migration/ActuatorGate.php',      // lokale Safety-Wahrheit je physischem Aktor
    'Migration/Ledger.php',            // Orchestrierungssicht der Migrationsphasen
    'Migration/Backup.php',            // kanonische Snapshots + Restore
    'Migration/MigrateProvider.php',   // plan/apply/verify/cutover/rollback/retire/status

    // --- EntityModule: abstrakte Modulbasis (haengt an allem Vorherigen -> zuletzt) ---
    'Contracts/EntityModule.php',      // \IPSModule-Basis: RequestAction-Dispatch, RPC, Helfer
];

foreach ($__hs_files as $__hs_rel) {
    $__hs_path = $__hs_base . '/' . $__hs_rel;
    // M0.0-Guard: waehrend der Bauphase nur laden, was schon existiert.
    if (is_file($__hs_path)) {
        require_once $__hs_path;
    }
}

unset($__hs_base, $__hs_files, $__hs_rel, $__hs_path);

<?php

declare(strict_types=1);

/**
 * HomeSuite Hub (HSH) — Singleton, parentless (§1.2, §6).
 *
 * Der Hub ist die zentrale, EINMALIG in der Konsole angelegte Instanz der
 * HomeSuite-Library. Er ist bewusst KEIN Monolith: die Kopplung an die
 * Domaenen-Module (HeatingZone, ShadingDevice, AudioZone, IrrigationCircuit)
 * erfolgt lose ueber GUID-Discovery (IPS_GetInstanceListByModuleID), NICHT ueber
 * parentRequirements-Chaining. Jedes Domaenen-Modul bleibt einzeln lauffaehig.
 *
 * Aufgaben (Spec §6.1):
 *  - Registry: HSH_ListEntities entdeckt alle HomeSuite-Domaenen-Instanzen.
 *  - Aggregat: HSH_GetSuiteManifest sammelt die Manifeste aller Entitaeten.
 *  - Verwaltung: HSH_Manage (geerbter Whitelist-Dispatch) fuehrt Hub-Ops aus.
 *  - Provisionierung: HSH_Provision legt neue Instanzen ASYNCHRON an — als Job,
 *    der ein Modul-Timer (__TimerCb) abarbeitet (F6). NIEMALS synchrones
 *    IPS_CreateInstance/ApplyChanges im Hook-/RPC-Pfad.
 *  - WebHook /hook/homesuite: wird ERST bei KR_READY registriert (F3), nie in
 *    Create() (die WebHook-Control existiert erst nach Kernel-Ready).
 *  - Token: ein rotierbares Verwaltungs-Token (X-HS-Token) schuetzt alle
 *    Schreib-/Migrate-Endpunkte (§6.2, Risiko K).
 *
 * Der Hub erbt {@see \Hoep\HomeSuite\EntityModule}. Damit stehen die generischen
 * RPC HSH_GetManifest / HSH_GetState / HSH_Manage sowie der harte,
 * try/catch-gekapselte RequestAction-Dispatch und die KR_READY-MessageSink
 * bereits zur Verfuegung (Leitprinzip 1: Wahrheit an EINER Stelle). Der Hub
 * selbst exponiert keine Bedien-Controls; sein Manifest traegt nur die
 * Hub-managementActions-Whitelist.
 */

require_once __DIR__ . '/../libs/HomeSuite/autoload.php';

use Hoep\HomeSuite\ActionContext;
use Hoep\HomeSuite\Control;
use Hoep\HomeSuite\EntityModule;
use Hoep\HomeSuite\Manifest;
use Hoep\HomeSuite\Migration\Backup;
use Hoep\HomeSuite\Provisioner;

// Klassenname MUSS = module.json "name" ohne Leerzeichen ("HomeSuite Hub" -> HomeSuiteHub),
// sonst findet Symcon die Modulklasse nicht (IPS_CreateInstance liefert false).
class HomeSuiteHub extends EntityModule
{
    // ==================================================================
    // Konstanten
    // ==================================================================

    /** Eigene GUID (aus GUIDS.md, IMMUTABEL). */
    private const GUID_HUB = '{A0C082B4-9E74-430E-BD97-F9CEBB364257}';

    /** Domaenen-Modul-GUIDs (Device, type 3) — Ziel der Registry-Discovery. */
    private const GUID_HSHT  = '{AC059357-088A-4DF8-ABBC-F8724BC78769}'; // HeatingZone
    private const GUID_HSSH  = '{A9645ED8-CB55-43B8-869B-BFF6ACFC8DC1}'; // ShadingDevice
    private const GUID_HSAU  = '{C4F2639D-2A87-453D-8175-B586BF605A38}'; // AudioZone parentless
    private const GUID_HSAUX = '{053E7017-584E-4F62-A246-EBA6CE3DE034}'; // AudioZone bridged
    private const GUID_HSIR  = '{D264A82B-DE31-45CC-8AF2-8F4C5D076508}'; // IrrigationCircuit
    private const GUID_HSAC  = '{81B7257F-4B34-4024-89B3-98BC43E00E54}'; // ClimateZone (tado)
    private const GUID_HSLTE = '{B7E1C3A4-5D62-4F08-9A1E-2C7D6B4F0E93}'; // LightDevice
    private const GUID_HSSP  = '{5598F752-886D-475F-91CE-5813A3C581E5}'; // HomeSuite Bereich (Struktur)

    /** Audio-Bridges (Splitter, type 2) — provisionierbar, aber keine Entitaeten. */
    private const GUID_HSBH = '{BCDCA10C-BDFD-4270-8D28-1CC690A130DB}'; // HeosBridge
    private const GUID_HSBM = '{3EABAEBB-9DFF-4D8F-AB0C-0639B488CFCC}'; // MusicCastHub
    private const GUID_HSBD = '{878924BE-C0FA-4553-8897-22FF03E62D82}'; // DenonAvrBridge

    /** Built-in WebHook-Control (Server) — Ziel der Hook-Registrierung. */
    private const GUID_WEBHOOK = '{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}';

    /** Pfad des HomeSuite-WebHooks. */
    private const HOOK_PATH = '/hook/homesuite';

    /** Attribut fuer das rotierbare Verwaltungs-Token (X-HS-Token). */
    private const ATTR_TOKEN = 'MgmtToken';

    /** Attribut fuer die async Provision-Job-Warteschlange (JSON-Liste). */
    private const ATTR_QUEUE = 'ProvisionQueue';

    /** Timer-Ident des async Provision-Jobs. */
    private const TIMER_PROVISION = 'ProvisionJob';
    private const TIMER_LIGHTAUTO = 'LightAuto';
    private const LIGHTAUTO_TICK_MS = 60000;
    /** Wie lange einem stummen Geraet nachgefasst wird, und wie viele Faelle gleichzeitig. */
    private const NACHFASS_SEK = 300;
    private const NACHFASS_MAX = 24;

    /** Intervall (ms), in dem der Provision-Timer die Queue abarbeitet. */
    private const PROVISION_TICK_MS = 500;

    // ==================================================================
    // Lebenszyklus
    // ==================================================================

    public function Create()
    {
        // EntityModule::Create() legt FabricStore/HoldState an, registriert die
        // KERNELMESSAGE (fuer KR_READY -> onKernelReady) und ruft setupTimers().
        parent::Create();

        // Verwaltungs-Token: leer initialisieren. Die eigentliche Erzeugung
        // erfolgt bei Kernel-Ready (WriteAttributeString ist in Create() noch
        // nicht moeglich). RegisterAttributeString liefert nur den Default.
        $this->RegisterAttributeString(self::ATTR_TOKEN, '');

        // Async-Provision-Warteschlange (persistente Job-Liste, resumable).
        $this->RegisterAttributeString(self::ATTR_QUEUE, '[]');

        // Einmal-Seed-Flag fuer die Scharf-Master (ArmLight etc. aus per-Instanz-Armed).
        $this->RegisterAttributeBoolean('ArmSeeded', false);
        $this->RegisterAttributeBoolean('ArmModeSeeded', false);

        // --- Globale, domaenenweite Einstellungen (zentrales Config-Formular) ---
        // Alle Instanzen einer Domaene teilen sich diese Werte (statt Duplikat je
        // Instanz). Childs lesen sie via EntityModule::hubProp() mit Instanz-Override.
        // Standort/Sonne (alle Domaenen)
        $this->RegisterPropertyString('SunSource', 'location');
        $this->RegisterPropertyInteger('LocationId', 0);
        $this->RegisterPropertyFloat('Lat', 0.0);
        $this->RegisterPropertyFloat('Lon', 0.0);
        // Beschattung — Sensoren + Sicherheit
        $this->RegisterPropertyInteger('ShadeWindId', 0);
        $this->RegisterPropertyInteger('ShadeRainId', 0);
        // Aussentemperatur ist EINE Groesse fuers ganze Haus (ein Fuehler), deshalb hier
        // und nicht je Rollo. Die INNEN-Fuehler bleiben an der jeweiligen Zone.
        $this->RegisterPropertyInteger('ShadeTempOutId', 0);
        $this->RegisterPropertyInteger('ShadeBrightId', 0);
        $this->RegisterPropertyInteger('ShadeSunAzId', 0);
        $this->RegisterPropertyInteger('ShadeSunElId', 0);
        $this->RegisterPropertyFloat('ShadeWindStormKmh', 50.0);
        // Mindestabstand zwischen zwei Funktelegrammen IRGENDWELCHER Rollos. Haus-weit und
        // nicht je Rollo, weil sich alle EINEN Sender und EINE Luftschnittstelle teilen: der
        // Wert beschreibt den Bus, nicht das Geraet. Am 17.08.2026 gingen 15 Fahrbefehle im
        // Abstand von 0,8 s raus und genau einer kam an - RTS quittiert nichts, also faellt
        // so etwas nur dadurch auf, dass die Fassade offen bleibt.
        $this->RegisterPropertyInteger('ShadeBusGapMs', 4000);
        $this->RegisterPropertyBoolean('ShadeRainClose', true);
        $this->RegisterPropertyInteger('ShadeSafePos', 0);
        // Nordausrichtung (Haus-Abweichung gegen Nord, °): dreht alle Sonnenprofile mit.
        $this->RegisterPropertyFloat('ShadeNorthDeg', -12.6);
        $this->RegisterAttributeString('ShadeNorthApplied', ''); // zuletzt angewandte Ausrichtung (Baseline)
        // Heizung — Frostschutz (haus-weit)
        $this->RegisterPropertyFloat('HeatFrostTemp', 8.0);
        // Bewaesserung — Regensensor (grundstuecksweit)
        $this->RegisterPropertyInteger('IrrRainSensorId', 0);

        /* Flugverkehr (OpenSky).
         *
         * Der Zugang gehoert hierher und nicht auf fest verdrahtete Variablen-IDs
         * im LiveViewBuilder - wie bei tado und Toshiba wird er in der Oberflaeche
         * gepflegt. Seit Maerz 2026 nimmt OpenSky kein Benutzername/Kennwort mehr;
         * im OpenSky-Konto unter "Account" einen API-Client anlegen, der liefert
         * Client-ID und Geheimnis. Ohne Zugang laeuft es anonym mit 400 statt 4000
         * Abfragen je Tag. */
        $this->RegisterPropertyBoolean('FlightsOn', false);
        $this->RegisterPropertyInteger('FlightRadius', 30);
        $this->RegisterPropertyString('FlightClientId', '');
        $this->RegisterPropertyString('FlightSecret', '');
    }

    /**
     * Domaenenspezifische Timer (aus EntityModule::Create() heraus aufgerufen).
     * Der Provision-Timer startet ausgeschaltet (Intervall 0) und wird beim
     * Einreihen eines Jobs aktiviert.
     */
    protected function setupTimers(): void
    {
        // Der Timer ruft die oeffentliche Bruecke HSH_RunTimer, welche intern
        // __TimerCb('provision') aufruft (F6/F10: Callback-Logik lebt in
        // __TimerCb, ist aber nie ein Manage-Verb).
        $this->RegisterTimer(
            self::TIMER_PROVISION,
            0,
            'HSH_RunTimer($_IPS[\'TARGET\'], "provision");'
        );
        $this->RegisterTimer(
            self::TIMER_LIGHTAUTO,
            0,
            'HSH_RunTimer($_IPS[\'TARGET\'], "lightauto");'
        );
    }

    /**
     * Kernel-Ready-Hook (F3): erst hier existiert die WebHook-Control und die
     * Instanz ist aktiv (WriteAttribute moeglich). Token sicherstellen + Hook
     * registrieren.
     */
    protected function onKernelReady(): void
    {
        $this->ensureToken();
        $this->registerHook(self::HOOK_PATH);
        $this->registerHook('/hook/hsspotify'); // Spotify-OAuth-Callback (ueber Symcon Connect)

        // Falls beim letzten Lauf noch Jobs offen waren -> Timer reaktivieren.
        if (count($this->readQueue()) > 0) {
            $this->SetTimerInterval(self::TIMER_PROVISION, self::PROVISION_TICK_MS);
        }
    }

    /**
     * Konsolen-Formular: Token-Anzeige/Regenerate + Status. Verwaltung selbst
     * laeuft im LiveViewBuilder. Baut auf form.json auf und spielt die aktuellen
     * Laufzeitwerte (Token, Hook-URL, Anzahl Entitaeten) ein.
     */
    // ==================================================================
    // Oeffentliche Scripting-Prozeduren (-> HSH_SetAutomationEnabled …)
    // Globale Bedienung + duenne Manage-Fassaden (Szenen/Licht-Automatik/Rotation).
    // ==================================================================

    public function SetAutomationEnabled(bool $Enabled): bool { return $this->setControlValue('AutomationEnabled', $Enabled); }
    public function GetAutomationEnabled(): bool              { return (bool) $this->GetControlValue('AutomationEnabled'); }

    /** Nordausrichtung (Grad); dreht alle Sonnenprofile additiv (Property+ApplyChanges+Rotation). */
    public function SetNorthAlignment(float $Degrees): bool { return $this->setControlValue('ShadeNorth', $Degrees); }
    public function GetNorthAlignment(): float             { return (float) $this->GetControlValue('ShadeNorth'); }

    public function ApplyLightScene(string $SceneId): bool
    {
        $r = json_decode($this->Manage(json_encode(['op' => 'lightSceneApply', 'args' => ['id' => $SceneId]])), true);
        return is_array($r) && !empty($r['ok']);
    }
    public function ListLightScenes(): string { return $this->Manage(json_encode(['op' => 'lightSceneList'])); }

    /** Licht-Automatik an/aus OHNE die Regeln zu verlieren (liest sie erst und schreibt sie zurueck). */
    public function SetLightAutomationEnabled(bool $Enabled): bool
    {
        $g = json_decode($this->Manage(json_encode(['op' => 'lightAutoGet'])), true);
        $rules = (is_array($g) && isset($g['rules']) && is_array($g['rules'])) ? $g['rules'] : [];
        $r = json_decode($this->Manage(json_encode(['op' => 'lightAutoSet', 'args' => ['enabled' => $Enabled, 'rules' => $rules]])), true);
        return is_array($r) && !empty($r['ok']);
    }

    /** Additive Rotation aller Sonnenprofile (Wartung); liefert das Manage-JSON. */
    public function RotateSunProfiles(float $DeltaDeg): string
    {
        return $this->Manage(json_encode(['op' => 'rotateSun', 'args' => ['deltaDeg' => $DeltaDeg]]));
    }

    public function GetConfigurationForm()
    {
        $form = [];
        $file = __DIR__ . '/form.json';
        if (is_file($file)) {
            $decoded = json_decode((string) file_get_contents($file), true);
            if (is_array($decoded)) {
                $form = $decoded;
            }
        }
        if (!isset($form['elements']) || !is_array($form['elements'])) {
            $form['elements'] = [];
        }

        // Laufzeit-Statuszeile voranstellen.
        $token   = $this->readToken();
        $masked  = $token === '' ? '(noch nicht erzeugt)' : $token;
        $count   = count($this->discoverEntities());
        $info    = 'WebHook: ' . self::HOOK_PATH
            . '   |   entdeckte Entitaeten: ' . $count
            . "\nVerwaltungs-Token (X-HS-Token): " . $masked;

        array_unshift($form['elements'], [
            'type'    => 'Label',
            'caption' => $info,
        ]);

        // --- Medienquellen (Provider) im Frontend konfigurierbar (Nutzer-Anforderung) ---
        $sources = $this->sourcesConfig();
        $schema  = \Hoep\HomeSuite\Engines\MediaProviders::schema();
        $items   = [[
            'type' => 'Label',
            'caption' => 'Haus-weite Medienquellen. Aktivieren + Zugangsdaten eintragen, dann speichern. '
                . 'Plex/Jellyfin/Audiobookshelf liefern direkte Stream-URLs (renderer-unabhaengig); '
                . 'Spotify/Audible sind dienst-gebunden (DRM, nur ueber einen dienstfaehigen Renderer).',
        ]];
        $argParts = [];
        foreach ($schema as $id => $def) {
            $cfg = $sources[$id] ?? ['enabled' => false];
            $items[] = ['type' => 'Label', 'caption' => '— ' . $def['label'] . ' —'];
            $items[] = ['type' => 'CheckBox', 'name' => 'src_' . $id . '_enabled', 'caption' => 'aktiv',
                'value' => (bool) ($cfg['enabled'] ?? false)];
            $arg = '"enabled"=>$src_' . $id . '_enabled';
            foreach ((array) $def['fields'] as $f) {
                $secret = in_array($f, ['clientSecret', 'token', 'apiKey', 'refreshToken'], true);
                $items[] = ['type' => $secret ? 'PasswordTextBox' : 'ValidationTextBox',
                    'name' => 'src_' . $id . '_' . $f, 'caption' => $f, 'value' => (string) ($cfg[$f] ?? '')];
                $arg .= ',"' . $f . '"=>$src_' . $id . '_' . $f;
            }
            $argParts[] = '"' . $id . '"=>[' . $arg . ']';
        }
        $items[] = ['type' => 'Button', 'caption' => 'Medienquellen speichern',
            'onClick' => 'echo HSH_Manage($id, json_encode(["op"=>"configureSources","args"=>[' . implode(',', $argParts) . ']]));'];

        // --- Radiosender: eingebaute anzeigen, eigene pflegen ---------------------
        // Die eingebauten stehen im Code und bleiben dort; eigene liegen im Hub und
        // duerfen einen eingebauten Schluessel ueberschreiben (tote Adresse ersetzen).
        $items[] = ['type' => 'Label', 'caption' => '— Radiosender —'];
        $items[] = ['type' => 'Label', 'caption' => 'Alle Sender werden hier gepflegt; im Code steht keiner mehr. '
            . 'Schluessel klein und ohne Leerzeichen. Erkennung = Textstuecke (kommagetrennt), an denen der vom '
            . 'Player gemeldete Sendername erkannt wird; leer lassen nutzt Name und Schluessel.'];
        $items[] = ['type' => 'List', 'name' => 'radioStations', 'caption' => 'Eigene Sender',
            'rowCount' => 6, 'add' => true, 'delete' => true, 'sort' => ['column' => 'key', 'direction' => 'ascending'],
            'columns' => [
                ['caption' => 'Schluessel', 'name' => 'key', 'width' => '140px', 'add' => '',
                    'edit' => ['type' => 'ValidationTextBox']],
                ['caption' => 'Name', 'name' => 'title', 'width' => '220px', 'add' => '',
                    'edit' => ['type' => 'ValidationTextBox']],
                ['caption' => 'Stream-Adresse', 'name' => 'stream', 'width' => 'auto', 'add' => '',
                    'edit' => ['type' => 'ValidationTextBox']],
                ['caption' => 'Logo-Adresse', 'name' => 'logo', 'width' => '200px', 'add' => '',
                    'edit' => ['type' => 'ValidationTextBox']],
                ['caption' => 'Erkennung', 'name' => 'match', 'width' => '200px', 'add' => '',
                    'edit' => ['type' => 'ValidationTextBox']],
            ],
            'values' => array_map(static function ($e) {
                $e['match'] = implode(', ', (array) ($e['match'] ?? []));
                return $e;
            }, $this->eigeneSender())];
        $items[] = ['type' => 'Button', 'caption' => 'Radiosender speichern',
            'onClick' => 'echo HSH_Manage($id, json_encode(["op"=>"configureStations","args"=>["stations"=>$radioStations]]));'];
        $items[] = ['type' => 'ValidationTextBox', 'name' => 'streamTestUrl', 'caption' => 'Stream testen (Adresse)'];
        $items[] = ['type' => 'Button', 'caption' => 'Stream testen',
            'onClick' => 'echo HSH_Manage($id, json_encode(["op"=>"testStation","args"=>["url"=>$streamTestUrl]]));'];
        // Spotify-OAuth: Redirect-URI anzeigen (in Spotify-App eintragen) + Login-Link erzeugen.
        $redir = $this->spotifyRedirectUri();
        $login = ($redir !== '') ? $redir : ''; // /hook/hsspotify leitet ohne code direkt zu Spotify weiter
        $items[] = ['type' => 'Label', 'caption' => '— Spotify verbinden (OAuth) —'];
        $items[] = ['type' => 'Label', 'caption' => 'Redirect-URI fuer die Spotify-App (in den App-Settings eintragen): '
            . ($redir !== '' ? $redir : '(Symcon Connect nicht verfuegbar)')];
        $items[] = ['type' => 'Label', 'caption' => 'Client-ID/Secret oben speichern, dann auf den Link klicken und bei Spotify anmelden — der Refresh-Token wird automatisch gespeichert.'];
        // Direkter Ein-Klick-Login: der Link oeffnet den Hook, der sofort zu Spotify weiterleitet.
        $items[] = ['type' => 'Label', 'caption' => $login !== '' ? ('Bei Spotify anmelden: ' . $login) : 'Login-Link nicht verfuegbar (Symcon Connect pruefen).'];
        // Fallback-Button (zeigt denselben Login-Link, falls der Link oben nicht anklickbar ist).
        $items[] = ['type' => 'Button', 'caption' => 'Spotify-Login-Link anzeigen',
            'onClick' => 'echo (@json_decode(HSH_Manage($id, json_encode(["op"=>"spotifyAuthUrl"])), true)["authUrl"] ?? "Login-Link nicht verfuegbar");'];
        $form['elements'][] = ['type' => 'ExpansionPanel', 'caption' => 'Medienquellen (Audio-Provider)', 'items' => $items];

        // --- Globale, domaenenweite Einstellungen (property-gebunden; Childs lesen via hubProp) ---
        $form['elements'][] = ['type' => 'ExpansionPanel', 'caption' => 'Standort & Sonne (alle Domänen)', 'items' => [
            ['type' => 'Label', 'caption' => 'Ein Standort fürs ganze Haus (Sonnenzeiten/Sonnenautomatik aller Domänen).'],
            ['type' => 'Select', 'name' => 'SunSource', 'caption' => 'Quelle', 'options' => [
                ['caption' => 'Location-Instanz', 'value' => 'location'],
                ['caption' => 'Eigene Koordinaten', 'value' => 'coords'],
            ]],
            ['type' => 'SelectInstance', 'name' => 'LocationId', 'caption' => 'Location-Instanz'],
            ['type' => 'RowLayout', 'items' => [
                ['type' => 'NumberSpinner', 'name' => 'Lat', 'caption' => 'Breite (Lat)', 'digits' => 5],
                ['type' => 'NumberSpinner', 'name' => 'Lon', 'caption' => 'Länge (Lon)', 'digits' => 5],
            ]],
        ]];
        $form['elements'][] = ['type' => 'ExpansionPanel', 'caption' => 'Beschattung — Wetter/Sonne/Sicherheit (global)', 'items' => [
            ['type' => 'Label', 'caption' => 'Gilt für alle Rollos. Einzelne Rollos können per Instanz-Property abweichen (Instanzwert > 0 gewinnt).'],
            ['type' => 'RowLayout', 'items' => [
                ['type' => 'SelectVariable', 'name' => 'ShadeWindId', 'caption' => 'Wind (km/h)'],
                ['type' => 'SelectVariable', 'name' => 'ShadeRainId', 'caption' => 'Regen'],
                ['type' => 'SelectVariable', 'name' => 'ShadeTempOutId', 'caption' => 'Aussentemperatur (haus-weit)'],
                ['type' => 'SelectVariable', 'name' => 'ShadeBrightId', 'caption' => 'Helligkeit / Globalstrahlung (Schwellen stehen im Sonnenprofil)'],
            ]],
            ['type' => 'RowLayout', 'items' => [
                ['type' => 'SelectVariable', 'name' => 'ShadeSunAzId', 'caption' => 'Sonne Azimut'],
                ['type' => 'SelectVariable', 'name' => 'ShadeSunElId', 'caption' => 'Sonne Elevation'],
            ]],
            ['type' => 'RowLayout', 'items' => [
                ['type' => 'NumberSpinner', 'name' => 'ShadeWindStormKmh', 'caption' => 'Sturm-Schwelle (km/h)', 'digits' => 0],
                ['type' => 'NumberSpinner', 'name' => 'ShadeSafePos', 'caption' => 'Sichere Position (%)', 'minimum' => 0, 'maximum' => 100],
                ['type' => 'CheckBox', 'name' => 'ShadeRainClose', 'caption' => 'Bei Regen schließen'],
            ]],
            ['type' => 'NumberSpinner', 'name' => 'ShadeBusGapMs', 'caption' => 'Abstand zwischen Funkbefehlen (ms)',
             'minimum' => 0, 'maximum' => 30000, 'suffix' => ' ms'],
            ['type' => 'Label', 'caption' => 'Gilt für alle Rollos gemeinsam: sie teilen sich einen Sender. '
                . 'Zu kleine Werte lassen bei Sammelfahrten Telegramme verlorengehen, ohne dass es auffällt.'],
            ['type' => 'Label', 'caption' => 'Nordausrichtung: Haus-Abweichung gegen Nord (°). Beim Ändern werden ALLE Sonnenprofile (Hub + alle Zonen) automatisch mitgedreht.'],
            ['type' => 'NumberSpinner', 'name' => 'ShadeNorthDeg', 'caption' => 'Nordausrichtung (° gegen Nord)', 'digits' => 1, 'minimum' => -180, 'maximum' => 180],
        ]];
        $form['elements'][] = ['type' => 'ExpansionPanel', 'caption' => 'Heizung — Frostschutz (global)', 'items' => [
            ['type' => 'NumberSpinner', 'name' => 'HeatFrostTemp', 'caption' => 'Frostschutz-Solltemperatur (°C)', 'digits' => 1, 'minimum' => 3, 'maximum' => 15],
        ]];
        $form['elements'][] = ['type' => 'ExpansionPanel', 'caption' => 'Bewässerung — Klima (global)', 'items' => [
            ['type' => 'SelectVariable', 'name' => 'IrrRainSensorId', 'caption' => 'Regensensor (mm)'],
        ]];

        // Scharf-Schaltungen je Domaene (Master): Zustand + Buttons. Schaltet die Baum-
        // Variablen (ArmLight etc.), die alle Instanzen der Domaene live lesen.
        $armDefs = [['ArmLight', 'Licht'], ['ArmHeating', 'Heizung'], ['ArmShading', 'Beschattung'],
                    ['ArmIrrigation', 'Bewässerung'], ['ArmAudio', 'Audio'], ['ArmPool', 'Pool'], ['ArmMower', 'Mäher'],
                    ['ArmClimate', 'Klima']];
        $armItems = [['type' => 'Label', 'caption' => 'Aus = ganze Domäne Schatten (nur Anzeige/Log). Auto = jede Zone entscheidet selbst (Property „Armed"). Scharf = ganze Domäne schaltet REAL. Wirkt sofort; Zustand nach Klick durch Neu-Öffnen des Formulars aktualisieren.']];
        $armLbl = [0 => '○ Aus', 1 => '◐ Auto', 2 => '● Scharf'];
        foreach ($armDefs as $ad) {
            $mid = @$this->GetIDForIdent($ad[0] . 'Mode');
            $mode = ($mid && $mid > 0) ? (int) @\GetValue($mid) : 0;
            $armItems[] = ['type' => 'RowLayout', 'items' => [
                ['type' => 'Label', 'width' => '190px', 'caption' => $ad[1] . ':  ' . ($armLbl[$mode] ?? '?')],
                ['type' => 'Button', 'caption' => 'Aus',    'onClick' => 'HSH_SetArmMode($id, "' . $ad[0] . '", 0); echo "' . $ad[1] . ' Aus — Formular neu öffnen";'],
                ['type' => 'Button', 'caption' => 'Auto',   'onClick' => 'HSH_SetArmMode($id, "' . $ad[0] . '", 1); echo "' . $ad[1] . ' Auto — Formular neu öffnen";'],
                ['type' => 'Button', 'caption' => 'Scharf', 'onClick' => 'HSH_SetArmMode($id, "' . $ad[0] . '", 2); echo "' . $ad[1] . ' Scharf — Formular neu öffnen";'],
            ]];
        }
        $form['elements'][] = ['type' => 'ExpansionPanel', 'caption' => 'Scharf-Schaltungen (je Domäne)', 'items' => $armItems];

        $json = json_encode($form, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $json === false ? '{"elements":[]}' : $json;
    }

    // ==================================================================
    // Manifest (Hub: keine Bedien-Controls, nur Verwaltungs-Whitelist)
    // ==================================================================

    /**
     * Hub-Manifest. Enthaelt bewusst keine `controls` (der Hub wird nicht
     * bedient), aber die managementActions-Whitelist der Hub-Ops. Nur was hier
     * gelistet ist, laesst {@see EntityModule::Manage()} durch (§6.3-1).
     *
     * @return array<string,mixed>
     */
    protected function manifest(): array
    {
        $m = new Manifest();
        $m->setModule('homesuite.hub', 'HSH', 'hub', self::GUID_HUB, 3, 'HomeSuite Hub', 'Menu');
        $m->setInstance($this->InstanceID);
        $m->setCapabilities(['registry', 'provision', 'token', 'backup']);
        $m->setEntity([
            'id'             => 'hub',
            'name'           => 'HomeSuite Hub',
            'singular'       => 'Hub',
            'plural'         => 'Hubs',
            'group'          => 'System',
            'identNamespace' => 'HSH',
        ]);

        // --- Verwaltungs-Whitelist (§2.2.3 / §6.1) ---
        $m->addManagementAction([
            'op'          => 'provision',
            'verb'        => 'provision',
            'target'      => 'instance',
            'label'       => 'Instanz provisionieren',
            'destructive' => false,
            'fields'      => [
                ['key' => 'guid', 'type' => 'string', 'required' => false,
                    'help' => 'Modul-GUID (alternativ family oder prefix)'],
                ['key' => 'family', 'type' => 'select', 'required' => false,
                    'options' => [
                        ['value' => 'HSAU', 'label' => 'AudioZone (parentless)'],
                        ['value' => 'HSAUX', 'label' => 'AudioZone (bridged)'],
                    ]],
                ['key' => 'prefix', 'type' => 'string', 'required' => false],
                ['key' => 'name', 'type' => 'string', 'required' => true, 'maxLen' => 64],
                ['key' => 'parentId', 'type' => 'objid', 'required' => false,
                    'help' => 'Objektbaum-Elternkategorie (leer = HomeSuite-Wurzel)'],
                ['key' => 'connectParentId', 'type' => 'objid', 'required' => false,
                    'help' => 'Datenweg-Parent bei Splitter-Kind (HSAUX)'],
                ['key' => 'properties', 'type' => 'list', 'required' => false,
                    'help' => 'Zu setzende Instanz-Properties {key:value}'],
            ],
        ]);
        $m->addManagementAction([
            'op'          => 'rotateToken',
            'verb'        => 'rotateToken',
            'target'      => 'hub',
            'label'       => 'Verwaltungs-Token neu erzeugen',
            'destructive' => false,
            'fields'      => [],
        ]);
        $m->addManagementAction([
            'op'          => 'listSnapshots',
            'verb'        => 'listSnapshots',
            'target'      => 'hub',
            'label'       => 'Snapshots auflisten',
            'destructive' => false,
            'fields'      => [],
        ]);
        $m->addManagementAction([
            'op'          => 'restoreSnapshot',
            'verb'        => 'restoreSnapshot',
            'target'      => 'hub',
            'label'       => 'Snapshot wiederherstellen',
            'destructive' => true,
            'fields'      => [
                ['key' => 'file', 'type' => 'string', 'required' => true,
                    'help' => 'Basename oder voller Pfad der Snapshot-Datei'],
            ],
        ]);

        // Dead-Binding-Scan ueber alle Entitaeten (read-only Diagnose).
        $m->addManagementAction(['op' => 'organizeTree', 'verb' => 'organizeTree', 'target' => 'hub',
            'label' => 'Objektbaum aufraeumen (eine Wurzel, Geraete zu Raeumen)', 'destructive' => false, 'fields' => []]);
        $m->addManagementAction(['op' => 'detectContacts', 'verb' => 'detectContacts', 'target' => 'hub',
            'label' => 'Tuer-/Fensterkontakte erkennen (markenuebergreifend)', 'destructive' => false, 'fields' => []]);
        $m->addManagementAction(['op' => 'detectSensors', 'verb' => 'detectSensors', 'target' => 'hub',
            'label' => 'Bewegungs-/Anwesenheits-Sensoren erkennen', 'destructive' => false, 'fields' => []]);
        $m->addManagementAction(['op' => 'validate', 'verb' => 'validate', 'target' => 'hub',
            'label' => 'Bindungen pruefen (alle Entitaeten)', 'destructive' => false, 'fields' => []]);
        $m->addManagementAction(['op' => 'rotateSun', 'verb' => 'rotateSun', 'target' => 'hub',
            'label' => 'Sonnenprofile drehen (Nordausrichtung, deltaDeg)', 'destructive' => false, 'fields' => []]);

        // --- Medienquellen (Provider) — haus-weit, im Symcon-Frontend konfigurierbar ---
        $m->addManagementAction(['op' => 'getSources', 'verb' => 'getSources', 'target' => 'hub',
            'label' => 'Medienquellen lesen', 'destructive' => false, 'fields' => []]);
        $m->addManagementAction(['op' => 'configureSources', 'verb' => 'configureSources', 'target' => 'hub',
            'label' => 'Medienquellen konfigurieren', 'destructive' => false, 'fields' => []]);
        $m->addManagementAction(['op' => 'spotifyAuthUrl', 'verb' => 'spotifyAuthUrl', 'target' => 'hub',
            'label' => 'Spotify-Login-Link erzeugen', 'destructive' => false, 'fields' => []]);
        $m->addManagementAction(['op' => 'shadeLogClear', 'verb' => 'shadeLogClear', 'target' => 'hub',
            'label' => 'Beschattungs-Log leeren (alle Zonen oder entityId)']);
        $m->addManagementAction(['op' => 'shadeLog', 'verb' => 'shadeLog', 'target' => 'hub',
            'label' => 'Beschattungs-Log (alle Raeume) lesen', 'destructive' => false, 'fields' => []]);
        $m->addManagementAction(['op' => 'decisionLog', 'verb' => 'decisionLog', 'target' => 'hub',
            'label' => 'Entscheidungen aller Domaenen lesen', 'destructive' => false, 'fields' => []]);
        foreach ([['mediaProviders', 'Provider auflisten'], ['mediaBrowse', 'Bibliothek browsen'],
                  ['mediaSearch', 'Bibliothek suchen'], ['mediaResolve', 'Inhalt aufloesen'],
                  ['mediaTracks', 'Sammlung in Titelliste aufloesen'],
                  ['mediaCanWrite', 'Welche Quellen koennen Playlists?'],
                  ['mediaPlaylistList', 'Beschreibbare Playlists lesen'], ['mediaPlaylistCreate', 'Playlist anlegen'], ['mediaPlaylistAdd', 'An Playlist anhaengen'],
                  ['mediaPlaylistDelete', 'Playlist loeschen'],
                  ['getStations', 'Radiosender lesen'], ['configureStations', 'Radiosender speichern'],
                  ['testStation', 'Stream testen']] as $ma) {
            $m->addManagementAction(['op' => $ma[0], 'verb' => $ma[0], 'target' => 'hub',
                'label' => $ma[1], 'destructive' => false, 'fields' => []]);
        }

        // --- Beschattungs-/Profil-Verwaltung (geteilte benannte Profile, ProfileEngine) ---
        foreach ([
            ['profileTypes',    'Profiltypen auflisten'],
            ['profileList',     'Profile eines Typs auflisten'],
            ['profileGet',      'Profil lesen'],
            ['profileCreate',   'Profil anlegen'],
            ['profileSetFields','Profil bearbeiten'],
            ['profileRename',   'Profil umbenennen'],
            ['profileDuplicate','Profil duplizieren'],
            ['profileDelete',   'Profil loeschen'],
            ['profileAssign',   'Profil einer Zone zuweisen'],
            ['profileAssigned', 'Zuweisungen einer Zone lesen'],
        ] as $pa) {
            $m->addManagementAction(['op' => $pa[0], 'verb' => $pa[0], 'target' => 'hub', 'label' => $pa[1], 'destructive' => ($pa[0] === 'profileDelete'), 'fields' => []]);
        }

        // --- Licht-Szenen (Haus-Ebene, SceneEngine) ---
        foreach ([
            ['lightSceneList',    'Szenen auflisten'],
            ['lightSceneGet',     'Szene lesen'],
            ['lightSceneSave',    'Szene speichern (authored)'],
            ['lightSceneCapture', 'Szene aus Ist-Zustand aufnehmen'],
            ['lightSceneApply',   'Szene anwenden'],
            ['lightSceneOff',     'Szene ausschalten'],
            ['lightSceneRename',  'Szene umbenennen'],
            ['lightSceneDelete',  'Szene loeschen'],
        ] as $sa) {
            $m->addManagementAction(['op' => $sa[0], 'verb' => $sa[0], 'target' => 'hub',
                'label' => $sa[1], 'destructive' => ($sa[0] === 'lightSceneDelete'), 'fields' => []]);
        }
        foreach ([['lightAutoGet', 'Automatik-Regeln lesen'], ['lightAutoSet', 'Automatik-Regeln speichern'],
                  ['lightAutoTick', 'Automatik jetzt auswerten (Test)'],
                  ['lightAutoBandGet', 'Zeitsteuerung (Baender) lesen'],
                  ['lightAutoBandSet', 'Zeitsteuerung (Baender) speichern'],
                  ['houseGeo', 'Hausgeometrie lesen (Nordausrichtung, Koordinaten)']] as $la) {
            $m->addManagementAction(['op' => $la[0], 'verb' => $la[0], 'target' => 'hub',
                'label' => $la[1], 'destructive' => false, 'fields' => []]);
        }

        // Globaler Automatik-Schalter (HomeSuite-weit). Jede Entitaet liest ihn ueber
        // EntityModule::automationEnabled(); Default true (seed in ApplyChanges).
        $m->addControl([
            'ident' => 'AutomationEnabled', 'type' => \Hoep\HomeSuite\ControlContract::T_SWITCH,
            'role' => 'hub:automation', 'label' => 'Automatik global', 'varType' => 0,
            'profile' => '~Switch', 'actionable' => true,
        ]);
        // Nordausrichtung als aktionierbare Baum-Variable (Slider-bindbar); dreht beim
        // Setzen alle Sonnenprofile (delegiert an die Property-Rotation in ApplyChanges).
        $m->addControl([
            'ident' => 'ShadeNorth', 'type' => \Hoep\HomeSuite\ControlContract::T_LEVEL,
            'role' => 'hub:northdeg', 'label' => 'Nordausrichtung', 'varType' => 2,
            'unit' => '°', 'min' => -180, 'max' => 180, 'step' => 0.1, 'actionable' => true,
        ]);
        // Scharf-Master je Domaene: jede Entitaet liest ihren Gate live ueber
        // EntityModule::armed() (Hub-Vorrang). ON = ganze Domaene schaltet real.
        foreach ([['ArmLight','Licht scharf'],['ArmHeating','Heizung scharf'],['ArmShading','Beschattung scharf'],
                  ['ArmIrrigation','Bewässerung scharf'],['ArmAudio','Audio scharf'],['ArmPool','Pool scharf'],['ArmMower','Mäher scharf'],
                  ['ArmClimate','Klima scharf']] as $ag) {
            $m->addControl([
                'ident' => $ag[0], 'type' => \Hoep\HomeSuite\ControlContract::T_SWITCH,
                'role' => 'hub:arm', 'label' => $ag[1], 'varType' => 0,
                'profile' => '~Switch', 'actionable' => true,
            ]);
        }

        return $m->toArray();
    }

    /**
     * Der Hub hat keine Bedien-Controls; RequestAction wird faktisch nie mit
     * einem gueltigen Control aufgerufen. Die Basis faengt das ab. Dieser Hook
     * bleibt daher leer.
     */
    public function ApplyChanges()
    {
        parent::ApplyChanges();
        // Die Senderdatei ist nur eine Ableitung des Stores. Geht sie verloren (Umzug,
        // Aufraeumen, Rechteproblem), waeren ALLE Sender weg - deshalb wird sie hier bei
        // jedem Uebernehmen und bei jedem Kernelstart aus dem Store neu geschrieben.
        $this->senderDateiSchreiben();
        // Globalen Automatik-Schalter einmalig auf AN setzen (fail-safe Default true).
        if (!(bool) $this->store()->get('autoSeeded', false)) {
            @$this->SetValue('AutomationEnabled', true);
            $this->store()->set('autoSeeded', true);
        }

        // Scharf-Master einmalig aus dem aktuellen per-Instanz-Armed jeder Domaene
        // seeden (so bleibt der bisherige Live/Schatten-Zustand exakt erhalten).
        if (!$this->ReadAttributeBoolean('ArmSeeded')) {
            $doms = [
                'ArmLight'      => '{B7E1C3A4-5D62-4F08-9A1E-2C7D6B4F0E93}',
                'ArmHeating'    => '{AC059357-088A-4DF8-ABBC-F8724BC78769}',
                'ArmShading'    => '{A9645ED8-CB55-43B8-869B-BFF6ACFC8DC1}',
                'ArmIrrigation' => '{D264A82B-DE31-45CC-8AF2-8F4C5D076508}',
                'ArmAudio'      => '{C4F2639D-2A87-453D-8175-B586BF605A38}',
                'ArmPool'       => '{878CA345-86D1-84FC-B196-5B3224C067CF}',
                'ArmMower'      => '{D1FB2D11-21F3-4B22-8341-E88D512A9B61}',
                'ArmClimate'    => '{81B7257F-4B34-4024-89B3-98BC43E00E54}',
            ];
            foreach ($doms as $ident => $guid) {
                $on = false;
                foreach (@\IPS_GetInstanceListByModuleID($guid) ?: [] as $iid) {
                    if (@\IPS_GetProperty($iid, 'Armed')) { $on = true; break; }
                }
                if (@$this->GetIDForIdent($ident)) { @$this->SetValue($ident, $on); }
            }
            $this->WriteAttributeBoolean('ArmSeeded', true);
        }

        // 3-Zustand-Scharf-Master (Aus/Auto/Scharf): Integer-Mode-Var je Domaene sicherstellen
        // und einmalig aus dem alten Bool-Master seeden (Zustand bleibt exakt erhalten).
        $this->ensureArmModes();

        // Licht-Automatik: Timer + Bewegungs-Messages entsprechend Konfig einrichten.
        $this->lightAutoWire();

        // Baum-Transparenz: sichtbare bl_-Links + Loeschschutz auf alle vom Hub
        // referenzierten physischen Objekte (globale Sensoren/Standort, Automatik-
        // Regeln, Szenen-Mitglieder). syncBindingLinks() laeuft schon aus parent.
        $this->syncHubReferences();

        // Nordausrichtung: bei Aenderung der Haus-Abweichung ALLE Sonnenprofile
        // (Hub-benannte + je Zone geoProfile) um die Differenz mitdrehen. Beim
        // ersten Lauf nur Baseline merken (kein Drehen).
        $want = (float) $this->ReadPropertyFloat('ShadeNorthDeg');
        $applied = $this->ReadAttributeString('ShadeNorthApplied');
        if ($applied !== '' && abs((float) $applied - $want) > 0.001) {
            $this->rotateSun($want - (float) $applied);
        }
        $this->WriteAttributeString('ShadeNorthApplied', (string) $want);
        // Baum-Variable (Slider) mit der Property synchron halten.
        if (@$this->GetIDForIdent('ShadeNorth')) { @$this->SetValue('ShadeNorth', $want); }
    }

    /** Idents der Scharf-Master je Domaene (Bool-Master + Integer-Mode-Var <ident>Mode). */
    private const ARM_IDENTS = ['ArmLight', 'ArmHeating', 'ArmShading', 'ArmIrrigation', 'ArmAudio', 'ArmPool', 'ArmMower', 'ArmClimate'];

    /**
     * 3-Zustand-Master (Aus/Auto/Scharf): Profil + je Domaene eine Integer-Mode-Variable <ident>Mode
     * sicherstellen und EINMALIG aus dem alten Bool-Master seeden (false->0 Aus, true->2 Scharf), damit
     * der bisherige Live/Schatten-Zustand exakt erhalten bleibt. Additiv: der Bool bleibt bestehen
     * (Alt-Bindungen) und wird bei Mode-Wechsel synchron gehalten. hubArmGate liest Mode zuerst.
     */
    private function ensureArmModes(): void
    {
        $pn = 'HSSuite.ArmMode';
        if (function_exists('IPS_VariableProfileExists') && !@\IPS_VariableProfileExists($pn)) {
            @\IPS_CreateVariableProfile($pn, 1);
            @\IPS_SetVariableProfileValues($pn, 0, 2, 1);
            @\IPS_SetVariableProfileAssociation($pn, 0, 'Aus (alle Schatten)', '', -1);
            @\IPS_SetVariableProfileAssociation($pn, 1, 'Auto (je Zone)', '', 0x00CDAB);
            @\IPS_SetVariableProfileAssociation($pn, 2, 'Scharf (alle real)', '', 0xE5484D);
        }
        $pos = 90;
        foreach (self::ARM_IDENTS as $id) {
            if (!@$this->GetIDForIdent($id . 'Mode')) {
                $this->RegisterVariableInteger($id . 'Mode', $id . ' Modus', $pn, $pos);
            }
            $pos++;
        }
        if (!$this->ReadAttributeBoolean('ArmModeSeeded')) {
            foreach (self::ARM_IDENTS as $id) {
                $bv = @$this->GetIDForIdent($id);
                $on = ($bv && $bv > 0) ? (bool) @\GetValue($bv) : false;
                if (@$this->GetIDForIdent($id . 'Mode')) { @$this->SetValue($id . 'Mode', $on ? 2 : 0); }
            }
            $this->WriteAttributeBoolean('ArmModeSeeded', true);
        }

        // ---- Wie viele Lichter brennen gerade? ------------------------------------
        //
        // Eine Zahl, die man binden kann: fuer die Visu, fuer "alles aus"-Hinweise, fuer
        // Auswertungen. Sie wird NICHT gerechnet, wenn jemand danach fragt, sondern
        // fortlaufend nachgefuehrt - sonst zeigt sie beim Ansehen einen Stand von vorhin.
        //
        // Zwei Wege zusammen, weil jeder allein eine Luecke hat: der MessageSink meldet
        // jede Aenderung sofort (das ist der Normalfall), und der Provision-Timer zaehlt
        // zusaetzlich turnusmaessig nach - falls eine Leuchte neu dazukommt, verschwindet
        // oder ihre Statusvariable getauscht wird, ohne dass jemand ApplyChanges drueckt.
        if (!@$this->GetIDForIdent('LightsOn')) {
            $this->RegisterVariableInteger('LightsOn', 'Lichter an', '', 120);
        }
        if (!@$this->GetIDForIdent('LightsOff')) {
            $this->RegisterVariableInteger('LightsOff', 'Lichter aus', '', 121);
        }
        if (!@$this->GetIDForIdent('LightsTotal')) {
            $this->RegisterVariableInteger('LightsTotal', 'Lichter gesamt', '', 122);
        }
        $this->lichterHorchen();
        $this->lichterZaehlen();
    }

    /**
     * Auf die Schaltzustaende ALLER Leuchten horchen.
     *
     * Angemeldet wird die Power-Statusvariable jeder LightDevice-Instanz. Doppelte
     * Anmeldungen sind unschaedlich (IPS haelt die Liste eindeutig), deshalb genuegt
     * der Aufruf bei jedem ApplyChanges - so werden neu angelegte Leuchten mit
     * eingesammelt.
     */
    private function lichterHorchen(): void
    {
        foreach ($this->hsltList() as $iid) {
            $vid = (int) @\IPS_GetObjectIDByIdent('Power', $iid);
            if ($vid > 0) {
                @$this->RegisterMessage($vid, 10603 /* VM_UPDATE */);
            }
        }
    }

    /** Brennende und dunkle Leuchten zaehlen und in die drei Variablen schreiben. */
    private function lichterZaehlen(): void
    {
        $an = 0;
        $gesamt = 0;
        foreach ($this->hsltList() as $iid) {
            $vid = (int) @\IPS_GetObjectIDByIdent('Power', $iid);
            if ($vid <= 0) {
                continue;
            }
            $gesamt++;
            if ((bool) @\GetValue($vid)) {
                $an++;
            }
        }
        if (@$this->GetIDForIdent('LightsOn'))    { @$this->SetValue('LightsOn', $an); }
        if (@$this->GetIDForIdent('LightsOff'))   { @$this->SetValue('LightsOff', $gesamt - $an); }
        if (@$this->GetIDForIdent('LightsTotal')) { @$this->SetValue('LightsTotal', $gesamt); }
    }

    /**
     * Setzt den 3-Zustand-Master einer Domaene (0=Aus, 1=Auto, 2=Scharf) und haelt den alten
     * Bool-Master synchron (bool = mode>=2). Public: aus dem Konfig-Formular aufgerufen.
     */
    public function SetArmMode(string $ident, int $mode): void
    {
        if (!in_array($ident, self::ARM_IDENTS, true)) { return; }
        $mode = max(0, min(2, $mode));
        if (@$this->GetIDForIdent($ident . 'Mode')) { @$this->SetValue($ident . 'Mode', $mode); }
        if (@$this->GetIDForIdent($ident))          { @$this->SetValue($ident, $mode >= 2); }
    }

    /**
     * Dreht ALLE Sonnenprofile um $delta Grad (mod 360): die Hub-benannten Profile
     * (profiles.sun) UND je ShadingDevice-Zone das geoProfile (via HSSH_Manage
     * rotateGeo). Kern der zentralen „Nordausrichtung".
     */
    private function rotateSun(float $delta): array
    {
        $rot = function ($v) use ($delta) { $n = fmod(((float) $v + $delta), 360.0); if ($n < 0) { $n += 360.0; } return (int) round($n); };
        $map = $this->store()->get('profiles.sun', []);
        $profiles = 0;
        if (is_array($map)) {
            foreach ($map as $name => $p) {
                if (!is_array($p)) { continue; }
                if (isset($p['azimuthBgn'])) { $p['azimuthBgn'] = $rot($p['azimuthBgn']); }
                if (isset($p['azimuthEnd'])) { $p['azimuthEnd'] = $rot($p['azimuthEnd']); }
                $map[$name] = $p;
                $profiles++;
            }
            $this->store()->set('profiles.sun', $map);
        }
        $zones = 0;
        if (function_exists('IPS_GetInstanceListByModuleID') && function_exists('HSSH_Manage')) {
            foreach (@\IPS_GetInstanceListByModuleID(self::GUID_HSSH) as $iid) {
                @\HSSH_Manage((int) $iid, json_encode(['op' => 'rotateGeo', 'args' => ['deltaDeg' => $delta]]));
                $zones++;
            }
        }
        return ['ok' => true, 'delta' => $delta, 'profiles' => $profiles, 'zones' => $zones];
    }

    protected function applyControl(Control $c, $value, ActionContext $ctx): void
    {
        // Nordausrichtung-Slider -> Property setzen + ApplyChanges (dort erfolgt die
        // Rotation aller Sonnenprofile ueber den Baseline-Delta-Vergleich).
        if ($c->ident === 'ShadeNorth') {
            @\IPS_SetProperty($this->InstanceID, 'ShadeNorthDeg', (float) $value);
            @\IPS_ApplyChanges($this->InstanceID);
        }
        // sonst: Hub wird nur ueber Statusvariablen/Manage gesteuert
    }

    /**
     * Alle vom Hub referenzierten physischen Objekte einsammeln (globale Sensoren/
     * Standort aus Properties + Automatik-/Szenen-Geraete aus dem Store) -> sichtbare
     * bl_-Links (Baum-Transparenz). Dedupliziert je Objekt-ID.
     */
    protected function bindingTargets(): array
    {
        $out = []; $seen = [];
        $add = function (string $ident, string $name, $id) use (&$out, &$seen): void {
            $id = (int) $id;
            if ($id > 0 && !isset($seen[$id]) && function_exists('IPS_ObjectExists') && @\IPS_ObjectExists($id)) {
                $seen[$id] = true;
                $out[] = ['ident' => $ident, 'name' => $name, 'targetId' => $id];
            }
        };
        // Globale Sensoren/Standort (Properties)
        $add('bl_LocationId', 'Standort', $this->ReadPropertyInteger('LocationId'));
        $add('bl_ShadeWindId', 'Wind (global)', $this->ReadPropertyInteger('ShadeWindId'));
        $add('bl_ShadeRainId', 'Regen (global)', $this->ReadPropertyInteger('ShadeRainId'));
        $add('bl_ShadeTempOutId', 'Aussentemperatur (global)', $this->ReadPropertyInteger('ShadeTempOutId'));
        $add('bl_ShadeBrightId', 'Helligkeit/Globalstrahlung (global)', $this->ReadPropertyInteger('ShadeBrightId'));
        $add('bl_ShadeSunAzId', 'Sonne Azimut', $this->ReadPropertyInteger('ShadeSunAzId'));
        $add('bl_ShadeSunElId', 'Sonne Elevation', $this->ReadPropertyInteger('ShadeSunElId'));
        $add('bl_IrrRainSensorId', 'Regensensor (global)', $this->ReadPropertyInteger('IrrRainSensorId'));
        // Licht-Automatik-Regeln
        $la = $this->store()->get('lightAuto', []);
        $rules = (is_array($la) && is_array($la['rules'] ?? null)) ? $la['rules'] : [];
        $ri = 0;
        foreach ($rules as $r) {
            $ri++;
            if (!is_array($r)) { continue; }
            foreach (['sensor' => 'Bewegung', 'lux' => 'Lux', 'awayVar' => 'Abwesenheit', 'audioZone' => 'Audio-Zone'] as $k => $lab) {
                if (!empty($r[$k]) && is_numeric($r[$k])) { $add('bl_la' . $ri . '_' . $k, 'Auto ' . $ri . ': ' . $lab, $r[$k]); }
            }
            foreach ((array) ($r['devices'] ?? []) as $j => $d) { $add('bl_la' . $ri . '_dev' . $j, 'Auto ' . $ri . ': Gerät', $d); }
        }
        // Licht-Szenen (Mitglieder + Raum-Bezug)
        $sc = $this->store()->get('lightScenes', []);
        if (is_array($sc)) {
            $si = 0;
            foreach ($sc as $s) {
                $si++;
                if (!is_array($s)) { continue; }
                $ref = $s['scope']['ref'] ?? null;
                if (is_numeric($ref)) { $add('bl_sc' . $si . '_room', 'Szene ' . $si . ': Raum', $ref); }
                foreach ((array) ($s['members'] ?? []) as $m => $mem) {
                    if (is_array($mem) && !empty($mem['device'])) { $add('bl_sc' . $si . '_dev' . $m, 'Szene ' . $si . ': Gerät', $mem['device']); }
                }
            }
        }
        return $out;
    }

    /** Loeschschutz: alle bl_-Ziele zusaetzlich als Instanz-Referenzen registrieren. */
    private function syncHubReferences(): void
    {
        if (!method_exists($this, 'GetReferenceList')) { return; }
        foreach ($this->GetReferenceList() as $ref) { @$this->UnregisterReference($ref); }
        foreach ($this->bindingTargets() as $t) {
            $id = (int) $t['targetId'];
            if ($id > 0 && function_exists('IPS_ObjectExists') && @\IPS_ObjectExists($id)) {
                @$this->RegisterReference($id);
            }
        }
    }

    /** Baum-Sichtbarkeit: Szenen + Licht-Automatik als read-only JSON spiegeln. */
    protected function refreshMirrors(): void
    {
        $this->mirrorVar('ScenesJson', 'Licht-Szenen (JSON, Anzeige)', $this->store()->get('lightScenes', []));
        $this->mirrorVar('LightAutoJson', 'Licht-Automatik (JSON, Anzeige)', $this->store()->get('lightAuto', []));
    }

    // ==================================================================
    // Verwaltungs-Ops (mgmt) — bereits whitelist-geprueft durch Manage()
    // ==================================================================

    /**
     * Hub-spezifischer Verwaltungs-Dispatch. Wird von {@see EntityModule::Manage()}
     * aufgerufen, nachdem der `op` gegen die managementActions-Whitelist geprueft
     * wurde. Jeder Op laeuft in der try/catch-Huelle der Basis.
     *
     * @param array<string,mixed> $args
     * @param array<string,mixed> $ctx  {dryrun,confirm,baseVersion,hard,cascade}
     * @return array<string,mixed>
     */
    protected function mgmt(string $op, array $args, array $ctx): array
    {
        switch ($op) {
            case 'provision':
                return $this->enqueueProvision($args, !empty($ctx['dryrun']));

            case 'rotateToken':
                $token = $this->rotateTokenInternal();
                return ['ok' => true, 'op' => $op, 'result' => ['token' => $token]];

            case 'listSnapshots':
                return ['ok' => true, 'op' => $op, 'result' => ['snapshots' => $this->listSnapshots()]];

            case 'restoreSnapshot':
                if (empty($ctx['confirm'])) {
                    return ['ok' => false, 'op' => $op, 'error' => 'forbidden',
                        'detail' => 'restoreSnapshot ist destruktiv und verlangt confirm=1'];
                }
                $file = (string) ($args['file'] ?? '');
                if ($file === '') {
                    return ['ok' => false, 'op' => $op, 'error' => 'validation', 'field' => 'file'];
                }
                (new Backup())->restore($file);
                return ['ok' => true, 'op' => $op, 'result' => ['restored' => basename($file)]];

            case 'validate':
                return $this->mgmtValidateAll();

            case 'organizeTree':
                return $this->organizeTree();

            case 'rotateSun':
                return $this->rotateSun((float) ($args['deltaDeg'] ?? 0));

            case 'detectContacts':
                return ['ok' => true, 'contacts' => \Hoep\HomeSuite\Engines\Contacts::detect(
                    !isset($args['includeGates']) || (bool) $args['includeGates']
                )];

            case 'detectSensors':
                return ['ok' => true, 'sensors' => \Hoep\HomeSuite\Engines\Contacts::detectSensors(
                    (string) ($args['kind'] ?? 'motion')
                )];

            case 'getStations':
                return $this->mgmtGetStations();

            case 'configureStations':
                return $this->mgmtConfigureStations($args);

            case 'testStation':
                return $this->mgmtTestStation($args);

            case 'getSources':
                return ['ok' => true, 'op' => $op, 'sources' => $this->sourcesConfig(),
                    'schema' => \Hoep\HomeSuite\Engines\MediaProviders::schema()];

            case 'configureSources':
                return $this->mgmtConfigureSources($args);

            case 'spotifyAuthUrl':
                return $this->mgmtSpotifyAuthUrl();

            case 'decisionLog':
                return $this->mgmtDecisionLog($args);

            case 'shadeLog':
                return $this->mgmtShadeLog($args);

            case 'shadeLogClear':
                return $this->mgmtShadeLogClear($args);

            case 'mediaProviders':
                $ps = [];
                foreach (\Hoep\HomeSuite\Engines\MediaProviders::build($this->sourcesConfig()) as $id => $p) {
                    $ps[] = ['id' => $id, 'label' => $p->label(), 'configured' => $p->isConfigured()];
                }
                return ['ok' => true, 'providers' => $ps];

            case 'mediaBrowse':
                return $this->mgmtMediaBrowse($args);
            case 'mediaSearch':
                return $this->mgmtMediaBrowse($args, true);
            case 'mediaTracks':
                return $this->mgmtMediaTracks($args);

            case 'mediaCanWrite':
                return $this->mgmtMediaCanWrite();

            case 'mediaPlaylistList':
                return $this->mgmtMediaPlaylistList($args);

            case 'mediaPlaylistCreate':
                return $this->mgmtMediaPlaylist('create', $args);

            case 'mediaPlaylistAdd':
                return $this->mgmtMediaPlaylist('add', $args);

            case 'mediaPlaylistDelete':
                return $this->mgmtMediaPlaylist('delete', $args);
            case 'mediaResolve':
                return $this->mgmtMediaResolve($args);
        }

        if ($op === 'houseGeo') {
            // Geometrie des Hauses an EINER Stelle: Nordausrichtung und Koordinaten. Die
            // Anzeige-Widgets sollen sie hier holen, statt sie doppelt gepflegt zu bekommen.
            $co = $this->sunCoords();
            return ['ok' => true,
                'northDeg' => round((float) $this->ReadPropertyFloat('ShadeNorthDeg'), 2),
                'lat' => (float) ($co['lat'] ?? 48.2082),
                'lon' => (float) ($co['lon'] ?? 16.3738)];
        }
        if (strncmp($op, 'lightAuto', 9) === 0) {
            return $this->mgmtLightAuto($op, $args, $ctx);
        }
        if (strncmp($op, 'lightScene', 10) === 0) {
            return $this->mgmtLightScene($op, $args, $ctx);
        }
        if (strncmp($op, 'profile', 7) === 0) {
            return $this->mgmtProfile($op, $args);
        }
        return ['ok' => false, 'op' => $op, 'error' => 'not_implemented'];
    }

    // ==================================================================
    // Licht-Szenen (Haus-Ebene) — SceneEngine (Storage) + Capture/Apply (Kernel)
    // ==================================================================

    private const GUID_HSLT = '{B7E1C3A4-5D62-4F08-9A1E-2C7D6B4F0E93}';

    private function scenes(): \Hoep\HomeSuite\Engines\SceneEngine
    {
        return new \Hoep\HomeSuite\Engines\SceneEngine($this->store());
    }

    /** Alle LightDevice-Instanzen. */
    private function hsltList(): array
    {
        return array_map('intval', @\IPS_GetInstanceListByModuleID(self::GUID_HSLT) ?: []);
    }

    /**
     * Naechstgelegener Standort ueber dem Objekt (HSSP mit Kind = Haus).
     *
     * Seit dem 28.08.2026 gibt es mehrere. Raum- und Geschossnamen wiederholen
     * sich zwischen ihnen,
     * die Standort-ID nicht. 0 = kein Standort gefunden (Geraet haengt neben
     * der Struktur).
     */
    private function standortVon(int $id): int
    {
        $x = $id;
        for ($n = 0; $x > 0 && $n < 16; $n++) {
            if ((string) (@\IPS_GetInstance($x)['ModuleInfo']['ModuleID'] ?? '') === self::GUID_HSSP) {
                $cfg = json_decode((string) @\IPS_GetConfiguration($x), true);
                if (is_array($cfg) && (string) ($cfg['Kind'] ?? '') === 'Haus') {
                    return $x;
                }
            }
            $x = (int) @\IPS_GetParent($x);
        }
        return 0;
    }

    /** Ist-Zustand + Kontext eines Geraets. */
    private function hsltState(int $iid): array
    {
        $rs = function_exists('HSLT_Manage')
            ? json_decode((string) @\HSLT_Manage($iid, json_encode(['op' => 'readState'])), true) : null;
        $st = is_array($rs['state'] ?? null) ? $rs['state'] : [];
        $parent = (int) @\IPS_GetParent($iid);
        $pIdent = $parent > 0 ? (string) (@\IPS_GetObject($parent)['ObjectIdent'] ?? '') : '';
        $room = (strpos($pIdent, 'HSLT_FLOOR_') === 0) ? '' : (string) @\IPS_GetName($parent);
        $floor = (strpos($pIdent, 'HSLT_FLOOR_') === 0)
            ? (string) @\IPS_GetName($parent)
            : ($parent > 0 ? (string) @\IPS_GetName((int) @\IPS_GetParent($parent)) : '');
        // Praesenzmelder kommt vom RAUM (HSSP-Eigenschaft), nicht vom Geraet.
        // Ueber IPS_GetConfiguration statt IPS_GetProperty gelesen, weil letzteres
        // wirft, wenn die Eigenschaft (noch) nicht existiert - etwa bei aelteren
        // Raeumen vor dem Modul-Update.
        $praesenz = 0;
        if ($room !== '' && $parent > 0) {
            $rcfg = json_decode((string) @\IPS_GetConfiguration($parent), true);
            $praesenz = is_array($rcfg) ? (int) ($rcfg['PresenceVid'] ?? 0) : 0;
        }
        // Geschoss und Standort zusaetzlich als ID. Namen sind nicht eindeutig,
        // sobald es mehrere Standorte gibt: "Erdgeschoss" oder "Wohnzimmer" kann
        // es in jedem Haus geben. Die Namen bleiben fuer Anzeige und Altbestand
        // erhalten, entschieden wird kuenftig ueber die ID.
        $floorId = ($room !== '' && $parent > 0) ? (int) @\IPS_GetParent($parent) : $parent;
        $ortId   = $this->standortVon($iid);
        return ['id' => $iid, 'name' => (string) @\IPS_GetName($iid),
            'room' => $room, 'roomId' => $room !== '' ? $parent : 0, 'floor' => $floor,
            'floorId' => $floorId, 'locationId' => $ortId,
            'locationName' => $ortId > 0 ? (string) @\IPS_GetName($ortId) : '',
            'presenceVid' => $praesenz,
            'on' => (bool) ($st['on'] ?? false), 'level' => (int) ($st['level'] ?? -1),
            'color' => (int) ($st['color'] ?? -1), 'cct' => (int) ($st['cct'] ?? 0),
            'caps' => is_array($rs['caps'] ?? null) ? $rs['caps'] : []];
    }

    /**
     * Geraete-IDs im Geltungsbereich einer Szene.
     *
     * Vier Formen, von weit nach eng:
     *   house    ohne ref  -> ALLE Geraete, standortuebergreifend (Altbestand)
     *   house    mit  ref  -> nur dieser Standort (ref = ID des Hauses)
     *   location mit  ref  -> gleichbedeutend, sprechender Name
     *   floor    mit  ref  -> ein Geschoss; ID bevorzugt, Name als Rueckfall
     *   room     mit  ref  -> ein Raum ueber die ID
     *
     * Warum die ID: bis zum 28.08.2026 gab es einen Standort, da war
     * "Erdgeschoss" eindeutig. Bei vier Standorten ist es das nicht mehr - eine
     * Szene auf den NAMEN eines Geschosses wuerde in jedem Haus zugreifen.
     * Bestehende Szenen speichern den Namen; die werden weiter verstanden,
     * neue sollten die ID setzen.
     *
     * `house` ohne ref bleibt bewusst "alles": die vier vorhandenen Szenen sind
     * so gespeichert, und sie meinen heute tatsaechlich das ganze Haus, weil es
     * ausserhalb des Hauptstandorts keine Lampen gibt. Sie stillschweigend
     * einzuengen waere eine Verhaltensaenderung ohne Anlass.
     */
    private function scopeDevices(array $scope): array
    {
        $type = (string) ($scope['type'] ?? 'house');
        $ref  = (string) ($scope['ref'] ?? '');
        $ids  = [];
        foreach ($this->hsltList() as $iid) {
            $s = $this->hsltState($iid);
            $treffer = false;
            switch ($type) {
                case 'house':
                    $treffer = ($ref === '') || ((string) $s['locationId'] === $ref);
                    break;
                case 'location':
                    $treffer = ((string) $s['locationId'] === $ref);
                    break;
                case 'floor':
                    // Zahl = ID, sonst Name (Altbestand).
                    $treffer = ctype_digit($ref)
                        ? ((string) $s['floorId'] === $ref)
                        : ($s['floor'] === $ref);
                    break;
                case 'room':
                    $treffer = ((string) $s['roomId'] === $ref);
                    break;
            }
            if ($treffer) {
                $ids[] = $iid;
            }
        }
        return $ids;
    }

    /** Szene anwenden: je Mitglied Power/Brightness/ColorTemp per RequestAction (Schatten-sicher). */
    /**
     * Szene anwenden. $ein=false schaltet sie AUS: alle Leuchten aus, alle Variablen
     * auf ihren Aus-Wert. Eine Szene ohne Gegenrichtung waere nur halb bedienbar -
     * man koennte "Fernsehen" einschalten, aber nicht beenden.
     */
    /**
     * Nachfassen bei einem Befehl, der nicht angekommen ist.
     *
     * Die Lichtautomatik schaltet auf der KANTE und setzt bewusst keinen Sollzustand
     * durch - sonst wuerde sie eine von Hand eingeschaltete Lampe wieder ausknipsen.
     * Genau deshalb merkt sie aber auch nicht, wenn ein Befehl verpufft: die
     * Statusvariable wird beim Schalten optimistisch vorgeschrieben, ein stummes
     * Geraet sieht darin aus wie ein gehorsames.
     *
     * Diese Schlange liest deshalb den ECHTEN Zustand zurueck (Refresh holt ihn vom
     * Treiber) und wiederholt NUR den Befehl, der nicht angekommen ist. Ein Mensch,
     * der zwischendurch von Hand gegengesteuert hat, behaelt den Vorrang - erkannt
     * daran, dass sich der beobachtete Zustand GEAENDERT hat. Ein taubes Geraet
     * aendert sich nicht, ein bedienter Schalter schon. Zeitstempel taugen dafuer
     * nicht: Refresh schreibt die Variable selbst und saehe wie ein Eingriff aus.
     *
     * @param array<int,array{iid:int,want:bool,bis:int,versuche:int,zuletzt?:bool}> $liste
     * @return array<int,array<string,mixed>> die noch offenen Faelle
     */
    private function nachfassen(array $liste, int $now): array
    {
        $bleibt = [];
        foreach ($liste as $e) {
            $iid = (int) ($e['iid'] ?? 0);
            if ($iid <= 0 || !@\IPS_InstanceExists($iid)) {
                continue;
            }
            // Ist-Zustand vom Geraet holen, nicht aus der optimistisch gesetzten Anzeige.
            if (\function_exists('HSLT_Refresh')) {
                @\HSLT_Refresh($iid);
            }
            $pv = (int) (@\IPS_GetObjectIDByIdent('Power', $iid) ?: 0);
            if ($pv <= 0) {
                continue;
            }
            $ist  = (bool) @\GetValue($pv);
            $want = (bool) ($e['want'] ?? false);
            if ($ist === $want) {
                continue;                       // angekommen - erledigt
            }
            if (array_key_exists('zuletzt', $e) && (bool) $e['zuletzt'] !== $ist) {
                continue;                       // jemand hat gegengesteuert - der hat Vorrang
            }
            if ($now >= (int) ($e['bis'] ?? 0)) {
                $this->LogMessage(sprintf(
                    'Licht-Automatik: %s (#%d) nimmt "%s" nicht an - nach %d Versuchen aufgegeben.',
                    (string) @\IPS_GetName($iid), $iid, $want ? 'ein' : 'aus', (int) ($e['versuche'] ?? 0)
                ), KL_WARNING);
                continue;
            }
            @\RequestAction($pv, $want);
            $e['versuche'] = (int) ($e['versuche'] ?? 0) + 1;
            $e['zuletzt']  = $ist;
            $bleibt[] = $e;
        }
        return $bleibt;
    }

    private function applyScene(array $scene, bool $ein = true, string $grund = 'Szene von Hand'): array
    {
        $applied = 0; $skipped = 0; $pending = [];
        $set = function (int $iid, string $ident, $val) {
            $vid = (int) (@\IPS_GetObjectIDByIdent($ident, $iid) ?: 0);
            if ($vid <= 0 || !@\IPS_VariableExists($vid)) { return false; }
            $v = @\IPS_GetVariable($vid);
            if ((int) ($v['VariableAction'] ?? 0) > 0 || (int) ($v['VariableCustomAction'] ?? 0) > 0) {
                @\RequestAction($vid, $val);
            } else {
                @\SetValue($vid, $val);
            }
            return true;
        };
        foreach ((array) ($scene['members'] ?? []) as $m) {
            $iid = (int) ($m['device'] ?? 0);
            if ($iid <= 0 || !@\IPS_InstanceExists($iid)) { $skipped++; continue; }
            $on = $ein ? (bool) ($m['on'] ?? false) : false;
            $set($iid, 'Power', $on);
            $pending[] = ['iid' => $iid, 'want' => $on];
            if ($on && (int) ($m['level'] ?? -1) >= 0) { $set($iid, 'Brightness', (int) $m['level']); }
            if ($on && (int) ($m['cct'] ?? 0) > 0)     { $set($iid, 'ColorTemp', (int) $m['cct']); }
            if ($on && (int) ($m['color'] ?? -1) >= 0) { $set($iid, 'Color', (int) $m['color']); }
            $applied++;
        }
        // Eine Szene ist EINE Entscheidung, auch wenn sie vierzig Leuchten anfasst -
        // ein Eintrag je Lampe waere im Gesamtlog unlesbar. Szenen laufen ausserdem
        // NICHT ueber autoSetDevice, waeren hier also sonst gar nicht sichtbar.
        $this->entscheidungMerken(
            'Szene „' . (string) ($scene['name'] ?? ($scene['id'] ?? '?')) . '" ' . ($ein ? 'an' : 'aus'),
            $grund,
            ['geschaltet' => $applied] + ($skipped > 0 ? ['uebersprungen' => $skipped] : []),
            true
        );
        // Schaltbare Variablen: RequestAction, wenn die Variable eine Aktion hat, sonst
        // SetValue. Der Wert wird auf den Typ der Variablen gecastet - der Editor kennt
        // nur Text, ein Integer-Ziel bekaeme sonst "1" statt 1.
        $vAppl = 0; $vSkip = 0;
        foreach ((array) ($scene['vars'] ?? []) as $v) {
            $vid = (int) ($v['vid'] ?? 0);
            if ($vid <= 0 || !@\IPS_VariableExists($vid)) { $vSkip++; continue; }
            $roh = $ein ? ($v['on'] ?? true) : ($v['off'] ?? false);
            $var = @\IPS_GetVariable($vid);
            switch ((int) ($var['VariableType'] ?? 0)) {
                case 0: $wert = is_bool($roh) ? $roh
                        : in_array(strtolower(trim((string) $roh)), ['1','true','ein','an','on','ja'], true); break;
                case 1: $wert = (int) $roh; break;
                case 2: $wert = (float) str_replace(',', '.', (string) $roh); break;
                default: $wert = (string) $roh;
            }
            if ((int) ($var['VariableAction'] ?? 0) > 0 || (int) ($var['VariableCustomAction'] ?? 0) > 0) {
                @\RequestAction($vid, $wert);
            } else {
                @\SetValue($vid, $wert);
            }
            $vAppl++;
        }
        // Skripte ZULETZT: so sehen sie den bereits gesetzten Zustand. Bewusst
        // asynchron - eine Szene darf nicht an einem langsamen Skript haengenbleiben.
        // Das Skript bekommt die Richtung mit, damit EIN Skript beide Faelle bedienen kann.
        $sRun = 0; $sSkip = 0;
        foreach ((array) ($scene['scripts'] ?? []) as $sc) {
            $sid = (int) ($sc['sid'] ?? 0);
            if ($sid <= 0 || !@\IPS_ScriptExists($sid)) { $sSkip++; continue; }
            $when = (string) ($sc['when'] ?? 'on');
            if ($ein && $when === 'off')  { continue; }
            if (!$ein && $when === 'on')  { continue; }
            @\IPS_RunScriptEx($sid, ['SCENE' => (string) ($scene['id'] ?? ''), 'STATE' => $ein ? 'on' : 'off']);
            $sRun++;
        }
        return ['pending' => $pending, 'applied' => $applied, 'skipped' => $skipped, 'vars' => $vAppl,
                'varsSkipped' => $vSkip, 'scripts' => $sRun, 'scriptsSkipped' => $sSkip];
    }

    private function mgmtLightScene(string $op, array $args, array $ctx): array
    {
        $eng = $this->scenes();
        switch ($op) {
            case 'lightSceneList':
                return ['ok' => true, 'scenes' => $eng->list()];
            case 'lightSceneGet':
                $s = $eng->get((string) ($args['id'] ?? ''));
                return $s ? ['ok' => true, 'scene' => $s] : ['ok' => false, 'error' => 'not_found'];
            case 'lightSceneSave':
                $sc = is_array($args['scene'] ?? null) ? $args['scene'] : $args;
                return ['ok' => true, 'scene' => $eng->save($sc, time())];
            case 'lightSceneRename':
                return ['ok' => true, 'scene' => $eng->rename((string) ($args['id'] ?? ''), (string) ($args['newName'] ?? ''))];
            case 'lightSceneDelete':
                $eng->delete((string) ($args['id'] ?? ''));
                return ['ok' => true];
            case 'lightSceneCapture':
                $scope = is_array($args['scope'] ?? null) ? $args['scope'] : ['type' => 'house', 'ref' => ''];
                $members = [];
                foreach ($this->scopeDevices($scope) as $iid) {
                    $s = $this->hsltState($iid);
                    $members[] = ['device' => $iid, 'on' => $s['on'],
                        'level' => ($s['caps']['dim'] ?? false) ? $s['level'] : -1,
                        'color' => $s['color'], 'cct' => $s['cct']];
                }
                $scene = $eng->save([
                    'id'    => (string) ($args['id'] ?? ''),
                    'name'  => (string) ($args['name'] ?? 'Neue Szene'),
                    'icon'  => (string) ($args['icon'] ?? 'bulb'),
                    'scope' => $scope,
                    'transitionMs' => (int) ($args['transitionMs'] ?? 0),
                    'members' => $members,
                    // Beim Neu-Aufnehmen bleiben die Variablen der Szene erhalten -
                    // aufgenommen wird der LICHT-Zustand, nicht der Rest.
                    'vars' => (array) ($eng->get((string) ($args['id'] ?? ''))['vars'] ?? []),
                ], time());
                return ['ok' => true, 'scene' => $scene, 'captured' => count($members)];
            case 'lightSceneApply':
                $s = $eng->get((string) ($args['id'] ?? ''));
                if (!$s) { return ['ok' => false, 'error' => 'not_found']; }
                return ['ok' => true] + $this->applyScene($s, true);
            case 'lightSceneOff':
                $s = $eng->get((string) ($args['id'] ?? ''));
                if (!$s) { return ['ok' => false, 'error' => 'not_found']; }
                return ['ok' => true] + $this->applyScene($s, false);
            default:
                return ['ok' => false, 'op' => $op, 'error' => 'not_implemented'];
        }
    }

    // ==================================================================
    // Objektbaum-Selbstorganisation — EINE Wurzel „HomeSuite", Geraete unter Raeumen
    // ==================================================================

    /** Stabile Wurzel „HomeSuite" (per Ident 'HomeSuiteRoot'); adoptiert vorhandene, legt sonst an. */
    private function homeRoot(): int
    {
        foreach (@\IPS_GetChildrenIDs(0) as $c) {
            if ((string) (@\IPS_GetObject($c)['ObjectIdent'] ?? '') === 'HomeSuiteRoot') {
                return $c;
            }
        }
        $root = 0;
        foreach (@\IPS_GetChildrenIDs(0) as $c) {
            $o = @\IPS_GetObject($c);
            if ((int) ($o['ObjectType'] ?? -1) === 0 && (string) @\IPS_GetName($c) === 'HomeSuite') {
                $root = $c;
                break;
            }
        }
        if ($root === 0) {
            $root = @\IPS_CreateCategory();
            @\IPS_SetName($root, 'HomeSuite');
            @\IPS_SetParent($root, 0);
        }
        @\IPS_SetIdent($root, 'HomeSuiteRoot');
        return $root;
    }

    /**
     * Räumt den HomeSuite-Objektbaum idempotent auf (nach jedem Provisionieren automatisch):
     *  - EINE Wurzel „HomeSuite" (Hub darunter)
     *  - verstreute HomeSuite-/Bewaesserungs-Wurzelkategorien unter die Wurzel ziehen
     *  - Domänen-Instanzen auf Wurzel-Ebene (parent 0) einsammeln (Sicherheitsnetz)
     *  - Geräte per EXAKTEM Namenstreffer dem gleichnamigen Raum zuordnen (keine Rate-Zuordnung)
     */
    public function organizeTree(): array
    {
        $root   = $this->homeRoot();
        $moved  = [];
        $unklar = [];
        if ((int) @\IPS_GetParent($this->InstanceID) !== $root) {
            @\IPS_SetParent($this->InstanceID, $root);
            $moved[] = 'Hub';
        }
        // Verstreute HomeSuite-Wurzelkategorien einsammeln
        foreach (@\IPS_GetChildrenIDs(0) as $c) {
            if ($c === $root) {
                continue;
            }
            if ((int) (@\IPS_GetObject($c)['ObjectType'] ?? -1) !== 0) {
                continue; // nur Kategorien
            }
            $nm = (string) @\IPS_GetName($c);
            if (stripos($nm, 'HomeSuite') === 0 || stripos($nm, 'Bewaesserung') === 0 || stripos($nm, 'Bewässerung') === 0) {
                @\IPS_SetParent($c, $root);
                $moved[] = $nm;
            }
        }
        // Räume (HSSP) + Domänen-Instanzen
        //
        // STANDORTFEST seit 28.08.2026: Frueher war $rooms ein Woerterbuch
        // Name -> ID, bei dem der zuletzt gefundene Raum gewann. Mit einem Haus
        // ging das gut. Seit es mehrere Standorte gibt, kommen "Wohnzimmer"
        // und "Schlafzimmer"
        // mehrfach vor - und das Aufraeumen haette Geraete in den falschen
        // Standort geschoben. Jetzt: Name -> LISTE, und verschoben wird nur,
        // wenn die Zuordnung eindeutig ist.
        $HSSP  = '{5598F752-886D-475F-91CE-5813A3C581E5}';
        $rooms = [];
        foreach (@\IPS_GetInstanceListByModuleID($HSSP) ?: [] as $r) {
            $rooms[(string) @\IPS_GetName($r)][] = $r;
        }
        /** Naechstgelegenes Haus ueber dem Objekt (0 = keines gefunden). */
        $hausVon = function (int $id) use ($HSSP): int {
            $x = $id;
            for ($n = 0; $x > 0 && $n < 16; $n++) {
                if ((string) (@\IPS_GetInstance($x)['ModuleInfo']['ModuleID'] ?? '') === $HSSP) {
                    $cfg = json_decode((string) @\IPS_GetConfiguration($x), true);
                    if (is_array($cfg) && (string) ($cfg['Kind'] ?? '') === 'Haus') {
                        return $x;
                    }
                }
                $x = (int) @\IPS_GetParent($x);
            }
            return 0;
        };
        $domains = [
            '{C4F2639D-2A87-453D-8175-B586BF605A38}', // Audio
            '{053E7017-584E-4F62-A246-EBA6CE3DE034}', // AudioBridged
            '{B7E1C3A4-5D62-4F08-9A1E-2C7D6B4F0E93}', // Light
            '{D264A82B-DE31-45CC-8AF2-8F4C5D076508}', // Irrigation
            '{A9645ED8-CB55-43B8-869B-BFF6ACFC8DC1}', // Shading
            '{AC059357-088A-4DF8-ABBC-F8724BC78769}', // Heating
            '{D1FB2D11-21F3-4B22-8341-E88D512A9B61}', // Mower
            '{81B7257F-4B34-4024-89B3-98BC43E00E54}', // Climate (HSAC)
        ];
        foreach ($domains as $g) {
            foreach (@\IPS_GetInstanceListByModuleID($g) ?: [] as $iid) {
                $p = (int) @\IPS_GetParent($iid);
                if ($p === 0) {                       // Sicherheitsnetz: nie auf Wurzel-Ebene liegen lassen
                    @\IPS_SetParent($iid, $root);
                    $moved[] = (string) @\IPS_GetName($iid);
                    $p = $root;
                }
                if ($p > 0 && (string) (@\IPS_GetInstance($p)['ModuleInfo']['ModuleID'] ?? '') === $HSSP) {
                    continue; // haengt bereits in einem Raum
                }
                $nm = (string) @\IPS_GetName($iid);
                $kandidaten = $rooms[$nm] ?? [];
                if ($kandidaten === []) {
                    continue;
                }
                if (count($kandidaten) > 1) {
                    // Mehrere Raeume dieses Namens: den im SELBEN Standort nehmen,
                    // in dem das Geraet heute schon liegt.
                    $hausGeraet = $hausVon($iid);
                    $passend = [];
                    foreach ($kandidaten as $r) {
                        if ($hausGeraet > 0 && $hausVon($r) === $hausGeraet) {
                            $passend[] = $r;
                        }
                    }
                    $kandidaten = $passend;
                }
                if (count($kandidaten) !== 1) {
                    // Nicht entscheidbar - lieber liegen lassen als falsch einordnen.
                    $unklar[] = $nm;
                    continue;
                }
                $ziel = (int) $kandidaten[0];
                if ($ziel !== $p) {
                    @\IPS_SetParent($iid, $ziel);
                    $moved[] = $nm . ' → Raum ' . $nm;
                }
            }
        }
        return ['ok' => true, 'root' => $root, 'moved' => count($moved), 'items' => $moved,
                'mehrdeutig' => array_values(array_unique($unklar))];
    }

    // ==================================================================
    // Licht-Automatik (L7-L11) — Timer/MessageSink -> LightAutomation-Engine
    // ==================================================================

    /**
     * Einen ausgeloesten Einmal-Wecker abschalten.
     *
     * Ueber den INHALT gesucht, nicht ueber den Index: der Tick arbeitet auf einer
     * neu durchnummerierten Kopie (nur eingeschaltete Regeln), deren Indizes nicht
     * zu denen im Speicher passen.
     */
    private function wakeEinmalEntschaerfen(array $regel): void
    {
        $c = $this->store()->get('lightAuto', []);
        if (!is_array($c) || !is_array($c['rules'] ?? null)) {
            return;
        }
        $gleich = static function (array $a, array $b): bool {
            foreach (['type', 'name', 'time', 'audioZone', 'volume', 'rampMin'] as $k) {
                if ((string) ($a[$k] ?? '') !== (string) ($b[$k] ?? '')) {
                    return false;
                }
            }
            return true;
        };
        foreach ($c['rules'] as $i => $r) {
            if (!is_array($r) || ($r['type'] ?? '') !== 'wake' || empty($r['once'])) {
                continue;
            }
            if (($r['enabled'] ?? true) === false || !$gleich($r, $regel)) {
                continue;
            }
            $c['rules'][$i]['enabled'] = false;
            $this->store()->set('lightAuto', $c);
            $this->LogMessage('Einmal-Wecker "' . (string) ($r['name'] ?? '') . '" hat geweckt und ist jetzt aus.', KL_MESSAGE);
            return;
        }
    }

    private function lightAutoCfg(): array
    {
        $c = $this->store()->get('lightAuto', []);
        $c = is_array($c) ? $c : [];
        return ['enabled' => (bool) ($c['enabled'] ?? false),
                'rules'   => is_array($c['rules'] ?? null) ? $c['rules'] : []];
    }

    /** Timer + Bewegungs-Messages gemaess Konfig einrichten (idempotent). */
    private function lightAutoWire(): void
    {
        $cfg = $this->lightAutoCfg();
        $active = $cfg['enabled'] && $cfg['rules'] !== [];
        @$this->SetTimerInterval(self::TIMER_LIGHTAUTO, $active ? self::LIGHTAUTO_TICK_MS : 0);
        // Bewegungssensoren fuer Event-Auswertung registrieren (VM_UPDATE = 10603).
        foreach ($cfg['rules'] as $r) {
            if (($r['type'] ?? '') === 'motion' && ($r['enabled'] ?? true) !== false) {
                $sid = (int) ($r['sensor'] ?? 0);
                if ($sid > 0 && @\IPS_VariableExists($sid)) {
                    @$this->RegisterMessage($sid, 10603 /* VM_UPDATE */);
                }
            }
        }
    }

    /** Variable eines Geraets (per Ident) schatten-sicher schreiben (RequestAction/SetValue). */
    private function setDeviceVar(int $iid, string $ident, $val): bool
    {
        $vid = (int) (@\IPS_GetObjectIDByIdent($ident, $iid) ?: 0);
        if ($vid <= 0 || !@\IPS_VariableExists($vid)) {
            return false;
        }
        $v = @\IPS_GetVariable($vid);
        if ((int) ($v['VariableAction'] ?? 0) > 0 || (int) ($v['VariableCustomAction'] ?? 0) > 0) {
            @\RequestAction($vid, $val);
        } else {
            @\SetValue($vid, $val);
        }
        return true;
    }

    /**
     * Von der Lichtautomatik geschaltet - mit Begruendung.
     *
     * Die Automatik sitzt im Hub, nicht im LightDevice: Bewegung, Circadian, Szenen und
     * Zeitregeln entscheiden ZONENUEBERGREIFEND. Wer im Protokoll einer einzelnen Leuchte
     * nachsaehe, warum sie anging, faende deshalb nichts - der Eintrag gehoert hierher.
     *
     * $grund nennt die Regel, die geschaltet hat (Name aus der Konfig), damit im Protokoll
     * "Bewegung Gang" steht und nicht bloss "Automatik".
     */
    private function autoSetDevice(int $iid, bool $on, int $level = -1, int $cct = 0, string $grund = 'Lichtautomatik'): void
    {
        if (!@\IPS_InstanceExists($iid)) {
            return;
        }
        // Entdopplung je Leuchte: die Circadian-Regel faehrt Helligkeit und Farbtemperatur
        // im Takt nach. Ohne diesen Riegel stuende dasselbe Schalten hundertfach im
        // Protokoll, und der Ringpuffer waere voll, bevor ein echtes Ereignis darin
        // auftaucht. Verglichen wird das GANZE Ergebnis, ein Helligkeitswechsel zaehlt
        // also weiterhin - nur die Wiederholung nicht.
        $sig = $iid . '|' . ($on ? 1 : 0) . '|' . $level . '|' . $cct . '|' . $grund;
        $rt  = $this->readRt();
        $seen = is_array($rt['lightSig'] ?? null) ? $rt['lightSig'] : [];
        if ((string) ($seen[$iid] ?? '') !== $sig) {
            $seen[$iid] = $sig;
            $rt['lightSig'] = $seen;
            $this->writeRt($rt);
            $this->entscheidungMerken(
                (@\IPS_GetName($iid) ?: ('Leuchte ' . $iid)) . ' ' . ($on ? 'ein' : 'aus'),
                $grund,
                ['leuchte' => $iid] + ($on && $level >= 0 ? ['helligkeit' => $level] : [])
                                    + ($on && $cct > 0 ? ['farbtemperatur_k' => $cct] : []),
                true
            );
        }
        $this->setDeviceVar($iid, 'Power', $on);
        if ($on && $level >= 0) {
            $this->setDeviceVar($iid, 'Brightness', $level);
        }
        if ($on && $cct > 0) {
            $this->setDeviceVar($iid, 'ColorTemp', $cct);
        }
    }

    /** Ist eine LightDevice-Zone gerade an? (fuer Circadian: nur an-Lampen nachfuehren) */
    private function deviceOn(int $iid): bool
    {
        $vid = (int) (@\IPS_GetObjectIDByIdent('Power', $iid) ?: 0);
        return $vid > 0 && @\IPS_VariableExists($vid) && (bool) @\GetValue($vid);
    }

    /** Zyklische Auswertung: Trigger (Zeit/Sonne), Circadian, Wecken, Anwesenheitssim. */
    public function lightAutoTick(): void
    {
        $cfg = $this->lightAutoCfg();
        if (!$cfg['enabled'] || $cfg['rules'] === []) {
            return;
        }
        if (!$this->automationEnabled()) {
            return; // globaler Automatik-Schalter aus
        }
        $rules = array_values(array_filter($cfg['rules'], static fn($r) => ($r['enabled'] ?? true) !== false));
        if ($rules === []) {
            return;
        }
        $now = time();
        $nowMin = (int) date('G', $now) * 60 + (int) date('i', $now);
        $weekday = (int) date('w', $now);

        // Sonnenzeiten/-hoehe (best effort)
        $coords = $this->sunCoords();
        $lat = (float) ($coords['lat'] ?? 48.2082);
        $lon = (float) ($coords['lon'] ?? 16.3738);
        $si = @date_sun_info($now, $lat, $lon);
        $srMin = is_array($si) ? (int) date('G', (int) $si['sunrise']) * 60 + (int) date('i', (int) $si['sunrise']) : 360;
        $ssMin = is_array($si) ? (int) date('G', (int) $si['sunset']) * 60 + (int) date('i', (int) $si['sunset']) : 1200;
        $sunMin = ['sunrise' => $srMin, 'sunset' => $ssMin];
        $elev = 0.0;
        if ($nowMin > $srMin && $nowMin < $ssMin && $ssMin > $srMin) {
            $frac = ($nowMin - $srMin) / max(1, $ssMin - $srMin);
            $elev = sin(M_PI * $frac) * 55.0; // Pseudo-Elevation fuer Circadian
        }

        $st = $this->store()->get('_lightAutoState', []);
        $st = is_array($st) ? $st : [];
        $prevMin = isset($st['prevMin']) ? (int) $st['prevMin'] : $nowMin;

        // --- L7/L9: faellige Zeit-/Sonnen-Trigger + Wecken ---
        // Offene Prueflinge aus frueheren Durchgaengen zuerst: erst nachfassen, dann neu schalten.
        $st['nachfassen'] = $this->nachfassen(is_array($st['nachfassen'] ?? null) ? $st['nachfassen'] : [], $now);
        foreach (\Hoep\HomeSuite\Engines\LightAutomation::dueTriggers($rules, $prevMin, $nowMin, $weekday, $sunMin) as $act) {
            if (($act['kind'] ?? '') === 'applyScene') {
                $sc = $this->scenes()->get((string) $act['sceneId']);
                if ($sc) {
                    // Richtung kommt aus der Regel: eine Szene kann angewandt ODER
                    // ausgeschaltet werden. Fehlt die Angabe, gilt wie bisher "ein".
                    $rn = trim((string) ($act['name'] ?? '')) !== '' ? (string) $act['name'] : 'Zeitregel';
                    $r = $this->applyScene($sc, (bool) ($act['ein'] ?? true), 'Regel "' . $rn . '"');
                    // Ein Schaltbefehl kann verpuffen. Die Kante feuert nur einmal, also
                    // wird das Ergebnis in den naechsten Durchgaengen nachgeprueft.
                    foreach ((array) ($r['pending'] ?? []) as $p) {
                        $st['nachfassen'][] = $p + ['bis' => $now + self::NACHFASS_SEK, 'versuche' => 0];
                    }
                    $st['nachfassen'] = array_slice($st['nachfassen'], -self::NACHFASS_MAX);
                }
            } elseif (($act['kind'] ?? '') === 'wake') {
                $r = is_array($act['rule'] ?? null) ? $act['rule'] : [];
                $this->applyWake($r);
                // Einmal-Wecker: nach dem Ausloesen abschalten. Er bleibt stehen,
                // damit man sieht, dass er gelaufen ist, und ihn mit einem Griff
                // wieder scharf stellen kann.
                if (!empty($r['once'])) {
                    $this->wakeEinmalEntschaerfen($r);
                }
            }
        }

        // --- L8: Circadian (nur eingeschaltete Lampen nachfuehren) ---
        foreach ($rules as $r) {
            if (($r['type'] ?? '') !== 'circadian') {
                continue;
            }
            $t = \Hoep\HomeSuite\Engines\LightAutomation::circadian($r, $elev);
            foreach ((array) ($r['devices'] ?? []) as $iid) {
                $iid = (int) $iid;
                if ($iid > 0 && $this->deviceOn($iid)) {
                    $wantLevel = !empty($r['level']) ? (int) $t['level'] : -1;
                    $this->autoSetDevice($iid, true, $wantLevel, (int) $t['cct'], 'Circadian');
                }
            }
        }

        // --- L11: Anwesenheitssimulation ---
        foreach ($rules as $ri => $r) {
            if (($r['type'] ?? '') !== 'presence') {
                continue;
            }
            $away = ((int) ($r['awayVar'] ?? 0) > 0) ? (bool) @\GetValue((int) $r['awayVar']) : false;
            $devs = array_values(array_filter(array_map('intval', (array) ($r['devices'] ?? []))));
            $lastKey = 'presLast_' . $ri;
            $last = (int) ($st[$lastKey] ?? 0);
            $sim = \Hoep\HomeSuite\Engines\LightAutomation::presenceSim($r, $away, $nowMin, $last, $now, count($devs));
            if ($sim['fire']) {
                $this->autoSetDevice($devs[$sim['index']], (bool) $sim['on'], -1, 0, 'Anwesenheit simulieren');
                $st[$lastKey] = $now;
            }
        }

        // --- L10: Bewegungs-"Aus" nach Haltezeit (das "An" macht MessageSink) ---
        foreach ($rules as $ri => $r) {
            if (($r['type'] ?? '') !== 'motion') {
                continue;
            }
            $holdKey = 'motHold_' . $ri;
            $hold = (int) ($st[$holdKey] ?? 0);
            $sensorOn = ((int) ($r['sensor'] ?? 0) > 0) ? (bool) @\GetValue((int) $r['sensor']) : false;
            $res = \Hoep\HomeSuite\Engines\LightAutomation::motion($r, $sensorOn, null, $hold, $now);
            if ($res['action'] === 'off') {
                foreach ((array) ($r['devices'] ?? []) as $iid) {
                    $this->autoSetDevice((int) $iid, false, -1, 0, 'Bewegung abgelaufen');
                }
            }
            $st[$holdKey] = $res['holdUntil'];
        }

        $st['prevMin'] = $nowMin;
        $this->store()->set('_lightAutoState', $st);
    }

    /**
     * Wecken (L9) — REIN AUDIO.
     *
     * Die Weckregel schaltet ausdruecklich KEIN Licht. Wer zum Wecken auch Licht
     * will, legt dafuer eine eigene 'schedule'-Regel auf dieselbe Zeit; das
     * Regelmodell ist genau dafuer gemacht, und beides in einer Regel zu buendeln
     * hiesse, zwei Zwecke in ein Feld zu zwingen.
     *
     * Quelle als {kind, id}: damit sind Playlist und Favorit genauso erreichbar
     * wie Radio. Frueher stand hier ein Platzhalter, der ausschliesslich
     * playDirect(station) kannte und rampMin schlicht verworfen hat.
     *
     * Regel: {type:'wake', time, days[], audioZone, audioSource:{kind,id}|string,
     *         volume?, rampMin?}
     */
    private function applyWake(array $rule): void
    {
        $az = (int) ($rule['audioZone'] ?? 0);
        if ($az <= 0 || !@\IPS_InstanceExists($az) || !function_exists('HSAU_Manage')) {
            $this->LogMessage('Wecken: Audiozone ' . $az . ' nicht ansprechbar - nichts gestartet.', KL_WARNING);
            return;
        }

        // Quelle: neues Format {kind,id}; eine blosse Zeichenkette bleibt als
        // Radiosender gueltig, damit bestehende Regeln weiterlaufen.
        $q = $rule['audioSource'] ?? '';
        if (is_array($q)) {
            $kind = (string) ($q['kind'] ?? 'station');
            $id   = (string) ($q['id'] ?? '');
        } else {
            $kind = 'station';
            $id   = (string) $q;
        }
        if ($id === '') {
            $this->LogMessage('Wecken: keine Quelle konfiguriert - nichts gestartet.', KL_WARNING);
            return;
        }

        $args = ['kind' => $kind, 'id' => $id];
        if (isset($rule['volume']))  { $args['volume']  = (int) $rule['volume']; }
        if (isset($rule['rampMin'])) { $args['rampMin'] = (int) $rule['rampMin']; }

        $r = json_decode((string) @\HSAU_Manage($az, json_encode(['op' => 'wake', 'args' => $args])), true);
        if (!is_array($r) || empty($r['ok'])) {
            $this->LogMessage('Wecken fehlgeschlagen (Zone ' . $az . ', ' . $kind . ' ' . $id . '): '
                . substr((string) json_encode($r), 0, 120), KL_WARNING);
            return;
        }

        // Selbsttaetiges Ausschalten: der Wecker soll nicht bis zum Abend spielen.
        // Dafuer gibt es den Sleep-Timer der Zone - kein zweiter Mechanismus, und er
        // laesst sich am Geraet wie in der Oberflaeche jederzeit abbrechen.
        // 0 oder fehlend = laeuft weiter, wie bisher.
        $aus = (int) ($rule['offAfterMin'] ?? 0);
        if ($aus > 0) {
            @\HSAU_Manage($az, json_encode(['op' => 'setSleep', 'args' => ['minutes' => $aus]]));
        }
    }

    /** Motion-"An" event-getrieben (MessageSink) + Circadian/Trigger unveraendert ueber Timer. */
    public function MessageSink($Timestamp, $Sender, $Message, $Data)
    {
        parent::MessageSink($Timestamp, $Sender, $Message, $Data);
        if ((int) $Message !== 10603) { // VM_UPDATE
            return;
        }
        // Zuerst zaehlen, DANN die Automatik. Sonst haengt der Zaehler daran, ob die
        // Lichtautomatik ueberhaupt eingeschaltet ist - er soll aber immer stimmen.
        $this->lichterZaehlen();
        $cfg = $this->lightAutoCfg();
        if (!$cfg['enabled'] || !$this->automationEnabled()) {
            return;
        }
        $st = $this->store()->get('_lightAutoState', []);
        $st = is_array($st) ? $st : [];
        $now = time();
        $changed = false;
        foreach ($cfg['rules'] as $ri => $r) {
            if (($r['type'] ?? '') !== 'motion' || (int) ($r['sensor'] ?? 0) !== (int) $Sender) {
                continue;
            }
            if (($r['enabled'] ?? true) === false) {
                continue;
            }
            $sensorOn = (bool) @\GetValue((int) $Sender);
            $lux = ((int) ($r['lux'] ?? 0) > 0) ? (float) @\GetValue((int) $r['lux']) : null;
            $holdKey = 'motHold_' . $ri;
            $res = \Hoep\HomeSuite\Engines\LightAutomation::motion($r, $sensorOn, $lux, (int) ($st[$holdKey] ?? 0), $now);
            if ($res['action'] === 'on') {
                $rn = trim((string) ($r['name'] ?? '')) !== '' ? (string) $r['name'] : 'Bewegung';
                foreach ((array) ($r['devices'] ?? []) as $iid) {
                    $this->autoSetDevice((int) $iid, true, (int) ($r['level'] ?? -1), 0, 'Regel "' . $rn . '"');
                }
                $st[$holdKey] = $res['holdUntil'];
                $changed = true;
            }
        }
        if ($changed) {
            $this->store()->set('_lightAutoState', $st);
        }
    }

    private function mgmtLightAuto(string $op, array $args, array $ctx): array
    {
        switch ($op) {
            case 'lightAutoGet':
                $co = $this->sunCoords();
                $si = @date_sun_info(time(), (float) ($co['lat'] ?? 48.2082), (float) ($co['lon'] ?? 16.3738));
                $sun = is_array($si)
                    ? ['sunrise' => (int) date('G', (int) $si['sunrise']) * 60 + (int) date('i', (int) $si['sunrise']),
                       'sunset'  => (int) date('G', (int) $si['sunset']) * 60 + (int) date('i', (int) $si['sunset'])]
                    : ['sunrise' => 360, 'sunset' => 1200];
                // Aus Baendern abgeleitete Regeln gehoeren NICHT in den Regel-Editor: dort waeren
                // sie nur verwirrend (Loeschen haette keinen Bestand, weil sie beim naechsten
                // Speichern neu entstehen). Sie werden im Baender-Widget bearbeitet.
                $cfg = $this->lightAutoCfg();
                $cfg['rules'] = array_values(array_filter($cfg['rules'], static fn($r) => ($r['src'] ?? '') !== 'band'));
                // ... sie gehoeren aber sehr wohl in die ANZEIGE: der Tagesverlauf muss alles
                // zeigen, was heute schaltet, sonst versteckt der eine Weg wieder den anderen.
                // Getrennter Schluessel, damit ein Speichern des Regel-Editors sie nicht mitnimmt.
                return ['ok' => true, 'sun' => $sun, 'bandRules' => $this->bandRules(),
                    'automationEnabled' => $this->automationEnabled(),
                    'automationVar'     => (int) (@\IPS_GetObjectIDByIdent('AutomationEnabled', $this->InstanceID) ?: 0),
                ] + $cfg;
            case 'lightAutoSet':
                $enabled = (bool) ($args['enabled'] ?? false);
                $rules = is_array($args['rules'] ?? null) ? array_values($args['rules']) : [];
                // Aus Baendern abgeleitete Regeln gehoeren dem Baender-Editor: sie stehen in
                // KEINEM Regel-Editor und duerfen von dessen Speichern nicht geloescht werden.
                $rules = array_values(array_filter($rules, static fn($r) => ($r['src'] ?? '') !== 'band'));
                $rules = array_merge($rules, $this->bandRules());
                $this->store()->set('lightAuto', ['enabled' => $enabled, 'rules' => $rules]);
                $this->lightAutoWire();
                return ['ok' => true, 'enabled' => $enabled, 'count' => count($rules)];
            case 'lightAutoTick':
                $this->lightAutoTick();
                return ['ok' => true, 'ticked' => true];
            case 'lightAutoBandGet':
                $co = $this->sunCoords();
                $si = @date_sun_info(time(), (float) ($co['lat'] ?? 48.2082), (float) ($co['lon'] ?? 16.3738));
                return ['ok' => true, 'bands' => $this->bandCfg(),
                    'enabled' => $this->lightAutoCfg()['enabled'],
                    'automationEnabled' => $this->automationEnabled(),
                    'scenes' => $this->scenes()->list(),
                    'sun' => is_array($si)
                        ? ['sunrise' => (int) date('G', (int) $si['sunrise']) * 60 + (int) date('i', (int) $si['sunrise']),
                           'sunset'  => (int) date('G', (int) $si['sunset']) * 60 + (int) date('i', (int) $si['sunset'])]
                        : ['sunrise' => 360, 'sunset' => 1200]];
            case 'lightAutoBandSet':
                return $this->bandSet($args);
            default:
                return ['ok' => false, 'op' => $op, 'error' => 'not_implemented'];
        }
    }

    // ==================================================================
    // Licht-Zeitsteuerung als BAENDER
    //
    // Bearbeitet wird ein Tag als Folge von Abschnitten ("ab 06:30 gilt Morgen"), gehandelt
    // wird weiterhin nur an den KANTEN: aus jedem Abschnittsbeginn entsteht eine gewoehnliche
    // Schaltpunkt-Regel (type=schedule, src=band). Damit bleibt die gepruefte Auswertung
    // unveraendert - und vor allem bleibt das Verhalten unaufdringlich: es gibt keinen
    // durchgesetzten Sollzustand, der eine von Hand eingeschaltete Lampe wieder ausmacht.
    // ==================================================================

    /** @return array{days:array<int,array>} Baender je Wochentag (0=So..6=Sa, wie date('w')). */
    private function bandCfg(): array
    {
        $b = $this->store()->get('lightBands', []);
        $days = is_array($b['days'] ?? null) ? $b['days'] : [];
        $out = [];
        for ($d = 0; $d <= 6; $d++) {
            $segs = is_array($days[$d] ?? null) ? $days[$d] : (is_array($days[(string) $d] ?? null) ? $days[(string) $d] : []);
            $out[$d] = array_values(array_filter(array_map([$this, 'bandSeg'], $segs)));
            usort($out[$d], fn($a, $b2) => $this->bandSegMin($a) <=> $this->bandSegMin($b2));
        }
        return ['days' => $out];
    }

    /** Einen Abschnitt normalisieren; ohne Szene ist er wertlos -> null. */
    private function bandSeg($s): ?array
    {
        if (!is_array($s)) {
            return null;
        }
        $scene = (string) ($s['sceneId'] ?? '');
        if ($scene === '') {
            return null;
        }
        $tr = is_array($s['trigger'] ?? null) ? $s['trigger'] : [];
        $kind = ($tr['kind'] ?? 'time') === 'sun' ? 'sun' : 'time';
        return ['sceneId' => $scene,
            'trigger' => $kind === 'sun'
                ? ['kind' => 'sun',
                   'event' => (($tr['event'] ?? 'sunset') === 'sunrise') ? 'sunrise' : 'sunset',
                   'offsetMin' => max(-240, min(240, (int) ($tr['offsetMin'] ?? 0)))]
                : ['kind' => 'time', 'time' => $this->bandTime((string) ($tr['time'] ?? '00:00'))]];
    }

    private function bandTime(string $t): string
    {
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', trim($t), $m)) {
            return '00:00';
        }
        return sprintf('%02d:%02d', max(0, min(23, (int) $m[1])), max(0, min(59, (int) $m[2])));
    }

    /** Sortierschluessel in Minuten; Sonnenabschnitte mit den heutigen Sonnenzeiten. */
    private function bandSegMin(array $s): int
    {
        $tr = $s['trigger'] ?? [];
        if (($tr['kind'] ?? 'time') === 'sun') {
            $co = $this->sunCoords();
            $si = @date_sun_info(time(), (float) ($co['lat'] ?? 48.2082), (float) ($co['lon'] ?? 16.3738));
            $base = ($tr['event'] ?? 'sunset') === 'sunrise'
                ? (is_array($si) ? (int) date('G', (int) $si['sunrise']) * 60 + (int) date('i', (int) $si['sunrise']) : 360)
                : (is_array($si) ? (int) date('G', (int) $si['sunset']) * 60 + (int) date('i', (int) $si['sunset']) : 1200);
            return $base + (int) ($tr['offsetMin'] ?? 0);
        }
        $p = explode(':', (string) ($tr['time'] ?? '00:00'));
        return (int) ($p[0] ?? 0) * 60 + (int) ($p[1] ?? 0);
    }

    /** Baender -> Schaltpunkt-Regeln (eine je Abschnittsbeginn und Wochentag). */
    private function bandRules(): array
    {
        $rules = [];
        foreach ($this->bandCfg()['days'] as $d => $segs) {
            foreach ($segs as $i => $s) {
                $rules[] = [
                    'id'      => 'band-' . $d . '-' . $i,
                    'src'     => 'band',
                    'type'    => 'schedule',
                    'name'    => 'Band ' . ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'][$d],
                    'enabled' => true,
                    'days'    => [(int) $d],
                    'trigger' => $s['trigger'],
                    'sceneId' => $s['sceneId'],
                ];
            }
        }
        return $rules;
    }

    /** Baender speichern und die abgeleiteten Regeln neu erzeugen. */
    private function bandSet(array $args): array
    {
        $days = is_array($args['days'] ?? null) ? $args['days'] : [];
        $rein = [];
        for ($d = 0; $d <= 6; $d++) {
            $segs = is_array($days[$d] ?? null) ? $days[$d] : (is_array($days[(string) $d] ?? null) ? $days[(string) $d] : []);
            $rein[$d] = array_values(array_filter(array_map([$this, 'bandSeg'], $segs)));
        }
        $this->store()->set('lightBands', ['days' => $rein]);

        $cfg   = $this->lightAutoCfg();
        $rules = array_values(array_filter($cfg['rules'], static fn($r) => ($r['src'] ?? '') !== 'band'));
        $rules = array_merge($rules, $this->bandRules());
        $enabled = array_key_exists('enabled', $args) ? (bool) $args['enabled'] : $cfg['enabled'];
        $this->store()->set('lightAuto', ['enabled' => $enabled, 'rules' => $rules]);
        $this->lightAutoWire();
        return ['ok' => true, 'enabled' => $enabled,
            'segments' => array_sum(array_map('count', $rein)), 'rules' => count($rules)];
    }

    /** Quellen-Konfig (Medien-Provider) aus dem Store, inkl. Defaults je bekanntem Provider. */
    private function sourcesConfig(): array
    {
        $s = $this->store()->get('sources', []);
        $s = is_array($s) ? $s : [];
        foreach (array_keys(\Hoep\HomeSuite\Engines\MediaProviders::schema()) as $id) {
            if (!isset($s[$id]) || !is_array($s[$id])) {
                $s[$id] = ['enabled' => false];
            }
        }
        return $s;
    }

    // ==================================================================
    // Radiosender: fest eingebaute + eigene, haus-weit
    // ==================================================================

    /** Alle Sender: eingebaute und eigene, mit Herkunft. */
    private function mgmtGetStations(): array
    {
        $alle = \Hoep\HomeSuite\Engines\RadioNow::all();
        $out  = [];
        foreach ($alle as $k => $st) {
            $out[] = [
                'key'    => $k,
                'title'  => (string) ($st['title'] ?? $k),
                'stream' => (string) ($st['stream'] ?? ''),
                'logo'   => (string) ($st['logo'] ?? ''),
                'match'  => implode(', ', (array) ($st['match'] ?? [])),
                'eigen'  => !empty($st['eigen']),
            ];
        }
        return ['ok' => true, 'stations' => $out, 'eigene' => $this->eigeneSender()];
    }

    /** @return array<int,array> die im Hub gepflegten eigenen Sender */
    private function eigeneSender(): array
    {
        $s = $this->store()->get('radioStations', []);
        return is_array($s) ? array_values($s) : [];
    }

    /**
     * Eigene Sender speichern. Ein Sender mit dem Schluessel eines eingebauten
     * UEBERSCHREIBT diesen - so laesst sich eine tote Adresse ersetzen, ohne Code zu aendern.
     * Geschrieben wird zusaetzlich eine Datei, aus der RadioNow liest: die Klasse wird aus
     * Zone, Hook und Wecker benutzt und soll den Hub nicht kennen muessen.
     */
    private function mgmtConfigureStations(array $args): array
    {
        $ein = (array) ($args['stations'] ?? []);
        $rein = [];
        foreach ($ein as $st) {
            $k = preg_replace('~[^a-z0-9_]~', '', mb_strtolower(trim((string) ($st['key'] ?? ''))));
            $u = trim((string) ($st['stream'] ?? ''));
            if ($k === '' || $u === '') {
                continue;   // ohne Schluessel oder Adresse ist ein Sender wertlos
            }
            // Erkennungsmuster kommaweise; sie ordnen den vom Player gemeldeten Sendernamen
            // diesem Sender zu. Leer lassen ist erlaubt - dann werden Name und Schluessel
            // benutzt. Beim laufenden Betrieb gewinnt die laengste Uebereinstimmung.
            $mus = [];
            foreach (preg_split('~\s*,\s*~', (string) ($st['match'] ?? '')) as $m) {
                $m = mb_strtolower(trim((string) $m));
                if ($m !== '') { $mus[] = $m; }
            }
            $rein[$k] = [
                'key'    => $k,
                'title'  => trim((string) ($st['title'] ?? $k)),
                'stream' => $u,
                'logo'   => trim((string) ($st['logo'] ?? '')),
                'match'  => $mus,
            ];
        }
        $liste = array_values($rein);
        $this->store()->set('radioStations', $liste);

        $ok = $this->senderDateiSchreiben();
        return ['ok' => $ok, 'gespeichert' => count($liste),
                'datei' => \Hoep\HomeSuite\Engines\RadioNow::CUSTOM_FILE];
    }

    /** Senderdatei aus dem Store schreiben (Ableitung, jederzeit neu erzeugbar). */
    private function senderDateiSchreiben(): bool
    {
        $datei  = \Hoep\HomeSuite\Engines\RadioNow::CUSTOM_FILE;
        $ordner = dirname($datei);
        if (!is_dir($ordner)) {
            @mkdir($ordner, 0775, true);
        }
        $ok = @file_put_contents($datei,
            json_encode(['stations' => $this->eigeneSender()], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) !== false;
        \Hoep\HomeSuite\Engines\RadioNow::reset();
        return $ok;
    }

    /**
     * Einen Stream antesten, BEVOR er als Sender abgelegt wird.
     *
     * Geprueft wird, was den Player wirklich betrifft: Erreichbarkeit, Inhaltstyp und ob
     * eine Weiterleitung von http nach https fuehrt. Sonos spielt Radio zwar auch ueber
     * https ab (anders als Dateien), aber eine Adresse, die eine Playlist statt eines
     * Stroms liefert, oder eine, die gar nicht antwortet, faellt hier auf statt spaeter
     * still im Wohnzimmer.
     */
    private function mgmtTestStation(array $args): array
    {
        $url = trim((string) ($args['url'] ?? ''));
        if ($url === '' || !preg_match('~^https?://~i', $url)) {
            return ['ok' => false, 'error' => 'keine gueltige Adresse'];
        }
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'error' => 'curl fehlt'];
        }
        // Ein Livestream endet NIE. Ein einfacher Abruf mit Zeitlimit meldet darum immer
        // einen Fehler, obwohl alles in Ordnung ist (erster Versuch: 206 KB Audio empfangen
        // und trotzdem "nicht erreichbar"). Deshalb wird hier mitgezaehlt und nach ein paar
        // Kilobyte selbst abgebrochen - genau das ist der Beweis, dass Ton fliesst.
        $genug = 24576;
        $gelesen = 0;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => ['Icy-MetaData: 1', 'User-Agent: HomeSuite/1.0'],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_WRITEFUNCTION  => function ($c, $daten) use (&$gelesen, $genug) {
                $gelesen += strlen($daten);
                return ($gelesen >= $genug) ? 0 : strlen($daten);   // 0 bricht sauber ab
            },
        ]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $typ  = strtolower(trim((string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE)));
        $ziel = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $errn = curl_errno($ch);
        $err  = curl_error($ch);
        curl_close($ch);

        // Abbruch durch die eigene Zaehlfunktion ist KEIN Fehler, sondern das Ziel.
        $abgebrochen = ($errn === CURLE_WRITE_ERROR || $gelesen >= $genug);
        if ($code < 200 || $code >= 400 || ($gelesen === 0 && !$abgebrochen)) {
            return ['ok' => false, 'error' => 'nicht erreichbar', 'code' => $code,
                    'detail' => $err !== '' ? $err : 'keine Daten'];
        }

        $hinweise = [];
        $spielbar = true;
        $istAudio = (strpos($typ, 'audio') !== false || strpos($typ, 'octet-stream') !== false
            || strpos($typ, 'ogg') !== false || strpos($typ, 'aacp') !== false);
        if (!$istAudio) {
            $spielbar = false;
            if (preg_match('~mpegurl|scpls|x-scpls|pls|xspf~', $typ)) {
                $hinweise[] = 'Das ist eine Playlist-Datei (' . $typ . '), kein Strom. '
                    . 'Bitte die darin enthaltene Stream-Adresse eintragen.';
            } else {
                $hinweise[] = 'Kein Audio: der Server meldet ' . ($typ !== '' ? $typ : 'keinen Inhaltstyp') . '.';
            }
        }
        if (stripos($ziel, 'https://') === 0 && stripos($url, 'http://') === 0) {
            $hinweise[] = 'Die Adresse leitet auf https um.';
        }
        if ($spielbar && $gelesen < 4096) {
            $hinweise[] = 'Es kamen nur ' . $gelesen . ' Bytes - der Strom koennte sofort abreissen.';
        }
        return ['ok' => true, 'spielbar' => $spielbar, 'code' => $code,
                'typ' => $typ !== '' ? $typ : '-', 'zieladresse' => $ziel,
                'bytes' => $gelesen, 'hinweise' => $hinweise];
    }

    /** Medienquellen speichern (haus-weit). Nur bekannte Provider/Felder werden uebernommen. */
    private function mgmtConfigureSources(array $args): array
    {
        $schema = \Hoep\HomeSuite\Engines\MediaProviders::schema();
        $cur = $this->sourcesConfig();
        foreach ($schema as $id => $def) {
            if (!isset($args[$id]) || !is_array($args[$id])) {
                continue;
            }
            $in = $args[$id];
            $entry = ['enabled' => (bool) ($in['enabled'] ?? ($cur[$id]['enabled'] ?? false))];
            foreach ((array) $def['fields'] as $f) {
                $entry[$f] = (string) ($in[$f] ?? ($cur[$id][$f] ?? ''));
            }
            $cur[$id] = $entry;
        }
        $this->store()->set('sources', $cur);
        // Aktive Provider (Diagnose): welche sind jetzt konfiguriert?
        $active = array_keys(\Hoep\HomeSuite\Engines\MediaProviders::build($cur));
        return ['ok' => true, 'sources' => $cur, 'active' => $active];
    }

    /** Externe HTTPS-Basis ueber Symcon Connect (fuer OAuth-Redirect). '' wenn nicht verfuegbar. */
    private function connectUrl(): string
    {
        if (!function_exists('CC_GetUrl')) {
            return '';
        }
        // Connect-Instanz robust ueber die Modul-GUID finden (ModuleName variiert:
        // "Connect Control" statt "Symcon Connect"). Erste mit gueltiger URL nehmen.
        $CONNECT = '{9486D575-BE8C-4ED8-B5B5-20930E26DE6F}';
        foreach (@\IPS_GetInstanceListByModuleID($CONNECT) ?: [] as $iid) {
            try {
                $u = rtrim((string) @CC_GetUrl($iid), '/');
            } catch (\Throwable $e) {
                $u = '';
            }
            if ($u !== '') {
                return $u;
            }
        }
        return '';
    }

    /** Redirect-URI fuer Spotify (Connect-URL + fester Hook). */
    private function spotifyRedirectUri(): string
    {
        $base = $this->connectUrl();
        return $base === '' ? '' : ($base . '/hook/hsspotify');
    }

    /** Spotify Authorize-URL erzeugen (fuer den Connect-Button). */
    private function mgmtSpotifyAuthUrl(): array
    {
        $sp = (array) ($this->sourcesConfig()['spotify'] ?? []);
        $cid = (string) ($sp['clientId'] ?? '');
        $redirect = $this->spotifyRedirectUri();
        if ($cid === '') {
            return ['ok' => false, 'error' => 'clientId fehlt (im Formular eintragen + speichern)'];
        }
        if ($redirect === '') {
            return ['ok' => false, 'error' => 'Connect-URL nicht verfuegbar'];
        }
        $url = 'https://accounts.spotify.com/authorize?' . http_build_query([
            'client_id'     => $cid,
            'response_type' => 'code',
            'redirect_uri'  => $redirect,
            'scope'         => 'playlist-read-private playlist-read-collaborative user-library-read',
            'state'         => 'homesuite',
        ]);
        return ['ok' => true, 'authUrl' => $url, 'redirectUri' => $redirect];
    }

    /** Spotify-OAuth-Callback: code -> refresh_token, im Store ablegen. Liefert HTML. */
    private function handleSpotifyCallback(): void
    {
        $code = (string) ($_GET['code'] ?? '');
        $err  = (string) ($_GET['error'] ?? '');
        // Aufruf OHNE code/error = Login-START -> direkt zu Spotify weiterleiten
        // (Ein-Klick: /hook/hsspotify oeffnen genuegt).
        if ($code === '' && $err === '') {
            $a = $this->mgmtSpotifyAuthUrl();
            if (!empty($a['authUrl'])) {
                header('Location: ' . $a['authUrl'], true, 302);
                return;
            }
            header('Content-Type: text/html; charset=utf-8');
            echo $this->spotifyHtml('Login nicht moeglich: ' . htmlspecialchars((string) ($a['error'] ?? 'Client-ID/Connect fehlt')));
            return;
        }
        header('Content-Type: text/html; charset=utf-8');
        $sp   = (array) ($this->sourcesConfig()['spotify'] ?? []);
        if ($err !== '') {
            echo $this->spotifyHtml('Spotify meldet einen Fehler: ' . htmlspecialchars($err));
            return;
        }
        if ($code === '' || ($sp['clientId'] ?? '') === '' || ($sp['clientSecret'] ?? '') === '') {
            echo $this->spotifyHtml('Kein Code oder Client-ID/Secret fehlt.');
            return;
        }
        $redirect = $this->spotifyRedirectUri();
        $body = http_build_query(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $redirect]);
        $auth = 'Basic ' . base64_encode(((string) $sp['clientId']) . ':' . ((string) $sp['clientSecret']));
        $resp = '';
        if (function_exists('curl_init')) {
            $ch = curl_init('https://accounts.spotify.com/api/token');
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'Authorization: ' . $auth]]);
            $resp = (string) curl_exec($ch);
            curl_close($ch);
        }
        $j = json_decode($resp, true);
        $refresh = (string) ($j['refresh_token'] ?? '');
        if ($refresh === '') {
            echo $this->spotifyHtml('Token-Tausch fehlgeschlagen. ' . htmlspecialchars((string) ($j['error_description'] ?? '')));
            return;
        }
        $cur = $this->sourcesConfig();
        $cur['spotify']['refreshToken'] = $refresh;
        $cur['spotify']['enabled'] = true;
        $this->store()->set('sources', $cur);
        echo $this->spotifyHtml('Spotify erfolgreich verbunden! Du kannst dieses Fenster schliessen.');
    }

    /** Provider browsen/suchen -> ContentRef-Liste (Array). */
    /**
     * Aggregiert den Entscheidungs-/Befehls-Log ALLER ShadingDevice-Instanzen (alle Raeume),
     * chronologisch (neueste zuerst), gedeckelt. Jede Instanz haelt einen eigenen Ringpuffer;
     * hier wird beim Lesen zusammengefuehrt (kein zentraler Schreib-Kopplungspunkt).
     */
    /**
     * Beschattungs-Log leeren - alle Zonen oder eine einzelne (args.entityId).
     * Das Log dient nur der Nachvollziehbarkeit; Leeren aendert nichts am Betrieb.
     */
    private function mgmtShadeLogClear(array $args): array
    {
        // WICHTIG: "eine bestimmte Zone" wird am VORHANDENSEIN des Schluessels erkannt, nicht
        // an seinem Wert. Frueher galt `if ($only > 0 && ...)` - ein unsinniger Wert wie -1 fiel
        // damit durch die Bedingung und leerte ALLE Zonen. Genau so ist mir bei einer Probe der
        // komplette Verlauf verloren gegangen.
        $hasOnly = array_key_exists('entityId', $args);
        $only    = (int) ($args['entityId'] ?? 0);
        $ids     = @\IPS_GetInstanceListByModuleID('{A9645ED8-CB55-43B8-869B-BFF6ACFC8DC1}') ?: [];
        if ($hasOnly && ($only <= 0 || !in_array($only, array_map('intval', $ids), true))) {
            return ['ok' => false, 'error' => 'unbekannte entityId', 'zones' => 0, 'cleared' => 0];
        }
        $n = 0; $zonen = 0;
        foreach ($ids as $id) {
            if ($hasOnly && (int) $id !== $only) { continue; }
            if (!function_exists('HSSH_Manage')) { break; }
            $r = @json_decode((string) @\HSSH_Manage((int) $id, json_encode(['op' => 'clearLog'])), true);
            if (is_array($r) && !empty($r['ok'])) { $zonen++; $n += (int) ($r['cleared'] ?? 0); }
        }
        return ['ok' => true, 'zones' => $zonen, 'cleared' => $n];
    }

    /**
     * Entscheidungen ALLER Domaenen einsammeln, neueste zuerst.
     *
     * Seit 21.09.2026 fuehrt jede Entitaet einen Ringpuffer in der Variablen
     * 'Entscheidungen' (EntityModule::entscheidungMerken). Einzeln im Objektbaum
     * nachzusehen hilft niemandem - die Frage lautet ja "was hat das Haus heute
     * entschieden", nicht "was hat Kreis 3 entschieden".
     *
     * Gesucht wird ueber die KINDER der Instanzen, nicht ueber
     * IPS_GetObjectIDByIdent: das schreibt auch hinter @ eine Warnung ins Meldungslog,
     * und bei ein paar hundert Instanzen waere das eine Warnung je Aufruf.
     */
    /** Modulname -> Domaenenname fuer den Filter im Log. */
    private const DOMAENEN = [
        'HeatingZone' => 'Heizung', 'IrrigationCircuit' => 'Bewässerung', 'ClimateZone' => 'Klima',
        'ShadingDevice' => 'Beschattung', 'LightDevice' => 'Licht', 'Hub' => 'Licht-Automatik',
        'PoolController' => 'Pool', 'MowerDevice' => 'Mäher', 'AudioZone' => 'Audio',
        'SecurityCenter' => 'Wächter', 'GardenaDevice' => 'Gardena', 'DeviceHealth' => 'Geräte',
    ];

    private function mgmtDecisionLog(array $args): array
    {
        $limit = max(1, min(1000, (int) ($args['limit'] ?? 300)));
        $out   = [];
        foreach (@\IPS_GetInstanceList() ?: [] as $iid) {
            $vid = 0;
            foreach (@\IPS_GetChildrenIDs($iid) ?: [] as $c) {
                $o = @\IPS_GetObject($c);
                if (($o['ObjectType'] ?? -1) === 2 && ($o['ObjectIdent'] ?? '') === 'Entscheidungen') {
                    $vid = (int) $c;
                    break;
                }
            }
            if ($vid <= 0) { continue; }
            $log = json_decode((string) @\GetValue($vid), true);
            if (!is_array($log)) { continue; }
            // Der Anzeigename soll den Raum nennen, nicht die Domaene: 28 Instanzen
            // heissen "Heizung (Heizung)", das unterscheidet nichts.
            $name = (string) @\IPS_GetName($iid);
            $eltern = (int) @\IPS_GetParent($iid);
            $raum = $eltern > 0 ? (string) @\IPS_GetName($eltern) : '';
            // Domaene aus dem Modulnamen: der Raum allein reicht zum Filtern nicht, denn in
            // einem Raum entscheiden Heizung, Licht und Beschattung nebeneinander.
            $mod = (string) (@\IPS_GetInstance($iid)['ModuleInfo']['ModuleName'] ?? '');
            $domaene = self::DOMAENEN[$mod] ?? ($mod !== '' ? $mod : 'Sonstiges');
            foreach ($log as $e) {
                if (!is_array($e)) { continue; }
                $out[] = [
                    't'      => (int) ($e['t'] ?? 0),
                    'iid'    => (int) $iid,
                    'geraet' => $name,
                    'raum'   => $raum,
                    'domaene' => $domaene,
                    'was'    => (string) ($e['was'] ?? ''),
                    'warum'  => (string) ($e['warum'] ?? ''),
                    'real'   => (int) ($e['real'] ?? 1),
                    'werte'  => is_array($e['werte'] ?? null) ? $e['werte'] : null,
                ];
            }
        }
        // Beschattung fuehrt ihr Protokoll seit jeher SELBST (Attribut DecisionLog, gelesen
        // ueber getLog je Zone) und nicht in der Variablen 'Entscheidungen'. Die Schleife
        // oben sieht davon nichts, und ausgerechnet die Domaene mit den meisten
        // Entscheidungen fehlte im Gesamtlog. Statt 17 Zonen umzubauen - das Format ist
        // erprobt und der shadelog-Modus liest es unveraendert - hier zusammenfuehren.
        foreach (($this->mgmtShadeLog(['limit' => $limit])['entries'] ?? []) as $e) {
            if (!is_array($e)) { continue; }
            $von = $e['from'] ?? null;
            $bis = $e['to'] ?? null;
            $was = ($bis === null || (int) $bis < 0)
                ? 'gestoppt'
                : ('auf ' . (int) $bis . ' %' . (($von !== null && (int) $von >= 0) ? ' (von ' . (int) $von . ' %)' : ''));
            $out[] = [
                't'       => (int) ($e['t'] ?? 0),
                'iid'     => (int) ($e['id'] ?? 0),
                'geraet'  => (string) ($e['room'] ?? ''),
                'raum'    => (string) ($e['room'] ?? ''),
                'domaene' => 'Beschattung',
                'was'     => $was,
                'warum'   => (string) ($e['why'] ?? '')
                             . (((string) ($e['src'] ?? '')) === 'manuell' ? ' (manuell)' : ''),
                'real'    => (int) (!empty($e['armed'])),
                'werte'   => null,
            ];
        }
        usort($out, static fn($a, $b) => $b['t'] <=> $a['t']);
        return ['ok' => true, 'count' => count($out), 'rows' => array_slice($out, 0, $limit)];
    }

    private function mgmtShadeLog(array $args): array
    {
        $limit = max(1, min(1000, (int) ($args['limit'] ?? 300)));
        $ids   = @\IPS_GetInstanceListByModuleID('{A9645ED8-CB55-43B8-869B-BFF6ACFC8DC1}') ?: [];
        $all   = [];
        foreach ($ids as $id) {
            if (!function_exists('HSSH_Manage')) { break; }
            $r = @json_decode((string) @\HSSH_Manage((int) $id, json_encode(['op' => 'getLog'])), true);
            if (!is_array($r) || empty($r['ok'])) { continue; }
            $room = (string) ($r['room'] ?? \IPS_GetName((int) $id));
            foreach ((array) ($r['entries'] ?? []) as $e) {
                if (!is_array($e)) { continue; }
                $e['room'] = $room;
                $e['id']   = (int) $id;
                $all[]     = $e;
            }
        }
        usort($all, static fn($a, $b) => ((int) ($b['t'] ?? 0)) <=> ((int) ($a['t'] ?? 0)));
        if (count($all) > $limit) { $all = array_slice($all, 0, $limit); }
        return ['ok' => true, 'count' => count($all), 'entries' => $all];
    }

    private function mgmtMediaBrowse(array $args, bool $search = false): array
    {
        $pid = (string) ($args['provider'] ?? '');
        $providers = \Hoep\HomeSuite\Engines\MediaProviders::build($this->sourcesConfig());
        $p = $providers[$pid] ?? null;
        if ($p === null) {
            return ['ok' => false, 'error' => 'provider nicht aktiv: ' . $pid];
        }
        if ($search) {
            $items = $p->search((string) ($args['query'] ?? ''), (int) ($args['limit'] ?? 50));
        } else {
            $container = (string) ($args['container'] ?? '');
            $items = ($container === '') ? $p->roots()
                : $p->browse($container, (int) ($args['offset'] ?? 0), (int) ($args['limit'] ?? 100));
        }
        return ['ok' => true, 'provider' => $pid,
            'items' => array_map(fn($r) => $r->toArray(), $items)];
    }

    /** Einen ContentRef abspielbereit machen (uri fuellen). */
    /**
     * Einen Container in seine geordnete Titelliste aufloesen.
     *
     * Gegenstueck zu mediaResolve, das per Vertrag nur EINEN Verweis liefern kann. Der
     * Deckel begrenzt, was eine einzelne Antwort ueberhaupt tragen darf - der Symcon-Hook
     * kappt bei 1 MB, und eine Playlist mit tausenden Titeln liegt darueber.
     */
    private function mgmtMediaTracks(array $args): array
    {
        $pid = (string) ($args['provider'] ?? '');
        $providers = \Hoep\HomeSuite\Engines\MediaProviders::build($this->sourcesConfig());
        $p = $providers[$pid] ?? null;
        if ($p === null) {
            return ['ok' => false, 'error' => 'provider nicht aktiv: ' . $pid];
        }
        $ref = \Hoep\HomeSuite\HAL\ContentRef::fromArray((array) ($args['ref'] ?? []));
        $max = (int) ($args['max'] ?? 200);
        $r   = \Hoep\HomeSuite\Engines\MediaProviders::expand($p, $ref, $max);
        return [
            'ok'        => true,
            'tracks'    => array_map(static fn($t) => $t->toArray(), $r['tracks']),
            'total'     => $r['total'],
            'truncated' => $r['truncated'],
            'skipped'   => $r['skipped'],
        ];
    }

    /** Welche aktiven Quellen koennen Playlists anlegen? (Die Oberflaeche blendet danach aus.) */
    private function mgmtMediaCanWrite(): array
    {
        $out = [];
        foreach (\Hoep\HomeSuite\Engines\MediaProviders::build($this->sourcesConfig()) as $id => $p) {
            $out[] = ['provider' => $id, 'label' => $p->label(),
                      'schreibbar' => ($p instanceof \Hoep\HomeSuite\Contracts\IMediaWritable)];
        }
        return ['ok' => true, 'quellen' => $out];
    }

    /** Die beschreibbaren Playlists einer Quelle (ohne die regelbasierten). */
    private function mgmtMediaPlaylistList(array $args): array
    {
        $pid = (string) ($args['provider'] ?? '');
        $p = \Hoep\HomeSuite\Engines\MediaProviders::build($this->sourcesConfig())[$pid] ?? null;
        if (!($p instanceof \Hoep\HomeSuite\Contracts\IMediaWritable)) {
            return ['ok' => true, 'playlists' => []];
        }
        return ['ok' => true, 'playlists' => array_map(static fn($r) => $r->toArray(), $p->playlists())];
    }

    /**
     * Playlist anlegen, erweitern oder loeschen - IM ANBIETER.
     *
     * Es entsteht nichts Eigenes bei uns: die Liste kommt danach ueber den normalen
     * browse()-Weg zurueck. Wer nicht schreiben kann, bekommt eine klare Absage statt
     * eines Fehlschlags im Verborgenen.
     */
    private function mgmtMediaPlaylist(string $was, array $args): array
    {
        $pid = (string) ($args['provider'] ?? '');
        $p = \Hoep\HomeSuite\Engines\MediaProviders::build($this->sourcesConfig())[$pid] ?? null;
        if ($p === null) {
            return ['ok' => false, 'error' => 'provider nicht aktiv: ' . $pid];
        }
        if (!($p instanceof \Hoep\HomeSuite\Contracts\IMediaWritable)) {
            return ['ok' => false, 'error' => 'unsupported',
                    'note' => $p->label() . ' kann keine Playlists anlegen'];
        }
        $refs = [];
        foreach ((array) ($args['refs'] ?? []) as $r) {
            $refs[] = \Hoep\HomeSuite\HAL\ContentRef::fromArray((array) $r);
        }
        if ($was === 'delete') {
            return ['ok' => $p->deletePlaylist((string) ($args['id'] ?? ''))];
        }
        if ($was === 'add') {
            $n = $p->addToPlaylist((string) ($args['id'] ?? ''), $refs);
            return ['ok' => $n > 0, 'uebernommen' => $n, 'gewaehlt' => count($refs)];
        }
        $neu = $p->createPlaylist((string) ($args['name'] ?? ''), $refs);
        return $neu === null
            ? ['ok' => false, 'error' => 'anlegen fehlgeschlagen',
               'note' => 'Kein passender Eintrag dieser Quelle oder der Server hat abgelehnt']
            : ['ok' => true, 'playlist' => $neu->toArray(), 'gewaehlt' => count($refs)];
    }

    private function mgmtMediaResolve(array $args): array
    {
        $pid = (string) ($args['provider'] ?? '');
        $providers = \Hoep\HomeSuite\Engines\MediaProviders::build($this->sourcesConfig());
        $p = $providers[$pid] ?? null;
        if ($p === null) {
            return ['ok' => false, 'error' => 'provider nicht aktiv: ' . $pid];
        }
        $ref = \Hoep\HomeSuite\HAL\ContentRef::fromArray((array) ($args['ref'] ?? []));
        return ['ok' => true, 'ref' => $p->resolve($ref)->toArray()];
    }

    private function spotifyHtml(string $msg): string
    {
        return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>HomeSuite · Spotify</title></head><body style="font-family:system-ui;background:#141c1f;color:#e7eef0;'
            . 'display:flex;align-items:center;justify-content:center;height:100vh;margin:0;text-align:center">'
            . '<div><div style="font-size:34px;margin-bottom:12px">&#127925;</div><div style="font-size:16px;max-width:420px;padding:0 20px">'
            . htmlspecialchars($msg, ENT_QUOTES) . '</div></div></body></html>';
    }

    /**
     * Aggregat-Bindungspruefung ueber ALLE Entitaeten (Dead-Binding-Scan): ruft je
     * validate-faehiger Entitaet deren op=validate und sammelt die Problemfaelle.
     * Direkte Antwort auf „jemand loescht einen Aktor/IPSShadowing" -> zentral sichtbar.
     */
    private function mgmtValidateAll(): array
    {
        $problems = [];
        $total    = 0;
        $checked  = 0;
        $healthy  = 0;
        foreach ($this->discoverEntities() as $e) {
            $total++;
            $prefix = (string) ($e['prefix'] ?? '');
            $iid    = (int) ($e['instanceID'] ?? 0);
            if ($prefix === '' || $iid <= 0) {
                continue;
            }
            $fn = $prefix . '_Manage';
            if (!function_exists($fn)) {
                continue;
            }
            try {
                $res = json_decode((string) $fn($iid, json_encode(['op' => 'validate'])), true);
            } catch (\Throwable $ex) {
                $res = null;
            }
            // Nur validate-faehige Entitaeten zaehlen (liefern health/issues).
            if (!is_array($res) || (!isset($res['health']) && !isset($res['issues']))) {
                continue;
            }
            $checked++;
            if (!empty($res['ok'])) {
                $healthy++;
            } else {
                $problems[] = [
                    'id'     => $iid,
                    'name'   => (string) ($e['name'] ?? ''),
                    'domain' => (string) ($e['domain'] ?? ''),
                    'health' => (string) ($res['health'] ?? '?'),
                    'issues' => $res['issues'] ?? [],
                ];
            }
        }
        return ['ok' => empty($problems), 'total' => $total, 'checked' => $checked,
            'healthy' => $healthy, 'problems' => $problems];
    }

    // --- Geteilte Beschattungs-Profile (ProfileEngine auf dem Hub-Store) -----

    private function profileEngine(): \Hoep\HomeSuite\ProfileEngine
    {
        return new \Hoep\HomeSuite\ProfileEngine($this->store(), \Hoep\HomeSuite\ShadingProfiles::types());
    }

    private function mgmtProfile(string $op, array $args): array
    {
        $pe   = $this->profileEngine();
        $type = (string) ($args['type'] ?? '');
        $name = (string) ($args['name'] ?? '');
        $flds = (isset($args['fields']) && is_array($args['fields'])) ? $args['fields'] : [];
        switch ($op) {
            case 'profileTypes':    return ['ok' => true, 'types' => \Hoep\HomeSuite\ShadingProfiles::types()];
            case 'profileList':     return ['ok' => true, 'type' => $type, 'profiles' => $pe->list($type)];
            case 'profileGet':      return ['ok' => true, 'type' => $type, 'name' => $name, 'fields' => $pe->get($type, $name)];
            case 'profileCreate':   $pe->create($type, $name, $flds); return ['ok' => true, 'type' => $type, 'name' => $name];
            case 'profileSetFields':$pe->setFields($type, $name, $flds); $this->pushProfile($type, $name); return ['ok' => true, 'type' => $type, 'name' => $name];
            case 'profileRename':   $pe->rename($type, $name, (string) ($args['newName'] ?? '')); return ['ok' => true];
            case 'profileDuplicate':$pe->duplicate($type, $name, (string) ($args['newName'] ?? '')); return ['ok' => true];
            case 'profileDelete':   $pe->delete($type, $name); return ['ok' => true];
            case 'profileAssign':
                $eid = (int) ($args['entityId'] ?? 0);
                if ($eid <= 0) { throw new \Hoep\HomeSuite\ContractException('entityId fehlt'); }
                if ($name === '') {
                    $this->store()->set('assign.' . $eid . '.' . $type, '');
                    // Abwahl MUSS als ausdrueckliche Loeschung bei der Zone ankommen, sonst
                    // blieben die alten Werte stehen und das Rollo folgte weiter einem Profil,
                    // das gar nicht mehr zugewiesen ist. Nur HIER loeschen, nicht in pushZone.
                    $clr = \Hoep\HomeSuite\ShadingProfiles::clearConfig($type);
                    if ($clr !== [] && function_exists('HSSH_Manage')) {
                        @\HSSH_Manage($eid, json_encode(['op' => 'configureAutomation', 'args' => $clr]));
                    }
                } else { $pe->assign($eid, $type, $name); }
                $this->pushZone($eid);
                return ['ok' => true, 'entityId' => $eid, 'type' => $type, 'name' => $name];
            case 'profileAssigned':
                $eid = (int) ($args['entityId'] ?? 0); $a = [];
                foreach (\Hoep\HomeSuite\ShadingProfiles::typeIds() as $t) { $a[$t] = $pe->assignedName($eid, $t); }
                return ['ok' => true, 'entityId' => $eid, 'assigned' => $a];
        }
        return ['ok' => false, 'op' => $op, 'error' => 'not_implemented'];
    }

    /** Merged Config aller zugewiesenen Profile einer Zone -> configureAutomation der Zone. */
    private function pushZone(int $eid): void
    {
        if ($eid <= 0 || !@\IPS_InstanceExists($eid) || !function_exists('HSSH_Manage')) { return; }
        $pe = $this->profileEngine(); $cfg = [];
        foreach (\Hoep\HomeSuite\ShadingProfiles::typeIds() as $t) {
            $nm = $pe->assignedName($eid, $t);
            // NICHT zugewiesen = "dieser Push sagt nichts dazu" - und NICHT "loeschen".
            // Die Zone kann denselben Wert auf anderem Weg haben: die Temperatur-Schwellen
            // etwa stammen bei den meisten Rollos aus der IPSShadowing-Migration und haengen
            // ohne Profil direkt an der Zone. Wer hier pauschal loescht, raeumt sie bei jedem
            // beliebigen Profil-Push mit ab. Geloescht wird ausschliesslich im Moment der
            // ausdruecklichen Abwahl - siehe case 'profileAssign'.
            if ($nm === null || $nm === '') { continue; }
            try { $f = $pe->get($t, $nm); } catch (\Throwable $e) { continue; }
            $cfg = array_merge($cfg, \Hoep\HomeSuite\ShadingProfiles::mapToConfig($t, $f));
        }
        if ($cfg !== []) { @\HSSH_Manage($eid, json_encode(['op' => 'configureAutomation', 'args' => $cfg])); }
    }

    /** Push ein Profil an ALLE Zonen, denen es zugewiesen ist. */
    private function pushProfile(string $type, string $name): void
    {
        $pe = $this->profileEngine();
        foreach ($this->discoverEntities() as $e) {
            if (($e['domain'] ?? '') !== 'shading') { continue; }
            $eid = (int) ($e['instanceID'] ?? 0);
            if ($eid > 0 && $pe->assignedName($eid, $type) === $name) { $this->pushZone($eid); }
        }
    }

    // ==================================================================
    // Oeffentliche RPC (-> HSH_*)
    // ==================================================================

    /**
     * HSH_ListEntities — GUID-Discovery aller HomeSuite-Domaenen-Instanzen.
     *
     * @return string JSON: {"ok":true,"entities":[{instanceID,guid,prefix,domain,name,moduleType},...]}
     */
    public function ListEntities(): string
    {
        $out = ['ok' => true, 'entities' => $this->discoverEntities()];
        return $this->json($out);
    }

    /**
     * HSH_GetTopology — liest die Raumstruktur aus dem OBJEKTBAUM: alle
     * "HomeSuite Bereich"-Instanzen (Haus/Bereich/Raum) plus die Entitaeten, die
     * (irgendwo) unter einem Raum haengen. Liefert den verschachtelten Baum
     * Haus -> Bereich -> Raum -> [Entitaeten je Domaene] fuer Navigation/Widgets.
     * Nicht zugeordnete Entitaeten landen in "unassigned".
     */
    public function GetTopology(): string
    {
        $spaces = [];
        if (function_exists('IPS_GetInstanceListByModuleID')) {
            foreach ((array) @\IPS_GetInstanceListByModuleID(self::GUID_HSSP) as $iid) {
                $iid = (int) $iid;
                $spaces[$iid] = [
                    'iid'      => $iid,
                    'name'     => $this->nameOf($iid),
                    'kind'     => (string) (@\IPS_GetProperty($iid, 'Kind') ?: 'Raum'),
                    'abbr'     => (string) (@\IPS_GetProperty($iid, 'Abbr') ?: ''),
                    'parent'   => (int) @\IPS_GetParent($iid),
                    // Die POSITION im Objektbaum ist die Reihenfolge. Sie gibt es an jedem
                    // IPS-Objekt, sie ist je Elternteil vergeben - also "1..x je Geschoss" -
                    // und sie laesst sich in der Konsole durch Ziehen aendern. Ein eigenes
                    // Indexfeld waere ein zweiter Ort fuer dieselbe Aussage; sobald Baum und
                    // Feld auseinanderlaufen, weiss niemand mehr, welches gilt.
                    'pos'      => (int) (@\IPS_GetObject($iid)['ObjectPosition'] ?? 0),
                    'children' => [],
                    'entities' => [],
                ];
            }
        }

        // Entitaeten dem naechsten Raum-Vorfahren im Baum zuordnen.
        $unassigned = [];
        foreach ($this->discoverEntities() as $e) {
            $room = $this->nearestRoom((int) $e['instanceID'], $spaces);
            $ent  = ['iid' => $e['instanceID'], 'kind' => 'entity', 'domain' => $e['domain'],
                     'name' => $e['name'], 'prefix' => $e['prefix']];
            if ($room !== null) {
                $spaces[$room]['entities'][] = $ent;
            } else {
                $unassigned[] = $ent;
            }
        }

        // Verlinkte ROHGERAETE (Verknuepfungen unter einem Raum): zerstoerungsfrei
        // (das Original bleibt in seinem Vendor-Baum) mit AUTOMATISCH bestimmter
        // Domaene/Gewerk aus den Variablen/Profilen/Modul des Ziels.
        if (function_exists('IPS_GetLinkList')) {
            foreach ((array) @\IPS_GetLinkList() as $lid) {
                $lid  = (int) $lid;
                $room = $this->nearestRoom($lid, $spaces);
                if ($room === null) {
                    continue;
                }
                $target = (int) (@\IPS_GetLink($lid)['TargetID'] ?? 0);
                if ($target <= 0) {
                    continue;
                }
                $spaces[$room]['entities'][] = [
                    'iid'    => $target,
                    'linkId' => $lid,
                    'kind'   => 'link',
                    'domain' => $this->classifyDomain($target),
                    'name'   => $this->nameOf($lid),   // im Raum vergebener Link-Name
                ];
            }
        }

        // Kinder verlinken; Wurzeln = Spaces, deren Elternteil kein Space ist.
        foreach ($spaces as $iid => $s) {
            if (isset($spaces[$s['parent']])) {
                $spaces[$s['parent']]['children'][] = $iid;
            }
        }
        $roots = [];
        foreach ($spaces as $iid => $s) {
            if (!isset($spaces[$s['parent']])) {
                $roots[] = $iid;
            }
        }

        // Nach Position sortieren, bei Gleichstand nach Namen. Ohne das kommt die
        // Reihenfolge aus IPS_GetInstanceListByModuleID - also aus der Reihenfolge der
        // ENTSTEHUNG. Genau daran lag es, dass das Obergeschoss mit dem Schlafzimmer
        // begann und die Bereiche als OG, Garten, EG, DG kamen.
        $nachPosition = function (int $a, int $b) use ($spaces): int {
            $pa = $spaces[$a]['pos'] ?? 0; $pb = $spaces[$b]['pos'] ?? 0;
            return ($pa <=> $pb) ?: strnatcasecmp((string) $spaces[$a]['name'], (string) $spaces[$b]['name']);
        };
        foreach ($spaces as $iid => $s) {
            if ($s['children'] !== []) {
                usort($spaces[$iid]['children'], $nachPosition);
            }
        }
        usort($roots, $nachPosition);

        $build = function (int $iid) use (&$build, $spaces) {
            $s    = $spaces[$iid];
            $node = ['iid' => $iid, 'name' => $s['name'], 'kind' => $s['kind'], 'abbr' => $s['abbr'],
                     'entities' => $s['entities'], 'children' => []];
            foreach ($s['children'] as $c) {
                $node['children'][] = $build($c);
            }
            return $node;
        };
        $tree = array_map($build, $roots);

        return $this->json(['ok' => true, 'tree' => $tree, 'unassigned' => $unassigned]);
    }

    /**
     * AUTOMATISCHE Domaenen-/Gewerk-Klassifikation eines (verlinkten) Rohobjekts
     * anhand seiner Variablen-Idents, Profile und des Moduls. Liefert die
     * HomeSuite-Domaene oder '' wenn unklar. Loest Links auf.
     *
     * Vergebene Werte, in der Reihenfolge der Pruefung:
     *   audio    Modulname (Sonos/HEOS/MusicCast/Denon/AirPlay/Cast)
     *   heating  SET_TEMPERATURE / ACTUAL_TEMPERATURE / Temperatur-Profil
     *   shading  LEVEL + STOP|DIRECTION|SHUTTER, oder Rollo-/Jalousie-Profil
     *   irrigation WATERING / IRRIGATION / VALVE_OPEN
     *   security MELDER: STATE ohne Stellkanal, dafuer LOWBAT|INSTALL_TEST|ERROR
     *   power    ENERGY_COUNTER|POWER|VOLTAGE|CURRENT, oder STATE + WORKING|INHIBIT ohne Dimmer
     *   lighting Intensity-/Dimmer-/Brightness-Profil, sonst STATE mit Schalterprofil
     * Die REIHENFOLGE traegt die Aussage: Melder und Steckdose muessen VOR der Lichtregel
     * stehen, sonst schluckt deren STATE-Zweig beide.
     */
    private function classifyDomain(int $objId): string
    {
        if ($objId <= 0 || !function_exists('IPS_GetObject') || !@\IPS_ObjectExists($objId)) {
            return '';
        }
        $obj  = @\IPS_GetObject($objId);
        $type = (int) ($obj['ObjectType'] ?? -1);
        if ($type === 6) { // Link -> Ziel aufloesen
            $t = (int) (@\IPS_GetLink($objId)['TargetID'] ?? 0);
            return $t > 0 ? $this->classifyDomain($t) : '';
        }

        $idents   = [];
        $profiles = [];
        $module   = '';
        $addVar   = function (int $vid) use (&$idents, &$profiles): void {
            $o = @\IPS_GetObject($vid);
            if (($o['ObjectType'] ?? -1) !== 2) {
                return;
            }
            $id = strtoupper((string) ($o['ObjectIdent'] ?? ''));
            if ($id !== '') {
                $idents[$id] = 1;
            }
            $v = @\IPS_GetVariable($vid);
            if (is_array($v)) {
                $p = (string) ($v['VariableCustomProfile'] ?? '');
                if ($p === '') {
                    $p = (string) ($v['VariableProfile'] ?? '');
                }
                if ($p !== '') {
                    $profiles[strtolower($p)] = 1;
                }
            }
        };

        if ($type === 1) { // Instanz -> Kind-Variablen scannen
            $module = (string) (@\IPS_GetInstance($objId)['ModuleInfo']['ModuleName'] ?? '');
            foreach ((array) @\IPS_GetChildrenIDs($objId) as $c) {
                $addVar((int) $c);
            }
        } elseif ($type === 2) { // einzelne Variable
            $addVar($objId);
        } else {
            return '';
        }

        $has  = static fn(string $k): bool => isset($idents[$k]);
        $prof = static function (string $needle) use ($profiles): bool {
            foreach (array_keys($profiles) as $p) {
                if (strpos($p, $needle) !== false) {
                    return true;
                }
            }
            return false;
        };

        // Audio ueber das Modul (Sonos/HEOS/MusicCast/Denon).
        foreach (['sonos', 'heos', 'musiccast', 'denon', 'airplay', 'cast'] as $needle) {
            if (stripos($module, $needle) !== false) {
                return 'audio';
            }
        }
        // Heizung: Sollwert-/Ist-Temperatur-Datenpunkte oder Temperatur-Profil.
        if ($has('SET_TEMPERATURE') || $has('SETPOINT') || $has('SET_POINT_TEMPERATURE')
            || $has('ACTUAL_TEMPERATURE') || $prof('temperatur')) {
            return 'heating';
        }
        // Beschattung: Level + Stop/Richtung, oder Rollo-/Shutter-Profil.
        if (($has('LEVEL') && ($has('STOP') || $has('DIRECTION') || $has('SHUTTER')))
            || $prof('shutter') || $prof('blind') || $prof('rollo') || $prof('shading') || $prof('jalous')) {
            return 'shading';
        }
        // Bewaesserung.
        if ($has('WATERING') || $has('IRRIGATION') || $has('VALVE_OPEN') || $prof('irrigation') || $prof('bewaess')) {
            return 'irrigation';
        }
        // MELDER VOR LICHT.
        //
        // Ein Tuer-/Fensterkontakt, Rauch- oder Bewegungsmelder hat STATE mit einem
        // Schalterprofil - und fiel damit in die Lichtregel darunter. Auf der Raumkarte
        // stand "FS_Esszimmer · Kontakt [lighting]" (nachgemessen 05.09.2026).
        // Der Unterschied ist nicht das STATE, sondern das FEHLEN eines Stellkanals:
        // ein Melder MELDET, er schaltet nichts. HM-Sec-SCo fuehrt ERROR, INSTALL_TEST,
        // LOWBAT und STATE - kein LEVEL, kein WORKING, kein INHIBIT. Ein Schaltaktor
        // (HM-ES-PMSw1) hat dagegen WORKING und INHIBIT neben dem STATE.
        // 'security' ist keine Erfindung: das SecurityCenter-Modul fuehrt dieselbe Domaene.
        if ($has('STATE')
            && !$has('LEVEL') && !$has('WORKING') && !$has('INHIBIT')
            && !$has('ON_TIME') && !$has('RAMP_TIME')
            && ($has('LOWBAT') || $has('INSTALL_TEST') || $has('ERROR'))) {
            return 'security';
        }
        // STROM/STECKDOSE VOR LICHT.
        //
        // Ein einfacher Schaltaktor sah bisher aus wie eine Lampe: STATE mit Schalterprofil,
        // und die Lichtregel darunter griff zu. An einem Nebenstandort hiessen deshalb die
        // Steckdosen fuer Fernseher und IT "lighting". Zwei Merkmale trennen das sauber:
        //   MESSKANAL  ENERGY_COUNTER / POWER / VOLTAGE / CURRENT - misst Strom, schaltet nichts
        //   SCHALTAKTOR STATE zusammen mit WORKING oder INHIBIT, aber OHNE Dimmkanal
        // Ein Dimmer (Intensity/Brightness-Profil) bleibt Licht - das ist der Fall, den die
        // Lichtregel wirklich meint, und so sind die 21 verlinkten Dimmkanaele im Haus
        // eingestuft (nachgemessen: ~Intensity.100 MIT Schaltaktion).
        // Was an einer Steckdose haengt, sagt kein Datenpunkt. 'power' behauptet deshalb nur,
        // was messbar ist: hier wird Strom geschaltet oder gemessen.
        $dimmbar = $prof('intensity') || $prof('dimmer') || $prof('brightness');
        if ($has('ENERGY_COUNTER') || $has('POWER') || $has('VOLTAGE') || $has('CURRENT')) {
            return 'power';
        }
        if (!$dimmbar && $has('STATE') && ($has('WORKING') || $has('INHIBIT'))) {
            return 'power';
        }
        // Licht (noch kein HomeSuite-Modul, aber erkennbar -> spaeter nutzbar).
        if ($dimmbar || ($has('STATE') && ($prof('switch') || $prof('~switch')))) {
            return 'lighting';
        }
        return '';
    }

    /** Naechster Raum-Vorfahre einer Instanz im Objektbaum (oder null). */
    private function nearestRoom(int $iid, array $spaces): ?int
    {
        $cur = (int) @\IPS_GetParent($iid);
        for ($i = 0; $i < 12 && $cur > 0; $i++) {
            if (isset($spaces[$cur]) && (($spaces[$cur]['kind'] ?? '') === 'Raum')) {
                return $cur;
            }
            $cur = (int) @\IPS_GetParent($cur);
        }
        return null;
    }

    /**
     * HSH_GetSuiteManifest — Aggregat der Manifeste aller Entitaeten plus
     * Hub-Kopf. Ruft je Entitaet das generische Prefix_GetManifest auf.
     *
     * @return string JSON
     */
    public function GetSuiteManifest(): string
    {
        $entities = $this->discoverEntities();
        $manifests = [];

        foreach ($entities as $e) {
            $fn = $e['prefix'] . '_GetManifest';
            if (!function_exists($fn)) {
                continue;
            }
            try {
                $raw = (string) call_user_func($fn, $e['instanceID']);
                $dec = json_decode($raw, true);
                if (is_array($dec)) {
                    $manifests[] = $dec;
                }
            } catch (\Throwable $ex) {
                // Einzelne defekte Entitaet ueberspringen — nie das Aggregat kippen.
                $this->SendDebug('HS.SuiteManifest', $e['prefix'] . '#' . $e['instanceID'] . ': ' . $ex->getMessage(), 0);
            }
        }

        $out = [
            'ok'  => true,
            'hub' => [
                'instanceId'  => $this->InstanceID,
                'hookPath'    => self::HOOK_PATH,
                'entityCount' => count($manifests),
            ],
            'entities' => $manifests,
        ];
        return $this->json($out);
    }

    /**
     * HSH_Provision — reiht einen ASYNC Provision-Job ein und startet den Timer.
     * Legt NIE synchron eine Instanz an (F6); die eigentliche Erzeugung erledigt
     * __TimerCb('provision') in einem eigenen Thread.
     *
     * @param string $requestJson {guid|family|prefix, name, parentId?, connectParentId?, properties?}
     * @return string JSON-Zwischenstand
     */
    public function Provision(string $requestJson): string
    {
        $spec = json_decode($requestJson, true);
        if (!is_array($spec)) {
            return $this->json(['ok' => false, 'error' => 'validation', 'detail' => 'ungueltiges JSON']);
        }
        // Ein evtl. mitgeschicktes {op:'provision',args:{...}} entpacken.
        if (isset($spec['args']) && is_array($spec['args'])) {
            $spec = $spec['args'];
        }
        return $this->json($this->enqueueProvision($spec, false));
    }

    /**
     * HSH_RotateToken — erzeugt ein neues Verwaltungs-Token und liefert es zurueck.
     *
     * @return string JSON: {"ok":true,"token":"..."}
     */
    public function RotateToken(): string
    {
        $token = $this->rotateTokenInternal();
        return $this->json(['ok' => true, 'token' => $token]);
    }

    /**
     * HSH_RunTimer — oeffentliche Bruecke fuer den Modul-Timer. Delegiert an den
     * (SDK-oeffentlichen, aber nie als Manage-Verb zulaessigen) __TimerCb (F10).
     * Kein Token noetig: der Tick ist idempotent und legt nur bereits
     * eingereihte, autorisierte Jobs an.
     */
    public function RunTimer(string $job): void
    {
        $this->__TimerCb($job);
    }

    /**
     * Timer-Callback (F6/F10). Arbeitet die Provision-Queue NICHT-BLOCKIEREND ab:
     * pro Tick genau ein Job (Create -> SetProperty -> ConnectParent -> ApplyChanges
     * ueber den idempotenten Provisioner). Danach Timer stoppen, wenn leer.
     */
    public function __TimerCb(string $job): void
    {
        if ($job === 'lightauto') {
            $this->lightAutoTick();
            return;
        }
        if ($job !== 'provision') {
            return;
        }
        $this->processNextProvisionJob();
    }

    // ==================================================================
    // WebHook /hook/homesuite
    // ==================================================================

    /**
     * WebHook-Handler. Wird von der WebHook-Control aufgerufen, sobald ein
     * Request fuer einen auf diese Instanz registrierten Hook eintrifft.
     * Endpunkt-Matrix (§6.2):
     *
     *   GET  ?api=manifest|suite|entities|state|ping   -> frei (Lesen)
     *   POST ?api=manage                                -> Header X-HS-Token
     *   POST ?api=provision                             -> Header X-HS-Token
     *   GET  ?api=discover  &module=&inst=&driver=      -> Header X-HS-Token
     *   *    ?api=migrate                               -> Header X-HS-Token
     *
     * Token AUSSCHLIESSLICH ueber Header X-HS-Token fuer Schreib-/Migrate-Verben
     * (Risiko K); ?key= nur als Bequemlichkeit fuer die freien Lese-Endpunkte.
     */
    protected function ProcessHookData()
    {
        // Spotify-OAuth-Callback (eigener Hook /hook/hsspotify) — VOR jeder Token-Pruefung,
        // da der Redirect von Spotify kommt (kein Header-Token). Liefert HTML zurueck.
        if (strpos((string) ($_SERVER['REQUEST_URI'] ?? ''), 'hsspotify') !== false) {
            $this->handleSpotifyCallback();
            return;
        }

        header('Content-Type: application/json; charset=utf-8');

        $api = strtolower((string) ($_GET['api'] ?? 'suite'));

        // Freie Lese-Endpunkte -----------------------------------------------
        switch ($api) {
            case 'ping':
                echo $this->json(['ok' => true, 'pong' => true, 'hub' => $this->InstanceID]);
                return;

            case 'suite':
            case 'manifest':
                echo $this->GetSuiteManifest();
                return;

            case 'entities':
                echo $this->ListEntities();
                return;

            case 'state':
                echo $this->GetState();
                return;
        }

        // Ab hier: Schreib-/Migrate-Verben -> Header-Token zwingend (§K).
        if (!$this->checkHeaderToken()) {
            http_response_code(403);
            echo $this->json(['ok' => false, 'error' => 'forbidden']);
            return;
        }

        switch ($api) {
            case 'manage':
                echo $this->hookManage();
                return;

            case 'provision':
                echo $this->Provision($this->requestBody());
                return;

            case 'discover':
                echo $this->hookDiscover();
                return;

            case 'migrate':
                // Migrations-Provider je Domaene folgt in spaeteren Meilensteinen.
                echo $this->hookMigrate();
                return;
        }

        http_response_code(404);
        echo $this->json(['ok' => false, 'error' => 'unknown_api', 'detail' => $api]);
    }

    /**
     * ?api=manage: routet an das Ziel-Modul (eigenes oder ein HomeSuite-Device)
     * ueber dessen generisches Prefix_Manage — mit GUID-Whitelist + ModuleType-
     * Check (§5.1). Ohne Ziel: Hub-eigenes Manage.
     */
    private function hookManage(): string
    {
        $body = $this->requestBody();
        $p    = json_decode($body, true);
        if (!is_array($p)) {
            return $this->json(['ok' => false, 'error' => 'validation', 'detail' => 'ungueltiges JSON']);
        }

        $target = (int) ($p['instanceID'] ?? $p['inst'] ?? 0);

        // Kein Ziel -> Hub selbst.
        if ($target <= 0 || $target === $this->InstanceID) {
            return $this->Manage($body);
        }

        // Ziel muss ein HomeSuite-Device (type 3) sein.
        $info = $this->hsModuleInfo($target);
        if ($info === null) {
            return $this->json(['ok' => false, 'error' => 'unknown_module']);
        }
        $fn = $info['prefix'] . '_Manage';
        if (!function_exists($fn)) {
            return $this->json(['ok' => false, 'error' => 'not_found', 'detail' => $fn]);
        }
        return (string) call_user_func($fn, $target, $body);
    }

    /**
     * ?api=discover: delegiert an das Ziel-Modul (op:discover, dort asynchron).
     */
    private function hookDiscover(): string
    {
        $target = (int) ($_GET['inst'] ?? 0);
        $driver = (string) ($_GET['driver'] ?? '');
        if ($target <= 0) {
            return $this->json(['ok' => false, 'error' => 'validation', 'detail' => 'inst fehlt']);
        }
        $info = $this->hsModuleInfo($target);
        if ($info === null) {
            return $this->json(['ok' => false, 'error' => 'unknown_module']);
        }
        $fn = $info['prefix'] . '_Manage';
        if (!function_exists($fn)) {
            return $this->json(['ok' => false, 'error' => 'not_found', 'detail' => $fn]);
        }
        $req = $this->json(['op' => 'discover', 'args' => ['driver' => $driver]]);
        return (string) call_user_func($fn, $target, $req);
    }

    /**
     * ?api=migrate: Platzhalter-Zweig (Header-token-gated). Die konkreten
     * Migrations-Provider (HSHT_MigrateAPI etc.) liefern die spaeteren Phasen.
     */
    private function hookMigrate(): string
    {
        return $this->json([
            'ok'     => false,
            'error'  => 'not_implemented',
            'detail' => 'Migrations-Provider werden je Domaene in Phase 1..4 bereitgestellt',
        ]);
    }

    // ==================================================================
    // Registry / Discovery-Helfer
    // ==================================================================

    /**
     * Entdeckt alle HomeSuite-Domaenen-Instanzen (Devices) im Kernel.
     *
     * @return array<int,array{instanceID:int,guid:string,prefix:string,domain:string,name:string,moduleType:int}>
     */
    private function discoverEntities(): array
    {
        $entities = [];
        if (!function_exists('IPS_GetInstanceListByModuleID')) {
            return $entities;
        }
        foreach (array_merge($this->domainGuids(), $this->fremdeEntitaetsGuids()) as [$guid, $domain]) {
            $ids = @\IPS_GetInstanceListByModuleID($guid);
            if (!is_array($ids)) {
                continue;
            }
            foreach ($ids as $id) {
                $id = (int) $id;
                $entities[] = [
                    'instanceID' => $id,
                    'guid'       => $guid,
                    'prefix'     => $this->prefixOf($id, $guid),
                    'domain'     => $domain,
                    'name'       => $this->nameOf($id),
                    'moduleType' => 3,
                ];
            }
        }
        return $entities;
    }

    /**
     * Entitaets-Domaenen als [guid, domain]-Paare (nur Devices, keine Bridges/
     * kein Hub). Beide Audio-Familien (HSAU/HSAUX) melden dieselbe Domaene
     * "audio" — daher eine Liste statt einer domain=>guid-Map.
     *
     * @return array<int,array{0:string,1:string}>
     */
    private function domainGuids(): array
    {
        return [
            [self::GUID_HSHT,  'heating'],
            [self::GUID_HSSH,  'shading'],
            [self::GUID_HSAU,  'audio'],
            [self::GUID_HSAUX, 'audio'],
            [self::GUID_HSIR,  'irrigation'],
            // ClimateZone fehlte hier. Die Instanzen gibt es laengst (sieben an den
            // Auslandsstandorten), sie erben wie alle anderen von EntityModule - sie tauchten
            // nur in der Raumtopologie nicht auf, weil diese Liste sie nicht kannte. Damit
            // standen die Klimazonen an den Nebenstandorten weder in einem Raum noch in
            // 'unassigned': sie waren schlicht unsichtbar.
            [self::GUID_HSAC,  'climate'],
            // LightDevice fehlte aus demselben Grund wie die ClimateZone. Alle 42 Leuchten
            // haengen laengst korrekt unter ihren HSSP-Raeumen, tauchten in der Topologie aber
            // nur als verlinkte ROHGERAETE mit geratener Domaene auf - die HSLT-Entitaeten
            // selbst nicht. Doppelungen entstehen dadurch nicht: ein Link traegt die ID des
            // Zielgeraets, die Entitaet ihre eigene.
            [self::GUID_HSLTE, 'lighting'],
        ];
    }

    /**
     * Geraete aus FREMDEN Libraries, die im Raumbaum erscheinen sollen.
     *
     * Bewusst getrennt von domainGuids(): daraus entsteht ueber domainGuidList()
     * auch die Whitelist, die bestimmt, an welche Instanzen der Hub PREFIX_Manage
     * schicken darf. Ein Modul im Raumbaum zu zeigen und ihm Befehle schicken zu
     * duerfen sind zwei verschiedene Dinge - und das Zweite ist eine
     * Sicherheitsgrenze, die wegen einer Anzeige nicht aufgeweicht wird.
     *
     * @return list<array{0:string,1:string}> [guid, domaene]
     */
    private function fremdeEntitaetsGuids(): array
    {
        return [
            // EnigmaReceiver (eigene Library SymconEnigmaReceiver, Prefix ER)
            ['{34B2530B-BFFE-4691-9937-385917E12D51}', 'media'],
        ];
    }

    /** Nur die GUIDs der Entitaets-Domaenen (fuer Whitelist-Checks). */
    private function domainGuidList(): array
    {
        return array_map(static fn(array $p): string => $p[0], $this->domainGuids());
    }

    /**
     * Whitelist aller provisionierbaren HomeSuite-Modul-GUIDs (Devices + Bridges).
     * Sicherheitsgrenze: provision darf ausschliesslich eigene Module anlegen.
     *
     * @return array<string,string> guid => prefix
     */
    private function provisionableGuids(): array
    {
        return [
            self::GUID_HSHT  => 'HSHT',
            self::GUID_HSSH  => 'HSSH',
            self::GUID_HSAU  => 'HSAU',
            self::GUID_HSAUX => 'HSAUX',
            self::GUID_HSIR  => 'HSIR',
            self::GUID_HSBH  => 'HSBH',
            self::GUID_HSBM  => 'HSBM',
            self::GUID_HSBD  => 'HSBD',
        ];
    }

    /**
     * Liefert {prefix} zu einer Instanz, WENN sie ein HomeSuite-Device (type 3)
     * ist — sonst null (GUID-Whitelist + ModuleType-Check, §5.1).
     *
     * @return array{prefix:string,guid:string}|null
     */
    private function hsModuleInfo(int $instanceId): ?array
    {
        if ($instanceId <= 0 || !function_exists('IPS_InstanceExists') || !\IPS_InstanceExists($instanceId)) {
            return null;
        }
        $inst = @\IPS_GetInstance($instanceId);
        $guid = is_array($inst) ? (string) ($inst['ModuleInfo']['ModuleID'] ?? '') : '';
        if (!in_array($guid, $this->domainGuidList(), true)) {
            return null;
        }
        $mod = @\IPS_GetModule($guid);
        if (!is_array($mod) || (int) ($mod['ModuleType'] ?? -1) !== 3) {
            return null;
        }
        return ['prefix' => (string) ($mod['Prefix'] ?? ''), 'guid' => $guid];
    }

    /** Prefix einer Instanz aus dem Kernel (F2: nie erfundene Property). */
    private function prefixOf(int $instanceId, string $guid): string
    {
        if (function_exists('IPS_GetModule')) {
            $mod = @\IPS_GetModule($guid);
            if (is_array($mod) && isset($mod['Prefix'])) {
                return (string) $mod['Prefix'];
            }
        }
        return '';
    }

    /** Objektname einer Instanz (leer bei Fehler). */
    private function nameOf(int $instanceId): string
    {
        if (function_exists('IPS_GetName')) {
            return (string) @\IPS_GetName($instanceId);
        }
        return '';
    }

    // ==================================================================
    // Async Provision (Job-Queue + Timer)
    // ==================================================================

    /**
     * Validiert eine Provision-Spec, reiht sie als Job ein und aktiviert den
     * Timer. dryrun=true validiert nur und liefert den aufgeloesten Plan.
     *
     * @param array<string,mixed> $spec
     * @return array<string,mixed>
     */
    private function enqueueProvision(array $spec, bool $dryrun): array
    {
        $guid = $this->resolveModuleGuid($spec);
        if ($guid === null) {
            return ['ok' => false, 'op' => 'provision', 'error' => 'validation',
                'field' => 'guid', 'detail' => 'unbekannte/nicht erlaubte Modul-GUID'];
        }
        $name = trim((string) ($spec['name'] ?? ''));
        if ($name === '') {
            return ['ok' => false, 'op' => 'provision', 'error' => 'validation', 'field' => 'name'];
        }

        $job = [
            'id'              => bin2hex(random_bytes(6)),
            'guid'            => $guid,
            'name'            => $name,
            'parentId'        => (int) ($spec['parentId'] ?? 0),
            'connectParentId' => (int) ($spec['connectParentId'] ?? 0),
            'properties'      => (isset($spec['properties']) && is_array($spec['properties'])) ? $spec['properties'] : [],
            'status'          => 'queued',
            'createdAt'       => time(),
        ];

        $plan = [
            'IPS_CreateInstance(' . $guid . ')',
            'IPS_SetName(<new>, "' . $name . '")',
            'IPS_SetProperty(<new>, ...) x' . count($job['properties']),
        ];
        if ($job['connectParentId'] > 0) {
            $plan[] = 'IPS_ConnectInstance(<new>, ' . $job['connectParentId'] . ')';
        }
        $plan[] = 'IPS_ApplyChanges(<new>)';

        if ($dryrun) {
            return ['ok' => true, 'op' => 'provision', 'dryrun' => true, 'plan' => $plan, 'job' => $job];
        }

        $queue   = $this->readQueue();
        $queue[] = $job;
        $this->writeQueue($queue);

        // Timer scharf schalten -> Abarbeitung im eigenen Thread (F6).
        $this->SetTimerInterval(self::TIMER_PROVISION, self::PROVISION_TICK_MS);

        return ['ok' => true, 'op' => 'provision', 'plan' => $plan,
            'result' => ['job' => $job['id'], 'status' => 'queued']];
    }

    /**
     * Loest die Ziel-GUID aus der Spec auf (guid | family | prefix) und prueft
     * sie gegen die Whitelist provisionierbarer HomeSuite-Module.
     */
    private function resolveModuleGuid(array $spec): ?string
    {
        $whitelist = $this->provisionableGuids();

        $guid = trim((string) ($spec['guid'] ?? ''));
        if ($guid !== '' && isset($whitelist[$guid])) {
            return $guid;
        }

        $byPrefix = array_flip($whitelist); // prefix => guid
        $family   = trim((string) ($spec['family'] ?? ''));
        if ($family !== '' && isset($byPrefix[$family])) {
            return $byPrefix[$family];
        }
        $prefix = trim((string) ($spec['prefix'] ?? ''));
        if ($prefix !== '' && isset($byPrefix[$prefix])) {
            return $byPrefix[$prefix];
        }

        return null;
    }

    /**
     * Arbeitet genau EINEN Job ab (non-blocking, resumable). Bei leerer Queue
     * wird der Timer gestoppt.
     */
    private function processNextProvisionJob(): void
    {
        $queue = $this->readQueue();
        if (count($queue) === 0) {
            $this->SetTimerInterval(self::TIMER_PROVISION, 0);
            return;
        }

        $job = array_shift($queue);
        // Restliche Queue sofort zurueckschreiben (der aktuelle Job ist entnommen).
        $this->writeQueue($queue);

        try {
            $prov   = new Provisioner(0);
            $parent = ((int) $job['parentId']) > 0 ? (int) $job['parentId'] : $this->homeRoot();

            $props = is_array($job['properties']) ? $job['properties'] : [];
            if (((int) $job['connectParentId']) > 0) {
                // Sonderschluessel -> Provisioner ruft IPS_ConnectInstance (F6).
                $props['ConnectParentID'] = (int) $job['connectParentId'];
            }

            $id = $prov->instance((string) $job['guid'], $parent, (string) $job['name'], $props);

            $this->LogMessage(
                'HS.Provision ok: ' . $job['guid'] . ' -> #' . $id . ' "' . $job['name'] . '"',
                KL_MESSAGE
            );
            $this->SendDebug('HS.Provision', 'Job ' . $job['id'] . ' angelegt: #' . $id, 0);
        } catch (\Throwable $e) {
            $this->LogMessage('HS.Provision FEHLER (' . $job['id'] . '): ' . $e->getMessage(), KL_ERROR);
        }

        // Weitere Jobs? Timer weiterlaufen lassen, sonst stoppen + Baum aufraeumen.
        if (count($this->readQueue()) === 0) {
            $this->SetTimerInterval(self::TIMER_PROVISION, 0);
            try { $this->organizeTree(); } catch (\Throwable $e) { /* Aufraeumen nie fatal */ }
        }
    }

    /** @return array<int,array<string,mixed>> */
    private function readQueue(): array
    {
        try {
            $raw = (string) $this->ReadAttributeString(self::ATTR_QUEUE);
        } catch (\Throwable $e) {
            return [];
        }
        $dec = json_decode($raw, true);
        return is_array($dec) ? array_values($dec) : [];
    }

    /** @param array<int,array<string,mixed>> $queue */
    private function writeQueue(array $queue): void
    {
        $json = json_encode(array_values($queue), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->WriteAttributeString(self::ATTR_QUEUE, $json === false ? '[]' : $json);
    }

    // ==================================================================
    // Token & Snapshots
    // ==================================================================

    /** Aktuelles Verwaltungs-Token (leer, wenn noch nicht erzeugt). */
    private function readToken(): string
    {
        try {
            return (string) $this->ReadAttributeString(self::ATTR_TOKEN);
        } catch (\Throwable $e) {
            return '';
        }
    }

    /** Erzeugt ein Token, falls noch keins existiert (idempotent). */
    private function ensureToken(): void
    {
        if ($this->readToken() === '') {
            $this->WriteAttributeString(self::ATTR_TOKEN, bin2hex(random_bytes(24)));
        }
    }

    /** Erzeugt ein neues Token und liefert es zurueck. */
    private function rotateTokenInternal(): string
    {
        $token = bin2hex(random_bytes(24));
        $this->WriteAttributeString(self::ATTR_TOKEN, $token);
        $this->LogMessage('HS.Hub: Verwaltungs-Token rotiert', KL_MESSAGE);
        return $token;
    }

    /**
     * Prueft das Header-Token (X-HS-Token) gegen das gespeicherte, per hash_equals
     * (§6.2). Ein leeres/unkonfiguriertes Token laesst NICHTS durch.
     */
    private function checkHeaderToken(): bool
    {
        $stored = $this->readToken();
        if ($stored === '') {
            return false;
        }
        $sent = (string) ($_SERVER['HTTP_X_HS_TOKEN'] ?? '');
        return $sent !== '' && hash_equals($stored, $sent);
    }

    /**
     * Listet vorhandene Snapshot-Dateien (Basename + Groesse + mtime).
     *
     * @return array<int,array{file:string,size:int,mtime:int}>
     */
    private function listSnapshots(): array
    {
        $dir = (new Backup())->dir();
        $out = [];
        $files = glob($dir . '/*_snapshot.json');
        if (is_array($files)) {
            sort($files, SORT_STRING);
            foreach ($files as $f) {
                $out[] = [
                    'file'  => basename($f),
                    'size'  => (int) @filesize($f),
                    'mtime' => (int) @filemtime($f),
                ];
            }
        }
        return $out;
    }

    // ==================================================================
    // interne Helfer
    // ==================================================================

    /** Rohen Request-Body des WebHooks lesen. */
    private function requestBody(): string
    {
        $body = file_get_contents('php://input');
        return $body === false ? '' : $body;
    }

    /**
     * @param array<string,mixed> $data
     */
    private function json(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        return $json === false ? '{"ok":false,"error":"encode"}' : $json;
    }

    /**
     * Registriert (idempotent) den WebHook auf diese Instanz. Muss NACH
     * Kernel-Ready laufen (F3) — die WebHook-Control existiert erst dann.
     */
    private function registerHook(string $hook): void
    {
        if (!function_exists('IPS_GetInstanceListByModuleID')) {
            return;
        }
        $ids = @\IPS_GetInstanceListByModuleID(self::GUID_WEBHOOK);
        if (!is_array($ids) || count($ids) === 0) {
            return;
        }
        $whId = (int) $ids[0];

        $hooks = json_decode((string) @\IPS_GetProperty($whId, 'Hooks'), true);
        if (!is_array($hooks)) {
            $hooks = [];
        }

        $changed = false;
        $found   = false;
        foreach ($hooks as &$h) {
            if (is_array($h) && ($h['Hook'] ?? '') === $hook) {
                $found = true;
                if ((int) ($h['TargetID'] ?? 0) !== $this->InstanceID) {
                    $h['TargetID'] = $this->InstanceID;
                    $changed = true;
                }
                break;
            }
        }
        unset($h);

        if (!$found) {
            $hooks[] = ['Hook' => $hook, 'TargetID' => $this->InstanceID];
            $changed = true;
        }

        if ($changed) {
            @\IPS_SetProperty($whId, 'Hooks', json_encode($hooks));
            @\IPS_ApplyChanges($whId);
        }
    }
}

<?php

declare(strict_types=1);

/**
 * AudioZone (HSAU) — Domaenen-Modul "Audio/Media" (Portierung von IPSSonos).
 *
 * Eine Instanz = ein Renderer = ein Raum-Lautsprecher. Erbt
 * {@see \Hoep\HomeSuite\EntityModule} (Manifest -> Variablen, native RequestAction,
 * RPC-Trio). Gesteuert wird ueber den generischen IAudioRenderer-Treiber
 * `generic-audio` (variablen-/skriptgebunden) — im Uebergang an die vorhandenen
 * IPSSonos-Raum-Dummy-Variablen, spaeter ohne Code-Aenderung auf einen nativen
 * Treiber (sonos-upnp / heos) umstellbar. NIE eine harte IPSSonos-Abhaengigkeit im
 * Modul; Loeschbarkeit entsteht durch den Treiberwechsel.
 *
 * "Power" ist bewusst KEINE Codec-Methode (IAudioRenderer kennt kein power()),
 * sondern eine gebundene Variable/Skript ODER Play/Stop — das Modul entscheidet.
 * Multiroom laeuft deklarativ ueber setGroupMembers (B2). Zeitregeln/Wecken laufen
 * rein HomeSuite ueber die ScheduleEngine per play/stop (kein Geraete-Alarm-Sync).
 *
 * M2-Stand: Manifest/Controls, RPC, Treiber-Aufbau, Refresh (readState->Reflect),
 * Bindung/Import (Schatten armed=false), Health/Validate, Gruppen-Ops, Notfallformular.
 * LVB-Hook (?api=audio) und Widgets folgen in M5/M6; native Treiber in M8/M9.
 *
 * Klassenname == module.json "name" == GUID {C4F2639D-2A87-453D-8175-B586BF605A38}.
 */

require_once __DIR__ . '/../libs/HomeSuite/autoload.php';

use Hoep\HomeSuite\ActionContext;
use Hoep\HomeSuite\Control;
use Hoep\HomeSuite\ControlContract;
use Hoep\HomeSuite\ContractException;
use Hoep\HomeSuite\EntityModule;
use Hoep\HomeSuite\HAL\AudioSourceRef;
use Hoep\HomeSuite\HAL\AudioState;
use Hoep\HomeSuite\HAL\DriverFactory;
use Hoep\HomeSuite\HAL\IAudioQueue;
use Hoep\HomeSuite\HAL\IAudioRenderer;
use Hoep\HomeSuite\HAL\IAudioStateReadable;
use Hoep\HomeSuite\HAL\IDriver;
use Hoep\HomeSuite\HAL\SonosUpnp;
use Hoep\HomeSuite\HAL\ContentRef;
use Hoep\HomeSuite\Engines\RadioNow;

class AudioZone extends EntityModule
{
    /** Kanonische Transport-Codes (Manifest-Optionen); der Treiber mappt auf Vendor-Codes. */
    private const TR_PLAY = 1;
    private const TR_PAUSE = 2;
    private const TR_STOP = 3;
    private const TR_NEXT = 4;
    private const TR_PREV = 5;

    private const TIMER_REFRESH = 'Refresh';
    // Ein-Schuss-Nachlesen kurz nach einem Befehl. Ohne ihn zeigt die Kachel bis zu einen
    // vollen Abfragetakt lang den alten Zustand - man drueckt Pause und sieht fuenf Sekunden
    // lang weiter "spielt". Der Push kann daran nichts aendern: er ist sofort, aber die
    // Variable aendert sich erst beim naechsten Abruf.
    private const TIMER_KICK    = 'Kick';
    // Wie lange eine nicht erreichbare Zone in Ruhe gelassen wird, bevor wieder angeklopft wird.
    private const OFFLINE_RETRY_S = 60;
    private const KICK_MS       = 900;   // dem Geraet Zeit lassen, den Befehl umzusetzen
    private const TIMER_ENQ     = 'Enqueue';
    private const ENQ_MS        = 3000;  // Takt; laenger als ein Block braucht (12 x ~165 ms)
    private const ENQ_FIRST     = 3;     // sofort, damit binnen ~1 s Ton kommt
    private const ENQ_BLOCK     = 12;    // je Timer-Lauf (~2 s bei 164 ms/Titel)
    private const TIMER_SLEEP = 'Sleep';       // Ein-Schuss: Sleep-Timer -> stop
    private const TIMER_RAMP = 'Ramp';         // sanftes Wecken: Volume schrittweise
    private const REFRESH_MS = 5000;
    private const RAMP_MS = 4000;

    protected ?IDriver $driverInstance = null;
    protected bool $driverResolved = false;

    // ==================================================================
    // Manifest (Vertrag 2)
    // ==================================================================

    protected function entityLabel(): string { return 'Audio'; }

    protected function manifest(): array
    {
        return [
            'domain' => 'audio',
            'title'  => 'Audio',
            'icon'   => 'Speaker',

            'controls' => [
                // --- schaltbar ---
                ['ident' => 'Transport', 'type' => ControlContract::T_COMMAND, 'role' => 'audio:transport',
                 'label' => 'Wiedergabe', 'varType' => 1, 'actionable' => true,
                 'options' => [
                     ['value' => self::TR_PREV,  'label' => 'Zurueck'],
                     ['value' => self::TR_PLAY,  'label' => 'Play'],
                     ['value' => self::TR_PAUSE, 'label' => 'Pause'],
                     ['value' => self::TR_STOP,  'label' => 'Stop'],
                     ['value' => self::TR_NEXT,  'label' => 'Weiter'],
                 ]],
                ['ident' => 'Volume', 'type' => ControlContract::T_LEVEL, 'role' => 'audio:volume',
                 'label' => 'Lautstaerke', 'varType' => 1, 'unit' => '%',
                 'min' => 0, 'max' => 100, 'step' => 1, 'actionable' => true],
                ['ident' => 'Mute', 'type' => ControlContract::T_SWITCH, 'role' => 'audio:mute',
                 'label' => 'Stumm', 'varType' => 0, 'profile' => '~Switch', 'actionable' => true],
                ['ident' => 'Power', 'type' => ControlContract::T_SWITCH, 'role' => 'audio:power',
                 'label' => 'Ein/Aus', 'varType' => 0, 'profile' => '~Switch', 'actionable' => true],
                ['ident' => 'Repeat', 'type' => ControlContract::T_SELECT, 'role' => 'audio:repeat',
                 'label' => 'Wiederholen', 'varType' => 1, 'actionable' => true,
                 'options' => [
                     ['value' => AudioState::REPEAT_OFF, 'label' => 'Aus'],
                     ['value' => AudioState::REPEAT_ONE, 'label' => 'Titel'],
                     ['value' => AudioState::REPEAT_ALL, 'label' => 'Alle'],
                 ]],
                ['ident' => 'Shuffle', 'type' => ControlContract::T_SWITCH, 'role' => 'audio:shuffle',
                 'label' => 'Zufall', 'varType' => 0, 'profile' => '~Switch', 'actionable' => true],
                ['ident' => 'Position', 'type' => ControlContract::T_LEVEL, 'role' => 'audio:position',
                 'label' => 'Position', 'varType' => 1, 'unit' => '%',
                 'min' => 0, 'max' => 100, 'step' => 1, 'actionable' => true],
                ['ident' => 'SourceFavorite', 'type' => ControlContract::T_SELECT, 'role' => 'audio:source-favorite',
                 'label' => 'Favorit', 'varType' => 1, 'actionable' => true],
                ['ident' => 'SourceRadio', 'type' => ControlContract::T_SELECT, 'role' => 'audio:source-radio',
                 'label' => 'Radio', 'varType' => 1, 'actionable' => true],
                ['ident' => 'SourcePlaylist', 'type' => ControlContract::T_SELECT, 'role' => 'audio:source-playlist',
                 'label' => 'Playlist', 'varType' => 1, 'actionable' => true],

                // --- Reflect (read-only Now-Playing + Zustand) ---
                ['ident' => 'Title', 'type' => ControlContract::T_REFLECT, 'role' => 'audio:title',
                 'label' => 'Titel', 'varType' => 3, 'actionable' => false],
                ['ident' => 'Artist', 'type' => ControlContract::T_REFLECT, 'role' => 'audio:artist',
                 'label' => 'Interpret', 'varType' => 3, 'actionable' => false],
                ['ident' => 'Album', 'type' => ControlContract::T_REFLECT, 'role' => 'audio:album',
                 'label' => 'Album', 'varType' => 3, 'actionable' => false],
                ['ident' => 'AlbumArtist', 'type' => ControlContract::T_REFLECT, 'role' => 'audio:albumartist',
                 'label' => 'Album-Interpret', 'varType' => 3, 'actionable' => false],
                ['ident' => 'CoverUri', 'type' => ControlContract::T_REFLECT, 'role' => 'audio:cover',
                 'label' => 'Cover-URL', 'varType' => 3, 'actionable' => false],
                ['ident' => 'PositionTime', 'type' => ControlContract::T_REFLECT, 'role' => 'audio:positiontime',
                 'label' => 'Position (Zeit)', 'varType' => 3, 'actionable' => false],
                ['ident' => 'Duration', 'type' => ControlContract::T_REFLECT, 'role' => 'audio:duration',
                 'label' => 'Dauer', 'varType' => 3, 'actionable' => false],
                ['ident' => 'PlayState', 'type' => ControlContract::T_REFLECT, 'role' => 'audio:playstate',
                 'label' => 'Spielt', 'varType' => 0, 'profile' => '~Switch', 'actionable' => false],
                ['ident' => 'GroupRole', 'type' => ControlContract::T_REFLECT, 'role' => 'audio:grouprole',
                 'label' => 'Gruppen-Rolle', 'varType' => 3, 'actionable' => false],
                ['ident' => 'GroupCoordinator', 'type' => ControlContract::T_REFLECT, 'role' => 'audio:groupcoordinator',
                 'label' => 'Gruppen-Master', 'varType' => 3, 'actionable' => false],
                ['ident' => 'Online', 'type' => ControlContract::T_REFLECT, 'role' => 'common:online',
                 'label' => 'Online', 'varType' => 0, 'profile' => '~Switch', 'actionable' => false],
            ],

            // Geplante Wiedergabe/Wecken: normaler Zeitplan (value-until-end); val = Quellen-Preset
            // (0 = aus/Stop, sonst Quellen-Index), optional volume je Slot. Rein HomeSuite (play/stop).
            'profileTypes' => [
                'audioProgram' => [
                    'label'  => 'Audio-Wochenplan (Wiedergabe/Wecken)',
                    'axes'   => ['weekday' => ['MO', 'DI', 'MI', 'DO', 'FR', 'SA', 'SO']],
                    'slot'   => ['end' => 'HH:MM (Grenze)', 'val' => ['type' => 'int', 'min' => 0, 'max' => 100]],
                    'editor' => 'slots',
                ],
            ],

            'managementActions' => [
                ['op' => 'createEntity',    'label' => 'Audiozone anlegen'],
                ['op' => 'renameEntity',    'label' => 'Umbenennen'],
                ['op' => 'deleteEntity',    'label' => 'Loeschen'],
                ['op' => 'moveEntity',      'label' => 'Verschieben'],
                ['op' => 'configureDriver', 'label' => 'Treiber/Bindung konfigurieren'],
                ['op' => 'getConfig',       'label' => 'Konfiguration lesen (Diagnose)'],
                ['op' => 'validate',        'label' => 'Bindung pruefen (Diagnose)'],
                ['op' => 'driverProbe',     'label' => 'Treiber-Status (Diagnose)'],
                ['op' => 'topology',        'label' => 'Adressen aus der Anlage pruefen (Diagnose)'],
                ['op' => 'setArmed',        'label' => 'Scharfschalten / Schatten-Modus'],
                ['op' => 'migrateConfig',   'label' => 'Config auf Properties migrieren (einmalig)'],
                ['op' => 'importLegacy',    'label' => 'Aus IPSSonos importieren'],
                ['op' => 'group',           'label' => 'Gruppieren'],
                ['op' => 'ungroup',         'label' => 'Gruppe trennen'],
                ['op' => 'setGroupVolume',  'label' => 'Gruppen-Lautstaerke'],
                ['op' => 'seek',            'label' => 'Springen (Position %)'],
                ['op' => 'queue',           'label' => 'Warteschlange lesen'],
                ['op' => 'playQueueIndex',  'label' => 'Spur der Warteschlange anspringen'],
                ['op' => 'queueRemove',     'label' => 'Titel aus der Warteschlange entfernen'],
                ['op' => 'queueClear',      'label' => 'Warteschlange leeren'],
                ['op' => 'playSource',      'label' => 'Quelle abspielen'],
                ['op' => 'updateProfile',   'label' => 'Wochenplan bearbeiten'],
                ['op' => 'getSchedule',     'label' => 'Wochenplan lesen'],
                ['op' => 'configureSchedule', 'label' => 'Zeitplan-Optionen (Quelle/Volume/Ramp/Ruhezeit)'],
                ['op' => 'sources',        'label' => 'Quellen auflisten (Favoriten/Playlists)'],
                ['op' => 'wake',           'label' => 'Wecken: Quelle starten (Lautstaerke/Rampe)'],
                ['op' => 'setSleep',        'label' => 'Sleep-Timer setzen'],
                ['op' => 'cancelSleep',     'label' => 'Sleep-Timer abbrechen'],
                ['op' => 'computeProbe',    'label' => 'Zeitplan/Regel-Vorschau (Trockenlauf)'],
                ['op' => 'radioNow',        'label' => 'Radio: laufender Titel + Cover'],
                ['op' => 'playDirect',      'label' => 'Radio: HQ-Direktstream spielen'],
                ['op' => 'radioStations',   'label' => 'Radio: Senderliste'],
                ['op' => 'playContent',     'label' => 'Bibliotheks-Inhalt abspielen (ContentRef)'],
                ['op' => 'playContainer',   'label' => 'Ganze Sammlung abspielen/anhaengen (Album, Playlist, Hoerbuch)'],
                ['op' => 'cancelEnqueue',   'label' => 'Laufendes Einreihen abbrechen'],
            ],

            'capabilities' => [
                'scheduleMode' => 'controller',
                'transport'    => true,
                'grouping'     => true,
                'sources'      => ['favorite', 'station', 'playlist'],
                'driver'       => $this->configuredDriverId(),
            ],
        ];
    }

    /** Bedienung dieser Idents oeffnet ein manualHold-Fenster (Automatik-Hoheit, A3). */
    protected function isAutomated(Control $c): bool
    {
        return in_array($c->ident, ['Volume', 'Power', 'SourceFavorite', 'SourceRadio', 'SourcePlaylist'], true);
    }

    // ==================================================================
    // Lebenszyklus
    // ==================================================================

    protected function setupTimers(): void
    {
        $this->RegisterTimer(self::TIMER_REFRESH, 0, 'HSAU_Refresh($_IPS[\'TARGET\']);');
        $this->RegisterTimer(self::TIMER_SLEEP, 0, 'HSAU_RunSleep($_IPS[\'TARGET\']);');
        $this->RegisterTimer(self::TIMER_RAMP, 0, 'HSAU_RunRamp($_IPS[\'TARGET\']);');
        $this->RegisterTimer(self::TIMER_KICK, 0, 'HSAU_Refresh($_IPS[\'TARGET\']);');
        $this->RegisterTimer(self::TIMER_ENQ, 0, 'HSAU_RunEnqueue($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->driverResolved = false;
        $this->driverInstance = null;

        $active = $this->driver() instanceof IAudioRenderer;
        $this->SetTimerInterval(self::TIMER_REFRESH, $active ? $this->refreshIntervalMs() : 0);
        $this->syncReferences();
        $this->syncBindingLinks(); // Baum-Transparenz: sichtbare bl_-Links (nur generic-audio)
        $this->updateHealth();
        $this->ensureScheduleEvent(); // nativer Wochenplan (Aus/An) als Zeitplan-Wahrheit
    }

    // ==================================================================
    // Bedien-Hook (Vertrag 1)
    // ==================================================================

    protected function applyControl(Control $c, $value, ActionContext $ctx): void
    {
        $drv = $this->driver();
        $armed = (bool) $this->cfgVal('armed', false);

        // Schatten-Modus: nichts real schalten, nur protokollieren (optimistischer
        // SetValue der Basis hat die Statusvariable bereits gesetzt).
        if (!$drv instanceof IAudioRenderer || !$armed) {
            $this->SendDebug('HSAU.shadow', $c->ident . '=' . (is_scalar($value) ? (string) $value : '?')
                . ($drv instanceof IAudioRenderer ? ' (nicht scharf)' : ' (kein Treiber)'), 0);
            return;
        }

        switch ($c->ident) {
            case 'Transport':
                $this->applyTransport((int) $value, $drv);
                break;
            case 'Volume':
                $drv->setVolume((int) $value);
                break;
            case 'Mute':
                $drv->setMute((bool) $value);
                break;
            case 'Power':
                $this->applyPower((bool) $value, $drv);
                break;
            case 'Repeat':
            case 'Shuffle':
                $drv->setPlayMode((int) $this->valOf('Repeat', 0), (bool) $this->valOf('Shuffle', 0));
                break;
            case 'Position':
                $drv->seek($this->pctToSec((int) $value, $drv));
                break;
            case 'SourceFavorite':
                $drv->playSource(new AudioSourceRef(AudioSourceRef::KIND_FAVORITE, (string) (int) $value));
                break;
            case 'SourceRadio':
                $drv->playSource(new AudioSourceRef(AudioSourceRef::KIND_STATION, (string) (int) $value));
                break;
            case 'SourcePlaylist':
                $drv->playSource(new AudioSourceRef(AudioSourceRef::KIND_PLAYLIST, (string) (int) $value));
                break;
            default:
                $this->SendDebug('HSAU.apply', $c->ident, 0);
                break;
        }
        // Ein Befehl an die Zone hebt die Offline-Sperre sofort auf: wer einschaltet, will
        // nicht bis zu einer Minute auf die naechste Erreichbarkeitspruefung warten.
        $rt = $this->readRt();
        if (!empty($rt['offlineSince'])) { $rt['offlineSince'] = 0; $this->writeRt($rt); }

        // Nach dem Befehl kurz nachlesen, damit die Kachel nicht bis zum naechsten Abfragetakt
        // den alten Zustand zeigt. Beim ersten Versuch machte das alles LANGSAMER, weil sieben
        // stromlose Zonen je Abruf sieben bis acht Sekunden in die Zeitueberschreitung liefen -
        // ein zusaetzlicher Abruf legte auf eine blockierte Kette noch einen drauf. Seit tote
        // Zonen zurueckgestellt werden (OFFLINE_RETRY_S) kostet ein Abruf rund 100 ms, und der
        // Anstoss tut das, wofuer er gedacht war.
        //
        // Nicht bei Position: dort laeuft der Wert ohnehin weiter, und ein Nachlesen mitten im
        // Suchlauf liest daneben.
        if ($c->ident !== 'Position') {
            $this->SetTimerInterval(self::TIMER_KICK, self::KICK_MS);
        }
    }

    /** Kanonischer Transport-Code -> Treibermethode. */
    private function applyTransport(int $code, IAudioRenderer $drv): void
    {
        switch ($code) {
            case self::TR_PLAY:  $drv->play();     break;
            case self::TR_PAUSE: $drv->pause();    break;
            case self::TR_STOP:  $drv->stop();     break;
            case self::TR_NEXT:  $drv->next();     break;
            case self::TR_PREV:  $drv->previous(); break;
        }
    }

    /**
     * "Power" ein/aus. Konfigurierbar (Nutzer-Vorgabe: beide Wege):
     *   mode 'var'      : gebundene bool-Variable (z. B. IPSSonos ROOMPOWER, echte Steckdose)
     *   mode 'script'   : Ein-/Aus-Skript (z. B. Relais-Skript)
     *   mode 'playstop' : Play (ein) / Stop (aus) ueber den Treiber (Default)
     */
    protected function applyPower(bool $on, IAudioRenderer $drv): void
    {
        $p = (array) ($this->cfg()['power'] ?? []);
        $mode = (string) ($p['mode'] ?? 'playstop');
        if ($mode === 'var' && (int) ($p['varId'] ?? 0) > 0) {
            $val = (bool) ($p['invert'] ?? false) ? !$on : $on;
            $this->writeVar((int) $p['varId'], $val);
            return;
        }
        if ($mode === 'script') {
            $sid = (int) ($on ? ($p['scriptOn'] ?? 0) : ($p['scriptOff'] ?? 0));
            if ($sid > 0) {
                $this->runScript($sid);
                return;
            }
        }
        $on ? $drv->play() : $drv->stop();
    }

    /** Position-Prozent -> Sekunden (aus reflektierter Dauer). */
    private function pctToSec(int $pct, IAudioRenderer $drv): int
    {
        $dur = 0;
        if ($drv instanceof IAudioStateReadable) {
            $dur = (int) $drv->readState()->durationSec;
        }
        $pct = max(0, min(100, $pct));
        return $dur > 0 ? (int) round($dur * $pct / 100) : 0;
    }

    // ==================================================================
    // HAL-Treiber
    // ==================================================================

    protected function driver(): ?IDriver
    {
        if ($this->driverResolved) {
            return $this->driverInstance;
        }
        $this->driverResolved = true;
        $this->driverInstance = null;

        $cfg = $this->cfg();
        $driverId = (string) ($cfg['driver'] ?? '');
        if ($driverId === '' || !DriverFactory::has($driverId)) {
            return null; // unkonfiguriert -> Schatten-Modus
        }
        // Mindest-Bindung: irgendeine schaltbare Variable/ein Skript vorhanden?
        $bind = (array) ($cfg['bind'] ?? []);
        if ($bind === []) {
            return null;
        }
        try {
            // VERBINDUNGSDATEN mitgeben: die nativen Treiber (sonos-upnp, heos) sprechen das
            // Geraet direkt an und brauchen Adresse/Kennung. Sie fehlten hier - der Treiber
            // liess sich zwar waehlen und meldete seine Faehigkeiten, stand aber ohne Adresse
            // da und lieferte einen leeren Zustand (online=false, Lautstaerke 0).
            $dcfg = [
                'bind'    => $bind,
                'reflect' => (array) ($cfg['reflect'] ?? []),
                'group'   => (array) ($cfg['group'] ?? []),
                'caps'    => (array) ($cfg['caps'] ?? []),
            ];
            foreach (['host', 'rincon', 'uid'] as $k) {
                if (($cfg[$k] ?? '') !== '') { $dcfg[$k] = (string) $cfg[$k]; }
            }
            if ((int) ($cfg['port'] ?? 0) > 0) { $dcfg['port'] = (int) $cfg['port']; }
            $this->driverInstance = DriverFactory::create($driverId, $dcfg);
        } catch (\Throwable $e) {
            $this->SendDebug('HSAU.driver', 'Treiberaufbau fehlgeschlagen: ' . $e->getMessage(), 0);
            $this->driverInstance = null;
        }
        return $this->driverInstance;
    }

    // ==================================================================
    // Refresh: Ist-Zustand spiegeln
    // ==================================================================

    /**
     * Zustand aus einem UPnP-Ereignis uebernehmen (vom Modul HomeSuite Sonos-Ereignisse).
     *
     * Der Player meldet von sich aus, was sich geaendert hat - wir schreiben nur diese
     * Felder. Was das Ereignis NICHT enthaelt, bleibt stehen; ein NOTIFY ueber die
     * Lautstaerke sagt nichts ueber den Titel, und ein leergeschriebener Titel waere
     * schlechter als ein Sekunden alter.
     *
     * Titel und Interpret stecken im DIDL des Ereignisses; ist keines dabei, wird nur
     * der Transportzustand gesetzt und der naechste regulaere Abruf holt den Rest.
     */
    public function ApplyEvent(string $json): void
    {
        $ev = json_decode($json, true);
        if (!is_array($ev) || $ev === []) {
            return;
        }
        if (isset($ev['transport'])) {
            $t = strtoupper((string) $ev['transport']);
            $spielt = ($t === 'PLAYING' || $t === 'TRANSITIONING');
            // Namen aus dem Manifest: PlayState (bool), nicht "Playing". Power folgt der
            // Wiedergabe nur, wenn keine eigene Netz-Variable gebunden ist - sonst wuerde
            // ein Ereignis die echte Steckdose ueberschreiben.
            $this->setReflect('PlayState', $spielt);
            // Power genau wie im Abruf ableiten: eine gebundene Netz-Variable hat Vorrang,
            // sonst gilt "spielt gerade". Zwei Varianten derselben Regel liefen sonst
            // auseinander, sobald eine davon geaendert wird.
            $pw = (array) ($this->cfg()['power'] ?? []);
            if ((string) ($pw['mode'] ?? 'playstop') === 'var' && (int) ($pw['varId'] ?? 0) > 0) {
                $pv = @\GetValue((int) $pw['varId']);
                $this->setReflect('Power', (bool) ($pw['invert'] ?? false) ? !$pv : (bool) $pv);
            } else {
                $this->setReflect('Power', $spielt);
            }
            // Ein Ereignis beweist, dass der Player antwortet - die Ruhepause aufheben.
            $rt = $this->readRt();
            if (!empty($rt['offlineSince'])) {
                $rt['offlineSince'] = 0;
                $this->writeRt($rt);
            }
            $this->setReflect('Online', true);
        }
        if (isset($ev['volume']) && $ev['volume'] !== '') {
            $this->setReflect('Volume', (int) $ev['volume']);
        }
        if (isset($ev['mute']) && $ev['mute'] !== '') {
            $this->setReflect('Mute', ((int) $ev['mute']) === 1);
        }
        $meta = (string) ($ev['meta'] ?? '');
        if ($meta !== '') {
            $tag = static function (string $x, string $t) {
                return preg_match('~<' . $t . '\b[^>]*>(.*?)</' . $t . '>~s', $x, $m)
                    ? trim(html_entity_decode($m[1], ENT_QUOTES | ENT_XML1 | ENT_HTML5)) : '';
            };
            $titel = $tag($meta, 'dc:title');
            if ($titel !== '') {
                $this->setReflect('Title', $titel);
                $this->setReflect('Artist', $tag($meta, 'dc:creator'));
                $this->setReflect('Album', $tag($meta, 'upnp:album'));
                $cov = $tag($meta, 'upnp:albumArtURI');
                if ($cov !== '') {
                    $this->setReflect('CoverUri', $cov);
                }
            }
        }
        // Der Radiotitel steckt nicht im DIDL, sondern im laufenden Strom - der Zwischen-
        // speicher der Now-Anzeige muss daher weg, sonst zeigt sie den alten Song.
        $rt = $this->readRt();
        unset($rt['radioCache']);
        $this->writeRt($rt);
    }

    public function Refresh(): void
    {
        $this->SetTimerInterval(self::TIMER_KICK, 0);   // Ein-Schuss: nach dem Lauf wieder aus
        $drv = $this->driver();
        if (!$drv instanceof IAudioStateReadable) {
            return; // Push-Treiber spiegeln ueber parseEvent (spaeter, Bridge)
        }

        // NICHT ERREICHBARE ZONE NUR SELTEN ANKLOPFEN.
        //
        // Ein Abruf besteht aus mehreren SOAP-Aufrufen mit je zwei Sekunden Zeitgrenze. Ist das
        // Geraet aus oder vom Netz, laeuft jeder einzelne in die Grenze - gemessen sieben bis
        // acht Sekunden fuer EINEN Abruf. Bei sieben abgeschalteten Zonen im Fuenf-Sekunden-Takt
        // ist die Kette dauerhaft blockiert, und auch die erreichbaren Zonen reagieren traege.
        //
        // Darum: wer beim letzten Mal nicht geantwortet hat, wird erst nach OFFLINE_RETRY_S
        // wieder gefragt. Ein Befehl an die Zone hebt die Sperre sofort auf (siehe applyControl),
        // damit ein Einschalten nicht bis zu einer Minute wartet.
        // Der Merker liegt im vorhandenen Laufzeitspeicher, nicht in einem neuen Attribut:
        // ein nachtraeglich eingefuehrtes Attribut fehlt bestehenden Instanzen, bis sie neu
        // angelegt werden - und jeder Lesezugriff darauf scheitert.
        $rt = $this->readRt();
        $letzte = (int) ($rt['offlineSince'] ?? 0);
        if ($letzte > 0 && (time() - $letzte) < self::OFFLINE_RETRY_S) {
            return;
        }

        $st = $drv->readState();
        $rt = $this->readRt();
        $rt['offlineSince'] = $st->online ? 0 : time();
        $this->writeRt($rt);
        $this->setReflect('Title', $st->title);
        $this->setReflect('Artist', $st->artist);
        $this->setReflect('Album', $st->album);
        $this->setReflect('AlbumArtist', $st->albumArtist);
        $this->setReflect('CoverUri', $st->coverUri);
        $this->setReflect('PositionTime', $this->secToTime($st->positionSec));
        $this->setReflect('Duration', $this->secToTime($st->durationSec));
        $this->setReflect('PlayState', $st->playState);
        $this->setReflect('Online', $st->online);
        // EIN/AUS spiegeln. Fehlte bisher ganz - deshalb stand die Zone dauerhaft auf "aus",
        // obwohl Musik lief, und der Schalter im Frontend sprang nach dem Druck zurueck.
        // Quelle je nach Betriebsart: eine gebundene Variable (Altbestand) oder - bei Geraeten
        // ohne echten Netzschalter wie Sonos - schlicht "spielt gerade".
        $pw = (array) ($this->cfg()['power'] ?? []);
        if ((string) ($pw['mode'] ?? 'playstop') === 'var' && (int) ($pw['varId'] ?? 0) > 0) {
            $pv = @\GetValue((int) $pw['varId']);
            $this->setReflect('Power', (bool) ($pw['invert'] ?? false) ? !$pv : (bool) $pv);
        } else {
            $this->setReflect('Power', (bool) $st->playState);
        }
        // Reflect der schaltbaren Ist-Werte (nur wenn nicht manuell gehalten -> kein Flackern).
        if (!$this->isManuallyHeld('Volume')) {
            $this->setReflect('Volume', $st->volume);
        }
        $this->setReflect('Mute', $st->mute);
        $this->setReflect('Position', $st->durationSec > 0
            ? (int) round($st->positionSec / $st->durationSec * 100) : 0);

        $g = $drv->readGroup();
        $this->setReflect('GroupRole', (string) ($g['role'] ?? 'standalone'));
        $this->setReflect('GroupCoordinator', (string) ($g['coordinatorUid'] ?? ''));

        $this->runSchedule();
    }

    // ==================================================================
    // Zeitregeln (rein HomeSuite ueber ScheduleEngine, gesteuert per play/stop)
    // ==================================================================

    private function scheduleCfg(): array
    {
        $c = $this->cfg();
        $s = (isset($c['schedule']) && is_array($c['schedule'])) ? $c['schedule'] : [];
        return [
            'enabled'      => (bool) ($s['enabled'] ?? false),
            'sourceKind'   => (string) ($s['sourceKind'] ?? 'favorite'), // favorite|station|playlist
            'sourceId'     => (string) ($s['sourceId'] ?? ''),           // leer -> Slot-val als Index
            'volume'       => (int) ($s['volume'] ?? 25),
            'rampMin'      => (int) ($s['rampMin'] ?? 0),                 // 0 = sofort
            'powerOffEnd'  => (bool) ($s['powerOffEnd'] ?? true),
            'quietFrom'    => (int) ($s['quietFrom'] ?? -1),             // Minuten seit Mitternacht (-1 aus)
            'quietTo'      => (int) ($s['quietTo'] ?? -1),
            'quietCapVol'  => (int) ($s['quietCapVol'] ?? 15),
        ];
    }

    /** Volume-Deckel in der Ruhezeit (Nachtabsenkung). PURE-ish (nutzt Uhrzeit). */
    private function ruleVolumeCap(array $sc, int $vol, ?int $nowMin = null): int
    {
        $from = (int) $sc['quietFrom'];
        $to   = (int) $sc['quietTo'];
        if ($from < 0 || $to < 0) {
            return $vol;
        }
        $m = $nowMin ?? ((int) date('G') * 60 + (int) date('i'));
        $inQuiet = ($from <= $to) ? ($m >= $from && $m < $to) : ($m >= $from || $m < $to); // ueber Mitternacht
        return $inQuiet ? min($vol, (int) $sc['quietCapVol']) : $vol;
    }

    /**
     * Automatik-Zeitplan (value-until-end via ScheduleEngine, Sonnen-Anker generisch).
     * FLANKE 0->an: Power on -> Volume (ggf. Ramp/Ruhezeit-Cap) -> playSource.
     * an->0: stop (+ optional Power off). Rein HomeSuite (play/stop), kein Geraete-Alarm.
     */
    protected function runSchedule(): void
    {
        if (!$this->automationEnabled()) {
            return;
        }
        $sc = $this->scheduleCfg();
        if (!$sc['enabled']) {
            return;
        }
        $now  = time();
        // Zeitplan-Wahrheit = nativer Symcon-Wochenplan (Ereignis AudioSchedule, Aus/An).
        $onNow = $this->scheduleOnAt($now);
        $rt   = $this->readRt();
        $prev = !empty($rt['schedOn']);
        if ($onNow && !$prev) {
            $this->scheduleStart(1, $sc);
        } elseif (!$onNow && $prev) {
            $this->scheduleStop($sc);
        }
        $rt = $this->readRt();
        $rt['schedOn'] = $onNow;
        $this->writeRt($rt);
    }

    private function scheduleStart(int $slotVal, array $sc): void
    {
        // Der Wochenplan unterliegt der Ruhezeit-Absenkung: er ist Dauerbeschallung,
        // kein Weckruf. Beim Wecken (mgmtWake) gilt das ausdruecklich NICHT.
        $target = $this->ruleVolumeCap($sc, max(0, min(100, (int) $sc['volume'])));
        $kind = $sc['sourceKind'];
        $sid  = ($sc['sourceId'] !== '') ? $sc['sourceId'] : (string) $slotVal;
        $this->quelleStarten($kind, $sid, $target, (int) $sc['rampMin'], 'HSAU.sched');
    }

    /**
     * Gemeinsamer Startvorgang: einschalten, Quelle setzen, Lautstaerke bzw. Rampe.
     * Genutzt vom Wochenplan UND vom Wecken - damit beide Wege dasselbe tun.
     *
     * @return array{ok:bool,armed:bool,kind:string,id:string,volume:int,rampMin:int}
     */
    private function quelleStarten(string $kind, string $sid, int $volume, int $rampMin, string $tag,
                                   string $uri = '', string $titel = ''): array
    {
        $volume = max(0, min(100, $volume));
        $drv    = $this->driver();
        $scharf = (bool) $this->cfgVal('armed', false);
        if (!$drv instanceof IAudioRenderer || !$scharf) {
            $this->SendDebug($tag, 'Schatten: WUERDE starten (' . $kind . ' #' . $sid . ', vol ' . $volume . ')', 0);
            return ['ok' => true, 'armed' => $scharf, 'kind' => $kind, 'id' => $sid,
                    'volume' => $volume, 'rampMin' => $rampMin, 'note' => 'Schatten'];
        }
        $this->applyPower(true, $drv);
        $drv->playSource(new AudioSourceRef($kind, $sid, $titel, $uri));
        if ($rampMin > 0) {
            $this->startRamp($volume, $rampMin);
        } else {
            $drv->setVolume($volume);
        }
        return ['ok' => true, 'armed' => true, 'kind' => $kind, 'id' => $sid,
                'volume' => $volume, 'rampMin' => $rampMin];
    }

    /**
     * Waehlbare Quellen der Zone: Favoriten und Playlists, wie der Player sie kennt.
     *
     * Nur LESEN - unabhaengig von 'armed', denn eine Auswahlliste schaltet nichts.
     * Ergebnis absichtlich schlank ({id,title}), die Oberflaeche braucht nicht mehr.
     *
     * @return array{ok:bool,favorites:array,playlists:array}
     */
    private function mgmtSources(): array
    {
        $drv = $this->driver();
        if (!$drv instanceof IAudioRenderer) {
            return ['ok' => false, 'error' => 'kein Treiber', 'favorites' => [], 'playlists' => []];
        }
        $abbild = static function (array $liste): array {
            $out = [];
            foreach ($liste as $ref) {
                if (!($ref instanceof AudioSourceRef)) {
                    continue;
                }
                $out[] = ['id' => $ref->id, 'title' => ($ref->title !== '' ? $ref->title : $ref->id)];
            }
            return $out;
        };
        try {
            $fav = $abbild($drv->listFavorites());
        } catch (\Throwable $e) {
            $fav = [];
        }
        try {
            $pl = $abbild($drv->listPlaylists());
        } catch (\Throwable $e) {
            $pl = [];
        }
        return ['ok' => true, 'favorites' => $fav, 'playlists' => $pl];
    }

    /**
     * Eine Quelle der Zone anhand ihrer Kennung auffinden (Favorit/Playlist).
     * Liefert den vollstaendigen Verweis samt Adresse - ohne die spielt der
     * Player nichts ab.
     */
    private function quelleFinden(string $kind, string $id): ?AudioSourceRef
    {
        $drv = $this->driver();
        if (!$drv instanceof IAudioRenderer || $id === '') {
            return null;
        }
        try {
            $liste = ($kind === AudioSourceRef::KIND_PLAYLIST) ? $drv->listPlaylists() : $drv->listFavorites();
        } catch (\Throwable $e) {
            return null;
        }
        foreach ($liste as $ref) {
            if ($ref instanceof AudioSourceRef && (string) $ref->id === $id) {
                return $ref;
            }
        }
        return null;
    }

    /**
     * Wecken: Quelle starten mit ausdruecklicher Lautstaerke und optionaler Rampe.
     *
     * Bewusst OHNE die Ruhezeit-Absenkung des Wochenplans: ein Weckruf um 6:30 faellt
     * in aller Regel in die Nachtabsenkung, und ein gedeckelter Weckruf weckt nicht.
     * Wer leise geweckt werden will, setzt die Lautstaerke in der Regel niedrig oder
     * benutzt die Rampe.
     *
     * args: {kind: favorite|playlist|station|uri|preset, id, volume?, rampMin?}
     */
    private function mgmtWake(array $args): array
    {
        $kind = (string) ($args['kind'] ?? AudioSourceRef::KIND_STATION);
        $sid  = (string) ($args['id'] ?? '');
        if ($sid === '') {
            return ['ok' => false, 'error' => 'keine Weck-Quelle angegeben (id fehlt)'];
        }
        $erlaubt = [AudioSourceRef::KIND_FAVORITE, AudioSourceRef::KIND_PLAYLIST,
                    AudioSourceRef::KIND_STATION, AudioSourceRef::KIND_URI, AudioSourceRef::KIND_PRESET];
        if (!in_array($kind, $erlaubt, true)) {
            return ['ok' => false, 'error' => 'unbekannte Quellenart: ' . $kind];
        }
        $sc  = $this->scheduleCfg();
        $vol = isset($args['volume']) ? (int) $args['volume'] : (int) $sc['volume'];
        $ramp = max(0, isset($args['rampMin']) ? (int) $args['rampMin'] : 0);

        // RADIO braucht den Umweg ueber RadioNow: playSource() baut die Adresse aus
        // $ref->uri, und ein blosser Senderschluessel ergaebe 'x-rincon-mp3radio://'
        // OHNE Ziel - der Wecker wuerde still bleiben. streamUrl() loest den
        // Schluessel auf, genau wie mgmtPlayDirect es tut.
        if ($kind === AudioSourceRef::KIND_STATION) {
            $url = RadioNow::streamUrl($sid);
            if ($url === '') {
                return ['ok' => false, 'error' => 'unbekannter Sender: ' . $sid];
            }
            $titel = (string) (RadioNow::all()[$sid]['title'] ?? 'Radio');
            return $this->quelleStarten($kind, $sid, $vol, $ramp, 'HSAU.wake', $url, $titel);
        }
        // Favorit/Playlist: die Kennung allein genuegt dem Player nicht, er braucht
        // die Adresse aus der Liste. Wird sie nicht gefunden, ist die Regel veraltet
        // (Playlist umbenannt oder geloescht) - dann lieber ein klarer Fehler als
        // stilles Nichtstun zur Weckzeit.
        $ref = $this->quelleFinden($kind, $sid);
        if ($ref === null) {
            return ['ok' => false, 'error' => 'Quelle nicht gefunden: ' . $kind . ' ' . $sid];
        }
        return $this->quelleStarten($kind, $sid, $vol, $ramp, 'HSAU.wake', $ref->uri, $ref->title);
    }

    private function scheduleStop(array $sc): void
    {
        $this->SetTimerInterval(self::TIMER_RAMP, 0);
        $drv = $this->driver();
        if (!$drv instanceof IAudioRenderer || !(bool) $this->cfgVal('armed', false)) {
            $this->SendDebug('HSAU.sched', 'Schatten: WUERDE stoppen', 0);
            return;
        }
        $drv->stop();
        if ($sc['powerOffEnd']) {
            $this->applyPower(false, $drv);
        }
    }

    /** Sanftes Wecken: Volume in Schritten bis Ziel ueber rampMin Minuten. */
    private function startRamp(int $target, int $rampMin): void
    {
        $steps = max(1, (int) round($rampMin * 60000 / self::RAMP_MS));
        $rt = $this->readRt();
        $rt['ramp'] = ['target' => $target, 'cur' => 0, 'step' => max(1, (int) ceil($target / $steps))];
        $this->writeRt($rt);
        $drv = $this->driver();
        if ($drv instanceof IAudioRenderer) {
            $drv->setVolume(0);
        }
        $this->SetTimerInterval(self::TIMER_RAMP, self::RAMP_MS);
    }

    public function RunRamp(): void
    {
        $rt = $this->readRt();
        $r = $rt['ramp'] ?? null;
        $drv = $this->driver();
        if (!is_array($r) || !$drv instanceof IAudioRenderer) {
            $this->SetTimerInterval(self::TIMER_RAMP, 0);
            return;
        }
        $cur = min((int) $r['target'], (int) $r['cur'] + (int) $r['step']);
        if ((bool) $this->cfgVal('armed', false)) {
            $drv->setVolume($cur);
        }
        if ($cur >= (int) $r['target']) {
            $this->SetTimerInterval(self::TIMER_RAMP, 0);
            unset($rt['ramp']);
        } else {
            $rt['ramp']['cur'] = $cur;
        }
        $this->writeRt($rt);
    }

    private function mgmtConfigureSchedule(array $args): array
    {
        $s = (array) ($this->cfg()['schedule'] ?? []);
        foreach (['enabled', 'sourceKind', 'sourceId', 'volume', 'rampMin', 'powerOffEnd', 'quietFrom', 'quietTo', 'quietCapVol'] as $k) {
            if (array_key_exists($k, $args)) {
                $s[$k] = $args[$k];
            }
        }
        $this->store()->patch('config', ['schedule' => $s]);
        return ['ok' => true, 'schedule' => $this->scheduleCfg()];
    }

    private function mgmtSetSleep(array $args): array
    {
        $min = max(1, (int) ($args['minutes'] ?? 30));
        $this->SetTimerInterval(self::TIMER_SLEEP, $min * 60000);
        $rt = $this->readRt();
        $rt['sleepUntil'] = time() + $min * 60;
        $this->writeRt($rt);
        return ['ok' => true, 'minutes' => $min, 'until' => date('H:i', $rt['sleepUntil'])];
    }

    public function RunSleep(): void
    {
        $this->SetTimerInterval(self::TIMER_SLEEP, 0);
        $rt = $this->readRt();
        unset($rt['sleepUntil']);
        $this->writeRt($rt);
        $drv = $this->driver();
        if ($drv instanceof IAudioRenderer && (bool) $this->cfgVal('armed', false)) {
            $drv->stop();
            $this->applyPower(false, $drv);
        } else {
            $this->SendDebug('HSAU.sleep', 'Schatten: WUERDE stoppen (Sleep)', 0);
        }
    }

    private function mgmtComputeProbe(): array
    {
        $sc = $this->scheduleCfg();
        $now = time();
        $val = $this->scheduleValueAt($now, 'Standard');
        return [
            'ok'          => true,
            'schedule'    => $sc,
            'scheduleVal' => $val,
            'onNow'       => is_numeric($val) && (float) $val > 0,
            'volumeCapNow' => $this->ruleVolumeCap($sc, (int) $sc['volume']),
            'armed'       => (bool) $this->cfgVal('armed', false),
            'sleepUntil'  => ($this->readRt()['sleepUntil'] ?? null),
        ];
    }

    // ==================================================================
    // Verwaltung (mgmt)
    // ==================================================================

    protected function mgmt(string $op, array $args, array $ctx): array
    {
        switch ($op) {
            case 'configureDriver':
                return $this->mgmtConfigureDriver($args, $ctx);
            case 'getConfig':
                return ['ok' => true, 'config' => $this->cfg()];
            case 'validate':
                return $this->mgmtValidate();
            case 'driverProbe':
                return $this->mgmtDriverProbe();
            case 'topology':
                return $this->mgmtTopology();
            case 'setArmed':
                @\IPS_SetProperty($this->InstanceID, 'Armed', (bool) ($args['armed'] ?? false));
                @\IPS_ApplyChanges($this->InstanceID);
                return ['ok' => true, 'armed' => $this->armed()];
            case 'migrateConfig':
                return $this->migrateConfig();
            case 'importLegacy':
                return $this->mgmtImportLegacy($args);
            case 'group':
                return $this->mgmtGroup($args);
            case 'ungroup':
                return $this->mgmtUngroup();
            case 'setGroupVolume':
                return $this->mgmtSetGroupVolume($args);
            case 'seek':
                return $this->mgmtSeek($args);
            case 'queue':
                return $this->mgmtQueue($args);
            case 'playQueueIndex':
                return $this->mgmtPlayQueueIndex($args);
            case 'queueRemove':
                return $this->mgmtQueueRemove($args);
            case 'queueClear':
                return $this->mgmtQueueClear();
            case 'playSource':
                return $this->mgmtPlaySource($args);
            case 'updateProfile':
                return $this->mgmtUpdateProfile($args, $ctx);
            case 'getSchedule':
                return $this->mgmtGetSchedule($args);
            case 'configureSchedule':
                return $this->mgmtConfigureSchedule($args);
            case 'sources':
                return $this->mgmtSources();
            case 'wake':
                return $this->mgmtWake($args);
            case 'setSleep':
                return $this->mgmtSetSleep($args);
            case 'cancelSleep':
                $this->SetTimerInterval(self::TIMER_SLEEP, 0);
                $rt = $this->readRt(); unset($rt['sleepUntil']); $this->writeRt($rt);
                return ['ok' => true];
            case 'computeProbe':
                return $this->mgmtComputeProbe();
            case 'radioNow':
                return $this->mgmtRadioNow();
            case 'playDirect':
                return $this->mgmtPlayDirect($args);
            case 'radioStations':
                // Logo und Herkunft mitgeben: die Oberflaeche soll eigene Sender kenntlich
                // machen koennen und braucht das Bild, ohne es selbst zu wissen.
                $list = [];
                foreach (RadioNow::all() as $k => $s) {
                    $list[] = [
                        'key'   => $k,
                        'title' => (string) ($s['title'] ?? $k),
                        'logo'  => (string) ($s['logo'] ?? ''),
                        'eigen' => !empty($s['eigen']),
                    ];
                }
                return ['ok' => true, 'stations' => $list];
            case 'playContent':
                return $this->mgmtPlayContent($args);
            case 'playContainer':
                return $this->mgmtPlayContainer($args);
            case 'cancelEnqueue':
                return $this->mgmtCancelEnqueue();
            default:
                return parent::mgmt($op, $args, $ctx);
        }
    }

    /**
     * Treiber/Bindung schreiben. Struktur (GenericBoundAudioRenderer):
     *   driver, bind{transport,volume,mute,repeat,shuffle,position,source{...}},
     *   reflect{...}, group{...}, power{...}, caps{...}. Immer Variable ODER Skript.
     */
    private function mgmtConfigureDriver(array $args, array $ctx): array
    {
        $driver = (string) ($args['driver'] ?? 'generic-audio');
        if (!DriverFactory::has($driver)) {
            throw new ContractException('unbekannter Treiber: ' . $driver);
        }
        $config = ['driver' => $driver];
        foreach (['bind', 'reflect', 'group', 'power', 'caps'] as $k) {
            if (isset($args[$k]) && is_array($args[$k])) {
                $config[$k] = $args[$k];
            }
        }
        // VERBINDUNGSDATEN der nativen Treiber (sonos-upnp: Player-Adresse + RINCON-Kennung,
        // heos: Host). Ohne sie war der native Treiber zwar waehlbar, aber adresslos - er
        // meldete Faehigkeiten und lieferte trotzdem keinen Zustand. Skalare Werte, deshalb
        // in der Schleife oben nicht erfasst.
        foreach (['host', 'rincon', 'uid'] as $k) {
            if (isset($args[$k]) && is_scalar($args[$k]) && (string) $args[$k] !== '') {
                $config[$k] = (string) $args[$k];
            }
        }
        if (isset($args['port'])) {
            $config['port'] = max(1, (int) $args['port']);
        }
        if (!empty($ctx['dryrun'])) {
            return ['ok' => true, 'dryrun' => true, 'config' => $config];
        }
        $this->applyConfigProperties($config, true); // driver->Property, Rest->Store; ApplyChanges zieht Treiber/Refs/Links neu
        return ['ok' => true, 'config' => $this->cfg(), 'driverActive' => $this->driver() instanceof IAudioRenderer];
    }

    /**
     * Aus einer IPSSonos-Raum-Dummy-Instanz die Idents aufloesen und als generic-audio-
     * Bindung uebernehmen (Schatten, armed=false). KEINE harte Abhaengigkeit — die
     * Loeschbarkeit von IPSSonos entsteht spaeter beim Treiberwechsel auf sonos-upnp.
     */
    private function mgmtImportLegacy(array $args): array
    {
        $room = (int) ($args['roomInstanceId'] ?? 0);
        if ($room <= 0 || !function_exists('IPS_InstanceExists') || !@\IPS_InstanceExists($room)) {
            throw new ContractException('roomInstanceId (IPSSonos-Raum) fehlt/ungueltig');
        }
        $id = function (string $ident) use ($room): int {
            return (int) (@\IPS_GetObjectIDByIdent($ident, $room) ?: 0);
        };
        $transport = $id('TRANSPORT');
        if ($transport <= 0) {
            throw new ContractException('TRANSPORT auf #' . $room . ' nicht gefunden — kein IPSSonos-Raum?');
        }
        // IPSSonos_Transport-Codes: 0=Prev,1=Play,2=Pause,3=Stop,4=Next.
        $config = [
            'driver' => 'generic-audio',
            'armed'  => false,
            'bind' => [
                'transport' => ['varId' => $transport,
                    'map' => ['play' => 1, 'pause' => 2, 'stop' => 3, 'next' => 4, 'prev' => 0]],
                'volume'   => ['varId' => $id('VOLUME')],
                'mute'     => ['varId' => $id('MUTE')],
                'repeat'   => ['varId' => $id('REPEAT'), 'map' => [0 => 0, 1 => 1, 2 => 2]],
                'shuffle'  => ['varId' => $id('SHUFFLE')],
                'position' => ['varId' => $id('POSITIONP')],
                'source'   => [
                    'favorite' => ['varId' => $id('FAVORITE')],
                    'radio'    => ['varId' => $id('RADIOSTATION')],
                    'playlist' => ['varId' => $id('PLAYLIST')],
                ],
            ],
            'reflect' => [
                'title'        => $id('TITLE'),
                'artist'       => $id('ARTIST'),
                'album'        => $id('ALBUM'),
                'albumArtist'  => $id('ALBUMARTIST'),
                'coverUri'     => $id('COVERURI'),
                'positionTime' => $id('POSITION'),
                'duration'     => $id('DURATION'),
                // IPSSonos-TRANSPORT: 1=Play, 2=Pause, 3=Stop. Nur die 1 heisst Wiedergabe -
                // ohne diese Angabe galt auch Stop als "spielt" (Bool-Cast von 3).
                'playState'    => ['varId' => $transport, 'playValues' => [1]],
                'volume'       => $id('VOLUME'),
                'mute'         => $id('MUTE'),
                'repeat'       => $id('REPEAT'),
                'shuffle'      => $id('SHUFFLE'),
            ],
            // Power = IPSSonos ROOMPOWER (bool-Variable).
            'power' => ['mode' => 'var', 'varId' => $id('ROOMPOWER')],
            // Multiroom ueber ChangeMasterSlave #<ID> + globale MASTERRINCON/NAME.
            'group' => [
                'mode'              => 'script',
                'scriptId'         => (int) ($args['groupScriptId'] ?? 32666),
                'rinconVarId'      => $id('RINCON'),
                'masterVarId'      => $id('B_MASTER'),
                'slaveVarId'       => $id('B_SLAVE'),
                'masterRinconVarId' => (int) ($args['masterRinconVarId'] ?? 37837),
                'masterNameVarId'  => (int) ($args['masterNameVarId'] ?? 36753),
            ],
        ];
        $this->applyConfigProperties($config, true); // driver->Property, Rest->Store; ApplyChanges zieht Treiber/Refs/Links neu
        return ['ok' => true, 'roomInstanceId' => $room, 'transportVarId' => $transport,
            'driverActive' => $this->driver() instanceof IAudioRenderer, 'armed' => false];
    }

    private function mgmtGroup(array $args): array
    {
        $drv = $this->driver();
        if (!$drv instanceof IAudioRenderer) {
            return ['ok' => false, 'error' => 'kein Treiber'];
        }
        $coord = (string) ($args['coordinatorUid'] ?? '');
        $members = array_values(array_map('strval', (array) ($args['memberUids'] ?? [])));
        if ($coord === '') {
            throw new ContractException('coordinatorUid fehlt');
        }
        if (!(bool) $this->cfgVal('armed', false)) {
            return ['ok' => true, 'armed' => false, 'note' => 'Schatten: WUERDE gruppieren', 'coordinator' => $coord, 'members' => $members];
        }
        $drv->setGroupMembers($coord, $members);
        return ['ok' => true, 'coordinator' => $coord, 'members' => $members];
    }

    private function mgmtUngroup(): array
    {
        $drv = $this->driver();
        if (!$drv instanceof IAudioRenderer) {
            return ['ok' => false, 'error' => 'kein Treiber'];
        }
        $self = '';
        if ($drv instanceof IAudioStateReadable) {
            $self = (string) ($drv->readGroup()['memberUids'][0] ?? '');
        }
        if (!(bool) $this->cfgVal('armed', false)) {
            return ['ok' => true, 'armed' => false, 'note' => 'Schatten: WUERDE Gruppe trennen'];
        }
        $drv->setGroupMembers($self, $self !== '' ? [$self] : []);
        return ['ok' => true, 'self' => $self];
    }

    private function mgmtSetGroupVolume(array $args): array
    {
        $drv = $this->driver();
        if (!$drv instanceof IAudioRenderer) {
            return ['ok' => false, 'error' => 'kein Treiber'];
        }
        $pct = max(0, min(100, (int) ($args['volume'] ?? 0)));
        if (!(bool) $this->cfgVal('armed', false)) {
            return ['ok' => true, 'armed' => false, 'note' => 'Schatten', 'volume' => $pct];
        }
        $drv->setGroupVolume($pct);
        return ['ok' => true, 'volume' => $pct];
    }

    /**
     * Springen (Position in %) ueber die native RequestAction: bei scharfem Treiber
     * uebersetzt applyControl die Prozent in Sekunden und ruft $drv->seek(); ohne
     * (scharfen) Treiber wird nur die Position-Statusvariable optimistisch gesetzt.
     */
    private function mgmtSeek(array $args): array
    {
        $pct = max(0, min(100, (int) ($args['percent'] ?? 0)));
        $this->RequestAction('Position', $pct);
        return ['ok' => true, 'percent' => $pct, 'armed' => (bool) $this->cfgVal('armed', false)];
    }

    /**
     * WARTESCHLANGE lesen. Nicht jeder Treiber hat eine: Radio-Streams und die HEOS-Bruecke
     * kennen keine Queue. Statt einen Fehler zu werfen, wird das ehrlich gemeldet
     * (supported=false) - die Kachel zeigt dann die Senderliste statt einer leeren Liste.
     */
    private function mgmtQueue(array $args): array
    {
        $drv = $this->driver();
        if (!is_object($drv) || !method_exists($drv, 'queueList')) {
            return ['ok' => true, 'supported' => false, 'items' => [], 'current' => 0, 'total' => 0,
                    'note' => 'Dieser Treiber fuehrt keine Warteschlange'];
        }
        $off = max(0, (int) ($args['offset'] ?? 0));
        $lim = max(1, min(200, (int) ($args['limit'] ?? 60)));
        try {
            $q = $drv->queueList($off, $lim);
        } catch (\Throwable $e) {
            return ['ok' => false, 'supported' => true, 'error' => $e->getMessage(), 'items' => []];
        }
        return ['ok' => true, 'supported' => true,
                'items' => $q['items'] ?? [], 'current' => (int) ($q['current'] ?? 0),
                'total' => (int) ($q['total'] ?? 0)];
    }

    /**
     * Einen Titel aus der Warteschlange entfernen. Wirkt am Zuspieler, faellt also
     * unter das Scharf-Gate. Der Index ist 0-basiert wie in mgmtQueue/queueList.
     */
    private function mgmtQueueRemove(array $args): array
    {
        $drv = $this->queueDriver();
        if (!$drv instanceof IAudioQueue) {
            return ['ok' => false, 'error' => 'Treiber fuehrt keine Warteschlange'];
        }
        if (!$this->armed()) {
            return ['ok' => false, 'armed' => false, 'note' => 'Schatten-Modus: nicht gesendet'];
        }
        $idx = (int) ($args['index'] ?? -1);
        if ($idx < 0) {
            return ['ok' => false, 'error' => 'index fehlt'];
        }
        $ok = $drv->removeFromQueue($idx);
        return $ok ? ['ok' => true, 'index' => $idx]
                   : ['ok' => false, 'index' => $idx, 'error' => 'Zuspieler hat nicht bestaetigt'];
    }

    /** Ganze Warteschlange leeren. Wirkt am Zuspieler -> Scharf-Gate. */
    private function mgmtQueueClear(): array
    {
        $drv = $this->queueDriver();
        if (!$drv instanceof IAudioQueue) {
            return ['ok' => false, 'error' => 'Treiber fuehrt keine Warteschlange'];
        }
        if (!$this->armed()) {
            return ['ok' => false, 'armed' => false, 'note' => 'Schatten-Modus: nicht gesendet'];
        }
        $drv->clearQueue();
        return ['ok' => true];
    }

    /** Spur der Warteschlange anspringen. Faehrt real - deshalb am Scharf-Gate vorbei geprueft. */
    private function mgmtPlayQueueIndex(array $args): array
    {
        $drv = $this->driver();
        if (!is_object($drv) || !method_exists($drv, 'playQueueIndex')) {
            return ['ok' => false, 'error' => 'Treiber kennt keine Warteschlange'];
        }
        if (!$this->armed()) {
            return ['ok' => false, 'armed' => false, 'note' => 'Schatten-Modus: nicht gesendet'];
        }
        $idx = max(0, (int) ($args['index'] ?? 0));
        $drv->playQueueIndex($idx);
        return ['ok' => true, 'index' => $idx];
    }

    private function mgmtPlaySource(array $args): array
    {
        $drv = $this->driver();
        if (!$drv instanceof IAudioRenderer) {
            return ['ok' => false, 'error' => 'kein Treiber'];
        }
        $kind = (string) ($args['kind'] ?? AudioSourceRef::KIND_FAVORITE);
        $refId = (string) ($args['id'] ?? '');
        if (!(bool) $this->cfgVal('armed', false)) {
            return ['ok' => true, 'armed' => false, 'note' => 'Schatten', 'kind' => $kind, 'id' => $refId];
        }
        $drv->playSource(new AudioSourceRef($kind, $refId, (string) ($args['title'] ?? '')));
        return ['ok' => true, 'kind' => $kind, 'id' => $refId];
    }

    private function mgmtDriverProbe(): array
    {
        $drv = $this->driver();
        if (!$drv instanceof IAudioRenderer) {
            return ['ok' => true, 'driverActive' => false];
        }
        $res = ['ok' => true, 'driverActive' => true, 'capabilities' => $drv->capabilities()->toArray()];
        if ($drv instanceof IAudioStateReadable) {
            $res['state'] = $drv->readState()->toArray();
            $res['group'] = $drv->readGroup();
        }
        return $res;
    }

    private function mgmtUpdateProfile(array $args, array $ctx): array
    {
        $variant = (string) ($args['variant'] ?? 'Standard');
        $day = (int) ($args['day'] ?? -1);
        if ($day < 0 || $day > 6) {
            throw new ContractException('day muss 0..6 sein');
        }
        $slots = (isset($args['slots']) && is_array($args['slots'])) ? $args['slots'] : [];
        $clean = [];
        foreach ($slots as $s) {
            if (!is_array($s) || !isset($s['end'])) {
                continue;
            }
            $entry = ['end' => max(1, min(1440, (int) $s['end'])),
                      'val' => max(0, min(100, (int) round((float) ($s['val'] ?? 0))))];
            if (isset($s['anchor']) && $s['anchor'] !== '' && $s['anchor'] !== null) {
                $entry['anchor'] = (string) $s['anchor'];
                $entry['offset'] = (int) ($s['offset'] ?? 0);
            }
            $clean[] = $entry;
        }
        if (!empty($ctx['dryrun'])) {
            return ['ok' => true, 'dryrun' => true, 'variant' => $variant, 'day' => $day, 'slots' => $clean];
        }
        $this->schedules()->setSlots($variant, $day, $clean);
        return ['ok' => true, 'variant' => $variant, 'day' => $day, 'slots' => $this->schedules()->getSlots($variant, $day)];
    }

    private function mgmtGetSchedule(array $args): array
    {
        $variant = (string) ($args['variant'] ?? 'Standard');
        $week = [];
        for ($d = 0; $d < 7; $d++) {
            $week[$d] = $this->schedules()->getSlots($variant, $d);
        }
        return ['ok' => true, 'variant' => $variant, 'week' => $week, 'activeVariant' => 'Standard',
            'variants' => $this->scheduleVariants(), 'sunEvents' => $this->sunEvents(time()),
            'anchors' => array_keys(\Hoep\HomeSuite\SunTimes::ANCHORS)];
    }

    // ==================================================================
    // Radio: laufender Titel + Song-Cover (RadioNow) + HQ-Direktstream
    // ==================================================================

    /** Speaker-IP/RINCON aus der gebundenen Raum-Instanz (bind.transport-Variable -> Parent). */
    /**
     * Adresse der eigenen Box: [IP, RINCON].
     *
     * Rangfolge:
     *   1. eigene Eigenschaften (SpeakerIp/SpeakerRincon) - der Normalfall,
     *   2. Topologie: stimmt die hinterlegte IP nicht mehr, liefert sie ein beliebiger
     *      erreichbarer Player anhand des RINCON neu - die Eigenschaft wird nachgezogen,
     *   3. der IPSSonos-Altbaum, solange eine Zone noch keine eigenen Werte hat.
     *
     * Der RINCON ist die dauerhafte Kennung, die IP nicht: sie kommt per DHCP. Deshalb
     * wird sie geprueft statt geglaubt - und dass mehrere Boxen ausgesteckt sind, ist
     * dabei der Normalfall und kein Fehler: gefragt wird, wer gerade antwortet.
     */
    private function resolveSpeaker(): array
    {
        $cfg = $this->cfg();
        $ip  = trim((string) ($cfg['speakerIp'] ?? ''));
        $rin = trim((string) ($cfg['speakerRincon'] ?? ''));

        if ($rin !== '') {
            $topo = $this->sonosTopologie();
            if (isset($topo[$rin]['ip']) && $topo[$rin]['ip'] !== '' && $topo[$rin]['ip'] !== $ip) {
                $neu = (string) $topo[$rin]['ip'];
                $this->LogMessage('HS.Audio: Sonos-Adresse geaendert '
                    . ($ip === '' ? '(leer)' : $ip) . ' -> ' . $neu, KL_MESSAGE);
                @\IPS_SetProperty($this->InstanceID, 'SpeakerIp', $neu);
                @\IPS_ApplyChanges($this->InstanceID);
                $ip = $neu;
            }
        }
        if ($ip !== '' && $rin !== '') {
            return [$ip, $rin];
        }

        // Rueckfall auf den Altbaum, solange die Zone keine eigenen Werte hat.
        $tv = (int) ((($cfg['bind'] ?? [])['transport']['varId']) ?? 0);
        if ($tv <= 0 || !function_exists('IPS_GetParent')) {
            return [$ip, $rin];
        }
        $room = (int) @\IPS_GetParent($tv);
        if ($room <= 0) {
            return [$ip, $rin];
        }
        $aIp  = (string) @\GetValue((int) (@\IPS_GetObjectIDByIdent('IPADDR', $room) ?: 0));
        $aRin = (string) @\GetValue((int) (@\IPS_GetObjectIDByIdent('RINCON', $room) ?: 0));
        return [$ip !== '' ? $ip : $aIp, $rin !== '' ? $rin : $aRin];
    }

    /**
     * Zeigt, was die Anlage ueber diese Zone weiss - und was hinterlegt ist.
     *
     * Schreibt bewusst nichts: wer eine Adresse aendert, soll das sehen. Automatisch
     * nachgezogen wird nur im Betrieb, wenn der RINCON eindeutig unter einer anderen
     * IP antwortet.
     *
     * @return array<string,mixed>
     */
    private function mgmtTopology(): array
    {
        $cfg = $this->cfg();
        $rin = trim((string) ($cfg['speakerRincon'] ?? ''));
        $ip  = trim((string) ($cfg['speakerIp'] ?? ''));
        $rt  = $this->readRt();
        unset($rt['topoCache']);            // frisch fragen, nicht aus dem Puffer
        $this->writeRt($rt);
        $topo = $this->sonosTopologie();
        $ich  = ($rin !== '' && isset($topo[$rin])) ? $topo[$rin] : null;
        return [
            'ok'         => $topo !== [],
            'hinterlegt' => ['rincon' => $rin, 'ip' => $ip],
            'gefunden'   => $ich,
            'stimmt'     => $ich !== null && (string) $ich['ip'] === $ip,
            'erreichbar' => count($topo),
            'haushalt'   => array_map(static function ($u, $a) {
                return ['rincon' => $u, 'ip' => $a['ip'], 'name' => $a['name'], 'unsichtbar' => $a['invisible']];
            }, array_keys($topo), array_values($topo)),
        ];
    }

    /**
     * UUID => Angaben aller Player des Haushalts, fuenf Minuten gepuffert.
     *
     * Gefragt wird der Reihe nach: der eingetragene Master, die eigene letzte Adresse,
     * dann die Adressen der uebrigen Zonen. Der erste, der antwortet, kennt ohnehin alle.
     *
     * @return array<string,array{ip:string,name:string,invisible:bool}>
     */
    private function sonosTopologie(): array
    {
        $rt = $this->readRt();
        $c  = $rt['topoCache'] ?? null;
        if (is_array($c) && (time() - (int) ($c['ts'] ?? 0)) < 300 && !empty($c['data'])) {
            return (array) $c['data'];
        }
        $cfg   = $this->cfg();
        $hosts = [];
        foreach ([trim((string) ($cfg['masterIp'] ?? '')), trim((string) ($cfg['speakerIp'] ?? ''))] as $h) {
            if ($h !== '' && !in_array($h, $hosts, true)) { $hosts[] = $h; }
        }
        foreach ($this->andereZonenIps() as $h) {
            if (!in_array($h, $hosts, true)) { $hosts[] = $h; }
        }
        $topo = [];
        foreach (array_slice($hosts, 0, 6) as $h) {
            try {
                $drv = DriverFactory::create('sonos-upnp', ['host' => $h, 'timeout' => 2000]);
                if ($drv instanceof SonosUpnp) {
                    $topo = $drv->zoneGroupTopology();
                }
            } catch (\Throwable $e) {
                $topo = [];
            }
            if ($topo !== []) { break; }
        }
        if ($topo !== []) {
            $rt['topoCache'] = ['ts' => time(), 'data' => $topo];
            $this->writeRt($rt);
        }
        return $topo;
    }

    /** Hinterlegte Adressen der uebrigen Audiozonen - Ausweichkandidaten fuer die Topologie. */
    private function andereZonenIps(): array
    {
        $out = [];
        foreach (\IPS_GetInstanceList() as $iid) {
            if ($iid === $this->InstanceID) { continue; }
            if ((\IPS_GetInstance($iid)['ModuleInfo']['ModuleName'] ?? '') !== 'AudioZone') { continue; }
            $ip = '';
            try { $ip = trim((string) @\IPS_GetProperty($iid, 'SpeakerIp')); } catch (\Throwable $e) {}
            if ($ip !== '') { $out[] = $ip; }
        }
        return $out;
    }

    /** Radio "was laeuft": aktueller Titel (streamContent) + Song-Cover, 20s gecacht (RtState). */
    private function mgmtRadioNow(): array
    {
        $rt = $this->readRt();
        $c  = $rt['radioCache'] ?? null;
        if (is_array($c) && (time() - (int) ($c['ts'] ?? 0)) < 20) {
            return ['ok' => true, 'cached' => true] + (array) $c['data'];
        }
        [$ip, $rin] = $this->resolveSpeaker();
        $data = ['isRadio' => false, 'station' => '', 'key' => null, 'artist' => '', 'title' => '', 'cover' => '', 'logo' => '', 'coverIsLogo' => false, 'isTalk' => true, 'reachable' => false];
        if ($ip !== '') {
            try {
                $drv = DriverFactory::create('sonos-upnp', ['host' => $ip, 'rincon' => $rin, 'timeout' => 2500]);
                if ($drv instanceof SonosUpnp) {
                    $info = $drv->radioInfo();
                    $data['reachable'] = true;
                    $data['isRadio']   = (bool) $info['isRadio'];
                    $data['station']   = (string) $info['station'];
                    $data['key']  = RadioNow::detect($info['station'] . ' ' . $info['uri']);
                    $data['logo'] = RadioNow::logoOf($data['key']);
                    // Song aus dem Player-streamContent; wenn leer/Wort -> Direktstream-ICY als
                    // Fallback (dort steht der Song oft, auch waehrend TuneIn-Luecken).
                    $song = RadioNow::songParse((string) $info['streamContent'], (string) $info['station']);
                    if ($song['isTalk'] && $data['key'] !== null) {
                        $n = RadioNow::now($data['key'], false);
                        if (!$n['isTalk']) {
                            $song = ['artist' => $n['artist'], 'title' => $n['title'], 'isTalk' => false];
                        }
                    }
                    $data['artist'] = $song['artist'];
                    $data['title']  = $song['title'];
                    $data['isTalk'] = $song['isTalk'];
                    if (!$song['isTalk']) {
                        $data['cover'] = RadioNow::cover($song['artist'], $song['title']);
                    }
                    // Kein Song-Cover (Nachrichten/Wort ODER Song ohne Treffer) -> Sender-Logo.
                    if ($data['cover'] === '' && $data['logo'] !== '') {
                        $data['cover'] = $data['logo'];
                        $data['coverIsLogo'] = true;
                    }
                }
            } catch (\Throwable $e) {
                $this->SendDebug('HSAU.radioNow', $e->getMessage(), 0);
            }
        }
        $rt['radioCache'] = ['ts' => time(), 'data' => $data];
        $this->writeRt($rt);
        return ['ok' => true] + $data;
    }

    /**
     * Treiber fuer Warteschlangen-Betrieb besorgen.
     *
     * Drei Stufen, in dieser Reihenfolge:
     *  1. der GEBUNDENE Treiber, wenn er Warteschlangen beherrscht (IAudioQueue),
     *  2. sonst ein sonos-upnp aus IP und RINCON des Raums - das ist die Kruecke, die
     *     frueher hart in mgmtPlayContent stand und den konfigurierten Treiber umging,
     *  3. sonst null: dann antwortet der Aufrufer ehrlich mit "nicht unterstuetzt",
     *     statt so zu tun, als waere etwas passiert.
     */
    private function queueDriver(): ?IAudioQueue
    {
        $drv = $this->driver();
        if ($drv instanceof IAudioQueue) {
            return $drv;
        }
        [$ip, $rin] = $this->resolveSpeaker();
        if ($ip === '') {
            return null;
        }
        $d = DriverFactory::create('sonos-upnp', ['host' => $ip, 'rincon' => $rin, 'timeout' => 3000]);
        return ($d instanceof IAudioQueue) ? $d : null;
    }

    /** Laufendes Einreihen abbrechen: Restliste verwerfen, Timer aus. */
    private function mgmtCancelEnqueue(): array
    {
        $rt   = $this->readRt();
        $rest = count((array) (($rt['enq'] ?? [])['refs'] ?? []));
        unset($rt['enq'], $rt['enqBusy']);
        $this->writeRt($rt);
        $this->SetTimerInterval(self::TIMER_ENQ, 0);
        return ['ok' => true, 'verworfen' => $rest];
    }

    /**
     * Naechster Block der laufenden Einreihung.
     *
     * Wird vom Timer gerufen und haengt ENQ_BLOCK Titel an, bis nichts mehr uebrig ist.
     * Zwei Sicherungen: ohne Treiber oder ohne Restliste schaltet der Timer sich ab, und
     * eine Einreihung, die aelter als zehn Minuten ist, gilt als verwaist und wird
     * verworfen - sonst wuerde ein Neustart mitten im Lauf sie ewig weiterschleppen.
     */
    public function RunEnqueue(): void
    {
        // Der Timer laeuft DURCH und wird erst abgeschaltet, wenn nichts mehr aussteht.
        // Zwei Versuche zuvor sind gescheitert: bei 250 ms Takt liefen mehrere Durchgaenge
        // gleichzeitig und reihten doppelt ein (240 statt 200 Titel); das Abschalten am
        // Anfang mit Wiederanschalten am Ende desselben Laufs kam beim Kernel nicht an -
        // der Timer blieb aus und 177 Titel lagen liegen. Jetzt haelt allein die Sperre
        // die Durchgaenge auseinander, und der Takt ist laenger als ein Block braucht.
        // KEINE IPS-Sperre mehr: eine solche blieb nach einem abgebrochenen Lauf haengen und
        // liess sich weder freigeben noch neu belegen - danach kehrte jeder Durchgang sofort
        // zurueck und 177 Titel blieben liegen. Der Merker im Laufzeitspeicher heilt sich
        // selbst: nach 30 s gilt ein Lauf als tot und der naechste uebernimmt.
        $rt   = $this->readRt();
        $seit = (int) ($rt['enqBusy'] ?? 0);
        if ($seit > 0 && (time() - $seit) < 30) {
            return;
        }
        $rt['enqBusy'] = time();
        $this->writeRt($rt);
        try {
            // In eigenem Thread: bis zum Ende durchziehen. Die Obergrenze ist der Deckel
            // aus playContainer, mehr als ein paar Dutzend Bloecke kann es nie geben.
            for ($i = 0; $i < 60; $i++) {
                if (!$this->enqueueStep()) {
                    break;
                }
            }
        } finally {
            $rt = $this->readRt();
            unset($rt['enqBusy']);
            $this->writeRt($rt);
        }
    }

    private function enqueueStep(): bool
    {
        $rt   = $this->readRt();
        $enq  = (array) ($rt['enq'] ?? []);
        $refs = (array) ($enq['refs'] ?? []);
        if ($refs === [] || (time() - (int) ($enq['ts'] ?? 0)) > 600) {
            unset($rt['enq']);
            $this->writeRt($rt);
            return false;
        }
        $drv = $this->queueDriver();
        if ($drv === null) {
            unset($rt['enq']);
            $this->writeRt($rt);
            return false;
        }
        $block = array_splice($refs, 0, self::ENQ_BLOCK);
        foreach ($block as $r) {
            $c = ContentRef::fromArray((array) $r);
            if ($c->uri !== '') {
                $drv->addToQueue($c->toSourceRef());
            }
        }
        if ($refs === []) {
            unset($rt['enq']);
            $this->writeRt($rt);
            $this->SetTimerInterval(self::TIMER_KICK, self::KICK_MS);
            return false;
        }
        $rt['enq'] = ['refs' => $refs, 'ts' => (int) ($enq['ts'] ?? time())];
        $this->writeRt($rt);
        return true;
    }

    /**
     * Eine ganze Sammlung abspielen oder an die Warteschlange anhaengen.
     *
     * Erwartet die bereits aufgeloeste Titelliste (der Hub kennt die Provider, dieses Modul
     * den Player - die Trennung bleibt). $args:
     *   tracks[]  ContentRef-Datensaetze in Abspielreihenfolge
     *   mode      'replace' (Vorgabe) oder 'append'
     *   dryRun    true = nur melden, was passieren wuerde; nichts senden
     *   title     Anzeigename der Sammlung (nur fuer die Rueckmeldung)
     */
    private function mgmtPlayContainer(array $args): array
    {
        $roh   = (array) ($args['tracks'] ?? []);
        $mode  = ((string) ($args['mode'] ?? 'replace') === 'append') ? 'append' : 'replace';
        $trock = (bool) ($args['dryRun'] ?? false);
        $titel = (string) ($args['title'] ?? '');

        $refs = [];
        foreach ($roh as $r) {
            $c = ContentRef::fromArray((array) $r);
            if ($c->uri !== '') {
                $refs[] = $c;
            }
        }
        if ($refs === []) {
            return ['ok' => false, 'error' => 'keine abspielbaren Titel'];
        }

        $drv = $this->queueDriver();
        if ($drv === null) {
            return ['ok' => false, 'error' => 'unsupported',
                    'note' => 'Dieser Player kann keine Warteschlange'];
        }

        // Gruppenmitglied: die eigene Warteschlange waere stumm, es spielt der Koordinator.
        // In dieser Stufe wird das gesperrt und gesagt - Umleiten kommt spaeter.
        $rolle = '';
        try {
            $g = $drv->readGroup();
            $rolle = (string) ($g['role'] ?? '');
        } catch (\Throwable $e) {
            $rolle = '';
        }
        if ($rolle === 'member') {
            return ['ok' => false, 'error' => 'group_member',
                    'note' => 'Dieser Raum ist Mitglied einer Gruppe - bitte im Koordinator abspielen'];
        }

        if ($trock) {
            return ['ok' => true, 'dryRun' => true, 'count' => count($refs), 'mode' => $mode,
                    'title' => $titel, 'note' => 'Trockenlauf: WUERDE ' . count($refs) . ' Titel einreihen'];
        }
        if (!(bool) $this->cfgVal('armed', false)) {
            return ['ok' => true, 'armed' => false, 'count' => count($refs), 'mode' => $mode,
                    'title' => $titel, 'note' => 'Schatten: WUERDE ' . count($refs) . ' Titel einreihen'];
        }

        // Haeppchenweise: die ersten Titel sofort, damit binnen etwa einer Sekunde Ton
        // kommt (gemessen 164 ms je Titel - 200 Titel am Stueck waeren 33 s Stille), der
        // Rest laeuft ueber den Zonen-Timer nach. Der Aufruf kehrt sofort zurueck.
        $sofort = array_slice($refs, 0, self::ENQ_FIRST);
        $rest   = array_slice($refs, self::ENQ_FIRST);
        $srcs   = array_map(static fn($c) => $c->toSourceRef(), $sofort);
        $n      = $drv->playSourceList($srcs, $mode);

        $rt = $this->readRt();
        unset($rt['radioCache']);
        if ($rest !== []) {
            // Ab hier wird IMMER angehaengt - das Leeren ist im ersten Block passiert.
            $rt['enq'] = ['refs' => array_map(static fn($c) => $c->toArray(), $rest), 'ts' => time()];
        } else {
            unset($rt['enq']);
        }
        $this->writeRt($rt);
        // Der Rest laeuft in einem EIGENEN Thread weiter. Ein dafuer registrierter Timer
        // wurde von diesem Kernel nicht in den Ablaufplan uebernommen (Faelligkeit wanderte
        // in die Vergangenheit, LastRun blieb leer) - IPS_RunScriptText startet dagegen
        // sofort einen Thread und kehrt zurueck, ganz ohne Zeitplanung.
        if ($rest !== [] && function_exists('IPS_RunScriptText')) {
            @\IPS_RunScriptText('<?php HSAU_RunEnqueue(' . $this->InstanceID . ');');
        }
        $this->SetTimerInterval(self::TIMER_KICK, self::KICK_MS);
        return ['ok' => true, 'count' => $n, 'wanted' => count($refs), 'mode' => $mode,
                'title' => $titel, 'queued' => count($rest)];
    }

    /** Aufgeloesten Bibliotheks-Inhalt (ContentRef) auf diesem Renderer abspielen. */
    private function mgmtPlayContent(array $args): array
    {
        $ref = ContentRef::fromArray((array) ($args['ref'] ?? []));
        if ($ref->uri === '') {
            return ['ok' => false, 'error' => 'ref ohne uri (erst ueber Hub mediaResolve aufloesen)'];
        }
        if (!(bool) $this->cfgVal('armed', false)) {
            return ['ok' => true, 'armed' => false, 'note' => 'Schatten: WUERDE abspielen', 'title' => $ref->title];
        }
        $drv = $this->queueDriver();
        if ($drv === null) {
            return ['ok' => false, 'error' => 'Speaker nicht aufloesbar'];
        }
        $drv->playSource($ref->toSourceRef());
        $rt = $this->readRt(); unset($rt['radioCache']); $this->writeRt($rt);
        return ['ok' => true, 'title' => $ref->title, 'provider' => $ref->provider, 'kind' => $ref->kind];
    }

    /** HQ-Direktstream eines Senders auf diesem Speaker spielen (statt TuneIn). */
    private function mgmtPlayDirect(array $args): array
    {
        $key = (string) ($args['station'] ?? '');
        $url = RadioNow::streamUrl($key);
        if ($url === '') {
            throw new ContractException('unbekannter Sender: ' . $key);
        }
        if (!(bool) $this->cfgVal('armed', false)) {
            return ['ok' => true, 'armed' => false, 'note' => 'Schatten: WUERDE direkt spielen', 'station' => $key];
        }
        [$ip, $rin] = $this->resolveSpeaker();
        if ($ip === '') {
            return ['ok' => false, 'error' => 'Speaker nicht aufloesbar'];
        }
        $title = (string) (RadioNow::all()[$key]['title'] ?? 'Radio');
        $drv = DriverFactory::create('sonos-upnp', ['host' => $ip, 'rincon' => $rin, 'timeout' => 3000]);
        $drv->playSource(new AudioSourceRef(AudioSourceRef::KIND_STATION, $key, $title, $url));
        // Cache invalidieren, damit der neue Titel sofort gezogen wird.
        $rt = $this->readRt(); unset($rt['radioCache']); $this->writeRt($rt);
        return ['ok' => true, 'station' => $key, 'title' => $title, 'url' => $url];
    }

    // ==================================================================
    // Loeschschutz + Health
    // ==================================================================

    /** RegisterReference nur auf real gebundene Variablen/Skripte (nie hart auf IPSSonos-IDs). */
    private function syncReferences(): void
    {
        if (!method_exists($this, 'GetReferenceList')) {
            return;
        }
        foreach ($this->GetReferenceList() as $ref) {
            @$this->UnregisterReference($ref);
        }
        $ids = [];
        $add = function ($id) use (&$ids) {
            $id = (int) $id;
            if ($id > 0) {
                $ids[$id] = true;
            }
        };
        $walk = function ($node) use (&$walk, &$add) {
            if (is_array($node)) {
                foreach ($node as $k => $v) {
                    if (is_array($v)) {
                        $walk($v);
                    } elseif (($k === 'varId' || $k === 'scriptId' || $k === 'scriptOn' || $k === 'scriptOff'
                        || $k === 'masterRinconVarId' || $k === 'masterNameVarId' || $k === 'rinconVarId'
                        || $k === 'masterVarId' || $k === 'slaveVarId') && is_int($v)) {
                        $add($v);
                    }
                }
            }
        };
        $cfg = $this->cfg();
        foreach (['bind', 'reflect', 'group', 'power'] as $k) {
            if (isset($cfg[$k]) && is_array($cfg[$k])) {
                $walk($cfg[$k]);
            }
        }
        foreach (array_keys($ids) as $id) {
            if (function_exists('IPS_ObjectExists') && @\IPS_ObjectExists($id)) {
                @$this->RegisterReference($id);
            }
        }
    }

    private function mgmtValidate(): array
    {
        $h = $this->computeHealth();
        return ['ok' => $h['ok'], 'health' => $h['text'], 'issues' => $h['issues'], 'config' => $this->cfg()];
    }

    private function computeHealth(): array
    {
        $cfg = $this->cfg();
        $drv = (string) ($cfg['driver'] ?? '');
        if ($drv === '') {
            return ['ok' => false, 'text' => 'inaktiv (kein Treiber)', 'issues' => ['kein Treiber']];
        }
        $issues = [];
        $bind = (array) ($cfg['bind'] ?? []);
        if ((int) (($bind['transport']['varId'] ?? 0)) <= 0 && empty($bind['transport']['scriptPlay'])) {
            $issues[] = 'Transport-Bindung fehlt';
        }
        if ((int) (($bind['volume']['varId'] ?? 0)) <= 0) {
            $issues[] = 'Volume-Bindung fehlt';
        }
        if (!($this->driver() instanceof IAudioRenderer)) {
            $issues[] = 'Treiber inaktiv';
        }
        if ($issues) {
            return ['ok' => false, 'text' => 'FEHLER: ' . implode(', ', $issues), 'issues' => $issues];
        }
        $armed = (bool) ($cfg['armed'] ?? false);
        return ['ok' => true, 'issues' => [],
            'text' => 'OK · ' . $drv . ($armed ? ' · scharf' : ' · Schatten-Modus')];
    }

    private function updateHealth(): void
    {
        @$this->RegisterVariableString('BindHealth', 'Bindung', '', 90);
        $h = $this->computeHealth();
        @$this->SetValue('BindHealth', (string) $h['text']);
    }

    // ==================================================================
    // Konsolen-Formular (Notfall/Diagnose; Verwaltung sonst im LVB)
    // ==================================================================

    // ==================================================================
    // Oeffentliche Scripting-Prozeduren (-> HSAU_Play / _SetVolume …)
    // Gilt via Vererbung identisch fuer AudioZoneBridged (HSAUX_).
    // Audio ist scharf (armed=true) -> Setter wirken real.
    // ==================================================================

    public function Play(): bool         { return $this->setControlValue('Transport', self::TR_PLAY); }
    public function Pause(): bool        { return $this->setControlValue('Transport', self::TR_PAUSE); }
    public function StopPlayback(): bool { return $this->setControlValue('Transport', self::TR_STOP); }
    public function Next(): bool         { return $this->setControlValue('Transport', self::TR_NEXT); }
    public function Previous(): bool     { return $this->setControlValue('Transport', self::TR_PREV); }

    public function SetVolume(int $Percent): bool { return $this->setControlValue('Volume', $Percent); }
    public function SetMute(bool $On): bool       { return $this->setControlValue('Mute', $On); }
    public function SetPower(bool $On): bool      { return $this->setControlValue('Power', $On); }
    public function SetRepeat(int $Mode): bool    { return $this->setControlValue('Repeat', $Mode); }
    public function SetShuffle(bool $On): bool    { return $this->setControlValue('Shuffle', $On); }
    public function Seek(int $Percent): bool      { return $this->setControlValue('Position', $Percent); }

    public function PlayFavorite(int $Index): bool { return $this->setControlValue('SourceFavorite', $Index); }
    public function PlayRadio(int $Index): bool    { return $this->setControlValue('SourceRadio', $Index); }
    public function PlayPlaylist(int $Index): bool { return $this->setControlValue('SourcePlaylist', $Index); }

    /** HQ-Direktstream (Sender-Key). */
    public function PlayDirectRadio(string $StationKey): bool
    {
        $r = json_decode($this->Manage(json_encode(['op' => 'playDirect', 'args' => ['station' => $StationKey]])), true);
        return is_array($r) && !empty($r['ok']);
    }
    public function SetSleep(int $Minutes): bool
    {
        $r = json_decode($this->Manage(json_encode(['op' => 'setSleep', 'args' => ['minutes' => $Minutes]])), true);
        return is_array($r) && !empty($r['ok']);
    }
    public function CancelSleep(): bool
    {
        $r = json_decode($this->Manage(json_encode(['op' => 'cancelSleep'])), true);
        return is_array($r) && !empty($r['ok']);
    }
    public function SetGroupVolume(int $Percent): bool
    {
        $r = json_decode($this->Manage(json_encode(['op' => 'setGroupVolume', 'args' => ['volume' => $Percent]])), true);
        return is_array($r) && !empty($r['ok']);
    }
    public function Ungroup(): bool
    {
        $r = json_decode($this->Manage(json_encode(['op' => 'ungroup'])), true);
        return is_array($r) && !empty($r['ok']);
    }
    public function SetArmed(bool $Armed): bool
    {
        $r = json_decode($this->Manage(json_encode(['op' => 'setArmed', 'args' => ['armed' => $Armed]])), true);
        return is_array($r) && (isset($r['armed']) ? (bool) $r['armed'] : (!empty($r['ok']) ? $Armed : false));
    }

    public function GetVolume(): int  { return (int) $this->GetControlValue('Volume'); }
    public function IsPlaying(): bool { return (bool) $this->GetControlValue('PlayState'); }
    public function IsOnline(): bool  { return (bool) $this->GetControlValue('Online'); }

    public function GetConfigurationForm()
    {
        $cfg = $this->cfg();
        $armed = (bool) ($cfg['armed'] ?? false);
        $h = $this->computeHealth();
        return json_encode(['elements' => [
            ['type' => 'Label', 'caption' => 'Audio/Media — Steuerung ueber generischen Treiber (Sonos im Uebergang '
                . 'ueber IPSSonos-Raum-Variablen; spaeter nativ sonos-upnp/heos). Verwaltung/Visu im LiveViewBuilder.'],
            ['type' => 'NumberSpinner', 'name' => 'QueryInterval', 'caption' => 'Abfrage-Intervall (s)'],
            ['type' => 'Label', 'caption' => 'Adresse der Box. Der RINCON ist dauerhaft, die IP kommt per DHCP - '
                . 'stimmt sie nicht mehr, holt die Zone sie sich ueber einen beliebigen erreichbaren Player zurueck.'],
            ['type' => 'RowLayout', 'items' => [
                ['type' => 'ValidationTextBox', 'name' => 'SpeakerRincon', 'caption' => 'RINCON'],
                ['type' => 'ValidationTextBox', 'name' => 'SpeakerIp', 'caption' => 'IP'],
                ['type' => 'ValidationTextBox', 'name' => 'MasterIp', 'caption' => 'Master (optional)'],
            ]],
            ['type' => 'Button', 'caption' => 'Adressen aus der Anlage pruefen', 'onClick' =>
                'echo HSAU_Manage($id, json_encode(["op"=>"topology"]));'],
            ['type' => 'SelectInstance', 'name' => 'cfgRoom', 'caption' => 'IPSSonos-Raum-Instanz (Import)'],
            ['type' => 'Button', 'caption' => 'Aus IPSSonos importieren (Schatten)', 'onClick' =>
                'echo HSAU_Manage($id, json_encode(["op"=>"importLegacy","args"=>["roomInstanceId"=>$cfgRoom]]));'],
            ['type' => 'Label', 'caption' => 'Status: ' . $h['text'] . ' · scharf: ' . ($armed ? 'JA (schaltet real)' : 'nein (Schatten-Modus)')],
            ['type' => 'RowLayout', 'items' => [
                ['type' => 'Button', 'caption' => 'Bindung pruefen', 'onClick' =>
                    'echo HSAU_Manage($id, json_encode(["op"=>"validate"]));'],
                ['type' => 'Button', 'caption' => 'Treiber-Status', 'onClick' =>
                    'echo HSAU_Manage($id, json_encode(["op"=>"driverProbe"]));'],
                ['type' => 'Button', 'caption' => 'Konfiguration lesen', 'onClick' =>
                    'echo HSAU_Manage($id, json_encode(["op"=>"getConfig"]));'],
            ]],
            ['type' => 'Label', 'caption' => 'Achtung: real geschaltet wird nur bei "scharf" (armed). Cutover ist ein eigener Schritt.'],
        ]]);
    }

    // ==================================================================
    // Helfer
    // ==================================================================

    // ==================================================================
    // Native Instanz-Properties (Symcon-Konzept). Nur die flachen Top-Level-
    // Felder (driver/armed) sind Properties; die tief verschachtelte Bindung
    // (bind/reflect/power/group/caps/schedule) bleibt im FabricStore.
    // ==================================================================
    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyString('Driver', '');
        $this->RegisterPropertyBoolean('Armed', false);
        $this->RegisterPropertyInteger('ConfigSchema', 0); // Migrations-Marker
        $this->RegisterPropertyInteger('QueryInterval', 5); // Abfrage-Intervall in SEKUNDEN (Default = REFRESH_MS/1000)
        // EIGENE ADRESSE DER BOX. Bis 19.09.2026 holte sich die Zone IP und RINCON aus dem
        // IPSSonos-Altbaum: ueber die gebundene Transport-Variable auf deren Elternobjekt
        // und von dort die Idents IPADDR/RINCON. Damit haette das Entfernen der alten
        // Bibliothek alle Zonen stillgelegt, obwohl sie laengst per UPnP selbst sprechen.
        $this->RegisterPropertyString('SpeakerIp', '');
        $this->RegisterPropertyString('SpeakerRincon', '');
        // Fester Ansprechpartner fuer die Topologie. Jeder erreichbare Player kennt den
        // ganzen Haushalt; ein benannter Master erspart das Durchprobieren im Normalfall.
        $this->RegisterPropertyString('MasterIp', '');
    }

    /**
     * Property lesen, die es vielleicht noch nicht gibt.
     *
     * Neu in Create() registrierte Properties entstehen auf einer LAUFENDEN Anlage erst
     * mit dem naechsten Reload - der Modulcode wird aber sofort gelesen. Ohne diesen
     * Umweg wirft jeder Abruf zwischen Dateiwechsel und Reload einen Fehler, und zwar
     * im Sekundentakt ueber alle Zonen (am 19.09.2026 genau so passiert).
     */
    private function propStr(string $name): string
    {
        try {
            return (string) $this->ReadPropertyString($name);
        } catch (\Throwable $e) {
            return '';
        }
    }

    /** Effektives Refresh-Intervall in ms; Boden 2s, damit eine 0 keinen Hot-Loop verursacht. */
    private function refreshIntervalMs(): int
    {
        return max(2, $this->ReadPropertyInteger('QueryInterval')) * 1000;
    }

    private const PROP_MAP = ['driver' => ['Driver', 's'], 'armed' => ['Armed', 'b']];

    private function castProp(string $type, $v)
    {
        switch ($type) { case 'i': return (int)$v; case 'f': return (float)$v; case 'b': return (bool)$v; default: return (string)$v; }
    }

    /** Flache Felder (driver/armed) aus Properties, alles andere aus dem Store. */
    protected function cfg(): array
    {
        $store = $this->store()->get('config', []);
        $store = is_array($store) ? $store : [];
        return array_merge($store, [
            'driver'        => $this->ReadPropertyString('Driver'),
            'armed'         => $this->ReadPropertyBoolean('Armed'),
            'speakerIp'     => $this->propStr('SpeakerIp'),
            'speakerRincon' => $this->propStr('SpeakerRincon'),
            'masterIp'      => $this->propStr('MasterIp'),
        ]);
    }

    private function armed(): bool
    {
        return $this->armedEffective($this->ReadPropertyBoolean('Armed')); // Hub-Master hat Vorrang
    }

    /** Baum-Sichtbarkeit: Programm-Parameter (Quelle/Volume/Ruhezeiten) als JSON; die Wochen-TIMING liegt im nativen Wochenplan-Ereignis. */
    protected function refreshMirrors(): void
    {
        $cfg = $this->cfg();
        $this->mirrorVar('ProgramJson', 'Audio-Programm/Wecken (JSON, Anzeige)', $cfg['schedule'] ?? []);
    }

    // ==================================================================
    // Nativer Symcon-Wochenplan (Ereignis Typ 2) als Zeitplan-Wahrheit (Aus/An)
    // ==================================================================
    private function audioEventId(): int
    {
        $e = @$this->GetIDForIdent('AudioSchedule');
        return (is_int($e) && $e > 0) ? $e : 0;
    }

    private function ensureScheduleEvent(): void
    {
        if (!function_exists('IPS_CreateEvent')) { return; }
        $eid = $this->audioEventId();
        $fresh = false;
        if ($eid <= 0) {
            $eid = @\IPS_CreateEvent(2);
            if (!$eid) { return; }
            @\IPS_SetParent($eid, $this->InstanceID);
            @\IPS_SetIdent($eid, 'AudioSchedule');
            @\IPS_SetName($eid, 'Audio-Zeitplan');
            @\IPS_SetEventScheduleAction($eid, 0, 'Aus', 0x9AA5AD, '');
            @\IPS_SetEventScheduleAction($eid, 1, 'An', 0x00CDAB, '');
            @\IPS_SetEventActive($eid, true);
            $fresh = true;
        }
        $e = @\IPS_GetEvent($eid);
        $hasPoints = false;
        foreach (($e['ScheduleGroups'] ?? []) as $g) { if (!empty($g['Points'])) { $hasPoints = true; break; } }
        if ($fresh || !$hasPoints) { $this->migrateScheduleToEvent($eid); }
    }

    private function migrateScheduleToEvent(int $eid): void
    {
        $e = @\IPS_GetEvent($eid);
        foreach (($e['ScheduleGroups'] ?? []) as $g) { @\IPS_SetEventScheduleGroup($eid, (int) $g['ID'], 0); }
        for ($d = 0; $d < 7; $d++) {
            @\IPS_SetEventScheduleGroup($eid, $d, (1 << $d));
            $slots = $this->schedules()->getSlots('Standard', $d);
            usort($slots, fn($a, $b) => (int) $a['end'] - (int) $b['end']);
            $prev = 0; $pid = 0;
            @\IPS_SetEventScheduleGroupPoint($eid, $d, $pid++, 0, 0, 0, 0);
            foreach ($slots as $s) {
                $start = (int) $prev; $act = ((float) ($s['val'] ?? 0) > 0) ? 1 : 0;
                if ($start > 0) { @\IPS_SetEventScheduleGroupPoint($eid, $d, $pid++, intdiv($start, 60), $start % 60, 0, $act); }
                else { @\IPS_SetEventScheduleGroupPoint($eid, $d, 0, 0, 0, 0, $act); }
                $prev = (int) ($s['end'] ?? 1440);
            }
        }
    }

    private function scheduleOnAt(int $now): bool
    {
        $eid = $this->audioEventId();
        if ($eid <= 0) { return false; }
        $e = @\IPS_GetEvent($eid);
        if (!is_array($e) || empty($e['EventActive'])) { return false; }
        $dow = (int) date('N', $now) - 1;
        $minNow = (int) date('G', $now) * 60 + (int) date('i', $now);
        $act = 0;
        foreach (($e['ScheduleGroups'] ?? []) as $g) {
            if (!(((int) ($g['Days'] ?? 0)) & (1 << $dow))) { continue; }
            $pts = $g['Points'] ?? [];
            usort($pts, fn($a, $b) => (($a['Start']['Hour'] ?? 0) * 60 + ($a['Start']['Minute'] ?? 0)) - (($b['Start']['Hour'] ?? 0) * 60 + ($b['Start']['Minute'] ?? 0)));
            foreach ($pts as $p) {
                $m = (int) ($p['Start']['Hour'] ?? 0) * 60 + (int) ($p['Start']['Minute'] ?? 0);
                if ($m <= $minNow) { $act = (int) ($p['ActionID'] ?? 0); }
            }
        }
        return $act === 1;
    }

    /** Flache Keys -> Properties, verschachtelte -> Store; $apply triggert ApplyChanges. */
    private function applyConfigProperties(array $c, bool $apply = true): void
    {
        $storePatch = [];
        foreach ($c as $k => $v) {
            if (isset(self::PROP_MAP[$k])) {
                [$p, $t] = self::PROP_MAP[$k];
                @\IPS_SetProperty($this->InstanceID, $p, $this->castProp($t, $v));
            } else {
                $storePatch[$k] = $v; // bind/reflect/power/group/caps/schedule …
            }
        }
        if ($storePatch !== []) {
            $this->store()->patch('config', $storePatch);
        }
        if ($apply) {
            @\IPS_ApplyChanges($this->InstanceID);
        }
    }

    /** Einmal-Migration: flache FabricStore-config -> native Properties (per RPC-Op). */
    private function migrateConfig(): array
    {
        if ($this->ReadPropertyInteger('ConfigSchema') >= 1) {
            return ['ok' => true, 'already' => true, 'config' => $this->cfg()];
        }
        $c = $this->store()->get('config', []);
        $c = is_array($c) ? $c : [];
        $flat = [];
        foreach ($c as $k => $v) {
            if (isset(self::PROP_MAP[$k])) {
                [$p, $t] = self::PROP_MAP[$k];
                @\IPS_SetProperty($this->InstanceID, $p, $this->castProp($t, $v));
                $flat[] = $k;
            }
        }
        @\IPS_SetProperty($this->InstanceID, 'ConfigSchema', 1);
        @\IPS_ApplyChanges($this->InstanceID);
        return ['ok' => true, 'migrated' => $flat, 'config' => $this->cfg()];
    }

    /**
     * Baum-Transparenz: bl_-Links auf die vom Zonen-Aktor GESTEUERTEN Symcon-
     * Objekte. NUR fuer generic-audio moeglich (bindet an Variablen); sonos-upnp
     * (IP/RINCON) und heos (PID) binden an Netzwerk-Adressen -> keine Objekt-Links.
     */
    protected function bindingTargets(): array
    {
        $cfg = $this->cfg();
        if ((string) ($cfg['driver'] ?? '') !== 'generic-audio') {
            return [];
        }
        $out = [];
        $add = function (string $ident, string $name, $id) use (&$out): void {
            $id = (int) $id;
            if ($id > 0 && function_exists('IPS_ObjectExists') && @\IPS_ObjectExists($id)) {
                $out[] = ['ident' => $ident, 'name' => $name, 'targetId' => $id];
            }
        };
        $bind = is_array($cfg['bind'] ?? null) ? $cfg['bind'] : [];
        foreach (['transport'=>'Transport','volume'=>'Lautstärke','mute'=>'Stumm','repeat'=>'Repeat','shuffle'=>'Shuffle','position'=>'Position'] as $k => $lab) {
            $add('bl_' . $k, $lab, $bind[$k]['varId'] ?? 0);
        }
        $src = is_array($bind['source'] ?? null) ? $bind['source'] : [];
        foreach (['favorite'=>'Favorit','radio'=>'Radio','playlist'=>'Playlist'] as $k => $lab) {
            $add('bl_src_' . $k, $lab, $src[$k]['varId'] ?? 0);
        }
        $pow = is_array($cfg['power'] ?? null) ? $cfg['power'] : [];
        $add('bl_power', 'Ein/Aus', $pow['varId'] ?? 0);
        $add('bl_powerOn', 'Ein-Skript', $pow['scriptOn'] ?? 0);
        $add('bl_powerOff', 'Aus-Skript', $pow['scriptOff'] ?? 0);
        $grp = is_array($cfg['group'] ?? null) ? $cfg['group'] : [];
        foreach (['scriptId'=>'Gruppen-Skript','rinconVarId'=>'RINCON','masterVarId'=>'Master','slaveVarId'=>'Slave',
                  'masterRinconVarId'=>'Master-RINCON','masterNameVarId'=>'Master-Name'] as $k => $lab) {
            $add('bl_grp_' . strtolower($k), $lab, $grp[$k] ?? 0);
        }
        // Now-Playing-Reflect-Variablen (was das Modul liest/spiegelt) -> Baum-Transparenz.
        $ref = is_array($cfg['reflect'] ?? null) ? $cfg['reflect'] : [];
        $refLabels = ['title'=>'Titel','artist'=>'Interpret','album'=>'Album','albumArtist'=>'Album-Interpret',
                      'coverUri'=>'Cover','positionTime'=>'Position','duration'=>'Dauer','playState'=>'Wiedergabe',
                      'volume'=>'Lautstärke','mute'=>'Stumm','repeat'=>'Repeat','shuffle'=>'Shuffle'];
        foreach ($refLabels as $k => $lab) { $add('bl_ref_' . $k, 'NowPlaying: ' . $lab, $ref[$k] ?? 0); }
        return $out;
    }

    protected function cfgVal(string $key, $def)
    {
        $c = $this->cfg();
        $v = array_key_exists($key, $c) ? $c[$key] : $def;
        return $key === 'armed' ? $this->armedEffective((bool) $v) : $v; // Hub-Master hat Vorrang
    }

    private function configuredDriverId(): string
    {
        return (string) ($this->cfg()['driver'] ?? '');
    }

    /** Aktueller Wert einer Statusvariable per Ident (numerisch/bool), sonst Default. */
    private function valOf(string $ident, $def)
    {
        try {
            $id = @$this->GetIDForIdent($ident);
            if (is_int($id) && $id > 0) {
                $v = @GetValue($id);
                if (is_bool($v) || is_numeric($v)) {
                    return $v;
                }
            }
        } catch (\Throwable $e) {
        }
        return $def;
    }

    private function writeVar(int $vid, $value): void
    {
        if ($vid <= 0) {
            return;
        }
        $actionable = false;
        if (function_exists('IPS_GetVariable')) {
            $v = @\IPS_GetVariable($vid);
            $actionable = is_array($v) && ((int) ($v['VariableAction'] ?? 0) > 0 || (int) ($v['VariableCustomAction'] ?? 0) > 0);
        }
        try {
            if ($actionable && function_exists('RequestAction')) {
                @\RequestAction($vid, $value);
            } elseif (function_exists('SetValue')) {
                @\SetValue($vid, $value);
            }
        } catch (\Throwable $e) {
            $this->SendDebug('HSAU.writeVar', '#' . $vid . ': ' . $e->getMessage(), 0);
        }
    }

    private function runScript(int $sid): void
    {
        if ($sid > 0 && function_exists('IPS_RunScript') && @\IPS_ScriptExists($sid)) {
            @\IPS_RunScript($sid);
        }
    }

    /** Sekunden -> "m:ss" bzw. "h:mm:ss". */
    private function secToTime(int $sec): string
    {
        $sec = max(0, $sec);
        $h = intdiv($sec, 3600);
        $m = intdiv($sec % 3600, 60);
        $s = $sec % 60;
        return $h > 0
            ? sprintf('%d:%02d:%02d', $h, $m, $s)
            : sprintf('%d:%02d', $m, $s);
    }
}

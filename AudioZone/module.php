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
use Hoep\HomeSuite\HAL\IAudioRenderer;
use Hoep\HomeSuite\HAL\IAudioStateReadable;
use Hoep\HomeSuite\HAL\IDriver;

class AudioZone extends EntityModule
{
    /** Kanonische Transport-Codes (Manifest-Optionen); der Treiber mappt auf Vendor-Codes. */
    private const TR_PLAY = 1;
    private const TR_PAUSE = 2;
    private const TR_STOP = 3;
    private const TR_NEXT = 4;
    private const TR_PREV = 5;

    private const TIMER_REFRESH = 'Refresh';
    private const REFRESH_MS = 5000;

    private ?IDriver $driverInstance = null;
    private bool $driverResolved = false;

    // ==================================================================
    // Manifest (Vertrag 2)
    // ==================================================================

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
                ['op' => 'setArmed',        'label' => 'Scharfschalten / Schatten-Modus'],
                ['op' => 'importLegacy',    'label' => 'Aus IPSSonos importieren'],
                ['op' => 'group',           'label' => 'Gruppieren'],
                ['op' => 'ungroup',         'label' => 'Gruppe trennen'],
                ['op' => 'setGroupVolume',  'label' => 'Gruppen-Lautstaerke'],
                ['op' => 'playSource',      'label' => 'Quelle abspielen'],
                ['op' => 'updateProfile',   'label' => 'Wochenplan bearbeiten'],
                ['op' => 'getSchedule',     'label' => 'Wochenplan lesen'],
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
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->driverResolved = false;
        $this->driverInstance = null;

        $active = $this->driver() instanceof IAudioRenderer;
        $this->SetTimerInterval(self::TIMER_REFRESH, $active ? self::REFRESH_MS : 0);
        $this->syncReferences();
        $this->updateHealth();
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
    private function applyPower(bool $on, IAudioRenderer $drv): void
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
            $this->driverInstance = DriverFactory::create($driverId, [
                'bind'    => $bind,
                'reflect' => (array) ($cfg['reflect'] ?? []),
                'group'   => (array) ($cfg['group'] ?? []),
                'caps'    => (array) ($cfg['caps'] ?? []),
            ]);
        } catch (\Throwable $e) {
            $this->SendDebug('HSAU.driver', 'Treiberaufbau fehlgeschlagen: ' . $e->getMessage(), 0);
            $this->driverInstance = null;
        }
        return $this->driverInstance;
    }

    // ==================================================================
    // Refresh: Ist-Zustand spiegeln
    // ==================================================================

    public function Refresh(): void
    {
        $drv = $this->driver();
        if (!$drv instanceof IAudioStateReadable) {
            return; // Push-Treiber spiegeln ueber parseEvent (spaeter, Bridge)
        }
        $st = $drv->readState();
        $this->setReflect('Title', $st->title);
        $this->setReflect('Artist', $st->artist);
        $this->setReflect('Album', $st->album);
        $this->setReflect('AlbumArtist', $st->albumArtist);
        $this->setReflect('CoverUri', $st->coverUri);
        $this->setReflect('PositionTime', $this->secToTime($st->positionSec));
        $this->setReflect('Duration', $this->secToTime($st->durationSec));
        $this->setReflect('PlayState', $st->playState);
        $this->setReflect('Online', $st->online);
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
            case 'setArmed':
                $this->store()->patch('config', ['armed' => (bool) ($args['armed'] ?? false)]);
                $this->updateHealth();
                return ['ok' => true, 'armed' => (bool) $this->cfgVal('armed', false)];
            case 'importLegacy':
                return $this->mgmtImportLegacy($args);
            case 'group':
                return $this->mgmtGroup($args);
            case 'ungroup':
                return $this->mgmtUngroup();
            case 'setGroupVolume':
                return $this->mgmtSetGroupVolume($args);
            case 'playSource':
                return $this->mgmtPlaySource($args);
            case 'updateProfile':
                return $this->mgmtUpdateProfile($args, $ctx);
            case 'getSchedule':
                return $this->mgmtGetSchedule($args);
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
        if (!empty($ctx['dryrun'])) {
            return ['ok' => true, 'dryrun' => true, 'config' => $config];
        }
        $this->store()->patch('config', $config);
        $this->driverResolved = false;
        $this->driverInstance = null;
        $this->syncReferences();
        $this->updateHealth();
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
                'playState'    => $transport, // Naeherung; nativer Treiber liefert echten PlayState
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
        $this->store()->patch('config', $config);
        $this->driverResolved = false;
        $this->driverInstance = null;
        $this->syncReferences();
        $this->updateHealth();
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

    public function GetConfigurationForm()
    {
        $cfg = $this->cfg();
        $armed = (bool) ($cfg['armed'] ?? false);
        $h = $this->computeHealth();
        return json_encode(['elements' => [
            ['type' => 'Label', 'caption' => 'Audio/Media — Steuerung ueber generischen Treiber (Sonos im Uebergang '
                . 'ueber IPSSonos-Raum-Variablen; spaeter nativ sonos-upnp/heos). Verwaltung/Visu im LiveViewBuilder.'],
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

    private function cfg(): array
    {
        $c = $this->store()->get('config', []);
        return is_array($c) ? $c : [];
    }

    private function cfgVal(string $key, $def)
    {
        $c = $this->cfg();
        return array_key_exists($key, $c) ? $c[$key] : $def;
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

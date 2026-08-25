<?php

declare(strict_types=1);

/**
 * AudioZoneBridged (HSAUX) — Audio-Entitaet als KIND einer Bridge (HEOS/MusicCast/Denon).
 *
 * Erbt das komplette AudioZone-Manifest/Verhalten (identische Controls), fahrt aber einen
 * PUSH-Treiber (z. B. heos): Kommandos gehen ueber SendDataToParent an die Bridge (die den
 * Socket haelt), Zustands-Frames kommen via ReceiveData -> parseEvent -> Reflect. Damit
 * traegt die Vendor-Abstraktion: gleiche Seite/Widgets, anderer Treiber/Transport.
 *
 * Klassenname == module.json "name" == GUID {053E7017-584E-4F62-A246-EBA6CE3DE034}.
 */

require_once __DIR__ . '/../AudioZone/module.php'; // Basisklasse AudioZone (+ autoload)

use Hoep\HomeSuite\HAL\AudioState;
use Hoep\HomeSuite\HAL\DriverFactory;
use Hoep\HomeSuite\HAL\IAudioRenderer;
use Hoep\HomeSuite\HAL\IDriver;

class AudioZoneBridged extends AudioZone
{
    private const HSBH     = '{BCDCA10C-BDFD-4270-8D28-1CC690A130DB}'; // HeosBridge (Parent)
    private const CHILD_DL = '{D7E6F5C4-B3A2-4190-8E7D-6C5B4A392817}'; // Bridge <-> Zone Daten-GUID
    private const RX_MS    = 15000;

    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyString('PID', '');    // HEOS Player-ID (aus der Bridge-Playerliste)
        $this->RegisterPropertyString('Vendor', 'heos');
        $this->RegisterPropertyInteger('QueryInterval', 15); // Abfrage-Intervall in SEKUNDEN (default = RX_MS/1000)
        $this->ConnectParent(self::HSBH);            // unter die HEOS-Bridge haengen
    }

    /** Timer mit HSAUX-Prefix (nicht HSAU!) registrieren. */
    protected function setupTimers(): void
    {
        $this->RegisterTimer('Refresh', 0, 'HSAUX_Refresh($_IPS[\'TARGET\']);');
        $this->RegisterTimer('Sleep', 0, 'HSAUX_RunSleep($_IPS[\'TARGET\']);');
        $this->RegisterTimer('Ramp', 0, 'HSAUX_RunRamp($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->driverResolved = false;
        $this->driverInstance = null;
        // Push-Treiber: dezenter Refresh-Poll als Sicherheitsnetz (HEOS liefert primaer Events).
        $this->SetTimerInterval('Refresh', $this->driver() instanceof IAudioRenderer ? $this->rxMs() : 0);
    }

    /** Effektives Refresh-Intervall in ms aus der QueryInterval-Eigenschaft (Boden 2 s gegen Hot-Loop). */
    private function rxMs(): int
    {
        return max(2, $this->ReadPropertyInteger('QueryInterval')) * 1000;
    }

    /** PUSH-Treiber (heos): Kommandos ueber die Bridge (SendDataToParent). */
    protected function driver(): ?IDriver
    {
        if ($this->driverResolved) {
            return $this->driverInstance;
        }
        $this->driverResolved = true;
        $this->driverInstance = null;

        $pid = trim((string) $this->ReadPropertyString('PID'));
        $vendor = trim((string) $this->ReadPropertyString('Vendor')) ?: 'heos';
        if ($pid === '' || !DriverFactory::has($vendor)) {
            return null;
        }
        try {
            $this->driverInstance = DriverFactory::create($vendor, ['pid' => $pid], function ($frame): void {
                $this->SendDataToParent(json_encode(['DataID' => self::CHILD_DL, 'Buffer' => (string) $frame]));
            });
        } catch (\Throwable $e) {
            $this->SendDebug('HSAUX.driver', $e->getMessage(), 0);
            $this->driverInstance = null;
        }
        return $this->driverInstance;
    }

    /** Push-Modus: kein readState; Snapshot-Requests ueber die Bridge anfordern + Zeitplan. */
    public function Refresh(): void
    {
        $drv = $this->driver();
        if ($drv instanceof IAudioRenderer) {
            foreach ($drv->poll() as $frame) {
                $this->SendDataToParent(json_encode(['DataID' => self::CHILD_DL, 'Buffer' => (string) $frame]));
            }
        }
        $this->runSchedule();
    }

    /** Frames von der Bridge: parseEvent -> Reflect (nur die vom Command betroffenen Felder). */
    public function ReceiveData($JSONString)
    {
        $data = json_decode((string) $JSONString);
        if (($data->DataID ?? '') !== self::CHILD_DL) {
            return '';
        }
        $frame = (string) ($data->Buffer ?? '');
        if ($frame === '') {
            return '';
        }
        $drv = $this->driver();
        if (!$drv instanceof IAudioRenderer) {
            return '';
        }
        $st = $drv->parseEvent($frame);   // ignoriert fremde pid -> null
        if (!$st instanceof AudioState) {
            return '';
        }
        $j   = json_decode($frame, true);
        $cmd = is_array($j) ? (string) ($j['heos']['command'] ?? '') : '';

        if (strpos($cmd, 'now_playing_media') !== false) {
            $this->setReflect('Title', $st->title);
            $this->setReflect('Artist', $st->artist);
            $this->setReflect('Album', $st->album);
            $this->setReflect('CoverUri', $st->coverUri);
        } elseif (strpos($cmd, 'volume') !== false) {
            $this->setReflect('Volume', $st->volume);
            $this->setReflect('Mute', $st->mute);
        } elseif (strpos($cmd, 'mute') !== false) {
            $this->setReflect('Mute', $st->mute);
        } elseif (strpos($cmd, 'play_state') !== false || strpos($cmd, 'state_changed') !== false) {
            $this->setReflect('PlayState', $st->playState);
        } elseif (strpos($cmd, 'progress') !== false) {
            $this->setReflect('PositionTime', $this->hms($st->positionSec));
            $this->setReflect('Duration', $this->hms($st->durationSec));
            $this->setReflect('Position', $st->durationSec > 0
                ? (int) round($st->positionSec / $st->durationSec * 100) : 0);
        }
        $this->setReflect('Online', true);
        return '';
    }

    private function hms(int $sec): string
    {
        $sec = max(0, $sec);
        $h = intdiv($sec, 3600);
        $m = intdiv($sec % 3600, 60);
        $s = $sec % 60;
        return $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%d:%02d', $m, $s);
    }

    public function GetConfigurationForm()
    {
        return json_encode(['elements' => [
            ['type' => 'Label', 'caption' => 'HEOS-Player (Kind der HEOS-Bridge). PID aus der Bridge-Playerliste eintragen. '
                . 'Bedienung/Visu laufen wie bei AudioZone im LiveViewBuilder (?api=audio findet HSAU + HSAUX).'],
            ['type' => 'ValidationTextBox', 'name' => 'PID', 'caption' => 'HEOS Player-ID (pid)', 'value' => $this->ReadPropertyString('PID')],
            ['type' => 'NumberSpinner', 'name' => 'QueryInterval', 'caption' => 'Abfrage-Intervall (s)'],
        ]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

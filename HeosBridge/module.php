<?php

declare(strict_types=1);

/**
 * HeosBridge (HSBH) — Splitter fuer Denon/Marantz HEOS (CLI ueber TCP 1255).
 *
 * Haelt (ueber einen ClientSocket-Parent) die EINE persistente HEOS-Verbindung und
 * verteilt die Event-/Antwort-Frames an die AudioZoneBridged-Kinder (HSAUX). Kommandos
 * der Kinder werden gebuendelt an den Socket weitergereicht. So bleibt der Treiber
 * (Heos.php) zustandslos; Socket/Session/Reconnect liegen hier (Vertrag 3, B1).
 *
 * HEOS-CLI: nach Verbindungsaufbau einmalig
 *   heos://system/register_for_change_events?enable=on
 *   heos://player/get_players
 * Frames sind zeilenweise (\r\n) JSON. Referenz: HEOS CLI Protocol Specification.
 *
 * Datenwege (Symcon-Connector):
 *   Parent I/O (ClientSocket {3CFF0FD9-...}): senden ueber DataID {79827379-...},
 *   empfangen via ReceiveData.
 *   Kinder (HSAUX): downstream SendDataToChildren DataID {D7E6F5C4-...17};
 *   upstream via ForwardData (Kind -> SendDataToParent).
 */
class HeosBridge extends IPSModule
{
    private const IO_TX      = '{79827379-F36E-4ADA-8A95-5F8313DAE8DB}'; // -> ClientSocket
    private const CLIENTSOCK = '{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}'; // ClientSocket-Modul
    private const CHILD_DL   = '{D7E6F5C4-B3A2-4190-8E7D-6C5B4A392817}'; // Bridge -> Zone (downstream)

    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyInteger('Port', 1255);
        $this->RegisterAttributeString('RxBuffer', '');
        $this->RegisterAttributeString('Players', '[]');   // [{pid,name,model,ip}]
        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
        $this->RequireParent(self::CLIENTSOCK);            // ClientSocket-Parent automatisch anlegen
        $this->RegisterTimer('HeosKeepalive', 0, 'HSBH_Keepalive($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->configureParent();
        if (function_exists('IPS_GetKernelRunlevel') && IPS_GetKernelRunlevel() === KR_READY) {
            $this->onReady();
        }
    }

    public function MessageSink($t, $sender, $message, $data)
    {
        if ($message === IPS_KERNELMESSAGE && isset($data[0]) && $data[0] === KR_READY) {
            $this->onReady();
        }
        // Parent-Status-Wechsel (Socket offen) -> HEOS-Handshake senden.
        if ($message === IM_CHANGESTATUS && (int) ($data[0] ?? 0) === IS_ACTIVE) {
            $this->handshake();
        }
    }

    /** ClientSocket-Parent mit Host/Port:1255 konfigurieren und oeffnen. */
    private function configureParent(): void
    {
        $pid = (int) (IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0);
        if ($pid <= 0) {
            return;
        }
        $host = trim($this->ReadPropertyString('Host'));
        $port = max(1, $this->ReadPropertyInteger('Port'));
        @IPS_SetProperty($pid, 'Host', $host);
        @IPS_SetProperty($pid, 'Port', $port);
        @IPS_SetProperty($pid, 'Open', $host !== '');
        if (IPS_HasChanges($pid)) {
            @IPS_ApplyChanges($pid);
        }
        $this->RegisterParent(self::CLIENTSOCK); // Statuswechsel abonnieren
    }

    private function onReady(): void
    {
        // Wenn der Socket bereits aktiv ist, Handshake sofort.
        $pid = (int) (IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0);
        if ($pid > 0 && IPS_GetInstance($pid)['InstanceStatus'] === IS_ACTIVE) {
            $this->handshake();
        }
    }

    /** HEOS-Handshake: Change-Events aktivieren + Player abfragen; Keepalive starten. */
    private function handshake(): void
    {
        $this->toSocket("heos://system/register_for_change_events?enable=on\r\n");
        $this->toSocket("heos://player/get_players\r\n");
        $this->SetTimerInterval('HeosKeepalive', 30000); // HEOS trennt Idle-Verbindungen
    }

    public function Keepalive(): void
    {
        // Leichtgewichtiger Ping, damit der Broker die Verbindung nicht kappt.
        $this->toSocket("heos://system/heart_beat\r\n");
    }

    // ---- Daten vom Socket (Parent) ------------------------------------------

    public function ReceiveData($JSONString)
    {
        $data = json_decode((string) $JSONString);
        if (!isset($data->Buffer)) {
            return '';
        }
        // Rohbytes rekonstruieren; HEOS liefert UTF-8-JSON.
        $chunk = (string) $data->Buffer;
        $buf   = $this->ReadAttributeString('RxBuffer') . $chunk;

        // Zeilenweise (\r\n bzw. \n) framen.
        $lines = preg_split('/\r?\n/', $buf);
        $rest  = array_pop($lines); // unvollstaendiger Rest
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $this->handleFrame($line);
        }
        $this->WriteAttributeString('RxBuffer', (string) $rest);
        return '';
    }

    /** Ein vollstaendiger HEOS-JSON-Frame: an alle Kinder verteilen + Player-Liste pflegen. */
    private function handleFrame(string $line): void
    {
        $j = json_decode($line, true);
        if (is_array($j) && (string) ($j['heos']['command'] ?? '') === 'player/get_players') {
            $players = [];
            foreach ((array) ($j['payload'] ?? []) as $p) {
                $players[] = ['pid' => (string) ($p['pid'] ?? ''), 'name' => (string) ($p['name'] ?? ''),
                    'model' => (string) ($p['model'] ?? ''), 'ip' => (string) ($p['ip'] ?? '')];
            }
            $this->WriteAttributeString('Players', json_encode($players));
        }
        $this->SendDataToChildren(json_encode(['DataID' => self::CHILD_DL, 'Buffer' => $line]));
    }

    // ---- Daten von einem Kind (Zone) -> an den Socket -----------------------

    public function ForwardData($JSONString)
    {
        $data = json_decode((string) $JSONString);
        $buf  = (string) ($data->Buffer ?? '');
        if ($buf !== '') {
            $this->toSocket($buf);
        }
        return '';
    }

    private function toSocket(string $s): void
    {
        $this->SendDataToParent(json_encode(['DataID' => self::IO_TX, 'Buffer' => $s]));
    }

    /** @return array<int,array> bekannte Player (aus get_players). */
    public function GetPlayers(): string
    {
        // Frisch anfragen und den zuletzt bekannten Stand liefern.
        $this->toSocket("heos://player/get_players\r\n");
        return $this->ReadAttributeString('Players');
    }

    public function GetConfigurationForm()
    {
        $players = json_decode($this->ReadAttributeString('Players'), true) ?: [];
        $rows = [];
        foreach ($players as $p) {
            $rows[] = ['pid' => $p['pid'], 'name' => $p['name'], 'model' => $p['model'], 'ip' => $p['ip']];
        }
        return json_encode([
            'elements' => [
                ['type' => 'Label', 'caption' => 'HEOS-Bridge: EINE Verbindung zu einem beliebigen HEOS-Geraet '
                    . '(Port 1255). Die AudioZoneBridged-Instanzen (je Player) haengen als Kinder darunter.'],
                ['type' => 'ValidationTextBox', 'name' => 'Host', 'caption' => 'HEOS-Geraet IP', 'value' => $this->ReadPropertyString('Host')],
                ['type' => 'NumberSpinner', 'name' => 'Port', 'caption' => 'Port', 'value' => $this->ReadPropertyInteger('Port')],
            ],
            'actions' => [
                ['type' => 'Button', 'caption' => 'Player abfragen', 'onClick' => 'echo HSBH_GetPlayers($id);'],
                ['type' => 'List', 'name' => 'PlayersList', 'caption' => 'Gefundene Player', 'rowCount' => 6,
                    'columns' => [
                        ['caption' => 'PID', 'name' => 'pid', 'width' => '120px'],
                        ['caption' => 'Name', 'name' => 'name', 'width' => 'auto'],
                        ['caption' => 'Modell', 'name' => 'model', 'width' => '160px'],
                        ['caption' => 'IP', 'name' => 'ip', 'width' => '140px'],
                    ], 'values' => $rows],
            ],
            'status' => [],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

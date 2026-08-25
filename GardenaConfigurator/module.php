<?php

declare(strict_types=1);

require_once '/var/lib/symcon/modules/HomeSuite/libs/HomeSuite/autoload.php';

use Hoep\HomeSuite\HAL\DriverFactory;
use Hoep\HomeSuite\HAL\GardenaAppApi;
use Hoep\HomeSuite\HAL\GardenaDevApi;

/**
 * Gardena Cloud (HomeSuite) — zentrale Verbindung + Configurator (Prefix HSGX).
 *
 * EINE Instanz haelt den Zugang (Client ID + Client Secret der GARDENA-smart-system-Developer-App)
 * und listet alle Geraete der Cloud in einer Tabelle. Pro Zeile wird per Klick eine
 * GardenaDevice-Instanz (HSGA) mit ihren Variablen angelegt. Lesen UND Schalten der Geraete
 * laufen anschliessend ueber DIESELBE Developer-API (OAuth2 client_credentials) — kein Legacy-Token.
 *
 * Die Zugangsdaten werden (root-only) nach scripts/gardena_dev.json geschrieben; der HAL-Treiber
 * gardena-dev liest sie von dort. So teilen sich Configurator und alle Geraete-Instanzen EINEN Zugang.
 */
class GardenaConfigurator extends \IPSModule
{
    private const CRED_FILE     = '/var/lib/symcon/scripts/gardena_dev.json';       // Client ID/Secret (nur Ventil)
    private const DEVTOKEN_FILE = '/var/lib/symcon/scripts/gardena_dev_token.json'; // access_token-Cache (neue API)
    private const TOKEN_FILE    = '/var/lib/symcon/scripts/automower_token.json';   // Legacy-Token (Lesen/Liste)
    private const DEV_GUID      = '{2DB05F36-BBD0-AC2E-85A8-369B88CD242F}';

    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyString('ClientID', '');
        $this->RegisterPropertyString('ClientSecret', '');
        $this->RegisterPropertyInteger('PollInterval', 60);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->saveCreds();
        $this->SetStatus($this->hasCreds() ? 102 : 104); // 102 aktiv, 104 inaktiv (keine Creds)
    }

    // ------------------------------------------------------------------
    private function hasCreds(): bool
    {
        return trim((string) $this->ReadPropertyString('ClientID')) !== ''
            && trim((string) $this->ReadPropertyString('ClientSecret')) !== '';
    }

    /** Client ID/Secret aus dem Formular in die (root-only) Datei schreiben; Token-Cache verwerfen. */
    private function saveCreds(): void
    {
        $id = trim((string) $this->ReadPropertyString('ClientID'));
        $se = trim((string) $this->ReadPropertyString('ClientSecret'));
        if ($id === '' || $se === '') {
            return;
        }
        @file_put_contents(self::CRED_FILE, json_encode(['appKey' => $id, 'appSecret' => $se]));
        @chmod(self::CRED_FILE, 0600);
        @unlink(self::DEVTOKEN_FILE);
    }

    /** Lese-/Listen-Treiber ueber die ALTE, grosszuegig limitierte Cloud (Legacy-Token). */
    private function listDriver(): ?GardenaAppApi
    {
        try {
            $d = DriverFactory::create('gardena-app', ['tokenFile' => self::TOKEN_FILE]);
            return $d instanceof GardenaAppApi ? $d : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Schalt-Treiber ueber die NEUE Developer-API (Client ID/Secret) — nur fuer den Verbindungstest. */
    private function devDriver(): ?GardenaDevApi
    {
        $this->saveCreds();
        if (!$this->hasCreds()) {
            return null;
        }
        try {
            $d = DriverFactory::create('gardena-dev', ['credFile' => self::CRED_FILE]);
            return $d instanceof GardenaDevApi ? $d : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Button: Developer-API (Ventil-Steuerung) testen — Auth + Geraetezahl. */
    public function TestConnection(): void
    {
        $d = $this->devDriver();
        if ($d === null) {
            echo "Keine Zugangsdaten hinterlegt (Client ID/Secret).";
            return;
        }
        $devs = $d->listDevices();
        if ($devs === []) {
            echo "Developer-API: Verbindung fehlgeschlagen oder keine Geraete.\nFehler: " . $d->lastError();
            return;
        }
        echo "Developer-API OK (fuer Ventil-Steuerung) — " . count($devs) . " Geraet(e) sichtbar.";
    }

    // ------------------------------------------------------------------
    // Configurator-Formular: Zugang oben, Geraetetabelle unten.
    // ------------------------------------------------------------------
    public function GetConfigurationForm()
    {
        $values = [];
        $d = $this->listDriver();
        if ($d !== null) {
            $existing = $this->existingByDeviceId();
            foreach ($d->listDevices() as $dev) {
                if (($dev['category'] ?? '') === 'gateway') {
                    continue; // Gateway hat nichts darzustellen
                }
                $did  = (string) $dev['id'];
                $iid  = $existing[$did] ?? 0;
                $values[] = [
                    'id'         => crc32($did),
                    'name'       => (string) $dev['name'],
                    'category'   => $this->catLabel((string) $dev['category']),
                    'serial'     => (string) ($dev['serial'] ?? ''),
                    'model'      => (string) ($dev['model'] ?? ''),
                    'instanceID' => $iid,
                    'create'     => [
                        'moduleID'      => self::DEV_GUID,
                        'name'          => 'Gardena ' . $dev['name'],
                        'configuration' => [
                            'Driver'       => 'gardena-app',
                            'WriteDriver'  => 'gardena-dev',
                            'DeviceId'     => $did,
                            'Serial'       => (string) ($dev['serial'] ?? ''),
                            'Category'     => (string) $dev['category'],
                            'PollInterval' => max(15, (int) $this->ReadPropertyInteger('PollInterval')),
                        ],
                    ],
                ];
            }
        }

        $form = [
            'elements' => [
                ['type' => 'ValidationTextBox', 'name' => 'ClientID',     'caption' => 'Application Key (Client ID)'],
                ['type' => 'PasswordTextBox',   'name' => 'ClientSecret', 'caption' => 'Application Secret'],
                ['type' => 'NumberSpinner',     'name' => 'PollInterval', 'caption' => 'Abfrage-Intervall (s)', 'minimum' => 15, 'maximum' => 3600, 'suffix' => 's'],
                ['type' => 'Button',            'caption' => 'Verbindung testen', 'onClick' => 'HSGX_TestConnection($id);'],
                ['type' => 'Label',             'caption' => 'App anlegen: developer.husqvarnagroup.cloud → Authentication API + GARDENA smart system API verbinden.'],
                [
                    'type'     => 'Configurator',
                    'name'     => 'Devices',
                    'caption'  => 'Gardena-Geraete',
                    'rowCount' => 12,
                    'add'      => false,
                    'delete'   => false,
                    'columns'  => [
                        ['caption' => 'Name',  'name' => 'name',     'width' => '260px'],
                        ['caption' => 'Typ',   'name' => 'category', 'width' => '180px'],
                        ['caption' => 'Serie', 'name' => 'serial',   'width' => '120px'],
                        ['caption' => 'Modell', 'name' => 'model',   'width' => 'auto'],
                    ],
                    'values'   => $values,
                ],
            ],
            'actions' => [],
            'status'  => [
                ['code' => 102, 'icon' => 'active',   'caption' => 'Verbunden (Zugang hinterlegt)'],
                ['code' => 104, 'icon' => 'inactive', 'caption' => 'Kein Zugang (Client ID/Secret fehlt)'],
            ],
        ];
        return json_encode($form);
    }

    /** Bereits angelegte HSGA-Instanzen: DeviceId => InstanzID. */
    private function existingByDeviceId(): array
    {
        $out = [];
        foreach (@\IPS_GetInstanceListByModuleID(self::DEV_GUID) ?: [] as $iid) {
            $out[(string) @\IPS_GetProperty($iid, 'DeviceId')] = $iid;
        }
        return $out;
    }

    private function catLabel(string $cat): string
    {
        $m = [
            'watering_computer'       => 'Bewaesserungscomputer',
            'water_control'           => 'Water Control',
            'smart_irrigation_control'=> 'Smart Irrigation Control',
            'sensor2'                 => 'Boden-/Feuchtesensor',
            'sensor'                  => 'Sensor',
            'gateway'                 => 'Gateway',
        ];
        return $m[$cat] ?? $cat;
    }
}

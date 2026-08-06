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

        return $m->toArray();
    }

    /**
     * Der Hub hat keine Bedien-Controls; RequestAction wird faktisch nie mit
     * einem gueltigen Control aufgerufen. Die Basis faengt das ab. Dieser Hook
     * bleibt daher leer.
     */
    protected function applyControl(Control $c, $value, ActionContext $ctx): void
    {
        // absichtlich leer — Hub wird nicht bedient
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
        }

        return ['ok' => false, 'op' => $op, 'error' => 'not_implemented'];
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
        foreach ($this->domainGuids() as [$guid, $domain]) {
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
            $parent = ((int) $job['parentId']) > 0 ? (int) $job['parentId'] : $prov->category('HomeSuite');

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

        // Weitere Jobs? Timer weiterlaufen lassen, sonst stoppen.
        if (count($this->readQueue()) === 0) {
            $this->SetTimerInterval(self::TIMER_PROVISION, 0);
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

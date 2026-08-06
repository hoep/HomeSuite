<?php

declare(strict_types=1);

namespace Hoep\HomeSuite;

/**
 * EntityModule — abstrakte Modulbasis aller Domaenen-Module (Vertrag 1).
 *
 * Eine Modul-Instanz = eine Entitaet. Diese Basis kapselt den einzigen
 * autoritativen Bedien-Eingang (native RequestAction) sowie das gemeinsame
 * RPC-Trio (GetManifest/GetState/Manage). Konkrete Domaenen (HeatingZone,
 * ShadingDevice, …) liefern nur `manifest()` und `applyControl()`.
 *
 * Design-Entscheidungen am Code:
 *  - F4: `RequestAction` ist FINAL und laeuft komplett in try/catch — es wirft
 *        NIE nach oben. Ein Fehler landet im Modul-Log, nie als ungefangene
 *        Exception im `?api=setvar`-Pfad / Kernel-Log. Optimistischer SetValue
 *        ist an `manualHold` gekoppelt, nicht pauschal verboten.
 *  - F5: Auch `command`-Controls werden mit RegisterVariable + EnableAction
 *        angelegt; "transient" wird durch Idle-Reset nach `applyControl`
 *        umgesetzt ({@see ControlContract::CMD_IDLE}).
 *  - A3: `isAutomated()` liefert im Basisfall FALSE — die Domaene ueberschreibt
 *        gezielt (z. B. Heizung nur fuer Setpoint/Mode).
 *  - Blocker D: fluechtiger Zustand (manualHold) liegt in einem EIGENEN,
 *        volatilen Attribut ('HoldState') bzw. in Statusvariablen — niemals im
 *        Konfig-Store (FabricStore). `setReflect()` schreibt ausschliesslich die
 *        Statusvariable.
 *
 * HINWEIS (Milestone-Abgrenzung): Die WebHook-Registrierung bei KR_READY
 * (HookTrait, F3) ist Sache des Hub-Moduls und wird in einem spaeteren
 * Meilenstein als Trait eingemischt. Diese Basis stellt dafuer den
 * ueberschreibbaren Aufhaenger `onKernelReady()` bereit; sie bleibt dadurch fuer
 * die reine Contracts-Stufe eigenstaendig ladbar (php -l/Autoload).
 */
abstract class EntityModule extends \IPSModule
{
    /** Attribut fuer selten geschriebene Konfig/Profile (Store, Blocker D). */
    protected const ATTR_STORE = 'FabricStore';

    /** Attribut fuer FLUECHTIGEN manualHold-Zustand (nicht im Store! Blocker D). */
    protected const ATTR_HOLD = 'HoldState';

    /** Default-Hold-Fenster (Sekunden), wenn keine Konfig gesetzt ist. */
    protected const DEFAULT_HOLD_SECONDS = 300;

    /** Lazy-Cache der materialisierten Controls (ident => Control), pro Prozess. */
    private ?array $controlCache = null;

    /** Lazy-Store-Instanz. */
    private ?Store $storeInstance = null;

    // ==================================================================
    // Lebenszyklus
    // ==================================================================

    public function Create()
    {
        parent::Create();

        // Konfig/Profile (selten geschrieben) — Store-Ablage.
        $this->RegisterAttributeString(self::ATTR_STORE, '{}');
        // Fluechtiger manualHold-Zustand (Blocker D: NICHT in den Store).
        $this->RegisterAttributeString(self::ATTR_HOLD, '{}');

        // KR_READY abfangen (WebHook/Provision erst nach Kernel-Ready — F3).
        $this->RegisterMessage(0, IPS_KERNELMESSAGE);

        // Timer-Einrichtung ist domaenenspezifisch (async Provision-Job etc.).
        $this->setupTimers();
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Controls aus dem Manifest materialisieren: Variablen anlegen,
        // EnableAction (auch fuer command! F5).
        $this->controlCache = null;
        $this->registerControls();

        // Falls Kernel bereits laeuft, Ready-Hook sofort ausloesen.
        if (function_exists('IPS_GetKernelRunlevel') && IPS_GetKernelRunlevel() === KR_READY) {
            $this->onKernelReady();
        }
    }

    /**
     * Native Nachrichtensenke. Reagiert auf KR_READY (Kernel-Ready).
     *
     * @param mixed $sender
     * @param mixed $data
     */
    public function MessageSink($Timestamp, $Sender, $Message, $Data)
    {
        if ($Message === IPS_KERNELMESSAGE && isset($Data[0]) && $Data[0] === KR_READY) {
            $this->onKernelReady();
        }
    }

    /**
     * Konsole ist bewusst minimal: Verwaltung laeuft im LiveViewBuilder.
     */
    public function GetConfigurationForm()
    {
        $form = [
            'elements' => [
                [
                    'type'    => 'Label',
                    'caption' => 'Diese Entitaet wird vollstaendig im LiveViewBuilder verwaltet '
                        . '(Bedienung + Verwaltung ueber das Manifest). Die Konsole dient nur der '
                        . 'einmaligen Installation und Notfall-Diagnose.',
                ],
                [
                    'type'    => 'ExpansionPanel',
                    'caption' => 'Erweitert (Notfall)',
                    'items'   => [
                        [
                            'type'    => 'Label',
                            'caption' => 'Manifest/State: ueber die generischen RPC-Funktionen '
                                . '(Prefix_GetManifest / Prefix_GetState / Prefix_Manage).',
                        ],
                    ],
                ],
            ],
            'actions'  => [],
            'status'   => [],
        ];

        return json_encode($form, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    // ==================================================================
    // Vertrag 1 — finaler Bedien-Dispatch (F4)
    // ==================================================================

    /**
     * Der einzige autoritative Bedien-Eingang. Native Signatur ($Ident,$Value)
     * OHNE Typehints (SDK-Vorgabe). Faengt jeden Fehler ab (F4).
     *
     * @param string $Ident
     * @param mixed  $Value
     */
    final public function RequestAction($Ident, $Value)
    {
        try {
            $c = $this->control((string) $Ident);
            if ($c === null || !$c->actionable) {
                throw new ContractException("Control '{$Ident}' unbekannt oder nicht actionable");
            }

            // 1) Wert haerten (Typ/Range/Enum).
            $v = $c->coerce($Value);

            // 2) Provenienz: nativer Pfad ist immer 'user' (Risiko 11).
            $ctx = ActionContext::user();

            // 3) Automatik-Hoheit getrennt behandeln: nur wenn die Domaene den
            //    Ident als automatisiert markiert (A3-Default false) und es kein
            //    momentanes Kommando ist, ein manualHold-Fenster oeffnen.
            if ($c->type !== ControlContract::T_COMMAND && $this->isAutomated($c)) {
                $this->manualHold($c->ident, $this->holdSeconds());
            }

            // 4) Reflect-Politik (F4): optimistisch nur bei Nicht-Push-Treibern.
            if ($c->optimistic() && $c->varId !== null) {
                $this->SetValue($c->varId, $v);
            }

            // 5) Domaenen-Hook: realer Aktor-/Berechnungsbefehl.
            $this->applyControl($c, $v, $ctx);

            // 6) command ist transient -> Wert auf Idle zuruecksetzen (F5).
            if ($c->type === ControlContract::T_COMMAND) {
                $this->resetCommand($c);
            }

            // 7) Zustandswechsel bekanntgeben (Hub/Logging).
            $this->emitStateChanged($c->ident, $v, $ctx);
        } catch (\Throwable $e) {
            // NIE nach oben werfen (F4).
            $this->LogMessage('HS.RA ' . $Ident . ': ' . $e->getMessage(), KL_ERROR);
        }
    }

    /**
     * Domaenen-Hook: setzt den gehaerteten Wert real um (Treiber/Berechnung).
     */
    abstract protected function applyControl(Control $c, $value, ActionContext $ctx): void;

    /**
     * Domaenen-Hook: liefert den vollstaendigen Manifest-Baum (§2.2) als Array.
     *
     * @return array<string,mixed>
     */
    abstract protected function manifest(): array;

    // ==================================================================
    // RPC-Trio (-> Prefix_GetManifest / _GetState / _Manage)
    // ==================================================================

    /**
     * Vollstaendiges Manifest inkl. aktueller varIds und state-Snapshot.
     */
    public function GetManifest(): string
    {
        $m = $this->manifest();

        $m['manifestVersion'] = $m['manifestVersion'] ?? Manifest::VERSION;
        $m['instanceId']      = $this->InstanceID;

        // varIds aus den real angelegten Variablen nachziehen.
        if (isset($m['controls']) && is_array($m['controls'])) {
            foreach ($m['controls'] as &$c) {
                if (is_array($c) && isset($c['ident'])) {
                    $vid = $this->varIdOf((string) $c['ident']);
                    if ($vid !== null) {
                        $c['varId'] = $vid;
                    }
                }
            }
            unset($c);
        }

        $m['state'] = $this->stateSnapshot();

        $json = json_encode($m, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        return $json === false ? '{}' : $json;
    }

    /**
     * Live-State-Snapshot (Ident => aktueller Variablenwert).
     */
    public function GetState(): string
    {
        $json = json_encode($this->stateSnapshot(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $json === false ? '{}' : $json;
    }

    /**
     * Verwaltungs-RPC. Prueft das `op` gegen die managementActions-Whitelist
     * IM MODUL (kein Reflection-/runscript-Loch) und delegiert an `mgmt()`.
     *
     * @param string $requestJson {op,args,dryrun?,confirm?,baseVersion?}
     */
    public function Manage(string $requestJson): string
    {
        $p = json_decode($requestJson, true);
        if (!is_array($p)) {
            return $this->errJson('validation', 'ungueltiges JSON');
        }

        $op = (string) ($p['op'] ?? '');
        if ($op === '') {
            return $this->errJson('validation', 'op fehlt');
        }

        // F10: Timer-Callbacks / interne Methoden sind NIE als Verb zulaessig.
        if (strncmp($op, '__', 2) === 0) {
            return $this->errJson('forbidden', "interner op '{$op}' nicht erlaubt");
        }

        // Whitelist: nur was im Manifest als managementAction deklariert ist.
        if (!$this->isWhitelistedOp($op)) {
            return $this->errJson('op_not_whitelisted', "op '{$op}' nicht in managementActions");
        }

        $args = (isset($p['args']) && is_array($p['args'])) ? $p['args'] : [];
        $ctx  = [
            'dryrun'      => !empty($p['dryrun']),
            'confirm'     => $p['confirm'] ?? null,
            'baseVersion' => $p['baseVersion'] ?? null,
            'hard'        => !empty($p['hard']),
            'cascade'     => !empty($p['cascade']),
        ];

        try {
            $res = $this->mgmt($op, $args, $ctx);
        } catch (ContractException $e) {
            return $this->errJson('validation', $e->getMessage());
        } catch (\Throwable $e) {
            $this->LogMessage('HS.Manage ' . $op . ': ' . $e->getMessage(), KL_ERROR);
            return $this->errJson('validation', $e->getMessage());
        }

        if (!isset($res['ok'])) {
            $res['ok'] = true;
        }
        if (!isset($res['op'])) {
            $res['op'] = $op;
        }

        $json = json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        return $json === false ? $this->errJson('validation', 'Antwort nicht serialisierbar') : $json;
    }

    // ==================================================================
    // Domaenen-Helfer (protected, ueberschreibbar)
    // ==================================================================

    /**
     * Materialisiert das Control zum Ident aus dem Manifest (gecacht).
     */
    protected function control(string $ident): ?Control
    {
        $this->buildControls();
        return $this->controlCache[$ident] ?? null;
    }

    /**
     * Schreibt eine Rueckmeldung IN DIE STATUSVARIABLE — niemals in den Store
     * (Blocker D).
     *
     * @param mixed $value
     */
    protected function setReflect(string $ident, $value): void
    {
        $vid = $this->varIdOf($ident);
        if ($vid !== null) {
            $this->SetValue($vid, $value);
        }
    }

    /**
     * HAL-Treiber der Entitaet. Basis kennt keinen — Domaene ueberschreibt
     * (baut ihn ueber DriverFactory aus der Konfig). Rueckgabetyp wird erst zur
     * Laufzeit aufgeloest; die HAL-Klassen liegen in einem eigenen Meilenstein.
     */
    protected function driver(): ?HAL\IDriver
    {
        return null;
    }

    /**
     * Konfig-/Profil-Store (selten geschrieben, Semaphoren-serialisiert).
     */
    protected function store(): Store
    {
        if ($this->storeInstance === null) {
            $this->storeInstance = new Store($this, self::ATTR_STORE);
        }
        return $this->storeInstance;
    }

    /**
     * ProfileEngine (Anlegen/Bearbeiten/Zuweisen). Wird lazy aus Store +
     * Manifest-profileTypes gebaut; Klasse liegt im Engines-Meilenstein.
     */
    protected function profiles(): ProfileEngine
    {
        $m  = $this->manifest();
        $pt = (isset($m['profileTypes']) && is_array($m['profileTypes'])) ? $m['profileTypes'] : [];
        return new ProfileEngine($this->store(), $pt);
    }

    /**
     * ScheduleEngine (Slot-/Geo-/Rule-Auswertung). Klasse liegt im
     * Engines-Meilenstein.
     */
    protected function schedules(): ScheduleEngine
    {
        return new ScheduleEngine($this->store());
    }

    /**
     * Oeffnet ein manualHold-Fenster fuer einen Ident. Zustand liegt VOLATIL im
     * eigenen Attribut (Blocker D). $seconds<=0 => "sticky" bis explizitem Reset
     * (z. B. Beschattung: bis Tag/Nacht-Wechsel).
     */
    protected function manualHold(string $ident, int $seconds): void
    {
        $hold          = $this->readHold();
        $hold[$ident]  = $seconds <= 0 ? -1 : (time() + $seconds);
        $this->writeHold($hold);
    }

    /**
     * Ist der Ident aktuell manuell gehalten? Abgelaufene Eintraege gelten als
     * nicht gehalten.
     */
    protected function isManuallyHeld(string $ident): bool
    {
        $hold = $this->readHold();
        if (!array_key_exists($ident, $hold)) {
            return false;
        }
        $expiry = (int) $hold[$ident];
        return $expiry < 0 || $expiry > time();
    }

    /**
     * Loescht ein manualHold-Fenster (z. B. bei Tag/Nacht-Wechsel).
     */
    protected function clearManualHold(string $ident): void
    {
        $hold = $this->readHold();
        if (array_key_exists($ident, $hold)) {
            unset($hold[$ident]);
            $this->writeHold($hold);
        }
    }

    /**
     * Ist dieses Control automatisiert (loest manualHold aus)? BASIS-DEFAULT
     * false (A3) — die Domaene ueberschreibt gezielt.
     */
    protected function isAutomated(Control $c): bool
    {
        return false;
    }

    /**
     * Laenge des manualHold-Fensters in Sekunden (aus Konfig, sonst Default).
     */
    protected function holdSeconds(): int
    {
        $v = $this->store()->get('config.holdSeconds', null);
        if (is_numeric($v)) {
            return max(0, (int) $v);
        }
        return self::DEFAULT_HOLD_SECONDS;
    }

    /**
     * Zustandswechsel bekanntgeben. Basis: Debug-Log. Der Hub/konkrete Module
     * koennen dies erweitern (z. B. Registry benachrichtigen).
     *
     * @param mixed $value
     */
    protected function emitStateChanged(string $ident, $value, ActionContext $ctx): void
    {
        $this->SendDebug(
            'HS.StateChanged',
            $ident . '=' . (is_scalar($value) ? (string) $value : json_encode($value))
                . ' (' . $ctx->source . ')',
            0
        );
    }

    /**
     * Verwaltungs-Hook der Domaene (bereits whitelist-geprueft durch Manage()).
     * Basis liefert "not_implemented"; die Domaene setzt die Ops um.
     *
     * @param array<string,mixed> $args
     * @param array<string,mixed> $ctx
     * @return array<string,mixed>
     */
    protected function mgmt(string $op, array $args, array $ctx): array
    {
        return ['ok' => false, 'op' => $op, 'error' => 'not_implemented'];
    }

    /**
     * Domaenenspezifische Timer-Einrichtung. Basis: keine (Token/Provision-Job
     * sind Hub-Sache). Wird aus Create() aufgerufen.
     */
    protected function setupTimers(): void
    {
        // absichtlich leer
    }

    /**
     * Kernel-Ready-Hook (F3). Basis: keine Aktion. Der Hub registriert hier via
     * HookTrait seinen WebHook — dieser Trait wird in einem spaeteren
     * Meilenstein eingemischt.
     */
    protected function onKernelReady(): void
    {
        // absichtlich leer
    }

    // ==================================================================
    // Timer-Callback & Legacy-Import (SDK-oeffentlich, aber nie Manage-Verb)
    // ==================================================================

    /**
     * Timer-Callback. MUSS public sein (SDK-Zwang), heisst aber bewusst
     * `__TimerCb` und wird von Manage() ueber die `__`-Sperre nie als Verb
     * zugelassen (F10). Basis dispatcht nichts — Domaenen ueberschreiben.
     */
    public function __TimerCb(string $job): void
    {
        // absichtlich leer (Basis)
    }

    /**
     * Idempotenter Legacy-Import (kein Geraeteschreiben). Basis: nicht
     * implementiert; die Migrationslogik liegt in der Domaene + MigrateProvider.
     *
     * @param array<string,mixed> $spec
     * @return array<string,mixed>
     */
    public function ImportLegacy(array $spec): array
    {
        return ['ok' => false, 'error' => 'not_implemented'];
    }

    // ==================================================================
    // interne Mechanik
    // ==================================================================

    /**
     * Baut den Control-Cache aus dem Manifest (ident => Control, varId gefuellt).
     */
    private function buildControls(): void
    {
        if ($this->controlCache !== null) {
            return;
        }
        $this->controlCache = [];

        $m        = $this->manifest();
        $controls = (isset($m['controls']) && is_array($m['controls'])) ? $m['controls'] : [];

        foreach ($controls as $descriptor) {
            if (!is_array($descriptor) || !isset($descriptor['ident'])) {
                continue;
            }
            $c = Control::fromArray($descriptor);
            // varId aus der real angelegten Variable nachziehen.
            if ($c->varId === null) {
                $c->varId = $this->varIdOf($c->ident);
            }
            $this->controlCache[$c->ident] = $c;
        }
    }

    /**
     * Legt fuer jedes Manifest-Control die Statusvariable an und aktiviert bei
     * actionable Controls die native RequestAction (auch fuer command! F5).
     */
    private function registerControls(): void
    {
        $m        = $this->manifest();
        $controls = (isset($m['controls']) && is_array($m['controls'])) ? $m['controls'] : [];

        $pos = 0;
        foreach ($controls as $descriptor) {
            if (!is_array($descriptor) || !isset($descriptor['ident'])) {
                continue;
            }
            $c = Control::fromArray($descriptor);
            $profile = $c->profile ?? '';

            switch ($c->varType) {
                case 0:
                    $this->RegisterVariableBoolean($c->ident, $c->label, $profile, $pos);
                    break;
                case 1:
                    $this->RegisterVariableInteger($c->ident, $c->label, $profile, $pos);
                    break;
                case 2:
                    $this->RegisterVariableFloat($c->ident, $c->label, $profile, $pos);
                    break;
                case 3:
                default:
                    $this->RegisterVariableString($c->ident, $c->label, $profile, $pos);
                    break;
            }

            // F5: JEDES actionable Control (command inklusive) bekommt EnableAction.
            if ($c->actionable) {
                $this->EnableAction($c->ident);
            }

            $pos++;
        }
    }

    /**
     * Liest den aktuellen Wert aller Controls, die eine Statusvariable haben.
     *
     * @return array<string,mixed>
     */
    private function stateSnapshot(): array
    {
        $this->buildControls();
        $state = [];
        foreach ($this->controlCache as $ident => $c) {
            $vid = $c->varId ?? $this->varIdOf($ident);
            if ($vid !== null) {
                try {
                    $state[$ident] = $this->GetValue($vid);
                } catch (\Throwable $e) {
                    // Variable ohne Wert -> auslassen.
                }
            }
        }
        return $state;
    }

    /**
     * Setzt ein command-Control auf den Idle-Code zurueck (F5, transient).
     */
    private function resetCommand(Control $c): void
    {
        $vid = $c->varId ?? $this->varIdOf($c->ident);
        if ($vid !== null) {
            try {
                $this->SetValue($vid, ControlContract::CMD_IDLE);
            } catch (\Throwable $e) {
                // Idle-Reset ist best-effort; Fehler nicht eskalieren.
            }
        }
    }

    /**
     * Prueft, ob $op als managementAction im Manifest deklariert ist.
     */
    private function isWhitelistedOp(string $op): bool
    {
        $m       = $this->manifest();
        $actions = (isset($m['managementActions']) && is_array($m['managementActions']))
            ? $m['managementActions'] : [];

        foreach ($actions as $a) {
            if (is_array($a) && (($a['op'] ?? null) === $op || ($a['verb'] ?? null) === $op)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Objekt-ID einer Statusvariable per Ident (oder null, wenn nicht vorhanden).
     */
    private function varIdOf(string $ident): ?int
    {
        try {
            $id = @$this->GetIDForIdent($ident);
            return (is_int($id) && $id > 0) ? $id : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Liest den volatilen manualHold-Zustand (eigenes Attribut, Blocker D).
     *
     * @return array<string,int>
     */
    private function readHold(): array
    {
        try {
            $raw = (string) $this->ReadAttributeString(self::ATTR_HOLD);
        } catch (\Throwable $e) {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Schreibt den volatilen manualHold-Zustand.
     *
     * @param array<string,int> $hold
     */
    private function writeHold(array $hold): void
    {
        $json = json_encode($hold);
        $this->WriteAttributeString(self::ATTR_HOLD, $json === false ? '{}' : $json);
    }

    /**
     * Einheitliche Fehler-Antwort (Response-Vertrag §6.3).
     */
    private function errJson(string $error, string $detail): string
    {
        return json_encode(
            ['ok' => false, 'error' => $error, 'detail' => $detail],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ) ?: '{"ok":false,"error":"validation"}';
    }
}

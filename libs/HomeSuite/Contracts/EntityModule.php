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
 * SKRIPT-API: Neben dem RPC-Trio (PREFIX_GetManifest/_GetState/_Manage) stellt
 * die Basis eine typisierte Scripting-Fassade bereit, die alle Kindmodule erben:
 *   - `PREFIX_SetControl($id, string $Ident, $Value): bool` — actionable Control
 *     aus einem Skript setzen (validiert vor, delegiert an RequestAction).
 *   - `PREFIX_GetControlValue($id, string $Ident)` — aktuellen Statuswert lesen.
 * Die Domaenenmodule ergaenzen duenne, typisierte Wrapper (PREFIX_SetSetpoint,
 * PREFIX_SetVolume …) darauf; alle gehen ueber RequestAction -> applyControl
 * (armed-Gate/Reflect/Reconcile bleiben konsistent). Referenz je Modul: README.md.
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

    /** Attribut fuer volatilen Laufzeit-Status (last-commanded, pushHash, watchVid). */
    protected const ATTR_RT = 'RtState';

    /** Default-Hold-Fenster (Sekunden), wenn keine Konfig gesetzt ist. */
    protected const DEFAULT_HOLD_SECONDS = 300;

    /** Lazy-Cache der materialisierten Controls (ident => Control), pro Prozess. */
    private ?array $controlCache = null;

    /** Lazy-Store-Instanz. */
    private ?Store $storeInstance = null;

    /**
     * Lazy-Cache Ident => ObjektID fuer den GESAMTEN Instanz-Teilbaum (inkl.
     * Gruppen-Kategorien). Ermoeglicht subtree-faehige Ident-Aufloesung, damit
     * in Kategorien einsortierte Statusvariablen weiter per Ident gefunden werden.
     */
    private ?array $identMap = null;

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
        // Volatiler Laufzeit-Status (last-commanded/pushHash/watchVid).
        $this->RegisterAttributeString(self::ATTR_RT, '{}');

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
        $this->identMap     = null;
        if ($this->usesVariableGroups()) {
            // Statusvariablen in beschriftete Kategorien einsortieren (IDs bleiben
            // erhalten). Ersetzt registerControls(), das per SDK-RegisterVariable
            // gruppierte Variablen sonst flach NEU anlegen wuerde (nur direkte
            // Kinder werden gefunden) -> Dubletten.
            $this->materializeControlsGrouped();
        } else {
            $this->registerControls();
        }

        // Baum-Transparenz: sichtbare Links auf die gebundenen Quell-Variablen/
        // Aktoren anlegen/pflegen, damit im Symcon-Objektbaum nachvollziehbar ist,
        // was die Entitaet liest/schaltet (generisch aus der Store-Konfig).
        $this->syncBindingLinks();

        // Baum-Sichtbarkeit fuer verschachtelte Store-Daten (Zeitplaene/Szenen/
        // Automatik): read-only Spiegel-Variablen (JSON). Domaenen ueberschreiben
        // refreshMirrors(); Default: nichts.
        $this->refreshMirrors();

        // Baum-Klarheit: Entitaets-Typ als Suffix im Instanznamen "(Licht)" etc.
        $this->ensureEntitySuffix();

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
                // IPSModule::SetValue erwartet den IDENT der eigenen Variable, NICHT die Objekt-ID.
                $this->SetValue($c->ident, $v);
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

    /**
     * Domaenen-Label fuer den Baum-Suffix (z. B. 'Licht', 'Beschattung', 'Audio',
     * 'Heizung'). Leer = kein Suffix (z. B. Hub). Domaenen ueberschreiben.
     */
    protected function entityLabel(): string
    {
        return '';
    }

    /** Haengt den Entitaets-Typ als "(Label)" an den Instanznamen an (idempotent). */
    private function ensureEntitySuffix(): void
    {
        $lbl = trim($this->entityLabel());
        if ($lbl === '' || !function_exists('IPS_SetName')) {
            return;
        }
        $suffix = ' (' . $lbl . ')';
        $name   = (string) @\IPS_GetName($this->InstanceID);
        if ($name === '' || substr($name, -strlen($suffix)) === $suffix) {
            return; // leer oder bereits vorhanden -> nichts tun
        }
        @\IPS_SetName($this->InstanceID, $name . $suffix);
    }

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
    // Oeffentliche Scripting-Fassade (-> Prefix_SetControl / _GetControlValue)
    //
    // Einheitlicher, script-tauglicher Eingang: RequestAction ist als reservierter
    // SDK-Name NICHT als Prefix-Funktion aufrufbar; diese Methoden fuellen die Luecke.
    // Setzen geht IMMER ueber RequestAction -> applyControl (armed-Gate, Wert-Haertung,
    // manualHold, Reflect, Reconcile bleiben konsistent). NIE direkt SetValue am Geraet.
    // Die typisierten Domaenen-Wrapper (Prefix_SetSetpoint …) delegieren hierher.
    // ==================================================================

    /**
     * Setzt einen actionable Control per Ident aus einem Skript.
     * Validiert VORHER (ehrlicher bool, da RequestAction void ist) und delegiert
     * dann an den einen autoritativen Bedienpfad.
     *
     * @return bool true = validiert und dispatcht; false = unbekannt/nicht actionable
     *              oder Wert unzulaessig (coerce-Fehler). Realer Effekt nur bei
     *              Armed=true + gebundenem Treiber (sonst Schatten-Modus).
     */
    public function SetControl(string $Ident, string $Value): bool
    {
        // Oeffentliche Fassade: die Symcon-Funktionsbibliothek verlangt fuer Prefix-
        // Funktionen skalar-typisierte Parameter (bool/int/float/string) — daher hier
        // string; die Wert-Haertung (coerce) wandelt in den echten Control-Typ. Interne,
        // typisierte Wrapper rufen setControlValue() (untypisiert) direkt.
        return $this->setControlValue($Ident, $Value);
    }

    /** Interner, untypisierter Setz-Pfad (nicht exponiert -> beliebiger Wertetyp erlaubt). */
    /**
     * Bedienwert setzen - und den WIRKLICHEN Ausgang zurueckgeben.
     *
     * Hier stand frueher ein bedingungsloses `return true` nach dem RequestAction. Das war
     * keine Aussage, sondern ein Versprechen ins Blaue: jedes Skript, jede Visu und jeder
     * Aufruf der Prefix-Funktionen bekam "erfolgreich" gemeldet, sobald der Befehl
     * ANGENOMMEN war - nicht, wenn er gewirkt hatte. Am 09.09.2026 meldeten so sechzehn
     * Rollo-Befehle hintereinander Erfolg, waehrend kein einziges Telegramm den Socket
     * verliess.
     *
     * RequestAction gibt in Symcon nichts zurueck; das Ergebnis kommt deshalb ueber
     * $applyOk, das die Domaene in applyControl setzt. Domaenen, die es nicht setzen,
     * verhalten sich wie bisher (Vorbelegung true) - der Umbau ist damit fuer alle anderen
     * Domaenen wirkungsfrei, bis sie ihn selbst nutzen.
     */
    protected function setControlValue(string $Ident, $Value): bool
    {
        $c = $this->control($Ident);
        if ($c === null || !$c->actionable) {
            $this->LogMessage("HS.SetControl: '{$Ident}' unbekannt/nicht actionable", KL_ERROR);
            return false;
        }
        try {
            $c->coerce($Value); // Vor-Check -> ehrlicher Rueckgabewert
        } catch (\Throwable $e) {
            $this->LogMessage("HS.SetControl '{$Ident}': " . $e->getMessage(), KL_ERROR);
            return false;
        }
        $this->applyOk = true;                // Vorbelegung: wer nichts meldet, gilt als gelungen
        $this->RequestAction($Ident, $Value); // EINZIGER Bedienpfad (coerct erneut)
        return $this->applyOk;
    }

    /**
     * Ergebnis des letzten applyControl-Durchlaufs.
     *
     * Bewusst eine schlichte Eigenschaft und kein Rueckgabewert von applyControl: die
     * Signatur ist Vertragsbestandteil aller Domaenen, und ein Domaenen-Hook, der ploetzlich
     * etwas zurueckgeben MUSS, waere ein Bruch. So meldet, wer etwas zu melden hat.
     */
    protected bool $applyOk = true;

    /**
     * Liest den aktuellen Statuswert eines Controls per Ident.
     *
     * @return mixed Wert oder null (kein solches Control / keine Variable / leer).
     */
    public function GetControlValue(string $Ident)
    {
        if ($this->control($Ident) === null || $this->GetIDForIdent($Ident) === false) {
            return null;
        }
        try {
            return $this->GetValue($Ident);
        } catch (\Throwable $e) {
            return null;
        }
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
        if ($this->varIdOf($ident) !== null) {
            $this->SetValue($ident, $value);   // per IDENT, nicht Objekt-ID
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
     * GLOBALER Automatik-Schalter (HomeSuite-weit). Liest das Hub-Flag
     * 'AutomationEnabled'. FAIL-SAFE: true, wenn kein Hub/Flag existiert -> die
     * Automatik laeuft im Zweifel weiter. Jede Domaene ruft dies in ihrem
     * Automatik-/Reconcile-Pfad; Safety (z. B. Sturm) bleibt davon unberuehrt.
     */
    protected function automationEnabled(): bool
    {
        if (!function_exists('IPS_GetInstanceListByModuleID')) {
            return true;
        }
        $hubs = @\IPS_GetInstanceListByModuleID('{A0C082B4-9E74-430E-BD97-F9CEBB364257}');
        if (!is_array($hubs) || $hubs === []) {
            return true;
        }
        $vid = @\IPS_GetObjectIDByIdent('AutomationEnabled', (int) $hubs[0]);
        if (!$vid) {
            return true;
        }
        return @\GetValue($vid) === false ? false : true;
    }

    /** InstanzID des zentralen HomeSuite-Hub (0 wenn keiner). */
    protected function hubInstanceId(): int
    {
        if (!function_exists('IPS_GetInstanceListByModuleID')) {
            return 0;
        }
        $hubs = @\IPS_GetInstanceListByModuleID('{A0C082B4-9E74-430E-BD97-F9CEBB364257}');
        return (is_array($hubs) && $hubs !== []) ? (int) $hubs[0] : 0;
    }

    /** Hub-Variablen-Ident des domaenenweiten Scharf-Schalters ('' = kein Master). */
    protected function armGateIdent(): string
    {
        $m = ['Licht' => 'ArmLight', 'Heizung' => 'ArmHeating', 'Beschattung' => 'ArmShading',
              'Bewässerung' => 'ArmIrrigation', 'Audio' => 'ArmAudio', 'Pool' => 'ArmPool', 'Mäher' => 'ArmMower'];
        return $m[$this->entityLabel()] ?? '';
    }

    /** Liest den domaenenweiten Scharf-Master aus dem Hub; null wenn (noch) nicht vorhanden. */
    protected function hubArmGate(): ?bool
    {
        $id = $this->armGateIdent();
        if ($id === '') {
            return null;
        }
        $hub = $this->hubInstanceId();
        if ($hub <= 0 || !function_exists('IPS_GetObjectIDByIdent')) {
            return null;
        }
        // 3-Zustand-Master (Vorrang): <ident>Mode 0=Aus(alle Schatten), 1=Auto(per-Instanz), 2=Scharf(alle real).
        $mv = @\IPS_GetObjectIDByIdent($id . 'Mode', $hub);
        if (is_int($mv) && $mv > 0) {
            $m = (int) @\GetValue($mv);
            return $m === 1 ? null : ($m >= 2); // Auto -> null (Einzelinstanz entscheidet)
        }
        // Fallback: alter Bool-Master (ON=alle real, OFF=alle Schatten).
        $gv = @\IPS_GetObjectIDByIdent($id, $hub);
        if (!is_int($gv) || $gv <= 0) {
            return null;
        }
        return (bool) @\GetValue($gv);
    }

    /** Scharf-Zustand: Hub-Master hat Vorrang (Autoritaet), sonst per-Instanz-Wert. */
    protected function armedEffective(bool $own): bool
    {
        $g = $this->hubArmGate();
        return $g === null ? $own : $g;
    }

    /**
     * Globale (domaenenweite) Einstellung aus dem zentralen Hub-Config-Formular
     * (native Hub-Property, extern via IPS_GetProperty lesbar). Liefert $default,
     * wenn kein Hub existiert oder die Property (noch) fehlt. So teilen sich alle
     * Instanzen einer Domaene EINE Einstellung, statt sie pro Instanz zu duplizieren.
     */
    protected function hubProp(string $name, $default)
    {
        $hid = $this->hubInstanceId();
        if ($hid <= 0 || !function_exists('IPS_GetProperty')) {
            return $default;
        }
        $v = @\IPS_GetProperty($hid, $name);
        return $v === false ? $default : $v;
    }

    /** Wie hubProp, aber bool-sicher (unterscheidet echtes false vom fehlenden Property nicht -> nach Hub-Reload korrekt). */
    protected function hubPropBool(string $name, bool $default): bool
    {
        $hid = $this->hubInstanceId();
        if ($hid <= 0 || !function_exists('IPS_GetProperty')) {
            return $default;
        }
        $v = @\IPS_GetProperty($hid, $name);
        return is_bool($v) ? $v : $default;
    }

    /** Globale Objekt-ID mit Instanz-Override: Instanzwert>0 gewinnt, sonst Hub-Global, sonst $default. */
    protected function globalId(int $instanceVal, string $hubProp, int $default = 0): int
    {
        if ($instanceVal > 0) {
            return $instanceVal;
        }
        $g = (int) $this->hubProp($hubProp, 0);
        return $g > 0 ? $g : $default;
    }

    /**
     * Baum-Sichtbarkeit fuer verschachtelte Store-Daten (Zeitplaene/Szenen/Automatik):
     * legt/aktualisiert eine read-only String-Variable mit dem JSON. Store bleibt die
     * Editier-Wahrheit (ueber die Editoren); die Variable macht den Inhalt im Baum
     * nachvollziehbar. Idempotent.
     */
    protected function mirrorVar(string $ident, string $name, $data, int $pos = 95): void
    {
        @$this->RegisterVariableString($ident, $name, '', $pos);
        $json = is_string($data) ? $data : (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        @$this->SetValue($ident, $json);
    }

    /** Domaenen-Hook: Spiegel-Variablen fuer verschachtelte Store-Daten pflegen (Default: nichts). */
    protected function refreshMirrors(): void
    {
    }

    // ==================================================================
    // Baum-Transparenz: sichtbare Links auf gebundene Quell-Objekte
    // (generisch aus der Store-Konfig; kein modul-spezifischer Code noetig).
    // ==================================================================

    /** Lesbare Labels bekannter Bindungs-Konfigschluessel (Fallback: prettify). */
    private const BIND_LABELS = [
        'switchVarId' => 'Schalter', 'levelVarId' => 'Helligkeit', 'colorVarId' => 'Farbe',
        'cctVarId' => 'Farbtemperatur', 'wattVarId' => 'Leistung', 'onScriptId' => 'Ein-Skript',
        'offScriptId' => 'Aus-Skript', 'trealSourceVarId' => 'Wassertemperatur extern',
        'positionVarId' => 'Position', 'slatVarId' => 'Lamelle', 'setpointVarId' => 'Sollwert',
        'airTempVarId' => 'Ist-Temperatur', 'humidityVarId' => 'Luftfeuchte', 'presenceVarId' => 'Praesenz',
        'valveVarId' => 'Ventil', 'powerVarId' => 'Leistung', 'startScriptId' => 'Start-Skript',
        'stopScriptId' => 'Stop-Skript', 'sourceVarId' => 'Quelle',
    ];

    /**
     * Sammelt gebundene Quell-Objekte aus der Store-Konfig. Erkennt Schluessel mit
     * Suffix *VarId/*VariableID/*VariablenID/*ScriptId (Wert = existierende Objekt-ID).
     * Domaenen koennen dies ueberschreiben/ergaenzen.
     *
     * @return array<int,array{ident:string,name:string,targetId:int}>
     */
    protected function bindingTargets(): array
    {
        $cfg = $this->store()->get('config', []);
        if (!is_array($cfg)) {
            return [];
        }
        $out = [];
        $scan = function (array $arr, string $prefix) use (&$scan, &$out): void {
            foreach ($arr as $k => $v) {
                if (is_array($v)) {
                    $scan($v, $prefix . $k . '_');
                    continue;
                }
                $key = (string) $k;
                if (!preg_match('/(VarId|VariableID|VariablenID|ScriptId|ScriptID)$/', $key)) {
                    continue;
                }
                $id = is_numeric($v) ? (int) $v : 0;
                if ($id <= 0 || !(function_exists('IPS_ObjectExists') && @\IPS_ObjectExists($id))) {
                    continue;
                }
                $out[] = [
                    'ident'    => 'bl_' . $prefix . $key,
                    'name'     => self::BIND_LABELS[$key] ?? $this->prettifyBindKey($key),
                    'targetId' => $id,
                ];
            }
        };
        $scan($cfg, '');
        return $out;
    }

    private function prettifyBindKey(string $key): string
    {
        $key = (string) preg_replace('/(VarId|VariableID|VariablenID|ScriptId|ScriptID)$/', '', $key);
        $key = (string) preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', $key);
        return ucfirst(trim($key)) ?: 'Bindung';
    }

    /** Legt/pflegt sichtbare Links (ObjectType 6) auf die gebundenen Quell-Objekte (idempotent). */
    protected function syncBindingLinks(): void
    {
        if (!function_exists('IPS_CreateLink') || !function_exists('IPS_GetChildrenIDs')) {
            return;
        }
        $wanted = [];
        foreach ($this->bindingTargets() as $t) {
            $ident = (string) preg_replace('/[^A-Za-z0-9_]/', '', (string) $t['ident']);
            if ($ident !== '') {
                $wanted[$ident] = $t;
            }
        }
        // Von UNS verwaltete Links (ident-Prefix bl_) einsammeln; verwaiste entfernen.
        foreach ((array) @\IPS_GetChildrenIDs($this->InstanceID) as $cid) {
            $o = @\IPS_GetObject($cid);
            if (!is_array($o) || (int) ($o['ObjectType'] ?? 0) !== 6) {
                continue;
            }
            $id = (string) ($o['ObjectIdent'] ?? '');
            if (strncmp($id, 'bl_', 3) === 0 && !isset($wanted[$id])) {
                @\IPS_DeleteLink($cid);
            }
        }
        // Anlegen/aktualisieren.
        foreach ($wanted as $ident => $t) {
            $lid = @$this->GetIDForIdent($ident);
            if (!(is_int($lid) && $lid > 0)) {
                $lid = @\IPS_CreateLink();
                if (!$lid) {
                    continue;
                }
                @\IPS_SetParent($lid, $this->InstanceID);
                @\IPS_SetIdent($lid, $ident);
            }
            @\IPS_SetName($lid, (string) $t['name']);
            @\IPS_SetLinkTargetID($lid, (int) $t['targetId']);
        }
    }

    // ==================================================================
    // GENERISCHE REGELN (wiederverwendbar in allen Domaenen)
    // Sonnen-Anker (Auf/Untergang ± Offset) stecken bereits in resolveEnd/sunEvents/
    // scheduleValueAt. Hier ergaenzt: Schwellen-Ueberschreibung (Temperatur).
    // ==================================================================

    /**
     * Temperatur-Faktor aus einer Schwellen-Regel. PURE.
     * $cfg {enabled,coldBelowC,coldPct,hotAboveC,hotPct}; >=hotAboveC -> hotPct%,
     * < coldBelowC -> coldPct%, sonst 100 %. Kein $temp/deaktiviert -> 1.0.
     */
    protected function ruleTempFactor(array $cfg, ?float $temp): float
    {
        if (empty($cfg['enabled']) || $temp === null) {
            return 1.0;
        }
        if (isset($cfg['hotAboveC']) && $temp >= (float) $cfg['hotAboveC']) {
            return max(0.0, (float) ($cfg['hotPct'] ?? 100) / 100.0);
        }
        if (isset($cfg['coldBelowC']) && $temp < (float) $cfg['coldBelowC']) {
            return max(0.0, (float) ($cfg['coldPct'] ?? 100) / 100.0);
        }
        return 1.0;
    }

    /** Harte Sperre unterhalb einer Schwelle (z. B. Frost). PURE. */
    protected function ruleBlockBelow(array $cfg, ?float $temp): bool
    {
        if (empty($cfg['enabled']) || $temp === null || !isset($cfg['blockBelowC'])) {
            return false;
        }
        return $temp < (float) $cfg['blockBelowC'];
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
        switch ($op) {
            case 'syncStatus':
                return $this->opSyncStatus();
            case 'loadFromDevice':
                return $this->opLoadFromDevice();
            case 'syncToDevice':
                return $this->opSyncToDevice();
        }
        return ['ok' => false, 'op' => $op, 'error' => 'not_implemented'];
    }

    // ==================================================================
    // Generischer Geraete-Zeitplan-Sync (device-Modus) — von ALLEN Domaenen
    // geerbt. Delegiert an den Treiber (readWeekProfile/writeWeekProfile) und
    // die ScheduleEngine. Bei controller-Modus/keinem Treiber: applicable=false.
    // ==================================================================

    /** Zeitplan-Varianten der Domaene (z. B. Heizung: Praesenzen). Default: eine. */
    protected function scheduleVariants(): array
    {
        return ['Standard'];
    }

    /** Index der aktiven Variante (Domaene ueberschreibt, z. B. Presence). */
    protected function activeVariantIndex(): int
    {
        return 0;
    }

    // ==================================================================
    // Sonnen-verankerte Zeitplan-Grenzen (universell, jede controller-Domaene)
    // ==================================================================

    /** Lat/Lon fuer Sonnenzeiten: ZENTRALER Standort am Hub (globales Config-Formular); Fallback Hauskoords. */
    protected function sunCoords(): array
    {
        if ((string) $this->hubProp('SunSource', 'location') === 'coords') {
            $lat = (float) $this->hubProp('Lat', 0.0);
            $lon = (float) $this->hubProp('Lon', 0.0);
            if ($lat != 0.0 || $lon != 0.0) {
                return [$lat, $lon];
            }
        }
        $lid = (int) $this->hubProp('LocationId', 0);
        if ($lid <= 0) {
            $lid = 0;     // kein Rueckfall auf eine feste ID - fremde Anlagen haetten hier eine andere Instanz
        }
        if ($lid > 0 && function_exists('IPS_InstanceExists') && @\IPS_InstanceExists($lid)) {
            $c   = json_decode((string) @\IPS_GetConfiguration($lid), true);
            $loc = is_array($c) ? json_decode((string) ($c['Location'] ?? 'null'), true) : null;
            if (is_array($loc) && isset($loc['latitude'], $loc['longitude'])) {
                return [(float) $loc['latitude'], (float) $loc['longitude']];
            }
        }
        return [48.2082, 16.3738];
    }

    /** Sonnen-Ereigniszeiten (Minuten seit lokaler Mitternacht) fuer den Tag von $ts. */
    protected function sunEvents(int $ts): array
    {
        [$lat, $lon] = $this->sunCoords();
        return SunTimes::eventsMinutes($ts, $lat, $lon);
    }

    /** Slot-Grenze aufloesen: Sonnen-Anker (+Offset, geklemmt) ODER feste Minute. */
    protected function resolveEnd(array $slot, array $sun): int
    {
        $anchor = isset($slot['anchor']) ? (string) $slot['anchor'] : '';
        if ($anchor !== '' && isset($sun[$anchor]) && $sun[$anchor] !== null) {
            return max(0, min(1440, (int) $sun[$anchor] + (int) ($slot['offset'] ?? 0)));
        }
        return max(0, min(1440, (int) ($slot['end'] ?? 1440)));
    }

    /**
     * Aktiver Slot-WERT zum Zeitpunkt $ts mit AUFGELOESTEN Sonnen-Ankern (generisch:
     * Temperatur/Position). Ersetzt ScheduleEngine::eval() dort, wo Grenzen an Sonnen-
     * ereignisse gebunden sein koennen (controller-Modus). @return mixed|null
     */
    protected function scheduleValueAt(int $ts, string $variant)
    {
        $day   = (int) date('N', $ts) - 1;
        $slots = $this->schedules()->getSlots($variant, $day);
        if ($slots === []) {
            return null;
        }
        $sun = $this->sunEvents($ts);
        $res = [];
        foreach ($slots as $s) {
            $res[] = ['end' => $this->resolveEnd($s, $sun), 'val' => $s['val'] ?? null];
        }
        usort($res, static fn($a, $b) => $a['end'] - $b['end']);
        $minNow = ((int) date('G', $ts)) * 60 + (int) date('i', $ts);
        foreach ($res as $s) {
            if ($minNow < $s['end']) {
                return $s['val'];
            }
        }
        $last = end($res);
        return $last['val'];
    }

    /** Sekunden bis zur naechsten (aufgeloesten) Slot-Grenze der Variante (min. 60s). */
    protected function secondsToNextBoundary(string $variant): int
    {
        $now    = time();
        $day    = (int) date('N', $now) - 1;
        $minNow = ((int) date('G', $now)) * 60 + (int) date('i', $now);
        $sun    = $this->sunEvents($now);
        $ends   = [];
        foreach ($this->schedules()->getSlots($variant, $day) as $slot) {
            $ends[] = $this->resolveEnd($slot, $sun);
        }
        sort($ends);
        foreach ($ends as $end) {
            if ($end > $minNow) {
                return max(60, ($end - $minNow) * 60);
            }
        }
        return max(60, (1440 - $minNow) * 60);
    }

    /** syncStatus — vergleicht Modul-Wochenplan (aktive Variante) mit dem Geraet. */
    protected function opSyncStatus(): array
    {
        $drv = $this->driver();
        if (!$drv instanceof HAL\IThermostat) {
            return ['ok' => true, 'applicable' => false, 'mode' => 'none'];
        }
        $caps = $drv->capabilities();
        if (($caps['scheduleMode'] ?? '') !== 'device') {
            return ['ok' => true, 'applicable' => false, 'mode' => (string) ($caps['scheduleMode'] ?? 'controller')];
        }
        $pi      = $this->activeVariantIndex();
        $variant = $this->scheduleVariants()[$pi] ?? 'Standard';
        $mod     = $this->schedules()->toHomematicWeek($variant, (int) ($caps['rasterMinutes'] ?? 10), (int) ($caps['maxSlots'] ?? 13));
        $dev     = $drv->readWeekProfile($pi);
        $synced  = $this->weeksEqual($mod, $dev);
        return ['ok' => true, 'applicable' => true, 'mode' => 'device', 'synced' => $synced, 'variant' => $variant];
    }

    /** loadFromDevice — liest das Geraeteprogramm je Variante in den Modul-Store (kein Geraetewrite). */
    protected function opLoadFromDevice(): array
    {
        $drv = $this->driver();
        if (!$drv instanceof HAL\IThermostat) {
            return ['ok' => false, 'error' => 'kein Treiber'];
        }
        if (($drv->capabilities()['scheduleMode'] ?? '') !== 'device') {
            return ['ok' => false, 'error' => 'nur im device-Modus'];
        }
        $eng     = $this->schedules();
        $adopted = [];
        foreach ($this->scheduleVariants() as $pi => $variant) {
            $week = $drv->readWeekProfile((int) $pi);
            $days = 0;
            foreach ($week as $di => $slots) {
                if (is_array($slots) && $slots !== []) {
                    $eng->setSlots($variant, (int) $di, $slots);
                    $days++;
                }
            }
            $adopted[$variant] = $days;
        }
        $this->seedPushHash($drv);
        return ['ok' => true, 'source' => 'device', 'adopted' => $adopted];
    }

    /** syncToDevice — schreibt den Modul-Wochenplan (aktive Variante) ins Geraet (Backup/Verify im Treiber). */
    protected function opSyncToDevice(): array
    {
        $drv = $this->driver();
        if (!$drv instanceof HAL\IThermostat) {
            return ['ok' => false, 'error' => 'kein Treiber'];
        }
        $caps = $drv->capabilities();
        if (($caps['scheduleMode'] ?? '') !== 'device') {
            return ['ok' => false, 'error' => 'nur im device-Modus'];
        }
        $pi      = $this->activeVariantIndex();
        $variant = $this->scheduleVariants()[$pi] ?? 'Standard';
        $week    = $this->schedules()->toHomematicWeek($variant, (int) ($caps['rasterMinutes'] ?? 10), (int) ($caps['maxSlots'] ?? 13));
        $wrote   = $drv->writeWeekProfile($week, $pi);
        if ($wrote) {
            $this->seedPushHash($drv);
        }
        return ['ok' => (bool) $wrote, 'wrote' => (bool) $wrote, 'variant' => $variant];
    }

    /** Setzt den pushHash der aktiven Variante -> Reconciler schreibt das identische Programm nicht erneut. */
    protected function seedPushHash(HAL\IThermostat $drv): void
    {
        $caps    = $drv->capabilities();
        $pi      = $this->activeVariantIndex();
        $variant = $this->scheduleVariants()[$pi] ?? 'Standard';
        $week    = $this->schedules()->toHomematicWeek($variant, (int) ($caps['rasterMinutes'] ?? 10), (int) ($caps['maxSlots'] ?? 13));
        $rt      = $this->readRt();
        $hash    = md5($variant . '|' . json_encode($week));
        // Zusaetzlich je Variante merken: das Geraet fuehrt mehrere Profile, und ein
        // Praesenzwechsel darf spaeter nicht wie ein geaenderter Plan aussehen.
        $map = is_array($rt['pushHashes'] ?? null) ? $rt['pushHashes'] : [];
        $map[$variant]     = $hash;
        $rt['pushHashes']  = $map;
        $rt['pushHash']    = $hash;
        $this->writeRt($rt);
    }

    /** Vergleicht zwei Wochenstrukturen [day => [{end,val}]] mit Toleranz. */
    protected function weeksEqual(array $a, array $b): bool
    {
        return self::weekCurve($a) === self::weekCurve($b);
    }

    /**
     * Tagesverlauf statt Slot-Liste.
     *
     * Verglichen wird, WAS der Tag tut, nicht in wie vielen Stufen er es
     * aufschreibt. Ein Geraet fuehrt dieselbe Kurve oft feiner unterteilt als der
     * Plan sie schreibt: zwei benachbarte Slots mit demselben Wert (17:30 auf 18
     * Grad, 22:30 auf 18 Grad) sind derselbe Verlauf wie ein Slot bis 22:30. Der
     * fruehere Vergleich zaehlte erst die Slots und erklaerte solche Tage fuer
     * verschieden - bei der Heizung galten dadurch 14 von 23 Zonen als abweichend,
     * obwohl beide Seiten dasselbe fuhren.
     *
     * @param array<int,array<int,array{end:int,val:mixed}>> $w
     * @return array<int,string>
     */
    private static function weekCurve(array $w): array
    {
        $out = [];
        for ($d = 0; $d < 7; $d++) {
            $tag   = [];
            $letzt = null;
            foreach (($w[$d] ?? []) as $slot) {
                if (!is_array($slot)) {
                    continue;
                }
                $end = (int) ($slot['end'] ?? 0);
                $val = number_format((float) ($slot['val'] ?? 0), 1, '.', '');
                if ($end <= 0) {
                    continue;
                }
                if ($letzt !== null && $letzt === $val && $tag !== []) {
                    array_pop($tag);            // gleicher Wert -> Grenze faellt weg
                }
                $tag[]  = $end . ':' . $val;
                $letzt  = $val;
                if ($end >= 1440) {
                    break;                      // alles dahinter ist Auffuellung
                }
            }
            $out[$d] = implode(',', $tag);
        }
        return $out;
    }

    /** Volatiler Laufzeit-Status (eigenes Attribut, Blocker D). */
    protected function readRt(): array
    {
        try {
            $raw = (string) $this->ReadAttributeString(self::ATTR_RT);
        } catch (\Throwable $e) {
            return [];
        }
        $d = json_decode($raw, true);
        return is_array($d) ? $d : [];
    }

    protected function writeRt(array $rt): void
    {
        $j = json_encode($rt);
        $this->WriteAttributeString(self::ATTR_RT, $j === false ? '{}' : $j);
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
     * Leitet aus den `options` eines select-Controls ein IPS-Variablenprofil ab
     * und gibt dessen Namen zurueck ('' wenn nicht moeglich).
     *
     * Der Name ist bewusst je Domaene und Ident stabil (`HS.<domain>.<Ident>`):
     * alle Zonen einer Domaene teilen dieselben Beschriftungen, also genuegt EIN
     * Profil fuer alle Instanzen - und ein spaeter umbenannter Eintrag wirkt
     * ueberall zugleich. Idempotent: die Verknuepfungen werden nur geschrieben,
     * wenn sie tatsaechlich abweichen.
     */
    private function profilAusOptionen(Control $c, array $descriptor = []): string
    {
        // Nur ganzzahlige Auswahlen; ein Profil ist an den Variablentyp gebunden.
        if ($c->options === [] || (int) $c->varType !== 1) {
            return '';
        }
        $m      = $this->manifest();
        $domain = (string) ($m['domain'] ?? '');
        if ($domain === '' || $c->ident === '') {
            return '';
        }
        $name = 'HS.' . $domain . '.' . $c->ident;

        // Ein Modul, dessen Beschriftungen der Nutzer je Instanz aendern darf,
        // haengt seine InstanzID an (`profileSuffix`). Solange die Vorgabe gilt,
        // teilen sich alle Zonen EIN Profil - die Profilliste bleibt aufgeraeumt,
        // und erst eine echte Umbenennung erzeugt ein eigenes.
        $suffix = (string) ($descriptor['profileSuffix'] ?? '');
        if ($suffix !== '') {
            $name .= '.' . $suffix;
        }

        // Soll-Verknuepfungen aus dem Manifest.
        $soll = [];
        foreach ($c->options as $opt) {
            if (!is_array($opt) || !array_key_exists('value', $opt)) {
                continue;
            }
            $soll[] = [(int) $opt['value'], (string) ($opt['label'] ?? $opt['value'])];
        }
        if ($soll === []) {
            return '';
        }

        if (!@IPS_VariableProfileExists($name)) {
            if (!@IPS_CreateVariableProfile($name, 1)) {
                return '';
            }
            @IPS_SetVariableProfileIcon($name, (string) ($m['icon'] ?? ''));
        } elseif ((int) (@IPS_GetVariableProfile($name)['ProfileType'] ?? 1) !== 1) {
            return ''; // Fremdprofil gleichen Namens: nicht anfassen.
        }

        // Nur bei echter Abweichung schreiben - ApplyChanges laeuft oft.
        $ist = [];
        foreach ((array) (@IPS_GetVariableProfile($name)['Associations'] ?? []) as $a) {
            $ist[] = [(int) $a['Value'], (string) $a['Name']];
        }
        if ($ist !== $soll) {
            foreach ($ist as $a) {
                @IPS_SetVariableProfileAssociation($name, $a[0], '', '', -1);
            }
            foreach ($soll as $a) {
                @IPS_SetVariableProfileAssociation($name, $a[0], $a[1], '', -1);
            }
        }
        return $name;
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

            // Ein select-Control KENNT seine Beschriftungen - sie stehen als
            // `options` im Manifest. Benutzt wurden sie bisher nur zum Pruefen
            // des geschriebenen Wertes; ein Variablenprofil entstand nur, wenn
            // der Deskriptor ausdruecklich eines nannte. Folge: die Variable
            // stand profillos im Baum, und JEDE Oberflaeche ausserhalb der
            // eigenen Kachel zeigte die nackte Zahl - Konsole, App, Alexa.
            //
            // Abgeleitet wird nur auf ANSAGE (`deriveProfile`), nicht fuer jedes
            // select: Auswahllisten wie die Radiofavoriten oder Wiedergabelisten
            // einer Audiozone sind je Instanz verschieden UND veraenderlich. Ein
            // gemeinsames Profil wuerden sich solche Zonen bei jedem
            // ApplyChanges gegenseitig ueberschreiben. Feste Mengen sagen es zu.
            if ($profile === '' && $c->type === ControlContract::T_SELECT
                && !empty($descriptor['deriveProfile'])) {
                $profile = $this->profilAusOptionen($c, $descriptor);
            }

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

            // RegisterVariable benennt nur beim ANLEGEN. Damit Manifest-Label-Aenderungen auch bei
            // BESTEHENDEN Variablen greifen (z. B. korrigierte Regel-Feldnamen), Namen nachziehen (idempotent).
            $vid = @$this->GetIDForIdent($c->ident);
            if ($vid && $c->label !== '' && @IPS_GetName($vid) !== $c->label) {
                @IPS_SetName($vid, $c->label);
            }

            // F5: JEDES actionable Control (command inklusive) bekommt EnableAction.
            if ($c->actionable) {
                $this->EnableAction($c->ident);
            }

            $pos++;
        }
    }

    // ==================================================================
    // Variablen-Gruppierung (Baum-Struktur) + subtree-faehige Aufloesung
    // ==================================================================

    /**
     * Ob dieses Modul seine Statusvariablen in beschriftete Kategorien gruppiert.
     * Default: nein (flache Ablage wie bisher). Domaenen mit sehr vielen
     * Variablen (z. B. PoolController) ueberschreiben mit true + controlGroup().
     */
    protected function usesVariableGroups(): bool
    {
        return false;
    }

    /**
     * Liefert den Gruppen-/Kategorienamen fuer ein Control (nur relevant, wenn
     * usesVariableGroups() true ist). Default: eine Sammelgruppe.
     */
    protected function controlGroup(string $ident, Control $c): string
    {
        return 'Allgemein';
    }

    /**
     * Materialisiert die Manifest-Controls und sortiert ihre Statusvariablen in
     * beschriftete Kategorien unter der Instanz. BESTEHENDE Variablen werden
     * wiederverwendet (per Ident im Teilbaum gesucht) und nur verschoben -> die
     * Objekt-IDs bleiben erhalten (LVB-Referenzen bleiben gueltig). Idempotent.
     */
    private function materializeControlsGrouped(): void
    {
        $m        = $this->manifest();
        $controls = (isset($m['controls']) && is_array($m['controls'])) ? $m['controls'] : [];

        // 1) Controls nach Gruppe bucketieren (Manifest-Reihenfolge erhalten).
        $buckets = [];
        $order   = [];
        foreach ($controls as $descriptor) {
            if (!is_array($descriptor) || !isset($descriptor['ident'])) {
                continue;
            }
            $c = Control::fromArray($descriptor);
            $g = $this->controlGroup($c->ident, $c);
            if ($g === '') {
                $g = 'Allgemein';
            }
            if (!isset($buckets[$g])) {
                $buckets[$g] = [];
                $order[]     = $g;
            }
            $buckets[$g][] = $c;
        }

        // 2) Kategorien sicherstellen und Variablen einsortieren.
        $catPos = 0;
        foreach ($order as $g) {
            $cat = $this->ensureGroupCategory($g, $catPos++);
            $pos = 0;
            foreach ($buckets[$g] as $c) {
                $vid = $this->resolveIdentDeep($c->ident);
                if (!$vid) {
                    // Neu anlegen (Sonderfall: sollte bei bestehender Instanz nicht
                    // vorkommen — alle 518 existieren bereits flach).
                    $vid = @IPS_CreateVariable($c->varType);
                    if ($vid) {
                        @IPS_SetIdent($vid, $c->ident);
                        if (($c->profile ?? '') !== '') {
                            @IPS_SetVariableCustomProfile($vid, $c->profile);
                        }
                    }
                }
                if (!$vid) {
                    continue;
                }
                // Name (idempotent nachziehen).
                if ($c->label !== '' && @IPS_GetName($vid) !== $c->label) {
                    @IPS_SetName($vid, $c->label);
                }
                // In die Zielkategorie verschieben (ID bleibt erhalten).
                if (@IPS_GetParent($vid) !== $cat) {
                    @IPS_SetParent($vid, $cat);
                }
                @IPS_SetPosition($vid, $pos++);
                // Aktion: actionable Controls muessen RequestAction dieser Instanz
                // ausloesen (Modul-Aktion VariableAction, NICHT CustomAction — die
                // nimmt nur Skripte an). EnableAction loest den Ident aber ueber
                // direkte Kinder auf; die Variable liegt in einer Kategorie. Daher
                // nur bei fehlender/falscher Aktion kurz als direktes Kind halten,
                // EnableAction, zurueck. Fuer die bestehenden 518 ist VA bereits
                // gesetzt -> dieser Zweig wird uebersprungen (kein Churn).
                if ($c->actionable) {
                    $va = @IPS_GetVariable($vid)['VariableAction'] ?? 0;
                    if ((int) $va !== $this->InstanceID) {
                        @IPS_SetParent($vid, $this->InstanceID);
                        @$this->EnableAction($c->ident);
                        @IPS_SetParent($vid, $cat);
                        @IPS_SetPosition($vid, $pos - 1);
                    }
                }
            }
        }

        // Ident-Cache verwerfen — die Variablen liegen jetzt (teilweise) in
        // Kategorien; Aufloesung erfolgt ab sofort ueber den Teilbaum.
        $this->identMap = null;
    }

    /**
     * Stellt eine beschriftete Gruppen-Kategorie direkt unter der Instanz sicher
     * (stabiler Ident aus dem Namen, damit wiederverwendbar/umbenennbar).
     */
    private function ensureGroupCategory(string $name, int $pos): int
    {
        $ident = 'hsgrp_' . substr(md5($name), 0, 12);
        $cid   = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        if ($cid === false) {
            $cid = IPS_CreateCategory();
            IPS_SetParent($cid, $this->InstanceID);
            IPS_SetIdent($cid, $ident);
        }
        if (@IPS_GetName($cid) !== $name) {
            @IPS_SetName($cid, $name);
        }
        @IPS_SetPosition($cid, $pos);
        return (int) $cid;
    }

    /**
     * Baut (lazy, pro Prozess) die Abbildung Ident => ObjektID fuer den gesamten
     * Instanz-Teilbaum. Steigt in Kategorien ab, NICHT in Kind-Instanzen (die
     * haben einen eigenen Ident-Namensraum).
     */
    private function identMap(): array
    {
        if ($this->identMap !== null) {
            return $this->identMap;
        }
        $map = [];
        $this->collectIdents($this->InstanceID, $map);
        $this->identMap = $map;
        return $map;
    }

    /** Rekursiver Sammler fuer identMap(). */
    private function collectIdents(int $parent, array &$map): void
    {
        foreach (IPS_GetChildrenIDs($parent) as $cid) {
            $o = IPS_GetObject($cid);
            $ident = $o['ObjectIdent'];
            if ($ident !== '' && !isset($map[$ident])) {
                $map[$ident] = $cid;
            }
            // Nur in Kategorien absteigen (Typ 0). Kind-Instanzen (Typ 1) haben
            // einen eigenen Ident-Namensraum und werden nicht durchsucht.
            if ($o['ObjectType'] === 0) {
                $this->collectIdents($cid, $map);
            }
        }
    }

    /**
     * Loest einen Ident im GESAMTEN Instanz-Teilbaum auf: erst direktes Kind
     * (schnell, deckt flache Module vollstaendig ab), sonst ueber den Teilbaum-
     * Cache. Liefert 0, wenn nicht gefunden.
     */
    private function resolveIdentDeep(string $ident): int
    {
        $d = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        if ($d !== false) {
            return (int) $d;
        }
        $m = $this->identMap();
        if (isset($m[$ident]) && @IPS_ObjectExists($m[$ident])) {
            return (int) $m[$ident];
        }
        // Cache koennte veraltet sein -> einmal neu aufbauen und erneut pruefen.
        $this->identMap = null;
        $m = $this->identMap();
        return (isset($m[$ident]) && @IPS_ObjectExists($m[$ident])) ? (int) $m[$ident] : 0;
    }

    /**
     * Override der SDK-Ident-Aufloesung: subtree-faehig. Fuer flache Module
     * verhaelt sich das identisch (direktes Kind wird immer zuerst gefunden);
     * fuer gruppierte Module werden in Kategorien einsortierte Variablen
     * ebenfalls gefunden. Liefert false, wenn nicht vorhanden (SDK-kompatibel
     * fuer die durchgaengig genutzten @-/!==false-Aufrufer).
     */
    protected function GetIDForIdent($Ident)
    {
        $d = @IPS_GetObjectIDByIdent($Ident, $this->InstanceID);
        if ($d !== false) {
            return $d;
        }
        if (!$this->usesVariableGroups()) {
            return false;
        }
        $id = $this->resolveIdentDeep((string) $Ident);
        return $id > 0 ? $id : false;
    }

    /**
     * Override: liest den Wert per subtree-faehiger Ident-Aufloesung.
     * Signatur UNTYPISIERT — muss exakt zu IPSModule::GetValue($Ident) passen.
     */
    protected function GetValue($Ident)
    {
        $id = $this->GetIDForIdent($Ident);
        if ($id === false) {
            throw new \Exception("Ident '{$Ident}' nicht gefunden");
        }
        return GetValue($id);
    }

    /**
     * Override: schreibt den Wert per subtree-faehiger Ident-Aufloesung.
     * Signatur UNTYPISIERT — muss exakt zu IPSModule::SetValue($Ident,$Value) passen.
     */
    protected function SetValue($Ident, $Value)
    {
        $id = $this->GetIDForIdent($Ident);
        if ($id === false) {
            throw new \Exception("Ident '{$Ident}' nicht gefunden");
        }
        return SetValue($id, $Value);
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
            if (($c->varId ?? $this->varIdOf($ident)) !== null) {
                try {
                    $state[$ident] = $this->GetValue($ident);   // per IDENT, nicht Objekt-ID
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
        if (($c->varId ?? $this->varIdOf($c->ident)) !== null) {
            try {
                $this->SetValue($c->ident, ControlContract::CMD_IDLE);   // per IDENT, nicht Objekt-ID
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

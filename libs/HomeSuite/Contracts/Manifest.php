<?php

declare(strict_types=1);

namespace Hoep\HomeSuite;

/**
 * Manifest — Vertrag 2 (Manifest-JSON-Schema v1.0), Builder + Validator.
 *
 * Baut den vollstaendigen Manifest-Baum (§2.2) fluent auf, validiert ihn gegen
 * die formalen Regeln und serialisiert ihn JSON-tauglich. Kernel-frei und
 * damit unit-testbar.
 *
 * Design-Entscheidungen am Code:
 *  - A1: Control-`role` wird nur ueber {@see ControlContract::isValidRole()}
 *        (Format) geprueft, nie gegen eine geschlossene Liste.
 *  - F5: `command` ist ein gueltiger, aktionierbarer Control-Typ; ein `command`
 *        MUSS actionable sein (Idle-Reset braucht Variable+EnableAction).
 *  - Forward-Compat: der LVB-Renderer toleriert unbekannte Typen (§2.2); die
 *        PRODUZENTEN-Seite (dieser Validator) bleibt hingegen streng, damit ein
 *        Modul kein strukturell kaputtes Manifest ausliefert.
 */
final class Manifest implements \JsonSerializable
{
    public const VERSION = '1.0';

    /** Erlaubte managementAction-Verben (§2.2.3), inkl. Hub-only. */
    private const VERBS = [
        'createEntity', 'renameEntity', 'deleteEntity', 'moveEntity',
        'createProfile', 'updateProfile', 'deleteProfile', 'duplicateProfile', 'assignProfile',
        'setSchedule', 'setThreshold', 'setOrientation',
        'addDriver', 'configureDriver', 'removeDriver',
        'discover', 'pair', 'setConfig', 'regenerateViews',
        // Hub-only:
        'provision', 'rotateToken', 'listSnapshots', 'restoreSnapshot',
        // Szenarien (Beschattung/Audio):
        'scenarioSave', 'scenarioActivate', 'scenarioDelete', 'group', 'ungroup', 'addRenderer',
        'setActivePresence', 'testRun', 'discoverAsync',
    ];

    /** Erlaubte Field-Typen (§2.2.4). */
    private const FIELD_TYPES = [
        'string', 'int', 'float', 'bool', 'select', 'entityRef', 'driver',
        'objid', 'color', 'time', 'profileRef', 'varpick', 'boolArray', 'list',
    ];

    /** @var array<string,mixed> Interner Manifest-Baum. */
    private array $data;

    public function __construct()
    {
        // Skelett gemaess §2.2-Top-Level; Reihenfolge stabil fuer kanonisches JSON.
        $this->data = [
            'manifestVersion'   => self::VERSION,
            'module'            => [],
            'instanceId'        => 0,
            'capabilities'      => [],
            'entity'            => [],
            'controls'          => [],
            'profileTypes'      => [],
            'programTypes'      => [],
            'managementActions' => [],
            'configFields'      => [],
            'driverCatalog'     => [],
            'state'             => [],
        ];
    }

    // ------------------------------------------------------------------
    // Builder
    // ------------------------------------------------------------------

    public function setModule(
        string $id,
        string $prefix,
        string $domain,
        string $guid,
        int $moduleType,
        string $title,
        string $icon = ''
    ): self {
        $this->data['module'] = [
            'id'         => $id,
            'prefix'     => $prefix,
            'domain'     => $domain,
            'guid'       => $guid,
            'moduleType' => $moduleType,
            'title'      => $title,
            'icon'       => $icon,
        ];
        return $this;
    }

    public function setInstance(int $instanceId): self
    {
        $this->data['instanceId'] = $instanceId;
        return $this;
    }

    /**
     * entity.id ist IMMUTABEL (renameEntity aendert nur name) — Idempotenz-
     * schluessel (template,entity.id) bleibt stabil.
     *
     * @param array<string,mixed> $entity {id,name,singular,plural,group,roomRef,identNamespace}
     */
    public function setEntity(array $entity): self
    {
        $this->data['entity'] = $entity;
        return $this;
    }

    /**
     * @param string[] $capabilities
     */
    public function setCapabilities(array $capabilities): self
    {
        $this->data['capabilities'] = array_values($capabilities);
        return $this;
    }

    /**
     * Fuegt ein Control hinzu — als {@see Control} oder Roh-Descriptor-Array.
     *
     * @param Control|array<string,mixed> $control
     */
    public function addControl($control): self
    {
        $this->data['controls'][] = $control instanceof Control
            ? $control->toArray()
            : $control;
        return $this;
    }

    /** @param array<string,mixed> $profileType */
    public function addProfileType(array $profileType): self
    {
        $this->data['profileTypes'][] = $profileType;
        return $this;
    }

    /** @param array<string,mixed> $programType */
    public function addProgramType(array $programType): self
    {
        $this->data['programTypes'][] = $programType;
        return $this;
    }

    /** @param array<string,mixed> $action */
    public function addManagementAction(array $action): self
    {
        $this->data['managementActions'][] = $action;
        return $this;
    }

    /** @param array<string,mixed> $field */
    public function addConfigField(array $field): self
    {
        $this->data['configFields'][] = $field;
        return $this;
    }

    /** @param array<string,mixed> $driver */
    public function addDriver(array $driver): self
    {
        $this->data['driverCatalog'][] = $driver;
        return $this;
    }

    /**
     * Setzt den state-Snapshot (Ident => aktueller Wert).
     *
     * @param array<string,mixed> $state
     */
    public function setState(array $state): self
    {
        $this->data['state'] = $state;
        return $this;
    }

    // ------------------------------------------------------------------
    // Zugriff / Serialisierung
    // ------------------------------------------------------------------

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->data;
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): mixed
    {
        return $this->data;
    }

    public function toJson(): string
    {
        $json = json_encode($this->data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        return $json === false ? '{}' : $json;
    }

    /**
     * Baut einen Manifest aus einem bestehenden Array (z. B. eingelesenem JSON).
     *
     * @param array<string,mixed> $a
     */
    public static function fromArray(array $a): self
    {
        $m = new self();
        // Bekannte Top-Level-Keys uebernehmen, Rest ignorieren (Forward-Compat).
        foreach ($m->data as $key => $_) {
            if (array_key_exists($key, $a)) {
                $m->data[$key] = $a[$key];
            }
        }
        // manifestVersion nie leer lassen.
        if (($m->data['manifestVersion'] ?? '') === '') {
            $m->data['manifestVersion'] = self::VERSION;
        }
        return $m;
    }

    // ------------------------------------------------------------------
    // Validator
    // ------------------------------------------------------------------

    public function isValid(): bool
    {
        return $this->validate() === [];
    }

    /**
     * Prueft den gesamten Manifest-Baum. Liefert eine Liste menschlich lesbarer
     * Fehlerbeschreibungen; leeres Array = gueltig.
     *
     * @return string[]
     */
    public function validate(): array
    {
        $errors = [];

        // --- manifestVersion ---
        if (($this->data['manifestVersion'] ?? '') !== self::VERSION) {
            $errors[] = "manifestVersion muss '" . self::VERSION . "' sein";
        }

        // --- module ---
        $mod = $this->data['module'] ?? [];
        foreach (['id', 'prefix', 'domain', 'guid', 'moduleType', 'title'] as $req) {
            if (!isset($mod[$req]) || $mod[$req] === '') {
                $errors[] = "module.$req fehlt";
            }
        }
        if (isset($mod['guid']) && !self::isGuid((string) $mod['guid'])) {
            $errors[] = 'module.guid hat kein gueltiges {…}-Format';
        }
        if (isset($mod['moduleType']) && !is_int($mod['moduleType'])) {
            $errors[] = 'module.moduleType muss int sein';
        }

        // --- entity (id immutabel & pflicht) ---
        $entity = $this->data['entity'] ?? [];
        if (!isset($entity['id']) || $entity['id'] === '') {
            $errors[] = 'entity.id fehlt (Idempotenzschluessel, immutabel)';
        }
        if (!isset($entity['name']) || $entity['name'] === '') {
            $errors[] = 'entity.name fehlt';
        }

        // --- controls ---
        $seenIdents = [];
        foreach (($this->data['controls'] ?? []) as $i => $c) {
            $errors = array_merge($errors, $this->validateControl($c, $i, $seenIdents));
        }

        // --- profileTypes / programTypes ---
        foreach (['profileTypes', 'programTypes'] as $ptKey) {
            foreach (($this->data[$ptKey] ?? []) as $i => $pt) {
                if (!isset($pt['id']) || $pt['id'] === '') {
                    $errors[] = "$ptKey[$i].id fehlt";
                }
                if (!isset($pt['schema']) || !is_array($pt['schema'])) {
                    $errors[] = "$ptKey[$i].schema fehlt oder ist kein Objekt";
                }
            }
        }

        // --- managementActions ---
        foreach (($this->data['managementActions'] ?? []) as $i => $ma) {
            $errors = array_merge($errors, $this->validateManagementAction($ma, $i));
        }

        // --- configFields ---
        foreach (($this->data['configFields'] ?? []) as $i => $f) {
            $errors = array_merge($errors, $this->validateField($f, "configFields[$i]"));
        }

        // --- driverCatalog ---
        foreach (($this->data['driverCatalog'] ?? []) as $i => $d) {
            if (!isset($d['id']) || $d['id'] === '') {
                $errors[] = "driverCatalog[$i].id fehlt";
            }
            if (!isset($d['interface']) || $d['interface'] === '') {
                $errors[] = "driverCatalog[$i].interface fehlt";
            }
        }

        return $errors;
    }

    // ------------------------------------------------------------------
    // Teil-Validatoren
    // ------------------------------------------------------------------

    /**
     * @param mixed                $c
     * @param array<string,bool>   $seenIdents (per Referenz, Dubletten-Erkennung)
     * @return string[]
     */
    private function validateControl($c, int $i, array &$seenIdents): array
    {
        $e = [];
        if (!is_array($c)) {
            return ["controls[$i] ist kein Objekt"];
        }

        $ident = (string) ($c['ident'] ?? '');
        if ($ident === '') {
            $e[] = "controls[$i].ident fehlt";
        } elseif (isset($seenIdents[$ident])) {
            $e[] = "controls[$i]: doppelter ident '$ident'";
        } else {
            $seenIdents[$ident] = true;
        }

        $type = (string) ($c['type'] ?? '');
        if (!ControlContract::isValidType($type)) {
            $e[] = "controls[$i] ('$ident'): ungueltiger type '$type'";
        }

        $role = (string) ($c['role'] ?? '');
        if (!ControlContract::isValidRole($role)) {
            // A1: nur Format, nicht Mitgliedschaft.
            $e[] = "controls[$i] ('$ident'): role '$role' verletzt Format domain:slug";
        }

        $actionable = (bool) ($c['actionable'] ?? false);

        // reflect ist niemals actionable.
        if ($type === ControlContract::T_REFLECT && $actionable) {
            $e[] = "controls[$i] ('$ident'): reflect darf nicht actionable sein";
        }
        // F5: command MUSS actionable sein (Idle-Reset braucht Variable+Action).
        if ($type === ControlContract::T_COMMAND && !$actionable) {
            $e[] = "controls[$i] ('$ident'): command muss actionable sein (F5)";
        }
        // select/command ohne Optionen ist verdaechtig.
        if (in_array($type, [ControlContract::T_SELECT], true)
            && (empty($c['options']) || !is_array($c['options']))) {
            $e[] = "controls[$i] ('$ident'): select ohne options";
        }

        // varType plausibel (0..3)?
        if (isset($c['varType']) && !in_array((int) $c['varType'], [0, 1, 2, 3], true)) {
            $e[] = "controls[$i] ('$ident'): varType muss 0..3 sein";
        }

        return $e;
    }

    /**
     * @param mixed $ma
     * @return string[]
     */
    private function validateManagementAction($ma, int $i): array
    {
        $e = [];
        if (!is_array($ma)) {
            return ["managementActions[$i] ist kein Objekt"];
        }
        if (!isset($ma['op']) || $ma['op'] === '') {
            $e[] = "managementActions[$i].op fehlt";
        }
        $verb = (string) ($ma['verb'] ?? $ma['op'] ?? '');
        if ($verb === '') {
            $e[] = "managementActions[$i].verb fehlt";
        } elseif (!in_array($verb, self::VERBS, true)) {
            // Unbekanntes Verb: als Fehler melden, aber ohne Absturz — der
            // Aufrufer entscheidet, ob er es toleriert (Forward-Compat).
            $e[] = "managementActions[$i]: unbekanntes Verb '$verb'";
        }
        if (isset($ma['fields'])) {
            if (!is_array($ma['fields'])) {
                $e[] = "managementActions[$i].fields ist kein Array";
            } else {
                foreach ($ma['fields'] as $j => $f) {
                    $e = array_merge($e, $this->validateField($f, "managementActions[$i].fields[$j]"));
                }
            }
        }
        // Destruktive Verben muessen als destructive markiert sein.
        if (in_array($verb, ['deleteEntity', 'deleteProfile', 'restoreSnapshot'], true)
            && empty($ma['destructive'])) {
            $e[] = "managementActions[$i]: destruktives Verb '$verb' muss destructive:true tragen";
        }
        return $e;
    }

    /**
     * @param mixed  $f
     * @return string[]
     */
    private function validateField($f, string $where): array
    {
        $e = [];
        if (!is_array($f)) {
            return ["$where ist kein Objekt"];
        }
        if (!isset($f['key']) || $f['key'] === '') {
            $e[] = "$where.key fehlt";
        }
        $type = (string) ($f['type'] ?? '');
        if ($type === '') {
            $e[] = "$where.type fehlt";
        } elseif (!in_array($type, self::FIELD_TYPES, true)) {
            $e[] = "$where: unbekannter field-type '$type'";
        }
        return $e;
    }

    /** GUID-Format {8-4-4-4-12}. */
    private static function isGuid(string $g): bool
    {
        return (bool) preg_match(
            '/^\{[0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{12}\}$/',
            $g
        );
    }
}

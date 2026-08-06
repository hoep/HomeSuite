<?php

declare(strict_types=1);

namespace Hoep\HomeSuite;

/**
 * Control — ein typisiertes Bedien-/Anzeige-Element einer Entitaet (Vertrag 1).
 *
 * Jedes Control bindet an genau EINE Status-Variable (`ident` -> `varId`) und
 * ist optional `actionable` (native RequestAction ueber EnableAction). Der
 * Control-Descriptor im Manifest (§2.2.1) und dieses Objekt sind
 * verlustfrei ineinander ueberfuehrbar (fromArray/toArray).
 *
 * Design-Entscheidungen am Code:
 *  - F5: Auch `command` traegt eine echte Statusvariable (`varId`) und ist
 *        `actionable`. "transient" wird NICHT hier modelliert, sondern im
 *        Dispatch durch Idle-Reset ({@see ControlContract::CMD_IDLE}).
 *  - F4: `coerce()` ist die einzige Stelle, die Werte gegen Typ/Range/Enum
 *        haertet, bevor sie in den Aktor gehen. Bei numerischen Reglern wird
 *        auf [min,max] GEKLEMMT (nie ein 500-Grad-Sollwert an die Heizung),
 *        bei Auswahl/Kommando mit unbekanntem Code kontrolliert geworfen
 *        (ContractException), damit der Dispatch sauber loggen kann.
 *  - Reflect-Politik: das Feld `$optimistic` (aus dem Manifest, = !supportsPush)
 *        steuert, ob der Dispatch optimistisch vorschreibt und ein
 *        manualHold-Fenster oeffnet.
 */
final class Control
{
    public string $ident;
    public string $type;
    public string $role;
    public string $label;

    /** IPS-Variablentyp: 0=bool, 1=int, 2=float, 3=string. */
    public int $varType;

    /** Nimmt native RequestAction an (EnableAction gesetzt)? */
    public bool $actionable;

    /**
     * Reflect-Politik (F4): true => optimistisch vorschreiben + manualHold-
     * Fenster. Aus dem Manifest gespeist (= !driverSupportsPush(ident)).
     */
    public bool $optimistic;

    public ?float $min;
    public ?float $max;
    public ?float $step;
    public ?int $dec;
    public ?int $varId;

    public ?string $unit;
    public ?string $profile;
    public ?string $group;
    public ?string $requiresCap;
    public ?string $reflectOf;

    /**
     * Auswahl-/Kommando-Optionen: Liste von {value,key,label,icon}.
     * @var array<int,array<string,mixed>>
     */
    public array $options = [];

    /**
     * Materialisiert ein Control aus einem Manifest-Descriptor (§2.2.1).
     * Fehlende optionale Felder werden defensiv mit sinnvollen Defaults belegt.
     *
     * @param array<string,mixed> $a
     */
    public static function fromArray(array $a): self
    {
        $c = new self();

        $c->ident      = (string) ($a['ident'] ?? '');
        $c->type       = (string) ($a['type'] ?? ControlContract::T_REFLECT);
        $c->role       = (string) ($a['role'] ?? '');
        $c->label      = (string) ($a['label'] ?? $c->ident);
        $c->varType    = (int) ($a['varType'] ?? self::defaultVarType($c->type));
        $c->actionable = (bool) ($a['actionable'] ?? ControlContract::isActionableType($c->type));

        // Reflect nie actionable (F5-Gegenstueck: reflect ist read-only).
        if ($c->type === ControlContract::T_REFLECT) {
            $c->actionable = false;
        }

        // Reflect-Politik: Default true (ohne Push-Treiber optimistisch), s. F4.
        $c->optimistic = (bool) ($a['optimistic'] ?? true);

        $c->min         = self::nullableFloat($a['min'] ?? null);
        $c->max         = self::nullableFloat($a['max'] ?? null);
        $c->step        = self::nullableFloat($a['step'] ?? null);
        $c->dec         = isset($a['dec']) ? (int) $a['dec'] : null;
        $c->varId       = isset($a['varId']) && $a['varId'] !== null ? (int) $a['varId'] : null;
        $c->unit        = isset($a['unit']) ? (string) $a['unit'] : null;
        $c->profile     = isset($a['profile']) ? (string) $a['profile'] : null;
        $c->group       = isset($a['group']) ? (string) $a['group'] : null;
        $c->requiresCap = isset($a['requiresCap']) ? (string) $a['requiresCap'] : null;
        $c->reflectOf   = isset($a['reflectOf']) && $a['reflectOf'] !== null ? (string) $a['reflectOf'] : null;
        $c->options     = isset($a['options']) && is_array($a['options']) ? array_values($a['options']) : [];

        return $c;
    }

    /**
     * Serialisiert das Control zurueck in einen Manifest-Descriptor (§2.2.1).
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'ident'       => $this->ident,
            'type'        => $this->type,
            'role'        => $this->role,
            'label'       => $this->label,
            'varType'     => $this->varType,
            'actionable'  => $this->actionable,
            'varId'       => $this->varId,
            'unit'        => $this->unit,
            'min'         => $this->min,
            'max'         => $this->max,
            'step'        => $this->step,
            'dec'         => $this->dec,
            'profile'     => $this->profile,
            'group'       => $this->group,
            'requiresCap' => $this->requiresCap,
            'optimistic'  => $this->optimistic,
            'options'     => $this->options,
            'reflectOf'   => $this->reflectOf,
        ];
    }

    /**
     * Reflect-Politik (F4): soll der Dispatch fuer dieses Control OPTIMISTISCH
     * vorschreiben (sofort SetValue + manualHold-Fenster)? Wert stammt aus dem
     * Manifest (= !driverSupportsPush(ident)). Getrennte Methode, damit der
     * Dispatch-Pseudocode `c.optimistic()` 1:1 abbildbar ist — koexistiert in
     * PHP problemlos mit der gleichnamigen Property.
     */
    public function optimistic(): bool
    {
        return $this->optimistic;
    }

    /**
     * Haertet einen eingehenden Bedienwert gegen Typ, Range und Enum (F4).
     *
     * - setpoint/level : cast (varType-abhaengig) + KLEMMEN auf [min,max].
     * - switch         : robuster Bool-Cast ("1"/"true"/"on"/1/true => true).
     * - command        : Integer-Code; muss ein Options-Code ODER der Idle-Code
     *                    ({@see ControlContract::CMD_IDLE}) sein (falls Optionen
     *                    definiert sind).
     * - select         : Wert muss unter den Options-`value` (oder `key`) sein.
     * - reflect        : nicht actionable — Wert wird nur unveraendert
     *                    durchgereicht (falls dennoch aufgerufen).
     *
     * @param mixed $v Roher Wert (typischerweise String aus `?api=setvar`).
     * @return mixed   Gehaerteter, aktor-tauglicher Wert.
     * @throws ContractException bei nicht-actionable Control oder unbekanntem
     *                           Enum/Kommando-Code.
     */
    public function coerce($v)
    {
        if (!$this->actionable && $this->type !== ControlContract::T_REFLECT) {
            throw new ContractException("Control '{$this->ident}' ist nicht actionable");
        }

        switch ($this->type) {
            case ControlContract::T_SETPOINT:
                return $this->coerceNumeric($v);

            case ControlContract::T_LEVEL:
                // Level defaultet auf Bereich 0..100, falls min/max fehlen.
                $min = $this->min ?? 0.0;
                $max = $this->max ?? 100.0;
                return $this->coerceNumeric($v, $min, $max);

            case ControlContract::T_SWITCH:
                return self::toBool($v);

            case ControlContract::T_COMMAND:
                return $this->coerceCommand($v);

            case ControlContract::T_SELECT:
                return $this->coerceSelect($v);

            case ControlContract::T_REFLECT:
            default:
                return $v;
        }
    }

    // ------------------------------------------------------------------
    // interne Helfer
    // ------------------------------------------------------------------

    /**
     * Numerischer Cast + Klemmung auf [min,max] (Defaults ueberschreibbar).
     * varType 1 => Integer (gerundet), sonst Float.
     *
     * @param mixed      $v
     * @param float|null $minOverride
     * @param float|null $maxOverride
     * @return int|float
     */
    private function coerceNumeric($v, ?float $minOverride = null, ?float $maxOverride = null)
    {
        if (!is_numeric($v)) {
            throw new ContractException("Control '{$this->ident}': '" . (is_scalar($v) ? (string) $v : gettype($v)) . "' ist nicht numerisch");
        }

        $f   = (float) $v;
        $min = $minOverride ?? $this->min;
        $max = $maxOverride ?? $this->max;

        if ($min !== null && $f < $min) {
            $f = $min;
        }
        if ($max !== null && $f > $max) {
            $f = $max;
        }

        return $this->varType === 1 ? (int) round($f) : $f;
    }

    /**
     * Kommando-Cast: Integer-Code. Sind Optionen definiert, muss der Code einer
     * der Options-`value` oder der Idle-Code sein — sonst ContractException.
     *
     * @param mixed $v
     */
    private function coerceCommand($v): int
    {
        if (!is_numeric($v)) {
            throw new ContractException("Command '{$this->ident}': Code nicht numerisch");
        }
        $code = (int) $v;

        if ($this->options === []) {
            return $code; // freies Kommando ohne Enum
        }

        if ($code === ControlContract::CMD_IDLE) {
            return $code; // Idle-Reset (F5) ist immer erlaubt
        }

        foreach ($this->options as $opt) {
            if (isset($opt['value']) && (int) $opt['value'] === $code) {
                return $code;
            }
        }

        throw new ContractException("Command '{$this->ident}': unbekannter Code {$code}");
    }

    /**
     * Auswahl-Cast: Wert muss unter den Options-`value` (oder `key`) sein.
     * Rueckgabe im Typ des passenden `value` (int bleibt int, string bleibt
     * string).
     *
     * @param mixed $v
     * @return mixed
     */
    private function coerceSelect($v)
    {
        if ($this->options === []) {
            // Kein geschlossenes Optionsset hinterlegt -> tolerant durchreichen.
            return is_numeric($v) ? (int) $v : $v;
        }

        foreach ($this->options as $opt) {
            if (array_key_exists('value', $opt) && (string) $opt['value'] === (string) $v) {
                return $opt['value'];
            }
            if (array_key_exists('key', $opt) && (string) $opt['key'] === (string) $v) {
                return $opt['value'] ?? $opt['key'];
            }
        }

        throw new ContractException("Select '{$this->ident}': ungueltige Auswahl '" . (is_scalar($v) ? (string) $v : gettype($v)) . "'");
    }

    /**
     * Robuster Bool-Cast fuer Bedienwerte aus dem heissen Pfad (Strings!).
     *
     * @param mixed $v
     */
    private static function toBool($v): bool
    {
        if (is_bool($v)) {
            return $v;
        }
        if (is_numeric($v)) {
            return (float) $v != 0.0;
        }
        $s = strtolower(trim((string) $v));
        return in_array($s, ['1', 'true', 'on', 'yes', 'ja'], true);
    }

    /**
     * IPS-Standard-Variablentyp fuer einen Control-Typ, falls im Descriptor
     * nicht explizit gesetzt.
     */
    private static function defaultVarType(string $type): int
    {
        switch ($type) {
            case ControlContract::T_SWITCH:
                return 0; // bool
            case ControlContract::T_LEVEL:
            case ControlContract::T_COMMAND:
            case ControlContract::T_SELECT:
                return 1; // int
            case ControlContract::T_SETPOINT:
                return 2; // float
            case ControlContract::T_REFLECT:
            default:
                return 3; // string (sichere Voreinstellung fuer beliebige Rueckmeldung)
        }
    }

    /**
     * @param mixed $v
     */
    private static function nullableFloat($v): ?float
    {
        return ($v === null || $v === '') ? null : (float) $v;
    }
}

<?php

declare(strict_types=1);

namespace Hoep\HomeSuite;

/**
 * ContractException — einheitliche Fehlerklasse aller Vertrags-Verletzungen.
 *
 * Wird von {@see Control::coerce()} bei Typ-/Range-/Enum-Verstoessen geworfen
 * und im finalen RequestAction-Dispatch von {@see EntityModule::RequestAction()}
 * gefangen (F4: der Bedienpfad wirft NIE nach oben in den Kernel-Log).
 */
class ContractException extends \Exception
{
}

/**
 * ControlContract — Vertrag 1 (Control-Contract), formaler Kern.
 *
 * Haelt die geschlossene Menge der 6 Control-Typen und die reine
 * Format-Validierung des offenen `role`-Vokabulars. Bewusst ohne Kernel-
 * Abhaengigkeit: nur statische, testbare Funktionen (Basis der Unit-Tests
 * in M0.5).
 *
 * Design-Entscheidungen am Code:
 *  - A1: `role` ist ein OFFENER, formatvalidierter String `domain:slug` — KEIN
 *        geschlossenes Enum. `isValidRole()` prueft ausschliesslich das Format,
 *        niemals die Mitgliedschaft. Nur so kann ein Fremd-Domaenenmodul eine
 *        neue `role` einfuehren, ohne den Core zu patchen.
 *  - F5: `command` ist ein vollwertiger, aktionierbarer Control-Typ mit
 *        Statusvariable + EnableAction. "transient" heisst NICHT "keine
 *        Variable", sondern: der Wert wird nach `applyControl` auf den
 *        Idle-Code {@see self::CMD_IDLE} zurueckgeschrieben.
 */
final class ControlContract
{
    /** Die 6 geschlossenen Control-Typen (Vertrag 1). */
    public const T_SETPOINT = 'setpoint';   // numerischer Zielwert (float/int, min/max/step/unit)
    public const T_SWITCH   = 'switch';     // boolean
    public const T_LEVEL    = 'level';      // stetiger 0..100-Regler
    public const T_COMMAND  = 'command';    // momentaner/enum-Befehl, Idle-Reset (F5)
    public const T_REFLECT  = 'reflect';    // read-only Rueckmeldung (nicht actionable)
    public const T_SELECT   = 'select';     // Auswahl aus benannter Menge (options)

    /**
     * Idle-Code, auf den ein `command`-Control nach Ausfuehrung zurueckgesetzt
     * wird (F5: transient = Idle-Reset, nicht "keine Variable").
     */
    public const CMD_IDLE = -1;

    /**
     * Regex des offenen role-Vokabulars: `domain:slug`.
     * domain = [a-z][a-z0-9]*  ·  slug = [a-z][a-z0-9-]* (Bindestriche im Slug erlaubt).
     * A1: ausschliesslich Format, keine Registry-Mitgliedschaft.
     */
    public const ROLE_PATTERN = '/^[a-z][a-z0-9]*:[a-z][a-z0-9-]*$/';

    /** Diese Klasse ist rein statisch. */
    private function __construct()
    {
    }

    /**
     * Vollstaendige, unveraenderliche Liste der gueltigen Control-Typen.
     *
     * @return string[]
     */
    public static function allTypes(): array
    {
        return [
            self::T_SETPOINT,
            self::T_SWITCH,
            self::T_LEVEL,
            self::T_COMMAND,
            self::T_REFLECT,
            self::T_SELECT,
        ];
    }

    /**
     * Prueft, ob $t einer der 6 geschlossenen Control-Typen ist.
     */
    public static function isValidType(string $t): bool
    {
        return in_array($t, self::allTypes(), true);
    }

    /**
     * FORMAT-Pruefung des role-Strings (A1) — NICHT Mitgliedschaft.
     *
     * Gueltig: `heating:target-temperature`, `common:online`, `audio:seek`.
     * Ungueltig: `Heating:x` (Grossbuchstabe), `:x`, `x:` , `x:-y`, `x y`.
     */
    public static function isValidRole(string $r): bool
    {
        return (bool) preg_match(self::ROLE_PATTERN, $r);
    }

    /**
     * Ist der Typ grundsaetzlich aktionierbar (kann eine RequestAction annehmen)?
     * Nur `reflect` ist niemals aktionierbar; die anderen fuenf sind es (F5:
     * command inklusive).
     */
    public static function isActionableType(string $t): bool
    {
        return $t !== self::T_REFLECT && self::isValidType($t);
    }

    /**
     * Ist der Typ "transient" (Idle-Reset nach Ausfuehrung)? Nur `command`.
     */
    public static function isTransientType(string $t): bool
    {
        return $t === self::T_COMMAND;
    }
}

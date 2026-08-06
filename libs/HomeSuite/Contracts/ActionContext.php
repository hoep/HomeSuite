<?php

declare(strict_types=1);

namespace Hoep\HomeSuite;

/**
 * ActionContext — Provenienz eines Bedienvorgangs (Vertrag 1, Leitprinzip 3).
 *
 * Trennt den dritten der drei historisch vermischten Belange sauber ab:
 *   1. Wert setzen           -> SetValue / applyControl
 *   2. Automatik-Hoheit      -> manualHold (eigener, volatiler Zustand)
 *   3. HERKUNFT des Vorgangs -> dieses Objekt
 *
 * Bewusste Design-Entscheidung: `manualHold` ist KEIN Bestandteil des
 * ActionContext. Die Herkunft (source) sagt nur, WER den Vorgang ausgeloest
 * hat; ob dadurch die Automatik zurueckgestellt wird, entscheidet das Modul
 * ueber `isAutomated()`/`manualHold()` getrennt. So bleiben Provenienz und
 * Automatik-Belang entkoppelt (Risiko 11: die native RequestAction liefert nur
 * eine grobe Provenienz `user`; feinere Herkuenfte kommen ueber den Manage-RPC).
 */
final class ActionContext
{
    /** Nutzer-Bedienung (nativer RequestAction-Pfad, Default). */
    public const SOURCE_USER = 'user';
    /** Fremdsystem / externe Automation (z. B. anderes Modul, Skript). */
    public const SOURCE_EXTERNAL = 'external';
    /** Interner Zeitplan / ScheduleEngine-Slotgrenze. */
    public const SOURCE_SCHEDULE = 'schedule';
    /** Sicherheits-Tier (Wind/Regen/Frost) — ueberfaehrt manualHold hart. */
    public const SOURCE_SAFETY = 'safety';

    /** Herkunft: user|external|schedule|safety. */
    public string $source;

    /** Unix-Zeitstempel des Vorgangs (0 => wird auf time() gesetzt). */
    public int $ts;

    /** Freie Zusatzinfos (z. B. ausloesende Regel, Job-ID). */
    public array $meta;

    /**
     * @param string $source Eine der SOURCE_*-Konstanten. Unbekannte Werte
     *                       werden defensiv auf SOURCE_EXTERNAL normalisiert
     *                       (nie Exception im Bedienpfad — F4).
     * @param int    $ts     Unix-Zeit; 0 => time().
     * @param array  $meta   Optionale Zusatzinformationen.
     */
    public function __construct(string $source, int $ts = 0, array $meta = [])
    {
        $this->source = self::normalizeSource($source);
        $this->ts     = $ts > 0 ? $ts : time();
        $this->meta   = $meta;
    }

    /**
     * Bequemer Konstruktor fuer den haeufigsten Fall (Nutzer-Bedienung, jetzt).
     */
    public static function user(array $meta = []): self
    {
        return new self(self::SOURCE_USER, time(), $meta);
    }

    /**
     * Gehoert dieser Vorgang zum Safety-Tier? Dieses Tier ignoriert manualHold
     * (Beschattung Wind/Regen — Blocker B). Reine Auskunft, keine Nebenwirkung.
     */
    public function isSafety(): bool
    {
        return $this->source === self::SOURCE_SAFETY;
    }

    /**
     * @return array{source:string,ts:int,meta:array}
     */
    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'ts'     => $this->ts,
            'meta'   => $this->meta,
        ];
    }

    /**
     * Normalisiert eine Herkunft auf eine der vier gueltigen Konstanten.
     */
    private static function normalizeSource(string $source): string
    {
        $valid = [
            self::SOURCE_USER,
            self::SOURCE_EXTERNAL,
            self::SOURCE_SCHEDULE,
            self::SOURCE_SAFETY,
        ];

        return in_array($source, $valid, true) ? $source : self::SOURCE_EXTERNAL;
    }
}

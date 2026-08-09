<?php

declare(strict_types=1);

namespace Hoep\HomeSuite\HAL;

/**
 * Licht-HAL Value Objects (Vertrag 3, §2.3) — reine Datenhalter, KEIN Kernel-Zugriff.
 *
 * Von ILight referenziert (ILight::readState(): LightState). Liegen bewusst im
 * Namespace Hoep\HomeSuite\HAL, damit die Interface-Signaturen ohne Namespace-
 * Import tragen — analog zu den Audio-Value-Objects.
 *
 * Grundsatz: unveraenderlich konstruiert (Konstruktor-Promotion), zusaetzlich ein
 * toArray() fuer den Reflect-/State-Snapshot und ein fromArray() zum Rekonstruieren.
 * KEINE Kernel-Aufrufe (IPS_..., GetValue) hier — Variablen liest der Treiber.
 *
 * Konventionen fuer "unbekannt":
 *   level = -1   (0..100 sonst)     color = -1   (0xRRGGBB sonst)
 *   cct   = 0    (Kelvin sonst)     watt  = -1.0 (aktuelle Schaetzung in W sonst)
 */

/**
 * LightState — Zustand EINER Lampe/eines Lichtkreises.
 *
 * readState() liefert diesen; nicht ermittelbare Felder bleiben auf ihrem
 * "unbekannt"-Default. Gruppen-/Szenen-Info gehoert NICHT hierher.
 */
final class LightState
{
    public function __construct(
        public bool $on = false,          // Schaltzustand (aus Schalter ODER level>0)
        public int $level = -1,           // Helligkeit 0..100, -1 = unbekannt/kein Dimmer
        public int $color = -1,           // Farbe 0xRRGGBB, -1 = unbekannt/keine Farbe
        public int $cct = 0,              // Farbtemperatur in Kelvin, 0 = unbekannt/kein CCT
        public float $watt = -1.0,        // aktuelle Leistungsschaetzung in W, -1 = unbekannt
        public bool $reachable = true     // Aktor erreichbar/Wert vorhanden
    ) {
    }

    public function toArray(): array
    {
        return [
            'on'        => $this->on,
            'level'     => $this->level,
            'color'     => $this->color,
            'cct'       => $this->cct,
            'watt'      => $this->watt,
            'reachable' => $this->reachable,
        ];
    }

    public static function fromArray(array $a): self
    {
        return new self(
            (bool) ($a['on'] ?? false),
            (int) ($a['level'] ?? -1),
            (int) ($a['color'] ?? -1),
            (int) ($a['cct'] ?? 0),
            (float) ($a['watt'] ?? -1.0),
            (bool) ($a['reachable'] ?? true)
        );
    }
}

/**
 * LightCapabilities — real verfuegbare Faehigkeiten EINER Lampe, aus der
 * Bindung (welche Variablen/Skripte gesetzt sind) abgeleitet.
 */
final class LightCapabilities
{
    public function __construct(
        public bool $switch = false,  // An/Aus schaltbar
        public bool $dim = false,     // Helligkeit 0..100
        public bool $color = false,   // RGB-Farbe
        public bool $cct = false,     // Farbtemperatur (Kelvin)
        public int $cctMin = 2700,    // untere CCT-Grenze (warm)
        public int $cctMax = 6500,    // obere CCT-Grenze (kalt)
        public bool $watt = false,    // Leistung schaetzbar
        public int $circuit = 0       // Stromkreis-Nummer (0 = keiner)
    ) {
    }

    public function toArray(): array
    {
        return [
            'switch'  => $this->switch,
            'dim'     => $this->dim,
            'color'   => $this->color,
            'cct'     => $this->cct,
            'cctMin'  => $this->cctMin,
            'cctMax'  => $this->cctMax,
            'watt'    => $this->watt,
            'circuit' => $this->circuit,
        ];
    }

    public static function fromArray(array $a): self
    {
        return new self(
            (bool) ($a['switch'] ?? false),
            (bool) ($a['dim'] ?? false),
            (bool) ($a['color'] ?? false),
            (bool) ($a['cct'] ?? false),
            (int) ($a['cctMin'] ?? 2700),
            (int) ($a['cctMax'] ?? 6500),
            (bool) ($a['watt'] ?? false),
            (int) ($a['circuit'] ?? 0)
        );
    }
}

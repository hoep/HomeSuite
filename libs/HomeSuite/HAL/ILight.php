<?php

declare(strict_types=1);

namespace Hoep\HomeSuite\HAL;

/**
 * ILight — HAL fuer Beleuchtungs-Aktoren (§3, Lichtsteuerung).
 *
 * Deckt die drei IPSLight-Typen (Switch/Dimmer/RGB) plus Farbtemperatur ab,
 * bindet aber — anders als IPSLight — direkt reale Symcon-Variablen ODER
 * Skripte (universelle Bindung, Nutzer-Vorgabe). Kernel-frei und zustandslos
 * (Vertrag 3, B1): der Treiber kennt nur, WELCHE Variable welchen Kanal traegt;
 * Timer/Rampen/Szenen organisiert das Modul bzw. die SceneEngine.
 *
 * capabilities() liefert bewusst ein array (Kovarianz zu IDriver::capabilities(),
 * das keinen Rueckgabetyp deklariert) — Inhalt = LightCapabilities::toArray().
 * readState() liefert den aggregierten LightState.
 *
 * "unbekannt"-Konventionen wie in LightState: level -1, color -1, cct 0, watt -1.
 */
interface ILight extends IDriver
{
    /** @return array caps-Array (LightCapabilities::toArray()). */
    public function capabilities(): array;

    /** An/Aus schalten. */
    public function setPower(bool $on): bool;

    /** true=an, false=aus, null=unbekannt. */
    public function isOn(): ?bool;

    /** Helligkeit 0..100 setzen (auf Geraete-Bereich skaliert). */
    public function setLevel(int $percent): bool;

    /** Helligkeit 0..100 oder null, wenn kein Dimmer/unbekannt. */
    public function getLevel(): ?int;

    /** Farbe als 0xRRGGBB setzen. */
    public function setColor(int $rgb): bool;

    /** Farbe 0xRRGGBB oder null, wenn keine Farbe/unbekannt. */
    public function getColor(): ?int;

    /** Farbtemperatur in Kelvin setzen (auf Geraete-Format abgebildet). */
    public function setCct(int $kelvin): bool;

    /** Farbtemperatur in Kelvin oder null, wenn kein CCT/unbekannt. */
    public function getCct(): ?int;

    /** Aktuelle Leistungsschaetzung in W oder null, wenn nicht ermittelbar. */
    public function power(): ?float;

    /** Aggregierter Ist-Zustand der Lampe. */
    public function readState(): LightState;
}

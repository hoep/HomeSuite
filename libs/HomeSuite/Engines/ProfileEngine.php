<?php

declare(strict_types=1);

namespace Hoep\HomeSuite;

/**
 * ProfileEngine — benannte Profile: ANLEGEN, BEARBEITEN und ZUWEISEN sind
 * bewusst GETRENNTE Operationen (§3). Ein Profil existiert unabhaengig davon, ob
 * und welcher Entitaet es zugewiesen ist; dieselbe Definition kann mehreren
 * Entitaeten zugewiesen werden.
 *
 * OPTIONALE 2. ACHSE (Praesenz/Variante): definiert ein profileType eine
 * 'axis2' (z. B. Heizung: Praesenz present/absent/night), so darf der fields-Blob
 * eines Profils je Achsen-Option eine eigene Teil-Definition tragen. Die Engine
 * bleibt dabei generisch — sie speichert/holt den Blob und validiert ihn gegen das
 * Schema des profileType; die achsen-/slot-genaue Zeitplanlogik liegt in der
 * ScheduleEngine bzw. im domaenenspezifischen Editor.
 *
 * ABLAGE (nur Konfiguration, NIE fluechtiger Zustand — Blocker D):
 *   Profile:      "profiles.<type>"            => { <name> => <fields> }
 *   Zuweisungen:  "assign.<entityId>.<type>"   => <name>
 *
 * Schreibzugriffe des Store sind semaphoren-serialisiert (siehe Store).
 */
final class ProfileEngine
{
    private Store $s;

    /** @var array<int,array> Manifest-profileTypes[] (fuer Schema-Validierung). */
    private array $profileTypes;

    /**
     * @param Store $s
     * @param array $profileTypes Manifest-profileTypes[] (jeweils {id,title,editor,schema,axis2?})
     */
    public function __construct(Store $s, array $profileTypes)
    {
        $this->s            = $s;
        $this->profileTypes = $profileTypes;
    }

    // --- ANLEGEN / BEARBEITEN ---------------------------------------------

    /** @return array<int,string> Namen aller Profile eines Typs. */
    public function list(string $type): array
    {
        return array_keys($this->map($type));
    }

    /**
     * Legt ein neues Profil an (schlaegt fehl, wenn Name bereits existiert).
     * @param array $fields optionaler Anfangs-Inhalt (gegen Schema validiert)
     */
    public function create(string $type, string $name, array $fields = []): void
    {
        $this->assertType($type);
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('Profilname darf nicht leer sein');
        }
        $map = $this->map($type);
        if (array_key_exists($name, $map)) {
            throw new \InvalidArgumentException('Profil existiert bereits: ' . $name);
        }
        $this->validate($type, $fields);
        $map[$name] = $fields;
        $this->s->set('profiles.' . $type, $map);
    }

    /** Benennt ein Profil um (name -> new); Inhalt bleibt, Zuweisungen bleiben am Namen haengen. */
    public function rename(string $type, string $old, string $new): void
    {
        $this->assertType($type);
        $new = trim($new);
        if ($new === '') {
            throw new \InvalidArgumentException('Neuer Profilname darf nicht leer sein');
        }
        $map = $this->map($type);
        if (!array_key_exists($old, $map)) {
            throw new \InvalidArgumentException('Profil nicht gefunden: ' . $old);
        }
        if ($old === $new) {
            return;
        }
        if (array_key_exists($new, $map)) {
            throw new \InvalidArgumentException('Zielname existiert bereits: ' . $new);
        }
        $map[$new] = $map[$old];
        unset($map[$old]);
        $this->s->set('profiles.' . $type, $map);
    }

    /** Dupliziert ein Profil unter neuem Namen (tiefer Wert-Kopie durch Store-JSON). */
    public function duplicate(string $type, string $src, string $new): void
    {
        $this->assertType($type);
        $new = trim($new);
        if ($new === '') {
            throw new \InvalidArgumentException('Neuer Profilname darf nicht leer sein');
        }
        $map = $this->map($type);
        if (!array_key_exists($src, $map)) {
            throw new \InvalidArgumentException('Quellprofil nicht gefunden: ' . $src);
        }
        if (array_key_exists($new, $map)) {
            throw new \InvalidArgumentException('Zielname existiert bereits: ' . $new);
        }
        // Wert-Kopie (Arrays sind in PHP Value-Types; verschachtelte Arrays inklusive).
        $map[$new] = $map[$src];
        $this->s->set('profiles.' . $type, $map);
    }

    /** Loescht ein Profil. Bestehende Zuweisungen auf diesen Namen bleiben (werden ins Leere zeigen). */
    public function delete(string $type, string $name): void
    {
        $this->assertType($type);
        $map = $this->map($type);
        if (!array_key_exists($name, $map)) {
            return; // idempotent
        }
        unset($map[$name]);
        $this->s->set('profiles.' . $type, $map);
    }

    /** @return array fields eines Profils. */
    public function get(string $type, string $name): array
    {
        $map = $this->map($type);
        if (!array_key_exists($name, $map)) {
            throw new \InvalidArgumentException('Profil nicht gefunden: ' . $name);
        }
        $f = $map[$name];
        return is_array($f) ? $f : [];
    }

    /** Setzt/aktualisiert die fields eines bestehenden Profils (gegen Schema validiert). */
    public function setFields(string $type, string $name, array $fields): void
    {
        $this->assertType($type);
        $map = $this->map($type);
        if (!array_key_exists($name, $map)) {
            throw new \InvalidArgumentException('Profil nicht gefunden: ' . $name);
        }
        $this->validate($type, $fields);
        $map[$name] = $fields;
        $this->s->set('profiles.' . $type, $map);
    }

    // --- ZUWEISEN ---------------------------------------------------------

    /** Weist einer Entitaet ein (existierendes) Profil eines Typs zu. */
    public function assign(int $entityId, string $type, string $name): void
    {
        $this->assertType($type);
        $map = $this->map($type);
        if (!array_key_exists($name, $map)) {
            throw new \InvalidArgumentException('Profil nicht gefunden: ' . $name);
        }
        $this->s->set('assign.' . $entityId . '.' . $type, $name);
    }

    /** Aktuell zugewiesener Profilname einer Entitaet fuer einen Typ (oder null). */
    public function assignedName(int $entityId, string $type): ?string
    {
        $v = $this->s->get('assign.' . $entityId . '.' . $type, null);
        return is_string($v) ? $v : null;
    }

    // ----------------------------------------------------------------------

    /** @return array<string,mixed> Name=>fields-Map eines Typs. */
    private function map(string $type): array
    {
        $m = $this->s->get('profiles.' . $type, []);
        return is_array($m) ? $m : [];
    }

    private function assertType(string $type): void
    {
        if ($this->profileTypeById($type) === null) {
            throw new \InvalidArgumentException('Unbekannter profileType: ' . $type);
        }
    }

    private function profileTypeById(string $type): ?array
    {
        foreach ($this->profileTypes as $pt) {
            if (is_array($pt) && ($pt['id'] ?? null) === $type) {
                return $pt;
            }
        }
        return null;
    }

    /**
     * Validiert fields gegen das Schema des profileType.
     *
     * - weekSlots-Schema (kind=='weekSlots') und unbekannte Strukturen: tolerant
     *   (Slot-/Achsen-Detailpruefung liegt in ScheduleEngine/Editor).
     * - Feld-Map-Schema (z. B. sunProfile: {azimuthBgn:{type,min,max}, ...}):
     *   pro gesetztem Feld Typ + min/max pruefen; als 'required' markierte Felder
     *   muessen vorhanden sein.
     */
    private function validate(string $type, array $fields): void
    {
        $pt = $this->profileTypeById($type);
        if ($pt === null) {
            return;
        }
        $schema = $pt['schema'] ?? null;
        if (!is_array($schema)) {
            return;
        }
        // weekSlots o. Ae. -> tolerant.
        if (($schema['kind'] ?? null) === 'weekSlots') {
            return;
        }
        // Wenn eine 2. Achse definiert ist, kann fields je Achsen-Option verschachtelt
        // sein -> hier nicht in die Tiefe validieren (Editor/Engine uebernimmt).
        if (isset($pt['axis2'])) {
            return;
        }
        $this->validateFieldMap($schema, $fields);
    }

    /**
     * @param array<string,mixed> $schema Feld-Map {key => {type,min,max,required,...}}
     * @param array<string,mixed> $fields
     */
    private function validateFieldMap(array $schema, array $fields): void
    {
        foreach ($schema as $key => $spec) {
            if (!is_array($spec)) {
                continue;
            }
            $present = array_key_exists($key, $fields);
            if (!empty($spec['required']) && !$present) {
                throw new \InvalidArgumentException('Pflichtfeld fehlt: ' . $key);
            }
            if (!$present) {
                continue;
            }
            $val  = $fields[$key];
            $ftyp = (string) ($spec['type'] ?? '');

            if (($ftyp === 'int' || $ftyp === 'float') && !is_numeric($val)) {
                throw new \InvalidArgumentException('Feld ' . $key . ' muss numerisch sein');
            }
            if ($ftyp === 'bool' && !is_bool($val) && !is_numeric($val)) {
                throw new \InvalidArgumentException('Feld ' . $key . ' muss bool sein');
            }
            if (is_numeric($val)) {
                $num = (float) $val;
                if (isset($spec['min']) && $num < (float) $spec['min']) {
                    throw new \InvalidArgumentException('Feld ' . $key . ' unter Minimum');
                }
                if (isset($spec['max']) && $num > (float) $spec['max']) {
                    throw new \InvalidArgumentException('Feld ' . $key . ' ueber Maximum');
                }
            }
        }
    }
}

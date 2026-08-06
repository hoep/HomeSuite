<?php

declare(strict_types=1);

namespace Hoep\HomeSuite;

/**
 * Store — persistente Konfig-/Profil-Ablage je Instanz.
 *
 * Speichert einen JSON-Baum im Instanz-Attribut "FabricStore"
 * (RegisterAttributeString), das die {@see EntityModule::Create()} anlegt.
 * Zugriff per Punkt-Pfad ("profiles.roomProfile.Bad_OG").
 *
 * Design-Entscheidungen am Code:
 *  - Blocker D: HIER liegt AUSSCHLIESSLICH selten geschriebene Konfiguration
 *        und Profildaten. FLUECHTIGER Reflect (Ist-/Transportzustand, Positionen)
 *        gehoert NIE in den Store — er lebt in Statusvariablen bzw. volatilen
 *        RegisterAttribute-Feldern (manualHold). Wuerde man Reflect hier
 *        ablegen, verschliesse man das Attribut bei jedem Push -> Verschleiss.
 *  - Schreibpfad ist Semaphoren-serialisiert (Read-modify-write unter Lock),
 *        damit nebenlaeufige Manage-Ops/Timer keine Konfig verlieren. Da der
 *        Store per Definition NUR SELTEN schreibt, ist das kein Hot-Path-Risiko
 *        (Blocker J betrifft Timer/Hook, nicht diesen selten genutzten Lock).
 */
final class Store
{
    private \IPSModule $module;
    private string $attr;

    /** In-Memory-Cache des zuletzt gelesenen Baums (Lesekosten senken). */
    private ?array $cache = null;

    public function __construct(\IPSModule $module, string $attr = 'FabricStore')
    {
        $this->module = $module;
        $this->attr   = $attr;
    }

    /**
     * Liest einen Wert per Punkt-Pfad. Leerer Pfad => gesamter Baum.
     *
     * @param string $path    z. B. "config.holdMinutes" oder "" fuer alles.
     * @param mixed  $default Rueckgabe, wenn der Pfad nicht existiert.
     * @return mixed
     */
    public function get(string $path, $default = null)
    {
        $tree = $this->load();

        if ($path === '') {
            return $tree;
        }

        $node = $tree;
        foreach (self::segments($path) as $seg) {
            if (is_array($node) && array_key_exists($seg, $node)) {
                $node = $node[$seg];
            } else {
                return $default;
            }
        }

        return $node;
    }

    /**
     * Setzt einen Wert per Punkt-Pfad (legt Zwischenknoten an). Serialisiert.
     *
     * @param mixed $value
     */
    public function set(string $path, $value): void
    {
        $this->mutate(function (array $tree) use ($path, $value): array {
            if ($path === '') {
                // Ganzen Baum ersetzen (nur mit Array sinnvoll).
                return is_array($value) ? $value : $tree;
            }
            $this->assignByPath($tree, self::segments($path), $value);
            return $tree;
        });
    }

    /**
     * Merged ein Teil-Array flach in den (Array-)Knoten am Pfad. Serialisiert.
     *
     * @param array<string,mixed> $partial
     */
    public function patch(string $path, array $partial): void
    {
        $this->mutate(function (array $tree) use ($path, $partial): array {
            $current = ($path === '') ? $tree : $this->get($path, []);
            if (!is_array($current)) {
                $current = [];
            }
            $merged = array_replace($current, $partial);

            if ($path === '') {
                return $merged;
            }
            $this->assignByPath($tree, self::segments($path), $merged);
            return $tree;
        });
    }

    /**
     * Gesamter Konfig-Baum als Array.
     *
     * @return array<string,mixed>
     */
    public function all(): array
    {
        return $this->load();
    }

    // ------------------------------------------------------------------
    // interne Mechanik
    // ------------------------------------------------------------------

    /**
     * Read-modify-write unter Semaphore. Innerhalb des Locks wird FRISCH
     * gelesen (nicht der Cache), damit parallele Writes nicht verloren gehen.
     *
     * @param callable(array):array $fn
     */
    private function mutate(callable $fn): void
    {
        $sem = $this->semaphoreName();
        $ok  = false;

        // Non-blocking mit kurzem Timeout: Store schreibt selten, ein 2s-Fenster
        // reicht selbst bei kollidierenden Manage-Ops locker aus.
        if (function_exists('IPS_SemaphoreEnter')) {
            $ok = @\IPS_SemaphoreEnter($sem, 2000);
        }

        try {
            $tree    = $this->readRaw();      // frisch aus dem Attribut, cache-los
            $updated = $fn($tree);
            $this->writeRaw($updated);
            $this->cache = $updated;
        } finally {
            if ($ok && function_exists('IPS_SemaphoreLeave')) {
                @\IPS_SemaphoreLeave($sem);
            }
        }
    }

    /**
     * Baum aus dem Cache oder (bei Cache-Miss) frisch aus dem Attribut.
     *
     * @return array<string,mixed>
     */
    private function load(): array
    {
        if ($this->cache === null) {
            $this->cache = $this->readRaw();
        }
        return $this->cache;
    }

    /**
     * Roh-Lesen des JSON-Attributs -> Array (defensiv bei kaputtem JSON).
     *
     * @return array<string,mixed>
     */
    private function readRaw(): array
    {
        $raw = '';
        try {
            $raw = (string) $this->module->ReadAttributeString($this->attr);
        } catch (\Throwable $e) {
            // Attribut (noch) nicht registriert o. ae. -> leerer Baum.
            return [];
        }

        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Roh-Schreiben des Baums als JSON ins Attribut.
     *
     * @param array<string,mixed> $tree
     */
    private function writeRaw(array $tree): void
    {
        $json = json_encode($tree, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $json = '{}';
        }
        $this->module->WriteAttributeString($this->attr, $json);
    }

    /**
     * Weist einen Wert entlang der Pfadsegmente zu und legt fehlende
     * Zwischenknoten als Arrays an (per Referenz auf $tree).
     *
     * @param array<string,mixed> $tree
     * @param string[]            $segments
     * @param mixed               $value
     */
    private function assignByPath(array &$tree, array $segments, $value): void
    {
        $ref = &$tree;
        $last = array_pop($segments);

        foreach ($segments as $seg) {
            if (!isset($ref[$seg]) || !is_array($ref[$seg])) {
                $ref[$seg] = [];
            }
            $ref = &$ref[$seg];
        }

        $ref[$last] = $value;
        unset($ref);
    }

    /**
     * Instanz-eindeutiger Semaphor-Name (pro Instanz + Attribut).
     */
    private function semaphoreName(): string
    {
        $id = 0;
        // InstanceID ist am IPSModule verfuegbar; defensiv gekapselt.
        try {
            $id = (int) $this->module->InstanceID;
        } catch (\Throwable $e) {
            $id = 0;
        }
        return 'HS.Store.' . $id . '.' . $this->attr;
    }

    /**
     * Zerlegt einen Punkt-Pfad in Segmente.
     *
     * @return string[]
     */
    private static function segments(string $path): array
    {
        return array_values(array_filter(explode('.', $path), static fn($s) => $s !== ''));
    }
}

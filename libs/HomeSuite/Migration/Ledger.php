<?php

declare(strict_types=1);

namespace Hoep\HomeSuite\Migration;

/**
 * Ledger — reine ORCHESTRIERUNGSSICHT der Migrationsphasen (Blocker I).
 *
 * WICHTIG: Das Ledger ist NIEMALS die Freigabequelle fuer einen realen Fahrbefehl
 * — das ist ausschliesslich der ActuatorGate-Modus (lokale Safety-Wahrheit). Das
 * Ledger dokumentiert nur, in welcher Phase eine Entitaet/Domaene steckt, damit
 * Verwaltung/UI den Fortschritt sehen und Migrationen re-entrant sind.
 *
 * REDUNDANZ: Der Zustand wird als JSON-Zustandskarte gehalten UND jede Transition
 * zusaetzlich in ein append-only Transitions-Log geschrieben (so ist die Historie
 * auch dann rekonstruierbar, wenn die Karte verloren geht). In der Modul-Praxis
 * spiegelt das Modul die Karte zusaetzlich in ein Attribut.
 */
final class Ledger
{
    // Phasen (monoton steigend im Normalfluss).
    public const NONE    = 0;
    public const PLANNED = 1;
    public const APPLIED = 2;
    public const SHADOW  = 3;
    public const LIVE    = 4;
    public const RETIRED = 5;

    private string $scope;
    private ?string $dataDir;

    /**
     * @param string      $scope   logischer Namensraum (z. B. 'heating', 'shading')
     * @param string|null $dataDir Ablage-Verzeichnis; Default: <Kernel>/homesuite/ledger
     */
    public function __construct(string $scope = 'default', ?string $dataDir = null)
    {
        $this->scope   = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $scope) ?: 'default';
        $this->dataDir = $dataDir;
    }

    /** Setzt die Phase einer Entitaet/Domaene und schreibt eine Transition ins Log. */
    public function set(string $entityId, int $phase): void
    {
        $map = $this->load();
        $prev = $map[$entityId] ?? self::NONE;
        $map[$entityId] = $phase;
        $this->store($map);
        $this->appendTransition($entityId, (int) $prev, $phase);
    }

    /** Aktuelle Phase (Default NONE). */
    public function get(string $entityId): int
    {
        $map = $this->load();
        return (int) ($map[$entityId] ?? self::NONE);
    }

    /** @return array<string,int> vollstaendige Zustandskarte. */
    public function all(): array
    {
        return $this->load();
    }

    // ----------------------------------------------------------------------

    /** @return array<string,int> */
    private function load(): array
    {
        $file = $this->mapFile();
        if (!is_file($file)) {
            return [];
        }
        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return [];
        }
        $out = [];
        foreach ($data as $k => $v) {
            $out[(string) $k] = (int) $v;
        }
        return $out;
    }

    /** @param array<string,int> $map */
    private function store(array $map): void
    {
        $file = $this->mapFile();
        $this->ensureDir(dirname($file));
        @file_put_contents($file, json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    private function appendTransition(string $entityId, int $from, int $to): void
    {
        $file = $this->logFile();
        $this->ensureDir(dirname($file));
        $line = json_encode([
            'ts'     => time(),
            'entity' => $entityId,
            'from'   => $from,
            'to'     => $to,
        ], JSON_UNESCAPED_UNICODE) . "\n";
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }

    private function baseDir(): string
    {
        if ($this->dataDir !== null && $this->dataDir !== '') {
            return rtrim($this->dataDir, '/\\');
        }
        $base = function_exists('IPS_GetKernelDir') ? rtrim((string) \IPS_GetKernelDir(), '/\\') : sys_get_temp_dir();
        return $base . '/homesuite/ledger';
    }

    private function mapFile(): string
    {
        return $this->baseDir() . '/ledger_' . $this->scope . '.json';
    }

    private function logFile(): string
    {
        return $this->baseDir() . '/ledger_' . $this->scope . '.log';
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
    }
}

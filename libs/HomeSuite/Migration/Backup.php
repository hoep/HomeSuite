<?php

declare(strict_types=1);

namespace Hoep\HomeSuite\Migration;

/**
 * Backup — kanonische Snapshots + Restore (Blocker H, §6.3-5).
 *
 * snapshot() sichert einen Objekt-Teilbaum kanonisch (nach ID sortiert) UND —
 * zwingend — die migrations-kritischen Zusatzflags aus $extra:
 *   'eventActive'   => { eventId => bool }   Aktiv-Zustand von Alt-Ereignissen/Timern
 *   'oldAutomatic'  => { varId   => value }  Alt-"Automatik"-Schalter je Geraet
 *   'globalConfig'  => beliebig             Modul-globale Konfig-Kategorie (z. B. IDs wie 12192)
 *
 * restore() stellt eventActive/oldAutomatic ZUERST wieder her (Blocker H) und
 * danach die Variablenwerte — so reaktiviert ein Rollback die Alt-Automatik
 * verlaesslich, bevor irgendein Wert zurueckgesetzt wird.
 *
 * Ringpuffer: es werden hoechstens MAX_KEEP Snapshots vorgehalten (aeltere werden
 * verworfen).
 */
final class Backup
{
    private const MAX_KEEP = 20;

    private ?string $dataDir;

    /**
     * @param string|null $dataDir Ablage; Default: <Kernel>/homesuite/backups
     */
    public function __construct(?string $dataDir = null)
    {
        $this->dataDir = $dataDir;
    }

    /** Verzeichnis, in dem Snapshots liegen. */
    public function dir(): string
    {
        if ($this->dataDir !== null && $this->dataDir !== '') {
            return rtrim($this->dataDir, '/\\');
        }
        $base = function_exists('IPS_GetKernelDir') ? rtrim((string) \IPS_GetKernelDir(), '/\\') : sys_get_temp_dir();
        return $base . '/homesuite/backups';
    }

    /**
     * Erzeugt einen Snapshot der genannten Objekte + $extra und liefert den vollen
     * Dateipfad. Der Basename traegt einen Zeitstempel; der KANONISCHE Objektteil
     * (fuer Vergleichbarkeit) ist nach ID sortiert und ohne Zeitstempel.
     *
     * @param array $objectIds Objekt-IDs des zu sichernden Teilbaums
     * @param array $extra      siehe Klassen-Doc (eventActive/oldAutomatic/globalConfig)
     * @return string voller Pfad der Snapshot-Datei
     */
    public function snapshot(array $objectIds, array $extra): string
    {
        $ids = array_values(array_unique(array_map('intval', $objectIds)));
        sort($ids, SORT_NUMERIC);

        $objects = [];
        foreach ($ids as $id) {
            $entry = $this->captureObject($id);
            if ($entry !== null) {
                $objects[$id] = $entry;
            }
        }
        ksort($objects, SORT_NUMERIC);

        $payload = [
            'version' => 1,
            'objects' => $objects,
            'extra'   => $extra,
        ];

        $dir = $this->dir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $file = $dir . '/' . date('Ymd-His') . '_' . substr(bin2hex(random_bytes(3)), 0, 6) . '_snapshot.json';
        @file_put_contents($file, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);

        $this->prune($dir);
        return $file;
    }

    /**
     * Stellt einen Snapshot wieder her. Reihenfolge (Blocker H):
     *   1) eventActive-Flags (IPS_SetEventActive)
     *   2) oldAutomatic-Schalter (SetValue/RequestAction)
     *   3) uebrige Variablenwerte
     *
     * @param string $file voller Pfad ODER Basename (wird gegen dir() aufgeloest)
     */
    public function restore(string $file): void
    {
        $path = is_file($file) ? $file : $this->dir() . '/' . basename($file);
        $raw  = @file_get_contents($path);
        if ($raw === false) {
            $this->log('restore: Snapshot nicht lesbar: ' . $path);
            return;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $this->log('restore: Snapshot unlesbar/kein JSON: ' . $path);
            return;
        }
        $extra = is_array($data['extra'] ?? null) ? $data['extra'] : [];

        // 1) eventActive ZUERST — Alt-Automatik/Timer reaktivieren.
        if (isset($extra['eventActive']) && is_array($extra['eventActive'])) {
            foreach ($extra['eventActive'] as $eventId => $active) {
                $eid = (int) $eventId;
                if ($eid > 0 && function_exists('IPS_SetEventActive')) {
                    @\IPS_SetEventActive($eid, (bool) $active);
                }
            }
        }

        // 2) oldAutomatic-Schalter danach.
        if (isset($extra['oldAutomatic']) && is_array($extra['oldAutomatic'])) {
            foreach ($extra['oldAutomatic'] as $varId => $value) {
                $this->writeVar((int) $varId, $value);
            }
        }

        // 3) uebrige Variablenwerte.
        if (isset($data['objects']) && is_array($data['objects'])) {
            foreach ($data['objects'] as $entry) {
                if (!is_array($entry) || (int) ($entry['type'] ?? -1) !== 2) {
                    continue; // nur Variablen tragen Werte
                }
                if (!array_key_exists('value', $entry)) {
                    continue;
                }
                $this->writeVar((int) ($entry['id'] ?? 0), $entry['value'], false);
            }
        }
    }

    // ----------------------------------------------------------------------

    /** @return array{id:int,type:int,name:string,parent:int,value?:mixed,eventActive?:bool}|null */
    private function captureObject(int $id): ?array
    {
        if ($id <= 0 || !function_exists('IPS_GetObject')) {
            return null;
        }
        $o = @\IPS_GetObject($id);
        if (!is_array($o)) {
            return null;
        }
        $type = (int) ($o['ObjectType'] ?? -1);
        $entry = [
            'id'     => $id,
            'type'   => $type,
            'name'   => (string) ($o['ObjectName'] ?? ''),
            'parent' => (int) ($o['ParentID'] ?? 0),
        ];
        if ($type === 2 && function_exists('GetValue')) { // Variable
            $entry['value'] = @\GetValue($id);
        }
        if ($type === 4 && function_exists('IPS_GetEvent')) { // Ereignis
            $ev = @\IPS_GetEvent($id);
            if (is_array($ev)) {
                $entry['eventActive'] = (bool) ($ev['EventActive'] ?? false);
            }
        }
        return $entry;
    }

    private function writeVar(int $vid, $value, bool $preferAction = true): void
    {
        if ($vid <= 0) {
            return;
        }
        try {
            if ($preferAction && $this->isActionable($vid) && function_exists('RequestAction')) {
                @\RequestAction($vid, $value);
                return;
            }
            if (function_exists('SetValue')) {
                @\SetValue($vid, $value);
            }
        } catch (\Throwable $e) {
            $this->log('restore writeVar #' . $vid . ': ' . $e->getMessage());
        }
    }

    private function isActionable(int $vid): bool
    {
        if (!function_exists('IPS_GetVariable')) {
            return false;
        }
        $v = @\IPS_GetVariable($vid);
        if (!is_array($v)) {
            return false;
        }
        return (int) ($v['VariableAction'] ?? 0) > 0
            || (int) ($v['VariableCustomAction'] ?? 0) > 0;
    }

    /** Behaelt nur die MAX_KEEP neuesten Snapshots. */
    private function prune(string $dir): void
    {
        $files = glob($dir . '/*_snapshot.json');
        if (!is_array($files) || count($files) <= self::MAX_KEEP) {
            return;
        }
        // Aeltere zuerst (nach Dateiname, der mit dem Zeitstempel beginnt).
        sort($files, SORT_STRING);
        $remove = count($files) - self::MAX_KEEP;
        for ($i = 0; $i < $remove; $i++) {
            @unlink($files[$i]);
        }
    }

    private function log(string $msg): void
    {
        if (function_exists('IPS_LogMessage')) {
            @\IPS_LogMessage('HS.Backup', $msg);
        } else {
            error_log('HS.Backup: ' . $msg);
        }
    }
}

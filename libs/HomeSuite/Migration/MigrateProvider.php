<?php

declare(strict_types=1);

namespace Hoep\HomeSuite\Migration;

/**
 * MigrateProvider — Strangler-Fig-Orchestrator: plan/apply/verify/cutover/rollback/
 * retire/status (§7). Domaenen-agnostisch; die domaenenspezifischen Schritte
 * (Enumeration des Alt-Teilbaums, Plan-Bau, idempotentes Apply, berechnungs-
 * basiertes verify, retire) werden als Strategie (Callables) je Domaene injiziert.
 * So bleiben die oeffentlichen Signaturen exakt (plan(domain), apply(domain,
 * planHash, baseVersion, confirm)) und der Kern generisch.
 *
 * TOCTOU-SCHUTZ (Blocker F): plan() liefert
 *   baseVersion = sha256(canonicalSubtree(ids))   // Zustand des betroffenen Teilbaums
 *   planHash    = sha256(canonicalPlan(...))       // kanonischer Plan
 * apply() verlangt confirm == planHash UND prueft baseVersion gegen den JETZIGEN
 * Teilbaum-Hash; bei Abweichung -> {ok:false, error:"conflict"}.
 *
 * SICHERHEIT: apply() schreibt NIE Hardware (nur idempotente Objekt-Anlage) und
 * legt ZUERST ein Backup (inkl. eventActive/oldAutomatic/globalConfig) an. Der
 * reale Fahrbefehl kommt erst bei cutover ueber den ActuatorGate (lokale Safety-
 * Wahrheit) — nie ueber das Ledger (Blocker I).
 *
 * STRATEGIE je Domaene ($domains[$domain]) = Array von Callables:
 *   'enumerate'   fn(): int[]                  Objekt-IDs des Alt-Teilbaums (inkl. globaler Konfig-Kategorie)
 *   'buildPlan'   fn(int[] $ids): array        ['report'=>..,'warnings'=>..,'steps'=>..]   (optional)
 *   'backupExtra' fn(int[] $ids): array        eventActive/oldAutomatic/globalConfig       (optional)
 *   'apply'       fn(int[] $ids): array        idempotente Anlage; KEIN HW-Schreiben        (optional)
 *   'verify'      fn(): array                  ['ok'=>bool,'report'=>..]                    (optional)
 *   'retire'      fn(): array                                                              (optional)
 */
final class MigrateProvider
{
    private Ledger $ledger;
    private Backup $backup;

    /** @var array<string,array<string,callable>> */
    private array $domains;

    /**
     * @param array<string,array<string,callable>> $domains
     */
    public function __construct(Ledger $ledger, Backup $backup, array $domains = [])
    {
        $this->ledger  = $ledger;
        $this->backup  = $backup;
        $this->domains = $domains;
    }

    /** Registriert/ersetzt die Strategie einer Domaene. */
    public function registerDomain(string $domain, array $strategy): void
    {
        $this->domains[$domain] = $strategy;
    }

    /**
     * Erstellt einen Migrationsplan fuer eine Domaene.
     * @return array{ok:bool,domain:string,ids:int[],baseVersion:string,planHash:string,report:array,warnings:array,steps:array}
     */
    public function plan(string $domain): array
    {
        $s   = $this->strategy($domain);
        $ids = $this->enumerate($s);

        $baseVersion = hash('sha256', self::canonicalSubtree($ids));

        $built = ['report' => [], 'warnings' => [], 'steps' => []];
        if (isset($s['buildPlan'])) {
            $b = ($s['buildPlan'])($ids);
            if (is_array($b)) {
                $built = array_merge($built, $b);
            }
        }
        $steps    = is_array($built['steps'] ?? null) ? $built['steps'] : [];
        $planHash = hash('sha256', self::canonicalPlan($domain, $ids, $steps));

        return [
            'ok'          => true,
            'domain'      => $domain,
            'ids'         => $ids,
            'baseVersion' => $baseVersion,
            'planHash'    => $planHash,
            'report'      => is_array($built['report'] ?? null) ? $built['report'] : [],
            'warnings'    => is_array($built['warnings'] ?? null) ? $built['warnings'] : [],
            'steps'       => $steps,
        ];
    }

    /**
     * Wendet einen zuvor geplanten Migrationsschritt an — mit Konflikt- und
     * Bestaetigungspruefung. KEIN Hardware-Schreiben; Backup zuerst.
     *
     * @param string $planHash    aus plan()
     * @param string $baseVersion aus plan()
     * @param string $confirm     MUSS == $planHash sein (bewusste Bestaetigung)
     * @return array
     */
    public function apply(string $domain, string $planHash, string $baseVersion, string $confirm): array
    {
        $s = $this->strategy($domain);

        if (!hash_equals($planHash, $confirm)) {
            return ['ok' => false, 'error' => 'validation', 'detail' => 'confirm muss dem planHash entsprechen'];
        }

        $ids = $this->enumerate($s);
        $now = hash('sha256', self::canonicalSubtree($ids));
        if (!hash_equals($baseVersion, $now)) {
            // TOCTOU: der Teilbaum hat sich seit plan() geaendert.
            return ['ok' => false, 'error' => 'conflict', 'detail' => 'Teilbaum hat sich seit dem Plan geaendert'];
        }

        // Backup VOR jeder Struktur-Aenderung (inkl. eventActive/oldAutomatic/globalConfig).
        $extra = [];
        if (isset($s['backupExtra'])) {
            $e = ($s['backupExtra'])($ids);
            if (is_array($e)) {
                $extra = $e;
            }
        }
        $snapshot = $this->backup->snapshot($ids, $extra);

        // Idempotente Anlage (KEIN HW-Schreiben). Aktoren bleiben abgeklemmt (Gate SUSPEND).
        $result = [];
        if (isset($s['apply'])) {
            $r = ($s['apply'])($ids);
            if (is_array($r)) {
                $result = $r;
            }
        }

        // Ledger: Domaene auf APPLIED; einzelne Entitaeten, falls die Strategie sie meldet.
        $this->ledger->set('domain:' . $domain, Ledger::APPLIED);
        if (isset($result['entities']) && is_array($result['entities'])) {
            foreach ($result['entities'] as $eid) {
                $this->ledger->set((string) $eid, Ledger::APPLIED);
            }
        }

        $this->audit('apply', $domain, ['ids' => count($ids), 'snapshot' => basename($snapshot)]);

        return [
            'ok'          => true,
            'op'          => 'apply',
            'domain'      => $domain,
            'baseVersion' => $baseVersion,
            'planHash'    => $planHash,
            'snapshot'    => basename($snapshot),
            'result'      => $result,
            'warnings'    => [],
        ];
    }

    /**
     * BERECHNUNGSbasierte Verifikation (Blocker C bei Heizung): delegiert an die
     * Strategie, die z. B. fuer jeden Slot den ScheduleEngine-Sollwert gegen den
     * rekonstruierten Alt-Wert vergleicht — saisonunabhaengig.
     */
    public function verify(string $domain): array
    {
        $s = $this->strategy($domain);
        if (!isset($s['verify'])) {
            return ['ok' => false, 'error' => 'not_supported', 'detail' => 'Domaene bietet kein verify'];
        }
        $r = ($s['verify'])();
        return is_array($r) ? $r : ['ok' => false, 'error' => 'verify_failed'];
    }

    /**
     * Cutover EINER Entitaet: das atomare Lock-Protokoll (Alt-Automatik an der
     * Quelle abklemmen, Idle-Pruefung, Alt-Semaphor) liefert der Aufrufer als
     * $protocol. Bei Erfolg -> Gate LIVE + Ledger LIVE; bei Fehler -> Gate SUSPEND
     * (fail-safe) und die Entitaet bleibt regelungsseitig unberuehrt.
     *
     * @param callable $protocol fn(): bool|array  (true / ['ok'=>true] bei Erfolg)
     */
    public function cutover(string $entityId, ActuatorGate $gate, callable $protocol): array
    {
        $res = $protocol();
        $ok  = ($res === true) || (is_array($res) && !empty($res['ok']));

        if (!$ok) {
            $gate->setMode(ActuatorGate::SUSPEND);
            $detail = is_array($res) ? (string) ($res['error'] ?? '') : '';
            $this->audit('cutover_failed', $entityId, ['detail' => $detail]);
            return ['ok' => false, 'entity' => $entityId, 'error' => 'cutover_failed', 'detail' => $detail];
        }

        $gate->setMode(ActuatorGate::LIVE);
        $this->ledger->set($entityId, Ledger::LIVE);
        $this->audit('cutover', $entityId, []);
        return ['ok' => true, 'entity' => $entityId, 'phase' => 'live'];
    }

    /**
     * Rollback EINER Entitaet: Gate SUSPEND, Backup zurueckspielen (eventActive/
     * oldAutomatic ZUERST, Blocker H), Ledger auf SHADOW. Es werden NIE Objekte
     * geloescht (kein Delete im Rollback).
     */
    public function rollback(string $entityId, ActuatorGate $gate, string $backupFile): array
    {
        $gate->setMode(ActuatorGate::SUSPEND);
        $this->backup->restore($backupFile);
        $this->ledger->set($entityId, Ledger::SHADOW);
        $this->audit('rollback', $entityId, ['snapshot' => basename($backupFile)]);
        return ['ok' => true, 'entity' => $entityId, 'phase' => 'shadowed'];
    }

    /**
     * Retire einer ganzen Domaene (spaet): Strategie-Hook ausfuehren, Ledger auf
     * RETIRED. Erst danach darf das Gate einen EIGENEN Semaphor-Namensraum nutzen.
     */
    public function retire(string $domain): array
    {
        $s = $this->strategy($domain);
        $result = [];
        if (isset($s['retire'])) {
            $r = ($s['retire'])();
            if (is_array($r)) {
                $result = $r;
            }
        }
        $this->ledger->set('domain:' . $domain, Ledger::RETIRED);
        $this->audit('retire', $domain, []);
        return ['ok' => true, 'domain' => $domain, 'phase' => 'retired', 'result' => $result];
    }

    /** Gesamtstatus (Ledger-Zustandskarte + bekannte Domaenen). */
    public function status(): array
    {
        return [
            'ok'      => true,
            'ledger'  => $this->ledger->all(),
            'domains' => array_keys($this->domains),
        ];
    }

    // ----------------------------------------------------------------------

    /**
     * Kanonische Repraesentation eines Objekt-Teilbaums: IDs numerisch sortiert,
     * je Objekt sortierte Schluessel, KEINE Zeitstempel. Grundlage fuer baseVersion.
     */
    public static function canonicalSubtree(array $ids): string
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids, SORT_NUMERIC);

        $map = [];
        foreach ($ids as $id) {
            $entry = ['id' => $id];
            if (function_exists('IPS_GetObject')) {
                $o = @\IPS_GetObject($id);
                if (is_array($o)) {
                    $entry['type']   = (int) ($o['ObjectType'] ?? -1);
                    $entry['name']   = (string) ($o['ObjectName'] ?? '');
                    $entry['parent'] = (int) ($o['ParentID'] ?? 0);
                    $entry['ident']  = (string) ($o['ObjectIdent'] ?? '');
                    if ((int) ($o['ObjectType'] ?? -1) === 2 && function_exists('GetValue')) {
                        $entry['value'] = @\GetValue($id);
                    }
                    if ((int) ($o['ObjectType'] ?? -1) === 4 && function_exists('IPS_GetEvent')) {
                        $ev = @\IPS_GetEvent($id);
                        if (is_array($ev)) {
                            $entry['eventActive'] = (bool) ($ev['EventActive'] ?? false);
                        }
                    }
                }
            }
            ksort($entry);
            $map[$id] = $entry;
        }
        ksort($map, SORT_NUMERIC);

        return json_encode($map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Kanonische Plan-Repraesentation (fuer planHash): Domaene + sortierte IDs +
     * tief sortierte Schritt-Struktur, KEINE Zeitstempel.
     */
    private static function canonicalPlan(string $domain, array $ids, array $steps): string
    {
        sort($ids, SORT_NUMERIC);
        $canon = [
            'domain' => $domain,
            'ids'    => array_values($ids),
            'steps'  => self::deepKsort($steps),
        ];
        ksort($canon);
        return json_encode($canon, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** Rekursives ksort fuer deterministische Serialisierung (Listen bleiben in Reihenfolge). */
    private static function deepKsort($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        $isList = array_keys($value) === range(0, count($value) - 1);
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = self::deepKsort($v);
        }
        if (!$isList) {
            ksort($out);
        }
        return $out;
    }

    /** @return array<string,callable> */
    private function strategy(string $domain): array
    {
        if (!isset($this->domains[$domain])) {
            throw new \InvalidArgumentException('Unbekannte Migrations-Domaene: ' . $domain);
        }
        return $this->domains[$domain];
    }

    /** @return int[] */
    private function enumerate(array $strategy): array
    {
        if (!isset($strategy['enumerate'])) {
            return [];
        }
        $ids = ($strategy['enumerate'])();
        if (!is_array($ids)) {
            return [];
        }
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    private function audit(string $op, string $subject, array $meta): void
    {
        if (!function_exists('IPS_LogMessage')) {
            return;
        }
        @\IPS_LogMessage('HS.Migrate', json_encode([
            'op'      => $op,
            'subject' => $subject,
            'meta'    => $meta,
            'ts'      => time(),
        ], JSON_UNESCAPED_UNICODE));
    }
}

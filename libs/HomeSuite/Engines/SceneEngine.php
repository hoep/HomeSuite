<?php

declare(strict_types=1);

namespace Hoep\HomeSuite\Engines;

/**
 * SceneEngine — Speicherung/Verwaltung von Licht-Szenen (Haus-Ebene, im Hub-Store).
 *
 * Reine Datenhaltung (CRUD) auf einem Store-Schluessel; das LESEN des Ist-Zustands
 * (Capture) und das ANWENDEN (Apply -> Variablen schreiben) macht der Hub, weil dafuer
 * Kernel-Zugriff (GetValue/RequestAction) noetig ist. So bleibt die Engine testbar.
 *
 * Szenen-Schema:
 *   id          string  stabiler Slug (aus Name), eindeutig
 *   name        string
 *   icon        string  optional (Widget-Icon)
 *   scope       array   ['type'=>'house'|'floor'|'room', 'ref'=>'<Geschossname>'|'<RaumInstanzId>']
 *   transitionMs int    Überblendzeit (0 = sofort; Rampe macht der Anwender/Timer)
 *   members     array   [ ['device'=>int, 'on'=>bool, 'level'=>int(-1=egal), 'color'=>int(-1), 'cct'=>int(0)] ]
 *   updated     int     Unix-Zeit (vom Aufrufer gestempelt)
 */
final class SceneEngine
{
    /** @var object Store mit get(key,def)/set(key,val) */
    private $store;
    private string $key;

    public function __construct($store, string $key = 'lightScenes')
    {
        $this->store = $store;
        $this->key   = $key;
    }

    /** @return array<string,array> id => scene */
    private function all(): array
    {
        $a = $this->store->get($this->key, []);
        return is_array($a) ? $a : [];
    }

    private function put(array $all): void
    {
        $this->store->set($this->key, $all);
    }

    /** Kurzuebersicht aller Szenen (fuer Chips/Listen). */
    public function list(): array
    {
        $out = [];
        foreach ($this->all() as $id => $s) {
            $out[] = [
                'id'    => (string) $id,
                'name'  => (string) ($s['name'] ?? $id),
                'icon'  => (string) ($s['icon'] ?? 'bulb'),
                'scope' => $s['scope'] ?? ['type' => 'house', 'ref' => ''],
                'count' => is_array($s['members'] ?? null) ? count($s['members']) : 0,
                'updated' => (int) ($s['updated'] ?? 0),
            ];
        }
        usort($out, fn($a, $b) => strcasecmp($a['name'], $b['name']));
        return $out;
    }

    public function get(string $id): ?array
    {
        $all = $this->all();
        return isset($all[$id]) ? $all[$id] : null;
    }

    /** Slug aus Namen (a-z0-9-), eindeutig gegen vorhandene ids (ausser $keepId). */
    public function slugify(string $name, string $keepId = ''): string
    {
        $s = strtolower($name);
        $s = preg_replace('/[^a-z0-9]+/', '-', $s);
        $s = trim((string) $s, '-');
        if ($s === '') {
            $s = 'szene';
        }
        $all = $this->all();
        $base = $s;
        $i = 2;
        while (isset($all[$s]) && $s !== $keepId) {
            $s = $base . '-' . $i++;
        }
        return $s;
    }

    /**
     * Anlegen/Aktualisieren. Ohne id wird eine aus dem Namen erzeugt. Gibt die
     * gespeicherte Szene (inkl. id) zurueck.
     */
    public function save(array $scene, int $now = 0): array
    {
        $id = (string) ($scene['id'] ?? '');
        $name = trim((string) ($scene['name'] ?? ''));
        if ($name === '') {
            $name = $id !== '' ? $id : 'Szene';
        }
        if ($id === '') {
            $id = $this->slugify($name);
        }
        $members = [];
        foreach ((array) ($scene['members'] ?? []) as $m) {
            $dev = (int) ($m['device'] ?? 0);
            if ($dev <= 0) {
                continue;
            }
            $members[] = [
                'device' => $dev,
                'on'     => (bool) ($m['on'] ?? false),
                'level'  => array_key_exists('level', $m) ? (int) $m['level'] : -1,
                'color'  => array_key_exists('color', $m) ? (int) $m['color'] : -1,
                'cct'    => array_key_exists('cct', $m) ? (int) $m['cct'] : 0,
            ];
        }
        // Schaltbare Variablen als zweite Mitgliederart. Eine Szene ist selten nur Licht:
        // "Fernsehen" heisst Stehlampe + Ambiente + Receiver an - und beim Ausschalten
        // wieder alle drei aus. Deshalb je Variable ein Ein- UND ein Aus-Wert; ohne
        // Aus-Wert wuesste die Szene beim Abschalten nicht, wohin.
        $vars = [];
        foreach ((array) ($scene['vars'] ?? []) as $v) {
            $vid = (int) ($v['vid'] ?? 0);
            if ($vid <= 0) {
                continue;
            }
            $vars[] = [
                'vid'  => $vid,
                'name' => trim((string) ($v['name'] ?? '')),
                'on'   => $v['on']  ?? true,
                'off'  => $v['off'] ?? false,
            ];
        }

        // Skripte als dritte Mitgliederart. Manches laesst sich nicht als Variable
        // ausdruecken - eine Geraetesequenz, eine Fahrt, eine Benachrichtigung.
        // 'when' sagt, in welche Richtung das Skript laeuft.
        $scripts = [];
        foreach ((array) ($scene['scripts'] ?? []) as $sc) {
            $sid = (int) ($sc['sid'] ?? 0);
            if ($sid <= 0) {
                continue;
            }
            $when = (string) ($sc['when'] ?? 'on');
            if (!in_array($when, ['on', 'off', 'both'], true)) {
                $when = 'on';
            }
            $scripts[] = ['sid' => $sid, 'name' => trim((string) ($sc['name'] ?? '')), 'when' => $when];
        }

        $rec = [
            'id'           => $id,
            'name'         => $name,
            'icon'         => (string) ($scene['icon'] ?? 'bulb'),
            'scope'        => is_array($scene['scope'] ?? null) ? $scene['scope'] : ['type' => 'house', 'ref' => ''],
            'transitionMs' => max(0, (int) ($scene['transitionMs'] ?? 0)),
            'members'      => $members,
            'vars'         => $vars,
            'scripts'      => $scripts,
            'updated'      => $now > 0 ? $now : (int) ($scene['updated'] ?? 0),
        ];
        $all = $this->all();
        $all[$id] = $rec;
        $this->put($all);
        return $rec;
    }

    public function rename(string $id, string $newName): array
    {
        $all = $this->all();
        if (!isset($all[$id])) {
            throw new \InvalidArgumentException('Szene fehlt: ' . $id);
        }
        $all[$id]['name'] = trim($newName) !== '' ? trim($newName) : $all[$id]['name'];
        $this->put($all);
        return $all[$id];
    }

    public function delete(string $id): void
    {
        $all = $this->all();
        unset($all[$id]);
        $this->put($all);
    }
}

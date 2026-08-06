<?php

declare(strict_types=1);

namespace Hoep\HomeSuite;

/**
 * Provisioner — IPSInstaller-Nachfolger, IDEMPOTENT (§3, §6.3-7).
 *
 * Grundregel: NUR anlegen, wenn nicht vorhanden. Jede Methode kann beliebig oft
 * aufgerufen werden und konvergiert auf denselben Zielzustand — Basis fuer die
 * Create-Ops der Migration und fuer die async Provisionierung (F6).
 *
 * Kapselt die IPS_*-Objektbaum-Operationen. Objekt-Typen (SDK):
 *   0=Kategorie 1=Instanz 2=Variable 3=Skript 4=Ereignis 5=Medien 6=Link.
 */
final class Provisioner
{
    private int $rootId;

    public function __construct(int $rootId)
    {
        $this->rootId = $rootId;
    }

    /** Wurzel-Objekt-ID dieser Provisioner-Instanz. */
    public function root(): int
    {
        return $this->rootId;
    }

    /**
     * Legt (idempotent) den Kategorie-Pfad "A/B/C" unter der Wurzel an und liefert
     * die ID der Blattkategorie. Bestehende Kategorien werden wiederverwendet.
     */
    public function category(string $path): int
    {
        $cur = $this->rootId;
        foreach (explode('/', $path) as $seg) {
            $seg = trim($seg);
            if ($seg === '') {
                continue;
            }
            $child = $this->findChild($cur, $seg, 0);
            if ($child <= 0) {
                $child = \IPS_CreateCategory();
                \IPS_SetParent($child, $cur);
                \IPS_SetName($child, $seg);
            }
            $cur = $child;
        }
        return $cur;
    }

    /**
     * Legt (idempotent) eine Statusvariable mit festem Ident unter $parent an.
     * Existiert der Ident bereits, werden Name/Profil aktualisiert und die ID
     * zurueckgegeben.
     *
     * @param int    $type 0=bool 1=int 2=float 3=string
     */
    public function variable(int $parent, string $ident, string $name, int $type, ?string $profile = null): int
    {
        $id = $this->objectIdByIdent($ident, $parent);
        if ($id <= 0) {
            $id = \IPS_CreateVariable($type);
            \IPS_SetParent($id, $parent);
            \IPS_SetIdent($id, $ident);
        }
        \IPS_SetName($id, $name);
        if ($profile !== null && $profile !== '') {
            \IPS_SetVariableCustomProfile($id, $profile);
        }
        return $id;
    }

    /**
     * Legt (idempotent) ein Float-Variablenprofil an bzw. aktualisiert dessen
     * Darstellung. $unit wird als Suffix gesetzt.
     */
    public function profileFloat(string $name, string $unit, float $min, float $max, float $step, int $dec, string $icon = ''): void
    {
        if (!\IPS_VariableProfileExists($name)) {
            \IPS_CreateVariableProfile($name, 2); // 2 = float
        }
        $suffix = $unit !== '' ? ' ' . $unit : '';
        \IPS_SetVariableProfileText($name, '', $suffix);
        \IPS_SetVariableProfileDigits($name, $dec);
        \IPS_SetVariableProfileValues($name, $min, $max, $step);
        if ($icon !== '') {
            \IPS_SetVariableProfileIcon($name, $icon);
        }
    }

    /**
     * Legt (idempotent) ein Assoziations-Profil an und setzt die Assoziationen.
     *
     * @param array $assoc Liste [ [value, label, icon='', color=-1], ... ]
     * @param int   $type  0=bool 1=int 3=string (Default 1=int)
     */
    public function profileAssoc(string $name, array $assoc, int $type = 1): void
    {
        if (!\IPS_VariableProfileExists($name)) {
            \IPS_CreateVariableProfile($name, $type);
        }
        foreach ($assoc as $a) {
            if (!is_array($a) || !array_key_exists(0, $a)) {
                continue;
            }
            $value = $a[0];
            $label = (string) ($a[1] ?? '');
            $ico   = (string) ($a[2] ?? '');
            $color = (int) ($a[3] ?? -1);
            \IPS_SetVariableProfileAssociation($name, $value, $label, $ico, $color);
        }
    }

    /**
     * Legt (idempotent) einen Link auf $target unter $parent an. Existiert bereits
     * ein Link auf dasselbe Ziel, wird dessen ID (mit aktualisiertem Namen) geliefert.
     */
    public function link(int $parent, int $target, string $name): int
    {
        foreach (\IPS_GetChildrenIDs($parent) as $cid) {
            $o = \IPS_GetObject($cid);
            if ((int) $o['ObjectType'] === 6) { // Link
                $l = \IPS_GetLink($cid);
                if ((int) $l['TargetID'] === $target) {
                    \IPS_SetName($cid, $name);
                    return $cid;
                }
            }
        }
        $id = \IPS_CreateLink();
        \IPS_SetParent($id, $parent);
        \IPS_SetName($id, $name);
        \IPS_SetLinkTargetID($id, $target);
        return $id;
    }

    /**
     * Legt (idempotent) eine Modul-Instanz unter $parent an, setzt Properties und
     * ruft ApplyChanges. Reihenfolge F6: Create -> SetProperty -> (ConnectParent) ->
     * ApplyChanges.
     *
     * Sonderschluessel in $props:
     *   'ConnectParentID' => int   Bei Splitter-Kind (HSAUX): Datenweg-Parent per
     *                              IPS_ConnectInstance verbinden (nicht der Objektbaum-Parent).
     *
     * Idempotenz: eine bestehende Instanz gleicher ModuleID UND gleichen Namens
     * unter $parent wird wiederverwendet.
     */
    public function instance(string $moduleGuid, int $parent, string $name, array $props = []): int
    {
        $connectParent = 0;
        if (isset($props['ConnectParentID'])) {
            $connectParent = (int) $props['ConnectParentID'];
            unset($props['ConnectParentID']);
        }

        $id = 0;
        foreach (\IPS_GetChildrenIDs($parent) as $cid) {
            $o = \IPS_GetObject($cid);
            if ((int) $o['ObjectType'] !== 1) { // Instanz
                continue;
            }
            $inst = \IPS_GetInstance($cid);
            if (($inst['ModuleInfo']['ModuleID'] ?? '') === $moduleGuid && \IPS_GetName($cid) === $name) {
                $id = $cid;
                break;
            }
        }

        if ($id <= 0) {
            $id = \IPS_CreateInstance($moduleGuid);
            \IPS_SetParent($id, $parent);
            \IPS_SetName($id, $name);
        }

        foreach ($props as $k => $v) {
            \IPS_SetProperty($id, (string) $k, $v);
        }

        if ($connectParent > 0) {
            // Nur verbinden, wenn nicht bereits am gewuenschten Parent haengend.
            $inst = \IPS_GetInstance($id);
            if ((int) ($inst['ConnectionID'] ?? 0) !== $connectParent) {
                \IPS_ConnectInstance($id, $connectParent);
            }
        }

        \IPS_ApplyChanges($id);
        return $id;
    }

    /**
     * Loescht rekursiv einen Teilbaum (Kinder zuerst) — typ-korrekt. Kein blindes
     * Loeschen im Migrations-Fluss (dort Soft-Delete/Backup vorher); purge() ist die
     * harte Aufraeum-Operation.
     */
    public function purge(int $id): void
    {
        foreach (\IPS_GetChildrenIDs($id) as $cid) {
            $this->purge($cid);
        }
        $type = (int) \IPS_GetObject($id)['ObjectType'];
        switch ($type) {
            case 0:
                \IPS_DeleteCategory($id);
                break;
            case 1:
                \IPS_DeleteInstance($id);
                break;
            case 2:
                \IPS_DeleteVariable($id);
                break;
            case 3:
                \IPS_DeleteScript($id, true);
                break;
            case 4:
                \IPS_DeleteEvent($id);
                break;
            case 5:
                \IPS_DeleteMedia($id, true);
                break;
            case 6:
                \IPS_DeleteLink($id);
                break;
        }
    }

    // ----------------------------------------------------------------------

    /** Kind mit Namen $name und Objekt-Typ $type unter $parent (oder 0). */
    private function findChild(int $parent, string $name, int $type): int
    {
        foreach (\IPS_GetChildrenIDs($parent) as $cid) {
            $o = \IPS_GetObject($cid);
            if ((int) $o['ObjectType'] === $type && \IPS_GetName($cid) === $name) {
                return $cid;
            }
        }
        return 0;
    }

    /** Objekt-ID zu einem Ident unter $parent (oder 0, wenn nicht vorhanden). */
    private function objectIdByIdent(string $ident, int $parent): int
    {
        try {
            $id = @\IPS_GetObjectIDByIdent($ident, $parent);
            return is_int($id) ? $id : 0;
        } catch (\Throwable $e) {
            return 0;
        }
    }
}

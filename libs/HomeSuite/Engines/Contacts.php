<?php

declare(strict_types=1);

namespace Hoep\HomeSuite\Engines;

/**
 * Contacts — markenübergreifende Erkennung von Tür-/Fensterkontakten (für Aussperr-Schutz).
 *
 * NICHT auf Homematic beschränkt. Vier Signale, damit Z-Wave/Zigbee/Shelly/HmIP/… erkannt werden:
 *   A) Symcon-Standardprofil `~Window` (0=zu,1=offen,2=gekippt) — viele Integrationen.
 *   B) Profilname passt auf Fenster/Tür/Window/Dachfenster/Haustuere/Reed/Contact (Custom-Profile
 *      aller Systeme, z.B. HM „FensterTile"/„Dachfenster").
 *   C) Zigbee2MQTT-Konvention: boolsche Variable mit Ident/Name genau `contact`/`kontakt`.
 *   D) Homematic/HmIP: Ident `STATE` mit Fenster/Tür-bezogenem Variablennamen.
 * Ausgeschlossen: Auto/Geräte-Profile (BMW./Miele…), Licht/Schalter/Rollo/Thermostat/Batterie-Profile,
 * Schlösser (Name Schloss/Lock/Keymatic), Wartungsvariablen (LOWBAT/UNREACH/CONFIG/…).
 *
 * Reine Erkennung (nutzt IPS_* lesend); benannt nach der übergeordneten Instanz.
 */
final class Contacts
{
    private const PROF_RE   = '/fenster|dachfenster|window|t(ü|ue)r|door|haustuere|reed|opening|kontakt|contact/i';
    private const BLOCK_PROF = '/^bmw\.|miele|licht|light|switch|schalter|dimmer|rollo|shutter|jalousie|markise|batter|thermostat|heat|temper|lock|schloss/i';
    private const BLOCK_NAME = '/LOWBAT|UNREACH|STICKY|CONFIG|INSTALL|DUTY|RSSI|ERROR|SABOTAGE|BATTERY|Batterie|Warnung|^Test$|SIGNAL|SERVICE|Low Battery|schloss|keymatic|tastensperre/i';
    private const NAME_RE   = '/fenster|dachfenster|t(ü|ue)r|door|window|reed|öffn/i';

    /**
     * @param bool $includeGates Tore/Garagentore (Profil „Dachfenster" o.ä.) mit aufnehmen.
     * @return array<int,array{id:int,instance:string,var:string,profile:string,signal:string}>
     */
    public static function detect(bool $includeGates = true): array
    {
        if (!function_exists('IPS_GetVariableList')) {
            return [];
        }
        $out = [];
        foreach (@\IPS_GetVariableList() as $vid) {
            $v = @\IPS_GetVariable($vid);
            if (!$v) {
                continue;
            }
            $prof = ($v['VariableCustomProfile'] !== '') ? $v['VariableCustomProfile'] : $v['VariableProfile'];
            $name = (string) @\IPS_GetName($vid);
            $ident = (string) (@\IPS_GetObject($vid)['ObjectIdent'] ?? '');
            $type = (int) $v['VariableType'];

            if (preg_match(self::BLOCK_NAME, $name)) {
                continue;
            }
            if ($prof !== '' && preg_match(self::BLOCK_PROF, $prof)) {
                continue;
            }
            $par = (int) @\IPS_GetParent($vid);
            $inst = $par > 0 ? (string) @\IPS_GetName($par) : $name;
            if (preg_match(self::BLOCK_NAME, $inst)) {
                continue; // Instanz ist ein Schloss/Keymatic o.ä.
            }

            $A = ($prof === '~Window');
            $B = ($prof !== '' && (bool) preg_match(self::PROF_RE, $prof));
            $C = ($type === 0 && (preg_match('/^(contact|kontakt)$/i', $ident) || preg_match('/^(contact|kontakt)$/i', $name)));
            $D = ($ident === 'STATE' && (bool) preg_match(self::NAME_RE, $name));
            if (!($A || $B || $C || $D)) {
                continue;
            }
            if (!$includeGates && preg_match('/tor|garage/i', $inst)) {
                continue;
            }
            $out[] = [
                'id'       => (int) $vid,
                'instance' => $inst,
                'var'      => $name,
                'profile'  => (string) $prof,
                'signal'   => $A ? 'window' : ($C ? 'contact' : ($B ? 'profile' : 'state')),
            ];
        }
        usort($out, static function ($a, $b) {
            return strnatcasecmp($a['instance'], $b['instance']);
        });
        return $out;
    }
}

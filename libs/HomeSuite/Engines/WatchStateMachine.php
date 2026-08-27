<?php

declare(strict_types=1);

namespace Hoep\HomeSuite\Engines;

/**
 * WatchStateMachine — Zustandsautomat des Waechters (HSSC).
 *
 * Kernel-frei und deterministisch: bekommt Zustand, Konfiguration, EIN Ereignis
 * und die aktuelle Zeit — und liefert den Folgezustand plus eine Liste von
 * Aktionen. Das ANWENDEN macht das Modul.
 *
 * DIESE ENGINE ENTSCHEIDET NICHTS VON SELBST. Sie schaltet nie scharf, weil
 * niemand zu Hause ist, sie schlaegt nichts vor und sie fuehrt nichts nach einer
 * Widerspruchsfrist aus. Was wann scharf wird, steht in $cfg['profile'] und wird
 * ausschliesslich durch ein ausdrueckliches Ereignis ausgeloest — einen Knopf
 * oder einen vom Bewohner angelegten Zeiteintrag. Was bei welchem Anlass
 * passiert, steht in $cfg['matrix'] und $cfg['reaktionen'].
 *
 * ALLE ZEITEN WERDEN AUS $nowTs GERECHNET, nie aus Restlaufzeiten. Ein
 * Kernelneustart mitten in der Eingangsverzoegerung darf nicht in den Alarm
 * fallen — und eine Frist, die waehrend des Neustarts abgelaufen waere, wird
 * verworfen statt rueckwirkend ausgeloest.
 */
final class WatchStateMachine
{
    // Zustaende
    public const AUS        = 0;
    public const AUSGANG    = 1;
    public const UEBERWACHT = 2;
    public const EINGANG    = 3;
    public const ALARM      = 4;
    public const QUITTIERT  = 5;

    // Modi
    public const M_AUS      = 0;
    public const M_ANWESEND = 1;
    public const M_NACHT    = 2;
    public const M_ABWESEND = 3;

    // Was ein Melder in einem Modus bewirken darf (Wert der Matrix)
    public const A_ALARM   = 'alarm';         // loest sofort aus
    public const A_BESTAET = 'bestaetigung';  // setzt Verdacht, braucht zweiten Beleg
    public const A_MELDEN  = 'melden';        // nur Chronik und Ring 0
    public const A_IGNOR   = 'ignorieren';

    /** Vorgabe-Matrix. Sie ist NUR die Vorgabe — massgeblich ist $cfg['matrix']. */
    public const MATRIX = [
        self::M_ANWESEND => ['huelle' => self::A_ALARM, 'eingang' => self::A_ALARM,
                             'durchgang' => self::A_MELDEN, 'innen' => self::A_MELDEN, 'vorfeld' => self::A_MELDEN],
        self::M_NACHT    => ['huelle' => self::A_ALARM, 'eingang' => self::A_ALARM,
                             'durchgang' => self::A_BESTAET, 'innen' => self::A_BESTAET, 'vorfeld' => self::A_MELDEN],
        self::M_ABWESEND => ['huelle' => self::A_ALARM, 'eingang' => self::A_ALARM,
                             'durchgang' => self::A_BESTAET, 'innen' => self::A_BESTAET, 'vorfeld' => self::A_MELDEN],
    ];

    /** Frischer Zustand. */
    public static function leer(): array
    {
        return ['zustand' => self::AUS, 'modus' => self::M_AUS, 'zonen' => [],
                'phase' => '', 'seit' => 0, 'frist' => 0, 'anlass' => '',
                'still' => [], 'verdacht' => [], 'profil' => '', 'tuerOffen' => 0,
                'eingangGenutzt' => false, 'boot' => 0];
    }

    /**
     * Ein Ereignis verarbeiten.
     *
     * Ereignisse (alle AUSDRUECKLICH, keines entsteht von selbst):
     *   ['art'=>'scharf','profil'=>id]           Knopf oder konfigurierter Zeiteintrag
     *   ['art'=>'aus']                           Ausschalten — wirkt aus jedem Zustand
     *   ['art'=>'ruhe']                          leise stellen, bleibt scharf
     *   ['art'=>'panik']
     *   ['art'=>'melder','vid'=>,'name'=>,'zone'=>,'role'=>,'offen'=>bool,'gesund'=>bool,'huelleBlind'=>bool]
     *   ['art'=>'kette','name'=>,'weg'=>bool]
     *   ['art'=>'tick']
     *
     * @return array{state:array, aktionen:array}
     */
    public static function verarbeite(array $st, array $cfg, array $ev, int $nowTs): array
    {
        $st = $st + self::leer();
        $a  = [];
        $art = (string) ($ev['art'] ?? '');

        // --- Panik und Ausschalten wirken aus JEDEM Zustand, sofort ----------
        if ($art === 'panik') {
            $st['zustand'] = self::ALARM;
            $st['anlass']  = 'panik';
            $st['seit']    = $nowTs;
            $st['frist']   = 0;
            return self::fertig($st, array_merge($a, self::alarmAktionen($cfg, 'panik', '', $nowTs)));
        }
        if ($art === 'aus') {
            $vor = $st['zustand'];
            $st = self::leer();
            $st['boot'] = (int) ($ev['boot'] ?? 0);
            $a[] = self::chronik('ausgeschaltet', ['vorher' => $vor]);
            $a[] = ['kind' => 'ring0', 'text' => 'Überwachung aus'];
            $a[] = ['kind' => 'stillAlles'];
            return self::fertig($st, $a);
        }

        // --- Scharfschalten: nur auf ausdrueckliches Ereignis ----------------
        if ($art === 'scharf') {
            $pid = (string) ($ev['profil'] ?? '');
            $p   = self::profil($cfg, $pid);
            if ($p === null) {
                return self::fertig($st, [self::chronik('scharf abgelehnt', ['grund' => 'Profil unbekannt', 'profil' => $pid])]);
            }
            $st['modus']  = (int) $p['modus'];
            $st['zonen']  = array_values((array) $p['zonen']);
            $st['profil'] = $pid;
            $st['phase']  = 'p' . $nowTs;          // Scharfphasen-ID: alle Zaehler haengen daran
            $st['still']  = (array) ($ev['still'] ?? []);
            $st['verdacht'] = [];
            $st['eingangGenutzt'] = false;
            $st['seit']   = $nowTs;
            $aus = (int) ($p['ausgangSek'] ?? 0);
            if ($aus > 0) {
                $st['zustand'] = self::AUSGANG;
                $st['frist']   = $nowTs + $aus;
                $st['tuerOffen'] = 0;
                $a[] = self::chronik('Ausgang laeuft', ['profil' => $pid, 'sek' => $aus]);
                $a[] = ['kind' => 'ring0', 'text' => 'Ausgang — Tür öffnen und schließen'];
                $a[] = ['kind' => 'timer', 'sek' => 1];
            } else {
                $st['zustand'] = self::UEBERWACHT;
                $st['frist']   = 0;
                $a[] = self::chronik('scharf', ['profil' => $pid, 'modus' => $st['modus'], 'zonen' => $st['zonen']]);
                $a[] = ['kind' => 'ring0', 'text' => 'Überwacht'];
            }
            return self::fertig($st, $a);
        }

        // --- Quittieren: leise, bleibt scharf --------------------------------
        if ($art === 'ruhe') {
            if ($st['zustand'] === self::ALARM) {
                $st['zustand'] = self::QUITTIERT;
                $st['frist']   = 0;
                $a[] = ['kind' => 'stillAlles'];
                $a[] = self::chronik('quittiert — leise, bleibt scharf', ['anlass' => $st['anlass']]);
                $a[] = ['kind' => 'ring0', 'text' => 'Leise gestellt, Vorfall bleibt offen'];
            }
            return self::fertig($st, $a);
        }

        // --- Kettenausfall: NIE direkt Alarm ---------------------------------
        if ($art === 'kette') {
            $weg = (bool) ($ev['weg'] ?? false);
            $nm  = (string) ($ev['name'] ?? '?');
            if ($weg) {
                $a[] = self::chronik('Kettenausfall', ['kette' => $nm]);
                $a[] = ['kind' => 'ring0', 'text' => 'Meldergruppe ' . $nm . ' schweigt'];
                $a[] = ['kind' => 'push', 'prio' => 1, 'text' => 'Wächter: ' . $nm . ' meldet sich nicht mehr — Überwachung eingeschränkt'];
            } else {
                $a[] = self::chronik('Kette wieder da', ['kette' => $nm]);
            }
            return self::fertig($st, $a);
        }

        // --- Zeitablauf -------------------------------------------------------
        if ($art === 'tick') {
            return self::fertig(...self::tick($st, $cfg, $nowTs));
        }

        // --- Meldertelegramm --------------------------------------------------
        if ($art === 'melder') {
            return self::fertig(...self::melder($st, $cfg, $ev, $nowTs));
        }

        return self::fertig($st, $a);
    }

    // =====================================================================
    private static function tick(array $st, array $cfg, int $nowTs): array
    {
        $a = [];
        if ($st['frist'] <= 0 || $nowTs < $st['frist']) {
            return [$st, $a];
        }

        // Ausgang abgelaufen OHNE Tuerfolge: NICHT scharf werden.
        if ($st['zustand'] === self::AUSGANG) {
            $a[] = self::chronik('nicht scharf geworden', ['grund' => 'niemand hat das Haus verlassen']);
            $a[] = ['kind' => 'ring0', 'text' => 'Nicht scharf geworden — niemand hat das Haus verlassen'];
            $st = self::leer();
            return [$st, $a];
        }

        // Eingangsverzoegerung abgelaufen: jetzt Alarm.
        if ($st['zustand'] === self::EINGANG) {
            $st['zustand'] = self::ALARM;
            $st['anlass']  = 'eingang-abgelaufen';
            $st['seit']    = $nowTs;
            $st['frist']   = 0;
            return [$st, self::alarmAktionen($cfg, 'eingang-abgelaufen', (string) ($st['anlassName'] ?? ''), $nowTs)];
        }

        $st['frist'] = 0;
        return [$st, $a];
    }

    // =====================================================================
    private static function melder(array $st, array $cfg, array $ev, int $nowTs): array
    {
        $a     = [];
        $vid   = (int) ($ev['vid'] ?? 0);
        $name  = (string) ($ev['name'] ?? ('#' . $vid));
        $zone  = (string) ($ev['zone'] ?? '');
        $role  = (string) ($ev['role'] ?? '');
        $offen = (bool) ($ev['offen'] ?? false);
        $gesund = (bool) ($ev['gesund'] ?? true);
        $huelleBlind = (bool) ($ev['huelleBlind'] ?? false);

        // Im Ausgang zaehlt nur die Tuerfolge: auf, dann zu.
        if ($st['zustand'] === self::AUSGANG) {
            if ($role === 'eingang') {
                if ($offen) {
                    $st['tuerOffen'] = $nowTs;
                    $a[] = self::chronik('Ausgangstuer geoeffnet', ['melder' => $name]);
                } elseif ((int) $st['tuerOffen'] > 0) {
                    $st['zustand'] = self::UEBERWACHT;
                    $st['frist']   = 0;
                    $st['seit']    = $nowTs;
                    $a[] = self::chronik('scharf — Tuerfolge erkannt', ['melder' => $name, 'profil' => $st['profil']]);
                    $a[] = ['kind' => 'ring0', 'text' => 'Überwacht'];
                }
            }
            return [$st, $a];
        }

        // Ausserhalb einer scharfen Phase wird nur protokolliert.
        if (!in_array($st['zustand'], [self::UEBERWACHT, self::EINGANG, self::QUITTIERT], true)) {
            return [$st, $a];
        }
        // Zone nicht scharf, Melder stillgelegt, oder Ruhe-Richtung: nichts.
        if (!in_array($zone, (array) $st['zonen'], true) || isset($st['still'][$vid]) || !$offen) {
            if (!$offen) {
                $a[] = self::chronik('geschlossen', ['melder' => $name]);
            }
            return [$st, $a];
        }

        $wirkung = self::wirkung($cfg, (int) $st['modus'], $role);

        // Ein UNGESUNDER Melder loest nicht aus — ausser er ist ein Eingang.
        if (!$gesund && $role !== 'eingang') {
            $a[] = self::chronik('Befund statt Alarm', ['melder' => $name, 'grund' => 'Melder nicht belastbar']);
            $a[] = ['kind' => 'ring0', 'text' => $name . ' meldet, ist aber nicht belastbar'];
            return [$st, $a];
        }

        // Eingangsmelder: Verzoegerung, EINMAL je Phase. Sie startet mit dem
        // Schliessen — hier ist $offen true, also merken wir uns die Tuer und
        // starten beim Schliessen. Ein zweites Oeffnen verkuerzt statt zu verlaengern.
        if ($role === 'eingang' && $wirkung !== self::A_IGNOR) {
            if ($st['zustand'] === self::EINGANG) {
                $rest = max(0, (int) $st['frist'] - $nowTs);
                $st['frist'] = $nowTs + (int) min($rest, 10);
                $a[] = self::chronik('zweiter Eingangsbeleg — Frist verkuerzt', ['melder' => $name]);
                return [$st, $a];
            }
            if (!$st['eingangGenutzt']) {
                $sek = $gesund ? (int) ($cfg['eingangSek'] ?? 45) : (int) ($cfg['eingangSekKrank'] ?? 20);
                $st['zustand'] = self::EINGANG;
                $st['frist']   = $nowTs + $sek;
                $st['eingangGenutzt'] = true;
                $st['anlassName'] = $name;
                $a[] = self::chronik('Eingang laeuft', ['melder' => $name, 'sek' => $sek, 'gesund' => $gesund]);
                $a[] = ['kind' => 'ring0', 'text' => 'Eingang — ' . $sek . ' Sekunden'];
                $a[] = ['kind' => 'push', 'prio' => 1, 'text' => 'Wächter: ' . $name . ' geöffnet, ' . $sek . ' s bis zum Alarm'];
                $a[] = ['kind' => 'timer', 'sek' => 1];
                return [$st, $a];
            }
            // Frist schon verbraucht: dieser Eingang loest jetzt voll aus.
            return self::ausloesen($st, $cfg, $name, 'huellenbruch', $nowTs);
        }

        // Waehrend einer laufenden Eingangsverzoegerung ist der Laufweg still,
        // aber ein Huellenmelder eines ANDEREN Bereichs loest weiter aus.
        if ($st['zustand'] === self::EINGANG) {
            if (in_array($role, ['huelle'], true)) {
                return self::ausloesen($st, $cfg, $name, 'huellenbruch', $nowTs);
            }
            $a[] = self::chronik('Laufweg — nur Chronik', ['melder' => $name]);
            return [$st, $a];
        }

        // Blinde Huelle hebt die Innenbewachung an: dann loest innen SOFORT aus.
        if ($huelleBlind && in_array($role, ['innen', 'durchgang'], true) && $wirkung === self::A_BESTAET) {
            $wirkung = self::A_ALARM;
            $a[] = self::chronik('Huelle unvollstaendig — Innenbewachung erhoeht', ['melder' => $name]);
        }

        // NACHTZONE — und zwar ZULETZT, sie schlaegt auch die erhoehte Innenbewachung.
        //
        // In den Schlafraeumen und ihren gelernten Nachbarn darf Innenbewegung nachts
        // NIE zum Alarm werden. Das ist die wirksamste Fehlalarmbremse der ganzen
        // Anlage: ein Alarm um 02:40, ausgeloest vom Gang aufs Klo, schaltet sie
        // dauerhaft ab. Der Preis ist benannt und bewusst bezahlt — wer sich vor dem
        // Scharfschalten versteckt hat, erzeugt nachts nur Chronik.
        if ((int) $st['modus'] === self::M_NACHT
            && in_array($role, ['innen', 'durchgang'], true)
            && in_array($vid, array_map('intval', (array) ($cfg['nachtzone'] ?? [])), true)) {
            if ($wirkung !== self::A_MELDEN) {
                $a[] = self::chronik('Nachtzone — nur Chronik', ['melder' => $name]);
            }
            $wirkung = self::A_MELDEN;
        }

        if ($wirkung === self::A_ALARM) {
            [$st, $b] = self::ausloesen($st, $cfg, $name, in_array($role, ['huelle', 'eingang'], true) ? 'huellenbruch' : 'innen', $nowTs);
            return [$st, array_merge($a, $b)];
        }

        if ($wirkung === self::A_BESTAET) {
            $gruppe = (string) ($ev['gruppe'] ?? $zone);
            $fenster = (int) ($cfg['verdachtSek'] ?? 120);
            // Ein zweiter Beleg zaehlt nur aus einer ANDEREN Gruppe.
            foreach ((array) $st['verdacht'] as $g => $ts) {
                if ($g !== $gruppe && ($nowTs - (int) $ts) <= $fenster) {
                    [$st, $b] = self::ausloesen($st, $cfg, $name, 'innen-bestaetigt', $nowTs);
                    return [$st, array_merge($a, $b)];
                }
            }
            $st['verdacht'][$gruppe] = $nowTs;
            $a[] = self::chronik('Verdacht', ['melder' => $name, 'gruppe' => $gruppe]);
            $a[] = ['kind' => 'ring0', 'text' => 'Bewegung ' . $name];
            return [$st, $a];
        }

        $a[] = self::chronik('gemeldet', ['melder' => $name, 'wirkung' => $wirkung]);
        return [$st, $a];
    }

    // =====================================================================
    private static function ausloesen(array $st, array $cfg, string $melder, string $anlass, int $nowTs): array
    {
        if ($st['zustand'] === self::ALARM) {
            return [$st, [self::chronik('weiterer Ausloeser waehrend Alarm', ['melder' => $melder])]];
        }
        $st['zustand'] = self::ALARM;
        $st['anlass']  = $anlass;
        $st['anlassName'] = $melder;
        $st['seit']    = $nowTs;
        $st['frist']   = 0;
        return [$st, self::alarmAktionen($cfg, $anlass, $melder, $nowTs)];
    }

    /**
     * WAS PASSIERT — vollstaendig aus der Konfiguration.
     *
     * $cfg['reaktionen'][<anlass>] = ['stilleSek'=>int, 'ringe'=>['ring0','licht','sirene','push',...]]
     * Fehlt ein Eintrag, passiert genau das Minimum: Chronik und Ring 0. Es wird
     * NICHTS angenommen, was nicht konfiguriert ist.
     */
    public static function alarmAktionen(array $cfg, string $anlass, string $melder, int $nowTs): array
    {
        $r = $cfg['reaktionen'][$anlass] ?? $cfg['reaktionen']['*'] ?? ['ringe' => ['ring0']];
        $a = [self::chronik('ALARM', ['anlass' => $anlass, 'melder' => $melder])];

        $stille = (int) ($r['stilleSek'] ?? 0);
        if ($stille > 0) {
            $a[] = ['kind' => 'stilleVorstufe', 'sek' => $stille, 'melder' => $melder];
        }
        foreach ((array) ($r['ringe'] ?? []) as $ring) {
            $a[] = ['kind' => (string) $ring, 'anlass' => $anlass, 'melder' => $melder,
                    'text' => 'Wächter: ' . ($melder !== '' ? $melder : $anlass)];
        }
        return $a;
    }

    /** Welche Wirkung hat diese Rolle in diesem Modus? Konfiguration schlaegt Vorgabe. */
    public static function wirkung(array $cfg, int $modus, string $role): string
    {
        $m = $cfg['matrix'][$modus][$role] ?? null;
        if (is_string($m) && $m !== '') {
            return $m;
        }
        return self::MATRIX[$modus][$role] ?? self::A_IGNOR;
    }

    /** Ein Scharfschalt-Profil aus der Konfiguration holen. */
    public static function profil(array $cfg, string $id): ?array
    {
        foreach ((array) ($cfg['profile'] ?? []) as $p) {
            if ((string) ($p['id'] ?? '') === $id) {
                return $p;
            }
        }
        return null;
    }

    /**
     * Welche Profile sind JETZT faellig? Liefert nur Kandidaten — das Modul
     * entscheidet, ob es sie ausfuehrt. Ein Profil ohne Zeiteintrag ist NIE
     * faellig; es gibt keinen Auslöser ausser dem Knopf.
     */
    public static function faellig(array $cfg, int $prevMin, int $nowMin, int $weekday): array
    {
        $out = [];
        foreach ((array) ($cfg['profile'] ?? []) as $p) {
            if ((string) ($p['ausloeser'] ?? 'knopf') !== 'zeit') {
                continue;
            }
            $tage = (array) ($p['tage'] ?? []);
            if ($tage !== [] && !in_array($weekday, array_map('intval', $tage), true)) {
                continue;
            }
            $ziel = self::hhmm((string) ($p['zeit'] ?? ''));
            if ($ziel >= 0 && LightAutomation::crossed($prevMin, $nowMin, $ziel)) {
                $out[] = (string) $p['id'];
            }
        }
        return $out;
    }

    private static function hhmm(string $s): int
    {
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', trim($s), $m)) {
            return -1;
        }
        return ((int) $m[1]) * 60 + (int) $m[2];
    }

    private static function chronik(string $was, array $daten = []): array
    {
        return ['kind' => 'chronik', 'was' => $was, 'daten' => $daten];
    }

    private static function fertig(array $st, array $aktionen): array
    {
        return ['state' => $st, 'aktionen' => $aktionen];
    }
}

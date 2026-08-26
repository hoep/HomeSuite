<?php

declare(strict_types=1);

namespace Hoep\HomeSuite\Engines;

/**
 * SecurityAssessment — REINE Beurteilung der Melderlage fuer den Waechter (HSSC).
 *
 * Kernel-frei und deterministisch: bekommt das Melderregister, eine Momentaufnahme
 * der Werte und die aktuelle Zeit — und liefert je Melder einen Gesundheitsbefund,
 * je Bereich eine Abdeckung und die Antwort auf die eine Frage, die zaehlt:
 * "loest dieser Melder gerade aus?". Das ANWENDEN macht das Modul.
 *
 * WARUM DIE GESUNDHEIT VOR DER BEWERTUNG KOMMT: ein Melder, der seit Wochen
 * denselben Wert meldet, sieht in der Statusvariablen aus wie ein wachsamer.
 * Im Haus sind das heute keine Einzelfaelle — fuenf Dachfenster stehen seit
 * dem 02.07.2026 still, sechs Kontakte melden UNREACH und funken trotzdem,
 * zwei Batterieflags stehen auf OK bei 17 % und 40 %. Eine Anlage, die solchen
 * Meldern vertraut, behauptet Schutz, den sie nicht hat. Deshalb gilt hier:
 * ein ungueltiger Melder loest NICHT aus, er erzeugt einen Befund.
 *
 * KEINE AUTOMATISMEN: diese Engine entscheidet NIE, ob scharf geschaltet wird.
 * Sie beurteilt nur, was ein Melder wert ist. Wann und wie scharf geschaltet
 * wird, steht ausschliesslich in der Konfiguration des Bewohners.
 *
 * Melderregister (ein Eintrag):
 *   ['id'=>vid, 'name'=>string, 'zone'=>'Z1'..'Z4', 'role'=>'huelle|eingang|durchgang|innen|vorfeld',
 *    'ruhe'=>string   (erwarteter Ruhewert, formatiert, z. B. 'Geschlossen'),
 *    'takt'=>int      (Erwartungstakt in Sekunden, 0 = kein Takt bekannt),
 *    'wechsel'=>int   (erwarteter Hoechstabstand zweier WERTaenderungen, 0 = keine Erwartung),
 *    'unreachVid'=>int, 'lowbatVid'=>int, 'battPctVid'=>int, 'zweitVid'=>int]
 *
 * Momentaufnahme (je vid):
 *   ['wert'=>string (formatiert), 'roh'=>mixed, 'updated'=>int, 'changed'=>int]
 */
final class SecurityAssessment
{
    /** Befunde, von harmlos nach schwer. Reihenfolge ist Rangfolge. */
    public const OK        = 'ueberwacht';
    public const OFFEN     = 'stillgelegt-offen';    // meldet, steht aber nicht in Ruhe
    public const BLIND     = 'stillgelegt-blind';    // meldet nicht mehr verlaesslich
    public const UNBEKANNT = 'unbekannt';            // Widerspruch oder keine Aussage
    public const ZEIT      = 'zeit-unsicher';        // Uhr nicht plausibel — nichts ist bewertbar

    /** Der Ruhewert wird IMMER formatiert verglichen: das eine Profil ist 0/1/2, das andere true/false. */
    public const OFFEN_WERTE = ['geoeffnet', 'geöffnet', 'offen', 'gekippt', 'auf', 'true', 'an', 'ein'];

    /**
     * Ist die Uhr brauchbar? Alles Zeitbasierte haengt daran.
     *
     * Nach einem Stromausfall laeuft der Kernel los, bevor die Zeit steht. Wer
     * dann Telegrammalter rechnet, bekommt fuer JEDEN Melder "seit Stunden
     * stumm" und loest im leeren Urlaubshaus einen Sabotagealarm aus.
     */
    public static function zeitPlausibel(int $nowTs, int $letzterEintrag, int $toleranzSek = 3600): bool
    {
        if ($nowTs <= 0) {
            return false;
        }
        if ($letzterEintrag > 0 && $nowTs < $letzterEintrag - 60) {
            return false;                       // Uhr laeuft rueckwaerts
        }
        if ($letzterEintrag > 0 && ($nowTs - $letzterEintrag) > $toleranzSek * 24 * 400) {
            return false;                       // absurd weit vorne
        }
        return true;
    }

    /** Steht dieser formatierte Wert fuer "offen"? */
    public static function istOffen(string $wert): bool
    {
        $w = trim(mb_strtolower($wert));
        foreach (self::OFFEN_WERTE as $o) {
            if ($w === $o) {
                return true;
            }
        }
        return false;
    }

    /**
     * Gesundheit EINES Melders.
     *
     * @param array $m     Registereintrag
     * @param array $snap  Momentaufnahme, Schluessel = vid
     * @return array{befund:string, grund:string, belastbar:bool}
     */
    public static function melder(array $m, array $snap, int $nowTs, bool $zeitOk = true, int $karenzBis = 0): array
    {
        $vid = (int) ($m['id'] ?? 0);
        $s   = $snap[$vid] ?? null;
        $nm  = (string) ($m['name'] ?? ('#' . $vid));

        if ($vid <= 0 || $s === null) {
            return self::befund(self::UNBEKANNT, 'kein Wert vorhanden');
        }
        if (!$zeitOk) {
            return self::befund(self::ZEIT, 'Uhrzeit nicht plausibel — nichts ist bewertbar');
        }

        // Anlaufkarenz nach einem Kernelstart: vorher ist "seit Stunden stumm"
        // eine Aussage ueber den Neustart, nicht ueber den Melder.
        $inKarenz = $karenzBis > 0 && $nowTs < $karenzBis;

        // (f) Zweitmeinung zuerst: ein Widerspruch schlaegt jeden anderen Befund.
        $zw = (int) ($m['zweitVid'] ?? 0);
        if ($zw > 0 && isset($snap[$zw])) {
            $a = self::istOffen((string) $s['wert']);
            $b = self::istOffen((string) $snap[$zw]['wert']);
            if ($a !== $b) {
                return self::befund(self::UNBEKANNT, 'Zweitmeinung widerspricht — unbekannt ist nie harmloser als offen');
            }
        }

        // (a) Telegrammalter gegen den Erwartungstakt.
        $takt = (int) ($m['takt'] ?? 0);
        if (!$inKarenz && $takt > 0) {
            $alter = $nowTs - (int) $s['updated'];
            if ($alter > $takt) {
                return self::befund(self::BLIND, sprintf('meldet sich seit %d Minuten nicht mehr', intdiv($alter, 60)));
            }
        }

        // (b) WERTalter: erkennt eingefrorene Werte bei lebendem Funk.
        $wechsel = (int) ($m['wechsel'] ?? 0);
        if (!$inKarenz && $wechsel > 0) {
            $alter = $nowTs - (int) $s['changed'];
            if ($alter > $wechsel) {
                return self::befund(self::BLIND, sprintf('Wert eingefroren seit %d Tagen', intdiv($alter, 86400)));
            }
        }

        // (d) Erreichbarkeit — NUR zusammen mit dem Telegrammalter. Sechs Kontakte
        //     im Haus melden UNREACH und funken trotzdem; allein ist das Flag wertlos.
        $ur = (int) ($m['unreachVid'] ?? 0);
        if ($ur > 0 && isset($snap[$ur]) && self::wahr($snap[$ur]['roh'] ?? null)) {
            $alter = $nowTs - (int) $s['updated'];
            if ($takt > 0 && $alter > intdiv($takt, 2)) {
                return self::befund(self::BLIND, 'nicht erreichbar und lange stumm');
            }
        }

        // (e) Batterie ueber den Prozentwert, nicht ueber das Flag.
        $bp = (int) ($m['battPctVid'] ?? 0);
        if ($bp > 0 && isset($snap[$bp])) {
            $pct = (float) ($snap[$bp]['roh'] ?? 100);
            if ($pct <= 5.0) {
                return self::befund(self::BLIND, sprintf('Batterie %d %%', (int) $pct));
            }
        }

        // (c) Ruhewert: meldet zwar, steht aber nicht in Ruhe.
        //
        // OHNE festen Erwartungswert wird "offen" ERKANNT statt verglichen. Das ist
        // die belastbarere Form: dieselbe Tuer formatiert je nach Profil "Aus",
        // "Geschlossen" oder "Zu", und ein aus dem Profil abgeleiteter Erwartungswert
        // liegt dann daneben — gemessen am 27.08.2026 an zehn von zwoelf Kontakten,
        // die alle geschlossen waren und trotzdem als offen galten.
        $ruhe = (string) ($m['ruhe'] ?? '');
        $rolle = (string) ($m['role'] ?? '');
        if ($ruhe !== '') {
            if (trim(mb_strtolower((string) $s['wert'])) !== trim(mb_strtolower($ruhe))) {
                return self::befund(self::OFFEN, sprintf('steht auf "%s" statt "%s"', $s['wert'], $ruhe));
            }
        } elseif (in_array($rolle, ['huelle', 'eingang', 'durchgang'], true)
                  && self::istOffen((string) $s['wert'])) {
            return self::befund(self::OFFEN, sprintf('steht offen ("%s")', $s['wert']));
        }

        return self::befund(self::OK, '');
    }

    /** Alle Melder auf einmal. Schluessel des Ergebnisses = vid. */
    public static function alle(array $register, array $snap, int $nowTs, bool $zeitOk = true, int $karenzBis = 0): array
    {
        $out = [];
        foreach ($register as $m) {
            $vid = (int) ($m['id'] ?? 0);
            if ($vid > 0) {
                $out[$vid] = self::melder($m, $snap, $nowTs, $zeitOk, $karenzBis);
            }
        }
        return $out;
    }

    /**
     * Abdeckung je Bereich — die Zahl, die das Panel zeigt.
     *
     * @return array<string,array{gesamt:int, belastbar:int, huelleVollstaendig:bool, gruende:array}>
     */
    public static function abdeckung(array $register, array $befunde): array
    {
        $z = [];
        foreach ($register as $m) {
            $zone = (string) ($m['zone'] ?? '?');
            $vid  = (int) ($m['id'] ?? 0);
            $b    = $befunde[$vid] ?? self::befund(self::UNBEKANNT, 'nicht beurteilt');
            if (!isset($z[$zone])) {
                $z[$zone] = ['gesamt' => 0, 'belastbar' => 0, 'huelleVollstaendig' => true, 'gruende' => []];
            }
            $z[$zone]['gesamt']++;
            if ($b['belastbar']) {
                $z[$zone]['belastbar']++;
            } else {
                $z[$zone]['gruende'][] = ['name' => (string) ($m['name'] ?? ('#' . $vid)),
                                          'befund' => $b['befund'], 'grund' => $b['grund']];
                // Eine unvollstaendige HUELLE ist etwas anderes als ein blinder
                // Innenmelder: sie hebt spaeter die Innenbewachung an.
                if (in_array((string) ($m['role'] ?? ''), ['huelle', 'eingang'], true)) {
                    $z[$zone]['huelleVollstaendig'] = false;
                }
            }
        }
        return $z;
    }

    /** Eine Zahl fuer die ganze Anlage — Selbstauskunft, NIEMALS ein Alarmkriterium. */
    public static function verlaesslichkeit(array $abdeckung, array $zonenAktiv): array
    {
        $g = 0; $b = 0;
        foreach ($abdeckung as $zone => $a) {
            if (!in_array($zone, $zonenAktiv, true)) {
                continue;
            }
            $g += (int) $a['gesamt'];
            $b += (int) $a['belastbar'];
        }
        return ['belastbar' => $b, 'gesamt' => $g,
                'anteil' => $g > 0 ? round($b / $g * 100) : 0.0];
    }

    private static function befund(string $art, string $grund): array
    {
        return ['befund' => $art, 'grund' => $grund, 'belastbar' => $art === self::OK];
    }

    private static function wahr($v): bool
    {
        if (is_bool($v)) {
            return $v;
        }
        if (is_numeric($v)) {
            return (float) $v != 0.0;
        }
        return in_array(trim(mb_strtolower((string) $v)), ['1', 'true', 'an', 'ein', 'ja'], true);
    }
}

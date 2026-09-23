<?php

declare(strict_types=1);

namespace Hoep\HomeSuite\Engines;

/**
 * Anwesenheit aus Netzwerk-Hosts — reine Entscheidungslogik, ohne Symcon-Aufrufe.
 *
 * Eingang ist eine Liste von Hosts, wie sie ein Router meldet (Name, MAC, online), dazu
 * die Personen mit ihren Merkmalen. Ausgang ist, wer da ist, ob Gaeste da sind und
 * welche Belegung daraus folgt.
 *
 * Drei Dinge, die diese Logik bewusst so macht:
 *
 *  1. Personen werden an MEHREREN Merkmalen erkannt (MAC-Liste UND Geraetename).
 *     Telefone wechseln ihre WLAN-Adresse (private Adressen, Rotation); wer nur an einer
 *     MAC haengt, gilt nach einem Wechsel still und dauerhaft als abwesend.
 *  2. Eine HALTEZEIT ueberbrueckt Funkpausen. Ein Telefon im Ruhezustand verlaesst das WLAN
 *     fuer Minuten; ohne Haltezeit flackert die Anwesenheit im Takt des Akkusparens.
 *  3. Ein veralteter Router ist KEINE Abwesenheit. Meldet der Router laenger nichts, ist der
 *     Zustand unbekannt — sonst schaltet ein Netzausfall das Haus auf "leer".
 */
final class PresenceEngine
{
    public const OCC_EMPTY   = 0;  // niemand da
    public const OCC_RESIDENT = 1; // nur Bewohner
    public const OCC_GUESTS  = 2;  // nur Gaeste
    public const OCC_BOTH    = 3;  // Bewohner und Gaeste
    public const OCC_UNKNOWN = 4;  // Router meldet nichts Aktuelles
    public const OCC_FAMILY  = 5;  // bekannte Personen, die hier NICHT wohnen (Besuch, Ferienhaus)

    /** Geraetenamen, die typischerweise ein Telefon oder eine Uhr bezeichnen (Gaeste-Erkennung). */
    public const DEFAULT_GUEST_PATTERN = '/iphone|galaxy|pixel|oneplus|huawei|xiaomi|redmi|android|phone|watch|-von-|mate-|nord/i';

    /**
     * Geraete, die NIE als Gast zaehlen, auch wenn das Gaeste-Muster passt: Tablets und
     * Rechner bleiben im Haus und reisen nicht mit ("iPad-von-..." traf sonst "-von-").
     */
    /**
     * Haustechnik, die im Gaestenetz haengen darf, ohne ein Gast zu sein (Lautsprecher,
     * Bridges, Klimageraete, Steckdosen, Kameras).
     */
    public const DEFAULT_INFRA_PATTERN = '/sonos|denon|heos|hue|apple-?tv|chromecast|fire-?tv|echo|alexa|repeater|tado|shelly|plug|camera|kamera|gateway|bridge|printer|drucker|linktap|ikea|tasmota|esp/i';

    public const DEFAULT_NO_GUEST_PATTERN = '/ipad|tablet|macbook|laptop|notebook|xps|desktop|spectre|surface/i';

    /**
     * @param array $hosts   Liste [{name, mac, online:bool, updated:int}] — name darf den
     *                       angehaengten " (IP)"-Teil tragen, er wird abgeschnitten.
     * @param array $persons Liste [{name, macs:[..], names:[..], ext:?bool}] — ext ist ein
     *                       bereits fertig ermittelter Anwesenheitswert aus anderer Quelle
     *                       (null = keine andere Quelle).
     * @param array $state   Vorzustand: {seen:{person:ts}, guestSeen:ts}
     * @param array $opt     {now, holdSec, staleSec, guestPattern, ignore:[..], guestWlan:int}
     * @return array {persons:{name:bool}, present:[names], guests:int, guestNames:[..],
     *                occupancy:int, fresh:bool, state:{...}}
     */
    public static function evaluate(array $hosts, array $persons, array $state, array $opt): array
    {
        $now      = (int) ($opt['now'] ?? time());
        $hold     = max(0, (int) ($opt['holdSec'] ?? 1200));
        $stale    = max(60, (int) ($opt['staleSec'] ?? 1800));
        $pattern  = (string) ($opt['guestPattern'] ?? self::DEFAULT_GUEST_PATTERN);
        $noGuest  = (string) ($opt['noGuestPattern'] ?? self::DEFAULT_NO_GUEST_PATTERN);
        $ignore   = array_map([self::class, 'norm'], (array) ($opt['ignore'] ?? []));
        $guestWlan = max(0, (int) ($opt['guestWlan'] ?? 0));
        // Hosts des Gaestenetzes (optional). Sind sie gegeben, zaehlt JEDES Online-Geraet dort
        // als Gast - ausser Haustechnik und Geraeten einer Person.
        $guestHosts = (array) ($opt['guestHosts'] ?? []);
        $infra    = (string) ($opt['infraPattern'] ?? self::DEFAULT_INFRA_PATTERN);

        $seen = is_array($state['seen'] ?? null) ? $state['seen'] : [];
        $guestSeen = (int) ($state['guestSeen'] ?? 0);

        // Frische: der juengste Host-Zeitstempel sagt, ob der Router ueberhaupt noch meldet.
        $newest = 0;
        foreach ($hosts as $h) {
            $newest = max($newest, (int) ($h['updated'] ?? 0));
        }
        $fresh = $hosts === [] ? false : ($now - $newest) <= $stale;

        // Hosts normalisieren (Hauptnetz, danach Gaestenetz mit Markierung)
        $list = [];
        foreach (array_merge(
            array_map(function ($h) { $h['guestNet'] = false; return $h; }, $hosts),
            array_map(function ($h) { $h['guestNet'] = true; return $h; }, $guestHosts)
        ) as $h) {
            $list[] = [
                'guestNet' => (bool) $h['guestNet'],
                'name'   => self::hostName((string) ($h['name'] ?? '')),
                'mac'    => self::mac((string) ($h['mac'] ?? '')),
                'online' => (bool) ($h['online'] ?? false)
                            && ($now - (int) ($h['updated'] ?? 0)) <= $stale,
            ];
        }

        // Personen
        $out = [];
        $claimed = [];   // Hosts, die einer Person gehoeren (zaehlen nie als Gast)
        foreach ($persons as $p) {
            $pn = trim((string) ($p['name'] ?? ''));
            if ($pn === '') {
                continue;
            }
            $macs  = array_filter(array_map([self::class, 'mac'], (array) ($p['macs'] ?? [])));
            $names = array_filter(array_map([self::class, 'norm'], (array) ($p['names'] ?? [])));
            $onlineNow = false;
            foreach ($list as $i => $h) {
                $hit = ($h['mac'] !== '' && in_array($h['mac'], $macs, true))
                    || self::nameMatches($h['name'], $names);
                if ($hit) {
                    $claimed[$i] = true;
                    if ($h['online']) {
                        $onlineNow = true;
                    }
                }
            }
            $ext = $p['ext'] ?? null;
            if ($ext === true) {
                $onlineNow = true;
            }
            if ($onlineNow) {
                $seen[$pn] = $now;
            }
            $last = (int) ($seen[$pn] ?? 0);
            $out[$pn] = $last > 0 && ($now - $last) <= $hold;
            // Ohne frischen Router und ohne zweite Quelle wissen wir nichts Neues: dann
            // bleibt der letzte Stand stehen, statt die Person abzumelden.
            if (!$fresh && $ext === null && $last > 0) {
                $out[$pn] = (bool) ($state['last'][$pn] ?? $out[$pn]);
            }
        }

        // Gaeste: im Gaestenetz jedes fremde Geraet ausser Haustechnik; im Hauptnetz nur,
        // wenn ein Muster gesetzt ist (fremde Telefone/Uhren). Leeres Muster = Hauptnetz aus.
        $guestNames = [];
        foreach ($list as $i => $h) {
            if (!$h['online'] || isset($claimed[$i])) {
                continue;
            }
            $label = $h['name'] !== '' ? $h['name'] : $h['mac'];
            if (in_array(self::norm($h['name']), $ignore, true)) {
                continue;
            }
            if ($h['guestNet']) {
                if ($infra === '' || @preg_match($infra, $h['name']) !== 1) {
                    $guestNames[] = $label;
                }
                continue;
            }
            if ($pattern !== '' && $h['name'] !== ''
                && @preg_match($pattern, $h['name']) === 1 && @preg_match($noGuest, $h['name']) !== 1) {
                $guestNames[] = $label;
            }
        }
        $guestNames = array_values(array_unique($guestNames));
        $guestsNow = count($guestNames) + $guestWlan;
        if ($guestsNow > 0) {
            $guestSeen = $now;
        }
        $guestsPresent = $guestSeen > 0 && ($now - $guestSeen) <= $hold;

        $present = array_keys(array_filter($out));
        // Rollen: sind Bewohner genannt, zaehlen nur sie als "Bewohner"; andere bekannte
        // Personen sind "Familie" (Besuch). Ohne Liste (Ferienhaus) ist jede bekannte
        // Person Familie.
        $resNames = array_map([self::class, 'norm'], (array) ($opt['residents'] ?? []));
        $hasRes = false;
        foreach ($present as $pn) {
            if (in_array(self::norm($pn), $resNames, true)) { $hasRes = true; }
        }
        if (!$fresh && $present === [] && !$guestsPresent) {
            $occ = self::OCC_UNKNOWN;
        } elseif ($present && $guestsPresent) {
            $occ = self::OCC_BOTH;
        } elseif ($present) {
            $occ = $hasRes ? self::OCC_RESIDENT : self::OCC_FAMILY;
        } elseif ($guestsPresent) {
            $occ = self::OCC_GUESTS;
        } else {
            $occ = self::OCC_EMPTY;
        }

        return [
            'persons'    => $out,
            'present'    => $present,
            'guests'     => $guestsNow,
            'guestNames' => $guestNames,
            'guestsPresent' => $guestsPresent,
            'occupancy'  => $occ,
            'fresh'      => $fresh,
            'state'      => ['seen' => $seen, 'guestSeen' => $guestSeen, 'last' => $out],
        ];
    }

    /** "Telefon-A (192.168.1.20)" -> "Telefon-A" */
    public static function hostName(string $n): string
    {
        return trim((string) preg_replace('/\s*\([^)]*\)\s*$/', '', $n));
    }

    /** MAC in Grossbuchstaben ohne Trenner; alles andere als 12 Hexzeichen -> ''. */
    public static function mac(string $m): string
    {
        $m = strtoupper((string) preg_replace('/[^0-9A-Fa-f]/', '', $m));
        return strlen($m) === 12 ? $m : '';
    }

    public static function norm(string $s): string
    {
        return mb_strtolower(trim($s));
    }

    /** Name exakt (ohne Gross/Klein) oder als Muster mit * . */
    private static function nameMatches(string $host, array $names): bool
    {
        $h = self::norm($host);
        if ($h === '') {
            return false;
        }
        foreach ($names as $n) {
            if ($n === '') {
                continue;
            }
            if (strpos($n, '*') !== false) {
                $rx = '/^' . str_replace('\*', '.*', preg_quote($n, '/')) . '$/u';
                if (preg_match($rx, $h) === 1) {
                    return true;
                }
            } elseif ($h === $n) {
                return true;
            }
        }
        return false;
    }
}

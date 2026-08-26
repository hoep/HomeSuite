<?php

declare(strict_types=1);

/**
 * HomeSuite Waechter (HSSC) — Einbruchmeldung fuer das ganze Haus.
 *
 * EINE Instanz fuer das ganze Haus. Geschosse, Bereiche und Melderrollen sind
 * Eigenschaften dieser Instanz, keine eigenen Entitaeten. Genau daran ist die
 * Altanlage gescheitert: #<ID> "Gesamt" steht seit dem 31.07.2026 auf An,
 * waehrend #<ID>/#<ID>/#<ID> seit 2016 und 2017 auf Aus stehen. Eine Instanz
 * kann sich nicht selbst widersprechen.
 *
 * KEINE AUTOMATISMEN. Dieses Modul schaltet nie von selbst scharf oder unscharf.
 * Es leitet nichts aus Anwesenheit ab, es schlaegt nichts vor, das sich nach
 * einer Frist selbst ausfuehrt. Drei Dinge sind konfigurierbar und NUR
 * konfigurierbar:
 *   WANN scharf  -> store 'profile'     (Knopf oder ein vom Bewohner angelegter Zeiteintrag)
 *   WIE  scharf  -> je Profil Modus, Bereiche, Verzoegerungen
 *   WAS passiert -> store 'matrix' (Rolle x Modus) und 'reaktionen' (Anlass -> Ringe)
 * Was nicht konfiguriert ist, passiert nicht.
 *
 * Die Entscheidungslogik liegt kernelfrei in zwei Engines und ist ausserhalb
 * von Symcon geprueft (47 Faelle). Dieses Modul BEOBACHTET und WENDET AN:
 *   SecurityAssessment  Meldergesundheit, Abdeckung, Verlaesslichkeit
 *   WatchStateMachine   Zustaende, Verzoegerungen, Ausloesung
 *
 * Ort: HomeSuite\Waechter
 */

require_once __DIR__ . '/../libs/HomeSuite/autoload.php';

use Hoep\HomeSuite\ActionContext;
use Hoep\HomeSuite\Control;
use Hoep\HomeSuite\ControlContract;
use Hoep\HomeSuite\EntityModule;
use Hoep\HomeSuite\Engines\SecurityAssessment as SA;
use Hoep\HomeSuite\Engines\WatchStateMachine as WSM;

class HomeSuiteWaechter extends EntityModule
{
    private const TIMER_WACHE = 'Wache';   // Meldergesundheit, Kette, Wiederholungen
    private const TIMER_FRIST = 'Frist';   // laeuft NUR waehrend einer Verzoegerung

    private const WACHE_MS = 30000;
    private const FRIST_MS = 1000;

    /** Anlaufkarenz nach einem Kernelstart: so lange ist "stumm" eine Aussage ueber den Neustart. */
    private const KARENZ_SEK = 7500;

    private const ATTR_RUNTIME = 'WatchState';
    private const ATTR_CHRONIK = 'ChronikRing';

    // =====================================================================
    // Aufbau
    // =====================================================================
    public function Create()
    {
        parent::Create();

        // Skalare Konfiguration als native Properties (ConfigSchema=1).
        $this->RegisterPropertyBoolean('Armed', false);          // Vorgabe: Schattenbetrieb
        $this->RegisterPropertyInteger('EingangSek', 45);
        $this->RegisterPropertyInteger('EingangKrankSek', 20);
        $this->RegisterPropertyInteger('VerdachtSek', 120);
        $this->RegisterPropertyInteger('KetteStilleSek', 900);
        $this->RegisterPropertyString('ChronikPfad', '');        // leer = Kernel-Dir/waechter

        // Zustand der Zustandsmaschine. Eigenes Attribut, NICHT der Store:
        // der Store ist Konfiguration, das hier ist Laufzeit.
        $this->RegisterAttributeString(self::ATTR_RUNTIME, '{}');
        $this->RegisterAttributeString(self::ATTR_CHRONIK, '[]');
    }

    protected function setupTimers(): void
    {
        $this->RegisterTimer(self::TIMER_WACHE, 0, 'HSSC_RunTimer($_IPS[\'TARGET\'], "wache");');
        $this->RegisterTimer(self::TIMER_FRIST, 0, 'HSSC_RunTimer($_IPS[\'TARGET\'], "frist");');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Melder anbinden. NUR was im Register steht.
        $this->horchen();

        // Timer nach jedem Reload ausdruecklich neu planen — ein neu angelegter
        // Timer laeuft an dieser Anlage nachweislich nicht von selbst wieder an.
        @$this->SetTimerInterval(self::TIMER_WACHE, self::WACHE_MS);
        $st = $this->rt();
        @$this->SetTimerInterval(self::TIMER_FRIST, ((int) ($st['frist'] ?? 0)) > 0 ? self::FRIST_MS : 0);

        // Kernelstart merken: davor geschriebene Laufzeitwerte sind ungueltig.
        if (($st['boot'] ?? 0) !== $this->bootStempel()) {
            $st['boot'] = $this->bootStempel();
            $st['verdacht'] = [];
            $this->rtSet($st);
            $this->chronik('Neustart', ['zustand' => (int) ($st['zustand'] ?? 0)]);
        }

        $this->spiegeln();
    }

    protected function manifest(): array
    {
        return [
            'domain' => 'security',
            'title'  => 'Waechter',
            'icon'   => 'Shield',

            'controls' => [
                ['ident' => 'Mode', 'type' => ControlContract::T_SELECT, 'role' => 'security:mode',
                 'label' => 'Überwachung', 'varType' => 1, 'actionable' => true,
                 'options' => [
                     ['value' => WSM::M_AUS,      'label' => 'Aus'],
                     ['value' => WSM::M_ANWESEND, 'label' => 'Anwesend'],
                     ['value' => WSM::M_NACHT,    'label' => 'Nacht'],
                     ['value' => WSM::M_ABWESEND, 'label' => 'Abwesend'],
                 ]],
                ['ident' => 'ZoneEG',    'type' => ControlContract::T_SWITCH, 'role' => 'security:zone',
                 'label' => 'Erdgeschoss',  'varType' => 0, 'profile' => '~Switch', 'actionable' => true],
                ['ident' => 'ZoneOG',    'type' => ControlContract::T_SWITCH, 'role' => 'security:zone',
                 'label' => 'Obergeschoss', 'varType' => 0, 'profile' => '~Switch', 'actionable' => true],
                ['ident' => 'ZoneDG',    'type' => ControlContract::T_SWITCH, 'role' => 'security:zone',
                 'label' => 'Dachgeschoss', 'varType' => 0, 'profile' => '~Switch', 'actionable' => true],
                ['ident' => 'ZoneNeben', 'type' => ControlContract::T_SWITCH, 'role' => 'security:zone',
                 'label' => 'Nebenräume',   'varType' => 0, 'profile' => '~Switch', 'actionable' => true],

                ['ident' => 'Panic', 'type' => ControlContract::T_COMMAND, 'role' => 'security:panic',
                 'label' => 'Panik', 'varType' => 1, 'actionable' => true],
                ['ident' => 'Quiet', 'type' => ControlContract::T_COMMAND, 'role' => 'security:quiet',
                 'label' => 'Ruhe — leise, bleibt scharf', 'varType' => 1, 'actionable' => true],
                ['ident' => 'SelfTest', 'type' => ControlContract::T_COMMAND, 'role' => 'security:selftest',
                 'label' => 'Meldewege prüfen', 'varType' => 1, 'actionable' => true],

                ['ident' => 'State', 'type' => ControlContract::T_REFLECT, 'role' => 'security:state',
                 'label' => 'Zustand', 'varType' => 3, 'actionable' => false],
                ['ident' => 'Coverage', 'type' => ControlContract::T_REFLECT, 'role' => 'security:coverage',
                 'label' => 'Verlässlichkeit', 'varType' => 1, 'unit' => '%', 'actionable' => false],
                ['ident' => 'Gaps', 'type' => ControlContract::T_REFLECT, 'role' => 'security:gaps',
                 'label' => 'Nicht belastbar', 'varType' => 1, 'actionable' => false],
                ['ident' => 'Incident', 'type' => ControlContract::T_REFLECT, 'role' => 'security:incident',
                 'label' => 'Offener Vorfall', 'varType' => 0, 'profile' => '~Alert', 'actionable' => false],
            ],

            'managementActions' => [
                ['op' => 'getConfig',      'label' => 'Konfiguration lesen'],
                ['op' => 'setProfiles',    'label' => 'Wann scharf — Profile'],
                ['op' => 'setMatrix',      'label' => 'Wie scharf — Rolle mal Modus'],
                ['op' => 'setReactions',   'label' => 'Was passiert — Anlass mal Ring'],
                ['op' => 'setRegister',    'label' => 'Melderregister setzen'],
                ['op' => 'scanRegister',   'label' => 'Melder suchen (Vorschlag, wirkt nicht)'],
                ['op' => 'arm',            'label' => 'Scharf schalten (Profil)'],
                ['op' => 'disarm',         'label' => 'Ausschalten'],
                ['op' => 'quiet',          'label' => 'Leise stellen'],
                ['op' => 'panic',          'label' => 'Panik'],
                ['op' => 'assess',         'label' => 'Melderlage beurteilen (Diagnose)'],
                ['op' => 'chronik',        'label' => 'Chronik lesen'],
                ['op' => 'selfTest',       'label' => 'Meldewege prüfen'],
                ['op' => 'setArmed',       'label' => 'Scharfschalten / Schatten-Modus'],
            ],
        ];
    }

    // =====================================================================
    // Bedienung
    // =====================================================================
    protected function applyControl(Control $c, $value, ActionContext $ctx): void
    {
        switch ($c->ident) {
            case 'Mode':
                // Der Modus-Regler waehlt KEIN Profil aus. Er ist die Anzeige des
                // Zustands; scharf geschaltet wird ueber ein Profil, damit immer
                // klar ist, WELCHE Einstellung gerade gilt.
                $v = (int) $value;
                if ($v === WSM::M_AUS) {
                    $this->ereignis(['art' => 'aus'], $ctx);
                } else {
                    $p = $this->profilFuerModus($v);
                    if ($p === '') {
                        $this->LogMessage('HSSC: kein Profil fuer Modus ' . $v . ' konfiguriert', KL_WARNING);
                        return;
                    }
                    $this->ereignis(['art' => 'scharf', 'profil' => $p], $ctx);
                }
                break;

            case 'ZoneEG': case 'ZoneOG': case 'ZoneDG': case 'ZoneNeben':
                $this->zonenAusControls();
                break;

            case 'Panic':    $this->ereignis(['art' => 'panik'], $ctx); break;
            case 'Quiet':    $this->ereignis(['art' => 'ruhe'], $ctx);  break;
            case 'SelfTest': $this->selbsttest(); break;
        }
    }

    /** Timer-Rueckruf. */
    public function RunTimer(string $job): void
    {
        if ($job === 'wache') {
            $this->wache();
            return;
        }
        if ($job === 'frist') {
            $this->ereignis(['art' => 'tick'], null);
        }
    }

    // =====================================================================
    // Melder
    // =====================================================================
    /** Auf genau die Variablen aus dem Register horchen — und auf sonst nichts. */
    private function horchen(): void
    {
        foreach ($this->register() as $m) {
            $vid = (int) ($m['id'] ?? 0);
            if ($vid > 0 && @IPS_VariableExists($vid)) {
                $this->RegisterMessage($vid, VM_UPDATE);
            }
        }
    }

    public function MessageSink($Timestamp, $Sender, $Message, $Data)
    {
        // Kernel-Nachrichten gehoeren der Basis.
        if ((int) $Message === IPS_KERNELMESSAGE) {
            parent::MessageSink($Timestamp, $Sender, $Message, $Data);
            return;
        }
        if ((int) $Message !== VM_UPDATE) {
            return;
        }

        // ERSTE ZEILE: der Filter. Wer nicht im Register steht, kostet nichts.
        // Genau hier ist die Beschattung mit 22.000 Bremsmeldungen gescheitert —
        // dort wurde IM Handler gerechnet, bevor gefiltert wurde.
        $vid = (int) $Sender;
        $m   = $this->registerEintrag($vid);
        if ($m === null) {
            return;
        }

        $this->melderTelegramm($m, $Data);
    }

    private function melderTelegramm(array $m, $data): void
    {
        $vid  = (int) $m['id'];
        $wert = (string) @GetValueFormatted($vid);
        $offen = SA::istOffen($wert);

        $snap = $this->momentaufnahme();
        $zeitOk = SA::zeitPlausibel(time(), $this->letzterChronikTs());
        $bef  = SA::melder($m, $snap, time(), $zeitOk, $this->karenzBis());
        $abd  = SA::abdeckung($this->register(), SA::alle($this->register(), $snap, time(), $zeitOk, $this->karenzBis()));
        $zone = (string) ($m['zone'] ?? '');

        $this->ereignis([
            'art'   => 'melder',
            'vid'   => $vid,
            'name'  => (string) ($m['name'] ?? ('#' . $vid)),
            'zone'  => $zone,
            'role'  => (string) ($m['role'] ?? ''),
            'offen' => $offen,
            'gesund' => (bool) $bef['belastbar'],
            'gruppe' => (string) ($m['gruppe'] ?? $zone),
            'huelleBlind' => !((bool) ($abd[$zone]['huelleVollstaendig'] ?? true)),
        ], null);
    }

    // =====================================================================
    // Herzstueck: ein Ereignis durch die Zustandsmaschine schicken
    // =====================================================================
    private function ereignis(array $ev, ?ActionContext $ctx): void
    {
        $now = time();
        $cfg = $this->maschinenConfig();
        $r   = WSM::verarbeite($this->rt(), $cfg, $ev, $now);

        $this->rtSet($r['state']);
        $this->anwenden($r['aktionen'], $r['state']);
        $this->spiegeln();

        // Der Sekundentakt laeuft NUR, solange eine Frist offen ist.
        @$this->SetTimerInterval(self::TIMER_FRIST, ((int) ($r['state']['frist'] ?? 0)) > 0 ? self::FRIST_MS : 0);
    }

    /** Aktionen der Engine ausfuehren. Hier — und nur hier — wird real geschaltet. */
    private function anwenden(array $aktionen, array $state): void
    {
        $scharf = $this->armedEffective($this->ReadPropertyBoolean('Armed'));

        foreach ($aktionen as $a) {
            $kind = (string) ($a['kind'] ?? '');
            switch ($kind) {
                case 'chronik':
                    $this->chronik((string) ($a['was'] ?? ''), (array) ($a['daten'] ?? []));
                    break;

                case 'ring0':
                    // Ring 0 ist still und laeuft IMMER, auch im Schattenbetrieb:
                    // er schaltet nichts, er sagt nur, was los ist.
                    $this->setReflect('State', (string) ($a['text'] ?? ''));
                    break;

                case 'stilleVorstufe':
                    $this->chronik('stille Vorstufe', ['sek' => (int) ($a['sek'] ?? 0)]);
                    break;

                case 'stillAlles':
                    if ($scharf) { $this->allesStill(); }
                    break;

                case 'timer':
                    @$this->SetTimerInterval(self::TIMER_FRIST, self::FRIST_MS);
                    break;

                case 'licht': case 'sirene': case 'push':
                    if (!$scharf) {
                        $this->chronik('Schattenbetrieb — nicht gesendet', ['weg' => $kind, 'text' => (string) ($a['text'] ?? '')]);
                        break;
                    }
                    $this->meldeweg($kind, (string) ($a['text'] ?? ''), (int) ($a['prio'] ?? 1));
                    break;
            }
        }

        $this->setReflect('Incident', in_array((int) ($state['zustand'] ?? 0), [WSM::ALARM, WSM::QUITTIERT], true));
    }

    /**
     * Die Meldewege. In Stufe 1 je eine Funktion, keine eigenen Instanzen —
     * sieben zusaetzliche Instanzen sind genau der Umfang, der aus sechs kleinen
     * Wartungsfenstern ein grosses macht.
     */
    private function meldeweg(string $weg, string $text, int $prio): void
    {
        $cfg = $this->store()->get('wege', []);
        $cfg = is_array($cfg) ? $cfg : [];

        if ($weg === 'push') {
            $this->push($text, $prio, $cfg);
            return;
        }
        if ($weg === 'licht') {
            foreach ((array) ($cfg['lichter'] ?? []) as $iid) {
                $iid = (int) $iid;
                if ($iid > 0 && function_exists('HSLT_SetPower')) {
                    @HSLT_SetPower($iid, true);
                }
            }
            $this->chronik('Licht an', ['anzahl' => count((array) ($cfg['lichter'] ?? []))]);
            return;
        }
        if ($weg === 'sirene') {
            $vid = (int) ($cfg['sireneVid'] ?? 0);
            if ($vid <= 0 || !@IPS_VariableExists($vid)) {
                // Ehrlich bleiben: es gibt keinen Signalgeber, also wird auch keiner behauptet.
                $this->chronik('Sirene nicht vorhanden', []);
                $this->LogMessage('HSSC: Alarm ohne Signalgeber — Ring 1 ist faktisch nur Licht.', KL_WARNING);
                return;
            }
            @RequestAction($vid, true);
            $this->chronik('Sirene an', ['vid' => $vid]);
        }
    }

    /** EIN sauberer Sendeweg mit ausgewertetem Rueckgabecode. */
    private function push(string $text, int $prio, array $cfg): void
    {
        $token = trim((string) ($cfg['pushToken'] ?? ''));
        $user  = trim((string) ($cfg['pushUser'] ?? ''));
        if ($token === '' || $user === '') {
            $this->chronik('Push nicht konfiguriert', []);
            return;
        }
        $post = ['token' => $token, 'user' => $user, 'message' => $text,
                 'title' => 'Wächter', 'priority' => $prio];
        if ($prio >= 2) {
            $post['retry'] = 60;
            $post['expire'] = 1800;
        }
        $ch = curl_init('https://api.pushover.net/1/messages.json');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $post, CURLOPT_TIMEOUT => 12]);
        $antwort = curl_exec($ch);
        $code    = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200) {
            // Ein nicht zugestellter Push ist ein Befund, kein Schweigen.
            $this->chronik('Meldung NICHT zugestellt', ['code' => $code]);
            $this->LogMessage('HSSC: Push nicht zugestellt (HTTP ' . $code . ')', KL_WARNING);
            return;
        }
        $this->chronik('Push zugestellt', ['prio' => $prio]);
    }

    private function allesStill(): void
    {
        $cfg = $this->store()->get('wege', []);
        $vid = (int) (is_array($cfg) ? ($cfg['sireneVid'] ?? 0) : 0);
        if ($vid > 0 && @IPS_VariableExists($vid)) {
            @RequestAction($vid, false);
        }
    }

    // =====================================================================
    // Wache: Gesundheit, Faelligkeit, Spiegel
    // =====================================================================
    private function wache(): void
    {
        $now  = time();
        $snap = $this->momentaufnahme();
        $zeitOk = SA::zeitPlausibel($now, $this->letzterChronikTs());
        $bef  = SA::alle($this->register(), $snap, $now, $zeitOk, $this->karenzBis());
        $abd  = SA::abdeckung($this->register(), $bef);
        $st   = $this->rt();
        $v    = SA::verlaesslichkeit($abd, (array) ($st['zonen'] ?? []));

        $this->setReflect('Coverage', (int) $v['anteil']);
        $this->setReflect('Gaps', max(0, (int) $v['gesamt'] - (int) $v['belastbar']));

        // Faellige Zeiteintraege — NUR was der Bewohner angelegt hat.
        $nowMin  = (int) date('G', $now) * 60 + (int) date('i', $now);
        $prevMin = (int) ($st['prevMin'] ?? $nowMin);
        foreach (WSM::faellig($this->maschinenConfig(), $prevMin, $nowMin, (int) date('w', $now)) as $pid) {
            $this->ereignis(['art' => 'scharf', 'profil' => $pid], null);
            $st = $this->rt();
        }
        $st['prevMin'] = $nowMin;
        $this->rtSet($st);
    }

    /**
     * Anzeige nachziehen — AUSDRUECKLICH ohne den Bedienpfad.
     *
     * setControlValue() der Basis ruft RequestAction auf, also applyControl.
     * Wer damit den Modus spiegelt, loest sich selbst wieder aus: spiegeln ->
     * applyControl -> ereignis -> spiegeln. Das hat den ersten Anlauf dieses
     * Moduls in eine Endlosschleife und den PHP-Speicher an die 128-MB-Grenze
     * getrieben; die Instanz blieb auf Status 101 stehen. Anzeigen wird deshalb
     * IMMER direkt geschrieben.
     */
    private function anzeige(string $ident, $wert): void
    {
        if (@$this->GetIDForIdent($ident) === false) {
            return;
        }
        try { $this->SetValue($ident, $wert); } catch (\Throwable $e) { /* Anzeige ist nie kritisch */ }
    }

    private function spiegeln(): void
    {
        $st = $this->rt();
        $namen = [WSM::AUS => 'Aus', WSM::AUSGANG => 'Ausgang', WSM::UEBERWACHT => 'Überwacht',
                  WSM::EINGANG => 'Eingang', WSM::ALARM => 'ALARM', WSM::QUITTIERT => 'Quittiert'];
        $z = (int) ($st['zustand'] ?? 0);
        $this->anzeige('Mode', (int) ($st['modus'] ?? WSM::M_AUS));
        foreach (['Z1' => 'ZoneEG', 'Z2' => 'ZoneOG', 'Z3' => 'ZoneDG', 'Z4' => 'ZoneNeben'] as $k => $ident) {
            $this->anzeige($ident, in_array($k, (array) ($st['zonen'] ?? []), true));
        }
        $this->anzeige('State', $namen[$z] ?? '?');
    }

    private function zonenAusControls(): void
    {
        $st = $this->rt();
        $z  = [];
        foreach (['Z1' => 'ZoneEG', 'Z2' => 'ZoneOG', 'Z3' => 'ZoneDG', 'Z4' => 'ZoneNeben'] as $k => $ident) {
            if ((bool) @$this->GetControlValue($ident)) { $z[] = $k; }
        }
        $st['zonen'] = $z;
        $this->rtSet($st);
        $this->chronik('Bereiche geaendert', ['zonen' => $z]);
    }

    private function selbsttest(): void
    {
        $cfg = $this->store()->get('wege', []);
        $cfg = is_array($cfg) ? $cfg : [];
        $erg = ['push' => 'nicht konfiguriert', 'sirene' => 'nicht vorhanden', 'licht' => 0];
        if (trim((string) ($cfg['pushToken'] ?? '')) !== '') { $erg['push'] = 'konfiguriert'; }
        $sv = (int) ($cfg['sireneVid'] ?? 0);
        if ($sv > 0 && @IPS_VariableExists($sv)) { $erg['sirene'] = 'vorhanden'; }
        $erg['licht'] = count((array) ($cfg['lichter'] ?? []));
        $this->chronik('Selbsttest', $erg);
    }

    // =====================================================================
    // Konfiguration — die drei Achsen
    // =====================================================================
    protected function mgmt(string $op, array $args, array $ctx): array
    {
        switch ($op) {
            case 'getConfig':
                return ['ok' => true,
                        'profile'    => $this->store()->get('profile', []),
                        'matrix'     => $this->store()->get('matrix', []),
                        'reaktionen' => $this->store()->get('reaktionen', []),
                        'wege'       => $this->store()->get('wege', []),
                        'register'   => $this->register(),
                        'zustand'    => $this->rt()];

            case 'setProfiles':
                $this->store()->set('profile', array_values((array) ($args['profile'] ?? [])));
                $this->chronik('Profile geaendert', ['anzahl' => count((array) ($args['profile'] ?? []))]);
                return ['ok' => true];

            case 'setMatrix':
                $this->store()->set('matrix', (array) ($args['matrix'] ?? []));
                $this->chronik('Matrix geaendert', []);
                return ['ok' => true];

            case 'setReactions':
                $this->store()->set('reaktionen', (array) ($args['reaktionen'] ?? []));
                $this->chronik('Reaktionen geaendert', []);
                return ['ok' => true];

            case 'setRegister':
                $this->store()->set('register', array_values((array) ($args['register'] ?? [])));
                $this->horchen();
                $this->chronik('Melderregister geaendert', ['anzahl' => count((array) ($args['register'] ?? []))]);
                return ['ok' => true];

            case 'scanRegister':
                return ['ok' => true, 'vorschlag' => $this->scan()];

            case 'arm':
                $this->ereignis(['art' => 'scharf', 'profil' => (string) ($args['profil'] ?? '')], null);
                return ['ok' => true, 'zustand' => $this->rt()];

            case 'disarm':
                $this->ereignis(['art' => 'aus'], null);
                return ['ok' => true, 'zustand' => $this->rt()];

            case 'quiet':
                $this->ereignis(['art' => 'ruhe'], null);
                return ['ok' => true, 'zustand' => $this->rt()];

            case 'panic':
                $this->ereignis(['art' => 'panik'], null);
                return ['ok' => true, 'zustand' => $this->rt()];

            case 'assess':
                $snap = $this->momentaufnahme();
                $zeitOk = SA::zeitPlausibel(time(), $this->letzterChronikTs());
                $bef = SA::alle($this->register(), $snap, time(), $zeitOk, $this->karenzBis());
                $abd = SA::abdeckung($this->register(), $bef);
                return ['ok' => true, 'zeitPlausibel' => $zeitOk, 'befunde' => $bef, 'abdeckung' => $abd,
                        'verlaesslichkeit' => SA::verlaesslichkeit($abd, (array) ($this->rt()['zonen'] ?? []))];

            case 'chronik':
                return ['ok' => true, 'eintraege' => $this->chronikLesen((int) ($args['n'] ?? 100))];

            case 'selfTest':
                $this->selbsttest();
                return ['ok' => true];
        }
        return parent::mgmt($op, $args, $ctx);
    }

    /** Vorschlag, welche Variablen Melder sein koennten. Wirkt NICHT. */
    private function scan(): array
    {
        $out = [];
        foreach (@IPS_GetVariableList() ?: [] as $vid) {
            $n = (string) @IPS_GetName($vid);
            $p = (string) @IPS_GetLocation($vid);
            $v = @IPS_GetVariable($vid);
            if (!is_array($v)) { continue; }
            $prof = (string) ($v['VariableCustomProfile'] ?: $v['VariableProfile']);
            $istKontakt = stripos($prof, 'Window') !== false || stripos($prof, 'Door') !== false;
            $istMelder  = stripos($prof, 'Motion') !== false || stripos($n, 'Bewegung') !== false;
            if (!$istKontakt && !$istMelder) { continue; }
            $out[] = ['id' => (int) $vid, 'name' => $n, 'pfad' => $p,
                      'rolleVorschlag' => $istKontakt ? 'huelle' : 'innen',
                      'letzte' => date('c', (int) $v['VariableUpdated'])];
            if (count($out) >= 400) { break; }
        }
        return $out;
    }

    // =====================================================================
    // Ablagen
    // =====================================================================
    private function register(): array
    {
        $r = $this->store()->get('register', []);
        return is_array($r) ? $r : [];
    }

    private function registerEintrag(int $vid): ?array
    {
        foreach ($this->register() as $m) {
            if ((int) ($m['id'] ?? 0) === $vid) { return $m; }
        }
        return null;
    }

    /** Momentaufnahme aller Registervariablen und ihrer Nebenvariablen. */
    private function momentaufnahme(): array
    {
        $snap = [];
        $sammle = function (int $vid) use (&$snap): void {
            if ($vid <= 0 || isset($snap[$vid]) || !@IPS_VariableExists($vid)) { return; }
            $v = @IPS_GetVariable($vid);
            $snap[$vid] = ['wert' => (string) @GetValueFormatted($vid), 'roh' => @GetValue($vid),
                           'updated' => (int) ($v['VariableUpdated'] ?? 0),
                           'changed' => (int) ($v['VariableChanged'] ?? 0)];
        };
        foreach ($this->register() as $m) {
            $sammle((int) ($m['id'] ?? 0));
            foreach (['unreachVid', 'lowbatVid', 'battPctVid', 'zweitVid'] as $k) {
                $sammle((int) ($m[$k] ?? 0));
            }
        }
        return $snap;
    }

    /** Konfiguration in der Form, die die Zustandsmaschine erwartet. */
    private function maschinenConfig(): array
    {
        return [
            'profile'    => (array) $this->store()->get('profile', []),
            'matrix'     => (array) $this->store()->get('matrix', []),
            'reaktionen' => (array) $this->store()->get('reaktionen', []),
            'eingangSek'      => max(5, $this->ReadPropertyInteger('EingangSek')),
            'eingangSekKrank' => max(5, $this->ReadPropertyInteger('EingangKrankSek')),
            'verdachtSek'     => max(10, $this->ReadPropertyInteger('VerdachtSek')),
        ];
    }

    private function profilFuerModus(int $modus): string
    {
        foreach ((array) $this->store()->get('profile', []) as $p) {
            if ((int) ($p['modus'] ?? -1) === $modus) { return (string) ($p['id'] ?? ''); }
        }
        return '';
    }

    private function rt(): array
    {
        $j = json_decode((string) $this->ReadAttributeString(self::ATTR_RUNTIME), true);
        return is_array($j) ? $j + WSM::leer() : WSM::leer();
    }

    private function rtSet(array $st): void
    {
        $this->WriteAttributeString(self::ATTR_RUNTIME, json_encode($st, JSON_UNESCAPED_UNICODE));
    }

    private function bootStempel(): int
    {
        // Kernelstart-Kennung: alles im Laufzeitzustand, was aelter ist, ist ungueltig.
        return (int) @filemtime(IPS_GetKernelDir() . 'settings.json') ?: 0;
    }

    private function karenzBis(): int
    {
        $st = $this->rt();
        $b  = (int) ($st['boot'] ?? 0);
        return $b > 0 ? $b + self::KARENZ_SEK : 0;
    }

    // =====================================================================
    // Chronik — der groesste Einzelgewinn: von 35 Oeffnungsmeldern haben heute
    // ganze zwei ueberhaupt eine Historie.
    // =====================================================================
    private function chronikDatei(): string
    {
        $p = trim((string) $this->ReadPropertyString('ChronikPfad'));
        if ($p === '') {
            // AUSSERHALB des Modulordners: der ist git-verwaltet und wird per
            // release.sh ausgeliefert.
            $p = IPS_GetKernelDir() . 'waechter';
        }
        if (!is_dir($p)) { @mkdir($p, 0775, true); }
        return rtrim($p, '/') . '/chronik-' . date('Ym') . '.jsonl';
    }

    private function chronik(string $was, array $daten = []): void
    {
        $st = $this->rt();
        $z  = [
            'ts'    => time(),
            'zeit'  => date('c'),
            'was'   => $was,
            'daten' => $daten,
            'phase' => (string) ($st['phase'] ?? ''),
            'modus' => (int) ($st['modus'] ?? 0),
            'zustand' => (int) ($st['zustand'] ?? 0),
        ];
        @file_put_contents($this->chronikDatei(), json_encode($z, JSON_UNESCAPED_UNICODE) . "\n",
                           FILE_APPEND | LOCK_EX);

        // Ringpuffer fuer das Tabellen-Widget.
        $ring = json_decode((string) $this->ReadAttributeString(self::ATTR_CHRONIK), true);
        $ring = is_array($ring) ? $ring : [];
        $ring[] = $z;
        if (count($ring) > 200) { $ring = array_slice($ring, -200); }
        $this->WriteAttributeString(self::ATTR_CHRONIK, json_encode($ring, JSON_UNESCAPED_UNICODE));
    }

    private function chronikLesen(int $n): array
    {
        $ring = json_decode((string) $this->ReadAttributeString(self::ATTR_CHRONIK), true);
        $ring = is_array($ring) ? $ring : [];
        return array_slice($ring, -max(1, min(200, $n)));
    }

    private function letzterChronikTs(): int
    {
        $ring = json_decode((string) $this->ReadAttributeString(self::ATTR_CHRONIK), true);
        if (!is_array($ring) || $ring === []) { return 0; }
        $l = end($ring);
        return (int) ($l['ts'] ?? 0);
    }
}

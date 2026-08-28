<?php

declare(strict_types=1);

namespace Hoep\HomeSuite\HAL;


/**
 * GenericBoundClimate — Klimageraet ueber gebundene Symcon-Variablen.
 *
 * Fuer alles, was bereits ein Modul in Symcon hat: Tado ueber TadoAC, spaeter
 * beliebige andere. Der Treiber kennt kein Protokoll, er schreibt auf die
 * Variablen des Fremdmoduls - per RequestAction, wenn sie eine Aktion haben,
 * sonst per SetValue.
 *
 * Aufzaehlungen werden ueber eine MAPPE uebersetzt, weil jedes Modul eigene
 * Bezeichner fuehrt: Tado schreibt 'COOL' und 'AUTO' als Text, ein anderes
 * Modul vielleicht Zahlen. Die Mappe steht in der Konfiguration und nicht im
 * Code, damit ein neues Fremdmodul ohne Codeaenderung anzubinden ist.
 *
 * Konfiguration:
 *   powerVid, modeVid, targetVid, indoorVid, outdoorVid, fanVid, swingVid,
 *   ionVid, onlineVid   - je Symcon-Variable, 0 = nicht vorhanden
 *   map: ['mode' => ['cool' => 'COOL', ...], 'fan' => [...], 'swing' => [...]]
 *   targetMin/targetMax/targetStep
 */
final class GenericBoundClimate implements IClimate
{
    private array $cfg = [];

    public function bind(array $config, callable $send): void
    {
        $this->cfg = $config;
    }

    public function capabilities(): array
    {
        $m = $this->mappe('mode');
        $f = $this->mappe('fan');
        $s = $this->mappe('swing');
        return (new ClimateCapabilities(
            power:  $this->vid('powerVid') > 0,
            target: $this->vid('targetVid') > 0,
            targetMin:  (float) ($this->cfg['targetMin'] ?? 16.0),
            targetMax:  (float) ($this->cfg['targetMax'] ?? 30.0),
            targetStep: (float) ($this->cfg['targetStep'] ?? 0.5),
            modes:  array_keys($m), fans: array_keys($f), swings: array_keys($s),
            presets: [],
            ion:     $this->vid('ionVid') > 0,
            indoor:  $this->vid('indoorVid') > 0,
            outdoor: $this->vid('outdoorVid') > 0
        ))->toArray();
    }

    /** Keine Netzsuche: beide Wege brauchen Zugangsdaten bzw. eine Bindung. */
    public static function discover(int $timeoutMs = 2000): array
    {
        return [];
    }

    public function poll(): array
    {
        return $this->readState()->toArray();
    }

    public function parseEvent(string $raw): ?AudioState
    {
        return null;
    }

    public function readState(): ClimateState
    {
        $online = $this->vid('onlineVid');
        return new ClimateState(
            on:      (bool) $this->lies('powerVid', false),
            mode:    $this->zurueck('mode',  (string) $this->lies('modeVid', '')),
            target:  (float) $this->lies('targetVid',  -100.0),
            indoor:  (float) $this->lies('indoorVid',  -100.0),
            outdoor: (float) $this->lies('outdoorVid', -100.0),
            fan:     $this->zurueck('fan',   (string) $this->lies('fanVid', '')),
            swing:   $this->zurueck('swing', (string) $this->lies('swingVid', '')),
            preset:  '',
            ion:     (bool) $this->lies('ionVid', false),
            humidity: (float) $this->lies('humidityVid', -1.0),
            swingH:  $this->zurueck('swingH', (string) $this->lies('swingHVid', '')),
            light:   $this->vid('lightVid')    > 0 ? (bool) $this->lies('lightVid', false) : null,
            scheduled: $this->vid('scheduleVid') > 0 ? (bool) $this->lies('scheduleVid', false) : null,
            reachable: $online > 0 ? (bool) @\GetValue($online) : true
        );
    }

    public function setPower(bool $on): bool          { return $this->schreib('powerVid', $on); }
    public function setIon(bool $on): bool            { return $this->schreib('ionVid', $on); }
    public function setTarget(float $celsius): bool   { return $this->schreib('targetVid', $celsius); }
    public function setPreset(string $preset): bool   { return false; }   // kennt dieser Weg nicht
    public function setSwingH(string $swing): bool   { return $this->schreib('swingHVid', $this->hin('swingH', $swing) ?? $swing); }
    public function setLight(bool $on): bool         { return $this->schreib('lightVid', $on); }
    public function setScheduled(bool $folgen): bool { return $this->schreib('scheduleVid', $folgen); }

    public function setMode(string $mode): bool
    {
        $w = $this->hin('mode', $mode);
        return $w === null ? false : $this->schreib('modeVid', $w);
    }

    public function setFan(string $fan): bool
    {
        $w = $this->hin('fan', $fan);
        return $w === null ? false : $this->schreib('fanVid', $w);
    }

    public function setSwing(string $swing): bool
    {
        $w = $this->hin('swing', $swing);
        return $w === null ? false : $this->schreib('swingVid', $w);
    }

    // ------------------------------------------------------------------ intern

    private function vid(string $schluessel): int
    {
        return (int) ($this->cfg[$schluessel] ?? 0);
    }

    private function mappe(string $art): array
    {
        $m = $this->cfg['map'][$art] ?? [];
        return is_array($m) ? $m : [];
    }

    /** sprechend -> Fremdwert */
    private function hin(string $art, string $wert)
    {
        $m = $this->mappe($art);
        return $m[strtolower($wert)] ?? null;
    }

    /** Fremdwert -> sprechend */
    private function zurueck(string $art, string $wert): string
    {
        if ($wert === '') {
            return '';
        }
        foreach ($this->mappe($art) as $sprechend => $fremd) {
            if ((string) $fremd === $wert) {
                return (string) $sprechend;
            }
        }
        return '';
    }

    private function lies(string $schluessel, $vorgabe)
    {
        $vid = $this->vid($schluessel);
        if ($vid <= 0 || !function_exists('IPS_VariableExists') || !@\IPS_VariableExists($vid)) {
            return $vorgabe;
        }
        $w = @\GetValue($vid);
        return $w === null ? $vorgabe : $w;
    }

    /**
     * Schreiben ueber RequestAction, wenn die Variable eine Aktion hat - nur so
     * erreicht der Wert das Fremdmodul und damit das Geraet. Ohne Aktion bliebe
     * ein SetValue reine Anzeige; das wird protokolliert, damit eine fehlende
     * Bindung nicht als Erfolg durchgeht.
     */
    private function schreib(string $schluessel, $wert): bool
    {
        $vid = $this->vid($schluessel);
        if ($vid <= 0 || !@\IPS_VariableExists($vid)) {
            return false;
        }
        $v = @\IPS_GetVariable($vid);
        $hatAktion = is_array($v)
            && ((int) ($v['VariableAction'] ?? 0) > 0 || (int) ($v['VariableCustomAction'] ?? 0) > 0);
        if ($hatAktion) {
            return (bool) @\RequestAction($vid, $wert);
        }
        @\IPS_LogMessage('HS.Klima', 'Variable ' . $vid . ' hat keine Aktion - nur Anzeige gesetzt');
        @\SetValue($vid, $wert);
        return false;
    }
}

DriverFactory::register('generic-climate', GenericBoundClimate::class);

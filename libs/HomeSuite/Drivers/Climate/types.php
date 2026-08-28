<?php

declare(strict_types=1);

namespace Hoep\HomeSuite\HAL;

/**
 * Wertetypen der Klima-HAL.
 *
 * Bewusst herstellerneutral: Toshiba spricht Hexbytes, Tado spricht JSON ueber
 * ein Fremdmodul. Beides landet hier in denselben Feldern, damit das Modul
 * ClimateZone nicht wissen muss, mit wem es redet.
 *
 * "Unbekannt"-Konventionen wie bei LightState: Temperaturen -100, Aufzaehlungen
 * leerer String.
 */
final class ClimateState
{
    public function __construct(
        public bool $on = false,            // Geraet laeuft
        public string $mode = '',           // auto|cool|heat|dry|fan  ('' = unbekannt)
        public float $target = -100.0,      // Solltemperatur in Grad C
        public float $indoor = -100.0,      // gemessene Innentemperatur
        public float $outdoor = -100.0,     // gemessene Aussentemperatur
        public string $fan = '',            // quiet|low|medium|high|auto
        public string $swing = '',          // off|vertical|horizontal|both|fix1..fix5
        public string $preset = '',         // off|eco|highpower|silent|comfort|sleep|floor|frost
        public bool $ion = false,           // Ionisierung/Luftreinigung
        public bool $reachable = true
    ) {
    }

    public function toArray(): array
    {
        return ['on' => $this->on, 'mode' => $this->mode, 'target' => $this->target,
                'indoor' => $this->indoor, 'outdoor' => $this->outdoor,
                'fan' => $this->fan, 'swing' => $this->swing, 'preset' => $this->preset,
                'ion' => $this->ion, 'reachable' => $this->reachable];
    }
}

final class ClimateCapabilities
{
    /**
     * @param string[] $modes   unterstuetzte Betriebsarten
     * @param string[] $fans    unterstuetzte Luefterstufen
     * @param string[] $swings  unterstuetzte Schwenkarten
     * @param string[] $presets unterstuetzte Sonderfunktionen
     */
    public function __construct(
        public bool $power = false,
        public bool $target = false,
        public float $targetMin = 16.0,
        public float $targetMax = 30.0,
        public float $targetStep = 1.0,
        public array $modes = [],
        public array $fans = [],
        public array $swings = [],
        public array $presets = [],
        public bool $ion = false,
        public bool $indoor = false,
        public bool $outdoor = false
    ) {
    }

    public function toArray(): array
    {
        return ['power' => $this->power, 'target' => $this->target,
                'targetMin' => $this->targetMin, 'targetMax' => $this->targetMax,
                'targetStep' => $this->targetStep, 'modes' => $this->modes,
                'fans' => $this->fans, 'swings' => $this->swings,
                'presets' => $this->presets, 'ion' => $this->ion,
                'indoor' => $this->indoor, 'outdoor' => $this->outdoor];
    }
}

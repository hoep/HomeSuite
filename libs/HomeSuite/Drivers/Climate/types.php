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
        public float $humidity = -1.0,      // relative Luftfeuchte in %, -1 = unbekannt
        public string $swingH = '',         // waagrechtes Schwenken: off|on ('' = kann das Geraet nicht)
        public ?bool $light = null,         // Displaybeleuchtung, null = kein solches Feld
        public ?bool $scheduled = null,     // true = folgt dem Zeitplan, false = Handbetrieb
        public ?bool $openWindow = null,    // offenes Fenster erkannt, null = kein solches Feld
        public int $powerLevel = -1,        // Leistungsstufe in % (50/75/100), -1 = unbekannt
        public ?bool $running = null,       // laeuft der Verdichter WIRKLICH (nicht nur "eingeschaltet")
        public string $presence = '',       // home|away - Geofencing des Anbieters
        public int $overrideUntil = 0,      // Handbetrieb laeuft bis (Unixzeit), 0 = unbefristet/keiner
        public int $nextChange = 0,         // naechste planmaessige Aenderung (Unixzeit), 0 = keine
        public ?bool $selfClean = null,     // Selbstreinigung aktiv
        public string $fireplace = '',      // Kaminmodus: off|kamin1|kamin2
        public bool $reachable = true
    ) {
    }

    public function toArray(): array
    {
        return ['on' => $this->on, 'mode' => $this->mode, 'target' => $this->target,
                'indoor' => $this->indoor, 'outdoor' => $this->outdoor,
                'fan' => $this->fan, 'swing' => $this->swing, 'preset' => $this->preset,
                'ion' => $this->ion, 'humidity' => $this->humidity, 'swingH' => $this->swingH,
                'light' => $this->light, 'scheduled' => $this->scheduled,
                'openWindow' => $this->openWindow, 'powerLevel' => $this->powerLevel,
                'running' => $this->running, 'presence' => $this->presence,
                'overrideUntil' => $this->overrideUntil, 'nextChange' => $this->nextChange,
                'selfClean' => $this->selfClean, 'fireplace' => $this->fireplace,
                'reachable' => $this->reachable];
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
        public bool $outdoor = false,
        public bool $humidity = false,      // misst relative Luftfeuchte
        public array $swingsH = [],         // waagrechtes Schwenken, leer = kann es nicht
        public bool $light = false,         // Displaybeleuchtung schaltbar
        public bool $schedule = false,      // kennt Zeitplan vs. Handbetrieb
        public array $powerLevels = [],     // waehlbare Leistungsstufen in %, leer = kann es nicht
        public bool $running = false,       // meldet den echten Laufzustand
        public bool $presence = false,      // meldet Geofencing
        public bool $selfClean = false,     // meldet Selbstreinigung
        public array $fireplaces = [],      // Kaminmodi, leer = kann es nicht
        public int $pollSeconds = 60        // vertraeglicher Abfragetakt in Sekunden
    ) {
    }

    public function toArray(): array
    {
        return ['power' => $this->power, 'target' => $this->target,
                'targetMin' => $this->targetMin, 'targetMax' => $this->targetMax,
                'targetStep' => $this->targetStep, 'modes' => $this->modes,
                'fans' => $this->fans, 'swings' => $this->swings,
                'presets' => $this->presets, 'ion' => $this->ion,
                'indoor' => $this->indoor, 'outdoor' => $this->outdoor,
                'humidity' => $this->humidity, 'swingsH' => $this->swingsH,
                'light' => $this->light, 'schedule' => $this->schedule,
                'powerLevels' => $this->powerLevels, 'running' => $this->running,
                'presence' => $this->presence, 'selfClean' => $this->selfClean,
                'fireplaces' => $this->fireplaces, 'pollSeconds' => $this->pollSeconds];
    }
}

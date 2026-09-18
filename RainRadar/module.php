<?php

declare(strict_types=1);

/**
 * RainRadar — Symcon-Modul, das die bewaehrte WeatherRadar-Klasse (GD-Bildkomposition +
 * Regenerkennung, aus dem Alt-Skript PHPRainRadar.php) sauber kapselt:
 *  - Konsolen-Konfiguration (Standort, Radar-Quelle, Basiskarte, Stunden, Animation, Schwelle),
 *  - zyklischer Timer (statt losem Ereignis-Skript),
 *  - registrierte Variablen: RadarMeta (JSON fuers LVB-Frontend), Forecast, Intensitaet.
 * Prefix RR -> RR_Update() (Timer/Button). Bildausgabe in OutputDir, web-erreichbar unter /tile/<ordner>/.
 */
class RainRadar extends IPSModule
{
    public function Create()
    {
        parent::Create();

        // Standort
        $this->RegisterPropertyFloat('Lat', 48.2082);
        $this->RegisterPropertyFloat('Lon', 16.3738);
        $this->RegisterPropertyInteger('LocX', 875);   // Standort-Pixel (nur Fallback, wenn keine Geokoordinaten)
        $this->RegisterPropertyInteger('LocY', 316);
        $this->RegisterPropertyInteger('LocationID', 0); // Symcon-Location-Control (0 = automatisch), wenn Lat/Lon = 0
        // Quelle / Karte
        // Das Modul brennt die Standortmarke (Fadenkreuz + Kreis) in die PNG. Das LVB-Widget
        // zeichnet daneben eine eigene, die als Vektor bei jeder Kachelgroesse scharf bleibt und
        // per Konstruktion genau auf der Kachelmitte sitzt - zwei Marken an derselben Stelle.
        // Voreinstellung daher AUS; wer die Bilder ausserhalb des Widgets nutzt, schaltet sie ein.
        $this->RegisterPropertyBoolean('DrawMarkers', false);
        $this->RegisterPropertyString('BaseMap', '/usr/share/symcon/tile/rainradar/basemap.png');
        $this->RegisterPropertyString('RadarUrl', 'https://portale.geosphere.at/hpAT/index.php?pu=default&op=getNoCacheImg&a=INCAL_VW1398&p=HP_RR_AT&i=');
        $this->RegisterPropertyString('OutputDir', '/usr/share/symcon/tile/rainradar');
        // Verarbeitung
        $this->RegisterPropertyInteger('Hours', 72);   // Headroom; der Lauf bricht am echten Vorhersage-Horizont ab (nur echte Bilder)
        $this->RegisterPropertyInteger('AnimDelay', 750);   // ms je Frame im GIF
        $this->RegisterPropertyFloat('Threshold', 0.1);     // mm/h -> Regen (nur fuer Intensitaets-Variable/Forecast-Logik der Klasse)
        $this->RegisterPropertyInteger('Interval', 15);     // Update-Takt in Minuten (0 = aus)

        // Variablen
        $this->RegisterVariableString('RadarMeta', 'Radar Meta (JSON)', '', 10);
        $this->RegisterVariableString('Forecast', 'Vorhersage', '', 20);
        $this->RegisterVariableFloat('Intensity', 'Regen aktuell', '', 30);

        // Timer
        $this->RegisterTimer('Update', 0, 'RR_Update($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->ensureWorker();
        $min = max(0, $this->ReadPropertyInteger('Interval'));
        $this->SetTimerInterval('Update', $min * 60 * 1000);
    }

    /**
     * Legt/aktualisiert das versteckte Worker-Skript unter der Instanz. Die SCHWERE
     * Verarbeitung (48 Downloads + GD-Komposition + ImageMagick) MUSS in einem Skript-
     * Thread laufen, nicht in der Modul-Methode (Kernel-Thread) — sonst blockiert/crasht
     * der Kernel. Das Skript liest die Konfig live aus den Properties und schreibt die
     * Modul-Variablen per ID.
     */
    private function ensureWorker(): void
    {
        $wid = @IPS_GetObjectIDByIdent('Worker', $this->InstanceID);
        if ($wid === false) {
            $wid = IPS_CreateScript(0);
            IPS_SetParent($wid, $this->InstanceID);
            IPS_SetIdent($wid, 'Worker');
            IPS_SetName($wid, 'RainRadar Worker (async)');
            @IPS_SetHidden($wid, true);
        }
        $iid  = (int) $this->InstanceID;
        $code = "<?php\n"
            . "// AUTOGENERIERT von RainRadar (module.php ensureWorker). Laeuft im Skript-Thread\n"
            . "// (nicht im Kernel) -> schwere GD-/Download-Arbeit ist hier sicher. NICHT haendisch aendern.\n"
            . "\$IID = {$iid};\n"
            . "require_once '/var/lib/symcon/modules/HomeSuite/RainRadar/libs/WeatherRadar.php';\n"
            . "\$out = (string) IPS_GetProperty(\$IID, 'OutputDir'); if (\$out !== '' && !is_dir(\$out)) @mkdir(\$out, 0777, true);\n"
            . "\$baseMap = (string) IPS_GetProperty(\$IID, 'BaseMap');\n"
            . "\$bm = @getimagesize(\$baseMap); \$bw = \$bm ? (int) \$bm[0] : 1398; \$bh = \$bm ? (int) \$bm[1] : 798;\n"
            . "// Standort-Pixel aus Geokoordinaten: Lat/Lon (Property) bevorzugt; 0/0 -> Symcon-Location-Modul;\n"
            . "// nur wenn beides fehlt -> manueller Pixel-Fallback LocX/LocY.\n"
            . "\$lat = (float) IPS_GetProperty(\$IID, 'Lat'); \$lon = (float) IPS_GetProperty(\$IID, 'Lon');\n"
            . "if (\$lat == 0.0 && \$lon == 0.0) { \$sl = RainRadarEngine::symconLocation((int) @IPS_GetProperty(\$IID, 'LocationID')); if (\$sl) { \$lat = \$sl[0]; \$lon = \$sl[1]; } }\n"
            . "if (\$lat != 0.0 || \$lon != 0.0) { \$coord = RainRadarEngine::geoToPixel(\$lat, \$lon, \$bw, \$bh); }\n"
            . "else { \$coord = [(int) IPS_GetProperty(\$IID, 'LocX'), (int) IPS_GetProperty(\$IID, 'LocY')]; }\n"
            . "\$config = [\n"
            . "  'baseMapPath'    => \$baseMap,\n"
            . "  'outputDir'      => \$out,\n"
            . "  'radarBaseUrl'   => IPS_GetProperty(\$IID, 'RadarUrl'),\n"
            . "  'hours'          => IPS_GetProperty(\$IID, 'Hours'),\n"
            // 0 = Dateiname traegt oesterreichische Ortszeit (nachgemessen). Ausdruecklich gesetzt,
            // damit der Wert nicht still an der Bibliotheks-Vorgabe haengt.
            . "  'hourOffset'     => 0,\n"
            . "  'coordinates'    => [\$coord],\n"
            . "  'drawMarkers'    => (bool) IPS_GetProperty(\$IID, 'DrawMarkers'), 'markerColor' => [255,0,0], 'markerSize' => 10,\n"
            . "  'showDateTime'   => true, 'dateFormat' => 'd.m.y-H:i',\n"
            . "  'createAnimation'=> true, 'animationDelay' => IPS_GetProperty(\$IID, 'AnimDelay'),\n"
            . "  'cropEnabled'    => false, 'legendEnabled' => false, 'showForecastText' => false,\n"
            . "  'debug'          => false, 'logLevel' => 'warning',\n"
            . "  'logFile'        => rtrim(\$out, '/') . '/rainradar.log',\n"
            . "];\n"
            . "try {\n"
            . "  \$radar = new RainRadarEngine(\$config);\n"
            . "  \$radar->processRadarImages();\n"
            . "  SetValue(IPS_GetObjectIDByIdent('RadarMeta', \$IID), (string) \$radar->getRadarMeta(0));\n"
            . "  SetValue(IPS_GetObjectIDByIdent('Forecast', \$IID), (string) \$radar->getRainForecast(0, IPS_GetProperty(\$IID, 'Hours')));\n"
            . "  \$rain = \$radar->getRain(0); \$now = time(); \$cur = 0.0;\n"
            . "  if (is_array(\$rain)) foreach (\$rain as \$r) { if ((\$r['timestamp'] ?? 0) <= \$now) \$cur = (float) (\$r['intensity'] ?? 0); }\n"
            . "  SetValue(IPS_GetObjectIDByIdent('Intensity', \$IID), \$cur);\n"
            . "} catch (\\Throwable \$e) { IPS_LogMessage('RainRadar', 'Worker-Fehler: ' . \$e->getMessage()); }\n";
        IPS_SetScriptContent($wid, $code);
    }

    /** Manuelle Notfall-/Erstkonfiguration; Verwaltung sonst ueber das LVB-Frontend. */
    public function GetConfigurationForm()
    {
        $meta = @json_decode((string) $this->GetValue('RadarMeta'), true);
        $note = 'Noch kein Lauf.';
        if (is_array($meta) && isset($meta['img'])) {
            $note = 'Letzter Lauf: ' . ($meta['img']['ts'] ?? '?') . ' · Standort ' . ($meta['img']['lx'] ?? '?') . '% / ' . ($meta['img']['ly'] ?? '?') . '% · Frames: ' . (isset($meta['frames']) ? count($meta['frames']) : 0);
        }
        return json_encode([
            'elements' => [
                ['type' => 'Label', 'caption' => 'RainRadar — komponiert das GeoSphere-Radar auf die Basiskarte und liefert RadarMeta (JSON) fuer die LVB-Widgets rainradar/rainintensity.'],
                ['type' => 'Label', 'caption' => 'Standort: Breite/Laenge eingeben — der Standort-Pixel wird daraus exakt berechnet. Sind beide 0, wird das Symcon-Standort-Modul verwendet. Standort X/Y (px) ist nur Notfall-Fallback.'],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'NumberSpinner', 'name' => 'Lat', 'caption' => 'Breite (lat)', 'digits' => 5],
                    ['type' => 'NumberSpinner', 'name' => 'Lon', 'caption' => 'Laenge (lon)', 'digits' => 5],
                    ['type' => 'SelectInstance', 'name' => 'LocationID', 'caption' => 'Standort-Modul (0 = auto, wenn Lat/Lon = 0)'],
                ]],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'NumberSpinner', 'name' => 'LocX', 'caption' => 'Fallback X (px)'],
                    ['type' => 'NumberSpinner', 'name' => 'LocY', 'caption' => 'Fallback Y (px)'],
                ]],
                ['type' => 'ValidationTextBox', 'name' => 'BaseMap', 'caption' => 'Basiskarte (Pfad)'],
                ['type' => 'ValidationTextBox', 'name' => 'RadarUrl', 'caption' => 'Radar-URL (GeoSphere)'],
                ['type' => 'ValidationTextBox', 'name' => 'OutputDir', 'caption' => 'Ausgabe-Ordner'],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'NumberSpinner', 'name' => 'Hours', 'caption' => 'Stunden'],
                    ['type' => 'CheckBox', 'name' => 'DrawMarkers', 'caption' => 'Standortmarke ins Bild zeichnen (Fadenkreuz)'],
                    ['type' => 'Label', 'caption' => 'Aus lassen, solange die Karte im LiveViewBuilder gezeigt wird: das Widget zeichnet dort eine eigene, bei jeder Kachelgroesse scharfe Marke. Beides zusammen ergibt zwei Marken an derselben Stelle.'],
                    ['type' => 'NumberSpinner', 'name' => 'AnimDelay', 'caption' => 'GIF-Delay (ms)'],
                    ['type' => 'NumberSpinner', 'name' => 'Threshold', 'caption' => 'Schwelle (mm/h)', 'digits' => 2],
                    ['type' => 'NumberSpinner', 'name' => 'Interval', 'caption' => 'Takt (min)'],
                ]],
                ['type' => 'Button', 'caption' => 'Jetzt aktualisieren', 'onClick' => 'RR_Update($id);'],
                ['type' => 'Label', 'caption' => $note],
            ],
        ]);
    }

    /**
     * Timer-/Button-Callback (RR_Update): loest den Worker NUR ASYNCHRON aus und kehrt
     * sofort zurueck. Die schwere Verarbeitung laeuft im Skript-Thread des Workers —
     * so blockiert/crasht der Kernel NICHT (das war der Fehler der Erstversion).
     */
    public function Update(): void
    {
        $wid = @IPS_GetObjectIDByIdent('Worker', $this->InstanceID);
        if ($wid === false) { $this->ensureWorker(); $wid = @IPS_GetObjectIDByIdent('Worker', $this->InstanceID); }
        if ($wid !== false) { IPS_RunScript($wid); }   // async, nicht blockierend
    }
}

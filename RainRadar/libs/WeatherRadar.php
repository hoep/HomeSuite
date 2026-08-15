<?php
// WeatherRadar - aus PHPRainRadar.php uebernommen (bewaehrte GD-Bildkomposition + Regenerkennung).
class RainRadarEngine {   // eindeutiger Name (Legacy PHPRainRadar.php nutzt 'WeatherRadar' -> keine Redeklaration im geteilten PHP-Prozess)

    /**
     * Geo-Referenzierung der GeoSphere-Austria-Basiskarte (Produkt INCAL_VW1398, austria.png).
     * Kalibriert an der Landmaske ueber die vier Extrempunkte Oesterreichs (deren Lat/Lon exakt
     * bekannt sind) und verifiziert am Haus (~3 px Abweichung). Laengengrad linear, Breitengrad
     * per Mercator. Referenzmasse 1398x798; bei abweichender Kartengroesse proportional skaliert.
     *
     * @return array [x, y] Pixel auf der Basiskarte
     */
    public static function geoToPixel($lat, $lon, $baseW = 1398, $baseH = 798) {
        $rx = $baseW / 1398.0; $ry = $baseH / 798.0;
        $xl = 186 * $rx; $xr = 1328 * $rx; $yt = 98 * $ry; $yb = 686 * $ry;   // Landmasken-Kanten
        $lonW = 9.5307; $lonE = 17.1607; $latN = 49.0205; $latS = 46.3722;    // OE-Extrempunkte
        $my = function ($la) { return log(tan(M_PI / 4 + deg2rad($la) / 2)); }; // Mercator-Y
        $x = $xl + ($lon - $lonW) / ($lonE - $lonW) * ($xr - $xl);
        $y = $yt + ($my($latN) - $my($lat)) / ($my($latN) - $my($latS)) * ($yb - $yt);
        return [(int) round($x), (int) round($y)];
    }

    /**
     * Standort aus einem Symcon-Location-Control lesen. $locId=0 -> erste Instanz mit
     * gueltiger Location-Konfiguration (gleiche Quelle wie das LVB ?api=astro).
     *
     * @return array|null [lat, lon] oder null wenn keine Location gefunden
     */
    public static function symconLocation($locId = 0) {
        $ids = ($locId > 0) ? [$locId] : @IPS_GetInstanceList();
        if (!is_array($ids)) { return null; }
        foreach ($ids as $id) {
            $cfg = @json_decode((string) @IPS_GetConfiguration($id), true);
            if (!is_array($cfg) || !isset($cfg['Location'])) { continue; }
            $loc = @json_decode((string) $cfg['Location'], true);
            if (is_array($loc) && isset($loc['latitude'])
                && ((float) $loc['latitude'] != 0.0 || (float) ($loc['longitude'] ?? 0) != 0.0)) {
                return [(float) $loc['latitude'], (float) ($loc['longitude'] ?? 0)];
            }
        }
        return null;
    }

    /**
     * @var array Konfiguration der Klasse
     */
    private $config = [];
    
    /**
     * @var array Speichert Regendaten für die übergebenen Koordinaten
     */
    private $rainData = [];
    
    /**
     * @var array Speichert die formatierte Regendaten für Abfragen
     */
    private $formattedRainData = [];
    
    /**
     * @var array Speichert die Sequenz der generierten Bilder
     */
    private $imageSequence = [];
    
    /**
     * Standardkonfiguration für die WeatherRadar-Klasse
     * 
     * @return array Standardkonfiguration
     */
    private function getDefaultConfig() {
        return [
            // Basispfade und URLs
            'baseMapPath' => '/usr/share/symcon/tile/kremsmuenster/austria.png',
            'outputDir' => './radar_images',
            'radarBaseUrl' => 'https://portale.geosphere.at/hpAT/index.php?pu=default&op=getNoCacheImg&a=INCAL_VW1398&p=HP_RR_AT&i=',
            'cleanOutputDir' => true,          // Ausgabeverzeichnis vor der Verarbeitung bereinigen
            
            // Radar-Einstellungen
            'hours' => 48,                     // Anzahl der Stunden für Radarbilder
            'coordinates' => [],               // X/Y-Koordinaten zur Prüfung [[x1,y1], [x2,y2], ...]
            'radius' => 5,                     // Radius um die Koordinaten für Regenprüfung
            'hourOffset' => null,              // Stunden-Offset für Radar-URL (null = automatisch berechnen)
            'hoursInterval' => 3,              // Stunden-Intervall für die Anzeige der Uhrzeiten in der Tabelle
            'forecastTableHours' => 24,        // Anzahl der Stunden für die Vorhersagetabelle
            'forecastTextHours' => 24,         // Anzahl der Stunden für die Textvorhersage
            
            // Bildverarbeitung
            'drawMarkers' => true,             // Rote Kreuze an den Koordinaten zeichnen
            'markerColor' => [255, 0, 0],      // Farbe der Marker (RGB)
            'markerSize' => 5,                 // Größe der Marker in Pixeln
            'showDateTime' => true,            // Datum und Uhrzeit anzeigen
            'dateFormat' => 'd.m.y H:i',       // Format für Datum und Uhrzeit
            'datePosition' => [10, 6],         // Position des Datumstextes [x, y]
            'dateFontSize' => 3,               // Schriftgröße des Datumstextes (1-5)
            'dateTextColor' => [255, 255, 255], // Textfarbe (RGB) - auf Weiß geändert
            'dateBgColor' => [255, 255, 255, 80], // Hintergrundfarbe mit Alpha (RGBA)
            'imageFormat' => 'png',            // Bildformat (png oder jpg)
            'jpgQuality' => 90,                // JPEG-Qualität (0-100), nur bei 'jpg'
            
            // HTML-Ausgabe
            'textColor' => '#FFFFFF',          // Textfarbe für die HTML-Ausgabe (Weiß als Standard für dunkle Hintergründe)
            'cellBorderColor' => '#555555',    // Rahmenfarbe für die Zellen der Vorhersagetabelle
            'showForecastText' => true,        // Vorhersagetext anzeigen
            
            // Crop-Einstellungen
            'cropEnabled' => false,            // Ausschneiden eines Teilbereichs aktivieren
            'cropWidth' => 400,                // Breite des Ausschnitts in Pixeln
            'cropHeight' => 300,               // Höhe des Ausschnitts in Pixeln
            'cropFromTopRight' => true,        // Ausschnitt von der rechten oberen Ecke aus
            
            // Niederschlagsskala-Einstellungen
            'legendEnabled' => false,          // Kopieren der Niederschlagsskala aktivieren
            'legendSourceX' => 0,              // X-Koordinate der Skala in der Basiskarte
            'legendSourceY' => 750,            // Y-Koordinate der Skala in der Basiskarte (unterer Rand)
            'legendWidth' => 270,              // Breite der Skala
            'legendHeight' => 30,              // Höhe der Skala
            'legendTargetX' => 10,             // X-Koordinate der Skala im Zielbild (links)
            'legendTargetY' => null,           // Y-Koordinate der Skala im Zielbild (null = automatisch berechnen, unten)
            
            // Animation
            'createAnimation' => true,          // Animation automatisch erstellen
            'animationFile' => 'radar_animation.gif', // Dateiname der Animation (im Ausgabeverzeichnis)
            'animationDelay' => 200,           // Verzögerung zwischen den Bildern in ms
            
            // Debug und Logging
            'debug' => true,                  // Debug-Modus aktivieren
            'debugRainDetection' => false,     // Debug-Ausgabe für Regenerkennung aktivieren
            'logFile' => '/usr/share/symcon/tile/kremsmuenster/regenradar.log',  // Log-Datei (null = Standard-Error-Log)
            'logLevel' => 'info',             // Log-Level (debug, info, warning, error)
            
            // Regenerkennungs-Einstellungen
            'rainMinBlueValue' => 150,         // Minimaler Blau-Wert für Regenerkennung
            'rainOverridesBlueOnly' => true,   // Nur blaue Farben als Regen erkennen (sonst auch Lila)
            'rainColorTolerance' => 35,        // Toleranz für Farbvergleiche (höher = mehr Toleranz)
            'rainBlueExcessFactor' => 1.5,     // Faktor um wie viel Blau größer sein muss als Rot/Grün
            'ignoreWhite' => true,             // Weiße Pixel ignorieren
            'ignoreWhiteThreshold' => 252      // Schwellwert für weiße Pixel (erhöht von 240 auf 252)
        ];
    }
    
    /**
     * Konstruktor
     * 
     * @param array $config Konfiguration der Klasse (optional)
     */
    public function __construct($config = []) {
        // Standardkonfiguration laden
        $this->config = $this->getDefaultConfig();
        
        // Benutzerkonfiguration übernehmen
        if (is_array($config)) {
            $this->config = array_merge($this->config, $config);
        }
        
        // Ausgabeverzeichnis erstellen, falls es nicht existiert
        if (!file_exists($this->config['outputDir'])) {
            mkdir($this->config['outputDir'], 0755, true);
        }
        
        // Debug-Modus aktivieren, falls gewünscht
        if ($this->config['debug']) {
            error_reporting(E_ALL);
            ini_set('display_errors', 1);
        }
        
        $this->log('WeatherRadar initialisiert mit Konfiguration: ' . json_encode($this->config, JSON_UNESCAPED_SLASHES), 'debug');
    }
    
    /**
     * Log-Nachricht schreiben
     * 
     * @param string $message Nachricht
     * @param string $level Log-Level (debug, info, warning, error)
     */
    private function log($message, $level = 'info') {
        if (!in_array($level, ['debug', 'info', 'warning', 'error'])) {
            $level = 'info';
        }
        
        // Nur loggen, wenn der Log-Level ausreichend hoch ist
        $logLevels = ['debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3];
        if ($logLevels[$level] < $logLevels[$this->config['logLevel']]) {
            return;
        }
        
        $logMessage = date('Y-m-d H:i:s') . ' [' . strtoupper($level) . '] ' . $message;
        
        if ($this->config['logFile']) {
            file_put_contents($this->config['logFile'], $logMessage . PHP_EOL, FILE_APPEND);
        } else {
            error_log($logMessage);
        }
    }
    
    /**
     * Konfiguration aktualisieren
     * 
     * @param array $config Neue Konfigurationsparameter
     * @return bool Erfolg des Vorgangs
     */
    public function setConfig($config) {
        if (!is_array($config)) {
            $this->log('Ungültige Konfiguration: Kein Array', 'error');
            return false;
        }
        
        $this->config = array_merge($this->config, $config);
        $this->log('Konfiguration aktualisiert: ' . json_encode($config, JSON_UNESCAPED_SLASHES), 'debug');
        return true;
    }
    
    /**
     * Konfiguration abrufen
     * 
     * @param string $key Konfigurationsschlüssel (optional, wenn null wird die gesamte Konfiguration zurückgegeben)
     * @return mixed Konfigurationswert oder gesamte Konfiguration
     */
    public function getConfig($key = null) {
        if ($key === null) {
            return $this->config;
        }
        
        return isset($this->config[$key]) ? $this->config[$key] : null;
    }
    
    /**
     * Herunterladen und Verarbeiten der Radarbilder
     * Mit zufälligem Namen für das animierte GIF
     * 
     * @param array $config Optionale Konfigurationsparameter für diesen Aufruf
     * @return bool Erfolg des Vorgangs
     */
    public function processRadarImages($config = []) {
        // Temporäre Konfiguration für diese Verarbeitung
        $tempConfig = $this->config;
        if (is_array($config) && !empty($config)) {
            $tempConfig = array_merge($tempConfig, $config);
        }

        // EINZELLAUF-SPERRE: verhindert ueberlappende/gestaute Laeufe (das war die Hang-Ursache).
        // Lock im Ausgabeordner; wird nach 600s als verwaist ignoriert und per Shutdown-Hook
        // auch bei Fatal entfernt. cleanOutputDirectory loescht nur *.png/*.gif, nicht den Dotfile.
        $lockDir  = rtrim((string) $tempConfig['outputDir'], '/');
        if ($lockDir !== '' && !is_dir($lockDir)) { @mkdir($lockDir, 0777, true); }
        $lockFile = $lockDir . '/.rainradar.lock';
        if (is_file($lockFile) && (time() - (int) @filemtime($lockFile)) < 600) {
            $this->log('Lauf laeuft bereits (Lock aktiv) - uebersprungen', 'warning');
            return false;
        }
        @file_put_contents($lockFile, (string) @getmypid());
        register_shutdown_function(function () use ($lockFile) { @unlink($lockFile); });

        // Zufälligen Namen für Animation generieren
        $randomAnimationName = 'radar_animation_' . uniqid() . '.gif';
        $tempConfig['animationFile'] = $randomAnimationName;
        $this->config['animationFile'] = $randomAnimationName; // auch in der Hauptkonfiguration speichern
        $this->log("Zufälliger Animationsname generiert: " . $randomAnimationName, 'debug');
        
        $hours = $tempConfig['hours'];
        $coordinates = $tempConfig['coordinates'];
        $radius = $tempConfig['radius'];
        
        // Ausgabeverzeichnis bereinigen, wenn nicht explizit deaktiviert
        if (!isset($tempConfig['cleanOutputDir']) || $tempConfig['cleanOutputDir'] === true) {
            $this->log("Bereinige Ausgabeverzeichnis vor der Verarbeitung", 'info');
            $this->cleanOutputDirectory($tempConfig['outputDir']);
            
            // Bildsequenz zurücksetzen, da wir alle Bilder gelöscht haben
            $this->imageSequence = [];
        }
        
        if ($hours <= 0) {
            $this->log('Ungültige Anzahl von Stunden: ' . $hours, 'error');
            return false;
        }
        
        if (!is_array($coordinates)) {
            $coordinates = [];
        }
        
        // Formatierte Regendaten zurücksetzen
        $this->formattedRainData = [];
        
        // Anpassung der Koordinaten für gecropte Bilder
        $adjustedCoordinates = $coordinates;
        
        // Wenn Cropping aktiviert ist, müssen die Koordinaten angepasst werden
        if ($tempConfig['cropEnabled'] && !empty($coordinates)) {
            $adjustedCoordinates = [];
            
            // Crop-Bereich bestimmen
            $cropWidth = min($tempConfig['cropWidth'], 9999); // Breite begrenzen für Sicherheit
            $cropHeight = min($tempConfig['cropHeight'], 9999); // Höhe begrenzen für Sicherheit
            
            // Basiskarte laden, um die Dimensionen zu erhalten
            $baseMapInfo = @getimagesize($tempConfig['baseMapPath']);
            if (!$baseMapInfo) {
                $this->log("Fehler beim Ermitteln der Basiskarten-Größe: " . $tempConfig['baseMapPath'], 'error');
                return false;
            }
            
            $baseWidth = $baseMapInfo[0];
            $baseHeight = $baseMapInfo[1];
            
            // Startposition für das Cropping berechnen
            if ($tempConfig['cropFromTopRight']) {
                $cropX = $baseWidth - $cropWidth;
                $cropY = 0; // Obere Kante
            } else {
                $cropX = 0;
                $cropY = 0;
            }
            
            $this->log("Crop-Bereich: x=$cropX, y=$cropY, Breite=$cropWidth, Höhe=$cropHeight", 'debug');
            
            // Koordinaten für den gecropten Bereich anpassen
            foreach ($coordinates as $index => $coord) {
                if (is_array($coord) && count($coord) >= 2) {
                    list($x, $y) = $coord;
                    
                    // Prüfen, ob der Punkt im Crop-Bereich liegt
                    if ($x >= $cropX && $x < ($cropX + $cropWidth) && 
                        $y >= $cropY && $y < ($cropY + $cropHeight)) {
                        
                        // Koordinaten relativ zum Crop-Bereich berechnen
                        $adjustedX = $x - $cropX;
                        $adjustedY = $y - $cropY;
                        
                        $adjustedCoordinates[$index] = [$adjustedX, $adjustedY];
                        $this->log("Koordinate [$x,$y] angepasst zu [$adjustedX,$adjustedY] für Crop", 'debug');
                    } else {
                        // Punkt liegt außerhalb des Crop-Bereichs
                        $this->log("Koordinate [$x,$y] liegt außerhalb des Crop-Bereichs und wird ignoriert", 'warning');
                        // Wir behalten die ursprüngliche Koordinate für die Regenanalyse,
                        // auch wenn sie im gecropten Bild nicht sichtbar ist
                        $adjustedCoordinates[$index] = $coord;
                    }
                }
            }
        }
        
        // Radius auf sinnvollen Wert beschränken
        $radius = max(1, min(50, (int)$radius));
        
        try {
            // Aktuelle Zeit bestimmen
            $now = new DateTime();
            
            // Sommerzeit prüfen
            $isSummerTime = (int)date('I');
            
            // Stunden-Offset für Radar-URL berechnen (bei Sommerzeit -2, bei Winterzeit -1)
            $hourOffset = $tempConfig['hourOffset'] !== null ? $tempConfig['hourOffset'] : ($isSummerTime ? 2 : 1);
            
            // Debug-Information
            $this->log("Starte Verarbeitung von {$hours} Radarbildern. Sommerzeit: " . ($isSummerTime ? "Ja" : "Nein") . ", Stunden-Offset: {$hourOffset}", 'info');
            
            $successCount = 0;
            
            // Basiskarte vorsorglich einmalig laden, um die Dimensionen zu erhalten
            $baseMapInfo = @getimagesize($tempConfig['baseMapPath']);
            if (!$baseMapInfo) {
                $this->log("Fehler beim Ermitteln der Basiskarten-Größe: " . $tempConfig['baseMapPath'], 'error');
                return false;
            }
            
            $baseWidth = $baseMapInfo[0];
            $baseHeight = $baseMapInfo[1];
            $this->log("Basiskartengröße: {$baseWidth}x{$baseHeight}", 'debug');
            
            // Radarbilder herunterladen und analysieren
            for ($i = 0; $i < $hours; $i++) {
                $forecastTime = clone $now;
                $forecastTime->modify("+{$i} hours");
                
                // Technische Zeit für URL berechnen
                $radarHour = (int)$forecastTime->format('H') - $hourOffset;
                $radarDay = clone $forecastTime;
                
                if ($radarHour < 0) {
                    $radarHour += 24;
                    $radarDay->modify('-1 day');
                }
                
                $dateStr = $radarDay->format('Ymd');
                $hourStr = str_pad($radarHour, 2, '0', STR_PAD_LEFT);
                $radarUrl = $tempConfig['radarBaseUrl'] . $dateStr . "_" . $hourStr . "00.gif";
                
                // Tatsächliche Zeit des Radarbildes berechnen (Technische Zeit + Offset + Sommerzeit)
                $actualRadarTime = clone $radarDay;
                $actualRadarTime->setTime($radarHour, 0);
                $actualRadarTime->modify('+' . $hourOffset . ' hours'); // Hier fügen wir den Offset wieder hinzu
                
                $technicalTimestamp = $radarDay->format('Y-m-d') . '_' . $hourStr . '-00'; // Für Dateiname
                $actualTimestamp = $actualRadarTime->format('Y-m-d H:i'); // Für Anzeige und Regendaten
                
                // Debug-Information
                $this->log("Verarbeite Radar für: " . $actualTimestamp . ", URL: " . $radarUrl, 'debug');
                
                // Radarbild herunterladen
                $radarImage = $this->downloadRadarImage($radarUrl);
                if (!$radarImage) {
                    // Vorhersage-HORIZONT erreicht: ab hier liefert die Quelle nur noch 404-
                    // Platzhalter bzw. sparsame Einzeltreffer -> das ergaebe den "immer gleichen"
                    // Tail. Nach 2 aufeinanderfolgenden Fehlschlaegen abbrechen (1 Blip toleriert),
                    // damit NUR echte, zusammenhaengende Bilder gezeigt werden (kein Loop am Ende).
                    $consecFail = (isset($consecFail) ? $consecFail : 0) + 1;
                    $this->log("Download fehlgeschlagen ({$consecFail}): " . $radarUrl, 'warning');
                    if ($consecFail >= 2) { $this->log('Vorhersage-Horizont erreicht -> Ende', 'info'); break; }
                    continue;
                }
                $consecFail = 0;
                
                // Regenintensität direkt im heruntergeladenen GIF analysieren
                $this->analyzeRainIntensity($radarImage, $actualTimestamp, $coordinates, $radius, $actualRadarTime->getTimestamp());
                
                // Bild mit Austria-Karte kombinieren und speichern
                if ($this->createCombinedImage($radarImage, $technicalTimestamp, $tempConfig, $actualTimestamp, $adjustedCoordinates)) {
                    $successCount++;
                }
                
                // Ressource freigeben
                if (is_resource($radarImage) || is_object($radarImage)) {
                    imagedestroy($radarImage);
                }
            }
            
            $this->log("Verarbeitung abgeschlossen. {$successCount} von {$hours} Bildern erfolgreich verarbeitet.", 'info');
            
            // Animation erstellen, falls gewünscht
            if ($tempConfig['createAnimation'] && $successCount > 0) {
                $result = $this->createAnimation($tempConfig['animationFile'], $tempConfig['animationDelay']);
                $this->log("Animation erstellt: " . ($result ? "Erfolgreich" : "Fehlgeschlagen"), 'info');
            }
            
            return $successCount > 0;
        } catch (Exception $e) {
            $this->log("Fehler bei der Verarbeitung der Radarbilder: " . $e->getMessage(), 'error');
            return false;
        }
    }
    
    /**
     * Verbesserte Methode zum Herunterladen eines Radarbildes
     * 
     * @param string $url URL des Radarbildes
     * @return resource|false GD-Ressource oder false bei Fehler
     */
    private function downloadRadarImage($url) {
        try {
            $this->log("Lade Radarbild herunter: {$url}", 'debug');
            
            // Initialisiere cURL-Session mit verbesserten Optionen
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);  // Verbindungsaufbau max 5s
            curl_setopt($ch, CURLOPT_TIMEOUT, 8);         // Gesamt max 8s je Bild (war 30 -> 48x30=24min Hang-Risiko)
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
            curl_setopt($ch, CURLOPT_ENCODING, ''); // Akzeptiere alle Komprimierungen
            
            // Führe Request aus
            $imageData = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            curl_close($ch);
            
            // Überprüfe, ob der Download erfolgreich war
            if ($imageData === false || $httpCode != 200) {
                $this->log("HTTP-Fehler beim Herunterladen: HTTP-Code " . $httpCode, 'warning');
                return false;
            }
            
            // Überprüfe den Content-Type (sollte image/gif sein)
            if (strpos($contentType, 'image/gif') === false && $this->config['debug']) {
                $this->log("Warnung: Unerwarteter Content-Type: {$contentType}", 'warning');
            }
            
            // Temporäre Datei erstellen
            $tempFile = tempnam(sys_get_temp_dir(), 'radar');
            file_put_contents($tempFile, $imageData);
            
            // Überprüfe Dateigröße
            $fileSize = filesize($tempFile);
            if ($fileSize < 100) {
                $this->log("Datei zu klein: {$fileSize} Bytes", 'warning');
                unlink($tempFile);
                return false;
            }
            
            $this->log("Radarbild heruntergeladen: {$fileSize} Bytes", 'debug');
            
            // Versuche Bild zu laden, mit Fehlerbehandlung
            $image = @imagecreatefromgif($tempFile);
            
            // Temporäre Datei löschen
            unlink($tempFile);
            
            if (!$image) {
                $this->log("Fehler beim Laden des GIF-Bildes", 'warning');
                return false;
            }
            
            // Überprüfe Bildgröße
            $width = imagesx($image);
            $height = imagesy($image);
            $this->log("Radarbild geladen: {$width}x{$height} Pixel", 'debug');
            
            // Wenn das Bild zu klein ist, ist es wahrscheinlich ein Fehler
            if ($width < 50 || $height < 50) {
                $this->log("Bild zu klein: {$width}x{$height} Pixel", 'warning');
                imagedestroy($image);
                return false;
            }
            
            return $image;
        } catch (Exception $e) {
            $this->log("Fehler beim Herunterladen des Radarbilds: " . $e->getMessage(), 'error');
            return false;
        }
    }
    
    /**
     * Korrigierte Methode zur Analyse der Regenintensität mit verbesserten RGB-Definitionen
     * 
     * @param resource $radarImage GD-Ressource des Radarbildes
     * @param string $timestamp Zeitstempel des Bildes
     * @param array $coordinates Array mit zu prüfenden Koordinaten [[x1,y1], [x2,y2], ...]
     * @param int $radius Radius um die Koordinaten, in dem nach Regen gesucht wird
     * @param int $unixTimestamp Unix-Timestamp des Bildes (optional)
     */
    private function analyzeRainIntensity($radarImage, $timestamp, $coordinates, $radius, $unixTimestamp = null) {
        if (!is_resource($radarImage) && !is_object($radarImage)) {
            $this->log("Ungültiges Radarbild für Analyse", 'warning');
            return;
        }
        
        // Wenn kein Unix-Timestamp übergeben wurde, versuchen wir, ihn aus dem Zeitstempel zu berechnen
        if ($unixTimestamp === null) {
            $dateTime = DateTime::createFromFormat('Y-m-d H:i', $timestamp);
            $unixTimestamp = $dateTime ? $dateTime->getTimestamp() : time();
        }
        
        // Bildgröße ermitteln
        $imageWidth = imagesx($radarImage);
        $imageHeight = imagesy($radarImage);
        
        // Debug-Ausgabe
        $this->log("Analysiere Regenintensität für Bild {$timestamp}, Größe: {$imageWidth}x{$imageHeight}", 'debug');
        
        // EXAKTE REGENDEFINITIONEN mit RGB-Werten und Intensitäten - KORRIGIERT
        // Array-Schlüssel als Strings, nicht als Arrays
        $rainDefinitions = [
            // RGB-Wert => [min_intensity, max_intensity, description]
            '210,233,255' => [0.1, 0.4, "Sehr leichter Regen"],
            '166,204,253' => [0.5, 1.0, "Leichter Regen"],
            '140,153,253' => [1.0, 2.99, "Mäßiger Regen"],
            '115,102,254' => [3.0, 4.99, "Regen"],
            '88,51,254'   => [5.0, 9.99, "Regen"],
            '53,0,183'    => [10.0, 14.99, "Stärkerer Regen"],
            '112,31,128'  => [15.0, 19.99, "Starker Regen"],
            '140,17,170'  => [20.0, 50.0, "Sehr starker Regen"]
        ];
        
        // GIF-Bild in ein True-Color-Bild konvertieren
        // Dies garantiert korrekte RGB-Werte
        $trueColorImage = imagecreatetruecolor($imageWidth, $imageHeight);
        imagealphablending($trueColorImage, true);
        imagesavealpha($trueColorImage, true);
        imagecopy($trueColorImage, $radarImage, 0, 0, 0, 0, $imageWidth, $imageHeight);
        
        // Debug-Bild erstellen, falls aktiviert
        $debugEnabled = $this->config['debug'] && isset($this->config['debugRainDetection']) && $this->config['debugRainDetection'];
        $debugImage = null;
        
        if ($debugEnabled) {
            $debugImage = imagecreatetruecolor($imageWidth, $imageHeight);
            $white = imagecolorallocate($debugImage, 255, 255, 255);
            imagefill($debugImage, 0, 0, $white);
            
            // Original-GIF in Debug-Bild kopieren für bessere Visualisierung
            imagecopy($debugImage, $radarImage, 0, 0, 0, 0, $imageWidth, $imageHeight);
        }
        
        // Für jede Koordinate den Bereich prüfen
        foreach ($coordinates as $index => $coord) {
            if (!is_array($coord) || count($coord) < 2) {
                $this->log("Ungültige Koordinaten für Index {$index}: " . json_encode($coord), 'warning');
                continue;
            }
            
            list($x, $y) = $coord;
            $hasRain = false;
            $maxIntensity = 0;
            $maxIntensityMmh = 0; // Maximale Regenintensität in mm/h
            $rainPixelCount = 0;
            $totalPixelCount = 0;
            $maxIntensityPixel = [0, 0, 0]; // [r, g, b] für den Pixel mit der höchsten Intensität
            $maxIntensityDesc = ""; // Beschreibung der max. Intensität
            
            $this->log("Prüfe Koordinate [{$x},{$y}] mit Radius {$radius}", 'debug');
            
            // Rechteck um die Koordinate definieren für die Suche
            $startX = max(0, $x - $radius);
            $endX = min($imageWidth - 1, $x + $radius);
            $startY = max(0, $y - $radius);
            $endY = min($imageHeight - 1, $y + $radius);
            
            // Bereich um die Koordinaten durchlaufen
            for ($px = $startX; $px <= $endX; $px++) {
                for ($py = $startY; $py <= $endY; $py++) {
                    $totalPixelCount++;
                    
                    // Nur Pixel innerhalb des Kreises betrachten (Pythagoräischer Satz)
                    $distSquared = ($px - $x) * ($px - $x) + ($py - $y) * ($py - $y);
                    if ($distSquared > $radius * $radius) {
                        continue;
                    }
                    
                    // RGB-Wert des Pixels korrekt auslesen
                    $rgb = imagecolorat($trueColorImage, (int)$px, (int)$py);
                    
                    // RGB-Komponenten extrahieren
                    $r = ($rgb >> 16) & 0xFF;
                    $g = ($rgb >> 8) & 0xFF;
                    $b = $rgb & 0xFF;
                    
                    // Völlig weiße Pixel ignorieren
                    if ($r > 250 && $g > 250 && $b > 250) {
                        continue;
                    }
                    
                    // Prüfen, welcher Regendefinition dieser Pixel am besten entspricht
                    $isRain = false;
                    $bestMatch = null;
                    $bestMatchDistance = PHP_INT_MAX;
                    $intensityMmh = 0;
                    $intensityDesc = "";
                    
                    // Distanz zu jeder definierten Regenfarbe berechnen
                    foreach ($rainDefinitions as $rainRgb => $rainInfo) {
                        list($rainR, $rainG, $rainB) = explode(',', $rainRgb);
                        
                        // Euklidische Distanz im RGB-Farbraum
                        $distance = sqrt(
                            pow($r - $rainR, 2) + 
                            pow($g - $rainG, 2) + 
                            pow($b - $rainB, 2)
                        );
                        
                        // Toleranz von 30 für Farbabgleich
                        if ($distance < 30 && $distance < $bestMatchDistance) {
                            $bestMatchDistance = $distance;
                            $bestMatch = $rainInfo;
                            $isRain = true;
                        }
                    }
                    
                    // Alternative für Blautöne, die keiner exakten Definition entsprechen
                    if (!$isRain && $b > max($r, $g) + 5) {
                        $isRain = true;
                        
                        // Je nach Blauanteil eine Intensität zuweisen
                        if ($b > 200) {
                            // Helles Blau - sehr leichter Regen
                            $intensityMmh = 0.2;
                            $intensityDesc = "Sehr leichter Regen";
                        } else if ($b > 150) {
                            // Mittleres Blau - leichter Regen
                            $intensityMmh = 0.7;
                            $intensityDesc = "Leichter Regen";
                        } else {
                            // Dunkleres Blau - mäßiger Regen
                            $intensityMmh = 1.5;
                            $intensityDesc = "Mäßiger Regen";
                        }
                    } else if ($isRain && $bestMatch) {
                        // Intensität vom besten Match nehmen (Mittelpunkt des Bereichs)
                        $intensityMmh = ($bestMatch[0] + $bestMatch[1]) / 2;
                        $intensityDesc = $bestMatch[2];
                    }
                    
                    if ($isRain) {
                        $hasRain = true;
                        $rainPixelCount++;
                        
                        // Wenn dieser Pixel die höchste Intensität hat, speichern wir ihn
                        if ($intensityMmh > $maxIntensityMmh) {
                            $maxIntensityMmh = $intensityMmh;
                            $maxIntensityDesc = $intensityDesc;
                            $maxIntensityPixel = [$r, $g, $b];
                            
                            // Für die Speicherung im rainData-Array: Skalieren auf 0-100
                            // Die höchste erwartete Intensität ist ~20 mm/h
                            $maxIntensity = min(100, $intensityMmh * 5);
                        }
                        
                        // Debug-Visualisierung, falls aktiviert
                        if ($debugEnabled && $debugImage) {
                            // Farbe für Debug-Visualisierung basierend auf mm/h
                            if ($intensityMmh < 1) {
                                $markColor = imagecolorallocate($debugImage, 0, 255, 0); // Grün: Sehr leicht
                            } else if ($intensityMmh < 3) {
                                $markColor = imagecolorallocate($debugImage, 100, 255, 0); // Hellgrün: Leicht
                            } else if ($intensityMmh < 5) {
                                $markColor = imagecolorallocate($debugImage, 255, 255, 0); // Gelb: Mittel
                            } else if ($intensityMmh < 10) {
                                $markColor = imagecolorallocate($debugImage, 255, 128, 0); // Orange: Stark
                            } else {
                                $markColor = imagecolorallocate($debugImage, 255, 0, 0);   // Rot: Sehr stark
                            }
                            
                            // Erkannten Regenpixel markieren
                            imagesetpixel($debugImage, (int)$px, (int)$py, $markColor);
                        }
                    }
                }
            }
            
            // Regenintensität für diese Koordinaten speichern (altes Format)
            if (!isset($this->rainData[$index])) {
                $this->rainData[$index] = [];
            }
            
            // WICHTIG: Wir verwenden die höchste erkannte Intensität
            $this->rainData[$index][$timestamp] = $hasRain ? $maxIntensity : 0;
            
            // Speichern im neuen Format für die formatierte Ausgabe
            if (!isset($this->formattedRainData[$index])) {
                $this->formattedRainData[$index] = [];
            }
            
            // Neues Datenformat mit timestamp, time und intensity
            $this->formattedRainData[$index][] = [
                'timestamp' => $unixTimestamp,
                'time' => date("d.m.Y-H:i", $unixTimestamp),
                'intensity' => $hasRain ? $maxIntensity : 0,
                'description' => $hasRain ? $maxIntensityDesc : "Kein Regen"
            ];
            
            // Debug-Ausgabe
            if ($hasRain) {
                $percentage = ($rainPixelCount / $totalPixelCount) * 100;
                $this->log("Regen erkannt an Koordinate {$index}: Höchste Intensität {$maxIntensityMmh} mm/h ({$maxIntensityDesc}), " .
                         "RGB: " . implode(",", $maxIntensityPixel) . ", " .
                         "{$rainPixelCount} von {$totalPixelCount} Pixeln ({$percentage}%)", 'debug');
                
                // Markiere die Koordinate im Debug-Bild mit einem Kreis und Kreuz
                if ($debugEnabled && $debugImage) {
                    // Farbe abhängig von der Intensität in mm/h
                    if ($maxIntensityMmh < 1) {
                        $markColor = imagecolorallocate($debugImage, 0, 255, 0); // Grün: Sehr leicht
                    } else if ($maxIntensityMmh < 3) {
                        $markColor = imagecolorallocate($debugImage, 100, 255, 0); // Hellgrün: Leicht
                    } else if ($maxIntensityMmh < 5) {
                        $markColor = imagecolorallocate($debugImage, 255, 255, 0); // Gelb: Mittel
                    } else if ($maxIntensityMmh < 10) {
                        $markColor = imagecolorallocate($debugImage, 255, 128, 0); // Orange: Stark
                    } else {
                        $markColor = imagecolorallocate($debugImage, 255, 0, 0);   // Rot: Sehr stark
                    }
                    
                    // Kreuz zeichnen
                    imageline($debugImage, (int)($x - 5), (int)$y, (int)($x + 5), (int)$y, $markColor);
                    imageline($debugImage, (int)$x, (int)($y - 5), (int)$x, (int)($y + 5), $markColor);
                    
                    // Kreis zeichnen
                    imageellipse($debugImage, (int)$x, (int)$y, (int)($radius * 2), (int)($radius * 2), $markColor);
                    
                    // Beschriftung zur Intensität hinzufügen (mm/h)
                    $intensityText = sprintf("%.1f mm/h", $maxIntensityMmh);
                    imagestring($debugImage, 2, (int)($x + $radius + 2), (int)($y - 6), $intensityText, $markColor);
                }
            } else {
                $this->log("Kein Regen erkannt an Koordinate {$index}", 'debug');
                
                // Markiere die Koordinate im Debug-Bild
                if ($debugEnabled && $debugImage) {
                    $markColor = imagecolorallocate($debugImage, 0, 0, 0); // Schwarz für "kein Regen"
                    
                    // Kreis zeichnen
                    imageellipse($debugImage, (int)$x, (int)$y, (int)($radius * 2), (int)($radius * 2), $markColor);
                    
                    // Kreuz zeichnen
                    imageline($debugImage, (int)($x - 5), (int)$y, (int)($x + 5), (int)$y, $markColor);
                    imageline($debugImage, (int)$x, (int)($y - 5), (int)$x, (int)($y + 5), $markColor);
                }
            }
            
            // Debug-Bild speichern, falls aktiviert
            if ($debugEnabled && $debugImage) {
                $debugDir = $this->config['outputDir'] . '/debug';
                if (!file_exists($debugDir)) {
                    mkdir($debugDir, 0755, true);
                }
                
                // Zeitstempel zum Debug-Bild hinzufügen
                $black = imagecolorallocate($debugImage, 0, 0, 0);
                imagestring($debugImage, 3, 10, $imageHeight - 20, $timestamp, $black);
                
                // Debug-Bild speichern
                $debugFile = $debugDir . '/rain_detection_' . str_replace([':', ' '], ['_', '_'], $timestamp) . '.png';
                imagepng($debugImage, $debugFile);
                imagedestroy($debugImage);
                $this->log("Debug-Bild der Regenerkennung gespeichert: {$debugFile}", 'debug');
            }
        }
        
        // Aufräumen
        if (isset($trueColorImage) && (is_resource($trueColorImage) || is_object($trueColorImage))) {
            imagedestroy($trueColorImage);
        }
    }
    
    /**
     * Erstellen eines kombinierten Bildes aus Basiskarte und Radarbild
     * Mit korrigierter Markierung und Skalen-Anzeige
     * Version 2.3.8: Verbesserte Darstellung der Datumsausgabe mit Regenintensitätsanzeige
     * 
     * @param resource $radarImage GD-Ressource des Radarbildes
     * @param string $timestamp Zeitstempel für den Dateinamen
     * @param array $config Konfiguration für die Bildbearbeitung
     * @param string $actualTimestamp Tatsächlicher Zeitstempel des Radarbildes (optional)
     * @param array $adjustedCoordinates Angepasste Koordinaten für die Marker (optional)
     * @return bool Erfolg des Vorgangs
     */
    private function createCombinedImage($radarImage, $timestamp, $config, $actualTimestamp = null, $adjustedCoordinates = null) {
        if (!is_resource($radarImage) && !is_object($radarImage)) {
            $this->log("Ungültiges Radarbild für Kombination", 'warning');
            return false;
        }
        
        try {
            // SCHRITT 1: Basiskarte laden
            $baseMap = @imagecreatefrompng($config['baseMapPath']);
            if (!$baseMap) {
                $this->log("Fehler beim Laden der Basiskarte: " . $config['baseMapPath'], 'error');
                return false;
            }
            
            // Dimensionen ermitteln
            $baseWidth = imagesx($baseMap);
            $baseHeight = imagesy($baseMap);
            $radarWidth = imagesx($radarImage);
            $radarHeight = imagesy($radarImage);
            
            $this->log("Basiskarte: {$baseWidth}x{$baseHeight}, Radarbild: {$radarWidth}x{$radarHeight}", 'debug');
            
            // SCHRITT 2: Neues Bild mit korrekten Alpha-Eigenschaften erstellen
            $finalImage = imagecreatetruecolor($baseWidth, $baseHeight);
            imagealphablending($finalImage, false); // Wichtig: Zuerst alpha blending ausschalten
            imagesavealpha($finalImage, true);  // Alpha-Kanal speichern aktivieren
            
            // Transparent füllen
            $transparent = imagecolorallocatealpha($finalImage, 0, 0, 0, 127);
            imagefill($finalImage, 0, 0, $transparent);
            
            // SCHRITT 3: Basiskarte in das neue Bild kopieren
            imagecopy($finalImage, $baseMap, 0, 0, 0, 0, $baseWidth, $baseHeight);
            
            // SCHRITT 4: Radarbild skalieren, falls notwendig
            $scaledRadar = null; // Vorinitialisieren
            
            if ($baseWidth != $radarWidth || $baseHeight != $radarHeight) {
                $scaledRadar = imagecreatetruecolor($baseWidth, $baseHeight);
                
                // Transparenzeinstellungen für das skalierte Bild
                imagealphablending($scaledRadar, false);
                imagesavealpha($scaledRadar, true);
                
                // Temporär mit Transparenz füllen
                imagefill($scaledRadar, 0, 0, $transparent);
                
                // Radarbild skalieren
                imagecopyresampled($scaledRadar, $radarImage, 
                                  0, 0, 0, 0, 
                                  $baseWidth, $baseHeight, 
                                  $radarWidth, $radarHeight);
                
                // Skaliertes Radarbild weiterverarbeiten
                $radarToUse = $scaledRadar;
            } else {
                // Originalradarbild verwenden
                $radarToUse = $radarImage;
            }
            
            // SCHRITT 5: Alpha-Blending für Überlagerung aktivieren
            imagealphablending($finalImage, true);
            
            // SCHRITT 6: Radarbild über Basiskarte legen
            imagecopy($finalImage, $radarToUse, 0, 0, 0, 0, $baseWidth, $baseHeight);
            
            // SCHRITT 7: Cropping durchführen falls gewünscht
            $finalWidth = $baseWidth;
            $finalHeight = $baseHeight;
            $cropX = 0;
            $cropY = 0;
            
            if ($config['cropEnabled']) {
                $cropWidth = min($config['cropWidth'], $finalWidth);
                $cropHeight = min($config['cropHeight'], $finalHeight);
                
                // Crop-Position bestimmen
                if ($config['cropFromTopRight']) {
                    $cropX = $finalWidth - $cropWidth;
                    $cropY = 0;
                }
                
                // Neues Bild für den Ausschnitt
                $croppedImage = imagecreatetruecolor($cropWidth, $cropHeight);
                
                // Transparenzunterstützung für das gecropte Bild
                imagealphablending($croppedImage, false);
                imagesavealpha($croppedImage, true);
                
                // Ausschnitt kopieren
                imagecopy($croppedImage, $finalImage, 0, 0, $cropX, $cropY, $cropWidth, $cropHeight);
                
                // Altes Bild freigeben
                imagedestroy($finalImage);
                
                // Gecroptes Bild wird neues finales Bild
                $finalImage = $croppedImage;
                $finalWidth = $cropWidth;
                $finalHeight = $cropHeight;
                
                $this->log("Bild auf {$cropWidth}x{$cropHeight} Pixel zugeschnitten", 'debug');
                
                // SCHRITT 8: Niederschlagsskala hinzufügen, NUR wenn Cropping aktiviert ist
                if ($config['legendEnabled']) {
                    // Legende aus Basiskarte kopieren
                    $legendSourceX = $config['legendSourceX'];
                    $legendSourceY = $config['legendSourceY'];
                    $legendWidth = $config['legendWidth'];
                    $legendHeight = $config['legendHeight'];
                    
                    // Zielposition bestimmen
                    $legendTargetX = $config['legendTargetX'];
                    $legendTargetY = $config['legendTargetY'] === null 
                                   ? $finalHeight - $legendHeight - 5 
                                   : $config['legendTargetY'];
                    
                    // Alpha-Blending aktivieren für korrekte Überlagerung
                    imagealphablending($finalImage, true);
                    
                    // Legende in das finale Bild kopieren
                    imagecopy(
                        $finalImage,
                        $baseMap,
                        $legendTargetX,
                        $legendTargetY,
                        $legendSourceX,
                        $legendSourceY,
                        $legendWidth,
                        $legendHeight
                    );
                    
                    $this->log("Niederschlagsskala eingefügt", 'debug');
                }
            }
            
            // SCHRITT 9: Marker an den angepassten Koordinaten zeichnen - mit Kreis
            if ($config['drawMarkers'] && !empty($adjustedCoordinates)) {
                $markerColor = $this->allocateColor($finalImage, $config['markerColor']);
                $markerSize = $config['markerSize'];
                $halfSize = floor($markerSize / 2);
                $radius = isset($config['radius']) ? $config['radius'] : 5; // Radius aus Config verwenden
                
                foreach ($adjustedCoordinates as $coord) {
                    if (is_array($coord) && count($coord) >= 2) {
                        list($x, $y) = $coord;
                        
                        // Nur zeichnen, wenn innerhalb des Bildes
                        if ($x >= 0 && $x < $finalWidth && $y >= 0 && $y < $finalHeight) {
                            // Kreuz zeichnen
                            imageline($finalImage, $x - $halfSize, $y, $x + $halfSize, $y, $markerColor);
                            imageline($finalImage, $x, $y - $halfSize, $x, $y + $halfSize, $markerColor);
                            
                            // Kreis um die Koordinate zeichnen mit exakt dem Radius aus den Koordinaten
                            // Der Kreis hat den gleichen Mittelpunkt wie das Kreuz und verwendet auch die gleiche Farbe
                            imageellipse($finalImage, $x, $y, $radius * 2, $radius * 2, $markerColor);
                        }
                    }
                }
            }
            
            // SCHRITT 10: Datum und Uhrzeit hinzufügen mit Regenintensität
            if ($config['showDateTime']) {
                $dateText = '';
                
                // Zeitstempel bestimmen
                if ($actualTimestamp && strpos($actualTimestamp, ' ') !== false) {
                    $dateTime = DateTime::createFromFormat('Y-m-d H:i', $actualTimestamp);
                    if ($dateTime) {
                        $dateText = $dateTime->format($config['dateFormat']);
                    }
                }
                
                // Fallbacks
                if (empty($dateText)) {
                    $dateTime = DateTime::createFromFormat('Y-m-d_H-i', $timestamp);
                    if ($dateTime) {
                        $dateText = $dateTime->format($config['dateFormat']);
                    } else {
                        $dateText = date($config['dateFormat']);
                    }
                }
                
                // Regenintensität für den aktuellen Zeitstempel abrufen
                $rainIntensity = 0;
                
                // Suche in den Regendaten nach dem aktuellen Zeitstempel
                if (!empty($this->rainData) && !empty($actualTimestamp)) {
                    // Überprüfe alle Koordinaten und finde die höchste Intensität
                    foreach ($this->rainData as $coordIndex => $coordData) {
                        if (isset($coordData[$actualTimestamp])) {
                            $intensity = $coordData[$actualTimestamp];
                            if ($intensity > $rainIntensity) {
                                $rainIntensity = $intensity;
                            }
                        }
                    }
                }
                
                // Größere Hintergrundbox für Text und ggf. Regenindikator
                $backgroundWidth = 200; // Breiter für mehr Text und Indikator
                $backgroundHeight = 25; // Höher für größeren Text
                
                // Hintergrund für den Text
                $textBgColor = $this->allocateColor($finalImage, $config['dateBgColor']);
                imagefilledrectangle($finalImage, 5, 5, 5 + $backgroundWidth, 5 + $backgroundHeight, $textBgColor);
                
                // Text zeichnen (weiß)
                $whiteTextColor = $this->allocateColor($finalImage, [255, 255, 255]);
                list($x, $y) = $config['datePosition'];
                
                // Größere Schriftgröße verwenden (5 ist das Maximum für imagestring)
                $fontSize = 5; // Maximum für imagestring
                imagestring($finalImage, $fontSize, $x, $y, $dateText, $whiteTextColor);
                
                // Wenn Regenintensität > 0, Quadrat und Wert zeichnen
                if ($rainIntensity > 0) {
                    // Position nach dem Text
                    $textWidth = strlen($dateText) * imagefontwidth($fontSize);
                    $squareX = $x + $textWidth + 10;
                    $squareY = $y;
                    $squareSize = 12; // Größe des Quadrats
                    
                    // Farbe basierend auf Regenintensität
                    $hexColor = $this->getRainColor($rainIntensity);
                    
                    // RGB-Farbe aus dem Hexwert extrahieren
                    $rgb = $this->hexToRgb($hexColor);
                    $colorIndex = $this->allocateColor($finalImage, $rgb);
                    
                    // Quadrat zeichnen
                    imagefilledrectangle($finalImage, $squareX, $squareY, $squareX + $squareSize, $squareY + $squareSize, $colorIndex);
                    
                    // Rahmen um das Quadrat
                    $blackColor = $this->allocateColor($finalImage, [0, 0, 0]);
                    imagerectangle($finalImage, $squareX, $squareY, $squareX + $squareSize, $squareY + $squareSize, $blackColor);
                    
                    // Intensitätswert als Text nach dem Quadrat
                    $intensityText = round($rainIntensity);
                    $textX = $squareX + $squareSize + 5;
                    imagestring($finalImage, $fontSize, $textX, $squareY, $intensityText, $whiteTextColor);
                }
            }
            
            // SCHRITT 11: Bild speichern
            $randomName = uniqid('radar_') . '.' . $config['imageFormat'];
            $filePath = $config['outputDir'] . '/' . $randomName;
            
            // Ausgabeverzeichnis anlegen falls notwendig
            if (!file_exists($config['outputDir'])) {
                mkdir($config['outputDir'], 0755, true);
            }
            
            // Bild speichern
            $saved = false;
            if ($config['imageFormat'] == 'jpg' || $config['imageFormat'] == 'jpeg') {
                $saved = imagejpeg($finalImage, $filePath, $config['jpgQuality']);
            } else {
                $saved = imagepng($finalImage, $filePath);
            }
            
            if (!$saved) {
                $this->log("Fehler beim Speichern des kombinierten Bildes: " . $filePath, 'error');
                
                // Ressourcen aufräumen
                imagedestroy($finalImage);
                imagedestroy($baseMap);
                if (isset($scaledRadar) && (is_resource($scaledRadar) || is_object($scaledRadar))) {
                    imagedestroy($scaledRadar);
                }
                
                return false;
            }
            
            // SCHRITT 12: Bildsequenz aktualisieren
            $this->imageSequence[] = [
                'timestamp' => $actualTimestamp ?: $timestamp,
                'filename' => $randomName
            ];
            
            // SCHRITT 13: Ressourcen aufräumen
            imagedestroy($finalImage);
            imagedestroy($baseMap);
            if (isset($scaledRadar) && (is_resource($scaledRadar) || is_object($scaledRadar))) {
                imagedestroy($scaledRadar);
            }
            
            return true;
        } catch (Exception $e) {
            $this->log("Fehler beim Erstellen des kombinierten Bildes: " . $e->getMessage(), 'error');
            return false;
        }
    }
    
    /**
     * Konvertiert einen Hex-Farbwert zu RGB
     * 
     * @param string $hex Hex-Farbwert (z.B. '#ff0000')
     * @return array RGB-Werte als Array [r, g, b]
     */
    private function hexToRgb($hex) {
        // Wenn es sich um einen RGB-String handelt
        if (strpos($hex, 'rgb(') === 0) {
            // RGB-Werte aus dem String extrahieren
            $matches = [];
            preg_match('/rgb\((\d+),(\d+),(\d+)\)/', $hex, $matches);
            if (count($matches) === 4) {
                return [intval($matches[1]), intval($matches[2]), intval($matches[3])];
            }
            // Bei Fehler Schwarz zurückgeben
            return [0, 0, 0];
        }
        
        // RGBA-Format behandeln
        if (strpos($hex, 'rgba(') === 0) {
            $matches = [];
            preg_match('/rgba\((\d+),(\d+),(\d+),([0-9.]+)\)/', $hex, $matches);
            if (count($matches) === 5) {
                $alpha = round((1 - floatval($matches[4])) * 127);
                return [intval($matches[1]), intval($matches[2]), intval($matches[3]), $alpha];
            }
            return [0, 0, 0, 127];
        }
        
        // Originaler Code für Hex-Werte
        $hex = ltrim($hex, '#');
        
        // Hex in RGB umwandeln
        if (strlen($hex) == 3) {
            // Kurze Schreibweise (#RGB)
            $r = hexdec(substr($hex, 0, 1) . substr($hex, 0, 1));
            $g = hexdec(substr($hex, 1, 1) . substr($hex, 1, 1));
            $b = hexdec(substr($hex, 2, 1) . substr($hex, 2, 1));
        } else {
            // Lange Schreibweise (#RRGGBB)
            $r = hexdec(substr($hex, 0, 2));
            $g = hexdec(substr($hex, 2, 2));
            $b = hexdec(substr($hex, 4, 2));
        }
        
        return [$r, $g, $b];
    }
    
    /**
     * Hilfsfunktion zum Allokieren einer Farbe in einem Bild
     * 
     * @param resource $image GD-Ressource des Bildes
     * @param array $color Farbe als Array [r, g, b] oder [r, g, b, a]
     * @return int Farbe-Index
     */
    private function allocateColor($image, $color) {
        if (count($color) == 4) {
            return imagecolorallocatealpha($image, $color[0], $color[1], $color[2], $color[3]);
        } else {
            return imagecolorallocate($image, $color[0], $color[1], $color[2]);
        }
    }
    
    /**
     * Erstellen eines animierten GIFs aus den Sequenzbildern
     * 
     * @param string $outputFile Pfad zur Ausgabedatei (relativ oder absolut)
     * @param int $delay Verzögerung zwischen den Bildern in Millisekunden
     * @return bool Erfolg des Vorgangs
     */
    public function createAnimation($outputFile = null, $delay = null) {
        // Standardwerte aus der Konfiguration verwenden, wenn nicht explizit angegeben
        if ($outputFile === null) {
            $outputFile = $this->config['animationFile'];
        }
        
        if ($delay === null) {
            $delay = $this->config['animationDelay'];
        }
        
        if (empty($this->imageSequence)) {
            $this->log("Keine Bilder für Animation vorhanden", 'warning');
            return false;
        }
        
        // Sicherstellen, dass die Sequenz nach Zeitstempel sortiert ist
        usort($this->imageSequence, function($a, $b) {
            return strcmp($a['timestamp'], $b['timestamp']);
        });
        
        // Wenn der Pfad nicht absolut ist, im Ausgabeverzeichnis speichern
        if (!preg_match('~^(/|[a-zA-Z]:)~', $outputFile)) {
            $outputFile = rtrim($this->config['outputDir'], '/') . '/' . $outputFile;
        }
        
        $this->log("Erstelle Animation: $outputFile", 'info');
        
        // Dateiliste für ImageMagick zusammenstellen
        $files = [];
        foreach ($this->imageSequence as $item) {
            $files[] = $this->config['outputDir'] . '/' . $item['filename'];
        }
        
        // Sicherheitscheck für Shell-Befehle
        $safeFiles = array_map('escapeshellarg', $files);
        $safeOutput = escapeshellarg($outputFile);
        $safeDelay = (int)($delay / 10);
        
        // Kommando für Animation erstellen
        // Wichtig: -dispose previous für korrekte Überlagerung
        $convertCommand = "convert -dispose previous -delay " . $safeDelay . " " . implode(' ', $safeFiles) . " " . $safeOutput;
        
        try {
            $this->log("Führe ImageMagick-Befehl aus: " . $convertCommand, 'debug');
            exec($convertCommand, $output, $returnVar);
            
            if ($returnVar !== 0) {
                $this->log("Fehler bei der Ausführung von ImageMagick: " . implode("\n", $output), 'error');
                return false;
            }
            
            // Prüfen, ob die Animation erstellt wurde
            if (!file_exists($outputFile)) {
                $this->log("Animation wurde nicht erstellt: $outputFile", 'error');
                return false;
            }
            
            $this->log("Animation erfolgreich erstellt: $outputFile (" . count($files) . " Bilder)", 'info');
            return true;
        } catch (Exception $e) {
            $this->log("Fehler beim Erstellen der Animation: " . $e->getMessage(), 'error');
            return false;
        }
    }
    
    /**
     * Abrufen der Regendaten im neuen Format
     * 
     * @param int $coordinateIndex Index der Koordinaten im Array (optional)
     * @return array Formatierte Regendaten
     */
    public function getRain($coordinateIndex = null) {
        // Wenn noch keine formatierten Daten vorliegen, aber alte Daten vorhanden sind,
        // konvertieren wir diese einmalig
        if (empty($this->formattedRainData) && !empty($this->rainData)) {
            $this->convertRainDataToFormatted();
        }
        
        if ($coordinateIndex !== null && isset($this->formattedRainData[$coordinateIndex])) {
            return $this->formattedRainData[$coordinateIndex];
        }
        
        return $this->formattedRainData;
    }
    
    /**
     * Konvertiert alte Regendaten zum neuen formatierten Format
     */
    private function convertRainDataToFormatted() {
        foreach ($this->rainData as $index => $timeseries) {
            if (!isset($this->formattedRainData[$index])) {
                $this->formattedRainData[$index] = [];
            }
            
            foreach ($timeseries as $timestamp => $intensity) {
                $dateTime = DateTime::createFromFormat('Y-m-d H:i', $timestamp);
                if (!$dateTime) {
                    continue; // Ungültiger Zeitstempel
                }
                
                $unixTimestamp = $dateTime->getTimestamp();
                
                // Beschreibung basierend auf Intensität bestimmen
                $description = "Kein Regen";
                if ($intensity > 0) {
                    if ($intensity < 0.5) {
                        $description = "Sehr leichter Regen";
                    } else if ($intensity < 1) {
                        $description = "Leichter Regen";
                    } else if ($intensity < 3) {
                        $description = "Mäßiger Regen";
                    } else if ($intensity < 5) {
                        $description = "Regen";
                    } else if ($intensity < 10) {
                        $description = "Intensiver Regen";
                    } else if ($intensity < 15) {
                        $description = "Starker Regen";
                    } else if ($intensity < 20) {
                        $description = "Sehr Starker Regen";
                    } else {
                        $description = "Extremer Regen";
                    }
                }
                
                $this->formattedRainData[$index][] = [
                    'timestamp' => $unixTimestamp,
                    'time' => date("d.m.Y H:i", $unixTimestamp),
                    'intensity' => $intensity,
                    'description' => $description
                ];
            }
            
            // Nach Zeitstempel sortieren
            usort($this->formattedRainData[$index], function($a, $b) {
                return $a['timestamp'] - $b['timestamp'];
            });
        }
    }
    
    /**
     * Abrufen der Bildsequenz
     * 
     * @return array Bild-Sequenz
     */
    public function getImageSequence() {
        return $this->imageSequence;
    }
    
    /**
     * Bereinigen des Ausgabeverzeichnisses
     * Mit expliziter Löschung aller Animation-GIFs
     * 
     * @param string $directory Das zu bereinigende Verzeichnis
     * @param array $filePatterns Array mit Dateimuster, die gelöscht werden sollen (z.B. ['*.png', '*.jpg'])
     * @return int Anzahl der gelöschten Dateien
     */
    public function cleanOutputDirectory($directory = null, $filePatterns = ['*.png', '*.jpg', '*.jpeg', '*.gif']) {
        if ($directory === null) {
            $directory = $this->config['outputDir'];
        }
        
        // Sicherstellen, dass das Verzeichnis existiert
        if (!file_exists($directory) || !is_dir($directory)) {
            $this->log("Ausgabeverzeichnis nicht gefunden: $directory", 'warning');
            return 0;
        }
        
        // Basiskarte schützen - Dateinamen aus dem Pfad extrahieren
        $baseMapFile = basename($this->config['baseMapPath']);
        $this->log("Basiskarte wird geschützt: $baseMapFile", 'debug');
        
        $count = 0;
        
        // Explizit nach animierten GIFs suchen und löschen
        $oldAnimations = glob($directory . '/*animation*.gif');
        foreach ($oldAnimations as $oldAnimation) {
            if (is_file($oldAnimation)) {
                if (unlink($oldAnimation)) {
                    $count++;
                    $this->log("Alte Animation gelöscht: " . basename($oldAnimation), 'debug');
                } else {
                    $this->log("Fehler beim Löschen der Animation: " . $oldAnimation, 'warning');
                }
            }
        }
        
        // Alle Dateien im Verzeichnis durchlaufen
        foreach ($filePatterns as $pattern) {
            $files = glob($directory . '/' . $pattern);
            
            if (!empty($files)) {
                foreach ($files as $file) {
                    // Prüfen, ob es sich um die Basiskarte handelt
                    $fileName = basename($file);
                    if ($fileName === $baseMapFile) {
                        $this->log("Basiskarte wird übersprungen: $file", 'debug');
                        continue; // Basiskarte nicht löschen
                    }
                    
                    if (is_file($file)) {
                        if (unlink($file)) {
                            $count++;
                        } else {
                            $this->log("Fehler beim Löschen der Datei: $file", 'warning');
                        }
                    }
                }
            }
        }
        
        $this->log("$count Dateien aus dem Ausgabeverzeichnis gelöscht", 'info');
        return $count;
    }

    /**
     * Debug-Informationen ausgeben
     * 
     * @return array Debug-Informationen
     */
    public function getDebugInfo() {
        $baseMapPath = $this->config['baseMapPath'];
        $baseMapExists = file_exists($baseMapPath);
        $baseMapSize = $baseMapExists ? filesize($baseMapPath) : 0;
        $baseMapReadable = $baseMapExists ? is_readable($baseMapPath) : false;
        
        $outputDir = $this->config['outputDir'];
        $outputDirExists = file_exists($outputDir);
        $outputDirWritable = $outputDirExists ? is_writable($outputDir) : false;
        
        $gdInfo = function_exists('gd_info') ? gd_info() : ['GD Version' => 'Nicht verfügbar'];
        
        $phpVersion = phpversion();
        $maxExecutionTime = ini_get('max_execution_time');
        $memoryLimit = ini_get('memory_limit');
        
        return [
            'version' => '2.3.8',
            'php_version' => $phpVersion,
            'max_execution_time' => $maxExecutionTime,
            'memory_limit' => $memoryLimit,
            'base_map' => [
                'path' => $baseMapPath,
                'exists' => $baseMapExists,
                'size' => $baseMapSize,
                'readable' => $baseMapReadable
            ],
            'output_dir' => [
                'path' => $outputDir,
                'exists' => $outputDirExists,
                'writable' => $outputDirWritable
            ],
            'gd_info' => $gdInfo,
            'rain_data_count' => count($this->rainData),
            'image_sequence_count' => count($this->imageSequence),
            'config' => $this->config
        ];
    }
    
    /**
     * Erzeugt eine natürlichsprachige Wettervorhersage basierend auf den Regendaten
     * 
     * @param int $coordinateIndex Index der Koordinaten (optional, Standard: 0)
     * @param int $hours Anzahl der Stunden, die für die Vorhersage berücksichtigt werden sollen (optional, Standard: 24)
     * @return string Textuelle Wettervorhersage
     */
    public function getRainForecast($coordinateIndex = 0, $hours = 48) {
        // Stelle sicher, dass wir formatierte Daten haben
        $rainData = $this->getRain($coordinateIndex);
        
        if (empty($rainData)) {
            return "Keine Regendaten verfügbar.";
        }
        
        // Nach Zeitstempel sortieren (aufsteigend)
        usort($rainData, function($a, $b) {
            return $a['timestamp'] - $b['timestamp'];
        });
        
        // Aktuelle Zeit und maximale Zeit berechnen
        $now = time();
        $maxTime = $now + ($hours * 3600);
        
        // Nur die relevanten Daten im Zeitfenster verwenden
        $relevantData = array_filter($rainData, function($item) use ($now, $maxTime) {
            return $item['timestamp'] >= $now && $item['timestamp'] <= $maxTime;
        });
        
        if (empty($relevantData)) {
            return "Keine Vorhersagedaten für die nächsten {$hours} Stunden verfügbar.";
        }
        
        // Analyse der Regendaten
        $rainPeriods = [];
        $currentPeriod = null;
        $isRaining = false;
        
        foreach ($relevantData as $data) {
            $hasRain = $data['intensity'] > 0;
            
            // Wenn sich der Regenstatus ändert
            if ($hasRain != $isRaining) {
                // Wenn ein neuer Regenzeitraum beginnt
                if ($hasRain) {
                    $currentPeriod = [
                        'start' => $data['timestamp'],
                        'startTime' => $data['time'],
                        'maxIntensity' => $data['intensity'],
                        'description' => $data['description']
                    ];
                    $isRaining = true;
                } 
                // Wenn ein Regenzeitraum endet
                else if ($currentPeriod !== null) {
                    $currentPeriod['end'] = $data['timestamp'];
                    $currentPeriod['endTime'] = $data['time'];
                    $rainPeriods[] = $currentPeriod;
                    $currentPeriod = null;
                    $isRaining = false;
                }
            } 
            // Update der maximalen Intensität, wenn es weiterhin regnet
            else if ($hasRain && $currentPeriod !== null && $data['intensity'] > $currentPeriod['maxIntensity']) {
                $currentPeriod['maxIntensity'] = $data['intensity'];
                $currentPeriod['description'] = $data['description'];
            }
        }
        
        // Den letzten offenen Regenzeitraum abschließen, falls vorhanden
        if ($isRaining && $currentPeriod !== null) {
            $currentPeriod['end'] = end($relevantData)['timestamp'];
            $currentPeriod['endTime'] = end($relevantData)['time'];
            $rainPeriods[] = $currentPeriod;
        }
        
        // Einfache Fälle zuerst behandeln
        if (empty($rainPeriods)) {
            return "Kein Niederschlag für die nächsten {$hours} Stunden erwartet.";
        }

        // Wenn es nur eine Regenperiode gibt
        if (count($rainPeriods) == 1) {
            $period = $rainPeriods[0];
            $durationHours = round(($period['end'] - $period['start']) / 3600, 1);
            $startIn = round(($period['start'] - $now) / 3600, 1);
            
            if ($startIn <= 0) {
                return "{$period['description']} dauert voraussichtlich noch etwa {$durationHours} Stunden an.";
            } else {
                $startTime = date('H:i', $period['start']);
                return "{$period['description']} wird in etwa {$startIn} Stunden (gegen {$startTime} Uhr) erwartet und dauert voraussichtlich {$durationHours} Stunden an.";
            }
        }
        
        // Komplexere Fälle mit mehreren Regenperioden
        $totalRainHours = 0;
        $maxIntensity = 0;
        $maxIntensityDesc = "";
        $rainPhasesCount = count($rainPeriods);
        
        foreach ($rainPeriods as $period) {
            $totalRainHours += ($period['end'] - $period['start']) / 3600;
            if ($period['maxIntensity'] > $maxIntensity) {
                $maxIntensity = $period['maxIntensity'];
                $maxIntensityDesc = $period['description'];
            }
        }
        
        // Prozentsatz der Zeit mit Regen berechnen
        $rainPercentage = ($totalRainHours / $hours) * 100;
        
        // Verschiedene Beschreibungen abhängig von den Mustern
        if ($rainPercentage > 70) {
            return "Anhaltender Niederschlag für den Großteil der nächsten {$hours} Stunden erwartet, mit Phasen von {$maxIntensityDesc}.";
        } else if ($rainPercentage > 30) {
            $firstRainStart = date('H:i', $rainPeriods[0]['start']);
            return "Wiederholte Regenphasen in den nächsten {$hours} Stunden erwartet, beginnend gegen {$firstRainStart} Uhr. In der Spitze {$maxIntensityDesc}.";
        } else {
            $nextRainStart = date('H:i', $rainPeriods[0]['start']);
            $nextRainIn = max(0, round(($rainPeriods[0]['start'] - $now) / 3600, 1));
            
            if ($nextRainIn <= 0) {
                return "Aktuell {$rainPeriods[0]['description']}, gefolgt von weiteren " . ($rainPhasesCount-1) . " Regenphasen in den nächsten {$hours} Stunden.";
            } else {
                return "Erste Regenphase ({$rainPeriods[0]['description']}) in {$nextRainIn} Stunden gegen {$nextRainStart} Uhr, insgesamt " . $rainPhasesCount . " Regenphasen in den nächsten {$hours} Stunden.";
            }
        }
    }
    
    /**
     * Generiert HTML-Code zur Anzeige des animierten GIFs mit Regenvorhersagetabelle
     * 
     * @param string $containerId Optional: ID des Container-Elements (default: "weather-radar-container")
     * @param string $width Optional: Breite des Containers (default: "100%")
     * @param string $height Optional: Höhe des Containers (default: "100%")
     * @param bool $responsive Optional: Responsives Design aktivieren (default: true)
     * @param int $coordinateIndex Optional: Index der Koordinaten für die Regendaten (default: 0)
     * @param string $textColor Optional: Textfarbe (überschreibt Config)
     * @param string $cellBorderColor Optional: Rahmenfarbe für Tabellenzellen (überschreibt Config)
     * @param bool $showForecastText Optional: Vorhersagetext anzeigen (überschreibt Config)
     * @param int $tableHours Optional: Anzahl der Stunden für die Vorhersagetabelle (überschreibt Config)
     * @param int $forecastHours Optional: Anzahl der Stunden für die Textvorhersage (überschreibt Config)
     * @return string HTML-Code
     */
    public function getHTML($containerId = "weather-radar-container", $width = "100%", $height = "100%", $responsive = true, $coordinateIndex = 0, $textColor = null, $cellBorderColor = null, $showForecastText = null, $tableHours = null, $forecastHours = null) {
        // Pfad zum GIF berechnen
        $gifPath = $this->getRelativeGifPath();
        
        // Zufälligen Query-Parameter hinzufügen um Browser-Cache zu umgehen
        $gifPathWithCache = $gifPath . '?v=' . uniqid();

        // Konfigurationsparameter verwenden oder aus dem Parameter nehmen
        $textColor = $textColor ?: $this->config['textColor'];
        $cellBorderColor = $cellBorderColor ?: $this->config['cellBorderColor'];
        $showForecastText = ($showForecastText !== null) ? $showForecastText : $this->config['showForecastText'];
        $tableHours = ($tableHours !== null) ? $tableHours : $this->config['forecastTableHours'];
        $forecastHours = ($forecastHours !== null) ? $forecastHours : $this->config['forecastTextHours'];
        
        // Regendaten abrufen für die Tabelle
        $rainData = $this->getRain($coordinateIndex);
        
        // Keine Daten? Nur das GIF anzeigen
        if (empty($rainData)) {
            // CSS für die Zentrierung und Größenanpassung
            $css = "
            <style>
                #{$containerId} {
                    width: {$width};
                    height: {$height};
                    display: flex;
                    justify-content: center;
                    align-items: center;
                    overflow: hidden;
                    position: relative;
                }
                #{$containerId} img {
                    " . ($responsive ? "
                    max-width: 100%;
                    max-height: 100%;
                    object-fit: contain;" : "
                    width: 100%;
                    height: 100%;
                    object-fit: contain;") . "
                }
            </style>";
            
            // HTML für die Anzeige
            $html = "
            {$css}
            <div id=\"{$containerId}\">
                <img src=\"{$gifPathWithCache}\" alt=\"Wetterradar Animation\" />
            </div>";
            
            return $html;
        }
        
        // Nach Zeitstempel sortieren (aufsteigend)
        usort($rainData, function($a, $b) {
            return $a['timestamp'] - $b['timestamp'];
        });
        
        // Aktuelle Zeit und gerundete aktuelle Stunde
        $now = time();
        $currentHour = strtotime(date('Y-m-d H:00:00', $now));
        
        // Nur die nächsten X Stunden ab der aktuellen Stunde anzeigen (konfigurierbar)
        $maxTime = $currentHour + ($tableHours * 3600);
        
        // Relevante Daten herausfiltern - beginnend mit der aktuellen Stunde
        $relevantData = [];
        foreach ($rainData as $data) {
            if ($data['timestamp'] >= $currentHour && $data['timestamp'] < $maxTime) {
                $relevantData[] = $data;
            }
        }
        
        // Leere Stunden auffüllen, damit exakt die gewünschte Anzahl an Stunden angezeigt wird
        $completeHourlyData = [];
        for ($hour = 0; $hour < $tableHours; $hour++) {
            $timestamp = $currentHour + ($hour * 3600);
            $hourFound = false;
            
            foreach ($relevantData as $data) {
                // Wenn die Daten für diese Stunde vorhanden sind
                if (abs($data['timestamp'] - $timestamp) < 1800) { // Toleranz von 30 Minuten
                    $completeHourlyData[] = $data;
                    $hourFound = true;
                    break;
                }
            }
            
            // Wenn keine Daten gefunden wurden, leeren Eintrag erstellen
            if (!$hourFound) {
                $completeHourlyData[] = [
                    'timestamp' => $timestamp,
                    'time' => date("d.m.Y H:i", $timestamp),
                    'intensity' => 0,
                    'description' => "Kein Regen"
                ];
            }
        }
        
        // Intervall für Uhrzeitanzeige
        $hoursInterval = isset($this->config['hoursInterval']) ? $this->config['hoursInterval'] : 3;
        
        // CSS für die Wettertabelle und Animation
        $css = "
        <style>
            #{$containerId} {
                width: {$width};
                font-family: Arial, sans-serif;
                padding: 2px;
                box-sizing: border-box;
            }
            .weather-animation-container {
                height: {$height};
                display: flex;
                justify-content: center;
                align-items: center;
                overflow: hidden;
                position: relative;
                margin-top: 1px;
            }
            .weather-animation-container img {
                " . ($responsive ? "
                max-width: 100%;
                max-height: 100%;
                object-fit: contain;" : "
                width: 100%;
                height: 100%;
                object-fit: contain;") . "
            }
            .forecast-table {
                width: 100%;
                border-collapse: collapse;
                table-layout: fixed;
                margin-bottom: 10px;
            }
            .forecast-day {
                text-align: center;
                font-weight: bold;
                background-color: transparent;
                padding: 5px;
                border-bottom: 1px solid {$cellBorderColor};
                color: {$textColor};
            }
            .forecast-cell {
                aspect-ratio: 1/1;
                width: 30px;
                height: 30px;
                position: relative;
                border: 1px solid {$cellBorderColor};
                box-sizing: border-box;
                text-align: center;
                vertical-align: middle;
            }
            .intensity-value {
                position: absolute;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
                color: {$textColor};
                font-weight: bold;
                
            }
            .forecast-hour {
                text-align: center;
                font-size: 12px;
                padding: 3px 0;
                color: {$textColor};
                background-color: transparent;
                border: none;
            }
            .forecast-hour-empty {
                padding: 3px 0;
                border: none;
            }
            .forecast-hour {
                text-align: center;
                font-size: 12px;
                padding: 5px 0;
                color: {$textColor};
                background-color: transparent;
            }
            .forecast-text {
                margin-top: 15px;
                padding: 10px;
                background-color: transparent;
                border-radius: 5px;
                border: 1px solid {$cellBorderColor};
                color: {$textColor};
            }
        </style>";
        
        // HTML-Ausgabe beginnen
        $html = $css . '<div id="' . $containerId . '">';
        
        // JavaScript hinzufügen für quadratische Zellen
        $html .= '
        <script>
        function adjustCellHeights() {
            const cells = document.querySelectorAll(".forecast-cell");
            cells.forEach(cell => {
                const width = cell.offsetWidth;
                cell.style.height = width + "px";
            });
        }
        
        // Beim Laden und bei Größenänderung ausführen
        window.addEventListener("load", adjustCellHeights);
        window.addEventListener("resize", adjustCellHeights);
        </script>';
        
        // Wettervorhersagetext hinzufügen, wenn gewünscht
        if ($showForecastText) {
            $forecast = $this->getRainForecast($coordinateIndex, $forecastHours);
            $html .= '<div class="forecast-text">' . $forecast . '</div>';
        }
        
        // Tabelle für die Wettervorhersage erstellen
        $html .= '<table class="forecast-table">';
        
        // Aktuelle Zeit und gerundete aktuelle Stunde
        $now = time();
        $currentHour = strtotime(date('Y-m-d H:00:00', $now));
        
        // Vorverarbeitung, um die genauen Positionen der Tageswechsel zu bestimmen
        $dayChangePositions = [];
        $days = [];
        $lastDay = '';
        
        // Erste Startzeit bestimmen
        $startDay = date('d.m.', $completeHourlyData[0]['timestamp']);
        $days[] = $startDay;
        $lastDay = $startDay;
        
        // Finde den exakten Index, bei dem 00:00 Uhr ist (Tageswechsel)
        for ($i = 1; $i < count($completeHourlyData); $i++) {
            $hour = (int)date('H', $completeHourlyData[$i]['timestamp']);
            $minute = (int)date('i', $completeHourlyData[$i]['timestamp']);
            $day = date('d.m.', $completeHourlyData[$i]['timestamp']);
            
            // Wenn neuer Tag und genau Mitternacht, dann Tageswechsel markieren
            if ($day !== $lastDay && $hour === 0 && $minute === 0) {
                $dayChangePositions[] = $i;
                $days[] = $day;
                $lastDay = $day;
            }
        }
        
        // Header-Zeile mit Datumsangaben
        $html .= '<tr>';
        
        // Startposition
        $lastPos = 0;
        
        // Für jeden Tageswechsel eine Spaltenüberschrift erstellen
        foreach ($dayChangePositions as $index => $position) {
            $colspan = $position - $lastPos;
            $html .= '<td colspan="' . $colspan . '" class="forecast-day">' . $days[$index] . '</td>';
            $lastPos = $position;
        }
        
        // Die letzte Spaltenüberschrift
        $colspan = count($completeHourlyData) - $lastPos;
        if ($colspan > 0) {
            $html .= '<td colspan="' . $colspan . '" class="forecast-day">' . end($days) . '</td>';
        }
        
        $html .= '</tr>';
        
        // Zeile mit den Intensitätszellen
        $html .= '<tr>';
        
        foreach ($completeHourlyData as $data) {
            $intensity = $data['intensity'];
            $cellColor = $this->getRainColor($intensity);
            $intensityDisplay = round($intensity);
            
            // Intensitätswert immer anzeigen, auch wenn 0
             // Verbesserte Darstellung mit garantierter Sichtbarkeit auf dunklen Hintergründen
            $html .= '<td class="forecast-cell" style="background-color: ' . $cellColor . ' !important;" ' . 
                    'title="' . $data['description'] . '">' . 
                    '<span class="intensity-value">' . $intensityDisplay . '</span>' .
                    '</td>';
        }
        
        $html .= '</tr>';
        
        // Zeile mit den Uhrzeiten
        $html .= '<tr>';
        
        foreach ($completeHourlyData as $index => $data) {
            $hourDisplay = date('H:i', $data['timestamp']);
            $hoursInterval = isset($this->config['hoursInterval']) ? $this->config['hoursInterval'] : 3;
            
            if ($index % $hoursInterval === 0) {
                $html .= '<td class="forecast-hour">' . $hourDisplay . '</td>';
            } else {
                $html .= '<td class="forecast-hour-empty"></td>';
            }
        }
        
        $html .= '</tr>';
        $html .= '</table>';
        
        // Animation-Container hinzufügen
        $html .= '<div class="weather-animation-container">';
        $html .= '<img src="' . $gifPathWithCache . '" alt="Wetterradar Animation" />';
        $html .= '</div>';
        
        $html .= '</div>'; // Ende Container
        
        return $html;
    }
    
    /**
    * Gibt eine Farbe basierend auf der Regenintensität zurück
    * Mit Abstufungen gemäß der offiziellen Niederschlagsskala (mm/h)
    * 
    * @param int $intensity Regenintensität (0-100)
    * @return string HTML-Farbcode
    */

    /*'210,233,255' => [0.1, 0.4, "Sehr leichter Regen"],
            '166,204,253' => [0.5, 1.0, "Leichter Regen"],
            '140,153,253' => [1.0, 2.99, "Mäßiger Regen"],
            '115,102,254' => [3.0, 4.99, "Regen"],
            '88,51,254'   => [5.0, 9.99, "Regen"],
            '53,0,183'    => [10.0, 14.99, "Stärkerer Regen"],
            '112,31,128'  => [15.0, 19.99, "Starker Regen"],
            '140,17,170'  => [20.0, 50.0, "Sehr starker Regen"]*/
    private function getRainColor($intensity) {
        // Intensitätswerte auf der Skala 0-100 in mm/h zurückrechnen
        // 100 auf der Skala entspricht ~20 mm/h
        $mmPerHour = $intensity / 5;
        
        if ($mmPerHour <= 0) {
            return 'rgba(50, 50, 50, 0)'; // Dunkelgrau transparent für kein Niederschlag
        } else if ($mmPerHour <= 0.5) {
            return 'rgb(166,204,253)'; // Hellblau für 0,1-0,5 mm/h
        } else if ($mmPerHour <= 1) {
            return 'rgb(140,153,253)'; // Für 0,5-1 mm/h
        } else if ($mmPerHour <= 3) {
            return 'rgb(115,102,254)'; // Für 1,1-3 mm/h
        } else if ($mmPerHour <= 5) {
            return 'rgb(88,51,254)'; // Für 3,1-5 mm/h
        } else if ($mmPerHour <= 10) {
            return 'rgb(53,0,183)'; // Für 5,1-10 mm/h
        } else if ($mmPerHour <= 15) {
            return 'rgb(112,31,128)'; // Für 10,1-15 mm/h
        } else if ($mmPerHour <= 20) {
           return 'rgb(140,17,170)'; // Für 15,1-20 mm/h (Indigo)
        } else {
            return 'rgb(140,17,170)'; // Für > 20 mm/h (Lila/Violett)
        }
    }
    
    /**
     * Liefert Metadaten fuer das LVB-Frontend (Bild + Standort-% + 48h-Forecast) als JSON.
     * Das Frontend nutzt img.lx/ly (Standort in Prozent des ausgelieferten Bildes) fuer
     * zentriertes, aspekt-korrektes Clipping (cover, kein Stretch). Beruecksichtigt Crop.
     *
     * @param int $coordinateIndex Index in config.coordinates (Standort)
     * @return string JSON: {img:{w,h,lx,ly,file,url,ts}, forecast:[{t,v}]}
     */
    public function getRadarMeta($coordinateIndex = 0) {
        // Basiskarten-Masse
        $baseW = 1398; $baseH = 798;
        if (!empty($this->config['baseMapPath']) && is_file($this->config['baseMapPath'])) {
            $sz = @getimagesize($this->config['baseMapPath']);
            if ($sz) { $baseW = (int)$sz[0]; $baseH = (int)$sz[1]; }
        }
        // Standort-Pixel (im ausgelieferten Bild, Crop beruecksichtigt)
        $coord = isset($this->config['coordinates'][$coordinateIndex]) ? $this->config['coordinates'][$coordinateIndex] : [0, 0];
        $lx = (float)$coord[0]; $ly = (float)$coord[1]; $imgW = $baseW; $imgH = $baseH;
        if (!empty($this->config['cropEnabled'])) {
            $cw = (int)$this->config['cropWidth']; $ch = (int)$this->config['cropHeight'];
            $cx = !empty($this->config['cropFromTopRight']) ? ($baseW - $cw) : 0; $cy = 0;
            $lx -= $cx; $ly -= $cy; $imgW = $cw; $imgH = $ch;
        }
        $lxPct = $imgW > 0 ? round($lx / $imgW * 100, 2) : 0;
        $lyPct = $imgH > 0 ? round($ly / $imgH * 100, 2) : 0;
        // GIF-URL (tile-Verzeichnis wird unter /tile/<ordner>/ web-ausgeliefert)
        $lastDir = basename(rtrim((string)$this->config['outputDir'], '/'));
        $file    = basename((string)$this->config['animationFile']);
        // Einzelframes {url,t}: t = echter Radar-Zeitpunkt des Frames (Y-m-d H:i -> 'd.m. H:i').
        // Quelle imageSequence (chronologisch, mit Zeit); Fallback: Verzeichnis-Glob (ohne Zeit).
        $frames = []; $fmt = !empty($this->config['imageFormat']) ? $this->config['imageFormat'] : 'png';
        $seq = $this->getImageSequence();
        if (is_array($seq) && $seq) {
            foreach ($seq as $s) {
                if (empty($s['filename'])) continue;
                $ts = (string) ($s['timestamp'] ?? '');
                $lbl = $ts; $dt = DateTime::createFromFormat('Y-m-d H:i', $ts);
                if ($dt) { $lbl = $dt->format('d.m. H:i'); }
                $frames[] = ['url' => '/tile/' . $lastDir . '/' . basename($s['filename']), 't' => $lbl];
            }
        } else {
            $glob = @glob(rtrim((string) $this->config['outputDir'], '/') . '/radar_*.' . $fmt);
            if (is_array($glob) && $glob) {
                usort($glob, function($a, $b){ return @filemtime($a) - @filemtime($b); });
                foreach ($glob as $g) { $frames[] = ['url' => '/tile/' . $lastDir . '/' . basename($g), 't' => '']; }
            }
        }
        // 48h-Forecast-Serie (Standort)
        $fc = []; $rain = $this->getRain($coordinateIndex);
        if (is_array($rain)) { foreach ($rain as $r) { if (isset($r['timestamp'])) $fc[] = ['t' => (int)$r['timestamp'], 'v' => round((float)$r['intensity'], 2)]; } }
        return json_encode([
            'img' => ['w' => $imgW, 'h' => $imgH, 'lx' => $lxPct, 'ly' => $lyPct,
                      'file' => $file, 'url' => '/tile/' . $lastDir . '/' . $file, 'ts' => date('d.m.y H:i')],
            'frames' => $frames,
            'forecast' => $fc,
        ], JSON_UNESCAPED_SLASHES);
    }

    /**
     * Berechnet den relativen Pfad zum GIF basierend auf dem Ausgabeverzeichnis
     *
     * @return string Relativer Pfad zum GIF
     */
    private function getRelativeGifPath() {
        // Aktuellen GIF-Namen verwenden
        $gifName = basename($this->config['animationFile']);
        
        // Standard-Ausgabeverzeichnis
        $outputDir = $this->config['outputDir'];
        
        // Pfad in Teile zerlegen
        $pathParts = explode('/', str_replace('\\', '/', $outputDir));
        
        // Letzten Teil des Pfades extrahieren (Ordnername)
        $lastDir = end($pathParts);
        
        // Wenn das Ausgabeverzeichnis eine relative Pfadangabe ist (z.B. ./wetterradar_bilder)
        if (strpos($lastDir, '.') === 0) {
            $lastDir = count($pathParts) > 1 ? $pathParts[count($pathParts)-1] : 'radar_images';
        }
        
        // Relativen Pfad zusammensetzen
        $relativePath = "./tile/{$lastDir}/{$gifName}";
        
        return $relativePath;
    }
}
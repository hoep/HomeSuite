<?php
/**
 * BAUSTEIN 4 - KARTEN-RENDERER (self-contained)
 *
 * Ersetzt automower.class.maps.php. KEINE Abhaengigkeit von PHPAutomower/maps.php.
 * Optik wie das Original: Leaflet via CDN, Esri-Satellit-Tiles als Default,
 * OSM-Strassen als Alternative, Layer-Control, Bewegungspfad-Polyline,
 * Pin-Positions-Marker, Geofence-Kreis.
 *
 * @param array  $positions      Liste von Punkten [{lat,lng}, ...]  (auch {latitude,longitude} erlaubt)
 * @param array|null $geofence    Geofence {lat,lng,radius}          (auch {latitude,longitude,radius} erlaubt)
 * @param string $activityColor   Farbe fuer Pfad + Marker (Hex, z.B. '#4CAF50')
 * @param int    $width           Kartenbreite in px
 * @param int    $height          Kartenhoehe in px
 * @return string                 Komplettes eigenstaendiges HTML-Dokument
 */
/**
 * @param string      $activityColor Farbe fuer Pfad UND Marker (Rueckfall)
 * @param int         $zoom          Anfangs-Zoomstufe (Leaflet). 19 war fest verdrahtet und
 *                                   zeigt so wenig Umgebung, dass man die Bahnen nicht
 *                                   einordnen kann; 18 ist eine Stufe weiter weg.
 * @param string|null $markerColor   eigene Markerfarbe; null = wie $activityColor
 * @param string|null $fenceColor    eigene Geofence-Farbe; null = Hausblau
 */
function renderPositionMap($positions, $geofence = null, $activityColor = '#E91E63', $width = 800, $height = 600, $zoom = 18, $markerColor = null, $fenceColor = null) {
    // --- Eingaben normalisieren: {lat,lng} ODER {latitude,longitude} akzeptieren ---
    $norm = [];
    if (is_array($positions)) {
        foreach ($positions as $p) {
            $p = (array)$p;
            $lat = isset($p['lat']) ? $p['lat'] : (isset($p['latitude']) ? $p['latitude'] : null);
            $lng = isset($p['lng']) ? $p['lng'] : (isset($p['longitude']) ? $p['longitude'] : null);
            if ($lat === null || $lng === null) continue;
            $norm[] = ['lat' => (float)$lat, 'lng' => (float)$lng];
        }
    }

    if (empty($norm)) {
        return renderPositionMapError('Keine Positionsdaten verfuegbar');
    }

    // Geofence normalisieren
    $geo = null;
    if (!empty($geofence)) {
        $g = (array)$geofence;
        $glat = isset($g['lat']) ? $g['lat'] : (isset($g['latitude']) ? $g['latitude'] : null);
        $glng = isset($g['lng']) ? $g['lng'] : (isset($g['longitude']) ? $g['longitude'] : null);
        $grad = isset($g['radius']) ? $g['radius'] : null;
        if ($glat !== null && $glng !== null && $grad !== null) {
            $geo = ['lat' => (float)$glat, 'lng' => (float)$glng, 'radius' => (float)$grad];
        }
    }

    // Mittelpunkt aus allen Punkten
    $sumLat = 0.0; $sumLng = 0.0;
    foreach ($norm as $p) { $sumLat += $p['lat']; $sumLng += $p['lng']; }
    $centerLat = $sumLat / count($norm);
    $centerLng = $sumLng / count($norm);

    // Farbe absichern
    if (!is_string($activityColor) || $activityColor === '') {
        $activityColor = '#E91E63';
    }

    // Vorberechnete Markergroessen (wie im Original, markerSize = 30)
    $markerSize        = 30;
    $markerSizeHalf    = $markerSize / 2;        // 15
    $markerSizeInner   = $markerSize * 0.47;     // 14.1
    $markerSizeMargin  = $markerSize * 0.27;     // 8.1
    $markerSizeHeight  = $markerSize * 1.4;      // 42
    $popupAnchorOffset = $markerSize * 1.17;     // 35.1

    // JSON fuer JavaScript
    $positionsJson = json_encode($norm);
    $geofenceJson  = $geo ? json_encode($geo) : 'null';
    $colorJson     = json_encode($activityColor);
    // Nur zulaessige Farbangaben durchreichen - der Wert landet ungeprueft im Stil.
    $hex = function ($c, $fallback) {
        $c = trim((string) $c);
        return preg_match('/^#[0-9a-fA-F]{3,8}$/', $c) ? $c : $fallback;
    };
    $markerJson = json_encode($hex($markerColor, $hex($activityColor, '#E91E63')));
    $fenceJson  = json_encode($hex($fenceColor, '#3388ff'));
    $zoomJs     = (int) max(1, min(24, (int) $zoom));

    $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <title>Automower Karte</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
          integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY="
          crossorigin=""/>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
            integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="
            crossorigin=""></script>
    <style>
        html, body {
            width: 100%;
            height: 100%;
            margin: 0;
            padding: 0;
        }
        /* Karte fuellt IMMER die (iframe-)Box und reflowt bei Groessenaenderung. */
        #map {
            width: 100%;
            height: 100%;
        }

        /* Custom Pin-Marker (wie Original) */
        .mower-marker-pin {
            width: {$markerSize}px;
            height: {$markerSize}px;
            border-radius: 50% 50% 50% 0;
            position: absolute;
            transform: rotate(-45deg);
            left: 50%;
            top: 50%;
            margin: -{$markerSizeHalf}px 0 0 -{$markerSizeHalf}px;
        }
        .mower-marker-pin::after {
            content: '';
            width: {$markerSizeInner}px;
            height: {$markerSizeInner}px;
            margin: {$markerSizeMargin}px 0 0 {$markerSizeMargin}px;
            background: #FFFFFF88;
            position: absolute;
            border-radius: 50%;
        }

        /* Namensnennung der Kartenanbieter.
           Sie war frueher ganz ausgeblendet ("wie im Original") - das ist bei Esri World
           Imagery und OpenStreetMap-Kacheln aber nicht in Ordnung: beide verlangen die
           Nennung. Sie steht deshalb wieder da, nur dezent - dieselbe Sprache wie in der
           Sonnenszene und auf der Auto-Karte: klein, gedaempft, rechts unten, ohne den
           weissen Kasten, den Leaflet von Haus aus darum zieht. */
        .leaflet-control-attribution {
            background: rgba(13, 19, 21, .58) !important;
            color: #7e9198 !important;
            font-size: 9px;
            line-height: 1.25;
            padding: 1px 5px;
            border-radius: 4px 0 0 0;
            box-shadow: none;
        }
        .leaflet-control-attribution a {
            color: #8ba0a6 !important;
            text-decoration: none;
        }
    </style>
</head>
<body>
    <div id="map"></div>

    <script>
    const positions   = {$positionsJson};
    const geofence    = {$geofenceJson};
    const pathColor   = {$colorJson};
    const markerColor = {$markerJson};
    const fenceColor  = {$fenceJson};

    // Karte initialisieren
    const map = L.map('map', {
        center: [{$centerLat}, {$centerLng}],
        zoom: {$zoomJs},
        maxZoom: 24,
        attributionControl: true
    });
    // Ohne Praefix steht dort sonst noch "Leaflet" - das ist der Bibliothek geschuldet,
    // nicht den Kartendaten, und traegt fuer den Betrachter nichts bei.
    map.attributionControl.setPrefix(false);

    // Satellit (Esri World Imagery) - Default wie im Original.
    // Die Nennung haengt AM LAYER, nicht an der Karte: dann wechselt sie mit, sobald
    // ueber die Layer-Auswahl auf Strassen umgeschaltet wird.
    const satellite = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
        maxZoom: 24,
        attribution: 'Luftbild &copy; Esri, Maxar, Earthstar Geographics'
    });

    // Strassen (OpenStreetMap)
    const streets = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap-Mitwirkende'
    });

    satellite.addTo(map);

    L.control.layers({
        "Satellit": satellite,
        "Strassen": streets
    }).addTo(map);

    // Pin-Marker-Klasse (wie Original)
    const PinMarker = L.DivIcon.extend({
        options: {
            className: 'mower-custom-marker',
            iconSize: [{$markerSize}, {$markerSizeHeight}],
            iconAnchor: [{$markerSizeHalf}, {$markerSizeHeight}],
            popupAnchor: [0, -{$popupAnchorOffset}],
            color: '#E91E63'
        },
        createIcon: function(oldIcon) {
            const div = (oldIcon && oldIcon.tagName === 'DIV') ? oldIcon : document.createElement('div');
            div.innerHTML = '<div class="mower-marker-pin" style="background-color: ' + this.options.color + ';"></div>';
            this._setIconStyles(div, 'icon');
            return div;
        }
    });

    // Bewegungspfad-Polyline
    const path = positions.map(p => [p.lat, p.lng]);
    L.polyline(path, {
        color: pathColor,
        weight: 3,
        opacity: 0.8
    }).addTo(map);

    // Positions-Marker (aktuelle = erste Position, wie Original path[0])
    const currentPos = path[0];
    L.marker(currentPos, {
        icon: new PinMarker({ color: markerColor })
    }).addTo(map);

    // Geofence-Kreis
    if (geofence) {
        L.circle([geofence.lat, geofence.lng], {
            color: fenceColor,
            fillColor: fenceColor,
            fillOpacity: 0.1,
            radius: geofence.radius
        }).addTo(map);
    }

    // --- Responsiv: bei jeder Groessenaenderung (Fenster/Widget-Box) Karte neu vermessen ---
    window.addEventListener('resize', function(){ map.invalidateSize(); });
    if (window.ResizeObserver) { try { new ResizeObserver(function(){ map.invalidateSize(); }).observe(document.getElementById('map')); } catch(e){} }
    setTimeout(function(){ map.invalidateSize(); }, 150);
    </script>
</body>
</html>
HTML;

    return $html;
}

/**
 * Kleine Fehlerseite (self-contained).
 */
function renderPositionMapError($errorMessage) {
    $errorMessage = htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8');
    return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <title>Fehler</title>
    <meta charset="utf-8">
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 0;
               display: flex; justify-content: center; align-items: center;
               height: 100vh; background-color: #f5f5f5; }
        .error-box { background: #fff; border: 1px solid #ddd; border-radius: 5px;
                     padding: 20px; text-align: center;
                     box-shadow: 0 2px 10px rgba(0,0,0,0.1); max-width: 400px; }
        h1 { color: #d32f2f; font-size: 20px; margin-top: 0; }
        p { color: #333; font-size: 14px; }
    </style>
</head>
<body>
    <div class="error-box">
        <h1>Karten-Fehler</h1>
        <p>$errorMessage</p>
    </div>
</body>
</html>
HTML;
}

// --- Selbsttest / Beispieldaten-Renderer, wenn direkt aufgerufen ---
if (isset($argv) && basename(__FILE__) === basename($argv[0])) {
    $dir = __DIR__;
    // Beispiel: kleiner Maehpfad um Hauskoordinaten (48.2082, 16.3738)
    $positions = [
        ['lat' => 48.20820, 'lng' => 16.37380],
        ['lat' => 48.20825, 'lng' => 16.37390],
        ['lat' => 48.20830, 'lng' => 16.37385],
        ['lat' => 48.2082, 'lng' => 16.3738],
        ['lat' => 48.20828, 'lng' => 16.37360],
        ['lat' => 48.2082, 'lng' => 16.3738],
        ['lat' => 48.2082, 'lng' => 16.3738],
    ];
    $geofence = ['lat' => 48.20823, 'lng' => 16.37377, 'radius' => 40];
    $html = renderPositionMap($positions, $geofence, '#4CAF50', 800, 600);
    file_put_contents($dir . '/04_map_test.html', $html);
    echo "wrote " . $dir . "/04_map_test.html\n";
}

# PoolController (HSPC)

Domänen-Modul „Pool" der HomeSuite-Familie — eigenständiges IP-Symcon-Modul für einen
ProCon.IP-artigen Pool-Controller (pooldigital.de). Es löst die alte PHP-Skript-Klassenfamilie
`PHPPoolcontroller` ab. **Eine Instanz = ein Pool-Controller.**

- **Modul-GUID:** `{878CA345-86D1-84FC-B196-5B3224C067CF}`
- **Prefix:** `HSPC`
- **Aliase:** PoolController, HSPC, HomeSuite Pool
- **Vendor/Repo:** Hoep · https://github.com/hoep/HomeSuite

## Überblick / Zweck

Das Modul spiegelt den kompletten Live-Zustand des Controllers (GetState.csv / GetDos.csv) in
IP-Symcon-Statusvariablen und stellt schaltbare Bedien- und Konfigurations-Controls bereit:
Relais-Modi (Auto / Manuell Aus / Manuell Ein), Dosier-Automatiken (Chlor/Redox, pH-minus,
pH-plus), Dosier-Sollwerte, manuelle Dosierung, Steuerregeln (Temperatur/Solar, Analog, Digital-IO),
Sensor-, Netzwerk-, Alarm- und Kalibrier-Konfiguration sowie einen Umwälz-Wochenplan.

Der Gerätezugriff läuft ausschließlich über den selbst-enthaltenen Treiber
`Hoep\HomeSuite\Drivers\Pool\PoolClient`
(`modules/HomeSuite/libs/HomeSuite/Drivers/Pool/PoolClient.php`), der das HTTP-Protokoll
(GetState.csv, GetDos.csv, usrcfg.cgi, Command.htm etc.) kapselt. Die semantische Feldbelegung
der Regel-Sektionen liegt im Modul (UI-/Editor-Ebene), nicht im Transport-Client.

## Architektur / HAL

- Basisklasse `Hoep\HomeSuite\EntityModule`: liefert Manifest→Variablen-Materialisierung,
  das RPC-Trio (`SetControl`/`GetControlValue`/`GetState`/`Manage`), Store/Properties und das
  **Armed-Gate**.
- **Klassenname == module.json `name` == GUID.** Prefix `HSPC`.
- Konfiguration liegt in **nativen Symcon-Properties** (Instanz-Formular), nicht mehr im
  FabricStore-JSON. Alle früher hartkodierten Werte (Host/Login/Timeout, Spaltenindizes,
  Schwellen, Poll-Intervall) sind jetzt Properties.
- **Baum-Transparenz:** `bindingTargets()` legt einen sichtbaren Bindungs-Link `bl_TrealSourceVarId`
  auf den externen In-Pool-Sensor an.
- **Variablen-Gruppierung:** `usesVariableGroups()` = true. Die ~500 Statusvariablen werden über
  `controlGroup()` in beschriftete Baum-Kategorien einsortiert (Relais, Regeln Filter/Solar-Temp/
  Analog/Digital-IO, Dosierung Chlor/pH-minus/pH-plus, Sensorik, Netzwerk, Alarme & Meldung,
  Kalibrierung, System & Verbindung, Sammelgruppe „Messwerte").

## Poll / Datenfluss

- Timer `Poll` (RPC `HSPC_Poll`), Standardintervall **30 000 ms**, konfigurierbar über die
  Property `PollInterval` (Minimum 5 000 ms). Der Timer läuft nur, wenn ein Host konfiguriert ist.
- Jeder Poll liest `GetState.csv` + `GetDos.csv`, mappt die Spalten (konfigurierbare Spaltenindizes)
  auf Statusvariablen und pflegt Dosier-Restzeiten, Kanister-Füllstände, Relais-Ist-Zustände,
  Fehler/Meldungen und Verbindungsstatus.
- **Relais-Logging** wird automatisch sichergestellt (`ensureRelayLogging()`), damit die
  Filterlaufzeit (Minuten heute) aus dem Archiv berechenbar ist.

### TruePoolTemp — durchfluss-abhängige Temperatur-Fusion

Inline-Sensoren (S0) messen nur bei laufender Pumpe / vorhandenem Durchfluss korrekt. Das Modul
fusioniert daraus die **echte Wassertemperatur** `TruePoolTemp`:

- Bei vorhandenem Durchfluss (Anströmung ≥ `FlowThreshold`, nach `FlowSettleSeconds` Settle-Zeit)
  gilt der Inline-Sensor.
- Bei Stillstand wird der externe **In-Pool-Sensor** (`TrealSourceVarId`) als Wahrheit genommen,
  sofern aktiviert und plausibel (`MinPlausible`…`MaxPlausible`, nicht älter als `MaxStaleSeconds`).
- **Kein Geräte-Rückschreiben** (Option A): das Modul steuert/rechnet, schreibt die externe
  Temperatur aber nicht in den Controller zurück. Die Quelle wird über `TempSource` ausgewiesen.

## Sicherheits-Gates

- **Armed (Scharf-Gate):** Property `Armed`. Bei `false` läuft das Modul im **Schatten-Modus** —
  alle realen Schreibzugriffe (Relais, Dosierung, Regeln, Netzwerk, Wochenplan-Rückschreiben)
  werden gestoppt und als `shadow` quittiert. Realer Effekt nur bei `Armed = true`.
  Umschaltbar per Formular oder `HSPC_SetArmed`.
- **CalibrationAllowed (Doppel-Gate):** Kalibrier-Schreibzugriffe greifen **direkt in die
  ADC-/Elektroden-Messkette** und verlangen zusätzlich zum Armed-Gate die Freigabe
  `CalibrationAllowed`. Nur für Fachpersonal.

## Konfiguration (Properties / Formular)

| Property | Typ | Default | Bedeutung |
|---|---|---|---|
| `Host` | string | '' | Host/IP des Controllers (leer = inaktiv) |
| `Port` | int | 80 | HTTP-Port |
| `UseHTTPS` | bool | false | HTTPS statt HTTP |
| `User` / `Password` | string | admin / '' | Login |
| `Timeout` | int | 10 | Request-Timeout (s) |
| `ConnectTimeout` | int | 5 | Connect-Timeout (s) |
| `PollInterval` | int | 30000 | Poll-Intervall (ms, min. 5000) |
| `PoolTempCol`/`OutsideCol`/`SolarCol`/`ReturnCol`/`PumpTempCol` | int | 8/9/10/11/12 | Temperatur-Spalten in GetState.csv |
| `PhCol`/`RedoxCol`/`PressureCol`/`FlowVolCol`/`FlowRateCol` | int | 7/6/3/4/24 | Chemie-/Hydraulik-Spalten |
| `PumpRelayIndex` | int | 0 | Relais-Index der Filterpumpe |
| `FlowThreshold` | float | 0.5 | Durchfluss-Schwelle (cm/s) → Durchfluss vorhanden |
| `FlowSettleSeconds` | int | 120 | Settle-Zeit nach Durchfluss-Wechsel (s) |
| `PoolSize` | float | 0.0 | Poolvolumen (m³; 0 = Standardformel) |
| `CircFlowRate` | float | 0.0 | Umwälzleistung (m³/h) für Umwälzzeit-Berechnung |
| `TrealEnabled` | bool | false | Externen In-Pool-Sensor als TReal-Quelle nutzen |
| `TrealSourceVarId` | int | 0 | Variable des externen In-Pool-Sensors |
| `MinPlausible`/`MaxPlausible` | float | 0.0 / 45.0 | Plausibilitätsfenster TReal (°C) |
| `MaxStaleSeconds` | int | 1800 | Max. Alter der TReal-Quelle (s) |
| `FilterRuleIndex` | int | 0 | TIMEC-Regel-Index (0–15), die die Filterpumpe steuert |
| `FilterRuleCount` | int | 4 | Anzahl TIMEC-Regeln ab `FilterRuleIndex` (je Wochentag-Gruppe eine) |
| `CircWindowStart` | int | 480 | Startzeit des automatischen Filterfensters (Min ab Mitternacht) |
| `Armed` | bool | false | Scharf-Gate: reale Schreibzugriffe erlauben |
| `CalibrationAllowed` | bool | false | Zweit-Gate für Kalibrier-Schreibzugriffe (Messkette) |
| `DtcCodes` | string | '' | Komma-Liste von Alarm-/DTC-Codes; erzeugt je Code eine schreibbare Meldestufe-Variable `DtcLevel<code>` |

**Formular:** Panels für Verbindung, „Echte Wassertemperatur (TReal)", Spaltenzuordnung &
Umwälz-Berechnung, dazu die Checkboxen `Armed` und `CalibrationAllowed`. Der `actions`-Bereich
enthält Laufzeit-Buttons (Diagnose/Schreiben) und Rechenhelfer — diese rufen `HSPC_Manage(...)` auf.

## Status-Variablen / Controls (Auswahl)

**Temperatur:** `TruePoolTemp` (echt/autoritativ), `WaterTempInline` (S0), `TrealExternal`,
`TempSource`, `FlowActive`, `TempOutside`, `TempSolar`, `TempReturn`, `TempPumpHousing`, `CpuTemp`.

**Wasserchemie:** `PH` + `PHTarget`, `Redox` + `RedoxTarget`, `PHPlusTarget` (Sollwerte schreibbar).

**Hydraulik:** `Pressure` (Kesseldruck), `FlowVolume`, `FlowRate` (Anströmung).

**Kanister & Dosierung:** `ClLevel`/`PHMinusLevel`/`PHPlusLevel` (Füllstände),
`ClConsumption`/`PHMinusConsumption`/`PHPlusConsumption`, Live-Dosier-Aktivität
`ClDosing`/`PHMinusDosing`/`PHPlusDosing` sowie je Regler Restzeit/Zyklus/Dauer/Verbrauch
(`*DosRemain`, `*DosNextCycle`, `*DosDurCur`, `*DosDurTotal`, `*DosConsumed`), `ClPoleReversal`.

**Automatik-Schalter (schaltbar, gated):** `DosingClAuto`, `DosingPHAuto`, `DosingPHPAuto`,
`CircAuto` (Umwälzautomatik).

**Relais (0–7):** je Relais `Relay<i>` (Ist an/aus, reflect) und `Relay<i>Mode`
(Select: 0=Auto, 1=Manuell Aus, 2=Manuell Ein). Beschriftungen: Pumpe, Ventil Absorber,
Pumpe Chlor, Pumpe pH-minus, Pumpe pH-plus, Scheinwerfer, Photovoltaik, Relais 8.

**System/Filter:** `AutoCircOptimal`, `ProgFilterMin`, `FilterRuntimeToday`, `OperatingHours`,
`Firmware`, `StatusFlag`, `ErrorCount`, `ErrorText`, `LinkOK`.

**Konfig-Variablen (schreibbar, tabellengetrieben):** Regel-Sektionen TEMPC/ADCC/SWITCHC (je
Regelindex × Feld eine Variable), Sensor-, Netzwerk-, E-Mail-/Kontakt-/DTC- und Kalibrier-Variablen.
Schreiben dispatcht über `writeRuleField`/`dispatchCfgVar`, Ist-Spiegel über `spiegelRules()` u. a.

## Skript-API (PHP-Befehlsreferenz)

> Control-Setter gehen über `RequestAction`; Sollwerte/Dosierung/Wartung über `Manage`.
> **Realer Effekt nur bei `Armed = true`** (globales Gate), Kalibrierung zusätzlich nur bei
> `CalibrationAllowed = true`.

### Generisch (aus EntityModule)
| Funktion | Beschreibung |
|---|---|
| `bool HSPC_SetControl(int $id, string $Ident, mixed $Value)` | beliebigen actionable Control setzen |
| `mixed HSPC_GetControlValue(int $id, string $Ident)` | aktuellen Statuswert lesen |
| `string HSPC_GetState(int $id)` | kompletter Live-State-Snapshot (JSON, alle Idents) |
| `string HSPC_Manage(int $id, string $Json)` | mgmt-Op ausführen (siehe unten), JSON `{op,args}` |

### Setzen (Convenience)
| Funktion | Zweck |
|---|---|
| `bool HSPC_SetDosingRedoxAuto(int $id, bool $On)` | Cl/Redox-Dosierautomatik |
| `bool HSPC_SetDosingPHAuto(int $id, bool $On)` | pH-minus-Dosierautomatik |
| `bool HSPC_SetDosingPHPAuto(int $id, bool $On)` | pH-plus-Dosierautomatik |
| `bool HSPC_SetCircAuto(int $id, bool $On)` | Umwälzautomatik |
| `bool HSPC_SetRelayMode(int $id, int $Index, int $Mode)` | Relais 0–15 → 0=Auto, 1=Manuell Aus, 2=Manuell Ein |
| `bool HSPC_SetArmed(int $id, bool $Armed)` | scharf / Schatten-Modus |

### Dosierung & Wartung (Manage-Fassaden)
| Funktion | Zweck |
|---|---|
| `bool HSPC_DoDosage(int $id, int $Type, int $Seconds)` | manuell dosieren (Type 0=Cl/Redox, 1=pH-minus, 2=pH-plus; 0 s = Stop) |
| `bool HSPC_SetDosageConfig(int $id, int $Type, string $ConfigJson)` | Sollwerte/Grenzen setzen (Shape wie `getDosageConfig(type)`) |
| `bool HSPC_ResetContainer(int $id, int $Type, float $Liters)` | Kanister-/Zellen-Füllstand zurücksetzen |
| `bool HSPC_SetRelayName(int $id, int $Index, string $Name)` | Relaisname |
| `bool HSPC_SendSchedule(int $id)` | Wochenplan ins Gerät schreiben |
| `bool HSPC_SetDeviceTime(int $id, int $Unix = 0)` | Geräteuhr stellen (0 = jetzt) |
| `bool HSPC_ClearErrors(int $id)` | Fehlerlog löschen |
| `bool HSPC_SendTestMail(int $id, int $Index = 0)` | Test-Mail an Kontakt-Index senden |

### Lesen
| Funktion | Zweck |
|---|---|
| `float HSPC_GetTruePoolTemp(int $id)` | autoritative Wassertemperatur |

### Beispiele
```php
HSPC_SetRelayMode($id, 2, 2);       // Relais 2 (Pumpe Chlor) manuell Ein
HSPC_DoDosage($id, 1, 15);          // 15 s pH-minus dosieren
$cfg = json_decode(HSPC_Manage($id, json_encode(['op'=>'getDosageConfig','args'=>['type'=>0]])), true);
echo HSPC_Manage($id, json_encode(['op'=>'probe']));   // Verbindung testen
```

## Management-Ops (`HSPC_Manage`)

JSON `{"op":"…","args":{…}}`. Ops mit „(schreibt)" wirken nur bei `Armed = true`
(Kalibrierung zusätzlich nur bei `CalibrationAllowed`).

**Diagnose / Setup:** `configureConnection`, `configureMapping`, `configureTReal`, `getConfig`
(Passwort maskiert), `probe`, `poll`, `readRaw`, `computeCirculation` (Umwälzzeit aus TruePoolTemp).

**Betrieb (schreibt):** `setRelayMode`, `doDosage`, `resetContainer`, `clearErrors`, `setDeviceTime`.

**Fehler/Alarme:** `readErrors`, `getDtc`, `setDtc`, `setDtcField` (RMW), `getEmail`, `getContacts`,
`setEmailAccount`, `setEmailServer`, `setContacts`, `sendTestMail`, `getOther`, `setOther`.

**Dosierung:** `getDosageConfig`, `setDosageConfig`, `setDosageFull` (voller Regler, RMW).

**Regeln:** `getRules`/`setRules`, `getTempRule`/`setTempRule` (TEMPC/Solar),
`getAdccRule`/`setAdccRule` (Analog), `getSwitchcRule`/`setSwitchcRule` (Digital-IO).

**Sensorik:** `getSensorConfig` (ADC/BNC/1-Wire/IO), `getRomCodes`, `setSensorConfig`,
`setSensorChannel` (Einzelkanal-RMW), Rechenhelfer `calcImpulseGain`, `calcGainOffset` (2-Punkt).

**Netzwerk:** `getNetwork`, `setNetwork`, `setNetworkFields` (RMW).

**Relaisnamen:** `getRelayNames`, `setRelayName`.

**Wochenplan:** `scheduleStatus`, `sendSchedule`.

**Kalibrierung (Doppel-Gate):** `getCal`, `setHwCal` (ADC-Hardware), `setRdxPhCal` (Elektroden).

**Scharf:** `setArmed`.

## Umwälz-Wochenplan (TIMEC)

Der Umwälz-Filterplan wird über ein Symcon-Wochenplan-Ereignis editiert und in die TIMEC-Regeln
des Controllers geschrieben. Steuernd sind `FilterRuleIndex` (Start-Regel) und `FilterRuleCount`
(eine Regel je Wochentag-Gruppe). `ApplyChanges` adoptiert den Ist-Stand als Baseline (Hash),
damit unveränderte Pläne nicht fälschlich als „pending" gelten. Rückschreiben nur bei `Armed`.

## Besondere Hinweise

- **`Host` leer = Modul inaktiv:** kein Poll-Timer, keine Schreibzugriffe.
- **Schatten-Modus by default:** ausgeliefert mit `Armed = false`; erst nach bewusstem
  Scharfschalten wirken Relais-/Dosier-/Regel-Schreibzugriffe real.
- **Spaltenindizes** sind auf einen live verifizierten ProCon.IP (192.168.1.30) vorbelegt; bei
  abweichender Firmware/Bestückung über das Formular anpassen (`readRaw` zur Diagnose nutzen).
- **Kein TReal-Rückschreiben** in den Controller — die externe Temperatur dient nur der Fusion
  zu `TruePoolTemp`.
- **~500 Statusvariablen** werden gruppiert im Objektbaum abgelegt (keine flache Ablage).
- **DTC-Variablen** entstehen nur für die in `DtcCodes` gelisteten Codes.

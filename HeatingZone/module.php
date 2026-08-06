<?php

declare(strict_types=1);

/**
 * HeatingZone (HSHT) — Domaenen-Modul „Heizung" (Phase 1, M1.1).
 *
 * Eine Instanz = ein Heizkreis/Raum. Erbt {@see \Hoep\HomeSuite\EntityModule}:
 * die Basis legt aus diesem manifest() die Status-Variablen an, aktiviert die
 * native RequestAction (Vertrag 1) und liefert das RPC-Trio
 * HSHT_GetManifest / HSHT_GetState / HSHT_Manage.
 *
 * Zwei Thermostat-Klassen (Leitprinzip 7, capabilities().scheduleMode):
 *  - 'device'     : Geraet fuehrt das Wochenprofil selbst (HomeMatic-Adapter, M1.2)
 *  - 'controller' : dummer Sollwert-Thermostat -> ScheduleEngine im Modul faehrt
 *                   den Zeitplan (GenericVariableThermostat, M1.2)
 * Manifest/Editor/Musterseite bleiben in beiden Faellen identisch.
 *
 * M1.1 = Skelett: Modul + Controls + Manifest. Der HAL-Treiber (IThermostat)
 * und der Schreibpfad kommen in M1.2; applyControl() ist hier bewusst noch ohne
 * Hardware-Wirkung (nur optimistischer SetValue der Basis + Debug-Log).
 */

require_once __DIR__ . '/../libs/HomeSuite/autoload.php';

use Hoep\HomeSuite\ActionContext;
use Hoep\HomeSuite\Control;
use Hoep\HomeSuite\ControlContract;
use Hoep\HomeSuite\EntityModule;

// Klassenname MUSS = module.json "name" (ohne Leerzeichen) sein.
class HeatingZone extends EntityModule
{
    private const SETPOINT_MIN = 5.0;
    private const SETPOINT_MAX = 30.0;

    /**
     * Vertrag 2 — das Manifest dieser Entitaet. Aus ihm legt die Basis die
     * Variablen an; der LVB rendert daraus Bedienung UND Verwaltung generisch.
     *
     * @return array<string,mixed>
     */
    protected function manifest(): array
    {
        return [
            'domain'  => 'heating',
            'title'   => 'Heizung',
            'icon'    => 'Temperature',

            // ---- typisierte Controls (Vertrag 1) ----
            'controls' => [
                [
                    'ident' => 'Setpoint', 'type' => ControlContract::T_SETPOINT,
                    'role' => 'heating:setpoint', 'label' => 'Solltemperatur',
                    'varType' => 2, 'profile' => '~Temperature',
                    'unit' => '°C', 'min' => self::SETPOINT_MIN, 'max' => self::SETPOINT_MAX,
                    'step' => 0.5, 'dec' => 1, 'actionable' => true,
                ],
                [
                    'ident' => 'ActualTemp', 'type' => ControlContract::T_REFLECT,
                    'role' => 'heating:actual', 'label' => 'Ist-Temperatur',
                    'varType' => 2, 'profile' => '~Temperature', 'unit' => '°C',
                    'dec' => 1, 'actionable' => false,
                ],
                [
                    'ident' => 'Humidity', 'type' => ControlContract::T_REFLECT,
                    'role' => 'heating:humidity', 'label' => 'Luftfeuchte',
                    'varType' => 1, 'profile' => '~Humidity.F', 'unit' => '%',
                    'actionable' => false,
                ],
                [
                    'ident' => 'Mode', 'type' => ControlContract::T_SELECT,
                    'role' => 'heating:mode', 'label' => 'Modus',
                    'varType' => 1, 'actionable' => true,
                    'options' => [
                        ['value' => 0, 'label' => 'Auto'],
                        ['value' => 1, 'label' => 'Manuell'],
                        ['value' => 2, 'label' => 'Boost'],
                        ['value' => 3, 'label' => 'Frostschutz'],
                    ],
                ],
                [
                    'ident' => 'Presence', 'type' => ControlContract::T_SELECT,
                    'role' => 'heating:presence', 'label' => 'Praesenz',
                    'varType' => 1, 'actionable' => true,
                    'options' => [
                        ['value' => 0, 'label' => 'Normal'],
                        ['value' => 1, 'label' => 'Erweitert'],
                        ['value' => 2, 'label' => 'Abgesenkt'],
                    ],
                ],
                [
                    'ident' => 'Online', 'type' => ControlContract::T_REFLECT,
                    'role' => 'heating:online', 'label' => 'Online',
                    'varType' => 0, 'actionable' => false,
                ],
            ],

            // ---- Profil-Typ: Wochenprofil (2 Achsen Praesenz x Wochentag) ----
            'profileTypes' => [
                'roomProfile' => [
                    'label'  => 'Wochenprofil',
                    'axes'   => [
                        'presence' => ['Normal', 'Erweitert', 'Abgesenkt'],
                        'weekday'  => ['MO', 'DI', 'MI', 'DO', 'FR', 'SA', 'SO'],
                    ],
                    'slot'   => ['end' => 'HH:MM', 'val' => ['type' => 'float', 'min' => self::SETPOINT_MIN, 'max' => self::SETPOINT_MAX]],
                    'rules'  => ['lastSlotEnd' => '24:00', 'ascending' => true],
                    'editor' => 'weekedit-hm',
                ],
            ],

            // ---- Verwaltungs-Aktionen (Whitelist; Impl folgt in M1.x) ----
            'managementActions' => [
                ['op' => 'createEntity',      'label' => 'Heizkreis anlegen'],
                ['op' => 'renameEntity',      'label' => 'Umbenennen'],
                ['op' => 'deleteEntity',      'label' => 'Loeschen'],
                ['op' => 'configureDriver',   'label' => 'Treiber konfigurieren'],
                ['op' => 'updateProfile',     'label' => 'Wochenprofil bearbeiten'],
                ['op' => 'duplicateProfile',  'label' => 'Profil duplizieren'],
                ['op' => 'assignProfile',     'label' => 'Profil zuweisen'],
                ['op' => 'setActivePresence', 'label' => 'Praesenz setzen'],
            ],

            // ---- Konfig-Felder (Treiberwahl; im LVB gesetzt) ----
            'configFields' => [
                ['key' => 'driver', 'type' => 'select', 'label' => 'Treiber', 'required' => true,
                 'options' => [
                     ['value' => 'hm-HM-TC-IT-WM-W-EU', 'label' => 'HomeMatic HM-TC-IT-WM-W-EU'],
                     ['value' => 'hm-HM-CC-RT-DN',      'label' => 'HomeMatic HM-CC-RT-DN'],
                     ['value' => 'hm-HM-CC-TC',         'label' => 'HomeMatic HM-CC-TC'],
                     ['value' => 'generic-thermostat',  'label' => 'Generisch (Soll/Ist-Variablen)'],
                 ]],
                ['key' => 'targetId',    'type' => 'objid', 'label' => 'Geraet/Sollwert-Variable', 'required' => false],
                ['key' => 'sensorId',    'type' => 'objid', 'label' => 'Ist-Fuehler (optional)',   'required' => false],
            ],

            'capabilities' => ['scheduleMode' => 'device', 'hasPresence' => true, 'hasHumidity' => true],
        ];
    }

    /**
     * Automatik-Hoheit: nur Solltemperatur & Modus oeffnen ein manualHold-Fenster
     * (A3 — Reflect/Presence nicht).
     */
    protected function isAutomated(Control $c): bool
    {
        return in_array($c->ident, ['Setpoint', 'Mode'], true);
    }

    /**
     * Vertrag 1 — realer Umsetzungs-Hook. M1.1: noch KEIN Treiber verdrahtet;
     * der optimistische SetValue der Basis hat den Wert bereits in die
     * Statusvariable geschrieben. In M1.2 routet dies an IThermostat
     * (setSetpoint / setMode bzw. Profil-Schreiben).
     */
    protected function applyControl(Control $c, $value, ActionContext $ctx): void
    {
        $drv = $this->driver();
        if ($drv === null) {
            $this->SendDebug(
                'HSHT.applyControl',
                $c->ident . '=' . (is_scalar($value) ? (string) $value : json_encode($value))
                    . ' (M1.1: kein Treiber verdrahtet)',
                0
            );
            return;
        }
        // M1.2: an IThermostat delegieren (setSetpoint/setMode/writeWeekProfile).
    }
}

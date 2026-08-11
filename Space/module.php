<?php

declare(strict_types=1);

/**
 * HomeSuite Bereich (HSSP) — schlankes Struktur-Element der Topologie.
 *
 * Eine Instanz bildet EINE Ebene der Raumstruktur ab; welche, sagt die Property
 * `Kind`: Haus | Bereich | Raum. Die HIERARCHIE ist der OBJEKTBAUM selbst
 * (IPS_GetParent): eine Bereich-Instanz haengt unter ihrem Haus, ein Raum unter
 * seinem Bereich, und die Entitaeten (HeatingZone, spaeter ShadingDevice, ...)
 * haengen unter ihrem Raum. Damit ist die ZUORDNUNG = Elternschaft — kein
 * zweites Register, keine Drift.
 *
 * Bewusst KEIN EntityModule: dieses Element hat keine Bedien-Controls und kein
 * HAL; es traegt nur Name (Instanzname), Kind und ein optionales Kuerzel. Der
 * HomeSuite Hub liest den Baum und baut daraus die Topologie
 * (Haus -> Bereich -> Raum -> [Entitaeten je Gewerk]) fuer Navigation und Widgets.
 *
 * "Bereich/Ebene" ist bewusst WEIT gefasst: neben EG/OG/DG auch Keller, Garten
 * oder Aussenbereich — einfach eine weitere Bereich-Instanz.
 */

// Klassenname MUSS = module.json "name" ohne Leerzeichen ("HomeSuite Bereich").
class HomeSuiteBereich extends IPSModule
{
    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyString('Kind', 'Raum');  // Haus | Bereich | Raum
        $this->RegisterPropertyString('Abbr', '');       // Kuerzel (optional)
    }

    /**
     * Minimale Konsole: Ebene-Auswahl + Kuerzel. Die Struktur selbst wird im
     * Objektbaum per Drag & Drop gepflegt (Element unter sein uebergeordnetes,
     * Entitaeten unter den Raum).
     */
    public function GetConfigurationForm()
    {
        $form = [
            'elements' => [
                ['type' => 'Select', 'name' => 'Kind', 'caption' => 'Ebene', 'options' => [
                    ['caption' => 'Haus/Wohnung',  'value' => 'Haus'],
                    ['caption' => 'Bereich/Ebene', 'value' => 'Bereich'],
                    ['caption' => 'Raum',          'value' => 'Raum'],
                ]],
                ['type' => 'ValidationTextBox', 'name' => 'Abbr', 'caption' => 'Kuerzel (optional)'],
                ['type' => 'Label', 'caption' =>
                    'Struktur = Objektbaum: dieses Element unter sein uebergeordnetes haengen '
                    . '(Haus > Bereich > Raum) und die Entitaeten (Heizung, ...) unter den Raum. '
                    . 'Der HomeSuite Hub liest die Struktur automatisch.'],
            ],
            'actions' => [],
            'status'  => [],
        ];
        return json_encode($form, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** HSSP_GetInfo — Struktur-Infos dieses Elements (fuer Hub/LVB). */
    public function GetInfo(): string
    {
        return json_encode([
            'iid'  => $this->InstanceID,
            'kind' => $this->ReadPropertyString('Kind'),
            'abbr' => $this->ReadPropertyString('Abbr'),
            'name' => IPS_GetName($this->InstanceID),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

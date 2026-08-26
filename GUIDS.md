# HomeSuite — GUID-Register (IMMUTABEL)

Diese GUIDs sind **unveraenderlich**. Sie wurden einmalig in M0.0 erzeugt und
duerfen nie wieder geaendert werden — jede Aenderung bricht bestehende Instanzen,
Verweise und Store-Installationen. Ergaenzungen (neue Module) sind erlaubt,
Aenderungen bestehender Zeilen sind es nicht.

`ModuleType` gemaess Symcon-SDK:
`0 = Core/IO`, `1 = I/O (ClientSocket/ServerSocket)`, `2 = Splitter`,
`3 = Device`, `4 = Configurator`, `5 = Discovery`.

Alle Domaenen-Module sind `type 3` (Device / eine Instanz = eine Entitaet).
Audio-Bridges (Splitter) sind `type 2`. Die Library selbst traegt keine
ModuleType-Angabe (sie ist Container, kein Modul).

## Library

| Prefix | Zweck                        | GUID                                     | ModuleType |
|--------|------------------------------|------------------------------------------|------------|
| —      | Library HomeSuite            | `{0F66F23F-ED50-4CD5-AB44-5FC961C7733A}` | —          |

## Domaenen-Module (Device, type 3)

| Prefix  | Zweck                                        | GUID                                     | ModuleType |
|---------|----------------------------------------------|------------------------------------------|------------|
| `HSH`   | Hub (Singleton, parentless, Registry/Provision) | `{A0C082B4-9E74-430E-BD97-F9CEBB364257}` | 3          |
| `HSHT`  | HeatingZone (Heizzone)                        | `{AC059357-088A-4DF8-ABBC-F8724BC78769}` | 3          |
| `HSSH`  | ShadingDevice (Beschattung)                   | `{A9645ED8-CB55-43B8-869B-BFF6ACFC8DC1}` | 3          |
| `HSAU`  | AudioZone parentless (eigener In-Process-Socket) | `{C4F2639D-2A87-453D-8175-B586BF605A38}` | 3          |
| `HSAUX` | AudioZone bridged (Kind einer Bridge-Splitter)   | `{053E7017-584E-4F62-A246-EBA6CE3DE034}` | 3          |
| `HSIR`  | IrrigationCircuit (Bewaesserung)              | `{D264A82B-DE31-45CC-8AF2-8F4C5D076508}` | 3          |
| `HSSP`  | Bereich (Struktur: Haus/Bereich/Raum)         | `{5598F752-886D-475F-91CE-5813A3C581E5}` | 3          |
| `HSPC`  | PoolController (ProCon.IP Pool-Steuerung)     | `{878CA345-86D1-84FC-B196-5B3224C067CF}` | 3          |
| `HSSC`  | SecurityCenter (Waechter, Einbruchmeldung)    | `{0E7216C3-FCCA-41FF-BE4E-E5939D9AA571}` | 3          |

## Audio-Bridges (Splitter, type 2)

| Prefix  | Zweck                          | GUID                                     | ModuleType |
|---------|--------------------------------|------------------------------------------|------------|
| `HSBH`  | HeosBridge (HEOS-Splitter)      | `{BCDCA10C-BDFD-4270-8D28-1CC690A130DB}` | 2          |
| `HSBM`  | MusicCastHub (MusicCast-Splitter) | `{3EABAEBB-9DFF-4D8F-AB0C-0639B488CFCC}` | 2          |
| `HSBD`  | DenonAvrBridge (Denon-Splitter)  | `{878924BE-C0FA-4553-8897-22FF03E62D82}` | 2          |

## Anmerkungen

- Prefixe sind kernelweit eindeutig; `Prefix_`-Funktionsnamen (z. B. `HSHT_GetManifest`)
  leiten sich stabil daraus ab.
- Datenkette (SDK-Zwang): `IO(type 1) <- Splitter(type 2) <- Device(type 3)`.
  `HSAUX` wird stets als Kind einer der Bridges (`HSBH`/`HSBM`/`HSBD`) provisioniert,
  `HSAU` bringt seinen Socket in-process selbst mit.
- Idents/Profile werden prefix-namespaced vergeben (`HSHT.Setpoint`, `HSSH.Movement`).

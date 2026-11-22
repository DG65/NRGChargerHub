# Eigenes Wallbox-Modul an den NRG-Stack anbinden

Diese Anleitung richtet sich an Entwickler, die ein **eigenes Symcon-Modul für eine Wallbox** haben (zum Beispiel für
Easee, Wallbox Pulsar oder eine Cloud-Anbindung) und es für das EMS und die Anzeigemodule des NRG-Stacks sichtbar machen
wollen. Das eigene Modul bleibt dabei ein eigenständiges Modul. Es muss weder ChargerHub noch ein anderes NRG-Stack-Modul
installiert haben, und ChargerHub braucht das Fremdmodul nicht.

Gemeint ist der **Vertrag `charger`, Version 1.14**. Er wird von ChargerHub (`CHUB_GetFunctions`) angeboten. Dieselbe
Feldliste verwenden auch OCPPHub und ChargerEEBUSHub.

## Das Prinzip in drei Sätzen

1. Dein Modul bietet eine Funktion `<PRÄFIX>_GetFunctions($id)` an, die eine **Liste mit einem Eintrag je Ladepunkt**
   liefert. Der Eintrag beschreibt, welche Variablen es gibt (als Variablen-IDs) und was die Wallbox kann.
2. Gelesen wird über diese **Variablen-IDs**. Es gibt keine Abfragefunktion für Messwerte.
3. **Gesteuert** wird über `IPS_RequestAction()` auf die Instanz, mit festen Idents. Es gibt kein `<PRÄFIX>_Set…` für
   Steuerbefehle (siehe „Steuern“).

## Die Funktion `<PRÄFIX>_GetFunctions($id)`

- Der Präfix steht in der `module.json` deines Moduls (`"prefix": "MWB"`). Eine öffentliche Methode `GetFunctions()` in
  der Modulklasse wird von Symcon automatisch als `MWB_GetFunctions($id)` bereitgestellt.
- Rückgabe: eine **Liste** (PHP-Array mit Index ab 0) von Einträgen, je Ladepunkt einer. Ein JSON-String mit derselben
  Struktur wird von den Konsumenten ebenfalls gelesen.
- Der Konsument ergänzt die Instanz-ID selbst. Du kannst `instanceID` aber auch mitliefern.
- Die Funktion muss **schnell und ohne Nebenwirkung** sein: nur Variablen-IDs nachschlagen und Eigenschaften lesen, nichts
  vom Gerät abfragen.

## Felder des Eintrags

Spalte „Pflicht“: Ohne diese Felder ist die Wallbox für das EMS nur eingeschränkt oder gar nicht nutzbar. Alle anderen
Felder sind optional. **Fehlt ein optionales Feld, heißt das „unbekannt“, nicht „nein“.**

| Feld | Pflicht | Bedeutung |
|---|---|---|
| `contractVersion` | ja | `'1.14'`. Konsumenten prüfen die Hauptversion (vor dem Punkt). Fehlt das Feld, gilt `'1.0'`. |
| `function` | ja | immer `'charger'` |
| `contractType` | empfohlen | immer `'charger'`. Das EMS erkennt den Eintrag daran (ältere Einträge ohne das Feld werden über `function` erkannt). |
| `label` | ja | Anzeigename des Ladepunkts (meist der Instanzname) |
| `powerID` | ja | Variablen-ID der Ladeleistung in **Watt** (Float). `0`, wenn nicht verfügbar. |
| `energyImportID` | ja | Variablen-ID eines **kumulativen** Energiezählers in kWh. Ein Sitzungszähler, der je Ladevorgang auf 0 zurückspringt, ist **kein** Zählerstand. `0`, wenn nicht verfügbar. |
| `measured` | ja | `true`, wenn echte Messwerte geliefert werden |
| `chargeEnableID` | zum Steuern | Variablen-ID der Ladefreigabe (Bool). Nur zum Lesen des Zustands, geschrieben wird per `IPS_RequestAction` auf den Ident `ctl_enable`. `0` = nicht steuerbar. |
| `currentLimitID` | zum Steuern | Variablen-ID des Stromlimits in Ampere. Geschrieben wird auf den Ident `ctl_curr_limit`. `0` = nicht steuerbar. Ohne beide IDs größer 0 steuert das EMS die Wallbox nicht, sondern misst sie nur. |
| `minCurrent` | zum Steuern | kleinster gültiger Ladestrom in Ampere (üblich 6). Es ist ein **Wert**, keine Variablen-ID. |
| `maxCurrent` | zum Steuern | wirksame Obergrenze in Ampere (Wert): das kleinere von Hardware-Limit und Anschlussstrom-Einstellung |
| `managedBy` | ja | wer die Wallbox regelt: `none`, `ems`, `goe-controller`, `tibber`, `p14a`, `marketer` oder `other` (siehe unten) |
| `externallyManaged` | ja | `true`, sobald `managedBy` nicht `none` oder `ems` ist (aus Kompatibilität zu Version 1.0) |
| `lastSeenAt` | ja | Unix-Zeit des letzten **erfolgreichen** Kontakts zum Gerät, `0` = noch nie (siehe unten) |
| `failsafeSec` | zum Steuern | Sekunden, nach denen dein Modul in den sicheren Zustand zurückfällt, wenn `ctl_enable` und `ctl_curr_limit` ausbleiben (siehe „Rückfall“). `0` oder fehlend = kein Rückfall, dann gibt das EMS das Schreiben nicht frei. |
| `failsafeMode` | nein | `'deadman'` (Standard, wenn das Feld fehlt): der Konsument erneuert die Sollwerte innerhalb von `failsafeSec / 2`, und dein Modul fällt zurück, wenn das Nachschreiben ausbleibt. `'maxDuration'`: dein Modul beendet einen Ein-Wunsch selbst nach `failsafeSec`, ohne Nachschreiben. Für Wallboxen ist `'deadman'` richtig. |
| `groupName` | nein | Gruppenname, den der Nutzer in deiner Instanz setzt (zum Beispiel „Garage“), `''` = keine Gruppe, höchstens 60 Zeichen. Die Anzeige im Dashboard kann Ladepunkte mit demselben Namen unter einem Knoten zusammenfassen. Rein organisatorisch. |
| `plugStateID` | nein | Variablen-ID „Fahrzeug angesteckt“ (Bool), `0` wenn die Wallbox es nicht meldet |
| `stateID`, `errorCodeID` | nein | Variablen-ID von Ladestatus (Integer) bzw. Fehlercode (Integer, `0` = kein Fehler). Ein Fehlercode ungleich 0 sperrt im EMS die Freigabe. |
| `deviceSerial`, `deviceHost`, `manufacturer` | nein | Seriennummer, Adresse und Herstellerschlüssel des Geräts, für die Doppelanbindung (siehe unten) |
| `duplicateOf` | nein | `null` (zählt normal) oder ein Verweis auf die Instanz, die für dasselbe Gerät zählt (siehe unten) |
| `phases`, `phasesSwitchable` | nein | aktuell genutzte Phasenzahl (1 oder 3) bzw. ob das Gerät umschalten kann. Nur angeben, wenn das **Gerät** es selbst meldet, nie aus Messwerten raten. |
| `phaseModeID`, `phaseModeValues` | nein | Variablen-ID der Phasenumschaltung (Ident `ctl_phase_mode`) und die zu schreibenden Werte `{"one": 1, "three": 3, "auto": null}`. `phaseModeID = 0` heißt nicht umschaltbar. |
| `currentStepA` | nein | kleinste Stromstufe des Sollwerts in Ampere (1,0 bei ganzzahliger Variable) |
| `vehicleNameID` | nein | Variablen-ID „zugeordnetes Fahrzeug“ (String), nur wenn das Modul den Namen von außen bekommt |

Die vollständige Feldliste mit allen weiteren optionalen Feldern (Sitzungsenergie, Energie-Limit, Zeitstempel,
Regelungsstatus …) steht in der [README](../README.md#einbindung-in-den-modul-verbund) im Abschnitt „Einbindung in den
Modul-Verbund“. Für den Anfang genügen die Felder oben.

### `lastSeenAt`

Die Unix-Zeit des letzten **erfolgreichen** Kontakts mit dem Gerät (nicht der Zeitpunkt des Funktionsaufrufs). So erkennt
der Konsument den Unterschied zwischen „gerade 0 W“ und „liefert seit Langem nichts mehr“. Ein Modul, das bei jedem Zyklus
dieselbe 0 neu schreibt, sieht sonst aus wie ein Gerät, das gerade nicht lädt. Das EMS behandelt die Leistung als unbekannt,
wenn der letzte Kontakt mehr als 10 Minuten zurückliegt. Bei einer Cloud-Anbindung zählt der letzte erfolgreiche Abruf, nicht
der letzte geänderte Wert.

### `managedBy` und `externallyManaged`

`managedBy` sagt, wer die Wallbox regelt. Bei `none` und `ems` darf das EMS selbst schalten. Bei allem anderen
(`goe-controller`, `tibber`, `p14a`, `marketer`, `other`) **steuert das EMS nicht**, es liest nur mit. `tibber` gilt als
harte Sperre, weil dabei eine zugesagte Regelenergie gestört würde. Wenn dein Modul oder der Hersteller die Wallbox selbst
regelt (zum Beispiel ein Cloud-Lastmanagement), melde `other`. Der Wert wird am besten als Auswahlfeld im Formular
angeboten, **vom Nutzer gesetzt und nie geraten**: Ob jemand anderes regelt, steht in der Regel in keinem Register.

### `minCurrent` und `maxCurrent`

Der Bereich, in dem ein Sollwert gültig ist. Das EMS schreibt nie unter `minCurrent` ein Limit, sondern pausiert stattdessen
über die Ladefreigabe (`ctl_enable` = `false`). `maxCurrent` ist die wirksame Obergrenze, also das Minimum aus
Hardware-Limit und einer Einstellung des Nutzers („maximaler Anschlussstrom“). Ein Rechenfehler im übergeordneten Regler soll
nicht an der Zuleitung ankommen: Klemme jeden Schreibwert in deinem Modul zusätzlich auf diesen Bereich.

### Doppelanbindung: `deviceSerial`, `deviceHost`, `duplicateOf`

Wird dieselbe Wallbox von zwei Modulen angebunden (zum Beispiel von deinem Modul und zusätzlich über OCPP), würde das EMS
sie doppelt zählen. Dafür gibt es:

- `deviceSerial` und `deviceHost` zur Erkennung derselben Hardware. Liefere sie, wenn du sie kennst, sonst leer.
- `duplicateOf`: Der **Nutzer** markiert im Formular, dass diese Instanz nur ein zweiter Zugang zu einem Gerät ist, das
  schon ein anderes Modul zählt. Das Feld bleibt `null`, solange nichts markiert ist, und wird nie automatisch geraten.
  Es betrifft **nur das Zählen** (Summen, Sitzungen, Leistung), nicht die Steuerhoheit. Wer ans Gerät schreiben darf,
  bestimmt allein `managedBy`.

## Steuern

Das EMS schreibt **nicht** über eine Funktion deines Moduls, sondern wie jede Aktion in Symcon:

```php
IPS_RequestAction($instanceID, 'ctl_enable', true);       // Ladefreigabe
IPS_RequestAction($instanceID, 'ctl_curr_limit', 10);     // Stromlimit in A
IPS_RequestAction($instanceID, 'ctl_phase_mode', 3);      // nur wenn phaseModeID > 0
```

Daraus folgt:

- Deine Instanz braucht Variablen mit **genau diesen Idents** (`ctl_enable`, `ctl_curr_limit`, optional `ctl_phase_mode`),
  jeweils mit `EnableAction()`, und `RequestAction($Ident, $Value)` setzt den Wert am Gerät.
- Idents sind Teil der öffentlichen Schnittstelle und werden nie umbenannt.
- Die Variablen-IDs `chargeEnableID` und `currentLimitID` im Eintrag zeigen auf diese Variablen. Das EMS liest daraus den
  aktuellen Zustand.
- **Reihenfolge beim Start:** erst `ctl_enable` = `true`, dann `ctl_curr_limit`.
- **Pausieren** immer über `ctl_enable` = `false`. Ein Limit von 0 ist kein gültiger Pausewert.
- Setze die Variable erst, **wenn das Gerät den Befehl angenommen hat**, und lasse sie sonst unverändert. Wirf bei einem
  Fehler eine Ausnahme oder gib `false` zurück: Das EMS merkt sich fehlgeschlagene Schreibzugriffe.
- Schreibt dein Modul im Hintergrund selbst (zum Beispiel ein eigener Regler), gilt das als „andere Hoheit“: Melde dann
  `managedBy` = `other`, damit nicht zwei Regler dieselbe Stellgröße setzen.

Eine Wallbox, die nur gemessen werden soll, liefert `chargeEnableID` = 0 und `currentLimitID` = 0. Dann steuert das EMS
sie nicht.

### Rückfall und `failsafeSec`

Ein übergeordneter Regler kann ausfallen, die Verbindung kann abreißen, oder das EMS kann die Steuerung entziehen. Deshalb
muss dein Modul oder die Wallbox **von selbst in einen sicheren, dokumentierten Zustand zurückfallen**, wenn eine Zeit
lang kein Sollwert mehr ankommt, zum Beispiel auf eine feste Grundstromstärke oder die eigene Ladelogik der Wallbox. Das
gilt besonders, wenn die Wallbox einen Sollwert nach einer gewissen Zeit selbst vergisst oder festhält.

- Melde diese Zeit im Eintrag als **`failsafeSec`** (Ganzzahl, Sekunden).
- Das EMS erneuert `ctl_enable` und `ctl_curr_limit` spätestens nach `failsafeSec / 2` (mindestens alle 10 Sekunden), auch
  während einer Pause nach einem Wechsel. Dein Modul muss also nur reagieren, wenn **kein** Nachschreiben mehr kommt.
- `failsafeMode` ist optional. Fehlt es, gilt `'deadman'` (so beschrieben). Für Wallboxen ist das die richtige Wahl.
- Beschreibe den sicheren Zustand in deiner Dokumentation.
- `0` oder ein fehlendes Feld heißt **kein Rückfall**. Ein Regler gibt das Schreiben für dein Modul dann nicht frei, und
  das EMS misst die Wallbox nur.

### Schreibstatus (empfohlen)

Wenn dein Modul schreibt, biete diese vier Variablen an. Das EMS wertet sie ohne weitere Absprache aus und meldet, wenn
Befehle länger nicht ankommen:

| Ident | Typ | Bedeutung |
|---|---|---|
| `ctl_write_fail_count` | Integer | Anzahl fehlgeschlagener Steuerbefehle |
| `ctl_write_fail_time` | Integer | Unix-Zeit des letzten Fehlers |
| `ctl_write_fail_text` | String | Grund des letzten Fehlers |
| `ctl_write_ok_time` | Integer | Unix-Zeit des letzten erfolgreich angekommenen Befehls |

„Hängt“ heißt `ctl_write_fail_time` größer als `ctl_write_ok_time`.

## Minimales Beispiel

`module.json` (Auszug):

```json
{
    "id": "{DEINE-EIGENE-GUID}",
    "name": "Meine Wallbox",
    "type": 3,
    "vendor": "",
    "prefix": "MWB",
    "aliases": [],
    "url": ""
}
```

`module.php`:

```php
<?php

declare(strict_types=1);

class MeineWallbox extends IPSModule
{
    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyInteger('MaxCurrent', 16);
        $this->RegisterPropertyString('ManagedBy', 'none');
        $this->RegisterAttributeInteger('LastSeenAt', 0);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->RegisterVariableFloat('power', 'Ladeleistung', '', 10);          // Watt
        $this->RegisterVariableFloat('energy_total', 'Energie gesamt', '', 20); // kWh, kumulativ
        $this->RegisterVariableBoolean('ctl_enable', 'Ladefreigabe', '~Switch', 30);
        $this->RegisterVariableInteger('ctl_curr_limit', 'Stromlimit (A)', '', 40);
        $this->EnableAction('ctl_enable');
        $this->EnableAction('ctl_curr_limit');
    }

    // Steuerbefehle vom EMS (IPS_RequestAction) und aus der Konsole
    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'ctl_enable':
                if ($this->sendToDevice('enable', (bool)$Value)) {
                    $this->SetValue('ctl_enable', (bool)$Value);
                    return;
                }
                throw new Exception('Ladefreigabe wurde vom Gerät nicht angenommen');
            case 'ctl_curr_limit':
                $max = $this->ReadPropertyInteger('MaxCurrent');
                $amps = max(6, min($max, (int)$Value));   // auf den gültigen Bereich klemmen
                if ($this->sendToDevice('limit', $amps)) {
                    $this->SetValue('ctl_curr_limit', $amps);
                    return;
                }
                throw new Exception('Stromlimit wurde vom Gerät nicht angenommen');
        }
    }

    // wird von Symcon als MWB_GetFunctions($id) bereitgestellt
    public function GetFunctions(): array
    {
        $max = $this->ReadPropertyInteger('MaxCurrent');
        $managedBy = $this->ReadPropertyString('ManagedBy');
        return [[
            'contractVersion'   => '1.14',
            'function'          => 'charger',
            'contractType'      => 'charger',
            'label'             => IPS_GetName($this->InstanceID),
            'powerID'           => $this->GetIDForIdent('power'),
            'energyImportID'    => $this->GetIDForIdent('energy_total'),
            'measured'          => true,
            'chargeEnableID'    => $this->GetIDForIdent('ctl_enable'),
            'currentLimitID'    => $this->GetIDForIdent('ctl_curr_limit'),
            'minCurrent'        => 6,
            'maxCurrent'        => $max,
            'managedBy'         => $managedBy,
            'externallyManaged' => !in_array($managedBy, ['none', 'ems'], true),
            'lastSeenAt'        => $this->ReadAttributeInteger('LastSeenAt'),
            'failsafeSec'       => 120,   // nach 120 s ohne Sollwert fällt die Box in ihren dokumentierten sicheren Zustand zurück
            'deviceSerial'      => '',
            'duplicateOf'       => null,
        ]];
    }

    private function sendToDevice(string $what, $value): bool
    {
        // hier die eigene Geräte- oder Cloud-Anbindung
        return true;
    }
}
```

Wo dein Modul Werte vom Gerät liest, setze bei jedem erfolgreichen Abruf `LastSeenAt` auf `time()`:

```php
$this->WriteAttributeInteger('LastSeenAt', time());
```

## Wie wird ein Fremdmodul gefunden?

Das EMS (ab Version 0.110.0) sucht Gerätemodule, die eine Funktion `<PRÄFIX>_GetFunctions` anbieten, und übernimmt deren
Einträge mit `contractType` = `'charger'` (sonst `function` = `'charger'`) und der Hauptversion 1 in `contractVersion`.
Die Suche läuft nicht im Regelzyklus, sondern im Partnerzyklus des EMS, gekapselt und mit einem Zeitbudget von 3 Sekunden
je Aufruf. Ein Fehler, eine Ausnahme oder eine andere Hauptversion führt dazu, dass dein Modul in diesem Durchlauf
übersprungen und im EMS-Log genannt wird. Deaktivierte Instanzen (Status 104) werden ignoriert. Die Module ChargerHub,
OCPPHub, ChargerEEBUSHub, das EMS selbst, MeterHub und InverterHub werden nicht als Fremdmodul behandelt.

- **Doppelte Wallboxen:** Ein Eintrag mit `duplicateOf` zählt nicht. Hat dein Eintrag dieselbe `deviceSerial` wie eine
  Wallbox, die über ChargerHub, OCPPHub oder ChargerEEBUSHub angebunden ist, hat die andere Anbindung Vorrang. Im
  Formular des EMS erscheint deine Wallbox als „<PRÄFIX> (Fremdmodul)“.
- **Lesen** geht sofort, mit den Feldern dieses Vertrags (`powerID`, `chargeEnableID` und so weiter) und einer
  Frischeprüfung über `lastSeenAt`.
- **Schreiben** (`ctl_enable`, `ctl_curr_limit`, `ctl_phase_mode` über `IPS_RequestAction` auf deine Instanz) gibt es nur,
  wenn **alle** vier Punkte zutreffen:
  1. Der Nutzer hat deine Instanz im EMS-Formular in der Liste der Fremdmodule, die das EMS steuern darf, ausdrücklich
     freigegeben (Standard: leer, also nur Messen).
  2. Dein Eintrag meldet `failsafeSec` größer 0.
  3. `lastSeenAt` ist höchstens 10 Minuten alt (falls geliefert).
  4. `managedBy` ist `none` oder `ems`.

Die Anbindung ist freiwillig und wird vom Nutzer pro Instanz freigegeben. Zusagen zu weiteren Funktionen oder Terminen
gibt es dazu nicht.

## Prüfen, ob es klappt

1. `print_r(MWB_GetFunctions($id));` in einem Skript. Alle `…ID`-Felder müssen auf existierende Variablen zeigen
   (`IPS_VariableExists`).
2. `IPS_RequestAction($id, 'ctl_enable', true);` in einem Skript. Die Variable muss sich ändern, und bei einem Fehler
   muss eine Ausnahme kommen.
3. Eine tote Verbindung simulieren (Gerät ausschalten): `lastSeenAt` darf nicht weiterlaufen.

## Rechtliches

Der Vertrag und diese Anleitung stehen wie der übrige ChargerHub unter der PolyForm Noncommercial License 1.0.0 (siehe
[LICENSE](../LICENSE)). Ein eigenes Modul, das diesen Vertrag nur **anbietet**, ist ein eigenes Werk und wird dadurch nicht
Teil von ChargerHub.

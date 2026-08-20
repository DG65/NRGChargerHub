# ChargerHub

![Symcon](https://img.shields.io/badge/Symcon-PHPModul-blue)
![Modul Version](https://img.shields.io/badge/Modul_Version-0.9.34-blue)
![Symcon Version](https://img.shields.io/badge/Symcon_Version-9.0%2B-blue)
![License](https://img.shields.io/badge/License-PolyForm_Noncommercial_1.0.0-lightgrey)
[![Check Style](https://github.com/DG65/NRGChargerHub/actions/workflows/check-style.yml/badge.svg)](https://github.com/DG65/NRGChargerHub/actions/workflows/check-style.yml)
[![PayPal](https://img.shields.io/badge/PayPal-Me-blue?logo=paypal)](https://paypal.me/DietmarGureth)

IP-Symcon-Modul für Wallboxen (Ladestationen für Elektrofahrzeuge) verschiedener Hersteller
per Modbus TCP — analog zu [InverterHub](https://github.com/DG65/NRGInverterHub) (Wechselrichter)
und [MeterHub](https://github.com/DG65/NRGMeterHub) (Energiezähler).

Teil des **NRG-Stack** — welche Modulstände zusammenpassen, listet
[SUITE.md](https://github.com/DG65/NRGEMS/blob/main/SUITE.md).

**Status: Beta.** Die Register-Zuordnungen basieren auf den öffentlich verfügbaren
Modbus-Dokumentationen der Hersteller, sind aber — mit Ausnahme von go-eCharger (siehe unten) —
**noch nicht an echter Hardware verifiziert**. Rückmeldungen zu falschen/fehlenden Werten sind
willkommen — bitte mit Wallbox-Typ und betroffenem Register melden.

## Unterstützte Wallboxen

| Wallbox | Umfang | Anmerkung |
|---|---|---|
| **KEBA KeContact P30/P40** | Ladestatus, Kabelstatus, Ladeleistung, Energie gesamt + akt. Sitzung, optional Strom/Spannung je Phase, Seriennummer/Firmware, Steuerung (Ladefreigabe, Stromlimit 6–63 A) | Holding-Register (FC 0x03) ab 1000, alle Werte U32 über 2 Register, Unit-ID standardmäßig 255. Gegen die evcc-Referenzimplementierung (an realer Hardware erprobt) abgeglichen. P40: Ladefreigabe läuft über das Stromlimit (5004), Register 5014 existiert dort nicht — noch nicht gesondert behandelt. |
| **Alfen Eve Single/Double Pro-line, NG9xx** | Sockel-Status, Ladeleistung, angewandtes Stromlimit, optional Spannung/Strom je Phase, Steuerung (Ladefreigabe, Stromlimit) | Holding-Register (FC 0x03), Float32 Big-Endian. Nur Sockel 1 (Basisadresse ohne Offset) wird bedient. |
| **Heidelberg Energy Control** | Ladestatus, Leistung, optional Strom/Spannung je Phase, PCB-Temperatur, Steuerung (Ladefreigabe, Stromlimit 6–32 A) | Holding-Register (FC 0x03), Unit-ID standardmäßig 1. |
| **go-eCharger Gemini/HOME+** | Ladestatus, Ladeleistung, Energie akt. Sitzung/gesamt, optional Spannung/Strom/Leistung je Phase (+ N), genutzte Phasen nach Schütz, Kabel-Codierung, Adapter, RFID-Karte, Fehlercode, Seriennummer/Firmware; Steuerung: Ladefreigabe (FORCE_STATE), Stromlimit (AMPERE_VOLATILE 6–32 A), **Phasenumschaltung** (Auto/1-/3-phasig), Zugangskontrolle, Kabelverriegelung, Energie-Limit je Ladevorgang, LED-Helligkeit — Steuerwerte werden vom Gerät zurückgelesen | Gegen die offizielle Herstellerdoku ([go-eCharger-API-v2](https://github.com/goecharger/go-eCharger-API-v2), modbus-de.md) geprüft. FC 0x06 wird von go-e nicht unterstützt, Schreibzugriffe laufen über FC 0x16. Firmware 60.3 hatte einen dokumentierten Byte-Order-Bug (Schalter „Byte-Reihenfolge getauscht"), seit 60.4 behoben. **Modbus muss erst per App/HTTP-API aktiviert werden** (`men=true`) — sonst bleibt Port 502 geschlossen. ⚠️ **Zwei-Regler-Warnung:** Regelt ein go-e Controller die Wallbox bereits selbst (Lastmanagement/Überschussladen), schließen sich EMS-Steuerung und Controller-Regelung gegenseitig aus — eins von beiden deaktivieren, oder in der Instanz beim Auswahlfeld „Wer regelt diesen Ladepunkt?" den go-e Controller wählen (wird als `managedBy`/`externallyManaged` über `CHUB_GetFunctions` gemeldet, per Modbus ist der Lastmanagement-Status nicht auslesbar). |

Registeradressen stehen im **Beschreibungsfeld** jeder Variable (Objekt-Manager, Spalte
„Beschreibung") — praktisch zum Abgleich mit dem Herstellerhandbuch.

ℹ️ Der **go-e Controller** (Energiemess-Zentrale) wird nicht hier, sondern von
[MeterHub](https://github.com/DG65/NRGMeterHub) unterstützt (ab 0.14.0, inkl. automatischer
Erkennung in der dortigen Zählersuche) — er ist ein Energiezähler, keine Wallbox.

## Ziel

Ein Modul, ein Auswahlfeld „Wallbox-Hersteller" — je nach Auswahl werden die passenden Register
und Bedienelemente freigeschaltet. Im Unterschied zu MeterHub (nur lesen) braucht ChargerHub auch
Schreibzugriff: Ladefreigabe setzen, Stromlimit setzen — das macht die Anbindung näher an
InverterHub als an MeterHub.

## Installation

1. In der IP-Symcon-Konsole: **Modulverwaltung → Hinzufügen** und die URL dieses Repositories
   eintragen: `https://github.com/DG65/NRGChargerHub` (Branch **beta**, Default-Branch).
2. Eine neue Instanz vom Typ **„ChargerHub"** anlegen — oder **„ChargerHub Suche"**
   (ChargerHubDiscovery) für einen automatischen Netzwerk-Scan.

## Netzwerk-Suche

Das Modul **ChargerHubDiscovery** durchsucht einen IP-Bereich nach den oben genannten
Wallbox-Typen (Modbus-TCP-Port 502) und legt gefundene Geräte per Klick als ChargerHub-Instanz
mit vorausgefüllter IP-Adresse, Unit-ID und Hersteller an — analog zu InverterHubDiscovery/
MeterHubDiscovery.

## Migration vom go-eCharger-Modul (IPSCoyote/GO-eCharger)

Wer von diesem verbreiteten Fremdmodul (github.com/IPSCoyote/GO-eCharger, GUID
`{B4624A42-...}`) auf ChargerHub umsteigt, kann über **MigrationsHub** die Historie
übernehmen (siehe „Einbindung in den Modul-Verbund" unten). Folgende Ident-Zuordnung wurde am
Quellcode des Fremdmoduls verifiziert (Stand 03.08.2026, MigrationsHub-Sitzung):

| Alt-Ident (GO-eCharger) | Neu-Ident (ChargerHub)    | Hinweis                              |
| ------------------------ | -------------------------- | ------------------------------------- |
| `status`                 | `state`                    |                                        |
| `powerToCarLineL1/L2/L3` | `power_l1`/`power_l2`/`power_l3` |                                  |
| `powerToCarTotal`        | `power`                     |                                        |
| `ampToCarLineL1/L2/L3`   | `current_l1`/`current_l2`/`current_l3` |                            |
| `energyTotal`            | `energy_total`              |                                        |
| `energyLoadCycle`        | `energy_session`            |                                        |
| `serialID`               | `dev_serial`                |                                        |
| `error`                  | `dev_error`                 |                                        |
| `supplyLineN`            | `voltage_n`                 |                                        |
| `supplyLineL1/L2/L3`     | `voltage_l1`/`voltage_l2`/`voltage_l3` |                             |
| `adapterAttached`        | `adapter`                   |                                        |
| `unlockedByRFID`         | `unlocked_by`                |                                       |
| `cableUnlockMode`        | `ctl_cable_lock`             |                                       |
| `accessControl`          | `ctl_access`                 |                                       |
| `cableCapability`        | `cable_current`              |                                       |
| `energyChargedCard1`…`10`| `card0_energy`…`card9_energy` | **Achtung Index-Offset**: Alt-Modul zählt Karten 1-basiert (1–10), ChargerHub 0-basiert (0–9) — `energyChargedCard1` entspricht `card0_energy`, nicht `card1_energy`. |

**Kein Alt-Gegenpart vorhanden** (im Fremdmodul-Quellcode nicht enthalten, keine Migration
nötig/möglich): `carConnected`, `firmwareVersion`. Diese Werte liest ChargerHub trotzdem — nur
eben ohne übertragbare Alt-Historie. `carConnected`/`vehicle_plugged` ließe sich im Prinzip aus
dem Alt-Ident `status` ableiten (z. B. status≠1 → verbunden) — das ist aber eine
Werte-Transformation, kein reiner Ident-Bezug, und müsste bei Bedarf in ChargerHub selbst gelöst
werden, nicht über MigrationsHubs generisches Ident-Matching.

**RFID-Kartennamen** (z. B. „Karte 0: Name") hat das Fremdmodul nicht als eigene Variable —
diese kommen bei ChargerHub ausschließlich über den neuen MQTT-Kartenzähler (siehe oben,
„RFID-Kartenzähler"), nicht aus einer Migration.

## Einbindung in den Modul-Verbund

ChargerHub bietet analog zu `MHUB_GetFunctions` eine Funktion `CHUB_GetFunctions($id)` an, über
die ein EMS oder eine Kachel Ladepunkte, Momentanleistung und Steuerungsmöglichkeiten abfragen
kann. Der Vertrag ist **mit der EMS-Entwicklung abgestimmt** (Version 1.1); je Ladepunkt ein Eintrag:

| Feld | Typ | Bedeutung |
|---|---|---|
| `contractVersion` | string | Vertragsversion `Major.Minor` (aktuell `'1.1'`); Konsumenten prüfen die Major, additive Felder erhöhen nur die Minor. Fehlt das Feld, gilt konservativ `'1.0'` |
| `function` | string | `'charger'` |
| `label` | string | Instanzname |
| `powerID` | int | Variablen-ID Ladeleistung (W); 0 falls nicht verfügbar |
| `energyImportID` | int | Variablen-ID Energiezähler (kWh, kumulativ — `energy_total` bevorzugt); 0 falls nicht verfügbar |
| `measured` | bool | `true` (echte Messwerte) |
| `chargeEnableID` | int | Variablen-ID Ladefreigabe (Bool, per `RequestAction` schreibbar) — **nur fürs EMS**, nicht für Anzeigemodule |
| `currentLimitID` | int | Variablen-ID Stromlimit (A, per `RequestAction` schreibbar) — **nur fürs EMS**; Summenlimit, phasengetrennte Limits ggf. später additiv |
| `plugStateID` | int | Variablen-ID „Fahrzeug verbunden" (Bool); 0 wenn die Wallbox es nicht liefert (z. B. Alfen) |
| `minCurrent` | int | Wert (A): kleinster gültiger Ladestrom (6 A). Fällt das EMS-Budget darunter, pausiert es über `chargeEnableID` statt ein ungültiges Limit zu setzen |
| `maxCurrent` | int | Wert (A): wirksame Obergrenze = min(Hardware-Limit des Herstellers, Property „Maximaler Anschlussstrom"). Jeder Schreibzugriff wird im Treiber zusätzlich hart darauf geklemmt |
| `managedBy` | string | Wer hat die Hoheit über den Ladepunkt: `none`, `ems`, `goe-controller`, `tibber`, `p14a`, `marketer`, `other`. Bei allem außer `none`/`ems` steuert das EMS **nicht** selbst; `tibber` gilt als harte Sperre (Regelenergie, Pönale-Risiko). In der Instanz als Auswahlfeld, je Hersteller passende Teilmenge (`goe-controller` nur beim go-eCharger) |
| `externallyManaged` | bool | Abgeleitet aus `managedBy` (`true`, sobald ein anderer Regler als `none`/`ems` die Hoheit hat). Bleibt aus Kompatibilität zu Vertrag 1.0 erhalten |

Vertragsversion (`contractVersion`): aktuell **`1.1`** (managedBy ergänzt; abwärtskompatibel zu 1.0).

Siehe [CLAUDE.md](CLAUDE.md) für die Konventionen des Verbunds.

### go-e Eco-Modus als Alternativpfad (dokumentiert, nicht implementiert)

Statt aktiver Steuerung über `currentLimitID` kann ein EMS (oder der go-e Controller) dem
go-eCharger per HTTP/MQTT-API zyklisch Überschussdaten liefern (API-Key `ids`, alle ~5 s) —
dann regelt der **Eco-Modus der Wallbox selbst** (`fup=true`). Erkennungssignal, dass dieser
Pfad aktiv ist: der API-Key `lpsc` (Zeitpunkt der letzten Überschussrechnung) aktualisiert sich
laufend. Diese Schlüssel liegen **nicht** in der Modbus-Registerkarte; ein optionaler
HTTP-Nebenkanal, der `fup`/`loe`/`modelStatus` ausliest und `externallyManaged` automatisch
setzt, ist angedacht, aber bewusst noch nicht umgesetzt — bis dahin gilt die manuelle
Kennzeichnung in der Instanz.

## Lizenz

PolyForm Noncommercial 1.0.0 — privat/nicht-kommerziell frei, gewerbliche Nutzung lizenzpflichtig (Kontakt: DG65). Siehe [LICENSE](LICENSE).

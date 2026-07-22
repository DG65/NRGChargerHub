# ChargerHub

IP-Symcon-Modul für Wallboxen (Ladestationen für Elektrofahrzeuge) verschiedener Hersteller
per Modbus TCP — analog zu [InverterHub](https://github.com/DG65/InverterHub) (Wechselrichter)
und [MeterHub](https://github.com/DG65/MeterHub) (Energiezähler).

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
| **go-eCharger Gemini/HOME+** | Ladestatus, Ladeleistung, Energie akt. Sitzung/gesamt, optional Spannung/Strom je Phase, Kabel-Codierung, Fehlercode, Seriennummer/Firmware, Steuerung (Ladefreigabe über FORCE_STATE, Stromlimit über AMPERE_VOLATILE 6–32 A) | Gegen die offizielle Herstellerdoku ([go-eCharger-API-v2](https://github.com/goecharger/go-eCharger-API-v2), modbus-de.md) geprüft. FC 0x06 wird von go-e nicht unterstützt, Schreibzugriffe laufen über FC 0x16. Firmware 60.3 hatte einen dokumentierten Byte-Order-Bug (Schalter „Byte-Reihenfolge getauscht"), seit 60.4 behoben. **Modbus muss erst per App/HTTP-API aktiviert werden** (`men=true`) — sonst bleibt Port 502 geschlossen. |

Registeradressen stehen im **Beschreibungsfeld** jeder Variable (Objekt-Manager, Spalte
„Beschreibung") — praktisch zum Abgleich mit dem Herstellerhandbuch.

## Ziel

Ein Modul, ein Auswahlfeld „Wallbox-Hersteller" — je nach Auswahl werden die passenden Register
und Bedienelemente freigeschaltet. Im Unterschied zu MeterHub (nur lesen) braucht ChargerHub auch
Schreibzugriff: Ladefreigabe setzen, Stromlimit setzen — das macht die Anbindung näher an
InverterHub als an MeterHub.

## Installation

1. In der IP-Symcon-Konsole: **Modulverwaltung → Hinzufügen** und die URL dieses Repositories
   eintragen: `https://github.com/DG65/ChargerHub` (Branch **beta**, Default-Branch).
2. Eine neue Instanz vom Typ **„ChargerHub"** anlegen — oder **„ChargerHub Suche"**
   (ChargerHubDiscovery) für einen automatischen Netzwerk-Scan.

## Netzwerk-Suche

Das Modul **ChargerHubDiscovery** durchsucht einen IP-Bereich nach den oben genannten
Wallbox-Typen (Modbus-TCP-Port 502) und legt gefundene Geräte per Klick als ChargerHub-Instanz
mit vorausgefüllter IP-Adresse, Unit-ID und Hersteller an — analog zu InverterHubDiscovery/
MeterHubDiscovery.

## Einbindung in den Modul-Verbund

ChargerHub bietet analog zu `MHUB_GetFunctions` eine Funktion `CHUB_GetFunctions($id)` an, über
die ein EMS oder eine Kachel Ladepunkte, Momentanleistung und Steuerungsmöglichkeiten abfragen
kann. Der Schreib-Teil des Vertrags (`chargeEnableID`, `currentLimitID`) ist ein erster
Vorschlag und noch mit der EMS-Sitzung abzustimmen. Siehe [CLAUDE.md](CLAUDE.md) für die
Konventionen des Verbunds.

## Lizenz

MIT, siehe [LICENSE](LICENSE).

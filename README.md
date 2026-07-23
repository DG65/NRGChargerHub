# ChargerHub

IP-Symcon-Modul für Wallboxen (Ladestationen für Elektrofahrzeuge) verschiedener Hersteller
per Modbus TCP — analog zu [InverterHub](https://github.com/DG65/InverterHub) (Wechselrichter)
und [MeterHub](https://github.com/DG65/MeterHub) (Energiezähler).

Teil der DG65 Energie-Suite — welche Modulstände zusammenpassen, listet
[SUITE.md](https://github.com/DG65/EMS/blob/main/SUITE.md).

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
| **go-eCharger Gemini/HOME+** | Ladestatus, Ladeleistung, Energie akt. Sitzung/gesamt, optional Spannung/Strom/Leistung je Phase (+ N), genutzte Phasen nach Schütz, Kabel-Codierung, Adapter, RFID-Karte, Fehlercode, Seriennummer/Firmware; Steuerung: Ladefreigabe (FORCE_STATE), Stromlimit (AMPERE_VOLATILE 6–32 A), **Phasenumschaltung** (Auto/1-/3-phasig), Zugangskontrolle, Kabelverriegelung, Energie-Limit je Ladevorgang, LED-Helligkeit — Steuerwerte werden vom Gerät zurückgelesen | Gegen die offizielle Herstellerdoku ([go-eCharger-API-v2](https://github.com/goecharger/go-eCharger-API-v2), modbus-de.md) geprüft. FC 0x06 wird von go-e nicht unterstützt, Schreibzugriffe laufen über FC 0x16. Firmware 60.3 hatte einen dokumentierten Byte-Order-Bug (Schalter „Byte-Reihenfolge getauscht"), seit 60.4 behoben. **Modbus muss erst per App/HTTP-API aktiviert werden** (`men=true`) — sonst bleibt Port 502 geschlossen. ⚠️ **Zwei-Regler-Warnung:** Regelt ein go-e Controller die Wallbox bereits selbst (Lastmanagement/Überschussladen), schließen sich EMS-Steuerung und Controller-Regelung gegenseitig aus — eins von beiden deaktivieren, oder in der Instanz „Ladepunkt wird bereits extern geregelt" setzen (wird als `externallyManaged` über `CHUB_GetFunctions` gemeldet, per Modbus ist der Lastmanagement-Status nicht auslesbar). |

Registeradressen stehen im **Beschreibungsfeld** jeder Variable (Objekt-Manager, Spalte
„Beschreibung") — praktisch zum Abgleich mit dem Herstellerhandbuch.

ℹ️ Der **go-e Controller** (Energiemess-Zentrale) wird nicht hier, sondern von
[MeterHub](https://github.com/DG65/MeterHub) unterstützt (ab 0.14.0, inkl. automatischer
Erkennung in der dortigen Zählersuche) — er ist ein Energiezähler, keine Wallbox.

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
kann. Der Vertrag ist **mit der EMS-Entwicklung abgestimmt** (v1); je Ladepunkt ein Eintrag:

| Feld | Typ | Bedeutung |
|---|---|---|
| `contractVersion` | string | Vertragsversion `Major.Minor` (aktuell `'1.0'`); Konsumenten prüfen die Major, additive Felder erhöhen nur die Minor. Fehlt das Feld, gilt konservativ `'1.0'` |
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
| `externallyManaged` | bool | `true` = ein externes Lastmanagement (z. B. go-e Controller) regelt diesen Ladepunkt bereits — EMS darf nur lesen, nie schreiben |

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

MIT, siehe [LICENSE](LICENSE).

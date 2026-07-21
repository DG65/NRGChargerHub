# ChargerHub

IP-Symcon-Modul für Wallboxen (Ladestationen für Elektrofahrzeuge) verschiedener Hersteller
per Modbus TCP — analog zu [InverterHub](https://github.com/DG65/InverterHub) (Wechselrichter)
und [MeterHub](https://github.com/DG65/MeterHub) (Energiezähler).

**Status: Gerüst.** Noch kein konkreter Wallbox-Treiber implementiert.

## Ziel

Ein Modul, ein Auswahlfeld „Wallbox-Typ" — je nach Auswahl werden die passenden Register und
Bedienelemente freigeschaltet. Im Unterschied zu MeterHub (nur lesen) braucht ChargerHub auch
Schreibzugriff: Ladefreigabe setzen, Stromlimit setzen — das macht die Anbindung näher an
InverterHub als an MeterHub.

## Installation

1. In der IP-Symcon-Konsole: **Modulverwaltung → Hinzufügen** und die URL dieses Repositories
   eintragen: `https://github.com/DG65/ChargerHub`
2. Eine neue Instanz vom Typ **„ChargerHub"** anlegen.

## Einbindung in den Modul-Verbund

ChargerHub bietet analog zu `MHUB_GetFunctions` eine Funktion `CHUB_GetFunctions($id)` an, über
die ein EMS oder eine Kachel Ladepunkte, Momentanleistung und Steuerungsmöglichkeiten abfragen
kann. Siehe [CLAUDE.md](CLAUDE.md) für die Konventionen des Verbunds.

## Lizenz

MIT, siehe [LICENSE](LICENSE).

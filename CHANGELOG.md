# Changelog

Alle nennenswerten Änderungen an diesem Modul werden hier dokumentiert.
Format angelehnt an [Keep a Changelog](https://keepachangelog.com/de/1.0.0/).

## [0.2.1-beta.1] - 2026-07-22

### Fixed
- **go-eCharger-Treiber komplett neu**, gegen die offizielle Herstellerdoku
  ([go-eCharger-API-v2](https://github.com/goecharger/go-eCharger-API-v2), modbus-de.md)
  geprüft — der bisherige Registersatz (Platzhalter, Register 1000ff.) war frei erfunden und
  falsch. Neu: korrekte Input-/Holding-Register (CAR_STATE @100, POWER_TOTAL @120,
  ENERGY_CHARGE @132, Phasenspannungen/-ströme, Fehlercode, Seriennummer/Firmware),
  Ladefreigabe über FORCE_STATE (Holding 337, nicht das rein informative ALLOW-Register),
  Stromlimit über AMPERE_VOLATILE (Holding 299, EEPROM-schonend). Schreibzugriffe laufen
  jetzt über FC 0x16 (writeMultiple) statt FC 0x06, da go-e Einzelregister-Schreiben laut
  Doku nicht unterstützt. Neuer Schalter „Byte-Reihenfolge getauscht" für den dokumentierten
  Firmware-60.3-Bug (behoben mit 60.4).
- `ModbusTcpClient::u32sw()` ergänzt (wortgetauschte 32-Bit-Werte, analog zu MeterHub).
- Discovery-Sonde für go-eCharger auf die echten Register (Input 100, Holding 201) umgestellt.

## [0.2.0-beta.1] - 2026-07-22

### Added
- **Vier Wallbox-Treiber**: KEBA KeContact P30/P40, Alfen Eve Single/Double Pro-line,
  Heidelberg Energy Control, go-eCharger Gemini/HOME+ — je mit Ladestatus, Ladeleistung,
  optionalen Datenpunkt-Gruppen (Strom/Spannung je Phase, Geräteinfo) sowie Steuerung
  (Ladefreigabe, Stromlimit) über `RequestAction`.
- `ModbusTcpClient` und `ChargerDriverInterface` als treiberunabhängiges Grundgerüst,
  analog zu InverterHub/MeterHub.
- Variablen-Registrierung inkl. automatischem Aufräumen verwaister Variablen beim
  Herstellerwechsel oder Abwählen einer Datenpunkt-Gruppe.
- `CHUB_GetFunctions($id)` — erster Vorschlag für den Partnermodul-Vertrag (powerID,
  energyImportID, chargeEnableID, currentLimitID); der Schreib-Teil ist laut CLAUDE.md
  noch mit der EMS-Sitzung abzustimmen und daher noch nicht als stabil zu betrachten.
- **Neues Modul: ChargerHubDiscovery** — durchsucht einen IP-Bereich nach den vier
  unterstützten Wallbox-Typen und legt gefundene Geräte per Klick als ChargerHub-Instanz
  an, strukturell wie InverterHubDiscovery/MeterHubDiscovery.

### Fixed
- `PruneForeignObjects` prüfte nur die direkten Kinder der Instanz, in denen aber nur
  Kategorien liegen (die eigentlichen Variablen stecken darunter) — verwaiste Variablen
  eines abgewählten Herstellers oder einer deaktivierten Gruppe wurden dadurch nie entfernt.
  Jetzt rekursiv wie bei InverterHub.

### Known Issues
- **Registeradressen aller vier Treiber sind noch nicht an echter Hardware verifiziert**,
  nur aus den öffentlichen Hersteller-Dokumentationen abgeleitet. Vor Produktiveinsatz,
  insbesondere der Schreibfunktionen, bitte gegen ein reales Gerät prüfen und Rückmeldung
  geben (Modell + betroffenes Register).

## [0.1.0] - 2026-07-21

### Added
- Repo-Gerüst angelegt: Modul-Skelett (`ChargerHub/module.php`, `module.json`), `library.json`,
  README, LICENSE, CLAUDE.md, `.tools/check-standalone.php`
- Noch kein konkreter Wallbox-Treiber implementiert

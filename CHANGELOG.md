# Changelog

Alle nennenswerten Änderungen an diesem Modul werden hier dokumentiert.
Format angelehnt an [Keep a Changelog](https://keepachangelog.com/de/1.0.0/).

## [0.6.1-beta.1] - 2026-07-22

### Fixed
- **Füllwert-Schutz** (MeterHub-Befund am echten go-e Controller): go-e-Firmware
  beantwortet unbelegte Register mit 0xFF-Füllwerten statt einer Modbus-Exception.
  go-e-U32-Werte mit 0xFFFFFFFF und U16-Werte mit 0xFFFF werden jetzt verworfen
  (sonst landete z. B. eine Leistung von 42.949.672,95 W im Archiv); KEBA-Helfer
  ebenso abgesichert; `SetVarFloat` verwirft generell NaN/Inf (schützt auch die
  Float32-Treiber wie Alfen).

### Changed
- README: Verweis auf MeterHub für den go-e Controller (dort ab 0.14.0 unterstützt).

## [0.6.0-beta.1] - 2026-07-22

### Added
- **Zwei-Regler-Schutz** (Hinweis vom EMS): Der go-e Controller kann Wallboxen selbst per
  Lastmanagement/Überschussladen regeln — parallele EMS-Steuerung würde sich mit ihm
  gegenseitig überschreiben. Neue Kennzeichnung „Ladepunkt wird bereits extern geregelt"
  in der Instanz, gemeldet über `CHUB_GetFunctions` als neues Feld `externallyManaged`
  (bool), damit das EMS solche Ladepunkte automatisch von der eigenen Steuerung ausnimmt.
  Automatische Erkennung ist per Modbus nicht möglich (die Lastmanagement-Zustände
  `loe`/`loa` existieren nur in der HTTP/MQTT-API) — daher manuelle Kennzeichnung.
  Warnhinweise in Formular und README ergänzt.

## [0.5.0-beta.1] - 2026-07-22

### Removed
- **go-e Controller wieder ausgebaut** (war in 0.3.0 dazugekommen): Als reine
  Energiemess-Zentrale gehört er fachlich zu MeterHub, nicht zu ChargerHub — dorthin
  wird er umgezogen (Registerkarte und Erkennungssignatur wurden an die
  MeterHub-Entwicklung übergeben). `ModbusTcpClient::readDouble64()` bleibt als
  generischer Helfer erhalten.

## [0.4.0-beta.1] - 2026-07-22

### Fixed
- **KEBA-Treiber gegen die evcc-Referenzimplementierung korrigiert** (charger/keba-modbus.go,
  an realer Hardware erprobt) — mehrere echte Fehler: Alle KEBA-Werte sind U32 über
  2 Register (der bisherige 1-Register-Read des Ladestatus hätte immer 0 geliefert);
  gelesen wird per FC 0x03 (Holding), nicht FC 0x04; Kabelstatus liegt auf 1004 (nicht
  1002); 1036 ist die GESAMT-Energie, die Sitzungsenergie liegt auf 1502; Ladefreigabe
  auf Holding 5014 (nicht 5004 — dort liegt das Stromlimit); Status-Enum beginnt bei 0.
  Kein Block-Read über Wertegrenzen (KEBA lehnt das ab) — jeder Datenpunkt einzeln.
  Neu dabei: Energie gesamt, Spannungen je Phase, Firmware-Version, Kabelstatus-Enum.
- Discovery-Sonde für KEBA entsprechend auf U32/FC 0x03 umgestellt (bisher hätte sie
  echte KEBAs praktisch nie erkannt).

### Changed
- **Abstimmung mit MeterHub umgesetzt** (Zählersuche matcht auf Profil-Suffix):
  Sitzungsenergie (`energy_session`) trägt jetzt bei KEBA und go-eCharger ein eigenes
  Profil `CHB.kWhSession` mit Suffix „ kWh (Sitzung)" — damit nimmt die
  MeterHubVirtual-Zählersuche nur noch den kumulativen Gesamtzähler (`energy_total`,
  Suffix „ kWh") auf und nicht den je Ladevorgang zurückspringenden Sitzungswert.
- `CHUB_GetFunctions`: `energyImportID` liefert bevorzugt `energy_total` (kumulativ),
  Fallback `energy_session`; Hinweis ergänzt, dass die Steuer-IDs fürs EMS bestimmt
  sind, nicht für Anzeigemodule.

## [0.3.0-beta.1] - 2026-07-22

### Added
- **Neuer Gerätetyp: go-e Controller** (Energiemess-Zentrale, nur lesend) — Spannungen
  L1/L2/L3/N, 6 Stromsensoren (Strom + Leistung), Kategorien Home/Netz/Fahrzeug/Relais/
  Solar/Batterie mit Leistung und Energiezählern In/Out (Float64 Wh → kWh). Register gemäß
  offizieller Doku ([go-eController-API](https://github.com/goecharger/go-eController-API),
  modbus-de.md). Auch in der Discovery (Spannungs-Signatur auf Input 1000/1002).
- `ModbusTcpClient::readDouble64()` (Float64 Big-Endian, wie PAC2200 in MeterHub).

### Changed
- Discovery-Hilfetext: Hinweis, dass go-e-Geräte bei nicht (wirklich) laufendem
  Modbus-Server Port 502 geschlossen halten und damit für den Scan unsichtbar sind —
  inkl. Prüf-URL (`/api/status?filter=men`). Real beobachtet: Auch bei gespeichertem
  „aktiviert" lief der Server erst nach Aus-/Einschalten der Einstellung bzw. Neustart.

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

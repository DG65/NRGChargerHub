# Changelog

Alle nennenswerten Änderungen an diesem Modul werden hier dokumentiert.
Format angelehnt an [Keep a Changelog](https://keepachangelog.com/de/1.0.0/).

## [0.9.8-beta.1] - 2026-07-24

### Changed
- Diagnose-Experiment aus 0.9.7 zurückgebaut (Testvariable `DiagTestAction` samt A/B-Vergleich
  entfernt) — die Objekt-ID-Änderung der Instanz zwischen zwei Tests stellte sich als manuelle
  Neuanlage durch den Nutzer heraus, nicht als Nebeneffekt unseres Codes. `SetControlActions()`
  bleibt auf dem einfachen Diagnose-Stand aus 0.9.6 (Log je Steuer-Ident), Ursache des
  `IPS_SetVariableCustomAction`-Fehlers weiterhin offen.

## [0.9.7-beta.1] - 2026-07-24

### Changed
- **Diagnose erweitert (A/B-Test)**: Der "Skript #<InstanceID> existiert nicht"-Fehler tritt
  laut Log auch über den `TimerPool`-Weg auf (nicht nur synchron aus `ApplyChanges()`) — die
  Transaktions-Theorie aus 0.9.6 ist damit widerlegt. Neue temporäre Testvariable
  `🧪 Diagnose Testaktion`, ganz normal über `RegisterVariableBoolean()` angelegt (statt
  unseres bisherigen rohen `IPS_CreateVariable()`-Wegs für Steuer-Variablen), bekommt in
  `SetControlActions()` versuchsweise ebenfalls eine Custom Action. Klärt, ob die
  Anlage-Methode der Variable der Unterschied ist.

## [0.9.6-beta.1] - 2026-07-24

### Fixed
- **Ursache des "Ladefreigabe/Stromlimit nicht bedienbar"-Bugs live bestätigt und behoben.**
  Der in 0.9.4 ergänzte synchrone `SetControlActions()`-Aufruf am Ende von `ApplyChanges()`
  war der eigentliche Fehler: `IPS_SetVariableCustomAction($vid, $this->InstanceID)`,
  aus der EIGENEN laufenden `ApplyChanges()`-Transaktion heraus aufgerufen, schlägt mit
  `Warning: Skript #<InstanceID> existiert nicht` fehl (live reproduziert — Symcon
  behandelt die eigene Instanz während der eigenen Transaktion als ungültiges Aktionsziel,
  nicht nur bei der Instanz-Erstellung, sondern bei jedem "Übernehmen"). Der synchrone
  Aufruf ist wieder entfernt; einziger Weg ist jetzt wie ursprünglich vorgesehen der
  200-ms-`EnableActionsTimer`, der außerhalb der Transaktion feuert.
- Diagnose-Log (`IPS_LogMessage('ChargerHub-Diagnose', …)`) bleibt vorerst zur Bestätigung
  bestehen, dass der Timer-Weg jetzt fehlerfrei durchläuft.

## [0.9.4-beta.1] - 2026-07-24

### Fixed
- **Steuer-Variablen (Ladefreigabe, Stromlimit, Phasenumschaltung, …) blieben nicht
  bedienbar** — echter Fund aus dem Live-Test: `IPS_SetVariableCustomAction` lief bislang
  nur über einen 200-ms-Timer nach `ApplyChanges`, der bei bestehenden Instanzen (nach
  einem Modul-Update, ohne dass "Übernehmen" die Timing-Kette erneut anstößt) nicht
  zuverlässig griff — die Variablen blieben reine Anzeige (kein fett dargestelltes
  Bedienelement in der Konsole), Schreibversuche liefen ins Leere. Jetzt zusätzlich
  **synchron am Ende von `ApplyChanges()`** versucht (`SetControlActions()`, mit `@`
  gegen die bekannte Erstellungs-Transaktions-Race abgesichert); der Timer bleibt als
  Rückfallebene für die Instanz-Neuanlage bestehen. **Bestehende Instanzen brauchen nach
  dem Update einmal "Übernehmen".**

## [0.9.3-beta.1] - 2026-07-24

### Changed
- **Layout-Nachbesserung** (Verbund-Konvention „logische Gruppierung", Pflicht-Prüfung bei
  jedem Fix): `ManagedBy` (Regler-Hoheit) und `MaxCurrent` (Anschlussstrom) waren
  sachfremd verstreut — `MaxCurrent` unter „Verbindung", `ManagedBy` unter „Datenpunkte".
  Beides sind Steuer-/Sicherheitsfelder, jetzt gemeinsam im neuen Panel
  „🛡️ Steuerungshoheit & Sicherheit" zwischen Polling und Datenpunkte.

## [0.9.2-beta.1] - 2026-07-24

### Added
- **Einheitliche Formular-Optik** (Verbund-Konvention, SUITE.md, Referenz InverterHub):
  „🆕 Neu in Version …"-Banner (aufgeklappt, je Version einmalig ausblendbar über ein
  Attribut, erscheint bei neuer Version automatisch wieder), Versionsnummer im
  „📖 Dokumentation & Hilfe"-Panel, `🆕`-Präfix am neuen Auswahlfeld „Wer regelt diesen
  Ladepunkt?", sowie ein einmalig ausblendbarer Rückmeldungs-Hinweis nach den
  Haupteinstellungen (noch ohne Forum-Link, da noch nicht gepostet — verweist vorerst auf
  GitHub). Muster: `UpdateFormField` + Attribut, nie `IPS_SetProperty`+`ApplyChanges`.

## [0.8.1-beta.1] - 2026-07-22

### Changed
- **Sprachregel des Verbunds umgesetzt** (Anweisung Dietmar): Nutzersichtbare Texte auf
  Deutsch, vermeidbare Anglizismen ersetzt — „Scan abbrechen" → „Suche abbrechen",
  „Portscan" → „Port-Prüfung", „Bug"/„Byte-Order-Bug" → deutsche Formulierung, „Test:" →
  „zum Prüfen im Browser aufrufen". Idents, Property-Namen und die
  `CHUB_GetFunctions`-Feldnamen bleiben unverändert (sind API).
- CLAUDE.md: Sprachregel und der Grundsatz „Idents sind API" als Verbund-Regeln festgehalten.

## [0.9.1-beta.1] - 2026-07-24

### Changed
- **Migration auf gemeinsame `NRG.*`-Variablenprofile** (Verbund-Konvention, SUITE.md):
  `CHB.Watt`/`CHB.kWh`/`CHB.Ampere`/`CHB.Volt`/`CHB.Celsius` → `NRG.Watt`/`NRG.kWh`/
  `NRG.Ampere`/`NRG.Volt`/`NRG.Celsius`. Anlage bleibt idempotent und ohne Eigentümer-Modul:
  ein bereits vorhandenes `NRG.*`-Profil wird nicht mehr überschrieben, nur bei Fehlen angelegt.
  `NRG.kWh`-Wertebereich vereinheitlicht (0–9.999.999 kWh).
  Modulspezifische Steuer-/Status-Profile (`CHB.Ampere6to32`, `CHB.Ampere10to63`,
  `CHB.kWhSession`, `CHB.kWhLimit`, `CHB.Led255`, alle `CHB.*State`/`CHB.*Mode`-Enums) bleiben
  bewusst unter `CHB.*` — sie tragen keine austauschbare physikalische Einheit oder haben eine
  Sonderbedeutung (z. B. `CHB.kWhSession`, damit die MeterHub-Zählersuche den je Ladevorgang
  zurückspringenden Sitzungswert nicht als Energiezähler aufnimmt).
  Reine Anzeige-Migration, keine Vertrags-/Ident-Änderung.

## [0.9.0-beta.1] - 2026-07-23

### Changed
- **Lizenz: MIT → PolyForm Noncommercial 1.0.0** (verbundweit, nur nach vorn wirkend;
  ältere Versionen bleiben MIT).
- **Regler-Kennzeichnung `managedBy`** (Vertrag `contractVersion` → **1.1**, additiv): Aus der
  Checkbox „extern geregelt" wird ein Auswahlfeld „Wer regelt diesen Ladepunkt?" mit dem
  Verbund-Vokabular `none`/`ems`/`goe-controller`/`tibber`/`p14a`/`marketer`/`other`. Je
  Hersteller nur die passende Teilmenge (`goe-controller` nur beim go-eCharger). `managedBy`
  kommt neu in `CHUB_GetFunctions`; `externallyManaged` bleibt und wird daraus abgeleitet
  (true außer bei `none`/`ems`). Alt-Property `ExternallyManaged` wird als Migrationsrückfall
  weitergelesen (alter Haken → `other`), ein für den Hersteller ungültiger gespeicherter Wert
  wird konservativ auf `other` abgebildet (EMS bleibt dann read-only).

## [0.8.1-beta.1] - 2026-07-23

### Added
- **Vertragsversionierung** (Verbund-Konvention, [SUITE.md](https://github.com/DG65/EMS/blob/main/SUITE.md)):
  `CHUB_GetFunctions` liefert additiv `contractVersion => '1.0'`. Konsumenten prüfen die
  Major-Version; additive Felder erhöhen künftig nur die Minor, ein Bruch die Major.
- README: Hinweis auf die DG65 Energie-Suite und SUITE.md (welche Modulstände zusammenpassen).

## [0.8.0-beta.1] - 2026-07-22

### Added
- **go-eCharger: deutlich mehr Datenpunkte, v. a. Steuerung** (Rückmeldung aus dem
  Live-Betrieb an zwei V3-Chargern — das Einlesen selbst lief fehlerfrei):
  - Steuerung neu: **Phasenumschaltung** (Auto/1-/3-phasig, Reg 332 `psm` — wichtig fürs
    Überschussladen), **Zugangskontrolle** (Offen/RFID/Strompreis/Scheduler, Reg 201),
    **Kabelverriegelung** (Reg 204), **Energie-Limit je Ladevorgang** (Reg 333-336 `dwo`,
    Float64 Wh, 0 = kein Limit → schreibt Inf), **LED-Helligkeit** (Reg 206).
  - Steuerwerte werden jetzt **vom Gerät zurückgelesen** — die ctl_*-Variablen zeigen
    damit auch Änderungen aus App/Cloud/anderen Reglern.
  - Status neu: Leistung je Phase (146-151), Spannung N (144), **aktiv genutzte Phasen
    nach dem Schütz** (Bitmaske Reg 205), Adapter-Erkennung (202), entsperrende
    RFID-Karte (203).
  - `ModbusTcpClient::writeDouble64()` (Float64 Big-Endian über FC 0x16).

## [0.7.0-beta.1] - 2026-07-22

### Added
- **`CHUB_GetFunctions`-Vertrag v1 — mit dem EMS abgestimmt und final umgesetzt:**
  - Neu `plugStateID`: Variablen-ID „Fahrzeug verbunden" (Bool) — KEBA (Kabelstatus ≥ 5),
    go-eCharger (CAR_STATE 2/3/4) und Heidelberg (Status 3–8 ohne Fehler) liefern sie;
    Alfen hat kein dokumentiertes Steckerkennungs-Register → 0.
  - Neu `minCurrent`/`maxCurrent` als **Werte** (keine IDs): min immer 6 A;
    max = min(Hardware-Limit des Herstellers [KEBA 63 / Alfen 32 / Heidelberg 16 /
    go-e 32 A], neue Property „Maximaler Anschlussstrom").
  - Neue Property/Formularfeld **„Maximaler Anschlussstrom (A)"** (Default 16): harter
    Clamp in JEDEM Treiber-Schreibzugriff — letzte Verteidigungslinie, egal was ein EMS
    anfordert.
- README: vollständige Feldtabelle des Vertrags; Abschnitt zum go-e-Eco-Modus-Alternativpfad
  (`ids`-Feed + `fup`/`lpsc`-Erkennung, HTTP/MQTT — dokumentiert, bewusst nicht implementiert).
- CLAUDE.md: Vertrag als abgestimmt (v1) festgeschrieben.

### Fixed
- Heidelberg: Stromlimit-Clamp auf das reale Hardware-Maximum 16 A statt 32 A.

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

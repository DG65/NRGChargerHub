# Changelog

Alle nennenswerten Änderungen an diesem Modul werden hier dokumentiert.
Format angelehnt an [Keep a Changelog](https://keepachangelog.com/de/1.0.0/).

Ältere Versionen: [CHANGELOG-Archiv.md](CHANGELOG-Archiv.md)

## [0.9.109-beta.1] - 2026-09-28

### Fixed
- Statuszeile der Dubletten-Zuordnung folgte nur beim Öffnen des Formulars der Auswahl, nicht bei
  einer Änderung (Verstoß gegen SUITE.md „Auswahlfelder: Zeile folgt der Auswahl“, EMS-Fund
  28.09.2026 im verbundweiten Formular-Review). Das Auswahlfeld „Diese Wallbox ist dasselbe Gerät
  wie …“ aktualisiert die Zeile jetzt bei jeder Änderung live, mit der noch nicht übernommenen
  Auswahl (nicht der gespeicherten Property).

## [0.9.108-beta.1] - 2026-09-28

### Fixed
- Variablen-Position wurde bei JEDEM „Übernehmen“ neu gesetzt, nicht nur bei Neuanlage einer
  Variable (Symcon-Review-Fund, SUITE.md 9m, ausgelöst durch HeishaMons abgelehntes v1.33.0;
  Referenz InverterHub-Fix). Eine manuelle Umsortierung der Variablen im Objektbaum wurde dadurch
  bei jedem „Übernehmen“ wieder auf die feste Reihenfolge zurückgesetzt. Die Position wird jetzt
  nur noch bei der Neuanlage einer Variable gesetzt.

## [0.9.107-beta.1] - 2026-09-24

### Added
- Neue Option „Dauerhafte Verbindung statt für jeden Zugriff neu zu verbinden“ (Panel
  „Steuerungshoheit & Sicherheit“, Standard aus, nur direkter Verbindungsweg). Fund sieckendieck
  (DaheimLader Touch PRO, 22.-24.09.2026): Steuerbefehle blieben trotz bestätigter Schreibantwort
  wirkungslos, solange ChargerHub häufig pollte (2-10 s); mit dem Lese-Intervall auf Maximum
  (300 s, kaum noch Verbindungen) startete die Ladung sofort. Das häufige Verbinden/Trennen
  brachte offenbar den kleinen Modbus-Server der Box aus dem Tritt. Mit der neuen Option hält
  ChargerHub stattdessen eine Verbindung offen (geteilt über Host+Port, auch über mehrere
  ChargerHub-Instanzen zum selben Gerät hinweg, z. B. mehrere CHARX-Ladepunkte an einer IP;
  Zugriffe darauf per IPS-Semaphore serialisiert, bei Fehler wird neu verbunden). Betrifft nur den
  direkten TCP-Weg (nicht Modbus ASCII/ABL, nicht den Symbox-Gateway-Weg).

## [0.9.106-beta.1] - 2026-09-22

### Added
- DaheimLader: neue Einstellung „Ladefreigabe“ (Panel „Steuerungshoheit & Sicherheit“): „Über
  Ladebefehl, Register 95“ (Standard, unverändert) oder „Über Stromlimit, Register 91“ wie evcc
  (charger/daheimladen.go: Freigabe = Limit mindestens 6 A, Sperre = 0,1 A, weil 0 nach einem
  Neustart als Autostart-Freigabe gilt; vor jedem Freigabe-Befehl 1 s Pause, weil die Box zu
  schnelle Befehle verwirft). Im Limit-Modus wird die Freigabe aus Register 91 zurückgelesen.
  Anlass: Bei sieckendieck (Touch PRO) startet die Box über Register 95 nicht, obwohl die
  Schreibanfrage identisch zu seinem funktionierenden Direktzugriff ist. evcc nutzt Register 95
  gar nicht. Ob der Limit-Modus bei ihm hilft, ist offen.
- Hinweis zur Einrichtung ergänzt: laut evcc-Vorlage Smart „Nachladen“, Touch „RSDA“ aktivieren.

## [0.9.105-beta.1] - 2026-09-21

### Fixed
- DaheimLader: Register 0x300A („Automatische Phasenumschaltung“) wird nach dem ersten
  Fehlschlag nicht mehr bei jedem Poll abgefragt (bis zum nächsten „Übernehmen“). Es lief bei
  Touch PRO ständig in eine Modbus-Exception 2 („Register nicht vorhanden“). Seit dieser
  Abfrage (0.9.79) lässt sich die Box laut sieckendieck über ChargerHub weder starten noch stoppen,
  obwohl das Protokoll die identische Schreibanfrage zeigt wie sein funktionierender Direktzugriff.
  Ob der Dauerfehler die Ursache ist, ist noch nicht bestätigt.

## [0.9.104-beta.1] - 2026-09-21

### Added
- Neue Option „Schreibbefehle im Meldungen-Log protokollieren (Fehlersuche)“ (Panel
  „Steuerungshoheit & Sicherheit“, Standard aus). Schreibt je Steuerbefehl Funktionscode, Register,
  Werte, Unit-ID sowie Anfrage und Antwort als Hex ins Log („ChargerHub-Schreibprotokoll“). Anlass:
  DaheimLader startet über ChargerHub nicht, direkt per Modbus schon (sieckendieck) — so lässt sich
  vergleichen, was tatsächlich gesendet wird. Gilt für die direkte TCP-Verbindung.

## [0.9.103-beta.1] - 2026-09-21

### Fixed
- DaheimLader: Der Schalter „Automatische Phasenumschaltung“ (Register 0x300A) zeigte „Aus“, auch
  wenn die Box das Register gar nicht kennt (Modbus-Exception 2 bei einer Touch PRO,
  Rückmeldung sieckendieck). Ist das Register nicht lesbar, wird die Variable jetzt ausgeblendet
  statt einen falschen Wert zu zeigen; sie erscheint wieder, sobald das Register lesbar ist. Die
  frühere Vermutung, diese Automatik überschreibe manuelle Umschaltbefehle, ist damit nicht belegt.

## [0.9.102-beta.1] - 2026-09-21

### Added
- Neuer Hersteller: Phoenix Contact CHARX SEC-3xxx (Ladesteuerung), **experimentell**. Registerkarte
  aus dem Handbuch CHARX SEC-XXXX (Anhang 8.4, Wunsch Mstaudi): Ladestatus, Leistung, Energie
  gesamt/Sitzung, Spannung/Strom je Phase, Kennung, Software-Version, Freigabe-Art, Fehlercode;
  Steuerung Ladefreigabe (x300) und Stromlimit 6-80 A (x301). Ein Registerblock je Ladepunkt
  (Startadresse = Nummer x 1000), neue Property „Nummer des Ladepunkts“ (nur CHARX, additiv,
  öffentliche Funktion `CHUB_GetChargePointNo`). Netzwerksuche erkennt die Steuerung über
  Register 114 und 100. Watchdog (x306/x307) und Phasenumschaltung sind nicht umgesetzt.

## [0.9.101-beta.1] - 2026-09-21

### Fixed
- Alfen: Ein gesetztes Stromlimit/Ladefreigabe „Aus“ verfiel, weil die Box nach Ablauf der
  Gültigkeit (Register 1208, zählt rückwärts, an der Box einstellbar) auf ihren Standardstrom
  zurückfällt und ChargerHub den Sollwert nie erneuerte. Jetzt wird der Sollwert (Register 1210)
  kurz vor Ablauf (unter 120 s Restzeit) erneuert, aber nur, wenn er dem zuletzt von uns
  geschriebenen Wert entspricht; ein fremder Sollwert wird nicht verlängert. Voraussetzung: das
  Lese-Intervall ist kürzer als die an der Box eingestellte Gültigkeitsdauer. (Auskunft tkpage)

## [0.9.100-beta.1] - 2026-09-21

### Changed
- Statuszeilen mit „🔗“ (automatisch übernommen) werden grün dargestellt (Label-`color`
  0x2E8B3D, sonst Standardfarbe), Verbund-Konvention SUITE.md 21.09.2026.

## [0.9.99-beta.1] - 2026-09-21

### Changed
- Formular „Wert kommt automatisch“ (SUITE.md 21.09.2026): Der NAP-Zähler für das Überschussladen
  wird bei automatischer Erkennung über MeterHub nicht mehr als leeres Eingabefeld gezeigt. Das
  Feld liegt jetzt im eingeklappten Panel „✏️ Anderen Netzzähler stattdessen verwenden“, die
  Statuszeile lautet „🔗 Netzzähler: … (automatisch von MeterHub …)“. Eigene Wahl: „✏️ …“, Panel
  offen. Nichts automatisch gefunden: Panel offen. Der automatische Wert wird nie ins Feld
  geschrieben.

## [0.9.98-beta.1] - 2026-09-21

### Added
- Formular: live berechnete Statuszeilen für jede automatische Verbindung zu einem Partnermodul
  (Verbund-Konvention „Verbindungen sichtbar machen“, SUITE.md 21.09.2026): Brücke (Gateway-Weg),
  Dubletten-Zuordnung (OCPPHub/ChargerHub), EMS, Netzzähler (MeterHub), Speicher (InverterHub),
  zugeordnetes Fahrzeug. Je ✅ verbunden mit Werten und Quelle, ⚠️ verbunden ohne Brauchbares,
  ℹ️ nicht gefunden und was dann gilt, ⛔ Pflichtangabe fehlt. Die Zeilen werden in
  `GetConfigurationForm()` rekursiv über alle `items` eingesetzt.

## [0.9.97-beta.1] - 2026-09-21

### Fixed
- Suche: Peblar wird jetzt mit Unit-ID 1 (an echter Hardware bestätigt) und danach 255
  gesucht. Bisher wurde nur 255 versucht, das an keiner echten Box geprüft ist.

## [0.9.96-beta.1] - 2026-09-20

### Fixed
- Alfen: Registeradressen für Spannung und Strom je Phase lagen ein Register zu weit
  (Spannung 308/310/312 statt 306/308/310, Strom 322/324/326 statt 320/322/324). Dadurch
  zeigte L1 die Werte von L2, L2 die von L3 und L3 blieb bei 0. Ladeleistung (344) war richtig.
  Fund tkpage (Forum), Gegenprüfung an seiner Symcon-Vorlage.
- Alfen: Steuern schrieb fälschlich in die nur lesbaren Register 1212 (Sicherheitsstrom,
  Float32) und 1214 („Sollwert berücksichtigt“). Jetzt wird nur noch der Stromlimit-Sollwert
  (Register 1210, Float32, FC 0x10) geschrieben; die Variablen ändern sich nur bei erfolgreichem
  Schreiben.

### Added
- Alfen: Phasenumschaltung 1/3 (Register 1215, U16, 1 = einphasig, 3 = dreiphasig), Variable
  `ctl_phase_mode`. Register-Schema aus der Vorlage von tkpage, der damit an seiner Eve Single
  regelmäßig umschaltet. Ob die Phasenumschaltung im Überschussladen greift, hängt wie bei den
  anderen Herstellern an dieser Variable.

## [0.9.95-beta.1] - 2026-09-20

### Added
- `CHUB_GetFunctions`, Vertrag 1.6 (EMS-Anfrage): optionale Felder `phases` (1/3) und
  `phasesSwitchable` (bool). Nur befüllt, wenn das Gerät es selbst meldet (Peblar Input
  30092/30093; go-e Umschaltregister). Fehlt ein Feld, ist der Wert unbekannt. Additiv, keine
  Umbenennung. `minCurrent` (feste 6 A) und `maxCurrent` (Herstellervorgabe, begrenzt durch die
  Property „Maximaler Anschlussstrom“) bleiben unverändert und sind keine Gerätemesswerte.

## [0.9.94-beta.1] - 2026-09-20

### Added
- Peblar: Variable „Energie akt. Sitzung“ aus Input 30004 (INT64, Wh), Wunsch Mstaudi.

## [0.9.93-beta.1] - 2026-09-20

### Fixed
- Peblar: Ladefreigabe und Stromlimit wurden nie vom Gerät zurückgelesen. Die Freigabe zeigte
  dadurch dauerhaft „Aus“, und ein gesetztes Stromlimit wurde dann nur gemerkt statt an die
  Wallbox geschrieben. Jetzt wird Holding 40000 mitgelesen (Freigabe = Limit > 0, Limit in A).
  (Fund Mstaudi im Forum)
- Verbindungs-Formular: Felder des Symbox-Gateway-Wegs (Gateway, Brücke, Hinweise) blieben im
  Direkt-Modus sichtbar, weil Symcon `visible`-Ausdrücke mit `$ConnectionType` nicht zuverlässig
  auswertet. Umschaltung jetzt wie in MeterHub/InverterHub per `onChange` + `UpdateFormField`.
- Unit ID: Höchstwert 247 → 255 (Modbus TCP erlaubt 255; Peblar/DaheimLader-Suche nutzt 255,
  das Formular meldete dann einen Fehler).

### Changed
- Lese-Intervall: Mindestwert 5 s → 2 s (Wunsch Mstaudi).

## [0.9.92-beta.1] - 2026-09-20

### Changed
- Anzeigenamen vereinheitlicht (Auftrag Dietmar, Vorbild MeterHub 0.31.3): jeder Alias in
  `module.json` ist in „Instanz hinzufügen" ein eigener Eintrag und der Vorschlagsname neuer
  Instanzen. Jetzt genau EIN Alias je Modul nach dem Muster „NRG-Stack ChargerHub …":
  „NRG-Stack ChargerHub", „NRG-Stack ChargerHub Suche", „NRG-Stack ChargerHub Brücke
  (ModBus-Gateway)". Die Zweit-Aliase „Wallbox (Multi-Hersteller)" und „Wallbox Suche"
  entfallen (deren Schnellfilter-Suchbegriffe finden die Module nicht mehr). Modulname,
  GUID und Prefix unverändert.
- Statuszeile 104 ist in jedem Verbindungsweg neutral („Bitte Verbindung einstellen.").
  Sie folgt dem gespeicherten Stand und ließ sich im offenen Formular nicht live umschalten
  (Wechsel Symbox -> Direkt zeigte weiter „Brücke eintragen", Fund Mstaudi).

## [0.9.91-beta.1] - 2026-09-19

### Added
- Neuer Hersteller (EXPERIMENTELL): **Peblar** Home/Home Plus/Business (auch ChargeLine),
  Dietmars Entscheidung nach Anfrage des Forum-Testers Mstaudi, der künftig nur noch
  Peblar einbaut und als Hardware-Prüfer eingeplant ist. Direkt per Modbus TCP (Port
  502, Unit-ID 255, Firmware 1.6+, Modbus-Server im Web-Interface aktivieren). Registerkarte
  gegen zwei Quellen gegengelesen (Peblars offizieller Beispielclient
  github.com/Peblar/py-modbus-api-client und die evcc-Implementierung): Messwerte als
  Input-Register (FC 0x04) ab 30000, Steuerung als Holding-Register ab 40000; Ladestatus
  (CP-Zustand als ASCII-Zeichencode), Leistung/Energie gesamt, Spannung/Strom/Leistung je
  Phase (nur so viele Phasen lesen, wie Register 30092 meldet — Phase 2/3 liefern bei
  Einphasern eine Exception), Seriennummer/Produkt/Firmware, „Begrenzt durch"
  (Limit-Quelle), Kabelsperre, Steuerung (Ladefreigabe = Stromlimit 0 mA, Stromlimit
  UINT32 in mA) und Einphasig-Erzwingen (nur mit unabhängigem Relais). Bewusst nicht
  übernommen: Sitzungsenergie (evcc entfernte sie als unzuverlässig), Fehler-/Warnungs-
  Bitfelder, tatsächliches Stromlimit (Einheit nicht belegt). Offen für die
  Hardware-Prüfung: ob ein per Modbus gesetztes Stromlimit zyklisch erneuert werden
  muss. Register-Offsets/Schreibwerte mit einem Fake-Client geprüft (3-phasig 11 kW/
  16 A/230 V/12345,678 kWh, Einphaser ohne Phase 2/3, Schreibwerte).
- ChargerHubDiscovery erkennt Peblar (Phasenzahl/Relais/CP-Zustand plausibel).

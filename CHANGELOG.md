# Changelog

Alle nennenswerten Änderungen an diesem Modul werden hier dokumentiert.
Format angelehnt an [Keep a Changelog](https://keepachangelog.com/de/1.0.0/).

Ältere Versionen: [CHANGELOG-Archiv.md](CHANGELOG-Archiv.md)

## [0.9.136-beta.1] - 2026-10-26

### Added
- **Geräte-Übernahme vom alten go-eCharger-Modul:** `CHUB_GetIdentMapping` liefert für die vier Leistungs-Idents
  (`powerToCarTotal`, `powerToCarLineL1/L2/L3`) das additive Feld `archiveFactor` = 1000.0. Das Alt-Modul speichert die Leistung in
  kW, der ChargerHub in W; der Übernehmende (Gerätetausch/MigrationsHub) multipliziert damit jeden archivierten Wert der
  Alt-Variable (fehlt das Feld: 1.0). Ohne diese Umrechnung stand die übernommene Historie 1000-fach zu klein im Archiv
  (Fund Dietmar, 26.10.2026; Wunsch Dietmar).

## [0.9.135-beta.1] - 2026-10-26

### Fixed
- **Energiezähler im Archiv als Zähler:** „Energie gesamt“ (`energy_total`) und die Energie je RFID-Karte werden beim ersten
  Einschalten der Archivierung mit dem Aggregationstyp „Zähler“ angelegt, nicht mehr mit dem Standard (Momentanwert). Bei der
  Geräte-Übernahme vom alten go-eCharger-Modul zieht der MigrationsHub den Typ der neuen Variable auf die Alt-Variable nach;
  vorher bekam sie deshalb den falschen Typ, und Verbrauchsauswertungen lasen Zählerstände als Momentanwerte (Fund 26.10.2026).
  Bereits archivierte Variablen behalten ihren Typ (eine Änderung würde im Archiv eine Reaggregation auslösen).

## [0.9.134-beta.1] - 2026-10-25

### Added
- **Regler-Hoheit per Funktion umstellen:** `CHUB_GetManagedByOptions($id)` liefert die für diesen Hersteller zulässigen Werte
  für „Wer regelt?“, `CHUB_SetManagedBy($id, $value)` setzt und übernimmt einen zulässigen Wert (Rückgabe `ok`, `error`,
  `previous`, `current`). Unzulässige Werte werden abgelehnt. Die Übergabelogik wie bei der Umstellung im Formular läuft mit
  (z. B. Lösen einer Ladesperre), jede Umstellung steht mit alt/neu im Meldungsprotokoll. Gedacht für die Wallbox-Detailseite
  des Dashboards (Wunsch Dietmar), damit die Kachel nicht selbst Eigenschaften der Instanz setzt.

## [0.9.133-beta.1] - 2026-10-25

### Fixed
- **go-e: Leistung 42 949 672,86 W im Archiv:** Die Leistungsregister des go-e sind vorzeichenbehaftet, im Leerlauf meldet das
  Gerät kleine negative Werte (Messoffset, z. B. −0,1 W). Als vorzeichenlose Zahl gelesen ergab das 42,9 Megawatt. Ein einziger
  solcher Archivwert machte in der Ladesitzungs-Auswertung des Dashboards aus einer kleinen Ladung „288 kWh“ (Fund Paul,
  24.10.2026, 15:15). Gesamt- und Phasenleistung werden jetzt vorzeichenrichtig gelesen, negative Werte gelten als 0 W.
- **Plausibilitätsschutz für alle Hersteller:** Eine Leistung über 1 MW wird nie in eine Variable (und damit ins Archiv)
  geschrieben. Bereits archivierte Fehlwerte bleiben im Archiv stehen und müssen dort bereinigt werden.

## [0.9.132-beta.1] - 2026-10-24

### Added
- **Bedienung im Formular:** Neues Panel „🎛️ Bedienung“ ganz oben im Konfigurationsformular der Instanz: Schalter
  **Ladefreigabe**, Schieberegler **Stromlimit** (6 A bis zur Obergrenze der Wallbox) und, wo das Gerät es kann, Auswahl
  **Phasenumschaltung**. Die Elemente zeigen den aktuellen Gerätewert und wirken sofort am Gerät, ohne „Übernehmen“
  (gleicher Weg wie die Steuervariablen im WebFront). Darüber stehen der Plan (falls vom EMS gemeldet) und ein Hinweis, wer
  gerade regelt: bei EMS, Tibber usw. warnt er, dass eine Handbedienung beim nächsten Regelschritt überschrieben werden kann
  (Wunsch Dietmar: „Schalter und Schieberegler im Instanzformular“).

## [0.9.131-beta.1] - 2026-10-24

### Fixed
- **EMS wurde nie erkannt:** Die hinterlegte Modul-GUID des EMS war falsch, `IsEmsActive()` lieferte deshalb immer „nein“. Folgen:
  die Formularzeile zeigte „Kein EMS installiert“, die Anzeige „Regelung (wer, warum)“ nannte bei aktivem EMS „Niemand“, und
  vor allem **gab die eigene Überschussregelung dem EMS nie den Vorrang**: waren Überschussladen und EMS gleichzeitig aktiv
  (und „Wer regelt?“ auf „Niemand“), regelten beide dieselbe Wallbox. Mit der richtigen GUID weicht die Überschussregelung
  jetzt wie vorgesehen zurück, wenn das EMS aktiv ist (gefunden bei der Prüfung auf Dietmars System, 24.10.2026).

## [0.9.130-beta.1] - 2026-10-23

### Added
- **Plan (was als Nächstes passiert):** Neue Variable „Plan (was als Nächstes passiert)“ (`control_plan`) und die Funktion
  `CHUB_SetPlanInfo($id, $text, $validUntil)`. Ein Planer wie das EMS meldet damit, was mit der Wallbox geplant ist
  („Heute 02:15 bis 04:30 laden …“). Der ChargerHub zeigt den Text nur an und regelt danach nicht. Mit Ablaufzeit wird der
  Text gelöscht, wenn der Planer ausfällt, damit kein veralteter Plan stehen bleibt (Wunsch Dietmar: „Ich sehe nicht, was die
  Wallbox tun wird“).
- **Vertrag 1.12 (additiv):** Referenzfeld `planInfoID`.

## [0.9.129-beta.1] - 2026-10-23

### Added
- **Vertrag 1.11 (additiv):** `CHUB_GetFunctions` liefert zusätzlich `stateID` (Ladestatus) und `errorCodeID` (Fehlercode, nur
  DaheimLader, Fox ESS und CHARX). Damit kann das EMS Status und Fehler einer Wallbox automatisch verbinden, statt dafür
  eigene Eingabefelder zu verlangen (Hinweis Dietmar, 23.10.2026).

## [0.9.128-beta.1] - 2026-10-23

### Added
- **Vertrag 1.10 (additiv):** `CHUB_GetFunctions` liefert die Referenzfelder `sessionEnergyID` (Energie der laufenden Sitzung, 0 bei
  Alfen/Heidelberg/ABL), `energyLimitID` (hartes Energie-Limit der Wallbox, nur go-e) und `chargingStateID`. Grundlage dafür,
  dass das EMS Ladeziel und Frist je Wallbox planen kann (wie viel ist schon geladen, lässt sich ein Limit in der Wallbox absichern).
- **Neue Variable „Lädt“** (`charging_active`) im Bereich Status: wahr, wenn die Ladeleistung über 100 W liegt. Unterscheidet
  „Ladung freigegeben“ von „es fließt wirklich Strom“.

## [0.9.127-beta.1] - 2026-10-23

### Fixed
- **Absturz bei der Dubletten-Zuordnung:** Zeigte die gespeicherte Zuordnung („Zählt als Dublette von …“) auf eine Instanz,
  die die Funktion `CHUB_`/`OHUB_GetFunctions` nicht (mehr) bereitstellt (z. B. weil die ID inzwischen eine andere Instanzart
  ist), brach das Formular mit „Instance does not implement this function“ ab, ebenso die tägliche Prüfung im Lesezyklus.
  Die Abfrage ist jetzt abgesichert; der Regler der Zielinstanz erscheint dann als „unbekannt“.

## [0.9.126-beta.1] - 2026-10-22

### Added
- **Regelung (wer, warum):** Neue Variable „Regelung (wer, warum)“ (`control_status`) zeigt je Ladepunkt, wer ihn gerade
  regelt (Niemand, EMS, Überschussladen, go-e Controller, Tibber, §14a, Direktvermarkter, Anderer Regler), den Grund im
  Klartext und seit wann. Gedacht gegen die Frage „warum lädt (oder lädt nicht) meine Wallbox?“ bei mehreren möglichen Reglern.
- **Vertrag 1.9 (additiv):** `CHUB_GetFunctions` liefert zusätzlich `controlOwner`, `controlSource`, `controlReason` und
  `controlSince`. Reine Auskunft, die Begriffe entsprechen der Entscheidungsauskunft des EMS (Quelle, Grund, seit wann).

## [0.9.125-beta.1] - 2026-10-21

### Added
- Überschussladen: Netzleistung **direkt aus einer Variablen** (Anregung Dietmar), ohne MeterHub. Neue Einstellungen
  „Netzleistung (Variable)“, Vorzeichen (`+ = Bezug` wie MeterHub oder `+ = Einspeisung`), Einheit (W/kW) und maximales
  Alter des Werts (Standard 30 s). Die Variable hat Vorrang vor einem MeterHub-Zähler. Geeignet z. B. für einen per MQTT
  befüllten Netzbetreiber-Zähler oder den Smartmeter-Wert eines InverterHub (Vorzeichen dort je nach InverterHub-Einstellung).
- **Fail-Safe:** Ist der Wert der eigenen Netzvariable älter als erlaubt, ungültig oder die Variable gelöscht, stoppt die
  Überschussregelung eine laufende Ladung und startet erst wieder mit aktuellen Werten (kein Weiterladen mit einem
  eingefrorenen Messwert). Bei MeterHub als Quelle bleibt das Verhalten unverändert.

## [0.9.124-beta.1] - 2026-10-21

### Added
- `CHUB_GetFunctions`, Vertrag 1.8 (EMS-Anfrage zu Stefans Wallbox-Modi, abgestimmt): optionale Steuerfelder
  `phaseModeID` und `phaseModeValues` (herstellerunabhängige Phasenumschaltung für das EMS; nur bei belegter
  Umschaltbarkeit), `phaseSwitchPauseSec` (DaheimLader 120 s), `currentStepA` (1,0, Alfen 0,1) und
  `controlLatencySec` (bei keinem Gerät gemessen, immer 0). Fox ESS liefert bewusst keine Werte (Kodierung
  an eine Zusatz-Box gebunden). Die Steuerwerte (0 ist kein Pausewert, erst Freigabe dann Limit, Bedeutung
  von `phases`) sind im README festgeschrieben.

### Changed
- go-e: `phases` im Vertrag kommt bevorzugt aus der Phasenmaske nach dem Schütz (Register 205, auch im
  Automatikmodus), sonst aus dem erzwungenen Modus. DaheimLader: Register 184 (nur PRO) belegt jetzt Phasenzahl
  und Umschaltbarkeit (`phases`/`phasesSwitchable`).

## [0.9.123-beta.1] - 2026-10-21

### Added
- Überschussladen: Der Netzzähler kann jetzt auch ein MeterHub „Virtueller Zähler“ sein (Modul
  `MeterHubVirtual`, Präfix MHUBV, gleicher Vertrag wie MeterHub: Funktion `grid`, Latenz `realtime`,
  `powerID`, + = Bezug). Bisher fragte ChargerHub nur Instanzen des normalen MeterHub-Moduls ab (`MHUB_`),
  ein virtueller Zähler wurde weder automatisch gefunden noch in der Instanzauswahl angeboten. Damit lässt
  sich zum Beispiel ein Netzbetreiber-Zähler, dessen Werte per MQTT als Symcon-Variablen vorliegen, als
  Netzzähler führen (Anfrage somm im Forum, Anleitung der MeterHub-Sitzung). Auswahl „NAP-Zähler“ listet
  beide Module; die Statuszeile „Netzzähler“ erkennt beide. Die Autorität `billing` wird nur als
  Vorrang bevorzugt, nicht verlangt (der virtuelle Zähler meldet `auxiliary`).

## [0.9.122-beta.1] - 2026-10-20

### Fixed
- go-e: „Leistung L1/L2/L3“ kam 1000-fach zu groß an (z. B. 4 000 000 W statt 4 000 W). Die Register 146 bis 151
  liefern 0,1 W je Einheit, die go-e-Doku nennt 0,1 kW; ChargerHub rechnete mal 100 statt durch 10. Bestätigt an
  zwei Geräten mit zwei Firmware-Ständen: archivierte Werte einer go-e mit Firmware 60.6 (Maximum je Phase
  4 000 000 W bei 11 800 W Gesamtleistung) und Rohwerte einer go-e mit Firmware 56.2 (13 000 bei 1,3 kW).
  **Bereits archivierte Phasenleistungen früherer Versionen bleiben 1000-fach zu groß** (Archiv bei Bedarf
  nachträglich bereinigen oder die Variablen ab diesem Zeitpunkt neu archivieren). Gesamtleistung
  („Ladeleistung“) war nie betroffen.

## [0.9.121-beta.1] - 2026-10-20

### Fixed
- go-e, Firmware-Version: Die 4-Byte-Zeichenkette (Register 105) hängt an derselben Wortreihenfolge wie die
  32-Bit-Werte. Mit erzwungener Option kam „.256“ statt „56.2“ an (Fund baraka/Adam, Firmware 56.2).
  Jetzt werden die Wörter bei erzwungener Option getauscht; bei erkannter, nicht ausgeglichener Vertauschung
  wird die Zeichenkette wie die 32-Bit-Werte nicht übernommen.

## [0.9.120-beta.1] - 2026-10-20

### Changed
- go-e, Hinweis bei vertauschter Wortreihenfolge: Anleitung auf den tatsächlichen Weg umgestellt. Die
  go-e-App bietet die Schalter unter Einstellungen > API Einstellungen („Register tauschen“, „Bytes
  tauschen“, beide aus = Standard; API-Schlüssel msr/msb). Vorher stand dort die HTTP-API, weil mir der
  App-Pfad nicht bekannt war (Hinweis von Dietmar mit Screenshot).

## [0.9.119-beta.1] - 2026-10-20

### Changed
- go-e, vertauschte Wortreihenfolge (Fund baraka/Adam): Statt die Werte automatisch auszugleichen
  (0.9.117/0.9.118), wird die Vertauschung nur noch ERKANNT und GEMELDET (Entscheidung Dietmar: Ursache am
  Gerät beheben, keine Folgeprobleme durch halb korrigierte Werte). Bei erkannter Vertauschung (Netzspannung
  als Wert x 65536, bei jedem Lesezyklus geprüft) werden die betroffenen 32-Bit-Werte NICHT in Variablen
  geschrieben, also auch nicht archiviert; Status, Stecker, Seriennummer usw. laufen weiter. Im Formular
  erscheint ein ⚠️-Hinweis mit der Anleitung (go-e-Einstellung `msr` umstellen, z. B. über die HTTP-API), einmal
  steht eine Meldung im Meldungen-Log. Korrigiert der Nutzer die Einstellung am Gerät, kehren die Werte ohne
  „Übernehmen“ zurück. Wer die Einstellung nicht ändern kann, setzt „Wortreihenfolge getauscht erzwingen“; sie
  hat Vorrang und liest die vertauschte Reihenfolge richtig.

## [0.9.118-beta.1] - 2026-10-20

### Fixed
- go-e, Wortreihenfolge-Automatik (0.9.117): Die Erkennung war nach der ersten Entscheidung dauerhaft
  festgehalten. Wurde die Einstellung am Gerät später geändert (msr), blieben die Werte bis zum nächsten
  „Übernehmen“ falsch. Die Prüfung läuft jetzt bei jedem Lesezyklus; ein eindeutiges Muster setzt den
  Merker oder nimmt ihn zurück (je ein Hinweis im Meldungen-Log), 0 V oder gemischte Werte lassen den
  bisherigen Stand unverändert.

## [0.9.117-beta.1] - 2026-10-20

### Added
- go-e: Wortreihenfolge der 32-Bit-Werte wird automatisch erkannt (Fund baraka/Adam, Forum): Eine go-e,
  die das niederwertige Wort zuerst sendet (go-e-Einstellung msr, Firmware 60.3), lieferte bisher
  Spannung 14 680 064 V statt 224 V und Gesamtenergie 194 124 185,7 kWh statt 9 515,7 kWh. Der Treiber
  prüft jetzt die Netzspannung (100-300 V): [0, V] = Standard, [V, 0] = getauscht, andere Werte
  (z. B. 0 V) lassen die Frage offen. Der Merker gilt bis zum nächsten „Übernehmen“, eine Meldung
  erscheint einmal im Meldungen-Log. Die Option „Wortreihenfolge getauscht erzwingen“ (vorher „Byte-
  Reihenfolge getauscht“) bleibt als manuelle Übersteuerung und hat Vorrang.
- Bekannt offen bei diesem Gerät: Gesamtleistung und Sitzungsenergie sowie die Skalierung der
  Leistung je Phase ergeben nach dem Tauschen noch keine plausiblen Werte (Rohwerte werden vom Nutzer
  nachgeliefert).

## [0.9.116-beta.1] - 2026-10-13

### Changed
- Netzwerksuche (ChargerHubDiscovery): Aufrufe an das Gerätetausch-Modul (vormals MigrationsHub)
  auf den neuen Funktionspräfix umgestellt: `GTAUSCH_FindLegacyCandidates` und
  `GTAUSCH_PrefillMigration` zuerst, `MIGHUB_…` als Rückfall (Toolkit-Umbenennung 13.10.2026);
  Parameter unverändert, jeder Aufruf weiter hinter `function_exists()`. Der Rückfall kann entfallen,
  sobald das Gerätetausch-Modul im Store ist.

## [0.9.115-beta.1] - 2026-10-12

### Changed
- Logging nur noch über `$this->LogMessage()` mit Log-Stufe (KL_WARNING/KL_ERROR/KL_MESSAGE) statt der
  globalen Log-Funktion (Symcon-Store-Review-Konvention 12.10.2026, Verbund-Checkliste Punkt 15).
  Meldungen erscheinen jetzt mit Absender der Instanz; die Texte beginnen mit „Schreibfehler …“,
  „Schreibprotokoll …“, „Vorführmodus: …“, „MQTT: …“, „Symbox-Gateway: …“ (vorher Absender
  „ChargerHub-Schreibfehler“ usw.). Die Bibliotheksklasse für den Symbox-Gateway-Weg meldet über einen
  Log-Callback an die Modulklasse. Prüfstand: Strukturtest auf die globale Log-Funktion in allen
  drei Moduldateien.

## [0.9.114-beta.1] - 2026-10-06

### Fixed
- CHARX: Ladefreigabe und Stromlimit laufen jetzt wie bei evcc (charger/phoenix-charx.go, bei
  evcc-Nutzern an echter Hardware erprobt) beide über Register x301 per FC 0x10: Freigabe =
  Stromvorgabe (mindestens 6 A), Sperre = 0; „Ladefreigabe“ wird aus x301 zurückgelesen (ungleich 0).
  Bisher wurde die Freigabe über x300 per FC 0x06 geschrieben. x300 ist laut Handbuch nur
  schreibbar, wenn die Freigabe-Art im WBM auf „Modbus“ steht; bei Mstaudi scheiterte die Aktion
  („Unerwartete Antwort“), und ein gesetztes Stromlimit änderte die tatsächliche Leistung nicht.
  Ob die Änderung das behebt, ist an echter Hardware noch offen.

## [0.9.113-beta.1] - 2026-10-06

### Added
- `CHUB_GetFunctions`, Vertrag 1.7 (Anfrage Dashboard, abgestimmt mit EMS): optionale Referenzfelder
  `pluggedInAtID`, `pluggedOutAtID`, `chargeTimeSecID`, `connectionTimeSecID` (Variablen-IDs der
  Zeit-Variablen, 0 = nicht vorhanden). Rein additiv.

## [0.9.112-beta.1] - 2026-10-06

### Added
- Herstellerübergreifend: optionale Variablen „Angesteckt um“ (`plugged_in_at`) und „Abgesteckt um“
  (`plugged_out_at`, Profil `~UnixTimestamp`), einschaltbar im Panel „Datenpunkte“ („🆕 Angesteckt-/
  Abgesteckt-Zeit“, Standard aus, damit die Variablenzahl nicht unbemerkt wächst). Die Wallbox meldet
  keine Uhrzeiten: ChargerHub leitet sie aus dem Wechsel von „Fahrzeug verbunden“ ab, mit der
  Genauigkeit des Lese-Intervalls. Der zuerst gesehene Zustand (nach Neustart oder Einschalten der
  Option) setzt keine Zeit, bis zum ersten beobachteten Wechsel steht 1970 (leer). Gilt für alle
  Hersteller mit „Fahrzeug verbunden“ (alle außer Alfen). Wunsch Mstaudi.

## [0.9.111-beta.1] - 2026-10-05

### Fixed
- CHARX: „Energie gesamt“ blieb bei 0,0 kWh. Der Zählerstand Wirkenergie (x250) wurde nur über 2
  Register gelesen, also nur das höherwertige Wort. Er wird jetzt als INT64 über 4 Register gelesen
  (wie die Sitzungsenergie x289); das Handbuch führt x250 mit 2 Worten, der Adressabstand zum
  nächsten Zähler (x254) beträgt aber 4. Fund Mstaudi (eigene Abfrage über Register 1250 zeigte
  2317,6 kWh).

### Added
- CHARX: Variablen „Verbindungszeit (Sek.)“ (x285) und „Ladezeit (Sek.)“ (x287) in „Geräteinformation“,
  für eine einfache Ladesitzungs-Übersicht (Wunsch Mstaudi).

## [0.9.110-beta.1] - 2026-10-05

### Added
- CHARX: Variable „Angewandtes Limit“ (Register x297, Ampere, aktuell von der Steuerung vorgegebener
  Ladestrom). Zeigt, ob ein gesetztes Stromlimit wirklich gilt oder ob die Steuerung davon
  abweicht (z. B. Rückfallstrom/Watchdog). Anlass: Rückfrage von Mstaudi zum Watchdog (Forum).

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

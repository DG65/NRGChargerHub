# Changelog

Alle nennenswerten Änderungen an diesem Modul werden hier dokumentiert.
Format angelehnt an [Keep a Changelog](https://keepachangelog.com/de/1.0.0/).

## [0.9.42-beta.1] - 2026-08-26

### Fixed
- `FindGridSurplusW()`: `MHUB_GetFunctions()` liefert (anders als unser eigenes
  `CHUB_GetFunctions()`) einen JSON-**String**, keinen nativen PHP-Array — mit MeterHub live
  abgeglichen. `json_decode()` ergänzt, den bisherigen defensiven Fallback-Zweig (Rückgabewert
  selbst als Liste behandeln) entfernt, da die Struktur jetzt bestätigt und stabil ist
  (`assignments` immer ein eigener Schlüssel im obersten Objekt).

## [0.9.41-beta.1] - 2026-08-26

### Added
- Neue Option „Überschussladen selbst regeln" (Property `EnableSurplusCharging`, Standard aus).
  ChargerHub kann jetzt eigenständig per PV-Überschuss laden — aber ausdrücklich NUR als
  Fallback, wenn EMS nicht vorhanden oder nicht aktiv ist (Dietmars Vorgabe). Greift nur, wenn
  ALLE Bedingungen erfüllt sind: `managedBy` = „Niemand", EMS installiert+aktiv? nein
  (`IsEmsActive()`, Statusvariable `EMS_Active_State`, GUID mit EMS-Sitzung abgestimmt), GENAU
  eine aktive ChargerHub-Instanz insgesamt (Koordination mehrerer Wallboxen bleibt EMS
  vorbehalten — sonst würden zwei Instanzen um denselben Überschuss konkurrieren), ein
  MeterHub-Zähler am Netzanschlusspunkt liefert einen Echtzeit-Wert (`FindGridSurplusW()`, neuer
  Verbund-Vertrag mit MeterHub: `MHUB_GetFunctions()`-Zuordnung mit `function==='grid'` UND
  `latency==='realtime'`, `authority==='billing'` nur als Tiebreaker — NIE über den frei
  wählbaren Instanznamen, und Vorzeichen `max(0, -powerValue)`, da MeterHub `+` als Bezug
  zählt). Automatisches Umschalten der Phasenzahl ist bewusst NICHT Teil dieser ersten Fassung.

## [0.9.40-beta.1] - 2026-08-26

### Fixed
- Live entdeckt (Ladung an WB2 komplett blockiert, go-e-App zeigte „Dein Limit von 0 kWh wurde
  erreicht"): Die Anzeige von „Energie-Limit Ladevorgang" konnte „kein Limit" (Modbus-Wert `Inf`,
  laut go-e-Doku der korrekte Weg, das Limit zu deaktivieren) nicht von einem ECHTEN, ladungs-
  blockierenden 0-Wh-Limit unterscheiden — beide Zustände zeigten „0,0 kWh". Anzeige-Sentinel auf
  `-1` umgestellt (mit eigener Profil-Textassoziation „Kein Limit"), Beschriftung entsprechend
  angepasst. Schreibseite war bereits korrekt (0/negativ eingeben → schreibt `Inf`).

## [0.9.39-beta.1] - 2026-08-20

### Added
- Neue, herstellerunabhängige Variable „Zugeordnetes Fahrzeug" (`vehicle_name`, String,
  archiviert) je Instanz. Bleibt leer, bis ein externes Fahrzeug-Modul (z. B. Tessie, oder ein
  beliebiges anderes bei einem anderen Nutzer) `CHUB_SetVehicleName($id, $name)` aufruft.
  ChargerHub errät selbst nicht, welches Fahrzeug angesteckt ist (kennt weder Marke noch Name,
  keine GPS-/Zeitfenster-Heuristik) — das würde die Eigenständigkeitsregel verletzen (kein Modul
  setzt ein anderes voraus). Wird automatisch geleert, sobald `vehicle_plugged` (falls vom
  Treiber geliefert) auf „kein Fahrzeug" wechselt, damit nach einem Fahrzeugwechsel nicht der
  alte Name stehen bleibt. `CHUB_GetFunctions()` liefert die Variablen-ID neu als
  `vehicleNameID` (Vertragsversion 1.1 → 1.2, additiv).

## [0.9.38-beta.1] - 2026-08-20

### Added
- Neuer Button „🔄 Übernehmen erzwingen (ohne Formularänderung)" in ChargerHub UND
  ChargerHub Suche — ruft direkt `IPS_ApplyChanges($id)` mit Bestätigungs-Popup auf. Praktisch,
  wenn nach einem Modul-Update der reguläre Weg (Modulverwaltung → Aktualisieren → Übernehmen)
  einmal nicht greift (EMS-Vorschlag).

## [0.9.37-beta.1] - 2026-08-20

### Fixed
- Alle Formular-Buttons gegen SUITE.md "Sichtbare Rückmeldung bei jeder Aktion" (verbindlich seit
  20.08.2026) durchgeprüft. Ein Fund: „Verbindung testen / Daten sofort lesen" rief bisher
  direkt `CHUB_Update()` auf — ohne jede sichtbare Reaktion im Formular. Neuer Wrapper
  `CHUB_TestConnection()` liest wie bisher, zeigt danach aber ✅/❌ mit Uhrzeit in einem neuen
  Label an. `Update()` selbst bleibt unverändert der reine Timer-Callback (kein UpdateFormField
  bei jedem FastTimer-Tick). Alle anderen Buttons (Netzwerksuche, Abbrechen, Migration
  vorbereiten, News/Review-Hinweis ausblenden) hatten bereits sichtbares Feedback.

## [0.9.36-beta.1] - 2026-08-20

### Fixed
- `ChargerHubDiscovery`: SUITE.md-Stolperfalle 12 defensiv abgedeckt — `GetConfigurationForm()`
  läuft nach einem `RequestAction`-Button nicht immer automatisch neu (bei EMS live beobachtet:
  Kopfzeile blieb im bereits offenen Formular stehen, obwohl die Suche serverseitig lief). Wir
  hatten bereits `ReloadForm()`, das deckt es normalerweise ab — zusätzlich jetzt ein expliziter
  `UpdateFormField()`-Aufruf auf die neue Kopfzeile direkt nach der Suche.

## [0.9.35-beta.1] - 2026-08-20

### Changed
- `ChargerHubDiscovery`: Verbund-Konvention „Einheitliche Verbund-Status-Kopfzeile" (SUITE.md,
  Referenz EMS `getDiscoverySummaryLine()`) übernommen. Direkt unter dem „Netzwerk
  durchsuchen"-Button erscheint jetzt eine Zeile „✅/⚠️/ℹ️ N Wallbox(en) gefunden (zuletzt
  HH:MM:SS Uhr)." statt nur des flüchtigen Fortschrittsbalkentexts während der Suche.

## [0.9.17-beta.1] - 2026-07-27

### Changed
- Usability-Nachschärfung nach Dietmars EMS-Feedback (irreführendes "Pflicht"-Panel dort):
  Panel „Verbindung" macht jetzt explizit, dass Host/Port/UnitId normalerweise von der
  ChargerHub-Suche automatisch befüllt werden — manuelle Eingabe war zuvor unkommentiert und
  wirkte wie der einzige/erwartete Weg.

## [0.9.23-beta.1] - 2026-08-02

### Changed
- `ChargerHubDiscovery`: Bei mehreren Alt-Instanz-Treffern zeigt die Ergebnisliste jetzt den von
  MigrationsHub gelieferten Kategorie-Pfad je Kandidat an (z. B. „WB 1 (#19716) [Geräte /
  Module / Wallboxen / API]" vs. „WB 1 (#48730) [Sicherung]") — damit lässt sich die richtige
  Alt-Instanz vor der manuellen Verknüpfung erkennen, statt nur „mehrere gefunden" zu sehen.

## [0.9.22-beta.1] - 2026-08-02

### Fixed
- `ChargerHubDiscovery`: Bei mehreren Alt-Instanz-Treffern an derselben IP (live gefunden: zwei
  goeCharger-Fremdinstanzen unter derselben IP, eine davon eine Sicherungs-Kopie — das
  goeCharger-Modul speichert keine Unit-ID, das Matching lief nur über die IP) wählte
  `LegacyCandidateFor()` bisher stillschweigend den ersten Treffer — Risiko, die Historie der
  physisch falschen Wallbox zu verknüpfen. Bei Mehrdeutigkeit wird jetzt NICHTS automatisch
  gewählt (`ambiguous`-Flag), die Ergebnisliste zeigt „Mehrere Alt-Instanzen — bitte manuell
  verknüpfen" statt eines einzelnen Vorschlags, und „Migration vorbereiten" bricht für diese
  Zeile mit einer erklärenden Meldung ab statt still gar nichts zu tun.

## [0.9.34-beta.1] - 2026-08-04

### Changed
- Alle statistisch sinnvollen Datenpunkte werden jetzt standardmäßig archiviert (Dietmars
  Wunsch, orientiert an der go-e-API-Kategorisierung "Status" vs. "Config"): Verbindung,
  Ladestatus, Fahrzeug verbunden, Ladeleistung inkl. je Phase, Energie (Sitzung/gesamt),
  Spannung/Strom je Phase, genutzte Phasen, Fehlercode, Adapter, RFID-Kartenzähler, Kabel-Status/
  -Strombegrenzung, PCB-Temperatur. Bewusst weiterhin NICHT archiviert: Seriennummer/
  Firmware-Version (statische Kennungen, kein Zeitreihenwert) sowie alle `ctl_*`-Steuer-Sollwerte
  (entsprechen go-es "Config"-Kategorie — Einstellungen, keine Messwerte). Betrifft alle vier
  Treiber (KEBA/Alfen/Heidelberg/go-eCharger) einheitlich. Greift automatisch beim nächsten
  „Übernehmen" auch für bereits bestehende Instanzen.

## [0.9.33-beta.1] - 2026-08-03

### Changed
- `CHUB_GetIdentMapping()` auf den finalen, verbundweit abgestimmten 3-Parameter-Vertrag
  umgestellt: `($foreignModuleGUID, array $foreignIdents): array`, Rückgabe jetzt keyed nach
  Alt-Ident mit `['ident' => neuIdent, 'type' => VARIABLETYPE_*]` (int-Konstante statt
  String-Label), gefiltert auf die tatsächlich übergebenen `$foreignIdents` — reines
  GUID-Matching würde bei firmwareabhängig unterschiedlich benannten Feldern ins Leere laufen.

## [0.9.32-beta.1] - 2026-08-03

### Added
- `CHUB_GetIdentMapping($foreignModuleID): array` — schmale Auskunftsfunktion für
  MigrationsHub (Verbund-Konvention, Alternative zu einer vollen "AdoptFromLegacyInstance" in
  jedem Hub-Modul, um Reparent-/Prune-Logik nicht zu duplizieren). Liefert für das
  go-eCharger-Fremdmodul (IPSCoyote/GO-eCharger) die bereits am Quellcode verifizierte
  Ident+Typ-Zuordnung (30 Paare, siehe README) — MigrationsHub kann damit vor einem
  `AC_ChangeVariableID()`-Aufruf auf Typgleichheit prüfen, statt sie per Preflight-Sonde zu
  erraten (Ursache des heutigen `AC_ChangeVariableID`-Crashs: unerkannter Typ-Mismatch).

## [0.9.31-beta.1] - 2026-08-03

### Fixed
- `ChargerHubDiscovery`: „Migration vorbereiten" verarbeitet pro Klick bewusst nur eine Zeile
  (kehrt nach dem ersten Treffer sofort zurück), merkte sich aber nicht, welche bereits erledigt
  war — bei mehreren Treffern landete jeder weitere Klick wieder bei der ersten passenden Zeile,
  spätere Zeilen (live beobachtet: WB1) waren so nie erreichbar. Neues Attribut
  `PreparedTargets` merkt sich bereits vorbereitete Ziel-Instanzen und überspringt sie beim
  nächsten Klick, damit der Nutzer sich wirklich Zeile für Zeile durcharbeiten kann. Wird bei
  einem neuen Suchlauf zurückgesetzt.

## [0.9.30-beta.1] - 2026-08-03

### Fixed
- `ChargerHubDiscovery`: `LegacyCandidateFor()` las die Felder aus `MIGHUB_FindLegacyCandidates()`
  als `instanceID`/`name` (lowercase), MigrationsHub liefert aber `InstanceID`/`Name`
  (PascalCase) — case-sensitiver PHP-Array-Zugriff, dadurch wurde `$id` immer `0` und der
  0.9.28-Defensivfilter (`$id > 0`) hat dadurch JEDEN Treffer verworfen, nicht nur die eigene
  Instanz. Live reproduziert: MigrationsHub meldete #27208 korrekt als Treffer, bei uns kam
  „keine passende Kombination" an. Liest jetzt beide Schreibweisen (PascalCase zuerst).

## [0.9.29-beta.1] - 2026-08-03

### Fixed
- `ChargerHubDiscovery`: Live-Absturz beim Formular-Aufbau ("Too few arguments to function
  MIGHUB_FindLegacyCandidates(), 4 passed ... exactly 5 expected") — MigrationsHub hat den
  neuen 5. Parameter `$excludeInstanceID` entgegen der Ankündigung ohne Default-Wert
  eingeführt, kein Bug bei uns, aber wir rufen die Funktion jetzt ohnehin mit allen 5 Parametern
  auf (nutzt gleich den neuen Ausschluss-Parameter, siehe 0.9.28-Motivation — serverseitig statt
  nur clientseitig gefiltert).

## [0.9.28-beta.1] - 2026-08-03

### Fixed
- `ChargerHubDiscovery`: `MIGHUB_FindLegacyCandidates()` konnte eine frisch angelegte, EIGENE
  ChargerHub-Instanz als vermeintliche "Alt-Instanz" zurückgeben (live beobachtet bei einer IP,
  an der gar keine echte Fremd-Instanz mehr existierte). "Migriere von dir selbst" wäre
  sinnlos und im Extremfall (Quelle=Ziel) schädlich. `LegacyCandidateFor()` filtert Kandidaten
  jetzt zusätzlich defensiv nach `ModuleID`, akzeptiert keine Treffer mit der eigenen
  `CHARGERHUB_GUID` — zusätzlich zu einem entsprechenden Fix bei MigrationsHub selbst, nicht als
  Ersatz dafür.

## [0.9.27-beta.1] - 2026-08-03

### Fixed
- Live-Test: Nach korrekten Zugangsdaten kamen weiterhin keine Kartendaten an — Ursache war das
  fest einprogrammierte Topic-Präfix `go-eCharger/<Seriennummer>`. go-e erlaubt ein eigenes
  Präfix (API-Key `mtp`), Dietmar nutzt `WB1`/`WB2` statt der Seriennummer — bestätigt über den
  MQTT-Server-Konfigurator, dort liefen die Topics unter `WB1/c0e`, `WB1/c0n` usw. Neue Property
  „MQTT-Topic-Präfix" (leer = weiterhin Standard `go-eCharger/Seriennummer`).

## [0.9.26-beta.1] - 2026-08-03

### Fixed
- Live-Test (erster echter MQTT-Verbindungsversuch): `CONNACK fehlgeschlagen oder Broker
  abgelehnt` — Symcons MQTT-Server verlangt Zugangsdaten, `CHUB_MqttMiniClient` schickte bisher
  keine mit. Neue Properties „MQTT-Benutzername"/„MQTT-Passwort" im Panel, `CONNECT`-Paket
  setzt jetzt bei Bedarf Username-/Password-Flags. CONNACK-Fehlercode wird zudem im Klartext
  geloggt (z. B. „Benutzername/Passwort falsch", „nicht autorisiert") statt einer pauschalen
  Meldung.

## [0.9.25-beta.1] - 2026-08-02

### Changed
- RFID-Kartenzähler (0.9.24) auf einen eigenen, rohen MQTT-Client (`CHUB_MqttMiniClient`)
  umgestellt statt einer Symcon-Splitter-Anbindung (Kind-Instanz einer fremden MQTT-Client-
  Instanz). Dietmars Feedback: das gehört direkt ins Modul, kein Verlass auf eine korrekt
  konfigurierte fremde Instanz — passt außerdem zum bestehenden Prinzip von
  `CHUB_ModbusTcpClient` (eigener roher Socket statt Symcon-Configurator-Abhängigkeit).
  `module.json` `parentRequirements`/`implemented` wieder entfernt. Neue Properties
  „MQTT-Broker"/„MQTT-Port" im Panel „RFID-Kartenzähler". Verbindung wird bei jedem Poll neu
  aufgebaut (kein Zustand über Timer-Aufrufe hinweg, wie beim Modbus-Client) — unproblematisch,
  da go-e seine Werte als retained Topics veröffentlicht und der Broker sie beim SUBSCRIBE
  sofort erneut liefert.

## [0.9.24-beta.1] - 2026-08-02

### Added
- Neu: RFID-Kartenzähler (Name + Energie je Karte, 0–9) für go-eCharger über MQTT. Die
  offizielle go-e-Modbus-Registertabelle enthält diese Werte nicht — nur die HTTP/MQTT-API bietet
  sie an (`c0n`…`c9n` Kartenname, `c0e`…`c9e` Energie in Wh). Modul deklariert jetzt
  `parentRequirements`/`implemented` für die native IP-Symcon-MQTT-Client-Schnittstelle
  (`{043EA491-0325-4ADD-8FC2-A30C8EEB4D3F}`/`{7F7632D9-FA40-4F38-8DEA-C83CD4325A32}`, gegen ein
  reales, gepflegtes Referenzmodul verifiziert, nicht geraten). Neue Property
  „RFID-Kartenzähler per MQTT abbilden" (nur go-eCharger). `ReceiveData()` filtert per
  `SetReceiveDataFilter()` gezielt auf Topics der EIGENEN Seriennummer — bei mehreren
  go-e-Geräten am selben Broker (wie bei Dietmars zwei Wallboxen) würde ein Topic-Filter ohne
  Seriennummer sonst die Kartendaten der jeweils anderen Wallbox übernehmen.

## [0.9.21-beta.1] - 2026-07-29

### Fixed
- `ChargerHubDiscovery`: `LegacyCandidateFor()` übergab an `MIGHUB_FindLegacyCandidates()`
  fälschlich `$this->InstanceID` (die eigene Discovery-Instanz) statt einer
  MigrationsHub-Instanz-ID — live abgestürzt beim Formular-Aufbau ("Instance does not
  implement this function", Testfall `#19716` von MigrationsHub). Sucht jetzt wie
  `PerformMigration()` über `IPS_GetInstanceListByModuleID()` nach einer vorhandenen
  MigrationsHub-Instanz; ohne eine solche gibt es „nichts gefunden" statt eines Fatal Errors —
  legt dabei selbst keine Instanz an (das bleibt dem expliziten „Migration vorbereiten"-Klick
  vorbehalten).

## [0.9.20-beta.1] - 2026-07-29

### Added
- `ChargerHubDiscovery`: MigrationsHub-Anbindung nach Verbund-Konvention (mit MigrationsHub,
  InverterHub, MeterHub abgestimmt, Referenz `MeterHubDiscovery` 0.21.0-beta.1). Beim Suchlauf
  wird je Fund über `MIGHUB_FindLegacyCandidates($id, $host, $port, $unitId)` geprüft, ob eine
  Alt-Instanz eines anderen Moduls an derselben IP/Unit-ID existiert (Matching NIE über den
  Namen). Erkennt die Suche eine Alt-Instanz: neue ChargerHub-Instanz kommt mit „Kommunikation
  aktiv" = aus, ein neuer Button „Migration vorbereiten" verknüpft Alt-/Neu-Instanz über
  `MIGHUB_PrefillMigration()` (legt bei Bedarf eine MigrationsHub-Instanz an), ein
  `OpenObjectButton` führt direkt dorthin. Alles hinter `function_exists('MIGHUB_...')`
  abgesichert — ohne MigrationsHub verhält sich die Suche wie bisher. MQTT-/OCPP-Altbestände
  (z. B. go-e per MQTT, OCPP-Splitter) sind bewusst außen vor: ChargerHub spricht aktuell nur
  Modbus TCP, für andere Transportwege gibt es bei uns keine Zielstruktur.

## [0.9.19-beta.1] - 2026-07-27

### Fixed
- Eine der 0.9.18-Karteileichen (#30324 „Entsperrt durch RFID-Karte") überlebte die Aufräumung:
  `IPS_DeleteVariable()` scheitert still, wenn unter der Variable noch ein Kind-Objekt hängt —
  hier ein `Link` (vermutlich aus einer Visualisierung). Neue `DeleteVariableSafely()` entfernt
  vorher etwaige Link-Kinder.

## [0.9.18-beta.1] - 2026-07-27

### Fixed
- Karteileichen von Instanzen aufgeräumt, die während des kurzen 0.9.13-Zwischenfalls
  (ApplyChanges brach mitten im Lauf ab) eine zweite, direkt unter der Instanz hängende Kopie
  jeder Variable erhalten hatten, während die korrekte, in ihrer Kategorie verschachtelte
  Variable unangetastet weiterlief (live gefunden bei Dietmars beiden Instanzen, z. B.
  „Ladestatus" existierte doppelt). `PruneForeignObjects()` erkennt jetzt zusätzlich zum
  bisherigen „Ident nicht mehr gültig"-Fall auch doppelt vorkommende Idents und löscht gezielt
  die direkt unter der Instanz hängende Kopie, behält die verschachtelte.
- Veralteten, seit 0.9.12 widersprüchlichen Kommentar über `RegisterVar()` korrigiert (verwies
  noch auf das längst abgelöste rohe `IPS_CreateVariable()`-Muster).

## [0.9.17-beta.1] - 2026-07-25

### Changed
- Formular: Hinweis im „Verbindung"-Panel ergänzt, dass Host/Port/UnitId normalerweise
  automatisch von der ChargerHub-Suche befüllt werden — manuelle Eingabe ist nur der Fallback
  für händisch angelegte Instanzen. Gefunden bei einer Selbstprüfung nach dem SUITE.md-Kapitel
  „keine eigene Anlage als Norm annehmen" (analog zu einem echten Usability-Fund bei EMS).

## [0.9.16-beta.1] - 2026-07-25

### Fixed
- Eigentliche Ursache für "funktioniert per Skript, aber nicht über die normale Oberfläche"
  gefunden (unabhängig bestätigt durch denselben Bug bei InverterHub, DG65/NRGInverterHub
  Commits 2e1c56e→2565450): `IPS_SetVariableCustomAction($vid, 0)` ist die falsche API für
  Variablen, die eine Instanz per `RegisterVariableX()` selbst angelegt hat — sieht korrekt aus
  (keine Exception), bleibt aber wirkungslos für WebFront/Konsole (live bestätigt:
  `VariableAction` blieb `0`). Direkte `IPS_RequestAction()`-Aufrufe funktionierten trotzdem,
  weil sie nicht über diese Bindung laufen — daher der Widerspruch zwischen EMS' erfolgreichem
  Skript-Test und Dietmars erfolglosen Versuchen in der eigenen Oberfläche.
  Ersetzt durch die SDK-eigene `$this->EnableAction($Ident)`, aufgerufen direkt nach der
  Neuanlage über RegisterVariableX — noch BEVOR die Variable per `IPS_SetParent()` in ihre
  Kategorie verschoben wird, weil `EnableAction()` den Ident intern über das flache
  `GetIDForIdent()` auflöst (nur direkte Instanz-Kinder).

## [0.9.15-beta.1] - 2026-07-25

### Fixed
- Live entdeckt (Dietmar konnte die von EMS bestätigt funktionierenden IDs 31315/55705 danach
  nicht mehr finden): Die 0.9.14-Migrationsbedingung prüfte `IPS_GetVariable()['VariableAction']`
  live erneut nach jedem Übernehmen und fand weiterhin `0` — dadurch wurden die
  Steuervariablen bei **jedem** ApplyChanges neu gelöscht und angelegt, mit jeweils neuer ID,
  statt nur einmalig zu migrieren. Ersetzt durch ein persistentes Attribut
  (`ControlActionsMigrated`), das nach der ersten erfolgreichen `RegisterVariables()`-Runde
  dauerhaft auf `true` gesetzt wird — unabhängig davon, was `VariableAction` live zurückgibt.

## [0.9.14-beta.1] - 2026-07-25

### Fixed
- 0.9.13 löste den fehlenden VariableAction-Eintrag, brach aber `ApplyChanges()` live komplett
  (`IPS_ApplyChanges()` gab `false` zurück, Log voller „Ident muss für jede Ebene eindeutig
  sein" + „Kann Schnittstellen-Instanz nicht erstellen", Instanz #30324 nicht mehr sauber
  durchlaufbar, live reproduziert während ein Fahrzeug angesteckt war). Ursache:
  `RegisterVariableX()` registriert den Ident instanzweit, nicht nur bei den direkten Kindern —
  ein erneuter Aufruf, NACHDEM die Variable längst per `IPS_SetParent()` in eine
  Kategorie-Unterordner verschoben wurde, kollidierte dort mit sich selbst. Das war exakt der
  Grund, warum eine frühere Version überhaupt von `RegisterVariableX` auf rohes
  `IPS_CreateVariable()` umgestiegen war — dieser Kollisionsmechanismus wurde beim 0.9.12-Fix
  übersehen.
  - `RegisterVariableX()` läuft jetzt nur noch bei echter Neuanlage (`!$vid`), nicht mehr bei
    jedem `ApplyChanges()`. Die dabei gesetzte Kernel-Standardaktion bleibt über das spätere
    `IPS_SetParent()` in die Kategorie hinweg erhalten.
  - Für "control"-Variablen, die noch vor dem 0.9.12-Fix per rohem `IPS_CreateVariable()`
    erzeugt wurden (`VariableAction` weiterhin `0`), läuft einmalig eine gezielte
    Migration: löschen und über `RegisterVariableX()` neu anlegen — nur für diese betroffenen
    Variablen, nicht für alle. Die IDs von `ctl_enable`/`ctl_curr_limit` ändern sich dadurch
    einmalig; `CHUB_GetFunctions()` liefert die neuen IDs beim nächsten Aufruf automatisch.

## [0.9.13-beta.1] - 2026-07-25

### Fixed
- Live bestätigt (EMS-Sitzung, Fahrzeug an WB1 angesteckt): 0.9.12 reichte bei bereits
  bestehenden Steuervariablen (Ident/ID unverändert, ursprünglich vor dem Fix per rohem
  IPS_CreateVariable() erzeugt) nicht — ein erneuter RegisterVariableX()-Aufruf trägt die
  Standardaktion für schon existierende Objekte offenbar nicht nachträglich nach.
  `RegisterVar()` ruft jetzt zusätzlich unconditional `IPS_SetVariableCustomAction($vid, 0)`
  für alle Variablen der Gruppe „control" auf, unabhängig vom Neu-/Bestandsstatus.

## [0.9.12-beta.1] - 2026-07-25

### Fixed
- Root Cause für "Ladefreigabe/Stromlimit lassen sich nicht bedienen" endlich gefunden (Live-
  Diagnose der EMS-Sitzung an Instanz #30324, während ein Fahrzeug real angesteckt war):
  `RegisterVar()` erzeugte Steuervariablen bisher über rohes `IPS_CreateVariable()` +
  `IPS_SetIdent()`. Damit trägt der Symcon-Kernel **keine Standardaktion** ein, die die
  Variable an diese Instanz (`RequestAction()`) bindet — `IPS_SetVariableCustomAction($vid, 0)`
  lief dadurch immer ohne Fehler durch, änderte aber nachweislich nichts
  (`VariableAction`/`VariableCustomAction` blieben beide `0`). Variablen werden jetzt über
  `RegisterVariableBoolean()`/`Integer()`/`Float()`/`String()` erzeugt (wie beim SDK
  vorgesehen) und danach wie bisher per `IPS_SetParent()` in die passende Kategorie
  verschoben — das ändert nichts an der Kernel-Standardaktions-Zuordnung.
- Ident-Kollisionsrisiko aus einer früheren Version (Grund für den ursprünglichen Wechsel weg
  von `RegisterVariableX`) betraf laut Analyse nur den mehrstufigen Instanz-Anlage-Ablauf über
  die Discovery — dort bitte nach diesem Update einmal eine Testinstanz neu anlegen, um das zu
  bestätigen.

## [0.9.11-beta.1] - 2026-07-25

### Fixed
- Live-Vergleich mit der funktionierenden Referenzinstanz „go-e Controller" (anderes Modul, selbes
  System) zeigte: dessen Schalter-Variable trägt korrekt `~Switch` als Profil, unsere Instanz
  dagegen bei JEDER Variable — auch reinen Anzeigevariablen wie „Ladeleistung", nicht nur den
  Steuervariablen — kein Profil, trotz bestätigtem Code-Update, Kern-Neustart UND explizitem
  „Übernehmen". Die in 0.9.10 eingeführte Bedingung „nur setzen, wenn `$created` oder aktuell
  leer" verhinderte das Setzen offenbar unabhängig vom eigentlichen Zustand der Variable.
  `RegisterVar()` setzt das Profil jetzt unconditional bei jedem Übernehmen, ohne Sonderfall.

### Changed
- Globale Klasse `ModbusTcpClient` in `CHUB_ModbusTcpClient` umbenannt (Verbund-Fund von der
  EMS-Sitzung: MeterHub deklariert ebenfalls eine globale Klasse `ModbusTcpClient` — sobald ein
  Konsument beide Module im selben PHP-Prozess lädt, kollidiert das mit `Fatal error: Cannot
  redeclare class ModbusTcpClient`). InverterHub/MeterHub ziehen denselben Präfix-Ansatz nach.

## [0.9.10-beta.1] - 2026-07-25

### Fixed
- Live-Check an Instanz #30324 (Konsole zeigte weiterhin kein Schalter-Icon, keinen
  „Schalten/Simulieren"-Dialog, geschriebene Werte sprangen nach wenigen Sekunden zurück)
  ergab zwei getrennte Ursachen:
  - `RegisterVar()` setzte das Variablenprofil (`~Switch` etc.) bisher nur bei echter
    Neuanlage der Variable (`$created === true`). Bei dieser Instanz blieb das Profil dadurch
    dauerhaft leer — kein Schalter-Icon, kein Schalten/Simulieren-Dialog. Wird jetzt zusätzlich
    nachgetragen, wenn die Variable aktuell kein Profil trägt; ein bewusst vom Nutzer gesetztes
    eigenes Profil bleibt unangetastet.
  - `ModbusTcpClient::writeSingle()`/`writeMultiple()` gaben bei einer Modbus-Exception-Antwort
    vom Gerät (oder Timeout/Verbindungsfehler) stillschweigend `false` zurück. Der Treiber
    schreibt den neuen Wert nur bei Erfolg in die Variable — bei einem stillen Fehlschlag blieb
    der alte Gerätewert bestehen und die Konsole sprang beim nächsten Poll sichtbar zurück, ohne
    dass irgendwo ein Grund protokolliert wurde. Jetzt wertet `CheckWriteResponse()` die Antwort
    aus (Timeout, zu kurze Antwort, oder Modbus-Exception-Code mit Klartext-Bedeutung) und
    `RequestAction()` schreibt den Grund ins Meldungen-Log unter „ChargerHub-Schreibfehler".

## [0.9.9-beta.1] - 2026-07-25

### Fixed
- Steuer-Variablen (Ladefreigabe, Stromlimit (A) etc.) waren in der Konsole nicht bedienbar
  (nicht fett dargestellt), Klick auf „Übernehmen" bzw. der `EnableActionsTimer` warfen
  `Warning: Skript #<InstanceID> existiert nicht`. Ursache: `IPS_SetVariableCustomAction()`
  erwartet als zweiten Parameter laut Doku **keine** Instanz-ID, sondern 0 (Standardaktion
  aktivieren), 1 (deaktivieren) oder eine echte Skript-ID (>1). Es wurde durchgängig
  `$this->InstanceID` übergeben — Symcon deutete diese Zahl als Skript-ID und meldete
  zurecht, dass kein Skript mit dieser Nummer existiert. Ersetzt durch die dokumentierte
  Konstante `0`.

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
- **Vertragsversionierung** (Verbund-Konvention, [SUITE.md](https://github.com/DG65/NRGEMS/blob/main/SUITE.md)):
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

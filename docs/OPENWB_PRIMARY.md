# openWB software2 Primary (ChargerHub ab 0.13.0)

Beitrag von Dirk (Forum „dallard“), für den Verbund übernommen. Die Anbindung nutzt die **HTTP-simpleAPI der openWB
software2 Primary** ([Dokumentation](https://wiki.openwb.de/doku.php?id=openwb:vc:2.3.x:simpleapi)). Eine von der Primary
gesteuerte openWB Pro wird **nie** direkt per Modbus oder OCPP angesprochen: nur die Primary eintragen.

**Standard ist ausschließlich lesend.** Die Laderegelung der Primary bleibt zuständig, `CHUB_GetFunctions` meldet dann
`managedBy=other` und keine schreibbaren Steuer-IDs (`chargeEnableID`/`currentLimitID` = 0).

## Einrichtung

1. Hersteller **openWB Primary (software2, HTTP simpleAPI)** wählen.
2. Adresse der **Primary** eintragen (nicht der Pro), HTTP(S)-Port (80 oder 443), **Ladepunkt-ID ausdrücklich** wählen
   (nullbasiert, auf der openWB-Statusseite prüfen; es gibt keinen automatischen Rückfall).
3. Optional HTTPS (Serverzertifikat wird geprüft) und ein Bearer-Token. Das Token wird nur über HTTPS gesendet. Es wird mit
   der Schaltfläche „Token speichern“ im Attribut abgelegt (nicht in der Property, also nicht im Formular sichtbar), das
   Eingabefeld wird danach geleert; ein leeres Feld löscht das gespeicherte Token.
4. **HTTP-Wartezeit** (Standard 8 Sekunden, 2 bis 30): wie lange ChargerHub auf eine Antwort der Primary wartet. Nach einem Update
   (zum Beispiel auf openWB 2.4 alpha-1) antwortet die Primary langsamer; mit der früher fest eingestellten Wartezeit von 3 Sekunden
   endete dann jede Anfrage mit „Connection failed“. Zeigt die Instanz „HTTP-Anfrage nach … s ohne Antwort“, den Wert erhöhen.

## Lesen (ein GET je Abfrage)

Ein GET je Poll:

`/openWB/simpleAPI/simpleapi.php?get_chargepoint_all=<ID>`

Erwartetes JSON: `{ "chargepoint_<ID>": { "power": ..., "imported": ..., ... } }`.
Die Pflichtfelder sind `power`, `imported`, `plug_state`; fehlen sie oder sind
sie ungültig, wird `connected=false` gesetzt und kein alter Messwert durch
eine vermeintliche Null ersetzt.

| openWB-Feld | ChargerHub-Ident | Typ/Umrechnung | Anmerkung |
|---|---|---|---|
| `power` | `power` | W → W | Momentane Ladeleistung |
| `imported` | `energy_total` | **Wh ÷ 1000 → kWh** | Kumulativer Gesamtzähler, kein Tages- oder Sitzungswert |
| `plug_state` | `vehicle_plugged` | Boolean | Nur gültiger Bool-/0/1-Wert |
| `voltages[0..2]` | `voltage_l1..l3` | V → V | Optional |
| `currents[0..2]` | `current_l1..l3` | A → A | Optional |
| `phases_in_use` | Verbundfeld `phases` | 1 oder 3 | Nur vom Gerät gemeldete Werte, keine Schätzung |
| `chargemode` | `openwb_mode` | String | Tatsächlich gelesene Werte wie `instant_charging`, `pv_charging`, `eco_charging`, `scheduled_charging`, `stop`; nicht identisch mit dem verkürzten POST-Wert `instant` |
| `state_str` | `openwb_state_text` | String | Statusbeschreibung |
| `fault_state` | `error_code` | Integer | 0 = kein Fehler, laut openWB |
| `fault_str` | `openwb_fault_text` | String | Fehlerbeschreibung |
| `soc` oder `soc.soc` | `openwb_soc` | % | **SoC des in openWB zugeordneten Fahrzeugs** (nicht `pro_soc`); nur mit `openwb_soc_available=true` gültig; unabhängig vom Steckerzustand |
| `serial_number` | `dev_serial` | String | Optional |
| `version` | `dev_firmware` | String | Optional |
| `vehicle_name` | `openwb_vehicle_name` | String | Beeinflusst **nicht** das externe `vehicle_name` der NRG-Zuordnung |

Nicht aus der API behauptet:

- **`energy_session`** wird nicht angelegt: kein bestätigtes Sitzungsfeld
  im verwendeten Gesamt-Snapshot. `CHUB_GetFunctions().sessionEnergyID=0`.
- **Phasenumschaltung**: `phaseModeID=0`; `phasesSwitchable` bleibt unbekannt.
- **Reale Steuerlatenz**: `controlLatencySec=0` (unbekannt).
- **SoC und Fahrzeugverbindung sind verschieden**: `vehicle_plugged=false`
  bedeutet kein physisch angestecktes Fahrzeug; `openwb_soc_available=true`
  bedeutet lediglich, dass openWB für das **zugeordnete Fahrzeug** einen
  gültigen SoC meldet. Beides kann gleichzeitig gelten. Die eigens
  gemessene Pro-SoC-Quelle wäre `pro_soc`, wird hier nicht als SoC ausgegeben.
- **SoC bei fehlendem Wert**: `openwb_soc_available=false` – ein zuvor
  angezeigter Wert oder 0 % ist dann *kein* aktueller Messwert.

Alle anderen ChargerHub-Treiber, Variablenprofile, Module-GUIDs und
die Verträge für Dashboard/MeterHub/EMS bleiben bestehen.

## Optionale NRG-EMS-Steuerung (NICHT die Standardkonfiguration)

Nur nach bewusster Entscheidung, die **Ladeplanung/Leistungsanforderung** von
der Primary an NRG EMS zu übertragen:

1. Im Formular `Who controls this charge point?` = `EMS` setzen.
2. Unter `Data points`: `Enable NRG EMS commands via openWB Primary`
   aktivieren **und** `openWB charging/status information` eingeschaltet lassen.
3. Danach erst den EMS-Modus umstellen und die Steuerung testen.

Es gelten diese Befehle, ausschließlich an die Primary:

| NRG-Variable | HTTP POST | Wirkung |
|---|---|---|
| `ctl_enable=true` | `set_chargemode=instant&chargepoint_nr=<ID>` | Schaltet die Primary bewusst auf **Sofortladen** |
| `ctl_enable=false` | `set_chargemode=stop&chargepoint_nr=<ID>` | Stoppt den Lademodus über die Primary |
| `ctl_curr_limit=A` | `chargecurrent=A&chargepoint_nr=<ID>` | Nur bei gelesenem `openwb_mode=instant_charging` (auch Altwert `instant`); 6–32 A, zusätzlich durch `MaxCurrent` begrenzt. Bei `MaxCurrent<6` **kein Schreibzugriff**. |

**Wichtig:** Das ist eine **Übergabe der Ladestrategie**. NRG EMS kann nicht
gleichzeitig Stromlimits vorgeben und erwarten, dass die eigene PV-Strategie
der Primary unberührt bleibt. In der empfohlenen Standardkonfiguration
(`Other`, Steuergruppe aus) werden `chargeEnableID` und `currentLimitID` im
Verbundvertrag als **0** gemeldet – ein EMS bekommt keine Schreib-IDs.
Bei inaktiver Instanz, Demo-Modus oder fehlender Berechtigung werden
Schreibversuche außerdem serverseitig abgewiesen. Ein Strom-Sollwert `0`
ist nie ein Pausenbefehl; Pausieren über `ctl_enable=false`.

Keine automatischen Änderungen an PV, ECO, Batterie-Modus, Fahrzeugzuordnung,
Zähler oder Phasenregler der Primary. Von der Primary gesteuerte Pro wird
nie direkt geschrieben.

## Tests

- Offline, ohne Hardware: `php .tools/test-openwb-primary.php` (simulierte HTTP-Antworten: Einheiten, unvollständige
  Antworten, Lademodus-Unterscheidung, Schreibsperre, Strombegrenzung, kein Token über HTTP).
- Lesende Abnahme in Symcon: `tests/Symcon_ReadOnly_Abnahme.php` als Skript anlegen, die Instanz-ID eintragen. Es schreibt nichts.

## Stand der Prüfung

An einer realen Anlage (Primary mit openWB Pro 11 kW) wurde **nur das Lesen** bestätigt. Die schreibenden Befehle sind
**an Hardware nicht getestet**.

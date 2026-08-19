# Forum-Beitrag für die IP-Symcon Community

**Kategorie:** dieselbe wie bei InverterHub und MeterHub (Kategorie 73, „PHP-Module Diskussion").

**Titelvorschlag:**

> [Beta-Tester gesucht] ChargerHub — ein Modbus-TCP-Modul für Wallboxen: go-eCharger, KEBA, Alfen, Heidelberg (+ Netzwerksuche + Lastvorgabe fürs Überschussladen)

**Bilder:** `suite.png` im Abschnitt „Die Modulfamilie". Screenshots aus der eigenen Anlage
(Netzwerksuche mit gefundenen Wallboxen, Konfigurationsmaske, angelegte Variablen) sind wie beim
InverterHub sinnvoll — die musst Du beisteuern.

---

# ChargerHub — ein Modbus-TCP-Modul für viele Wallboxen (+ Netzwerksuche)

Moin zusammen,

nach dem **InverterHub** (Wechselrichter) und dem **MeterHub** (Energiezähler) fehlte im Baukasten
noch das Stück, das nicht nur misst, sondern **eingreift**: **ChargerHub** liest Wallboxen per
**Modbus TCP** aus *und* steuert sie — Ladefreigabe, Stromlimit, beim go-eCharger auch die
Phasenumschaltung. Wieder ein gemeinsames Treibergerüst statt eines eigenen Moduls je Hersteller;
wer die beiden anderen kennt, findet sich sofort zurecht.

Ein Hersteller ist an echter Hardware verifiziert, einer gegen eine erprobte Referenzumsetzung
abgeglichen, zwei sind aus der Herstellerdoku abgeleitet. **Genau dafür suche ich Rückmeldungen.**

## Die zwei Module

- **ChargerHub** — die Auslese- und Steuerinstanz. Hersteller wählen, IP eintragen,
  Datenpunkt-Gruppen aktivieren. Ein kleiner Kern (Ladestatus, Ladeleistung, Energie) ist immer
  aktiv, alles Weitere je Gruppe zuschaltbar.
- **ChargerHub Suche** — durchsucht einen IP-Bereich und legt gefundene Wallboxen automatisch als
  Instanz an. Die Erkennung läuft über charakteristische Register mit Plausibilitätsprüfung, nicht
  über Rateverfahren.

## Nur ein Regler je Wallbox — das eigentliche Thema

Bei Zählern und Wechselrichtern ist die Frage „wer schreibt?" meist schnell beantwortet. Bei
Wallboxen ist sie **das** Problem, und sie wird gerade schlimmer:

- der **go-e Controller** kann Überschussladen selbst regeln,
- **Tibber Grid Rewards** vermarktet Regelenergie inzwischen über die Wallbox,
- ein **Lastmanagement** verteilt Ströme über mehrere Ladepunkte,
- ab **EnWG §14a** kommt die Dimm-Vorgabe des Netzbetreibers dazu,
- und dann möchte auch noch ein Energiemanagement optimieren.

Alle greifen auf dieselbe Stellgröße zu. Setzen zwei davon gleichzeitig das Stromlimit,
überschreiben sie sich gegenseitig — die Wallbox pendelt, und im Log sieht man nur, dass „irgendwer"
den Wert geändert hat. Bei Tibber kann das sogar Geld kosten, weil eine zugesagte Regelenergie-
Erbringung gestört wird.

Deshalb gibt es in jeder Instanz die Kennzeichnung **„Ladepunkt wird bereits extern geregelt"**.
Ist sie gesetzt, meldet das Modul über seine Schnittstelle: *Finger weg, hier regelt schon jemand* —
ein Energiemanagement liest den Ladepunkt dann nur noch mit. Bewusst **von Hand** gesetzt: Ob ein
go-e Controller oder Tibber gerade mitregelt, steht in keinem Modbus-Register; das Modul könnte es
allenfalls raten, und Raten ist hier die schlechteste Option.

Darunter liegt eine zweite Grenze, die **unabhängig von allem** gilt: der **maximale
Anschlussstrom** je Instanz. Was ein übergeordneter Regler anfordert, ist ein Wunsch — der Treiber
klemmt jeden Schreibvorgang hart auf diesen Wert (und zusätzlich auf das Hardware-Maximum der
Wallbox). Ein Rechenfehler im Energiemanagement soll nicht an der Zuleitung ankommen.

## Unterstützte Wallboxen

**Legende:** ✅ an echter Hardware bestätigt · 🔧 gegen eine an echter Hardware erprobte
Referenzumsetzung abgeglichen · 🧪 aus Herstellerdoku umgesetzt (Feldrückmeldung willkommen)

| Wallbox | Status | Anmerkung |
|---|---|---|
| **go-eCharger** (Gemini, HOME+, V3/V4) | ✅ | An zwei Geräten im Betrieb. Mit Abstand der größte Funktionsumfang: neben Status/Leistung/Energie auch Leistung je Phase, **aktiv genutzte Phasen**, Kabelcodierung, Adapter, RFID-Karte. Steuerbar: Ladefreigabe, Stromlimit, **Phasenumschaltung**, Zugangskontrolle, Kabelverriegelung, Energielimit je Ladevorgang, LED-Helligkeit. Die Steuerwerte werden vom Gerät **zurückgelesen** — was jemand in der App umstellt, ist in Symcon sichtbar |
| **KEBA KeContact** (P30, P40) | 🔧 | Registerkarte gegen die evcc-Umsetzung abgeglichen, die an realer Hardware erprobt ist. Ladestatus, Kabelstatus, Leistung, Energie gesamt + Ladevorgang, Strom/Spannung je Phase, Steuerung bis 63 A. **P40:** dort läuft die Ladefreigabe über das Stromlimit statt über ein eigenes Register — noch nicht gesondert behandelt |
| **Alfen** (Eve Single/Double Pro-line, NG9xx) | 🧪 | Sockelstatus, Leistung, angewandtes Limit, Spannung/Strom je Phase, Steuerung. Alfen verlangt eine **Gültigkeitsdauer** für jeden Sollwert — das Modul erneuert sie mit. Derzeit nur Sockel 1 |
| **Heidelberg Energy Control** | 🧪 | Ladestatus, Leistung, Strom/Spannung je Phase, Platinentemperatur, Steuerung bis 16 A (mehr kann das Gerät nicht) |

Alle Registeradressen stehen im **Beschreibungsfeld** jeder Variable (Objekt-Manager, Spalte
„Beschreibung") — praktisch für den Abgleich mit dem Handbuch.

## Das Wichtigste in Kürze

- 🔌 **Ein Modul, mehrere Hersteller** — austauschbare Treiber, gemeinsame Konventionen.
- 🔍 **Netzwerksuche** über Port 502 mit Abbrechen-Knopf und Fortschrittsanzeige.
- ⚙️ **Steuerung aus Symcon heraus** — Ladefreigabe und Stromlimit per Skript, Ablaufplan oder
  Energiemanagement; beim go-eCharger zusätzlich die Phasenumschaltung, der wichtigste Hebel beim
  Überschussladen (einphasig ab ~1,4 kW, dreiphasig ab ~4,2 kW).
- 🛡️ **Maximaler Anschlussstrom** je Instanz — harte Grenze bei *jedem* Schreibvorgang.
- 🚦 **Regler-Kennzeichnung** für Ladepunkte, die schon jemand anderes regelt (siehe oben).
- 🔗 **`CHUB_GetFunctions($id)`** — dieselbe Schnittstellenidee wie `MHUB_GetFunctions`: Leistungs-
  und Energievariablen, Steuer-IDs, Fahrzeugerkennung, Mindest-/Höchststrom und die
  Regler-Kennzeichnung als JSON.

## Anschluss-Besonderheiten (kurz)

- **go-eCharger:** Modbus muss in der go-e-App erst freigeschaltet werden (Internet → Erweiterte
  Einstellungen → Modbus). Und dann der Stolperstein, der hier einen Abend gekostet hat: Steht die
  Einstellung bereits auf „aktiv", heißt das nicht, dass der Server läuft — einmal aus- und wieder
  einschalten hilft. Prüfen lässt sich das im Browser mit
  `http://<wallbox-ip>/api/status?filter=men`. Solange Port 502 zu ist, findet die Suche schlicht
  nichts. Firmware 60.3 vertauschte außerdem die Byte-Reihenfolge (dafür gibt es einen Schalter,
  mit 60.4 behoben). Kleine Besonderheit am Rande: go-e unterstützt **kein** Schreiben einzelner
  Register — alles läuft über FC 0x16.
- **KEBA:** Unit-ID werkseitig **255**. Alle Werte sind 32 Bit über zwei Register — auch der
  Ladestatus. Wer ihn als einzelnes Register liest, bekommt immer 0 und sucht den Fehler lange an
  der falschen Stelle. (Ja, genau das war hier der erste Versuch.)
- **Alfen / Heidelberg:** Unit-ID werkseitig 1, Port 502.
- **Alfen:** Eve Double bedient derzeit nur Sockel 1.

## Installation

Über die **Modulverwaltung** → Modul hinzufügen → GitHub-Repository:

`https://github.com/DG65/NRGChargerHub` (Zweig **beta**)

Im Symcon Module Store ist das Modul noch nicht — der Beta-Zweig ist der schnellere Weg zu
Korrekturen.

## Status und was ich brauche

Das Ganze ist **Beta** und frisch. Rückmeldungen sind ausdrücklich erwünscht — bitte mit
**Hersteller, Modell und betroffenem Register/Wert**.

Ganz konkret fehlt mir Hardware für:

- **Alfen Eve** — kein Gerät zur Hand, der Treiber ist reine Doku-Arbeit. Wer eine Eve besitzt und
  einen Blick riskieren mag: sehr gerne. Beim **Eve Double** interessiert mich besonders der zweite
  Sockel, den ich dann nachziehen kann.
- **Heidelberg Energy Control** — dito.
- **KEBA P40** — die Ladefreigabe funktioniert dort anders als beim P30. Mit einer Rückmeldung
  baue ich das sauber ein.
- **go-eCharger, Schreibseite** — Lesen läuft bestätigt, aber Stromlimit und Phasenumschaltung
  habe ich noch nicht systematisch gegen die App gegengeprüft.

Und zwei Wallboxen stehen auf der Liste, mir fehlen die Registertabellen:

- **Vestel EVC04** und **Webasto Unite** (gleiche Plattform — ein Treiber, zwei Marken)
- **ABB Terra AC**

Wer eine Modbus-Adressliste oder eine funktionierende Symcon-Vorlage hat: her damit. Geraten wird
nicht — falsche Registeradressen liefern still falsche Werte, und bei einem Gerät, das 11 kW
schaltet, ist das keine akademische Frage.

## Die Modulfamilie: der NRG-Stack

ChargerHub steht nicht allein. Über die Zeit ist ein ganzer Baukasten entstanden — der
**NRG-Stack** —, dessen Teile zusammenarbeiten, aber **jedes Modul läuft auch für sich**. Es gibt
keine Pflichtabhängigkeiten: Fehlt der Partner, fällt nur dessen Zusatzfunktion weg. Welche
Modulstände zusammen getestet sind, listet
[SUITE.md](https://github.com/DG65/NRGEMS/blob/main/SUITE.md).

> **[hier `suite.png` einfügen]**

Für ChargerHub heißt das: Ohne Partner ist es eine Wallbox-Anbindung mit Handsteuerung — Ladefreigabe
und Stromlimit per Skript oder Ablaufplan, das reicht für „lade nachts" oder „lade nur mit 8 A"
völlig. Mit MeterHub und InverterHub daneben kennt das Energiemanagement Erzeugung, Netzbezug und
Verbrauch — und kann daraus echtes Überschussladen machen, inklusive Umschalten zwischen ein- und
dreiphasig.

## Was noch kommt

- **Weitere Hersteller**, priorisiert nach Verbreitung *und* danach, ob sie überhaupt Modbus TCP
  sprechen. Das ist die unangenehme Wahrheit dieses Marktsegments: Einige der meistverkauften
  Wallboxen (easee, Wallbox Pulsar Plus) bieten lokal **gar kein** Modbus an, sondern nur Cloud oder
  Bluetooth — die sind mit diesem Ansatz grundsätzlich nicht erreichbar. Nächste Kandidaten sind
  deshalb Vestel/Webasto, ABB Terra AC, SMA EV Charger und Mennekes Amtron.
- **Phasengenaues Stromlimit** bei Wallboxen, die das können, statt eines Summenlimits.
- **Alfen Eve Double** — zweiter Ladepunkt.
- **§14a-Anbindung**: Kommt die Steuerbox, wird die Dimm-Vorgabe **nicht** in dieses Modul wandern,
  sondern in ein eigenes. Eine gesetzliche Vorgabe darf nicht davon abhängen, ob gerade ein
  Energiemanagement läuft; und der Anschlussstrom-Riegel hier unten greift ohnehin unabhängig davon.

Danke fürs Lesen — und fürs Testen! 🙌

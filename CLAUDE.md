# Hinweise für die Arbeit an diesem Repository

## Verwandte Repositories

Teil desselben Modul-Verbunds, an mehreren wird teilweise **gleichzeitig in getrennten
Sitzungen** gearbeitet:

- **ChargerHub** (dieses Repo): Wallboxen per Modbus TCP — https://github.com/DG65/ChargerHub
- **InverterHub**: Wechselrichter per Modbus TCP — https://github.com/DG65/InverterHub
- **MeterHub**: Energiezähler per Modbus TCP — https://github.com/DG65/MeterHub
- **MigrationsHub**: Migration von Bestandsgeräten/Verknüpfungen/Archivwerten —
  https://github.com/DG65/MigrationsHub
- **EMS**: koordinierende Instanz, künftig einziger zulässiger Konsument aller Hub-Verträge —
  https://github.com/DG65/EMS

## Grundregeln des Verbunds (identisch in den anderen Hub-Repos)

1. **Kein Modul darf ein anderes voraussetzen.** Jeder Aufruf einer fremden Modulfunktion
   (`IHUB*_`, `MHUB*_`, `EMS_` …) muss innerhalb derselben Funktion durch `function_exists()`
   abgesichert sein — sonst Fatal Error, wenn das Partnermodul fehlt. Siehe
   `.tools/check-standalone.php`.
2. **Suchrichtung bei „Instanzen suchen": nur vom EMS aus, nie zurück.** Ein Hub darf das EMS
   niemals voraussetzen oder danach suchen.
3. **`*_GetFunctions`-Konvention** (Referenz: `MHUB_GetFunctions` in MeterHub) — eine Liste von
   Einträgen mit `function`/`label`/`powerID`/`energyImportID`/`energyExportID`/`measured`.
   `CHUB_GetFunctions` ist **mit der EMS-Sitzung abgestimmt (v1, 2026-07-22)** und um die
   Charger-spezifischen Felder erweitert: `chargeEnableID`/`currentLimitID` (Steuer-IDs, nur
   fürs EMS — Anzeigemodule dürfen darüber nicht schalten), `plugStateID` (optional),
   `minCurrent`/`maxCurrent` (Werte, keine IDs) und `externallyManaged` (bool; true = ein
   externes Lastmanagement wie der go-e Controller regelt bereits, EMS liest dann nur).
   Feldtabelle im README. Verträge werden dort konsumiert, wo aggregiert oder dargestellt
   wird (EMS, Kachel, Sankey) — nie Mess-Hub zu Mess-Hub.
4. **Ein veröffentlichter Vertrag wird nicht umbenannt.** Sobald ein Modul im Store ist, sind
   Feldnamen öffentliche API. Das gilt ausdrücklich auch für **Idents** (`ctl_*`,
   `energy_total`, `vehicle_plugged` …) — Idents sind API und werden nie umbenannt.
5. **Sprachregel: alles Nutzersichtbare auf Deutsch** (Anweisung Dietmar, 2026-07-22, gilt
   verbundweit). Deutsch sind: Formularbeschriftungen, Hinweis-/Warntexte, Fehler- und
   Statusmeldungen, Rückgabe-Texte, Log-Meldungen, Variablen- und Profil-**Anzeigenamen**,
   README/Dokumentation. Vermeidbare Anglizismen ersetzen (Scan → Suche, Link → Verknüpfung,
   Event → Ereignis, Button → Schaltfläche, Dry-Run → Probelauf).
   **Ausgenommen** (bleibt englisch): Bezeichner im Code — Klassen-, Methoden-, Variablen-,
   Property- und Ident-Namen, Formularelement-Typen (`'type' => 'Button'`) sowie die
   `CHUB_GetFunctions`-Feldnamen — und feststehende Fachbegriffe, bei denen das englische
   Wort DER Fachbegriff ist (Modbus TCP, Holding-/Input-Register, FC 0x16, Unit-ID, RFID,
   LED, SelectVariable, WebFront). Faustregel: eindeutschen, wo es das Verständnis
   verbessert; stehen lassen, wo es das Verständnis erschwert.
   Beim Ersetzen **den Diff Zeile für Zeile lesen**, nie blind ersetzen — drei erprobte
   Stolperfallen aus dem Verbund:
   1. *Genus-Kongruenz*: der Portcheck → die Port-Prüfung ⇒ Artikel und Adjektivendungen
      mitziehen („einen zuverlässigen Portcheck" → „eine zuverlässige Port-Prüfung").
   2. *Objekt-Verwechslung bei scannen*: Ein **Bereich** wird durchsucht/abgesucht, ein
      **Gerät** wird gefunden. „Geräte hinter Gateways lassen sich nicht durchsuchen" ist
      grammatisch richtig, inhaltlich falsch — korrekt: „… findet die Suche nicht
      zuverlässig". Im Englischen steht beide Male „scan".
   3. *Fachbegriffe nicht überdehnen* (siehe Ausnahmeliste oben).

## Eigenständigkeit prüfen: `.tools/check-standalone.php`

```
php .tools/check-standalone.php    # 0 = sauber, 1 = ungesicherter Fremdaufruf
```

Herkunft und Funktionsweise wie im MeterHub-Repo — bei Änderungen an der Prüflogik bitte in
allen Hub-Repos gleich halten.

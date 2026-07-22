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
   Feldnamen öffentliche API.

## Eigenständigkeit prüfen: `.tools/check-standalone.php`

```
php .tools/check-standalone.php    # 0 = sauber, 1 = ungesicherter Fremdaufruf
```

Herkunft und Funktionsweise wie im MeterHub-Repo — bei Änderungen an der Prüflogik bitte in
allen Hub-Repos gleich halten.

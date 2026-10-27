<?php
/*
 * Messskript: Wann setzt sich „Energie akt. Sitzung“ (energy_session) zurück?
 *
 * Läuft als IP-Symcon-Skript (Skript-Objekt anlegen, diesen Inhalt einfügen). Es ändert NICHTS an der
 * Wallbox oder am ChargerHub, es liest nur Variablen und schreibt bei jeder Änderung eine Zeile in
 *   <Symcon-Verzeichnis>/logs/sitzungsenergie_messung.csv
 *
 * Bedienung: Skript im Editor einmal ausführen = Messung AN (Abtast-Timer 2 s), erneut ausführen = AUS.
 * ChargerHub-Abfrageintervall mindestens 2 s, sonst sieht man nur dessen Takt.
 *
 * Versuchsablauf (Zeitpunkte am besten kurz notieren, die CSV hat Uhrzeiten):
 *   1. Messung starten, Auto NICHT angesteckt, ca. 30 s warten (Ausgangswert).
 *   2. Auto anstecken, Ladefreigabe AUS lassen, ca. 60 s warten.
 *        -> Zeigt: Setzt sich energy_session beim ANSTECKEN zurück?
 *   3. Ladefreigabe EIN, ca. 2 Minuten laden.
 *        -> Zeigt: Setzt sich der Wert erst beim ersten Stromfluss zurück?
 *   4. Ladefreigabe AUS (Pause), 60 s warten, wieder EIN, 1 Minute laden.
 *        -> Zeigt: Springt der Wert nach einer Sperre zurück?
 *   5. Auto abstecken, 60 s warten (bleibt der Endwert stehen?), dann erneut anstecken, 60 s warten.
 *   6. Messung beenden (Skript erneut ausführen).
 */

// ID der ChargerHub-Instanz der go-e (Instanz-ID im Objektbaum eintragen):
$hubID = 0;

$idents = ['vehicle_plugged', 'state', 'ctl_enable', 'ctl_curr_limit', 'power', 'energy_session', 'energy_total'];
$datei  = IPS_GetKernelDir() . 'logs/sitzungsenergie_messung.csv';

function MS_findVar(int $parent, string $ident): int
{
    foreach (IPS_GetChildrenIDs($parent) as $cid) {
        $o = IPS_GetObject($cid);
        if ($o['ObjectType'] === 2 && $o['ObjectIdent'] === $ident) {
            return $cid;
        }
        if ($o['ObjectType'] === 0) {
            $f = MS_findVar($cid, $ident);
            if ($f > 0) {
                return $f;
            }
        }
    }
    return 0;
}

$self = $_IPS['SELF'];

if ($_IPS['SENDER'] !== 'TimerEvent') {
    // Von Hand ausgeführt: Messung ein-/ausschalten
    if ($hubID <= 0 || !IPS_InstanceExists($hubID)) {
        echo "Bitte oben \$hubID auf die Instanz-ID des ChargerHub (go-e) setzen.\n";
        return;
    }
    $an = IPS_GetScriptTimer($self) > 0;
    if ($an) {
        IPS_SetScriptTimer($self, 0);
        echo "Messung AUS. Datei: $datei\n";
    } else {
        if (!file_exists($datei)) {
            file_put_contents($datei, "zeit;" . implode(';', $idents) . "\n");
        }
        IPS_SetScriptTimer($self, 2);
        echo "Messung AN (alle 2 s, Zeile nur bei Änderung). Datei: $datei\n";
    }
    return;
}

// Timer: abtasten, nur bei Änderung eine Zeile schreiben
$werte = [];
foreach ($idents as $ident) {
    $vid = MS_findVar($hubID, $ident);
    $werte[] = $vid > 0 ? (string)GetValue($vid) : '-';
}
$letzte = @file_get_contents(IPS_GetKernelDir() . 'logs/sitzungsenergie_messung.last');
$jetzt  = implode(';', $werte);
if ($letzte !== $jetzt) {
    file_put_contents($datei, date('H:i:s') . ';' . $jetzt . "\n", FILE_APPEND);
    file_put_contents(IPS_GetKernelDir() . 'logs/sitzungsenergie_messung.last', $jetzt);
}

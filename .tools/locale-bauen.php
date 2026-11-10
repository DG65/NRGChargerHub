<?php
/*
 * Kopiert libs/locale.json (Quelle der Uebersetzungen) in die drei Modulordner. Symcon liest die locale.json
 * nur direkt im Modulordner, die Texte sind aber fuer alle drei Module gemeinsam gepflegt.
 *   php .tools/locale-bauen.php   -> Kopien schreiben
 */
$root = dirname(__DIR__);
$quelle = file_get_contents($root . '/libs/locale.json');
if (!is_array(json_decode($quelle, true))) { fwrite(STDERR, "libs/locale.json ist kein gueltiges JSON\n"); exit(1); }
foreach (['ChargerHub', 'ChargerHubBridge', 'ChargerHubDiscovery'] as $m) {
    file_put_contents("$root/$m/locale.json", $quelle);
    echo "geschrieben: $m/locale.json\n";
}

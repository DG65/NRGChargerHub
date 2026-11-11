<?php
/*
 * Prueft die Zweisprachigkeit (Store-Pruefliste 25-29):
 *  1. Die drei locale.json in den Modulordnern sind identisch mit libs/locale.json.
 *  2. Jede Uebersetzung hat dieselben Platzhalter (%s %d %1$s) wie ihr englischer Schluessel.
 *  3. Kein Eintrag ist leer.
 *  4. Jeder Text, der im Code uebersetzt wird (CHUB_Text::t / Translate) oder als Formularbeschriftung
 *     ('caption' / 'label' / 'name' einer Option) steht, hat einen Eintrag.
 *  5. Jeder Eintrag kommt im Code noch vor (sonst veraltet).
 *  php .tools/locale-pruefen.php    # 0 = sauber, 1 = Fehler
 */
$root = dirname(__DIR__);
$fehler = [];
$quelle = file_get_contents($root . '/libs/locale.json');
$tr = json_decode($quelle, true)['translations']['de'] ?? null;
if (!is_array($tr)) { fwrite(STDERR, "libs/locale.json unlesbar\n"); exit(1); }
foreach (['ChargerHub', 'ChargerHubBridge', 'ChargerHubDiscovery'] as $m) {
    if (@file_get_contents("$root/$m/locale.json") !== $quelle) {
        $fehler[] = "$m/locale.json weicht von libs/locale.json ab (php .tools/locale-bauen.php)";
    }
}
$platzhalter = function (string $s): array {
    preg_match_all('/%(?:\d+\$)?[sd]/', str_replace('%%', '', $s), $m);
    $a = $m[0]; sort($a); return $a;
};
foreach ($tr as $en => $de) {
    if ($de === '' || trim($en) === '') { $fehler[] = "leerer Eintrag: " . substr($en, 0, 60); continue; }
    if ($platzhalter($en) !== $platzhalter($de)) { $fehler[] = "Platzhalter verschieden: " . substr($en, 0, 70); }
}
// Code-Literale sammeln
$dateien = ['ChargerHub/module.php', 'ChargerHubBridge/module.php', 'ChargerHubDiscovery/module.php', 'libs/CHUB_Text.php'];
$verwendet = []; $gebraucht = [];
// Gleichlautende Texte (Produkt-/Eigennamen, Fachbegriffe), die bewusst keinen Eintrag brauchen
$neutral = ['ChargerHub: ', 'OCPPHub: ', 'KEBA (KeContact P30/P40)', 'Alfen (Eve Single/Double Pro-line)', 'Heidelberg Energy Control',
    'go-eCharger (Gemini/HOME+)', '🆕 ABL (eMH1/eMH2/eMH3, Modbus ASCII)', '🆕 Fox ESS EV Charger (A/L/C)', 'Unit ID',
    '⏱️  Polling', 'Watt (W)', 'Kilowatt (kW)'];
// Modulnamen stehen als Schluessel in der locale.json (Store-Anzeige), nicht im Code
$modulnamen = ['ChargerHub', 'ChargerHubBridge', 'ChargerHubDiscovery'];
foreach ($dateien as $d) {
    $t = array_values(array_filter(token_get_all(file_get_contents("$root/$d")), function ($x) {
        return !(is_array($x) && in_array($x[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true));
    }));
    $n = count($t);
    for ($i = 0; $i < $n; $i++) {
        if (!(is_array($t[$i]) && $t[$i][0] === T_CONSTANT_ENCAPSED_STRING)) { continue; }
        $roh = $t[$i][1];
        $s = $roh[0] === "'" ? str_replace(["\\'", '\\\\'], ["'", '\\'], substr($roh, 1, -1)) : stripcslashes(substr($roh, 1, -1));
        $verwendet[$s] = true;
        $vor = $t[$i - 1] ?? null; $vor2 = $t[$i - 2] ?? null;
        $istAufruf = $vor === '(' && is_array($vor2) && $vor2[0] === T_STRING && in_array($vor2[1], ['t', 'Translate'], true);
        $istForm = is_array($vor) && $vor[0] === T_DOUBLE_ARROW && is_array($vor2) && $vor2[0] === T_CONSTANT_ENCAPSED_STRING
            && in_array(trim($vor2[1], "'\""), ['caption', 'label'], true);
        if (($istAufruf || $istForm) && preg_match('/[A-Za-z]{2}/', $s) && !isset($tr[$s]) && !in_array($s, $neutral, true)) {
            if ($istForm && (preg_match('/^[A-Z0-9 \/.,:+\-()%#{}|&]*$/', $s) || preg_match('#^https?://#', $s))) { continue; }
            $gebraucht[$s] = "$d:" . $t[$i][2];
        }
    }
}
foreach ($gebraucht as $s => $wo) { $fehler[] = "ohne Uebersetzung ($wo): " . substr($s, 0, 80); }
foreach (array_keys($tr) as $en) {
    if (!isset($verwendet[$en]) && !in_array($en, $modulnamen, true)) { $fehler[] = "Eintrag nicht mehr im Code: " . substr($en, 0, 80); }
}
if ($fehler) { echo implode("\n", $fehler), "\n\n", count($fehler), " Fehler\n"; exit(1); }
echo "Zweisprachigkeit sauber (" . count($tr) . " Eintraege)\n";

<?php
/**
 * Symcon-Abnahmeprobe (ausschließlich lesend, KEIN Schreibbefehl).
 * Als Symcon-Skript anlegen; unten die neue ChargerHub-Instanz-ID eintragen.
 */
declare(strict_types=1);

$chargerHubID = 0; // HIER die ChargerHub-Instanz-ID eintragen

if ($chargerHubID <= 0 || !IPS_InstanceExists($chargerHubID)) {
    throw new RuntimeException('Bitte die vorhandene ChargerHub-Instanz-ID eintragen.');
}
if (!function_exists('CHUB_GetFunctions')) {
    throw new RuntimeException('ChargerHub-Verbundfunktion CHUB_GetFunctions fehlt.');
}
if ((string)IPS_GetProperty($chargerHubID, 'Manufacturer') !== 'openwb') {
    throw new RuntimeException('Die angegebene Instanz ist kein openWB-Primary-Treiber.');
}

$functions = CHUB_GetFunctions($chargerHubID);
$contract = is_array($functions) ? ($functions[0] ?? []) : [];
if (!is_array($contract) || ($contract['function'] ?? '') !== 'charger') {
    throw new RuntimeException('Der ChargerHub-Verbundvertrag ist ungültig.');
}

$readVar = static function (string $ident) use ($chargerHubID): array {
    $id = @IPS_GetObjectIDByIdent($ident, $chargerHubID);
    return $id ? ['id' => $id, 'value' => GetValue($id)] : ['id' => 0, 'value' => null];
};

$result = [
    'time' => date(DATE_ATOM),
    'instanceID' => $chargerHubID,
    'controllerHost' => (string)IPS_GetProperty($chargerHubID, 'Host'),
    'chargepointID' => (int)IPS_GetProperty($chargerHubID, 'OpenWBChargePointID'),
    'readOnlyExpected' => !((bool)IPS_GetProperty($chargerHubID, 'GroupOpenWBControl')
        && (string)IPS_GetProperty($chargerHubID, 'ManagedBy') === 'ems'),
    'contract' => [
        'version' => $contract['contractVersion'] ?? null,
        'manufacturer' => $contract['manufacturer'] ?? null,
        'managedBy' => $contract['managedBy'] ?? null,
        'externallyManaged' => $contract['externallyManaged'] ?? null,
        'powerID' => $contract['powerID'] ?? null,
        'energyImportID' => $contract['energyImportID'] ?? null,
        'chargeEnableID' => $contract['chargeEnableID'] ?? null,
        'currentLimitID' => $contract['currentLimitID'] ?? null,
        'sessionEnergyID' => $contract['sessionEnergyID'] ?? null,
        'phaseModeID' => $contract['phaseModeID'] ?? null,
        'lastSeenAt' => $contract['lastSeenAt'] ?? null,
    ],
    'measurements' => [],
];
foreach (['connected', 'power', 'energy_total', 'vehicle_plugged',
    'openwb_mode', 'openwb_state_text', 'openwb_soc', 'openwb_soc_available',
    'voltage_l1', 'current_l1', 'error_code', 'openwb_fault_text'] as $ident) {
    $result['measurements'][$ident] = $readVar($ident);
}

$result['checks'] = [
    'manufacturer_openwb' => ($contract['manufacturer'] ?? null) === 'openwb',
    'readOnly_contract' => !$result['readOnlyExpected'] ||
        (($contract['managedBy'] ?? null) === 'other'
            && ($contract['chargeEnableID'] ?? -1) === 0
            && ($contract['currentLimitID'] ?? -1) === 0),
    'fresh_data_under_2_minutes' =>
        ($contract['lastSeenAt'] ?? 0) > 0
        && (time() - (int)$contract['lastSeenAt']) <= 120,
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

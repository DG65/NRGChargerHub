<?php
/**
 * Offline integration tests for the openWB Primary transport and driver.
 * Run: php .tools/test-openwb-primary.php
 * No Symcon installation, network connection or charging hardware required.
 */
declare(strict_types=1);

class IPSModule {}
const VARIABLETYPE_INTEGER = 1;
const VARIABLETYPE_FLOAT = 2;
require_once __DIR__ . '/../ChargerHub/module.php';

final class OpenWBFixtureClient extends CHUB_OpenWBHttpClient
{
    public array $calls = [];
    public ?string $getReply = null;
    public ?string $postReply = '{"ok":true}';

    protected function httpRequest(string $method, array $parameters): ?string
    {
        $this->calls[] = [$method, $parameters];
        return $method === 'GET' ? $this->getReply : $this->postReply;
    }
}

final class OpenWBFixtureHub
{
    public array $values = [];
    public array $groups = ['GroupPhases' => true, 'GroupOpenWBStatus' => true,
        'GroupDevice' => true, 'GroupOpenWBControl' => false];
    public bool $allowWrites = false;
    public int $maxCurrent = 16;
    public array $phases = [];
    public string $error = '';

    public function SetVarBool(string $ident, bool $value): void { $this->values[$ident] = $value; }
    public function SetVarFloat(string $ident, float $value): void { $this->values[$ident] = $value; }
    public function SetVarInt(string $ident, int $value): void { $this->values[$ident] = $value; }
    public function SetVarStr(string $ident, string $value): void { $this->values[$ident] = $value; }
    public function GroupActive(string $group): bool { return $this->groups[$group] ?? false; }
    public function GetVarValue(string $ident) { return $this->values[$ident] ?? null; }
    public function GetMaxCurrentA(): int { return $this->maxCurrent; }
    public function OpenWBWritesAllowed(): bool { return $this->allowWrites; }
    public function SetPhaseInfo(int $phases, int $switchable): void { $this->phases = [$phases, $switchable]; }
    public function SetOpenWBError(string $reason): void { $this->error = $reason; }
}

function verify(bool $condition, string $description): void
{
    if (!$condition) {
        throw new RuntimeException('FAILED: ' . $description);
    }
    echo "OK: {$description}\n";
}

$snapshot = [
    'chargepoint_0' => [
        'power' => 3680.0,
        'imported' => 5367626.95, // openWB Wh, not kWh!
        'plug_state' => true,
        'charge_state' => true,
        'phases_in_use' => 1,
        'voltages' => [230.0, 231.0, 232.0],
        'currents' => [16.0, 0.0, 0.0],
        'chargemode' => 'pv',
        'state_str' => 'Lädt mit PV-Überschuss',
        'fault_state' => 0,
        'fault_str' => 'Kein Fehler.',
        'serial_number' => '21207688',
        'version' => '2.3.0',
        'soc' => ['soc' => 82.8],
    ],
];
$client = new OpenWBFixtureClient('primary.local', 80, 0);
$client->getReply = json_encode($snapshot, JSON_THROW_ON_ERROR);
$hub = new OpenWBFixtureHub();
$driver = new OpenWBPrimaryDriver();

verify($driver->readValues($client, $hub) === true, 'Complete Primary snapshot accepted');
verify($hub->values['connected'] === true, 'Connected true after valid snapshot');
verify($hub->values['power'] === 3680.0, 'Power uses watts');
verify(abs($hub->values['energy_total'] - 5367.62695) < 0.000001, 'Total energy Wh to kWh');
verify($hub->values['vehicle_plugged'] === true, 'Plug state true');
verify($hub->values['current_l1'] === 16.0 && $hub->values['voltage_l3'] === 232.0, 'Three-phase measurements');
verify($hub->values['openwb_mode'] === 'pv' && $hub->values['error_code'] === 0, 'Mode and fault state');
verify($hub->values['openwb_soc_available'] === true && $hub->values['openwb_soc'] === 82.8, 'Vehicle SOC with availability flag');
// The selected openWB vehicle may still have a valid (remotely retrieved) SoC
// when no car is plugged in. The labels must not imply a cable connection.
$statusVars = $driver->getOptionalGroups()['GroupOpenWBStatus']['vars'];
$statusLabels = array_column($statusVars, 1, 0);
verify($statusLabels['openwb_soc'] === 'SoC of vehicle assigned in openWB', 'SoC source clearly names the assigned vehicle');
verify($statusLabels['openwb_soc_available'] === 'Assigned-vehicle SoC available', 'SoC availability is separate from plug state');
$snapshot['chargepoint_0']['plug_state'] = false;
$snapshot['chargepoint_0']['chargemode'] = 'instant_charging';
$client->getReply = json_encode($snapshot, JSON_THROW_ON_ERROR);
verify($driver->readValues($client, $hub), 'Live-format mode and unplugged state accepted');
verify($hub->values['vehicle_plugged'] === false && $hub->values['openwb_soc_available'] === true
    && $hub->values['openwb_soc'] === 82.8, 'Unplugged does not invalidate assigned-vehicle SoC');
verify($hub->values['openwb_mode'] === 'instant_charging', 'Read mode preserves real openWB spelling');
verify($hub->phases === [1, -1], 'Measured phase count, switching capability unknown');
verify($client->calls[0] === ['GET', ['get_chargepoint_all' => 0]], 'Explicit chargepoint ID 0, no auto-ID');
verify(!isset($hub->values['ctl_enable']), 'Read-only default does not expose control values');

// An incomplete API response is not a valid measurement; never advertise a
// healthy connection with a zero-looking energy counter or plug state.
$client->getReply = '{"chargepoint_0":{"power":1000}}';
verify($driver->readValues($client, $hub) === false, 'Missing energy/plug status rejected');
verify($hub->values['connected'] === false, 'Disconnected on incomplete snapshot');
verify($hub->values['energy_total'] > 5000, 'Last known total energy is not overwritten by zero');
$client->getReply = '{"error":"unknown chargepoint"}';
verify($driver->readValues($client, $hub) === false, 'API error body rejected');

// A missing SOC is unknown, never a measured 0%.
$snapshot['chargepoint_0']['soc'] = null;
$client->getReply = json_encode($snapshot, JSON_THROW_ON_ERROR);
verify($driver->readValues($client, $hub) && $hub->values['openwb_soc_available'] === false, 'Absent SOC explicitly unknown');

// No POST with control disabled, even if caller directly invokes writeControl.
$before = count($client->calls);
$driver->writeControl($client, $hub, 'ctl_enable', true);
verify(count($client->calls) === $before && $client->lastWriteError !== '', 'Read-only mode refuses commands');

// User opt-in + EMS ownership is enforced in ChargerHub::OpenWBWritesAllowed;
// the fixture explicitly simulates the authorized resulting state.
$hub->groups['GroupOpenWBControl'] = true;
$hub->allowWrites = true;
$driver->writeControl($client, $hub, 'ctl_enable', true);
verify($hub->values['ctl_enable'] === true && $hub->values['openwb_mode'] === 'instant_charging', 'Explicit enable reflects the reported instant_charging mode');
verify(end($client->calls) === ['POST', ['set_chargemode' => 'instant', 'chargepoint_nr' => 0]], 'Mode command targets Primary and selected ID');
$before = count($client->calls);
$driver->writeControl($client, $hub, 'ctl_curr_limit', 10);
verify(count($client->calls) === $before + 1, 'Current write permitted in actual instant_charging read mode');
$driver->writeControl($client, $hub, 'ctl_curr_limit', 25);
verify(end($client->calls) === ['POST', ['chargecurrent' => 16, 'chargepoint_nr' => 0]], 'Current hard-clamped to configured 16 A');
$driver->writeControl($client, $hub, 'ctl_enable', false);
verify(end($client->calls) === ['POST', ['set_chargemode' => 'stop', 'chargepoint_nr' => 0]], 'Disable maps to Primary stop');

$before = count($client->calls);
$driver->writeControl($client, $hub, 'ctl_curr_limit', 10);
verify(count($client->calls) === $before && $client->lastWriteError !== '', 'No current write outside instant mode');

// A legacy/alternate read representation remains accepted, while other modes
// must not bypass the Primary's charging strategy.
$hub->SetVarStr('openwb_mode', 'instant');
$before = count($client->calls);
$driver->writeControl($client, $hub, 'ctl_curr_limit', 10);
verify(count($client->calls) === $before + 1, 'Legacy instant spelling remains compatible');
foreach (['pv_charging', 'eco_charging', 'scheduled_charging'] as $mode) {
    $hub->SetVarStr('openwb_mode', $mode);
    $before = count($client->calls);
    $driver->writeControl($client, $hub, 'ctl_curr_limit', 10);
    verify(count($client->calls) === $before && $client->lastWriteError !== '', 'No current write in ' . $mode);
}
$hub->SetVarStr('openwb_mode', 'instant_charging');
$hub->maxCurrent = 5;
$before = count($client->calls);
$driver->writeControl($client, $hub, 'ctl_curr_limit', 20);
verify(count($client->calls) === $before && $client->lastWriteError !== '', 'MaxCurrent below 6 A never causes unsafe 6 A write');
$hub->maxCurrent = 16;

$client->postReply = '{"success":false}';
$driver->writeControl($client, $hub, 'ctl_enable', true);
verify($client->lastWriteError !== '' && $hub->values['ctl_enable'] === false, 'Rejected POST preserves visible control state');
verify($client->setCurrent(0) === false && $client->setCurrent(33) === false, 'API current validation prevents 0 and >32 A');
verify($client->setChargeMode('pv') === false, 'Unsupported control modes cannot be sent accidentally');

$notConfigured = new OpenWBFixtureClient('primary.local', 80, -1);
verify($notConfigured->getChargePoint() === null && $notConfigured->calls === [], 'Unset chargepoint ID never falls back to auto');
$unsafeToken = new CHUB_OpenWBHttpClient('127.0.0.1', 80, 0, false, 'secret');
verify($unsafeToken->getChargePoint() === null && str_contains($unsafeToken->getLastError(), 'HTTPS'), 'Token is never transmitted over HTTP');

echo "ALL OPENWB PRIMARY OFFLINE TESTS PASSED\n";
